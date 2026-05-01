# Wizard Product Model — Version A

Status: canonical VA-SYS-09 document.

## Product kinds

| Kind | Composer profile | Runtime behavior |
| --- | --- | --- |
| Ready product | none | Add directly to cart if product is available. |
| Simple option product | optional short profile | One or two steps, for example size/sauce. |
| Complex composed product | published profile required | Multi-step wizard, min/max/repeat constraints, stockable choices. |
| Addon target product | may be standalone or addon | Can be stock-decremented when selected as addon. |
| Ingredient/crudite/sauce/supplement | wizard choice | Can be stockable and unavailable per branch. |

## Backend model

Main files:

- `app/Models/Item.php`
- `app/Models/ItemWizardProfile.php`
- `app/Models/ItemWizardStep.php`
- `app/Models/ItemVariation.php`
- `app/Models/ItemExtra.php`
- `app/Models/ItemAddon.php`
- `app/Services/Composer/ComposerProfileService.php`
- `app/Services/Composer/ComposerStepService.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/Stock/ChoiceAvailabilityResolver.php`

## Runtime rules

1. No published composer profile means no forced wizard.
2. Published composer profile takes priority over old heuristic wizard logic.
3. A profile can be global or branch-scoped; branch actors cannot mutate foreign branch profiles.
4. Every submitted choice must still exist in the current published projection.
5. Required steps cannot be satisfied by unavailable choices.
6. `repeat`, `min`, `max`, and role semantics are validated by backend pricing.
7. POS/Kiosk UI only helps the user; backend remains the rejection authority.

## Choice availability

`ChoiceAvailabilityResolver` resolves:

- variations via `StockLevel` rows keyed by `ItemVariation::class`;
- extras via `StockLevel` rows keyed by `ItemExtra::class`;
- addons via addon target `Item` stock + `item_branch_availability`;
- surface visibility for addon item on `pos` / `kiosk`.

Returned shape is projected into:

- `ItemResource`
- `NormalItemResource`
- `ItemExtraResource`
- `ItemAddonResource`
- `MenuProjectionService`
- `KioskMenuService`

## Frontend surfaces

| Surface | Files | Responsibility |
| --- | --- | --- |
| Kiosk wizard | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`, `KioskStepGenericChoicesComponent.vue` | display composer steps, disable unavailable choices, prune stale restored selections |
| POS item modal | `resources/js/components/admin/pos/ItemComponent.vue` | show disabled choices, block direct JS method calls, remove stale restored selections |
| Cart/snapshot | `kioskCart.js`, POS component state | preserve user edit state but sanitize before submit |

## Pricing and stale data

Backend `PricingService` rejects:

- forged price/total/subtotal;
- inactive item/choice;
- choice unavailable for branch;
- choice absent from current published composer profile;
- invalid min/max/repeat selection;
- addon target unavailable on the requested surface.

## Historical order snapshots

Order items persist `composition_snapshot` and allergen snapshots. Historical receipts/KDS lines must read snapshots first; they must not recompute old order meaning from the live catalog after a product or wizard changes.

## Tests

- `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`
- `tests/Feature/ItemAttributeComposerResourceTest.php`
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php`
- `tests/js/kioskWizardGenericComposer.spec.js`
- `tests/js/posWizardComposerProfile.spec.js`

