#!/usr/bin/env bash
# Creates .local/help-repo → ../seo-ops-help (symlink on Unix).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LINK="$ROOT/.local/help-repo"
TARGET="$(cd "$ROOT/.." && pwd)/seo-ops-help"

if [[ ! -d "$TARGET/docs" ]]; then
  echo "Target Help repo not found: $TARGET" >&2
  exit 1
fi

mkdir -p "$ROOT/.local"
if [[ -L "$LINK" || -d "$LINK" ]]; then
  echo "Already linked: $LINK"
  exit 0
fi

ln -s "$TARGET" "$LINK"
echo "Linked $LINK -> $TARGET"
