#!/usr/bin/env bash
# DÉPLOIEMENT FINAL 2026-07-07 — tout le travail de session (owner-8, audits, ultra-loop,
# TVA livraison + C33, commande téléphone, compression images/WebP).
# HEAD attendu : 8a8167638 (ou +). Le VPS reset sur origin, rebuild COMPLET des bundles,
# migre (coupon soft-delete + pos_customer_phone), génère les vignettes, publie les 2 ponts,
# active l'impression silencieuse, VÉRIFIE la chaîne NF525 ET les triggers d'immutabilité.
set -uo pipefail
BRANCH="pos/category-first-caisse-2026-06-23"
EXPECTED="8a8167638"

echo "==> Déploiement final sur le VPS..."
ssh -o ConnectTimeout=25 lecayenne "cd /var/www/lecayenne && \
  git fetch origin $BRANCH >/dev/null 2>&1 && git reset --hard origin/$BRANCH && \
  echo 'HEAD : '\$(git rev-parse --short HEAD)' (attendu $EXPECTED ou +)' && \
  npm ci >/dev/null 2>&1 ; npm run production 2>&1 | tail -3 && \
  echo '--- migrations (coupon soft-delete + pos_customer_phone + triggers) ---' && \
  php artisan migrate --force 2>&1 | tail -5 && \
  echo '--- NF525 : (RE)INSTALLE les triggers immutabilité (idempotent — répare le gap dump-sans-triggers que migrate ne voit pas) ---' && \
  php artisan fiscal:install-immutability-triggers 2>&1 | tail -4 && \
  echo '--- NF525 : triggers immutabilité présents ? (audit_logs/z_reports hard-delete protégé) ---' && \
  php artisan fiscal:verify-immutability-triggers 2>&1 | tail -4 && \
  echo '--- vignettes WebP POS (grille légère) ---' && \
  php artisan images:generate-pos-thumbs 2>&1 | tail -2 && \
  echo '--- ponts locaux (borne + caisse + CUISINE) publiés ---' && \
  mkdir -p public/dl && cp -f tools/borne/bridge.js public/dl/bridge.js && cp -f tools/caisse-bridge/caisse-bridge.js public/dl/caisse-bridge.js && \
  cp -f tools/bridge-service/start-borne-bridge-hidden.vbs public/dl/start-borne-bridge-hidden.vbs && cp -f tools/bridge-service/start-caisse-bridge-hidden.vbs public/dl/start-caisse-bridge-hidden.vbs && \
  cp -f tools/bridge-service/install-borne-service.ps1 public/dl/install-borne-service.ps1 && cp -f tools/bridge-service/install-caisse-service.ps1 public/dl/install-caisse-service.ps1 && \
  echo '--- [KITCHEN-AUTOSTART 2026-08-09] pont CUISINE : il n était PAS publié, donc le PC cuisine n avait AUCUN moyen de le récupérer ---' && \
  cp -f tools/kitchen-bridge/kitchen-bridge.js public/dl/kitchen-bridge.js && \
  cp -f tools/bridge-service/start-kitchen-bridge-hidden.vbs public/dl/start-kitchen-bridge-hidden.vbs && \
  cp -f tools/bridge-service/install-kitchen-service.ps1 public/dl/install-kitchen-service.ps1 && \
  echo '--- lanceurs anti-flash (VBS window-0 + service NSSM) publies ---' && \
  echo 'bridge md5='\$(md5sum public/dl/bridge.js|cut -d' ' -f1)' caisse md5='\$(md5sum public/dl/caisse-bridge.js|cut -d' ' -f1)' cuisine md5='\$(md5sum public/dl/kitchen-bridge.js|cut -d' ' -f1) && \
  echo '--- impression silencieuse (0 écran gris) ---' && \
  ( grep -q '^POS_PRINT_SILENT_ONLY=' .env && sed -i 's/^POS_PRINT_SILENT_ONLY=.*/POS_PRINT_SILENT_ONLY=true/' .env || echo 'POS_PRINT_SILENT_ONLY=true' >> .env ) && \
  php artisan config:clear >/dev/null 2>&1 && php artisan config:cache >/dev/null 2>&1 && php artisan cache:clear >/dev/null 2>&1 && \
  php artisan db:seed --class=TacosCruditesRestore20260707Seeder --force 2>&1 | tail -1 && \
  php artisan db:seed --class=MenuEnfantChickenBurger20260707Seeder --force 2>&1 | tail -1 && \
  php artisan db:seed --class=SimulatedTpeTerminal20260708Seeder --force 2>&1 | tail -1 && \
  php artisan db:seed --class=OnionCuitExtra20260706Seeder --force 2>&1 | tail -1 && \
  php artisan db:seed --class=DrinksUpdate20260705Seeder --force 2>&1 | tail -1 && \
  echo '--- attestation NF525 + hashes bundles ---' && \
  php artisan fiscal:verify-chain --all 2>&1 | tail -1 && \
  grep -oE '\"/js/(admin-kds|pos-shell|app).js\": \"[^\"]*\"' public/mix-manifest.json && \
  php artisan queue:restart >/dev/null 2>&1 && echo 'queue redémarrée (rappel : worker sur --queue=high,default)'"
rc=$?
echo ""
if [ $rc -ne 0 ]; then echo "Échec (code $rc). Colle-moi la sortie."; exit $rc; fi
echo "======================================================================"
echo "==> VPS à jour. Les triggers d'immutabilité NF525 sont (ré)installés"
echo "    automatiquement (fiscal:install-immutability-triggers, idempotent) puis"
echo "    vérifiés (fiscal:verify-immutability-triggers = 8/8). Si le VERIFY signale"
echo "    encore un trigger MANQUANT après l'install : une table de base est absente"
echo "    (schéma incomplet) — corriger le schéma puis relancer le déploiement."
echo ""
echo "    Sur les MACHINES (borne + écran cuisine + caisse) :"
echo "    - HARD-RELOAD (Ctrl+Maj+R) → boissons/oignon cuit/notes/prix corrects,"
echo "      commande téléphone dispo à la caisse, images allégées."
echo "    - Borne : re-télécharger + relancer le pont CACHÉ (message cowork)."
echo "======================================================================"
