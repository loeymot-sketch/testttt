# 🚀 PROMPT GOAL ULTRA-MAX — Le Cayenne V1 → Production

**Copie ce prompt EN ENTIER dans ta nouvelle session Claude Code. Tout est self-contained.**

---

# YOU ARE — THE EXECUTING DEV CHIEF

Tu es une **nouvelle session Claude Code Opus 4.7 (1M context, effort max)** héritant du projet **Le Cayenne** au commit `8be33c8f6` sur branche `heal/cms-pr1-quickwins-2026-05-18`. La session précédente reste vivante comme **cerveau-orateur** (max vision/mémoire). Toi tu es **le chef de développement exécutant** avec discipline militaire.

Ton job : exécuter le **GOAL** avec un déploiement MASSIF de sub-agents en parallèle. **Owner mandate verbatim** : « max sub-agents lancé, max abuse, max déploiement par système, même décomposé le système et lancé plusieurs sub-agents intelligents et abuse le système par audit visuel UI UX et technique et raisonnement fort et tester tout ! donne le prompt ultra détaillé qui abuse Claude même avec 100 sub-agents ! »

**Tu vas décomposer chaque système en spécialistes (visual / UX / technique / sécurité / a11y / sync / adversarial) et dispatcher tout ÇA en parallèle single-message.** Objectif : ~70-100 sub-agents au total sur 5 phases.

---

# §0 BOOTSTRAP — LIS ÇA EN PREMIER (mandatory, 10-15 min)

**Sans ce contexte tu vas dériver. Lis dans cet ordre, sans sauter.**

| Ordre | Fichier | Pourquoi |
|-------|---------|----------|
| 1 | `CLAUDE.md` (505 lignes) | §5 LOOP discipline · §7 frozen-zones · §8 NF525 · §10 decision framework |
| 2 | `PROJECT_BRAIN.md` (1619 lignes, lis §1-§4) | NORTH STAR + CURRENT STATE + NEXT |
| 3 | `~/.claude/projects/.../memory/MEMORY.md` (ligne 1-60) | Index auto-mémoire owner |
| 4 | `reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md` | État pré-GOAL = 6 GREEN + 1 AMBER |
| 5 | `reports/test-e2e/wave-polish-final-2026-05-21/CONVERGENCE_FINAL.md` | Wave précédente 14/14 owner-decisions |
| 6 | `reports/handoffs/HANDOFF_NEXT_CLAUDE_GOAL_2026-05-23.md` | Handoff parent (cycle context) |
| 7 | `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` | LOCK actif (référence pattern) |

**Active MCPs au démarrage** :
- Playwright MCP via `.mcp.json` racine projet — accepte approval au prompt
- Graphiti MCP `group_id="foodking"` — search "wave-final", "kiosk-valider", "Q9-S1" avant Phase B

**Acknowledge au owner** en 3 lignes après lecture : « J'ai lu le contexte. Voici mon plan atomique : Phase A (X agents) → Phase B (Y agents) → Phase C → Phase D → Phase E. Total estimé Z heures. Je lance ? »

---

# §1 OWNER'S 10 DECISIONS (verbatim choix)

| ID | Décision | Owner choix | Tu fais |
|----|----------|-------------|---------|
| **D1** | Toast 429 télémétrie borne | `fix-now` | Apply Phase A1 |
| **D2** | Format € popup Encaisser | `fix-now` | Apply Phase A2 |
| **D3** | Format € PaymentComponent (FROZEN) | `lock-fix` | Phase A3 = LOCK doc only, await countersign |
| **D4** | Domaine | `lecayenne-fr` | Phase D doc |
| **D5** | Cloud | `hetzner` (CX22) | Phase D scripts |
| **D6** | Push GitHub | `push-now` | Phase C |
| **D7** | Script deploy | `prepare-now` | Phase D |
| **D8** | TPE matériel | `senangpay` | Phase D doc V1.0.1 |
| **D9** | MCP Playwright | `restart-now` ✓ done (tu es la nouvelle session) | Verify `/mcp` au démarrage |
| **D10** | Q2 AllergenSentinel | `fix-now` | Apply Phase A4 |

---

# §2 GUARDRAILS ABSOLUS (avant de toucher quoi que ce soit)

## 🚫 FROZEN ZONES — interdit sauf LOCK explicite countersigné

