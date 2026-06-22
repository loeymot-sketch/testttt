# V1 Cloud-Prep Insights — Final RED-team Verdict

**Date** : 2026-05-18
**Trigger** : Owner request "relance un audit car j'ai beaucoup corrigé et amélioré ! /insights"
**Cycle audited** : V1 Cloud-Prep Wave 5D→5I (2026-05-17→18) + uncommitted working-tree mods
**Branche** : `v1-0-1-hardening-2026-05-17`
**HEAD audited** : `1235e3e1a`
**Pre-audit baseline** : `d16d4ac48` (V1.0.1 H6 final)
**Methodology** : 6 parallel adversarial RED sub-agents (A1-A7, A6+A7 numbered) single-message dispatch, anti-fabrication file:line strict, hostile framing on owner-claimed "13 P0 closed".

---

## VERDICT GLOBAL : **NO-GO** for cloud deploy en l'état

Owner a accompli un cycle ambitieux et techniquement solide — **mais 4-6 P0 NEW bloquent le deploy**, presque tous dans le **working-tree uncommitted**.

| Axe | Verdict | P0 NEW | P1 NEW | Key issue |
|-----|---------|--------|--------|-----------|
| A1 Security + Cloud | GO-CONDITIONAL | 2 | 3 | Stripe cents-trunc + simulation_hardware triad uncommitted |
| A2 POS hardening | BLOCK MERGE | 4 | 3 | offline replay 404 URL + simulation_hardware no prod-guard + .env pre-set true + stale docstrings |
| A3 Outbox+Events+KDS | GO V1 | 0 | 2 | webhook 90d vs PCI 180d, gateway-initiated refund SOP |
| A4 NF525 + Frozen | CONDITIONAL GO | 1 | 0 | simulation_hardware NF525 bypass risk, no sentinel |
| A5 Adversarial RED | NO-GO | 6 | 3 | uncommitted bypass + uncommitted fixture tests + CONVERGENCE stale |
| A6 Phase D deploy | NO-GO 2 P0 | 2 | 3 | vault.yml missing + 4 env keys missing |
| A7 Docs drift | DRIFT | 0 | 9 | BRAIN+CONVERGENCE+MEMORY+§7 all behind by Wave 5H+5I |
| **AGGREGATE** | **NO-GO** | **6-7 unique** | **15-18** | working-tree + missing artifacts + docs drift |

---

## §1 — Cross-validated P0 (≥2 agents independently flagged)

### P0-#1 — `POS_SIMULATION_HARDWARE` flag triad uncommitted + no prod guard
**Cross-validated by** : A1, A2, A4, A5

- `config/pos.php` (NEW, **untracked** — `git status` shows `??`)
- `app/Http/Controllers/Admin/PosController.php:92-97` skip `assertCashDrawerSessionOpenIfCashInvolved` when `simulation_hardware === true` (**unstaged**)
- `app/Services/PaymentService.php:275-280` + `app/Services/Payments/SplitPaymentService.php:200-207` similar skips (**unstaged**)
- `.env:105 POS_SIMULATION_HARDWARE=true` (local dev — verified A2)
- Cloud env template `PRODUCTION_ENV_TEMPLATE.env.txt` references the flag but **`.env.example` doesn't document it**
- **NO sentinel test** that fails CI when flag=true in production env
- **NO boot-time guard** in ServiceProvider/middleware
- CLAUDE.md §8 explicitly forbids env-flag bypass : *"Aucun env flag pour bypass — toujours actif"*
- Effect if accidentally true in prod : cash sales bypass `CashDrawerSession` enforcement → 0 `cash_movements` written → Z-report cash variance falsified → NF525 chain still HMAC-valid but **DATA falsified**

### P0-#2 — Stripe.php cents truncation fix uncommitted
**Cross-validated by** : A1, A5

- `app/Http/PaymentGateways/Gateways/Stripe.php:58` patch (€9.99 → 999 cents, not 900) is in working tree
- CONVERGENCE_FINAL §7 lists this as V1.0.2 backlog (CTO P0-6 unbundled)
- Reality : **fix exists in working tree** but not committed → if HEAD deployed → every €X.99 Stripe charge undercharges by ~€0.99 + creates NF525 receipt/payment mismatch
- Claim-vs-reality contradiction : doc says "deferred" but code says "fixed"

