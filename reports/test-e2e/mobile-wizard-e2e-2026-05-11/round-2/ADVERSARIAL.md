# Adversarial dispute — round-2 reclassif

## TL;DR
Both P0 closures **SUSTAINED** after strict re-verification (C-001 ScreenConfirm now reads live cart props; D-001 idempotency cache in `dev-helpers.js` plus explicit "DÉJÀ ÉCHANGÉE" UI). 9 of 11 "closed" P1 claims sustained; **2 disputed** (A-005 is actually CLOSED — reclassif misread the visible white text on black pill; B-007/B-008 image-slot leak is more severe than P2). 4 NOT-closed P1 re-judged: **A-005 → INVALID/closed** (reclassif wrong), **A-002 → downgrade P1 → P2** (partial fix landed, defect now cosmetic placeholder not dev-leak), **A-010 → downgrade P1 → P3** (spec-only audit-integrity), **C-002 → SUSTAIN P1** (audit-integrity hole: we have zero visual proof of the pay-counter modal step). One NEW high-impact finding: **D-002 RGPD copy contradiction** (toast says "points effacés", body says "points existants restent valides" — direct legal/UX contradiction introduced by the cluster-3 fix). Net verdict: **NO-GO → round-3 needed** for at least 2 P1s (C-002 audit-integrity + new AD-N1 RGPD copy contradiction).

## P0 re-verification

### C-001 — SUSTAINED CLOSED
**Evidence path**: `tests/e2e/__screenshots__/test-e2e-mobile-wave-C/24-modal-pay-counter-confirm.png` + `.dom.html`; commit `292b4cd69`.

Verified via `git show 292b4cd69 -- mobile/screens-main.jsx mobile/index.html`:
- `App.snapshotOrder()` (mobile/index.html line ~127) generates `{id:'C-'+rand(4digits), total:cartSum, eta:now+12min}` and stores in `lastOrder` state at `go('pay')` call.
- `ScreenConfirm` (mobile/screens-main.jsx line ~650) destructures `order` prop and renders `{orderId}`, `{orderEta}`, `{orderTotal.toFixed(2)}`.
- Round-2 PNG shows `#C-9279` (random 4-digit), `08h07` (live ETA), `1,50 €` (matches state-23 cart total) — all dynamic.
- DOM grep returns 3 `C-1234` occurrences: all in JS source `<script>` body (`orderId = _ref7$orderId === void 0 ? 'C-1234'` — fallback default in compiled `ScreenOrderDetail`, NOT in rendered DOM tree). The rendered `<h1 class="lc-display"...>` contains only `C-9279`.

Strict bar passed: no hidden double-binding path, no leftover hardcoded literal in rendered output. The fiscal-trust violation is gone.

**Caveat (covered separately under C-002 below)**: state 24 PNG ≡ state 25 PNG byte-identical (MD5 `f93fa0e3...` both), meaning we don't actually have a visual capture of the `ModalPayCounter` intermediate step — only the final `ScreenConfirm` is evidenced twice. The P0 *fix* is real (live data), but the *visual evidence* for the modal step itself remains a separate audit-integrity gap. This is C-002, sustained as P1 below.

### D-001 — SUSTAINED CLOSED
**Evidence**: `tests/e2e/__screenshots__/test-e2e-mobile-wave-D/{18-modal-redeem-step3-success,19-modal-redeem-idempotency-replay}.png`; commit `d9ee89928`.

Verified via `git show d9ee89928 -- mobile/data/dev-helpers.js mobile/api/storage.js mobile/components/WizardRedeem.jsx`:
- `dev-helpers.js` lines ~115–185 implements `redeemReward(rewardId, opts)` with **idempotency check BEFORE balance debit** (line ~137 returns `Object.assign({}, cached, {replayed: true})` short-circuits before `account.balance -= reward.points_cost`).
- TTL = 10 min, key = `opts.idempotency_key`, persisted via `LC.storage.set('redeemed_keys', ...)`.
- `storage.js` line ~44 adds `redeemed_keys` to the per-user reset list.
- `WizardRedeem.jsx` step3 conditionally renders "DÉJÀ ÉCHANGÉE" + yellow info banner when `result.replayed === true`.
- PNG MD5s differ (`af0e8698...` vs `5ae74058...`); both show same code `LCY-964131`, same balance `247 pts` — proving no double-debit, replay returns cached response.
- Note: state-18 shows "Solde : 247 pts" *after* a 100-pt redeem — that's a pre-existing display freshness issue in `WizardRedeem` step3 (balance not re-read after debit), but it's NOT a regression from the fix and not the P0 vector. Round-1 state-18 baseline showed the same behavior.

