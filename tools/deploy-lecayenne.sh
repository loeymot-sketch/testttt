#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# DÉPLOIEMENT CANONIQUE — VPS lecayenne (Le Cayenne)
# Durci 2026-07-15 après audit adversaire (3 agents RED).
# Réf : plans/GOAL_REMEDIATION_3_POINTS_STAGING_2026-07-15.md (Workstream C).
# Remplace les scripts jetables deploy-now-YYYY-MM-DD.sh.
#
# Corrige les défauts PROUVÉS de deploy-now-2026-07-15.sh :
#   • le smoke ne bloquait JAMAIS (boucle finissant par `echo` → exit 0)
#         → gate réel : rollback si l'app est injoignable ou sert un bundle périmé.
#   • smoke par code HTTP AVEUGLE à une app cassée (200 = « PHP a répondu »)
#         → assertion de CONTENU : /api/healthz (JSON sous-systèmes) + hash du
#           app.js du mix-manifest RÉELLEMENT servi dans le HTML de /kiosk/idle.
#   • port 8766 hardcodé = port serveur de DEV (laptop), pas un fait VPS
#         → AUTO-DÉCOUVERTE du port via APP_URL du .env ; repli loopback→public.
#   • check HEAD « echo seul » + EXPECTED contradictoire
#         → assertion DURE (== origin) + garde optionnelle SHA relu (ancêtre).
#   • migrate --force SANS snapshot (migration non-réversible = irréparable)
#         → mysqldump --triggers gzip AVANT migrate ; abort si le dump échoue.
#   • PIN carnet ~15 bits ($RANDOM ≤ 32767) + echo en clair
#         → /dev/urandom 6 chiffres, jamais affiché ; valeur VIDE traitée = absente.
#   • aucun rollback
#         → PREV + rollback() (repris de deploy-vps.sh), déclenché à chaque échec.
#
# NE POSE AUCUN SECRET. Les secrets finaux se posent EN FIN DE PROJET via
# docs/HANDOVER_SECRETS_REGISTRY.md (décision owner : test en réel d'abord).
#
# Usage :
#   bash tools/deploy-lecayenne.sh [REVIEWED_SHA]
#     REVIEWED_SHA (option) : SHA relu/validé. Le deploy ABORTE si le HEAD
#     déployé n'inclut pas ce commit (garde anti « j'ai déployé du non-relu »).
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

BRANCH="pos/category-first-caisse-2026-06-23"
REVIEWED_SHA="${1:-}"

echo "==> Déploiement lecayenne · branche $BRANCH · reviewed=${REVIEWED_SHA:-<aucun>}"

ssh -o ConnectTimeout=25 lecayenne 'bash -s' "$BRANCH" "$REVIEWED_SHA" <<'REMOTE'
set -uo pipefail
BRANCH="${1:-}"
REVIEWED_SHA="${2:-}"
cd /var/www/lecayenne || { echo "XX cd /var/www/lecayenne a échoué"; exit 9; }

PREV="$(git rev-parse HEAD)"
DEPLOY_START="$(date +%s)"
echo "== HEAD avant : $(git rev-parse --short HEAD)  (rollback → $PREV)"

rollback() {
  echo "XX ROLLBACK → $PREV"
  git reset --hard "$PREV" >/dev/null 2>&1 || true
  npm run production >/dev/null 2>&1 || true
  php artisan config:clear >/dev/null 2>&1 || true
  php artisan queue:restart >/dev/null 2>&1 || true
  exit 1
}

# Lecture BRUTE du .env (fiable même quand la config est cachée).
env_val(){ grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }

# ── 1. Code + ASSERTION HEAD dure ────────────────────────────────────────────
git fetch origin "$BRANCH" >/dev/null 2>&1 || { echo "XX git fetch KO"; exit 2; }
git reset --hard "origin/$BRANCH" >/dev/null 2>&1 || { echo "XX git reset KO"; exit 2; }
AFTER="$(git rev-parse HEAD)"; ORIGIN="$(git rev-parse "origin/$BRANCH")"
echo "== HEAD apres : $(git rev-parse --short "$AFTER")"
if [ "$AFTER" != "$ORIGIN" ]; then echo "XX HEAD != origin/$BRANCH"; exit 3; fi
if [ -n "$REVIEWED_SHA" ] && ! git merge-base --is-ancestor "$REVIEWED_SHA" "$AFTER"; then
  echo "XX HEAD n'inclut PAS le SHA relu $REVIEWED_SHA — refus."; exit 3
fi