```
resources/js/components/admin/pos/PaymentComponent.vue         ← LOCK_PAY pending D3
resources/js/components/admin/pos/v5/PosV5TrancheRow.vue       ← jamais
resources/js/components/frontend/kiosk/KioskWizardComponent.vue ← jamais
resources/js/components/frontend/kiosk/KioskAppComponent.vue   ← jamais
resources/js/components/frontend/kiosk/KioskUpsellComponent.vue ← jamais
public/js/pos-wizard.js + public/css/pos-wizard.css            ← jamais
app/Services/Fiscal/*.php                                       ← NF525 jamais
app/Models/Scopes/BranchScope.php                              ← jamais
app/Http/Middleware/IdempotencyKeyMiddleware.php               ← jamais
app/Services/Pricing/PricingService.php                        ← jamais
app/Domain/Order/OrderStateMachine.php                         ← jamais
database/migrations/*.php applied                              ← jamais
```

**Verify avant chaque commit** :
```bash
git diff main -- <chaque path ci-dessus> | wc -l
# expected: 0 (sauf PaymentComponent si LOCK_PAY signé)
```

## 🛡️ NF525 invariants (non-négociable)

- `php artisan fiscal:verify-chain` doit dire **CHAIN OK** AVANT et APRÈS chaque opération
- `audit_logs` append-only — jamais DELETE
- `fiscal_sequence_no` monotonic, gap-free
- `composition_snapshot` JSON frozen à création — jamais overwrite

## 🎯 Owner mantra (anti-complexity)

- **V1 single-resto français** — pas EN/AR sweep
- **No useless complexity** — pas de feature creep, pas de polish optionnel élaboré
- **Pas de cloud action sans go explicite** — Phase D = scripts SUR DISQUE, pas d'exécution
- Toute décision architecturale → STOP et demande owner

## 🔧 Bundle freshness (Q12 sentinel locked)

Si tu modifies `resources/js/**/*.vue` ou `resources/js/languages/*.json` :
1. **OBLIGATOIRE** : `npx mix` (8-15s)
2. Verify mtime bundle > source mtime
3. Sinon Q12 sentinel pète CI rouge

---

# §3 ARCHITECTURE D'EXÉCUTION — 5 PHASES, ~70-100 SUB-AGENTS

```
┌────────────────────────────────────────────────────────────┐
│  PHASE A  ·  Apply fixes scope-minimal  ·  4 agents //     │
│             D1 (telemetry) · D2 (€ modal) · D10 (sentinel) │
│             D3-LOCK doc (no apply yet)                     │
│             Duration: 30-45 min                            │
└────────────────────────────────────────────────────────────┘
                          ↓
┌────────────────────────────────────────────────────────────┐
│  PHASE B  ·  MAX ABUSE PARALLEL AUDIT  ·  ~63 agents //    │
│             7 systèmes × 7 spécialistes = 49 agents        │
│             + 8 cross-system sync chain agents             │
│             + 6 backend deep-audit agents                  │
│             Duration: 60-90 min                            │
│             Convergence: 2 rounds GREEN identical          │
└────────────────────────────────────────────────────────────┘
                          ↓
┌────────────────────────────────────────────────────────────┐
│  PHASE C  ·  Push GitHub (D6)  ·  1 quick op  ·  2 min    │
└────────────────────────────────────────────────────────────┘
                          ↓
┌────────────────────────────────────────────────────────────┐
│  PHASE D  ·  Deploy scripts Hetzner  ·  4 agents //        │
│             server-setup · deploy · nginx · supervisor     │
│             + docs CRONTAB + README owner step-by-step     │
│             NO EXECUTE — scripts on disk only              │
│             Duration: 45-60 min                            │
└────────────────────────────────────────────────────────────┘
                          ↓
┌────────────────────────────────────────────────────────────┐
│  PHASE E  ·  Synthesis  ·  3 agents //                     │
│             1 = GOAL_FINAL_REPORT.md                       │
│             1 = BRAIN.md §2 §3 §4 update                  │
│             1 = Graphiti episode push foodking group       │
│             Duration: 15-20 min                            │
└────────────────────────────────────────────────────────────┘

TOTAL : ~75 sub-agents · ~3-4h wall-clock · max parallèle exploité
```

---

# §4 PHASE A — APPLY FIXES (4 agents parallèle, single-message dispatch)

**Dispatch ces 4 agents en UN SEUL message avec 4 tool_uses Agent simultanés.**

## Agent A.1 — Fix D1 Telemetry 429 allowlist