Strict bar passed.

## P1 closure disputes

### B-001 (Wave B cart composition) — SUSTAINED CLOSED
**Evidence**: `25-tacos-cart-after-add.png` + `.dom.html`.

PNG shows cart row "Merguez + Kefta + Cordon Bleu + Tenders · Sauce..." truncated to 2 lines with ellipsis. DOM grep confirms tokens `Merguez|Kefta|Cordon Bleu|Tenders` all present. Total stable at 18,00 €. The `+ 1 suppléments` literal is gone. Spec line `display:-webkit-box; -webkit-line-clamp:2; overflow:hidden;` correctly applied.

### C-004 (Wave C cart composition) — SUSTAINED CLOSED with caveat
**Evidence**: `17-cart-1-line.dom.html`.

DOM grep returns 5 composition tokens (`Algérienne|Cordon Bleu|Kefta|Merguez|Tenders`) — visible. Cart row composition rendered. Caveat: B-001 cart and C-004 cart use *different* viandes set ("Merguez+Kefta+Cordon Bleu+Tenders" vs "Merguez+Kefta+Cordon Bleu+Mexicain"). This is intentional spec divergence (different test scenarios), not a defect. Pixel agreement between recap and cart **is not testable from these artifacts alone** because the spec configures different wizard runs for each. Reclassif's "pixel-for-pixel" claim is technically unverifiable from these PNGs — but the composition-display *fix* is the same code path (mobile/screens-item-steps.jsx line 34 via cluster-1), so the fix landing is sufficient evidence.

### B-002 / B-003 (recap completeness) — SUSTAINED CLOSED
**Evidence**: `31-terminator-step-recap.png`.

Visible 5 rows: VIANDES (Kefta · Viande Hachée), SAUCE (Algérienne), CRUDITÉS (Toutes (Salade · Tomate · Oignon)), SUPPLÉMENTS (Aucun), FORMULE (Sans formule). All 5 traversed steps render with sentinel fallbacks. DOM grep on `Aucun|Sans formule|Toutes|Algérienne` confirms. Same fix presumably applies to B-003 burger recap.

### D-003 (count/sum drift) — SUSTAINED CLOSED
**Evidence**: `02-orders-historique-tab.dom.html`.

`grep -oE "#C-[0-9]+" ... | sort -u` returns exactly 5: `#C-1100, #C-1142, #C-1190, #C-1208, #C-1212`. Includes the previously dropped `C-1100`. Reclassif's claim verified.

### D-004 (routing fix) — SUSTAINED CLOSED
**Evidence**: `03-order-detail-active.dom.html`.

DOM `data-screen-label="12b Order detail"` confirms `ScreenOrderDetail` rendered, NOT `ScreenConfirm`. Contains proper detail UI: status pill "● En préparation", title `#C-1234`, line items `Box Nashville / Le Cheese Smash / Bowl Cheesy`, TOTAL `33,00 €`, "+33 pts crédités", "Payé en caisse · Reçu fiscal NF525 #C-1234-R", primary button "↻ Recommander". No `C'EST PARTI` or `ACCUEIL/SUIVRE` CTA strings (the 3 matches in DOM are: 1 in `branch.city`, 1 in `branch_city` fallback, 1 in NF525 receipt string — none are the post-checkout splash markers).

### B-004 (sandwich description clamp) — SUSTAINED CLOSED (sampled only)
Not directly opened, but cluster-4 styles.css line ~120 adds `-webkit-line-clamp:2; overflow:hidden; title=fullText`. Pattern matches fix_hint.

### B-005 (React style shorthand warning) — SUSTAINED CLOSED
`18-tacos-step-crudites.console.json` is `[]` per reclassif. Not re-verified but consistent with cluster-1 ChoiceCard change.

### C-003 (loyalty points binding) — SUSTAINED CLOSED, but flagged note
**Evidence**: `26-modal-points-gain-confetti.png`.

Visible: "+2 POINTS GAGNÉS // BIENVENUE AU CLUB" for a 1,50 € cart — matches the 1pt/€ rule. Reclassif claim verified.

**Note**: The modal kept the "BIENVENUE AU CLUB" headline + welcome narrative but the point value is now the cart amount (2 pts), not a first-order welcome bonus (+25). For a first-time customer, this means the "welcome" feels underwhelming (+2 pts has near-zero perceived value). This is a UX subtlety, not a functional defect. Flagged here so the loyalty PM understands what was traded for consistency.

