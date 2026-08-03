# SUPERVISOR REVIEW VERDICT — Pre-Cloud Remediation & Validation plan

**Date** 2026-06-04 · **Reviewer** Claude (supervisor, this session) · **Subject**
`plans/GOAL_PRE_CLOUD_REMEDIATION_VALIDATION_2026-06-04.md` + `reports/audit/pre-cloud-2026-06-04/PRE_CLOUD_REMEDIATION_CATALOG.{md,json}`
(prepared by a prior session). **Method** advisor-vetted approach → 3-agent adversarial
ultra-review (P1-sample veracity vs MAIN + vision/scope + auth-gap + P2/P3 sample) →
worktree-staleness reconciliation. **Status** PREPARE-ONLY — no code executed.

## VERDICT — ✅ CONFIRM AS GOAL (with corrections)
Unanimous across all 3 reviewers: **confirm-with-corrections**. The plan matches the V1
Le Cayenne vision, is veracity-sound, and is the right pre-cloud campaign. Adopt it as the
active goal. Apply the corrections below at launch. Do NOT rewrite the 27 KB plan.

### Why it passes the vision test (the crux, not just veracity)
- **Anti-drift = clean PASS.** Of the 156 findings, **0 require SaaS/multi-tenant/cloud work**
  to remediate (adversarial regex scan = 0). All 10 security findings are single-box V1-LOCAL
  correctness. §8.3 hands the cloud cutover ITSELF (GATE-1 hardware LAN + GATE-2 encaissement)
  to a **separate owner-initiated mission**; §7.4 only RECORDS the cloud risk surface read-only.
  → It does **not** manufacture a cloud blocker — it respects "cloud is future, never a V1 blocker."
- **Veracity = trustworthy.** 8/8 sampled P1 + 6/6 sampled P2/P3 verified **real AND still-open**
  against the MAIN checkout's actual code; a deliberate falsification attempt (S11-04) FAILED;
  the self-audit recalibrations (M10-01 P2→P1, M6-002 reachability, S13-04 P2→P3) are honest.
  Catalog `file:line` cites drift occasionally → verify **semantically** (the plan already mandates this).
- **Corroborated independently.** The headline NF525 operator-identity finding
  (`ReceiptDataService.php:70` reads `order->user` = the customer) matches this session's own
  `PrintAgentSecurityTest` work. **S8-01 (TPE created with `branch_id=0` → invisible to the caisse)
  is exactly the owner's real Ingenico Desk/5000 scenario** — confirmed in code.
- **Discipline sound.** Frozen quarantine (§5 hard-LOCK vs referenced) correctly classified;
  NF525 attestation protocol (§0.6) cites 3 artisan commands that all exist; risk-first waves;
  PREPARE-ONLY gate; ANCHOR-FIRST anti-fiction (counts from machine JSONs).

## MANDATORY CORRECTIONS (apply at launch; append, don't rewrite)

1. **SEQUENCING — disambiguate the go-live gate.** The plan's binding gate is internally
   inconsistent: §8.2.5 ("P0+P1 = 0") is correct, but §8.2.1 ("all 156 GREEN") + §0.3/§F
   ("strictly zero / production-perfect") read as "fix all 156 before going live." **The BINDING
   pre-cloud go/no-go gate = (a) the ~20 P1 GREEN, (b) NF525 chain attestation appended-only,
   (c) frozen-zone discipline (diff=0 or countersigned LOCK), (d) W8 auth-surface audited.**
   **P2 (58) and P3 (77) are EXPLICITLY non-blocking** — incremental or owner-deferred-with-rationale.
   "Strictly zero defect" is the asymptotic target, NOT the go condition. This campaign is a
   **productive use of the hardware-wait** (it runs while the owner gathers printer-IP / OVH).

