# FOODKING — EXECUTION SCRIPT 3 SEMAINES (Le Cayenne GO-LIVE path)

**Date base** : 2026-05-19 (Lundi) — Day 1
**Source** :
- `reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` (verdict 32/100, 15 P0 + 15 P1)
- `reports/audit/cto-global-2026-05-16/EXECUTION_ROADMAP_V1.md` (12 sem, 388h Claude + 32h owner)
- `reports/audit/cto-global-2026-05-16/AGENT_DISPATCH_PACK.md` (22 prompts ready)
- `reports/audit/cto-global-2026-05-16/OWNER_GATES_REGISTRY.md` (5 gate types, 30 items mappés)
- `reports/audit/cto-global-2026-05-16/QUICK_WINS_EXECUTED_2026-05-16.md` (delta : P0-6 + P1-24 + P1-26 ready ; P0-8/P0-9 already-fixed)
- `reports/audit/cto-global-2026-05-16/sql-prep/P1-22-branch-status-fix.sql` (SQL ready owner)
- `CLAUDE.md` §5 LOOP + §10 decision framework

**Audience** : owner (non-senior-dev, ~3h disponibles/jour) + Claude Code sessions (orchestrateur + subagents parallèles)

**Cible** : couvrir Sprints W1-W3 du roadmap (Days 1-15 hour-by-hour), Days 16-21 réservés heal/buffer/handoff vers Week 4+ track.

---

## §0 PRE-FLIGHT CHECKLIST (à lire AVANT Day 1)

### 0.1 État actuel (snapshot 2026-05-16 EOD)

| Item | Statut | Source |
|------|--------|--------|
| P0-1 AWS rotation | **NOT DONE** (gate cascade) | verdict §5 #1 |
| P0-6 Stripe cents | **DONE in working tree** (uncommitted, rotation gate) | QUICK_WINS_EXECUTED §3.3 + §3.4 |
| P1-24 safety-check.sh | **DONE partial** (script local, CI workflow Day 6) | QUICK_WINS_EXECUTED §3.2 |
| P1-26 AGENTS.md header | **DONE in working tree** (uncommitted) | QUICK_WINS_EXECUTED §3.1 |
| P0-8 mobile allergens | **ALREADY FIXED** (commit `245e8ab57`) | QUICK_WINS_EXECUTED §"Stale" |
| P0-9 mobile promo | **ALREADY FIXED** (commit `245e8ab57`) | QUICK_WINS_EXECUTED §"Stale" |
| P1-22 SQL | **READY for owner** (file written, copy-paste) | `sql-prep/P1-22-branch-status-fix.sql` |
| All other 22 P0+P1 | **OPEN** | verdict §5 |

### 0.2 Gate qui bloque tout

**P0-1 rotation AWS = entry point unique du cascade.** Aucun commit ne peut être créé tant que les clés `AKIAYJOT77SIZHDXNYOZ` ne sont pas révoquées en console AWS IAM. Roadmap §5 Risk A : chaque commit additionnel ré-embed la fuite en historique git permanent.

### 0.3 Doctrine session Claude (rappel §6 ci-dessous)

À chaque démarrage de session Claude :
1. Lire `PROJECT_BRAIN.md` (mandatory, §11 CLAUDE.md)
2. Lire `reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` §5 (priorités jour courant)
3. Lire ce fichier EXECUTION_SCRIPT_3_WEEKS.md à la section du jour courant
4. Si tâche significative : `mcp__graphiti__search_nodes` query "foodking le cayenne" group_id=foodking
5. Suivre LOOP 8 étapes (CLAUDE.md §5)

### 0.4 Frozen-zones snapshot (à respecter chaque commit)

Avant CHAQUE commit, vérifier `bash .cursor/hooks/safety-check.sh` (post P1-24 fix : 15 fichiers protégés). Si touch frozen sans LOCK doc : STOP, escalate owner via skill `/lock-plan` (CLAUDE.md §7 + OWNER_GATES_REGISTRY.md §1).

### 0.5 Définition "fin de journée OK"

Une journée est OK si :
- BLOCKING owner-action du jour est validée (ou re-planifiée explicitement)
- Aucun commit n'a été push (push reste owner-gated CLAUDE.md §10)
- PROJECT_BRAIN.md §2/§3 mis à jour
- Aucun frozen-zone touch hors LOCK doc

---

## §1 SEMAINE 1 — SECURITY HARD UNBLOCK (Days 1-5)

**Sprint goal W1** (roadmap §3 Week 1) : zéro P0 sécurité/ops ouvert. AWS rotated, RCE patché, gitleaks bloquant CI, backup automatisé verifié restore-tested, alerting Slack+Sentry+BetterUptime live.

---

### Day 1 — Lundi 2026-05-19 — UNBLOCK + COMMIT QUICK WINS

**Theme** : owner rotate, Claude lands the 3 ready commits + SQL P1-22.

#### Morning (09:00-12:00)

- **09:00-11:00 — OWNER (2h)** : Rotation AWS console (P0-1, OG-OWNER-EXECUTE, OWNER_GATES_REGISTRY §3.1 P0-1 checklist)
  - Login `console.aws.amazon.com` → IAM → Users → identifier user porteur de `AKIAYJOT77SIZHDXNYOZ`
  - Onglet "Security credentials" → "Create access key" → copier NEW Access Key ID + Secret
  - Ouvrir `.env` LOCAL (gitignored) : remplacer `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY`
  - Test : `php artisan tinker` → `Storage::disk('s3')->put('test-rotation.txt', 'ok');` → doit retourner `true`
  - Marquer OLD key `AKIAYJOT77SIZHDXNYOZ` "Inactive" (PAS delete — garder 24h fallback)
  - Vérification : `aws iam list-access-keys --user-name <user>` montre 2 keys, l'ancienne inactive
  - DM Claude session : "rotation AWS OK, lever gate commit"
- **11:00-12:00 — CLAUDE session A (1h)** : Lit DM owner, commit les 3 quick wins prêts (QUICK_WINS_EXECUTED §3 "Prochain coup")
  - Vérifier safety-check.sh : `bash .cursor/hooks/safety-check.sh` → 0 frozen touch
  - 3 commits atomiques dans cet ordre :
    1. `git add AGENTS.md` + `git commit -m "chore(docs): AGENTS.md scope disambiguation header (P1-26)"`
    2. `git add .cursor/hooks/safety-check.sh` + `git commit -m "feat(safety): sync safety-check.sh frozen list with CLAUDE.md §7 (P1-24)"`
    3. `git add app/Http/PaymentGateways/Gateways/Stripe.php tests/Unit/Payment/StripeCentsCastTest.php` + `git commit -m "fix(stripe): round-before-cast cents to prevent truncation loss (P0-6) + regression sentinels"`
  - Run tests : `./vendor/bin/phpunit --filter StripeCentsCastTest` → attendu 3 PASS
  - **NE PAS push** (owner gate)

#### Afternoon (14:00-17:00)

- **14:00-14:30 — OWNER (30min)** : Exécuter `reports/audit/cto-global-2026-05-16/sql-prep/P1-22-branch-status-fix.sql` étapes 1→5 sur DB prod
  - Ouvrir MySQL Workbench / sequel pro / phpMyAdmin sur DB prod
  - Step 1 INSPECT : copier output (rows count par status)
  - Step 2 ROLLBACK SQL : copier output dans fichier local `rollback-P1-22.sql` (safety net)
  - Step 3 DRY RUN : noter `rows_to_update`
  - Step 4 APPLY : copier-coller le `BEGIN/UPDATE/SELECT/COMMIT` block
  - Step 5 VERIFY : confirmer plus aucune row status=1
  - DM Claude : "P1-22 SQL exécuté, X rows updated"
