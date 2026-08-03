#!/usr/bin/env bash
# [DEPLOY 2026-06-30, durci ULTRA 2026-07-04] Déploiement VPS sûr + AUTO-VÉRIFIÉ.
#
# À lancer SUR le VPS, depuis le dossier de l'app OU en passant le chemin en argument :
#     bash deploy-vps.sh /chemin/vers/app
#
# Étapes : sauvegarde du commit courant → pull branche → npm build COMPLET (jamais de SCP
# partiel — leçon écran-blanc : app.js neuf + vendor.js stale = Vue NOT_MOUNTED) → migrations
# → caches → VÉRIFICATIONS (jeu complet de bundles via mix-manifest + fraîcheur + sonde de
# contenu) → queue:restart (queue HIGH — l'oublier = broadcasts temps réel morts en prod)
# → attestation NF525. Rollback auto si une vérification échoue.
set -euo pipefail

BRANCH="pos/category-first-caisse-2026-06-23"
APP="${1:-$(pwd)}"
cd "$APP"
echo "==> App : $APP"
echo "==> Branche cible : $BRANCH"

PREV="$(git rev-parse HEAD)"
DEPLOY_START="$(date +%s)"
echo "==> Commit actuel (sauvegarde rollback) : $PREV"

rollback() {
  echo "XX ROLLBACK vers $PREV"
  git reset --hard "$PREV"
  npm run production || true
  php artisan config:clear || true
  php artisan queue:restart || true
  exit 1
}

git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"

# Dépendances + build COMPLET (le build serveur régénère TOUT le jeu de bundles d'un bloc).
if [ -f package-lock.json ]; then npm ci; else npm install; fi
npm run production

# Migrations (additives, 178/178 réversibles — kitchen-timing + durability indexes en dépendent).
php artisan migrate --force

php artisan config:clear || true
php artisan cache:clear || true
php artisan view:clear || true
php artisan route:clear || true

# --- VÉRIF 1 : JEU COMPLET de bundles (leçon écran-blanc 2026-06-29) -------------------
# Chaque fichier référencé par mix-manifest.json doit exister ET dater de CE build.
MANIFEST="public/mix-manifest.json"
if [ ! -f "$MANIFEST" ]; then
  echo "XX ÉCHEC : $MANIFEST absent après build."
  rollback
fi
MISSING=0; STALE=0
while IFS= read -r rel; do
  f="public${rel%%\?*}"
  if [ ! -f "$f" ]; then
    echo "XX bundle MANQUANT : $f"; MISSING=1
  elif [ "$(stat -c %Y "$f" 2>/dev/null || stat -f %m "$f")" -lt "$DEPLOY_START" ]; then
    echo "XX bundle STALE (antérieur au build) : $f"; STALE=1
  fi
done < <(php -r '$m=json_decode(file_get_contents("public/mix-manifest.json"),true);foreach($m as $k=>$v){echo $k,"\n";}')
if [ "$MISSING" -ne 0 ] || [ "$STALE" -ne 0 ]; then
  echo "XX ÉCHEC : jeu de bundles incomplet/périmé → app+vendor incohérents = écran blanc."
  rollback
fi

# --- VÉRIF 2 : sonde de contenu (le code attendu est bien dans le build) ----------------
BUNDLE="public/js/admin-kds.js"
if [ -f "$BUNDLE" ] && grep -q "Imprimer ticket" "$BUNDLE"; then
  echo "XX ÉCHEC : '$BUNDLE' contient encore 'Imprimer ticket' → build pas à jour."
  rollback
fi

# --- QUEUE : broadcasts temps réel (KDS/OSS/caisse) roulent sur la lane HIGH ------------
# queue:restart signale les workers supervisés de recharger le nouveau code. Si AUCUN
# worker high,default ne tourne, l'app "marche" mais AUCUN événement temps réel ne part
# (l'omission historique des runbooks) → on prévient BRUYAMMENT.
php artisan queue:restart || true
if ! pgrep -f "queue:work.*high" >/dev/null 2>&1; then
  echo "!! ATTENTION : aucun worker 'queue:work --queue=high,default' détecté."
  echo "!!   → lancer/superviser :  php artisan queue:work --queue=high,default --timeout=120 --tries=1"
  echo "!!   Sans lui : broadcasts KDS/OSS/caisse MORTS (polling de secours seul)."
fi

# --- ATTESTATION NF525 post-déploiement --------------------------------------------------
if ! php artisan fiscal:verify-chain --all; then
  echo "XX ÉCHEC : chaîne NF525 KO après déploiement."
  rollback
fi

echo "============================================================"
echo "OK ✅ DÉPLOYÉ + VÉRIFIÉ ($(git rev-parse --short HEAD))"
echo "   • Jeu de bundles complet + frais (mix-manifest intégral)"
echo "   • Migrations appliquées, caches purgés"
echo "   • queue:restart envoyé (vérifier le worker high,default ci-dessus)"
echo "   • Chaîne NF525 attestée"
echo "   Teste une vraie commande borne→caisse→KDS : synchro temps réel + 2 tickets."
echo "   (rollback : git reset --hard $PREV && npm run production)"
echo "============================================================"
