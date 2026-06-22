# DISPUTE — Phase Z Recommendation (Adversarial Review)

**Date** : 2026-05-21
**Reviewer role** : Adversarial / second-opinion
**Branch under review** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `1116b3957`
**Recommendation under dispute** : Phase Z (6 sub-waves Z1-Z6) — soak test → WebSocket → DB hardening → hardware → HTTPS LAN → shadow operation
**Owner's redirect** : "small simple functions one by one, do NOT touch critical structural things"

---

## TL;DR

Phase Z is **wrong shape for the stated mandate**. The orchestrator proposed an infrastructure-investment plan (soak / WS / backup / hardware / HTTPS / shadow) when the owner asked for "thin/small dysfunctions, one by one". Cross-checking the backlog (`reports/test-e2e/wave-x-2026-05-21/CONVERGENCE_FINAL.md §6`, `reports/test-e2e/wave-p-2026-05-20/WAVE-P-FINAL-SYNTHESIS.md §Owner-deferred`, `PROJECT_BRAIN.md §4/§5`), there are **15+ named, file:line-identified, scope-minimal items** sitting in V1.0.x backlog that match the owner's mandate exactly. The orchestrator didn't surface a single one. Phase Z reads as if it was drafted from a generic SaaS-launch checklist, not from the project's own audit history.

The right next phase is a **Wave Polish** of small, isolated, ≤2h tasks — not a Phase Z infra wave.

---

## 1. Verdict per Z item

| Z | Item | Verdict | Rationale |
|---|------|---------|-----------|
| **Z1** | Owner Manual Soak Test (1 week continuous) | **REWORK → keep as background, not a "phase"** | Soak testing is valuable but it is **already happening** ("Owner manual test phase" mentioned in 4 BRAIN entries 2026-05-19 → 2026-05-21). Framing it as a *first Phase Z step* implies "wait a week before doing anything else" — that's the orchestrator parking the work. Owner can soak in parallel with small polish heals. **Keep as continuous activity, drop the "wave" framing.** |
| **Z2** | WebSocket real-time (laravel-websockets self-hosted) | **KILL for V1 LOCAL** | (a) Polling fallback already implemented & tested: `PosOrdersTrackerComponent.vue:690` 15s tick + Echo when reachable + "polling fallback handles it" comment line 2592. (b) Cross-system Flow A/B latencies measured at Wave P: kiosk-pay→KDS visible **5.7s**, KDS bump <500ms, OSS pickup→removal 6.1s. Owner has accepted these; they are humane fast-food latencies, not a defect. (c) Adding `laravel-websockets` adds an **OS process to monitor** + auth channel surface area (Wave J round-2 found `Pusher channel-auth observably broken via Sanctum wildcard` — recurrence risk). **CLAUDE.md mandate "no useless complexity V1" applies directly.** Reconsider only if owner reports a real-world latency complaint during soak. |
| **Z3** | DB hardening + backup automation (NF525 6-year retention) | **REWORK → it's a 30-min cron job, not a Z-wave** | NF525 retention machinery already exists in the repo: `database/migrations/2026_04_22_000002_create_audit_logs_table.php` lines 86-150 install `BEFORE UPDATE` + `BEFORE DELETE` triggers on `audit_logs`; equivalent on `z_reports` (BRAIN §8). Backup script `scripts/db/backup.sh` already shipped — backups already taken in `storage/backups/` for menu-reset, ultra-goal, v1-0-1-pre, etc. **What is actually missing**: a cron entry that runs `backup.sh` daily + rotation (e.g. keep 30 daily / 12 monthly). That is a single crontab line + a `find -delete` rotation script. ~30 minutes including verification. Calling this "DB hardening" is theatre. |
| **Z4** | Hardware integration (TPE + drawer + receipt printer; `POS_SIMULATION_HARDWARE=false`) | **DEFER until owner names device models** | Acknowledged by orchestrator itself: actionable hardware integration requires (a) TPE model (Ingenico / Verifone / Sumup) and (b) drawer interface (cash drawer kick on receipt printer GPIO vs USB-HID vs serial) and (c) printer brand (Epson TM-m30 / Star TSP / etc.). Without those choices it's vapor planning. **Defer to "production go-live owner decision moment"** — at which point each device is its own focused mini-task (TPE adapter ~1d, drawer kick ~2h, printer ESC/POS ~3h). |
| **Z5** | HTTPS + LAN topology (Nginx reverse proxy + cert) | **KILL for V1 LOCAL single-PC** | If deployment is **one PC running `php artisan serve` + browsers on the same machine**, then `http://127.0.0.1:8000` is loopback-only and HTTPS is **pure ceremony**. No network sniffing surface, no MITM vector, no mixed-content issue. Sanctum cookies work on `127.0.0.1`. Service Workers work on `127.0.0.1`. If the deployment is **LAN multi-device** (KDS tablet + kiosk borne + POS PC), then yes you need HTTPS — but that's a **deployment topology question**, not a software heal. **Defer to the moment owner chooses LAN-multi-device deploy.** Even then, mkcert + self-signed CA is 1 hour, not a "Z-wave". |
| **Z6** | Real-traffic shadow operation (2 weeks, paper backup) | **KILL — ceremonial duplication, finds nothing Z1 doesn't** | Double-bookkeeping with paper is a useful technique **when you don't trust the system at all**. Le Cayenne V1 has: NF525 chain CHAIN OK appended-only, fiscal_sequence_no gap-free + monotonic, cash drawer reconciliation card, Z reports daily close, audit_logs HMAC chain. Owner *already* trusts these enough to run manual tests on real data. Asking him to also keep paper records for 2 weeks is **labor theatre that finds defects no faster than Z1**. If a defect exists, manual soak (Z1) surfaces it. Paper parallel only helps if a defect *did* occur AND owner needs to reconstruct ground truth — but that's recovery, not detection. **Drop entirely.** |