- **14:30-16:30 — CLAUDE session B (2h, parallèle session A)** : Prompt #1 du DISPATCH_PACK (gitleaks + composer audit CI)
  - Lancer dans session fraîche Claude : copier-coller AGENT_DISPATCH_PACK.md §2 Prompt #1 (synopsis : workflow `.github/workflows/security-scan.yml` + pre-commit hook + checklist secrets)
  - Acceptance (Prompt #1) : fixture fake AKIA → CI FAIL ; suppression → GREEN ; checklist owner ≥6 secrets listés
  - Pas de commit ce sprint (PR draft + owner gate Day 2 morning)
- **16:30-17:00 — CLAUDE session A (30min)** : Follow-up P1-22 — éditer `app/Listeners/PersistCatalogChangedToOutbox.php:39` post owner confirm
  - Une fois owner DM reçu (step 14:00) : commit `fix(catalog-outbox): remove legacy status=1 workaround post P1-22 data migration`
  - Diff : `->whereIn('status', [Status::ACTIVE, 1])` → `->where('status', Status::ACTIVE)` + remove comment lines 33-38

#### End-of-day verification

- [ ] AWS old key `AKIAYJOT77SIZHDXNYOZ` status = Inactive en console
- [ ] `php artisan tinker` → S3 put test OK avec new keys
- [ ] `git log --oneline -5` montre 4 nouveaux commits (P1-26, P1-24, P0-6, P1-22-followup)
- [ ] `./vendor/bin/phpunit --filter StripeCentsCastTest` → 3/3 PASS
- [ ] P1-22 SQL : `branches` table 0 rows avec status=1 (verify query Step 5)
- [ ] Prompt #1 PR draft existe localement (pas push, pas merge)
- [ ] PROJECT_BRAIN.md §2 mis à jour (HEAD nouveau, timestamp)

#### BLOCKING owner-action

**P0-1 rotation AWS Day 1 09:00-11:00.** Si pas fait avant 17:00 Day 1 → Day 2 STOP, escalate via §8 Escalation pattern A.

#### Gate avant Day 2

- Owner confirms rotation OK + P1-22 SQL exécuté
- Claude session A confirms 4 commits landed local + tests green

---

### Day 2 — Mardi 2026-05-20 — RCE PATCH + GITLEAKS CI MERGE

**Theme** : Prompt #2 (RCE LanguageService + Sanctum role-scoped) lancé en parallèle de owner review Prompt #1 PR.

#### Morning (09:00-12:00)

- **09:00-09:30 — OWNER (30min)** : Review + merge PR Prompt #1 (gitleaks CI)
  - Si Claude n'a pas créé PR public : owner décide (a) push branch + create PR via `gh pr create` (b) commit direct sur feature branch dev
  - Recommandation Day 2 : commit direct, PR public viendra Day 5 quand l'ensemble W1 est consolidé
  - Si commit direct : `git add .github/workflows/security-scan.yml .githooks/pre-commit reports/audit/cto-global-2026-05-16/SECRETS_TO_ROTATE.md docs/onboarding/SETUP_PRE_COMMIT.md && git commit -m "chore(security): gitleaks pre-commit + composer-audit + npm-audit CI gate (Prompt #1 / P0-1b)"`
- **09:30-12:00 — CLAUDE session A (2.5h)** : Prompt #2 — RCE LanguageService + tokens Sanctum role-scoped
  - Session fraîche, copier-coller AGENT_DISPATCH_PACK.md §2 Prompt #2 (synopsis : route `permission:settings` + abilities role-scoped + force re-login migration + CI lint)
  - **Critique** : OG-RED-TEAM-FIRST (OWNER_GATES_REGISTRY §3.1 P0-2). Claude dispatch Architect + Security subagents en parallèle, puis Implementer TDD-first.
  - Tests RED requis AVANT code (DISPATCH_PACK Prompt #2 TDD section) :
    - `tests/Feature/Security/LanguageServiceRceTest.php`
    - `tests/Feature/Security/SanctumAbilityTest.php`
    - `tests/Feature/Security/TokenWildcardBanTest.php`
  - Migration `database/migrations/2026_05_20_revoke_wildcard_tokens.php`
  - Pas de commit avant tests GREEN

#### Afternoon (14:00-17:00)

- **14:00-16:00 — CLAUDE session A (2h)** : Prompt #2 — Implementer subagent (séquentiel après tests RED)
  - Modifications : `routes/api.php:486` middleware, `app/Services/LanguageService.php:198-220` whitelist + reject `<?`, `app/Http/Controllers/Auth/LoginController.php:87-91` + `app/Http/Controllers/Frontend/Auth/GuestSignupController.php:140` abilities role-scoped via `App\Support\TokenAbilityResolver`
  - Helper créé : `app/Support/TokenAbilityResolver.php`
  - Doc : `docs/security/SANCTUM_ABILITIES_MATRIX.md`
  - Run : `./vendor/bin/phpunit --filter Security` → 3 nouveaux tests GREEN + regression Sanctum suite passe
- **16:00-17:00 — CLAUDE session A (1h)** : RED-team dispatch (Template A du DISPATCH_PACK §6-A) sur scope Prompt #2
  - Brief subagent RED : "Patch couvre LanguageService::edit. Cherche d'autres file-write paths user-controllable (PdfController, ExcelExportService, MediaController). Whitelist realpath bypass via symlink/null-byte ?"
  - Synthèse RED dans `reports/audit/cto-global-2026-05-16/red-team/P0-2-RED.md`
  - Si RED P0 → loop heal (CLAUDE.md §5 étape 7, max 3 loops)
  - Si RED clean → commit `fix(security): patch RCE LanguageService + Sanctum abilities role-scoped + force re-login (Prompt #2 / P0-2)`

#### End-of-day verification

- [ ] 3 tests Security PASS : `./vendor/bin/phpunit --filter Security`
- [ ] `grep -rn "createToken(.*\['\*'\]" app/` → 0 hits
- [ ] Migration `revoke_wildcard_tokens` listée : `php artisan migrate:status` montre la migration pending OU run
- [ ] RED-team report existe : `reports/audit/cto-global-2026-05-16/red-team/P0-2-RED.md`
- [ ] PR Prompt #1 mergée (commit security-scan.yml dans `git log`)
- [ ] PROJECT_BRAIN.md §2/§3 updated

#### BLOCKING owner-action

**Owner décide merge Prompt #1 PR (09:00-09:30).** Si pas fait : Claude commit le dossier mais ne push pas, Day 3 démarre quand même (CI gate viendra Day 6+).

#### Gate avant Day 3

- Tests Security 3/3 GREEN + RED-team clean
- Migration revoke_wildcard_tokens à run sur staging (validation Day 3 morning)

---

### Day 3 — Mercredi 2026-05-21 — RCE STAGING VALIDATION + BACKUPS KICKOFF

**Theme** : Owner valide RCE patch sur staging + Claude démarre Prompt #3 backups.

#### Morning (09:00-12:00)

- **09:00-10:00 — OWNER (1h)** : Validation staging post-RCE patch
  - Deploy branch RCE patch sur staging (manuel SSH ou pipeline)
  - Run `php artisan migrate --force` (joue revoke_wildcard_tokens) → tous staff doivent se re-login
  - Test manuel : login admin → ouvrir Tinker → `User::find(<admin>)->tokens` → vérifier abilities pas `["*"]`
  - Test manuel : kiosk token essaie `/api/admin/items` → attendu 403
  - DM Claude : "RCE patch staging validated, prêt pour merge prod"
- **10:00-12:00 — CLAUDE session A (2h)** : Prompt #3 — Backups spatie/laravel-backup
  - Session fraîche, copier AGENT_DISPATCH_PACK.md §2 Prompt #3 (synopsis : composer require + config quotidien 03:00 + S3 destination + GPG + retention 6 ans + bin/restore.sh + runbook DR signed)
  - **Critique** : OG-OWNER-EXECUTE (OWNER_GATES_REGISTRY §3.1 P0-4). Claude écrit le code, owner crée bucket S3 + IAM `foodking-backup-writer` + GPG key (Day 4 morning).
  - Code Claude :
    - `composer require spatie/laravel-backup`
    - `config/backup.php` : daily 03:00, MySQL + storage/app/fiscal/ + storage/app/audit/, retention 7d/4w/12m/6y
    - `bin/restore.sh` : `--dump=<filename>` → download S3 + GPG decrypt + mysql restore + outbox replay
    - `docs/runbooks/RUNBOOK_DR_RESTORE.md` SIGNED template (placeholder pour owner signature Day 5)
    - `app/Console/Kernel.php` : `$schedule->command('backup:run')->dailyAt('03:00')`
    - `tests/Feature/Operations/BackupCronTest.php`

#### Afternoon (14:00-17:00)

- **14:00-15:00 — CLAUDE session A (1h)** : Prompt #3 — RED-team SRE subagent
  - Brief : "Bucket S3 a-t-il BlockPublicAccess + object-lock GOVERNANCE 6 ans ? IAM foodking-backup-writer read-list backups ? GPG passphrase rotation procedure ? IAM:DeleteObject + object-lock = vraiment immutable ?"
- **15:00-17:00 — CLAUDE session B (2h, parallèle)** : Prompt #4 — Alerting Slack + Sentry + BetterUptime
  - Session fraîche, copier AGENT_DISPATCH_PACK.md §2 Prompt #4 (synopsis : `composer require sentry/sentry-laravel` + `npm i @sentry/vue` + Slack channel config + BetterUptime monitor `/health/live` 60s + PII safe)
  - **Critique** : OG-OWNER-EXECUTE (OWNER_GATES_REGISTRY §3.1 P0-5). Code écrit, owner crée comptes (Day 4 morning).
  - Code Claude :
    - `config/sentry.php` + DSN dans `.env.example`
    - Init `resources/js/app.js` Sentry Vue SDK
    - `config/logging.php` : channel slack `level=error`
    - Doc `docs/operations/ALERTING_SETUP.md` step-by-step
  - Pas de commit (owner gate sur env vars)

#### End-of-day verification

- [ ] Staging RCE validation OK (owner DM confirmed)
- [ ] Backup composer package installé : `composer show spatie/laravel-backup`
- [ ] `config/backup.php` créé + visible
- [ ] `bin/restore.sh` créé + `chmod +x` + shellcheck clean
- [ ] Runbook DR_RESTORE.md template existe
- [ ] Sentry config + doc setup ALERTING écrits
- [ ] Tests backup cron : `./vendor/bin/phpunit --filter BackupCronTest` → PASS
- [ ] Pas de commit RCE-related encore push (owner valide merge prod Day 4)

#### BLOCKING owner-action

**Owner valide RCE staging (09:00-10:00).** Si KO → Claude heal loop max 3, sinon escalate. Si OK : merge prod planifié Day 4.

#### Gate avant Day 4

- Backup code complet, runbook template prêt pour owner setup
- Alerting code complet, doc setup prêt pour owner accounts

---

### Day 4 — Jeudi 2026-05-22 — INFRA SETUP OWNER + RUNBOOKS START

**Theme** : Owner crée comptes externes (S3, Sentry, BetterUptime, Slack) + Claude lance Prompt #9 runbooks.

#### Morning (09:00-12:00)

- **09:00-10:00 — OWNER (1h)** : Création comptes externes
  - **AWS S3** : créer bucket `foodking-backups-prod` (region eu-west-3 ou eu-west-1) + activer Object Lock GOVERNANCE 6 ans + Block Public Access
  - **IAM** : créer user `foodking-backup-writer` + policy minimale (`s3:PutObject` + `s3:GetObject` sur préfixe `foodking-backups-prod/*`) + access keys
  - **GPG** : `gpg --gen-key` → nom `FoodKing Backup` → export `BACKUP_ARCHIVE_PASSWORD` strong → safe storage password manager
  - **Sentry** : compte gratuit https://sentry.io → projet `foodking-prod` (Laravel + Vue) → copier DSN
  - **BetterUptime** : compte gratuit https://betteruptime.com → monitor `https://staging.foodking.fr/health/live` interval 60s → notify Telegram/SMS owner
  - **Slack** : workspace exists ? si oui channel `#foodking-prod-alerts` ; sinon créer workspace + channel + incoming webhook URL
  - DM Claude : "comptes prêts, env vars à pousser : BACKUP_S3_BUCKET, BACKUP_S3_KEY_ID, BACKUP_S3_SECRET, BACKUP_ARCHIVE_PASSWORD, SENTRY_LARAVEL_DSN, LOG_SLACK_WEBHOOK_URL, BETTERUPTIME_HEARTBEAT_URL"
- **10:00-12:00 — CLAUDE session A (2h)** : Prompt #9 — 4 runbooks critiques rewriting
  - Session fraîche, copier AGENT_DISPATCH_PACK.md §3 Prompt #9 (synopsis : FISCAL_SEQUENCE_BREAK + KIOSK_NETWORK_LOSS + OUTBOX_BLOCKED + ROLLBACK_CANARY avec commandes copy-paste, owner play-through staging Day 5+6)
  - Grep `app/Console/Commands/` pour identifier commandes Artisan utiles
  - Pour chaque runbook : section Symptômes (3) + Causes (3) + Steps numbered (≤8) + Validation post-fix (2-3)
  - Header restera `DRAFT_SKELETON_NOT_SIGNED` jusqu'au owner play-through

#### Afternoon (14:00-17:00)

- **14:00-15:00 — OWNER (1h)** : Push env vars sur staging + tester alerting
  - Add env vars dans staging `.env` (NE PAS commit)
  - Redéployer staging
  - Test Sentry : `php artisan tinker` → `Log::error('test-sentry-from-tinker')` → vérifier event apparaît Sentry UI
  - Test Slack : `Log::channel('slack')->error('test-slack')` → vérifier message arrive channel
  - Test BetterUptime : stopper staging 3 min → vérifier alerte SMS/email reçue
  - DM Claude : "alerting live"
- **15:00-17:00 — CLAUDE session A (2h)** : Prompt #3 + #4 finalisation
  - Commit Prompt #3 : `feat(backup): spatie/laravel-backup quotidien S3 GPG retention 6y + DR restore script (Prompt #3 / P0-4)`
  - Commit Prompt #4 : `feat(observability): Sentry Laravel+Vue + Slack channel error + doc alerting setup (Prompt #4 / P0-5)`
  - Pas de push (owner gate)

#### End-of-day verification

- [ ] Bucket S3 `foodking-backups-prod` visible AWS console + object-lock badge
- [ ] IAM user `foodking-backup-writer` créé
- [ ] GPG passphrase en password manager owner
- [ ] Sentry projet créé + DSN obtenu
- [ ] BetterUptime monitor visible + alerte test reçue
- [ ] Slack channel + webhook URL fonctionnels
- [ ] 4 runbooks réécrits avec commandes Artisan (toujours `DRAFT`)
- [ ] 2 commits Claude landed (backup + alerting)

#### BLOCKING owner-action

**Création des 5 comptes externes (09:00-10:00).** Si pas fait : Day 5 DR drill impossible, runbook play-through fail. Si slip : décaler Day 5 morning.

#### Gate avant Day 5

- Owner DM "alerting live" reçu
- Runbooks DRAFT prêts pour play-through

---

### Day 5 — Vendredi 2026-05-23 — DR DRILL + RUNBOOK PLAY 1/2 + W1 CONSOLIDATION

**Theme** : Owner exécute DR drill staging (P0-4 acceptance) + premier runbook play-through + W1 review.

#### Morning (09:00-12:00)

- **09:00-11:00 — OWNER (2h)** : DR drill staging
  - Suit `docs/runbooks/RUNBOOK_DR_RESTORE.md` (créé Day 3 par Claude)
  - Step 1 : `php artisan backup:run` sur staging → vérifier backup apparaît dans S3
  - Step 2 : drop staging `orders` + `order_items` (controlled chaos)
  - Step 3 : `bin/restore.sh --dump=latest` → mesurer timing
  - Step 4 : vérifier compteurs `orders` pre/post identique
  - Step 5 : test outbox replay : `php artisan outbox:rescue --since=1h`
  - Step 6 : close Z report sur branch test → chain HMAC intact
  - Documenter `reports/dr-drill/DR_DRILL_2026-05-23.md` (timing chaque étape)
  - Si OK : owner signe runbook DR_RESTORE.md header `Status: SIGNED_BY_OWNER_2026-05-23`
- **11:00-12:00 — CLAUDE session A (1h)** : Prompt #2 + #3 + #4 push consolidation (si owner décide)
  - Owner décide : push branch + create PR public OU continuer accumulation feature branch
  - Recommandation : 1 PR consolidée fin W1 avec tag `W1-SECURITY-OPS`

#### Afternoon (14:00-17:00)

- **14:00-16:00 — OWNER (2h)** : Runbook play-through 1/2 — FISCAL_SEQUENCE_BREAK
  - Lit `docs/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK.md` (rewritten Day 4)
  - Joue chaque step en staging (forcer un gap : `UPDATE fiscal_sequences SET last_no=X+5` puis créer order normale → break)
  - Mesure timing par step
  - Identifie blocages (commande manquante ? doc ambiguë ?)
  - DM Claude si itération needed
  - Si OK : sign header `Status: SIGNED_BY_OWNER_2026-05-23`
- **16:00-17:00 — CLAUDE session A (1h)** : Mise à jour PROJECT_BRAIN.md §2/§3/§4 + retro W1
  - §2 CURRENT STATE : Sprint W1 done, items closed P0-1 (rotation), P0-2 (RCE+Sanctum), P0-4 (backup), P0-5 (alerting), P0-6 (Stripe — already commit Day 1), P1-22 (branch.status), P1-24 (safety-check), P1-26 (AGENTS header)
  - §3 LAST DONE : recap 5 jours + commits list
  - §4 NEXT TO DO : Sprint W2 Day 6+ (runbooks 2-4 + deploy.sh + E2E bloquant + POS cash-trail + frozen CI gate)
  - Push Graphiti episode `foodking` group : "Sprint W1 closed 2026-05-23 — security unblock complete, ready W2 ops"

#### End-of-day verification

- [ ] DR drill report `reports/dr-drill/DR_DRILL_2026-05-23.md` existe + timing < 30 min restore
- [ ] Runbook DR_RESTORE.md `Status: SIGNED_BY_OWNER_2026-05-23`
- [ ] Runbook FISCAL_SEQUENCE_BREAK.md `Status: SIGNED_BY_OWNER_2026-05-23`
- [ ] PROJECT_BRAIN.md updated
- [ ] Graphiti episode pushed
- [ ] Status W1 commits : 7-8 commits landed local (P0-1 confirmation via env, P0-2, P0-4, P0-5, P0-6, P1-22, P1-22-followup, P1-24, P1-26)
- [ ] Optionnel : PR `W1-SECURITY-OPS` créée et mergée prod (si owner OK)

#### BLOCKING owner-action

**DR drill staging (09:00-11:00).** Si fail : RCA + heal loop. Si owner manque temps : décaler à Day 8 (Sprint W2 absorbe).

#### Gate avant Day 6 / Week 2

- Sprint W1 sortie criteria (roadmap §3 Week 1) : zéro P0 sécu/ops ouvert. Vérifier :
  - [x] AWS rotated + gitleaks CI live
  - [x] RCE patched + Sanctum role-scoped
  - [x] Backup quotidien + DR drill test
  - [x] Alerting Slack+Sentry+BetterUptime live
  - [x] Stripe cents fix (commit Day 1)
  - [ ] Frozen-zone CI gate (Day 6+ Prompt #13)

---

## §2 SEMAINE 2 — HYGIENE + OPS GATES (Days 6-10)

**Sprint goal W2** (roadmap §3 Week 2) : owner peut opérer incidents sans dev-senior. CI gate réel.

---

### Day 6 — Lundi 2026-05-26 — RUNBOOKS 2-3 + FROZEN ZONE CI GATE

#### Morning block

- **OWNER (2h)** : Runbook play-through 2/4 — KIOSK_NETWORK_LOSS staging
  - Simuler perte réseau kiosk (firewall block + reconnect après 5 min)
  - Lit runbook + joue steps
  - Sign header si OK
- **CLAUDE session A (parallèle)** : Prompt #13 — Frozen-zones CI gate réel
  - Copier AGENT_DISPATCH_PACK.md §4 Prompt #13 (synopsis : `config/frozen_zones.php` SSOT + scripts/check-frozen-zones.sh + `.github/workflows/frozen-zones-gate.yml` + cumulative diff ratchet baseline)
  - **Critique** : couvre P1-24 full scope (le DAY 1 commit était partiel — script local only, manque CI workflow)
  - Test fixture : fake PR touch `ZReportService.php` sans LOCK → CI rouge
  - Resync `CLAUDE.md §7` + `memory/reference_frozen_zones.md` pour pointer config PHP

#### Afternoon block

- **OWNER (1h)** : Runbook play-through 3/4 — OUTBOX_BLOCKED staging
  - Forcer outbox stall (stopper queue:work 30 min) → `php artisan outbox:rescue --since=1h`
  - Sign si OK
- **CLAUDE session A (2h)** : Prompt #13 finalisation + commit
  - RED-team subagent : "LOCK doc falsifiable via Claude commit ? Ratchet bypass via amend ? Regex robuste contre rename ?"
  - Commit : `feat(safety): frozen-zones CI gate + cumulative diff ratchet + config SSOT (Prompt #13 / P1-24 full)`

#### End-of-day verification

- [ ] 2 runbooks SIGNED additionnels (KIOSK_NETWORK_LOSS, OUTBOX_BLOCKED)
- [ ] `config/frozen_zones.php` créé + script + workflow + doc
- [ ] CI fixture rouge → vert confirmé

#### BLOCKING owner-action

Runbook play-through 2/4 + 3/4 (3h total). Si manque temps : 1 par jour étendu jusqu'à Day 8.

---

### Day 7 — Mardi 2026-05-27 — DEPLOY SCRIPTS + RUNBOOK 4/4

#### Morning block

- **CLAUDE session A (3h)** : Prompt #15 — `bin/deploy.sh` + `bin/rollback.sh` + supervisor units
  - Copier AGENT_DISPATCH_PACK.md §4 Prompt #15 (synopsis : atomic symlink flip + composer install + npm run prod + migrate --force + supervisor queue+schedule + drill staging)
  - **Critique** : couvre P1-25. Owner exécute drill Day 8.
  - Scripts dans `bin/` : `deploy.sh`, `rollback.sh`, `supervisor/foodking-queue.conf`, `supervisor/foodking-schedule.conf`
  - Update `docs/runbooks/RUNBOOK_ROLLBACK_CANARY.md` avec commandes `bin/rollback.sh`
  - shellcheck clean

#### Afternoon block

- **OWNER (1h)** : Runbook play-through 4/4 — ROLLBACK_CANARY (premier deploy drill avec bin/deploy.sh)
  - Deploy v1 staging → smoke test → deploy v2 → smoke test → rollback v1 → smoke test
  - Documenter `reports/deploy-drill/DRILL_2026-05-27.md`
  - Sign header runbook
- **CLAUDE session A (2h)** : Commit Prompt #15 + cheatsheet plastifiée
  - Commit : `feat(ops): bin/deploy.sh + bin/rollback.sh + supervisor units + canary rollback runbook signed (Prompt #15 / P1-25 + P0-10 runbook 4/4)`
  - Générer `docs/operations/CHEATSHEET_LE_CAYENNE.md` 1 page recto-verso (4 runbooks condensés)
  - Convert MD → PDF (owner imprime, plastifie)

#### End-of-day verification

- [ ] bin/deploy.sh + bin/rollback.sh shellcheck clean
- [ ] Runbook ROLLBACK_CANARY SIGNED + drill report
- [ ] Cheatsheet 1 page produit
- [ ] 4/4 runbooks SIGNED (P0-10 complete)

#### BLOCKING owner-action

Drill deploy/rollback staging (afternoon 1h). Si fail : RCA + heal.

---

### Day 8 — Mercredi 2026-05-28 — E2E BLOQUANT CI + STRESS MYSQL

#### Morning block

- **CLAUDE session A (3h)** : Prompt #11 — E2E bloquant + stress MySQL matrix
  - Copier AGENT_DISPATCH_PACK.md §3 Prompt #11 (synopsis : drop e2e-required label + drop continue-on-error + smoke pack 5 specs + stress MySQL CI matrix + remove markTestIncomplete)
  - **Critique** : couvre P0-15 + P1-29
  - Audit smoke pack actuel : kiosk happy / POS cash / KDS bump / OSS update / fiscal Z-close
  - Fix flaky : run chaque spec 10× local, identifier flaky, fix waits/selectors/fixtures
  - Modifier `.github/workflows/playwright.yml` : drop `continue-on-error: true` + drop label opt-in
  - Modifier `.github/workflows/ci-sync-rupture-harness.yml` : ajouter MySQL 8.0 service container + run `php artisan foodking:e2e:stress`

#### Afternoon block

- **CLAUDE session A (3h)** : Test failure simulation + commit
  - Push fake commit avec `assert false` dans smoke spec → vérifier CI rouge bloquant merge
  - Revert fake commit
  - Commit Prompt #11 : `test(e2e): bloquant CI + smoke pack 5 specs + stress MySQL matrix (Prompt #11 / P0-15 + P1-29)`
  - RED-team subagent : "Smoke pack couvre vraiment fiscal Z-close ? Stress test = vrai concurrent ou théâtral ? Opt-out caché ?"

#### End-of-day verification

- [ ] `.github/workflows/playwright.yml` : aucun `continue-on-error: true`, aucun `if: contains(github.event.pull_request.labels.*.name, 'e2e-required')`
- [ ] Smoke pack 5/5 GREEN sur 10 runs consécutifs
- [ ] Stress MySQL CI <5 min

#### BLOCKING owner-action

Aucun (Claude full-day). Owner peut réviser PR fin de journée.

---

### Day 9 — Jeudi 2026-05-29 — POS CASH-TRAIL E2E + IDOR P0-3

#### Morning block

- **CLAUDE session A (3h)** : Prompt #10 — POS cash-trail E2E test
  - Copier AGENT_DISPATCH_PACK.md §3 Prompt #10 (synopsis : feature test POS direct cash → CashMovement → CashDrawerSession → Z-report → variance gate)
  - **Critique** : couvre P0-11
  - `tests/Feature/POS/PosDirectCashTrailE2ETest.php` ≥80 LOC
  - 3 scenarios : single, multi, variance-block
  - RED-team subagent

#### Afternoon block

- **CLAUDE session A (3h)** : Patch P0-3 IDOR PosOrderController (quick win subset of Prompt #19)
  - Couvre P0-3 quick win : audit `withoutGlobalScope(BranchScope::class)` dans `PosOrderController::show` SPECIFIQUEMENT (pas les 39 sites — ça c'est Prompt #19 Week 3)
  - Ajouter assertion : `abort_unless(auth()->user()->branch_id === $order->branch_id || auth()->user()->isSuperAdmin(), 403)`
  - Tests régression : cashier branch=2 → order branch=3 → 403
  - Commit : `fix(security): scope assertion PosOrderController IDOR cross-branch (P0-3 quick win, Prompt #19 reste W3)`

#### End-of-day verification

- [ ] PosDirectCashTrailE2ETest 3 scenarios GREEN
- [ ] PosOrderController IDOR test GREEN
- [ ] 2 commits landed

#### BLOCKING owner-action

Aucun.

---

### Day 10 — Vendredi 2026-05-30 — W2 CONSOLIDATION + PR PUBLIC

#### Morning block

- **OWNER (2h)** : Review tout W1+W2 commits accumulés
  - Read diffs : `git log --oneline 245e8ab57..HEAD` (depuis dernière baseline avant audit)
  - Decide : create PR public `gh pr create --title "W1+W2 Security + Ops hardening" --body "..."`
  - OU continuer accumulation feature branch jusqu'à fin W3

#### Afternoon block

- **CLAUDE session A (3h)** : W2 retro + W3 setup
  - Update PROJECT_BRAIN.md §2/§3/§4
  - Push Graphiti episode "Sprint W2 closed 2026-05-30"
  - Préparer notes session pour Day 11 (Order collapse LOCK kickoff)
  - Lire `memory/project_kds_ultra_plan_2026-05-11.md` pour W3 KDS heal context
  - Préparer prompts Day 11-15 dans head : Prompts #6, #7, #17, #16

#### End-of-day verification

- [ ] W2 sortie criteria (roadmap §3 Week 2) :
  - [x] 4 runbooks SIGNED
  - [x] bin/deploy.sh + bin/rollback.sh + drill
  - [x] E2E bloquant CI
  - [x] POS cash-trail E2E
  - [x] IDOR P0-3 quick fix
  - [x] Frozen-zones CI gate
- [ ] PR public mergée OU branch state OK pour W3

#### BLOCKING owner-action

Review W1+W2 (morning 2h). Si owner skip : Claude continue W3, mais accumule risk de devoir tout reviewer Day 21.

---

## §3 SEMAINE 3 — ARCHITECTURE P0 CRITICAL PATH (Days 11-15)

**Sprint goal W3** (roadmap §3 Week 3) : LOCK docs Order collapse + POS wizard signed, OrderStateMachine sole writer enforce, KDS heal kickoff.

**IMPORTANT** : Order collapse + POS wizard surgical patch = **PLAN + LOCK ONLY** ce sprint. Exécution = sprint dédié Week 4+ (4 semaines Order collapse selon roadmap §3 Week 3-6).

---

### Day 11 — Lundi 2026-06-02 — LOCK ORDER COLLAPSE KICKOFF

#### Morning block

- **CLAUDE session A (3h)** : Prompt #6 — Order/FrontendOrder collapse LOCK doc + plan
  - Copier AGENT_DISPATCH_PACK.md §3 Prompt #6 (synopsis : invoque skill `/lock-plan` → `LOCK_ORDER_COLLAPSE_2026-06-02.md` + 3 subagents Architect+DBA+Security parallèles read-only + plan exécution séquentielle a→f + rollback)
  - **Critique** : OG-LOCK-DOC (OWNER_GATES_REGISTRY §3.1 P0-7). PLAN ONLY ce sprint, EXECUTION starts Week 4.
  - Invoque skill `/lock-plan` : files `[Order.php, FrontendOrder.php, AppServiceProvider.php, OrderService.php]`, reason "collapse dual model fillable divergence, single observer attach NF525 critical"
  - Architect cartographie `grep -rn "FrontendOrder::" app/` (attendu 10-20 callers)
  - DBA confirme no schema migration data
  - Security audit each caller

#### Afternoon block

- **CLAUDE session A (3h)** : RED-team adversarial sur plan + livraison à owner
  - Brief RED : "Alias `class FrontendOrder extends Order` peut créer doublons ? Migrations dropent FrontendOrder cast ? composition_snapshot intact ? Idempotency middleware fonctionne Order pur ?"
  - Synthèse `reports/audit/cto-global-2026-05-16/red-team/P0-7-RED.md`
  - Livraison : `LOCK_ORDER_COLLAPSE_2026-06-02.md` + `plans/MASTER_ORDER_COLLAPSE_2026-06-02.md` posés repo
  - DM owner : "LOCK doc Order collapse prêt review, attendre signature pour démarrer exécution (4 sem)"
  - **Aucun commit code** ce jour (PLAN ONLY)

#### End-of-day verification

- [ ] `LOCK_ORDER_COLLAPSE_2026-06-02.md` existe + owner sign-off slot
- [ ] `plans/MASTER_ORDER_COLLAPSE_2026-06-02.md` ≥3 pages avec file:line
- [ ] RED-team report existe
- [ ] 20+ callers FrontendOrder identifiés
- [ ] Aucun commit code

#### BLOCKING owner-action

Aucun ce jour (owner review LOCK doc Day 12 ou tranquille jusqu'à Day 14).

---

### Day 12 — Mardi 2026-06-03 — ORDER STATE MACHINE SOLE WRITER

#### Morning block

- **CLAUDE session A (3h)** : Prompt #7 — OrderStateMachine seul writer + 5 mutations OrderService
  - Copier AGENT_DISPATCH_PACK.md §3 Prompt #7 (synopsis : Architect + Security parallèles audit 5 sites OrderService.php lignes 1530/1609/1714/1820/1907 + Implementer TDD + CI lint grep state machine)
  - **Critique** : OG-LOCK-DOC pour OrderService.php (OWNER_GATES_REGISTRY §3.1 P0-12). MAIS si scope = appeler `apply()` existant depuis callers, pas modifier OrderStateMachine.php → frozen-zone NOT touched.
  - Architect cartographie : 5 sites + next_status + actor + reason
  - Tests RED écrits AVANT code : `tests/Unit/Domain/Order/OrderStateMachineInvariantsTest.php` (5 tests, 1 par site)

#### Afternoon block

- **CLAUDE session A (3h)** : Refactor 5 sites + CI lint workflow
  - Implementer subagent séquentiel (write conflicts si parallèle)
  - 5 sites : `$order->status = X; $order->save()` → `OrderStateMachine::apply($order, OrderStatus::X, auth()->user(), '<reason>')`
  - CI lint : `.github/workflows/state-machine-guard.yml` qui run `grep -rn '->status\s*=' app/Services/ app/Http/Controllers/` → fail si >0
  - Tests GREEN : `./vendor/bin/phpunit --filter OrderStateMachine`
  - RED-team subagent : "5 mutations ont bon actor ? reason useful ? Eloquent update/fill/observers indirects ? jobs queued forceFill ?"
  - Commit : `refactor(domain): OrderStateMachine apply() sole writer + CI lint guard (Prompt #7 / P0-12 + P1-17)`

#### End-of-day verification

- [ ] `grep -rn '->status\s*=' app/Services/ app/Http/Controllers/` → 0 hits
- [ ] 5 tests OrderStateMachineInvariantsTest GREEN
- [ ] CI workflow state-machine-guard actif
- [ ] 1 commit landed

#### BLOCKING owner-action

Aucun (sauf si LOCK doc P0-12 requis — confirmer Day 11 plan que scope = callers only).

---

### Day 13 — Mercredi 2026-06-04 — KDS HEAL KICKOFF

#### Morning block

- **CLAUDE session A (3h)** : Prompt #16 — KDS UX 8 P0 heal kickoff
  - Copier AGENT_DISPATCH_PACK.md §4 Prompt #16 (synopsis : Designer + Implementer + QA visual subagents + 8 P0 audit 2026-05-11 + DS V5 light flat + Vitest tests + Playwright visual gate + heal max 3 loops)
  - **Critique** : OG-RED-TEAM-FIRST (OWNER_GATES_REGISTRY §3.1 P1-20). Hors frozen-zone (KDS Vue 2545 LOC pas dans §7).
  - Lit `memory/project_kds_ultra_plan_2026-05-11.md` (contrat canonique KdsOrder, helper kdsCustomization)
  - Tests RED 8 (1 par P0) : accordéon ouvert, banners single-stack, bump ≥44px, allergenModal exists, contrast ≥4.5:1, i18n non-raw
  - Implementer subagent dispatch

#### Afternoon block

- **CLAUDE session A (3h)** : KDS implement + i18n keys + visual gate
  - Implement dans `resources/js/components/frontend/kds/KitchenDisplaySystemComponent.vue` + tokens CSS
  - i18n : 18 raw labels FR → `lang/fr/kds.php` + `lang/en/kds.php` + `lang/ar/kds.php`
  - Visual gate Playwright : capture `/kds` idle + rush 5 orders + bump → Read screenshots → analyser layout/contrast/touch/i18n
  - Heal max 3 loops si visual fail
  - RED-team subagent : "Capture vraiment `/kds` réel port 8000 ? Empty state cohérent ? RTL AR ? Touch 44px réel ou juste padding ?"
  - Commit si GREEN : `feat(kds): UX heal 8 P0 — accordion+banners+44px+contrast+i18n (Prompt #16 / P1-20)`

#### End-of-day verification

- [ ] 8 Vitest tests GREEN
- [ ] 0 raw label FR visible (`Label.X`, `kds.foo`)
- [ ] Visual analyse `reports/visual/kds-heal-2026-06-04.md`
- [ ] Heal loops ≤3
- [ ] 1 commit landed

#### BLOCKING owner-action

Aucun (sauf si visual fail 3 loops → escalate owner).

---

### Day 14 — Jeudi 2026-06-05 — LOCK POS WIZARD KICKOFF + OWNER REVIEW ORDER LOCK

#### Morning block

- **OWNER (1h)** : Review `LOCK_ORDER_COLLAPSE_2026-06-02.md` (livré Day 11)
  - Read scope (files + LOC budget) + justification + rollback + tests plan
  - Si questions : DM Claude pour clarifications
  - Si OK : signer (ajouter `Status: SIGNED_BY_OWNER_2026-06-05` au header + signature dans section dédiée)
  - Si re-work : préciser quoi → Claude itère
  - DM Claude : "LOCK Order signé" OU "demande revoir X"
- **CLAUDE session A (2h)** : Prompt #17 — POS Vanilla wizard surgical patch LOCK doc + plan
  - Copier AGENT_DISPATCH_PACK.md §4 Prompt #17 (synopsis : invoque `/lock-plan` → `LOCK_POS_WIZARD_SURGICAL_2026-06-05.md` + Architect+Designer+Security parallèles + plan ≤200 LOC diff + ARIA + 44px + var(--pos-v5-brand-red) + i18n keys + tests Vitest)
  - **Critique** : OG-LOCK-DOC strict (OWNER_GATES_REGISTRY §3.1 P1-21). `public/js/pos-wizard.js` + `public/css/pos-wizard.css` + `resources/views/admin-pos-v4.blade.php` = frozen-zone explicite CLAUDE.md §7.
  - Architect cartographie chaque `<div onClick>` → `<button role="button" tabindex=0>`
  - Designer mappe palette → var(--pos-v5-brand-red)
  - Scope strict ≤200 LOC, structure intacte (refactor INTERDIT)

#### Afternoon block

- **CLAUDE session A (3h)** : POS wizard plan finalize + RED-team + livraison
  - RED-team : "Surgical préserve logique wizard (no behavior change) ? ARIA sur `<div>` vs vrai `<button>` (focus + Enter) ? Token CSS pas cascade legacy override ? i18n keys cassent Blade rendering ?"
  - Livraison : `LOCK_POS_WIZARD_SURGICAL_2026-06-05.md` + `plans/MASTER_POS_WIZARD_SURGICAL_2026-06-05.md`
  - DM owner : "LOCK POS wizard prêt review"
  - **Aucun commit code** (PLAN ONLY)

#### End-of-day verification

- [ ] LOCK Order collapse signé owner OU itération en cours
- [ ] LOCK POS wizard livré
- [ ] Plan POS ≥2 pages avec file:line
- [ ] ≤200 LOC budget respecté
- [ ] RED-team report POS

#### BLOCKING owner-action

**Owner review LOCK Order (morning 1h).** Si pas signé Day 14 → Order collapse exécution Week 4 décalée d'autant. Acceptable de slip à Day 15-16 si owner busy.

---

### Day 15 — Vendredi 2026-06-06 — W3 CONSOLIDATION + W4 KICKOFF PREP

#### Morning block

- **OWNER (1h)** : Review LOCK POS wizard (livré Day 14)
  - Read scope + justification + tests plan + rollback
  - Si OK : sign header
  - DM Claude
- **CLAUDE session A (2h)** : Prompt #19 quick start — audit 39 `withoutGlobalScope` (kickoff, complétion Week 4-5)
  - Copier AGENT_DISPATCH_PACK.md §5 Prompt #19 (synopsis : `grep -rn "withoutGlobalScope(BranchScope::class)"` → 39 sites + categorize a/b/c + assertions sur (c) + tests cross-tenant)
  - Day 15 scope : audit + categorize (a/b/c) ONLY. Implementation = Week 4-5.
  - Doc `docs/security/BRANCH_SCOPE_EXCEPTIONS.md` baseline

#### Afternoon block

- **CLAUDE session A (3h)** : W3 retro + W4 prep + handoff prep
  - Update PROJECT_BRAIN.md §2/§3/§4 :
    - §2 : Sprint W3 done. P0-12 OrderStateMachine sole writer enforce + P1-20 KDS heal + LOCK Order collapse signed + LOCK POS wizard signed.
    - §3 : recap W3.
    - §4 NEXT TO DO : Week 4 = Order collapse implementation (4 sem effort) + POS wizard surgical impl + 39 withoutGlobalScope finalisation + FormRequest authz top-20 (Prompt #14) + 23 assertTrue(true) start (Prompt #20)
  - Push Graphiti episode "Sprint W3 closed 2026-06-06 — architecture P0 critical path established, LOCK docs signed, Week 4 implementation track ready"
  - Préparer handoff doc pour Week 4 session (use skill `handoff-cursor` OU rédiger inline)

#### End-of-day verification

- [ ] LOCK POS wizard signé owner
- [ ] `docs/security/BRANCH_SCOPE_EXCEPTIONS.md` baseline 39 sites
- [ ] PROJECT_BRAIN.md updated
- [ ] Graphiti episode pushed
- [ ] Handoff Week 4 prêt
- [ ] W3 sortie criteria : LOCK Order signed + LOCK POS signed + StateMachine sole writer + KDS heal merged

#### BLOCKING owner-action

Sign LOCK POS wizard (morning 1h). Si slip : décaler exécution POS Week 4.

---

## §4 DAYS 16-21 — RÉSERVE HEAL / BUFFER / HANDOFF

**Stratégie** : 6 jours flottants pour absorber slippage W1-W3, RED-team round 2 si besoin, healing post-merge, et préparation Week 4 track Order collapse impl.

### Day 16 — Lundi 2026-06-09 — BUFFER / RED-TEAM ROUND 2

- Owner : si Sprint W1-W3 slip → rattraper (DR drill, runbook play, LOCK reviews)
- Claude : RED-team round 2 adversarial sur tous les commits W1-W3 mergés. Mode RED hostile (Template A DISPATCH_PACK §6-A). Cherche P0 manqués.
  - Scope : `git diff <baseline-pre-audit>..HEAD` ≈ tous commits W1-W3
  - Brief : "Le cycle prétend GREEN. Trouve un P0 manqué."
  - Synthèse `reports/audit/cto-global-2026-05-16/red-team/W1-W3-ROUND-2-RED.md`
  - Si findings P0 → heal Day 17-18
  - Si clean → Day 17 = sunday plan Week 4

### Day 17 — Mardi 2026-06-10 — HEAL OU SUNDAY PLAN

- Si Day 16 RED findings : Claude heal max 3 loops par finding
- Sinon : Claude rédige `plans/MASTER_ORDER_COLLAPSE_IMPLEMENTATION_2026-06-10.md` (Week 4-6 plan détaillé jour-par-jour, basé sur LOCK doc signé Day 14)

### Day 18 — Mercredi 2026-06-11 — V1.0.1 KICKOFF (PROMPT #14)

- Claude : Prompt #14 — FormRequest authz baseline 20 endpoints prioritaires (kickoff)
  - Copier AGENT_DISPATCH_PACK.md §4 Prompt #14
  - Day 18 scope : Architect priorisation 20 endpoints + Security cartographie. Implementation = days 19-20 + Week 4+.
  - Doc `reports/authz/PRIORITY_20_ENDPOINTS.md`

### Day 19 — Jeudi 2026-06-12 — FORMREQUEST AUTHZ TOP-5

- Claude : Prompt #14 — Implement top-5 endpoints (fiscal Z-report, refund, archive order, edit menu, branch admin)
  - 5 FormRequest classes
  - 5 paires tests RED→GREEN
  - Update `docs/AUTHZ_MATRIX.md`
  - Commit : `feat(authz): FormRequest authz top-5 endpoints (Prompt #14 / P1-28 batch 1)`

### Day 20 — Vendredi 2026-06-13 — ASSERTTRUE FIX + 23 NF525 PATHS

- Claude : Prompt #20 — Replace 23 `assertTrue(true)` placeholders (kickoff)
  - Copier AGENT_DISPATCH_PACK.md §5 Prompt #20
  - Day 20 scope : 5 premiers fiscal/payment/state-machine paths
  - Mutation tests sur 3 critiques (fiscal HMAC chain, payment idempotency, state-machine transition)
  - Commit : `test(fiscal): replace 5 assertTrue(true) placeholders with real behavioral assertions (Prompt #20 / P1-23 batch 1)`

### Day 21 — Samedi 2026-06-14 — RETROSPECTIVE + HANDOFF WEEK 4

- **OWNER (2h)** : Review tous commits Days 1-20
  - `git log --oneline 245e8ab57..HEAD` → ≈ 20-25 commits
  - Decide : single PR `phase1-w1-w3-2026-06-14` merge prod OU continue feature branch
- **CLAUDE session A (3h)** : 
  - Update PROJECT_BRAIN.md complet § par §
  - Push Graphiti master episode "Phase 1 Sprint W1-W3 Le Cayenne path executed 2026-05-19 → 2026-06-14"
  - Rédiger `reports/audit/cto-global-2026-05-16/PHASE1_W1_W3_RETROSPECTIVE_2026-06-14.md` :
    - What done : items closed
    - What deferred : items reportés Week 4+
    - Risks materialized : oui/non par §5 Roadmap risks A-G
    - Owner bandwidth observed : real vs budget
    - Velocity : commits per day, RED-team findings count, healing loops count
  - Préparer prompt Week 4 (Order collapse implementation kickoff Day 22) — use skill `handoff-cursor` OU inline

**Gate fin Day 21 = sortie cycle 3-semaines** : Phase 1 partielle livrée. Items closed = 18-20/30 (les 30 P0+P1). Items deferred Week 4+ = 10-12 (Order collapse impl, POS wizard impl, FormRequest 88 endpoints reste, Laravel migration, multi-tenant, etc.).

---

## §5 OWNER CHEATSHEET — Les actions OG-OWNER-EXECUTE et leurs moments

**Source** : OWNER_GATES_REGISTRY.md §3.1 + §3.2. Filtré aux 4 OG-OWNER-EXECUTE strict + 5 owner moments critiques additionnels.

### 5.1 Les 4 OG-OWNER-EXECUTE strict (Claude NE PEUT PAS faire)

| # | Action | Quand | Durée | Outils |
|---|--------|-------|-------|--------|
| **P0-1** | Rotation AWS keys console | **Day 1 09:00** | 2h | console.aws.amazon.com IAM |
| **P0-4** | Setup bucket S3 + IAM + GPG | **Day 4 09:00** | 1h | AWS console + `gpg --gen-key` CLI |
| **P0-5** | Création comptes Sentry/BetterUptime/Slack | **Day 4 09:00** (parallèle) | 1h | sentry.io + betteruptime.com + slack.com |
| **Push prod final** | Deploy prod via `bin/deploy.sh` | **Day 21 EOD ou décalé** | 30min | SSH prod + bin/deploy.sh |

### 5.2 Les 5 owner-moments additionnels (gates non-OG-OWNER-EXECUTE mais owner-blocking)

| # | Action | Quand | Durée | Type |
|---|--------|-------|-------|------|
| **OWN-1** | DR drill staging (P0-4 acceptance) | **Day 5 09:00** | 2h | OG-OWNER-EXECUTE follow-up |
| **OWN-2** | 4 runbook play-through staging | **Days 5 + 6 + 7** (2h + 3h + 1h) | 6h total | OG-AUTO + sign-off |
| **OWN-3** | P1-22 SQL execution prod DB | **Day 1 14:00** | 30min | OG-OWNER-EXECUTE |
| **OWN-4** | Sign LOCK Order collapse | **Day 14 09:00** | 1h | OG-LOCK-DOC sign-off |
| **OWN-5** | Sign LOCK POS wizard | **Day 15 09:00** | 1h | OG-LOCK-DOC sign-off |

### 5.3 Owner bandwidth budget

| Phase | Owner hours |
|-------|-------------|
| Days 1-5 (W1) | ~9h (rotation 2h + SQL 0.5h + comptes 1h + DR drill 2h + runbook 2h + reviews 1.5h) |
| Days 6-10 (W2) | ~7h (runbook plays 6h + reviews 1h) |
| Days 11-15 (W3) | ~3h (LOCK reviews 2h + review final 1h) |
| Days 16-21 (buffer) | ~5h (heal validation + final review + push prod) |
| **TOTAL** | **~24h sur 3 semaines (~1.6h/jour avg)** |

Compatible avec budget owner ~3h/jour annoncé.

---

## §6 CLAUDE SESSION CHECKLIST — How to start each Claude session

### 6.1 Session start (chaque nouvelle session Claude Code)

1. **Auto** : Claude Code charge `CLAUDE.md` automatiquement
2. **Mandatory read** : `PROJECT_BRAIN.md` § par §
3. **Roadmap** : lire `reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` §5 (P0/P1 SSOT)
4. **Today's plan** : ouvrir ce fichier `reports/audit/cto-global-2026-05-16/ultra-plans/EXECUTION_SCRIPT_3_WEEKS.md` → section Day N courante
5. **Graphiti recall** (si tâche significative) :
   - `mcp__graphiti__search_nodes` query "le cayenne phase 1 sprint w<N>" group_id="foodking"
   - `mcp__graphiti__get_episodes` last_n=5 group_id="foodking"
6. **Verify branch** : `git status` + `git log --oneline -5` (vérifier HEAD, frozen-zone untouched)
7. **Skill auto-trigger** : si tâche non-triviale, invoque skill `superpower-gstack` (déclencheur "GStack pipeline")

### 6.2 Session execution (LOOP discipline CLAUDE.md §5)

Pour chaque tâche :
1. ORCHESTRATE — comprendre user request + alignment north star
2. PLAN — décomposer, déterminer subagents, vérifier frozen-zones
3. EXECUTE — scope-minimal, sub-agents parallèles si gain
4. AUDIT — relire code modifié, side-effects ?
5. TEST technique — PHPUnit + Vitest filter
6. VISUAL TEST — Playwright capture + Read screenshots (mandatory si frontend)
7. SELF-CORRECT — max 3 loops, sinon escalate
8. UPDATE BRAIN — PROJECT_BRAIN.md §2/§3/§4 + Graphiti episode

### 6.3 Session end

- [ ] PROJECT_BRAIN.md updated (HEAD, branch, timestamp, LAST DONE 1-2 phrases, NEXT TO DO si applicable)
- [ ] Graphiti episode pushed (si significatif)
- [ ] Tests run + GREEN
- [ ] Frozen-zone untouched (safety-check.sh)
- [ ] User summary : 3-5 bullets (verts, captures, décisions, blockers)

### 6.4 Session pour Prompt # spécifique du DISPATCH_PACK

1. **Open AGENT_DISPATCH_PACK.md** au prompt # exact
2. **Copy entire prompt block** (de "### Prompt #X" jusqu'au "---" suivant)
3. **Paste in fresh Claude Code session** : "Exécute ce prompt"
4. **Verify auto-trigger** : Claude doit invoquer skill `superpower-gstack` (présent dans missions via mots-clés)
5. **OWNER-GATE flag** : si prompt marqué OWNER-GATE, Claude STOP après plan + LOCK + RED-team, ne commit pas, attend signature

---

## §7 MID-WEEK GATES — Friday review + Sunday plan

### 7.1 Vendredi end-of-week (Day 5, Day 10, Day 15)

**Owner (30 min)** :
- Lire PROJECT_BRAIN.md §3 LAST DONE updated
- Lire `git log --oneline <last-friday>..HEAD`
- Verify sortie criteria du sprint (§1, §2, §3 de ce doc respectif)
- Sign-off ou identifier slippage

**Claude (1h)** :
- Push Graphiti episode "Sprint W<N> closed YYYY-MM-DD"
- Update PROJECT_BRAIN.md §4 NEXT TO DO pour Week+1
- Prep handoff inline notes pour Lundi session

### 7.2 Dimanche planning (jour off, optionnel owner)

**Owner (30 min, si dispo)** :
- Re-lire ce fichier section Week+1
- Note questions / décisions à prendre Lundi morning

**Claude (passive)** :
- Aucun work scheduled
- Si owner pose question via DM → réponse async

### 7.3 Lundi kickoff (Day 6, Day 11, Day 16)

**Owner (15 min)** :
- DM Claude "go Sprint W<N+1>"

**Claude (session start 30 min)** :
- LOOP discipline §6.1 ci-dessus
- Read Day N section ce fichier
- Confirm prerequisites précédente Sprint = met

---

## §8 ESCALATION PATTERNS (if X happens, do Y)

### Pattern A — P0-1 rotation slip >24h

**Symptom** : Owner ne confirme pas rotation Day 1 EOD (>17:00 lundi).

**Consequence** : Tout commit Day 2+ ré-embed les clés leakées en historique git. Roadmap §5 Risk A : finding qui s'aggrave avec le temps.

**Action** :
1. Claude STOP. Aucun commit code ne lande Day 2+ tant que rotation non confirmée.
2. Claude peut continuer du work read-only (audit, plan, doc) mais pas modify/commit
3. Si slip 48h : escalate red-flag dans Slack / DM owner direct "P0-1 rotation overdue 48h, halt all code commits per roadmap Risk A"
4. Si slip 5+ jours : escalate critical, considérer purge git history (`git filter-branch` ou BFG) si rotation finalement validée — owner gate (CLAUDE.md §10)

### Pattern B — RED-team trouve P0 sur commit récent

**Symptom** : Day N RED-team adversarial trouve P0 sur scope Day N-1 ou Day N-2.

**Action** :
1. Claude loop heal max 3 (CLAUDE.md §5 étape 7)
2. Si 3 loops insuffisants : escalate owner via Template C (DISPATCH_PACK §6-C) "Owner gate ESCALATE" format
3. Considérer revert commit problématique : `git revert <sha>` (NOT `git reset` — préserver historique)
4. Update PROJECT_BRAIN.md §6 DECISIONS LOG : entry "P0 trouvé post-merge, root cause, action"

### Pattern C — Owner DM "je n'ai pas pu faire X" (gate slip)

**Symptom** : Owner reporte une action OG-OWNER-EXECUTE (rotation, DR drill, runbook play, LOCK sign).

**Action** :
1. Claude évalue downstream impact : qui dépend de X ?
2. Re-plan : décaler tâches downstream du même nombre de jours
3. Update PROJECT_BRAIN.md §4 NEXT TO DO avec nouvelle date target
4. Identifier tâches Claude-only qui peuvent avancer en parallèle (pas bloquer wall-clock)
5. DM owner : "OK décalé +1j. En attendant Claude continue Y, Z. Re-confirme dispo demain pour X."

### Pattern D — Frozen-zone touch détecté hors LOCK

**Symptom** : Claude (par erreur) ou subagent (par drift) modifie un frozen-zone fichier sans LOCK doc en PR.

**Action** :
1. `bash .cursor/hooks/safety-check.sh` → output identifies le fichier touché
2. CI `frozen-zones-gate.yml` (post Prompt #13 Day 6) bloque le PR
3. Claude STOP immédiat
4. 2 options : (a) revert touch frozen-zone (`git checkout <file>`) (b) invoke skill `/lock-plan` rétroactif → STOP + owner gate
5. PROJECT_BRAIN.md log entry "Frozen-zone touch attempted, blocked, action"

### Pattern E — CI rouge persistante

**Symptom** : Tests CI fail 3 runs consécutifs après push commit.

**Action** :
1. Claude lit CI logs (`gh run view <run-id>`)
2. Root cause analysis (CLAUDE.md §5 étape 7) — pas juste "y'a une erreur"
3. Fix + push amend OU revert
4. Si 3 loops insuffisants : escalate Template C

### Pattern F — Owner pressure "compress timeline"

**Symptom** : Owner demande "ouvrons Le Cayenne dans 2 sem au lieu de 6"

**Action** :
1. Refer to roadmap §5 Risk C, F
2. Decline compression sur P0 critiques (sécu, backup, runbooks) — NON-NEGOTIABLE
3. Propose : open Le Cayenne après W3 (Day 15) avec G6 hardening en soft-launch backlog
4. Document tradeoff PROJECT_BRAIN.md §6 DECISIONS

### Pattern G — Findings stale dans roadmap

**Symptom** : Claude découvre qu'un P0 est déjà fixé (cf. P0-8, P0-9 trouvés already-fixed dans QUICK_WINS_EXECUTED).

**Action** :
1. Verify via `git log -p -S '<keyword>'`
2. Document dans `reports/audit/cto-global-2026-05-16/STALE_FINDINGS_VERIFIED.md`
3. Re-prioritize : leverage temps libéré pour Week+1 ahead-of-schedule
4. Push Graphiti episode

---

## §9 DAY-21 GO-LIVE CHECKLIST — Le Cayenne ouverture

**Source** : verdict §8 (40 items consolidés) + roadmap §7 finish-line definition.

> **Règle** : ≥90% green = ≤4 items yellow tolérés (aucun yellow en fiscal/payment/auth).
> **Hard gates** : doivent être 100% green, pas 90%.

### 9.1 Hard gates (100% mandatory)

- [ ] **P0-1** AWS keys rotated + gitleaks pre-commit + composer audit CI (validated Day 1-2)
- [ ] **P0-2** Sanctum abilities role-scoped + LanguageService route gated + force re-login executed (validated Day 2-3)
- [ ] **P0-4** Backup quotidien auto S3 GPG + restore drill staging tested (validated Day 5)
- [ ] **P0-5** Alerting Slack + Sentry + BetterUptime live + tested (validated Day 4)
- [ ] **P0-10** 4 critical runbooks SIGNED + owner walked-through (validated Day 5-7)
- [ ] **P0-12** OrderStateMachine.apply() sole writer + CI lint enforced (validated Day 12)
- [ ] **P0-15** E2E smoke pack 5 specs bloquant CI (validated Day 8)
- [ ] **P1-24** Frozen-zone CI gate live + fixture rouge confirmed (validated Day 6)
- [ ] **DR drill** production tested (deferred Day 21+ OU Week 4 hors scope ce 3-sem)

### 9.2 Soft gates (90% green target)

- [ ] **P0-3** IDOR PosOrderController scope assertion (Day 9 quick fix) ✅
- [ ] **P0-6** Stripe bcmath cast (Day 1 commit) ✅
- [ ] **P0-7** Order collapse — **LOCK signed Day 14, exécution Week 4-6 séparée** (deferred)
- [ ] **P0-8** Mobile allergens default `[]` (already fixed commit 245e8ab57) ✅
- [ ] **P0-9** Mobile promo button hide (already fixed commit 245e8ab57) ✅
- [ ] **P0-11** POS cash-trail E2E test (Day 9) ✅
- [ ] **P0-13** PHPSpreadsheet ≥2.0 — Prompt #12 PLAN Week 4-6 (deferred PLAN ONLY)
- [ ] **P1-19** withoutGlobalScope 39 sites audit (Day 15 baseline + completion Week 4-5 deferred)
- [ ] **P1-20** KDS UX 8 P0 heal (Day 13) ✅
- [ ] **P1-21** POS wizard LOCK signed Day 15, exécution Week 4 separate (deferred)
- [ ] **P1-22** branch.status SQL (Day 1) ✅
- [ ] **P1-25** bin/deploy.sh + bin/rollback.sh tested (Day 7) ✅

### 9.3 Backlog acceptable post-launch (V1.x — G7, OUT of 3-sem scope)

- P1-16 14 controllers DB facade refactor
- P1-17 OrderStateMachine ADR doc
- P1-18 Frontend API client layer + Composition migration
- P1-23 23 assertTrue(true) — Day 20 batch 1, reste Week 4+
- P1-27 TPE driver natif (owner decision required)
- P1-28 FormRequest authz 88 endpoints — Day 18-19 batch 1, reste Week 4+
- P1-30 Pinia migration
- P0-14 Laravel 9 → 11 (separate track plan only Week 4+)
- 17 advisories composer triage (incl. PHPSpreadsheet ≥ 2.0 already done Day 18+ Prompt #12)

### 9.4 Explicit V2 SaaS deferred (Phase 3+ verdict §7 — OUT of this roadmap entirely)

- items.branch_id + 7 catalog tables migration (Prompt #21 PLAN exists, exécution Phase 3 hors 3-sem)
- Billing / subscription / plan / Stripe Billing
- Onboarding command + signup flow + marketing site
- UberEats / Deliveroo / JustEat integrations
- DPA GDPR + DPIA + Privacy policy
- Driver TPE natif (Ingenico Tetra recommandé Agent 7)

### 9.5 Day-21 actions GO-LIVE

**OWNER (Day 21 EOD)** :
1. Review checklist §9.1 + §9.2 → marquer green/yellow
2. Si ≥90% green ET 100% hard gates green : **GO-LIVE possible**
3. Si <90% OU 1+ hard gate red : **NO-GO**, continuer Week 4 hardening
4. Décision documentée PROJECT_BRAIN.md §6 DECISIONS LOG

**CLAUDE (Day 21)** :
- Push Graphiti episode final "Phase 1 W1-W3 closed, GO/NO-GO Le Cayenne decision"
- Si GO : prepare Day 22 = ouverture Le Cayenne soft-launch + Claude on-call standby
- Si NO-GO : Week 4 plan rédigé avec items rouges en priorité

---

## §10 RÉFÉRENCE — Index des prompts utilisés Days 1-21

| Day | Prompt # | Sprint | Theme | Status post-cycle |
|-----|----------|--------|-------|-------------------|
| 1 | (none — quick wins commits prêts) | W1 | unblock cascade | DONE |
| 2 | #1 (kickoff Day 1 PM) + #2 | W1 | gitleaks + RCE | DONE |
| 3 | #3 | W1 | backups | DONE |
| 4 | #4 + #9 | W1 | alerting + runbooks rewrite | DONE |
| 5 | (consolidation + DR drill) | W1 | DR + runbook 1/4 | DONE |
| 6 | #13 | W2 | frozen-zones CI gate | DONE |
| 7 | #15 | W2 | deploy/rollback scripts | DONE |
| 8 | #11 | W2 | E2E bloquant + stress MySQL | DONE |
| 9 | #10 + P0-3 quick fix | W2 | POS cash-trail + IDOR | DONE |
| 10 | (consolidation W2) | W2 | review + PR public option | DONE |
| 11 | #6 | W3 | LOCK Order collapse PLAN | LOCK doc only |
| 12 | #7 | W3 | OrderStateMachine sole writer | DONE |
| 13 | #16 | W3 | KDS UX heal | DONE |
| 14 | #17 | W3 | LOCK POS wizard PLAN + owner sign Order LOCK | LOCK doc only |
| 15 | #19 (kickoff) | W3 | withoutGlobalScope audit baseline | KICKOFF |
| 16 | (RED-team round 2) | Buffer | heal cycle entier | HEAL |
| 17 | (heal OR Week 4 plan) | Buffer | sunday plan | PLAN |
| 18 | #14 (kickoff) | Buffer | FormRequest authz baseline 20 | KICKOFF |
| 19 | #14 (impl top-5) | Buffer | FormRequest top-5 | DONE |
| 20 | #20 (kickoff 5/23) | Buffer | assertTrue real | KICKOFF |
| 21 | (retro + handoff Week 4) | Buffer | GO-LIVE decision | DECISION |

### Prompts NOT used Days 1-21 (deferred to Week 4+)

- **Prompt #5** (Stripe bcmath) — already DONE Day 1 commit
- **Prompt #8** (mobile P0) — already DONE commit 245e8ab57
- **Prompt #12** (Laravel migration PLAN + PHPSpreadsheet upgrade) — Week 4+
- **Prompt #14** Implementation 15-88 endpoints — Week 4+ (Day 19 only top-5)
- **Prompt #18** (Frontend API client + Pinia) — Week 7-8 hors 3-sem
- **Prompt #19** Implementation 39 sites — Week 4-5 (Day 15 baseline only)
- **Prompt #20** Implementation 18/23 reste — Week 4+ (Day 20 only batch 1)
- **Prompt #21** (Multi-tenant catalog) — Phase 3 hors roadmap
- **Prompt #22** (Doctrine consolidation) — Week 11-12 hors 3-sem

---

— Fin EXECUTION_SCRIPT_3_WEEKS 2026-05-16.

**Prochain doc à produire** : `plans/MASTER_WEEK_4_ORDER_COLLAPSE_IMPLEMENTATION.md` (Day 17 ou Day 21 par Claude).
