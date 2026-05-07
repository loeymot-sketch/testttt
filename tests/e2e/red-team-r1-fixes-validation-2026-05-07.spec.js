/**
 * RED-R1 BLUE TEAM RESPONSE — fixes validation (2026-05-07)
 *
 * Vérifie que les 2 fixes appliqués post-RED-R1 sont en place:
 *   - L1: LoginComponent password autocomplete="current-password" (was "off")
 *   - W1/W2/W3: ItemComponent wizard modal a role="dialog" + aria-modal="true"
 *               + focus auto-positionné dans le wizard à l'ouverture
 *
 * NOTE: ce spec NE manipule PAS le wizard interaction — c'est un fix-witness
 * minimaliste. Les comportements UX complets seront re-validés en R3 (live).
 */
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');

const POS_BASE = 'http://localhost:8000';

test.describe.configure({ mode: 'serial' });

test('L1 fix — login password has autocomplete=current-password', async ({ page }) => {
    await page.goto(`${POS_BASE}/login`);
    await page.waitForLoadState('domcontentloaded');
    const password = page.locator('input[type="password"]').first();
    await expect(password).toHaveAttribute('autocomplete', 'current-password');
});

test('W1/W2/W3 fix — wizard modal has dialog/aria-modal/labelledby + focus inside', async ({ page }) => {
    await loginAsPosOperator(page);

    await page.goto(`${POS_BASE}/admin/pos`);
    await page.waitForLoadState('networkidle');

    // open wizard via 1st product tile (selector pattern from RED-R1 spec)
    const firstTile = page.locator('.pos-v5-grid .pos-v5-grid__item, .pos-v4-grid .pos-v4-tile, .pos-v5-grid > *').first();
    await firstTile.waitFor({ state: 'visible', timeout: 15000 });
    await firstTile.click();

    // Wait for active wizard
    const modal = page.locator('#item-variation-modal');
    await modal.waitFor({ state: 'attached', timeout: 5000 });
    await page.waitForFunction(
        () => document.querySelector('#item-variation-modal.active') !== null,
        { timeout: 5000 },
    );

    // ASSERT static a11y attributes
    await expect(modal).toHaveAttribute('role', 'dialog');
    await expect(modal).toHaveAttribute('aria-modal', 'true');
    await expect(modal).toHaveAttribute('aria-labelledby', 'item-variation-modal-title');

    // ASSERT title element has the matching id (attached, not necessarily visible
    // since the modal opens with CSS transition)
    await expect(page.locator('#item-variation-modal-title')).toHaveCount(1);

    // ASSERT focus is inside wizard (or on the wizard itself which has tabindex="-1")
    // Wait briefly for nextTick + rAF focus management to settle
    await page.waitForTimeout(800);
    const focusState = await page.evaluate(() => {
        const modal = document.querySelector('#item-variation-modal');
        const active = document.activeElement;
        return {
            modalFound: !!modal,
            modalHasTabindex: modal?.getAttribute('tabindex'),
            activeTag: active?.tagName,
            activeId: active?.id,
            activeClass: active?.className,
            activeIsInside: modal && active ? (modal === active || modal.contains(active)) : false,
        };
    });
    expect(focusState.activeIsInside, `focus state: ${JSON.stringify(focusState)}`).toBe(true);
});
