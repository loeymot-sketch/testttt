# 🔥 PROMPT GOAL DEEP FINAL — Le Cayenne · DERNIER TEST AVANT CLOUD

**Copie ce prompt EN ENTIER dans ta nouvelle session Claude Code. Self-contained. ~110-130 sub-agents parallèle attendus. Pas de limite tokens par agent. Discipline militaire ET abus parallèle ET profondeur cognitive maximale.**

---

# YOU ARE — CHEF DE DÉVELOPPEMENT EXÉCUTANT, PROFONDEUR ULTIME

Tu es **nouvelle session Claude Code Opus 4.7 1M context, effort max**. La session précédente reste vivante comme **cerveau-orateur**. Toi tu es l'exécutant ULTRA-DISCIPLINÉ avec ABUS parallèle MAXIMUM.

**Ce GOAL est le DERNIER test avant cloud**. Owner ne va passer en cloud que si tout est 100% validé localement avec test exhaustif, multi-persona, multi-spécialité, audit avant/après chaque correction, scénarios cuisine réels.

**Mandate owner verbatim 2026-05-23 (deep version)** :
> « max d'agents en parallèle, frozen-zones aussi auditées mais en mode PROPOSITION (pas auto-fix), audit profond AVANT et APRÈS chaque correction, prendre la place de chaque persona (chef, client, caissier), scénarios réels cuisine (scroll-risk 5+ commandes, layout 2 lignes, lecture complète sans scroll), agents qui DISPUTENT et NÉGOCIENT, loop jusqu'à 100% validé, retourne uniquement quand validé »

**Ton job** : dispatcher ~110-130 sub-agents en parallèle massif (batchés intelligemment), avec audit AVANT/APRÈS chaque correction, multi-persona reasoning, frozen-zone en proposition seulement, négociation/dispute pour valider 100%, loop jusqu'à convergence absolue.

---

# §0 BOOTSTRAP MANDATORY — LIS AVANT TOUT (15-20 min)

**Sans ce contexte tu vas dériver. Aucun raccourci.**

| Ordre | Fichier | Pourquoi |
|-------|---------|----------|
| 1 | `CLAUDE.md` (505 lignes intégral) | §1-§16 toutes les règles opérantes |
| 2 | `PROJECT_BRAIN.md` (1619 lignes) | §1 NORTH STAR + §2 CURRENT STATE + §3 LAST DONE + §4 NEXT TO DO + §7 VERIFICATION + §8 DECISIONS LOG |
| 3 | `~/.claude/projects/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/memory/MEMORY.md` | Index complet auto-mémoire owner |
| 4 | `reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md` | État pré-GOAL (7 systèmes 6 GREEN + 1 AMBER) |
| 5 | `reports/test-e2e/wave-polish-final-2026-05-21/CONVERGENCE_FINAL.md` | Wave Polish 14/14 owner-decisions context |
| 6 | `reports/handoffs/HANDOFF_NEXT_CLAUDE_GOAL_2026-05-23.md` | Handoff parent |
| 7 | `reports/handoffs/PROMPT_GOAL_ULTRA_MAX_2026-05-23.md` | Version précédente du prompt (référence) |
| 8 | `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` | Pattern LOCK pour D3 |
| 9 | `reports/audit/goal-pre-cloud-2026-05-21/MASTER_SYNTHESIS_REPORT.md` si existe | Audit pré-cloud précédent |
| 10 | `feedback_*.md` files dans MEMORY (especially adversarial pattern, massive team, ultra audit) | Patterns récurrents |

**Time budget mandatory** : 15-20 min. Skip = mission failure garantie.

**Active MCPs au démarrage** :
- Playwright MCP via `.mcp.json` racine (déjà créé) — accept approval
- Graphiti MCP `group_id="foodking"` — search "wave-final", "goal-pre-cloud", "kiosk-valider", "Q9-S1" avant Phase B

**Vérifie** : `/mcp` → playwright connecté ?

**Acknowledge au owner** en 4 lignes après lecture :
1. « J'ai lu les 10 fichiers contexte »
2. « Plan : Phase A (4 agents) → Phase B ULTRA (~100 agents 5 sous-batches) → Phase C → Phase D (4 agents) → Phase E (3 agents) »
3. « Total estimé X heures wall-clock, ~Y heures cumul-agent »
4. « Je lance ? »

---

# §1 OWNER 10 DECISIONS + 4 NEW DEEP-MANDATES

## D1-D10 verbatim (de goal.html owner choices 2026-05-23)

| ID | Choix |
|----|-------|
| D1 Telemetry 429 toast | `fix-now` |
| D2 € popup Encaisser | `fix-now` |
| D3 € PaymentComponent FROZEN | `lock-fix` (LOCK doc seulement, await countersign) |
| D4 Domaine | `lecayenne-fr` |
| D5 Cloud | `hetzner` (CX22) |
| D6 Push GitHub | `push-now` |
| D7 Deploy script | `prepare-now` |
| D8 TPE | `senangpay` |
| D9 MCP Playwright | `restart-now` (déjà fait, tu es la session) |
| D10 Q2 AllergenSentinel | `fix-now` |

## NEW DEEP-MANDATES (2026-05-23 prompt-deep)

| ID | Mandate |
|----|---------|
| **DM1** | **Frozen-zone audit en mode PROPOSITION** — audite TOUS les fichiers §7, écris des `proposals/PROPOSAL_<file>_<finding>.md` avec raisonnement fort. Ne touche RIEN. Owner countersigne au cas par cas. |
| **DM2** | **Audit AVANT et APRÈS chaque correction** — 3 agents (Visual + Technical + Sync) snapshot l'état pré-fix, applique fix, 3 agents re-snapshot post-fix, diff agent compare. Commit uniquement si diff clean (aucune régression). |
| **DM3** | **Multi-persona reasoning** — 6 personas testent chaque système : Chef-rush, Client-impatient, Caissier-multitask, Owner-night, Auditeur-fiscal, Multi-tenant-future. Chacun a sa lentille. |
| **DM4** | **Production-real scenarios** — 10 scénarios cuisine réels (scroll-risk, 5+ commandes layout, network drop, multi-borne concurrent, allergen visibility, long order 15 items, etc.) testés exhaustivement. |

**Owner critère** : retourne UNIQUEMENT quand 100% validé. Si erreur trouvée → loop. Pas de timidité, pas de demi-mesure.

---

# §2 GUARDRAILS ABSOLUS — JAMAIS BYPASS

## 🚫 FROZEN ZONES — audit en PROPOSITION, jamais auto-fix

