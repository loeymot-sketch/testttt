// test-e2e-mobile-design-full-wave-D — Wave D (design-full family, round 1)
// Mobile profile + Loyalty multi-sections + WizardRedeem 3-step + ALL loyalty modals.
//
// Fork from : tests/e2e/audit-mobile-wave-D-2026-05-11.spec.js
// Capture target dir : tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-D/
//
// 18 states per Wave-D brief 2026-05-11 :
//   01-screen-profile
//   02-screen-loyalty-hero-qr
//   03-screen-loyalty-hero-barcode-toggle
//   04-screen-loyalty-points-section
//   05-screen-loyalty-actions-rapides (Apple + Google Wallet buttons)
//   06-screen-loyalty-plastic-card-section
//   07-screen-loyalty-tab-mes-points
//   08-screen-loyalty-tab-recompenses
//   09-screen-loyalty-tab-historique
//   10-screen-loyalty-infos-rgpd-section
//   11-wizard-redeem-step1-preview
//   12-wizard-redeem-step2-timing
//   13-wizard-redeem-step3-success
//   14-modal-card-link
//   15-modal-wallet-apple-v0-notice
//   16-modal-wallet-google-v0-notice
//   17-modal-opt-out-confirm
//   18-modal-points-gain-confetti
//
// Bootstrap : waitForLoyaltyReady({ seedAccount: { balance: 347 } }) primes
// LC.storage + LC.loyalty + LC.dev BEFORE first navigation so we land on home
// past splash/onb/login/OTP.
//
// Capture-first discipline : every assertion uses expect.soft so a single
// failure does NOT abort the remaining captures. The deliverable is the 4-file
// quartet per state (PNG + DOM + console + network) consumed by the adversarial
// supervisor — not a green expect graph.
//
// Source-code findings surfaced as observations (NOT asserted) :
//   • ModalCardLink wrapper lacks role=dialog / aria-modal=true (bare ModalShell)
//   • ModalPointsGain is a custom overlay (no role=dialog wrapper)
//   • ESC keypress does NOT close any modal — no global keydown listener
//   • LC.dev.redeemReward ignores opts.idempotency_key (idempotency-replay gap)
//
// Critical assertions :
//   • PARITY-3-bis P0 : profile preview balance === loyalty screen balance === 347
//   • QR payload format == "FK:<loyalty_code>" (NOT "LECAY-LOYALTY-*")
//   • Rewards tiers [100, 250, 500, 1000, 2000] visible on Mes Points tab
//   • WizardRedeem step transitions via redeem-next-btn / redeem-apply-now-btn / redeem-close-btn
//   • RGPD opt-out sets LC.storage.getConsent() === 'opted_out'

const { test, expect } = require('@playwright/test');
const path = require('path');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { waitForLoyaltyReady } = require('../mobile-e2e/utils/waitForLoyaltyReady');

const SCREEN_DIR = path.join(__dirname, '__screenshots__/test-e2e-mobile-design-full-wave-D');
const MOBILE_URL = 'http://127.0.0.1:8081/index.html';

test.use({
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
  userAgent:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
});

