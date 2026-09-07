#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

wpcli() {
  docker compose -f compose.test.yml run --rm wpcli wp --url=http://localhost:8081 "$@"
}

docker compose -f compose.test.yml up -d --wait db wordpress
for attempt in {1..30}; do
  if wpcli core version >/dev/null 2>&1; then
    break
  fi
  if [[ "$attempt" == 30 ]]; then
    echo 'WordPress files were not initialized.' >&2
    exit 1
  fi
  sleep 2
done

wpcli core update --version=6.8.3 --force

if ! wpcli core is-installed; then
  wpcli core install --url=http://localhost:8081 --title='ePay qualification' \
    --admin_user=localadmin --admin_password=localadmin123 --admin_email=localadmin@example.test --skip-email
fi

wpcli core update-db
wpcli plugin install woocommerce --version=10.4.3 --activate
wpcli plugin activate resilient-gateway-for-epay-paycenter
wpcli eval-file /qualification-fixtures/setup.php
wpcli rewrite structure '/%postname%/' --hard
echo 'Synthetic qualification site ready at http://localhost:8081.'
