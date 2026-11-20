#!/usr/bin/env bash
# Banc E2E de l'espace commerçant de la roue — [2026-11-19].
#
# Sauve les réglages `wheel` + `pilotage`, sème UN lot à remettre (téléphone de test), lance le banc
# Playwright, puis RESTAURE tout (réglages, lot semé, sorties de stock qu'il a créées). Rien ne reste.
#
# Usage : PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 bash tools/banc-espace-roue.sh
set -uo pipefail
cd "$(dirname "$0")/.."

BASE="${PLAYWRIGHT_BASE_URL:-http://127.0.0.1:8766}"
TEL="${ESPACE_TEL:-0699887766}"
TMP="$(mktemp -d)"
PIN="$(grep '^WHEEL_PIN=' .env | head -1 | cut -d= -f2- | tr -d '"')"
[ -n "$PIN" ] || { echo "WHEEL_PIN absent du .env : la porte de la roue est fermée par défaut."; exit 2; }

php artisan tinker --execute="file_put_contents('$TMP/settings.json', json_encode(DB::table('settings')->whereIn('group',['wheel','pilotage'])->get()));" >/dev/null

SPIN_ID="$(php artisan tinker --execute="
\$c = (string) config('wheel.campaign_key');
DB::table('wheel_spins')->where('phone','$TEL')->delete();
echo DB::table('wheel_spins')->insertGetId(['branch_id'=>1,'campaign_key'=>\$c,'phone'=>'$TEL','customer_name'=>'Testeur Banc','prize_key'=>'banc_espace','prize_label'=>'Cadeau du banc','prize_type'=>'free_item','prize_value'=>0,'points_awarded'=>0,'unlock_method'=>'staff','created_at'=>now(),'updated_at'=>now()]);
" | tail -1)"
# Lot SANS produit de référence (clé hors catalogue) : sa remise n'écrit AUCUNE sortie de stock.
# Un « Tiramisu » réel en écrivait une à chaque passage — ligne en ajout seul, impossible à effacer,
# qui gonflait d'un cran à chaque banc l'avertissement « cadeaux remis sans sortir du stock ».
echo "lot semé : #$SPIN_ID"

restaurer() {
  php artisan tinker --execute="
    // La ligne de sortie (stock_outflows) est en AJOUT SEUL — déclencheur d'intégrité : elle reste, et
    // c'est voulu. On défait seulement le mouvement de stock, pour rendre le stock du banc.
    foreach (DB::table('stock_movements')->where('idempotency_key', 'wheel-gift-$SPIN_ID')->get() as \$m) {
      DB::table('stock_levels')->where('id', \$m->stock_level_id)->increment('on_hand', -1 * (float) \$m->delta);
      DB::table('stock_movements')->where('id', \$m->id)->delete(); }
    DB::table('wheel_spins')->where('id', (int) '$SPIN_ID')->delete();
    DB::table('settings')->whereIn('group',['wheel','pilotage'])->delete();
    foreach (json_decode(file_get_contents('$TMP/settings.json'), true) as \$r) { DB::table('settings')->insert(\$r); }
    echo 'restauré';
  " | tail -1
  rm -rf "$TMP"
}
trap restaurer EXIT

export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
WHEEL_PREVIEW_KEY="$(grep '^WHEEL_PREVIEW_KEY=' .env | head -1 | cut -d= -f2- | tr -d '"')" \
  MIX_API_KEY="$(grep '^MIX_API_KEY=' .env | head -1 | cut -d= -f2- | tr -d '"')" \
  WHEEL_PIN="$PIN" ESPACE_TEL="$TEL" PLAYWRIGHT_BASE_URL="$BASE" PLAYWRIGHT_NO_WEB_SERVER=1 \
  ESPACE_CAPTURES="${ESPACE_CAPTURES:-tests/captures/espace-roue}" \
  npx playwright test tests/Playwright/espace-roue-2026-11-19.spec.js --workers=1 --reporter=line
