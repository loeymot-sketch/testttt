# Journey F — Storefront web client (backend-served)

Date: 2026-07-15 · Serveur LIVE 127.0.0.1:8000 · Onde F

## Statut du parcours

**DEFECTS** (storefront UI désactivé en V1, mais API cliente LIVE et défectueuse).

### Contexte V1 LOCAL
- `STAFF_ONLY_MODE=true` (.env:73, config/features.php:50) → toutes les pages
  vitrine sont supprimées : `/`, `/menu`, `/offers` redirigent vers `/login`
  (web.php:43-46 + frontendRoutes.js:22-25). Aucune page Home/Menu/Offers rendue.
- MAIS les composants SPA `account/myOrder/*`, `checkout/*` existent encore
  (frontendRoutes.js) et **les endpoints `/api/frontend/order/*` sont LIVE et
  fonctionnels** (auth:sanctum + apiKey). Un token client `['*']` y accède.

### Parcours réellement exécuté (curl, token client forgé user_id=211 role=customer)
1. `GET /api/frontend/item?branch_id=1` → 200, catalogue OK.
2. `GET /api/frontend/item/details/22` (Cayenne) → 200, variations sauce+pain.
3. `POST /api/frontend/order/quote` → **refusé** `"Kiosk quote requires a
   registered kiosk machine."` (le quote est réservé borne — attendu).
4. `POST /api/frontend/order` sans variations → 422 garde variations
   (« Sélectionnez au moins 1 Sauce/Type de Pain »). Correct.
5. `POST /api/frontend/order` valide (2×Coca 119 + Cayenne 22 sauce 281/pain 450)
   → **200, order 5694, total 11,20 € exact** (2×1,90 + 7,40), TVA 1,02 €,
   `payment_status=UNPAID(10)`, `status=PENDING(1)`, `source=5(web)`. Pricing SSOT OK.
6. `GET /api/frontend/order` (my-orders) → 200 liste propre.
7. `GET /api/frontend/order/show/5694` (propre) → 200.
8. IDOR : `GET /api/frontend/order/show/5697` (appartient à user 2) → **422
   « Access denied: you do not own this order. »** Protégé.
9. `POST /api/frontend/order/change-status/5694 {status:16,reason}` (self-cancel)
   → **422 « Idempotency requires authenticated user with resolvable branch_id. »**
   (voir F-1). Avec `branch_id:1` ajouté → 200. Cancel OK.

## Findings

### F-1 (P2) — « Annuler ma commande » cassé pour le client web/app (422 message technique brut)
- Le bouton *Cancel Order* du storefront (OrderDetailsComponent.vue:197 →
  store frontendOrder.js:111-121) POST `/frontend/order/change-status/{id}` avec
  payload **`{id, status}` uniquement** — ni `branch_id`, ni `reason`.
- `buildIdempotencyHeaders` (idempotencyHeaders.js:29) génère TOUJOURS une clé →
  le middleware `idempotency` s'active systématiquement.
- `IdempotencyKeyMiddleware::resolveBranchId` (l.182-219) : client = branch_id=0,
  role customer (pas Admin), pas de KioskMachine → tombe sur
  `$request->input('branch_id', -1)` = **-1** → `handle()` l.70-73 lève 422
  « Idempotency requires authenticated user with resolvable branch_id. »
- Message technique brut renvoyé tel quel à l'utilisateur via
  `alertService.error(err.response.data.message)` (OrderDetailsComponent.vue:383).
- Défaut secondaire cumulé : même branch résolu, le payload n'envoie **pas
  `reason`** → OrderStatusRequest.php:64-68 rejette « Reason is required for
  cancel ». Le flux d'annulation client est donc doublement cassé.
- Repro EXÉCUTÉE :
  `POST change-status/5694 {status:16,reason:"..."}` (sans branch_id) → **HTTP 422**
  `{"code":"MISSING_IDEMPOTENCY_KEY","message":"Idempotency requires authenticated
  user with resolvable branch_id."}` ; avec `branch_id:1` → HTTP 200 (cancel réussi).
- Impact V1 : dormant (storefront désactivé STAFF_ONLY_MODE) ; réel dès qu'un
  front web/app client est activé (mobile). Fix côté client : ajouter branch_id +
  reason au payload ; OU côté resolveBranchId : fallback via l'order ciblé.

### F-2 (P3) — `source` accepté du client sans validation (web token peut se déclarer POS=15)
- OrderRequest.php:182 : `'source' => ['required','numeric']` — aucune contrainte
  d'appartenance. Un token client web peut poster `source=15` (POS).
- Repro EXÉCUTÉE : `POST /api/frontend/order {..,source:15,..}` avec token client
  → 200, order 5703 ; DB : `source=15` (POS) persisté, mais `source_surface=web`
  (dérivé serveur, route). Reporting canal utilise `source_surface` (SSOT, fix
  antérieur) → impact limité, mais la colonne brute `source` est polluée et
  trompeuse pour toute requête directe. Défensif : contraindre `source` à
  `Rule::in([5,10])` sur cet endpoint (jamais POS depuis un token non-POS).

## Verdict
Storefront vitrine = DÉSACTIVÉ en V1 LOCAL (choix assumé). Money-path création
commande web = SAIN (pricing SSOT exact, garde variations, IDOR bloqué). 1 défaut
de contrat client↔backend réel mais dormant (F-1 annulation), 1 durcissement
défensif (F-2 source). Aucun P0/P1.