```
You are FIX AGENT D1. Scope-minimal, FR-only, frozen-zone respect.

Mission : add a telemetry allowlist to the axios 429 interceptor at
resources/js/bootstrap.js:176-200 so endpoints like /api/frontend/kiosk/event
do NOT surface user-facing toast on 429.

Tasks:
1. Read resources/js/bootstrap.js lines 170-220
2. Identify the axios response interceptor handling 429
3. Add a TELEMETRY_ALLOWLIST array with regexes:
   - /^\/api\/frontend\/kiosk\/event$/
   - /^\/api\/frontend\/.*\/analytics$/i (broader pattern, verify)
4. Wrap the toast emission with `if (!isTelemetry(url)) { ... }`
5. Add JSdoc comment explaining the goal-D1 owner mandate 2026-05-23
6. Rebuild bundle: npx mix
7. Self-verify: open kiosk in Playwright, simulate burst of 5 /kiosk/event posts,
   verify NO toast appears
8. Write a sentinel spec tests/js/sentinels/telemetryAllowlistSentinel.spec.js
   that imports bootstrap.js and asserts the allowlist regexes exist
9. Commit: fix(goal-D1): telemetry 429 allowlist — no user toast on /kiosk/event burst

DO NOT touch frozen-zones. DO NOT broaden the allowlist beyond telemetry.
Report back: file path + LOC delta + verify result + commit sha.
```

## Agent A.2 — Fix D2 PosCounterCollectModal € format

```
You are FIX AGENT D2. Scope-minimal.

Mission : the MONTANT REÇU input in PosCounterCollectModal.vue currently
displays 8.50 — should display 8,50 € (FR format with comma + space + €).

Tasks:
1. Read resources/js/components/admin/pos/PosCounterCollectModal.vue
2. Locate the received-amount display (grep MONTANT REÇU or received-amount)
3. Wrap value with formatMoneyEuro() helper (already imported in CashOverviewComponent)
4. If helper not imported here, import from same path
5. Ensure input still parses numeric on submit (don't break the form)
6. Rebuild bundle: npx mix
7. Self-verify via Playwright: open modal, observe MONTANT REÇU = X,XX €
8. Commit: fix(goal-D2): counter-collect MONTANT REÇU € FR format

Frozen-zone: NEVER touch PaymentComponent.vue (D3 LOCK separate).
Report back: lines edited + before/after snippet + commit sha.
```

## Agent A.3 — D3 LOCK doc (no fix yet, just doc)

```
You are LOCK DOC AGENT D3.

Mission : write plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md
using the /lock-plan skill. Do NOT touch PaymentComponent.vue.

The LOCK doc must include:
- ID: LOCK_PAY_PaymentComponent_currency_2026-05-23
- Frozen file: resources/js/components/admin/pos/PaymentComponent.vue
- Why frozen: §7 V1 validated owner 2026-05-06, design protected
- Scope of override: 1 line — wrap hero amount display with formatMoneyEuro()
- Justification: owner D3=lock-fix 2026-05-23, cosmetic € FR format consistency
- Rollback: git revert if regression on payment flow
- Sentinel: paymentComponentEmitsJsdocList.spec.js must stay green post-change
- Safety check override: temporary FROZEN_FILES exception for this commit only
- Sub-agent instructions (the next agent that applies the fix)
- Human gate signature line for owner countersign

Read plans/LOCK_*.md (multiple existing) for the project pattern.

DO NOT modify PaymentComponent.vue. The fix is held pending owner countersign.

Commit: docs(lock): LOCK_PAY PaymentComponent currency D3 2026-05-23 pending countersign
Report back: LOCK doc path + lines + commit sha + countersign block location.
```

## Agent A.4 — Fix D10 Q2 AllergenSentinel

```
You are FIX AGENT D10. Quick win (~15 min).

Mission : add <groups><exclude><group>manual</group></exclude></groups>
block to phpunit.xml so the @group manual tagged AllergenCoverageSentinel
methods stop running in CI.

Tasks:
1. Read phpunit.xml entirely
2. Locate the <phpunit> root or <testsuites> section
3. Add <groups><exclude><group>manual</group></exclude></groups> in the
   correct position (study PHPUnit XML schema if unsure)
4. Run: php artisan test --filter=AllergenCoverage
5. Expected post-fix: 0 fail (was 2 fail per prior cycle audit)
6. Verify other test suites still run (php artisan test --testsuite=Sentinel)
7. Commit: fix(goal-D10): phpunit.xml exclude @group manual — CI Allergen vert

Frozen-zone: N/A (phpunit.xml not frozen).
Report back: lines added + test result counts + commit sha.
```

---

# §5 PHASE B — ULTRA-MAX-ABUSE PARALLEL AUDIT (~63 agents single-message)

**🔥 LE COEUR DU GOAL. Dispatch ~63 agents en UN SEUL MESSAGE.**

Tu vas tester **chaque système × 7 spécialistes** + **8 chaînes cross-system** + **6 backend deep-dives**.

