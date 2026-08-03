# Vérification adversaire — round4 — Z multi-taux (total_by_tax_rate)

## Finding candidat (lane completeness-critic)
[P3] `app/Services/Fiscal/ZReportService.php` (frozen) :425-442, 711-722 — NF525 :
décomposition TVA multi-taux (`total_by_tax_rate`) jamais exercée avec >1 taux.

## VERDICT : REFUTED

La prémisse centrale du candidat (« ni live ni en test cité ») est **fausse**.
Le chemin de décomposition multi-taux est explicitement exercé et asserté par un
test dédié vert.

### Preuve 1 — test dédié multi-bucket existant (réfute « ni en test »)
`tests/Feature/Fiscal/ZReportTaxBreakdownTest.php` :72-104
- Order B = une ligne @ 10% (2.00) + une ligne @ 5.5% (0.55) dans le MÊME close.
- Assertions : `total_by_tax_rate['10'] == 3.00` (A 1.00 + B 2.00) ET
  `total_by_tax_rate['5.5'] == 0.55` → **deux buckets distincts qui somment**.
- `test_tax_rate_keys_are_canonicalised` couvre la collision de clé "10.00"/"10".

Exécution (re-run) :
```
php artisan test tests/Feature/Fiscal/ZReportTaxBreakdownTest.php
PASS  Tests\Feature\Fiscal\ZReportTaxBreakdownTest
✓ tax breakdown groups per rate
✓ empty when no orders
✓ tax rate keys are canonicalised
Tests: 3 passed
```
La reco exacte du candidat (« seeder item 5,5%, commande mixte 10%+5,5%, asserter
2 buckets sommant à total_tva ») est DÉJÀ implémentée à la couche service.

### Preuve 2 — données live (corrige aussi une affirmation factuelle du candidat)
```
SELECT * FROM taxes;  -> id7 'VAT 5.5' tax_rate=5.500000 (existe, 0 item)
items actifs (status=5) par tax_id :
  tax_id=3 (10%) = 54 actifs
  tax_id=1 (0%)  = 8 actifs    <-- le candidat dit « 0 item actif » : FAUX
```
Le candidat affirme « tax_id=1 (0%) 0 item actif » — réfuté : 8 items actifs à 0%.
Le bucket 0% est donc lui aussi exercé en construction (contribue 0 à `total_tva`
mais traverse `taxBreakdownForOrders`).

### Preuve 3 — live single-rate cohérent (pas de corruption)
```
z_reports : id19 total_by_tax_rate={"10":0.73} total_tva=0.73
            id20 {"10":-0.73} -0.73 (miroir refund symétrique, l.436-438)
```
Identité NF525 `total_tva == Σ total_by_tax_rate` tient sur le live actuel.

## Analyse
- Code frozen (lu seulement) `ZReportService.php:435-442` : logique per-rate +
  `array_sum` + miroirs refund par taux — correcte par construction ET couverte.
- La « non-preuve » alléguée n'existe pas : la couche service est testée avec
  exactement le scénario 10%+5,5% recommandé.
- Sévérité V1-LOCAL : aucun défaut. Le mono-poste Le Cayenne vend aujourd'hui en
  taux unique (10%) + 0%, chemin sain. Ajouter un produit 5,5% en prod
  emprunterait un chemin déjà testé.

## Lentille
NF525 chemin-non-prouvé → INVALIDÉE (chemin prouvé par `ZReportTaxBreakdownTest`).

## Reco
Aucune. Finding redondant avec une couverture existante. Pas de heal (frozen +
rien à corriger). Mention possible au backlog QA : si l'owner introduit un vrai
SKU 5,5% en prod, vérifier projection menu — mais c'est config-data, pas un bug.

## Frozen
`ZReportService.php` = frozen NF525, lu seulement, 0 modification. Aucune escalade
requise (rien à corriger).
