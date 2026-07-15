#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# DÉPLOIEMENT PROD — 2026-07-15 (demandé owner : « push et deploy et test-e2e »)
# Cible : origin/pos/category-first-caisse-2026-06-23 @ 8399ba5c1
#
# Ce que ça déploie (sessions 2026-07-15) :
#  - RUPTURE 86 caisse+cuisine : permission availability_toggle + panel partagé
#    POS/KDS → propagation borne/caisse/web temps réel.
#  - CARNET PIN (/carnet) : dépenses, acomptes travailleurs, notes, photos
#    factures (disque privé gated PIN), résumés mois.
#  - Heals audit adversarial (13) + heals superviseur (P1 stock destroy
#    withTrashed, borne branch-aware, boot-guard PIN, N+1).
#  - Heals session parallèle (test réel Web, bol cuisine, ticket RENDU, etc.).
#
# ⚠ BOOT-GUARD : la prod REFUSE de démarrer si DAILY_BOOK_PIN reste '2468'.
#   Ce script génère un PIN 6 chiffres s'il est absent du .env VPS et
#   l'AFFICHE — l'owner peut le changer ensuite (puis config:cache).
#
# PREUVES à vérifier dans la sortie :
#   1) HEAD apres = 8399ba5c1
#   2) TRIGGERS OK — 9/9 · CHAIN OK · smoke kiosk/idle 200 · /carnet 200
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail
BRANCH="pos/category-first-caisse-2026-06-23"
EXPECTED="8399ba5c1"

echo "==> Déploiement prod sur le VPS (lecayenne)…"
ssh -o ConnectTimeout=25 lecayenne "cd /var/www/lecayenne && \
  echo '== HEAD avant : '\$(git rev-parse --short HEAD) && \
  git fetch origin $BRANCH >/dev/null 2>&1 && git reset --hard origin/$BRANCH 2>&1 | tail -1 && \
  echo '== HEAD apres : '\$(git rev-parse --short HEAD)' (attendu $EXPECTED)' && \
  if ! grep -q '^DAILY_BOOK_PIN=' .env; then \
    PIN=\$(( (RANDOM % 900000) + 100000 )); \
    printf '\nDAILY_BOOK_PIN=%s\n' \"\$PIN\" >> .env; \
    echo \"== CARNET PIN généré (à changer si tu veux) : \$PIN ==\"; \
  else echo '== DAILY_BOOK_PIN déjà défini dans .env =='; fi && \
  echo '== BUILD bundles (npm run production)…' && \
  ( npm ci --no-audit --no-fund >/dev/null 2>&1 || npm install >/dev/null 2>&1 ) ; npm run production 2>&1 | tail -2 && \
  echo '== MIGRATIONS ==' && php artisan migrate --force 2>&1 | tail -3 && \
  echo '== SEEDER permission rupture ==' && php artisan db:seed --class=AvailabilityTogglePermissionSeeder --force 2>&1 | tail -1 && \
  echo '== NF525 triggers ==' && \
  php artisan fiscal:install-immutability-triggers 2>&1 | tail -1 && \
  php artisan fiscal:verify-immutability-triggers 2>&1 | tail -1 && \
  echo '== CONFIG cache ==' && php artisan config:clear >/dev/null && php artisan config:cache >/dev/null && php artisan cache:clear >/dev/null && echo 'config OK' && \
  echo '== QUEUE restart ==' && php artisan queue:restart >/dev/null && echo 'queue OK' && \
  echo '== NF525 chain ==' && php artisan fiscal:verify-chain --all 2>&1 | tail -1 && \
  echo '== SMOKE (nginx, Host requis — le port 8766 n existe plus) ==' && \
  for p in kiosk/idle carnet admin/pos login; do curl -sk -o /dev/null -w "\$p: %{http_code}\n" -H 'Host: vps-418872ac.vps.ovh.net' https://127.0.0.1/\$p; done"
rc=$?
echo ""
if [ $rc -ne 0 ]; then echo "Échec (code $rc)."; exit $rc; fi
echo "======================================================================"
echo "==> Terminé. Vérifie : HEAD apres = $EXPECTED · TRIGGERS 9/9 · CHAIN OK · smokes 200."