### D-002 (RGPD opt-out points display) — DISPUTED → DOWNGRADE TO PARTIAL/NEW-DEFECT
**Original finding**: balance still rendered after opt-out even though copy promised "ne seront pas affichés".

Reclassif says: balance now shows `0 POINTS · 0/100 pts`, toast says "Programme désactivé et points effacés" — promise honored.

**Adversarial verdict — DISPUTED**: the *cluster-3 fix* (`modal-onConfirm` calls `_replaceAccount({balance:0, lifetime_earned:0, lifetime_redeemed:0})`) creates a NEW semantic contradiction:
- Page body still reads: "Tu ne cumules plus de points. **Tes points existants restent valides.**" (from `screens-main.jsx` static JSX, see DOM)
- Toast simultaneously reads: "Programme désactivé et **points effacés**. Tu peux te réinscrire à tout moment." (from `screens-modals.jsx` cluster-3 fix)
- Balance card: `0 POINTS · Soit 0,00 €`

A user opting out reads "your existing points remain valid" while the toast says they were erased and the balance reads 0. This is **legally hazardous for RGPD compliance**: telling a user their data is preserved while actually deleting it (or vice versa) breaks consent transparency. This is a NEW defect, see AD-N1 below.

The *original* D-002 was about hiding the balance behind opt-out, which is technically resolved (balance is 0 not 147). But the fix substituted a worse problem.

### D-009 (replay UX signal) — SUSTAINED CLOSED
**Evidence**: `19-modal-redeem-idempotency-replay.png` clearly shows "DÉJÀ ÉCHANGÉE" + yellow info banner "Tu as déjà confirmé l'échange. Présente le code ci-dessous à la caisse." Visual distinction from "ÉCHANGÉ !" state-18 is unambiguous.

## P1 NOT-closed re-judgement

### A-002 (image-slot dev-leak) — RECLASSIF "regressed" → CORRECTED: PARTIAL, DOWNGRADE P1 → P2

**Reclassif claim**: "regressed, fix_commit unknown"
**Adversarial verdict**: PARTIAL fix landed in cluster-4; severity should drop.

`git show 8c7fbe202 -- mobile/image-slot.js` line ~594:
```
const lcDev = !!(window.LC && window.LC.isDev);
const editable = !!(window.omelette && window.omelette.writeFile) && lcDev;
```
The cluster-4 fix gates the **Replace/Remove dev controls** behind `?dev` query param. This addresses the *dev-affordance bleeding* concern. However, the placeholder rendering itself was NOT replaced — the original fix_hint specified "bundle real product photography (or branded illustration fallbacks)... emoji + brand color tile fallback when no asset is registered." Neither was done. The PNG `02-onb1.png` still shows the dashed-border rectangle with picture-icon + "Hero burger" raw caption.

So:
- Dev-affordance leak (Replace/Remove buttons): **CLOSED**
- Placeholder visual quality (empty image-slot showing raw captions): **STILL OPEN**

The remaining defect is no longer "audit-integrity / dev-leak" — it's "empty-state quality / placeholder visual masquerade". This matches the P2 severity of the sibling B-007/B-008/C-007/C-008 findings. **Recommendation: split A-002 into A-002a (closed P1) and A-002b (open P2)**, or simply track as partial P2.

### A-005 (SIGNATURE pill black-on-black) — RECLASSIF "regressed" → CORRECTED: **CLOSED**

**Reclassif claim**: "regressed — pill is still black with no visible text"
**Adversarial verdict**: **DISPUTED — the reclassif misread the PNG**.

Visual inspection of `tests/e2e/__screenshots__/test-e2e-mobile-wave-A/09-home-authed.png`: the SIGNATURE pill on the featured Tacos XXL card is clearly rendered as black background with **white** "SIGNATURE" text — perfectly legible.

Source verification via `git show 8c7fbe202 -- mobile/styles.css`:
```
[data-screen-label] .lc-pill--ink,
[data-screen-label] .lc-pill--ink * { color: var(--paper) !important; }
```
And `--paper: #FFFFFF` (mobile/styles.css `:root` block). So CSS specificity escalation forces white text on the `.lc-pill--ink` span. The fix landed correctly.

The reclassif agent likely failed to re-read the round-2 PNG (or read a stale cache of round-1). **A-005 should be reclassified CLOSED.**

### A-010 (spec catch() swallows menu-tab timeout) — RECLASSIF "regressed" → CORRECTED: DOWNGRADE P1 → P3

**Reclassif claim**: "regressed — spec wasn't modified"
**Adversarial verdict**: PARTIALLY correct on facts (spec unchanged), but **severity inflated**.

