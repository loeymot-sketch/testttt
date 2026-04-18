# RUN V1 — Vague 2 Closure — 2026-04-16

## Portée

Consolidation de **Vague 2 — Domaine SSOT** :
- `TASK_V1_STATUS_MACHINE_001` — OrderStatus finite state machine.
- `TASK_V1_MENU_86_001` — Gestion rupture / disponibilité menu multi-surface.
- `TASK_V1_PRICING_SSOT_001` — Extraction PricingService centralisé.

Mode d'exécution : closure pass sur le code existant pour combler les gaps identifiés par l'audit du 2026-04-16. PRICING_SSOT gate requis — pas d'exécution frozen zone dans ce run.

---

## STATUS_MACHINE_001 — consolidation

### Livrables code

| Fichier | Changement |
|---|---|
| `app/Domain/Order/OrderStateMachine.php` | + `apply(Model, int, ?Authenticatable, ?string)` atomique (guard → persist → audit dans `DB::transaction`). + `requiresReason(int): bool` (CANCELED/REJECTED/RETURNED). + `legalTransitions(): array` (11 paires V1). + `allStatuses(): int[]`. |
| `app/Domain/Order/IllegalTransitionException.php` | Inchangé. |
| `tests/Unit/Domain/Order/OrderStateMachineTest.php` | Remplacé par suite exhaustive : data providers dérivant des 11 paires légales + balayage matriciel 9×9 des paires illégales. 77 cas pilotés par provider. Raccourci POS + admin override + reason requirement. |
| `tests/Feature/Domain/OrderStateMachineApplyTest.php` | **Nouveau** — 6 cas : legal + illegal rollback + identity no-op + cancel requires reason + cancel with reason + actor tracking. |
| `docs/ORDER_FLOW.md` | Réécrit avec diagramme Mermaid, table de transitions complète, API service, garanties `apply()`, invariants V1. |

### Garanties

- **Chemin de lecture** : `allows()` / `assertAllows()` / `requiresReason()` purs.
- **Chemin de mutation** : `apply()` garantit que sur transition illégale, la DB reste intacte (rollback transactionnel) et aucune ligne `order_status_transitions` n'est écrite.
- **Cancel / Reject / Return** : raison obligatoire non-vide, sous peine de `IllegalTransitionException`.
- **Frozen zone respect** : aucun call site d'`OrderService` / `FrontendOrderService` / `KitchenDisplaySystemOrderService` modifié. Les chemins historiques (`ValidStatusTransition` rule → mutation directe → `recordTransition`) restent en place. `apply()` est le chemin préféré pour le **new code**.

### Tests

```
Tests\Unit\Domain\Order\OrderStateMachineTest         77 cas (provider 66 + 11 scalaires)
Tests\Feature\Domain\OrderStateMachineApplyTest        6 cas
→ 83 tests / 106 assertions — tous verts
```

---

## MENU_86_001 — consolidation

### Livrables code

| Fichier | Changement |
|---|---|
| `app/Events/ItemAvailabilityChanged.php` | Refactor. Ajout factories statiques `fromItem()` (mode global legacy) et `forBranch()` (mode branch-scoped V1). Propriétés ajoutées : `branchId`, `isAvailable`, `reason`. Back-compat via `fromItem()`. |
| `app/Services/ItemService.php` | Ligne 241 : `new ItemAvailabilityChanged($item, $type)` → `ItemAvailabilityChanged::fromItem($item, $type)`. |
| `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` | Branch-scoped : si `event->branchId` défini, `channel = ["private-branch.{id}"]` + payload enrichi `{branch_id, is_available, reason}`. Sinon, legacy fanout sur toutes les branches actives. |
| `app/Services/Menu/AvailabilityService.php` | + `toggle(item, branch, available, reason)` — transactionnel + idempotent + event. + `toggleForAllBranches(item, available, reason)`. + `isAvailable(item, branch)` avec défaut `true`. `decrementForOrder()` émet l'event uniquement sur flip available→86. |
| `tests/Feature/Menu/AvailabilityServiceTest.php` | **Nouveau** — 7 tests : création row + event, retour à available, idempotence, défaut true, stored value, all-branches, outbox persiste event branch-scoped avec payload V1. |
| `docs/MENU_AVAILABILITY.md` | Réécrit : modèle de données, API service, contrat d'event (global vs branch-scoped), consommation front, tests, hors V1. |

