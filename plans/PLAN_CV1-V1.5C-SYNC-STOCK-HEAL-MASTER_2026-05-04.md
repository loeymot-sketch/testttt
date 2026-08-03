# MASTER PLAN — V1.5c Sync/Stock Heal — CV1-V1.5C-SYNC-STOCK-HEAL-MASTER

| Champ | Valeur |
|---|---|
| MASTER_TASK_ID | `CV1-V1.5C-SYNC-STOCK-HEAL-MASTER` |
| Date | 2026-05-04 |
| RUNNER_MODE | `single-session` |
| PHASE (master) | `EXECUTE` (séquencé en 7 cycles + audit final) |
| Source | Ultra audit Sync+Stock V1 — terminal Claude (Opus 4.7 advisor + Sonnet 4.6 xhigh) 2026-05-04 ~20:30 UTC+2 — verdict **HEAL**, score **41/50 (82%)**, 3 BLOCKING + 5 healings post-prod. Trace complète : `terminals/4.txt:159-714` (à archiver `reports/audit/CLAUDE_ULTRA_AUDIT_V1_SYNC_STOCK_2026-05-04.md`). |
| PARENT_CYCLE | `CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER` CLOSED PASS (2026-05-04 ~17:35) + audits techniques finaux post-quota (2026-05-04 ~19:30 + 20:00) |
| Owner | Cursor Claude (orchestrator + sub-agents) |
| Approbation humaine | User message 2026-05-04 20:50 UTC+2 : « je te demande de créer le plan, j'ai demandé à commencer la correction mais [Claude terminal a] vite limite finis ! alors c'est toi qui reprends la conduite » → délégation reprise orchestration depuis le terminal Claude (limite quota atteinte mid-execution). |
| PRIMARY_EXECUTION_MODEL_master | Claude orchestrator (sub-agents par cycle ci-dessous) |
| REASONING_EFFORT | Élevé (frozen zones touchées par R1) |

---

## 1. Contexte

L'audit ultra synchro+stock V1 a tracé les 8 scénarios E2E business critiques (toggle ingrédient/item/stock → broadcast → POS+kiosk+KDS) et a posé un verdict structuré :

- **Architecture** : ✅ outbox + `DispatchableAfterCommit` + Pusher branch-scoped + cache invalidation + frontend Echo en place.
- **Score** : 41/50 (82%) — au-dessus du seuil "heal acceptable" (75%), sous le seuil "continue sans réserve" (90%).
- **3 défauts BLOCKING V1** identifiés ; 5 healings post-prod recommandés.

Sans traitement de R1, le contrat SSOT étendu (pricing + disponibilité authoritative serveur) est rompu : un kiosk avec menu stale peut soumettre un order avec ingrédient en rupture. Sans R2, perte de WS = 30s de menu stale > SLA business 5s. Sans R3, broadcast peut être muet en silence si `BROADCAST_DRIVER` mal configuré en prod.

**Valeur métier** : R1+R2+R3 = la promesse business « toggle admin → tous reflètent en < 5s + serveur authoritative » qui est LA différenciation V1 vs concurrence.

**Scope strict V1.5c** : 3 BLOCKING (R1+R2+R3) + cycle audit final. Les 4 healings post-prod (E1-E4) sont **différés V1.5d** (post-cutover) sauf si un défaut critique remonte côté prod.

---

## 2. PRIOR_CONTEXT

