# MISSION COWORK — INSTALLATION DÉFINITIVE (une fois, sans faute, pour toujours)

> Objectif owner : installer le système **correctement une seule fois**, sans faute ni bug,
> ni maintenant ni dans le futur. **Plus jamais de problème de « vieille version »** : chaque
> mise à jour future doit être propre, sans dérive, sans crash, sans bug caché.

**POURQUOI il y avait des problèmes** (à comprendre) : quasi TOUS les bugs vus par le cowork
(KDS vide, « Viande 2 actuel:0 », etc.) viennent d'UNE chose : **le VPS tourne l'ANCIEN code**.
Les correctifs sont committés + poussés sur la branche, mais **le déploiement n'a pas été fait
proprement** — et pire, le VPS a pu recevoir des « hot-patches » SCP qui créent une dérive
(mélange ancien/nouveau). Cette mission élimine ça DÉFINITIVEMENT.

Branche source de vérité : **`pos/category-first-caisse-2026-06-23`** — HEAD **`3d905eb80`**.

---

## RÈGLE D'OR (à respecter POUR TOUJOURS)
> **On ne modifie JAMAIS le serveur à la main / par SCP.** Tout changement passe par :
> **git commit → git push → deploy.sh**. Le déploiement fait un `git reset --hard` + rebuild
> COMPLET → aucune dérive possible. Un fichier posé « à la main » sur le VPS = future panne.

---

## ÉTAPE 1 — INSTALLATION PROPRE (efface toute dérive)

```bash
ssh lecayenne
cd /var/www/lecayenne

# 1a. Vérifier s'il y a des hot-patches locaux (dérive) sur le VPS
git status --short
# S'il y a des lignes (fichiers M/??): ce sont des patches manuels = à ÉCRASER (c'est la source des bugs).
#   → sauvegarder par sécurité: git stash push -m "hot-patches-avant-install-propre-2026-07-01"
#     puis on les jette (git reset --hard ci-dessous les remplace par la version propre).

# 1b. Déploiement PROPRE de la branche (reset dur + rebuild COMPLET depuis la source)
sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
#   → git fetch + git reset --hard origin/<branche> (efface toute dérive)
#   → npm ci && npm run production (RECONSTRUIT tous les bundles = jamais de bundle périmé)
#   → migrations + vérif chaîne NF525

# 1c. Vider TOUS les caches serveur
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear
```

## ÉTAPE 2 — RÉGLAGES (une fois)
```bash
# 2a. Largeur imprimante 58mm (anti-coupure ticket) — 32 (mettre 48 si papier 80mm)
php artisan tinker
>>> \App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(['width_chars' => 32]);
>>> exit
# 2b. Stations KDS
php artisan db:seed --class=KdsStationAssignmentSeeder --force
```

## ÉTAPE 3 — FORCER LA NOUVELLE VERSION SUR LA BORNE + CAISSE (fini le cache old-version)
Sur CHAQUE machine (borne, caisse, écran cuisine) — le Chrome peut garder l'ancienne SPA en cache :
1. Fermer Chrome complètement.
2. Vider le cache + les **service workers** :
   - Chrome → `chrome://serviceworker-internals` → **Unregister** tout ce qui pointe vers lecayenne.fr.
   - Ou lancer Chrome avec un profil neuf, OU `--disk-cache-dir` vidé.
3. Relancer Chrome avec le flag impression :
   `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
4. Recharger la borne (`Ctrl+Shift+R` = hard reload).
> Le `mix-manifest.json` casse le cache par hash à chaque build — mais un service worker peut
> quand même servir l'ancien. Le désenregistrer une fois règle le problème définitivement.

## ÉTAPE 4 — VÉRIFIER QUE LA BONNE VERSION TOURNE (pas l'ancienne)
```bash
ssh lecayenne 'cd /var/www/lecayenne && git rev-parse --short HEAD'   # doit afficher 3d905eb80 (ou plus récent)
```
- Bundle servi == buildé : `curl -s https://lecayenne.fr/mix-manifest.json | head` (hash cohérent).
- Borne : `/kiosk` affiche l'attract, pas d'écran blanc.

---

## ÉTAPE 5 — TESTER TOUT (les bugs du cowork sont corrigés PAR le déploiement)

### 5a. Multi-viandes (le bug « Viande 2 actuel:0 » — CORRIGÉ)
Composer **Tacos L / Méga / Terminator** avec **2 viandes différentes** → doit s'ajouter au panier
SANS l'erreur « Sélectionnez au moins 1 Viande 2 ». Les 2 viandes doivent apparaître au panier + ticket.
> Avant (ancien code) : la 2e viande était perdue → erreur au paiement. Après déploiement : OK.

### 5b. KDS reçoit les commandes (le « KDS vide » — CORRIGÉ)
Passer une **NOUVELLE** commande borne APRÈS le déploiement → elle doit apparaître sur le KDS
(`/admin/kitchen-display-system`), avec ses produits.
> Le KDS vide N'ÉTAIT PAS `kds_station` (le KDS ne filtre pas par station). C'était l'ancien code
> qui mettait la commande en `UNPAID` au lieu de `PENDING_COUNTER` → filtrée. Le déploiement met
> `PENDING_COUNTER` → visible. (Les commandes créées AVANT le déploiement restent invisibles : normal.)

### 5c. Prix
- Cayenne + Menu = **9,90 €** (pas 10). Monnaie 20 € sur 9,90 → **10,10 €** (pas 11,10).
- Cayenne + burgers = **AUCUN choix de viande** (recette fixe). Viande supplémentaire = **+2,50 €**.

### 5d. Tickets (caisse ET borne, client ET cuisine)
- Prix **entiers** (« 12,90 € » jamais coupé), même forme caisse=borne, cuisine = symboles + suppléments sans prix.

### 5e. NF525 (PAS un bug)
- `fiscal_sequence_no` est **null AVANT encaissement** (normal : le n° fiscal est alloué à l'ENCAISSEMENT,
  pas à la création borne). Après avoir **encaissé** la commande à la caisse → le n° fiscal apparaît.

---

## ÉTAPE 6 — FUTURES MISES À JOUR (le « pour toujours »)
Chaque fois que tu veux mettre à jour, **le même processus propre** (jamais de dérive) :
```bash
# 1. (dev) commit + push sur la branche
# 2. sur le VPS :
ssh lecayenne
cd /var/www/lecayenne
sudo LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23 bash scripts/deploy/deploy.sh
php artisan config:clear && php artisan cache:clear && php artisan view:clear
# 3. sur les machines : hard reload Chrome (Ctrl+Shift+R) — le hash casse le cache.
```
> Comme le déploiement fait TOUJOURS `git reset --hard` + rebuild complet, il n'y a **jamais** de
> mélange ancien/nouveau. Tant que personne ne pose de fichier à la main (règle d'or), ça reste propre.
> **Recommandation permanente** : figer `LECAYENNE_BRANCH=pos/category-first-caisse-2026-06-23`
> dans le `.env`/config de deploy (ou fusionner la branche dans `main`) pour ne plus jamais avoir à
> le préciser.

---

## À RAPPORTER
- `git HEAD` sur le VPS == `3d905eb80` (ou +).
- Multi-viandes : 2 viandes OK, plus d'erreur « Viande 2 ».
- KDS : nouvelle commande borne visible (avec produits).
- Prix : Cayenne+Menu 9,90 / monnaie 10,10 / viande suppl 2,50.
- Tickets caisse == borne, prix entiers, 0 coupure.
- Photos de chaque point. **Ne pas clore si un seul cas casse — signaler produit + étape.**
