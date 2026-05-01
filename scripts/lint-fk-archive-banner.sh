#!/usr/bin/env bash
# FoodKing legacy archive banner guard.
# No set -e: report all missing banners in one run.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

FAILED=0

check_banner() {
  dir="$1"
  file="$dir/ARCHIVE_BANNER.md"

  if [ ! -f "$file" ]; then
    printf '[FAIL] missing archive banner: %s\n' "$file"
    FAILED=1
    return
  fi

  first_line="$(sed -n '1p' "$file" 2>/dev/null)"
  case "$first_line" in
    "# ARCHIVE"*) printf '[OK] archive banner present: %s\n' "$file" ;;
    *)
      printf '[FAIL] invalid archive banner header: %s\n' "$file"
      FAILED=1
      ;;
  esac
}

check_banner "kiosk_implementation"
check_banner "borne (Remix)"

exit "$FAILED"
