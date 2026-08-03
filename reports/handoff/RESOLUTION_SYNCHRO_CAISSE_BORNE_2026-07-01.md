# Résolution — Synchronisation CAISSE ↔ BORNE (réponse au rapport cowork)

**Date** : 2026-07-01
**Contexte** : le cowork a testé le VPS (`vps-418872ac`) en lecture seule via API et a
remonté 3 problèmes « critiques ». Chaque finding a été **vérifié contre le code réel**
(discipline anti-hallucination). Verdict : **la sync est correcte dans le code actuel ; le
VPS tourne une ANCIENNE version.** Le déploiement résout l'essentiel.

---

## Triage des findings cowork

### 🔴→✅ Finding #1 « KDS mort car `kds_station: null` sur 59 articles » — **NUANCÉ + RÉSOLU**

Deux choses étaient mélangées :

**(a) « KDS mort » = FAUX.** Le KDS **ne filtre PAS par `kds_station`** pour décider si une
commande atteint la cuisine. `KitchenDisplaySystemOrderService::list()` filtre par
`status ∈ visibleStatuses()` + `KitchenReleaseRule::applyBoardReleaseFilter`
(= `payment_status ∈ {PAID, PENDING_COUNTER}` ou POS-cash). **Aucun `WHERE kds_station`.**
Preuve : mes items locaux étaient aussi `kds_station='none'` et **441 commandes étaient
visibles au KDS** (vue « Toutes les stations »). La commande du cowork n'arrivait pas au KDS
à cause de son `payment_status=10 (UNPAID)` (ancien code VPS, voir #3), PAS de la station.

**(b) MAIS le trou de config était RÉEL et je l'ai RÉSOLU.** `kds_station` est un enum
`bar | cuisine_chaude | cuisine_froide | none`, et le KDS a un **filtre par poste**
(`KitchenDisplaySystemComponent` → `filterOrdersByStation`). Avec TOUS les articles à
`none`, dès qu'une cuisine sélectionne un poste (ex. « cuisine chaude »), elle ne voyait
**rien**. → J'ai créé **`KdsStationAssignmentSeeder`** (commit `a8c0517d6`, poussé) qui
assigne un poste réel à chaque article (Boissons→bar, Desserts→cuisine_froide, reste→
cuisine_chaude). **Local : 35/35 articles servis ont une vraie station (0 sans station)** ;
`OrderItemResource` lit la station live → toutes les commandes deviennent filtrables par
poste. Le filtre KDS par station est désormais opérationnel.

### 🔴→✅ Finding #3 « Commande #54 bloquée en Pending 30 min » — **ANCIEN CODE VPS**

La #54 avait `payment_method=0`, `payment_status=10 (UNPAID)`, `pos_payment_method=null`.
- Le code actuel (`FrontendOrderService:219-291`) met `PENDING_COUNTER(15)` +
  `COUNTER_DEFERRED(6)` dès qu'une commande **borne cash** (`payment_method=CASH_ON_DELIVERY=1`)
  arrive, puis **auto-accept** → `status=ACCEPT(4)`.
- **La vraie borne envoie `payment_method=1`** (prouvé : mes 40 commandes E2E d'aujourd'hui =
  toutes `payment_status=15`). Le `payment_method=0` de la #54 = valeur de l'ancien
  bundle/test manuel → ratait la condition → UNPAID orphelin.
- **Preuve end-to-end (code actuel)** : commande #5369 → **file caisse « à encaisser » OUI ✅**
  (requête exacte `counter-collect/pending`) **ET KDS OUI ✅**.

➡️ **Le déploiement de la branche corrige #1 et #3** (le VPS produira PENDING_COUNTER au lieu
d'UNPAID → la commande apparaît à la caisse ET au KDS).

### 🔴 Finding #2 « WebSocket Pusher key "undefined" » — **RÉEL (config/infra VPS)**

Vrai problème d'**environnement**, pas de logique métier :
- La clé est injectée au **BUILD** (`process.env.MIX_PUSHER_APP_KEY`, `bootstrap.js:302`).
  Si `MIX_PUSHER_APP_KEY` est absent au `npm run production`, le bundle obtient `undefined`.
- **Le polling de secours FONCTIONNE déjà** (caisse + KDS lisent la base directement) — donc
  la sync **fonctionne**, juste pas en temps réel (délai 2-13 s).
- **Fix code appliqué (commit `19ffe8ecf`)** : garde anti-« undefined » — si pas de vraie clé,
  Echo n'est PAS initialisé → fallback polling **propre** (plus de tentatives de connexion en
  boucle). Le temps réel s'active dès que l'env + Soketi + Nginx sont configurés (ci-dessous).

---

