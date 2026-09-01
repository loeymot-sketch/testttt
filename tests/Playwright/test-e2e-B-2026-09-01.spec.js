// test-e2e-B-2026-09-01 — Wave B: Voice-assistant route (disabled state) + Assistant entry point.
//
// Scope (reports/test-e2e/voice-order-poc-2026-09-01/AUDIT_PLAN.md §Wave B): the new
// "🎧 Assistant" caisse-toolbar entry point (data-testid pos-voice-assistant-open) and the
// /admin/pos/voice-assistant route it opens. VOICE_ORDER_ENABLED=false in this env, so the
// route must render VoiceOrderAssistantPanel.vue's disabled-safe message — never implying the
// assistant is listening/active — and must never strand the cashier: the manual phone-order
// flow and the normal caisse must both remain fully usable across the round trip.
//
// Wave A (parallel agent, same plan) owns the cash-drawer-modal + phone-order-submit states —
// this spec only opens the drawer as a prerequisite to reach a working caisse, it does not
// re-verify Wave A's phone-order numeric-integrity scenario.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('../e2e/helpers/login');
const { attachMegaAuditRecorder } = require('../e2e/helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.join(__dirname, '..', 'e2e', '__screenshots__', 'test-e2e-B');

// Phrases that would falsely imply the (disabled) assistant is listening/recording/transcribing
// right now. None of these may appear anywhere in the panel while VOICE_ORDER_ENABLED=false.
const FORBIDDEN_ACTIVE_PHRASES = [
  /en\s*cours\s*d[’']?\s*écoute/i,
  /écoute\s*en\s*cours/i,
  /transcription\s+active/i,
  /assistant\s+(actif|activ[ée]|en\s+cours|est\s+en\s+train)/i,
  /enregistrement\s+en\s+cours/i,
  /écoute\s*…/i,
];

// Per AUDIT_PLAN.md Wave B: raw i18n key leak pattern.
const RAW_I18N_KEY_RE = /^[a-z]+\.[a-z_.]+$/;

function linesOf(text) {
  return String(text || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
}

function assertNoUnsafeArtifacts(text, label) {
  for (const rx of FORBIDDEN_ACTIVE_PHRASES) {
    expect(text, `[${label}] must not imply active listening (matched ${rx})`).not.toMatch(rx);
  }
  for (const line of linesOf(text)) {
    expect(line, `[${label}] raw i18n key leak`).not.toMatch(RAW_I18N_KEY_RE);
  }
  expect(text, `[${label}] literal [object Object]`).not.toContain('[object Object]');
  expect(text, `[${label}] literal NaN`).not.toMatch(/\bNaN\b/);
  expect(text, `[${label}] literal undefined`).not.toMatch(/\bundefined\b/);
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function errorLevelTexts(entries) {
  return (entries || [])
    .filter((e) => e.level === 'error' || e.level === 'pageerror')
    .map((e) => e.text);
}

/**
 * Opens the cash-drawer session dialog if the caisse auto-prompts for it (fresh DB / no
 * session yet). Idempotent no-op if a session is already open (e.g. the parallel Wave A run
 * against this same worktree DB already opened it).
 */
async function openCashDrawerIfPrompted(page) {
  const overlay = page.locator('[data-testid="cash-session-overlay"]').first();
  const prompted = await overlay.isVisible({ timeout: 3_000 }).catch(() => false);
  if (!prompted) return;

  const openForm = page.locator('[data-testid="cash-session-open-form"]').first();
  const formVisible = await openForm.isVisible({ timeout: 5_000 }).catch(() => false);
  if (!formVisible) return; // e.g. dialog opened straight to the "active session" view

  const openingInput = page.locator('[data-testid="cash-session-opening-input"]').first();
  if (await openingInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
    const current = await openingInput.inputValue().catch(() => '');
    if (!current || Number(current) <= 0) {
      await openingInput.fill('50');
    }
  }
  const submitBtn = page.locator('[data-testid="cash-session-open-submit"]').first();
  await expect(submitBtn).toBeEnabled({ timeout: 5_000 });
  await submitBtn.click();

  // Submitting does not close the dialog — it flips to the "active session" view (mode
  // 'active') in the SAME overlay. Dismiss it explicitly via the header close (✕) button.
  const activeView = page.locator('[data-testid="cash-session-active-view"]').first();
  await expect(activeView).toBeVisible({ timeout: 10_000 });
  const closeBtn = page.locator('[data-testid="cash-session-close"]').first();
  await closeBtn.click();
  await expect(overlay).toBeHidden({ timeout: 10_000 });
}

test.describe('test-e2e-B-2026-09-01 — Voice-assistant disabled state + entry point', () => {
  test('disabled panel is safe/honest and the caisse survives the round trip', async ({ page }) => {
    const { snap } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);

    // ---------------------------------------------------------------
    // State 1 — caisse toolbar shows the Assistant entry point
    // ---------------------------------------------------------------
    await loginAsPosOperator(page);
    await openCashDrawerIfPrompted(page);

    const assistantButton = page.locator('[data-testid="pos-voice-assistant-open"]');
    await expect(assistantButton).toBeVisible({ timeout: 15_000 });
    await expect(assistantButton).toContainText('Assistant');
    await expect(assistantButton).toHaveAttribute('aria-label', 'Assistant commandes téléphone');

    await snap('01-caisse-toolbar-assistant-button');
    const baselineErrors = errorLevelTexts(
      readJson(path.join(SCREENSHOT_DIR, '01-caisse-toolbar-assistant-button.console.json')),
    );

    // ---------------------------------------------------------------
    // State 2 — the real entry point reaches the disabled-safe panel
    // ---------------------------------------------------------------
    await assistantButton.click();
    await expect(page).toHaveURL(/\/admin\/pos\/voice-assistant(?:[?#]|$)/, { timeout: 15_000 });

    const panel = page.locator('[data-testid="voice-order-assistant"]');
    await expect(panel).toBeVisible({ timeout: 15_000 });

    const disabledBlock = panel.locator('.voice-assistant__disabled');
    // This assertion also proves the transient default (`enabled: true` in the component's
    // own data() before the first /admin/voice-order/snapshot response lands) resolves to the
    // safe disabled state — Playwright auto-retries past that flash.
    await expect(disabledBlock).toBeVisible({ timeout: 15_000 });
    await expect(disabledBlock).toHaveAttribute('role', 'status');
    await expect(disabledBlock.locator('strong')).toHaveText('Assistant désactivé en sécurité.');
    await expect(disabledBlock).toContainText(
      'Assistant téléphonique désactivé. La commande téléphone manuelle reste disponible.',
    );
    await expect(disabledBlock).toContainText(
      'La commande téléphone manuelle de la caisse reste entièrement utilisable.',
    );

    // Health chip must not claim the assistant is synchronised/live while it is off.
    await expect(panel.locator('.voice-assistant__health')).toHaveText(/Désactivé/);

    // The entire "enabled" subtree (transcript, consent, live-call markers) must not exist
    // in the DOM at all — not just be visually hidden — while the flag is off.
    await expect(panel.locator('.voice-assistant__transcript')).toHaveCount(0);
    await expect(panel.locator('.voice-assistant__consent')).toHaveCount(0);
    await expect(panel.locator('.voice-call__status--transcribing')).toHaveCount(0);
    await expect(panel.locator('.voice-assistant__grid')).toHaveCount(0);

    const panelText = await panel.innerText();
    assertNoUnsafeArtifacts(panelText, 'state-02-disabled-panel');

    await snap('02-voice-assistant-disabled-state');

    // ---------------------------------------------------------------
    // State 3 — round trip back to a fully functional caisse
    // ---------------------------------------------------------------
    const posQuickLink = page.locator('a[aria-label="POS"]:visible').first();
    await expect(posQuickLink).toBeVisible({ timeout: 10_000 });
    await posQuickLink.click();
    await expect(page).toHaveURL(/\/admin\/pos$/, { timeout: 15_000 });
    await expect(page.locator('[data-testid="voice-order-assistant"]')).toHaveCount(0);

    const grid = page.locator('[data-testid="pos-category-grid"]');
    await expect(grid).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('[data-testid="pos-category-tile"]').first()).toBeVisible({ timeout: 10_000 });

    await snap('03-voice-assistant-back-to-caisse');
    const roundTripErrors = errorLevelTexts(
      readJson(path.join(SCREENSHOT_DIR, '03-voice-assistant-back-to-caisse.console.json')),
    );
    const newErrors = roundTripErrors.filter((text) => !baselineErrors.includes(text));
    expect(newErrors, `round trip introduced new console errors: ${JSON.stringify(newErrors)}`).toHaveLength(0);

    // ---------------------------------------------------------------
    // State 4 — same disabled route at POS tablet viewport (1024x600)
    // ---------------------------------------------------------------
    await page.setViewportSize({ width: 1024, height: 600 });
    await page.goto('/admin/pos/voice-assistant', { waitUntil: 'domcontentloaded' });

    const panel2 = page.locator('[data-testid="voice-order-assistant"]');
    await expect(panel2).toBeVisible({ timeout: 15_000 });
    const disabledBlock2 = panel2.locator('.voice-assistant__disabled');
    await expect(disabledBlock2).toBeVisible({ timeout: 15_000 });
    await expect(disabledBlock2).toContainText('Assistant désactivé en sécurité.');
    await expect(disabledBlock2).toContainText(
      'Assistant téléphonique désactivé. La commande téléphone manuelle reste disponible.',
    );

    const overflowPx = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflowPx, 'no horizontal overflow at 1024x600').toBeLessThanOrEqual(2);

    const panelText2 = await panel2.innerText();
    assertNoUnsafeArtifacts(panelText2, 'state-04-tablet-disabled-panel');

    await snap('04-tablet-viewport-1024x600');
  });
});
