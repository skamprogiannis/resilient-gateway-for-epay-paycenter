#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

for forbidden_path in \
  '*.sql' '*.sql.gz' '*.sqlite' '*.db' \
  '*.tar' '*.tar.gz' '*.tgz' '*.zip' '*.pdf' '*.log' \
  '*.pem' '*.key' '*.p12' '*.pfx' '.env' '.env.*' \
  'wp-config.php' 'wp-config-*.php' \
  '*screenshot*' '*checkout-investigation*' '*transaction-export*' '*admin-tool-export*'; do
  matched_files="$(git ls-files --cached --others --exclude-standard -- "$forbidden_path")"
  if [[ -n "$matched_files" ]]; then
    echo "Public tree contains forbidden path pattern: $forbidden_path" >&2
    printf '%s\n' "$matched_files" >&2
    exit 1
  fi
done

if rg -l -i \
  --hidden \
  --glob '!.git/**' \
  --glob '!scripts/check-public-tree.sh' \
  --glob '!scripts/check-public-history.sh' \
  --glob '!vendor/**' \
  --glob '!node_modules/**' \
  '(-----BEGIN ([A-Z]+ )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{20,}|DB_(NAME|USER|PASSWORD|HOST)[[:space:]]*[,=]|(AUTH|SECURE_AUTH|LOGGED_IN|NONCE)_(KEY|SALT)[[:space:]]*[,=])' \
  .; then
  echo 'Public tree contains a likely credential or private key.' >&2
  exit 1
else
  status=$?
  if [[ "$status" -ne 1 ]]; then
    echo 'Public-tree content scan failed.' >&2
    exit "$status"
  fi
fi

echo 'Public-tree privacy checks passed.'
