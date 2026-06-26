# Vérification adversaire — CAISSE / wizard / prise commande
## Finding: [P3] OSM DELIVERED→RETURNED ne requiert pas `pos-refund`

**Verdict: CONFIRMÉ — réel, reproductible LIVE, sévérité P3 (escalade owner-visibilité, racine FROZEN → pas de heal).**
Tentative de réfutation ÉCHOUÉE : toutes les assertions du finding sont vraies, y compris une repro réelle en base.

---

### [P3] app/Domain/Order/OrderStateMachine.php:76-77 — DELIVERED→RETURNED inconditionnel : un POS Operator rembourse une vente POS payée sans `pos-refund`

**REPRO (confirmée, vérifiée 4 couches + base live) :**
1. Endpoint `POST /api/.../pos-order/change-status/{order}` (routes/api.php:983) → middleware route = `throttle:pos-order-update` + `idempotency` UNIQUEMENT. Le gate de classe est `permission:pos-orders` (PosOrderController.php:28-36) — **PAS `pos-refund`**.
2. `PosOrderController::changeStatus` (l.312-321) → `OrderService::changeStatus($order, $request)` avec `$auth=false`.
3. `OrderService::changeStatus` (else-branch l.2098-2196) : valide la transition via `ValidStatusTransition` (l.2145), exige un `reason` (l.2151), garde sealed-Z (l.2160), puis `cashBack()` (l.2189) + `refundPoints()` (l.2195). **AUCUN check `pos-refund`** dans tout le bloc RETURNED.
4. `ValidStatusTransition::passes` (l.30-36) passe BIEN le user → `OrderStateMachine::allows($from,$to,$user)`.
5. `OrderStateMachine::allows` → `case DELIVERED: return $to === RETURNED;` (l.76-77) — **inconditionnel, `$user` ignoré** → passe quel que soit le rôle.
6. `PaymentService::cashBack` crée une `Transaction type='cash_back' amount=$order->total` ET `User->balance += $order->total` (PaymentService.php:24,52-55) = vrai remboursement argent.

**EVIDENCE :**
- OSM:76-77 inconditionnel (re-Read). Asymétrie confirmée : les arêtes pré-livraison ACCEPT/PREPARING/PREPARED→RETURNED EXIGENT `pos-refund` (OSM:48/59/67) ; seul DELIVERED→RETURNED ne l'exige pas.
- Rôle 7 « POS Operator » en base LIVE `foodking_e2e` : a `pos`+`pos-orders`, **PAS `pos-refund`** (mysql role_has_permissions). Confirmé aussi seeder RolePermissionTableSeeder.php:98-107 (commentaire explicite « POS Operator does NOT get it by default — mass-refund vector mitigation »).
- **REPRO RÉELLE EN BASE** : `order_status_transitions` contient #5015 et #5012 = `from_status=13 (DELIVERED) → to_status=22 (RETURNED)` exécutés par actor « Caissier Le Cayenne » dont le rôle est **POS Operator (role 7)** — donc SANS `pos-refund`. L'exploit n'est pas théorique, il s'est produit.
- Argent réellement déplacé : orders #4206/#4875 (status=22 RETURNED, payment_status=20 REFUNDED) portent un `payment` ET un `cash_back` égal au total (7.00 / 10.00).
- Sentinel `OrderStateMachinePreZRefundLockSentinelTest.php:163-164` ÉPINGLE explicitement `allows(DELIVERED,RETURNED,null)===true` + `(…,$withRefund)===true`, commentaire « unconditional — the always-legal refund edge ».
- LOCK doc `plans/LOCK_ORDERSTATEMACHINE_PREZ_REFUND_2026-06-04.md:27` : DELIVERED→RETURNED listé parmi les arêtes « byte-for-byte behavior preserved » → l'inconditionnalité est CONSERVÉE SCIEMMENT pendant que les arêtes sœurs sont gates `pos-refund`.

**LENTILLE :** asymétrie d'autorisation owner-connue, pas un trou accidentel. Le owner a gardé le *pré-livraison* derrière `pos-refund` (annuler en cuisine = privilégié) mais traite *retourner une commande livrée* comme un flux caisse normal.

**POURQUOI P3 (pas P1) — discipline sévérité V1-LOCAL :**
- Décision **owner-LOCKée** (LOCK-OSM-PREZ-REFUND 2026-06-04) + **épinglée par sentinel** (délibérée, documentée, non-régressable).
- **Traçabilité totale** : chaque retour écrit `order.returned` via AuditLogService (OrderService:2230) + ActionLog (l.2211) + order_status_transitions avec actor → aucun abus silencieux possible.
- **NF525 intact** : cashBack capturé dans le Z ouvert (pas de gap, chaîne HMAC intouchée — confirmé LOCK:19).
- **Enveloppe V1-LOCAL** : 1 caissier de confiance, mono-poste, frontière de confiance unique. Question de privilège intra-confiance, pas une faille d'intégrité fiscale ni une fuite externe (kiosk/token/customer N'ONT PAS `pos-orders`).

**RECO :** NE PAS auto-corriger. Racine = `OrderStateMachine.php` FROZEN (CLAUDE.md:367) + owner-locké + sentinel l.163-164. `heal_safe_nonfrozen=false`. Surfacer pour VISIBILITÉ owner. Option owner si resserrement souhaité (V1.0.X, décision business) : exiger `pos-refund` aussi sur DELIVERED→RETURNED pour source=POS inline-paid (3-line additif dans `allows()` case DELIVERED, miroir OSM:48/59/67) + MAJ sentinel l.163-164 (passer `allows(DELIVERED,RETURNED,null)` à `assertFalse` et garder `$withRefund`→true) + LOCK. Couche alternative non-frozen possible : `abort_unless(can('pos-refund'))` dans `OrderService::changeStatus` sur la branche RETURNED quand `order_type=POS && payment_status=PAID` — mais touche la logique partagée changeStatus (cancel/reject/online) → owner-gate quand même.
