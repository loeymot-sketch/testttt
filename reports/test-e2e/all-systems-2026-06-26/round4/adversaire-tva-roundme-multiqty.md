# Adversaire — Réfutation finding TVA arrondi multi-quantité (round4)

## Finding candidate (completeness-critic)
[P3] TaxCalculator.lineTaxAmountFromTTC (round par ligne) + ZReportService:711-722,439 —
« Classe d'arrondi monétaire sur MULTI-QUANTITÉ jamais validée numériquement ; écart 1
centime tickets vs total TVA Z possible mais non démontré ; audit-only / documenter tolérance. »

## Verdict : REFUTED

### Ce qui est vrai (mais inoffensif)
- `PricingService.php:234,251-256` : la TVA est extraite **PAR LIGNE sur le total de ligne
  (qty déjà multipliée)** — `$verifiedTotalPrice = unitSum * quantity` PUIS un **seul**
  `round(.., 2)` dans `lineTaxAmountFromTTC` (`TaxCalculator.php:44-47`). Il n'existe AUCUNE
  étape d'arrondi par-unité × qty. La « dérive d'accumulation multi-quantité » classique
  (round par unité puis × qty) **n'existe pas dans ce code** — c'est précisément l'approche
  ligne-unique qui l'évite.
- Confirmé que per-unité ≠ per-ligne (ex. ordre 5303, item qty4, 32,00 TTC : per-ligne
  `round(32 - 32/1.1,2)=2,91` ; per-unité `round(8-8/1.1,2)×4 = 0,73×4 = 2,92`). Le système
  utilise **per-ligne partout, de façon cohérente** = 2,91 stocké. C'est une méthode NF525
  valide (arrondi au niveau ligne).

### Pourquoi l'écart « ticket vs Z » NE PEUT PAS se produire (cœur de la réfutation)
Le ticket et le Z ne recalculent **PAS** indépendamment la TVA : ils lisent la **MÊME**
colonne persistée `order_items.tax_amount` avec la **MÊME** agrégation somme-puis-arrondi :
- Ticket : `OrderDetailsResource.php:227` `tax_amount_raw += $oi->tax_amount` → `:238 round(.., 2)`.
- Z : `ZReportService.php:711` `SUM(tax_amount)` GROUP BY (order_id, tax_rate) → `:439
  round(.., 2)` par bucket → `:442 total_tva = array_sum`.
Deux lectures identiques de la même source ⇒ **identiques par construction**. Aucune
recomputation divergente ⇒ aucun « écart 1 centime ». Le LOCK_ZREPORT_F1 (`:425-434`) garantit
en plus l'identité `total_tva == Σ total_by_tax_rate` dans la charge signée.

### Repro numérique (foodking_e2e, branch 1) — 0 dérive
```
SELECT SUM(ABS(ROUND(total_price - total_price/(1+tax_rate/100),2) - tax_amount) > 0.0001)
FROM order_items WHERE quantity>1 AND tax_type=10 AND tax_rate>0;
-- lines_mismatch_perline = 0   sur   total_multiqty_lines = 1621
```
Les **1621** lignes réelles multi-quantité correspondent EXACTEMENT à la formule
d'extraction documentée. Zéro mismatch. Ordres inspectés : 5303 (qty4 → 2,91 ✓),
5181 (3 lignes mono-qty 1,04/0,90/0,17 ✓), 4860 (qty3 9,00 → 0,82 ✓).

### Sévérité V1-LOCAL
La garde (arrondi ligne-unique + SSOT colonne unique lue par ticket ET Z) est **déjà
présente** et empêche la dérive hypothétisée. Le finding est spéculatif (« non démontré »,
« probablement inoffensif ») et la repro le **réfute** : système interne cohérent
(order.total ⇄ ticket TVA ⇄ Z TVA dérivent tous du même `tax_amount` par-ligne). Aucun
préjudice NF525/argent. Au mieux une note doc, pas un défaut.

## Lentille
Completeness-critic « jamais validé numériquement » → la validation numérique montre 0 dérive
et une garde structurelle (source SSOT unique partagée ticket/Z). REFUTED + preuve.

## Frozen
N/A — aucun heal. ZReportService/Pricing/TaxCalculator non touchés (lecture seule).
