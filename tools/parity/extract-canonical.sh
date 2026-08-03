#!/usr/bin/env bash
# ===========================================================================
# extract-canonical.sh — ré-extraction de la fixture canonique borne
# [GOAL-SYNC 2026-07-08]
#
# Méthode (CONTRACTS.md §0 + mission gate B3) :
#   1. tinker : crée un token Sanctum nommé 'parity-extract' (ability kiosk:order)
#      sur le user de la 1ère KioskMachine (les anciens tokens du même nom sont purgés).
#   2. curl GET $API_BASE/api/frontend/menu avec Bearer + X-API-Key (MIX_API_KEY du .env).
#   3. Validation JSON (status + data.items + data.categories) puis écriture atomique.
#   4. Nettoyage : le token 'parity-extract' est révoqué en fin de script.
#
# Usage :
#   tools/parity/extract-canonical.sh [chemin-sortie.json]
#   (défaut : reports/goal-web-app-sync/catalog-canonical.json)
#   API_BASE=http://127.0.0.1:8766 par défaut (serveur borne UP requis).
#
# ⚠️ Écrase la fixture cible : ne relancer vers le chemin par défaut que si le
#    catalogue borne a VRAIMENT changé (la fixture fait loi pour le gate).
# ===========================================================================
set -euo pipefail

BACKEND="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${1:-$BACKEND/reports/goal-web-app-sync/catalog-canonical.json}"
API_BASE="${API_BASE:-http://127.0.0.1:8766}"

# --- Clé API front (X-API-Key) depuis le .env (jamais committée) -----------
API_KEY="$(grep -E '^MIX_API_KEY=' "$BACKEND/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]')"
if [ -z "$API_KEY" ]; then
  echo "[extract] ERREUR : MIX_API_KEY introuvable dans $BACKEND/.env" >&2
  exit 2
fi

cleanup() {
  # Révocation best-effort du token d'extraction (append-only côté fiscal non touché).
  (cd "$BACKEND" && php artisan tinker --execute='
    \Laravel\Sanctum\PersonalAccessToken::where("name","parity-extract")->delete();
  ' >/dev/null 2>&1) || true
}
trap cleanup EXIT

# --- 1) Token kiosk:order sur le user de la 1ère KioskMachine ---------------
echo "[extract] Création du token 'parity-extract' (ability kiosk:order)…"
TOKEN="$(cd "$BACKEND" && php artisan tinker --execute='
  $m = \App\Models\KioskMachine::withoutGlobalScopes()->orderBy("id")->first();
  if (!$m) { fwrite(STDERR, "Aucune KioskMachine\n"); exit(3); }
  $u = \App\Models\User::withoutGlobalScopes()->find($m->user_id);
  if (!$u) { fwrite(STDERR, "User KioskMachine introuvable\n"); exit(3); }
  $u->tokens()->where("name","parity-extract")->delete();
  echo "PARITY_TOKEN=".$u->createToken("parity-extract", ["kiosk:order"])->plainTextToken."\n";
' | grep '^PARITY_TOKEN=' | cut -d= -f2-)"
if [ -z "$TOKEN" ]; then
  echo "[extract] ERREUR : impossible de créer le token kiosk (serveur/DB indisponible ?)" >&2
  exit 3
fi

# --- 2) GET /api/frontend/menu ----------------------------------------------
TMP="$(mktemp)"
echo "[extract] GET $API_BASE/api/frontend/menu…"
curl -fsS "$API_BASE/api/frontend/menu" \
  -H "X-API-Key: $API_KEY" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -o "$TMP"

# --- 3) Validation + écriture atomique ---------------------------------------
node -e '
  const fs = require("fs");
  const j = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
  if (!j.status || !j.data || !Array.isArray(j.data.items) || !Array.isArray(j.data.categories)) {
    console.error("[extract] ERREUR : payload inattendu (status/data.items/data.categories)");
    process.exit(4);
  }
  console.error("[extract] Payload OK : " + j.data.categories.length + " catégories, " + j.data.items.length + " items.");
' "$TMP"

mkdir -p "$(dirname "$OUT")"
mv "$TMP" "$OUT"
echo "[extract] Fixture écrite : $OUT"
