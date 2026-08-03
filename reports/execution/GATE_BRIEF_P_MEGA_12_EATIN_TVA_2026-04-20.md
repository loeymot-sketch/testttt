# GATE_BRIEF P-MEGA-12 — Eat-in vs Takeaway TVA

**Cycle** : P_MEGA_W5_EATIN_TPE_RECEIPT_2026-04-20 — Phase D
**Source** : `reports/execution/AUDIT_P_MEGA_12_EATIN_TAKEAWAY_2026-04-20.md`
**Niveau gate** : 🔴 **HUMAN_GATE pricing + fiscal**
**Décideur attendu** : Owner produit + expert-comptable / fiscal FR

---

## Question business à trancher

**Le moteur de pricing FoodKing doit-il appliquer une TVA différentielle selon `order_type` (sur place vs à emporter) conformément à la loi française, et à quel niveau de granularité ?**

## État actuel (1 phrase)

`PricingService::calculateOrder` lit la TVA uniquement depuis `items.tax_id` → **bascule sur place ↔ à emporter ne change AUCUN montant TVA** ; ticket borne thermique sans mention obligatoire NF525 du mode.

## Risque concret

- 🔴 **Redressement fiscal FR** : si contrôle URSSAF/DGFiP sur un panier alcool ou bouteille fermée, la TVA appliquée sera identique sur place (10%) et à emporter (5.5% pour produit consommation immédiate, 20% boisson alcoolisée à emporter), donc fausse.
- 🔴 **Non-conformité NF525** : ticket borne sans libellé "Sur place" / "À emporter".
- 🟡 **Reset POS post-print** force TAKEAWAY → erreur saisie commande suivante.

## Options proposées

### Option A — Table de règles centralisée `tax_rules(item_id|category_id, order_type) → tax_id`
- ✅ SSOT propre, scalable
- ✅ Compatible audit fiscal
- ❌ Migration DB + admin UI règles (~400-600 LOC + UX dashboard)
- ❌ Risque migration data legacy

### Option B — Duplication articles (un article par mode)
- ✅ Pas de migration schema
- ❌ Catalogue x2 = chaos UX admin
- ❌ Synchronisation manuelle des prix
- ❌ Reporting impossible

### Option C — Computed à la volée dans `PricingService` via convention naming/category
- ✅ Léger (~200 LOC)
- ❌ Convention magic = dette future
- ❌ Pas auditable

### Option D — STATU QUO + alerte légale
- ❌ Risque amende
- ✅ Zéro effort
- ⚠️ Acceptable UNIQUEMENT si tous les items du catalogue sont neutres au mode (ex: seulement boissons non-alcoolisées) → à vérifier business

## Recommandation orchestrator

**Option A** — table `tax_rules` avec `priority` ASC, fallback sur `items.tax_id`. Migration en 2 phases :
1. Phase 1 (~300 LOC) : créer table + service `TaxResolver` + propagation `order_type` dans `PricingRequest` + tests sentinelles. Phase 1 SANS UI admin (rules en seeders/config initial).
2. Phase 2 (~200 LOC ultérieur) : UI admin pour gérer règles dynamiquement.

Plus complétion tickets borne mention mode + maps i18n KIOSK (~150 LOC séparable, peut être pré-fix routine).

## Tests sentinelles à créer AVANT toute fix (rouge documenté)

1. `test_tva_changes_when_order_type_switches_for_alcohol_item` (Feature, expected RED)
2. `test_kiosk_thermal_receipt_contains_consumption_mode_label` (Vitest snapshot, expected RED)
3. `test_lang_orderType_php_has_kiosk_key_in_all_locales` (Feature, expected RED)

## Décision attendue

| Question | Choix |
|---|---|
| Option A / B / C / D ? | ☐ |
| Phase 1 + Phase 2 séparées ? | ☐ Oui ☐ Non |
| Pré-fix tickets + i18n routine maintenant ? | ☐ Oui ☐ Non |
| Validation matrice fiscale par expert-comptable ? | ☐ Acquise ☐ À faire |

## Impact LOC + zones (si Option A approuvée)

- `app/Services/Pricing/PricingRequest.php` : ~30 LOC (param order_type)
- `app/Services/Pricing/PricingService.php` : ~50 LOC (lookup TaxResolver)
- `app/Services/Pricing/TaxResolver.php` (NEW) : ~80 LOC
- `database/migrations/2026_xx_create_tax_rules_table.php` (NEW) : ~40 LOC
- `app/Models/TaxRule.php` (NEW) : ~30 LOC
- Tests Feature/Pricing/TaxRulesMatrixTest.php : ~120 LOC
- **Total Phase 1** : ~350 LOC

Pré-fix tickets/i18n (séparable) : ~150 LOC.
