# TASK_V1_EVENT_CONTRACT_001 — Enveloppe unifiée + adapter 4 surfaces

## Meta
- **Priority** : P0 (clôture vague 1)
- **Vague** : 1 — Synchro foundation
- **PRIMARY_MODEL** : GPT-5.4 (architecture multi-surface, typage)
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : TASK_V1_OUTBOX_001
- **BLOCKS** : TASK_V1_MENU_86_001, TASK_V1_TEST_PW_5FLOWS_001
- **Estimation** : 2 j-h

## Contexte

Chaque surface (POS, Kiosk, KDS, OSS) désérialise aujourd'hui les events WebSocket à sa façon. Il n'y a **aucun schéma partagé**. Ajouter un champ à `OrderStatusChanged` peut casser silencieusement une surface sans que les tests le détectent. Ajouter une 5e surface (site web, app mobile) exigerait de reproduire la logique de parsing n fois.

La V1 définit un **contrat d'événement canonique** unique, versionné, validé à l'émission ET à la réception.

## Acceptance Criteria
- [ ] Schéma JSON canonique défini et documenté : `{ version, type, aggregate_id, branch_id, occurred_at, correlation_id, payload }`.
- [ ] Tous les events backend sortent via une `EventResource` qui respecte le schéma — test unitaire par event.
- [ ] Service frontend unique `resources/js/services/eventContract.js` : parsing + validation schema + typage.
- [ ] Les 4 surfaces (POS, Kiosk, KDS, OSS) importent **uniquement** ce service pour recevoir les events — pas de parsing ad-hoc.
- [ ] `PayloadMismatchException` jetée côté backend (émission) et logué côté frontend (réception) si le schéma est violé.
- [ ] Types d'events V1 définis : `order.created`, `order.status_changed`, `order.item_added`, `order.cancelled`, `menu.item_availability_changed`, `stock.low`.
- [ ] `docs/EVENT_CONTRACT.md` avec exemples JSON par type.

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Domain/Events/DomainEvent.php` | extends avec `toContract(): array` | Write | No | Yes |
| `app/Http/Resources/EventResource.php` | nouveau serializer unifié | Write | No | Yes |
| `app/Events/Order*.php`, `app/Events/Menu*.php` | alignement sur le schéma | Write | No | Yes |
| `resources/js/services/eventContract.js` | service central de parsing | Write | No | No |
| `resources/js/services/eventContract.schema.json` | JSON schema embarqué | Write | No | No |
| `resources/js/components/admin/pos/` | refactor pour consommer eventContract | Write | No | No |
| `resources/js/components/admin/kitchenDisplaySystem/` | refactor pour consommer eventContract | Write | No | No |
| `resources/js/components/admin/orderStatusScreen/` | refactor pour consommer eventContract | Write | No | No |
| `resources/js/kiosk/` | refactor pour consommer eventContract | Write | No | No |
| `docs/EVENT_CONTRACT.md` | doc | Write | No | No |
| `tests/Unit/Events/EventContractTest.php` | tests | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- `app/Services/OrderService.php` — frozen zone.
- `app/Services/FrontendOrderService.php` — frozen zone.
- Logique pricing.
- Gestion rupture — réservée à MENU_86_001.
- Table `domain_events` — livrée par OUTBOX_001.

## Invariants at Risk
- [ ] None
- [ ] Backend pricing SSOT
- [x] OrderStatus enum — le payload doit contenir la **valeur enum**, pas un label.
- [x] branch_id data isolation — `branch_id` dans l'enveloppe pour que le consumer filtre (déjà fait côté channel `private-branch.{id}`, mais explicite dans la payload pour audit).
- [ ] Dispatch after DB commit
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — Schema canonique
1. Créer `resources/js/services/eventContract.schema.json` :
   ```json
   {
     "$schema": "http://json-schema.org/draft-07/schema#",
     "type": "object",
     "required": ["version", "type", "aggregate_id", "branch_id", "occurred_at", "correlation_id", "payload"],
     "properties": {
       "version": { "type": "integer", "const": 1 },
       "type": { "type": "string", "pattern": "^[a-z_]+\\.[a-z_]+$" },
       "aggregate_id": { "type": ["integer", "string"] },
       "branch_id": { "type": ["integer", "null"] },
       "occurred_at": { "type": "string", "format": "date-time" },
       "correlation_id": { "type": "string", "format": "uuid" },
       "payload": { "type": "object" }
     }
   }
   ```

### E2 — EventResource backend
1. Créer `App\Http\Resources\EventResource` :
   ```php
   public function toArray($request): array {
       return [
           'version' => 1,
           'type' => $this->resource->type(),
           'aggregate_id' => $this->resource->aggregateId(),
           'branch_id' => $this->resource->branchId(),
           'occurred_at' => $this->resource->occurredAt()->toIso8601String(),
           'correlation_id' => $this->resource->correlationId(),
           'payload' => $this->resource->payload(),
       ];
   }
   ```
2. `DomainEvent` (livré par OUTBOX_001) expose les méthodes `type()`, `payload()`, etc.
3. `DispatchDomainEventsJob` broadcast la sortie de `EventResource`.

### E3 — Types d'events V1
Enumérer explicitement dans `app/Domain/Events/EventType.php` :
- `order.created`
- `order.status_changed`
- `order.item_added`
- `order.cancelled`
- `menu.item_availability_changed`
- `stock.low`

Toute nouvelle valeur nécessite un PR explicite.

### E4 — Service frontend
1. `resources/js/services/eventContract.js` :
   ```js
   import Ajv from 'ajv';
   import addFormats from 'ajv-formats';
   import schema from './eventContract.schema.json';
   const ajv = new Ajv(); addFormats(ajv);
   const validate = ajv.compile(schema);

   export function parseEvent(raw) {
       if (!validate(raw)) {
           console.error('[eventContract] payload mismatch', validate.errors, raw);
           throw new PayloadMismatchError(validate.errors);
       }
       return raw;
   }

   export function onEvent(type, handler) { /* bind Echo channel + parseEvent */ }
   ```
2. Export de typages TypeScript/JSDoc pour chaque event (`OrderCreatedEvent`, `MenuItemAvailabilityChangedEvent`, ...).

### E5 — Refactor 4 surfaces
1. **POS** (`resources/js/components/admin/pos/`) — remplacer les `Echo.private(...).listen(...)` par `onEvent('order.status_changed', handler)`.
2. **KDS** (`kitchenDisplaySystem/`) — idem.
3. **OSS** (`orderStatusScreen/`) — idem.
4. **Kiosk** (`resources/js/kiosk/`) — idem.

Chaque surface n'a plus de connaissance directe de Pusher/Echo pour les events métier (elle peut garder la connaissance de la connexion WS via WebSocketService).

### E6 — Tests
1. `tests/Unit/Events/EventContractTest.php` : pour chaque event métier, `EventResource($event)->toArray()` respecte le schema JSON.
2. Test frontend Jest : `parseEvent()` throw sur payload invalide.

### E7 — Documentation
`docs/EVENT_CONTRACT.md` avec exemple JSON complet pour chaque type + règles de versioning.

## SYMMETRY_NOTE
N/A — OrderService / FrontendOrderService non touchés. Les events existants dérivent de Model/Domain via OUTBOX_001.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : demande d'ajouter un type d'event hors de la liste V1 (ex: `loyalty.points_earned`) — refuser, c'est V2.
- Stop-gate si : demande de versionner autrement qu'avec `version: 1` flat — on garde simple.

## Status
- [x] Pending plan
- [x] Plan approved
- [x] In execution
- [x] Validation
- [x] Audit
- [ ] Gate open
- [x] Closed — 2026-04-15
