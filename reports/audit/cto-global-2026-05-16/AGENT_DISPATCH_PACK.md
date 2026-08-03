# FOODKING — AGENT DISPATCH PACK (CTO Audit 2026-05-16)

**Source audit** : `reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` (verdict 32/100, 15 P0 + 15 P1)
**Audience** : owner (Claude Code single-session) — chaque prompt est self-contained, copy-paste vers une session fraîche
**Format** : 11-field template uniforme (Mission / Context / Pipeline step / Agent type / Frozen-zone / TDD / Steps / Constraints / Output / Acceptance / RED-team)
**Coverage map** : chaque prompt indique en tête `Covers items: P0-#X` ou `P1-#Y` couvrant tous les 30 items de §5 du verdict.

---

## §1 How to use this pack

Pour chaque sprint week, copie-colle le prompt dans une **session Claude Code fraîche** (pas la session courante orchestrateur). Lis le prompt en entier, puis dis simplement "Exécute ce prompt" à Claude — il devra invoquer la skill `superpower-gstack` automatiquement (déclencheur "GStack pipeline" présent dans chaque mission). Les prompts marqués **OWNER-GATE** explicitement interdisent à Claude d'auto-exécuter : Claude produit plan + LOCK doc + STOP, l'owner valide avant tout commit. Le RED-team subagent (§6 template) doit être dispatché APRÈS chaque implémenteur, AVANT de déclarer "done". Lance les sprints **séquentiellement** — la sécurité (Sprint 1) bloque tout le reste ; sans rotation secrets + RCE patché, aucune autre tâche ne doit toucher la prod.

---

## §2 Sprint Week 1 — P0 critiques sécurité/ops (5 prompts)

> **Critère de sortie Sprint 1** : zéro P0 sécurité/ops ouvert. AWS rotated, RCE patché, gitleaks bloquant CI, backup automatisé verifié restore-tested, alerting Slack+Sentry+BetterUptime live.

---

### Prompt #1 — Rotation secrets + gitleaks pre-commit + CI security gate (Sprint Week 1)

**Covers items**: P0-1 (AWS keys leaked non rotated), part of P0-3 (FormRequest authz scope follow-up CI), part of P2 (commit hygiene)

**Mission**: Préparer rotation secrets (owner exécute) + bolt gitleaks/composer audit/npm audit en CI pour bloquer nouveaux leaks.

**Context**: Audit verdict §5 P0 #1 — clés AWS `AKIAYJOT77SIZHDXNYOZ` + APP_KEY + FISCAL_*_SECRET committées dans `a4a88df06` (`.env.backup-pre-round2`), historique git permanent, **non rotées**. BRAIN.md:53 détecté il y a 3 jours. Agent 2 §P0-S-01. Agent 8 RISK 72/100. Aucun secret scanning CI actuel (Agent 4 §P0).

**Pipeline step**: Plan + Implement (CI infra only, owner gate sur rotation)

**Agent type**: `general-purpose` (Claude Code orchestrateur direct, scope ≤30 LOC inline) — pas de subagent nécessaire

**Frozen-zone**: No

**TDD instruction**: Oui — créer fixture commit avec fake AWS key `AKIA0000FAKE0000TEST`, vérifier que `gitleaks-action` fail le CI RED, retirer fake → GREEN.

**Steps**:
1. Lister secrets exposés via `git log --all -p -- '.env*' '*.env.backup*' | grep -E '(AKIA|sk_live|whsec_|pk_live)'` → écrire `reports/audit/cto-global-2026-05-16/SECRETS_TO_ROTATE.md` (AWS, Stripe live, Pusher, SenangPay, APP_KEY, FISCAL_HMAC_*, MIX_API_KEY cf. Agent 2 §P0-S-03).
2. Ajouter `.github/workflows/security-scan.yml` qui run `gitleaks-action@v2` + `composer audit` + `npm audit --audit-level=high` sur push + PR. Fail si HIGH/CRITICAL trouvé.
3. Ajouter pre-commit hook local `.githooks/pre-commit` (gitleaks détecte avant push). Doc dans `docs/onboarding/SETUP_PRE_COMMIT.md`.
4. Test RED→GREEN avec fixture (voir TDD).
5. Listing P0-S-03 spécifique `MIX_API_KEY` leaked en HTML body → ajouter à checklist owner.

**Constraints**:
- READ-ONLY sur `.env` réel. Claude ne touche **PAS** au fichier `.env` ni `.env.production` (CLAUDE.md §10 secrets gate humaine).
- Scope-minimal : ≤200 LOC total (workflow + hook + checklist + doc).
- Pas de commit "up" en fin de session (CLAUDE.md §10 + Agent 8 §44 auto-commits).

**Output**:
- `.github/workflows/security-scan.yml` (nouveau)
- `.githooks/pre-commit` + script install
- `reports/audit/cto-global-2026-05-16/SECRETS_TO_ROTATE.md` (checklist owner)
- `docs/onboarding/SETUP_PRE_COMMIT.md`
- PR draft avec ces fichiers — **owner gate avant merge**, owner gate **mandatoire** avant rotation effective des secrets (Claude ne lance pas la rotation lui-même)

**Acceptance**:
- Fixture commit avec fake AKIA key → CI workflow security-scan FAIL (rouge)
- Suppression fixture → CI GREEN
- Checklist owner liste ≥6 secrets (AWS x2, Stripe, Pusher, APP_KEY, FISCAL_HMAC, MIX_API_KEY)
- Aucun `.env*` modifié par Claude

**RED-team**: Dispatcher après PR draft. Mode = RED. Brief = "Trouve un secret type non couvert par le pattern gitleaks default (e.g. JWT custom, webhook signing secret SenangPay). Vérifie que workflow ne skip pas `forks` ou `dependabot` PRs."

---

### Prompt #2 — Patch RCE LanguageService + tokens Sanctum wildcard + abilities role-scoped (Sprint Week 1)

**Covers items**: P0-2 (RCE LanguageService + tokens wildcard), part of P0-3 (IDOR follow-up), P1-28 (FormRequest authz baseline)

**Mission**: Fermer primitive RCE `LanguageService::edit` + détonner les tokens Sanctum émis avec abilities `['*']` + remplacer par abilities role-scoped.

**Context**: Audit Agent 2 §P0-S-04 + §P0-S-05 :
- `app/Services/LanguageService.php:198-220` — écrit fichier PHP avec path user-supplied
- `routes/api.php:486` — route sous `auth:sanctum` SEUL (pas `permission:settings`)
- `app/Http/Controllers/Auth/LoginController.php:87-91` + `app/Http/Controllers/Frontend/Auth/GuestSignupController.php:140` — tokens créés avec abilities `['*']`
- `tokenCan('kiosk:order')` retourne `true` pour tous tokens wildcard → 6+ controllers compromis
- Combiné = client OTP kiosk peut écrire fichier PHP arbitraire dans `lang/` (auto-loaded par Laravel)

**Pipeline step**: Plan + TDD + Implement + Review + Test

**Agent type**: Architect subagent (plan + impact analysis sur 6+ controllers `tokenCan`) en parallèle avec Security subagent (audit des 6+ callers `tokenCan` + `withoutGlobalScope`), puis Implementer subagent (TDD-first) — pattern `superpowers:dispatching-parallel-agents`.

**Frozen-zone**: No (Sanctum config + LoginController + LanguageService ne sont pas dans CLAUDE.md §7)

**TDD instruction**: **Mandatoire**. Tests RED d'abord :
- `tests/Feature/Security/LanguageServiceRceTest.php` — POST payload avec `../../public/shell.php` → assert 403 + fichier NON créé
- `tests/Feature/Security/SanctumAbilityTest.php` — kiosk token avec ability `['kiosk:order']` appelle `/api/admin/items` → assert 403
- `tests/Feature/Security/TokenWildcardBanTest.php` — assert que `User::tokens()->where('abilities', 'like', '%[\"*\"]%')->count() === 0` après migration

**Steps**:
1. **Plan** : Architect subagent cartographie tous les `createToken` callers + tous `tokenCan` callers (Agent 2 dit 6+). Liste les routes utilisées par chaque ability future (cashier=`pos:order`, kiosk=`kiosk:order`, admin=`admin:catalog`, `admin:report`, `admin:fiscal`, etc.).
2. **Tests RED** : écrire les 3 tests ci-dessus, vérifier qu'ils FAIL sur main.
3. **Patch RCE** : `routes/api.php:486` ajouter `->middleware('permission:settings')`. Dans `LanguageService::edit` : whitelist `realpath()` contre `lang_path()`, rejeter regex `/<\?(php|=)/`, rejeter values contenant `eval|system|exec|passthru|`.
4. **Patch tokens** : `LoginController.php:87-91` + `GuestSignupController.php:140` — remplacer `['*']` par abilities calculées via rôle Spatie. Helper `App\Support\TokenAbilityResolver::for($user): array`.
5. **Force re-login** : migration `2026_05_17_revoke_wildcard_tokens.php` qui supprime tous les `personal_access_tokens` avec `abilities` contenant `"*"`. Doc utilisateur "tous les staff devront se reconnecter une fois post-deploy".
6. **CI lint** : `.github/workflows/security-scan.yml` ajouter `grep -rn "createToken(.*\['\*'\]" app/` doit retourner 0.
7. **Tests GREEN** sur les 3 fichiers + regression suite complète touchée (`php artisan test --testsuite=Feature --filter=Sanctum`).

**Constraints**:
- TDD obligatoire (tests RED prouvés avant code).
- Pas de touch `BranchScope.php` (frozen-zone §7).
- Migration `revoke_wildcard_tokens` testée sur SQLite + idempotente.

**Output**:
- 2 PRs séparées (mergeable indépendamment) :
  - PR-A : Patch RCE LanguageService (route middleware + service hardening + test)
  - PR-B : Tokens role-scoped (LoginController + GuestSignupController + migration + helper + tests + CI lint)
- Documentation `docs/security/SANCTUM_ABILITIES_MATRIX.md` mapping role→abilities

