#!/usr/bin/env bash
# 역터널(127.0.0.1:4873 = 외부 PC의 Verdaccio)이 올라온 뒤 실행
set -e
PORT="${1:-4873}"
echo "[1] 터널 도달 확인 (127.0.0.1:$PORT)"
curl -sS -m 5 -o /dev/null -w "  verdaccio HTTP %{http_code}\n" "http://127.0.0.1:$PORT/" \
  || { echo "  ✗ 터널 미연결. 외부 PC에서 verdaccio + ssh -R 먼저 실행하세요."; exit 1; }
echo "[2] npm registry 전환"
npm config set registry "http://127.0.0.1:$PORT/"
npm config get registry
echo "[3] 설치 테스트"
npm view left-pad version --registry "http://127.0.0.1:$PORT/" 2>&1 | tail -1
echo "✓ 준비 완료. 평소처럼 npm install 사용하세요."
