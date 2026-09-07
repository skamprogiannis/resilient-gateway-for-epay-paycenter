#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="resilient-gateway-for-epay-paycenter"
ENTRYPOINT="$ROOT_DIR/resilient-gateway-for-epay-paycenter.php"
OUTPUT_DIR="${1:-$ROOT_DIR/dist}"

if [[ ! -f "$ENTRYPOINT" ]]; then
  echo "Plugin entrypoint not found: $ENTRYPOINT" >&2
  exit 1
fi

VERSION="$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]\+\)[[:space:]]*$/\1/p' "$ENTRYPOINT" | head -n 1)"
if [[ -z "$VERSION" ]] \
  || ! rg -q "EPAY_PAYCENTER_VERSION', '$VERSION'" "$ENTRYPOINT"; then
  echo "Plugin header and EPAY_PAYCENTER_VERSION do not match." >&2
  exit 1
fi

if ! rg -q "Stable tag:[[:space:]]+$VERSION" "$ROOT_DIR/readme.txt" \
  || ! rg -q "'version'[[:space:]]*=>[[:space:]]*'$VERSION'" "$ROOT_DIR/assets/js/blocks.asset.php"; then
  echo "Plugin header, readme stable tag, and Blocks asset version do not match $VERSION." >&2
  exit 1
fi

if [[ -n "$(git -C "$ROOT_DIR" status --porcelain --untracked-files=normal)" ]]; then
  echo "Refusing to package a dirty plugin worktree." >&2
  exit 1
fi

EXPECTED_TAG="v$VERSION"
ACTUAL_TAG="$(git -C "$ROOT_DIR" describe --tags --exact-match HEAD 2>/dev/null || true)"
if [[ "$ACTUAL_TAG" != "$EXPECTED_TAG" ]]; then
  echo "Refusing to package untagged commit; expected HEAD tag $EXPECTED_TAG." >&2
  exit 1
fi

mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(realpath "$OUTPUT_DIR")"
ARCHIVE="$OUTPUT_DIR/$SLUG-$VERSION.zip"

if [[ -e "$ARCHIVE" ]]; then
  if [[ ! -f "$ARCHIVE" ]]; then
    echo "Release archive path exists and is not a regular file: $ARCHIVE" >&2
    exit 1
  fi
  find "$ARCHIVE" -maxdepth 0 -type f -delete
fi

git -C "$ROOT_DIR" archive \
  --format=zip \
  --prefix="$SLUG/" \
  --output="$ARCHIVE" \
  HEAD

if ! unzip -Z1 "$ARCHIVE" | rg -q "^$SLUG/resilient-gateway-for-epay-paycenter.php$"; then
  echo "Release archive is missing the plugin entrypoint." >&2
  exit 1
fi

while IFS= read -r member; do
  case "$member" in
    "$SLUG/" | \
    "$SLUG/LICENSE.txt" | \
    "$SLUG/NOTICE.md" | \
    "$SLUG/index.php" | \
    "$SLUG/readme.txt" | \
    "$SLUG/readme-el.txt" | \
    "$SLUG/resilient-gateway-for-epay-paycenter.php" | \
    "$SLUG/uninstall.php" | \
    "$SLUG/assets/" | \
    "$SLUG/assets/index.php" | \
    "$SLUG/assets/css/" | \
    "$SLUG/assets/css/"*.css | \
    "$SLUG/assets/css/index.php" | \
    "$SLUG/assets/js/" | \
    "$SLUG/assets/js/"*.js | \
    "$SLUG/assets/js/"*.php | \
    "$SLUG/includes/" | \
    "$SLUG/includes/"*.php | \
    "$SLUG/languages/" | \
    "$SLUG/languages/"*.l10n.php | \
    "$SLUG/languages/"*.mo | \
    "$SLUG/languages/"*.po | \
    "$SLUG/languages/"*.pot | \
    "$SLUG/languages/index.php" | \
    "$SLUG/templates/" | \
    "$SLUG/templates/"*.php)
      ;;
    *)
      echo "Release archive contains an unexpected path: $member" >&2
      exit 1
      ;;
  esac
done < <(unzip -Z1 "$ARCHIVE")

(
  cd "$OUTPUT_DIR"
  sha256sum "$SLUG-$VERSION.zip" > "$SLUG-$VERSION.zip.sha256"
)

printf '%s\n' \
  "archive=$ARCHIVE" \
  "sha256=$(sha256sum "$ARCHIVE" | cut -d ' ' -f 1)" \
  "commit=$(git -C "$ROOT_DIR" rev-parse HEAD)"
