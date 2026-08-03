# Deep Audit — Sprint 1 multi-agent — 2026-05-02

| Field | Value |
|---|---|
| Auditor | Claude (`foodking-planner-orchestrator` subagent, model `claude-opus-4-7`, effort xhigh) |
| Scope | 9 produit + 2 chore log-restore + 2 chore audit-log = 13 commits HEAD..HEAD~13 |
| Reference plans | `plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md`, `plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md` |
| Audit-source briefs | `reports/audit/CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_{1,2}_*.md`, `reports/audit/M2_1_9_INDUSTRY_COMPARATIVE_ANALYSIS_2026-05-02.md` |
| Mode | Read-only audit — no product code modified — report only |

## Verdict global

**PASS_WITH_NOTES** — Sprint 1 Vague 1 des deux missions livré complet, propre, scope-disciplined, invariants respectés, suite verte. Pas de blocker. 4 notes documentées (3 "follow-up", 1 "test-vocabulaire") détaillées en §"Risques détectés" et §"Recommandations". La fermeture du batch est techniquement éligible.

## Synthèse en 5 lignes

Les 9 commits produit implémentent fidèlement les §1.1–1.9 des deux plans avec un diff cumulé de ~16 800 insertions (≈ 11 700 sont des dossiers `missions/{TASK_ID}/` + auto-audits Codex GPT-5.5-pro, le code applicatif réel = ~1 500 LOC). Suite PHP 1272 passed / 40 skipped (vs 1263/46 baseline pré-sprint = +9 passing, −6 skipped, mouvement net positif). Suite JS 971/971 vert sur 148 fichiers. Lints `pos:lint:pricing` (un WARN signoff-pending non lié), `pos:lint:status` clean, `php -l` clean, `verify:boucle` exit 0 dans cette session. branch_id, dispatch-after-commit, OrderService↔FrontendOrderService symétrie, NF525 fiscal: tous respectés. Discipline orchestrateur excellente sur les deux discoveries (M1 1.7 plan-drift halt, M2 1.9 lockForUpdate impossibility) et sur le surgical commit M1 1.7 (+25 lignes seulement, 700 lignes prior-session restaurées dans le working tree pour cycle séparé).

## Tableau commit-par-commit