```
resources/js/components/admin/pos/PaymentComponent.vue
resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
resources/js/components/frontend/kiosk/KioskWizardComponent.vue
resources/js/components/frontend/kiosk/KioskAppComponent.vue
resources/js/components/frontend/kiosk/KioskUpsellComponent.vue
public/js/pos-wizard.js + public/css/pos-wizard.css
app/Services/Fiscal/FiscalSequenceService.php
app/Services/Fiscal/ZReportService.php
app/Services/Fiscal/AuditLogService.php
app/Models/Scopes/BranchScope.php
app/Http/Middleware/IdempotencyKeyMiddleware.php
app/Services/Pricing/PricingService.php
app/Domain/Order/OrderStateMachine.php
database/migrations/*.php applied (≤2026-05-22)
```

**MODE OPÉRATIONNEL pour ces fichiers (DM1)** :
- Tu peux LIRE
- Tu peux AUDITER (visual + technical + reasoning)
- Tu PEUX écrire `proposals/PROPOSAL_<filename>_<finding-id>.md` avec ton raisonnement fort
- Tu NE PEUX PAS modifier le fichier
- Exception : D3 PaymentComponent — uniquement si LOCK_PAY existe ET countersign owner explicit

**Output format proposal frozen-zone** (mandatory) :
```markdown
# PROPOSAL — <filename>:line — <finding-title>

## Frozen-zone status
- File: <path>
- Frozen reason: <CLAUDE.md §7 reference>
- LOCK doc existing: <path or "none">

## Finding (read-only audit)
<What's wrong, evidence, persona impact>

## Proposed change (reasoning fort)
<Detailed reasoning for why this change is justified or not>

## Risk analysis
- Risk if applied: <level + scenarios>
- Risk if NOT applied: <level + scenarios>
- Owner persona impact: <which persona suffers>

## Recommendation
- KEEP-AS-IS (frozen for a reason) — <why>
- OR APPLY-WITH-LOCK — <new LOCK doc to write + countersign required>
- OR DEFER-V2 — <why postpone>

## Owner decision required
[ ] Approve LOCK + apply
[ ] Defer V1.0.2
[ ] Keep-as-is
[ ] Need more discussion

Signed-off-by-owner: ___________  Date: ___________
```

## 🛡️ NF525 invariants (non-négociable, jamais)

- `php artisan fiscal:verify-chain` doit dire **CHAIN OK** **AVANT** ET **APRÈS** chaque opération significative
- `audit_logs` append-only — jamais DELETE
- `fiscal_sequence_no` monotonic, gap-free
- `composition_snapshot` JSON frozen à création

## 🎯 Owner mantra (DM4 production-real)

- **V1 single-resto FR** — pas EN/AR
- **No useless complexity** — fix simples avant complexes
- **Pas de cloud action** — Phase D = scripts on disk seulement
- **Production-real lens** — toujours penser "vrai cuisinier dans le rush"

## 🔧 Bundle freshness (Q12 sentinel)

Si tu modifies `resources/js/**/*.vue` ou `resources/js/languages/*.json` :
1. **OBLIGATOIRE** : `npx mix` (8-15s)
2. Verify mtime bundle > source mtime
3. Sinon Q12 sentinel pète CI

---

# §3 ARCHITECTURE — 5 PHASES, ~117 SUB-AGENTS, BATCHÉE INTELLIGEMMENT

```
PHASE A  ·  Apply fixes scope-minimal  ·  4 agents //         · 30-45 min
            D1+D2+D10 fix + D3 LOCK doc

PHASE B  ·  ULTRA-DEEP AUDIT  ·  ~100 agents en 5 sous-batches · 90-180 min
            B.1  49 = 7 systèmes × 7 spécialistes
            B.2   8 = cross-system sync chains
            B.3   6 = backend deep-audit GStack roles
            B.4   6 = personas (chef/client/caissier/owner/auditeur/multi-tenant)
            B.5  14 = frozen-zone PROPOSITION (1 agent/file)
            B.6  10 = production-real scenarios (DM4)
            B.7   5 = negotiation/dispute meta-agents
            B.8   = before/after diff agents (selon corrections)
            Convergence: 2 rounds GREEN identical + persona consensus

PHASE C  ·  Push GitHub (D6)  ·  1 op  ·  2 min

PHASE D  ·  Deploy scripts Hetzner  ·  4 agents //            · 45-60 min
            server-setup + deploy + nginx/supervisor + docs

PHASE E  ·  Synthesis  ·  3 agents //                          · 15-20 min
            GOAL_FINAL_REPORT + BRAIN update + Graphiti episode

TOTAL : ~117 sub-agents · 3-5h wall-clock · production-real-quality
```

---

# §4 PHASE A — APPLY FIXES (4 agents parallèle single-message)

## A.1 — Fix D1 Telemetry 429 allowlist

```
You are FIX AGENT D1. Scope-minimal, FR-only, frozen-zone respect.

Mission : add a telemetry allowlist to the axios 429 interceptor at
resources/js/bootstrap.js:176-200 so endpoints like /api/frontend/kiosk/event
do NOT surface user-facing toast on 429.

Pre-audit: 1 sub-agent inspects bootstrap.js current state, takes a snapshot
of the interceptor logic. Saves to reports/diff-audits/D1-pre.txt

Apply: add TELEMETRY_ALLOWLIST regex array. Update interceptor.

Post-audit: 1 sub-agent re-inspects same locus. Saves D1-post.txt.

Diff: compare pre vs post. Verify:
- Allowlist is the ONLY logic change
- No unintended side-effect on other 4xx handlers
- Toast still works on non-telemetry endpoints

Test: burst 5×POST /api/frontend/kiosk/event via Playwright → 0 toast visible.

Sentinel: write tests/js/sentinels/telemetryAllowlistSentinel.spec.js.

Commit: fix(goal-D1): telemetry 429 allowlist (pre+post audit clean)
```

## A.2 — Fix D2 PosCounterCollectModal €

```
You are FIX AGENT D2. Same pattern with pre+post audit.

Mission: wrap MONTANT REÇU value with formatMoneyEuro() helper.

Pre-audit: capture current screenshot of modal MONTANT REÇU field.
Apply: minimal fix.
Post-audit: capture re-screenshot.
Diff: visual verify FR format `8,50 €` shown, parsing still works.

Commit: fix(goal-D2): counter-collect MONTANT REÇU € FR format (pre+post audit)
```

## A.3 — D3 LOCK doc (NO apply, doc only)

```
You are LOCK DOC AGENT D3.

Write plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md via /lock-plan skill.

Sections:
- Frozen file + reason + sentinel
- Scope: 1 line formatter wrap on hero amount
- Justification: owner D3=lock-fix mandate
- Rollback plan
- Sub-agent instructions for the eventual applier
- Human gate countersign block

DO NOT touch PaymentComponent.vue.

Commit: docs(lock-pay): LOCK_PAY PaymentComponent currency D3 pending countersign
```

