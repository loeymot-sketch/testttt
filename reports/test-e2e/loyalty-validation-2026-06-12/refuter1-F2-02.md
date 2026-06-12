# REFUTER lane 1 — F2-02 (admin ['*'] token classé kiosk machine par OrderStatusRequest)

Date: 2026-06-12 · Harnais :8767 / foodking_e2e · Read-only code, mutations DB e2e autorisées.

## 1. Vérification file:line (grep/Read) — TOUT CONFIRMÉ
- `app/Http/Requests/OrderStatusRequest.php:93` → `return (bool) $user->tokenCan('kiosk:order');` (dans `actorIsKioskMachine()`) ✓
- `app/Http/Requests/OrderStatusRequest.php:75` → `'Reason code is not whitelisted for kiosk-originated transitions.'` ✓
- `app/Http/Requests/OrderStatusRequest.php:43-44` → commentaire `[AUDIT-F-004]` promettant « admin/staff actors keep free-text capability for back-compat » ✓
- `app/Http/Controllers/Auth/LoginController.php:157-161` → `$user->createToken('auth_token', ['*'], ...)` ✓
- `vendor/laravel/sanctum/src/PersonalAccessToken.php::can()` → `in_array('*', $this->abilities) || ...` ⇒ un token `['*']` satisfait `tokenCan('kiosk:order')` ✓ (mécanisme confirmé au niveau lib)
- Route: `routes/api.php:1001` `POST /api/admin/pos-order/change-status/{order}` → `PosOrderController::changeStatus` (PosOrderController.php:312) type-hinté `OrderStatusRequest` ✓
- `OrderStatus::CANCELED = 16` (app/Enums/OrderStatus.php:13) ✓ ; enum `OrderCancelReason` contient `customer_request` (app/Enums/OrderCancelReason.php:23) ✓

## 2. Repro live (:8767, foodking_e2e) — REPRODUIT
Token admin créé via tinker: `User admin@lecayenne.fr (id=1)->createToken('refuter-f202', ['*'])` → token 2771.
Ordre cible: order_id=4547 (status=4, non-terminal). Header `x-api-key` requis (ApiKeyMiddleware) ajouté.

A) Free-text:
```
POST /api/admin/pos-order/change-status/4547  Bearer <admin ['*']>  {"status":16,"reason":"F2 refuter free-text reversal test"}
→ HTTP 422 {"message":"Reason code is not whitelisted for kiosk-originated transitions.","errors":{"reason":["Reason code is not whitelisted for kiosk-originated transitions."]}}
```
B) Enum:
```
POST /api/admin/pos-order/change-status/4547  même token  {"status":16,"reason":"customer_request"}
→ HTTP 200 (OrderDetailsResource, order 4547 annulé)
```
⇒ Exactement l'évidence du finding. Un admin authentifié par token Sanctum `['*']` est traité comme kiosk machine ; le chemin free-text documenté (:43-44) est mort pour tout acteur token-auth.

## 3. Dedup
- `grep -rn "not whitelisted for kiosk" reports/` → seul lot antérieur = `pos-kds-sync-2026-05-10` (E-001, rounds 3-4) : la MÊME 422 a été observée live (cancel POS opérateur avec reason libre) mais filée comme **P0 silent-error UI** et fermée par un banner persistant (commit 7e3c8069b) — la 422 y était considérée « deliberate ». Aucun rapport antérieur n'identifie la cause racine (ability `*` ⇒ actorIsKioskMachine vrai pour les admins, free-text back-compat inaccessible). PAS un dedup des lots release/v1 A-H ni dashboard-deep 06-08 (grep négatif).

## 4. Sévérité
- Pas de régression sécurité (comportement PLUS restrictif), pas d'impact NF525, pas de casse UI (le dialog POS envoie des codes enum et la 422 est désormais affichée par banner). Impact réel = doc-drift (commentaire :43-44 mensonger) + dimension analytics `order_status_transitions.reason` « kiosk-originated » polluée par les acteurs admin token. V1 LOCAL mono-poste: mineur.
- Nuance aggravante non revendiquée par le finding: en session stateful Sanctum, `TransientToken::can()` retourne aussi true ⇒ le commentaire d'OrderStatusRequest:88-89 (« session-auth admins... fall through ») est probablement faux lui aussi — mais hors scope de cette réfutation.
- **P3 = juste.**

## VERDICT: NON RÉFUTÉ — finding CONFIRMÉ, repro 1:1, sévérité P3 maintenue.
Cleanup: token refuter-f202 (id 2771) supprimé post-test. Order 4547 annulé dans foodking_e2e (clone jetable, autorisé).