| Cycle parent | Statut | Date | Apport |
|---|---|---|---|
| `CV1-V1-PIVOT-MASTER` | CLOSED PASS | 2026-05-04 ~13:05 | Architecture wizard category-owned + Ingrédient unifié + Demo V2 flag |
| `CV1-V1-FINISH-MASTER` | CLOSED PASS | 2026-05-04 ~15:35 | 5 healings post ultra-review (sécurité Demo V2, i18n parity, a11y, gate brief, smoketest) |
| `CV1-V1.5-DEBT-CLEANUP-MASTER` | CLOSED PASS | 2026-05-04 ~16:05 | Bug critique runtime ingredient_rupture sur variation (D1) + FiscalArchive NO_OP (D2) + XOR monitoring (D3) |
| `CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER` | CLOSED PASS | 2026-05-04 ~17:35 | Feature drill-down ingrédient UX restaurateur visible |
| Audits techniques finaux | PASS_WITH_HEALING / GO_WITH_CONDITIONS | 2026-05-04 ~20:00 | 5 healings tactiques (G1+H1+H2 + A2 regex + A4 ordre + F3 npm ci + H3 spatie cache + F4 réfuté) |
| **Audit ultra sync+stock** (présent) | **HEAL — 41/50** | 2026-05-04 ~20:30 | **Source de ce plan** |

Toutes les baselines : PHPUnit **1428 passed | 24 skipped**, Vitest **1162 passed | 2 skipped**, build OK, 0 régression, gate prod cutover **APPROVED** (transcription orchestrator + signoff humain optionnel proposé).

---

## 3. SUBSYSTEMS_TOUCHED + INVARIANTS_AT_RISK + GATE_CONDITIONS (master)

