# RED-ADVERSAIRE — Caisse · Commandes / parking / loyalty / refund — round r1

Lentille: 🧑‍💼 commerçant (caissier qui se trompe/fraude) + 🧑 client qui abuse.
DB live `foodking_e2e` (READ-ONLY, 0 écriture). PHPUnit = base test isolée.
Méthode: Read ancres → SELECT preuves → run probe/test existants → vecteurs d'abuse.

---

## P1 — `app/Domain/Order/OrderStateMachine.php:76-77` (root FROZEN) + `app/Http/Controllers/Admin/PosOrderController.php:312-321` (fix NON-frozen) — Refund-bypass: un POS Operator SANS `pos-refund` rembourse une commande DELIVERED+PAID en cash via `change-status → RETURNED`

**repro** (probe existant, base test, 0 mutation live):
`vendor/bin/phpunit --filter ZZ_RefundBypassProbe_Test` → **OK (1 test, 5 assertions)**.
Sortie réelle capturée:
```
[PROBE] dedicated refund-with-counter-entry status = 403
[PROBE] change-status->RETURNED status = 200
[PROBE] orderB status_after = 22 | cash_back_txn = YES amount=30.000000
```
Acteur = `POS Operator` (a `pos`,`pos-orders` ; PAS `pos-refund` — confirmé live ci-dessous).
Chemin: `POST /api/admin/pos-order/change-status/{order}` body `{status:22,reason:...}`.

