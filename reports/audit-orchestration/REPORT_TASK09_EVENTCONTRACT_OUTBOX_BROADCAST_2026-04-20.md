# T09 — EventContract / Outbox / Broadcast (audit)

**Date.** 2026-04-20  
**Racine.** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`  
**Verdict.** **FAIL** (refactor K-10 non présent + AX12-02 confirmé)

## Constats

### 1. `DispatchDomainEventsJob`
- Utilise toujours **`connection('pusher')`** + **`getPusher()->trigger()`**, **pas** `BroadcastManager::connection()->broadcast(...)`.
- Si `PUSHER_APP_KEY` vide et `BroadcastManager` non mocké : log "Pusher not configured — skipping broadcast" + skip réseau.
- Réf : `app/Jobs/DispatchDomainEventsJob.php` L70–92.

### 2. AX12-02 — `PersistOrderCreatedToOutbox`
- `correlation_id` défini par **`(string) Str::uuid()`**, sans lecture de `X-Correlation-ID` ni du contexte de corrélation partagé.
- Réf : `app/Listeners/PersistOrderCreatedToOutbox.php` L33–34.
- **Confirme** AX12-02 P1 du tracker kiosk 110.

### 3. Modèles Outbox
- `Outbox.php` / `OutboxEvent.php` : **absents**.
- SSOT outbox : modèle **`DomainEvent`** (`app/Models/DomainEvent.php`), migration `2026_04_15_200000_create_domain_events_table.php` (`correlation_id` nullable char(36)).

### 4. EventContract V1
- Présent : `app/Domain/Events/EventContract.php` — enveloppe V1, `buildEnvelope()`, validation stricte (`correlation_id` non vide).

### 5. `phpunit.xml`
- `PUSHER_APP_KEY` / `SECRET` / `ID` **vidés** (dummy) — conforme à l'intention "skip réseau".
- **`BROADCAST_DRIVER` non défini** dans `phpunit.xml` (pas de `BROADCAST_DRIVER=log`).

### 6. Tests
| Fichier | Remarque |
|---------|----------|
| `tests/Feature/OutboxTest.php` | Mock `BroadcastManager::connection('pusher')` + `getPusher()->trigger` — **pas** `broadcast()`. |
| `tests/Feature/EventContractTest.php` | Job mocké pareil ; `test_domain_event_has_correlation_id` exige UUID sur outbox, **sans** lien avec header HTTP. |
| `tests/Feature/CorrelationIdPropagationTest.php` | **N'existe pas** ; existe `CorrelationIdMiddlewareTest.php` (middleware / health uniquement). |

### 7. Audit cross
- `reports/review/AUDIT_KIOSK_110_OBSERVABILITY_PERF_2026-04-19.md` : non trouvé dans ce dépôt.

### 8. Inventaire UUID vs corrélation
Listeners outbox génèrent un UUID local :
- `PersistOrderCreatedToOutbox` — `Str::uuid()`
- `PersistOrderStatusChangedToOutbox` — `Str::uuid()` (L32)
- `PersistItemAvailabilityChangedToOutbox` — `Str::uuid()` (L49)

Le trait `app/Traits/HasCorrelationId.php` lit bien `Log::sharedContext()` puis `request()?->header('X-Correlation-ID')` puis `Str::uuid()` — mais s'applique au **job** `DispatchDomainEventsJob`, **pas** au champ `domain_events.correlation_id` (qui lit `$event->correlation_id` depuis la ligne persistée).

### 9. `DB::afterCommit`
Présent dans `ItemService`, `HasDomainEvents`, `PersistItemAvailabilityChangedToOutbox`, `PersistOrderCreatedToOutbox`, `ItemCategoryService`, `PersistOrderStatusChangedToOutbox`, `AvailabilityController`. Le trait `recordDomainEvent` n'a aucun appel ailleurs dans `app/` → chemin peu / pas utilisé en prod.

### 10. Pipeline order
`FrontendOrderService` : `OrderCreated::dispatch`, `OrderStatusChanged::dispatch`, `event(new OrderStatusChanged(...))` (L688–692). Listeners outbox responsables du `afterCommit`.

## Checklist V1–V7

| ID | Critère | Statut |
|----|---------|--------|
| V1 | `DispatchDomainEventsJob` utilise broadcast par défaut (pas Pusher hardcodé) | **NON** |
| V2 | Tests attendent `broadcast()`, pas `getPusher()->trigger` | **NON** |
| V3 | `phpunit.xml` : `BROADCAST_DRIVER=log` + PUSHER dummies | **PARTIEL** |
| V4 | AX12-02 : correlation HTTP → job → outbox | **NON** |
| V5 | Tous les `event()` métier via `afterCommit` | **PARTIEL** |
| V6 | Aucun `event()` hors afterCommit dans pipeline order | **PARTIEL** |
| V7 | Vitest échos / Pusher mockés | **NON VÉRIFIÉ** |

## Top 3 actions

1. **AX12-02** — Dans `PersistOrderCreatedToOutbox` (et autres listeners outbox), résoudre `correlation_id` comme `HasCorrelationId` : `Log::sharedContext()['correlation_id']` puis `request()->header('X-Correlation-ID')` puis repli contrôlé. Tests bout-en-bout requête → outbox.
2. **K-10 broadcast** — Remplacer `getPusher()->trigger` par l'API `broadcast()` ; adapter `OutboxTest` et `EventContractTest`.
3. **CI / phpunit** — Ajouter `BROADCAST_DRIVER=log` (ou équivalent) dans `phpunit.xml` ; cohérence PUSHER vide + nouveau chemin d'émission.

## Décision

**T09 FAIL** — refactor K-10 absent ; AX12-02 confirmé. Remédiation **T09b** à planifier (3 actions ci-dessus).