**Aggregate** : 2 KEEP-as-background (Z1, Z4-deferred), 3 KILL (Z2, Z5, Z6), 1 REWORK-as-30min-cron (Z3).

---

## 2. What the orchestrator missed

The owner's mandate said "small simple functions one by one". The orchestrator delivered six **infra capability** items. The project's own audit backlog already contains the small-function list. Below is what was sitting in plain sight and *should have been surfaced first*.

### 2.1 V1.0.x backlog already documented (15 items, ≤2h each)

**Source** : `reports/test-e2e/wave-x-2026-05-21/CONVERGENCE_FINAL.md §6`

| ID | Source | Description | Est | Files (non-frozen) |
|----|--------|-------------|-----|-------------------|
| A-004 | Wave A | `aria-label` missing on icon-only modal close button (counter-collect modal) | 15min | `resources/js/components/admin/pos/PosCounterCollectModal.vue` |
| A-009 | Wave A | Empty-state CTA on shortcut panels (Prêt à livrer / À encaisser borne when 0 rows) | 30min | `resources/js/components/admin/pos/PosComponent.vue` (the new panels) |
| A-010 | Wave A | Numpad below-fold on small viewports — CSS height/scroll fix | 45min | `PosCounterCollectModal.vue` or shared modal CSS |
| B-005 | Wave B | KDS spec missing quartet siblings (Playwright audit infra) | 30min | `tests/e2e/wave-x3-kds-history.spec.js` |
| B-006 | Wave B | Status badge border-left contrast ~3:1 → 4.5:1 WCAG AA | 20min | `public/css/admin-kds.css` (badge border tokens) |
| B-008 | Wave B | Focus-visible ring on KDS Historique trigger after drawer close | 15min | `KdsHistoryDrawer.vue` or trigger button CSS |
| C-006 | Wave C | `formatMoneyEuro` not applied on Cash-overview aggregates/chips/rows (only on reconciliation strip) | 45min | `resources/js/components/admin/cash-overview/CashOverviewComponent.vue` |
| C-007 | Wave C | Empty-state bare text "Aucune donnée" on /admin/cash-overview — add illustration + reset CTA | 1h | same Vue file |
| C-008 | Wave C | Cash-overview filters not URL-bound (no shareable link) — add query param sync | 1.5h | same Vue file |
| C-009 | Wave C | aria-label gaps on aggregate cards + sort columns | 30min | same Vue file |
| C-011 | Wave C | `mode=other` filter is silent no-op (dormant footgun) — remove option OR wire | 20min | same Vue file |
| C-016 | Wave C | Capped probe test environmentally weak — adjust seed=600 to exercise UI capped=true branch | 30min | `tests/e2e/wave-x4-cash-overview.spec.js` |

**Source** : `reports/test-e2e/wave-p-2026-05-20/WAVE-P-FINAL-SYNTHESIS.md §Owner-deferred`

| Item | Description | Est |
|------|-------------|-----|
| Wave P P2 | Tailwind `capitalize` cleanup (sites where category labels mid-render uppercase a French diacritic) | 30min |
| Wave P P2 | Login a11y improvements (label/input association + autocomplete attributes) | 45min |
| Wave P P2 | OSS test prefix collision (rename test fixtures to avoid global namespace collision) | 30min |
| E2E_PLAYWRIGHT_STUDIO | Seed leak between specs — add `tests/e2e/global-setup.js` cleanup | 1h |

**Source** : `PROJECT_BRAIN.md §5 P1 V1.0.1 partial`

