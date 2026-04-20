# RUN_P_MEGA_07 — Audit TVA breakdown par taux

**Date** : 2026-04-20  
**Cycle** : P-MEGA  
**Tâche** : P-MEGA-07 (vague 2 — pricing SSOT / fiscal)  
**Verdict** : **AUDIT CLOSED — INCOHÉRENCE STRUCTURELLE TTC/HT IDENTIFIÉE → HUMAN GATE**  
**Mode** : audit lecture-seule, aucun code modifié

## Périmètre audité

- `app/Enums/TaxType.php` (FIXED=5, PERCENTAGE=10)
- `app/Services/Pricing/TaxCalculator.php` (calcul ligne)
- `app/Services/Pricing/PricingService.php` (orchestration)
- `app/Services/OrderService.php::posOrderStore` (POS path)
- `app/Http/Resources/OrderDetailsResource.php::buildTaxLines` (rendu ticket)
- `database/migrations/2023_07_20_095843_add_tax_to_order_items_table.php` (schéma)

## Architecture présumée (cible légale)

Conforme **CGI art. 242 nonies A** (mention TVA obligatoire sur ticket B2C) :
1. Prix admin saisi avec une convention claire (TTC ou HT)
2. Si saisi TTC : `tax_amount = TTC × rate / (100 + rate)` (TVA "à l'intérieur") puis `base_HT = TTC − tax_amount`
3. Si saisi HT : `tax_amount = HT × rate / 100` (TVA "au-dessus") puis `TTC = HT + tax_amount`
4. Ticket affiche `base_HT` et `tax_amount` séparés par taux

## Bug structurel identifié

Il y a une **incohérence de convention** entre la couche calcul et la couche rendu :

### Couche calcul (PricingService) — assume HT

`PricingService.php` ligne 159-164 :
```php
$taxPrice = $this->taxCalculator->lineTaxAmount(
    $verifiedTotalPrice,  // ← passé comme "lineSubtotalExTax" (HT) dans la signature
    $taxType,
    $taxRate,
    $req->roundLineTax
);
```

`TaxCalculator.php` ligne 9-16 :
```php
public function lineTaxAmount(float $lineSubtotalExTax, ...): float {
    $raw = $taxType === TaxType::FIXED
        ? $taxRate
        : ($lineSubtotalExTax * $taxRate) / 100.0;  // ← formule HT → TVA
}
```

→ **Le code calcule la TVA comme si `verifiedTotalPrice` était HT** (formule "TVA au-dessus").

### Couche rendu (OrderDetailsResource) — assume TTC

`OrderDetailsResource.php` ligne 73-101 :
```php
// "Assumes total_price is TTC at the line level"
$totalTtc = (float) ($oi->total_price ?? 0);
$groups[$key]['base_ht_raw'] += max(0.0, $totalTtc - $taxAmount);
```

→ **Le ticket affiche `base_HT = total_price − tax_amount`**, soit assume que `total_price` est TTC.

### Démonstration numérique du bug

Soit 1× pizza à 12€ admin, taux TVA 20%, qty 1.

**Hypothèse A — prix admin HT (cohérent avec PricingService)**

| Champ | Valeur |
|---|---:|
| `dbItem->price` | 12,00€ HT |
| `verifiedTotalPrice` | 12,00€ |
| `tax_amount` (TaxCalc) | 12 × 20/100 = **2,40€** ✓ |
| Vrai TTC client = 12 + 2,40 = 14,40€ |
| Stocké en BD `total_price` | 12,00€ (= HT) |
| Ticket `base_HT` (Resource) | 12 − 2,40 = **9,60€** ❌ FAUX (vrai HT = 12) |
| Ticket `tax` | 2,40€ ✓ |
| Ticket TTC affiché | 9,60 + 2,40 = 12€ ❌ (vrai TTC = 14,40) |

**Hypothèse B — prix admin TTC (cohérent avec OrderDetailsResource)**

| Champ | Valeur |
|---|---:|
| `dbItem->price` | 12,00€ TTC |
| `verifiedTotalPrice` | 12,00€ |
| `tax_amount` (TaxCalc) | 12 × 20/100 = **2,40€** ❌ (vraie TVA TTC→HT = 12 × 20/120 = 2,00€) |
| Stocké en BD `total_price` | 12,00€ (= TTC) |
| Ticket `base_HT` (Resource) | 12 − 2,40 = **9,60€** ❌ (vrai HT = 10) |
| Ticket `tax` | 2,40€ ❌ (vraie TVA = 2,00€) |
| Ticket TTC affiché | 12€ ✓ |

→ **Quelle que soit la convention prix admin (TTC ou HT), le système renvoie une TVA fausse côté ticket**.

## Conséquences réglementaires