## CE QUE LE COWORK DOIT FAIRE SUR LE VPS

### Étape 1 — Déployer la nouvelle version (corrige la sync commande)
```bash
cd /var/www/lecayenne
bash tools/deploy-vps.sh /var/www/lecayenne   # git reset --hard origin/<branche> + npm run production + clear caches
php artisan view:clear && php artisan route:clear
```
Branche `pos/category-first-caisse-2026-06-23` (HEAD `19ffe8ecf`) — **déjà poussée**.

### Étape 2 — (Optionnel mais recommandé) Activer le temps réel WebSocket
**Avant** le build, ajouter au `.env` du VPS :
```bash
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=lecayenne
PUSHER_APP_KEY=<clé-longue-aléatoire>
PUSHER_APP_SECRET=<secret-long-aléatoire>
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http
PUSHER_APP_CLUSTER=mt1
# IMPORTANT : les MIX_* sont lus au BUILD (npm run production) → doivent être présents AVANT le build
MIX_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
MIX_PUSHER_HOST="<domaine-public-ou-vps>"   # PAS 127.0.0.1 (le navigateur de la borne doit y accéder)
MIX_PUSHER_PORT=443
MIX_PUSHER_SCHEME=https
MIX_PUSHER_APP_CLUSTER=mt1
```
Puis : lancer le serveur WS + proxy Nginx :
```bash
# Soketi (ou reverb). Exemple Soketi :
soketi start --config=/etc/soketi/config.json   # écoute 127.0.0.1:6001
# Nginx : proxy wss public → 127.0.0.1:6001
#   location /app { proxy_pass http://127.0.0.1:6001; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "Upgrade"; proxy_read_timeout 3600s; }
# Rebuild APRÈS avoir mis les MIX_* :
npm run production && php artisan config:clear
```
**Sans cette étape, la sync marche quand même via polling** (2-13 s). Avec, elle est en temps réel.

### Étape 3 — Vérifier la sync (test réel)
1. Passe une commande à la **vraie borne** (emporter, payer à la borne / à régler en caisse).
2. La commande doit apparaître **immédiatement** (ou sous 2-13 s en polling) dans
   `/admin/pos` → file **« à encaisser borne »** ET sur le **KDS**.
3. Encaisse-la à la caisse → ticket fiscal + le KDS la garde en préparation.

> Si une commande n'apparaît PAS après déploiement : vérifier `payment_status` en base
> (`SELECT payment_status, pos_payment_method FROM orders WHERE id=<X>`). Doit être
> `15` + `6`. Si `10`/`null` → la borne a envoyé `payment_method ≠ 1` (bundle pas à jour /
> cache navigateur) → vider le cache du Chrome de la borne + recharger.

### Étape 4 — Assigner les stations KDS (RÉSOLU par seeder — 1 commande)
Le seeder est déjà dans la branche. Sur le VPS, après déploiement :
```bash
php artisan db:seed --class=KdsStationAssignmentSeeder --force
```
→ chaque article reçoit son poste (Boissons→bar, Desserts→cuisine_froide, plats→cuisine_chaude).
Le filtre par poste du KDS devient utilisable. **Vérif** :
```sql
SELECT kds_station, COUNT(*) FROM items WHERE status=1 GROUP BY kds_station;
-- attendu : bar / cuisine_chaude / cuisine_froide (plus de 'none' sur les plats servis)
```
(Rappel : même sans ça, la cuisine voit les commandes en vue « Toutes les stations ».)

---

## Résumé

| Finding cowork | Verdict | Action |
|---|---|---|
| #1 KDS mort (kds_station null) | ❌ « Mort » faux (KDS ne filtre pas par station pour la cuisine) MAIS ✅ trou de config réel **RÉSOLU** (seeder) | **Déployer + `db:seed --class=KdsStationAssignmentSeeder`** |
| #3 Commande bloquée Pending | ✅ Ancien code VPS (UNPAID au lieu de PENDING_COUNTER) | **Déployer** |
| #2 WebSocket undefined | ⚠️ Réel (config env VPS) | Déployer (fallback propre) + `.env` MIX_PUSHER_* + Soketi + Nginx pour le temps réel |

**La sync caisse↔borne est correcte dans le code (prouvé end-to-end). Stations KDS résolues
par seeder. Il faut DÉPLOYER + lancer le seeder.**

### Commits poussés (origin `pos/category-first-caisse-2026-06-23`)
- `a127fa614` — fix paiement « À RÉGLER EN CAISSE » 3 surfaces + cuisine Menu Enfant
- `19ffe8ecf` — garde Echo anti-« undefined » (fallback polling propre)
- `a8c0517d6` — seeder stations KDS