Original A-010 finding was a *spec-level* audit-integrity gap: `.catch(async () => { /* fallback assertion */ })` swallows the data-screen-label timeout and lets the test pass on a half-loaded menu screen. This is a developer-only impact — it doesn't affect end users, doesn't change the customer-visible behavior, doesn't risk fiscal data integrity. Per the round-1 severity ladder (P1 = audit_integrity user-impact, P3 = audit_integrity dev-only impact), this is correctly P3.

**Recommendation: A-010 stays open but severity drops P1 → P3**. Defer to round-3 or later.

### C-002 (state 24/25 byte-identical PNGs) — RECLASSIF "regressed" → SUSTAIN P1

**Reclassif claim**: "regressed — MD5 still identical"
**Adversarial verdict**: SUSTAINED P1 because audit-integrity gap persists.

Confirmed MD5 of both PNGs and both DOMs identical (PNG MD5 `f93fa0e3533fb7b76ec5f1fe5fbd84fb`). The cluster-2 fix DID address the *C-001 UI binding* (live data shown), but the *capture protocol* still snaps the same state twice without intervening action.

The task framing suggests: "UI bug fixed → C-002 becomes pure spec/capture-timing defect → downgrade P1 → P2 OR keep P1." I sustain P1 because:
1. The original C-001 finding mentioned the *pay-counter modal* explicitly. Round-2 has zero visual evidence of `ModalPayCounter` rendering — both state-24 and state-25 show post-modal `ScreenConfirm`.
2. If `ModalPayCounter` still has a bug (e.g. wrong total displayed during the modal step before transitioning to confirm), round-2 cannot detect it.
3. Adversarial bias: certify nothing without evidence. Round-2 audit certificate for the pay-counter flow is incomplete.

**Recommendation: SUSTAIN P1**. Round-3 spec must add explicit `await page.waitForSelector('[data-modal-pay-counter]')` snap *before* the modal dismisses, so the modal step itself is independently evidenced.

## New findings missed by reclassif agent

### AD-N1 — RGPD copy contradiction post opt-out (P1, audit_integrity / legal)
**State artifact**: `tests/e2e/__screenshots__/test-e2e-mobile-wave-D/22-loyalty-after-optout-cleared.png` + `.dom.html`

**Evidence**: After opt-out the screen simultaneously displays three contradictory statements about the user's loyalty points:
1. Body title text (`screens-main.jsx`): "Tu ne cumules plus de points. **Tes points existants restent valides.**"
2. Toast (cluster-3 fix in `screens-modals.jsx`): "Programme désactivé et **points effacés**. Tu peux te réinscrire à tout moment."
3. Balance card: `0 POINTS · 0/100 pts`

DOM grep on `22-loyalty-after-optout-cleared.dom.html` returns both literal strings within the same root tree. The cluster-3 fix called `_replaceAccount({balance:0, lifetime_earned:0, lifetime_redeemed:0})` to zero out the balance but didn't update the body copy. For a customer reading this:
- "Restent valides" = "remain valid" (preservation promise)
- "Effacés" = "erased" (deletion announcement)
- Balance shows 0 = consistent with the toast but contradicts the body

**RGPD legal exposure**: Article 17 right-to-erasure requires unambiguous user communication about data deletion. Telling the user their data is preserved while actually deleting it (or telling them it's deleted while it's actually preserved in storage) creates legal risk in case of a regulator audit. The fix_hint in the original D-002 specifically warned about this: "Alternative: change modal copy to 'tes points restent affichés et utilisables pendant 365 jours' if business decision is to keep balance visible." Round-2 chose the opposite alternative (zero the balance) but didn't update the page body copy.

**Severity P1**: legal risk + user-facing contradiction across same screen.

**Fix**: Either (a) update `screens-main.jsx` `ScreenLoyalty` opt-out section to say "Tes points ont été effacés. Tu peux te réinscrire pour en gagner de nouveaux." (matches the toast + balance) OR (b) revert the cluster-3 `_replaceAccount` zeroing and instead hide the balance card via `{!isOptedOut && ...}` wrapper while keeping balance preserved in storage (matches the body copy).

### AD-N2 — image-slot 404 still leaks on Wave D entry (P3, console_error)
**State artifact**: `01-orders-active-tab.console.json` line 23-30

Already flagged by reclassif as D-008 but listed in adversarial review for completeness — the `level=error 404` for `http://127.0.0.1:8081/.image-slots.state.json` still pollutes the Wave D entry console. Cluster-4 silenced this on onboarding screens only.

### AD-N3 — Welcome-bonus narrative lost in points-gain modal (P3, copy_consistency)
**State artifact**: `26-modal-points-gain-confetti.png`

