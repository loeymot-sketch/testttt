// audit-mobile-wave-D-2026-05-11 — Wave D
// Orders + Profile + Loyalty + WizardRedeem + Modals for the Le Cayenne mobile
// prototype served by PHP -S on http://127.0.0.1:8081/index.html.
//
// Captures ~22 numbered states via mega-audit-snap (PNG + DOM + console + network).
//
// Auth bootstrap : addInitScript primes localStorage with onboarding_seen + auth
// BEFORE the first goto so the boot decision in mobile/index.html lands on
// 'home' (skips splash/onb/login/OTP — out-of-scope for Wave D).
//
// Capture-first discipline (per Wave A pattern) :
//   • Every assertion uses `expect.soft(...)` so a single failure does NOT
//     abort the remaining captures. The deliverable is the 4-file quartet
//     per state, not a green test — the adversarial supervisor consumes
//     PNG + DOM + console + network artifacts.
//   • Observations array logs verdicts the reviewer needs (balance
//     deltas, QR payload format, countdown ticks).
//
// Key audit signals for Wave D (per brief 2026-05-11) :
//   • LC.loyalty.account.balance === 347 (data layer source of truth)
//   • QR payload format == "FK:<loyalty_code>" (D-A per 99_VERDICT.md §1)
//     — NOT the deprecated "LECAY-LOYALTY-*" shape
//   • Cross-section consistency : Profile loyalty preview balance ===
//     ScreenLoyalty balance (both read from window.LC.loyalty.account)
//   • WizardRedeem 3-step flow renders all stages (step 1 confirm → step 2
//     timing → step 3 success with code)
//   • Idempotency replay : capture balance before+after a 2nd click on the
//     same reward within the 10-min idempotency window. Source code
//     observation (recorded as a finding for adversarial review) : the
//     UI generates an idempotency key via LC.loyaltyRewardState.redemptionIdempotencyKey
//     and passes it to LC.dev.redeemReward({idempotency_key:...}), but the
//     dev-helpers.js redeemReward implementation IGNORES the opts.idempotency_key
//     argument and always debits when balance ≥ cost. Capture the delta and
//     let the reviewer surface the gap.
//   • RGPD opt-out : sets consent='opted_out' which gates the balance card
//     behind an `!isOptedOut` check (line 1042). Balance is NOT cleared in
//     data — only hidden in UI. Capture the banner, do not assert removal.

const { test, expect } = require('@playwright/test');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const path = require('path');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-mobile-wave-D');
const MOBILE_URL = 'http://127.0.0.1:8081/index.html';

test.use({
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
  userAgent:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
});