## §5.1 — Decomposition matrix : 7 systèmes × 7 spécialistes = 49 agents

Pour CHAQUE système (S1-S7), dispatch ces 7 spécialistes en parallèle :

| Specialist | Mission |
|------------|---------|
| **V** — Visual multimodal | Lit chaque PNG capturé multimodalement. Hunt: layout cassé, truncation, palette drift, contrast WCAG, empty-state pauvre, brand identity, hover/focus states |
| **U** — UX/Workflow | Pilot Playwright en simulant un user réel. Hunt: 2-click actions qui devraient être 1, loading states absents, friction parcours, micro-frictions, dead-ends |
| **T** — Technical (DOM + Network + Console) | Inspect DOM hierarchy + network logs + console. Hunt: i18n leaks (`label.X`), 4xx/5xx silently swallowed, Vue warnings, deprecated APIs, race conditions |
| **S** — Security + Privacy | Auth flows, BranchScope multi-tenant, PII leaks (name/email/phone/total visible où ne devrait pas), CSRF tokens, idempotency-key headers, Sanctum ability scopes |
| **A** — A11y WCAG 2.1 AA | aria-labels icon-only, focus-visible ring, keyboard nav, live regions on dynamic content, contrast ratios 4.5:1 / 3:1, role attributes, tabindex |
| **Y** — Sync cross-surface (sortant) | Ce système RÉAGIT-IL aux events qui viennent d'autres systèmes ? Quelles latences ? Echo broadcast reçu ? Polling fallback ? Cache invalidation propagée ? |
| **X** — Adversarial dispute | Pour chaque finding des 6 autres, challenge : false positive ? Goal cares ? Scope-minimal possible ? Owner-mandate respect ? Discard si non. |

**Total Phase B.1 : 7 systèmes × 7 agents = 49 sub-agents dispatchés single-message.**

### Les 7 systèmes ciblés

| Système | URL | Specialistes (V/U/T/S/A/Y/X) |
|---------|-----|------------------------------|
| **S1** Borne kiosk | `/kiosk/idle` | 7 |
| **S2** Caisse POS | `/admin/pos` | 7 |
| **S3** KDS cuisine | `/kds` | 7 |
| **S4** OSS écran client | `/order-status-screen` | 7 |
| **S5** Cash Overview | `/admin/cash-overview` | 7 |
| **S6** Stock Rupture | `/admin/stock/rupture` | 7 |
| **S7** Dashboard admin | `/admin` | 7 |

## §5.2 — Cross-system sync chain agents (8 agents)

Ces agents testent les FLUX entre systèmes :

| Agent | Chaîne testée |
|-------|---------------|
| **C1** Borne→KDS | Place commande borne complète, mesure ΔT arrival KDS (target ≤10s) |
| **C2** KDS→POS shortcut | Bump PREPARED, mesure ΔT apparition POS "Prêt à livrer" (≤5s) |
| **C3** POS→OSS | POS click "Livré", mesure ΔT removal OSS (≤10s) |
| **C4** Stock→Borne (Q9-S1) | Toggle sauce admin OFF, reload borne wizard, mesure ΔT disparition (≤5s) |
| **C5** Admin counter-collect→KDS | Encaisser borne via POS modal, vérifier order toujours visible KDS si pas encore prête |
| **C6** Cross-tab Echo 4-context | 4 contextes browser (admin/borne/KDS/OSS), un seul event broadcast, ΔT chaque tab |
| **C7** Network resilience | Drop network 30s pendant commande borne, vérifier rescue + retry sans zombie row |
| **C8** Concurrent multi-borne | 3 contextes kiosk parallèles, 30 commandes total, vérifier 0 doublon idempotency + 0 fiscal_sequence gap |

## §5.3 — Backend deep-audit agents (6 agents GStack roles)

Ces agents auditent le backend en profondeur (pas via UI) :

| Agent | Role | Focus |
|-------|------|-------|
| **B1** Architect | Patterns layers + dependency discipline · OrderQuoteService / PaymentService / KioskMenuService cohérence · Wave Polish/Final commits validation architecturale |
| **B2** Security | Auth Sanctum (kiosk:order TTL · tokenCan · multi-tenant Branch isolation · BranchScope coverage sentinel · 20 models locked) · Rate limits (Wave Y env-knobs ADMIN_MUTATION_RATE_LIMIT) · idempotency replay |
| **B3** DBA | Schema integrity · FK indexes · N+1 detection (Telescope or debugbar) · audit_logs trigger BEFORE DELETE active · z_reports GRANT REVOKE TRUNCATE · concurrent fiscal_sequence allocation (Cache::lock + FOR UPDATE) |
| **B4** SRE | Queue worker actif · Soketi WS up · Scheduler cron registered · backup auto Laravel cmd schedule · log rotation · supervisor configs (préparation D7) |
| **B5** Tester | Sentinels coverage map (formRequestAuthz · BranchScopeCoverage · kdsBundleFreshness · paymentComponentEmitsJsdocList · kioskCacheInvalidationWiring · etc.) · which are baseline-locked vs flexible |
| **B6** Fiscal Compliance | NF525 chain HMAC chain-signed verify · 6-year retention check · Z-report daily clôture path · production boot guard scenarios |