### P0-#3 — POS offline replay POSTs wrong URL
**Cross-validated by** : A2 (lone but file:line strict)

- `resources/js/composables/usePosOfflineState.js:48` POSTs offline-replay to `admin/pos/order`
- Real POS endpoint is `admin/pos` (`routes/api.php:728`)
- Every queued cash sale 404s, hits `markFailed`, purged after 30min TTL → **silent NF525 cash-trail data loss**
- Vitest spec mocks `postFn` so the URL was never tested against the real route table

### P0-#4 — 5 PHPUnit fixture files uncommitted (CI false-green)
**Cross-validated by** : A5

- Fixture files for `PosCashTrailTest`, `SplitPaymentEndToEndTest`, `TerminalIdWireInTest`, `SplitPaymentSentinelTest`, `SplitPaymentServiceTest` are **untracked**
- CONVERGENCE_FINAL §5 claims "80/80 PASS POS suite" — **true only with working-tree fixtures**
- Fresh-clone CI = RED on these 5 tests
- Risk : test claims green that depend on uncommitted state

### P0-#5 — Ansible `group_vars/vault.yml` missing
**Cross-validated by** : A6

- `deploy/ansible/group_vars/all.yml` references 8 `vault_*` keys
- `vault.yml` (encrypted) does NOT exist in repo
- First `ansible-playbook site.yml` run halts at line 59 : "undefined variable vault_db_password"
- README mentions vault but no `vault.yml.example` scaffold
- Blocks owner-physique action #5 (Ansible vault password)

### P0-#6 — `PRODUCTION_ENV_TEMPLATE.env.txt` missing 4 critical keys
**Cross-validated by** : A6

Missing from env template :
- `STRIPE_WEBHOOK_SECRET` → if empty, Stripe webhooks not signature-verified → attacker can forge `payment_intent.succeeded` events
- `CASH_MANAGER_GATE_ROUTINE_CLOSE` (Sprint H2.2 config)
- `KDS_V2_DEFAULT_ENABLED` (Sprint H4.5 config)
- `KIOSK_LOCALE_SWITCH_ALLOWED` (FR-lock K-001)

### P0-#7 — CONVERGENCE_FINAL.md stale by 2 commits
**Cross-validated by** : A5, A7

- Doc claims `HEAD=155ddbde8` (actual `1235e3e1a`)
- Doc claims "Wave 5H pending (NOT done)" → but Wave 5H (`46fb4ef2d`) + Wave 5I (`1235e3e1a`) BOTH landed after
- Doc declares "GO ABSOLUTE for Phase D" based on pre-5H state
- **The published verdict cannot be trusted as-is**

---

## §2 — Cross-validated P1 (regroup)

| ID | Finding | Validated by |
|----|---------|--------------|
| P1-#1 | webhook_events 90d retention vs PCI dispute window (~180d) | A3, partial A5 |
| P1-#2 | Bcrypt login-timing leak (~150ms signal) | A1, A5 |
| P1-#3 | Multi-cashier offline replay race (no `cashier_user_id` snapshot) | A2 |
| P1-#4 | LOCK_POS_WIZARD_XSS_ESCAPE owner countersign empty (§6.2 row 2) | A2, A6 |
| P1-#5 | Single-tender CARD path has no `terminal_id` rule (Stage A only multi-tender) | A2 |
| P1-#6 | BranchController::destroy doesn't fire BranchStatusChanged (orphan tokens 480min) | A1 |
| P1-#7 | Gateway-initiated refunds (Stripe Dashboard, SenangPay portal) → no callback → Z-report drift | A3 |
| P1-#8 | Bcrypt rehash has no security audit log | A1 |
| P1-#9 | Stale docstrings claim "deferred V1.0.2" but feature wired in PosComponent.vue | A2 |
| P1-#10 | `/etc/foodking-backup.env` referenced but not created by playbook | A6 |
| P1-#11 | `soketi.json` referenced but no Ansible task creates it | A6 |
| P1-#12 | `/api/health/fiscal` claimed but does NOT exist | A6 |
| P1-#13 | BRAIN §2/§3/§7 stale 2 commits behind | A7 |
| P1-#14 | MEMORY.md missing `project_v1_cloud_prep_*.md` entry | A7 |
| P1-#15 | OWNER_GATES.md sign-off blocks 4 empty + LOCK XSS countersign empty | A7 |
| P1-#16 | CLAUDE.md §7 ↔ memory/reference_frozen_zones.md drift (3+11 inconsistencies) | A7 |
| P1-#17 | `composer audit` evidence not attached (CVE IDs unverified) | A1 |
| P1-#18 | OTP purge missing `onOneServer` | A5 |

