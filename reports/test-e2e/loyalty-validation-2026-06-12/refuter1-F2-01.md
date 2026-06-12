# REFUTER n°1 — F2-01 (Redeem POS plafond sur sous-total BRUT)

Date: 2026-06-12 — harnais :8767 / foodking_e2e (clone jetable)
Verdict: **NON RÉFUTÉ — finding CONFIRMÉ, reproduit indépendamment de zéro** (mes propres données, pas l'order 4534 du rapporteur).

## 1. file:line vérifié (Read)
- `app/Services/Loyalty/PosRedemptionService.php:144-151` — guard `if ($discountEur > $subtotal)` compare au sous-total BRUT, ignore `$order->discount` existant. EXACT.
- `:213-216` — `$newDiscount = round($currentDiscount + $discountEur, 2)` (empilement).
- `:233-237` — `$newTotal = round(max(0, $subtotal - $newDiscount + $currentDelivery), 2)` — le clamp `max(0,…)` masque le dépassement. EXACT.
- Préconditions atteignables en V1: `config('pos.manual_discount_enabled')` défaut TRUE (`config/pos.php:172`, aucun override .env.e2e/.env), rate défaut 100 pts/€.

## 2. Reproduction indépendante (mes données)
- Client créé: user 74, loyalty_code `REFUT201`, 1000 pts.
- Order créé: **4549** branch 1, subtotal 25.00, discount préexistant 20.00, total 5.00, PENDING(1)/UNPAID(10).
- Repro: `POST /api/admin/pos-order/4549/redeem-loyalty {"points":600,"loyalty_code":"REFUT201"}` (Bearer admin 1 + x-api-key + X-Idempotency-Key)
- Résultat: **HTTP 200** `{"discount_eur":6,"balance_after":400,"order":{"subtotal":25,"discount":26,"total":0},"transaction_id":39}`
- DB post: orders.4549 `discount=26.000000 > subtotal=25.000000, total=0.000000` ; ledger txn 39 `redeem -600 balance_after=400` → 600 pts (6€) débités pour 5€ rendus = **1€ de points client détruit silencieusement** + ligne Remise > Sous-Total persistée.
- Contrôle (case C, sans remise préexistante): order **4550** subtotal 25 discount 0, redeem 2600 pts → **HTTP 422 DISCOUNT_EXCEEDS_SUBTOTAL**. Le trou n'existe QUE en présence d'une remise préexistante — exactement la thèse du finding.
- Evidence brute: `refuter1-F2-01-curl.txt` (même dossier).

## 3. Sévérité
- P2 = JUSTE. Pas un finding multi-tenant/cloud sur-coté: atteignable en V1 LOCAL (flag remises ré-activé par owner gate), impact réel = destruction silencieuse de valeur points client + row fiscalement incohérente (discount>subtotal entre dans les agrégats remise du Z). Pas P1: exige empilement remise préexistante + sur-redeem délibéré côté caisse, dégâts bornés (pas de perte caisse au-delà de la remise affichée), pas de gap de séquence fiscale.
- Pas un dedup connu: lots release/v1 A-H et dashboard-deep 06-08 ne contiennent pas de stacked-discount loyalty redeem; l'abuse-test loyalty 2026-06-11 (18/18) couvrait coupon/offer + case C, pas l'empilement.

corrected_sev=P2, refuted=false.