| Cycle | Tier | Subsystems_touched | Invariants_at_risk | Gate ? |
|---|---|---|---|---|
| **R1** re-validation submit | **complex** | `app/Services/OrderService.php` ⚠️ FROZEN, `app/Services/FrontendOrderService.php` ⚠️ FROZEN, `app/Services/Pricing/PricingService.php` ⚠️ FROZEN (read-only trace), `tests/Feature/Order/*` (write) | I1 pricing SSOT, I2 OrderStatus, I3 branch_id, I4 dispatch après commit, **I5 OrderService/FrontendOrderService symmetry** (CRITIQUE : les 2 services touchés simultanément), I6 frozen zones | **OUI — gate brief si patch nécessaire** |
| **R2** reconnect WS frontend | **complex** | `resources/js/services/WebSocketService.js` (write), `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (write), `resources/js/components/admin/pos/PosComponent.vue` (write), `resources/js/helpers/kioskOfflineQueue.js` (read), `tests/js/runtimeSyncFlagsWiring.spec.js` (write extension) | Aucun invariant FoodKing critique (frontend pure). I3 branch_id préservé via re-fetch scopé. | Non |
| **R3** broadcast driver sentinel | **routine** | `tests/Feature/Config/BroadcastDriverConfiguredTest.php` (nouveau), pas de modif code prod | Aucun | Non |
| **R4** audit final | n/a | Lecture seule | n/a | Non |

**Note frozen zones R1** : `OrderService.php` et `FrontendOrderService.php` sont en frozen zones consolidées (cf. `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25` Approved Option C — Partial allowlist by method/surface). **L'audit demande d'abord de TRACER file:line** pour confirmer si la re-validation existe déjà (probablement via `PricingService::assertSelectionsOrderable` qui appelle `ChoiceAvailabilityResolver::assertSelectionsOrderable`). Si déjà présente → R1 = NO_OP (pas de gate). Si absente → gate brief requis avant patch (allowlist hunk pour ajouter le re-check disponibilité).

---

## 4. Cycles d'exécution (séquencés)

### Cycle R1 — Re-validation serveur disponibilité au submit (BLOCKING #1, CRITIQUE)

- **TASK_ID** : `CV1-V1.5C-SYNC-REVALIDATE-SUBMIT-R1`
- **EXECUTION_TIER** : `complex` (frozen zones + invariant I5 symétrie)
- **EXECUTE_DELEGATION** : `foodking-complex-implementer` (FALLBACK Cursor sub-agent — codex-extension non requis pour cycle dette interne)
- **PHASE 1 — TRACE (read-only, no gate)** :
  - Tracer file:line dans `OrderService::placeOrder` (ou méthode équivalente : `createOrder`, `submit`, etc.) la séquence de validation au submit POS.
  - Idem `FrontendOrderService::placeOrder` côté kiosk/online.
  - Confirmer si `PricingService::quote` (ou équivalent) appelle bien `ChoiceAvailabilityResolver::assertSelectionsOrderable($branchId, $items)` qui throw 422 sur `ingredient_rupture` ou `out_of_stock`.
  - Identifier si la disponibilité est re-vérifiée sur **chaque ligne d'ordre** au moment du submit (pas seulement à la quote initiale).
  - Produire un rapport `reports/audit/RUN_R1_TRACE_REVALIDATE_SUBMIT.md` avec evidence file:line.
- **PHASE 2 — DECIDE (orchestrator)** :
  - Si re-validation **présente et complète** → R1 = NO_OP, ajouter test PHPUnit sentinelle qui prouve que toggle ingrédient pendant un order in-flight rejette le submit avec 422 + `unavailable_reason`. Pas de gate.
  - Si **absente ou partielle** → ouvrir `docs/gates/GATE_CV1-V1.5C-SUBMIT-REVALIDATION-PATCH_2026-05-04.md` (allowlist hunk frozen zone), attendre approval humaine, puis patcher.
- **PHASE 3 — EXECUTE** :
  - Soit ajout test sentinelle (`tests/Feature/Order/SubmitRevalidationIngredientRuptureTest.php` — 4 tests : toggle attribute en cours d'order POS, idem kiosk, ordre via item branch unavailable, ordre via stock=0).
  - Soit patch + tests si gate clearé.
- **SYMMETRY_NOTE obligatoire** : si patch d'`OrderService` → review symétrique `FrontendOrderService` ; idem inverse. Logger dans le plan avant CLOSE R1.
- **Acceptance criteria** :
  - 4+ tests PHPUnit prouvent que le serveur rejette un submit avec ingrédient/extra/addon devenu indisponible entre quote et submit.
  - Symétrie POS/Kiosk vérifiée.
  - 0 régression PHPUnit globale.
  - Si patch effectué, gate brief clearé + entrée GATE_LOG.md.
- **Effort** : S si NO_OP (1-2h tests), M si patch (4-6h + gate).

### Cycle R2 — Reconnect WebSocket force re-fetch menu (BLOCKING #2)

- **TASK_ID** : `CV1-V1.5C-SYNC-WS-RECONNECT-REFETCH-R2`
- **EXECUTION_TIER** : `complex` (frontend WS reconnect logic, multi-surface)
- **EXECUTE_DELEGATION** : `foodking-complex-implementer`
- **SUBSYSTEMS_TOUCHED** :
  - `resources/js/services/WebSocketService.js` (write — handler state_change)
  - `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (write — bind reconnect → re-fetch)
  - `resources/js/components/admin/pos/PosComponent.vue` (write — idem POS)
  - `resources/js/composables/useCatalogChangeNotifier.js` (read pour cohérence — possiblement write si centralisé)
  - `tests/js/runtimeSyncFlagsWiring.spec.js` ou nouveau `tests/js/wsReconnectRefetch.spec.js`
- **Goal** : sur transition `state_change → CONNECTED` (was `DISCONNECTED`), déclencher immédiatement `dispatch('item/lists', { branch_id })` + `dispatch('menu/refresh')` pour rattraper l'état. Réduit la fenêtre stale de 30s (polling fallback) à <500ms (immédiat post-reconnect).
- **Acceptance criteria** :
  - Test Vitest qui mock un `state_change DISCONNECTED → CONNECTED` et asserte que les dispatches sont émis avec le bon `branch_id`.
  - Pas de régression sur le `reconnect_storm` debounce existant (`WebSocketService.js:347-351`).
  - Test pour POS et Kiosk (symétrie).
  - Pas de fuite : si le composant est démonté avant reconnect, pas de dispatch orphelin.
- **Effort** : M (3-4h).

