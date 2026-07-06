#!/usr/bin/env bash
# DEPLOIEMENT GOAL owner-8-problemes (boissons caisse+borne, oignon cuit O̲, notes KDS,
# boissons cuisine, ticket borne=serveur, impression instantanée, perf tuiles, Hawaï).
# PRÉ-REQUIS OWNER : les commits doivent être POUSSÉS sur origin d'abord (gate §10).
#   git push origin pos/category-first-caisse-2026-06-23
set -uo pipefail
BRANCH="pos/category-first-caisse-2026-06-23"
EXPECTED="3e9eef062"   # HEAD attendu ou +

echo "==> Deploiement owner-8-problemes sur le VPS..."
ssh -o ConnectTimeout=25 lecayenne "cd /var/www/lecayenne && \
  git fetch origin $BRANCH >/dev/null 2>&1 && git reset --hard origin/$BRANCH && \
  echo 'HEAD : '\$(git rev-parse --short HEAD)' (attendu $EXPECTED ou +)' && \
  npm ci >/dev/null 2>&1 ; npm run production 2>&1 | tail -3 && \
  echo '--- vignettes POS (tuiles webp <=320px, -97% transfert) ---' && \
  php artisan images:generate-pos-thumbs 2>&1 | tail -2 && \
  echo '--- ponts locaux publies pour telechargement machines ---' && \
  mkdir -p public/dl && cp -f tools/borne/bridge.js public/dl/bridge.js && \
  cp -f tools/caisse-bridge/caisse-bridge.js public/dl/caisse-bridge.js && \
  echo 'bridge.js      md5='\$(md5sum public/dl/bridge.js | cut -d' ' -f1) && \
  echo 'caisse-bridge  md5='\$(md5sum public/dl/caisse-bridge.js | cut -d' ' -f1) && \
  echo '--- impression silencieuse : window.print JAMAIS auto ---' && \
  ( grep -q '^POS_PRINT_SILENT_ONLY=' .env && sed -i 's/^POS_PRINT_SILENT_ONLY=.*/POS_PRINT_SILENT_ONLY=true/' .env || echo 'POS_PRINT_SILENT_ONLY=true' >> .env ) && \
  php artisan config:clear >/dev/null 2>&1 && php artisan config:cache >/dev/null 2>&1 && php artisan cache:clear >/dev/null 2>&1 && \
  php artisan db:seed --class=OnionCuitExtra20260706Seeder --force 2>&1 | tail -1 && \
  php artisan db:seed --class=DrinksUpdate20260705Seeder --force 2>&1 | tail -1 && \
  echo '--- hashes bundles (doivent changer => cache busté) ---' && \
  grep -oE '\"/js/(admin-kds|pos-shell|app).js\": \"[^\"]*\"' public/mix-manifest.json && \
  php artisan fiscal:verify-chain --all 2>&1 | tail -1"
rc=$?
echo ""
if [ $rc -ne 0 ]; then echo "Echec (code $rc). Colle-moi la sortie."; exit $rc; fi
echo "======================================================================"
echo "==> VPS a jour. Sur les MACHINES :"
echo "  1) ECRAN CUISINE : Ctrl+Maj+R -> notes client + boissons (nom complet)"
echo "     + oignon cuit O souligne a cote de STO."
echo "  2) CAISSE : le wizard menu propose les 15 boissons (dont Hawaï), prix"
echo "     inchange ; impression INSTANTANEE (toast), plus d'ecran gris."
echo "  3) BORNE : re-telecharger bridge.js + le lancer CACHE (VBS) -> fin du flash ;"
echo "     ticket borne = design caisse (renderer serveur)."
echo "  Detail machines : reports/handoff/COWORK_VERIF_BORNE_KDS_2026-07-05.md"
echo "======================================================================"