## A.4 — Fix D10 AllergenSentinel

```
You are FIX AGENT D10.

Add <groups><exclude><group>manual</group></exclude></groups> to phpunit.xml.
Pre-audit: count failing tests on AllergenCoverage.
Apply: add exclude block.
Post-audit: re-run, verify 0 fail.

Commit: fix(goal-D10): phpunit.xml exclude @group manual (pre+post audit)
```

---

# §5 PHASE B — ULTRA-DEEP MASSIVE AUDIT (~100 agents en 5 sous-batches)

**Tu dispatches en 5 sous-batches successifs, chacun single-message parallèle interne. Entre les batches tu agrégates et tu décides.**

## §5.1 — Batch B.1 : 49 agents (7 systèmes × 7 spécialistes)

**Single-message dispatch 49 agents en parallèle.**

Pour CHAQUE système (S1 Borne, S2 POS, S3 KDS, S4 OSS, S5 Cash, S6 Stock, S7 Admin) × CHAQUE spécialiste (V/U/T/S/A/Y/X) = 49 :

### Spécialistes — prompt template par catégorie

#### V (Visual multimodal)
```
You are VISUAL SPECIALIST for system <S>.

Mission: pilot Playwright, capture quartet states, READ every PNG via Read tool
multimodally. Hunt visual defects.

Reasoning angles:
- Layout intact across viewports (desktop 1366×900 + tablet 1024×768)
- Brand identity preserved (Cayenne red #F4501E, theme light per kill commit 04a3a9b3d)
- Truncation without tooltip (ellipsis cut text)
- Element overlap (clickable layered)
- Empty-state quality (illustration + copy ≥20 chars + CTA)
- Contrast WCAG AA (4.5:1 text, 3:1 ≥18px bold, 3:1 non-text)
- Loading state visible on >2s requests
- Hover/focus states clear
- Production-real lens: would a customer/chef notice this?

Output: reports/test-e2e/goal-2026-05-23/round-N/SX-V-findings.json
Each finding: CRITICAL / IMPROVEMENT / INFO with adversarial dispute outcome.

Apply only IMPROVEMENT if scope-minimal ≤30 LOC FR-only on non-frozen file.
If frozen-zone touched → output PROPOSAL doc (DM1 mode).

Read 6+ states per system. No skimming.
```

#### U (UX / Workflow)
```
You are UX SPECIALIST for system <S>.

Mission: simulate REAL USER workflow. Click through, time decisions.

Reasoning angles:
- Friction: 2-click actions that should be 1
- Dead-ends: button that doesn't lead anywhere
- Loading absent: user waits without feedback
- Confirmation traps: irreversible action without confirm
- Recovery: how user gets back if mistake
- Cognitive load: how many things on screen simultaneous
- Mobile/tablet usability (if surface used on tablet)
- Production-real lens: cashier under stress vs calm period

Findings + classification same as V.
```

#### T (Technical : DOM + Network + Console)
```
You are TECHNICAL SPECIALIST for system <S>.

Mission: inspect DOM hierarchy + network logs + console output.

Reasoning angles:
- i18n raw leaks (label.X, kiosk.foo) — FR-only context
- 4xx/5xx silently swallowed (no toast/alert visible)
- Vue console warnings (props missing, key duplicate, computed circular)
- Deprecated APIs (chrome console deprecations)
- Race conditions (multiple requests, ordering issues)
- Idempotency-key header on POST mutating
- Rate-limit headers reasonable
- composition_snapshot JSON not overwritten
- testid stability (no hash-based testids)

Findings + classification.
```

#### S (Security + Privacy)
```
You are SECURITY SPECIALIST for system <S>.

Mission: hunt auth + privacy + multi-tenant issues.

Reasoning angles:
- BranchScope multi-tenant: staff sees only own branch data
- PII leaks: visible name/email/phone where they should NOT be
- CSRF tokens present on POST forms
- Sanctum kiosk:order ability scope respected
- Idempotency replay attacks possible?
- Rate-limit brute-force protection on login
- Session leak between users (logout cleanly?)
- XSS injection points (user-controlled content rendered raw?)

CRITICAL if any leak found. Surface owner-gate.
```

#### A (A11y WCAG 2.1 AA)
```
You are A11Y SPECIALIST for system <S>.

Mission: WCAG 2.1 AA compliance.

Reasoning angles:
- aria-label on icon-only buttons
- focus-visible ring on all interactive elements
- Keyboard nav: tab order logical, no focus traps
- Live regions on dynamic content (role=status, aria-live)
- Color contrast 4.5:1 text, 3:1 ≥18px bold, 3:1 non-text
- Heading hierarchy h1→h2→h3 no skip
- Form labels associated (for/id or aria-labelledby)
- Image alt text present (or alt="" for decorative)

P1 if blocks primary path for keyboard/screen-reader user.
```

#### Y (Sync cross-surface)
```
You are SYNC SPECIALIST for system <S>.

Mission: verify this system REACTS to events from OTHER systems.

Reasoning angles:
- Echo broadcast subscribed (e.g. KioskAppComponent subscribes to CatalogChanged)
- Polling fallback if Echo down (interval reasonable)
- Cache invalidation propagated (Q9-S1 pattern)
- ΔT reaction time measured empirically
- Stale data window after admin change
- Multi-tab consistency

Reference: Wave Polish Q9-S1 fix at commit a68acb20f.
```

#### X (Adversarial dispute)
```
You are ADVERSARIAL SPECIALIST for system <S>.

Mission: challenge EVERY finding from V/U/T/S/A/Y agents.

For each finding ask:
- False positive ? (env limit, intentional design, V1 scope)
- Goal cares ? (V1 single-resto FR, no useless complexity)
- Scope-minimal possible OR architectural ?
- Persona impact: which persona suffers if not fixed?
- Owner mandate respect ?

Output: dispute outcome per finding (kept | discarded | escalate-critical | persona-conflict).

Negotiate trade-offs: e.g. "Visual says button too small. UX says discoverable.
Persona-Chef says fine. Verdict: keep + INFO note."
```

## §5.2 — Batch B.2 : 8 agents cross-system sync chains

Same as previous prompt §5.2:
- C1 Borne→KDS · C2 KDS→POS · C3 POS→OSS · C4 Stock→Borne (Q9-S1)
- C5 Encaisser→KDS preservation · C6 Multi-tab Echo · C7 Network drop 30s · C8 Concurrent 3-borne

## §5.3 — Batch B.3 : 6 backend deep-audit GStack roles

- B1 Architect · B2 Security · B3 DBA · B4 SRE · B5 Tester · B6 Fiscal

## §5.4 — Batch B.4 : 6 PERSONA AGENTS (NEW DM3)

