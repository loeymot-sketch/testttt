# Brief pour Codex — post ultra-review Claude (2026-04-26)

Règle d’or (Claude) : **aucun lot B.1–B.5 tant que la Phase A n’est pas signée côté humain** (persistance git, dual `ACTIVE_CYCLE`, triage untracked, politique `memory/episodes`, migration `order_quotes` tranchée). `MASTERPLAY_FROZEN=1` : missions hors `CV1-MXX` → **run-cycle** / codex:complex standard, pas masterplay.

---

## 1. Ce que tu demandes à Codex **maintenant** (indépendant, sans contredire la Phase A)


| Priorité | Demande                                                                                                                                                                                                                                                                                   | Critère de sortie                                                                                   |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| P0       | **Re-produire les chiffres** sur ce worktree (pas de confiance seule) : `php artisan test` (compte failed/skipped) ; `npx vitest run` ; `npx playwright test` (config root). Coller le résumé + chemins de log si tu écris un mini rapport sous `reports/execution/` ou `reports/audit/`. | Preuve disque reproductible : les 44 / 6 / 35 (ou l’écart) sont **sourcés**                         |
| P0       | **Lire et résumer** `reports/audit/UNTRACKED_AUDIT_2026-04-26.txt` (ou nom équivalent) : valider le triage par bucket vs l’ultra plan A.1 ; noter conflits ou trous.                                                                                                                      | Paragraphe : alignement / écart par rapport à la table 402+111 de Claude                            |
| P1       | **Vérifier** `EXECUTE_DELEGATION: codex-extension` + traces attendues dans `reports/post_execute_latest.log` (et toute règle AGENTS.md) pour la passe 2026-04-26 ; si absent, **déclarer l’écart** (ne pas “inventer” la trace).                                                          | Liste binaire : conforme / manquant (chemins)                                                       |
| P1       | Exposer `vitest` au même niveau de DX que le reste : pr **ajout minimal** d’un script `npm run vitest` (ou alias documenté) qui appelle le même `npx vitest run` / config que l’équipe utilise — **sans** élargir le scope test.                                                          | `package.json` + 1 ligne dans le rapport ; `npm run vitest` = même comportement qu’`npx vitest run` |


Hors scope Codex ici : **trancher** A.1, A.5, A.6, C.1, D.4 (humain / gate).

---

## 2. Ce que tu demandes à Codex **dès que Phase A est signée** (ordre strict, 1 lot = 1 exécution cadrée)


| Ordre | TASK_ID proposé (hors `CV1-MXX`)             | Périmètre autorisé                                                                                                                                    | Critère de sortie binaire (Claude)                                                                                                                                                                                                            |
| ----- | -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| B.1   | `CV1-FIX-R1-POS-QUOTE-BINDING-TESTS`         | **Uniquement** `tests/`** + helpers de test. **Aucun** `app/Http/Controllers/Admin/PosController.php` ni `OrderService` (diff `app/` vide côté prod). | Filtre PHPUnit dédié **PASS** (liste de filtres : AntiGravity, POSComprehensive, PosDiscount, PosKioskPricingParity, PosOrderTax, PosPricingSsot, PosPriorityApi, PosTicketRestaurant, PosUI, SyncComprehensive — filtre exact selon le plan) |
| B.2   | `CV1-FIX-R2-OUTBOX-FIXTURES-K09B`            | `OutboxTest` + `OutboxConcurrentWorkerDedupeTest` : payloads `order.created` avec `_origin`, `payment_method`, `queue_number`.                        | `EventContract` / outbox filters **PASS** ; **pas** de changement listeners `app/Listeners` sauf preuve d’écart (Claude: diff listeners vide)                                                                                                 |
| B.3   | `CV1-FIX-R4-KIOSK-OFFLINE-QUEUE-IDEMPOTENCY` | `kioskOfflineQueue*.js` / store, tests Vitest listés                                                                                                  | Les 3 specs `kioskOfflineQueue*.spec.js` : **0 failed** ; reprise idempotence + localKey documentée en 3 bullets dans le self-audit                                                                                                           |
| B.4   | `CV1-FIX-R3-KDS-OWN-BRANCH-VISIBLE`          | Allowlist : KDS service + contrôleur + tests — **ne pas** affaiblir `BranchIsolationTest`                                                             | `BranchIsolationTest` + `SyncComprehensiveTest::kiosk_order_appears_in_kds` **PASS**                                                                                                                                                          |
| B.5   | `CV1-FIX-R6-KIOSK-MACHINE-FORCED-BRANCH`     | Middleware/abilities kiosk                                                                                                                            | `KioskSecurityTest::kiosk_branch_id_is_forced_from_machine` **PASS** (201 + branche machine)                                                                                                                                                  |


Entre B.x : **1 commit sémantique** + point mémoire si le projet l’impose (Claude: `12_decisions_log.jsonl` / procédure interne).

---

## 3. Ce que tu **ne** demandes **pas** à Codex sans gate humaine

- **C.1 / C.2** (unique `(branch_id, queue_number)`) : après note signée `docs/decisions/…`, puis seulement migration + sentinel.
- **R5** proprement dit : suit **C** ; pas de contournement du test sentinel.
- **A.1, A.2, A.3, A.5, A.6** : rôle **humain** (triage, commits, `ACTIVE_CYCLE` unique, migration / rollback order_quotes).
- **A.4** (régén `MISSIONS_CLOSED_VS_GIT`) : **après** commits humains, Codex ou script = OK.

---

## 4. Phrase d’or à coller en tête de prompt Codex

> Tu exécutes le brief `reports/audit/CODEX_BRIEF_APRES_CLAUDE_ULTRA_REVIEW_V2_2026-04-26.md`. **Si Phase A n’est pas marquée signée dans le plan / ACTIVE_CYCLE, tu ne traites que la section 1 (preuves reproductibles + vitest script + veille garde).** Pas de raccourcissement des invariants (quote SSOT, branch, `EventContract`). Self-audit GPT requis : `reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md`.

---

## 5. Référence ultra-review source

- Synthèse Claude complète (collée par l’orchestrateur) : `CLAUDE — ULTRA REVIEW V2 DEEP POST-CODEX (2026-04-26)`.
- Plan rework dépôt : `plans/PLAN_CAISSE_V1_W1W2_REWORK_AFTER_GLOBAL_TESTS_2026-04-26.md`.