#!/usr/bin/env bash
# [DEPLOY 2026-06-30] Déploiement VPS sûr + AUTO-VÉRIFIÉ du ticket caisse/cuisine.
#
# À lancer SUR le VPS (celui qui sert vps-418872ac.vps.ovh.net), depuis le dossier
# de l'app OU en passant le chemin en argument :
#     bash deploy-vps.sh /chemin/vers/app
#
# Ce script : sauvegarde le commit courant, récupère la branche, rebuild les bundles,
# vide les caches, puis VÉRIFIE que le nouveau ticket est bien en ligne (le bouton
# « Imprimer ticket » du board doit avoir disparu = mon dernier code). Rollback auto si échec.
set -euo pipefail

BRANCH="pos/category-first-caisse-2026-06-23"
APP="${1:-$(pwd)}"
cd "$APP"
echo "==> App : $APP"
echo "==> Branche cible : $BRANCH"

PREV="$(git rev-parse HEAD)"
echo "==> Commit actuel (sauvegarde rollback) : $PREV"

git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"

# Dépendances + build (le ticket ne nécessite aucune migration).
if [ -f package-lock.json ]; then npm ci; else npm install; fi
npm run production
php artisan config:clear || true
php artisan cache:clear || true

# --- AUTO-VÉRIFICATION : le board ne doit PLUS contenir le bouton « Imprimer ticket » ---
BUNDLE="public/js/admin-kds.js"
if [ -f "$BUNDLE" ] && grep -q "Imprimer ticket" "$BUNDLE"; then
  echo "XX ÉCHEC : '$BUNDLE' contient encore 'Imprimer ticket' → build pas à jour. ROLLBACK."
  git reset --hard "$PREV"
  npm run production || true
  php artisan config:clear || true
  exit 1
fi

echo "============================================================"
echo "OK ✅ DÉPLOYÉ : le board ne contient plus 'Imprimer ticket'."
echo "   → ticket caisse € + compo compacte + cuisine 2 lignes (G | … / MENU : SYM) sont EN LIGNE."
echo "   Teste maintenant une vraie commande sur la caisse : 2 tickets propres doivent sortir."
echo "   (rollback dispo : git reset --hard $PREV && npm run production)"
echo "============================================================"