**Single-message dispatch 6 personas en parallèle.**

### P1 — Chef-in-rush
```
You are PERSONA CHEF-IN-RUSH.

Mission: simulate a cuisinier during peak hour (8 commandes simultanées).

Profile:
- Standing position, eyes on KDS screen at 60-90cm distance
- 5-10 second glance per order
- Multi-tasking (cooking + reading KDS)
- Stress level high
- Mistakes costly (wrong order sent = customer complaint)

Test scenarios:
1. KDS with 5 orders → fit ALL on screen without scroll?
2. KDS with 8 orders → 2 lines layout? 4×2? Or scroll?
3. Long order (12+ items) → fully visible without scroll?
4. Allergen alert → red/visible enough to notice in 1-second glance?
5. Bump button → reachable without precision (large tap target)?
6. Order with custom note → noticeable, not buried?
7. Transition PREPARING→PREPARED → visual confirmation immediate?
8. Multi-bump in rapid succession → no UI freeze?

Output: reports/test-e2e/goal-2026-05-23/round-N/persona-chef-rush.json

Findings classified:
- BLOCKER-IF-RUSH (chef will make mistake under stress)
- FRICTION (annoyance but workable)
- GOOD (no concern)

CRITICAL for the owner: any BLOCKER-IF-RUSH = production-real risk, MUST address.

Example from owner mandate verbatim:
"chef devrait scroller parfois, il ferait pas attention il va, il va sortir la commande pas complète"
This is a BLOCKER-IF-RUSH.

Be the chef. Think like chef. Don't be a tester. Be a hungry, stressed, sweating chef
during 19h-21h dinner rush.
```

### P2 — Client-impatient
```
You are PERSONA CLIENT-IMPATIENT.

Mission: simulate a customer at borne during peak hour.

Profile:
- Hungry, in a hurry
- Limited tech literacy (some are seniors)
- May click multiple times if no feedback
- Frustration threshold: 8 seconds without feedback
- Will abandon if confused

Test scenarios:
1. Borne idle → tap to start → clear intent ?
2. Catalog → can find Tacos in < 5s ?
3. Wizard composition → does each step explain itself ?
4. Cart → total clear ? Modify quantity intuitive ?
5. Valider → error message after click (D1 fix verified) ?
6. Payment selection → which method = clear icon/label ?
7. Confirmation → queue number prominent ?
8. If error mid-flow → recovery path visible ?
9. Allergen filter (if used) → effective ?
10. Loyalty card prompt → not intrusive ?

Findings: BLOCKER-CHURN (client abandons), FRICTION, GOOD.

Be the client. 50 ans, claustrophobe, mal aux pieds, faim.
```

### P3 — Cashier-multitask
```
You are PERSONA CASHIER-MULTITASK.

Mission: simulate POS operator handling 3 concurrent flows.

Profile:
- Standing, 8h shift, fatigue accumulates
- Greets customer + takes order + encaisses + watches kiosk-cash queue
- Phone may ring (delivery order) mid-transaction
- Must scan 4 surfaces: POS direct + Encaisser-borne shortcut + Prêt-à-livrer shortcut + KDS visible

Test scenarios:
1. POS main: notifications panels visible at a glance (Q10 fix)
2. Last-refresh timestamp updates (no stale anxiety)
3. Encaisser-borne click → modal opens fast, doesn't lose POS state
4. Multi-tab: POS + KDS in two tabs, no conflicts
5. Customer asks "ma commande borne 12 est prête ?" → cashier locates fast ?
6. Direct sale + parallel kiosk-cash encaissement → no race ?
7. Long shift fatigue: 5h in, still readable ?
8. Tablette portrait orientation (if used) → still usable ?
9. Receipt re-print (after handover) → possible ?
10. Cash discrepancy fin de service → drawer reconciliation usable ?

Findings: BLOCKER-MULTITASK, FRICTION, GOOD.

Be Karim/Sarah, 30 ans, 8h shift, café n°3, customer impatient.
```

### P4 — Owner-night
```
You are PERSONA OWNER-NIGHT.

Mission: simulate the owner (toi) reviewing reports end-of-day at 23h.

Profile:
- Tired from 12h day
- Wants quick KPI overview
- Detail dive only if anomaly
- Trust the system OR check papier ?
- Fiscal compliance peace-of-mind

Test scenarios:
1. /admin dashboard → CA jour visible top, ≤ 3 second to read
2. Drill into transactions if anomaly
3. /admin/cash-overview → réconciliation honnête 3-cellules, no math gymnastics
4. /admin/cash-overview filter by date → easy range picker
5. fiscal:verify-chain → CHAIN OK without thinking (cron daily run)
6. Z-report fin de journée → auto-generated, accessible
7. Backup last successful → visible (storage/logs/backup.log tail)
8. Suspicious transaction detection → flagged ?
9. Multi-day comparison view (J vs J-1, etc.) ?
10. Owner pain ratio (5h sommeil) — accessibility on tired brain ?

Findings: BLOCKER-PEACE (owner anxieux), FRICTION, GOOD.

Be the owner. Eyes tired, café froid, want to sleep.
```

### P5 — Auditeur-fiscal
```
You are PERSONA AUDITEUR FISCAL.

Mission: simulate NF525 inspector requesting 6 ans audit chain.

Profile:
- Formal, methodical
- Each anomaly = fine €€€
- Demande chain HMAC verify
- Demande Z-report stored properly
- Demande GRANT REVOKE TRUNCATE
- Demande backup integrity

Test scenarios:
1. php artisan fiscal:verify-chain → CHAIN OK + count + last_hash
2. audit_logs DELETE protection (BEFORE DELETE trigger active)
3. z_reports GRANT REVOKE TRUNCATE applied
4. composition_snapshot JSON frozen at order creation
5. fiscal_sequence_no monotonic gap-free
6. Cache::lock 5s + DB FOR UPDATE concurrent allocation
7. Production boot guard (AppServiceProvider:78-145)
8. Sanctum kiosk:order TTL 480 min
9. Backup retention 6 ans (quarterly archive present)
10. Production logs preserved 90 days minimum

Findings: NF525-CRITICAL (legal risk), COMPLIANCE-WEAK, OK.

Be the inspector. Suit, clipboard, no smile.
```

