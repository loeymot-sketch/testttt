# CAISSE r1 — Lentille SÉCURITÉ / RBAC — Sub 1.a « Prise commande / wizard »

Agent: Security/RBAC. Mandat: READ-ONLY (SELECT only, 0 écriture, 0 ordre placé, 0 mutation fiscale).
DB live `foodking_e2e`. Forge prouvée via `PricingService::calculateOrder` en **mode preview orderId=0
(lecture seule, 0 INSERT)** + filtres PHPUnit existants (base test isolée). Aucun ordre/fiscal touché.

## VERDICT GLOBAL : DÉFENSES TIENNENT (0 P0, 0 P1, 0 P2). 1 P3 owner-visibilité (frozen+sentinel-locké).

Le cœur RBAC/forge de la prise de commande POS est SOLIDE. Tous les vecteurs d'abuse demandés
ont été démontrés bloqués en LIVE ou par test nommé vert.

---

## HOLDS PROUVÉS (vecteurs d'abuse → backend gagne)

**H1 — RBAC store/quote.** `PosController` ctor `middleware(['permission:pos'])->except('quote')`
(`PosController.php:51`) ; `quote` self-gate `abort_unless($request->user()?->can('pos'),403)` hors
chemin kiosk (`PosController.php:172-174`). `PosOrderRequest::authorize()` double `can('pos')`
(`:60-63`). POS Operator (rôle 7) a `pos` ⇒ OK ; un token sans `pos` ⇒ 403.

**H2 — Isolation branche (cross-branch order injection).** `OrderService::posOrderStore:728-738` :
non-Admin avec `branch_id` payload ≠ son `authBranchId` ⇒ `InvalidArgumentException(...,403)`.
Live : tous users non-Admin sont branch_id=1 (mono-poste) ; Admin id=1 branch_id=0.
repro: forger `branch_id=2` en tant que Caissier ⇒ 403.

**H3 — SSOT prix (forge total_price / option prices) — PROUVÉ LIVE.**
`posOrderStore:705` `unset($validated['total','subtotal','discount'])` puis `PricingService::calculateOrder`
(`:818-855`, V1 `pricing.use_ssot_service=true`). Le client n'envoie que `item_id/qty/option_ids`.
repro live (DB foodking_e2e, preview orderId=0):
`item1 (DB 2,50) + extra234 (DB 1,00)` avec `total_price=99.99` ET `extra price=50.00` forgés
⇒ **BACKEND subtotal=3.50 total_ttc=3.50** (99.99/50.00 IGNORÉS). Forge défait.

**H4 — Item INACTIF (status=10) ajoutable — PROUVÉ LIVE.**
`AvailabilityService::assertItemsOrderableForBranch:238` (appelé par PricingService:50/102) :
`status !== Status::ACTIVE` ⇒ `InvalidArgumentException(...,422)`.
repro live: item 25 « Sandwich Classique » (status=10) ⇒ **« Article 25 inactif dans le catalogue.
Commande rejetée. » [422]**. (Note: `Item::query()` y applique le SoftDeletingScope ⇒ soft-deleted ⇒
introuvable ⇒ 422 aussi.)

**H5 — Forge variation/extra cross-item — PROUVÉ LIVE.**
repro live: extra 12 (≠ item 1) ⇒ « Extra ID 12 n'appartient pas à l'article 1. » ;
variation 1 (appartient item 22) sur item 1 ⇒ « Variation ID 1 introuvable. Commande rejetée. » [422].

**H6 — MAX/MIN/omis viandes (MultiVariationConstraint) — PROUVÉ LIVE.**
`app/Rules/MultiVariationConstraint.php` : max_select/min_select/allow_repeat + heal 2026-06-24
(attr REQUIS entièrement omis ⇒ rejet, `:59/:91-105`).
repro live: Tacos item22 SANS variations ⇒ « Sélectionnez au moins 1 Sauce… | …1 Type de Pain… » ;
attr « Viande 1 » a min=1/max=1/allow_repeat=0 ⇒ excès/répétition rejetés.
Tests verts: `MultiVariationConstraint|AddonRole|VariationConstraint` 15/15.

**H7 — quote ≠ store (intent-hash) — pas de sous-facturation.**
`OrderQuoteService` : intent-hash sha256 + HMAC sur canonical incluant `surface`, `actor(id/branch/roles)`,
`order.payment_method`, `order.source` (`:392-438`). Replay : signature/intent mismatch ⇒ 401
(`:346/350`). **CRUCIAL** : `posOrderStore` n'utilise PAS `quote_signature/quote_token` — le devis est
**advisory** ; le store recalcule tout via PricingService. Donc un devis kiosk ne peut pas être rejoué
en POS pour sous-facturer (surface différente ⇒ hash différent) ET de toute façon le store ignore le
devis et recompute. Tests `QuoteBindingTest` + `PosOrderRequestNoClientTotalsTest` 8/8.

