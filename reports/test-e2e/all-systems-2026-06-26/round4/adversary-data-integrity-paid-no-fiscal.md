# Adversaire — Réfutation finding data-integrity « 19 PAID sans fiscal_sequence_no »

## Verdict : REFUTED (pas un P0/P1/P2 réel — fixtures factory/seed, 0 argent)

### Candidate
[P3] foodking_e2e.orders/order_items — 19 PAID sans fiscal_sequence_no + composition_snapshot NULL = seed delivery connu.

### Re-repro (confirmée mais re-cadrée)
```
SELECT COUNT(*), SUM(fiscal_sequence_no IS NULL) FROM orders WHERE payment_status=5;
-> total_paid=2573, paid_no_fiscal=19, paid_with_fiscal=2554
```
Les 19 lignes (détail) :
- **9 live** (deleted_at NULL) : 4953 (type=10, 0 item), **4954-4961** (type=5 delivery, batch 2026-06-17 14:38:18).
- **10 soft-deleted** : 429-433, 4062, 4064, 4855, 4856, 5017 (test-pollution, hors scope normal).

(NB : le candidat disait « 9 live ids 4954-4961 » — c'est 8 ids ; le 9ᵉ live est 4953. Substance identique.)

### Preuve que ce N'EST PAS un orphelin NF525 réel (≠ FISCAL-CPS-01)
Jointure order_payments sur les 19 :
```
WHERE payment_status=5 AND fiscal_sequence_no IS NULL  →  pays=0, paid_amt=0.00  POUR LES 19
fiscal_alloc_error_at = NULL pour les 19
```
- **0 order_payment, 0 € encaissé** sur les 19 → aucun argent réel n'a échappé à un Z. Un vrai orphelin FISCAL-CPS-01 aurait une ligne `order_payments` (argent encaissé sans n° fiscal). Ici `payment_status=5` a été posé DIRECTEMENT par factory/seeder, contournant le pipeline de paiement → d'où l'absence simultanée de payment ET de fiscal_sequence_no.
- `fiscal_alloc_error_at=NULL` → jamais passé par l'allocation fiscale (pas un échec à rejouer, un bypass factory).
- 8 lignes au timestamp EXACT `14:38:18` = signature factory/seeder, pas un parcours caisse réel.

### Pas de corruption fiscale
`ZReportService` filtre `whereNotNull('fiscal_sequence_no')` → ces 19 (fiscal NULL) sont **exclus de tout Z**. Aucun total fiscal corrompu. La requête couvre TOUS les `payment_status=5 AND fiscal_sequence_no IS NULL` = exactement ces 19, tous à `paid_amt=0` → **aucun orphelin argent-réel ne se cache**.

### composition_snapshot NULL
Confirmé sur 4954-4961 (legacy item_variations à la place) — cohérent avec des fixtures factory antérieures au snapshot SSOT. Display-only, déjà géré par les helpers shape-agnostiques KDS (snapshot-first puis fallback legacy). 0 nuisance V1.

### Conclusion adversaire
Reproductible OUI, **réellement nuisible V1-LOCAL NON** : fixtures de test sans argent, exclues des Z, sans payment. Le candidat l'a déjà classé P3-seed/non-actionnable — c'est correct. Aucun heal. Hygiène data : `catalog:clean-test-data` / purge des 9 live seeds pourrait nettoyer les dashboards count `payment_status=5` (P3 cosmétique/déjà-connu), mais 0 risque fiscal/argent/perte/fuite.

Frozen : n/a.