# ── 2. PIN carnet : /dev/urandom 6 chiffres, jamais affiché (guard boot ≠ 2468) ─
sed -i '/^DAILY_BOOK_PIN=$/d' .env 2>/dev/null || true   # une valeur VIDE = skip+bypass → on la retire
if ! grep -qE '^DAILY_BOOK_PIN=.+' .env; then
  PIN="$(printf '%06d' "$(( $(od -An -N4 -tu4 /dev/urandom) % 1000000 ))")"
  [ "$PIN" = "002468" ] && PIN="013579"
  printf '\nDAILY_BOOK_PIN=%s\n' "$PIN" >> .env
  echo "== CARNET PIN généré (6 chiffres, /dev/urandom). Récupère-le en privé :"
  echo "==   ssh lecayenne \"grep DAILY_BOOK_PIN /var/www/lecayenne/.env\""
else
  echo "== DAILY_BOOK_PIN déjà défini (inchangé)"
fi

# ── 2bis. CORS : FRONTEND_WEB_DOMAIN doit être posé, sinon le site Vercel est
# bloqué par CORS. config/cors.php lit env('FRONTEND_WEB_DOMAIN') ; absent/null →
# array_filter le vire → le VPS ne renvoie PAS d'Access-Control-Allow-Origin pour
# l'origine du site → le navigateur du client bloque CHAQUE appel API → checkout
# mort en ligne (P0 prouvé 2026-07-19 ; curl seul ne le voit pas, il ignore le
# CORS). Idempotent : on ne pose un défaut QUE si la var est absente/vide (respecte
# un domaine custom déjà configuré, ex. lecayenne.fr). Ce n'est PAS un secret —
# c'est l'URL PUBLIQUE du site. Posé AVANT config:cache (§4) pour être pris en compte.
sed -i '/^FRONTEND_WEB_DOMAIN=$/d' .env 2>/dev/null || true   # valeur VIDE = CORS cassé → on la retire
if ! grep -qE '^FRONTEND_WEB_DOMAIN=.+' .env; then
  printf '\nFRONTEND_WEB_DOMAIN=%s\n' 'https://site-lecayenne.vercel.app' >> .env
  echo "== FRONTEND_WEB_DOMAIN posé (défaut Vercel) : https://site-lecayenne.vercel.app"
else
  echo "== FRONTEND_WEB_DOMAIN déjà défini (inchangé) : $(env_val FRONTEND_WEB_DOMAIN)"
fi

# ── 3. SNAPSHOT DB avant migrate --force ─────────────────────────────────────
mkdir -p storage/backups
BK="storage/backups/predeploy-$(date +%Y%m%d-%H%M%S).sql.gz"
DBN="$(env_val DB_DATABASE)"; DBU="$(env_val DB_USERNAME)"; DBPW="$(env_val DB_PASSWORD)"
DBH="$(env_val DB_HOST)"; DBH="${DBH:-127.0.0.1}"; DBP="$(env_val DB_PORT)"; DBP="${DBP:-3306}"
if command -v mysqldump >/dev/null 2>&1 && [ -n "$DBN" ]; then
  if MYSQL_PWD="$DBPW" mysqldump --single-transaction --triggers --routines \
        -h"$DBH" -P"$DBP" -u"$DBU" "$DBN" 2>/dev/null | gzip > "$BK"; then
    echo "== DB snapshot OK : $BK ($(du -h "$BK" 2>/dev/null | cut -f1))"
  else
    echo "XX DB snapshot ÉCHOUÉ — refus de migrer sans backup."; rollback
  fi
else
  echo "!! mysqldump indisponible / DB_DATABASE vide — SNAPSHOT SAUTÉ (à vérifier avant go-live prod)."
fi

# ── 4. Build COMPLET + migrations + triggers NF525 + caches ──────────────────
echo "== BUILD (npm run production)…"
if [ -f package-lock.json ]; then npm ci --no-audit --no-fund >/dev/null 2>&1 || npm install >/dev/null 2>&1
else npm install >/dev/null 2>&1; fi
npm run production 2>&1 | tail -2 || rollback
echo "== MIGRATIONS…"; php artisan migrate --force 2>&1 | tail -3 || rollback
echo "== SEEDER permission rupture…"; php artisan db:seed --class=AvailabilityTogglePermissionSeeder --force 2>&1 | tail -1 || true
echo "== NF525 triggers…"
php artisan fiscal:install-immutability-triggers 2>&1 | tail -1 || rollback
php artisan fiscal:verify-immutability-triggers  2>&1 | tail -1 || rollback
php artisan config:clear >/dev/null && php artisan config:cache >/dev/null \
  && php artisan cache:clear >/dev/null && php artisan view:clear >/dev/null \
  && php artisan route:clear >/dev/null && echo "== caches OK"
