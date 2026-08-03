# V2 Revalidation — Dormant Blindspots (adversarial)

HEAD `61e9ea7b7` + working tree · live `127.0.0.1:8766` (foodking_e2e) · apiKey `MIX_API_KEY` (shipped in client bundle → effectively public).

Cible : (a) dine-in QR order, (b) delivery-boy cash-sessions, (c) exports Excel, (d) legacy /install, (e) legacy /payment.

## BROKEN

### P1 — Formula/CSV injection dans TOUS les exports Excel (~20 endpoints), amorçable par le champ nom du signup public
- **Fichiers** : `app/Exports/CustomerExport.php:31` (émet `$customer->name` brut) ; binder `vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Cell/DefaultValueBinder.php:61-62` (toute string `=…` → `TYPE_FORMULA`) ; aucun `config/excel.php` (pas de value-binder anti-injection) ; source non assainie `app/Http/Requests/SignupRequest.php:30-31` (`first_name`/`last_name` = `string,max:255`, aucune neutralisation `= + - @`).
- **Repro live** (bootstrap Laravel réel, écriture xlsx en mémoire, aucun write DB) :
  ```
  php /tmp/poc.php  # Excel::store(collect([['=1+1',...,'=cmd|"/c calc"!A1',...]]))
  → A1 value=[=1+1] dataType=[f]
  → C1 value=[=cmd|"/c calc"!A1] dataType=[f]
  ```
  `dataType=f` = FORMULA : le payload DDE est écrit comme formule, pas comme texte.
- **Chaîne d'attaque** : un client s'enregistre via `POST /api/signup/register` avec `first_name="=cmd|'/c calc'!A1"` (validation le laisse passer) → l'owner exporte la liste clients (`GET /api/customer/export`) → ouvre `Customer.xlsx` dans Excel → exécution de formule/DDE (RCE possible) ou exfiltration via `=WEBSERVICE/=HYPERLINK` sur la machine caisse. S'applique aussi aux exports coupons, offres, commandes… (tout champ texte user-controlled).
- **Impact** : injection de formule → potentielle exécution de commande / exfiltration de données sur le poste de l'owner à l'ouverture de l'export. Non listé dans les déférés.

### P2 — IDOR / fuite PII non-authentifiée : GET /api/table/dining-order/show/{id}
- **Fichier** : `routes/api.php:1545` (`show/{frontendOrder}`, groupe middleware `['installed','apiKey','localization']` — PAS d'`auth:sanctum`) → `app/Http/Controllers/Table/OrderController.php:33` (route-model-binding par PK, aucun contrôle d'ownership/token).
- **Repro live** (apiKey seul, aucun bearer) :
  ```
  curl -H "x-api-key: $K" .../api/table/dining-order/show/5410 → 200
  curl -H "x-api-key: $K" .../api/table/dining-order/show/100  → 200
  ```
  Corps → objet `user` complet d'autrui : `name`, `phone` (`0663479828`), `email`, `balance`. IDs séquentiels 1..5410 énumérables.
- **Impact** : n'importe qui possédant l'apiKey (donc n'importe quel visiteur du bundle borne/web) énumère toutes les commandes et récupère nom/téléphone/email/solde de tous les clients. Dine-in dormant mais route montée et servie sur des données réelles.

## HELD-GREEN (attaques tentées → échec = robuste)

- **Legacy /payment (IDOR {order} + forge success non signé)** : `guardWebPaymentV1()` (PaymentController.php:131) `abort(404)` si `config('payment.web_payment_v1.enabled')` (défaut `false`, `config/payment.php:15`). Live : `GET /payment/1/pay`→404, `GET /payment/stripe/1/success`→404. La forge de succès gateway est inatteignable.
- **Legacy /install** : `InstallerController::__construct` (:28-30) redirige vers `APP_URL` si `storage/installed` existe. Live : `GET /install`→302, `GET /install/database`→302. Re-confirmé SAFE.
- **Exports — authz** : `GET /api/customer/export` sans token → 200 mais corps = **shell SPA HTML** (redirection auth→login), pas de CSV/PII. Le groupe admin (`routes/api.php:302`) impose `auth:sanctum` ; `CustomerController::__construct:29-35` gate `permission:customers`. Pas de bypass d'auth.
- **Dine-in QR — pricing SSOT** : `OrderService::tableOrderStore` (:1349) force `discount=0` (neutralise remise manuelle anonyme), recalcule via `PricingService::calculateOrder` (`use_ssot_service`), et `assertDiscretionaryDiscountAllowed` (:1395) refuse toute remise coupon non autorisée avant signature Z. Prix client ignorés (`unset total/subtotal/discount` :1354). Robuste.
- **Delivery-boy cash-sessions** : `DeliveryBoyCashSessionController` — `permission:delivery-boys_show` (read) + `permission:delivery-boys` (open/close/reconcile) ; BranchScope via route-model-binding (404 cross-branch) ; garde write-path cross-branch explicite `open()` :146-151 (403). Pas d'IDOR ni de bypass d'autorisation trouvé.

## Note (PLAUSIBLE, non exécutée — no-write policy)
- `POST /api/table/dining-order/` fixe `user_id = customer_id` (OrderService.php:1358) sans vérifier que l'appelant possède ce `customer_id` (TableOrderRequest:36 = `numeric` seul, endpoint apiKey-only non-auth). Un client QR anonyme pourrait rattacher une commande table à n'importe quel `user_id` (pollution historique/loyalty). Non prouvé en live (nécessiterait un write DB). Dine-in dormant en V1.
