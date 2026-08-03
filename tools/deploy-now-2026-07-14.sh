#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# DÉPLOIEMENT PROD — 2026-07-14
# Cible : origin/pos/category-first-caisse-2026-06-23 @ 6b2d762ea
# À LANCER PAR L'OWNER (human-gate prod) :  bash tools/deploy-now-2026-07-14.sh
#
# Ce que ça déploie (session 2026-07-13/14) :
#  - Impression cuisine KDS : auto + bouton Réimprimer + résilience (0 ticket perdu,
#    retry auto, badge échec, pont timeout/respawn, anti-doublon) + panneau "Commandes
#    web" caisse masqué si pas online-orders.
#  - NF525 : trigger order_items_composition_snapshot_no_update RESTAURÉ (prod est à 8/8,
#    ce déploiement passe à 9/9 → ferme la faille SQL brut sur le snapshot fiscal scellé).
#  - Heals audit superviseur : split overpay cash-cap, unicité extra, garde catégorie
#    sous-cat, TVA preview borne.
#
# PREUVES DE CONVERGENCE PROD à vérifier dans la sortie :
#   1) HEAD apres = 6b2d762ea
#   2) IMMUTABILITY TRIGGERS OK — 9/9   (et NON 8/8)
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail
BRANCH="pos/category-first-caisse-2026-06-23"
EXPECTED="6b2d762ea"

echo "==> Déploiement prod sur le VPS (lecayenne)…"
ssh -o ConnectTimeout=25 lecayenne "cd /var/www/lecayenne && \
  echo '== HEAD avant : '\$(git rev-parse --short HEAD) && \
  git fetch origin $BRANCH >/dev/null 2>&1 && git reset --hard origin/$BRANCH 2>&1 | tail -1 && \
  echo '== HEAD apres : '\$(git rev-parse --short HEAD)' (attendu $EXPECTED)' && \
  echo '== BUILD bundles (npm run production)…' && \
  ( npm ci --no-audit --no-fund >/dev/null 2>&1 || npm install >/dev/null 2>&1 ) ; npm run production 2>&1 | tail -2 && \
  echo '== MIGRATIONS ==' && php artisan migrate --force 2>&1 | tail -4 && \
  echo '== NF525 triggers (install + verify = doit dire 9/9) ==' && \
  php artisan fiscal:install-immutability-triggers 2>&1 | tail -1 && \
  php artisan fiscal:verify-immutability-triggers 2>&1 | tail -1 && \
  echo '== CONFIG cache ==' && php artisan config:clear >/dev/null && php artisan config:cache >/dev/null && php artisan cache:clear >/dev/null && echo 'config OK' && \
  echo '== QUEUE restart ==' && php artisan queue:restart >/dev/null && echo 'queue OK' && \
  echo '== NF525 chain ==' && php artisan fiscal:verify-chain --all 2>&1 | tail -1 && \
  echo '== SMOKE ==' && curl -s -o /dev/null -w 'kiosk/idle: %{http_code}\n' http://127.0.0.1:8766/kiosk/idle"
rc=$?
echo ""
if [ $rc -ne 0 ]; then echo "Échec (code $rc). Colle-moi toute la sortie ci-dessus."; exit $rc; fi
echo "======================================================================"
echo "==> Terminé. Vérifie : HEAD apres = $EXPECTED  ET  TRIGGERS OK — 9/9."
echo "    Puis sur le PC cuisine : node tools/kitchen-bridge/kitchen-bridge.js (KITCHEN_PRINTER=<nom>)."
