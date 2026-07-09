# 🧹 MISSION COWORK (ULTRA) — Éradiquer TOUTE ancienne version / cache / résidu (caisse + borne)

> Symptôme owner : le ticket qui **s'imprime** ne ressemble PAS à ce qui **s'affiche à l'écran**
> (ancien format) + **deux tickets de cuisine** sortent.
> **Diagnostic prouvé côté code** : le code ACTUEL est CORRECT — ticket client riche + cuisine symbolique
> (rendu ESC/POS serveur = l'aperçu écran), et le **board KDS N'IMPRIME PLUS** (commit 372b1a351 → un
> SEUL ticket cuisine). Donc **l'ancien ticket + le double cuisine = une ANCIENNE VERSION / un ANCIEN
> BUNDLE / un service worker / un cache qui tournent ENCORE sur la machine.** Cette mission les éradique.

Branche = `pos/category-first-caisse-2026-06-23`, **HEAD `61e9ea7b7`** (+ heals V2 non commités — voir avec l'owner).

---

## PHASE A — SERVEUR (VPS) : que le RENDU vienne du code actuel
```bash
ssh lecayenne && cd /var/www/lecayenne
git fetch origin pos/category-first-caisse-2026-06-23
git reset --hard origin/pos/category-first-caisse-2026-06-23     # écrase toute dérive/hot-patch
npm ci && npm run production                                      # REBUILD COMPLET des bundles (fini les bundles périmés)
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear
php artisan config:cache
git rev-parse --short HEAD                                        # == 61e9ea7b7 (ou +)
```
> Le ticket est rendu par le SERVEUR (`OrderReceiptEscPosRenderer`, endpoint `escpos`). Si le VPS tourne
> l'ancien code → il rend l'ancien ticket. Ce reset+rebuild garantit le rendu actuel.

## PHASE B — MACHINES (caisse + borne, Windows) : purge TOTALE du cache navigateur
Sur CHAQUE machine, dans l'ordre :
1. **Fermer Chrome COMPLÈTEMENT** (Task Manager → tuer tous les `chrome.exe`).
2. **Désenregistrer TOUS les service workers** : ouvrir Chrome → `chrome://serviceworker-internals` →
   **Unregister** chaque entrée pointant vers le domaine (lecayenne / vps-ovh). C'est LA cause n°1 d'une
   « ancienne version » qui colle (le SW sert l'ancien bundle hors-ligne).
3. **Vider tout le stockage du site** : `chrome://settings/content/all` → chercher le domaine → **Supprimer
   les données** (cache, cookies, IndexedDB, localStorage, Cache Storage). OU `chrome://settings/clearBrowserData`
   → « Toutes les périodes » → images/fichiers en cache + cookies.
4. **Supprimer le cache disque** (résidu) : fermer Chrome, supprimer
   `%LOCALAPPDATA%\Google\Chrome\User Data\Default\Cache` et `...\Service Worker` et `...\Code Cache`.
5. **Relancer** avec un rechargement dur (`Ctrl+Shift+R`) ; vérifier que la page charge le NOUVEAU bundle.

## PHASE C — ÉLIMINER LES DOUBLONS D'AUTO-DÉMARRAGE (cause du « deux tickets » + double kiosk)
Dans le dossier Démarrage (`shell:startup`), il ne doit rester QUE les fichiers actuels :
- ✅ GARDER : `borne-kiosk.vbs`, `borne-bridge.vbs`.
- ❌ SUPPRIMER les ANCIENS (ils relancent un 2e Chrome / un 2e pont / un ancien chemin d'impression) :
  `Borne Le Cayenne.lnk`, `start-bridge.vbs`, et tout autre `.lnk`/`.vbs` kiosk/print antérieur.
```powershell
# lister le dossier Startup
ls ([Environment]::GetFolderPath(7))
# supprimer les anciens (adapter les noms vus)
Remove-Item ([Environment]::GetFolderPath(7) + "\Borne Le Cayenne.lnk") -EA 0
Remove-Item ([Environment]::GetFolderPath(7) + "\start-bridge.vbs") -EA 0
```
> ⚠️ **Un 2e pont d'impression OU un ancien Chrome-print = un 2e ticket cuisine.** Un seul pont
> (`127.0.0.1:9100`) et un seul Chrome kiosk doivent tourner. Vérifier : `Get-Process node`, `Get-Process chrome`.

## PHASE D — UN SEUL PONT D'IMPRESSION
- Tuer tout node en double : `Get-Process node | Stop-Process -Force` puis relancer via `borne-bridge.vbs`.
- Vérifier `http://127.0.0.1:9100/health` = « UP » (un seul répondeur).
- Chrome lancé avec `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
  (sinon le pont est bloqué → Chrome retombe sur `--kiosk-printing` = ancien HTML illisible).

## PHASE E — VÉRIFIER (le ticket imprimé == l'écran, UNE fois chacun)
1. Passer une commande composée (Tacos L 2 viandes) sur la borne ET une sur la caisse.
2. ✅ **Ticket CLIENT** imprimé = l'aperçu écran : resto + adresse + À EMPORTER + produits + compo + TVA + total.
3. ✅ **Ticket CUISINE** imprimé = symbolique `G | TACOS | L | Mex Cordon | STO | ALG` + suppléments + `MENU`,
   **SANS prix**, et **UN SEUL** exemplaire (plus de double).
4. ✅ Comparer visuellement : imprimé == écran. Si encore l'ancien format → un SW/cache a survécu (refaire B)
   OU le VPS n'a pas rebuild (refaire A). Si 2 tickets cuisine → un doublon de Startup/pont survit (refaire C/D).

## PHASE F — PROCESSUS DE MISE À JOUR (pour toujours, sans résidu)
À chaque future update, sur CHAQUE machine :
1. Serveur : `git → deploy.sh` (reset+rebuild). 2. Machine : **désenregistrer les SW + Ctrl+Shift+R**.
> Le hash du bundle change à chaque build (`mix-manifest.json`) → le navigateur recharge — SAUF si un
> service worker sert l'ancien. **Toujours désenregistrer les SW après une update** = la règle d'or anti-résidu.

## À RAPPORTER (photos)
- `git HEAD` VPS == 61e9ea7b7 · `chrome://serviceworker-internals` vide pour le domaine · dossier Startup
  = seulement les 2 VBS actuels · `Get-Process node/chrome` = 1 seul chacun · ticket client + cuisine
  physiques == écran, UN seul cuisine.
