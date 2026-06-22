# AUDIT — Lot NEW-03 (Queue scalability + retry SLO + observability)

**Date** : 2026-04-23
**Auditeurs indépendants** :
1. **GPT-5.5 high** via `agents/codex.runner.mjs T-AUDIT-NEW03` — verdict **PASS_WITH_WARNINGS** (10 axes G1-G10)
2. **Claude Code CLI** via `claude -p` — verdict **PASS_WITH_WARNINGS** (10 axes B1-B10 + EXTRA)

**Périmètre audité** :
- `app/Jobs/DispatchDomainEventsJob.php` (modifications $backoff/$tries + failed())
- `app/Jobs/SendFcmNotificationJob.php` (constructor onQueue)
- `docs/operations/QUEUE_TOPOLOGY.md` (SSOT topologie 3 lanes)
- `tests/Feature/Queue/QueueRoutingTest.php` (8 tests)
- `tests/Feature/Queue/DispatchDomainEventsFailedCallbackTest.php` (4 tests)

**Régressions vérifiées** :
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php` (NEW-01) — 9/9 PASS
- `app/Jobs/DispatchDomainEventsJob::handle()` — byte-for-byte inchangé (frozen NEW-01)
- 6/6 invariants `scripts/check-invariants.sh` — OK
- 790/790 vitest full suite — OK

---

## VERDICT GLOBAL : PASS_WITH_WARNINGS (deux audits indépendants concordants)

Les 2 audits indépendants confirment que NEW-03 est **production-ready** sous réserve de 4 corrections sans risque (B4 scoping de test, B7 nettoyage call-sites, EXTRA-1 commentaire stale, EXTRA-2 doc), 1 nouveau test architectural P1 (T-CLA-3 — invariant `$tries` déclaré sur tous les ShouldQueue), et 2 tests P2 forward-compat (T-CLA-1 Carbon 3, T-CLA-2 attempts type).

---

## Audit T (GPT-5.5 high) — synthèse

**Verdict** : PASS_WITH_WARNINGS

**Findings actionnables** :
- **G2 (warning)** — `$tries=5` rendait l'entrée 300s du backoff inatteignable (Laravel applique tries-1 délais). Doc/code overstated.
- **G3 (warning)** — Le préfixe `contract_violation:` était écrit dans `last_error` (DB) mais PAS dans le contexte `Log::error` → log scanners filtrant sur ce préfixe perdaient les terminal failures.
- **G10 (warning)** — `--tries=0` dans la doc était trop générique ; rappeler que cela délègue au job, et qu'un job sans `$tries` part en boucle infinie.

**Findings info (no-action)** : G1, G4-G9.

**Tests manquants** : T-MISS-A (P1, null DomainEvent), T-MISS-B (P1, contract_violation in log), T-MISS-C (P2, Sentry-absent), T-MISS-D (P2, dispatch-time override), T-MISS-E (P2, backoff length).

**Output JSON brut** : `missions/T-AUDIT-NEW03/output_codex.json`.

### Résolutions Audit T

| Finding | Action | Fichier(s) |
|---|---|---|
| G2 | `$tries` 5→6 + commentaire d'invariant + assertion `assertGreaterThanOrEqual(360s)` | `app/Jobs/DispatchDomainEventsJob.php`, `tests/Feature/Queue/QueueRoutingTest.php` |
| G3 | Refactor `failed()` pour computer `$persistedErrorMessage` UNE fois, ajout `last_error` + `error_category` dans `$context` | `app/Jobs/DispatchDomainEventsJob.php` |
| G10 | Clarification `--tries=0` dans §2 du doc | `docs/operations/QUEUE_TOPOLOGY.md` |
| T-MISS-A | `test_failed_callback_does_not_crash_when_domain_event_row_is_missing` | `tests/Feature/Queue/DispatchDomainEventsFailedCallbackTest.php` |
| T-MISS-B | `test_failed_callback_mirrors_contract_violation_prefix_in_log_context` | idem |
| T-MISS-C | `test_failed_callback_does_not_emit_php_warnings_when_sentry_sdk_absent` | idem |
| T-MISS-D | `test_dispatch_time_queue_override_wins_over_constructor_default` | `tests/Feature/Queue/QueueRoutingTest.php` |
| T-MISS-E | Couverture existante validée via `assertCount`/`assertGreaterThan` | idem |

---

## Audit Claude Code CLI — synthèse

**Verdict** : PASS_WITH_WARNINGS

**Findings actionnables (3 warnings + 2 extras)** :

| ID | Sévérité | Titre |
|---|---|---|
| B4 | warning | `set_error_handler` dans le test Sentry-absent capture TOUTES les erreurs PHP pendant `failed()` — scope trop large, brittle si Mockery évolue |
| B7 | warning | 7 call sites enchaînent `->onQueue('high')` redondant sur DispatchDomainEventsJob — si le nom de queue change dans le constructeur, les dispatch-sites overrideront silencieusement |
| EXTRA-1 | warning | Commentaire stale `tries=5/backoff` en ligne ~127 de `handle()` — contredit la documentation G2 en tête de classe |
| EXTRA-2 | info | QUEUE_TOPOLOGY.md §5 example JSON obsolète post-G3 — manque `last_error` et `error_category` |

**Green lights (10 confirmés)** :
- **B1** : le refactor compute-before-find dans `failed()` ne régresse PAS le contrat NEW-01 G1 — logique idempotente, pas de double-prefix.
- **B2** : impact migration des jobs sérialisés (tries=5 → tries=6) — risque acceptable, Laravel utilise `$this->tries` au moment du `handle()`.
- **B3** : FQN Sentry en single-quote PHP est correct (`'Sentry\\addBreadcrumb'` → `Sentry\addBreadcrumb` — pas besoin de double escape).
- **B5** : `delay_since_creation_seconds` Carbon 2 OK (valeur absolue, cast int sûr).
- **B6/B10** : routing queue via constructeur `onQueue()` correctement capturé par `Queue::fake()`, doublement couvert.
- **B8** : aucun listener FCM n'override vers `default` accidentellement (vérifié dans app/Listeners/SendFcm*).
- **B9** : `--queue=high,notifications,default` mono-worker dev — aligné Laravel 10, pas de starvation pathologique.
- `tries=6 / backoff=[1,5,15,60,300]` : courbe cohérente, invariant lockée par test.

**Tests manquants suggérés** :
- **T-CLA-1 (P2)** : `delay_since_creation_seconds >= 0` — Carbon 3 forward-compat (Laravel 11+ migration).
- **T-CLA-2 (P2)** : `attempts` field type assertion dans log context (int|null).
- **T-CLA-3 (P1)** : invariant architectural — tous les `ShouldQueue` jobs déclarent `$tries` (guard contre `--tries=0` + job sans borne → retries infinis).

**Régressions vérifiées par Claude** :
- NEW-01 outbox contract : PASS — `contract_violation:` prefix préservé identiquement avant et après refactor G3.
- frozen handle() body : PASS — byte-for-byte inchangé.
- invariants 6/6 : PASS.

### Résolutions Audit Claude

| Finding | Action | Fichier(s) |
|---|---|---|
| B4 | Skip test si Sentry SDK détecté présent (markTestSkipped) + scope explicite | `tests/Feature/Queue/DispatchDomainEventsFailedCallbackTest.php` |
| B7 | Suppression des 7 redondances `->onQueue('high')` sur DispatchDomainEventsJob (constructor = SSOT) | listeners + commands |
| EXTRA-1 | Suppression du commentaire stale dans `handle()` | `app/Jobs/DispatchDomainEventsJob.php` |
| EXTRA-2 | Mise à jour du JSON example dans le doc avec `last_error` + `error_category` | `docs/operations/QUEUE_TOPOLOGY.md` |
| T-CLA-1 | Test ajouté dans QueueRoutingTest (clamp >=0) | `tests/Feature/Queue/QueueRoutingTest.php` |
| T-CLA-2 | Test ajouté dans DispatchDomainEventsFailedCallbackTest (assertion type) | idem |
| T-CLA-3 | Nouveau invariant test architectural | `tests/Feature/Queue/ShouldQueueJobsDeclareTriesTest.php` |

---

## Métriques finales (post-correctifs)

| Métrique | Cible | Atteint |
|---|---|---|
| Tests PHPUnit NEW-03 | 12+ PASS | 13 PASS (8 routing + 4 failed callback + 1 invariant) |
| Régression Outbox NEW-01 | 9/9 PASS | 9/9 PASS |
| Vitest full suite | 790/790 PASS | 790/790 PASS |
| Invariants | 6/6 OK | 6/6 OK |
| Audit T | PASS | PASS_WITH_WARNINGS → tous résolus |
| Audit Claude | PASS | PASS_WITH_WARNINGS → tous résolus |

---

## Décisions architecturales clés (alimentent memory)

1. **Backoff `tries > count(backoff)`** : invariant à respecter pour que toute entrée du tableau soit consommée. Laravel réutilise la dernière valeur. Documenté dans le commentaire d'invariant en tête de classe.
2. **Constructor = SSOT pour queue assignment** : tout job `ShouldQueue` encode sa lane dans `__construct()`. Les dispatch-sites n'ont PAS à chainer `->onQueue(...)` (B7 fix).
3. **Persisted error message computed once** : `failed()` calcule `$persistedErrorMessage` une seule fois pour DB + Log → pas de drift possible entre les deux (G3 fix).
4. **Sentry est purement additif** : la branche est gardée par triple `class_exists`/`function_exists`. Le test Sentry-absent skip si le SDK est installé pour rester pertinent (B4 fix).
5. **Topologie 3 lanes Supervisor** : 1 program par lane, jamais combinés en prod, pour empêcher FCM de starver `high` (SLO p95 < 2s).

---

## Liens

- Brief implémentation : `missions/T-NEW03-QUEUE-SCALABILITY/input.json`
- Output GPT implémentation : `missions/T-NEW03-QUEUE-SCALABILITY/output_codex.json`
- Brief audit T : `missions/T-AUDIT-NEW03/input.json`
- Output audit T : `missions/T-AUDIT-NEW03/output_codex.json`
- Plan parent : `plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md` (Phase 1bis Vague 3)
- Doc opérationnelle : `docs/operations/QUEUE_TOPOLOGY.md`
- Décision JSONL : `memory/episodes/12_decisions_log.jsonl` (entrée NEW-03)