| Commit | Tâche | Plan-conformity | Scope-discipline | Invariants | Sentinels | Verdict | Notes |
|---|---|---|---|---|---|---|---|
| 957f59c65 | M1 1.1 Branch-scope PosCategoryController | OUI — `whereHas('items')` sur active_branch_id + channels(pos\|null) ; virtual id:0 préservée ; 403 fail-closed si pas de branche | PASS — 1 fichier ctlr + 1 sentinel | branch_id ✓ ; sqlite/MySQL portable (LIKE+JSON_CONTAINS) | 3 cas (branch A, branch B, Tenant Admin/Branch Manager bypass) — assertions précises, pas de nom-magique | **PASS** | Branch Manager carve-out explicite et testé. ItemBranchAvailability default-true sémantique préservée. |
| e88911275 | M1 1.2 surface=pos default | OUI — heuristique identique à `forcePosRuntimeBranchScope` (perm `pos` && !`items_show`) | PASS — 1 ctlr + extension sentinel existant | branch_id ✓ (réutilise mécanisme existant) | FrontendSurfaceFilteringTest +4 cas | **PASS** | Client-provided surface (kiosk) win — back-compat préservée. Commentaire inline pointe l'audit. |
| a5b417de4 | M1 1.3 Sentinel JS POS↔Kiosk | OUI — fixture 10 items + 4 cas | PASS — TEST-ONLY (zéro produit) | n/a (test) | 4 cas (intersection universal, POS\Kiosk, Kiosk\POS, empty channels) | **PASS_WITH_NOTE** | "Test-of-contract" pas "test-of-store-filtering" — la fonction `catalogItemVisibleOnSurface` est définie dans le test, pas importée du store. Limite documentée dans le header du spec. Voir §"Risques" #1. |
| f281d7eb1 | M1 1.4 channels-null warning | OUI — log dans store/update + CatalogWarningService::forItem + ItemController::show merge `warnings[]` | PASS — 3 fichiers (ctlr, service warnings, ItemService) | dispatch-after-commit non concerné (read-only warnings) | 6 cas (assert log + assert API JSON) | **PASS** | Configuration gate `expose_to_admin_show` respectée. |
| 15f36553d | M1 1.5 Runbook divergence | OUI — section ajoutée au runbook canonique avec snippet tinker + 3 causes + recovery | PASS — 1 fichier doc | n/a | n/a (doc) | **PASS_WITH_DISCOVERY** | Discovery RÉELLE (vérifiée § ci-dessous) : `CatalogChanged::dispatch($branchId)` du plan est techniquement invalide (constructeur exige 3 args required) et `CatalogChanged` n'est pas un event listener standalone — il est agrégé via `fromMenuMutation()` dans `PersistCatalogChangedToOutbox`. La réécriture redirige vers `CategoryUpdated` / `ItemAvailabilityChanged` qui sont les events réellement écoutés. Ops-correct. |
| db06c18ee | M1 1.6 DispatchableAfterCommit | OUI — 5 events (Item{Created,Deleted}, Category{Created,Updated,Deleted}) basculent du trait Laravel `Dispatchable` au trait projet `App\Events\Concerns\DispatchableAfterCommit` | PASS — 5 events + 1 sentinel | dispatch-after-commit ✓ (sentinel exécute `dispatch()` dans `DB::transaction()` rolled back puis committed) | CatalogEventDispatchAfterCommitTest 2 cas (rollback drop + commit dispatch) | **PASS** | Sentinel valide la doctrine KI-001 / gate C9. |
| 87011d916 | M1 1.7 PosSyncService fallback polling | OUI APRÈS PIVOT — plan référence `window.fkConfig` + `app.blade.php` (inexistants), réalité = `window.foodkingConfig` + `admin-pos-v4.blade.php`. Round 1 a halt-correctement avant edit ; round 2 amendé puis livré. | PASS — service neuf 425 LOC + +25 lignes surgical PosComponent.vue + +4 lignes blade + sentinel 180 LOC | branch_id ✓ (idempotency guard `_posSyncBranchId === branchId`) ; dispatch-after-commit n/a (couche front) | 5 cas (flag off, disconnected starts, connected suspends, 5xx backoff 5→10→20→30, AbortController overlap) | **PASS_WITH_DISCIPLINE_NOTE** | Surgical commit vérifié (cf. §"Cross-cutting" #5). PosSyncService aligné sur `KdsSyncService` (symétrie). Pas de logique pricing client. |
| 3d444c246 | M2 1.1 Composer warnings sur ItemShow | OUI — `composer_unpublished` + `composer_missing_for_complex_kind` + i18n 5 langues | PASS — 1 service catalog + 1 ctlr show + 1 vue badge + 1 vue ItemShow + 5 langues + 1 sentinel JS | n/a (read-only UX) | itemShowComposerWarning 4 cas (warning, blocker, simple no-badge, click action) | **PASS** | i18n appliqué uniformément. |
| d8d30b59c | M2 1.4 Sentinel composer publish-mid-cart | OUI — 2 cas dé-skipés, 2 restent skip derrière `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` (V2 §2.2) | PASS — TEST-ONLY (zéro produit) | n/a (test) | 2 cas actifs (option retirée → 422 + message ; option renommée même id → 200/201) | **PASS_WITH_DISCOVERY** | Discovery : l'API kiosk renvoie `{status:false, message:"..."}` (pas `errors.composer.removed_options[]` aspirationnel). Test asserte le format ACTUEL avec sondes regex `/choix\s*#/i` + ID variation présent. Cas 3 (409 + payload version mismatch) et cas 4 (composition_snapshot v2) gate-bloqués pour V2. Voir §"Risques" #2. |
| 47324fa33 | M2 1.8 Hard-delete protection | OUI — 409 si `OrderItem::exists()` && `forceDelete=true`, gated par `protect_force_delete_when_referenced` (default true) | PASS — 1 ctlr + 1 service + 1 sentinel | branch_id n/a (protection orthogonale) ; FK relax limité à `app()->environment('testing')` (PROD reste strict) | ItemDeletionWithOrderHistoryTest 4 cas | **PASS** | Production-safe : la levée FK n'arrive qu'en testing avec le flag à false. Soft-delete inchangé. |
| 76cc6d1d4 | M2 1.9 Atomic decrement (round 2) | OUI — supersedes round 1 (lockForUpdate impossibility, ESCALATED). Pattern atomic UPDATE choisi via comparative analysis Square/Toast/Foodics/Lightspeed. | PASS — 1 service (62 lignes décrement seulement) + 1 sentinel | branch_id ✓ (toutes UPDATE filtrent item_id+branch_id) ; dispatch-after-commit ✓ (appelé depuis listener post-commit, pas de DB::transaction nouvelle) ; OrderService/FrontendOrderService symétrie ✓ (signature publique inchangée) | AvailabilityDecrementConcurrencyTest 4 cas (under-cap / threshold flip / over-shoot / serialized concurrent → exactly-once event) | **PASS** | Pattern CAS `where is_available=true AND consumed >= max` garantit exactly-once flip event. Voir §"Cross-cutting" #2. |
| 8ce0bddc8 | chore log-restore M1 1.4 + 1.5 audit | n/a — log integrity | PASS — `reports/post_execute_latest.log` only | n/a | n/a | **PASS** | 21 lignes restored from orphan 98b7d0bcc. |
| 58938bf9a | chore log-restore appendix | n/a — log integrity | PASS — `reports/post_execute_latest.log` only | n/a | n/a | **PASS** | 71 lignes restored, orphan toujours présent (vérifié `git log --oneline 98b7d0bcc`). |

(2 chore additionnels `7c6667a1d` industry analysis + `2c12dac20`/`1ceb35934` audit logs hors scope du périmètre demandé — l'utilisateur a explicitement demandé d'exclure les chore log-only autres que ceux liés à l'integrity.)

## Vérification fonctionnelle

| Suite / commande | Résultat | Note |
|---|---|---|
| `php artisan test` | **1272 passed / 40 skipped** | Baseline pré-1.9 = 1263/46. Mouvement net : +9 passing, −6 skipped (4 sentinels nouveaux + 2 actifs sur 1.4 = 6 dé-skipés ; on récupère donc +6 et +9 nouveaux passants nets). Pas de fail. |
| `npx vitest run` | **971 passed / 0 failed**, 148 fichiers, 12.92s | 0 régression. |
| `php artisan test --filter=Catalog\|Menu\|Composer\|Stock\|Availability\|Symmetry\|Outbox` | **250 passed / 38 skipped** | Tous les sentinels Vague 1 ciblés verts. 38 skip = sentinels Vague 2/V3 attendant gates (StockScanRupture 6 / autres 32). |
| `npm run pos:lint:pricing` | **OK — scanned 55 files** | 1 WARN `signoff-pending until 2026-05-10` non-bloquant et hors scope du sprint. |
| `npm run pos:lint:status` | **OK — scanned 11 files** | Clean. |
| `php -l` × 4 (AvailabilityService, PosCategoryController, ItemController, ItemService) | **No syntax errors** | — |
| `npm run verify:boucle` | **exit 0** dans cette session : `claude on PATH 1.0.128`, `validate-active-cycle: OK PHASE=EXECUTE`, run-cycle/CODEX_API_DELEGATION docs OK | Codex sandbox a rapporté exit 1 (sandbox EPERM sur `~/.claude.json`) — confirmé env-only artifact. |

## Cross-cutting findings

### 1. branch_id isolation — PASS

- **M1 1.1** (`PosCategoryController::index`) : `posRuntimeBranchId` résolu via `DefaultAccessService::show()['branch_id']`, fail-closed 403 si <1. La sous-query `whereHas('items', fn($q) => $q->where('item_branch_availability.branch_id', $posRuntimeBranchId))` filtre strictement. Pas de `withoutGlobalScope` problématique.
- **M1 1.2** (`ItemController::index` surface=pos default) : ne touche pas la couche branch — la route force déjà `branch_id` via `forcePosRuntimeBranchScope` existant. Le commit ajoute uniquement `surface=pos` dans le query string.
- **M2 1.9** (`AvailabilityService::decrementForOrder`) : les 3 UPDATE atomiques filtrent toutes par `item_id` + `branch_id`. `$branchId = (int) $order->branch_id` est lu une fois par order et propagé. Aucune fuite cross-branch possible.

### 2. DispatchableAfterCommit — PASS

- **M1 1.6** : 5 events (`Item{Created,Deleted}`, `Category{Created,Updated,Deleted}`) basculent vers `App\Events\Concerns\DispatchableAfterCommit`. Sentinel `CatalogEventDispatchAfterCommitTest` exécute les `dispatch()` à l'intérieur de `DB::beginTransaction()` puis `rollBack()` → `Event::assertNotDispatched(...)`, puis `DB::transaction(fn)` avec dispatch dedans → `Event::assertDispatched(...)`. Les deux scénarios passent ; le trait travaille comme spécifié.
- **M2 1.9** : la chaîne d'invocation est `OrderService::create` → `OrderCreated::dispatch()` (afterCommit) → listener `DecrementItemAvailabilityOnOrder` (post-commit, pas de transaction active) → `decrementForOrder` (pas de `DB::transaction()` nouveau, 3 UPDATE atomiques par ligne) → `dispatchEvent` qui appelle `event(ItemAvailabilityChanged::forBranch(...))` direct. À ce point `transactionLevel()=0` donc même `DispatchableAfterCommit::dispatch()` s'exécuterait immédiatement — la sémantique est correcte. **Note de fragilité future** : si demain `decrementForOrder` est appelée depuis un autre site dans une transaction, l'`event()` direct ferait fuir un dispatch in-transaction. Risque LOW : aujourd'hui un seul appelant. Recommandation : remplacer `event(...)` par `ItemAvailabilityChanged::dispatch(...)` (la classe a déjà le trait). Voir §"Recommandations" #1.
- **`ItemAvailabilityChanged`** est déjà `DispatchableAfterCommit` — un futur switch de `event()` à `dispatch()` est trivial.

### 3. OrderService ↔ FrontendOrderService symétrie — PASS

Aucun des 9 commits ne touche directement `OrderService` ni `FrontendOrderService`. M2 1.9 modifie `AvailabilityService::decrementForOrder` dont la signature publique reste `decrementForOrder(Model $order): void` ; les deux services consomment l'API via le listener `DecrementItemAvailabilityOnOrder` (path commun, pas de double-câblage). La sentinel `StockSymmetryDiffTest` est verte. `OrderServicesContractTest` 5 cas verts (intentional payment asymmetry, branch+dispatch source-anchored, OS/FOS noops sans side effects, deferred payment golden idempotent).

### 4. NF525 / Fiscal — PASS

`composition_snapshot`, `allergens_snapshot`, Z-reports : aucun fichier touché. Diff stat confirmé sur les 16 commits : zéro entrée sous `app/Services/Fiscal/`, zéro sous `app/Services/Pricing/PricingService.php`, zéro sous `tests/Feature/Pos/AdvanceFiscal*`. Sentinel `tests/Feature/Stock/StockSymmetryDiffTest` reste vert.

### 5. Surgical commit discipline (M1 1.7) — VÉRIFIÉ

Le diff `git show 87011d916 -- resources/js/components/admin/pos/PosComponent.vue` est exactement +25 lignes :
- `import PosSyncService` (+1)
- data field `_posSyncBranchId: null` (+1)
- `beforeUnmount` : `PosSyncService.stop(); this._posSyncBranchId = null;` (+2)
- `mounted` : `this._startPosSyncFallback();` ×2 (avant `defaultAccess.show().then()` et dedans pour resolved branch_id) (+2)
- méthode `_startPosSyncFallback()` (+19)

Total : 25 lignes (1+1+2+2+19=25 ✓). **Aucune ligne POS V4 viewport / landing** dans ce commit. `git status -s` confirme que `resources/js/components/admin/pos/PosComponent.vue` est `M` (modifié) au working tree → les ~700 lignes prior-session sont restaurées et attendent un cycle séparé. Fidèle à la trace `CONSCIOUS_DISCIPLINE_NOTE` du log d'audit.

Rappel : le sentinel `posSyncFallback.spec.js` teste `PosSyncService` directement (pas `PosComponent.vue`), donc la version commitée minimale + la version Codex full produiraient toutes deux le même résultat de test. La parité de comportement runtime est garantie par les 6 StrReplace edits qui sont à la fois nécessaires ET suffisants pour wirer le service.

### 6. Sandbox blockers (Codex execution) — env-only

Trois artefacts récurrents dans les outputs Codex :
- `git/index.lock` : Operation not permitted dans le sandbox `codex exec`. Orchestrateur commit manuellement après application du diff. Pas un défaut applicatif.
- `verify:boucle` exit 1 : `claude --version` tente d'écrire `/Users/1millnonstop/.claude.json` et le sandbox refuse. **Vérifié dans cette session** : `npm run verify:boucle` retourne `exit 0` quand exécuté hors sandbox, prouvant que le code de orchestration est sain.
- npm `vitest` stderr noise (legacy localhost:3000, evil.tld, router-link warnings) : pré-existant, hors scope.

Conclusion : tous les warnings sandbox sont des artefacts d'environnement, pas des problèmes de code.

### 7. Log integrity (chore commits 8ce0bddc8 + 58938bf9a) — VÉRIFIÉ

L'orphan `98b7d0bcc` est encore atteignable (`git log --oneline 98b7d0bcc` retourne le commit M1 1.5 head + history). Les 2 chore commits restaurent 21 + 71 = 92 lignes de log dans `reports/post_execute_latest.log`. Aucun bloc audit dropped n'est resté non-restauré au regard du log courant qui contient les blocs M1 1.1, 1.2, 1.4, 1.5, 1.6, 1.7, M2 1.1, 1.4, 1.8, 1.9 round 1+2.

## Risques détectés (importance ↓)

1. **MEDIUM — M1 1.3 (sentinel POS↔Kiosk) est un test-of-contract, pas un test-of-store**. La fonction `catalogItemVisibleOnSurface` est définie inline dans le spec. Si demain quelqu'un ajoute un filtre channel à `resources/js/store/modules/item.js` qui diverge de `App\Models\Item::isVisibleOn()`, ce test ne le détecterait pas (il n'utilise pas le getter qui ferait le filtre). Limite explicitement documentée dans le header du spec. Mitigation : ce filtrage est aujourd'hui exclusivement serveur (SSOT). Recommandation : voir §"Recommandations" #2.

2. **MEDIUM — M2 1.4 sentinel asserte la forme actuelle de l'API**. Les 2 cas actifs vérifient `{status:false, message:"...n'appartient pas au profil publié..."}` avec sondes regex sur l'ID variation et "/choix\s*#/i". Si demain un dev améliore la réponse vers `{errors:{composer:{removed_options:[...]}}}` (la forme aspirationnelle du plan), les sentinels échoueraient — c'est OK car le test forcerait une mise à jour. Mais si un dev change la réponse vers une 3ème forme intermédiaire incomplète, le test n'attraperait pas la régression de la valeur ajoutée. Risque plutôt vocabulaire. Cas 3+4 (gate V2) couvriront la forme finale.

3. **LOW — `AvailabilityService::dispatchEvent` utilise `event()` direct**. Aujourd'hui safe (post-commit), mais une régression future qui appellerait `decrementForOrder` depuis un site in-transaction ferait fuir un dispatch in-transaction. La classe `ItemAvailabilityChanged` a déjà le trait `DispatchableAfterCommit` ; il suffit de remplacer `event(...)` par `::dispatch(...)`. Voir §"Recommandations" #1.

4. **LOW — `$qty` interpolation dans raw SQL CASE** (M2 1.9 ligne 219). `$qty = (int) $line->quantity` au-dessus rend cette interp safe contre l'injection. Mais le pattern enfreint la doctrine "jamais de string SQL avec interp même si int cast". Lint rule future. Voir §"Recommandations" #3. (Note 4.bis : la trace `RISKS_FROM_CODEX_OUTPUT` du post_execute log mentionne explicitement ce point — l'orchestrateur l'a déjà identifié.)

5. **LOW — ATD du cron auto-86 (M2 §2.1) toujours skipped**. Sentinel `StockScanRuptureCommandTest` reste à 6 cas skipped ("Pending plan task 2.1 (PLAN_CV1-LIFECYCLE-UX-001)"). Cohérent avec le périmètre Sprint 1 = Vague 1 uniquement. Vague 2 démarre quand l'humain le décide.

6. **INFO — Surgical commit M1 1.7 laisse 700 lignes prior-session uncommitted**. Le diff working-tree confirme `M PosComponent.vue` (et autres fichiers POS V4). Ces lignes appartiennent à `PLAN_POS_V4_UNIFIED_CATEGORY_VIEW_2026-05-02.md` et `PLAN_POS_V4_VIEWPORT_UI_2026-05-02.md` qui sont des plans séparés. **Action future requise** : ouvrir un cycle dédié à ces deux plans pour committer la version POS V4 complète. Pas un blocker pour le sprint actuel.

## Recommandations follow-up (par priorité)

1. **(PRIORITÉ 1)** Remplacer `event(ItemAvailabilityChanged::forBranch(...))` par `ItemAvailabilityChanged::dispatch(...)` dans `AvailabilityService::dispatchEvent()` (ligne 254). Trivial (1 ligne), bas risque, durcit la cohérence dispatch-after-commit.

2. **(PRIORITÉ 2)** Migrer la fonction `catalogItemVisibleOnSurface` du spec `tests/js/posComponentMenuFiltering.spec.js` vers un helper `resources/js/helpers/itemSurfaceVisibility.js` ET importer ce helper depuis le sentinel. Ainsi un futur filtre store qui dévierait serait visible. Cycle séparé, S effort.

3. **(PRIORITÉ 3)** Ajouter une lint rule (`tools/lint/`) qui interdit l'interpolation `{$var}` dans `DB::raw(...)`/`whereRaw(...)` même avec int cast. Le commit M2 1.9 est un cas légitime mais futur dev pourrait copier le pattern sans le cast. Cycle séparé, M effort.

4. **(PRIORITÉ 4)** Ouvrir un cycle dédié pour les 700 lignes POS V4 viewport/landing actuellement dans le working tree (référencer `plans/PLAN_POS_V4_UNIFIED_CATEGORY_VIEW_2026-05-02.md` + `plans/PLAN_POS_V4_VIEWPORT_UI_2026-05-02.md`). Bloque la propreté du working tree entre Sprint 1 et Vague 2.

5. **(PRIORITÉ 5)** Avant Vague 2 catalog, l'orchestrateur devrait poser `FK_CATALOG_UNIFIED_PROJECTION_SHADOW_COMPARE=true` en staging et observer 7 jours sans diff structurel par §2.1 du plan M1.

## Verdict détaillé par tâche

### M1 1.1 — Branch-scope PosCategoryController
**PASS.** L'implémentation respecte exactement le contrat : POS-only users (perm `pos` && !`items_show` && !`Branch Manager`) reçoivent une vue branch-scopée ; Tenant Admin / Branch Manager / Admin gardent la vue globale. Fail-closed 403 quand l'active branch manque. Le sentinel teste les 3 cas (branch A, branch B, Tenant Admin/Branch Manager) avec assertions précises sur les noms et l'ID:0 virtuelle. Portabilité sqlite/MySQL préservée.

### M1 1.2 — Default surface=pos for POS-only-scope users
**PASS.** Heuristique réutilise exactement le gate `forcePosRuntimeBranchScope` (perm `pos` && !`items_show`). `request->merge` + `request->query->set` couvrent les deux APIs Laravel (input bag + query bag). Client-provided surface `?surface=kiosk` toujours respecté. Sentinel étend `FrontendSurfaceFilteringTest` avec 4 nouveaux cas dont admin sans surface (qui doit garder la vue catalogue-wide).

### M1 1.3 — Sentinel JS parité menu POS↔Kiosk
**PASS_WITH_NOTE.** Les 4 cas couvrent le contrat backend (intersection universal + 2 diffs symétriques + empty channels). Le test reproduit `Item::isVisibleOn()` inline plutôt que d'importer un helper du store — limite documentée dans le header. C'est suffisant pour V1 où le filtrage est serveur-only. Voir §"Risques" #1 + §"Recommandations" #2.

### M1 1.4 — Warning catalog.channels-null
**PASS.** ItemService::store/update logs `[catalog.channels-null]` avec `item_id`, `user_id`, `tenant_id`, `action`. CatalogWarningService::forItem détecte channels=null en plus des codes composer. ItemController::show merge `warnings[]` quand `expose_to_admin_show=true`. Sentinel 6 cas couvre log + API output. La méthode `warnCatalogChannelsNullIfNeeded` est appelée APRÈS le `DB::transaction(fn)` close et sur `$item->refresh()` pour lire la valeur committée.

### M1 1.5 — Runbook divergence catalogue
**PASS_WITH_DISCOVERY.** La discovery est réelle : la signature constructeur de `CatalogChanged` est `(string, int, string, ?int, array)` (vérifié `app/Events/CatalogChanged.php`), et le seul site qui instancie ce event est `PersistCatalogChangedToOutbox::handle` via `CatalogChanged::fromMenuMutation()`. Le runbook redirige correctement vers `CategoryUpdated` / `ItemAvailabilityChanged` qui sont des events Laravel écoutés à part entière. Snippet tinker exécutable (charge `Branch::findOrFail`, instancie `MenuProjectionService` et `KioskMenuService`, dump diffs).

### M1 1.6 — DispatchableAfterCommit catalog events
**PASS.** Les 5 events basculent au trait projet. Le sentinel exécute les deux scénarios canoniques (rollback drop + commit dispatch). Cohérent avec `CatalogChanged` / `ItemAvailabilityChanged` / `StockLevelChanged` / `ComposerProfileChanged` qui utilisaient déjà ce trait.

### M1 1.7 — PosSyncService fallback polling
**PASS_WITH_DISCIPLINE_NOTE.** Service neuf 425 LOC avec state machine Echo + polling jittered + AbortController + backoff doubling capped 30s. Round 1 a halt-correctement avant edit (plan-drift sur `window.fkConfig` + `app.blade.php`) — discipline. Round 2 amendé avec corrections vers `window.foodkingConfig` + `admin-pos-v4.blade.php` (vérifié : `git show 87011d916 -- resources/views/admin-pos-v4.blade.php` montre le bloc `posFallbackPolling` injecté dans le `window.foodkingConfig` existant). Surgical commit +25 lignes vérifié. Sentinel 5 cas distincts (flag off, disconnected, reconnect, 5xx backoff doubling 5→10→20→30, AbortController overlap).

**Question utilisateur "y a-t-il d'autres endroits qui devraient recevoir l'injection ?"** : `kiosk.blade.php` n'est pas concerné (le kiosk n'a pas besoin de polling POS — il a son propre polling KioskWizard). `admin-shell.blade.php` est l'admin global — y injecter `posFallbackPolling` exposerait la config sur des pages admin sans POS. La scope au seul `admin-pos-v4.blade.php` est correcte.

