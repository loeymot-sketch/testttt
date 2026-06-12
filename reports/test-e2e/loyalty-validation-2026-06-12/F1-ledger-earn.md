# LANE F.1 — LEDGER & EARN fidélité (2026-06-12)

Harnais: :8767 / foodking_e2e (APP_ENV=e2e). Worktree cms-gestion-2026-06-10.

## Lecture code (préalable)
- `app/Models/LoyaltyTransaction.php` — ledger immutable, types: earn/redeem/manual_add/manual_deduct/expire ; points cast integer ; balance_after snapshot.
- Signes confirmés par lecture service:
  - earn: +points (AwardLoyaltyPointsOnDelivery.php:131)
  - redeem: -points (PosRedemptionService.php:185 `'points' => -$points`)
  - manual_add (refund annulation): +points (LoyaltyService.php:104)
  - manual_deduct (clawback): -points (LoyaltyService.php:193 `'points' => -$actualDeducted`)
- Donc SUM(points) du ledger == users.loyalty_points attendu (si aucun solde pré-ledger).
- Arrondi earn: AwardLoyaltyPointsOnDelivery.php:103 `$pointsToAward = (int) round($orderTotal * $rate);` — round, pas floor (L1 D11).
- Welcome +25: LoyaltyController.php:194-224 — `$isNewLoyaltyAccount = !$user->loyalty_code` ; bonus une seule fois à la création du loyalty_code ; route POST /api/frontend/loyalty/register throttle:5,1 (routes/api.php:1428).
- Redeem > solde: PosRedemptionService.php:134-141 INSUFFICIENT_BALANCE 422 ; mais gate amont `config('pos.manual_discount_enabled') !== true` → DISCOUNTS_DISABLED_V1 (lignes 72-78).

## Verdicts d'étapes

### Étape 1 — Cohérence ledger↔solde : PASS (1 artefact data, pas un bug produit)
- Scope: tous users avec loyalty_points>0 OU des LoyaltyTransaction → 1 user (id=44 "Victim Secret", VICT1234, créé 2026-06-08, fixture adversariale héritée du clone).
- SUM(ledger)=-85 (earn +15 ordre 4489, redeem -100 ordre 4511) vs balance=165 → écart +250 = solde d'ouverture posé HORS produit (DB write directe d'une session d'abuse antérieure). Preuve interne: balance_after chain 265→165 cohérente avec +15/-100 depuis 250, et last balance_after == users.loyalty_points (165==165).
- Vérif exhaustive des writers produit de loyalty_points (grep app/): AwardLoyaltyPointsOnDelivery (earn, ledger :126-137), LoyaltyController::register welcome (:210-219), addPoints (manual_add :279-291), redeem web/kiosk (:395-411), FrontendOrderService kiosk redeem (createKioskLoyaltyRedeemLedger :915+), PosRedemptionService (:180-190), LoyaltyService refundPoints (:99-108) + clawbackEarnedPoints (:188-197) — TOUS écrivent une ligne ledger dans la même transaction. Aucun chemin produit ne mute le solde sans ledger.
- Verdict: invariant produit OK ; l'écart vient d'un seed direct DB (artefact e2e). Note P3: addPoints garde un `Schema::hasTable('loyalty_transactions')` (:278) qui muterait le solde sans ledger si la table manquait — théorique, table présente.

### Étape 2 — Solde jamais négatif (PosRedemptionService) : PASS
- Service réel appelé en tinker sur foodking_e2e, order 4511 (unpaid, status=1), user 44 solde=165, rate=100.
- redeem 200 → REFUSED INSUFFICIENT_BALANCE http=422 « Solde insuffisant : 165 pts disponibles, 200 demandes » (PosRedemptionService.php:134-141)
- redeem 100000 → REFUSED INSUFFICIENT_BALANCE 422 ; redeem -100 → INVALID_POINTS 422 ; redeem 0 → INVALID_POINTS 422 (ligne 85-87)
- Solde 165 inchangé, 0 ligne ledger ajoutée (2→2). Aucun chemin vers solde négatif via ce service.

### Étape 3 — Earn réel POS phone-keyed : PASS (avec note rate=10)
- ⚠️ Le clone e2e a `loyalty_points_per_euro=10` (Settings DB), PAS 1 (la consigne lane supposait ×1 ; owner gate-D1 + L1 = 1pt/€). Vérifié contre le taux RÉEL (10).
- Chemin réel complet sur :8767/foodking_e2e :
  1. Customer créé status=5 (id=67, phone 0699111222, loyalty_code NULL)
  2. GET /api/frontend/loyalty/balance?code=0699111222 → 200, mint loyalty_code=4ECBF18B, points=0 (note: PAS de bonus bienvenue sur lazy-mint — seul /register le donne)
  3. POST /api/admin/pos/quote puis POST /api/admin/pos avec customer_id SEUL (pas de loyalty_customer_code dans le payload) → 201 order 4520, total 9,00€, status=7 PREPARING, payment_status=5 PAID
  4. DÉRIVATION PROUVÉE: order.loyalty_customer_code='4ECBF18B' dérivé server-side de customer_id ; loyalty_points_awarded=NULL
  5. POST /api/admin/pos-order/change-status/4520 {status:13 DELIVERED} → 200
  6. Ledger: 1 ligne earn +90 = round(9,00×10) ✓, balance_after=90, source_surface='pos', description='Commande #1206264520', sentinel order.loyalty_points_awarded=90, users.loyalty_points=90