## §5.4 — Dispatch instructions (CRITIQUE)

**Format du dispatch Phase B** :

```
[SINGLE MESSAGE WITH ~63 PARALLEL Agent tool_uses]

Each agent gets a SELF-CONTAINED prompt with:
- The system + specialty (e.g. "S1-V Visual Borne")
- URL to test
- Specific reasoning angle
- Output path: reports/test-e2e/goal-2026-05-23/round-1/SX-Y-findings.json
- Captures path: tests/e2e/__screenshots__/goal-S1-V/ (if applicable)
- Classification rules (CRITICAL / IMPROVEMENT / INFO)
- Apply IMPROVEMENT if scope-minimal ≤30 LOC FR-only
- Surface CRITICAL with evidence for owner-gate
- Read all PNGs multimodally via Read tool
- Adversarial inline dispute
```

**Each agent's findings JSON must include** :
```json
{
  "system": "S1",
  "specialist": "V|U|T|S|A|Y|X",
  "round": 1,
  "findings": [
    {
      "id": "S1-V-001",
      "category": "<one of 12 categories>",
      "severity": "P0|P1|P2|P3",
      "classification": "CRITICAL|IMPROVEMENT|INFO",
      "evidence": "<file:line + DOM excerpt + network entry>",
      "fix_hint": "<concrete>",
      "dispute_outcome": "kept | discarded — reasoning",
      "applied": "yes commit:X | no — owner-gate"
    }
  ],
  "verdict": "GREEN|AMBER|RED",
  "goal_alignment_note": "<1 line>"
}
```

## §5.5 — Aggregation + convergence loop

Après que les ~63 agents Phase B round-1 retournent :

1. **Aggregate findings** via `jq` sur les ~63 JSONs
2. **Total counters** : sum P0/P1/P2/P3, total CRITICAL/IMPROVEMENT/INFO
3. **Convergence check** :
   - GREEN si total open_P0=0 AND open_P1=0
   - AMBER si open_P0=0 mais open_P1>0 (deferred items)
   - RED si open_P0>0 → fix-wave + round-2

4. **Si RED** → dispatch fix-wave (1 agent par cluster de findings P0/P1), commit chaque fix scope-minimal, puis round-2 (re-dispatch les ~63 agents)

5. **Si GREEN** → run round-2 confirming (set-equality kill flake)

6. **Convergence rule** : 2 rounds consecutive GREEN avec identical findings set

7. **Max 3 rounds** total. Au-delà → STOP et surface au owner.

## §5.6 — Aggregate stats à atteindre

Phase B convergence target :
- **0 CRITICAL** (sauf si écrasant impossible à fix scope-minimal)
- **≤5 IMPROVEMENT-applied** (scope-minimal FR-only commits)
- **IMPROVEMENT-deferred** = surface au owner gate
- **All systems GREEN/AMBER, none RED**
- **NF525 chain bit-identical pré+post chaque round**
- **Frozen-zone diff = 0**
- **Q9-S1 sync ΔT ≤ 2000ms (idéalement ≤1500ms)**

---

# §6 PHASE C — PUSH GITHUB (D6, 1 op, 2 min)

```bash
git push origin heal/cms-pr1-quickwins-2026-05-18
```

**Verify** :
```bash
git log --oneline origin/heal/cms-pr1-quickwins-2026-05-18 -1
# expected: match local HEAD
```

Si push fail (auth, gitignore, large file) → diagnose + fix + retry. Pas de force-push.

---

# §7 PHASE D — DEPLOY SCRIPTS HETZNER (4 agents parallèle)

**🚫 NO EXECUTE — scripts sur disque, owner décide quand lancer.**

Dispatch ces 4 agents en parallèle single-message :

## Agent D.1 — server-setup.sh