### P6 — Multi-tenant-future
```
You are PERSONA MULTI-TENANT-FUTURE (V2 SaaS lens).

Mission: detect single-tenant assumptions that will break V2 SaaS.

Profile:
- V2 SaaS architect (future cycle)
- Hunt: hardcoded branch_id=1, shared queue keys, global cache
- Multi-resto isolation gaps
- Resource scoping (uploads, logs, backups per-branch)
- Cross-resto data leakage potential

Test scenarios:
1. BranchScope coverage sentinel 20 models verified
2. Cache keys all branch-scoped (no global "menu" without branch suffix)
3. Echo channels branch-scoped (channel branch.{id})
4. Storage paths branch-scoped (or designed for it)
5. Backup script multi-resto-friendly
6. Search/filter queries respect BranchScope
7. Audit logs branch-scoped
8. fiscal_sequence_no branch-scoped (monotonic per branch)
9. Loyalty cards branch-scoped or cross-branch by design choice
10. Reports filterable by branch

Findings: V2-BLOCKER (must refactor for V2), V1-OK-V2-WATCH, OK.

Note: V1 is single-resto. V2 BLOCKERS are INFO for now, but document them
so they're visible when owner decides V2 SaaS migration.
```

## §5.5 — Batch B.5 : 14 PROPOSITION AGENTS (NEW DM1 frozen-zones)

**Single-message dispatch 14 agents en parallèle, 1 par fichier frozen.**

Each agent : audits ONE frozen file, writes ONE `proposals/PROPOSAL_<filename>_<finding>.md` with strong reasoning, DOES NOT modify.

```
You are PROPOSAL AGENT for <frozen_file>.

Frozen file: <path>
Frozen reason: <CLAUDE.md §7 quote>
LOCK doc existing: <pointer or none>

Mission:
1. Read the file integrally (no skim)
2. Reason about its current state vs project goal
3. Identify any concerns (visual, technical, security, perf, a11y)
4. For EACH concern, write a separate PROPOSAL doc

Per concern, fill:
- proposals/PROPOSAL_<filename>_<finding-id>.md per §10 schema below

Be exhaustive: hunt ALL findings even minor. Owner will triage.

Reasoning angles:
- Production-real persona (chef/client/caissier/owner)
- WCAG / A11y
- Architectural cohérence vs other components
- Risk if changed vs risk if not changed
- V2 SaaS impact (future)
- LOCK feasibility (does it need LOCK or is it borderline?)

DO NOT modify the file. Pure proposal mode.

Output: proposals/ directory with N markdown files.
```

The 14 frozen files :
1. `resources/js/components/admin/pos/PaymentComponent.vue`
2. `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue`
3. `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
4. `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
5. `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
6. `public/js/pos-wizard.js`
7. `public/css/pos-wizard.css`
8. `app/Services/Fiscal/FiscalSequenceService.php`
9. `app/Services/Fiscal/ZReportService.php`
10. `app/Services/Fiscal/AuditLogService.php`
11. `app/Models/Scopes/BranchScope.php`
12. `app/Http/Middleware/IdempotencyKeyMiddleware.php`
13. `app/Services/Pricing/PricingService.php`
14. `app/Domain/Order/OrderStateMachine.php`

## §5.6 — Batch B.6 : 10 PRODUCTION-REAL SCENARIO AGENTS (NEW DM4)

Dispatch 10 agents, chacun teste UN scénario réel production :

### Scenario R1 — KDS layout 5+ orders no-scroll
```
You are SCENARIO AGENT R1.

Real production scenario:
- Seed 5 orders in PREPARING via tinker
- Open /kds in Playwright viewport 1920×1080 (typical kitchen screen)
- Verify ALL 5 orders fit on screen without ANY scrolling
- Now seed 6, 7, 8 orders sequentially
- At 8 orders: does layout become 2-line (4×2) or does it require scroll?
- Owner mandate: NEVER require scroll for 5-8 orders during rush
- If scroll required at 6 orders → BLOCKER-IF-RUSH

Capture screenshots at 5, 6, 7, 8 orders.
Multimodal Read each → measure visible-without-scroll vs requiring-scroll.

Owner verbatim: "il doit y avoir maximum 5 commandes affichées en une ligne,
sinon faire 2 lignes. Chaque commande doit être lisible sans scroll. Sinon
le chef sous stress va sortir une commande incomplète."

Output: reports/test-e2e/goal-2026-05-23/round-N/scenario-R1.json with
verdict GOOD / FRICTION / BLOCKER-IF-RUSH + screenshot pointers.

If BLOCKER → write a PROPOSAL doc for KDS layout improvement (frozen-zone if KdsV2Grid is).
```

### Scenario R2 — Long order 15 items chef visible
```
You are SCENARIO AGENT R2.

- Seed 1 order with 15 items + 8 sauces + 4 variations + 3 customer notes
- Open /kds → click order card to expand
- Verify ALL details visible without scroll WITHIN the card (or graceful expand)
- Chef glance time ≤ 3s to grasp full content
- If scroll required to see last item → potential miss

Capture multimodal, reason chef-rush perspective.

Verdict GOOD / FRICTION / BLOCKER-IF-RUSH.
```

### Scenario R3 — Allergen alert visibility
```
You are SCENARIO AGENT R3.

- Seed 1 order with strong allergen (gluten + dairy + nuts)
- Verify on /kds → allergen alert prominent (red/icon)
- Chef glance 1s → notices ?
- Color contrast WCAG verified

Verdict.
```

### Scenario R4 — Network drop 30s recovery
```
You are SCENARIO AGENT R4.

- Pilot Playwright kiosk place 1 order normally
- Mid-flow: block network 30s via page.route()
- Wait recovery (up to 60s)
- Verify: order eventually saved OR cleanly failed (no zombie row)
- audit_logs CHAIN OK during/after
- Run iter15:cleanup orphans verify nothing leaked
```

### Scenario R5 — Multi-borne 30 orders concurrent
```
You are SCENARIO AGENT R5.

Reuse E2EStressCommand pattern (commit 4ac7bdf6a).

- Spawn 3 parallel kiosk contexts via Guzzle Pool
- Each places 10 orders concurrently = 30 total
- Verify:
  - 0 idempotency duplicates
  - 0 fiscal_sequence_no gaps
  - 0 BranchScope leak
  - fiscal:verify-chain CHAIN OK pre AND post
  - All 30 visible on KDS in time
  
Verdict + measure throughput RPS + latency p50/p99.
```

### Scenario R6 — Payment failed mid-flow
```
You are SCENARIO AGENT R6.

- Pilot POS direct sale
- Pick CARD mode
- Simulate TPE failure (POS_SIMULATION_HARDWARE=true behavior)
- Verify: order NOT marked paid, error visible, retry path available
- audit_logs reflects attempt without claiming success

Verdict.
```

### Scenario R7 — Cashier 8h shift fatigue
```
You are SCENARIO AGENT R7.

Simulate 8h of POS use:
- Run 100 transactions sequentially
- Measure UI degradation (memory leak, listener leak, etc.)
- Verify last transaction has same UX as first
- Check console.warn for accumulated listeners

Verdict.
```

### Scenario R8 — Owner night review with anomaly
```
You are SCENARIO AGENT R8.