php artisan queue:restart >/dev/null && echo "== queue OK"
echo -n "== NF525 chain : "; php artisan fiscal:verify-chain --all 2>&1 | tail -1 || true

# ── 5. VÉRIF bundles : jeu COMPLET (leçon écran-blanc 2026-06-29) ─────────────
# [2026-07-17] mtime ≥ DEPLOY_START retiré comme critère d'échec : webpack 5
# (`output.compareBeforeEmit`, défaut) NE réécrit PAS un fichier au contenu
# identique → bundle inchangé = mtime ancien = faux positif STALE (rollback à
# tort du deploy 57df489ce). Un bundle MANQUANT reste bloquant ; la fraîcheur
# réelle est prouvée par la gate 6 (hash du app.js RÉELLEMENT servi ==
# mix-manifest post-build) qui, elle, attrape un vrai jeu périmé.
MANIFEST="public/mix-manifest.json"
[ -f "$MANIFEST" ] || { echo "XX $MANIFEST absent après build"; rollback; }
MISS=0
while IFS= read -r rel; do
  f="public${rel%%\?*}"
  if [ ! -f "$f" ]; then echo "XX bundle MANQUANT : $f"; MISS=1
  elif [ "$(stat -c %Y "$f" 2>/dev/null || stat -f %m "$f" 2>/dev/null)" -lt "$DEPLOY_START" ]; then
    echo "!! bundle inchangé par ce build (contenu identique, mtime ancien) : $f"; fi
done < <(php -r '$m=json_decode(file_get_contents("public/mix-manifest.json"),true);foreach($m as $k=>$v){echo $k,"\n";}')
[ "$MISS" -eq 0 ] || { echo "XX jeu de bundles incomplet → écran blanc probable"; rollback; }
echo "== bundles complets (fraîcheur prouvée par la gate hash-servi)"

# ── 6. SMOKE qui prouve du CONTENU (port auto-découvert ; loopback→public) ────
APP_URL_VAL="$(env_val APP_URL)"; APP_URL_VAL="${APP_URL_VAL%/}"
SCHEME="http"; case "$APP_URL_VAL" in https://*) SCHEME="https";; esac
PORT="$(printf '%s' "$APP_URL_VAL" | sed -E 's#^https?://[^/:]+:?([0-9]*).*#\1#')"
[ -n "$PORT" ] || { [ "$SCHEME" = "https" ] && PORT=443 || PORT=80; }
LOOPBACK="${SCHEME}://127.0.0.1:${PORT}"
CURL="curl -sk --max-time 8"
probe(){ $CURL -o /dev/null -w '%{http_code}' "$1/api/healthz" 2>/dev/null; }

GOOD=""
LB_CODE="$(probe "$LOOPBACK")"; echo "== healthz loopback $LOOPBACK → $LB_CODE"
case "$LB_CODE" in 200|204) GOOD="$LOOPBACK";; esac
if [ -z "$GOOD" ] && [ -n "$APP_URL_VAL" ]; then
  PB_CODE="$(probe "$APP_URL_VAL")"; echo "== healthz public $APP_URL_VAL → $PB_CODE"
  case "$PB_CODE" in 200|204) GOOD="$APP_URL_VAL";; esac
fi
if [ -z "$GOOD" ]; then
  echo "XX app injoignable en loopback ET en public — /api/healthz KO."
  echo "XX   Diagnostiquer sur la box : ss -tlnp | grep -E ':(80|443|8766)' ; nginx -T | grep -n listen"
  rollback
fi
echo "== app JOIGNABLE via $GOOD"

# Assertion CONTENU : la page borne rend bien l'app ET sert le app.js DE CE BUILD.
# [SMOKE-RETRY 2026-07-20] Root-cause d'un rollback à tort (deploy 3ae59d23) : la box a
# opcache.validate_timestamps=On + revalidate_freq=2 → une fenêtre ~2s après `git reset` où
# OPcache sert un bytecode MIXTE (l'app boote incohérente → /kiosk/idle transitoirement 200 SANS
# window.foodkingConfig). On sonde avec settle+retry : une VRAIE page blanche échoue TOUS les
# essais (→ rollback), un transitoire OPcache passe dès que la revalidation est faite. La gate
# reste dure (5 essais max, ~15s) — elle n'est PAS affaiblie, juste rendue robuste au timing.
KCODE=""; HTML=""
for try in 1 2 3 4 5; do
  KCODE="$($CURL -o /dev/null -w '%{http_code}' "$GOOD/kiosk/idle" 2>/dev/null)"
  HTML="$($CURL "$GOOD/kiosk/idle" 2>/dev/null)"
  if [ "$KCODE" = "200" ] && printf '%s' "$HTML" | grep -qF 'window.foodkingConfig'; then
    [ "$try" -gt 1 ] && echo "== /kiosk/idle OK au bout de $try essais (fenêtre OPcache passée)"
    break
  fi
  echo "== smoke /kiosk/idle essai $try/5 : HTTP $KCODE, foodkingConfig=$(printf '%s' "$HTML" | grep -c foodkingConfig) — settle 3s"
  sleep 3