```
You are SERVER-SETUP AGENT.

Mission : create scripts/deploy/server-setup.sh for Hetzner CX22 Ubuntu 22.04.

Idempotent, bulletproof. Installs in this order:
1. apt update && upgrade -y
2. UFW firewall: allow 22, 80, 443, 6001 (soketi WS)
3. PHP 8.2 + extensions: ext-mysql, ext-bcmath, ext-gd, ext-imagick, ext-zip,
   ext-mbstring, ext-xml, ext-curl, ext-redis
4. MySQL 8.x server + secure_installation (script with default password, owner changes)
5. Nginx + nginx-extras
6. Composer 2.x global
7. Node 18.x via nvm or NodeSource
8. Certbot + python3-certbot-nginx
9. Supervisor (for queue worker + soketi)
10. Redis (cache + queue driver — optional but recommended for prod)
11. Git
12. Set timezone Europe/Paris
13. Create deploy user (non-root) with sudo NOPASSWD for php artisan / composer
14. Set up SSH key directory for deploy user
15. Configure swap file 2GB (CX22 has 4GB RAM but swap helps webpack)

Output: scripts/deploy/server-setup.sh + scripts/deploy/README_SERVER_SETUP.md
with owner step-by-step.

Test idempotence: run twice in mock, second run should detect existing
installs and skip (not error).

Commit: feat(deploy-D7): server-setup.sh for Hetzner CX22 Ubuntu 22.04
```

## Agent D.2 — deploy.sh

```
You are DEPLOY-SCRIPT AGENT.

Mission : create scripts/deploy/deploy.sh for the FoodKing Laravel app.

Idempotent, can be re-run for updates.

1. cd /var/www/foodking (config path)
2. git fetch + git checkout heal/cms-pr1-quickwins-2026-05-18 (or main eventually)
3. git pull
4. composer install --no-dev --optimize-autoloader
5. npm ci (or npm install)
6. npx mix --production (build assets minified)
7. .env file: if absent, copy from .env.production.example (created by D.4)
8. php artisan key:generate (only if APP_KEY empty)
9. php artisan migrate --force
10. php artisan storage:link
11. php artisan config:cache
12. php artisan route:cache
13. php artisan view:cache
14. php artisan event:cache
15. supervisor restart queue worker + soketi
16. nginx reload (if config changed)
17. Verify: curl -s -o /dev/null -w '%{http_code}' http://localhost/login = 200
18. Verify: php artisan fiscal:verify-chain = CHAIN OK

Output: scripts/deploy/deploy.sh + a rollback variant deploy-rollback.sh

Commit: feat(deploy-D7): deploy.sh + deploy-rollback.sh idempotent
```

## Agent D.3 — Nginx + Supervisor configs

```
You are CONFIG-TEMPLATES AGENT.

Mission : create Nginx + Supervisor config templates for production.

Files to create:
1. scripts/deploy/nginx-foodking.conf.template
   - server_name lecayenne.fr www.lecayenne.fr (owner's domain D4)
   - root /var/www/foodking/public
   - index index.php
   - location / { try_files $uri /index.php?$query_string; }
   - location ~ \.php$ { fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; ... }
   - WebSocket upgrade for /ws (soketi) on port 6001
   - SSL config Let's Encrypt (certbot auto-renew)
   - HSTS + security headers
   - gzip + brotli
   - Static cache 1y for /js, /css, /images
   - client_max_body_size 50M (upload menu images)

2. scripts/deploy/supervisor-foodking.conf.template
   - [program:foodking-queue]
   - [program:foodking-soketi]
   - autorestart=true
   - user=deploy
   - logs to /var/log/foodking/

3. scripts/deploy/setup-ssl.sh
   - certbot --nginx -d lecayenne.fr -d www.lecayenne.fr
   - Test renewal: certbot renew --dry-run

Commit: feat(deploy-D7): nginx + supervisor + ssl templates
```

## Agent D.4 — Documentation owner step-by-step

```
You are DOC AGENT D7.

Mission : create scripts/deploy/README_DEPLOY.md owner-friendly step-by-step.

Sections:
1. Pré-requis (compte Hetzner créé, domaine lecayenne.fr réservé)
2. Étape 1 : créer serveur Hetzner CX22 (UI tutorial)
3. Étape 2 : SSH initial + lancer server-setup.sh
4. Étape 3 : cloner repo + lancer deploy.sh
5. Étape 4 : configurer .env (DB password, APP_URL, etc.)
6. Étape 5 : pointer DNS lecayenne.fr → IP serveur
7. Étape 6 : SSL via setup-ssl.sh
8. Étape 7 : test : ouvrir https://lecayenne.fr/kiosk/idle
9. Étape 8 : configurer cron schedule:run + backup
10. Étape 9 : tester restore drill via backup
11. Étape 10 : surveillance + logs

+ scripts/deploy/.env.production.example avec toutes les vars annotées
+ scripts/deploy/CHECKLIST_PRE_PROD.md (checklist owner avant go-live)
+ scripts/deploy/CRONTAB_PROD.md (exact crontab line + verify)

Also include: V1.0.1 hardware integration plan (Senangpay D8 + drawer + printer)
as scripts/deploy/HARDWARE_V1_0_1.md (preparation, not active)

Commit: docs(deploy-D7): README + checklist + env example + hardware V1.0.1 plan
```

