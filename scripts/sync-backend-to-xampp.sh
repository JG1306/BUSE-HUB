#!/usr/bin/env bash
# Copy backend PHP into XAMPP htdocs (Linux default path).
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)/backend"
DEST="${XAMPP_BACKEND:-/opt/lampp/htdocs/buse-hub/backend}"

if [[ ! -d "$SRC" ]]; then
  echo "Source not found: $SRC"
  exit 1
fi

mkdir -p "$DEST/uploads"

if [[ -w "$DEST" ]]; then
  rsync -av --no-group --no-owner --exclude 'uploads/*' "$SRC/" "$DEST/"
else
  echo "Need sudo to write $DEST (htdocs is owned by root)"
  sudo rsync -av --no-group --no-owner --exclude 'uploads/*' "$SRC/" "$DEST/"
  sudo chmod -R a+rX "$DEST"
  sudo chmod -R a+rwX "$DEST/uploads" 2>/dev/null || true
fi

# Always sync these when auth/marketplace changes
for f in create_business.php get_businesses.php; do
  if [[ -f "$SRC/$f" ]]; then
    if [[ -w "$DEST" ]]; then
      cp "$SRC/$f" "$DEST/$f"
    else
      sudo cp "$SRC/$f" "$DEST/$f"
    fi
  fi
done

echo "Synced $SRC -> $DEST"
echo "Test: http://localhost/buse-hub/backend/api/login.php"
