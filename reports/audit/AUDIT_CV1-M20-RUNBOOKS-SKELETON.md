# AUDIT — CV1-M20-RUNBOOKS-SKELETON

- DATE: 2026-04-25
- TASK_ID: CV1-M20-RUNBOOKS-SKELETON
- AUDITOR: foodking-planner-orchestrator
- AUDIT_CHANNEL: cursor-session
- AUDIT_FALLBACK_REASON: Anthropic terminal quota exhausted — fallback subagent per `docs/orchestration/AUDIT_TERMINAL_QUOTA_FALLBACK.md`
- PLAN_SOURCE: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` §4 M-20 + super-master PLAN-20
- BRIEF: `missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md`
- OUTPUT: `missions/CV1-M20-RUNBOOKS-SKELETON/output_codex.json`
- GPT_SELF_AUDIT: `reports/audit/GPT_SELF_AUDIT_CV1-M20-RUNBOOKS-SKELETON.md` (`VERDICT: NEEDS_FIX`)

## Méthode appliquée
- `ls -la reports/runbooks/` — 9 fichiers, tailles 7.5–22 KB.
- Lecture intégrale de l'INDEX et des 80 premières lignes de TPE / FISCAL / ROLLBACK runbooks.
- `rg '^## \d+\.'` sur `reports/runbooks/` — vérification structure H2 §1..§8 (et §0..§4 pour INDEX).
- `git status --short` sur `app resources routes database tests scripts config .cursor AGENTS.md` — vérification scope mission.
- Vérification dépendance Horizon : `composer.json`, `composer.lock`, `config/horizon.php`, `vendor/laravel/horizon`, commande custom — **absente**.
- Lecture verdict GPT self-audit (lignes 3358–3412 du fichier `GPT_SELF_AUDIT_*.md`).

## Findings

### F1 — Existence et taille (PASS)
9/9 fichiers présents sous `reports/runbooks/`, tous > 1 KB :
- `RUNBOOK_TPE_FAILURE_2026-04-25.md` (21 876 o)
- `RUNBOOK_PRINTER_FAILURE_2026-04-25.md` (21 784 o)
- `RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md` (21 830 o)
- `RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` (20 812 o)
- `RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md` (21 001 o)
- `RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md` (21 503 o)
- `RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md` (21 356 o)
- `RUNBOOK_ROLLBACK_CANARY_2026-04-25.md` (22 778 o)
- `RUNBOOK_INDEX_2026-04-25.md` (7 696 o)

### F2 — Structure runbooks (PASS)
Les 8 runbooks contiennent exactement les sections §1 Trigger, §2 Symptômes, §3 Diagnostic step-by-step, §4 Actions correctives par criticité (P0/P1/P2), §5 Escalation matrix, §6 Vérifications de sortie, §7 Template post-mortem, §8 Références. Bandeau métadonnées conforme (Status DRAFT_SKELETON_NOT_SIGNED, Owner DRAFT, Severity ceiling, Plan source, Linked gates, Last reviewed). Index respecte §0 Statut, §1 Carte de décision, §2 Liens transverses, §3 Procédure d'usage, §4 Maintenance.

### F3 — Critères mesurables (PASS)
Seuils chiffrés présents : P0 ≤ 5 min, P1 ≤ 30 min, P2 ≤ 24 h ; canary `payment_success_rate < 95% / 5min`, `kds_error_rate > 5%`, `fiscal_anomaly > 0` ; outbox attempts < 5, stale 2 min ; KDS cap 50. Aucun « rapide / un peu » détecté.

### F4 — Index (PASS)
Carte de décision liste les 8 runbooks avec lien Markdown, first responder et severity ceiling. Matrice « Liens transverses » fournit plans/gates/métriques pour chaque runbook. Procédure d'usage en 5 étapes + maintenance. Aucune signature de gate.

### F5 — Scope allowlist (PASS)
`git status --short` confirme : aucun fichier hors `reports/runbooks/` n'a été créé/modifié par ce cycle. Les modifications visibles dans `app/`, `scripts/`, `.cursor/`, `AGENTS.md`, `tests/` proviennent de cycles antérieurs (déjà présentes avant M-20 — voir `git status` global avant ouverture du cycle). Aucun code produit, aucun script, aucun gate signé, aucun changelog ajouté.

### F6 — Invariants FoodKing (PASS)
- pricing_ssot : runbooks rappellent que le frontend ne recalcule pas les prix.
- order_status : usage documentaire de `OrderStatus enum`, aucune réécriture de transition (KDS runbook §4 explicite : « Aucune action qui réécrit transitions »).
- branch_id : preuves et tris par `branch_id` exigés systématiquement.
- commit_before_dispatch : §3 OUTBOX rappelle outbox via commandes existantes, pas de patch DB brut sans L4.
- frozen_zones : aucune édition produit, gates cités en `PENDING_HUMAN_GATE`.
- os_fos_symmetry : N/A (aucun service modifié), correctement noté dans le runbook TPE.
- NF525 : §4.1 fiscal = freeze caisse + L4 immédiate, pas de patch séquence — conforme.

### F7 — Commande inventée Horizon (FAIL — bloquant)
`RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` cite `php artisan horizon:status` (ligne 42, étape 1 du diagnostic) et la commande/le « Horizon backlog » comme outil opérationnel réel (lignes 11, 41, 139). L'INDEX (lignes 16 et 28) en fait un signal et une métrique d'entrée.

Vérification dépôt :
- `composer.json` : aucun paquet `laravel/horizon`.
- `composer.lock` : aucun paquet `laravel/horizon`.
- `config/horizon.php` : absent.
- `vendor/laravel/horizon` : absent (vérifié par GPT self-diagnostic `config_horizon=1, vendor_horizon=1, app_cmd=1` lignes 3358-3363).
- Aucune commande artisan custom équivalente sous `app/Console/Commands/`.

Le brief interdit explicitement ce cas :
- §INVIOLABLE 5 : « Snippets shell **autorisés uniquement** s'ils citent un script déjà existant […] jamais d'invention de commande ».
- §RÈGLES DE QUALITÉ 1 : « Snippets shell autorisés **uniquement** sur scripts/commandes existants ».
- §RÈGLES DE QUALITÉ 10 : « Aucune mention de fichiers inexistants : avant de citer un chemin, garantir qu'il existe ».
- §INTERDITS : « Inventer une commande artisan ».

La cartographie pré-analysée (DISPATCH, lignes 55-59 du brief) ne mentionne pas Horizon ; elle pointait `app/Jobs/DispatchDomainEventsJob.php`, `config/queue.php`, `PreflightProductionCommand`, `SyncMetricsRecorder`, `SyncOverviewController` — tous présents. Les runbooks devaient s'en tenir là, ou ajouter `risks: ["EVIDENCE_MISSING: horizon non installé"]` et omettre les pointeurs.

GPT a tiré le même constat dans son auto-audit (`VERDICT: NEEDS_FIX`, ligne 3387) et propose la correction confinée au runbook DISPATCH + INDEX (remplacer par observation queue/Supervisor existante, ou marquer dépendance future M-14 + risk).

**Impact opérationnel réel** : un L1/DevOps en incident P0 lance la première commande du diagnostic, obtient `Command "horizon:status" is not defined`, perd un temps critique et perd confiance dans le runbook. Pour un livrable « DRAFT_SKELETON » destiné à devenir SSOT ops, c'est inacceptable même non-signé.

## AUDIT_VERDICT

`AUDIT_VERDICT: REWORK`

## REASON
Scope, structure, invariants, gates, allowlist : tout est conforme. Mais une commande artisan inexistante (`php artisan horizon:status`) et l'observabilité Horizon sont présentées comme outils ops réels dans `RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` et `RUNBOOK_INDEX_2026-04-25.md`, alors que le paquet `laravel/horizon` n'est ni dans `composer.json`/`composer.lock`, ni dans `vendor/`, ni `config/horizon.php`. Cela viole les règles « jamais d'invention de commande » / « aucune mention de fichiers inexistants » du brief, et concorde avec le `VERDICT: NEEDS_FIX` de l'auto-audit GPT. Correction locale, ne nécessite pas de gate humain.

## REWORK_ITEMS

1. **`reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md`** :
   - §1 Trigger (ligne 11) : retirer la mention « Horizon indique workers arrêtés, saturés ou backlog sur queue `high` » ou la reformuler en « Worker queue Laravel arrêté/saturé observé via le superviseur process (systemd / Supervisor) configuré pour `php artisan queue:work` (cf. `config/queue.php:16-72`) ».
   - §3 Diagnostic step 1 (lignes 41-43) : remplacer `php artisan horizon:status` par une observation/commande **existante** : `php artisan queue:failed` (Laravel core) + lecture `app/Services/Observability/SyncMetricsRecorder.php:29-63` + `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:140-157`. Ou marquer « observation superviseur process — pas de Horizon installé sur Caisse V1, observabilité queue native ».
   - §4 P1 (ligne 139) : remplacer « Horizon backlog baisse » par « `queue:failed` se vide et p95 dispatch revient sous seuil dans `SyncOverviewController` ».
   - Optionnel : ajouter §1 ou bandeau métadonnées une note « Dépendance Horizon non installée — observabilité queue via outils existants. Décision M-14 si Horizon ajouté ultérieurement ».

2. **`reports/runbooks/RUNBOOK_INDEX_2026-04-25.md`** :
   - Ligne 16 (Carte de décision) : remplacer « Horizon/backlog/failed jobs » par « Workers queue arrêtés/saturés, failed jobs, KDS/POS ne reçoivent plus events ».
   - Ligne 28 (Liens transverses, métriques) : remplacer « Horizon status, queue failed, dispatch latency p95 » par « `queue:failed`, dispatch latency p95 (`SyncOverviewController`), worker uptime supervisé ».

3. **`missions/CV1-M20-RUNBOOKS-SKELETON/output_codex.json`** :
   - Ajouter dans `risks` : `"DOC_GAP: Laravel Horizon non installé — observabilité queue native (queue:failed + SyncMetricsRecorder/SyncOverviewController) substituée. À reconsidérer si M-14 introduit Horizon."`

4. **Hors scope mais à noter pour le re-audit** : aucune autre rewrite. Une fois ces 2 fichiers corrigés et `output_codex.json` mis à jour, le runbook concerné peut être ré-audité localement (PASS attendu — tous les autres critères sont déjà PASS).

## REMEDIATION_AUDIT_CYCLE
1 (premier REWORK pour M-20). Reste 4 tentatives avant HUMAN_GATE selon `auto-remediation.mdc`.

## TERMINAL_AUDIT_OK
0 (audit en fallback Cursor session, terminal Anthropic non utilisé pour cette passe).
