# LANE F2 — REDEEM & REVERSAL fidélité (2026-06-12)

Harnais: :8767 / foodking_e2e. Read-only code, mutations DB e2e autorisées.

## Étape 0 — Lecture statique (faite)

- Route: `routes/api.php:1020` POST `/api/admin/pos-order/{order}/redeem-loyalty` → middleware `['throttle:pos-order-update', 'idempotency']`.
- Service: `app/Services/Loyalty/PosRedemptionService.php`
  - L72-78: kill-switch `pos.manual_discount_enabled` (default TRUE depuis F1-fix-r2, config/pos.php:172).
  - L85-86: points <= 0 → 422 INVALID_POINTS.
  - L96-106: rate = setting `loyalty_points_for_1_euro_discount` (def 100); points % rate !== 0 → 422 POINTS_NOT_MULTIPLE. **Pas de check "min 100" explicite — le min est impliqué par multiple-de-100 + FormRequest min:1.**
  - L134-141: balance insuffisante → 422 INSUFFICIENT_BALANCE (dans la TX, lockForUpdate).
  - L143-151: `discountEur > subtotal` → 422 DISCOUNT_EXCEEDS_SUBTOTAL — **REFUS, pas de plafonnement** (à confirmer cas réel). Note: comparé au SUBTOTAL, pas au total, et pas net des remises existantes.
  - L179-200: insert ledger UNIQUE(user_id,order_id,type) → 23000 → 409 ALREADY_REDEEMED. Décrément balance L169 AVANT insert mais dans la même DB::transaction → rollback OK si 23000.
  - L233-237: recompute total TTC: `total = max(0, subtotal - newDiscount + delivery)`.
- Controller: `app/Http/Controllers/Admin/PosLoyaltyController.php:45-56` — bypass BranchScope + check branch explicite; FormRequest `PosLoyaltyRedeemRequest` (permission `pos.redeem-loyalty`, points integer min:1 max:100000).
- Reversal: `app/Services/LoyaltyService.php`
  - `refundPoints` L21-116: somme des rows type=redeem → re-crédit + row `manual_add`; idempotence = pre-check existence manual_add (L71-82) + UNIQUE index.
  - `clawbackEarnedPoints` L150-213: row `manual_deduct`, clamp balance >= 0, idempotent via pre-check.
  - Callers refundPoints: OrderService:2067/:2203 (cancel), RefundWithCounterEntryService:418, FrontendOrderService:734.