- **CGI art. 242 nonies A** : ticket B2C doit mentionner TVA exacte. Une TVA sur-calculée de ~16-20% (pour TTC interprété en HT) constitue **fausse facturation** → risque de redressement fiscal.
- **NF525** : la chaîne d'audit (HMAC) signe `tax_amount` ; si la valeur est mathématiquement incohérente, c'est une **fragilité d'archivage**.
- **Fiabilité bilan compta** : sur 1000 commandes/jour à 12€, la déclaration TVA présente +20% d'erreur sur le calcul, soit ~400€/jour de TVA déclarée en trop (ou en moins selon la convention).

## Couverture tests existants

- ✅ `TaxCalculatorTest.php` (10 tests) : valide la formule HT × rate / 100 (qui est correcte SI le subtotal est HT)
- ✅ `PricingServiceTest.php` : cohérence de la cascade
- ❌ **Aucun test de bout en bout vérifiant `base_HT + tax_amount === total_price` côté ticket**
- ❌ Aucun test pour la formule "TVA à l'intérieur" (TTC → HT)

→ Les tests valident l'**implémentation** mais pas la **conformité fiscale**.

## Convention BD admin (à clarifier)

Le schéma `items.price` est `decimal(8,2) NOT NULL` mais **aucune colonne `tax_inclusive`** n'existe. Le commentaire colonne ne précise pas. La convention est implicite et donc **divergente entre couches**.

Option à confirmer avec l'équipe métier :
- **A** : prix admin HT → fix `OrderDetailsResource` (utiliser `total_price` comme HT, calculer TTC = HT + tax_amount)
- **B** : prix admin TTC → fix `TaxCalculator` (ajouter méthode `lineTaxAmountFromTtc(ttc, rate) = ttc × rate / (100 + rate)`)
- **C** : ajouter colonne `prices_inclusive_of_tax` à `tax` ou au `Setting` global, et brancher conditionnellement

Vu l'usage B2C kiosk/POS (les prix affichés au client = TTC en France), **option B** est la plus cohérente et la moins disruptive (pas de migration BD obligatoire).

## Tests parité POS/Kiosk (manquant)

Audit `AUDIT_MENU_TAX_PRICING_CASCADE_014` (déjà existant, P0, vague B4) demande :
> 8. Un test de parité POS/Kiosk (X fixtures de paniers) existe-t-il pour le total final ?

→ **NON** au 2026-04-20. À ajouter en parallèle du fix.

## Multi-taux français à supporter

- 5,5% : produits alimentaires de base (pain, pâtisseries, plats à emporter froids)
- 10% : restauration sur place, plats à consommer immédiatement
- 20% : boissons alcoolisées, certains produits transformés

Donc le bug TVA peut affecter des taux différents selon `selections.orderType` (sur place / à emporter). Audit AUDIT_MENU_TAX_PRICING_CASCADE_014 question 2 confirme ce point.

→ Nécessite que `tax_id` puisse varier dynamiquement selon le mode de consommation. Actuellement `dbItem->tax_id` est fixe par item, ce qui est **architecturalement insuffisant** pour une chaîne sur-place + à-emporter.

## Remédiations proposées (HUMAN GATE)

### Phase 1 (urgent — corriger le bug TVA)

1. Ajouter `TaxCalculator::lineTaxAmountFromTtc(float $ttc, float $rate, bool $round)`.
2. Décider de la convention via `Setting::get('prices_inclusive_of_tax', true)` ou ajouter une colonne `prices_inclusive_of_tax` à `taxes`.
3. `PricingService` route vers la bonne méthode.
4. Test : pour chaque taux (5.5, 10, 20), assert `base_HT + tax === TTC` exactement.

### Phase 2 (TVA dépendant du mode)

1. Ajouter `tax_id_takeaway` à `items` (nullable, fallback sur `tax_id`).
2. `PricingRequest` reçoit `order_type` et `PricingService` choisit le bon taux.
3. Test parité : fixture identique en sur-place vs takeaway → totaux différents conformément.

### Phase 3 (ticket conforme NF525 strict)

1. `OrderDetailsResource` rend le breakdown par taux avec `base_HT` correctement calculé selon convention BD.
2. Vérifier `ZReportService` agrège les `base_HT` (pas `total_price` brut).
3. Test : Z-report d'une journée multi-taux = somme des base_HT par taux.

## LOC estimées

| Phase | LOC |
|---|---:|
| 1 (fix TaxCalc + convention) | ~80 + tests 250 |
| 2 (TVA bi-mode) | ~150 + migration + tests 200 |
| 3 (ticket / Z-report) | ~120 + tests 180 |
| **Total** | ~980 |

## Demande de gate

→ **Statut** : `GATE_HUMAN_REQUIRED` zone Pricing SSOT + Fiscal NF525.

Décisions requises :
1. Convention prix admin actuelle = ? (TTC ou HT) — **bloquant**
2. Acceptable de modifier rétroactivement les tickets / Z-reports passés ? (Non recommandé : marquer un "cutover date")
3. Faut-il un script de **réconciliation rétroactive** sur la BD ?
4. Le redressement fiscal des données passées est-il un risque connu de l'équipe ?

Aucun commit code, juste ce rapport. Tests Vitest baseline reste 521/521. PHPUnit baseline supposé inchangé.