| Item | Description | Est |
|------|-------------|-----|
| Password min:12 + complexity | `app/Http/Requests/UserStoreRequest.php` (and 2 others) — bump rule + i18n | 30min |
| `mode=other` cleanup (dup with C-011) | — | — |
| Sanctum TTL 8h → 1h sensitive ops | `config/sanctum.php` + middleware tag list — owner-gate, but the code change is ~10 LOC | 1h |
| API key versioning header | `app/Http/Middleware/ApiVersion.php` middleware shim — header check + 426 fallback | 1.5h |

### 2.2 None of these are in frozen zones

Verified against `CLAUDE.md §7` :
- None touch `PaymentComponent.vue`, `PosV5TrancheRow.vue`, kiosk wizard components, `pos-wizard.js`, `pos-wizard.css`
- None touch fiscal services, `OrderStateMachine`, `BranchScope`, `PricingService`, `IdempotencyKeyMiddleware`
- All touch UI Vue, CSS, spec files, or peripheral controllers

### 2.3 None are NF525-adjacent

No audit_logs mutation, no z_reports DELETE, no fiscal_sequence_no touch, no `composition_snapshot` overwrite.

---

## 3. Counter-proposal : **Wave Polish (V1.0.x)**

Replace Phase Z with a **single-day Wave Polish** of 10-15 items.

### Sequencing principle

1. **Visible-to-owner first** : items that show up on the surfaces he tests daily (POS shortcut panels, cash-overview, KDS history). When owner sees them already polished during soak, confidence accumulates.
2. **One axis per commit** : a11y commit, copy commit, formatting commit — easier to revert if one breaks something.
3. **No batched mega-PR**. Each item is its own commit on the same branch.

### Concrete list (12 items, each ≤2h, total ~10h-agent)

| Order | ID | Domain | One-line | Est |
|-------|----|--------|----------|-----|
| 1 | C-006 | UX polish | `formatMoneyEuro` on all cash-overview aggregates/chips/rows | 45m |
| 2 | C-007 | UX polish | Cash-overview empty-state illustration + reset CTA | 1h |
| 3 | A-009 | UX polish | Shortcut panels empty-state ("Aucune commande prête à livrer pour l'instant") | 30m |
| 4 | A-010 | Responsive | Numpad below-fold fix on counter-collect modal | 45m |
| 5 | B-006 | a11y | KDS status badge border contrast 3:1 → 4.5:1 | 20m |
| 6 | A-004 | a11y | aria-label on counter-collect modal close button | 15m |
| 7 | B-008 | a11y | Focus-visible ring on KDS Historique trigger | 15m |
| 8 | C-009 | a11y | aria-label gaps on cash-overview aggregate cards + sort columns | 30m |
| 9 | Wave P P2 | a11y | Login form label/input association + autocomplete attributes | 45m |
| 10 | C-011 | UX cleanup | Remove dormant `mode=other` filter option | 20m |
| 11 | Wave P P2 | i18n | Tailwind `capitalize` cleanup where French diacritics break | 30m |
| 12 | C-008 | UX feature | Cash-overview filters → URL query params (sharable) | 1.5h |

**Plus 30-minute infra task** (extracted from Z3) :
- Add `crontab` entry `0 3 * * * /path/scripts/db/backup.sh && find storage/backups -mtime +30 -delete` + verify next-morning artifact

### Items deliberately excluded from Wave Polish

- **C-015** (V2-only branch label on admin fallback drawer) — true V2 SaaS hardening, single-resto V1 doesn't see this
- **Multi-tranche split counter-collect** (Wave X X1 deferred) — NF525-adjacent, needs LOCK plan + owner countersign
- **KDS PREPARED→PREPARING revert** (Wave X X3 deferred) — touches `OrderStateMachine.php` §7 frozen
- **Cash drawer count input feature** (Wave X X4 deferred) — needs schema migration + cashier-input wizard, not "small"

These three are **legitimate V1.0.2 backlog**, not Wave Polish.

---

## 4. Risk assessment — is Phase Z bias defensible ?

### The orchestrator's likely reasoning

"Owner is moving toward production. Production needs : (a) confidence in correctness → Z1 soak. (b) real-time UX → Z2 WS. (c) data durability → Z3 backup. (d) hardware → Z4. (e) network security → Z5. (f) parallel-run before cutover → Z6. Therefore Phase Z covers production-readiness."

This is **textbook SaaS launch thinking**. The orchestrator pattern-matched "owner says ready to ship" → "run my pre-launch checklist".

### Why it's wrong for THIS project

