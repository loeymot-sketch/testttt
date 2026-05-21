# Strategic Second-Opinion — Owner's Polish Wave vs Phase Z

**Date** : 2026-05-21
**Branch** : `heal/cms-pr1-quickwins-2026-05-18` (HEAD `1116b3957`)
**Owner verbatim mandate** : "small simple functions one by one, as if each is simple I can finish them. Do not touch critical structural things. Send again afterward."
**Author** : Claude Opus 4.7 1M (independent strategic reviewer, read-only)
**Status** : `/ultraplan` — plan-only, no execution

---

## Section A — Independent Strategic Verdict

### Phase Z assessment : **wrong-tier, not just premature**

The prior orchestrator proposed Z1 (5-day soak), Z2 (WebSocket infra), Z3 (DB backup automation), Z4 (TPE+drawer+printer hardware), Z5 (HTTPS LAN), Z6 (shadow op 2 weeks). Each of these is an **operational deployment project**, not a dysfunction. They fail the owner's discriminating test : "as if each is simple I can finish them." They also collide head-on with `feedback_no_cloud_until_owner_initiates.md` and `feedback_no_cloud_until_owner_initiates.md` — the owner has archived cloud / production-deploy actions as "vision avant production" and explicitly forbade them until he initiates. The prior orchestrator reached for a deployment checklist when the owner asked for a polish list.

- **Z1 soak test** : not a fix — an observation gate. Owner is already running one (manual test phase since Wave P / Wave X). No new "task" exists here.
- **Z2 WebSocket** : infra project, not a small dysfunction. Owner-fearful zone (sync = critical). Out of scope.
- **Z3 DB backup** : owner-gated cloud territory. Already partly addressed by `storage/backups/v1-0-1-pre/foodking-dump-2026-05-17.sql` baseline.
- **Z4 hardware** : blocked by missing TPE model / drawer model info from owner. Not a "Claude task."
- **Z5 HTTPS LAN** : ops/network setup, owner-physical.
- **Z6 shadow op** : 2-week elapsed-time observation, not work.

### Alternative framing (3 sentences)

Owner is in **manual test phase on a 9.0/10 V1 LOCAL** post Wave K→Y (~50 heal commits). He wants **owner-visible polish** that ships quickly, ships safely, and accumulates trust on the existing stable surface — not infra projects. The right wave is a **POL-α/β/γ sequence of ~12-15 micro-items already enumerated in the V1.0.2 backlogs of Wave X, Wave P and Couche-0 STATUS docs** — each item already has `file:line` evidence, each is XS/S, each is risk-1 (pure UI / i18n / aria / formatter). Phase Z gets parked behind a single owner gate ("when you say go production").

---

## Section B — THE PLAN (ranked 12 micro-items, all evidence-cited)

Scoring : `Score = Value / (Risk × Scope)`. Higher = do first.

