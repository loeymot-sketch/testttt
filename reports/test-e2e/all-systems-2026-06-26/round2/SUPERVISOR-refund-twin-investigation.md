# R2 superviseur — investigation jumeau refund-bypass (verify-before-accept de MON heal)

Mon heal P1 a gaté `PosOrderController::changeStatus` (RETURNED → can('pos-refund')). Question : jumeaux online/table laissés ouverts ?

## Faits vérifiés (file:line + DB)
- `OnlineOrderController::changeStatus` (l.94-97) délègue `orderService->changeStatus` (chemin cashBack sur RETURNED). Gardé par `permission:online-orders` (constructeur l.34, changeStatus DANS le ->only).
- `TableOrderController::changeStatus` (l.63-66, alias AdminTableOrderController) idem, gardé `permission:table-orders` (l.26-33, changeStatus DANS le ->only).
- `OrderStatusRequest::authorize` (l.25) retourne true pour Admin/BM/Chef/POS Operator/Cashier → l'autorisation REPOSE sur le middleware permission du contrôleur, pas sur la request.
- Rôles ayant online/table-orders (DB foodking_e2e role_has_permissions) :
  - Admin : online✓ table✓ refund✓ → SAFE (a pos-refund).
  - Branch Manager : online✓ table✓ refund✓ → SAFE.
  - **Waiter : online✗ table✓ refund✗** → JUMEAU LATENT (peut atteindre table-order changeStatus→RETURNED→cashBack sans pos-refund).
  - POS Operator : online✗ table✗ → ne peut atteindre NI online NI table (pas un vecteur).

## Sévérité V1-LOCAL = P3 (latent, non-exploitable en V1)
- dine-in DORMANT : `config('pos.dine_in_enabled')=NULL` (désactivé).
- DB : commandes dine-in (order_type=25) = 29, dont **0 PAID+DELIVERED** (précondition refund RETURNED+cashBack jamais atteinte).
- Online : aucun rôle n'a online-orders SANS pos-refund → pas de vecteur.
→ Le jumeau Waiter/table n'est exploitable QUE si dine-in est activé ET qu'une commande table atteint PAID+DELIVERED. En V1 LOCAL (dine-in off) = non-atteignable.

## RECO (heal de CLASSE, défense en profondeur)
Bien que non-exploitable V1, c'est la classe du bug. Heal symétrique NON-frozen :
- `OnlineOrderController::changeStatus` + `TableOrderController::changeStatus` : ajouter le MÊME gate `abort_unless(can('pos-refund'))` quand status===RETURNED, miroir de PosOrderController.
- Étendre `RefundBypassGuardTest` aux 3 contrôleurs (Waiter/table → 403).
→ À faire si R2 confirme (évite la réactivation dine-in V2 avec le trou). Sinon noter V1.0.X.

## Self-check heal QUOTE (attack-2) = COMPLET
- STORE déjà gardé AVANT mon heal : `PosOrderRequest:20` + `OrderRequest:23` + `TableOrderRequest:7` utilisent `trait ValidatesOrderItemVariations` → MultiVariationConstraint à la création (pos+kiosk+table).
- Mon heal a ajouté le MÊME guard au QUOTE (`OrderQuoteService`) qui en manquait.
- → quote ET store enforced sur les 3 surfaces. Bypass quote→store IMPOSSIBLE (store rejette via FormRequest). Heal complet.

## Autres vecteurs refund (même classe) — tous SAFE
- `counter-collect/cancel` (api.php:880) : `cancelCounterPayment` annule une commande PENDING_COUNTER NON-payée → 0 cashback. Gardé `can('pos')`. SAFE.
- `collect-kiosk-cash` (api.php:900) : collecte, pas refund. SAFE.

## DÉCISION SUPERVISEUR (disciplinée) sur le jumeau table/online RETURNED
- POS refund-bypass (vecteur V1-exploitable) = FIXÉ + vérifié.
- Online : aucun rôle online-orders SANS pos-refund → 0 vecteur.
- Table : Waiter table-orders SANS pos-refund = jumeau LATENT, MAIS dine-in dormant + 0 commande dine-in PAID+DELIVERED = **non-exploitable V1**.
- **NE PAS healer à la hâte** : gater table/online RETURNED unconditionnel risque de SUR-BLOQUER des retours légitimes non-payés (nuance : cashBack ne fire que si transaction payment existe). « Partiel > faux » : un heal mal calibré sur surface dormante = pire que la documentation.
- → **V1.0.X hardening** (owner-gate) : à l'activation dine-in, gater table/online changeStatus→RETURNED sur pos-refund AVEC discrimination payé/non-payé (miroir affiné de PosOrderController), + étendre RefundBypassGuardTest aux 3 contrôleurs. Documenté, pas exécuté (non-exploitable V1).