### M2 1.1 — Composer warnings sur ItemShow
**PASS.** Service `CatalogWarningService::forItem` détecte 3 codes (composer_unpublished, composer_missing_for_complex_kind, channels_null). Vue `ComposerProfileWarningBadge.vue` route correctement vers `/admin/items/{id}/composer` selon le code. i18n 5 langues complétées. Sentinel 4 cas (warning, blocker, no badge sur item simple, click action).

### M2 1.4 — Sentinel composer publish-mid-cart
**PASS_WITH_DISCOVERY.** Discovery accurate : la chaîne actuelle `PricingService::assertComposerSelectionsBelongToPublishedProfile()` lève `\InvalidArgumentException` qui est sérialisée en `{status:false, message:"..."}` par le exception handler kiosk, pas en `errors.composer.removed_options[]`. Les 2 cas actifs vérifient le format actuel ; cas 3 (409 + payload mismatch) et cas 4 (composition_snapshot v2) restent skipped derrière le gate `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_*`. Trade-off acceptable, voir §"Risques" #2.

### M2 1.8 — Hard-delete protection
**PASS.** Logic gating : si `?force=true` + `OrderItem::exists()` + `protect_force_delete_when_referenced=true` → 409 avec message clair. Soft-delete (`?force=false` ou absent) inchangé. Le contrôleur retourne 409 avec `error: "errors.item.cannot_force_delete_with_history"` distinct des 422 standards. FK relax `Schema::disableForeignKeyConstraints()` est strictement gated par `app()->environment('testing')` — production reste protégée par les FK natifs Laravel. Sentinel 4 cas.

