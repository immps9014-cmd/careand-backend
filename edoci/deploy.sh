#!/bin/bash
# 이도건설산업 홈페이지 반영: /root/edoci → /var/www/html/edoci (edoci.aiclaude.kr)
set -e
cd "$(dirname "$0")"
rsync -a --delete --exclude deploy.sh --exclude '.*' ./ /var/www/html/edoci/
echo "반영 완료: $(ls /var/www/html/edoci/img | wc -l)개 이미지, index.html $(stat -c %s /var/www/html/edoci/index.html)B"