- ANTI-DOUBLE-ACCRUAL: rejoué le MÊME OrderStatusChanged(7→13) 2× via listener réel (tinker) + re-POST HTTP change-status 13 → toujours 1 seule ligne earn, balance=90, awarded=90. Sentinel atomique (AwardLoyaltyPointsOnDelivery.php:52-60 whereNull loyalty_points_awarded) tient.
- Note: le re-POST change-status DELIVERED→DELIVERED répond HTTP 200 (pas d'erreur transition) — sans effet comptable ; hors scope lane (statut machine = autre lane).

### Étape 4 — Welcome +25 idempotent : PASS
- POST /api/frontend/loyalty/register {phone:0699333444} 2× (espacés 8s, throttle:5,1 routes/api.php:1428) → les 2 répondent 200 avec points=25, même loyalty_code 77E29818.
- Ledger user 70: exactement 1 ligne earn +25 'Bonus de bienvenue' (source kiosk), balance_after=25, sum(ledger)=25==balance. Idempotence par construction (`$isNewLoyaltyAccount = !$user->loyalty_code`, LoyaltyController.php:194).

### Étape 5 — Arrondi round() (pas floor) : PASS
- Code: AwardLoyaltyPointsOnDelivery.php:84 lit `loyalty_points_per_euro` (défaut 1) ; :103 `$pointsToAward = (int) round($orderTotal * $rate);` (commentaire L1 D11 « was floor »).
- Preuve empirique au taux LIVE du clone (10 pts/€) — listener RÉEL dispatché sur clones d'order 4520 (orders 4525-4528, fiscal_sequence_no NULL, artefacts test sur clone jetable) :
  - 8,50€ → 85 pts ; 8,49€ → 85 pts (round(84,9)=85 ; floor aurait donné 84 → DISCRIMINANT) ; 8,45€ → 85 (half-up) ; 8,44€ → 84.
- Adaptation: la consigne « 8,50→9 / 8,49→8 » supposait taux=1. Tentative de flip in-process à 1 inopérante (cache Settings in-process : orders 4529/4530 ont quand même earn à 10) — sans impact, le round() est prouvé rate-indépendant par le cas 8,49. Setting DB vérifié intact après coup (payload $value=10, updated_at inchangé 2026-06-10 23:09:07).
- Cohérence finale user 67: balance=599 == sum(ledger)=599 (90+85+85+85+84+85+85).
- ⚠️ FLAG orchestrateur: clone e2e seedé à 10 pts/€ alors que gate-D1/L1 owner = 1 pt/€ (et défaut code = 1). À vérifier côté DB opérante (interdite à cette lane).

### Étape 6 — Re-run PHPUnit ciblé : PASS
```
./vendor/bin/phpunit --filter "PosLoyaltyAccrualRealPath|PosCustomerActiveStatus5|LoyaltyBalanceThrottleParity"
PHPUnit 9.6.29 — OK (6 tests, 37 assertions) — 00:01.543
```

## SYNTHÈSE LANE F.1
- Étape 1 ledger↔solde: PASS produit (1 artefact data user 44, seed pré-ledger hors produit). Re-check final post-mutations: 3 users en scope, toujours 1 seul mismatch (user 44).
- Étape 2 solde négatif: PASS (INSUFFICIENT_BALANCE/INVALID_POINTS, solde+ledger intacts).
- Étape 3 earn réel: PASS (dérivation + earn +90=round(9×10) + anti-double-accrual 3 rejeux).
- Étape 4 welcome: PASS (1 seul bonus, idempotent).
- Étape 5 arrondi: PASS (round half-up prouvé empiriquement, 8,49→85 à 10pts/€).
- Étape 6 tests: 6/6 OK.
- 0 P0/P1 produit. Flags: rate e2e=10pts/€ vs owner 1pt/€ (data) ; lazy-mint balance sans welcome vs register avec (asymétrie produit mineure) ; Schema::hasTable guard addPoints (théorique).
- Artefacts créés sur clone jetable: users 67/70, orders 4520 + 4525-4530 (clones sans fiscal_seq), token sanctum 'f1-lane-2026-06-12'.

### Post-scriptum re-check final
- 2e mismatch apparu en cours de run: user 61 « F2 Lane Customer » (F2LOYAL1, créé 2026-06-12 02:28:38) = fixture de la lane F.2 CONCURRENTE, solde 500 seedé direct-DB puis 3 redeems -100 (chaîne balance_after 400→300→200 cohérente). Même classe d'artefact que user 44 — PAS un bug produit.
- Conclusion inchangée: aucun chemin produit ne crée d'écart ledger↔solde ; les 2 écarts observés = seeds direct-DB de sessions de test.
