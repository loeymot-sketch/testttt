# ADVERSARIAL MONEY-PATH — Le Cayenne backend (LIVE via `ssh lecayenne`, loopback :443)

**Verdict : money-path AIRTIGHT. P0=0 · P1=0 · P2=0.** Token guest réel (user #21, phone
0788001234, OTP lu en table `otps`), 22 attaques réelles, 6 commandes créées puis
**forceDelete + compte purgé** (orders=0, user=0, otps=0, token→401). TTC/SSOT actifs
(`pricing.use_ssot_service=true`, `tax_inclusive_prices=true`), coupons OFF
(`pos.manual_discount_enabled=false`). Item test 98 « Cheese Burger » 6,00 € TTC + sauce 390 (gratuite).

## Résultats (payload → réponse serveur → verdict → garde)

| # | Attaque | Réponse | Verdict | Garde (file:line) |
|---|---------|---------|---------|-------|
| B0 | panier réel, expected=6.00 | 201, scellé **6,00 €** | ref | — |
| A1 | expected_total **1,00** (réel 6,00) | 422 « total ne correspond pas » | BLOQUÉ ✓ | `FrontendOrderService.php:580-588` |
| A1b | expected_total **0** | 422 même garde (filled(0)=true) | BLOQUÉ ✓ | idem |
| A2 | expected_total **-5** | 422 « must be at least 0 » | BLOQUÉ ✓ | `OrderRequest.php:170` |
| A3 | expected_total **absent** | 201, re-scellé **6,00 €** (vrai prix DB) | ✓ pas de sous-paiement | SSOT `PricingService::calculateOrder` |
| A4 | client **total:1, subtotal:1, discount:5** | 201, scellé **6,90 €, discount 0** | ✓ champs client IGNORÉS | unset `FrontendOrderService.php:271` |
| A5 | extra **fantôme 999999** | 422 « Supplément introuvable » | BLOQUÉ ✓ | `PricingService.php:489/177` |
| A6 | extra **#1 (item 4)** sur item 98 | 422 « n'appartient pas à l'article 98 » | BLOQUÉ ✓ | `PricingService.php:182-187` |
| A7 | item **fantôme 999999** | 422 « Article introuvable » | BLOQUÉ ✓ | `PricingService.php:128-132` |
| A8 | extra qty **-5** | 201, scellé **6,90 €** (clamp max(1,·)) | ✓ pas de crédit négatif | `PricingService.php:188` |
| A9 | item **quantity 0** | 422 « quantité invalide » | BLOQUÉ ✓ | `ValidJsonOrder.php:66` |
| A11 | **coupon_id 999999** | 422 « Le coupon n'existe pas » | BLOQUÉ ✓ | `DiscountCalculator.php:17-25`; OFF `FrontendOrderService.php:924` |
| A12 | **DELIVERY (5)** alors que livraison DÉSACTIVÉE | 422, 0 commande | BLOQUÉ ✓ | geocode `OrderRequest.php:100` + disable `:283` |
| A13a/b | idempotence : **même clé + même corps ×2** | 201 → **même order #242** (0 doublon) | ✓ | `FrontendOrderService.php:176-183` |
| A13c | **même clé + corps DIFFÉRENT** | **409 IDEMPOTENCY_KEY_CONFLICT** | BLOQUÉ ✓ | `IdempotencyKeyMiddleware.php:88-93` |
| A15 | NF525 : client force `order_serial_no=HACKED-999`, `fiscal_sequence_no=99999`, `queue_number=Z9999`, `total=1` | 201, scellé **serial 300726243 / seq null / queue A0039 / total 6,00** | ✓ 100 % serveur | serial `FrontendOrderService.php:545`, queue `:1103` |
| Coupon | `/coupon/coupon-checking` code **FREESTUFF999** | 422 « Le coupon n'existe pas » | BLOQUÉ ✓ | `CouponService::resolveCouponById` |

## Preuve DB (au centime, avant purge)

`FrontendOrder where user_id=21` → 6 commandes, **toutes au vrai prix** :
- #232/#235/#242/#243 = **6,00 €** (subtotal 6,00 / tax 0,55 TTC / discount 0)
- #236 (A4 total forgé) = **6,90 €** discount **0** — le `discount:5` client n'a **jamais** été appliqué
- #240 (A8 qty -5) = **6,90 €** — extra facturé 1×, jamais crédité
- `fiscal_sequence_no = null` sur toutes (UNPAID pm=10 → alloc à la clôture/paiement, pas de gap créé)
- `order_serial_no` = `date('dmy').id` (serveur), `queue_number` = `A00xx` (serveur) : **aucune valeur client retenue**

## Mollie (LIVE key — non exercé, prouvé par code)

Compte `isMollieConfigured()=true` (LIVE). **Aucun paiement live créé** (refus délibéré). Montant Mollie =
`number_format((float) $order->total,…)` **total scellé serveur**, jamais un montant client
(`Mollie.php:108,141`) ; webhook re-vérifie `paidCents === expectedCents` sinon jamais PAID
(`Mollie.php:341-343`) ; contrôleur exige propriété + pm=CARD + UNPAID (`MolliePaymentController.php:50-74`).

## Conclusion
Impossible de **payer moins que le panier réellement soumis** : `expected_total` n'est qu'un
témoin défense-en-profondeur (rejette la sous-facturation), le serveur re-scelle **toujours**
depuis les prix DB (SSOT). Extras/options fantômes, cross-item, quantités hostiles, coupons,
livraison désactivée, double-submit, et numérotation NF525 : **tous bloqués ou re-scellés**.
Aucun trou. Nettoyage complet effectué.
