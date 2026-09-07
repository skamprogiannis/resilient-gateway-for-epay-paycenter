#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

objects="$(git rev-list --objects --all)"
if printf '%s\n' "$objects" \
  | cut -d' ' -f2- \
  | rg -n -i '(checkout-investigation|transaction-export|admin-tool-export|wp-config[^/]*\.php$|\.(sql(\.gz)?|sqlite|db|pdf|log|pem|key|p12|pfx|zip|tar(\.gz)?|tgz)$|(^|/)\.env(\.[^/]*)?$)'; then
  echo 'Public history contains a forbidden path.' >&2
  exit 1
else
  status=$?
  if [[ "$status" -ne 1 ]]; then
    echo 'Public-history path scan failed.' >&2
    exit "$status"
  fi
fi

commits="$(git rev-list --all)"
while IFS= read -r commit; do
  if git grep -I -l -i -E \
    '(-----BEGIN ([A-Z]+ )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{20,}|DB_(NAME|USER|PASSWORD|HOST)[[:space:]]*[,=]|(AUTH|SECURE_AUTH|LOGGED_IN|NONCE)_(KEY|SALT)[[:space:]]*[,=])' \
    "$commit" -- . \
    ':(exclude)scripts/check-public-tree.sh' \
    ':(exclude)scripts/check-public-history.sh'; then
    echo "Public history contains a likely credential or private key in $commit." >&2
    exit 1
  else
    status=$?
    if [[ "$status" -ne 1 ]]; then
      echo "Public-history content scan failed in $commit." >&2
      exit "$status"
    fi
  fi
done <<< "$commits"

echo 'Public-history privacy checks passed.'
