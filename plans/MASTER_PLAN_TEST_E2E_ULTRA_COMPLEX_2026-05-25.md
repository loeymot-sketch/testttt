# 🎯 MASTER PLAN ULTRA-COMPLEXE — /test-e2e FoodKing V1 Le Cayenne

**Date** : 2026-05-25
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`
**HEAD** : `bf1cc0382` (post Bad-Mood Heal)
**Mission** : Test E2E exhaustif tout système avant production
**Owner mandate** : « plan ultra complexe bien discipliné »

---

## §0 — POURQUOI CE PLAN

Les waves précédents (Wave Final, Phase A-P, Gap-Hunt, Bad-Mood, test-e2e MAX) ont accumulé **263+ sub-agents** + **504 sentinels GREEN** + **87 commits**. Mais le wave test-e2e MAX du 2026-05-25 a **rate-limité 16/19 agents** et **E2E-13 a tamper dev DB** par accident.

**Ce plan corrige ces erreurs** :
1. Rate-limit-aware batching (séquentiel 6 agents max par batch)
2. NF525 read-only ABSOLU (no DB write, no audit_logs insert)
3. Discipline contracts explicit per phase
4. Convergence loop max 3 rounds
5. Recovery procedure if rate limited
6. Production-real scenarios mandatory

---

## §1 — DISCIPLINE CONTRACTS (Non-Négociables)

### DM1 — Frozen-Zone Discipline
- 14 §7 files read-only, PROPOSAL docs only si modification nécessaire
- Diff vs `d601fdd34` doit rester 0 LOC

### DM2 — Pre+Post Diff Per Action
- Avant chaque écriture, snapshot état
- Après écriture, diff verify scope-minimal

### DM3 — Captures Comme Preuves
- Quartet par état : PNG + DOM dump + console log + network trace
- Stored in `reports/test-e2e/master-plan-2026-05-25/captures/<agent>/state-NN/`

### DM4 — Production-Real Scenarios
- Pas de mock pour fiscal flow
- Pas de skip pour multi-cashier race
- Pas de "happy path only"

### DM5 — Adversarial Inline
- Chaque agent doit avoir 1 hostile dispute paragraph
- "What's the WORST case I see?" obligatoire

### DM6 — NF525 READ-ONLY ABSOLU (NEW post E2E-13 incident)
- **AUCUNE écriture sur `audit_logs` table** par agents
- **AUCUNE écriture sur `z_reports`** par agents
- **AUCUNE modification `fiscal_sequence_no`** sur orders existants
- Pour tester chain integrity → **utiliser CLI** `php artisan fiscal:verify-chain` SEULEMENT (read-only)
- Si agent veut tester tamper detection → expliquer en doc, **NE PAS injecter**

### DM7 — Rate-Limit-Aware Batching (NEW)
- Max 6 agents en simultanée single-message
- Entre batches : pause 30-60s (let API breathe)
- Si rate limit détecté → exponential backoff
- Total : 8 batches × 6 = 48 agents max in sequence

### DM8 — Honest Reporting
- Pas de claim convergence si rate-limit
- Pas de fabrication de finding
- Cite file:line + command output evidence

### DM9 — Convergence Loop
- ROUND 1 : Phases A-D execute
- IF open_P0=0 AND open_P1=0 → ROUND 2 confirming
- ROUND 2 : Re-dispatch only failing zones
- IF identical findings GREEN → CONVERGED
- ROUND 3 (max) : Stop + surface owner

### DM10 — Bundle Freshness Gate
- Pre-test : `npx mix` mandatory si Vue source touchée depuis last bundle
- Post-test : verify mix-manifest hash unchanged (no rogue rebuild)

---

## §2 — ARCHITECTURE D'EXÉCUTION

```
┌─────────────────────────────────────────────────────────────┐
│ PHASE A · BOOTSTRAP                            (sequential) │
│   A.1 Preflight (server, DB, fixtures, rate-budget)         │
│   A.2 Capture baseline state (NF525 + frozen + counts)      │
│   A.3 Reset Playwright MCP if connected                     │
├─────────────────────────────────────────────────────────────┤
│ PHASE B · SURFACE COVERAGE                        (8 batches │
│   B.1-B.7 (6 agents each) = 42 agents max                  │
│   7 systems × 6 personas                                    │
│   Between batches : 30s pause                              │
├─────────────────────────────────────────────────────────────┤
│ PHASE C · CROSS-SYSTEM FLOWS                       (1 batch │
│   6 agents : 4 cross-flows + 2 sync deep                   │
├─────────────────────────────────────────────────────────────┤
│ PHASE D · PRODUCTION SCENARIOS                     (2 batches│
│   10 hostile scenarios (rush, fatigue, network, etc.)      │
│   2 × 5 agents                                              │
├─────────────────────────────────────────────────────────────┤
│ PHASE E · ADVERSARIAL DEEP                         (1 batch │
│   6 agents : NF525 RO + RBAC + security + race + perf      │
├─────────────────────────────────────────────────────────────┤
│ PHASE F · VISUAL + UI QUALITY                       (1 batch│
│   5 agents : screenshot multimodal analysis                │
├─────────────────────────────────────────────────────────────┤
│ PHASE G · CONVERGENCE LOOP                          (var.   │
│   Round 1 → if RED: heal → Round 2                         │
│   Max 3 rounds                                              │
├─────────────────────────────────────────────────────────────┤
│ PHASE H · SYNTHESIS                                 (1 batch│
│   3 agents : aggregate + verdict + BRAIN update            │
└─────────────────────────────────────────────────────────────┘

Total max : ~75 agents sequential batched
Total time : 4-6h wall clock
Total context : ~500K tokens
```

---

## §3 — PHASE A : BOOTSTRAP (sequential)

### A.1 — Preflight server + DB
```bash
# Server up
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/login

# Queue worker
ps aux | grep "queue:work" | grep -v grep | head -3

# Redis
php artisan tinker --execute='echo Redis::ping() ? "OK" : "FAIL";'

# Spatie roles (post FIX-1)
php artisan tinker --execute='
foreach (["Admin", "Branch Manager", "POS Operator", "Chef"] as $r) {
    echo "$r: ".\Spatie\Permission\Models\Role::where("name", $r)->count()."\n";
}
'

# Payment gateways (post FIX-1)
php artisan tinker --execute='echo "payment_gateways: ".\DB::table("payment_gateways")->count();'
```

Expected :
- Server 200
- Queue ≥ 1 worker
- Redis OK
- Roles ≥ 4 (Admin, Branch Manager, POS Operator, Chef)
- payment_gateways ≥ 1

### A.2 — Baseline capture (READ-ONLY)
```bash
# NF525 chain state baseline
php artisan tinker --execute='
echo "audit_logs: ".\DB::table("audit_logs")->count()."\n";
echo "last_hash: ".substr(\DB::table("audit_logs")->orderByDesc("id")->value("current_hash"), 0, 16)."\n";
echo "z_reports: ".\DB::table("z_reports")->count()."\n";
'

# Frozen-zone diff baseline
for f in <14 §7 files>; do
    n=$(git diff d601fdd34..HEAD -- "$f" | wc -l)
    echo "$n LOC: $f"
done
```

Save to `reports/test-e2e/master-plan-2026-05-25/baseline.txt`.

### A.3 — Rate-budget tracking
- Anthropic Tier 4 default : 50 RPM Sonnet, 5 RPM Opus
- Plan : 6 agents/min max
- Estimated cost : ~200K context per agent × 75 agents = 15M tokens
- Reset playwright MCP if connected

---

## §4 — PHASE B : SURFACE COVERAGE (8 batches)

### Personas (réutilisés cycle précédent — proven)

| Code | Persona | Lens |
|------|---------|------|
| **P1** | Chef-rush | Workflow speed + visibility |
| **P2** | Client-impatient | UX + autonomy + clarity |
| **P3** | Caissier-multitask | Efficiency + accountability |
| **P4** | Owner-night | Reporting + anomaly detect |
| **P5** | Inspecteur-fiscal | NF525 + audit + traceability |
| **P6** | Staff-newbie | Safety + guidance + reversibility |

### Systems

| Code | Surface | Route |
|------|---------|-------|
| **S1** | Borne Kiosk | `/kiosk/idle` |
| **S2** | POS Caisse | `/admin/pos` |
| **S3** | KDS Cuisine | `/admin/kitchen-display-system` |
| **S4** | OSS Display | `/admin/order-status-screen` |
| **S5** | Cash Drawer | `/admin/cash-overview` |
| **S6** | Stock Dashboard | `/admin/stock-rupture-dashboard` |
| **S7** | Admin Backoffice | `/admin/dashboard` + sub-pages |

### Batches B.1 → B.7 (6 agents each, paused 30s between)

**B.1 (Borne S1)** : 6 personas en parallèle sur S1
- S1-P1 Chef-rush · S1-P2 Client · S1-P3 Cashier · S1-P4 Owner · S1-P5 Inspector · S1-P6 Newbie

**B.2 (POS S2)** : 6 personas en parallèle sur S2
**B.3 (KDS S3)** : 6 personas en parallèle sur S3
**B.4 (OSS S4)** : 6 personas en parallèle sur S4
**B.5 (Cash S5)** : 6 personas en parallèle sur S5
**B.6 (Stock S6)** : 6 personas en parallèle sur S6
**B.7 (Admin S7)** : 6 personas en parallèle sur S7

### Agent template Phase B

```
You are GSTACK+ADVERSARIAL E2E AGENT [S-P].

## Server: http://127.0.0.1:8000
## Credentials (if admin needed): admin@lecayenne.fr / 123456

## CRITICAL DISCIPLINE (read first)
- READ-ONLY DB operations (per DM6 NF525 read-only absolute)
- NO INSERT into audit_logs / z_reports
- NO UPDATE on existing orders fiscal_sequence_no
- Use Playwright MCP if connected (browser_navigate, browser_snapshot, browser_take_screenshot)
- Fallback: Bash + curl + DOM analysis via HTML response

## Mission
Walk [SYSTEM] as [PERSONA]. Capture 8-12 states. Adversarial score.

## States checklist (mandatory)
1. Initial landing
2. Primary action (per persona)
3. Secondary action
4. Edge state (empty / error / loading)
5. Cross-link to another surface
6. Multi-step interaction if applicable
7. Cancel / undo / back
8. Logout / leave

## Captures (per state)
- Screenshot to reports/test-e2e/master-plan-2026-05-25/captures/[S-P]/state-NN.png
- DOM dump to ...state-NN.dom.html
- Console log to ...state-NN.console.txt
- Network requests to ...state-NN.network.json

## Multimodal analysis (Claude reads screenshots)
For each screenshot:
- Layout intact ?
- Raw i18n labels visible ?
- Console errors ?
- Network 4xx/5xx ?
- Branding intact ?
- Accessibility ?

## Adversarial scoring (DM5)
1 hostile paragraph "Worst case I see is X because Y, owner would lose Z€/day"

## GStack quality scores
- UX score 1-10
- Performance score 1-10
- A11y score 1-10
- i18n score 1-10
- Visual polish score 1-10

## Output
reports/test-e2e/master-plan-2026-05-25/agents/B-[S-P]-findings.json

## Return
Path + N states + verdict GREEN|AMBER|RED + top 3 findings
```

---

## §5 — PHASE C : CROSS-SYSTEM FLOWS (1 batch, 6 agents)

| Agent | Flow | States |
|-------|------|--------|
| **C.1** | Borne → KDS → OSS (full kiosk journey) | 13 states |
| **C.2** | POS direct → KDS → OSS (cashier journey) | 10 states |
| **C.3** | POS encaisser borne → cross-surface state | 8 states |
| **C.4** | Refund flow Stripe → Order cascade | 8 states (read-only verify) |
| **C.5** | Multi-cashier race protection (K2-HEAL-01 + K2-HEAL-02) | 6 states |
| **C.6** | Admin config change → Borne+POS+KDS sync (I.3 heal) | 8 states |

Each agent runs a complete journey + measures sync latency + verifies Echo + verifies polling fallback.

**Discipline** : Pas de tamper, pas d'écriture sur fiscal tables. Read-only verify de l'état post-action.

---

## §6 — PHASE D : PRODUCTION SCENARIOS (2 batches)

### Batch D-A (5 agents)

| Agent | Scenario | Mesure |
|-------|----------|--------|
| **D.1** | Rush hour 50 orders / 10 concurrent | Stress sister + DB invariants |
| **D.2** | Network drop 30s mid-payment | Recovery + idempotency |
| **D.3** | Echo down 5min | Polling fallback adaptive (N-HEAL-04) |
| **D.4** | Cashier 8h shift fatigue (multiple sessions) | Memory leak + UI degradation |
| **D.5** | Customer 8 sauces tacos compose | Wizard composition extreme |

### Batch D-B (5 agents)

| Agent | Scenario | Mesure |
|-------|----------|--------|
| **D.6** | Owner 23h close anomaly | Z chain + cross-chain anchor |
| **D.7** | Multi-borne 30 concurrent submits | Sequence monotonic + queue |
| **D.8** | Payment failed mid-flow + retry | Stripe stranded CPN cleanup (K2-HEAL-05) |
| **D.9** | Allergen alert flow critical | UI prominence + WCAG |
| **D.10** | Cross-day order 23:58 → 00:02 | Z-loop dead zone HEAL-07 verify |

---

## §7 — PHASE E : ADVERSARIAL DEEP (1 batch, 6 agents)

| Agent | Focus | Mode |
|-------|-------|------|
| **E.1** | NF525 chain verify (CLI read-only) | Sentinel + trigger probe (no insert) |
| **E.2** | RBAC matrix (Spatie roles × routes) | 403 expected on cross-role access |
| **E.3** | Branch isolation (BranchScope 20 models) | Cross-branch fetch attempts |
| **E.4** | Sanctum token security | Kiosk token on admin route (J2-HEAL-02) |
| **E.5** | Idempotency replay (K2-HEAL-01 + middleware) | Same key 10× → 1 result |
| **E.6** | Performance baseline | p50/p95/p99 latency 7 endpoints |

**Discipline** : E.1 utilise SEULEMENT le CLI `fiscal:verify-chain` + `SHOW TRIGGERS`. PAS d'INSERT. (Lesson from E2E-13 dev DB incident.)

---

## §8 — PHASE F : VISUAL + UI QUALITY (1 batch, 5 agents)

| Agent | Surface visual deep |
|-------|---------------------|
| **F.1** | Kiosk visual quality (idle + wizard + cart + payment) |
| **F.2** | POS visual quality (cart + payment + receipt) |
| **F.3** | KDS visual quality (board + cards + chips + history) |
| **F.4** | OSS visual quality (queue + status + rotation) |
| **F.5** | Admin visual quality (dashboard + reports + lists) |

Each agent :
- Capture 7-10 high-resolution screenshots
- Multimodal Claude analysis : "describe what you see, find broken layouts, missing affordances, raw labels"
- WCAG contrast + readable distance
- Branding consistency
- French language quality

---

## §9 — PHASE G : CONVERGENCE LOOP

### Round 1 (post Phases A-F)

Aggregate all findings :
- Total P0 (V1 ship blockers)
- Total P1 (V1 must-have)
- Total P2 (V1.0.X)
- Total P3 (V2)

**IF** open_P0 = 0 AND open_P1 = 0 :
→ Schedule Round 2 confirming run (same agents, verify identical findings GREEN)

**ELSE** :
→ Dispatch heal-wave (1 agent per P0/P1 cluster)
→ Re-dispatch failing zones in Round 2
→ Loop max 3 rounds

### Round 2 (confirming)

Only re-runs Phase B+C+E (audit + cross + adversarial). Phase D + F skipped if Round 1 was GREEN-VISUAL.

### Round 3 (max)

Stop + surface owner with honest verdict (CONVERGED | PARTIAL | BLOCKED).

---

## §10 — PHASE H : SYNTHESIS (3 agents)

### H.1 — Mega aggregation
- Read ALL findings JSONs (~65+ files expected)
- Dedup by gap ID
- Score matrix
- Owner-friendly executive summary

### H.2 — BRAIN update
- §2 CURRENT STATE prepend
- §3 LAST DONE append
- §4 NEXT TO DO update

### H.3 — Graphiti episode push
- group_id=foodking
- Episode "test-e2e Master Plan 2026-05-25"
- Cite cycle TOTAL

---

## §11 — BUDGET ESTIMATES

### Agents

| Phase | Agents | Wall time | Notes |
|-------|--------|-----------|-------|
| A bootstrap | 0 (direct bash) | 5 min | Read-only |
| B surface coverage | 42 (7 batches × 6) | 60-90 min | 30s pause/batch |
| C cross-system | 6 | 12-15 min | 1 batch |
| D production scenarios | 10 (2 batches × 5) | 20-30 min | |
| E adversarial deep | 6 | 12-15 min | 1 batch |
| F visual quality | 5 | 10-12 min | 1 batch |
| G convergence Round 2 | 15-25 (failing only) | 20-30 min | If needed |
| H synthesis | 3 | 8-10 min | 1 batch |
| **TOTAL** | **~75-85** | **2-4h** | Sequential disciplined |

### Tokens

- Per agent : ~150-300K input + ~30-80K output
- Total per cycle : ~12-25M tokens
- Cost estimate Opus 4.7 : ~$200-400 per full cycle

### Storage

- ~400-600 screenshots
- ~600 JSON findings files
- ~100 MB total artifacts

---

## §12 — RATE-LIMIT RECOVERY PROCEDURE (DM7 enforcement)

Lessons from 2026-05-25 wave (16/19 rate-limited) :

### Detection
- Pattern : "API Error: Server is temporarily limiting requests (not your usage limit) · Rate limited"

### Backoff strategy
1. First rate-limit detected → pause 60s
2. Retry batch → if rate-limit again, pause 180s
3. Third attempt → pause 300s
4. Fourth attempt → stop, surface owner

### Recovery
- Resume batch where rate-limit hit (don't re-run completed agents)
- Track in `reports/test-e2e/master-plan-2026-05-25/progress.json`

### Prevention
- Max 6 agents per single-message
- 30s pause between batches mandatory
- If Anthropic API status shows degraded → wait longer

---

## §13 — RECOVERY OPS (POST-INCIDENT LEARNING)

Lesson from E2E-13 (dev DB tamper row injection) :

### What happened
E2E-13 NF525 adversarial agent injected `audit_logs.id=34 action=tamper.attempt current_hash=FAKE_HASH` despite read-only mandate. DELETE blocked by trigger ✓ (good evidence of security). Chain now reports TAMPER.

### Lessons
1. **DM6 added** : NF525 read-only absolute, no INSERT/UPDATE on fiscal tables
2. **Sentinel for security agents** : pre-flight check the agent prompt for "INSERT INTO audit_logs" or similar — reject prompt
3. **Dev DB cleanup procedure** : `php artisan migrate:fresh --seed` if chain tamper detected dev side
4. **Positive outcome** : the incident PROVED NF525 triggers + chain verify works empirically

### Cleanup before this new wave starts
```bash
# Option A: Fresh dev DB (clean baseline)
php artisan migrate:fresh --seed --force  # destructive — owner approval

# Option B: Live with tamper row in dev (sentinel will warn)
# Verify trigger still active : SHOW TRIGGERS LIKE 'audit_logs'

# Option C: Re-anchor chain (advanced, not recommended)
```

Owner decision required pre-Phase A.

---

## §14 — KICK-OFF GATES (Owner Approval Required)

Before launching Phase A :
- [ ] Server up + queue worker running
- [ ] Spatie roles + payment_gateways seeded (FIX-1 verified)
- [ ] Dev DB tamper state decision (A/B/C above)
- [ ] Disk space ≥ 5GB free (for captures)
- [ ] Anthropic API rate limit budget OK (not in throttle window)
- [ ] No production deploy in flight
- [ ] Owner accepts ~2-4h wall clock
- [ ] Owner accepts ~$200-400 token cost
- [ ] Owner confirms 75-85 agents acceptable

---

## §15 — EXIT CRITERIA / CONVERGENCE

### CONVERGED (Round 1 success)
- 0 P0 found
- 0 P1 introduced
- 100% surfaces tested
- All cross-flows GREEN
- All production scenarios GREEN
- Visual quality acceptable
- NF525 chain integrity preserved (pre = post)
- Frozen-zone 0 LOC diff

### NEEDS-FIX-LOOP (Round 2 triggered)
- 1-3 P0/P1 found → heal scope-minimal
- Re-dispatch failing zones
- Re-verify in Round 2

### BLOCKED (Round 3 stop)
- 4+ P0 found
- Architectural change needed
- Frozen-zone touch required (LOCK countersign blocker)
- Owner consultation required

---

## §16 — POST-RUN DELIVERABLES

```
reports/test-e2e/master-plan-2026-05-25/
├── plan.md                              (this file copy)
├── baseline.txt                          (state pre-test)
├── progress.json                         (live progress tracker)
├── agents/                               (~75 findings JSONs)
│   ├── B-S1-P1-findings.json
│   ├── B-S1-P2-findings.json
│   ├── ...
│   ├── C-1-findings.json
│   ├── D-1-findings.json
│   ├── E-1-findings.json
│   ├── F-1-findings.json
│   └── H-final-synthesis.json
├── captures/                             (~400-600 screenshots)
│   └── [agent]/state-NN.[png|dom|console|network]
├── convergence/
│   ├── round-1-aggregate.json
│   ├── round-2-aggregate.json (if needed)
│   ├── round-3-stop.json (if reached)
│   └── CONVERGENCE_FINAL.md
└── verdict.md                            (owner-readable)
```

---

## §17 — METRICS COLLECTED

Per agent :
- States captured
- Console errors per state
- Network errors per state
- Layout intact (binary)
- Raw labels (count)
- GStack scores (5 dimensions)
- Adversarial finding count
- Verdict GREEN/AMBER/RED

Per phase :
- Wall clock
- Agents converged / rate-limited / failed
- Findings P0/P1/P2/P3

Total cycle :
- Commits ↑
- Sentinels ↑
- NF525 chain bit-identical ?
- Frozen-zone diff verified 0

---

## §18 — SAFEGUARDS

### Branch protection
- All work on `heal/cms-pr1-quickwins-2026-05-18`
- No force-push
- No merge to main

### Production safety
- AppServiceProvider boot guard ACTIVE
- POS_SIMULATION_HARDWARE=true (dev mode)
- APP_ENV=local
- Pas de production deploy

### Rollback procedure
- Per-batch commit allow heal wave to be reverted
- BRAIN.md update at end only (not mid-cycle)
- Graphiti push at end only

---

## §19 — OUT OF SCOPE

❌ **NOT covered** by this plan :
- Mobile app (separate plan)
- Web Le Cayenne site (separate plan)
- SpinBoost SaaS (separate project)
- Cloud deployment (owner-initiated only)
- Hardware integration (TPE Senangpay physical)
- Real production data migration
- Multi-tenant V2 SaaS prep

---

## §20 — FINAL DISCIPLINE

Pour LANCER ce plan, l'agent orchestrateur DOIT :

1. ✅ Lire ce plan intégral
2. ✅ Vérifier §14 kick-off gates tous coches
3. ✅ Démarrer Phase A (READ-ONLY)
4. ✅ Suivre §4 batches sequential pacing
5. ✅ Capturer quartet par état (DM3)
6. ✅ Adversarial inline (DM5)
7. ✅ NF525 read-only ABSOLU (DM6 — lesson from E2E-13)
8. ✅ Rate-limit aware (DM7)
9. ✅ Honest reporting (DM8)
10. ✅ Convergence loop max 3 rounds (DM9)
11. ✅ Bundle freshness (DM10)
12. ✅ §15 exit criteria explicit verdict

---

## ANNEXE A — AGENT PROMPT SKELETON

```
You are GSTACK+ADVERSARIAL TEST-E2E AGENT [X.Y].

## Server : http://127.0.0.1:8000
## Credentials (if needed) : admin@lecayenne.fr / 123456
## Branch : heal/cms-pr1-quickwins-2026-05-18

## DISCIPLINE CONTRACTS (mandatory)
- DM1 frozen-zone PROPOSAL only (14 §7 files read-only)
- DM3 captures quartet per state
- DM5 adversarial inline (1 hostile paragraph)
- DM6 NF525 read-only ABSOLU (no INSERT/UPDATE on audit_logs / z_reports / order fiscal_sequence_no)
- DM8 honest reporting (no fabrication)

## Mission
[specific to agent]

## Tasks
[10-12 enumerated steps]

## Output
reports/test-e2e/master-plan-2026-05-25/agents/[X.Y]-findings.json :
{
  "agent": "X.Y description",
  "phase": "B|C|D|E|F|H",
  "states_captured": N,
  "screenshots": [paths],
  "gstack_scores": {ux, perf, a11y, i18n, polish},
  "adversarial_findings": [...],
  "raw_labels_found": [],
  "console_errors": [],
  "network_errors": [],
  "verdict": "GREEN|AMBER|RED",
  "top_3_findings": [...]
}

## Return
Path + N states + verdict + 3 critical findings + cycle time
```

---

## ANNEXE B — ESTIMATION COMPARÉE

| Approche | Agents | Temps | Coverage | Risque rate-limit |
|----------|--------|-------|----------|--------------------|
| Single-message massive 60+ | 60 | 30 min | Excellent | TRÈS ÉLEVÉ (proven 2026-05-25) |
| **Sequential disciplined (ce plan)** | 75-85 | 2-4h | **Excellent** | **FAIBLE** (6 max/batch + pause) |
| Plus conservateur (3/batch) | 75-85 | 4-6h | Excellent | TRÈS FAIBLE |
| Minimal (15 critical only) | 15 | 30 min | Bon | FAIBLE |

**Recommendation BRAIN** : Sequential disciplined ce plan = ratio coverage/risque optimal.

---

## ANNEXE C — POST-CYCLE OWNER NEXT STEPS

Si CONVERGED :
1. Owner physical walk (60-90 min) — `OWNER_PHYSICAL_WALK_CHECKLIST.md`
2. `.env` production flip (10 min) — `.env.production.example` template
3. Disk cleanup (15 min) — `tmutil thinlocalsnapshots`
4. First real Z close Monday 23:59 — premier vraie production NF525

Si NEEDS-FIX-LOOP :
1. Review heal-wave commits
2. Re-approve Round 2

Si BLOCKED :
1. Architecture decision owner
2. PROPOSAL docs review
3. Potential LOCK countersign

---

*Master plan ultra-complexe disciplined pour /test-e2e exhaustif. 17 phases prior cycle convergence sufficient OR ce plan re-validates end-to-end. Owner decides.*

**Approuvé pour exécution** : ☐
**Démarrage Phase A** : ☐
**Round 1 convergence** : ☐
**Owner physical walk** : ☐
**GO-LIVE production** : ☐