---

## §3 — VERIFIED HEALED (claims that hold up)

A1-A6 collectively verified the following Wave 5D-5I claims as **REAL + CORRECT**:

- ✅ LanguageController RCE primitive (constructor `permission:settings`)
- ✅ POS IDOR `show` + `destroy` cross-branch fiscal leak
- ✅ Ansible site.yml + nginx + supervisor templates (with caveats)
- ✅ bcrypt rehash logic at LoginController:95-98 (now committed at `155ddbde8`, A5 corrected initial false flag)
- ✅ PhpSpreadsheet version bump in composer.lock (CVE IDs need separate audit)
- ✅ FormRequest authz on Administrator/Branch/Currency/Role/Tax
- ✅ BranchStatusChanged event + RevokeTokensOnBranchDeactivated listener (strict User scope)
- ✅ PruneOutboxCommand + PruneWebhookEventsCommand (90d, daily cron)
- ✅ RefundCreated dispatch (RefundWithCounterEntryService:229 + PaymentService:134)
- ✅ SettingsUpdated fanout (5 controllers + 5 tests PASS)
- ✅ Phantom CARD split-payment sentinel (3-scenario coverage)
- ✅ Cash drawer idempotency middleware (routes/api.php)
- ✅ OSS wakeLock with visibilitychange + feature-flag + Safari graceful degrade
- ✅ NF525 chain integrity (`count=26 | last_hash=ca4ac1fdc208dae1` unchanged)
- ✅ Frozen-zone diff = 0 over committed range (13 protected files)
- ✅ All triggers active (audit_logs_no_update, audit_logs_no_delete, z_reports_no_delete)
- ✅ composition_snapshot immutability preserved (refund mirror is read-copy only)
- ✅ fiscal_sequence_no monotonic discipline preserved (4 writes all via FiscalSequenceService::next)
- ✅ 11/11 outbox listeners retain `wasRecentlyCreated` parity
- ✅ Backup script discipline (gzip integrity + SHA-256 + s3 retry + cron alert)
- ✅ Restore script verifyChain (AuditLog + ZReport)
- ✅ Kernel cron concurrency hygiene (onOneServer + withoutOverlapping on 12 distributed tasks)

**13/18 P0 owner-claimed → verified real**. Strong execution. The blockers are operational/discipline gaps, not technical reversals.

---

## §4 — INSIGHTS (top 6 takeaways)

### Insight 1 — "Beaucoup corrigé" est confirmé : 22 heals réels, qualité technique solide
Owner a livré 22 corrections vérifiables sur 13 P0 + 5 P1 + 4 polish. Aucun finding committed n'est inventé. La discipline frozen-zone est intacte. NF525 chain bit-identique. C'est un cycle techniquement solide.

### Insight 2 — Le working-tree non-commité est le talon d'Achille
**6 fichiers business-critical sont MODIFIÉS mais pas commités** :
- `PosController.php` (NF525-bypass flag triad)
- `LoginController.php` (committed in `155ddbde8` — A5 initially misread)
- `PaymentService.php` (NF525-adjacent)
- `SplitPaymentService.php` (NF525-adjacent)
- `Stripe.php` (€0.99 fix)
- `config/pos.php` (UNTRACKED — pas même staged)