**Acceptance**:
- 3 tests RED passent au GREEN après patch
- `grep -rn "createToken(.*\['\*'\]" app/` → 0 hits
- Kiosk token réel testé manuellement (Tinker) ne peut appeler `/api/admin/*`
- Migration `revoke_wildcard_tokens` jouée sur staging, force-relogin observé

**RED-team**: Mode SECURITY + RED parallèles. Brief : "Le patch couvre `LanguageService::edit`. Y a-t-il d'autres file-write paths user-controllable ? (`PdfController`, `ExcelExportService`, `MediaController`, etc.) Le whitelist `realpath` est-il bypass-able via symlink ou null-byte ?"

---

### Prompt #3 — Backup automatisé S3 + GPG + restore DR drill (Sprint Week 1)

**Covers items**: P0-4 (no backup), part of P1 (NF525 6-year retention durability)

**Mission**: Installer `spatie/laravel-backup` quotidien off-host GPG-encrypted S3 object-lock 6 ans + DR drill restore testé.

**Context**: Agent 4 §"Sauvegardes" P0 — `storage/backups/` contient seulement snapshots manuels nommés à la main (`menu-heal-v2-2026-05-14`, etc.). Disk fail = perte totale 6 ans NF525 = exposition pénale. Pas de retention policy, pas de GPG, même filesystem que DB.

**Pipeline step**: Plan + Implement + Test + Visual gate (DR drill)

**Agent type**: SRE subagent (plan AWS S3 config + IAM least-privilege) + Implementer subagent (composer install + config + cron + restore script). Sequential (write dependencies).

**Frozen-zone**: No

**TDD instruction**: Pas de TDD unit, mais **DR drill empirique mandatoire** : drop staging `orders` → restore from latest backup → vérifier outbox replay → close Z report. Documenter timing chaque étape.

**Steps**:
1. `composer require spatie/laravel-backup --dev=false` (production package).
2. `config/backup.php` : daily 03:00, MySQL dump + `storage/app/fiscal/` + `storage/app/audit/`, retention 7 daily + 4 weekly + 12 monthly + 6 yearly (NF525 6 ans).
3. Destination = S3 dédié `foodking-backups-prod` (bucket avec object-lock GOVERNANCE 6 ans + GPG-encrypted via `BACKUP_ARCHIVE_PASSWORD` env). Wasabi alternative documentée.
4. IAM AWS user dédié `foodking-backup-writer` policy minimale (`s3:PutObject` + `s3:GetObject` sur préfixe). **NE PAS** réutiliser AWS keys leaked (cf. Prompt #1).
5. `bin/restore.sh` script : `--dump=<filename>` → télécharge depuis S3, déchiffre GPG, `mysql -u... < dump.sql`, replay `outbox` pending depuis dump timestamp.
6. `docs/runbooks/RUNBOOK_DR_RESTORE.md` SIGNED (pas DRAFT) avec commandes copy-paste + timing observé en drill.
7. **DR drill** : sur staging, drop `orders` + `order_items` → run `bin/restore.sh --dump=latest` → vérifier compteurs (count orders pre/post) + outbox replay OK + Z report close-able. Documenter `reports/dr-drill/DR_DRILL_2026-05-XX.md`.
8. CI cron health check : `tests/Feature/Operations/BackupCronTest.php` → `php artisan schedule:list` contient bien `backup:run` daily 03:00.

**Constraints**:
- Owner action requise sur IAM AWS + bucket creation + `BACKUP_ARCHIVE_PASSWORD` env (Claude ne touche pas `.env` prod, CLAUDE.md §10).
- Restore script doit fonctionner même si Pusher/Redis down (degraded).

**Output**:
- PR avec `config/backup.php` + `bin/restore.sh` + runbook signed + test cron
- DR drill report dans `reports/dr-drill/`
- Checklist owner : creation bucket S3 + IAM + env var

**Acceptance**:
- Backup quotidien run en staging 03:00 et apparaît dans S3 staging bucket
- Restore drill complet < 15 min staging
- Runbook header `Status: SIGNED_BY_OWNER_2026-XX-XX` (owner co-signe)
- `php artisan backup:list` montre N backups + checksum verified

**RED-team**: Mode SECURITY. Brief : "Le bucket S3 a-t-il bien `BlockPublicAccess` + object-lock ? L'IAM `foodking-backup-writer` peut-il READ-LIST backups en clair ? GPG passphrase rotation procedure ? Que se passe-t-il si l'attaquant a `IAM:DeleteObject` (object-lock évite-t-il vraiment) ?"

---

### Prompt #4 — Alerting câblé Slack + Sentry + BetterUptime (Sprint Week 1)

**Covers items**: P0-5 (no alerting), part of P0-10 (runbooks → alerting cross-ref)

**Mission**: Câbler Slack webhook + Sentry + BetterUptime ping `/health/live` pour passer de "détecté par client" à "détecté en <2 min".

**Context**: Agent 4 §"Logs + Monitoring" P0 — `MonitorOutboxStaleness.php:80` écrit `Log::error` seulement, `LOG_SLACK_WEBHOOK_URL` non set dans `.env`, aucune monitoring externe. Outage 22h samedi = client le découvre dimanche matin.

**Pipeline step**: Plan + Implement (SRE wiring)

**Agent type**: `general-purpose` (config + SDK install, scope ≤40 LOC + .env doc)

**Frozen-zone**: No

**TDD instruction**: Test integration léger : trigger `Log::error('test alert')` → vérifier que `LogSlackHandler` est appelé (mock HTTP).

**Steps**:
1. Slack : créer channel `#foodking-prod-alerts`, configurer webhook URL. Doc dans `docs/operations/ALERTING_SETUP.md`. Ajouter `LOG_SLACK_WEBHOOK_URL` à `.env.example`.
2. `config/logging.php` : vérifier channel `slack` existant pointe `LOG_SLACK_WEBHOOK_URL`. Forcer level `error` (pas `warning` = spam).
3. Sentry Laravel : `composer require sentry/sentry-laravel`. Config `config/sentry.php` + DSN dans `.env.example`. Capture exceptions only (pas breadcrumbs verbeux pour limites budget).
4. Sentry Vue : `npm i @sentry/vue` + init dans `resources/js/app.js` SDK avec sample rate 10% performance.
5. BetterUptime : créer compte gratuit, ajouter monitor `https://lecayenne.foodking.fr/health/live` interval 60s, notify owner Telegram/SMS si fail 2 cycles. Doc dans `docs/operations/ALERTING_SETUP.md`.
6. Test manuel : `php artisan tinker` → `Log::error('test manual alert')` → vérifier message dans Slack #foodking-prod-alerts.

**Constraints**:
- Owner action : créer compte Sentry + BetterUptime + Slack webhook (Claude ne crée pas comptes externes).
- PII safe : Sentry `beforeSend` strip user.email + body password fields.

**Output**:
- PR avec config Laravel + Vue Sentry + doc setup
- Doc `docs/operations/ALERTING_SETUP.md` step-by-step owner
- Checklist owner : 3 comptes à créer + 3 env vars à ajouter

**Acceptance**:
- `Log::error('manual test')` arrive dans Slack en <30s
- Exception throw en backend test → arrive Sentry
- `/health/live` down → BetterUptime alerte owner dans 2 min

**RED-team**: Mode RED. Brief : "Le PII strip Sentry est-il complet ? (card last4 OK, full number leak risque ?) Slack webhook leaké dans logs CI ? Sentry SDK frontend expose-t-il debug info pour attaquant ?"

---

### Prompt #5 — Stripe charge bcmath round-half-up + cents truncation fix (Sprint Week 1)

**Covers items**: P0-6 (Stripe `(int) $total * 100` tronque centimes)

**Mission**: Remplacer `(int) $total * 100` par cast bcmath `round-half-up` dans Stripe charge path + test régression sur €X.99.

**Context**: Audit Agent 2 §P0 — `(int) (float) $total * 100` tronque les centimes sur valeurs flottantes type 9.99 (IEEE 754 representation 9.989999...). Résultat = 998 centimes au lieu de 999. NF525 mismatch entre total order + montant Stripe = écart fiscal détectable lors audit.

**Pipeline step**: Plan + TDD + Implement

**Agent type**: `general-purpose` inline (≤20 LOC scope)

**Frozen-zone**: No (Stripe controller pas dans §7)

**TDD instruction**: **Mandatoire**. Tests parametrized RED sur valeurs problématiques `[9.99, 19.99, 0.01, 0.10, 1.05, 2.55, 99.99, 100.00, 0.999]` → assert `to_cents($value) === expected_int_cents`.

**Steps**:
1. Grep `(int).*\* 100` dans `app/` → trouver tous les sites (StripeController, PaymentService, etc.).
2. Créer helper `App\Support\MoneyMath::toCents(string|float $amount): int` utilisant `bcmul($amount, '100', 0)` + round half-up via `bcadd($amount, '0.005', 4)` puis floor.
3. Tests RED dans `tests/Unit/Support/MoneyMathTest.php` — datapoints ci-dessus.
4. Patch chaque caller `(int) $x * 100` → `MoneyMath::toCents($x)`.
5. Test régression Stripe charge : commande €9.99 → Stripe charge.amount === 999.
6. Test Z-report integrity : sum(orders.total) cents === sum(payments.amount) cents pour cycle test.

**Constraints**:
- Pas de touch `PricingService.php` (frozen-zone §7). Le fix est en aval du pricing.
- Money type doit rester string ou Money object dans la persistance — flux entier reste compatible.

**Output**:
- PR avec helper + tests parametrized + sites updated
- `docs/adr/0NNN-money-math-bcmath.md` (ADR décision)

**Acceptance**:
- Tests parametrized 9/9 datapoints GREEN
- Test régression Stripe €9.99 → 999 cents
- `grep -rn "(int).*\* 100" app/` → 0 hits (sauf helper interne)

**RED-team**: Mode SECURITY. Brief : "Le helper traite-t-il les négatifs (refunds) ? Locale FR `,` vs `.` decimal séparateur (frontend forms) ? Les autres devises (futur EUR cents vs USD cents vs GBP pence) ?"

---

## §3 Sprint Week 2-3 — P0 architecture + mobile + runbooks (6 prompts)

> **Critère sortie Sprint 2-3** : OrderStateMachine = seul writer status, dual Order/FrontendOrder collapsed (ou plan LOCK signed), mobile P0 fermés, 4 runbooks signed, E2E bloquant CI, POS cash-trail testé.

---

### Prompt #6 — Order ↔ FrontendOrder COLLAPSE (Sprint Week 2-3, **OWNER-GATE**)

**Covers items**: P0-7 (dual Order/FrontendOrder)

**Mission**: Collapse `app/Models/Order.php` + `app/Models/FrontendOrder.php` → modèle Order unique avec fillable consolidé + observer single attach. **PLAN + LOCK doc ONLY ce sprint** ; exécution en sprint dédié (4 semaines estimé).

**Context**: Agent 1 §"P0 — Two Eloquent models bound to the SAME `orders` table" :
- `Order.php:19` + `FrontendOrder.php:19` ciblent table `orders`
- Fillable divergent : Order = `parent_order_id, fiscal_alloc_error_at` ; FrontendOrder = `transaction_id, card_type, source_surface`
- `AppServiceProvider.php:68` attache observer (audit) à `FrontendOrder` seulement
- `OrderService.php:1102` crée `FrontendOrder::create(...)` côté backend (mauvais)
- NF525 sensible : observer audit miss-attached = chain integrity exposure
- Frozen-zone : touche `OrderStateMachine.php` + observers fiscal → CLAUDE.md §7 backend NF525

**Pipeline step**: Plan + LOCK doc (NO Build this sprint)

**Agent type**: Architect subagent (plan détaillé file:line) + DBA subagent (data migration safety) + Security subagent (NF525 invariant preservation) — 3 parallèles read-only. Implementer NE PAS dispatcher.

**Frozen-zone**: **Yes** — `app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7 backend tenancy/payment), observers fiscal (CLAUDE.md §7 backend NF525). LOCK doc obligatoire via skill `/lock-plan`.

**TDD instruction**: Plan doit définir les tests régression AVANT toute écriture (sprint suivant) : (a) Order POST POS = même row structure que Order POST Kiosk, (b) fiscal_sequence_no alloc unique pas dupliqué, (c) audit log écrit pour 100% transitions, (d) idempotency intact, (e) outbox écrit même DomainEvent.

**Steps**:
1. **Invoquer skill `/lock-plan`** pour générer `LOCK_ORDER_COLLAPSE_2026-05-XX.md`. Inputs : files = `[Order.php, FrontendOrder.php, AppServiceProvider.php, OrderService.php]`. Reason = "collapse dual model fillable divergence, single observer attach NF525 critical".
2. Architect cartographie tous les callers `FrontendOrder::` (find + grep). Liste ~10-20 callers attendus (controllers, jobs, services).
3. DBA confirme aucune divergence colonnes nécessitant migration data (tous fillables existent déjà dans la table physique).
4. Security audit : pour chaque caller `FrontendOrder::`, vérifier que basculer vers `Order::` ne perd pas une `BranchScope` exception ou un observer side-effect.
5. Plan d'exécution séquentiel : (a) consolider fillable Order, (b) attacher observer audit à Order (et seulement Order), (c) refactor 20 callers `FrontendOrder` → `Order`, (d) deprecate FrontendOrder classe (alias temporaire `class FrontendOrder extends Order {}`), (e) tests régression triple-vert avant remove FrontendOrder, (f) remove FrontendOrder + alias.
6. Rollback plan : alias FrontendOrder = Order garde compatibilité backward 1 sprint si rollback nécessaire.
7. Acceptance criteria sprint exécution : 100% tests pre-existing GREEN, 5 nouveaux tests régression GREEN, observer audit fire sur Order POS + Order Kiosk + Order Mobile, fiscal_sequence_no gap-free post-merge.

**Constraints**:
- **PLAN ONLY ce sprint**. Pas une seule ligne de code modifiée hors écriture du LOCK doc + plan.
- **OWNER-GATE strict** (CLAUDE.md §10) : Claude STOP après écriture plan + LOCK + RED-team review. Owner approves before any code execution. Pas de commit auto.
- 4 semaines effort estimé pour exécution (sprint séparé).

**Output**:
- `LOCK_ORDER_COLLAPSE_2026-05-XX.md` (via skill)
- `plans/MASTER_ORDER_COLLAPSE_2026-05-XX.md` (plan détaillé file:line + tests + rollback)
- RED-team dispute report sur le plan
- **Aucun commit code**. Branch reste clean.

**Acceptance**:
- LOCK doc complet (frozen-zone reason, files, justification, rollback, owner sign-off slot)
- Plan ≥3 pages avec file:line citations
- 20+ callers `FrontendOrder::` identifiés et leur path de refactor cartographié
- RED-team review : verdict "plan suffisamment robuste" OU liste P0 à corriger

**RED-team**: Mode RED après plan. Brief : "L'alias temporaire `class FrontendOrder extends Order` peut-il créer des doublons si quelqu'un fait `FrontendOrder::create()` ? Y a-t-il des migrations qui dropent FrontendOrder cast ? Le composition_snapshot reste-t-il intact ? L'idempotency middleware fonctionne-t-il sur Order pur ?"

---

### Prompt #7 — OrderStateMachine seul writer status (Sprint Week 2-3, **OWNER-GATE partiel**)

**Covers items**: P0-12 (OrderService 2432 LOC + 5 mutations bypass), P1-17 (apply() half-adopted)

**Mission**: Forcer `OrderStateMachine::apply()` comme seul writer de `orders.status` + CI lint rule. Split OrderService en QueryService/CommandService (séparé, sprint suivant).

**Context**: Agent 1 §P0 + P1 — `OrderStateMachine::apply` utilisé seulement 2× (`Jobs/CleanupStalePendingKioskOrders.php:60` + comment `OrderService.php:1511`). 5 mutations directes dans `OrderService.php` lignes 1530, 1609, 1714, 1820, 1907. State-machine guarantee = void si pas le seul writer.

**Pipeline step**: TDD + Plan + Implement + Test + Review

**Agent type**: Architect subagent (cartographie les 5 sites + impact transitions) en parallèle Security (audit que chaque mutation respecte invariant transition) → Implementer subagent (TDD-first).

**Frozen-zone**: **Yes** — `app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7 backend tenancy/payment) si on doit ajouter une transition. **Non** si on appelle seulement `apply()` existant depuis les 5 sites OrderService (callers, pas OrderStateMachine lui-même). À déterminer en plan.

