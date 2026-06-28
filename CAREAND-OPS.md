# Care& 운영 런북 (CI/CD·운영 인프라, 2026-06-10 구축)

> 도메인별 테스트 시나리오: `/root/CAREAND-TEST-SCENARIOS.md` (시니어·간병·가사·산후 E2E + 회귀 체크리스트)

## 배포

```bash
careand-deploy backend   # Laravel: 스냅샷 커밋 → 캐시클리어 → 큐 재시작 → 헬스체크 (실패 시 git 자동 롤백)
careand-deploy admin     # admin-web: 스냅샷 커밋 → next build → 재시작 → 헬스체크 (실패 시 .next.prev 롤백)
careand-deploy member    # member-web: 〃
careand-deploy ai        # FastAPI: 스냅샷 커밋 → 재시작 → :8001/health 체크
```

- 코드 수정 후 해당 컴포넌트로 실행하면 끝. 배포 전 상태는 항상 git 커밋으로 보존됨.
- Next.js 빌드 로그: `/tmp/careand-build-<comp>.log`. 이전 빌드는 `.next.prev`로 보존(수동 롤백 가능).
- 수동 롤백: `git log` → `git reset --hard <hash>` → `careand-deploy <comp>` 재실행.

## 버전관리 (git)

| 저장소 | 경로 |
|---|---|
| careand-backend | /var/www/careand-backend |
| careand-admin-web | /root/careand-admin-web |
| careand-member-web | /root/careand-member-web |
| careand-ai-service | /root/careand-ai-service |

`.env`/`node_modules`/`vendor`/`.next`/`venv`는 git 제외 (시크릿은 백업 tar가 담당).
원격(GitHub 등) 푸시는 미설정 — 필요 시 `git remote add` 후 푸시.

## 백업 (매일 03:30, /etc/cron.d/careand)

`/usr/local/bin/careand-backup` → `/backup/careand/` (로그: /var/log/careand-backup.log)

| 종류 | 내용 | 보존 |
|---|---|---|
| db/ | mysqldump careand_platform (gzip, --single-transaction) | 14일 |
| config/ | .env·.env.local·systemd 유닛·apache conf·cron·logrotate (tar, 600) | 30일 |
| git/ | 4개 저장소 git bundle (전체 이력) | 7일 |

복구: `zcat db/<dump>.sql.gz | mysql careand_platform` / `git clone <bundle> dir`

## 모니터링 (5분 주기)

`/usr/local/bin/careand-monitor` — systemd 8유닛 + API 헬스 + 웹 2종 점검.
상태 **변화 시에만** `/var/log/careand-monitor.log` 기록 (현재 상태: `/run/careand-monitor.state`).
재시작은 systemd(Restart=)에 위임 — 모니터는 기록만.

## 헬스 엔드포인트

- `GET /api/health`, `GET /api/v1/health` — DB·Redis 체크 포함, 200 ok / 503 degraded
- FastAPI: `GET http://127.0.0.1:8001/health`

## 로그

- logrotate: `/etc/logrotate.d/careand` (운영 로그 주간 8회전, laravel 로그 일간 14회전·copytruncate)
- 라이브 확인은 헤어핀 NAT 때문에: `curl -sk --resolve careand.aiclaude.kr:443:127.0.0.1 https://careand.aiclaude.kr/...`

## 남은 항목 (선택)

- 외부 git 원격 + GitHub Actions 등 외부 CI (현재는 단일 호스트 스크립트 CI/CD)
- ~~백업 오프사이트 복제~~ → ✅ 매일 04:10 careand-offsite로 103.55.190.159:/backup/careand-replica 복제 (rsync 추가동기화, 복제본 자체보존 db30/config60/git14일, 크기검증 포함)
- 모니터 알림 채널(메일/카카오워크 등) 연결 — 현재 로그 기록만

## AI 서비스 (careand-ai-service v0.2, 2026-06-10 실구현 전환)

- 매칭/이상징후/산후매칭: rule-v1 실구현 · 수요예측: seasonal-naive-v1(DB 이력)
- STT: faster-whisper(CPU int8) 실구현 — 모델 /root/careand-ai-service/models/base-ct2 (CT2 fp16 145MB),
  WHISPER_MODEL env로 교체(small 등 상향 시 RAM 주의: 박스 가용 ~1.5G), lazy 로드+추론 직렬화,
  모델 부재/실패 시 즉시 5xx(가짜 텍스트 금지). 모델 재다운로드: fetch_model.sh (egress 플랩 대응 curl 재시도)
- 챗봇·일지요약·일지생성: **Claude API 연동 완료, 키 대기** — `.env`에 `ANTHROPIC_API_KEY` 추가
  (`ANTHROPIC_MODEL`로 모델 지정, 기본 claude-haiku-4-5) → `careand-deploy ai` 실행하면 즉시 활성.
  키 없음/호출 실패 시 자동 폴백(응답 model 필드: kb-fallback/heuristic-fallback/template-v1/rule-fallback).
- ※ 2026-06-10 기준 박스 내 모든 Anthropic 키(ssfc/factory/bizcard) 크레딧 소진 — 충전 필요.
- 상태 확인: `curl http://127.0.0.1:8001/health` (models 필드에 엔진별 상태)
