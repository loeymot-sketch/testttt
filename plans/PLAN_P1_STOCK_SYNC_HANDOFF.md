# P1 — Stock sync POS ↔ Kiosk ↔ KDS (handoff)

## Audit (état avant ce cycle)

| Zone | État |
|------|------|
| Données | `item_branch_availability` + `AvailabilityService` (toggle, auto-86, `lockForUpdate` sur toggle) |
| Événements | `ItemAvailabilityChanged` → outbox `PersistItemAvailabilityChangedToOutbox` (`DB::afterCommit` pour job) |
| Temps réel | `private-branch.{branchId}`, `broadcast_as` = `ItemAvailabilityChanged` |
| Kiosk | `kioskMenu/UPDATE_ITEM` + invalidation cache Redis |
| POS | `PosComponent` handler `ItemAvailabilityChanged` |
| KDS | Affiche les commandes déjà créées ; la **non-création** de nouvelles lignes indisponibles suffit pour « ne plus recevoir d’orders avec cet item » côté nouvelles commandes |

**Écart principal corrigé ici :** aucun rejet serveur au **checkout** si l’article était passé « rupture » entre le chargement menu et le POST order.

## Cible implémentée

1. **`AvailabilityService::assertItemsOrderableForBranch`** : vérifie chaque `item_id` pour `branch_id` ; `lockForUpdate` sur les lignes existantes quand `$useRowLock=true` (commande dans une transaction).
2. **`PricingService::calculateOrder`** : appelle l’assert pour tout contexte (kiosk / pos / web / table) ; **preview** (`orderId === 0`) : assert **sans** lock (lecture seule).
3. **Chemins legacy** (SSOT désactivé) : même assert dans `FrontendOrderService` + `OrderService` (web, POS, table).
4. **Kiosk cart** : `kioskCart/pruneUnavailableLines` après chaque `ItemAvailabilityChanged` pour retirer les lignes de panier quand le menu marque l’article indispo.

## SYMMETRY_NOTE

`FrontendOrderService` et `OrderService` : garde disponibilité alignée (SSOT via `PricingService` + branches `else` explicites).

## Tests

- `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php` — POST kiosk 422 si rupture branche.

## Suite recommandée (hors scope minimal)

- Playwright cross-surface : POS toggle rupture → assertion menu kiosk < 2 s + panier vidé + tentative commande 422.
- Course complète « première ligne `item_branch_availability` insérée pendant commande » : faible probabilité ; amélioration possible via isolation RC ou verrouillage applicatif ciblé.

## Invariants

- **branch_id** : assert limité au `branch_id` de la commande / `PricingRequest`.
- **Pricing SSOT** : aucun calcul prix côté front ; rejet avant insert des lignes.
- **Dispatch after commit** : inchangé (déjà `afterCommit` sur outbox availability).
