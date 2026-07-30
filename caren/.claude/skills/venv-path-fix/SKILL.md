---
name: venv-path-fix
description: careand-ai-service(또는 다른 Python venv 기반 서비스)의 디렉토리를 이동한 뒤 venv/bin의 shebang이 구경로를 가리켜 systemd가 203/EXEC로 죽는 문제를 진단·수정한다. "203/EXEC", "venv 이동", "서비스가 죽음", "shebang" 관련 작업 시 사용한다.
---

Python venv는 `venv/bin/*` 실행 스크립트(python, pip, uvicorn 등) 첫 줄에 **venv를 만들 당시의 절대경로**를
shebang으로 박아 넣는다 (`#!/root/caren/careand-ai-service/venv/bin/python3.9`). 디렉토리를 통째로
`mv`/`cp -a`로 옮기면 이 shebang이 구경로를 계속 가리켜서 실행이 깨진다.

## 증상

```
systemctl status careand-ai
# ... Main PID exited, code=exited, status=203/EXEC
```
`203/EXEC`는 systemd가 `ExecStart`를 실행하려다 실패했다는 뜻 — venv 이동 직후라면 shebang 문제를
가장 먼저 의심할 것 (권한 문제나 파일 자체가 없는 경우도 같은 코드가 뜨므로 아래 진단으로 구분).

## 진단

```bash
head -1 <venv경로>/bin/uvicorn      # 또는 서비스가 실제로 쓰는 entrypoint 스크립트
cat <venv경로>/pyvenv.cfg           # home = ... 이 구경로를 가리키는지도 참고 확인
```
shebang의 경로가 systemd `WorkingDirectory`/`ExecStart`가 가리키는 실제 현재 경로와 다르면 확정.

## 수정

```bash
cd <venv경로>/bin
sed -i "1s|^#!<구경로>/venv/bin/python|#!<신경로>/venv/bin/python|" *
```
예 (careand-ai-service 2026-07-30 이동 사례):
```bash
sed -i '1s|^#!/root/careand-ai-service/venv/bin/python|#!/root/caren/careand-ai-service/venv/bin/python|' \
  /root/caren/careand-ai-service/venv/bin/*
```
- `1s`로 **첫 줄만** 치환 (본문에 구경로 문자열이 우연히 들어있는 스크립트를 잘못 건드리지 않기 위함)
- 바이너리 파일(`python3`, `python3.9` 실행파일 자체)은 shebang이 없으니 sed 대상에서 자동 제외됨 —
  텍스트 스크립트(uvicorn, pip, pip3, activate 등)만 걸림. `activate`/`activate.csh`/`activate.fish`는
  shebang이 아니라 내부에 `VIRTUAL_ENV=` 절대경로가 있으므로 별도로 확인 필요:
  ```bash
  grep -l "VIRTUAL_ENV=" <venv경로>/bin/activate*
  ```

## 검증

```bash
head -1 <venv경로>/bin/uvicorn        # 신경로로 바뀌었는지
systemctl restart careand-ai
systemctl is-active careand-ai        # active 확인
journalctl -u careand-ai -n 20        # 203/EXEC 재발 없는지
curl -sf http://127.0.0.1:8001/health # 서비스 응답 확인
```

## venv 자체를 새로 만드는 대안

shebang 치환 대신 venv를 신경로에서 `python -m venv --clear` 등으로 재생성하는 방법도 있지만, 설치된
패키지(`requirements-ml.txt`, faster-whisper/torch 등 대용량 ML 패키지 포함)를 이 서버의 제한된
아웃바운드(화이트리스트, [[lt-server-npm-reverse-tunnel]] 계열 미러 우회 필요)로 다시 받아야 해서
훨씬 느리다 — **shebang 일괄 치환이 훨씬 빠르고 안전한 방법**이니 특별한 이유가 없으면 이쪽을 쓸 것.

## 재발 방지

디렉토리 이동 작업을 할 때는 venv를 포함한 전체 이동 후 이 스킬로 바로 검증하는 걸 표준 절차에 포함시킬 것.
2026-07-30 `/root/careand-ai-service` → `/root/caren/careand-ai-service` 이동 때는 venv 자체는 (우연히
혹은 사전조치로) 정상이었지만, 같은 이동에서 `swap_whisper*.sh` 등 별도 스크립트 5개가 구경로를
하드코딩한 채 방치돼 있다가 뒤늦게 발견된 전례가 있다 — venv shebang뿐 아니라 **레포 내 모든 절대경로
하드코딩**을 이동 직후 `grep -rl "<구경로>" .`로 훑는 걸 권장.