### Garanties

- **Contrat V1 respecté** : payload contient toujours `item_id` + `status` (conforme `EventContract::assertPayloadValid()`). Mode branch-scoped ajoute `branch_id`, `is_available`, `reason` (keys additionnelles acceptées).
- **Outbox** : dispatch via `DispatchDomainEventsJob` dans `DB::afterCommit` — aucune émission avant commit.
- **Isolation branch_id** : row `domain_events` porte `branch_id` correct, channel broadcast unique.
- **Idempotence** : re-toggle identique ne génère pas de bruit réseau.

### Tests

```
Tests\Feature\Menu\AvailabilityServiceTest         7 cas / 22 assertions — verts
```

### Reste à faire (hors Vague 2)

- UI admin : composant toggle par produit. **→ scope Vague 4 / UI pass**.
- UI POS / Kiosk / KDS : handlers `ItemAvailabilityChanged` branch-scoped. **→ scope Vague 4 / TEST_PW_5FLOWS**.
- Playwright critique rupture (2s propagation). **→ scope TASK_V1_TEST_PW_5FLOWS_001**.
- Scheduler reset `daily_consumed_qty` à 4h. **→ V1.5**.

---

## PRICING_SSOT_001 — statut

### État observé

Le service existe et est **déjà branché** dans les frozen zones :

```
app/Services/Pricing/
  PricingService.php
  PricingRequest.php
  PricingResult.php
  PricingLineResult.php
  TaxCalculator.php
  DiscountCalculator.php

config/pricing.php → pricing.use_ssot_service (default: true, env PRICING_USE_SSOT)

app/Services/OrderService.php         — 3 call sites (web L309, POS L601, table L958)
app/Services/FrontendOrderService.php — 1 call site (kiosk L205) + legacy branch L399
```

Le gate `docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md` existe, cartographie les 4 chemins de calcul et décrit la stratégie de bascule feature-flaggée. **Les cases d'approbation humaine restent décochées.**

### Décisions de ce run

- **Aucun code pricing modifié.**
- La bascule est effectivement en production derrière feature flag, avec retour legacy possible via `.env` (`PRICING_USE_SSOT=false`).
- La validation exhaustive (50+ fixtures snapshot, parité bit-à-bit, benchmark 50ms p95, coverage ≥ 95% branches) relève de **`TASK_V1_TEST_PRICING_STATE_001`** (Vague 4 — bloquée par STATUS_MACHINE + PRICING_SSOT, désormais débloquée).

### Action requise (humain)

Pour formellement clore PRICING_SSOT :
1. Approuver le gate `docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md` (cocher les 3 cases, signer, dater).
2. Lancer `TASK_V1_TEST_PRICING_STATE_001` pour la validation parité exhaustive.

---

## Validation globale

### Tests PHPUnit ciblés

```bash
./vendor/bin/phpunit --filter="OrderStateMachine|OrderStateMachineApply|Availability|EventContract|Outbox|KioskEvent"
```

```
OK (122 tests, 210 assertions)
```

Distribution :
- OrderStateMachine : 83 tests.
- AvailabilityService : 7 tests.
- EventContract + Outbox + KioskEvent (Vague 1 regression) : 32 tests.

### Build frontend (Laravel Mix)

```
✔ Compiled Successfully in 7071ms
/js/app.js    12.8 MiB
css/app.css   181 KiB
js/kiosk.js   1.08 MiB
```