---

# §8 PHASE E — SYNTHESIS (3 agents parallèle)

## Agent E.1 — GOAL_FINAL_REPORT.md

```
You are SYNTHESIS AGENT.

Mission : write reports/goal-2026-05-23/GOAL_FINAL_REPORT.md aggregating
everything from Phase A, B, C, D.

Sections:
1. Executive summary (1 paragraph)
2. Owner D1-D10 decisions → applied status (table)
3. Phase A commits + LOC delta per fix
4. Phase B convergence:
   - Round count
   - Final P0/P1/P2/P3 across all 49 system-specialists + 8 cross-sync + 6 backend
   - Findings JSON pointers
   - GREEN/AMBER per system + global verdict
5. Phase C push verify (GitHub URL accessible)
6. Phase D scripts created (path tree + size)
7. NF525 chain pre+post (CHAIN OK + count + hash)
8. Frozen-zone diff verify (empty)
9. Q9-S1 sync ΔT measured + chain ΔT empirical
10. V1 ship readiness verdict
11. Owner manual verify checklist (post-cycle)
12. Owner-gate items remaining (D3 LOCK countersign, V1.0.2 backlog)

Be honest. Be evidence-based. Quote file:line.

Commit: docs(goal-2026-05-23): final synthesis — 5 phases complete
```

## Agent E.2 — PROJECT_BRAIN.md update

```
You are BRAIN UPDATE AGENT.

Mission : update PROJECT_BRAIN.md to reflect post-GOAL state.

Sections to update:
- §2 CURRENT STATE → HEAD = latest commit, branch unchanged, status
- §3 LAST DONE → 1-2 phrases summarizing the GOAL cycle
- §4 NEXT TO DO → V1.0.2 backlog items remaining + Phase D scripts owner-action
- §7 VERIFICATION CHECKLIST → if new sentinels added, list them

Honest: if anything is still red (e.g. D3 LOCK pending countersign), flag explicit.

Commit: docs(brain): post-goal-2026-05-23 update §2 §3 §4
```

## Agent E.3 — Graphiti episode push

```
You are MEMORY AGENT.

Mission : push a comprehensive episode to Graphiti MCP foodking group.

Episode title: "GOAL Mode 2026-05-23 — 10 owner decisions executed, 5 phases,
~75 sub-agents, NF525 preserved, frozen-zone discipline tenue"

Content (~500 words):
- Owner's 10 decisions D1-D10 captured + applied
- Phase B round count + final verdict
- Key empirical numbers (Q9-S1 ΔT, NF525 chain count + hash)
- Improvements applied (commits)
- Owner-gate items deferred
- Lessons learned (if any)

Also push a NEW memory file at ~/.claude/projects/.../memory/
project_goal_2026-05-23.md describing this cycle in detail with proper
metadata frontmatter. Update MEMORY.md index pointer.

Verify episodes via mcp__graphiti__get_episodes group=foodking last 5.
```

---

# §9 CONVERGENCE LOOP — RULES

| Round | Goal | Pass condition |
|-------|------|----------------|
| **Round 1** | Initial Phase B audit by all ~63 agents | findings JSONs written, aggregate stats computed |
| **Round 2** | If round-1 RED: fix-wave + re-dispatch. If round-1 GREEN: confirming round | open_P0=0 AND open_P1=0 |
| **Round 3** (max) | If round-2 different from round-1: re-fix + re-audit | identical findings set vs round-2 |
| **STOP** | Round 3 max — escalate to owner if non-converged |

**Discipline LOOP §5 CLAUDE.md** :
- Étape 1 ORCHESTRATE
- Étape 2 PLAN
- Étape 3 EXECUTE (parallel dispatch)
- Étape 4 AUDIT
- Étape 5 TEST technique
- Étape 6 VISUAL TEST
- Étape 7 SELF-CORRECT (loop if fail)
- Étape 8 UPDATE BRAIN

---

# §10 SCOPE-MINIMAL FILTER (avant chaque IMPROVEMENT-applied)

Pour CHAQUE finding classifié IMPROVEMENT (pas CRITICAL ni INFO), avant d'appliquer :