| # | ID | Title | File(s) touched | Value | Risk | Scope | Score | Effort | Acceptance criteria | Deps |
|---|----|-------|-----------------|-------|------|-------|-------|--------|---------------------|------|
| 1 | **POL-01** | Toast `Retry-After` real value instead of hardcoded "30s" | `resources/js/bootstrap.js:176-181` + `resources/js/languages/{fr,en,ar}.json` `error.rate_limited` | 4 | 1 | 1 | **4.0** | XS (~20min) | 429 toast displays actual `Retry-After` header seconds (e.g. "60s") not always "30s" ; i18n key uses `{seconds}` placeholder | none |
| 2 | **POL-02** | `aria-label` on icon-only modal close buttons (Wave X A-004 + C-009 aggregate cards + sort columns) | `PosCounterCollectModal.vue` close btn + `CashOverviewComponent.vue` aggregate cards + sort columns | 3 | 1 | 1 | **3.0** | XS (~25min) | Every icon-only button has `aria-label` ; axe-core check on those screens passes ; screen reader reads correct purpose | none |
| 3 | **POL-03** | `mode=other` filter silent no-op closure (Wave X C-011) | `CashOverviewComponent.vue` filter dropdown options | 3 | 1 | 1 | **3.0** | XS (~15min) | Remove `mode=other` from dropdown OR map it to documented effect ; no silent dead option remains | none |
| 4 | **POL-04** | `formatMoneyEuro` propagation to aggregates + chips + rows (Wave X C-006) | `CashOverviewComponent.vue` 3 zones | 4 | 1 | 2 | **2.0** | S (~45min) | All money displays render "12,50 €" canonical FR ; no bare `12.5` decimals visible ; consistent with reconciliation strip | none |
| 5 | **POL-05** | KDS status-badge contrast ≥4.5:1 (Wave X B-006) | `KdsHistoryDrawer.vue` + KDS card status border-left CSS tokens | 4 | 1 | 2 | **2.0** | S (~40min) | All KDS status badges pass WCAG AA 4.5:1 (axe / Lighthouse) ; visual unchanged for owner | none |
| 6 | **POL-06** | Empty-state CTA on POS shortcut panels (Wave X A-009) | `PosShortcuts*` panels (À encaisser borne / Prêt à livrer) | 3 | 1 | 2 | **1.5** | S (~30min) | When no rows : illustration or copy "Aucune commande en attente" + faint CTA "Voir tout" instead of bare collapse | none |
| 7 | **POL-07** | Reset CTA + illustration on Admin cash-overview empty-state (Wave X C-007) | `CashOverviewComponent.vue` empty state | 3 | 1 | 2 | **1.5** | S (~30min) | Empty state shows icon + "Aucune donnée pour ces filtres" + "Réinitialiser les filtres" button | partial dep on POL-04 (same file) |
| 8 | **POL-08** | Numpad below-fold on small viewports (Wave X A-010) | `PosCounterCollectModal.vue` CSS responsive | 3 | 2 | 2 | **0.75** | S (~45min) | Numpad fully visible on viewports ≥1024×640 ; min-height enforced ; modal scrolls inside if smaller | none |
| 9 | **POL-09** | KDS focus-visible ring on trigger after drawer close (Wave X B-008) | `KdsHistoryDrawer.vue` focus return logic | 3 | 1 | 2 | **1.5** | S (~30min) | Closing drawer returns focus to "Historique du jour" pill ; `:focus-visible` outline ring 2px brand red | none |
| 10 | **POL-10** | URL-bound filters on Admin cash-overview (Wave X C-008, sharable links) | `CashOverviewComponent.vue` route query sync | 4 | 2 | 3 | **0.67** | M (~75min) | Filters `from/to/source/mode/branch_id` sync to `?` query ; reload preserves state ; deep link reproducible | none |
| 11 | **POL-11** | Extend PII redaction pattern to 10 remaining sites (Couche-0 H2 follow-up) | `OrderService.php:1823, :2008` + 8 sites listed in `reports/audit/foundation-2026-05-18/F-9-OBS/STATUS.md` | 4 | 2 | 3 | **0.67** | M (~90min) | Drop `Auteur: {user->name}` segment everywhere ; sentinel extended ; sentinel tests green ; same pattern as H2 commit `269617720` | none |
| 12 | **POL-12** | Dead 5 sites `DispatchableAfterCommit` cleanup (Wave P deferred) | 5 sites listed in Z2 P1 reports | 3 | 2 | 2 | **0.75** | S (~45min) | 5 `DispatchableAfterCommit` interfaces removed where broadcast-row UNIQUE already absorbs ; no behaviour change ; tests green | none |

### Item-level notes on what was rejected

- **Wave X C-013 cashier-counted-cash input** (real écart calculation) : scope ≥M + touches money math + needs NF525 attestation discipline → **not a POL item**, V1.0.2 mini-feature.
- **Wave X C-015 admin fallback branch label** : V2 SaaS only, single-branch V1 is fine.
- **Wave X B-005, B-007, C-016 spec/audit infra** : value to owner = 1 (CI plumbing) ; defer.
- **Wave Y rate-limit env-knob** : already shipped commit `2e2400724` — only the toast remains (POL-01).
- **Stock-mgmt-M1 rounds in flight** (commits `7a409ade7` → `1116b3957`) : **active stream, not POL** — let it converge naturally.
- **Dashboard refresh** : owned by Claude Design parallel agent per `reports/handoff/HANDOFF_CLAUDE_DESIGN_DASHBOARD_2026-05-21.md` — **do not touch** from this wave.
- **17 `withoutGlobalScopes()` plural cleanup (Z6 P1)** : value to owner = 2, scope = M-L, risk = 2 (BranchScope-adjacent) — defer V1.0.2.

