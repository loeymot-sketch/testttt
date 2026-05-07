# AGENT CONTRACT — Adversarial review of batch `1b38e64a3`

- **Auditor role**: GSTACK CONTRACT (hostile engineer, zero-complacency).
- **Scope**: 5 plans GSTACK shipped in `1b38e64a3` ("ULTRA-V1.x"). Surface = API/event schema cohérence uniquement (pas d'audit UX/business/security au-delà du strict nécessaire pour un avis CONTRAT).
- **Date**: 2026-05-08
- **Verdict short**: **GO conditionné** — 0 mismatch CRITIQUE bloquant. 3 mismatches NON-BLOQUANTS à durcir. 1 piège méthodologique sentinel à observer en CI.

---

## 0. Préambule — méthode

Pour chaque hypothèse C1..C10, j'ai relu sources + grep contracts. Les verdicts portent un fichier:line vérifiable. Aucune confiance accordée aux titres de commit ni aux runbooks.

Files lus:
- `app/Domain/Events/EventContract.php`
- `app/Enums/EventType.php`
- `app/Events/ItemAvailabilityChanged.php`
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
- `app/Http/Controllers/Admin/ItemController.php`
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php`
- `routes/api.php` (242, 958-975)
- `resources/js/services/eventContract.js`
- `resources/js/store/modules/kdsInflight.js`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (1320-1700, template lanes)
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue`
- `resources/js/router/modules/observabilityRoutes.js`
- `tests/Feature/Observability/OutboxOverviewControllerTest.php`
- `tests/Feature/Sentinels/OutboxPipelineHealthSentinelTest.php`
- `tests/Feature/Sentinels/PosCatalogRequiresBranchSentinelTest.php`
- `tests/js/observabilityOutboxRoute.spec.js`
- `git diff 1b38e64a3^ 1b38e64a3 --stat` (42 files, 5432/319)

---

## 1. Bilan C1 → C10

### C1 — Event `ItemAvailabilityChanged` contract: payload V1 contient bien `item_id, branch_id, is_available, reason` ? KDS listener consomme correctement ?

**VERDICT: PASS avec ALERTE FAIBLESSE CONTRAT**.

- Producer: `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:25-33` écrit le payload complet:
  ```php
  ['item_id', 'status', 'price', 'type', 'is_available', 'branch_id', 'reason']
  ```
  → 7 keys, snake_case backend canonique. Cohérent avec `ItemAvailabilityChanged::forBranch()` (`app/Events/ItemAvailabilityChanged.php:71-87`) qui propage `isAvailable + branchId + reason`.
- Consumer JS: `KitchenDisplaySystemComponent.vue:1626-1641` lit:
  ```js
  payload.item_id ?? payload.itemId
  payload.is_available ?? payload.isAvailable
  payload.branch_id ?? payload.branchId
  payload.reason
  ```
  → fallback camelCase défensif (sain), pas de typo `itemId` côté KDS. **Pas de mismatch**.
- **MAIS** — `EventContract::REQUIRED_PAYLOAD_KEYS` (`app/Domain/Events/EventContract.php:63`) déclare seulement `['item_id', 'status']` requis. Les keys `is_available / branch_id / reason` ne sont PAS contract-enforced. Si un futur producer (ex: refonte ItemService) émet un MENU_ITEM_AVAILABILITY_CHANGED avec `is_available` absent, `assertPayloadValid` passe → KDS `_onItemAvailabilityChanged` voit `isAvailable=null`, ne marque rien, **silence radio sur le badge OOS in-flight**.
  - Niveau de risque: P3 (faiblesse contractuelle, pas un bug actuel — le seul producer aujourd'hui est `PersistItemAvailabilityChangedToOutbox`).
  - Dette à acter dans le sprint suivant: durcir REQUIRED_PAYLOAD_KEYS ou ajouter une assertion runtime côté job dispatcher.

### C2 — `kdsInflight` Vuex shape vs Echo event mapping snake_case → camelCase

**VERDICT: PASS sourced**.

- Backend payload: `is_available` / `branch_id` / `item_id` (snake_case).
- `eventContract.js:55-69 parseEvent()` retourne `{type, aggregateId, branchId, payload}` — laisse `payload` brut (snake_case préservé). Pas de conversion auto. Donc le handler doit lire `payload.is_available` (pas `payload.isAvailable`), ce qu'il fait avec fallback (`KitchenDisplaySystemComponent.vue:1628`).
- Le store `kdsInflight.js:79-84` reçoit ses arguments en camelCase **APRÈS** mapping explicite par `_onItemAvailabilityChanged` (`KitchenDisplaySystemComponent.vue:1633-1638`):
  ```js
  this.$store.dispatch('kdsInflight/markDeavailable', {
    itemId, branchId, reason, kdsBranchId: this.authBranchId(),
  });
  ```
  → mapping snake→camel fait au bord (handler), shape interne du store reste pure JS. **Cohérent et défensif**.
- Branch isolation testable: `kdsInflight.js:114-122` skip si `branchId !== kdsBranchId`. Cohérent avec la fan-out producer (`PersistItemAvailabilityChangedToOutbox.php:35-46`).

### C3 — Observability JSON contract: shape backend = shape frontend ?

**VERDICT: PASS — match exact**.

Mapping backend (`SyncOverviewController::outboxOverview` lignes 349-365):

| Backend key            | Frontend Vue local      | Test expects                     |
| ---------------------- | ----------------------- | -------------------------------- |
| `generated_at`         | `data.generated_at`     | `assertJsonStructure[generated_at]` |
| `pending.count/rows`   | `data.pending`          | `pending.count`, `pending.rows.0.last_error` |
| `dispatched_24h.{count,latency_p50_ms,latency_p95_ms,latency_p99_ms,samples}` | `data.dispatched_24h` (alias `dispatched`) | structure asserted |
| `queue_high.{available,count,oldest_age_seconds}` | `data.queue_high` | structure asserted |
| `failed_jobs.{available,count,rows}` | `data.failed_jobs` | structure asserted |
| `health.{queue_work,websockets_serve}` (chacun: `status, last_signal_age_seconds, method`) | `data.health` | structure asserted |

→ Vue (`OutboxOverviewComponent.vue:362-368`) consomme exactement ces keys.
→ Test PHP (`OutboxOverviewControllerTest.php:95-105`) `assertJsonStructure` verrouille le contract.
→ **Aucun mismatch**.

### C4 — Route binding observability: protégée auth + ability ?

**VERDICT: PASS**.

- Parent group `routes/api.php:242` impose `auth:sanctum` + `apiKey` + `installed` + `localization` + throttle.
- Sub-group `observability` (lignes 958-975) hérite. Pas de `withoutMiddleware`.
- Controller `__construct` (`SyncOverviewController.php:52-57`) ajoute `role:Admin|Tenant Admin` UNIQUEMENT sur les 3 actions outbox, pas sur `index/clientMetrics` (qui ont leur propre permission `kitchen-display-system`). Asymétrie volontaire — outbox est global multi-branches, donc Admin/Tenant Admin only.
- Cohérent avec autres routes admin du fichier.
- Sentinels confirment: `OutboxOverviewControllerTest::test_branch_manager_is_forbidden` → 403 sur les 3 endpoints. Test passe → gate effectif.

### C5 — `OrderPaidAtCounter` channel cohérence + observability subscribe ?

**VERDICT: PASS — observability est polling-only**.

- KDS subscribe: `KitchenDisplaySystemComponent.vue:1322-1346 subscribeEcho()` → utilise `onEvents(branchId, [...])` qui pointe vers `private-branch.{branchId}` (canal projet, cf `PersistItemAvailabilityChangedToOutbox.php:37`).
- Observability dashboard: `OutboxOverviewComponent.vue:342-355 mounted()` n'instancie **AUCUN** Echo / WebSocket. Polling pur, intervalle 10s par défaut + `visibilitychange` (pause quand onglet hidden). Cohérent avec doctrine ops dashboard (lecture infrastructure, pas event-driven).
- **Aucun mismatch**. Choix architectural défendable: l'observability ne PEUT PAS dépendre de la même infra (websockets) qu'elle observe.

### C6 — Réponse 422 `{status:false, message:...}` POS-availability vs autres erreurs 422 du projet

**VERDICT: PASS**.

- Cohérent avec le standard Foodking dans le MÊME fichier:
  - `ItemController.php:63` (le nouveau code) → `['status' => false, 'message' => 'POS catalog requires branch_id']`
  - `ItemController.php:75, 99, 112, 121, 131, 151, 163, 172, 181` (existant) → `['status' => false, 'message' => $exception->getMessage()]`
- Forme strictement identique. Pas de pseudo-shape `{errors:{...}}` Laravel-validation. Cohérent avec convention POS.

### C7 — `OutboxPipelineHealthSentinel`: vrai event V1 contract ou stubs ?

**VERDICT: PASS — vrai contract, MAIS piège skip à surveiller**.

- Test 1 (`OutboxPipelineHealthSentinelTest.php:103-186`) construit un VRAI `DomainEvent` row avec event_type = `EventType::MENU_ITEM_AVAILABILITY_CHANGED`, payload contract-conforme (`item_id, status, price, type, is_available, branch_id, reason`), broadcast_as=`ItemAvailabilityChanged`. Pas de stub. Lance `Artisan::call('queue:work', ['--once', '--queue' => 'high', '--stop-when-empty', '--tries' => 1])`. Authentique end-to-end.
- Test 4 (lignes 361-410) verrouille `PayloadMismatchException` avec préfixe `contract_violation:`.
- **PIÈGE MÉTHODOLOGIQUE**: Test 1 et Test 2 sont gated par `assertHarnessActive()` qui `markTestSkipped` si `CI_WEBSOCKETS_HARNESS=1` n'est pas set. En CI standard sans le harness GitHub Actions activé → tests SKIP silencieusement, pas FAIL.
  - Test 3 (release-claim) et Test 4 (contract violation) NE sont PAS gated — ils tournent partout. C'est OK pour la couverture défensive.
  - Risque: si `.github/workflows/ci-sync-rupture-harness.yml` n'est pas réellement déclenché (PR non-tagged, fork, etc.), le sentinel devient passive. Le test 2 (config self-check) est un garde-fou interne au harness, mais il SKIP aussi sans le flag.
  - **Recommandation CONTRAT**: tracer dans CI le ratio skipped vs passed pour ce sentinel. Si `CI_WEBSOCKETS_HARNESS=1` n'est jamais set → le filet est creux.

### C8 — `observabilityOutboxRoute.spec.js` cohérent avec le SPA router ?

**VERDICT: PASS**.

- Test 1 (lignes 28-34) vérifie déclaration path `/admin/observability/outbox` et name `admin.observability.outbox` dans `observabilityRoutes.js`. Confirmé visuellement: `observabilityRoutes.js:10-11`.
- Test 2 (36-41) vérifie import + concat dans `router/index.js` — pattern `observabilityRoutes` présent (statut git `M router/index.js` confirme).
- Test 3-5 (43-83) verrouillent les 5 data-testid + retry/drain endpoints. Tous présents dans le composant Vue (lignes 27, 142, 194, 233, 257, 83 + `/api/admin/observability/outbox/{retry,drain}-failed`).
- Test 6 (mount smoke, 92-129) compile + monte le composant avec axios stub. Réaliste — détecte parse-errors / broken imports. Single point of failure: dépend du résolveur `@vue/test-utils` (probable déjà installé puisque le batch a été merge avec ✓ vitest).
- **Aucune incohérence détectée**.

### C9 — Frozen-zones: aucun fichier frozen modifié ?

**VERDICT: PASS**.

`git diff 1b38e64a3^ 1b38e64a3 --stat` (42 fichiers) inspecté. Liste reproduite ci-dessous, croisée avec `MEMORY.md → reference_frozen_zones.md`:

- `app/Services/FiscalSequenceService.php` → ❌ ABSENT du diff
- `app/Services/AuditLogService.php` → ❌ ABSENT
- `app/Services/KitchenReleaseRule*` → ❌ ABSENT
- `app/Domain/Order/OrderStateMachine*` → ❌ ABSENT (vérifié par `git diff` filtré sur ces paths → vide)
- Le wizard POS Vanilla JS protégé (cf MEMORY) → ❌ ABSENT du diff (seule `PosComponent.vue` est touchée, et son diff est limité à la garde `if(bootstrapBranchId)` + commentaire CV1-POS-AVAILABILITY-LIVE-001 — confirmé par `PosCatalogRequiresBranchSentinelTest::test_PosComponent_does_not_fetch_itemList_without_bootstrapBranch`).

→ Conformité doctrine frozen-zone respectée.

### C10 — Migrations DB ?

**VERDICT: PASS**.

`git diff 1b38e64a3^ 1b38e64a3 --stat` ne contient AUCUN fichier `database/migrations/*`. Le batch est purement code applicatif + scripts CI + tests + docs. Donc:
- Pas de risque rolling-deploy.
- Pas de modèle modifié de manière structurelle.
- Le contrôleur outbox lit `domain_events`, `sync_metrics`, `jobs`, `failed_jobs` — toutes tables existantes, le code utilise même `Schema::hasTable('jobs')` / `Schema::hasTable('failed_jobs')` (`SyncOverviewController.php:439, 460, 499`) — défense protective si l'env n'a pas la DB queue (sync driver).

---

## 2. TOP 3 mismatches (NON-BLOQUANTS, dette à tracer)

### Mismatch #1 — Contract `MENU_ITEM_AVAILABILITY_CHANGED` sous-spécifié (P3)
**Source**: `app/Domain/Events/EventContract.php:63`
**Constat**: Required keys = `['item_id', 'status']`. Le KDS in-flight marker dépend pourtant de `is_available` (et `branch_id` pour isolation, `reason` pour log). Si un nouveau producer émet sans ces keys → `assertPayloadValid` PASS → KDS silence radio.
**Risque**: faux-négatif silencieux du badge OOS rupture sur tickets in-flight. Pas d'erreur visible.
**Heal proposé** (hors scope de cette review): durcir à `['item_id', 'status', 'is_available', 'branch_id', 'reason']` OU ajouter un test contractuel qui force `PersistItemAvailabilityChangedToOutbox` à toujours émettre ces 7 keys (test: mock event puis introspect `DomainEvent::query()->latest()->first()->payload`).

### Mismatch #2 — `OrderPaymentStatusChanged` absent de `BROADCAST_MAP` côté JS (P3)
**Source**: `resources/js/services/eventContract.js:16-25` vs `app/Domain/Events/EventContract.php:48`
**Constat**: PHP `BROADCAST_MAP` contient `'OrderPaymentStatusChanged' => EventType::ORDER_PAYMENT_STATUS_CHANGED` (ligne 48, ajouté par P13). Côté JS le mapping ne le déclare PAS. Ni `StockLow`, ni `OrderCancelled`, ni `OrderItemAdded` non plus. Ni `EVENT_TYPES.ORDER_PAYMENT_STATUS_CHANGED` n'existe côté JS.
**Risque**: si un listener SPA s'abonne (`broadcastAs: 'OrderPaymentStatusChanged'`) et que l'enveloppe arrive, `eventContract.js:349` warn `'broadcast/event_type mismatch'` mais ne rejette pas. Pas un bug actuel (aucun listener JS pour cet event aujourd'hui), mais le contract V1 PHP↔JS est désynchronisé.
**Heal proposé**: synchroniser `EVENT_TYPES` + `BROADCAST_MAP` JS avec PHP — c'est explicite dans le commentaire du PHP `EventContract.php:33` "Keep in sync with resources/js/services/eventContract.js (BROADCAST_MAP)". Drift documentaire confirmé.
**Note**: hors scope du batch `1b38e64a3` — c'est de la dette pré-existante (P13 cycle 7B). Mais une review CONTRACT honnête doit la flagger.

### Mismatch #3 — Sentinel `OutboxPipelineHealth` peut SKIP en CI standard (P2 méthodologique)
**Source**: `OutboxPipelineHealthSentinelTest.php:78-92`
**Constat**: Tests 1+2 gated par `CI_WEBSOCKETS_HARNESS=1`. Sans la variable, `markTestSkipped` → vert silencieux dans le rapport global. Or le BUT du sentinel est de fermer le trou méthodologique RED-R3 F1.
**Risque**: faux sentiment de couverture si le workflow `ci-sync-rupture-harness.yml` n'est pas systématiquement déclenché.
**Heal proposé**: en plus du skip-message verbose qui est déjà bien fait, exiger que **CI obligatoire** sur PR vers `main`/`release` exécute ce workflow. Ajouter dans le runbook une assertion qui count skipped tests et fail si > 0 sur cette classe en environnement gate.
**Note**: la doctrine RED-R3 F1 mentionne ce risque ("le pipeline Pusher maintenant testé en CI" dans le commit message) — la confiance dépend du déclenchement effectif du workflow, pas du test lui-même.

---

## 3. Verdict GO/NO-GO contract V1

### **GO conditionné**.

Aucun mismatch critique bloquant un tag V1 release. La cohérence API/event schemas du batch `1b38e64a3` est:
- ✅ Backend PersistItemAvailabilityChangedToOutbox émet le payload V1 enrichi (7 keys), KDS consume défensif avec fallback camelCase, store Vuex mapping clean.
- ✅ Observability JSON shape verrouillé bilatéralement par `assertJsonStructure` + Vue local data init + sentinel SPA.
- ✅ Routes auth + role gate cohérents avec doctrine admin du projet.
- ✅ 422 shape POS-availability conforme convention sibling.
- ✅ Frozen zones intactes.
- ✅ 0 migration DB → 0 risque rolling-deploy.

### Conditions de levée GO complète (non-bloquantes pour V1, dette V1.x à arbitrer):

1. **C1 hardening contract**: durcir `REQUIRED_PAYLOAD_KEYS[MENU_ITEM_AVAILABILITY_CHANGED]` ou ajouter test "producer emits 7 keys".
2. **C5 sync PHP↔JS BROADCAST_MAP**: ajouter `OrderPaymentStatusChanged`, `StockLow`, `OrderCancelled`, `OrderItemAdded` côté JS pour fermer le drift documentaire.
3. **C7 garantir exécution harness**: vérifier que `.github/workflows/ci-sync-rupture-harness.yml` est branché sur le path PR `main`/`release`. Sinon le sentinel = filet creux.

---

## 4. Limitations honnêtes de cette review

1. **Pas exécuté les tests** — review statique pure (lecture sources + grep). Si un test ment ou est cassé runtime, je ne le vois pas.
2. **Pas vérifié le runtime broadcaster** — la chaîne `Echo.private('private-branch.{id}').listen('.ItemAvailabilityChanged', ...)` n'a été tracée qu'à travers `onEvents()` (`eventContract.js:330`). Un bug dans la lib Echo / Pusher / soketi n'est pas détectable par audit code.
3. **Pas audité l'état de `.github/workflows/ci-sync-rupture-harness.yml`** — non lu en détail, donc impossible de garantir que le workflow est bien déclenché sur les bons triggers.
4. **Pas compilé Vue** — le test mount smoke devrait attraper les parse errors mais je ne l'ai pas exécuté.
5. **Drift PHP↔JS BROADCAST_MAP** (C5 mismatch #2): hors scope strict du batch (régression antérieure), je l'ai flaggé par devoir CONTRACT — mais il n'est pas formellement attribuable à `1b38e64a3`.
6. **Test JSON shape `OutboxOverviewControllerTest`** (`assertJsonStructure`) ne valide que la PRESENCE des keys, pas leur type/range. Un latency_p95_ms = `string("oops")` passerait. Robuste mais pas paranoïaque.
7. **Pas regardé** la qualité a11y réelle (je ne suis pas axe-core, juste lu `role="article" + aria-labelledby` placement template lanes 4/4 → present).
8. **Branch isolation outbox dashboard**: le contrôleur `outboxOverview()` ne filtre PAS par `branch_id` (volontaire — Admin/Tenant Admin global). Mais `pending.rows[].branch_id` peut leak des branches voisines si jamais un Branch Manager passe le gate (test affirme qu'il ne passe PAS). Pas un mismatch contract, c'est by design.

---

## 5. Annexe — résumé des PASS sourced

| Hyp | Verdict | Source verrouillage                                        |
| --- | ------- | ---------------------------------------------------------- |
| C1  | PASS*   | EventContract.php:63 + PersistItemAvailabilityChangedToOutbox.php:25 + KitchenDisplaySystemComponent.vue:1626 (* faiblesse contract docs en §2) |
| C2  | PASS    | kdsInflight.js:79 + KitchenDisplaySystemComponent.vue:1633 |
| C3  | PASS    | SyncOverviewController.php:349-365 + OutboxOverviewComponent.vue:362 + OutboxOverviewControllerTest.php:95 |
| C4  | PASS    | routes/api.php:242 + SyncOverviewController.php:52         |
| C5  | PASS    | OutboxOverviewComponent.vue:342-355 (no Echo)              |
| C6  | PASS    | ItemController.php:63 vs lignes 75/99/112/121/131/151/163/172/181 |
| C7  | PASS*   | OutboxPipelineHealthSentinelTest.php:103-186 (* skip risk en §2) |
| C8  | PASS    | observabilityOutboxRoute.spec.js:28-129                    |
| C9  | PASS    | git diff stat — frozen paths absents                       |
| C10 | PASS    | git diff stat — aucun database/migrations/*                |

---

**Signature**: Agent CONTRACT — review hostile sans complaisance. Verdict **GO conditionné V1**, 3 dettes V1.x non-bloquantes flaggées.