## Journal au fil de l'eau
### Étape 1 — Redeem POS cases (HTTP live :8767, cust id=61 F2LOYAL1 solde initial 500)
- Fixtures: f2-setup.php → orders 4512 (25€), 4513 (3€), 4514, 4515. api_key + Bearer token admin id=1.
- A) 50 pts → **422 POINTS_NOT_MULTIPLE** "multiple de 100" ✅ (min 100 implicite via multiple-de-rate, PosRedemptionService.php:100)
- B) 600 pts > solde 500 → **422 INSUFFICIENT_BALANCE** "500 pts disponibles, 600 demandes" ✅ (L135)
- C) 400 pts = 4€ > sous-total 3€ (order 4513) → **422 DISCOUNT_EXCEEDS_SUBTOTAL** — **REFUS, PAS plafonné** (L145, strictement >; égalité = commande gratuite autorisée) ✅ confirmé code ET cas réel
- D) 100 pts exact (order 4512) → **200** {discount_eur:1, balance_after:400, order:{subtotal:25, discount:1, total:24}} txn id=13 ✅ −100 pts / −1,00 €
### Étape 2 — Double-redeem (order 4514 race + order 4517 même clé)
- RACE 2 POST parallèles clés DIFFÉRENTES (order 4514): k2=200 (txn 14, balance_after 300), k1=**409 ALREADY_REDEEMED** ✅ UNIQUE(user_id,order_id,type) a tenu sous concurrence; décrément k1 rollbacké (balance décrémentée AVANT insert mais même DB::transaction, PosRedemptionService.php:169+191).
- MÊME clé idempotency ×2 (order 4517): POST1=200 txn 16 balance_after 200; POST2=200 **réponse identique rejouée** (même transaction_id 16, balance inchangée) ✅ middleware idempotency replay 2xx.
### Étape 3 — Remise visible UI (/admin/historique)
- Liste /admin/historique OK (F2-historique-list.png): colonnes N° commande/origine/montant/N° fiscal/statut; rows F2-RDM-* visibles, montant 24,00 € (total remisé). F2-RDM-1 en page 2 (tri date desc) → script fallback nav directe detail (reuse documenté admin.pos-orders.show, historiqueRoutes.js:5-7).
- Détail order 4512 (F2-historique-redeem.png, ANALYSÉE): **Sous-Total 25,00 € / Remise 1,00 € / Total 24,00 €** → 25 − 1 = 24 ✅ cohérent, format FR. Badge "Non Payé", bouton "Appliquer une réduction fidélité" présent. 0 console errors, 0 HTTP >=400.
- Note fixture (pas un bug produit): bloc "Détails Commande" vide car commandes factory sans order_items.
### Étape 4 — Reversal (HTTP live)
- 4a) Cancel order 4512 (redeemée) POST change-status status=16 reason=customer_request → 200; refundPoints (OrderService.php:2067) → **+100 re-crédités UNE fois**, ledger manual_add id=28 "Remboursement fidélité suite annulation #F2-RDM-1", balance 200→300. ⚠️ 1er essai reason libre "F2 lane reversal test" → 422 "Reason code is not whitelisted for kiosk-originated transitions" (voir finding P3 ci-dessous).
- Replay cancel (nouvelle clé) → 200 early-return idempotent (OrderService changeStatus, status déjà 16); replay direct refundPoints via tinker → NOOP (pre-check manual_add LoyaltyService.php:71-82). Balance reste 300, rows 4512 = 2.
- 4b) Clawback earned: fixture 4515 PAID + Transaction(type=payment) + loyalty_points_awarded=25 + earn +25 (balance 325). Cancel HTTP → 200 → chaîne RÉELLE cashBack (PaymentService.php:199 RefundCreated::dispatch afterCommit) → ClawbackLoyaltyPointsOnRefund → **manual_deduct -25 id=30**, balance 325→300, payment_status→20 REFUNDED, cash_back transaction créée.
- Replay clawback ×2 (re-dispatch RefundCreated réel + appel direct clawbackEarnedPoints) → **NOOP idempotent** (pre-check LoyaltyService.php:156-167), balance 300, rows 4515 = 2 (earn + manual_deduct).
### Étape 5 — Edge over-discount + NF525-adjacence
- **FINDING P2 (prouvé live)**: garde plafond PosRedemptionService.php:144-150 compare `discountEur > subtotal` BRUT, pas net des remises existantes. Order 4534 (subtotal 25, remise préexistante 20, reste à payer 5) : redeem 600 pts (6€) → **200 accepté**, discount final 26 > subtotal 25, total clampé 0 (L234 max(0,…)). Client brûle 600 pts pour 5€ de valeur (1€/100 pts détruits) + ligne Remise 26,00€ > Sous-Total 25,00€ incohérente sur historique/ticket. Repro: POST redeem-loyalty points=600 sur commande à remise préexistante 20€.
- NF525-adjacence (lecture + grep): la remise entre par **incrément de order.discount** (L213-216 `$newDiscount = round($currentDiscount + $discountEur, 2)`) + recompute total côté serveur (L233-237, TTC: `max(0, subtotal - newDiscount + delivery)`) + UPDATE limité à `discount/total/loyalty_customer_code` (L245-252). `discountEur` dérivé de points/rate serveur (L96-108, setting loyalty_setup). **Entrées client = points + loyalty_code uniquement** (PosLoyaltyRedeemRequest rules; controller L59-64). Aucune écriture de prix client, aucun write order_items. ✅ pas de P0.
- Seules écritures DB du service: users.loyalty_points (L169-174), loyalty_transactions (L180-190), orders.discount/total/loyalty_customer_code (L245-252).
### Étape 6 — PHPUnit (sqlite :memory:)
`./vendor/bin/phpunit --filter "PosLoyaltyRedeem|KioskLoyaltyDoubleRedeem|KioskLoyaltyLedgerAtomic|LoyaltyRefundPointsIdempotent|LoyaltyClawbackOnRefund|RefundWithCounterEntry|OrderCancellationLoyalty"`
→ **OK (27 tests, 112 assertions)**, Time 00:04.934.

## VERDICT LANE
- Sortie de points SOLIDE sur tous les axes demandés: refus min/solde/plafond, débit exact, anti double-redeem (race UNIQUE + idempotency replay), remise visible cohérente UI, reversal unique + idempotent, clawback unique + idempotent, remise = chemin backend (0 prix client).
- 2 findings: **P2** over-discount net-vs-brut (4534 prouvé live) + **P3** wildcard-token admin classé kiosk pour le whitelist de reason (free-text documenté mort).
- Ledger final user 61: 7 rows (3 redeem −300, 1 manual_add +100, 1 earn +25, 1 manual_deduct −25, 1 redeem 4534 −600), balance 400 = 1000 − 600 ✅ arithmétique exacte post-reset.