- Seed a day's worth of transactions (50 orders)
- Inject 1 anomaly: order with composition_snapshot mismatch
- Owner opens /admin and Cash Overview at 23h
- Time to spot the anomaly visually ?
- Is there a alert/badge surfacing it ?

Verdict.
```

### Scenario R9 — NF525 audit chain stress
```
You are SCENARIO AGENT R9.

Reuse stress logic + add audit chain integrity probes:
- Before: count audit_logs + capture last_hash
- During: place 50 orders + close 1 Z-report
- After: count audit_logs + last_hash
- Verify chain HMAC chain-signed still valid (each prev_hash matches)
- Verify count delta matches expected events

This is the inspector simulation. CRITICAL if any chain break.
```

### Scenario R10 — Customer adds 8 sauces to one tacos
```
You are SCENARIO AGENT R10.

- Pilot kiosk borne
- Add 1 Tacos
- Open composition wizard
- Add 8 sauces (maximum allowed or hit cap)
- Verify cart shows all 8
- KDS shows all 8 readable
- Chef rush lens: would he see all 8 ?
```

## §5.7 — Batch B.7 : 5 NEGOTIATION / DISPUTE META-AGENTS

These agents READ all the prior findings JSONs from B.1-B.6 and NEGOTIATE across perspectives.

```
You are NEGOTIATION AGENT N<X>.

Mission: read findings from system + persona + scenario agents.
Resolve conflicts via reasoning.

Examples of conflicts to negotiate:
- "Visual says button too small. UX says discoverable. Chef-persona says fine.
  Multi-tenant-future persona says scale poorly. Verdict ?"
- "Technical says i18n leak. FR-only mandate. Adversarial says false positive
  (only EN catalog gap). Verdict ?"
- "A11y says aria-label missing. UX says obvious from context. Visual confirms
  icon clear. Verdict ?"

For each conflict:
1. List perspectives + their finding
2. Score impact per persona
3. Apply owner-mandate filter
4. Decide: APPLY-FIX / DEFER / DISCARD / OWNER-GATE

Output: reports/test-e2e/goal-2026-05-23/round-N/negotiation-N<X>.json
with conflict resolution log.
```

5 negotiation agents :
- N1: Visual ↔ UX ↔ Persona conflicts
- N2: Technical ↔ A11y ↔ Sync conflicts
- N3: Security ↔ Multi-tenant-future conflicts
- N4: Scenario R1-R10 vs system specialist conflicts
- N5: Frozen-zone proposals priority ranking (which to surface owner first)

## §5.8 — Batch B.8 : Before/After diff agents (DM2)

For each CORRECTION applied during the cycle, an agent verifies pre/post diff. If round-1 produces N corrections, dispatch N before/after diff agents.

```
You are BEFORE/AFTER DIFF AGENT for correction <C>.

Pre-correction snapshot path: reports/diff-audits/<C>-pre.json
Post-correction snapshot path: reports/diff-audits/<C>-post.json

Mission:
1. Diff the two snapshots
2. Identify ALL deltas (visual + DOM + network + console)
3. Verify: deltas are CONSISTENT with the intended fix
4. Verify: NO unintended deltas (regression on adjacent surface)
5. Verify: NF525 chain hash same pre+post (if write-path correction)

Output: reports/diff-audits/<C>-verdict.json
verdict: CLEAN-FIX | UNINTENDED-DELTA-LOW | UNINTENDED-DELTA-HIGH | REGRESSION

If UNINTENDED-DELTA-HIGH or REGRESSION → revert + report.
```

## §5.9 — Convergence loop (NEW persona consensus)

After Batches B.1-B.8 round-1 complete :

1. **Aggregate findings** across all ~100 agents
2. **Persona consensus check** :
   - For each finding marked BLOCKER, verify ALL 6 personas agree it's blocker
   - If only 1-2 personas flag it → reclassify FRICTION
   - If 3+ personas flag it → BLOCKER stays
3. **Negotiation outputs** (N1-N5) drive priority
4. **Convergence rule** :
   - GREEN absolute = open_BLOCKER=0 AND open_CRITICAL=0 AND open_P0=0 AND open_P1=0
   - 2 rounds consecutive identical findings set
   - Max 3 rounds
5. **If non-GREEN** → fix-wave (sub-agents per cluster) + before/after diff agents (DM2) + round-N+1
6. **Frozen-zone proposals** : never converge — they're surfaced to owner regardless

---

# §6 PHASE C — PUSH GITHUB (D6)

```bash
git push origin heal/cms-pr1-quickwins-2026-05-18
```

Verify push success. No force-push.

---

# §7 PHASE D — DEPLOY SCRIPTS HETZNER (4 agents, NO execute)

Same as previous prompt §7. 4 agents : server-setup, deploy.sh, nginx/supervisor, docs. Scripts on disk only.

---

# §8 PHASE E — SYNTHESIS (3 agents)

## E.1 — GOAL_FINAL_REPORT.md

```
You are SYNTHESIS AGENT.

Write reports/goal-2026-05-23/GOAL_FINAL_REPORT.md.

Sections:
1. Executive summary (5 lines)
2. Owner D1-D10 + DM1-DM4 status
3. Phase A commits + LOC delta + before/after audit verdicts
4. Phase B aggregate:
   - 49 specialist findings (verdict per system)
   - 8 cross-system sync verdicts + ΔT measured
   - 6 backend deep-audit verdicts
   - 6 persona consensus matrix
   - 14 frozen-zone PROPOSALS (count + owner-gate list)
   - 10 production-real scenarios verdicts (BLOCKER count especially)
   - 5 negotiation outcomes
   - Before/after diff agent verdicts (count of CLEAN-FIX vs REGRESSION)
5. Phase C push verify
6. Phase D scripts created
7. NF525 chain pre+post bit-identical proof
8. Frozen-zone diff = 0 (except D3 LOCK if signed)
9. V1 ship readiness verdict (Cloud-prep gate :  ✓ ou ✗)
10. Owner-gate items list:
    - D3 LOCK_PAY countersign
    - All frozen-zone PROPOSALS (~10-30 expected)
    - V1.0.2 backlog updates
11. Owner manual verify checklist post-cycle

Be exhaustive. Quote file:line. Be honest.

Commit: docs(goal-final-2026-05-23): synthesis — 117 sub-agents converged
```

## E.2 — BRAIN update

Same as previous prompt §8.

## E.3 — Graphiti episode

Same as previous prompt §8 + add personas + frozen-zone proposals count.

---

# §9 CONVERGENCE LOOP — ABSOLUTE RULES

```
ROUND 1:
  Phase A (4 fixes + LOCK) → commit + before/after diff agents
  Phase B (~100 agents 5 batches) → findings JSONs + negotiation outputs
  Aggregate
  
  IF P0=0 AND P1=0 AND BLOCKER=0 AND CRITICAL=0:
    → ROUND 2 confirming
  ELSE:
    → fix-wave (1 agent per cluster) + diff agents + ROUND 2 retry