**TDD instruction**: **Mandatoire**. Pour chaque mutation parmi les 5 sites :
- Test RED : current code mutates `$order->status = X` directement → assert transition interdite (e.g. PAID→PENDING) crash avec InvalidTransitionException.
- Test pré-existant doit passer GREEN après refactor (regression coverage).

**Steps**:
1. **Plan** : Architect liste les 5 sites + le `next_status` de chacun + l'`actor` + le `reason`. Mapping vers `OrderStateMachine::apply($order, $next, $actor, $reason)`.
2. **5 tests RED** dans `tests/Unit/Domain/Order/OrderStateMachineInvariantsTest.php` — un test par site. Vérifier que la mutation actuelle bypass l'invariant (e.g. on devrait pas pouvoir passer cancelled→paid).
3. **Implementer subagent** : refactor les 5 sites séquentiellement (pas en parallèle, write conflicts). À chaque site : remplacer `$order->status = X; $order->save();` par `OrderStateMachine::apply($order, OrderStatus::X, auth()->user(), 'reason-string')`.
4. **Audit grep** : `grep -rn '->status\s*=\s*' app/Services/ app/Http/Controllers/` doit retourner **0 hits** (sauf OrderStateMachine.php lui-même).
5. **CI lint** : `.github/workflows/state-machine-guard.yml` qui run le grep ci-dessus et fail si non-zero.
6. **Tests GREEN** : `php artisan test --filter=OrderStateMachine`.
7. **Split OrderService PLAN ONLY** (no Build) : Architect dessine OrderQueryService + OrderCommandService → reports/refactor/.

