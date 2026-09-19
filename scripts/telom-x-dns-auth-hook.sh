#!/bin/bash
DIR=/root/scripts/telom-x-dns-challenge
SAFE_DOMAIN=$(echo "$CERTBOT_DOMAIN" | tr -c 'A-Za-z0-9._-' '_')
echo "$CERTBOT_VALIDATION" > "$DIR/${SAFE_DOMAIN}.value"
echo "waiting for $DIR/${SAFE_DOMAIN}.ready" >> "$DIR/log.txt"
for i in $(seq 1 120); do
  if [ -f "$DIR/${SAFE_DOMAIN}.ready" ]; then
    exit 0
  fi
  sleep 5
done
echo "TIMEOUT waiting for ${SAFE_DOMAIN}" >> "$DIR/log.txt"
exit 1
