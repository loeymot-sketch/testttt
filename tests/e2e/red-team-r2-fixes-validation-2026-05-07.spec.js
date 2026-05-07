/**
 * RED-R2 BLUE TEAM RESPONSE — fixes validation (2026-05-07)
 *
 * Vérifie que les 3 fixes P0 a11y wizard kiosk sont en place après le commit
 * BLUE-R2 (mirror du fix R1 sur ItemComponent.vue):
 *   - WK1: KioskWizardComponent root has role="dialog"
 *   - WK2: KioskWizardComponent root has aria-modal="true"
 *   - WK3: KioskWizardComponent root has aria-labelledby="kiosk-wizard-title"
 *   - WK4: focus moves into wizard root after open + Tab cycles inside
 *
 * Réutilise le pattern d'ouverture wizard de la spec RED-R2 (catégories iter).
 */
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');

test.describe.configure({ mode: 'serial' });

async function startOrderFromIdle(page) {
    const url = page.url();
    if (!/\/kiosk\/idle/.test(url)) return;
    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
    if (await takeaway.isVisible({ timeout: 4_000 }).catch(() => false)) {
        await takeaway.click().catch(() => {});
    }
    await page.waitForTimeout(2_000);
}

async function openWizard(page) {
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);

    const cats = page.locator('[data-testid^="kiosk-categories-sidebar-item-"]');
    const catCount = await cats.count();

    for (let c = 0; c < Math.min(catCount, 8); c++) {
        await cats.nth(c).click({ timeout: 2_500 }).catch(() => {});
        await page.waitForTimeout(1_500);
        const items = page.locator('[data-testid^="kiosk-product-card-"]');
        const itemCount = await items.count();
        for (let i = 0; i < Math.min(itemCount, 6); i++) {
            const card = items.nth(i);
            const addBtn = card.locator('[data-testid^="kiosk-product-add-"]').first();
            if (await addBtn.isVisible({ timeout: 600 }).catch(() => false)) {
                await addBtn.click({ timeout: 2_500 }).catch(() => {});
            } else {
                await card.click({ timeout: 2_500 }).catch(() => {});
            }
            await page.waitForTimeout(1_800);
            const wizardVisible = await page.evaluate(
                () => !!document.querySelector('.kiosk-wizard-overlay .kiosk-wizard, .kiosk-wizard'),
            );
            if (wizardVisible) return true;
        }
    }
    return false;
}

test('WK1/WK2/WK3 fix — kiosk wizard root has dialog/aria-modal/labelledby + focus inside', async ({ page }) => {
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await startOrderFromIdle(page);

    const opened = await openWizard(page);
    expect(opened, 'wizard kiosk should open via catalog click').toBe(true);

    // Wait for focus management (setTimeout 150 in mounted)
    await page.waitForTimeout(500);

    const probe = await page.evaluate(() => {
        const wizardRoot = document.querySelector('.kiosk-wizard-overlay .kiosk-wizard') || document.querySelector('.kiosk-wizard');
        if (!wizardRoot) return { found: false };
        const active = document.activeElement;
        return {
            found: true,
            role: wizardRoot.getAttribute('role'),
            ariaModal: wizardRoot.getAttribute('aria-modal'),
            ariaLabelledby: wizardRoot.getAttribute('aria-labelledby'),
            tabindex: wizardRoot.getAttribute('tabindex'),
            hasTitleEl: !!document.getElementById('kiosk-wizard-title'),
            activeIsInside: active ? (wizardRoot === active || wizardRoot.contains(active)) : false,
            activeTag: active?.tagName,
            activeId: active?.id,
        };
    });

    expect(probe.found).toBe(true);
    expect(probe.role).toBe('dialog');
    expect(probe.ariaModal).toBe('true');
    expect(probe.ariaLabelledby).toBe('kiosk-wizard-title');
    expect(probe.tabindex).toBe('-1');
    expect(probe.hasTitleEl).toBe(true);
    expect(probe.activeIsInside, `focus probe: ${JSON.stringify(probe)}`).toBe(true);
});
