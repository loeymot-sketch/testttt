# RUN_C15_TESTS_P93_UNIQUES_2026-04-20

**Cycle**: cleanup post-T20  
**Date**: 2026-04-20  
**Mode**: audit + exec sélectif (RUNNER_MODE single-session, auto-remediation active)  
**Status**: COMPLETED  
**Bug signature**: n/a (port test additif)

---

## Inventaire tests p93-uniques (post X1/X2)

13 fichiers Feature + 1 fichier Unit restants après X1/X2 (Observability portés).

| # | Test | Zone | Verdict |
|---|------|------|---------|
| 1 | `Availability/AvailabilityIdempotencyAndIsolationTest` | availability | **SKIP** P11 collision |
| 2 | `Availability/AvailabilityToggleLatencyTest` | availability | **SKIP** P11 collision |
| 3 | `Frontend/OfflineQueueReplayIdempotencyTest` | offline K-3 | **SKIP** testttt offline simpler (T14c requis) |
| 4 | `Frontend/OrderAvailabilityGuardTest` | order + availability | **SKIP** P11 collision |
| 5 | `Frontend/SimpleItemResourceAvailabilityTest` | availability | **SKIP** P11 collision |
| 6 | `ItemAttributeRoleTest` | item_attributes.role | **SKIP** colonne absente (cf. C9) |
| 7 | **`KDS/KdsSnapshotImmutableTest`** | KDS / Order invariant | **PORT ✓** |
| 8 | `KioskContextEndpointTest` | route /kiosk/context | **SKIP** route absente |
| 9 | `KioskMultiBranchPentestTest` | branch_id | **GATE** (lié C7) |
| 10 | `KioskEventBranchSpoofingTest` | branch_id | **GATE** (lié C7) |
| 11 | `KioskUX/KioskUiEventsWhitelistTest` | front analytics | **SKIP** déjà cancelled (X4) |
| 12 | `ObservabilityEventWhitelistTest` | front analytics | **SKIP** déjà cancelled (X3) |
| 13 | `SentryBridgeTest` | Sentry | **SKIP** (Sentry retiré activement de p93) |
| 14 | `Unit/Security/KioskThrottleKeysTest` | auth (rate limit) | **QUEUE C11** (garde-fou de la K-6.3/K-6.4 backport) |

## Trouvaille — `KdsSnapshotImmutableTest` (K-2 ADR-5 invariant)

### Contexte

> A committed order is a contract with the kitchen. Once persisted, a later
> availability change (POS toggles item 86, branch rupture, service rearm)
> MUST NOT mutate `order_items.item_id`/pricing snapshot, `item_variations`,
> `item_extras`, `orders.total`/`subtotal`, ou la liste des OrderItem.

### Vérification dépendances testttt

| Dépendance | Statut |
|---|---|
| `App\Services\Menu\AvailabilityService` | ✓ présent |
| `App\Models\ItemBranchAvailability` | ✓ présent |
| `App\Events\ItemAvailabilityChanged` | ✓ présent |
| `tests/TestCase::seedMinimalSettings()` / `seedSpatieRoles()` | ✓ présents |
| Enums `OrderStatus::PREPARING/PREPARED`, `OrderType::TAKEAWAY`, `PaymentStatus::PAID` | ✓ présents |

→ **Port sans modification**.

### Couverture ajoutée

Deux scénarios :
1. **`test_toggling_availability_does_not_mutate_existing_order`** : order crée avec `subtotal`/`total`/`item_id`/`item_variations`/`item_extras`, puis `AvailabilityService::toggle(available: false)`. Snapshot avant/après comparé champ par champ. Vérifie aussi que `ItemAvailabilityChanged` est dispatché (preuve que le toggle a effectivement basculé).
2. **`test_rearming_availability_also_does_not_mutate_orders`** : symétrique sur le rearm (`available: true`).

### Risque

**Bas** : test-only, lecture seule sur la production code. Si testttt avait une régression sur l'invariant K-2 ADR-5, le test échouerait IMMÉDIATEMENT — utile signal.

### Résultat

- **2/2 PASS** — l'invariant K-2 ADR-5 est conforme dans testttt
- 19 assertions
- Time 0.6s

## Garde-fous P11

L'ajout de ce test n'introduit pas de collision avec P11 :
- Fichier nouveau (`tests/Feature/KDS/KdsSnapshotImmutableTest.php`), pas de conflit de merge possible
- Si P11 modifie `AvailabilityService::toggle()` signature, ce test cassera et signalera explicitement le breaking change — comportement souhaité
- Si P11 ajoute ses propres tests sur l'availability, ils sont dans `tests/Feature/Availability/*` (différent path)

## Tests C11 garde-fou queued

`Unit/Security/KioskThrottleKeysTest.php` n'a pas été porté isolément car il **valide explicitement** les comportements K-6.3 + K-6.4 que p93 a et testttt n'a pas (vérifie présence de `kiosk:`, `anon`, et que 2 kiosks sur même NAT ont des keys distinctes). Le porter sans C11 = échec immédiat.

→ **Queue pour batch C11** : quand user approuve la backport K-6.3+K-6.4, port atomique du test + de la backport dans le même commit pour démontrer la valeur du hardening (test rouge → vert).

## Tests régression élargie

```
PHPUnit Feature suite (591 tests) :
- Avant C15 : 589 passed
- Après C15 : 591 passed (+2 = KdsSnapshotImmutable)
- 0 régression
- 8 skipped (pré-existants, sans rapport)
```

## Diff

```
tests/Feature/KDS/KdsSnapshotImmutableTest.php (nouveau, 205 LOC, copie p93)
1 file changed, 205 insertions(+)
```

## Verdict

**CLOSED — sans gate** (ajout de test, pas de modification production code, P11 non-bloquant car nouveau path).