1. **Owner mandate verbatim**: "thin/small dysfunctions that don't impact overall structure" + "as if each is simple I can finish them" + "DO NOT TOUCH critical structural things". Phase Z is the **opposite shape** : 6 systemic items, each requiring multi-day infra work, each carrying some structural risk.
2. **CLAUDE.md §3 principle 1+5**: "Vision is more important than speed" + "Partial is better than wrong". The project has been disciplined for 6 months precisely *because* it shipped small heals one at a time. Phase Z would break that cadence.
3. **CLAUDE.md mandate "no useless complexity V1"** (mentioned in `feedback_no_cloud_until_owner_initiates.md`, BRAIN §1, the critical focus 2026-05-18 mandate). Z2 (WebSocket), Z5 (HTTPS LAN), Z6 (paper parallel) are textbook "useless complexity V1".
4. **Audit history points the other way**: every successful Wave (K through X) has been **lots of small commits**, not a structural reorganization. Wave L = 11 small heals. Wave P = 22 small heals. Wave X = 5 small heals (3 round-1 + 2 round-2). The pattern that **demonstrably works** is exactly Wave Polish. Phase Z would be a stylistic regression.
5. **Frozen-zone discipline preserved by smallness**: every recent wave shipped with `frozen-zone diff = 0`. Phase Z infrastructure items have non-trivial probability of touching middleware (`IdempotencyKeyMiddleware`) or model scopes (`BranchScope`) when adding WS auth or HTTPS routing — risk of accidental frozen-zone touch.

### Severity of orchestrator drift

**Medium-high**. Not malicious — just wrong-shape recommendation. The mistake is **pattern-matching to a generic playbook** rather than reading the project's own audit history. The owner's prompt to call in an adversarial reviewer was correct.

---

## 5. Recommendation

1. **Drop Phase Z framing entirely.** Replace with **Wave Polish** = the 12-item list in §3 above.
2. **Z1 (manual soak) is already happening** — it doesn't need to be a "phase", it's just the owner's daily reality. Wave Polish runs in parallel; he tests the surfaces, agent ships micro-heals on a separate branch, owner re-tests the surfaces.
3. **Z3 backup** → extract the 30-min cron item, ship as `chore(backup-rotation): daily mysqldump + 30/12 retention`. Drop the rest of Z3 as already-in-place (triggers exist).
4. **Z2 WS, Z5 HTTPS, Z6 shadow → KILL for V1 LOCAL.** Re-evaluate only when owner makes one of these concrete deployment-topology decisions :
   - "We're going to have a KDS tablet on the LAN" → revisit Z5
   - "Owner reports laggy KDS during a real rush" → revisit Z2 (and even then, profile Echo Pusher first before laravel-websockets)
   - "Owner needs to reconstruct a day's ground truth" → that's a recovery exercise, not Z6 preventive
5. **Z4 hardware** → keep on roadmap, name device models with owner THEN spawn one focused mini-task per device.
6. **Sequencing alternative** :
   - Week 1 : Wave Polish items 1-7 (UX + a11y, visible-during-soak)
   - Week 2 : Wave Polish items 8-12 (cleanup + URL-binding)
   - Continuous : owner soak test
   - Pre-go-live : 30-min backup cron commit
   - At go-live decision : owner picks deployment topology → matching mini-task plan (Z4 device models, Z5 HTTPS only if LAN-multi, Z2 WS only if observed latency complaint)

---

## 6. Closing observation

The orchestrator did a fine job naming **categories of work** that *do* matter for production (soak, real-time, backup, hardware, network, shadow). The error was treating each category as a **wave-scope task** instead of recognizing that :
- Some are already done (Z3 triggers, Z1 ongoing)
- Some are deployment decisions blocked on owner choice (Z4, Z5)
- Some are unjustified by current evidence (Z2, Z6)
- And meanwhile the **named small dysfunctions in the audit reports** were not surfaced

A second-cerveau orchestrator should read its own audit history first. Wave Polish is the answer hiding in `reports/test-e2e/wave-x-2026-05-21/CONVERGENCE_FINAL.md §6` — the orchestrator wrote that document last week and forgot to consult it this week.

---

*Adversarial review by Claude (read-only investigation). No code edits, no commits. Findings sourced from`PROJECT_BRAIN.md` §2/§4/§5/§7, `reports/test-e2e/wave-x-2026-05-21/CONVERGENCE_FINAL.md`, `reports/test-e2e/wave-p-2026-05-20/WAVE-P-FINAL-SYNTHESIS.md`, `database/migrations/2026_04_22_000002_create_audit_logs_table.php` line 86-150, `config/broadcasting.php`, `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:678,690`, `resources/js/components/admin/pos/PosComponent.vue:2516,2592,2944`, `scripts/db/backup.sh` existence, `storage/backups/` directory listing.*