**evidence**:
- Split de permissions live (le profil de l'attaquant existe vraiment) :
  ```
  mysql -u root foodking_e2e -e "SELECT r.name, MAX(p.name='pos-orders') po, MAX(p.name='pos-refund') pr FROM roles r LEFT JOIN role_has_permissions rhp ON rhp.role_id=r.id LEFT JOIN permissions p ON p.id=rhp.permission_id GROUP BY r.id"
  → Admin po=1 pr=1 | Branch Manager po=1 pr=1 | POS Operator po=1 pr=0
  ```
  Le middleware sur `changeStatus` = `permission:pos-orders` (PosOrderController:28-37) → l'Operator passe.
- Le gate `pos-refund` n'existe QUE sur l'endpoint dédié `refundWithCounterEntry:58-62` (`abort_unless(can('pos-refund'))`). `changeStatus`/`changePaymentStatus` n'ont AUCUN check `pos-refund`.
- `OrderStateMachine::allows()` arête `DELIVERED(13)→RETURNED(22)` (l.76-77) retourne `true` SANS aucun check de permission (les arêtes pré-livraison ACCEPT/PREPARING/PREPARED→RETURNED, elles, gatent `pos-refund` aux l.48/59/67 — l'arête DELIVERED a été oubliée). `ValidStatusTransition::passes()` (l.30-36) délègue à `OrderStateMachine::allows()` → même trou.
- L'effet money: `OrderService::changeStatus:2188-2195` sur RETURNED appelle `PaymentService::cashBack()` (crée `Transaction('cash_back')`, **incrémente `User->balance += order.total`**, append audit NF525 + dispatch `RefundCreated` = libération stock) + `LoyaltyService::refundPoints()`. Vérifié `PaymentService::cashBack:91+`.
- **Surface exploitable réelle (live)** — DELIVERED+PAID, type POS, NON scellés (Z ouverte → chemin pré-Z, `SealedOrderGuard` ne bloque pas) :
  ```
  SELECT count(*) FROM orders o WHERE o.status=13 AND o.payment_status=5 AND o.deleted_at IS NULL AND o.order_type=5
    AND NOT EXISTS (SELECT 1 FROM z_reports z WHERE z.branch_id=o.branch_id AND z.status='closed' AND z.opened_at<o.created_at AND z.closed_at>=o.created_at)
  → 10
  ```
  (Z #26 ouverte 2026-06-25, aucune close après → tout DELIVERED récent est pré-Z = remboursable par l'Operator.)
  DELIVERED+PAID total = 2153 ; dès qu'une nouvelle vente est livrée+payée elle entre dans cet ensemble jusqu'à clôture Z.

**lentille**: commerçant (fraude/erreur). Le gate `pos-refund` a été créé EXPRÈS comme mitigation mass-refund (cf. `PROPOSAL_POS_REFUND_UI_2026-05-25 §8 risk #1`, cité dans `refundWithCounterEntry:53-56`). Il est intégralement contournable par la route sœur. Un caissier mécontent peut vider le tiroir (cashback) + gonfler des soldes fidélité de comptes complices, sans le droit manager.

**reco** (NON-frozen, lentille jumeau-systémique): dans `PosOrderController::changeStatus` (NON-frozen), AVANT de déléguer à `OrderService`, si `(int)$request->status === OrderStatus::RETURNED` → `abort_unless(Auth::user()?->can('pos-refund') ?? false, 403, 'Insufficient permission to issue refund.')` — miroir exact de `refundWithCounterEntry:58-62`. TDD: nouveau `tests/Feature/Pos/RefundBypassGuardTest.php` (Operator+DELIVERED → change-status status=22 → **403**, 0 `cash_back` ; Admin/BM → 200). NE PAS toucher `OrderStateMachine.php` (frozen + sentinelé `OrderStateMachineTest`). Round suivant: auditer les voies sœurs `OnlineOrderController`/`FrontendOrderController::changeStatus` (même `→RETURNED` déclenche cashBack — vecteur potentiellement identique). Si l'owner préfère fermer à la racine state-machine → **ESCALADE** (frozen + sentinelle à mettre à jour + gate owner). Corroboré indépendamment par 3 reports sœurs (security-rbac-paiement, security-rbac, security-rbac-commandes-parking-loyalty-refund).

---

## VECTEURS ABUSÉS → TENUS (réfutation de fausses certitudes — valeur RED)

**H1 — redeem points > solde** → TENU. `PosRedemptionService:135` `if ($available < $points) throw INSUFFICIENT_BALANCE(422)` DANS le `lockForUpdate` (l.110+). evidence: `PosLoyaltyRedeemTest` 7/7 OK.

**H2 — redeem APRÈS refund / sur commande terminale** → TENU. `assertOrderRedeemable` rejette `payment_status===PAID` (l.273) ET `status IN [DELIVERED,CANCELED,REJECTED,RETURNED]` (l.288-291) → `ORDER_NOT_REDEEMABLE`. Donc impossible de racheter des points sur une commande déjà remboursée.

**H3 — double-redeem (×2 réduction)** → TENU. `LoyaltyTransaction` UNIQUE(user_id,order_id,type) → 2ᵉ tentative = QueryException 23000 → `ALREADY_REDEEMED(409)` (`PosRedemptionService:191-198`). Confirmé par `KioskLoyaltyDoubleRedeemRefusedTest` (présent).

**H4 — double-refund post-Z (mirror ×2 = double négatif pour 1 vente)** → TENU. Index live `orders_parent_order_id_unique` (UNIQUE sur `parent_order_id`) → 2ᵉ mirror = 23000 → `PosOrderController:170-176` renvoie 409 `MIRROR_ALREADY_EXISTS`. Live: `SELECT parent_order_id,count(*) ... GROUP BY HAVING count>1` = **vide** (aucun double-mirror).

**H5 — double-refund pré-Z (change-status RETURNED ×2)** → TENU (idempotent). `cashBack()` early-return si `Transaction(type=cash_back)` existe déjà (`PaymentService:96-103`) → pas de second débit ; `changeStatus` idempotent-return si `status===toStatus` (`OrderService:2130-2137`). Money sorti UNE fois.

**H6 — refund cross-branch (Operator branche A rembourse commande branche B)** → TENU. Endpoint dédié: `refundWithCounterEntry:69-73` abort 403 si `branch_id` ≠. Voie change-status: `OrderService:2122-2127` abort(403) si non-Admin et `branch_id` ≠ `order.branch_id`. Loyalty: `PosLoyaltyController:53-56` abort 403 cross-branch. (NB: le bypass P1 reste exploitable SUR SA PROPRE branche — la branche ne le couvre pas.)

**H7 — resume order déjà payé (parking)** → NON-APPLICABLE. `pos_parked_orders` stocke un **payload panier JSON brouillon** (`ParkedOrderController::store` valide `payload:array`), PAS une commande fiscale. `show`/`recall` ne fait que retourner le payload ; aucun chemin ne ré-ouvre un `Order` PAID. `resolveOperatorContext:72-78` borne user+branche (`abort_unless id===authId`). Pas de vecteur.

**H8 — refund post-Z via change-status (contourner le mirror)** → TENU. `OrderService:2160-2183` `SealedOrderGuard::assertMutable` lève `OrderSealedException` si la commande est dans une Z CLOSE + écrit audit `pos.refund.post_z_blocked`. Le bypass P1 est donc strictement **pré-Z** (mais c'est de l'argent réel du tiroir ouvert).

---

## NOTES
- `changeStatus`/`changePaymentStatus` (PosOrderController:312/323): aucun refund-bypass via `changePaymentStatus` car `PaymentStateMachine` `PAID=>[]` (l.17) → toute transition depuis PAID lève InvalidArgumentException(422). Le SEUL chemin money-out reste `changeStatus→RETURNED` (P1).
- Frozen touché par la RACINE (OrderStateMachine) → le fix DOIT viser le contrôleur NON-frozen (couche autorisation), cohérent avec le pattern `refundWithCounterEntry`.
