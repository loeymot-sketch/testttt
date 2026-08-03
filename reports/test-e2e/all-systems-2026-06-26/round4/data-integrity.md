# Lane — Intégrité données : orphelins / FK / soft-delete / fiscal
DB: foodking_e2e (READ-ONLY, SELECT only) · 2968 orders · 2026-06-27

## Verdict
Cœur SOLIDE. Aucun orphelin, aucune FK cassée, aucun mismatch d'argent, chaîne
NF525 immuable INTACTE (`fiscal:verify-chain --branch=1` → CHAIN OK). Toutes les
anomalies trouvées sont de la **test-pollution / données de seed** (P3), pas des
trous de production. Aucun P0/P1/P2 réel.

## Verified-CLEAN (preuves)
- order_items orphelins = 0 ; order_payments = 0 ; order_coupons = 0 ; order_addresses = 0
- FK: delivery_boy_id cassé = 0 ; user_id = 0 ; branch_id = 0 ; order_items.item_id = 0
- Money: PAID avec lignes order_payments dont SUM(amount) ≠ total = **0** (ABS>0.01)
- NF525 chaîne immuable: `php artisan fiscal:verify-chain --branch=1` → `CHAIN OK (audit_logs + z_reports)`
- fiscal_alloc_error_at non-null = 0 ; duplicate fiscal_sequence_no branch 1 = 0

---

## [P3] orders.fiscal_sequence_no — GAP 2506-2508 (branche 1)
**Repro:** `SELECT seq FROM (compteur 1..2574) WHERE seq NOT IN (SELECT fiscal_sequence_no FROM orders WHERE branch_id=1)` → 2506,2507,2508 manquants. branch1: MIN=1 MAX=2574 distinct=2571 (3 trous), 0 doublon.
**Evidence cause:** entre order id 4974 (fiscal 2505, 2026-06-19) et 5019 (fiscal 2509, 2026-06-20), ~19 lignes orders HARD-deleted (ids 4975,4978,4985,4986,4994,4997-4999,5001-5003,5006,5009-5015 absentes même `withTrashed`). `deletion_log` VIDE pour cette fenêtre (`SELECT * FROM deletion_log WHERE model_id BETWEEN 4974 AND 5019` → 0 row) → suppression manuelle DB hors-app (l'observer `SoftDeleteAuditObserver` n'a pas tracé).
**Pourquoi PAS P1/P2:** (1) `FiscalSequenceService::next()` alloue MAX(withTrashed)+1 → un soft-delete NE crée PAS de gap ; seul un hard-delete DB le crée. (2) Le code app cleanup `CleanupWebTestOrdersCommand:39,60` garde explicitement `whereNull('fiscal_sequence_no')` + skip si fiscalisé → AUCUN chemin de prod ne hard-delete un order fiscalisé. (3) Chaîne légale NF525 = audit_logs (append-only, HMAC) → `verify-chain` = CHAIN OK, audit_logs garde 10 refs à 2506-2508. Le gap est dans la table secondaire mutable orders, pas dans le registre immuable.
**Lentille:** test-pollution canonical DB (suppression manuelle SQL de commandes de test).
**Reco:** P3 hygiène. Documenter que la DB dev a des hard-deletes manuels ; ne JAMAIS hard-delete via SQL une commande fiscalisée en prod (le code app le garde déjà). Pas de heal code.
**Frozen:** non.

## [P3] payment_status valeurs hors-enum (0 et 1)
**Repro:** `SELECT id,payment_status FROM orders WHERE payment_status NOT IN (5,10,15,20)` → order 9 (paystatus=0, pos, 49€, 2026-05-28), order 68 (paystatus=1, pos, 3€, 2026-05-28).
**Evidence:** `PaymentStatus` enum = {5,10,15,20}. 0 et 1 invalides. Données seed du 2026-05-28 (premier jour). Non fiscalisées.
**Lentille:** seed/test ancien. **Reco:** P3, nettoyer le seed. **Frozen:** non.

## [P3] orders.status valeurs hors-enum (2 et 5)
**Repro:** `SELECT status,COUNT(*) FROM orders WHERE status NOT IN (1,4,7,8,10,13,16,19,22)` → status=2 (1 order), status=5 (15 orders). `OrderStatus` enum ne définit ni 2 ni 5.
**Evidence:** status=5 = batch delivery seed ids 4954-4967 (tous créés 2026-06-17 14:38:18, montants seed 14.30/36.93/...). status=2 = orders 235,256 (kiosk 2026-05-28).
**Lentille:** seed/test. **Reco:** P3. **Frozen:** non.

## [P3] 60 commandes CANCELED gardent payment_status=PAID + fiscal consommé
**Repro:** `SELECT COUNT(*) FROM orders WHERE fiscal_sequence_no IS NOT NULL AND status=16 AND payment_status<>20` → 60 (batch kiosk 2.00€ 2026-05-28, fiscal 2018-2057).
**Evidence:** payées+annulées sans passer REFUNDED. PAS de fuite revenu : `Order::scopeRealizedRevenue`/netting Z exclut CANCELED/REJECTED/RETURNED (Order.php:271-272,288). Donc exclues du CA Z. Fiscal consommé mais hors-Z = acceptable NF525.
**Lentille:** test batch. **Reco:** P3. **Frozen:** non.

## [P3] 19 PAID sans fiscal_sequence_no (9 live + 10 soft-deleted)
**Repro:** `WHERE payment_status=5 AND fiscal_sequence_no IS NULL` → 9 (deleted_at NULL) + 10 (deleted). Les 9 = batch delivery seed ids 4954-4961 (déjà connu, contexte). composition_snapshot NULL sur ces mêmes lignes (legacy item_variations à la place).
**Lentille:** seed connu. **Reco:** P3. **Frozen:** non.