test('wave D design-full — profile + loyalty sections + wizard + modals', async ({ page }) => {
  test.setTimeout(300_000);
  const { snap, dispose } = attachMegaAuditRecorder(page, SCREEN_DIR);
  const observations = [];

  // ─────────────────────── Bootstrap via waitForLoyaltyReady ───────────────────────
  // The helper goes to /index.html, primes LC.storage (auth + onboarding + consent
  // 'opted_in') and seeds account.balance=347, then reloads so the boot reads
  // the seeded state and lands on home.
  await waitForLoyaltyReady(page, { seedAccount: { balance: 347 } });

  // The helper navigates relative to baseURL=playwright config. Our mobile-e2e
  // config sets baseURL=http://127.0.0.1:8081 already — but this test is
  // launched with a single file argument, not via the testMatch glob, so the
  // resolved baseURL is correct. Verify we're past splash :
  await page.waitForSelector('[data-screen-label]', { timeout: 15_000 });
  await page.waitForTimeout(500);

  const initialScreenLabel = await page.locator('[data-screen-label]').first().getAttribute('data-screen-label').catch(() => '?');
  observations.push(`bootstrap: screen=${initialScreenLabel} (expect "07 Home")`);

  const tabBar = page.locator('.lc-tabs');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 01 — PROFILE SCREEN (user card + loyalty preview + menu rows + logout)
  // ═══════════════════════════════════════════════════════════════════════
  await tabBar.locator('.lc-tab').filter({ hasText: /^Profil$/ }).click();
  await page.waitForSelector('[data-screen-label="13 Profile"]', { timeout: 5_000 });
  await page.waitForTimeout(400);
  const profileScreen = page.locator('[data-screen-label="13 Profile"]');
  await expect.soft(profileScreen).toBeVisible();
  // User card binding
  await expect.soft(profileScreen).toContainText(/Ikyes/);
  await expect.soft(profileScreen).toContainText(/\+33 6 42 79 98 84/);
  // Logout button rendered
  const logoutBtn = profileScreen.locator('button').filter({ hasText: /Se déconnecter/i });
  await expect.soft(logoutBtn).toBeVisible();
  // Menu rows — verify all 7 labels are rendered in the DOM (no need to scroll yet).
  await expect.soft(profileScreen).toContainText(/Ma carte fidélité/);
  await expect.soft(profileScreen).toContainText(/Moyens de paiement/);
  await expect.soft(profileScreen).toContainText(/Notifications/);
  await expect.soft(profileScreen).toContainText(/Allergènes/);
  await expect.soft(profileScreen).toContainText(/Langue/);
  await expect.soft(profileScreen).toContainText(/Nous contacter/);
  await expect.soft(profileScreen).toContainText(/CGU/);

  // PARITY P0 — capture profile preview balance from data layer + DOM
  const profileBalanceData = await page.evaluate(() => window.LC.loyalty.account.balance);
  const profilePreviewText = await profileScreen.locator('.lc-display').filter({ hasText: /PTS/ }).first().innerText().catch(() => '');
  observations.push(`state01 PROFILE: data_balance=${profileBalanceData} preview_card_text="${profilePreviewText.replace(/\s+/g, ' ').trim()}"`);
  await expect.soft(profileBalanceData).toBe(347);
  await snap('01-screen-profile');

  // Navigate to loyalty via the preview card row.
  await profileScreen.locator('text=/Ma carte fidélité/').first().click();
  await page.waitForSelector('[data-screen-label="14 Loyalty"]', { timeout: 5_000 });
  // Wait long enough for the async QR generation (crypto.subtle.digest in
  // useLoyaltyQR hook) to settle.
  await page.waitForTimeout(900);

  const loyaltyScreen = page.locator('[data-screen-label="14 Loyalty"]');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 02 — LOYALTY HERO QR (default mode)
  // ═══════════════════════════════════════════════════════════════════════
  // Top of screen — viewport already scrolled to top after route change.
  await page.evaluate(() => { const s = document.querySelector('.lc-screen'); if (s) s.scrollTop = 0; });
  await page.waitForTimeout(200);
  await expect.soft(loyaltyScreen).toBeVisible();

  // PARITY-3-bis P0 cross-section invariant
  const loyaltyBalanceData = await page.evaluate(() => window.LC.loyalty.account.balance);
  const loyaltyBalanceTextEl = loyaltyScreen.locator('[data-testid="loyalty-balance"]').first();
  const loyaltyBalanceText = await loyaltyBalanceTextEl.innerText().catch(() => '');
  observations.push(`PARITY-3-bis: profile_balance=${profileBalanceData} loyalty_balance=${loyaltyBalanceData} ui_balance="${loyaltyBalanceText.trim()}" match=${profileBalanceData === loyaltyBalanceData}`);
  await expect.soft(loyaltyBalanceData).toBe(profileBalanceData);
  await expect.soft(loyaltyBalanceData).toBe(347);

  // QR rendered in default mode (mode='qr')
  const qrEl = loyaltyScreen.locator('[data-testid="loyalty-qr"]').first();
  await expect.soft(qrEl).toBeVisible();
  const qrPayload = await qrEl.getAttribute('data-payload').catch(() => null);
  const isFKFormat = !!qrPayload && /^FK:[A-Z0-9]{4,}$/i.test(qrPayload);
  const isDeprecatedFormat = !!qrPayload && /^LECAY-LOYALTY-/i.test(qrPayload);
  await expect.soft(isFKFormat, `QR payload must be FK:<code> format, got: ${qrPayload}`).toBe(true);
  await expect.soft(isDeprecatedFormat, `QR payload must NOT be deprecated LECAY-LOYALTY format, got: ${qrPayload}`).toBe(false);
  observations.push(`state02 HERO_QR: payload="${qrPayload}" is_FK_format=${isFKFormat} is_deprecated=${isDeprecatedFormat}`);
  await snap('02-screen-loyalty-hero-qr');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 03 — LOYALTY HERO BARCODE (after toggle)
  // ═══════════════════════════════════════════════════════════════════════
  // Toggle button data-testid="qr-mode-toggle"
  await loyaltyScreen.locator('[data-testid="qr-mode-toggle"]').click();
  await page.waitForTimeout(500);
  // Verify storage persistence (storage.setQRPreference is called).
  const qrPreferenceAfterToggle = await page.evaluate(() => window.LC.storage.getQRPreference ? window.LC.storage.getQRPreference() : null);
  const qrPayloadAfterToggle = await qrEl.getAttribute('data-payload').catch(() => null);
  observations.push(`state03 HERO_BARCODE: storage_pref=${qrPreferenceAfterToggle} payload_still_FK="${qrPayloadAfterToggle}"`);
  await snap('03-screen-loyalty-hero-barcode-toggle');

  // QR storage persist check — reload + verify mode persists.
  // Brief P0 : "QR persist via storage (refresh keeps QR)"  — but we toggled
  // to barcode, so after reload we should still be on barcode. Capture as
  // observation only (don't reload mid-capture; reload would lose mid-state).
  // Inspect the stored preference value to surface the binding.

  // Toggle back to QR for cleanliness in subsequent states.
  await loyaltyScreen.locator('[data-testid="qr-mode-toggle"]').click();
  await page.waitForTimeout(300);

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 04 — LOYALTY POINTS SECTION (black balance card)
  // ═══════════════════════════════════════════════════════════════════════
  // The points section is below the HERO. Anchor = [data-testid="loyalty-balance"].
  await loyaltyScreen.locator('[data-testid="loyalty-balance"]').first().scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(300);
  // Verify discount-value text rendered ("X,XX € de réduction disponible")
  const pointsSectionText = await loyaltyScreen.innerText().catch(() => '');
  const hasDiscountText = /de réduction disponible/i.test(pointsSectionText);
  const hasProgressText = /pts.*pour/i.test(pointsSectionText) || /Plus que/i.test(pointsSectionText);
  observations.push(`state04 POINTS_SECTION: has_discount_text=${hasDiscountText} has_progress_label=${hasProgressText}`);
  await expect.soft(loyaltyScreen).toContainText(/de réduction disponible/i);
  await snap('04-screen-loyalty-points-section');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 05 — ACTIONS RAPIDES (Apple Wallet + Google Wallet + plastic card pills)
  // ═══════════════════════════════════════════════════════════════════════
  const walletAppleBtn = loyaltyScreen.locator('[data-testid="wallet-apple-btn"]');
  const walletGoogleBtn = loyaltyScreen.locator('[data-testid="wallet-google-btn"]');
  await walletAppleBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(300);
  await expect.soft(walletAppleBtn).toBeVisible();
  await expect.soft(walletGoogleBtn).toBeVisible();
  await expect.soft(walletAppleBtn).toHaveAttribute('aria-label', /Apple Wallet/i);
  await expect.soft(walletGoogleBtn).toHaveAttribute('aria-label', /Google Wallet/i);
  observations.push('state05 ACTIONS_RAPIDES: wallet-apple-btn + wallet-google-btn visible with ARIA labels');
  await snap('05-screen-loyalty-actions-rapides');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 06 — PLASTIC CARD SECTION (link-plastic-card-btn)
  // ═══════════════════════════════════════════════════════════════════════
  const linkPlasticBtn = loyaltyScreen.locator('[data-testid="link-plastic-card-btn"]');
  await linkPlasticBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(300);
  await expect.soft(linkPlasticBtn).toBeVisible();
  const plasticCardText = await linkPlasticBtn.innerText().catch(() => '');
  observations.push(`state06 PLASTIC_CARD: button_text="${plasticCardText.trim()}" (state: not-linked default)`);
  await snap('06-screen-loyalty-plastic-card-section');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 07 — LOYALTY TAB "MES POINTS" (tier progression)
  // ═══════════════════════════════════════════════════════════════════════
  // 'points' is the default tab (line 872 in screens-main.jsx). Click it
  // explicitly to ensure aria-selected=true.
  await loyaltyScreen.locator('[data-testid="loyalty-tab-points"]').click();
  await page.waitForTimeout(400);
  // Scroll the tab into view for capture.
  await loyaltyScreen.locator('[data-testid="loyalty-tab-points"]').scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  const pointsTab = loyaltyScreen.locator('[data-testid="loyalty-tab-points"]');
  await expect.soft(pointsTab).toHaveAttribute('aria-selected', 'true');
  // Brief assertion : Rewards tiers visible [100, 250, 500, 1000, 2000]
  // (these are config.tiers — rendered as reward-row-{tier}).
  const tierIds = [100, 250, 500, 1000, 2000];
  const tierStatuses = {};
  for (const tier of tierIds) {
    const row = loyaltyScreen.locator(`[data-testid="reward-row-${tier}"]`).first();
    const visible = await row.isVisible().catch(() => false);
    tierStatuses[tier] = visible;
    await expect.soft(row, `tier ${tier} must be present on Mes Points tab`).toBeVisible();
  }
  observations.push(`state07 TAB_MES_POINTS: tiers_visible=${JSON.stringify(tierStatuses)} (expected all true for balance 347)`);
  // Confirm unlocked tier 100 has UTILISER button (greens)
  const utiliserBtn = loyaltyScreen.locator('[data-testid="reward-redeem-btn-100"]').first();
  await expect.soft(utiliserBtn).toBeVisible();
  await snap('07-screen-loyalty-tab-mes-points');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 08 — LOYALTY TAB "RÉCOMPENSES" (catalog, 8 rewards)
  // ═══════════════════════════════════════════════════════════════════════
  await loyaltyScreen.locator('[data-testid="loyalty-tab-rewards"]').click();
  await page.waitForTimeout(400);
  await loyaltyScreen.locator('[data-testid="loyalty-tab-rewards"]').scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  const rewardRows = loyaltyScreen.locator('[data-testid^="reward-row-"]');
  const rewardCount = await rewardRows.count();
  observations.push(`state08 TAB_RECOMPENSES: rewards_rendered=${rewardCount} (data.rewards=8)`);
  await expect.soft(rewardCount).toBeGreaterThanOrEqual(8);
  // Ensure reward-row-1 is present (we'll redeem it in state 11-13).
  await expect.soft(loyaltyScreen.locator('[data-testid="reward-row-1"]').first()).toBeVisible();
  await snap('08-screen-loyalty-tab-recompenses');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 09 — LOYALTY TAB "HISTORIQUE"
  // ═══════════════════════════════════════════════════════════════════════
  await loyaltyScreen.locator('[data-testid="loyalty-tab-history"]').click();
  await page.waitForTimeout(400);
  await loyaltyScreen.locator('[data-testid="loyalty-tab-history"]').scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  const historyEntries = loyaltyScreen.locator('[data-testid^="history-entry-"]');
  const historyCount = await historyEntries.count();
  observations.push(`state09 TAB_HISTORIQUE: entries_rendered=${historyCount} (DEFAULT_HISTORY=7)`);
  await expect.soft(historyCount).toBeGreaterThanOrEqual(7);
  // Filter pills present (Tout / App / Kiosk / Caisse)
  await expect.soft(loyaltyScreen.locator('[data-testid="history-filter-all"]')).toBeVisible();
  await expect.soft(loyaltyScreen.locator('[data-testid="history-filter-mobile"]')).toBeVisible();
  await snap('09-screen-loyalty-tab-historique');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 10 — INFOS / RGPD SECTION (rules + opt-out button anchor)
  // ═══════════════════════════════════════════════════════════════════════
  const optOutBtn = loyaltyScreen.locator('[data-testid="loyalty-opt-out-btn"]');
  await optOutBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(300);
  await expect.soft(optOutBtn).toBeVisible();
  // Verify infos block text (config.earn_ratio + redeem_ratio rules visible).
  await expect.soft(loyaltyScreen).toContainText(/pt par € dépensé/i);
  await expect.soft(loyaltyScreen).toContainText(/Validité/i);
  await expect.soft(loyaltyScreen).toContainText(/Désactiver mon compte fidélité/i);
  observations.push('state10 INFOS_RGPD: rules + opt-out button visible at bottom');
  await snap('10-screen-loyalty-infos-rgpd-section');

  // Return to rewards tab + scroll back up for the redeem wizard.
  await loyaltyScreen.locator('[data-testid="loyalty-tab-rewards"]').click();
  await page.waitForTimeout(400);
  await page.evaluate(() => { const s = document.querySelector('.lc-screen'); if (s) s.scrollTop = 0; });
  await page.waitForTimeout(200);

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 11 — WIZARD REDEEM STEP 1 (preview)
  // ═══════════════════════════════════════════════════════════════════════
  // Reward id=1 = "Frites M offertes" cost=100, balance=347 → unlocked.
  // Record balance for the wizard math + idempotency check.
  const balanceBeforeRedeem = await page.evaluate(() => window.LC.loyalty.account.balance);
  // Scroll to reward-row-1, then click reward-redeem-btn-1.
  await loyaltyScreen.locator('[data-testid="reward-row-1"]').first().scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  const redeemBtn1 = loyaltyScreen.locator('[data-testid="reward-redeem-btn-1"]').first();
  await redeemBtn1.click();
  // Wizard mounts as data-modal-kind="redeem-wizard" wrapper + data-testid="redeem-wizard" inner.
  await page.waitForSelector('[data-testid="redeem-wizard"]', { timeout: 5_000 });
  await page.waitForTimeout(400);
  const wizard = page.locator('[data-testid="redeem-wizard"]');
  const wizardWrapper = page.locator('[data-modal-kind="redeem-wizard"]');
  // ARIA verification — wrapper has role=dialog + aria-modal=true (set in WizardRedeem.jsx line 131).
  const wrapperRole = await wizardWrapper.getAttribute('role').catch(() => null);
  const wrapperAriaModal = await wizardWrapper.getAttribute('aria-modal').catch(() => null);
  const wrapperAriaLabelledby = await wizardWrapper.getAttribute('aria-labelledby').catch(() => null);
  observations.push(`state11 WIZARD_STEP1: role=${wrapperRole} aria-modal=${wrapperAriaModal} aria-labelledby=${wrapperAriaLabelledby}`);
  await expect.soft(wizardWrapper).toHaveAttribute('role', 'dialog');
  await expect.soft(wizardWrapper).toHaveAttribute('aria-modal', 'true');
  await expect.soft(wizard).toHaveAttribute('data-step', '1');
  await expect.soft(wizard).toContainText(/Confirmer.*l'échange/i);
  await expect.soft(wizard).toContainText(/Solde avant/i);
  await expect.soft(wizard).toContainText(/Solde après/i);
  // Test ESC close → record whether modal stays open (no global keydown listener in src).
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
  const wizardStillVisibleAfterEsc = await wizard.isVisible().catch(() => false);
  observations.push(`state11 ESC_BEHAVIOR: wizard_still_visible_after_ESC=${wizardStillVisibleAfterEsc} (source: no document-level ESC handler — FINDING)`);
  observations.push(`state11 WIZARD_STEP1: balance_before_redeem=${balanceBeforeRedeem}`);
  await snap('11-wizard-redeem-step1-preview');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 12 — WIZARD REDEEM STEP 2 (timing choice)
  // ═══════════════════════════════════════════════════════════════════════
  await wizard.locator('[data-testid="redeem-next-btn"]').click();
  await page.waitForTimeout(400);
  await expect.soft(wizard).toHaveAttribute('data-step', '2');
  await expect.soft(wizard).toContainText(/Quand utiliser/i);
  await expect.soft(wizard.locator('[data-testid="redeem-apply-now-btn"]')).toBeVisible();
  await expect.soft(wizard.locator('[data-testid="redeem-save-later-btn"]')).toBeVisible();
  // Capture expected idempotency key (held in ref — not rendered, but reproducible).
  const expectedIdempotencyKey = await page.evaluate(() => {
    try {
      return window.LC.loyaltyRewardState
        ? window.LC.loyaltyRewardState.redemptionIdempotencyKey(window.LC.loyalty.account.user_id, 1)
        : null;
    } catch (_) { return null; }
  });
  observations.push(`state12 WIZARD_STEP2: timing choice rendered, expected_idempotency_key=${expectedIdempotencyKey}`);
  await snap('12-wizard-redeem-step2-timing');

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 13 — WIZARD REDEEM STEP 3 (success + code)
  // ═══════════════════════════════════════════════════════════════════════
  await wizard.locator('[data-testid="redeem-apply-now-btn"]').click();
  await page.waitForSelector('[data-testid="redeem-success"]', { timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(500);
  await expect.soft(wizard).toHaveAttribute('data-step', '3');
  // Balance should be 347 - 100 = 247.
  const balanceAfterRedeem = await page.evaluate(() => window.LC.loyalty.account.balance);
  await expect.soft(balanceAfterRedeem).toBe(balanceBeforeRedeem - 100);
  const successCodeEl = wizard.locator('[data-testid="redeem-success-code"]');
  const successCodeText = await successCodeEl.innerText().catch(() => '');
  await expect.soft(successCodeText).toMatch(/^LCY-/);
  // Verify close + share buttons rendered
  await expect.soft(wizard.locator('[data-testid="redeem-close-btn"]')).toBeVisible();
  await expect.soft(wizard.locator('[data-testid="redeem-share-btn"]')).toBeVisible();
  observations.push(`state13 WIZARD_STEP3: balance_after=${balanceAfterRedeem} (expected=${balanceBeforeRedeem - 100}) success_code="${successCodeText.trim()}"`);

  // IDEMPOTENCY check (logged as observation per advisor — known V0 gap).
  // Brief : "WizardRedeem 3-step idempotency (re-trigger reward = same code via
  // fenêtre 10min)". Source : LC.dev.redeemReward IGNORES opts.idempotency_key,
  // so V0 will double-debit. Record the expected key for adversarial review.
  observations.push(`state13 IDEMPOTENCY_OBSERVATION: success_code uses key="${expectedIdempotencyKey}"; re-redeem within 10min should reuse same key BUT V0 redeemReward ignores opts.idempotency_key (KNOWN GAP)`);
  await snap('13-wizard-redeem-step3-success');

  // Close wizard.
  await wizard.locator('[data-testid="redeem-close-btn"]').click().catch(() => {});
  await page.waitForSelector('[data-testid="redeem-wizard"]', { state: 'hidden', timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(400);

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 14 — MODAL CARD LINK (camera prompt mock)
  // ═══════════════════════════════════════════════════════════════════════
  // Trigger via link-plastic-card-btn (already scrolled in state 6, but the
  // wizard may have scrolled the screen; scroll again).
  const linkBtn = loyaltyScreen.locator('[data-testid="link-plastic-card-btn"]');
  await linkBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  await linkBtn.click();
  await page.waitForTimeout(500);
  // ModalCardLink wrapper = bare ModalShell — NO role=dialog / aria-modal on outer.
  // Verify by text content + button. SOURCE FINDING : missing dialog semantics.
  const cardLinkVisible = await page.locator('text=/Lier ta carte/i').first().isVisible().catch(() => false);
  const cardLinkScanBtnVisible = await page.locator('button').filter({ hasText: /Activer l'appareil photo/i }).first().isVisible().catch(() => false);
  // Try detecting any role=dialog ancestor (there shouldn't be one for ModalCardLink).
  const cardLinkDialog = page.locator('[role="dialog"]').filter({ hasText: /Lier ta carte/i }).first();
  const cardLinkHasDialog = await cardLinkDialog.count() > 0;
  observations.push(`state14 MODAL_CARD_LINK: card_link_title_visible=${cardLinkVisible} scan_btn_visible=${cardLinkScanBtnVisible} has_role_dialog_wrapper=${cardLinkHasDialog} (SOURCE FINDING: ModalCardLink is bare ModalShell — no role=dialog/aria-modal)`);
  await expect.soft(cardLinkVisible).toBe(true);
  // ESC verify — should NOT close (no listener).
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
  const cardLinkAfterEsc = await page.locator('text=/Lier ta carte/i').first().isVisible().catch(() => false);
  observations.push(`state14 ESC_BEHAVIOR: cardlink_visible_after_ESC=${cardLinkAfterEsc} (expected true — no ESC listener in V0)`);
  await snap('14-modal-card-link');
  // Close via "Pas maintenant" text button.
  const pasMaintenantBtn = page.locator('button').filter({ hasText: /Pas maintenant/i }).first();
  if (await pasMaintenantBtn.isVisible().catch(() => false)) {
    await pasMaintenantBtn.click();
  } else {
    // Backdrop fallback : ModalShell backdrop has onClick=onClose.
    await page.mouse.click(20, 50).catch(() => {});
  }
  await page.waitForTimeout(500);

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 15 — MODAL WALLET APPLE V0 NOTICE
  // ═══════════════════════════════════════════════════════════════════════
  await walletAppleBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  await walletAppleBtn.click();
  await page.waitForSelector('[data-modal-kind="wallet-apple"]', { timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(400);
  const walletAppleModal = page.locator('[data-modal-kind="wallet-apple"]');
  // ARIA: wrapper has role=dialog + aria-modal=true (screens-modals.jsx line 254).
  const walletAppleRole = await walletAppleModal.getAttribute('role').catch(() => null);
  const walletAppleAriaModal = await walletAppleModal.getAttribute('aria-modal').catch(() => null);
  observations.push(`state15 MODAL_WALLET_APPLE: role=${walletAppleRole} aria-modal=${walletAppleAriaModal}`);
  await expect.soft(walletAppleModal).toHaveAttribute('role', 'dialog');
  await expect.soft(walletAppleModal).toHaveAttribute('aria-modal', 'true');
  await expect.soft(walletAppleModal).toContainText(/Bientôt/i);
  await expect.soft(walletAppleModal).toContainText(/Apple Wallet/i);
  await expect.soft(walletAppleModal).toContainText(/Pass Type ID/i);
  // ESC verify
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
  const walletAppleAfterEsc = await walletAppleModal.isVisible().catch(() => false);
  observations.push(`state15 ESC_BEHAVIOR: wallet_apple_visible_after_ESC=${walletAppleAfterEsc} (no ESC listener)`);
  await snap('15-modal-wallet-apple-v0-notice');
  // Close via "Fermer" button.
  const walletAppleFermer = walletAppleModal.locator('button').filter({ hasText: /^Fermer$/i }).first();
  if (await walletAppleFermer.isVisible().catch(() => false)) {
    await walletAppleFermer.click();
  } else {
    await page.mouse.click(20, 50).catch(() => {});
  }
  await page.waitForTimeout(500);

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 16 — MODAL WALLET GOOGLE V0 NOTICE
  // ═══════════════════════════════════════════════════════════════════════
  await walletGoogleBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  await walletGoogleBtn.click();
  await page.waitForSelector('[data-modal-kind="wallet-google"]', { timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(400);
  const walletGoogleModal = page.locator('[data-modal-kind="wallet-google"]');
  const walletGoogleRole = await walletGoogleModal.getAttribute('role').catch(() => null);
  const walletGoogleAriaModal = await walletGoogleModal.getAttribute('aria-modal').catch(() => null);
  observations.push(`state16 MODAL_WALLET_GOOGLE: role=${walletGoogleRole} aria-modal=${walletGoogleAriaModal}`);
  await expect.soft(walletGoogleModal).toHaveAttribute('role', 'dialog');
  await expect.soft(walletGoogleModal).toHaveAttribute('aria-modal', 'true');
  await expect.soft(walletGoogleModal).toContainText(/Bientôt/i);
  await expect.soft(walletGoogleModal).toContainText(/Google Wallet/i);
  await expect.soft(walletGoogleModal).toContainText(/Issuer ID/i);
  // ESC verify
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
  const walletGoogleAfterEsc = await walletGoogleModal.isVisible().catch(() => false);
  observations.push(`state16 ESC_BEHAVIOR: wallet_google_visible_after_ESC=${walletGoogleAfterEsc} (no ESC listener)`);
  await snap('16-modal-wallet-google-v0-notice');
  const walletGoogleFermer = walletGoogleModal.locator('button').filter({ hasText: /^Fermer$/i }).first();
  if (await walletGoogleFermer.isVisible().catch(() => false)) {
    await walletGoogleFermer.click();
  } else {
    await page.mouse.click(20, 50).catch(() => {});
  }
  await page.waitForTimeout(500);

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 17 — MODAL OPT-OUT CONFIRM (RGPD)
  // ═══════════════════════════════════════════════════════════════════════
  // Scroll to opt-out btn (at bottom of loyalty screen) + click.
  await optOutBtn.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(200);
  await optOutBtn.click();
  await page.waitForSelector('[data-modal-kind="opt-out-confirm"]', { timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(400);
  const optOutModal = page.locator('[data-modal-kind="opt-out-confirm"]');
  const optOutRole = await optOutModal.getAttribute('role').catch(() => null);
  const optOutAriaModal = await optOutModal.getAttribute('aria-modal').catch(() => null);
  const optOutAriaLabelledby = await optOutModal.getAttribute('aria-labelledby').catch(() => null);
  observations.push(`state17 MODAL_OPT_OUT: role=${optOutRole} aria-modal=${optOutAriaModal} aria-labelledby=${optOutAriaLabelledby}`);
  await expect.soft(optOutModal).toHaveAttribute('role', 'dialog');
  await expect.soft(optOutModal).toHaveAttribute('aria-modal', 'true');
  // The outer [data-modal-kind="opt-out-confirm"] wrapper has zero intrinsic size
  // (descendants render via ModalShell) — verify via title visibility, not wrapper.
  await expect.soft(page.locator('#modal-opt-out-title')).toBeVisible();
  await expect.soft(optOutModal).toContainText(/Désactiver mon/i);
  await expect.soft(optOutModal).toContainText(/RGPD/i);
  // ESC verify
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
  const optOutAfterEsc = await optOutModal.isVisible().catch(() => false);
  observations.push(`state17 ESC_BEHAVIOR: opt_out_visible_after_ESC=${optOutAfterEsc} (no ESC listener)`);
  await snap('17-modal-opt-out-confirm');

  // Confirm the opt-out so we can verify the localStorage assertion (brief P0:
  // RGPD opt-out clears localStorage.lecayenne.loyalty_consent='opted_out'.
  // Source : LC.dev.setConsent → LC.storage.setConsent persists 'opted_out').
  const balancePreOptOut = await page.evaluate(() => window.LC.loyalty.account.balance);
  await page.locator('[data-testid="opt-out-confirm-btn"]').click();
  await page.waitForTimeout(700);
  const consentStatus = await page.evaluate(() => window.LC.storage.getConsent());
  const balancePostOptOut = await page.evaluate(() => window.LC.loyalty.account.balance);
  const storedConsentKey = await page.evaluate(() => {
    try {
      // Probe localStorage key by inspecting the namespaced key used by LC.storage.
      // Typical key path is 'lecayenne.loyalty_consent' — capture whatever is set.
      const keys = [];
      for (let i = 0; i < window.localStorage.length; i++) {
        const k = window.localStorage.key(i);
        if (/consent/i.test(k)) keys.push([k, window.localStorage.getItem(k)]);
      }
      return keys;
    } catch (_) { return []; }
  });
  observations.push(`state17 OPT_OUT_CONFIRMED: consent=${consentStatus} balance_pre=${balancePreOptOut} balance_post=${balancePostOptOut} (balance NOT cleared — only UI hidden) localStorage_consent_keys=${JSON.stringify(storedConsentKey)}`);
  await expect.soft(consentStatus).toBe('opted_out');
  await expect.soft(balancePostOptOut).toBe(balancePreOptOut);

  // Re-opt-in for the gain-confetti capture below (otherwise the gain modal
  // still mounts via setTimeout but the loyalty surfaces are gated by
  // isOptedOut; harmless but cleaner to restore).
  await page.evaluate(() => { if (window.LC && window.LC.dev) window.LC.dev.setConsent('opted_in'); });
  await page.waitForTimeout(400);

  // ═══════════════════════════════════════════════════════════════════════
  // STATE 18 — MODAL POINTS GAIN CONFETTI
  // ═══════════════════════════════════════════════════════════════════════
  // ModalPointsGain has NO LC.dev hook + no exposed setModal/go on window.
  // Only trigger : payment-counter flow.
  //   cart → pay modal → "Payer à la caisse" → setTimeout(()=>setModal('gain'), 900)
  // Source : mobile/index.html line 198.
  //
  // Strategy : seed cart via LC.storage.setCart, navigate to cart, click
  // "Payer", click "Payer à la caisse" pill, wait ~1200ms for the gain modal.
  //
  // Seed a single cart item with the minimum fields ScreenCart expects
  // (id, name, price, qty). ScreenCart re-reads from `cart` state — so we
  // can't mutate storage post-mount. Instead navigate via the tab bar to
  // 'menu', click an item, addToCart via the UI, then go to cart.
  //
  // Cheaper path : set cart in storage BEFORE the screen transition, then
  // navigate to cart via go('cart'). But `go` is internal to App. Use
  // window.LC.storage.setCart, then click any tab to remount (cart state
  // re-initializes from storage on mount).
  await page.evaluate(() => {
    try {
      window.LC.storage.setCart([{ id: 1001, name: 'Box test gain', price: 12.50, qty: 1, sups: [] }]);
    } catch (_) { /* noop */ }
  });
  // The App component cart state is `useState(storage.getCart())` (initialized
  // ONCE on mount). To force a re-init we navigate to a different screen and
  // back. Simpler : use a direct route to cart by clicking Accueil tab then
  // clicking 'Menu' → 'Voir mon panier' style. But that needs the menu UI.
  //
  // Cleanest hack : reload the page so the App remounts and reads the freshly
  // set cart. We're past loyalty/wizard captures — losing in-memory state is fine.
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-screen-label]', { timeout: 10_000 });
  await page.waitForTimeout(600);

  // We should be back on home. Mobile app exposes cart via :
  //   • ScreenMenu sticky bar — "Voir le panier" (orange button, only renders
  //     when cart.length > 0) → go('cart') (line 224).
  //   • ScreenCart bottom CTA — "Valider ma commande" → go('confirm') →
  //     index.html cart-mapper routes 'confirm' to go('pay') (line 182).
  //   • ModalPayChoice "Payer à la caisse" → setTimeout(setModal('gain'), 900).
  // ScreenCart label = "10 Cart" (verified screens-main.jsx line 546).
  const tabBarAfterReload = page.locator('.lc-tabs');
  await tabBarAfterReload.locator('.lc-tab').filter({ hasText: /^Menu$/i }).click();
  await page.waitForSelector('[data-screen-label="08 Menu"]', { timeout: 5_000 }).catch(() => {});
  await page.waitForTimeout(700);
  // Click "Voir le panier" sticky bar (orange button at bottom). Use the
  // explicit text — there's only one button with this label on ScreenMenu
  // when cart has 1 item.
  const cartCta = page.locator('button').filter({ hasText: /Voir le panier/i }).first();
  let onCartScreen = false;
  const cartCtaVisible = await cartCta.isVisible().catch(() => false);
  if (cartCtaVisible) {
    await cartCta.click();
    await page.waitForSelector('[data-screen-label="10 Cart"]', { timeout: 5_000 }).catch(() => {});
    onCartScreen = await page.locator('[data-screen-label="10 Cart"]').isVisible().catch(() => false);
  }
  observations.push(`state18 SETUP: cart_cta_visible=${cartCtaVisible} on_cart_screen=${onCartScreen}`);

  let gainModalReached = false;
  if (onCartScreen) {
    // Click "Valider ma commande" → go('confirm') → routed to go('pay') in
    // the cart's mapper (index.html line 182).
    const validateBtn = page.locator('button').filter({ hasText: /Valider ma commande/i }).first();
    const validateBtnVisible = await validateBtn.isVisible().catch(() => false);
    if (validateBtnVisible) {
      await validateBtn.click();
      await page.waitForTimeout(700);
      // Pay modal mounts as ModalPayChoice (no testid). Click "Payer à la
      // caisse" pill (text from ModalPayChoice line 30).
      const payCounterBtn = page.locator('button').filter({ hasText: /Payer à la caisse/i }).first();
      const payCounterVisible = await payCounterBtn.isVisible().catch(() => false);
      observations.push(`state18 PAY_MODAL: validate_clicked=true pay_counter_btn_visible=${payCounterVisible}`);
      if (payCounterVisible) {
        await payCounterBtn.click();
        // Wait for the gain modal (setTimeout 900ms in index.html line 198).
        // Give it ~1500ms buffer for React commit + confetti mount.
        await page.waitForTimeout(1500);
        // ModalPointsGain has no data-testid + no role=dialog. Detect by
        // visible text "points gagnés" + "Voir ma carte" CTA.
        const gainText = await page.locator('text=/points gagnés/i').first().isVisible().catch(() => false);
        const voirCarteBtn = await page.locator('button').filter({ hasText: /Voir ma carte/i }).first().isVisible().catch(() => false);
        gainModalReached = gainText || voirCarteBtn;
      }
    } else {
      observations.push('state18 PAY_MODAL: validate-btn not visible on cart screen');
    }
  }
  observations.push(`state18 MODAL_POINTS_GAIN: reached=${gainModalReached} (V0 SOURCE FINDING: ModalPointsGain has no role=dialog/aria-modal wrapper, no data-testid, no LC.dev trigger hook — must navigate cart→pay→counter)`);
  // ESC verify (won't close — no listener)
  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300);
  const gainAfterEsc = await page.locator('text=/points gagnés/i').first().isVisible().catch(() => false);
  observations.push(`state18 ESC_BEHAVIOR: gain_visible_after_ESC=${gainAfterEsc}`);
  await snap('18-modal-points-gain-confetti');

  // ──────────────── End of capture — write observations ────────────────
  // eslint-disable-next-line no-console
  console.log('[wave-D-design-full observations]\n' + observations.join('\n'));

  dispose();
});