done
case "$KCODE" in 200) : ;; *) echo "XX /kiosk/idle HTTP $KCODE (après 5 essais)"; rollback;; esac
printf '%s' "$HTML" | grep -qF 'window.foodkingConfig' \
  || { echo "XX /kiosk/idle ne contient pas window.foodkingConfig après 5 essais (vraie page d'erreur/blanche)"; rollback; }
APPJS="$(php -r '$m=json_decode(file_get_contents("public/mix-manifest.json"),true);echo $m["/js/app.js"]??"";')"
if [ -n "$APPJS" ]; then
  HASH="${APPJS#*id=}"
  if printf '%s' "$HTML" | grep -qF 'app.js'; then
    if printf '%s' "$HTML" | grep -qF "app.js?id=$HASH"; then
      echo "== contenu OK : /kiosk/idle sert app.js?id=$HASH (bundle frais)"
    else
      echo "XX /kiosk/idle sert un app.js au mauvais hash (attendu $HASH) → STALE"; rollback
    fi
  else
    echo "!! /kiosk/idle ne référence pas /js/app.js (autre entrée) — assertion hash sautée"
  fi
fi

# ── 6bis. CORS : le site web (FRONTEND_WEB_DOMAIN) DOIT recevoir l'en-tête
# Access-Control-Allow-Origin, sinon le navigateur du client bloque le checkout en
# ligne (curl seul = FAUX POSITIF, il ignore le CORS ; un préflight 204 sans ACAO
# ne prouve RIEN). WARNING loud, PAS de rollback : un CORS web cassé ne casse pas la
# borne/caisse (backend sain) — mais on le crie pour ne pas livrer un site mort.
WEBDOM="$(env_val FRONTEND_WEB_DOMAIN)"
if [ -n "$WEBDOM" ]; then
  ACAO="$($CURL -D - -o /dev/null -X OPTIONS \
    -H "Origin: $WEBDOM" -H 'Access-Control-Request-Method: GET' \
    "$GOOD/api/frontend/item?branch_id=1" 2>/dev/null | grep -i '^access-control-allow-origin:' || true)"
  if printf '%s' "$ACAO" | grep -qiF "$WEBDOM"; then
    echo "== CORS OK : $WEBDOM reçoit Access-Control-Allow-Origin (checkout web débloqué)"
  else
    WEB_CORS_WARN=1
    echo "!! CORS CASSÉ : $WEBDOM ne reçoit PAS d'Access-Control-Allow-Origin → checkout web bloqué navigateur."
    echo "!!   Corrige : poser FRONTEND_WEB_DOMAIN=$WEBDOM dans .env puis php artisan config:cache."
  fi
fi

# La bannière finale DOIT dire la vérité : un CORS web cassé ne rollback PAS (borne/caisse
# saines) mais NE DOIT PAS s'afficher « ✅ OK » (un opérateur qui survole les logs livrerait
# un checkout en ligne mort). Verdict honnête, sans rollback ni faux échec du deploy backend.
if [ "${WEB_CORS_WARN:-0}" -eq 1 ]; then
  echo "== ⚠️  Déploiement backend OK — MAIS CORS WEB CASSÉ (HEAD $(git rev-parse --short HEAD)) : borne/caisse saines, checkout EN LIGNE probablement BLOQUÉ. Corrige FRONTEND_WEB_DOMAIN + php artisan config:cache, puis re-teste le préflight avant d'annoncer le site en ligne."
else
  echo "== ✅ Déploiement OK — HEAD $(git rev-parse --short HEAD) · triggers vérifiés · healthz vert · contenu frais · CORS web OK."
fi
REMOTE
rc=$?
echo ""
if [ "$rc" -ne 0 ]; then echo "==> ÉCHEC (code $rc) — rollback effectué sur la box si applicable."; exit "$rc"; fi
echo "======================================================================"
echo "==> Terminé. Rappel : le go-live PROD passe d'abord le gate"
echo "==>   APP_ENV=production php artisan app:preflight-production --strict"
echo "==> (voir docs/HANDOVER_SECRETS_REGISTRY.md pour la pose des secrets finaux)."