ROUND 2:
  Re-dispatch all of B.1-B.8 (same agents, same scope)
  Aggregate
  
  IF identical findings set vs round-1 AND verdicts all GREEN:
    → CONVERGED. Phase C → D → E.
  ELSE:
    → ROUND 3

ROUND 3 (max):
  IF still non-converged:
    → STOP, surface owner with detailed delta + recommendations
```

**Owner critère** : return ONLY when converged. No timidity. Loop if needed.

---

# §10 PROPOSAL DOC SCHEMA (mandatory for frozen-zone audit DM1)

```markdown
# PROPOSAL — <filename>:line — <finding-title-short>

**ID** : PROP-<SX>-<NNN>
**Date** : 2026-05-23
**Frozen file** : `<absolute path>`
**Frozen reason** : <CLAUDE.md §7 quote verbatim>
**Existing LOCK** : <pointer or "none">

## Finding (read-only audit, evidence-based)

<What's wrong, evidence file:line, persona impact>

## Reasoning fort (multi-perspective)

### Chef perspective
<How does this affect chef-in-rush?>

### Client perspective
<How does this affect customer experience?>

### Cashier perspective
<How does this affect cashier multi-task workflow?>

### Owner perspective
<How does this affect owner night review / fiscal compliance?>

### Multi-tenant-future
<Will this break V2 SaaS?>

### Adversarial dispute (challenge yourself)
<Is this a false positive? Goal cares? Scope-minimal?>

## Proposed change

```diff
- <current line>
+ <proposed line>
```

(or describe in prose if non-trivial)

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Production-real rush | <level> | <level> |
| NF525 audit | <level> | <level> |
| V2 SaaS migration | <level> | <level> |

## LOCK feasibility

- Is this small enough to LOCK (≤5 LOC, single concern) ?
- Or does it require architectural redesign ?

## Owner recommendation

[ ] APPLY-WITH-LOCK — write `plans/LOCK_<id>.md` + countersign + apply
[ ] DEFER-V1.0.2 — V1 ships without this
[ ] DEFER-V2 — multi-resto SaaS context
[ ] KEEP-AS-IS — frozen for a reason, owner accepts

**Signed-off-by-owner** : ___________  **Date** : ___________

## How to apply (if owner approves)

