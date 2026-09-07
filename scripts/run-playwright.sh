#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -z "${PLAYWRIGHT_BROWSERS_PATH:-}" ]]; then
  EPAY_TEST_ACCOUNT_NAME="$(id -un)"
  EPAY_TEST_SYSTEM_PLAYWRIGHT="${EPAY_TEST_SYSTEM_PLAYWRIGHT:-/etc/profiles/per-user/$EPAY_TEST_ACCOUNT_NAME/bin/playwright}"
  if [[ -x "$EPAY_TEST_SYSTEM_PLAYWRIGHT" ]]; then
    EPAY_TEST_CHROMIUM_LOCATION="$($EPAY_TEST_SYSTEM_PLAYWRIGHT install --dry-run | awk '/Install location:/ { print $3; exit }')"
    if [[ -n "$EPAY_TEST_CHROMIUM_LOCATION" ]]; then
      export PLAYWRIGHT_BROWSERS_PATH="${EPAY_TEST_CHROMIUM_LOCATION%/chromium-*}"
    fi
  fi
fi

exec "$ROOT_DIR/node_modules/.bin/playwright" "$@"
