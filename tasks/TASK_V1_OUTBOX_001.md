# TASK_V1_OUTBOX_001 — Pattern outbox minimal garantissant livraison événements

## Meta
- **Priority** : P0 (cœur de la vague 1)
- **Vague** : 1 — Synchro foundation
- **PRIMARY_MODEL** : GPT-5.4 (architecture + transactions + jobs)
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : TASK_V1_SYNC_BACKBONE_001
- **BLOCKS** : TASK_V1_EVENT_CONTRACT_001, TASK_V1_MENU_86_001
- **Estimation** : 4 j-h

## Contexte

Aujourd'hui FoodKing broadcast ses events via `ShouldBroadcastNow`, qui émet **à la volée** vers Pusher dans la même requête HTTP :
- Si Pusher est indisponible 10s → **l'event est perdu définitivement**, aucun retry.
- Si la transaction DB rollback → le broadcast a **déjà eu lieu** → clients reçoivent un event pour un order qui n'existe pas.
- Aucune trace persistante des events émis → debug impossible.

Conséquences concrètes : le KDS peut rater une commande sans que personne ne le remarque. La Kiosk offline queue ne garantit rien côté serveur.

La solution V1 : un pattern **outbox** minimal. L'event est persisté dans une table `domain_events` **dans la même transaction** que l'écriture métier. Un job asynchrone lit la table et broadcast avec retry/backoff.

## Acceptance Criteria
- [ ] Table `domain_events` créée avec migration reversible.
- [ ] Trait `HasDomainEvents` disponible sur les aggregates racines (Order, Product, Menu).
- [ ] Job `DispatchDomainEventsJob` sur queue `high` — backoff `[1, 5, 30, 300]` secondes.
- [ ] Scheduler Artisan : dispatch toutes les minutes des events `dispatched_at IS NULL AND created_at < now() - 2min`.
- [ ] Commande `php artisan foodking:outbox:retry-failed` pour relancer manuellement les events avec `attempts >= max_attempts`.
- [ ] **0 occurrence** de `ShouldBroadcastNow` après migration (grep CI assertion).
- [ ] Test d'intégration : crash Pusher simulé 60s → après reconnexion, **tous** les events sont livrés dans l'ordre.
- [ ] Test transactionnel : DB rollback → **aucun** event persisté dans `domain_events`.
- [ ] Documentation `docs/OUTBOX_PATTERN.md` avec exemples.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `database/migrations/*_create_domain_events_table.php` | nouvelle migration | Write | No (indexé par branch_id) | Yes |
| `app/Domain/Events/DomainEvent.php` | base class | Write | No | Yes |
| `app/Domain/Events/HasDomainEvents.php` (trait) | trait aggregate | Write | No | Yes |
| `app/Models/DomainEvent.php` (Eloquent) | lecture/écriture events | Write | No | Yes |
| `app/Jobs/DispatchDomainEventsJob.php` | dispatcher async | Write | No | Yes |
| `app/Console/Commands/OutboxRetryFailedCommand.php` | relance manuelle | Write | No | Yes |
| `app/Console/Kernel.php` | scheduler | Write | No | Yes |
| `app/Events/OrderStatusChanged.php` + autres events | migrer de `ShouldBroadcastNow` vers `DomainEvent` | Write | No | Yes |
| `docs/OUTBOX_PATTERN.md` | doc | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- `app/Services/OrderService.php` — **frozen zone**. Le trait `HasDomainEvents` s'applique sur le **Model** `Order`, pas sur le service.
- `app/Services/FrontendOrderService.php` — **frozen zone**.
- Logique pricing — hors scope.
- State machine — hors scope (V1_STATUS_MACHINE_001 à part).

## Invariants at Risk
- [ ] None
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [x] branch_id data isolation — la table `domain_events` a une colonne `branch_id`, il faut vérifier que les consumers respectent le scope.
- [x] Dispatch after DB commit — **c'est précisément ce que cette task renforce**.
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — Migration
```sql
CREATE TABLE domain_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(128) NOT NULL,
  aggregate_type VARCHAR(128) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  payload JSON NOT NULL,
  correlation_id CHAR(36) NULL,
  occurred_at DATETIME(3) NOT NULL,
  dispatched_at DATETIME(3) NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_pending (dispatched_at, occurred_at),
  INDEX idx_aggregate (aggregate_type, aggregate_id),
  INDEX idx_branch (branch_id, occurred_at)
);
```

### E2 — Trait & base class
1. `DomainEvent` abstract class : `type`, `aggregateType`, `aggregateId`, `branchId`, `payload(): array`.
2. Trait `HasDomainEvents` :
   - `protected array $pendingEvents = [];`
   - Méthode `recordEvent(DomainEvent $event)`.
   - Hook Eloquent `afterCommit` : persiste les events dans `domain_events` dans la **même transaction**, vide la liste.
3. Test unitaire : event recordé + rollback → table vide. Event recordé + commit → table contient 1 ligne.

### E3 — Dispatcher Job
1. `DispatchDomainEventsJob` :
   - Constructor : `DomainEvent $event` (ou `$eventId` pour legèreté).
   - `handle()` : broadcast vers Pusher (via le même channel que l'event original), puis `update(['dispatched_at' => now()])`.
   - Retry : `public $backoff = [1, 5, 30, 300];`
   - Sur échec définitif (max retries) : log + alerte, `last_error` rempli.
2. Dispatch déclenché dans le hook `afterCommit` du trait : `Queue::push(new DispatchDomainEventsJob($event))` sur connexion `redis`, queue `high`.

### E4 — Scheduler rescue
1. Dans `Kernel::schedule()` :
   ```php
   $schedule->command('foodking:outbox:rescue')->everyMinute();
   ```
2. `OutboxRescueCommand` : SELECT events pending créés > 2 min, re-queue via `DispatchDomainEventsJob`.

### E5 — Migration des events existants
1. Identifier tous les events `ShouldBroadcastNow` du projet : `grep -rn "ShouldBroadcastNow" app/Events`.
2. Pour chaque event :
   - Remplacer `implements ShouldBroadcastNow` par extends `DomainEvent`.
   - Déplacer la logique de `broadcastOn()` dans la payload persistée.
   - Le job dispatcher prend le relais pour émettre vers Pusher.
3. Ajouter assertion CI `grep -c ShouldBroadcastNow app/ | [ "$OUTPUT" = "0" ]`.

### E6 — Commande retry
`php artisan foodking:outbox:retry-failed [--since=1h]` → reset `attempts=0, last_error=null` et re-queue.

### E7 — Documentation
`docs/OUTBOX_PATTERN.md` :
- Diagramme séquence (Order create → recordEvent → commit → hook → persist → dispatch job → Pusher).
- Exemple de création d'un nouvel event.
- Section "debugging" : comment inspecter `domain_events`, comment relancer.

### E8 — Validation
1. Unit tests : transaction rollback, transaction commit, retry, scheduler rescue.
2. Test intégration : stub Pusher qui throw → attempts incrémente → max → `last_error` écrit.
3. `php artisan test --filter=Outbox` vert.

## SYMMETRY_NOTE
N/A — trait appliqué sur Model, pas sur Service. OrderService / FrontendOrderService intouchés dans cette task.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : proposition de modifier OrderService pour "intégrer mieux" l'outbox → c'est précisément le découplage recherché, refuser.
- Stop-gate si : propose de supprimer le fallback polling existant dans les surfaces — le polling reste en sécurité V1.

## Status
- [x] Pending plan
- [x] Plan approved
- [x] In execution
- [x] Validation
- [x] Audit
- [ ] Gate open
- [x] Closed — 2026-04-15