### Lint

```
ReadLints sur 7 fichiers touchés → 0 erreur
```

---

## Invariants V1 renforcés par cette vague

| Invariant | État avant | État après |
|---|---|---|
| OrderStatus enum figé | respecté | respecté + API `apply()` documentée |
| 0 transition hors StateMachine | partiel (validation rule en place, mais audit post-mutation) | partiel — `apply()` offre chemin atomique pour new code, frozen zone inchangé (respect V1) |
| branch_id data isolation (rupture) | absent | renforcé — event branch-scoped avec channel unique |
| Dispatch after DB commit | respecté (Vague 1) | respecté (outbox V1 inchangé) |
| SSOT pricing | partiel (feature-flagged, gate non signé) | inchangé ici — renvoyé au gate + Vague 4 |
| Frozen zone | respecté | respecté |

---

## Risques résiduels

1. **Refactor frozen zones vers `apply()`** : l'API est en place mais les 5 call sites historiques (OrderService L1334, L1387, L1439 — FrontendOrderService L550, L736) continuent sur le pattern bi-étape. Migration prévue post-V1 quand la frozen zone sera levée. Risque faible : la `ValidStatusTransition` rule garantit déjà que toute mutation passe par `OrderStateMachine::allows()`.
2. **MENU_86 UI** : le backend est prêt ; l'UI admin et les handlers front n'existent pas. Un admin ne peut actuellement toggler une rupture que via appel direct à `AvailabilityService::toggle()` (tinker / script). À planifier en TASK_V1_TEST_PW_5FLOWS_001 ou un ticket UI dédié.
3. **PRICING_SSOT** : actif en prod sur la base de confiance du code + feature flag. Parité bit-à-bit non formellement prouvée → risque métier résiduel jusqu'à validation Vague 4.
4. **Scheduler daily reset** : `AvailabilityService::decrementForOrder()` reset le compteur ligne par ligne (`daily_reset_at != today`). Pas de cron global → une branche sans commandes ne verra pas son compteur remis à zéro tant qu'aucune commande ne touche l'item. Non bloquant V1, à corriger V1.5.

---

## Fichiers modifiés / créés

### Ajoutés
- `tests/Feature/Domain/OrderStateMachineApplyTest.php`
- `tests/Feature/Menu/AvailabilityServiceTest.php`

### Modifiés
- `app/Domain/Order/OrderStateMachine.php`
- `app/Events/ItemAvailabilityChanged.php`
- `app/Services/ItemService.php`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
- `app/Services/Menu/AvailabilityService.php`
- `tests/Unit/Domain/Order/OrderStateMachineTest.php`
- `docs/ORDER_FLOW.md`
- `docs/MENU_AVAILABILITY.md`

---

## Suite

La Vague 2 débloque :
- Vague 3 (Sécurité base) : `TASK_V1_SEC_XSS_001`, `TASK_V1_SEC_CORS_RATELIMIT_001` — indépendantes.
- Vague 4 : `TASK_V1_TEST_PW_5FLOWS_001` (dépend MENU_86 + EVENT_CONTRACT + PRICING_SSOT + STATUS_MACHINE → tous couverts au niveau code), `TASK_V1_TEST_PRICING_STATE_001` (dépend PRICING_SSOT + STATUS_MACHINE).

Chemins recommandés :
1. `run-cycle TASK_V1_SEC_XSS_001` (parallélisable, rapide, 1 j-h).
2. `run-cycle TASK_V1_SEC_CORS_RATELIMIT_001` (parallèle avec le précédent, 2 j-h).
3. Approbation humaine du gate `PRICING_SSOT` puis `run-cycle TASK_V1_TEST_PRICING_STATE_001`.

Aucun commit n'a été créé. Dis **"commit Vague 2 closure"** si tu veux que je crée le commit avec un message unifié couvrant STATUS_MACHINE + MENU_86.