**H8 — Addon menu-ratio injection (60% off via role forgé).**
`ValidatesAddonRoles` (lie `items[].item_addons[].role` à la colonne DB `item_addons.role` ;
payload `menu_*` n'est honoré que si DB role = `menu_component`) + double-garde `CompositionSnapshotBuilder`.
Tests verts inclus dans le run AddonRole 15/15.

**H9 — Refund-bypass via change-status→RETURNED (germe T-1.d.4) — GATÉ pour les arêtes pré-livraison.**
`PosOrderController::changeStatus` → `OrderService::changeStatus:2145` → `ValidStatusTransition::passes`
→ `OrderStateMachine::allows($from,$to,$user)`. Les arêtes pré-livraison ACCEPT/PREPARING/PREPARED→RETURNED
exigent `pos-refund` (`OrderStateMachine.php:48/59/67`). POS Operator (rôle 7) a `pos`+`pos-orders` mais
PAS `pos-refund` (BM rôle 6 l'a) ⇒ ces arêtes lui sont interdites. Sentinel
`OrderStateMachinePreZRefundLockSentinelTest` 8/8 + `PreZRefundViaEndpointTest` 5/5 VERTS.
Le endpoint dédié `refund-with-counter-entry` est en plus gardé `abort_unless(can('pos-refund'))`
(`PosOrderController.php:58-62`) + cross-branch (`:69-73`).

---

## P3 (owner-visibilité — frozen + sentinel-locké, PAS un défaut neuf)

[P3] app/Domain/Order/OrderStateMachine.php:76-77 — DELIVERED→RETURNED ne requiert AUCUNE permission ⇒ un POS Operator peut rembourser une vente POS payée sans `pos-refund`.
  repro: en tant que Caissier (rôle 7 : `pos`+`pos-orders`, PAS `pos-refund`) sur une vente POS PAYÉE
    inline (créée en PREPARING, payment_status=PAID, avec Transaction type='payment') :
    (1) POST /api/admin/pos-order/change-status/{id} status=PREPARED puis DELIVERED — autorisé car
        ACCEPT/PREPARING→DELIVERED est gardé par `pos` (OSM:41/55) que l'Operator possède ;
    (2) POST .../change-status/{id} status=RETURNED reason="x" — autorisé car `case DELIVERED:
        return $to===RETURNED;` est INCONDITIONNEL. ⇒ `cashBack()` crée une Transaction type='cash_back'
        montant=order.total + crédite le solde client (vrai mouvement d'argent) + `order.returned` audit.
  evidence: OSM:76-77 (inconditionnel) ; sentinel `OrderStateMachinePreZRefundLockSentinelTest.php:163-164`
    PINGLE explicitement `allows(DELIVERED,RETURNED,null)===true` ; live: orders order_type=15 PAID ont
    bien une Transaction type='payment' (ex #4929 amount=3.00) donc cashBack rembourse réellement ;
    cashBack body PaymentService.php:91+ (crée cash_back + User->balance += total).
  lentille: commerçant (un caissier peut contourner le gate `pos-refund` voulu BM-only, pour les ventes
    POS payées inline, via l'arête de remboursement post-livraison toujours-légale).
  reco: AUCUN heal sans gate. La racine est `OrderStateMachine.php` (FROZEN CLAUDE.md §7:367) ET le
    comportement est explicitement owner-locké par `LOCK-OSM-PREZ-REFUND 2026-06-04` + sentinel l.163-164
    (rationale : un retour d'une commande LIVRÉE est un flux opérationnel normal). C'est un compromis
    owner ASSUMÉ, pas un bug. Option owner si resserrement souhaité (V1.0.X) : exiger `pos-refund` aussi
    sur DELIVERED→RETURNED pour les ventes source=POS inline-paid — décision business + LOCK + mise à jour
    sentinel. À surfacer pour visibilité, ne PAS auto-corriger.

---

## VÉRIFICATIONS NF525 / FROZEN
- `OrderStateMachine.php` + `PricingService.php` FROZEN (CLAUDE.md §7) — AUCUNE modif (audit pur).
- Aucun ordre placé, aucune Transaction créée, aucune mutation fiscale. SELECT + preview orderId=0 only.
- Tests exécutés sur base test PHPUnit isolée (jamais la DB op) : sentinel pre-Z 8/8, PreZ endpoint 5/5,
  NoClientTotals+QuoteBinding 8/8, MultiVariation/AddonRole 15/15, BranchScopeCoverageSentinel 1/1.

## ANTI-HALLUCINATION
Chaque file:line re-greppé (PosController:51/172, OrderService:705/728-738/818-855/2145,
OrderStateMachine:48/59/67/76-77, AvailabilityService:238, MultiVariationConstraint:59/91-105,
OrderQuoteService:392-438/346-350). Forge prouvée live contre foodking_e2e (subtotal=3.50 vs forgé 149.99 ;
INACTIF 422 ; cross-item 422 ; omis-requis 422). Rien d'inventé.
