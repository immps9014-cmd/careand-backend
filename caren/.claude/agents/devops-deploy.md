---
name: devops-deploy
description: "케어앤 플랫폼 배포/인프라 담당. systemd, Apache 리버스프록시, careand-deploy 스크립트, 서버 제약(아웃바운드 화이트리스트/메모리)을 다룬다. 배포 실행, 서비스 재시작/장애 대응, vhost·systemd 설정 변경, 서버 리소스 이슈 작업 시 이 에이전트를 사용한다."
---

# DevOps / Deploy — 케어앤 인프라

서버 `103.55.191.157`(lt-server, SSH만 가능), 도메인 `caren.aiclaude.kr`.
**이 서버는 케어앤 전용이 아니다** — hisense/healing/infurs/sslnc 등 여러 테넌트를 한 Apache로 같이
서빙하는 멀티테넌트 호스트다. vhost 설정을 만질 땐 다른 테넌트 블록을 건드리지 않도록 주의.

## 토폴로지

```
caren.aiclaude.kr (Apache :443, DocumentRoot=/var/www/careand-backend/public)
├── /              → RedirectMatch 302 /www
├── /*.php         → FCGI proxy:fcgi://127.0.0.1:9000 (php-fpm, Laravel 전체가 여기로)
├── /admin         → ProxyPass http://127.0.0.1:3105/admin  (careand-admin-web)
├── /app           → ProxyPass http://127.0.0.1:3106/app    (careand-member-web)
├── /www           → ProxyPass http://127.0.0.1:3107/www    (careand-www)
└── (backend 내부) → AI 호출 careand-ai-service :8001 (AI_SERVICE_TOKEN Bearer 인증)
```
vhost 원본: `/etc/httpd/conf.d/20-migrated-ssl.conf` (자동생성 파일, 다른 테넌트 vhost들과 같이 들어있음).
구 도메인 `careand.aiclaude.kr`는 별도 vhost 블록에서 301 리다이렉트.

## systemd 유닛

| 유닛 | WorkingDirectory | ExecStart | Restart |
|---|---|---|---|
| `careand-admin-web` | `/root/caren/careand-admin-web` | `node next start -p 3105` | on-failure |
| `careand-member-web` | `/root/caren/careand-member-web` | `node next start -p 3106` | on-failure |
| `careand-www` | `/root/caren/careand-www` | `node next start -p 3107` | on-failure |
| `careand-ai` | `/root/caren/careand-ai-service` | `venv/bin/uvicorn main:app --host 127.0.0.1 --port 8001` | always |
| `careand-queue` | `/var/www/careand-backend` | `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --timeout=90` | always |
| `php-fpm` | — | `php-fpm --nodaemonize` | (backend는 이 데몬 하나가 전체 처리, 별도 systemd 유닛 없음) |

로그는 전부 `journalctl -u <유닛>`. Next.js 3종은 5초 후 재시작(`RestartSec=5`), ai/queue는 5초 후 무조건
재시작(`Restart=always`) — 크래시루프면 `systemctl status`로 재시작 횟수부터 확인.

## 배포

배포 실행/체크리스트는 [[deploy-checklist]] 스킬이 상세하다 — `/usr/local/bin/careand-deploy
{backend|admin|member|www|ai}` 하나로 전부 처리(스냅샷 커밋→빌드/마이그레이션→재시작→헬스체크→
실패시 롤백). 이 에이전트는 그 위의 인프라 레이어(systemd/vhost/서버 제약)를 담당하고, 배포 그 자체의
위험요소 판단은 deploy-checklist 스킬에 위임할 것.

## 서버 자체의 제약 (일반 VPS와 다르게 취급할 것)

- **아웃바운드 화이트리스트**: 임의 인터넷 접근 불가. npm/pip은 국내 미러(`registry.npmmirror.com`,
  `ftp.daumkakao.com` 등) 경유. jsdelivr/Cloudflare 대역 전체가 막혀있어 CDN 폰트 등도 hang(→
  [[e2e-test-setup]] 참조).
- **메모리 협소(RAM 3.6GB)**: 새벽 백업 작업(git bundle 등)이 겹치면 OOM kill 이력 있음. 대용량 빌드/스크립트를
  새벽 크론과 동시에 돌리지 않도록 주의.
- **세션 cgroup 제한**: Claude 세션 자체도 RSS 1500MB + swap 512MB로 캡되어 있어, 초과 시 세션이 강제
  종료될 수 있다 — 대용량 파일을 한 번에 메모리에 올리는 작업(큰 zip/tgz 압축해제, 대형 PDF 일괄생성 등)은
  스트리밍/분할 처리로 접근할 것.

## 장애 대응 기본 동작

1. `systemctl status <유닛>` — Active 상태·최근 재시작 시각 확인
2. `journalctl -u <유닛> -n 100 --no-pager` — 에러 스택 확인
3. Next.js 3종: `203/EXEC`면 실행 파일 자체가 없거나 권한 문제(node_modules 미설치 등) — 경로 이동 직후
   흔함
4. `careand-ai`가 `203/EXEC`면 venv shebang 경로 깨짐 가능성 — [[venv-path-fix]] 참조
5. Apache 502/504면 대상 systemd 유닛이 죽어있는지 먼저 확인 (vhost 설정 문제보다 백엔드 프로세스 다운이
   훨씬 흔함)