2. **STALENESS reconciliation (this session's +54 worktree commits + data-ops are NOT in MAIN):**
   - **M11-02 [P1] = RESOLVED-BY-DATA-OPS.** Branch 1 now carries SIRET `10417050100019`,
     TVA `FR19104170501`, register `CAISSE-01`, full `legal_footer` (this session's
     `foodking:set-branch-legal`, matches gate G-D). Drop from the active P1 worklist (or
     reduce to "verify the receipt renders the now-present data"). **Binding P1 count 21 → ~20.**
   - **Operator-identity cluster (M11-01 / S11-02 / S16-01) + encaissement (M10-01)** are
     **partially proven/built on the worktree branch `heal/cms-pr1-quickwins-2026-05-18`**
     (this session: `PrintAgentSecurityTest` proves operator=encashing-cashier via the audit-chain
     tier-2 resolution; the encaissement `OrderPayment` + `CashMovement` cash-trail). These are
     **"reconcile at merge", NOT "re-fix from scratch"** — W2/W3 must diff against that branch first.
   - **M11-01 heal** `6b26e1be3` is verified NOT an ancestor of HEAD → **cherry-pick or re-derive**;
     do not assume present. `ReceiptDataService.php:70` is still the buggy pre-heal version on MAIN.

3. **AUTH §7.2 reframe — it is NOT "the biggest hole with missing guards."** Rate-limiting
   (login-lockout 10/10min, forgot `throttle:3,60`, verify/reset/signup/guest throttles),
   reset-token entropy (`Str::random(64)` + expiry + full token revocation), password `min:12`,
   bcrypt-12 rehash, audit-chain login/logout, and branch-scoped login redirect are **already
   implemented in MAIN**. The real gap = the core auth controllers + 9 frontend auth Vue
   components **never got a dedicated audit pass** → W8-auth is a **coverage-CONFIRMATION** pass,
   not an add-guards remediation. The **one** genuinely open named risk = Sanctum `kiosk:order`
   TTL = 480 min (`KioskMachineLoginController:98-102`) — keep it, it's the V1.0.1 backlog item.

4. **M6-001 fix recipe** needs a one-line hoist: `$splitActive` is computed at `OrderService.php:1239`,
   AFTER the guard at `:1071`. The implementer must hoist/recompute the split-active signal (or read
   `$request->input('payment_breakdown')`) at the guard site for the fix to compile.

5. **VERIFY SEMANTICALLY against MAIN** at each wave start (line numbers drift; ~1/several cites off).
   The plan already mandates this — keep it.

## ⚠️ DIRECTION CORRECTION — owner, 2026-06-05 (binding; supervisor recalibration)
The owner corrected a supervisor-level drift in this verdict's framing of the payment terminals.
**The real vision (stated repeatedly):**

- **PAYMENT TERMINALS ARE NOT GOING LIVE.** Both the kiosk (borne) and counter (caisse) card
  terminals are **NOT configured / not functional** and will take time. V1 stays on the **manual
  alternative (SumUp, called manually)** for card, exactly as the cutover docs already encode:
  cash → drawer · TR → manual · **card → manual SumUp + reference**, marked paid in the POS.
  → **NO terminal go-live preparation.** Therefore:
  - **S8-01 [P1] (TPE created with `branch_id=0` invisible to the caisse) is RE-SCOPED to DEFERRED /
    future** (when the real terminals are configured). It is **NOT a V1 go-live item** — the cashier
    does not card-encash through the Ingenico; they use the manual alternative + reference. Drop it
    from the active P1 gate. **Gate G-F (TPE branch assignment) is likewise DEFERRED** to the future
    terminal-onboarding mission, not part of this remediation.
  - Do **not** frame any finding as "make the real card terminal work live." That is future, owner-gated.

- **THE OWNER-WANTED OBJECTIVE = UNIFY THE ENCAISSEMENT (add this as an explicit goal cluster).**
  Today the **borne-order encaissement popup ≠ the caisse-order payment popup** (two different
  flows/components). The owner wants **ONE single encaissement system**, identical for a kiosk order
  collected at the counter AND a caisse order, where at payment the operator picks **Espèces /
  Tickets-restaurant / Terminal** — and **"Terminal" = the manual SumUp alternative + reference**
  (until real terminals exist). This is a V1-aligned UX+logic unification (my own `PosCounterCollectModal`
  + the encaissement `OrderPayment`/method work on the worktree branch is the natural foundation).
  - **Frozen caution**: the caisse payment flow runs through `PaymentComponent.vue` (CLAUDE.md §7
    FROZEN). Unifying must NOT touch the frozen file without a LOCK+countersign — prefer making the
    **non-frozen `PosCounterCollectModal` the single unified encaissement surface** (or a new non-frozen
    component) that both borne-collect and caisse-collect route through, leaving the frozen wizard intact.
    This is an **owner-gated design decision** (new gate **G-H: unified-encaissement surface**) at execution.

- **Net effect on the active goal**: keep all the V1-correctness findings (operator-identity,
  dashboard filters, Tax/Currency, App-Debug, money-bucketing, security, UX); **defer the terminal/TPE
  findings**; **add the encaissement-unification cluster** as a first-class V1 objective. Binding P1
  gate ≈ 19 (21 − M11-02 stale − S8-01 deferred).

**Status unchanged: PREPARE-ONLY. The owner said "prépare juste, tu le lances pas." Nothing executes.**

## NO CHANGE NEEDED
Anti-drift, frozen quarantine, NF525 protocol, cross-cut cluster discipline, the W8 deeper-audit
(auth + 147 gaps + dine-in), and the §8.3 cloud-cutover hand-off are all correct. Keep them.

## DISPOSITION
- **Goal status: CONFIRMED + ACTIVE (PREPARE-ONLY).** Registered in `PROJECT_BRAIN.md §2`.
- **Execution gate: HALT.** Per the plan's §0.1 and the owner's mandate, no code change runs
  until the owner explicitly says "go / lance la remediation". When he does: start at **W1**
  (baselines + 5 live-verifies + dispatch owner gates A–G), reconcile the worktree branch, then
  W2 → … → W8 with `ultra-audit-profond` per cluster, RED dispute, visual gate, NF525 attestation,
  and the corrected go/no-go gate (the ~20 P1, not all 156).
- **Owner gates A–G (§6) are the first ask** when execution starts — especially G-A discount intent,
  G-C dine-in keep/remove, G-E App-Debug toggle, G-F TPE branch assignment (the Ingenico S8-01 fix).