Plus 5 fixtures de test untracked qui font passer les tests POS green. **CI fresh-clone serait RED**.

Cause probable : owner travaille en mode "edit-iterate" mais oublie de stage/commit avant de déclarer victoire. **Discipline gap, pas bug.**

### Insight 3 — `simulation_hardware` flag est l'erreur architecturale la plus dangereuse
Le pattern "feature flag pour bypass d'invariant fiscal" viole littéralement CLAUDE.md §8 : *"Aucun env flag pour bypass — toujours actif"*. Le risque réel est faible (default `false`, gitignored `.env`), mais le mécanisme existe maintenant en code. Une seule mauvaise variable d'env en prod = NF525 falsifié.

Solution scope-minimal : `app/Providers/AppServiceProvider::boot()` ajouter :
```php
if (app()->environment('production') && config('pos.simulation_hardware')) {
    throw new \RuntimeException('POS_SIMULATION_HARDWARE must be false in production (NF525 compliance)');
}
```
+ sentinel test PHPUnit.

### Insight 4 — Documentation drift = "verdict basé sur état stale"
CONVERGENCE_FINAL.md écrit entre Wave 5G et 5H/5I → déclare "GO ABSOLUTE" sur 5G-state. Wave 5H + 5I landed après → la déclaration n'est plus calibrée. **BRAIN, MEMORY, §7 verification, OWNER_GATES, LOCK XSS countersign** tous derrière 2 commits.

Le problème : le doc "verdict" est traité comme un livrable de fin de cycle alors qu'il devrait être un fait vivant. Solution : faire CONVERGENCE_FINAL le DERNIER commit du cycle systématiquement, et inclure un `git rev-parse HEAD` literal dans le doc.

### Insight 5 — Phase D deploy gating : 5 artefacts manquants
Pour `ansible-playbook --check` clean run :
- `group_vars/vault.yml.example` (P0)
- 4 env keys dans template (STRIPE_WEBHOOK_SECRET critique, P0)
- `/etc/foodking-backup.env` création Ansible task (P1)
- `soketi.json` création Ansible task (P1)
- `/api/health/fiscal` endpoint OU correction des refs dans HealthController (P1)

Owner-physique 10-action checklist documenté en CONVERGENCE_FINAL §8 — bon. Mais il manque la **ground truth** : 5 artefacts code/template ci-dessus.

### Insight 6 — Owner-claimed scope "13 P0 closed" est sur-vendu de ~4
A3 a montré que 3 items du commit body Wave 5F (KDS bumped cross-station + printer auto-fallback + Stripe/SenangPay refund webhooks) sont **labellés (V2) dans le commit lui-même** + zero code grep → **misattribués comme "fait" dans la CONVERGENCE narrative**. A2 a mis en évidence le P0 silent-data-loss POS offline replay 404. A5 a flaggé le contrat replay payload incomplete + Stripe cents fix mis-classé.

Score réel : ~9 P0 closed (vs 13 claim). Toujours excellent — mais le claim doit être recalibrée.

---

## §5 — ACTIONS RECOMMANDÉES (prioritized)

### Avant tout merge / deploy (P0 mandatory, ~3h owner work)

1. **Commit ou revert le working tree** (90 min)
   - Décider sur `simulation_hardware` triad : commit + sentinel + production-env guard OU revert tout
   - Stage + commit `config/pos.php` si gardé
   - Stage + commit 5 fixture files PHPUnit (sinon CI fresh-clone RED)
   - Décider sur `Stripe.php` cents-truncation : commit séparé V1.0.1.x OU stash
   - Décider sur `PaymentService.php` + `SplitPaymentService.php` working tree mods

2. **Fixer P0-#3 POS offline replay URL** (15 min)
   - `usePosOfflineState.js:48` → `admin/pos` (pas `admin/pos/order`)
   - Add Vitest spec qui assert l'URL réelle vs route table

3. **Créer P0-#5 + P0-#6 artefacts manquants** (45 min)
   - `deploy/ansible/group_vars/vault.yml.example` avec 8 placeholders
   - `PRODUCTION_ENV_TEMPLATE.env.txt` : ajouter STRIPE_WEBHOOK_SECRET + 3 autres keys