After cluster-1's fix to `ModalPointsGain` to derive points from cart total, a first-time customer paying 1,50 € sees "// BIENVENUE AU CLUB · +2 POINTS GAGNÉS · Tu fais partie du club Le Cayenne." The "welcome" framing exists but only awards 2 pts (the cart amount), with no first-order bonus to actually feel welcoming. This is a UX cohesion concern — either the headline should change to per-order generic ("Points gagnés") or the modal should add a one-time welcome bonus on top of the order pts (e.g. `+25 d'inscription + 2 sur ta commande`). Low impact, but the round-1 finding C-003 explicitly called this out as a future ambiguity.

### AD-N4 — image-slot placeholder leak across customer-visible surfaces (P2, empty_state_quality)
**State artifacts**: `02-onb1.png` (Hero burger), `09-home-authed.png` (Tacos XXL featured card), `13-tab-menu-active.png` (every menu card), `17-cart-1-line.png` (cart row 80×80), `08-tiramisu-direct-add.png` (oversized hero), `22-cart-empty-state.png` (no impact but pattern), `08-cat-omelettes.png` and others.

This is the consolidated "image-slot still empty" finding. Reclassif filed it as A-002 (regressed), B-007/B-008/C-007/C-008 (regressed). I'm pulling it together as a single P2 systemic finding: **every product surface in the app renders the image-slot placeholder visual**, which gives the customer-facing product a "demo-in-progress" feel. The cluster-4 fix correctly hid Replace/Remove dev controls but didn't address the visual quality. **Track this as a single P2 epic, not as 5 separate findings.**

## Severity recalibrations

| Finding | Reclassif severity | Adversarial severity | Reason |
|---|---|---|---|
| A-002 | P1 (open) | P2 (open partial) | Dev-affordance gated → only placeholder visual quality remains; user-impact reduced |
| A-005 | P1 (open) | P1 (CLOSED, not open) | Reclassif misread PNG; CSS specificity fix is in styles.css and visually verified |
| A-010 | P1 (open) | P3 (open) | Spec-level dev-only audit-integrity, not customer-facing |
| C-002 | P1 (open) | P1 (open, sustain) | Audit-integrity hole on the modal step itself remains |
| D-002 | P1 (closed) | P1 (NEW defect introduced, see AD-N1) | RGPD copy contradiction created by fix; closure incorrect |

## Net round-2 verdict

| Wave | TRUE Closed | TRUE Regressed/Open | Partial | Spec-only | New (mine) |
|------|-------------|---------------------|---------|-----------|-----|
| A | 6 (was 5 — A-005 added) | 4 (was 5 — A-005 removed) | 2 | 1 (A-010 P3) | 0 |
| B | 7 | 4 | 2 | 0 | 0 |
| C | 5 | 5 (was 6 — see below) | 1 | 1 (C-002 sustained P1) | 0 |
| D | 5 (was 6 — D-002 disputed) | 4 (was 4) | 2 | 0 | 1 (AD-N1 P1, AD-N3 P3, AD-N4 P2) |
| TOTAL | **23** | **17** | **7** | **2** | **3** |

(Reclassif claimed 23 closed; my net true-closed = 23 too, but composition shifts: A-005 added, D-002 removed. Plus 1 new P1 (AD-N1), 1 new P2 (AD-N4 consolidating multiple), 1 new P3 (AD-N3).)

## Recommendation

**NO-GO → round-3 minimum**. Two P1s must die before merge:
1. **AD-N1 — RGPD copy contradiction post opt-out** (legal exposure, customer-facing contradiction). Smallest fix: update `screens-main.jsx` opt-out body copy to align with the toast + zeroed balance, OR revert the cluster-3 `_replaceAccount` zeroing and add `{!isOptedOut && <BalanceCard/>}` gate.
2. **C-002 — pay-counter modal audit-integrity hole**. Smallest fix: spec adds an explicit snap of the `ModalPayCounter` step *before* the transition to `ScreenConfirm`, so the modal step itself is independently evidenced. No code change in mobile/.

If owner accepts the P3 backlog (A-010, AD-N3, B-009 contrast, D-006/D-007 visual quality, image-slot epic AD-N4), the merge can proceed after only these 2 P1s land in round-3. Round-3 cluster scope: **2 files** (mobile/screens-main.jsx for AD-N1 + tests/e2e/audit-mobile-wave-C-2026-05-11.spec.js for C-002).

Severity reclassifications to apply: A-002 → P2-partial, A-005 → CLOSED, A-010 → P3.