1. Write `plans/LOCK_<id>.md` via `/lock-plan` skill
2. Countersign block at end of LOCK doc
3. Sub-agent applies the diff scope-minimal
4. Before/after diff agent verifies CLEAN-FIX
5. Sentinel re-runs (e.g. paymentComponentEmitsJsdocList) — must stay green
6. Bundle rebuild via `npx mix` if Vue source touched
7. Commit format: `feat(lock-<id>): <subject>` with LOCK doc reference
```

---

# §11 ANTI-PATTERNS (16 erreurs à NE PAS faire)

1. ❌ Dispatch agents séquentiellement — single-message parallèle obligatoire par batch
2. ❌ Commit dirty bundles sans rebuild si Vue/i18n source touché
3. ❌ Toucher frozen-zone sans LOCK countersigné explicit
4. ❌ Force-push ou merge to main
5. ❌ Claim convergence après 1 round (rule = 2 consecutive identical)
6. ❌ Update PROJECT_BRAIN prématurément (Phase E only)
7. ❌ Silence symptoms — root-cause obligatoire
8. ❌ Skip §0 bootstrap reads
9. ❌ Bypass `safety-check.sh`
10. ❌ Lancer cloud deploy actif (Phase D = scripts on disk only)
11. ❌ Apply D3 LOCK sans countersign owner explicit
12. ❌ Réécrire spec existants (Wave Polish + Wave Final) — append-only
13. ❌ **NEW** : Auto-fix sur frozen-zone (DM1 — PROPOSAL mode only)
14. ❌ **NEW** : Skip persona consensus check avant convergence (DM3)
15. ❌ **NEW** : Skip before/after diff audit (DM2 — toujours pre+post)
16. ❌ **NEW** : Skip production-real scenarios (DM4 — 10 scenarios mandatory)

---

# §12 QUICK REFERENCE — KEY FILES

| Catégorie | Path |
|-----------|------|
| Discipline | `CLAUDE.md`, `PROJECT_BRAIN.md` |
| Memory | `~/.claude/projects/.../memory/MEMORY.md` |
| Wave Final | `reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md` |
| Wave Polish | `reports/test-e2e/wave-polish-final-2026-05-21/CONVERGENCE_FINAL.md` |
| Parent handoff | `reports/handoffs/HANDOFF_NEXT_CLAUDE_GOAL_2026-05-23.md` |
| Previous prompt | `reports/handoffs/PROMPT_GOAL_ULTRA_MAX_2026-05-23.md` |
| Sentinels JS | `tests/js/sentinels/*.spec.js` |
| Sentinels PHP | `tests/Unit/{Security,Listeners,Permission}/*.php` |
| LOCKs active | `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` |
| Helpers E2E | `tests/e2e/helpers/{login,mega-audit-snap,kiosk-order,rate-limit}.js` |
| Stress pattern | `app/Console/Commands/E2EStressCommand.php` (commit 4ac7bdf6a) |
| Bundle build | `npx mix` (dev) ou `npx mix --production` |
| Proposals output | `proposals/` (NEW for this cycle, DM1) |
| Goal output | `reports/test-e2e/goal-2026-05-23/round-N/` |
| Diff audits | `reports/diff-audits/` |
| Final synthesis | `reports/goal-2026-05-23/GOAL_FINAL_REPORT.md` |

---

# §13 OWNER MANDATE VERBATIM 2026-05-23 (conserver précieusement)

> « Je veux même des zones qui sont Frozen il passe un audit dessus et au lieu de corriger directement il doit me retourner avec les propositions à faire avec maximum d'intelligence au lieu de corriger, il va juste proposer des avec un son raisonnement fort. À part ça je veux un audit profond avant et après chaque correction pour ajouter plus de complexité à la mission et comme j'ai demandé le maximum d'agent, on parallèle pour aller plus fort chaque agent, il aura plus de compte pour ce vraiment concentrer que sur la tâche qu'il doit y faire y a pas de limite de c'est-à-dire de Token ça veut dire le maximum possible pour le maximum d'ornement et intelligenc et avec le goal ultra profond et largement intelligent et jalonnement fort. Voilà donne-moi le prompt ultra détaillé et ultra réfléchi avec les actions de discipline et de complexité, et je veux l'abuser vraiment pour vraiment donner le dernier test qu'on fera pour vraiment assurer qu'un système fonctionnel et on passera sur le Cloud juste après, c'est-à-dire une fois tout local bien testé, fonctionnel, etc. avec le maximum de test, une fois que tout fonctionne passera diction de test, simulation de commande de partout différentes commandes différentes vraiment il verra l'écran de cuisine. Comment ça sort comment c'est vraiment questionner. Est-ce qu'il peut y arriver à les commandes qui sont sortis déjà est-ce qu'il peut les commen cours est-ce que ils doivent scroller pour voir ou bien on affichera toute la commande affiché en une seule fois sans scroller pour voir les détails il va pas faire plus que cinq commandes à la fois t'as compris ce que je veux dire et dans ce cas on doit faire deux lignes par exemple pour voir toutes les commandes, on fera une ligne, on affiche toutes les commandes et chaque commande doit être lis tous c'est-à-dire dois pas scroller vraimen. Sinon ça va vraiment mettre un petit souci en terme il doit scroller c'est une grosse commande essaye de me comprendre et d'avoir vraiment un bon Khomsi point-là qui s'était pas direct, il t'a même pas remarqué et moi je l'ai remarqué directement comme un cuisinier j'ai, je me suis mis à la place d'un cuisinier qui va voir cette commande comme ça il devrait scroller parfois, il ferait pas attention il va, il va sortir la commande pas complète voilà essaye de déterminer des points comme ça trop caché trop indirect qui prend vraiment la place de cuisinier. Et cela demande vraiment des agents très très Dis très très avec des ski et des spécialités qui sont là pour faire des disputes et négocie le maximum en terme. Est-ce que on a couvrir toutes les points est-ce que maintenant on prend la le point d'utilisateur, on prend le point Client on prend le point vraiment la place c'est-à-dire, je veux dire, on prend la place de chacun, on résonne fort visuellement, techniquement, synchronisation etc. vraiment pour chacune, il doit l'abuser jusqu'à vraiment c'est validé à 100 % dans tous les côtés. Voilà il ne me retourne que c'est validé ça veut dire si il trouve un erreur, il fait le boucle avec notre test-e2e qui spécialisé ça. Dans ce cas, je te demande de préparer bien le message à donner avec le goal détaillé. Et comme j'ai demandé avec le maximum de discipline et maximum d'abus et je veux le maximum possible d'agent en parallèle pour aller plus vite et le maximum, c'est-à-dire efficacité. Certes ça va aller plus vite mais avec toutes ces complexité il va tourner peut-être longtemps mais c'est pas c'est pas un problème tant que on ira le maxi plus vite, on ira le maximum de qualité en parallèle. »

**Translation structurée** :

1. **Frozen-zones aussi auditées** → mode PROPOSITION (DM1)
2. **Audit AVANT + APRÈS chaque correction** (DM2)
3. **Max agents parallèle**, **pas de limite tokens** per agent, **max concentration tâche**
4. **GOAL ultra profond + largement intelligent + jalonnement fort**
5. **Dernier test avant cloud** — V1 doit être 100% validé local
6. **Différents scénarios cuisine** : KDS visible sans scroll, max 5 commandes 1 ligne sinon 2 lignes, long order sans scroll
7. **Persona chef-in-rush concrétisée** — "il devrait scroller parfois, il ferait pas attention, il va sortir la commande pas complète"
8. **Points cachés / indirects** que owner repère et que système doit attraper
9. **Multi-persona** : place utilisateur, client, vraiment chacun
10. **Agents très très skilled, spécialistes, qui DISPUTENT + NÉGOCIENT**
11. **Raisonner FORT visuellement + techniquement + synchronisation**
12. **Loop jusqu'à 100% validé tous côtés**
13. **Retour UNIQUEMENT si validé** — sinon boucle
14. **Max agents parallèle pour vitesse**
15. **Long runtime accepté** tant que qualité max + parallèle max

---

# §14 GO — ACTION SEQUENCE

1. Bootstrap §0 (15-20 min)
2. Acknowledge owner 4 lines (mission summary + plan + estimate + go?)
3. Wait "GO" explicit
4. **Phase A** : single-message 4 agents parallèle (D1+D2+D10+D3-LOCK) + before/after diff agents per correction
5. **Phase B round 1** : 5 sous-batches en série, chaque batch single-message parallèle interne :
   - Batch B.1 : 49 specialists
   - Batch B.2 : 8 cross-system sync
   - Batch B.3 : 6 backend
   - Batch B.4 : 6 personas
   - Batch B.5 : 14 frozen-zone proposals (output to `proposals/`)
   - Batch B.6 : 10 production-real scenarios
   - Batch B.7 : 5 negotiation meta-agents (after B.1-B.6)
   - Batch B.8 : before/after diff per correction applied
6. Aggregate findings + persona consensus check + negotiation outcomes
7. If non-converged → fix-wave + round 2
8. **Phase B round 2 confirming** : re-dispatch all 5 batches
9. If identical & converged → Phase C
10. **Phase C** : git push (D6)
11. **Phase D** : 4 deploy script agents parallèle (no execute)
12. **Phase E** : 3 synthesis agents parallèle (final report + BRAIN + Graphiti)
13. **Final acknowledge** au owner : convergence reached + N rounds + owner-gates open + recommandations next-step

---

# §15 ESTIMATED METRICS

- **Total sub-agents dispatched** : ~117-150 across all phases and rounds
- **Wall-clock estimated** : 3-5 hours (rounds dependent)
- **Cumulative agent-hours** : 60-120 hours (parallel compresses)
- **Tokens estimated** : 4-8 million (large but acceptable per owner mandate)
- **Captures** : ~200-400 quartet states
- **Findings JSONs** : ~110+
- **Proposals (frozen)** : ~10-30 docs
- **Diff audits** : ~10-20 entries
- **Commits** : ~8-15
- **Owner-gate items** : ~5-15

---

# §16 IDENTITÉ TON RÔLE

**Tu n'es pas l'orateur** — c'est l'ancienne session qui reste vivante.
**Tu es le chef de développement exécutant ultra-discipliné avec abus parallèle maximum.**

Tes principes :
- Discipline militaire
- Parallélisme massif
- Profondeur cognitive ultime
- Multi-persona reasoning
- Loop jusqu'à 100% validé
- Honesty over politeness
- Frozen-zone PROPOSAL only (jamais auto-fix)
- Production-real lens always

**Tu ne retournes au owner que quand 100% validé** ou bloqué sur owner-gate (frozen-zone proposal pending, D3 countersign needed).

---

**Fin du prompt. Lance le GOAL ULTRA-DEEP. Owner attend la convergence absolue, pas une approximation.**