---

## Section C — Sequencing (POL-α / β / γ)

### Wave POL-α (one focused session, ~2.5h, all risk-1 polish, owner-visible same day)

Single commit recommended (or 2-3 atomic) — files mostly disjoint :

| # | ID | Estimated |
|---|------|-----------|
| 1 | POL-01 toast Retry-After | XS |
| 2 | POL-02 aria-labels batch | XS |
| 3 | POL-03 `mode=other` closure | XS |
| 4 | POL-04 formatMoneyEuro propagation | S |
| 5 | POL-05 KDS status-badge contrast | S |

**Total Wave α** : ~2h15 wall-clock, 5 commits or 1 bundle. **0 frozen-zone touch**. **0 NF525 touch**. **Owner can verify visually within 5 minutes** : open `/admin/cash-overview` + KDS history drawer + trigger a 429.

### Wave POL-β (after α landed + manual verify, ~2h)

| # | ID | Estimated |
|---|------|-----------|
| 6 | POL-06 POS shortcuts empty-state CTA | S |
| 7 | POL-07 Admin cash-overview empty-state | S |
| 8 | POL-09 KDS focus-visible ring | S |
| 9 | POL-08 numpad below-fold responsive | S |

**Total Wave β** : ~2h15 wall-clock, 4 commits. Continues UI polish ; depends on α being landed (POL-04 prior to POL-07 same file). Risk-1 or risk-2 max.

### Wave POL-γ (after β, ~3h, deeper but still no critical surface)

| # | ID | Estimated |
|---|------|-----------|
| 10 | POL-10 URL-bound filters | M |
| 11 | POL-11 PII redaction 10 sites | M |
| 12 | POL-12 DispatchableAfterCommit cleanup | S |

**Total Wave γ** : ~3h30 wall-clock. Each is M (75-90 min) ; can be split per session if owner prefers. POL-11 has a sentinel pattern already locked-in by Couche-0 H2 → safe to extend.

### Cycle posture after POL-α + β + γ

- V1 LOCAL score : 9.0 → expected **9.3-9.4 /10** (UX + a11y + clean code surfaces)
- Zero structural-system change (POS, payment, kiosk wizard, KDS state machine, fiscal services, OrderStateMachine, BranchScope, IdempotencyKeyMiddleware, PricingService — all untouched)
- Frozen-zone diff stays at 0 throughout
- Owner-visible improvements on : Admin/cash-overview, KDS drawer, POS shortcuts, toast UX
- 1 backend hygiene wave (PII + dead code) batched at γ

---

## Section D — Anti-items (what NOT to do now)

Explicit list of tempting-but-wrong actions :

1. **Phase Z deployment work** (cloud, hardware, WebSocket infra, HTTPS, DB backup automation) — owner-gated per `feedback_no_cloud_until_owner_initiates.md` ; not "small dysfunctions" ; needs owner to physically initiate.
2. **Refactor wave** (V1.0.2 SaaS multi-tenant hardening, BranchScope plural cleanup at 17 sites, FormRequest authz baseline of 69, Spatie permissions upgrade) — V1.0.2 backlog scope, multi-session, structural.
3. **Multi-tranche counter-collect feature** — NF525-adjacent (`PaymentService::confirmCounterPaymentSplit` would need LOCK doc + owner countersign + audit_logs chain entry).
4. **KDS revert PREPARED→PREPARING** — `OrderStateMachine.php` §7 frozen + NF525-adjacent ; needs LOCK doc + Chef-role design.
5. **Cashier-input drawer count input** (real écart computation) — money math + workflow change ; mini-feature scope, not a quick win.
6. **Dashboard `/admin` redesign** — Claude Design parallel agent owns this scope. Coordinator-only ; do not start.
7. **Stock-mgmt-M1 follow-ups** — active stream (last commit `1116b3957`, 2026-05-21 round-3) ; let it converge before adding POL items in this area.
8. **i18n empty-key extension to bn/de** — out of V1 (locales not active).
9. **POS Wizard / kiosk wizard / `PaymentComponent.vue` touch** — §7 frozen, no exception.
10. **Action_logs historical-row PII redaction** (one-shot script) — owner-gate per F-9 STATUS.md §Questions Owner #1 ; not a POL item.
11. **Cron jobs scheduling fixes** (audit_locale_keys.mjs via Kernel) — Couche-0 deliberately punted to V1.0.2 dedicated cycle.
12. **WebSocket polling tuning** (5s → 60s logic ; or migrate to long-poll) — sync surface = critical, no touch this wave.
13. **Spatie media library cleanup** (53 rows dropped post Le Cayenne V2) — already shipped, do not regress.
14. **Browser cache-buster fix** beyond what shipped (Bug B item from Le Cayenne V2 project) — already shipped via `filemtime` suffix.
15. **`SetLocale` middleware revival** — already dropped 2026-05-19 ; do not reinstate without owner gate.