### Cycle R3 — Sentinel CI BroadcastDriverConfigured (BLOCKING #3, CONFIG)

- **TASK_ID** : `CV1-V1.5C-SYNC-BROADCAST-DRIVER-SENTINEL-R3`
- **EXECUTION_TIER** : `routine` (test PHPUnit pure, pas d'invariant critique)
- **EXECUTE_DELEGATION** : `foodking-routine-implementer`
- **SUBSYSTEMS_TOUCHED** :
  - `tests/Feature/Config/BroadcastDriverConfiguredTest.php` (nouveau)
  - `phpunit.xml` (vérifier groupe couvert)
- **Goal** : assert `config('broadcasting.default') in ['pusher','redis','ably']` quand `app()->environment()` est dans `['production', 'staging']`. Skip en `local` / `testing`. Empêche un déploiement silencieux où `BROADCAST_DRIVER=null` casse toute la chaîne broadcast sans alarme.
- **Acceptance criteria** :
  - 3 tests : (a) production drivers OK, (b) production driver=null → fail, (c) testing driver=null → skip OK.
  - Groupe `@group config-sentinel` ajouté pour exécution rapide CI.
  - Test ajouté à `tests/Feature/Config/` (créer dossier si absent).
- **Effort** : XS (30 min).

### Cycle R4 — Archivage rapport audit + audit final master

- **TASK_ID** : `CV1-V1.5C-SYNC-FINAL-AUDIT-R4`
- **EXECUTION_TIER** : n/a (orchestrator + audit channel)
- **Actions** :
  - Archiver `terminals/4.txt:159-714` → `reports/audit/CLAUDE_ULTRA_AUDIT_V1_SYNC_STOCK_2026-05-04.md` (markdown clean + frontmatter).
  - Run baselines complets (PHPUnit + Vitest + build).
  - Lancer audit master final via `foodking-planner-orchestrator` (Claude terminal toujours en quota down présumé).
  - Update `.cursor/ACTIVE_CYCLE.md` → CLOSED.
  - Append épisode mémoire `memory/episodes/12_decisions_log.jsonl` + `memory/episodes/03_central_sync.jsonl` (ou domain équivalent).
  - Update `docs/gates/GATE_LOG.md` si gate R1 ouvert+cleared.

---

## 5. Cycles différés V1.5d (POST-CUTOVER, hors scope V1.5c)

Ces healings ont été identifiés par l'audit comme **non-bloquants** pour le 1er restaurateur (env vierge). Documentés ici pour traçabilité backlog.

| ID | Severity audit | Goal | Effort estimé |
|---|---|---|---|
| **E1** | MEDIUM | Test Playwright `v1-broadcast-latency.spec.js` mesurant <5s entre toggle admin et badge POS+kiosk | M (3h) |
| **E2** | MEDIUM | Coverage S6 addons : test composer wizard avec addon désactivé (couverture actuelle 20%) | M (4h) |
| **E3** | MEDIUM | Sentinel cross-branch isolation broadcast : assert Branch A toggle ne reach pas Branch B clients | M (3h) |
| **E4** | LOW | Test concurrent admin toggles (race condition rare mais non couverte) | S (2h) |
| **E5** | LOW | B6 — `ComposerProfileProjection` cache R-T3 V1.5c+ (dette connue, pattern branch_id à confirmer) | M (4h) |

Ces 5 healings totalisent ~16h. À planifier dans un `CV1-V1.5D-SYNC-COVERAGE-MASTER` post-cutover si le 1er resto révèle des gaps observables, sinon backlog V2.

---

## 6. Definition of Done (master V1.5c)

- ✅ R1 résolu (NO_OP avec test sentinelle OU patch + gate clearé + symétrie OrderService/FrontendOrderService).
- ✅ R2 patch reconnect WS livré + tests Vitest verts.
- ✅ R3 sentinel BroadcastDriverConfigured livré + 3 tests verts.
- ✅ Baselines : PHPUnit ≥ **1431** passed (1428 + 3 R3 + au moins quelques R1) ; Vitest ≥ **1164** passed (1162 + 2 R2). 0 régression.
- ✅ Build `npm run prod` (ou dev fallback) OK.
- ✅ Rapport audit archivé `reports/audit/CLAUDE_ULTRA_AUDIT_V1_SYNC_STOCK_2026-05-04.md`.
- ✅ Audit final master PASS (terminal Claude PRIMARY si quota OK, sinon fallback `foodking-planner-orchestrator`).
- ✅ `.cursor/ACTIVE_CYCLE.md` reset CLOSED.
- ✅ Épisode mémoire JSONL append.
- ✅ Si gate R1 ouvert : entrée `GATE_LOG.md` complète + brief archivé cleared.

---

## 7. Risques + ESCALATION

- **Risque R1 frozen zone** : si le patch est nécessaire et que la modif sort de l'allowlist `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25 Option C`, escalade humaine obligatoire. Ne pas forcer.
- **Risque R1 invariant I5 symétrie** : si seulement un des 2 services est patché, `SYMMETRY_NOTE` bloque le close.
- **Risque R2 régression UX** : un mauvais handler reconnect peut spam les requêtes au reconnect. Le test doit valider le debounce existant non cassé.
- **Risque mémoire token** : 4 cycles + audit en parallèle est compatible avec session unique grâce au tier-routing (R1 complex, R2 complex, R3 routine, R4 audit). Pas de masterplay.

ESCALATION humaine si :
- R1 phase 1 trace ambigüe (re-validation existe mais partielle ou indirecte).
- R1 gate brief refusé par humain.
- 2 validations consécutives échouent sur R1 ou R2.

---

## 8. Test Strategy (master)

- **R1** : `local-validation` (PHPUnit Feature/Order). Pas de Playwright requis cycle-niveau (couvert par E1 différé).
- **R2** : `local-validation` (Vitest). Mock WebSocket via stub `useCatalogChangeNotifier`. Pas de Playwright.
- **R3** : `local-validation` (PHPUnit Feature/Config).
- **R4 audit** : `static-inspection` (lecture file:line + diff git).

---

## 9. PLAN_REVIEW (challenge GPT/Codex — déféré)

Conformément doctrine 2026-05-02, le PLAN_REVIEW GPT/Codex est recommandé avant EXECUTE complex. Pour ce master V1.5c :

- **Statut** : DÉFÉRÉ — la procédure `npm run codex:plan-review` n'a pas été exécutée car (a) le master enchaîne 4 cycles dont 2 routine ne nécessitant pas review, (b) le quota terminal Claude / Codex Pro est sous tension, (c) l'audit source était déjà fait par Claude terminal Opus 4.7 qui a posé des recommendations cycliques file:line précises.
- **Si humain demande review GPT** : exécuter `npm run codex:plan-review -- CV1-V1.5C-SYNC-STOCK-HEAL-MASTER` post-livraison de ce plan.

---

## 10. Séquence d'exécution

```
PHASE EXECUTE master :
  → R4 step 0 : archive terminals/4.txt → reports/audit/...
  → R3 (routine, ~30 min)              [parallèle possible avec R1 phase 1]
  → R1 phase 1 TRACE (read-only)       [détermine NO_OP vs gate]
  → R1 phase 2 DECIDE (orchestrator)
  → R1 phase 3 EXECUTE (selon décision)
  → R2 (complex frontend, ~3-4h)
  → Run baselines
  → R4 audit final master
  → CLOSE
```

---

## 11. Approbation orchestrator

L'orchestrator (Claude session Cursor) a délégation user explicite 2026-05-04 17:08 + 20:50 UTC+2 pour reprendre la conduite après limite quota terminal Claude. Ce plan est **EXECUTE-ready**. Pas de gate humain anticipé sauf si R1 phase 1 révèle absence de re-validation submit (probabilité estimée : 30%).