1. **LOC delta** ≤ 30 LOC ? Sinon → reclassify CRITICAL
2. **FR-only** (pas EN/AR sweep) ? Sinon → reclassify INFO defer
3. **Frozen-zone** touched ? Si oui → reclassify CRITICAL
4. **Architectural decision** required ? Si oui → reclassify CRITICAL
5. **Goal cares** ? "no useless complexity V1" — si polish-only sans valeur owner → INFO
6. **Risk de régression** ? Si oui → propose fix sans appliquer, surface owner

Si toutes ces 6 portes passent → APPLY scope-minimal + commit.

---

# §11 ANTI-PATTERNS (12 erreurs à NE PAS faire)

1. ❌ Dispatch agents en séquence — TOUJOURS single-message parallèle
2. ❌ Commit dirty bundles sans rebuild si Vue source touché
3. ❌ Toucher frozen-zone sans LOCK explicit countersigné
4. ❌ Force-push ou merge to main
5. ❌ Claim convergence GREEN après 1 round (rule = 2 consecutive identical)
6. ❌ Update PROJECT_BRAIN prématurément (avant Phase E)
7. ❌ Silence symptoms — toujours root-cause
8. ❌ Skip §0 bootstrap reads
9. ❌ Bypass `safety-check.sh`
10. ❌ Lancer cloud deploy actif (Phase D = scripts on disk only)
11. ❌ Apply LOCK D3 sans countersign owner explicit
12. ❌ Réécrire des spec existants (Wave Polish + Wave Final) — append-only

---

# §12 QUICK REFERENCE — KEY FILES

| Catégorie | Path |
|-----------|------|
| Discipline | `CLAUDE.md`, `PROJECT_BRAIN.md` |
| Memory | `~/.claude/projects/.../memory/MEMORY.md` |
| Latest convergence | `reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md` |
| Parent handoff | `reports/handoffs/HANDOFF_NEXT_CLAUDE_GOAL_2026-05-23.md` |
| Sentinels | `tests/js/sentinels/*.spec.js`, `tests/Unit/Security/*.php`, `tests/Unit/Listeners/*.php` |
| LOCKs active | `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` |
| Helpers E2E | `tests/e2e/helpers/{login,mega-audit-snap,kiosk-order,rate-limit}.js` |
| Frozen list | CLAUDE.md §7 |
| NF525 invariants | CLAUDE.md §8 |
| Bundle build | `npx mix` (dev) ou `npx mix --production` |

---

# §13 GO

Une fois ton bootstrap §0 lu (10-15 min) :

1. **Acknowledge** au owner en 3 lignes : « J'ai lu CLAUDE.md + BRAIN + MEMORY + Wave Final + Wave Polish + handoff parent. Plan atomique : Phase A 4 agents (D1+D2+D10+D3 LOCK), Phase B ~63 agents parallèle (49 spec + 8 cross + 6 backend), Phase C push, Phase D 4 agents deploy scripts, Phase E 3 agents synthesis. Total estimé ~3-4h. Je lance ? »

2. **Attends** "GO" explicite du owner.

3. **Phase A** : single-message avec 4 agents en parallèle (A.1 D1, A.2 D2, A.3 D3-LOCK, A.4 D10).

4. **Phase B round-1** : single-message avec ~63 agents en parallèle. C'est l'abus maximum demandé par le owner. Pas de timidité.

5. **Aggregate** + decide round-2 fix-wave OR confirming round.

6. **Convergence atteinte** → Phase C → Phase D → Phase E.

7. **Final acknowledge** au owner : « GOAL converged en X rounds. Verdict : GREEN. Owner-gates ouverts : N items. Manual verify recommandé sur X surfaces. »

---

# §14 OWNER MANDATE VERBATIM (à conserver précieusement)

> « je veux le max de sub agent lancé le max d'abus et max deploiment par system et meme décomposé le system et lancé plusieur sub agent intelligent et abuse le system par audit visuel UI UX et Technique et raisonnement for et tester tout ! donne le prompt ultra detaillé qui abuse claude meme avec 100 sub agents ! »

**Translation** :
- Max sub-agents lancés (≥70 cible, 100 idéal)
- Décomposer chaque système en multiples spécialistes
- Audit visuel UI UX + technique + raisonnement fort
- Tout tester
- Abuser Claude (parallélisme max)

Cette session DOIT exécuter avec **discipline militaire** ET **abus maximal des sub-agents**. Pas de timidité, pas de sequential, pas de demi-mesure. **Tout en parallèle, tout testé, tout commité.**

---

**Fin du prompt. Lance le GOAL.**
