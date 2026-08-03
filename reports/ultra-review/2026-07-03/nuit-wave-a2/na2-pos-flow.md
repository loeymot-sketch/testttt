# NUIT Wave A2 — POS flux caisse (parked / split / discount / tiroir / customer-display / suivi)

HEAD `86e3eee22` · DB `foodking_e2e` · posture refute-by-default · READ-ONLY.

## Attaques menées (10 angles)
- **Parked orders race** : recall concurrent (2 onglets même opérateur) → `PosParkedOrderService::recall` fait `lockForUpdate()` + `delete()` + retourne snapshot ; 2e recall obtient `null` → 404. Pas de double-résumé. HELD-GREEN.
- **Parked orders idempotence** : `park()` dé-doublonne par (user_id, idempotency_token) + catch `QueryException` sur l'index unique `pos_parked_user_idem_uniq`. HELD-GREEN.
- **Parked branch-isolation** : `ParkedOrderController::resolveOperatorContext` refuse branch_id=0 (403) — pas de fuite cross-branche pour l'admin org-wide. HELD-GREEN.
- **Split payment (concurrence/arrondi/fraude)** : `SplitPaymentService::validateBreakdown` — somme en cents, sous-total refusé, overpay borné 1€, CARD exige terminal_id ACTIVE scoppé branche (bypass BranchScope + check explicite), cash exige tendered≥amount. Persist = même transaction parente, 1 audit-log NF525 par tranche, cash_movement IN net par tranche (strict=true anti-race session). HELD-GREEN.
- **Tiroir sans vente (cash-trail)** : `CashDrawerController::open` écrit un `TYPE_DRAWER_OPEN` (amount=0) sur la session ouverte ; sans session → `Log::warning` « forensic gap » (mode manager pré-shift, documenté). HELD-GREEN.
- **Discount fidélité SSOT (F1 TVA)** : voir §Réfuté ci-dessous.
- **Multi-articles / qty** : prix 100 % recalculés backend (`PricingService`) ; parked recall re-soumet item_id/variations, pas de prix client. HELD-GREEN.

## Réfuté (verify-before-report a tué un faux P1)
**« POS loyalty-redeem casse le Z NF525 (F1: total_tax non recalculé) »** — REFUTED.
`PosRedemptionService::applyToOrder` (app/Services/Loyalty/PosRedemptionService.php:235-242) met à jour `discount`+`total` mais **PAS** `order.total_tax` ; son docblock dit « until F1 is fixed ». MAIS F1 A ÉTÉ CORRIGÉ après ce commentaire :
- **Z report** : `ZReportService::taxBreakdownForOrders` (:700-728) + `orderDiscountRatio` (:677) dérivent la TVA depuis `order_items.tax_amount` **× ratio (subtotal-discount)/subtotal** — jamais depuis `order.total_tax`. LOCK_ZREPORT_F1_DISCOUNT_NETTING.
- **Ticket client** : `OrderReceiptEscPosRenderer::taxLines` (:542-557) proratise identiquement la TVA par le ratio net/brut.
Les 2 surfaces fiscales sont donc correctes malgré `total_tax` figé. Live confirmé : `manual_discount_enabled=true`, `tax_inclusive=true`, order#5457 total=2,00 tax=0,18 (TVA 10 % TTC).

## Findings (tous P3 — aucun P0/P1/P2)

### F1 (P3, improvement) — `/api/admin/pos/customer-display` sans gate `permission:pos`
`PosCustomerDisplayController` (app/Http/Controllers/Admin/Pos/PosCustomerDisplayController.php) étend `Controller` sans constructeur/middleware ; la route (routes/api.php:934) et le groupe `pos` (routes/api.php:799) n'ajoutent aucun `permission:pos`. Gaté seulement par le groupe admin (auth:sanctum + apiKey + block_kiosk_token_admin). Un staff authentifié SANS permission POS (ex. rôle Chef/KDS) peut POSTer un total arbitraire sur l'afficheur client (pole display) — quand `printing.customer_display.enabled=true`. Impact borné (afficheur non-fiscal ; le ticket + l'écran restent autoritaires). Toutes les autres routes POS sensibles portent bien `permission:pos` (ParkedOrderController ctor, CashDrawerController ctor, PosController ctor except quote).
**Fix** : ajouter `->middleware('permission:pos')` sur la route customer-display (ou un ctor `$this->middleware('permission:pos')`), miroir des autres contrôleurs POS.

### F2 (P3, improvement) — `order.total_tax` figé après redeem fidélité POS → TVA écran non-nettée
`OrderDetailsResource` (app/Http/Resources/OrderDetailsResource.php:49-60) expose `total_tax` et `subtotal_without_tax = subtotal - total_tax` bruts. Pour une commande POS avec redeem fidélité, `total` baisse mais `total_tax` reste la valeur pré-remise → l'écran de détail/suivi caisse affiche une TVA/HT non-nettée (incohérente avec le total affiché). **Non fiscal** (ticket imprimé + Z sont nettés, cf. §Réfuté) — cosmétique écran seulement. Le docblock `PosRedemptionService` « until F1 is fixed » est également obsolète (F1 corrigé côté Z/ticket).
**Fix** : soit netter `total_tax` dans `PosRedemptionService` (via ratio, comme le renderer), soit calculer `total_tax`/`subtotal_without_tax` nettés dans la Resource. Corriger le docblock stale.

### F3 (P3, durability) — `parked_orders.payload_json` sans borne de taille
`ParkedOrderController::store` (routes/api.php:937) valide `payload => required|array` sans limite de taille/profondeur ; `PosParkedOrderService::park` sérialise tel quel en `payload_json`. Un client buggé/malveillant (staff authentifié) peut stocker des payloads volumineux → bloat DB. Atténué par TTL 24h (`pos:purge-parked-orders`, Kernel.php:127-128) et surface staff-only.
**Fix** : cap `max` sur `payload` (nb lignes) + garde-fou taille sérialisée dans `park()`.

## Verdict
**IMPROVABLE** — cœur POS (parked race/idempotence, split, tiroir cash-trail, fidélité fiscale) **HELD-GREEN** ; convergence quasi-atteinte. 3 P3 seulement, 0 P0/P1/P2, 1 faux-P1 réfuté par verify-before-report (F1 TVA déjà corrigé côté Z+ticket).