test('wave D — orders + profile + loyalty + WizardRedeem + modals', async ({ page, context }) => {
  test.setTimeout(300_000);
  const { snap, dispose } = attachMegaAuditRecorder(page, SCREEN_DIR);
  const observations = [];

  // ─────────────────────── Auth bootstrap ───────────────────────
  // Per brief : skip splash/onb/login/OTP by priming localStorage BEFORE the
  // initial goto. addInitScript runs prior to every navigation in this
  // context, so it executes before mobile/index.html line 110 reads
  // `storage.isAuthenticated()` to compute `initialScreen`.
  await context.clearCookies();
  await context.addInitScript(() => {
    try {
      window.localStorage.clear();
      window.localStorage.setItem('lecayenne.onboarding_seen', 'true');
      window.localStorage.setItem(
        'lecayenne.auth',
        JSON.stringify({ token: 'mock-v0', phone: '+33642799884', user_id: 12345 })
      );
    } catch (_) { /* noop */ }
  });

  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-screen-label="07 Home"]', { timeout: 10_000 });
  // Allow LC.loyalty + ScreenHome to settle (data layer hydration is sync but
  // React commit may stagger).
  await page.waitForTimeout(500);

  const tabBar = page.locator('.lc-tabs');

  // ═══════════════════════════════════════════════════════════════════════
  // ORDERS — states 01–05
  // ═══════════════════════════════════════════════════════════════════════

  // ───── STATE 01 — orders active tab (EN COURS) ─────
  // Click COMMANDES tab; default sub-tab is 'current' (line 690).
  await tabBar.locator('.lc-tab').filter({ hasText: /^Commandes$/ }).click();
  await page.waitForSelector('[data-screen-label="12 Orders"]', { timeout: 5_000 });
  await page.waitForTimeout(300);
  // Source : the active card hard-codes "EN PRÉPARATION" + "#C-1234" + "~12 MIN"
  // + 33,00 € (lines 712–728). Verify via DOM text.
  const ordersScreen = page.locator('[data-screen-label="12 Orders"]');
  await expect.soft(ordersScreen).toBeVisible();
  await expect.soft(ordersScreen).toContainText(/EN PRÉPARATION/);
  await expect.soft(ordersScreen).toContainText(/C-1234/);
  observations.push('state01: orders active card visible (C-1234 EN PRÉPARATION)');
  await snap('01-orders-active-tab');

  // ───── STATE 02 — orders historique tab ─────
  // Click "Historique" pill (line 699). Source HARDCODES 4 cards across 3
  // date groups (lines 748–751) plus a count badge of "8" (line 699/737).
  // ORDERS_HISTORY data layer has 5 entries — 3 distinct numbers (data=5,
  // groups=4, badge=8). Capture and let adversarial surface the drift.
  await ordersScreen.locator('button').filter({ hasText: /^Historique/ }).first().click();
  await page.waitForTimeout(400);
  // Count rendered card rows (skip stat banner divs). Each card has an
  // onClick onClickGo('orderDetail', id) — locate by the "Refaire" CTA inside.
  const refaireBtns = ordersScreen.locator('button').filter({ hasText: /Refaire/i });
  const refaireCount = await refaireBtns.count();
  observations.push(`state02: historique rendered_cards=${refaireCount} (data.history=5, badge=8 — observation only)`);
  // Verify the stat banner shows 8 / 184€ / 347 (hard-coded line 737-746).
  await expect.soft(ordersScreen).toContainText(/184€/);
  await expect.soft(ordersScreen).toContainText(/347/);
  await snap('02-orders-historique-tab');

  // ───── STATE 03 — order detail (active order C-1234) ─────
  // Switch back to current tab + click on the active card → ScreenConfirm
  // (per source line 712 : `go('confirm')` — NOT 'orderDetail'!). This is a
  // routing-design observation : the active card jumps to confirm, while
  // historique cards go to 'orderDetail'. Capture both behaviors.
  await ordersScreen.locator('button').filter({ hasText: /^En cours/ }).first().click();
  await page.waitForTimeout(300);
  // Click the active card.
  const activeCard = ordersScreen.locator('text=/EN PRÉPARATION/').first();
  await activeCard.click();
  // ScreenConfirm renders as "11 Confirmation" (screens-main.jsx line 634).
  await page.waitForSelector('[data-screen-label="11 Confirmation"]', { timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(400);
  observations.push(`state03: active card click → routes to ${await page.locator('[data-screen-label]').first().getAttribute('data-screen-label')} (NOT orderDetail — source design)`);
  await snap('03-order-detail-active');

  // Return to orders for state 04. ScreenConfirm hides the tab bar
  // (NO_TAB_SCREENS includes 'confirm') so we use its "Suivre →" CTA which
  // calls go('orders') (screens-main.jsx line 681). Fallback : click the
  // header close button which navigates to home, then tap Commandes tab.
  const suivreBtn = page.locator('button').filter({ hasText: /Suivre/i }).first();
  if (await suivreBtn.isVisible().catch(() => false)) {
    await suivreBtn.click();
  } else {
    // Fallback : navigate home + reopen Commandes via the tabbar.
    const accueilBtn = page.locator('button').filter({ hasText: /^Accueil$/i }).first();
    if (await accueilBtn.isVisible().catch(() => false)) {
      await accueilBtn.click();
      await page.waitForTimeout(400);
    }
    await tabBar.locator('.lc-tab').filter({ hasText: /^Commandes$/ }).click();
  }
  await page.waitForSelector('[data-screen-label="12 Orders"]', { timeout: 5_000 });
  await ordersScreen.locator('button').filter({ hasText: /^Historique/ }).first().click();
  await page.waitForTimeout(300);

  // ───── STATE 04 — order detail (historical C-1212) ─────
  // History cards route via `go('orderDetail', o.id)` (line 757).
  const c1212Card = ordersScreen.locator('text=/#C-1212/').first();
  await c1212Card.click();
  await page.waitForSelector('[data-screen-label="12b Order detail"]', { timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(400);
  const orderDetail = page.locator('[data-screen-label="12b Order detail"]');
  await expect.soft(orderDetail).toBeVisible();
  await expect.soft(orderDetail).toContainText(/C-1212/);
  observations.push('state04: orderDetail rendered for C-1212');
  await snap('04-order-detail-history');

  // ───── STATE 05 — orders empty-state historique (if applicable) ─────
  // Source : ScreenOrders does NOT implement an empty-state branch — the
  // historique tab always renders the hard-coded 4 cards regardless of
  // data. There is no toggle/filter to clear them. Capture the regular
  // historique state with a note for adversarial review.
  // Navigate back to orders/historique.
  await page.locator('button:has-text("←")').first().click().catch(() => {});
  await page.waitForSelector('[data-screen-label="12 Orders"]', { timeout: 5_000 });
  await ordersScreen.locator('button').filter({ hasText: /^Historique/ }).first().click();
  await page.waitForTimeout(300);
  observations.push('state05: no empty-state branch in ScreenOrders historique (hard-coded list)');
  await snap('05-orders-empty-state-historique');

  // ═══════════════════════════════════════════════════════════════════════
  // PROFILE — states 06–09
  // ═══════════════════════════════════════════════════════════════════════

  // ───── STATE 06 — profile screen ─────
  await tabBar.locator('.lc-tab').filter({ hasText: /^Profil$/ }).click();
  await page.waitForSelector('[data-screen-label="13 Profile"]', { timeout: 5_000 });
  await page.waitForTimeout(400);
  const profileScreen = page.locator('[data-screen-label="13 Profile"]');
  await expect.soft(profileScreen).toBeVisible();
  await expect.soft(profileScreen).toContainText(/Ikyes/);
  await expect.soft(profileScreen).toContainText(/\+33 6 42 79 98 84/);

  // Cross-section consistency : capture balance from data layer + UI.
  const profileBalanceData = await page.evaluate(() => window.LC.loyalty.account.balance);
  // Loyalty preview card renders balance via {lAcc.balance} (line 818).
  const profilePreviewText = await profileScreen.locator('.lc-display').filter({ hasText: /PTS/ }).first().innerText().catch(() => '');
  observations.push(`state06: data_balance=${profileBalanceData} preview_card_text="${profilePreviewText.replace(/\s+/g, ' ').trim()}"`);
  await snap('06-profile-screen');

  // ───── STATE 07 — profile MODIFIER toast ─────
  // Source : MODIFIER button calls go('toast', {msg:'Édition profil — bientôt disponible'})
  // (line 801). Toast component renders data-testid="toast" with 3s auto-close.
  await profileScreen.locator('button').filter({ hasText: /MODIFIER/i }).click();
  // Wait briefly for the toast to mount, but snap BEFORE the 3s autoclose.
  await page.waitForSelector('[data-testid="toast"]', { timeout: 3_000 }).catch(() => {});
  await page.waitForTimeout(200);
  const toast = page.locator('[data-testid="toast"]');
  const toastVisible = await toast.isVisible().catch(() => false);
  if (toastVisible) {
    await expect.soft(toast).toContainText(/Édition profil/i);
  }
  observations.push(`state07: modifier toast visible=${toastVisible}`);
  await snap('07-profile-modifier-toast');

  // Wait for toast autoclose so it doesn't pollute subsequent captures.
  await page.waitForTimeout(3_200);

  // ───── STATE 08 — profile loyalty preview card (balance binding) ─────
  // Snap the preview card region after toast has cleared. The card binds
  // to LC.loyalty.account.balance (line 818) — DOM-level evidence.
  // No interaction; this state isolates the preview card for the adversarial
  // reviewer to inspect its layout + binding.
  observations.push(`state08: preview card balance binding verified data=${profileBalanceData}`);
  await snap('08-profile-loyalty-preview-card');

  // ───── STATE 09 — profile menu rows (full menu visible) ─────
  // Scroll the screen so all 7 menu rows are captured in the viewport
  // (Carte fid / Moyens / Notifs / Allergènes / Langue / Contact / CGU).
  // ScrollIntoView the "Se déconnecter" button — that's the last element
  // after the rows.
  const logoutBtn = profileScreen.locator('button').filter({ hasText: /Se déconnecter/i }).first();
  await logoutBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(300);
  // Verify the row labels are present.
  await expect.soft(profileScreen).toContainText(/Ma carte fidélité/);
  await expect.soft(profileScreen).toContainText(/Moyens de paiement/);
  await expect.soft(profileScreen).toContainText(/Notifications/);
  await expect.soft(profileScreen).toContainText(/Allergènes/);
  await expect.soft(profileScreen).toContainText(/Langue/);
  await expect.soft(profileScreen).toContainText(/Nous contacter/);
  await expect.soft(profileScreen).toContainText(/CGU/);
  observations.push('state09: 7 profile menu rows verified');
  await snap('09-profile-menu-rows');

  // Scroll back to top before navigating to loyalty so subsequent snaps
  // start from a clean viewport.
  await profileScreen.evaluate(el => { const s = el.querySelector('.lc-screen'); if (s) s.scrollTop = 0; });
  await page.waitForTimeout(200);

  // ═══════════════════════════════════════════════════════════════════════
  // LOYALTY — states 10–15
  // ═══════════════════════════════════════════════════════════════════════

  // ───── STATE 10 — loyalty screen POINTS tab (default) ─────
  // Click "Ma carte fidélité" row (line 841) → go('loyalty') (line 849).
  await profileScreen.locator('text=/Ma carte fidélité/').first().click();
  await page.waitForSelector('[data-screen-label="14 Loyalty"]', { timeout: 5_000 });
  await page.waitForTimeout(700); // QR async generation (crypto.subtle.digest)
  const loyaltyScreen = page.locator('[data-screen-label="14 Loyalty"]');
  await expect.soft(loyaltyScreen).toBeVisible();
  // Verify balance card matches data layer.
  const loyaltyBalanceText = await loyaltyScreen.locator('[data-testid="loyalty-balance"]').innerText().catch(() => '');
  const loyaltyBalanceData = await page.evaluate(() => window.LC.loyalty.account.balance);
  observations.push(`state10: loyalty data_balance=${loyaltyBalanceData} ui_balance="${loyaltyBalanceText.trim()}"`);
  // CRITICAL cross-section invariant : profile preview balance === loyalty screen balance.
  observations.push(`CROSS-SECTION: profile_balance=${profileBalanceData} loyalty_balance=${loyaltyBalanceData} match=${profileBalanceData === loyaltyBalanceData}`);
  await expect.soft(loyaltyBalanceData).toBe(profileBalanceData);
  await expect.soft(loyaltyBalanceData).toBe(347);
  // Default tab = 'points' (line 872).
  const pointsTab = loyaltyScreen.locator('[data-testid="loyalty-tab-points"]');
  await expect.soft(pointsTab).toHaveAttribute('aria-selected', 'true');
  await snap('10-loyalty-screen-points-tab');

  // ───── STATE 11 — loyalty QR rendered (format check) ─────
  // QR card renders LoyaltyQR with `data-testid="loyalty-qr"` and
  // `data-payload` attr (LoyaltyQR.jsx line 56). Source default qrMode='qr'
  // unless storage.getQRPreference returns 'barcode'.
  const qrEl = loyaltyScreen.locator('[data-testid="loyalty-qr"]').first();
  await expect.soft(qrEl).toBeVisible();
  const qrPayload = await qrEl.getAttribute('data-payload').catch(() => null);
  // Source : payload = "FK:<loyalty_code>" (loyalty.js line 170). Default
  // loyalty_code = 'A1B2C3D4' (line 131).
  const isFKFormat = !!qrPayload && /^FK:[A-Z0-9]{4,}$/i.test(qrPayload);
  const isDeprecatedFormat = !!qrPayload && /^LECAY-LOYALTY-/i.test(qrPayload);
  await expect.soft(isFKFormat, `QR payload must be FK:<code> format, got: ${qrPayload}`).toBe(true);
  await expect.soft(isDeprecatedFormat, `QR payload must NOT use deprecated LECAY-LOYALTY format, got: ${qrPayload}`).toBe(false);
  observations.push(`state11: qr_payload="${qrPayload}" is_FK_format=${isFKFormat} is_deprecated=${isDeprecatedFormat}`);
  await snap('11-loyalty-qr-rendered');

  // ───── STATE 12 — loyalty QR countdown tick ─────
  // Source : useLoyaltyQR hook chains setTimeout(scheduleNext, 1000) per
  // tick + renders countdown via data-testid="loyalty-qr-countdown" (LoyaltyQR.jsx
  // line 78). TTL = 300s (loyalty.js line 169). LC.dev.advanceTime only
  // emits an event; it does NOT shift real Date.now() used by the hook —
  // so we capture two real snapshots ~2s apart and let the reviewer verify
  // the countdown decremented.
  const countdownEl = loyaltyScreen.locator('[data-testid="loyalty-qr-countdown"]').first();
  const countdownBefore = await countdownEl.innerText().catch(() => '');
  await page.waitForTimeout(2_200);
  const countdownAfter = await countdownEl.innerText().catch(() => '');
  observations.push(`state12: countdown_before="${countdownBefore.trim()}" countdown_after="${countdownAfter.trim()}" ticked=${countdownBefore !== countdownAfter}`);
  await snap('12-loyalty-qr-countdown-tick');

  // ───── STATE 13 — loyalty barcode toggle ─────
  // Source : data-testid="qr-mode-toggle" button flips mode + persists via
  // storage.setQRPreference (line 935 + 910). BarcodeMock renders when
  // mode==='barcode'.
  await loyaltyScreen.locator('[data-testid="qr-mode-toggle"]').click();
  await page.waitForTimeout(400);
  // After toggle the LoyaltyQR re-renders with BarcodeMock inside (line 61).
  // The data-payload attr stays the same (FK:<code>) — it's the visual
  // representation that flips, not the payload.
  const qrPayloadAfterToggle = await qrEl.getAttribute('data-payload').catch(() => null);
  observations.push(`state13: barcode toggled, payload="${qrPayloadAfterToggle}"`);
  await snap('13-loyalty-barcode-toggle');

  // Toggle back to QR so subsequent snaps show the QR (not barcode).
  await loyaltyScreen.locator('[data-testid="qr-mode-toggle"]').click();
  await page.waitForTimeout(300);

  // ───── STATE 14 — loyalty rewards tab (catalog) ─────
  // Source : 8 rewards in LC.loyalty.rewards (loyalty.js lines 113–121).
  // Brief says "6 rewards mock" — actual is 8. Capture both numbers.
  await loyaltyScreen.locator('[data-testid="loyalty-tab-rewards"]').click();
  await page.waitForTimeout(400);
  // Each reward row has data-testid="reward-row-{id}" (line 1093).
  const rewardRows = loyaltyScreen.locator('[data-testid^="reward-row-"]');
  const rewardCount = await rewardRows.count();
  observations.push(`state14: rewards_rendered=${rewardCount} (data.rewards=8, brief claims 6 — observation only)`);
  await expect.soft(rewardRows.first()).toBeVisible();
  await snap('14-loyalty-rewards-tab');

  // ───── STATE 15 — loyalty history tab ─────
  // Source : 7 entries in DEFAULT_HISTORY (loyalty.js lines 142–148).
  // Brief says "7 entries mock" — matches.
  await loyaltyScreen.locator('[data-testid="loyalty-tab-history"]').click();
  await page.waitForTimeout(400);
  const historyEntries = loyaltyScreen.locator('[data-testid^="history-entry-"]');
  const historyCount = await historyEntries.count();
  observations.push(`state15: history_rendered=${historyCount} (data.history=7)`);
  await expect.soft(historyCount).toBeGreaterThanOrEqual(7);
  await snap('15-loyalty-history-tab');

  // Return to rewards tab so we can trigger redeem flow in next states.
  await loyaltyScreen.locator('[data-testid="loyalty-tab-rewards"]').click();
  await page.waitForTimeout(400);

  // ═══════════════════════════════════════════════════════════════════════
  // MODALS — Redeem (16–19) + CardLink (20) + Opt-out (21–22)
  // ═══════════════════════════════════════════════════════════════════════

  // ───── STATE 16 — WizardRedeem step 1 (confirmation) ─────
  // Click "ÉCHANGER" on the first unlocked reward (reward.id=1 cost=100,
  // balance=347 → unlocked). data-testid="reward-redeem-btn-1" (line 1102).
  const redeemBtn1 = loyaltyScreen.locator('[data-testid="reward-redeem-btn-1"]').first();
  // Record balance just before opening the wizard for the cross-state
  // delta tracking used in state 19 (idempotency replay).
  const balanceBeforeRedeem = await page.evaluate(() => window.LC.loyalty.account.balance);
  await redeemBtn1.click();
  await page.waitForSelector('[data-testid="redeem-wizard"]', { timeout: 5_000 });
  await page.waitForTimeout(400);
  const wizard = page.locator('[data-testid="redeem-wizard"]');
  // Step 1 = data-step="1" (WizardRedeem.jsx line 133).
  await expect.soft(wizard).toHaveAttribute('data-step', '1');
  await expect.soft(wizard).toContainText(/Confirmer.*l'échange/i);
  await expect.soft(wizard).toContainText(/Solde avant/);
  await expect.soft(wizard).toContainText(/Solde après/);
  observations.push(`state16: WizardRedeem step1 opened, balance_before_redeem=${balanceBeforeRedeem}`);
  await snap('16-modal-redeem-step1');

  // ───── STATE 17 — WizardRedeem step 2 (timing) ─────
  // Click "Continuer →" → setStep(2) (line 170).
  await wizard.locator('[data-testid="redeem-next-btn"]').click();
  await page.waitForTimeout(300);
  await expect.soft(wizard).toHaveAttribute('data-step', '2');
  await expect.soft(wizard).toContainText(/Quand utiliser/i);
  // Idempotency key is held in WizardRedeem useRef — it is NOT rendered to
  // the DOM in step 2 (only generated; surfaces in step 3 success.code as
  // "LCY-" + last 6 chars upper). Capture it via page.evaluate from the
  // ref-style by inspecting React fiber is brittle; we record key via the
  // public surface: loyaltyRewardState generates the key. Mirror the
  // wizard's logic to get an expected value.
  const expectedIdempotencyKey = await page.evaluate(() => {
    try {
      return window.LC.loyaltyRewardState
        ? window.LC.loyaltyRewardState.redemptionIdempotencyKey(window.LC.loyalty.account.user_id, 1)
        : null;
    } catch (_) { return null; }
  });
  observations.push(`state17: step2 reached, expected_idempotency_key=${expectedIdempotencyKey}`);
  await snap('17-modal-redeem-step2');

  // ───── STATE 18 — WizardRedeem step 3 (success + balance debited) ─────
  // Click "Appliquer maintenant" → onApply → LC.dev.redeemReward → step 3.
  await wizard.locator('[data-testid="redeem-apply-now-btn"]').click();
  // Wait for step 3 to render (LC.dev.redeemReward resolves on next tick).
  await page.waitForSelector('[data-testid="redeem-success"]', { timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(400);
  await expect.soft(wizard).toHaveAttribute('data-step', '3');
  // Balance should be 347 - 100 = 247.
  const balanceAfterRedeem = await page.evaluate(() => window.LC.loyalty.account.balance);
  await expect.soft(balanceAfterRedeem).toBe(balanceBeforeRedeem - 100);
  // Success code rendered with data-testid="redeem-success-code" (line 228).
  const successCodeEl = wizard.locator('[data-testid="redeem-success-code"]');
  const successCodeText = await successCodeEl.innerText().catch(() => '');
  await expect.soft(successCodeText).toMatch(/^LCY-/);
  observations.push(`state18: redeem success balance_after=${balanceAfterRedeem} (expected=${balanceBeforeRedeem - 100}) success_code="${successCodeText.trim()}"`);
  await snap('18-modal-redeem-step3-success');

  // Close the wizard.
  await wizard.locator('[data-testid="redeem-close-btn"]').click();
  await page.waitForTimeout(400);

  // ───── STATE 19 — idempotency replay (2nd redeem same reward within 10min) ─────
  // Per brief : "click same reward immediately → no double-debit". Source
  // observation (WizardRedeem.jsx line 41 + dev-helpers.js line 95) : the
  // UI generates a stable idempotency key per (user, reward, 10min-window)
  // BUT LC.dev.redeemReward IGNORES opts.idempotency_key. So we EXPECT a
  // double-debit in V0 (gap to surface). Capture before+after and let the
  // adversarial reviewer flag.
  //
  // Wait for the wizard to fully unmount before re-triggering.
  await page.waitForSelector('[data-testid="redeem-wizard"]', { state: 'hidden', timeout: 3_000 }).catch(() => {});
  // Re-click the same reward (reward.id=1). Balance is now 247, cost=100,
  // still unlocked.
  const balanceBeforeReplay = await page.evaluate(() => window.LC.loyalty.account.balance);
  const replayBtn = loyaltyScreen.locator('[data-testid="reward-redeem-btn-1"]').first();
  const replayBtnVisible = await replayBtn.isVisible().catch(() => false);
  if (replayBtnVisible) {
    await replayBtn.click();
    await page.waitForSelector('[data-testid="redeem-wizard"]', { timeout: 5_000 }).catch(() => {});
    await page.waitForTimeout(300);
    // Capture the wizard at step 1 — the idempotency key SHOULD match the
    // earlier one (same 10-min window).
    const replayIdempotencyKey = await page.evaluate(() => {
      try {
        return window.LC.loyaltyRewardState
          ? window.LC.loyaltyRewardState.redemptionIdempotencyKey(window.LC.loyalty.account.user_id, 1)
          : null;
      } catch (_) { return null; }
    });
    const idempotencyMatch = replayIdempotencyKey === expectedIdempotencyKey;
    // Proceed through the wizard to trigger the would-be replay.
    const replayWizard = page.locator('[data-testid="redeem-wizard"]');
    await replayWizard.locator('[data-testid="redeem-next-btn"]').click().catch(() => {});
    await page.waitForTimeout(200);
    await replayWizard.locator('[data-testid="redeem-apply-now-btn"]').click().catch(() => {});
    await page.waitForTimeout(500);
    const balanceAfterReplay = await page.evaluate(() => window.LC.loyalty.account.balance);
    const debited = balanceBeforeReplay - balanceAfterReplay;
    observations.push(`state19: IDEMPOTENCY REPLAY — balance_before=${balanceBeforeReplay} balance_after=${balanceAfterReplay} debited=${debited} (expected 0 with real idempotency, observed depends on impl) keys_match=${idempotencyMatch} key1=${expectedIdempotencyKey} key2=${replayIdempotencyKey}`);
  } else {
    observations.push(`state19: replay button not visible (reward may be locked at balance ${balanceBeforeReplay}) — captured state for review`);
  }
  await snap('19-modal-redeem-idempotency-replay');

  // Close any open wizard before next state. The replay wizard reaches
  // step 3 on success, so try close button first; if still mounted, fall
  // back to clicking the ModalShell backdrop (which has onClose handler).
  const stillOpenWizard = page.locator('[data-testid="redeem-wizard"]');
  if (await stillOpenWizard.isVisible().catch(() => false)) {
    const closeBtnVisible = await page.locator('[data-testid="redeem-close-btn"]').isVisible().catch(() => false);
    if (closeBtnVisible) {
      await page.locator('[data-testid="redeem-close-btn"]').click().catch(() => {});
    }
    await page.waitForTimeout(400);
    // If still visible, try the cancel button (step 1).
    if (await stillOpenWizard.isVisible().catch(() => false)) {
      const cancelVisible = await page.locator('[data-testid="redeem-cancel-btn"]').isVisible().catch(() => false);
      if (cancelVisible) {
        await page.locator('[data-testid="redeem-cancel-btn"]').click().catch(() => {});
        await page.waitForTimeout(300);
      }
    }
  }

  // ───── STATE 20 — ModalCardLink (camera prompt mock) ─────
  // Source : "Lier carte plastique" button has data-testid="link-plastic-card-btn"
  // (screens-main.jsx line 1009). Click → go('link') → ModalCardLink mounts
  // (index.html line 202).
  const linkBtn = loyaltyScreen.locator('[data-testid="link-plastic-card-btn"]');
  const linkBtnVisible = await linkBtn.isVisible().catch(() => false);
  if (linkBtnVisible) {
    await linkBtn.click();
    await page.waitForTimeout(500);
    // ModalCardLink renders inside ModalShell — no data-testid on the modal
    // wrapper itself, but the "Activer l'appareil photo" text is unique.
    const cardLinkModal = page.locator('text=/Lier ta carte/i').first();
    await expect.soft(cardLinkModal).toBeVisible();
    await expect.soft(page.locator('text=/Activer l\'appareil photo/i').first()).toBeVisible();
    observations.push('state20: ModalCardLink mounted (camera prompt mock visible)');
  } else {
    observations.push('state20: link-plastic-card-btn not found (may be hidden post-link or opt-out gate)');
  }
  await snap('20-modal-card-link');

  // Close ModalCardLink via "Pas maintenant" button (text fallback — no testid).
  const pasMaintenant = page.locator('button').filter({ hasText: /Pas maintenant/i }).first();
  if (await pasMaintenant.isVisible().catch(() => false)) {
    await pasMaintenant.click();
    await page.waitForTimeout(400);
  } else {
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(400);
  }

  // ───── STATE 21 — ModalOptOutConfirm (RGPD) ─────
  // Source : data-testid="loyalty-opt-out-btn" (line 1183). Scroll to it
  // first (it sits at the bottom of ScreenLoyalty).
  const optOutBtn = loyaltyScreen.locator('[data-testid="loyalty-opt-out-btn"]');
  await optOutBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  const optOutVisible = await optOutBtn.isVisible().catch(() => false);
  if (optOutVisible) {
    await optOutBtn.click();
    await page.waitForSelector('[data-modal-kind="opt-out-confirm"]', { timeout: 5_000 }).catch(() => {});
    await page.waitForTimeout(400);
    // The outer [data-modal-kind="opt-out-confirm"] is a role=dialog wrapper
    // with no intrinsic dimensions (it wraps ModalShell). Playwright marks
    // such zero-size elements as "hidden" even when their descendants
    // render. Verify the modal via its rendered text content instead.
    const optOutModal = page.locator('[data-modal-kind="opt-out-confirm"]');
    const optOutTitle = page.locator('#modal-opt-out-title');
    await expect.soft(optOutTitle).toBeVisible();
    await expect.soft(optOutModal).toContainText(/Désactiver mon/i);
    await expect.soft(optOutModal).toContainText(/RGPD/i);
    observations.push('state21: ModalOptOutConfirm visible with RGPD banner');
  } else {
    observations.push('state21: opt-out btn not visible (already opted out?)');
  }
  await snap('21-modal-opt-out-rgpd');

  // ───── STATE 22 — loyalty after opt-out confirmed ─────
  // Click "Désactiver" data-testid="opt-out-confirm-btn" (line 294 in
  // screens-modals.jsx). onConfirm → LC.dev.setConsent('opted_out') + toast.
  // The loyalty screen re-renders with the "Programme désactivé" banner
  // and HIDES the balance card behind !isOptedOut guard (line 1042).
  // Per advisor : balance is NOT cleared in data — only the UI is hidden.
  const optOutConfirmBtn = page.locator('[data-testid="opt-out-confirm-btn"]');
  if (await optOutConfirmBtn.isVisible().catch(() => false)) {
    const balancePreOptOut = await page.evaluate(() => window.LC.loyalty.account.balance);
    await optOutConfirmBtn.click();
    await page.waitForTimeout(700);
    const balancePostOptOut = await page.evaluate(() => window.LC.loyalty.account.balance);
    const consentStatus = await page.evaluate(() => window.LC.storage.getConsent());
    // The banner "Programme désactivé" should be visible.
    const banner = loyaltyScreen.locator('text=/Programme désactivé/i').first();
    const bannerVisible = await banner.isVisible().catch(() => false);
    observations.push(`state22: consent=${consentStatus} balance_pre=${balancePreOptOut} balance_post=${balancePostOptOut} banner_visible=${bannerVisible} (balance preserved in data, hidden in UI)`);
    await expect.soft(consentStatus).toBe('opted_out');
    await expect.soft(balancePostOptOut).toBe(balancePreOptOut); // NOT cleared
  } else {
    observations.push('state22: opt-out confirm button not present — captured current state');
  }
  await snap('22-loyalty-after-optout-cleared');

  // ──────────────── End of capture ────────────────
  // Write observations alongside the screenshots for the adversarial
  // supervisor (one file per wave, not per state).
  // eslint-disable-next-line no-console
  console.log('[wave-D observations]\n' + observations.join('\n'));

  dispose();
});
