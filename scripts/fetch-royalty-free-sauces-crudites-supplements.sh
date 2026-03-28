#!/usr/bin/env bash
# Télécharge des photos libres de droits (licence Pexels) et les convertit en PNG
# pour crudités + suppléments (les sauces sont des SVG : generate-menu-sauce-svgs.php).
#
# Usage : depuis la racine du projet :
#   bash scripts/fetch-royalty-free-sauces-crudites-supplements.sh
#
# Prérequis : curl, sips (macOS). Sur Linux : installer ImageMagick et remplacer
#   sips par : convert "$jpg" "$png"
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/public/images/menu"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PEXELS_QS="auto=compress&cs=tinysrgb&w=900"
UA="FoodKing-asset-fetch/1.0 (internal; royalty-free menu images)"

download_png() {
  local file="$1" id="$2"
  local jpg="$TMP/${file%.png}.jpg"
  local url="https://images.pexels.com/photos/${id}/pexels-photo-${id}.jpeg?${PEXELS_QS}"
  echo "→ $file (Pexels #$id)"
  curl -fsSL -A "$UA" "$url" -o "$jpg"
  if command -v sips >/dev/null 2>&1; then
    sips -s format png "$jpg" --out "$OUT/$file" >/dev/null
  elif command -v convert >/dev/null 2>&1; then
    convert "$jpg" "$OUT/$file"
  else
    echo "Installez sips (macOS) ou ImageMagick (convert)." >&2
    exit 1
  fi
}

mkdir -p "$OUT"

# --- Crudités ---
download_png crudites_complet.png        1213710
download_png crudites_sans_oignon.png    28670062
download_png crudites_sans_tomate.png    1300975
download_png crudites_sans_salade.png    17302468
download_png crudites_aucune.png         958545

# --- Suppléments ---
download_png supplement_cheddar.png   1126359
download_png supplement_jambon.png    618775
download_png supplement_poulet.png   606676
download_png supplement_kebab.png    5639413
download_png supplement_viande.png   3996469
download_png supplement_oeuf.png     1627120
download_png supplement_raclette.png 3054697
download_png supplement_boursin.png  1093417
download_png supplement_chevre.png   1437267

echo "Terminé : $OUT (14 PNG crudités + suppléments). Sauces : php scripts/generate-menu-sauce-svgs.php"