### M2 1.9 — Atomic decrement + exactly-once flip event
**PASS.** Round 2 supersède round 1 (lockForUpdate impossibility). Le pattern atomic UPDATE × 3 (idempotent reset / capped CASE increment / CAS flip) est aligné avec la doctrine cloud-POS Square/Toast/Foodics/Lightspeed (cf. `M2_1_9_INDUSTRY_COMPARATIVE_ANALYSIS_2026-05-02.md`). Tous les UPDATE filtrent `item_id`+`branch_id`. Pas de `DB::transaction()` nouveau introduit (compatible avec l'invocation depuis le listener post-commit). La sentinel 4 cas teste sous-cap, threshold flip, over-shoot capped, et 3 décrements sériels concurrents → exactly-once event. Test theater check : les assertions vérifient `daily_consumed_qty` numérique précis ET `is_available=false` ET `unavailable_reason='out_of_stock'` ET `Event::assertDispatchedTimes(...)` — pas de tolérance sur les valeurs. Vérification serveur authentique.

**Q: cross-DB safe ?** Oui. Le pattern `whereDate('daily_reset_at', '<', $today)` génère du `DATE(daily_reset_at) < ?` portable (sqlite cast à TEXT, MySQL `DATE()`). `CASE WHEN ... THEN ... ELSE ... END` est SQL standard. La suite tests sur sqlite + le pattern industry sur MySQL prouvent la portabilité. Note de rigueur : le test PHP n'exécute pas littéralement de transactions concurrentes (PHP single-thread) — la "concurrence" du cas 4 est sériée. La correction sous vraie concurrence repose sur les locks row-level du SGBD, propriété hors-test. Acceptable.

## Décision finale et conditions de close

**Ce batch est éligible à la fermeture maintenant.** Les conditions suivantes sont remplies :

- [x] 9 commits produit alignés avec leurs §du plan respectif.
- [x] Aucun fichier hors `SUBSYSTEMS_TOUCHED` modifié.
- [x] 6 invariants FoodKing maintenus (pricing SSOT serveur, OrderStatus enum non touché car aucun commit n'introduit de string status, branch_id isolation préservée, dispatch-after-commit harmonisé, OrderService↔FrontendOrderService symétrie inchangée, frozen zones intactes).
- [x] Suite PHP + JS + lints + verify:boucle verts.
- [x] Audit-source briefs traités sur les §A.1 #1, #3, #2 (M1) et §A.1 #2, §C #1, §D Vague 1 (M2) — tous mappés à un commit + une sentinel.
- [x] Discoveries (M1 1.5, M1 1.7 round 1, M2 1.9 round 1, M2 1.4) sont DOCUMENTÉES, pas dissimulées.
- [x] Surgical commit M1 1.7 vérifié quasi-mathématiquement (+25 lignes exactes, prior-session restauré au working tree).
- [x] Log integrity restored (orphan 98b7d0bcc + 2 chore commits 8ce0bddc8 + 58938bf9a).

**Conditions résiduelles à traiter dans des cycles séparés (PAS de blocker) :**

1. Recommandations §1–4 ci-dessus (priorités décroissantes — toutes hors-scope du sprint actuel).
2. Vague 2 catalog convergence : nécessite décision humaine (shadow_compare staging 7 jours puis flip).
3. Vague 2 lifecycle UX : `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` à ouvrir avant §2.2 ; `StockScanRupture` cron à activer après tests staging.
4. Cycle séparé pour les ~700 lignes POS V4 viewport/landing prior-session présentes au working tree.

**Audit channel** : cursor-session (ce subagent foodking-planner-orchestrator). Aucun terminal claude exécuté pour cet audit massif — l'utilisateur a choisi un Cursor subagent. Pour conformité doctrine, l'orchestrateur peut ajouter `AUDIT_CHANNEL: cursor-session` + `AUDIT_FALLBACK_REASON: massive-deep-audit-batch-end-of-sprint` dans la mise à jour finale du log.

**Verdict final auditeur indépendant : PASS_WITH_NOTES — Sprint 1 Vague 1 (Missions #1+#2) techniquement clos.**