4. **Refresh P0-#7 CONVERGENCE_FINAL** (30 min)
   - Update HEAD reference, add Wave 5H + 5I sections, remove "Wave 5H pending"
   - Owner countersign on LOCK XSS + OWNER_GATES sign-off blocks

### Avant cron/realtime activation (P1, ~2h)

5. Webhook 90d → 180d (PCI compliance) — 10 min config
6. Multi-cashier offline `cashier_user_id` snapshot — 30 min
7. BranchController::destroy → fire BranchStatusChanged — 15 min
8. `composer audit` evidence + capture CVE IDs — 15 min
9. Single-tender CARD `terminal_id` rule — 20 min
10. Stale docstrings update (V1.0.2 → SHIPPED) — 10 min
11. `/etc/foodking-backup.env` + `soketi.json` Ansible tasks — 30 min
12. `/api/health/fiscal` endpoint OR docs alignment — 15 min

### Documentation drift (P1, ~3h)

13. PROJECT_BRAIN.md §2/§3/§7 refresh (Wave 5H+5I + V1 Cloud-Prep section) — 1h
14. memory/project_v1_cloud_prep_2026-05-17.md + MEMORY.md index — 30 min
15. CLAUDE.md §7 ↔ memory/reference_frozen_zones.md reconcile — 30 min
16. `rm` 4 garbage shell-artifact files (`,`, `[`, etc.) — 1 min
17. OWNER_GATES + LOCK XSS countersign formalisation — 30 min

### V1.0.2 backlog (defer)

18. Gateway-initiated refund callback handlers (SOP + V1.0.2 impl)
19. KDS bumped cross-station sync (still backlog per commit body)
20. Kitchen printer auto-fallback (V2)
21. Bcrypt rehash audit log
22. Bcrypt timing-leak mitigation (background job)
23. OTP purge `onOneServer`

---

## §6 — Convergence rule (recommended)

Apply Wave Z convergence pattern : **2 consecutive RED audits with P0=0** before declaring V1 Cloud-Prep merge-ready.

Round 1 (this audit) → P0 = 6-7 unique (commit/revert decisions block convergence)
Round 2 (post-heal) → expected P0 = 0 if all §5 P0 actions applied
Round 3 SMOKE (deterministic verification) → expected unchanged

---

## §7 — Final word

**Owner a livré un cycle ambitieux et techniquement solide.** Les 22 heals réels validés représentent ~5j-agent de travail compressé en ~24h wall-clock. La discipline frozen-zone et NF525 est exemplaire. Le pattern Wave 5D→5I + sub-agents parallèles est efficace.

**Le blocker principal n'est pas technique, c'est procédural** : le working tree non-commité fait que la version "vraie" du code diverge du HEAD git. Un seul `git stash` ou `git checkout .` au mauvais moment efface des heures de travail invisible. Convention : **commit-or-revert avant tout claim de convergence**.

Une fois les 5 P0 mandatoires fixés (3h de travail owner), V1 Cloud-Prep est mergeable à main et déployable Phase D Ansible.

---

## §8 — Sources

- `reports/audit/v1-cloud-prep-insights-2026-05-18/round-1/A1-security-cloud.md`
- `reports/audit/v1-cloud-prep-insights-2026-05-18/round-1/A2-pos-hardening.md`
- `reports/audit/v1-cloud-prep-insights-2026-05-18/round-1/A3-outbox-events-kds.md`
- `reports/audit/v1-cloud-prep-insights-2026-05-18/round-1/A4-nf525-frozen.md`
- `reports/audit/v1-cloud-prep-insights-2026-05-18/round-1/A5-adversarial-red.md`
- `reports/audit/v1-cloud-prep-insights-2026-05-18/round-1/A6-phase-d-deploy.md`
- `reports/audit/v1-cloud-prep-insights-2026-05-18/round-1/A7-docs-drift.md`

**Methodology** : 6 RED-team agents single-message parallel dispatch, ~25 min wall-clock total, anti-fabrication file:line strict, hostile framing on owner claims.