---

## Section E — Owner Gates / Blocker Info Needed

### Gate G-α (before Wave POL-α launches)

**The single most important question** :
> « Veux-tu que Claude exécute POL-α → β → γ en autonomie complète puis te livre un rapport unique à la fin, OU veux-tu valider visuellement après chaque wave (POL-α landed → tu testes → tu dis go β) ? »

This gates everything else.

### Gate G-β (post POL-α verification)

- Owner verifies visually (5 min) : 429 toast accurate / aria-labels work / mode=other gone / money formatted / KDS badges readable.
- Owner says "go β" OR identifies an issue.

### Gate G-γ (post POL-β verification)

- Owner verifies empty-state + focus + responsive.
- Owner says "go γ" or "park backend hygiene".

### Optional question (low-priority, for POL-10)

> URL-bound filters on `/admin/cash-overview` — useful for sharing a filtered view with another admin / employee, or just nice-to-have ? If never shared, deprioritize POL-10 to V1.0.2 backlog.

### Questions NOT to ask now (already answered or out-of-scope)

- TPE model — Phase Z territory, parked.
- Stripe production keys — production-deploy territory, parked.
- Cloud provider — owner archived `feedback_no_cloud_until_owner_initiates.md`.
- WebSocket self-hosted — Phase Z territory, parked.
- Dashboard direction — Claude Design parallel agent has it ; do not duplicate.

---

## Cross-references

- `CLAUDE.md §7` frozen-zones list (15 files protected)
- `CLAUDE.md §8` NF525 invariants
- `reports/test-e2e/wave-x-2026-05-21/CONVERGENCE_FINAL.md` §6 V1.0.2 backlog (source for POL-02..09)
- `reports/test-e2e/wave-p-2026-05-20/WAVE-P-FINAL-SYNTHESIS.md` owner-deferred (source for POL-12)
- `reports/rate-limit-rc-2026-05-21.md` §9 (source for POL-01)
- `reports/audit/couche-0-backlog-v1-0-x-2026-05-19/STATUS.md` H2 (source for POL-11)
- `reports/handoff/HANDOFF_CLAUDE_DESIGN_DASHBOARD_2026-05-21.md` (dashboard is owned, do not touch)
- `feedback_no_cloud_until_owner_initiates.md` (mandate absolute)
- `feedback_massive_team_orchestration_e2e_per_system.md` (discipline mandate)

---

## End of Strategic Second-Opinion

**TL;DR** : Phase Z is wrong-tier (deployment projects, not small dysfunctions). The owner's verbatim mandate maps to a **12-item polish wave POL-α/β/γ** assembled from already-enumerated V1.0.2 backlog items in Wave X + Wave P + Couche-0 docs — each with file:line evidence, each XS-S-M scope, each risk-1 or risk-2, **zero frozen-zone touch**, **zero NF525 touch**. Wave POL-α (5 items) ships in one ~2.5h session ; owner verifies in 5 minutes. The single gating question is whether owner wants step-by-step verification or autonomous α→β→γ execution.

*Generated 2026-05-21, branch `heal/cms-pr1-quickwins-2026-05-18` HEAD `1116b3957`, read-only investigation, no action taken.*