**Constraints**:
- 5 mutations OrderService.php : touch autorisé (pas frozen-zone, c'est juste 2432 LOC legacy).
- Toute touch `OrderStateMachine.php` : **OWNER-GATE** + LOCK doc.
- Tests GREEN obligatoires AVANT commit.

**Output**:
- PR avec : 5 sites refactorés + 5 tests RED→GREEN + CI lint + audit grep doc
- `plans/PLAN_ORDERSERVICE_SPLIT_2026-05-XX.md` (no Build)

**Acceptance**:
- `grep -rn '->status\s*=' app/Services/ app/Http/Controllers/` → 0
- 5 tests régression GREEN
- CI workflow `state-machine-guard.yml` actif

**RED-team**: Mode RED. Brief : "Les 5 mutations ont-elles toutes le bon `actor` (system vs user vs cron) ? Le `reason` string est-il utile pour audit (pas juste 'auto') ? Y a-t-il des mutations indirectes via Eloquent `update(['status' => X])` ou `fill()` ou observers qu'on a manquées ? Les jobs queued bypassent-ils via `forceFill` ?"

---

### Prompt #8 — Mobile cluster-7 P0 : allergènes fabriqués + promo stub (Sprint Week 2)

**Covers items**: P0-8 (allergens 60/60 default), P0-9 (promo "✓ Code appliqué" sans discount)

**Mission**: Fermer 2 P0 mobile exposition légale + UX trompeuse.

**Context**: Agent 6 §"Mobile" :
- `mobile/data/menu.js:274` — default `['gluten','lactose']` appliqué à 60/60 items incl. eau minérale = EU FIC 1169/2011 legal exposure
- `mobile/screens-main.jsx:595` — bouton "Appliquer code" affiche "✓ Code appliqué" sans appliquer discount = UX trompeuse

**Pipeline step**: Plan + Implement + Visual gate

**Agent type**: Designer subagent (curation allergens manuelle item par item — read-only audit puis brief structuré pour owner Le Cayenne validation) + Implementer subagent (code fix promo) en parallèle.

**Frozen-zone**: No (mobile/ pas dans §7)

**TDD instruction**: Tests E2E Detox/Maestro :
- "order eau minérale → vérifier 0 allergène pill affichée"
- "input fake promo code → soit erreur claire visible, soit bouton désactivé, soit pas de banner '✓ appliqué'"

**Steps**:
1. **Allergens fix code** : `mobile/data/menu.js:274` changer default `[]`. Re-build : tous items perdent leurs allergens.
2. **Allergens curation** : Designer subagent produit `mobile/data/allergens-curation-2026-05-XX.md` listant pour chaque item ses allergens probables (depuis nom + composition_snapshot backend menu.php SSOT). **Owner Le Cayenne valide manuellement** chaque item (recette réelle). Puis Implementer applique items un par un.
3. **Promo fix** : décision owner Q1 — soit (a) implémenter backend call `POST /api/mobile/promo/apply` + apply discount + UI feedback, soit (b) retirer le bouton/section entièrement V1. **Recommandation V1 = retirer** (moins de surface).
4. **Tests E2E** : 2 specs Detox/Maestro selon décision Q1.
5. **Visual gate** : capture mobile screens (item detail eau minérale + cart avec promo input) → Read screenshots → vérifier 0 allergen pill + pas de banner trompeur.

**Constraints**:
- Allergens curation = blocker owner (impossible auto-fix).
- Si promo retiré, doc dans `docs/mobile/V1_SCOPE.md` (feature flag promo=disabled).

**Output**:
- PR-A : allergens fix code + curation doc (owner-validation pending)
- PR-B : promo fix (selon décision Q1)
- Tests E2E specs
- Visual gate screenshots

**Acceptance**:
- Eau minérale détail screen : 0 allergen pill visible
- Promo flow : pas de banner "✓ Code appliqué" sans vrai discount
- Curation doc : 60/60 items reviewed avec source recette

**RED-team**: Mode RED. Brief : "Allergens fabriqués sur d'autres surfaces ? (Kiosk, POS, KDS allergen-pill) Le composition_snapshot backend a-t-il des allergens fabriqués aussi ? L'absence d'allergens (eau) doit-elle afficher 'aucun allergène' explicitement vs vide silencieux ? (UX trust)"

---

### Prompt #9 — Sign 4 runbooks critiques (Sprint Week 2)

**Covers items**: P0-10 (10 runbooks DRAFT)

**Mission**: Signer 4 runbooks critiques avec commandes `php artisan` copy-paste : FISCAL_SEQUENCE_BREAK, KIOSK_NETWORK_LOSS, OUTBOX_BLOCKED, ROLLBACK_CANARY.

**Context**: Agent 4 §"Runbooks" P0 — 10 runbooks tous tagués `DRAFT_SKELETON_NOT_SIGNED`. Diagnostic steps disent "Observation incident; aucune commande dédiée". Un proprio non-dev-senior ne peut résoudre un incident.

**Pipeline step**: Plan + Implement (rédaction doc)

**Agent type**: SRE subagent (rédaction + extraction commandes existantes du codebase) + Owner (joueur du runbook en staging)

**Frozen-zone**: No

**TDD instruction**: Pas TDD code, mais **chaque commande dans runbook doit être testée empiriquement** en staging.

**Steps**:
1. Pour chaque runbook :
   - Trouver les commandes Artisan/Bash existantes utiles (`grep -r "php artisan" app/Console/Commands/`).
   - Remplacer "Observation incident; aucune commande dédiée" par 3-5 commandes copy-paste exécutables.
   - Ajouter section "Symptômes" (3 bullets) + "Causes probables" (3 bullets) + "Steps remédiation" (numbered, ≤8 étapes) + "Validation post-fix" (2-3 checks).
2. **FISCAL_SEQUENCE_BREAK** : commandes `php artisan fiscal:audit-chain --branch=X` + `php artisan fiscal:repair-sequence --dry-run` + procédure escalation L4 NF525.
3. **KIOSK_NETWORK_LOSS** : `php artisan kiosk:status --machine=X` + fallback cashier procedure papier.
4. **OUTBOX_BLOCKED** : `php artisan outbox:rescue --since=1h` + `php artisan outbox:retry-failed` + comment lire `/health/ready` outbox depth.
5. **ROLLBACK_CANARY** : `bin/rollback.sh --to=<release_tag>` (créer le script si manquant cf. Prompt #15) + DB rollback procédure + smoke pack post-rollback.
6. **Owner play-through** : owner joue chaque runbook en staging, mesure temps, identifie blocages. Itérer.
7. Header tag : `Status: SIGNED_BY_OWNER_2026-XX-XX`.
8. **Cheatsheet plastifiée** : 1 page recto-verso, 4 runbooks condensés, pour Le Cayenne.

**Constraints**:
- Commandes doivent fonctionner. Pas d'invention de commandes non-existantes.
- Owner sign-off réel après play-through staging.

**Output**:
- 4 runbooks updated `docs/runbooks/RUNBOOK_*.md` avec Status SIGNED
- `docs/operations/CHEATSHEET_LE_CAYENNE.pdf` (export markdown → PDF)
- Play-through report `reports/runbook-drill/RUNBOOK_DRILL_2026-05-XX.md`

**Acceptance**:
- 4/4 runbooks Status SIGNED (pas DRAFT)
- Chaque runbook a ≥3 commandes copy-paste testées
- Cheatsheet 1 page produit
- Play-through staging timing < 10 min par runbook

**RED-team**: Mode RED. Brief : "Les commandes copy-paste sont-elles idempotentes (rerunnables) ? Procédure FISCAL_SEQUENCE_BREAK respecte-t-elle NF525 (pas de gap silencieux, pas de DELETE) ? Le ROLLBACK_CANARY préserve-t-il la chaîne fiscal HMAC ? Rollback préserve-t-il les 6 ans de retention ?"

---

### Prompt #10 — POS cash-trail E2E test bout-en-bout (Sprint Week 2)

**Covers items**: P0-11 (POS direct-cash → CashMovement wiring untested)

**Mission**: Feature test E2E POS direct cash → CashDrawerSession → CashMovement → Z-report → reconciliation.

**Context**: Agent 5 + Wave Z report `reports/test-e2e/wave-z-2026-05-16-claudemax/` — POS direct cash path n'a pas de feature test bout-en-bout. Sprint 1B variance gate fix a neutralisé tests en legacy endpoints.

**Pipeline step**: TDD + Test (Feature test only)

**Agent type**: QA subagent (mode QA) + Tester subagent

**Frozen-zone**: No (test only)

**TDD instruction**: **C'est la mission entière** — écrire feature test qui DOIT couvrir le path.

**Steps**:
1. `tests/Feature/POS/PosDirectCashTrailE2ETest.php` : ouvrir CashDrawerSession (POS operator), créer Order direct cash, payer cash → assert CashMovement créée + balance updated + close session produit Z report avec chain HMAC intact.
2. Test multi-orders : 3 orders cash dans même session → CashMovement count = 3 + variance < seuil → close OK.
3. Test variance gate : forced écart > seuil → close BLOCKED avec message error.
4. Test régression Sprint 1B legacy : variance gate ne crash pas anciens tests.
5. Documentation `docs/testing/CASH_TRAIL_TEST_PLAN.md`.

**Constraints**:
- Test doit utiliser fixtures branch + user POS operator + cash session setUp.
- Pas de touch du code prod cash-trail (sauf bug discovered → ticket P1 séparé).

**Output**:
- PR avec feature test ≥80 LOC + doc
- Si bug discovered : ticket BRAIN.md NEXT TO DO

**Acceptance**:
- Test GREEN sur 3 scenarios (single, multi, variance-block)
- Coverage cash-trail path > 70%
- Chain HMAC intact post-close

**RED-team**: Mode RED. Brief : "Le test mock-t-il quelque chose qui n'arrive pas en prod ? La variance threshold est-elle configurable per-branch (multi-tenant) ? La chain HMAC est-elle vraiment vérifiée ou juste calculée ?"

---

### Prompt #11 — E2E bloquant CI + stress MySQL CI matrix (Sprint Week 3)

**Covers items**: P0-15 (E2E non-bloquant), P1-29 (stress sqlite-memory théâtral)

**Mission**: Drop `e2e-required` label + `continue-on-error: true`. Définir smoke pack 5 specs bloquantes. Porter RushMidiSimulationTest en CI MySQL matrix.

**Context**: Agent 5 §"E2E + stress" P0 + P1 :
- `.github/workflows/playwright.yml:36-41` opt-in par label + `continue-on-error: true`. PRs ship GREEN sans Playwright.
- `tests/load/RushMidiSimulationTest.php:48-58` se documente "sqlite-memory pas vrai concurrent, lockForUpdate no-op", 10 orders only, S7.2+S7.3 `markTestIncomplete`.

**Pipeline step**: Plan + Implement (CI infra)

**Agent type**: SRE subagent

**Frozen-zone**: No

**TDD instruction**: Run smoke pack actuel localement, identifier les flaky → fix avant rendre bloquant (sinon CI hell).

**Steps**:
1. **Audit smoke pack actuel** : lister 5 specs candidate. Cible : kiosk happy / POS cash / KDS bump / OSS update / fiscal Z-close.
2. **Fix flaky specs** : run chaque spec 10× local → identifier flakiness → fix (waits, sélecteurs, fixtures).
3. **Modifier `.github/workflows/playwright.yml`** : drop label opt-in. Drop `continue-on-error: true`. Smoke pack = mandatoire sur push + PR. Full pack = opt-in label `e2e-full`.
4. **Stress test MySQL matrix** : modifier `.github/workflows/ci-sync-rupture-harness.yml` pour run `php artisan foodking:e2e:stress` avec MySQL service container (`mysql:8.0`).
5. **Remove markTestIncomplete** S7.2 + S7.3 → fix tests sous MySQL real lockForUpdate.
6. **Test failure simulation** : push commit avec assert false dans smoke spec → vérifier CI rouge bloquant merge.

**Constraints**:
- Si smoke pack flaky impossible à fix → escalate owner, ne pas marquer bloquant.

**Output**:
- PR avec workflows updated + flaky fixes + stress MySQL
- `docs/testing/E2E_SMOKE_PACK_DEFINITION.md`

**Acceptance**:
- Smoke pack 5/5 GREEN sur 10 runs consécutifs (no flakiness)
- CI workflow fail correctement quand un test fail
- Stress test run MySQL CI dans <5 min

**RED-team**: Mode RED. Brief : "Le smoke pack couvre-t-il vraiment les paths critiques ? (fiscal Z-close vraiment ou juste mocké ?) Stress test 10 orders MySQL = vrai concurrent ou encore théâtral ? Y a-t-il un opt-out caché dans workflow ?"

---

## §4 Sprint Week 4-6 — P1 hardening (5 prompts)

> **Critère sortie Sprint 4-6** : Laravel 9.52 EOL upgrade plannifié, FormRequest authz 88 endpoints baseline, frozen-zones gate réel, KDS UX heal (8 P0 audit 2026-05-11), POS Vanilla LOCK surgical patch ARIA+44px+i18n.

---

### Prompt #12 — Laravel 9.52 EOL → 10 → 11 migration (Sprint Week 4-6, **OWNER-GATE**)

**Covers items**: P0-13 (PHPSpreadsheet CVE), P0-14 (Laravel 9.52 EOL)

**Mission**: Plan migration Laravel 9.52 → 10 → 11 + upgrade PHPSpreadsheet ≥2.0.0 (CVE-2024-45048). **PLAN + LOCK ONLY ce sprint**, exécution sprint dédié séparé (track V1.x).

**Context**: Agent 2 §P0 — Laravel 9.52 EOL (no more security patches), PHPSpreadsheet 1.30.0 CVE-2024-45048 RCE reachable via admin Excel import. Migration touche `IdempotencyKeyMiddleware.php` (frozen-zone §7) si Sanctum 3→4 change API.

**Pipeline step**: Plan + LOCK doc (NO Build this sprint)

**Agent type**: Architect + Security + DBA + SRE parallèles (4 subagents read-only)

**Frozen-zone**: **Yes** si Sanctum upgrade touche `IdempotencyKeyMiddleware.php` ou `BranchScope.php` (CLAUDE.md §7 backend tenancy/payment). À confirmer en plan.

**TDD instruction**: Tests régression doivent exister AVANT upgrade : smoke pack E2E + sentinels NF525 + idempotency tests. Si manquent → écrire avant upgrade.

**Steps**:
1. **PHPSpreadsheet upgrade isolé** (séparable, ne nécessite pas Laravel upgrade) : `composer require phpoffice/phpspreadsheet:^2.0` → run test suite → fix breaking changes (Reader/Writer API peut avoir changé).
2. **Laravel 9 → 10** plan : Architect produit `plans/MASTER_LARAVEL_9_TO_10_2026-05-XX.md` avec breaking changes mapped + composer.json diff + tests à ajouter.
3. **Laravel 10 → 11** plan séparé (track V1.x).
4. **Sanctum 3 → 4** : Security audit Sanctum API breaking changes vs `IdempotencyKeyMiddleware.php` + 39 callers `withoutGlobalScope(BranchScope::class)`.
5. **LOCK doc** si frozen-zone touche : `/lock-plan` → `LOCK_LARAVEL_UPGRADE_2026-05-XX.md`.
6. **Rollback plan** : composer.lock baseline + DB schema baseline pre-upgrade.

**Constraints**:
- **PLAN ONLY** ce sprint. PHPSpreadsheet upgrade peut être exécuté immédiat (P0 RCE) si pas de frozen-zone touch.
- **OWNER-GATE** sur Laravel upgrade execution (CLAUDE.md §10 architecture decision majeure).

**Output**:
- PR PHPSpreadsheet upgrade (executable immediately)
- `plans/MASTER_LARAVEL_9_TO_10_2026-05-XX.md`
- `plans/MASTER_LARAVEL_10_TO_11_2026-05-XX.md`
- LOCK doc si applicable

**Acceptance**:
- PHPSpreadsheet ≥2.0.0 installé + tests GREEN
- 2 plans Laravel produits, ≥3 pages chacun
- LOCK doc owner sign-off slot

**RED-team**: Mode RED. Brief : "PHPSpreadsheet 2.0 a-t-il breaking changes silencieux (Reader factory) ? Laravel 10 cast attributes change behavior (custom casts) ? Sanctum 4 token format change BC ? Migration scripts (artisan migrate) safely run sur prod data ?"

---

### Prompt #13 — Frozen-zones gate réel (CI mandatory + cumulative diff ratchet) (Sprint Week 4)

**Covers items**: P1-24 (frozen-zones gate cassé), part of P1-26 (CLAUDE.md vs AGENTS.md)

**Mission**: Synchroniser frozen-zones list CLAUDE.md §7 / memory / safety-check.sh. Ajouter GitHub Action qui fail CI si frozen modifié sans LOCK co-committé. Cumulative diff ratchet anti-drift.

**Context**: Agent 5 §"Frozen-zones" + Agent 8 §"Process drift" :
- `.cursor/hooks/safety-check.sh:9-12` liste 2 fichiers
- CLAUDE.md §7 liste 13+ fichiers
- Hook self-documents "Run manually before every execution phase. Not auto-invoked."
- +6782 lignes diff sur frozen-zones (ZReportService +714, AuditLogService +312, PricingService +740)

**Pipeline step**: Plan + Implement (CI infra)

**Agent type**: SRE subagent

**Frozen-zone**: No (le gate lui-même n'est pas frozen)

**TDD instruction**: Test workflow : fixture commit qui touch `app/Services/Fiscal/ZReportService.php` SANS LOCK doc → CI fail. Avec LOCK doc → CI green.

**Steps**:
1. **Source-of-truth unique** : créer `config/frozen_zones.php` listant les 13+ fichiers (array PHP). CLAUDE.md §7 et memory référencent ce fichier.
2. **Réécrire `scripts/check-frozen-zones.sh`** : lire `config/frozen_zones.php` → grep `git diff --name-only` contre cette liste → si match + pas de `LOCK_*.md` dans la PR → fail.
3. **GitHub Action `.github/workflows/frozen-zones-gate.yml`** : run script sur PR. Fail si touch sans LOCK.
4. **Cumulative diff ratchet** : baseline `reports/frozen-zones-baseline/<file>-<sha>.diff` stocke diff par fichier. Action fail si nouveau PR augmente le diff cumulé sans incrément justifié.
5. **Doc `docs/FROZEN_ZONES.md`** : explique process LOCK, mapping CLAUDE.md §7 → fichiers réels, comment override.
6. **Test fixture** : fake PR qui touch `ZReportService.php` sans LOCK → CI rouge confirmé.
7. **Resync** CLAUDE.md §7 et `memory/reference_frozen_zones.md` pour pointer `config/frozen_zones.php`.

**Constraints**:
- Ratchet ne doit pas casser les PRs en cours (baseline = current state).

**Output**:
- PR avec config + script + action + doc
- Baseline snapshot

**Acceptance**:
- Fixture PR sans LOCK fail CI
- Avec LOCK → green
- Documentation cohérente CLAUDE.md ↔ memory ↔ config

**RED-team**: Mode RED. Brief : "Le LOCK doc peut-il être falsifié (auto-created via Claude commit) ? Le ratchet peut-il être bypassed via amend ? Y a-t-il un grep regex robuste (rename file = bypass) ?"

---

### Prompt #14 — FormRequest authz baseline 20 endpoints prioritaires (Sprint Week 4-5)

**Covers items**: P1-28 (88 endpoints sans FormRequest authz)

**Mission**: Refactor 20 endpoints les plus exposés vers FormRequest authz pattern cohérent. Baseline pour V1.0.1 refactor des 68 restants.

**Context**: BRAIN.md + Agent 2 §P0-S-04 cross-ref — 88 endpoints sans FormRequest authz, rely sur middleware route-level uniquement. Combiné avec tokens wildcard, surface auth = "qui a le token sanctum touche tout".

**Pipeline step**: Plan + TDD + Implement

**Agent type**: Architect (priorisation 20 endpoints) + Security (cartographie authz besoin) + Implementer subagent (séquentiel sur 20 endpoints)

**Frozen-zone**: No

**TDD instruction**: Pour chaque endpoint refactoré : test RED `acting as wrong role → 403`, GREEN `acting as right role → 200`.

**Steps**:
1. **Priorisation 20 endpoints** : critiques = fiscal Z-report, refund, archive order, edit menu, branch admin, user create, role assign. Doc `reports/authz/PRIORITY_20_ENDPOINTS.md`.
2. **Pattern FormRequest** : pour chaque endpoint, créer `App\Http\Requests\<Module>\<Action>Request` avec `authorize()` retournant `auth()->user()->can('<permission>', $resource)` ou `hasRole('admin')`.
3. **Tests TDD** : 20 paires RED→GREEN dans `tests/Feature/Authz/`.
4. **Refactor controllers** : injecter FormRequest, drop validation inline.
5. **Doc `docs/AUTHZ_MATRIX.md`** : mise à jour avec 20 nouveaux endpoints.

**Constraints**:
- Backward compat : middleware route-level reste (defense in depth).
- Aucun drop de check Spatie existant.

**Output**:
- PR avec 20 FormRequests + 20 tests + doc

**Acceptance**:
- 20/20 endpoints ont FormRequest authz
- 20/20 tests RED→GREEN pass
- AUTHZ_MATRIX.md updated

**RED-team**: Mode SECURITY + RED. Brief : "Les FormRequest sont-ils bien appelés (typehint controller method) ? L'authorize() est-il vraiment appelé avant rules() ? Cross-branch resource access bloquée (IDOR check) ? Spatie permission key correcte (pas typo) ?"

---

### Prompt #15 — bin/deploy.sh + bin/rollback.sh + supervisor units (Sprint Week 5)

**Covers items**: P1-25 (no deploy/rollback scripts)

**Mission**: Écrire `bin/deploy.sh` atomic symlink flip + `bin/rollback.sh` tested + supervisor units pour `queue:work` et `schedule:run`.

**Context**: Agent 4 §"CI/CD" P0 — `bin/` ne contient que `graphiti-ingest.sh` + `graphiti-p0-long-drain.sh`. Owner deploy par SSH manuel. RUNBOOK_ROLLBACK_CANARY DRAFT non testé. Default queue `sync` (config/queue.php:16) silently degrades outbox.

**Pipeline step**: Plan + Implement + DR drill

**Agent type**: SRE subagent

**Frozen-zone**: No

**TDD instruction**: Drill empirique mandatoire : deploy staging + rollback staging + timing observé.

**Steps**:
1. `bin/deploy.sh` : git fetch + composer install --no-dev + npm ci + npm run prod + php artisan migrate --force + php artisan optimize:clear + atomic symlink flip + php artisan queue:restart + smoke ping `/health/live`.
2. `bin/rollback.sh --to=<release_tag>` : symlink flip back + php artisan migrate:rollback --to=<step> + php artisan queue:restart.
3. `bin/supervisor/foodking-queue.conf` : supervisor unit `queue:work --tries=3 --max-time=3600` autorestart.
4. `bin/supervisor/foodking-schedule.conf` : supervisor unit `php artisan schedule:work` (vs cron) autorestart.
5. `bin/supervisor/foodking-horizon.conf` (optionnel si Horizon installé Phase 2).
6. **Drill staging** : deploy v1 → run smoke → deploy v2 → run smoke → rollback v1 → run smoke. Documenter timing `reports/deploy-drill/DRILL_2026-05-XX.md`.
7. **Update `RUNBOOK_ROLLBACK_CANARY.md`** : commandes `bin/rollback.sh` copy-paste + Status SIGNED.

**Constraints**:
- Scripts shellcheck clean.
- Pas de modification config queue par défaut (owner gate `.env` rester intact).

**Output**:
- PR avec scripts + supervisor + drill report + runbook updated

**Acceptance**:
- Deploy drill complet staging < 5 min
- Rollback drill < 3 min
- Supervisor units validés `supervisorctl status`

**RED-team**: Mode RED. Brief : "Atomic symlink flip preserve-t-il connections HTTP en cours ? `migrate --force` peut-il corrompre data prod ? Rollback DB migration safe sur prod (data loss potential) ? Supervisor restart loop sur crash répété (max restart) ?"

---

### Prompt #16 — KDS UX heal (8 P0 audit 2026-05-11) (Sprint Week 5-6)

**Covers items**: P1-20 (KDS UX 3.2/10, 8 P0 cross-validated)

**Mission**: Heal 8 P0 KDS UX : accordéon fermé, banners stack, bump 32px → 44px, bug allergenModal typo, contrast 3.2:1 → 4.5:1, 18 raw labels FR → i18n.

**Context**: Audit `memory/project_kds_audit_2026-05-11.md` — 8 P0 cross-validated. Verdict UX 3.2/10. Plan 3 sprints owner-gate. CLAUDE.md §6 visual mandate obligatoire.

**Pipeline step**: Plan + TDD + Implement + Visual gate + Review

**Agent type**: Designer subagent (DS V5 + light flat palette) + Implementer subagent + QA subagent visual

**Frozen-zone**: No (KDS pas dans §7 — KitchenDisplaySystemComponent.vue 2545 LOC est touchable)

**TDD instruction**: Vitest tests pour chaque P0 : (a) accordéon ouvert par défaut, (b) banners single-stack, (c) bump touch target ≥44px assertion, (d) allergenModal exists pas typo, (e) contrast token ≥4.5:1 (lib `polished` ou similaire), (f) i18n keys non-raw assertion.

**Steps**:
1. **Plan** : lire `memory/project_kds_ultra_plan_2026-05-11.md` (contrat canonique KdsOrder, helper kdsCustomization).
2. **Tests RED** : 8 tests un par P0.
3. **Implement** dans `resources/js/components/frontend/kds/KitchenDisplaySystemComponent.vue` + tokens CSS.
4. **i18n keys** : 18 raw labels FR → `lang/fr/kds.php` + `lang/en/kds.php` + `lang/ar/kds.php`. Pas de `Label.X` raw.
5. **Visual gate** : Playwright capture `/kds` état idle + état rush 5 orders + état bump → Read screenshots → analyser layout, contrast, touch targets, i18n résolu.
6. **Heal max 3 loops** si visual fail.

**Constraints**:
- Pas de touch du contrat canonique KdsOrder (référence ultra-plan).
- DS V5 + light flat palette (memory `feedback_design_flat_organized.md`).

**Output**:
- PR avec Vue + tokens + i18n + tests + screenshots
- Visual gate analyse `reports/visual/kds-heal-2026-05-XX.md`

**Acceptance**:
- 8/8 tests GREEN
- 8/8 P0 fermés (verifiable visuellement)
- 0 raw label FR visible
- Contrast ≥4.5:1 sur touts les texts

**RED-team**: Mode QA + RED. Brief : "Le visual capture est-il bien le `/kds` réel (port 8000) ou un mock ? Empty state KDS (0 order) cohérent ? RTL pour AR fonctionne ? Touch target 44px réel ou juste CSS `padding`?"

---

### Prompt #17 — POS Vanilla wizard LOCK surgical patch ARIA + 44px + i18n (Sprint Week 6, **OWNER-GATE**)

**Covers items**: P1-21 (POS Vanilla 0 ARIA, 32px touch, 100% FR hardcoded)

**Mission**: **PLAN + LOCK doc** pour surgical patch POS wizard : ARIA + 44px touch targets + `var(--pos-v5-brand-red)` + i18n keys `pos.wizard.*`. ~200 LOC diff owner-gate.

**Context**: Agent 6 §"POS Vanilla" 31/100 — 0 ARIA, 34 click handlers sur `<div>`, touch 32px WCAG 2.5.5 fail, 100% FR hardcoded, palette rouge legacy. **Frozen-zone strict** : `public/js/pos-wizard.js` + `public/css/pos-wizard.css` + `resources/views/admin-pos-v4.blade.php` (CLAUDE.md §7 frontend).

**Pipeline step**: Plan + LOCK doc (NO Build this sprint)

**Agent type**: Architect + Designer + Security parallèles (3 read-only). Implementer NE PAS dispatcher ce sprint.

**Frozen-zone**: **Yes** — `public/js/pos-wizard.js` + `public/css/pos-wizard.css` + `resources/views/admin-pos-v4.blade.php` (CLAUDE.md §7 frontend POS Vanilla wizard).

**TDD instruction**: Plan doit définir tests : Vitest sur DOM querySelectorAll `[role]` ≥ N, touch target getBoundingClientRect ≥44px, i18n key presence test.

**Steps**:
1. **Invoquer skill `/lock-plan`** : `LOCK_POS_WIZARD_SURGICAL_2026-05-XX.md`. Files = `[pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php]`. Reason = "WCAG 2.5.5 + ARIA + i18n surgical patch ~200 LOC".
2. **Plan détaillé** : Architect liste chaque `<div onClick>` → patch vers `<button role="button" tabindex=0>` + ARIA. Designer mappe palette legacy → `var(--pos-v5-brand-red)` token CSS. Liste 18-30 i18n keys FR à externaliser.
3. **Scope strict ≤200 LOC** diff total.
4. **Pas de refactor structurel** (wizard structure reste intacte).
5. **Tests plan** : Vitest specs définies dans le plan.
6. **Rollback plan** : git revert PR atomic.
7. **Owner sign-off slot** dans LOCK doc.

**Constraints**:
- **PLAN ONLY**. Aucun code modifié.
- **OWNER-GATE strict** (CLAUDE.md §7 + §10).
- Wizard structure = INTOUCHABLE. Seulement ARIA + sizes + tokens + i18n.

**Output**:
- LOCK doc
- `plans/MASTER_POS_WIZARD_SURGICAL_2026-05-XX.md`
- RED-team review du plan

**Acceptance**:
- LOCK doc complet
- Plan ≥2 pages avec file:line
- ≤200 LOC budget respecté

**RED-team**: Mode RED. Brief : "Le surgical patch préserve-t-il vraiment le wizard logique (no behavior change) ? ARIA roles sur `<div>` suffisent vs vrai `<button>` (focus + Enter handler) ? Token CSS palette = pas de cascade legacy override ? i18n keys cassent-elles dynamic Blade rendering ?"

---

## §5 Sprint Week 7-12 — P1 architecture long-terme (5 prompts)

> **Critère sortie Sprint 7-12** : Frontend layering plan (API client + Pinia + Composition migration), 39 `withoutGlobalScope` review + assertions, OrderService split QueryService/CommandService, multi-tenant catalog migration plan, 23 `assertTrue(true)` fixed.

---

### Prompt #18 — Frontend API client layer + Pinia migration plan (Sprint Week 7-8)

**Covers items**: P1-18 (frontend zero layering), P1-30 (Vuex 113 flat)

**Mission**: **PLAN** API client layer + Pinia migration (Vuex → Pinia + Options API → Composition API). Pas exécution massive ce sprint, pilote POS-V5 only.

**Context**: Agent 1 §P1 — 0 API client, 113 Vuex modules flat, 308 Options API vs 10 Composition. POS-V5 branch déjà amorcée (référence audit).

**Pipeline step**: Plan + Implement pilote 1 module

**Agent type**: Architect (plan détaillé) + Implementer subagent (pilote POS-V5 1 module)

**Frozen-zone**: No (mais POS Vanilla `public/js/pos-wizard.js` reste frozen — POS-V5 = Vue composant séparé)

**TDD instruction**: Pilote module : Vitest tests sur store Pinia + composable.

**Steps**:
1. **Plan API client** : `resources/js/api/` 1 fichier par controller backend. Pattern : `api/orders.js` exporte `listOrders()`, `createOrder()`, `getOrder(id)`. Wrap axios. Plan `plans/MASTER_API_CLIENT_LAYER.md`.
2. **Plan Pinia migration** : mapping 113 Vuex modules → Pinia stores. Grouping par bounded context (orders, catalog, users, fiscal, kiosk).
3. **Pilote POS-V5** : 1 module Vuex (e.g. `orders.js`) → Pinia `useOrdersStore` + composant POS-V5 1 écran refactor Composition API.
4. **Tests pilote** : Vitest sur store + composable.
5. **Doc migration recipe** : `docs/frontend/PINIA_MIGRATION_RECIPE.md`.

**Constraints**:
- Pilote only ce sprint. Pas de Big Bang.
- Tests régression POS existant pas cassés.

**Output**:
- 2 plans + 1 pilote module + recipe doc

**Acceptance**:
- 1 store Pinia + 1 composable Composition + tests GREEN
- 2 plans ≥3 pages chacun

**RED-team**: Mode RED. Brief : "Le pilote est-il vraiment représentatif des 112 autres modules ? Pinia stores reset-il properly entre tests ? Le composable Composition leak-il quand component destroy ?"

---

### Prompt #19 — 39 occurrences `withoutGlobalScope(BranchScope)` review + assertions (Sprint Week 8-9)

**Covers items**: P1-19 (39 withoutGlobalScope occurrences)

**Mission**: Auditer les 39 occurrences `withoutGlobalScope(BranchScope::class)` + ajouter assertion `auth()->user()->branch_id === $resource->branch_id` dans les contrôleurs critiques + tester chaque case.

**Context**: Agent 1 + Agent 3 — chaque occurrence est potentiellement une fuite multi-tenant. Combinées avec tokens wildcard = surface IDOR cross-branch.

**Pipeline step**: Audit + TDD + Implement

**Agent type**: Security + DBA parallèles (read-only audit) + Implementer subagent (séquentiel sur sites needing assertion)

**Frozen-zone**: No (BranchScope.php lui-même est frozen mais les callers ne le sont pas — on n'édite pas le scope, on édite les callers)

**TDD instruction**: Pour chaque site avec assertion ajoutée : test RED user branch A → user branch B resource → assert 403.

**Steps**:
1. **Audit grep** : `grep -rn "withoutGlobalScope(BranchScope::class)" app/ --include="*.php"` → liste 39 sites.
2. **Categorize** : (a) pre-auth lookup (e.g. login by email) = LÉGITIME, (b) cross-branch admin (super_admin) = LÉGITIME si gated, (c) IDOR risk = BUG.
3. **Pour (c)** : ajouter assertion `abort_unless(auth()->user()->branch_id === $resource->branch_id || auth()->user()->isSuperAdmin(), 403)`.
4. **Tests RED→GREEN** un par site IDOR-risk.
5. **Doc `docs/security/BRANCH_SCOPE_EXCEPTIONS.md`** : 39 sites listed avec justification (a/b/c).

**Constraints**:
- Pas de touch `BranchScope.php` (frozen-zone §7).
- Pre-auth lookups intacts (Sanctum recursion).

**Output**:
- PR avec assertions + tests + doc

**Acceptance**:
- 39/39 sites categorized
- 100% des (c) ont assertion + test
- Doc complet

**RED-team**: Mode SECURITY. Brief : "Les 39 sites couvrent-ils Eloquent + raw DB queries ? Y a-t-il des assertions bypass-ables via super_admin role spoofing ? Le `branch_id` du resource peut-il être null (legacy data) → fail-open ?"

---

### Prompt #20 — 23 `assertTrue(true)` placeholders → vrais tests (Sprint Week 9)

**Covers items**: P1-23 (23 assertTrue(true) dans fiscal/payment/state-machine)

**Mission**: Remplacer 23 `assertTrue(true)` placeholders par vrais tests behavioral.

**Context**: Agent 5 §"Tests" P1 — 23 occurrences dans paths fiscal/payment/state-machine. Tests verts mais ne testent rien.

**Pipeline step**: Plan + Implement (tests only)

**Agent type**: Tester subagent + QA subagent

**Frozen-zone**: No (tests directory)

**TDD instruction**: Pour chaque `assertTrue(true)` : identifier intent du test (commentaire ou nom méthode) → écrire vrai test → vérifier qu'il FAIL si invariant cassé.

**Steps**:
1. **Grep audit** : `grep -rn "assertTrue(true)" tests/ --include="*.php"` → 23 hits.
2. **Categorize** : fiscal / payment / state-machine / autre.
3. **Pour chaque** : déterminer ce que le test devrait assert (read context lines + method name).
4. **Implémenter assertion réelle** : e.g. `assertTrue(true)` dans `FiscalSequenceAllocTest::test_alloc_gap_free` → `assertSame($previous + 1, $current)`.
5. **Mutation test** : pour 3 tests critiques (fiscal HMAC chain, payment idempotency, state-machine transition), faire mutation (changer +1 → -1 dans code) → vérifier que test FAIL.

**Constraints**:
- Pas de touch du code prod (sauf bug discovered → ticket).

**Output**:
- PR avec 23 tests refactored
- Mutation evidence dans `reports/testing/MUTATION_2026-05-XX.md`

**Acceptance**:
- 23/23 `assertTrue(true)` éliminés
- 3 mutation tests GREEN→RED→GREEN cycle prouvé

**RED-team**: Mode RED. Brief : "Les nouveaux tests fail-ils vraiment quand l'invariant casse ? Y a-t-il d'autres patterns no-op (`assertNotNull($x)` quand x est toujours non-null, `assertCount(0, [])`) ? Coverage augmente-t-il statistiquement ?"

---

### Prompt #21 — Multi-tenant catalog migration plan + onboarding command (Sprint Week 10-11, **PLAN ONLY OWNER-GATE**)

**Covers items**: V2 prep (Phase 3 du verdict §7), foundation pour P0-22 (branch.status drift fix)

**Mission**: **PLAN ONLY** — Multi-tenant catalog migration (items + item_categories + taxes + coupons + item_attributes + item_variations + item_extras avec `branch_id`) + onboarding command `php artisan foodking:onboard`.

**Context**: Verdict §1 Architecture + Agent 3 §"SaaS multi-tenant 8/100" — items sans branch_id, impossible 2 menus différents. MultiTenantModelTrait stub.

**Pipeline step**: Plan ONLY

**Agent type**: Architect + DBA + Security parallèles read-only

**Frozen-zone**: No pour le plan. Yes pour exécution (touche `BranchScope.php` config + migrations production data).

**TDD instruction**: Plan doit définir tests : 2 restaurants pilote, menus différents, isolation cross-tenant verified.

**Steps**:
1. **Plan migration** : pour 7+ tables catalogues, schéma migration nullable `branch_id` + backfill `branch_id=1` (Le Cayenne) + `BranchScope` ajouté model. Inheritance chain : `branch_id IS NULL` = global, `branch_id=X` = override.
2. **Service `CatalogResolverService::resolve($branchId)`** : merge global + override.
3. **Onboarding command** : `php artisan foodking:onboard --restaurant="X" --plan=starter --siret=...` → crée Branch + User owner + seed menu vide + génère DB backup baseline.
4. **Super-admin Spatie separation** : `super_admin` (2FA cross-tenant) / `chain_owner` (multi-branch) / `branch_manager` (1 branch).
5. **Bug `branch.status=1 vs 5` fix** dans plan (PersistCatalogChangedToOutbox.php:38-41 + BRAIN.md).
6. **Budget + risks + rollback**.

**Constraints**:
- **PLAN ONLY**. Aucune migration jouée.
- **OWNER-GATE** sur exécution (CLAUDE.md §10 architecture decision).

**Output**:
- `plans/MASTER_MULTI_TENANT_CATALOG_PHASE3_2026-05-XX.md`

**Acceptance**:
- Plan ≥5 pages
- 7+ migrations détaillées
- Onboarding command CLI signature précise
- RED-team review

**RED-team**: Mode RED. Brief : "Migration backfill préserve-t-elle vraiment Le Cayenne menu ? Inheritance chain ambigüe (branch X override mais aussi NULL global, merge order) ? Onboarding command crée-t-il aussi NF525 fiscal_sequence init ? Super_admin 2FA mandatory enforcement ?"

---

### Prompt #22 — Doctrine consolidation : CLAUDE.md vs AGENTS.md + ARCHITECTURE.md (Sprint Week 11-12)

**Covers items**: P1-26 (CLAUDE.md vs AGENTS.md contradiction)

**Mission**: Décider SSOT (CLAUDE.md), archiver AGENTS.md → `AGENTS_LEGACY_2026-03.md`, réécrire `docs/ARCHITECTURE.md` comme cible (vs `ARCHITECTURE_TECHNIQUE.md` qui décrit existant).

**Context**: Agent 8 §"Doctrine drift" — `CLAUDE.md:10-13, 70-72` mandate Claude executor, `AGENTS.md:113-120, 153` interdit edits product Claude. Les deux load simultanés. Reality observed = CLAUDE.md suivi.

**Pipeline step**: Plan + Implement (docs)

**Agent type**: Architect + orchestrator inline

**Frozen-zone**: No

**TDD instruction**: Pas de TDD. Mais owner doit relire et signer.

**Steps**:
1. **Rename** `AGENTS.md` → `AGENTS_LEGACY_2026-03.md` + header explicite "ARCHIVED — see CLAUDE.md as SSOT".
2. **Update CHANGELOG.md** : entrée "Archived AGENTS.md, CLAUDE.md is now sole operating doctrine for Claude Code sessions".
3. **Réécrire `docs/ARCHITECTURE.md`** : description **cible** (Laravel 11, Composition API, Pinia, frozen-zones gate réel).
4. **Garder `docs/ARCHITECTURE_TECHNIQUE.md`** : description **état actuel**. Header explicite "État au 2026-05-XX, voir ARCHITECTURE.md pour cible".
5. **Update README.md** : pointer CLAUDE.md + ARCHITECTURE.md.

**Constraints**:
- Pas de delete fichier (rename only — historique git).

**Output**:
- PR docs renames + rewrites

**Acceptance**:
- AGENTS.md absent (renamed)
- ARCHITECTURE.md décrit cible
- README pointe SSOT correct

**RED-team**: Mode RED. Brief : "Restent-ils des références à AGENTS.md dans code ou docs (`grep -rn AGENTS.md`) ? CLAUDE.md self-consistent post-update ? README onboarding cohérent ?"

---

## §6 Generic templates (réutilisables)

### Template A — RED-team adversarial dispatch (à utiliser après chaque implémenter)

```
You are the RED-team adversarial reviewer for FoodKing.
Mode: RED (hostile dispute — assume implementer + reviewers were sloppy).

## Project context
- FoodKing = SaaS restaurant (POS + Kiosk + KDS + OSS + Mobile + admin)
- Stack: Laravel 9.52 + Vue 3 + Vanilla JS POS wizard + MySQL + Sanctum + Spatie
- NF525 fiscal compliance — pricing SSOT backend, fiscal_sequence_no monotonic, audit chain HMAC
- Multi-tenant via BranchScope on 13 models
- Canonical: CLAUDE.md §§ 6-13 + memory/feedback_adversarial_audit_pattern.md
- Audit context: reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md

## Your mission
Dispute the GREEN verdict of the cycle on `<<branch / PR / file:line scope>>`.
Your reputation depends on finding the P0 that others missed.

## What to look hard at (all of these)
- Pricing SSOT — frontend sends only item_id, quantity, option_ids
- composition_snapshot frozen at creation
- fiscal_sequence_no race / gap / negative
- audit_logs HMAC chain integrity
- z_reports append-only
- BranchScope withoutGlobalScope bypasses (39 occurrences)
- Sanctum kiosk:order ability boundary
- Idempotency double-fire
- Spatie RBAC FormRequest gaps
- OWASP top-10
- Secrets in diff
- Frozen-zone touched without LOCK gate (CLAUDE.md §7)
- Scope creep — features beyond task
- Tests vert hypnose (memory feedback_silent_html_masquerade.md)
- Mocked DB hiding bugs
- N+1 in loops
- Race conditions in async
- A11y regressions (44px touch, contrast, ARIA, focus)
- i18n keys raw in prod (kiosk.foo, Label.X)
- Console warnings

## Discipline
- Cite file:line for EVERY finding
- READ-ONLY — never edit
- Run `git diff main..HEAD` first to scope
- Verify with grep/Read before asserting
- DO NOT fabricate. If clean, say "RED dispute: nothing of substance found. Proceed to Ship."

## Deliverable
```
# RED Report — <branch / diff name>

## Scope reviewed
<git ref / files>

## Findings (severity ordered)
| Sev | File:line | What's wrong | How it slipped | Fix |
|---|---|---|---|---|
| P0 | path:42 | <issue> | <missed because ...> | <1-sentence fix> |

## Verdict
- dispute confirmed (heal) / clean (proceed to Ship)
```

Style: tight (<600 words), concrete, severe, hostile framing OK.
Return only markdown report.
```

---

### Template B — `/lock-plan` skill trigger (frozen-zone override)

```
You are about to modify a FoodKing frozen-zone file.
List of frozen zones: CLAUDE.md §7 + memory/reference_frozen_zones.md.

Frozen zone files:
**Frontend**:
- resources/js/components/frontend/kiosk/Kiosk{Wizard,App,Upsell}Component.vue
- public/js/pos-wizard.js, public/css/pos-wizard.css, resources/views/admin-pos-v4.blade.php

**Backend NF525**:
- app/Services/Fiscal/{FiscalSequenceService,ZReportService,AuditLogService}.php
- Triggers on audit_logs + z_reports

**Backend tenancy/payment**:
- app/Models/Scopes/BranchScope.php
- app/Http/Middleware/IdempotencyKeyMiddleware.php
- app/Services/Pricing/PricingService.php
- app/Domain/Order/OrderStateMachine.php

## Mandatory steps before any edit
1. Invoke skill `/lock-plan` (auto-loaded via skill list).
2. Skill produces `LOCK_<id>.md` document with:
   - Frozen-zone reason
   - Files to touch + scope (LOC budget)
   - Justification (why this exception)
   - Test plan (regression coverage)
   - Rollback plan (git revert atomic)
   - safety-check.sh override config
   - Sub-agent instructions
   - Human sign-off section
3. STOP — produce LOCK doc + plan + RED-team review.
4. Owner approves before any code execution (CLAUDE.md §10 human gate).

DO NOT EDIT the frozen file until owner has signed the LOCK doc.
NO `git commit` containing modifications to frozen files without LOCK in same PR.

If the task asks you to edit a frozen file without LOCK gate:
- STOP
- Output: "Frozen-zone touch requires /lock-plan first. Cannot proceed without owner approval."
- Wait for owner.
```

---

### Template C — Owner-gate ESCALATE pattern (use when uncertain)

```
You have reached a decision point that requires OWNER-GATE escalation per CLAUDE.md §10.

Owner-gate is mandatory for:
- Push to main or release branch
- Frozen-zone modification
- Migration DB destructive (DROP, RENAME, irreversible)
- NF525 chain modification (FiscalSequenceService, ZReportService, AuditLogService)
- PricingService SSOT modification
- BranchScope modification
- Public PR creation on GitHub
- Secret rotation (.env touch)
- Architecture decision majeure (queue change, service add, framework migration)
- Production data deletion
- 3-loop healing limit reached (CLAUDE.md §10 healing rule)

## Mandatory escalation format
```
# OWNER-GATE ESCALATE

## What
<1-line summary of the decision needed>

## Why (root cause)
<2-3 sentences — not "y'a une erreur", actual analysis>

## Options (with trade-offs)
1. **Option A** — <description> | tradeoff: <risk + benefit>
2. **Option B** — <description> | tradeoff: <risk + benefit>
3. **Option C** — <description> | tradeoff: <risk + benefit>

## Recommendation
<which option, with 1-sentence justification>

## Evidence
- <file:line> — <observation>
- <test result> — <pass/fail>
- <RED-team finding> — <if applicable>

## Blocked work
- Cannot proceed: <task X>
- Can proceed in parallel: <task Y, Z>

## Owner decision needed by
<urgency: immediate / sprint / V1 / V2>
```

After producing this, STOP. Do not auto-execute any option. Wait for owner.

Anti-pattern: "I'll just pick the safer option" — that's still a decision, escalate it.
Anti-pattern: "It's probably fine" — never. Escalate.
Anti-pattern: silent skip — escalate explicit, never silent.
```

---

## §7 Coverage map (audit verdict §5 ↔ prompts)

| Verdict §5 item | Severity | Prompt(s) | Sprint |
|---|---|---|---|
| 1 AWS keys leaked | P0 | #1 | W1 |
| 2 RCE + tokens wildcard | P0 | #2 | W1 |
| 3 IDOR PosOrderController | P0 | #1 + #2 + #19 | W1 + W8 |
| 4 No backups | P0 | #3 | W1 |
| 5 No alerting | P0 | #4 | W1 |
| 6 Stripe cents truncation | P0 | #5 | W1 |
| 7 Dual Order/FrontendOrder | P0 | #6 (PLAN) | W2-3 |
| 8 Mobile allergens fabricated | P0 | #8 | W2 |
| 9 Mobile promo stub | P0 | #8 | W2 |
| 10 Runbooks DRAFT | P0 | #9 | W2 |
| 11 POS cash-trail untested | P0 | #10 | W2 |
| 12 OrderService state-machine bypass | P0 | #7 | W2-3 |
| 13 PHPSpreadsheet CVE | P0 | #12 | W4-6 |
| 14 Laravel 9.52 EOL | P0 | #12 (PLAN) | W4-6 |
| 15 E2E non-bloquant | P0 | #11 | W3 |
| 16 14 controllers DB inline | P1 | #7 (split plan) | W2-3 |
| 17 apply() half-adopted | P1 | #7 | W2-3 |
| 18 Frontend zero layering | P1 | #18 | W7-8 |
| 19 39 withoutGlobalScope | P1 | #19 | W8-9 |
| 20 KDS UX 3.2/10 | P1 | #16 | W5-6 |
| 21 POS Vanilla 0 ARIA | P1 | #17 (PLAN LOCK) | W6 |
| 22 branch.status=1 vs 5 | P1 | #21 | W10-11 |
| 23 23 assertTrue(true) | P1 | #20 | W9 |
| 24 Frozen-zones gate cassé | P1 | #13 | W4 |
| 25 No deploy/rollback script | P1 | #15 | W5 |
| 26 CLAUDE.md vs AGENTS.md | P1 | #22 | W11-12 |
| 27 No TPE driver | P1 | (deferred V1.x — Agent 7 backlog) | — |
| 28 88 endpoints sans FormRequest | P1 | #14 | W4-5 |
| 29 Stress sqlite théâtral | P1 | #11 | W3 |
| 30 Vuex 113 flat | P1 | #18 | W7-8 |

**Total** : 30 items couverts par 22 prompts (certains prompts couvrent plusieurs items connexes pour cohérence). Item P1-27 (TPE driver) déférré V1.x avec Agent 7 backlog — pas adressé dans ce pack car non-bloquant V1 single-restaurant.

---

## §8 Discipline générale (à rappeler à Claude session par session)

1. **Toujours invoquer skill `superpower-gstack`** au début de chaque prompt non-trivial (auto-trigger sur "GStack pipeline" présent dans missions).
2. **Toujours RED-team APRÈS implémenteur, AVANT ship** (template §6-A).
3. **Toujours LOCK doc AVANT frozen-zone touch** (template §6-B).
4. **Toujours ESCALATE owner sur décisions §10** (template §6-C).
5. **Toujours visual gate si frontend touché** (CLAUDE.md §6).
6. **Toujours scope-minimal** ≤30 LOC inline OU dispatcher Implementer subagent (>30 LOC).
7. **Jamais auto-push remote** (CLAUDE.md §10 human gate).
8. **Jamais auto-commit "up"** (Agent 8 §44 occurrences — détruit le trail).
9. **Jamais skip test régression** (3-loop healing limit, puis ESCALATE).
10. **Jamais inventer file:line** — cite réel ou STOP.

— Fin AGENT_DISPATCH_PACK 2026-05-16.
