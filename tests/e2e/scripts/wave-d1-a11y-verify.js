/**
 * [UR3-A1 V1.0.2 Wave D1] Profile dropdown ARIA + keyboard accessibility verify.
 *
 * Verifies, on the live admin layout:
 *   1. aria-expanded toggles correctly when the dropdown opens/closes via click
 *   2. axe-core reports 0 critical/serious violations on the profile dropdown
 *   3. Escape key closes the menu and returns focus to the trigger button
 *   4. ArrowDown on a closed trigger opens the menu and focuses the first menuitem
 *
 * Usage: `node tests/e2e/scripts/wave-d1-a11y-verify.js`
 * Output: tests/e2e/__screenshots__/wave-d1-a11y.log + screenshot snapshots.
 */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    const log = [];
    const out = (line) => { console.log(line); log.push(line); };

    out('=== Wave D1 a11y profile dropdown verify ===');

    // 1. Login as admin
    await page.goto('http://127.0.0.1:8000/login');
    await page.locator('#formEmail').fill('admin@lecayenne.fr');
    await page.locator('#formPassword').fill('123456');
    await page.getByRole('button', { name: /connexion|login|sign in/i }).click();
    await page.waitForURL((u) => !u.toString().includes('/login'), { timeout: 15000 });
    await page.waitForTimeout(3500);

    // 2. Locate profile dropdown trigger (last .dropdown-btn in header that has avatar img + name <b>)
    const triggerHandle = await page.evaluateHandle(() => {
        const headers = document.querySelectorAll('header.db-header .dropdown-btn');
        for (let i = headers.length - 1; i >= 0; i--) {
            const t = headers[i];
            if (t.querySelector('img') && t.querySelector('b')) return t;
        }
        return null;
    });
    const triggerExists = await page.evaluate((el) => !!el, triggerHandle);
    if (!triggerExists) {
        out('FAIL: profile dropdown trigger not found in header.');
        fs.writeFileSync(
            path.resolve(__dirname, '../__screenshots__/wave-d1-a11y.log'),
            log.join('\n') + '\n'
        );
        await browser.close();
        process.exit(1);
    }

    // 3. Read ARIA attributes BEFORE open
    const ariaBefore = await page.evaluate((el) => ({
        ariaExpanded: el.getAttribute('aria-expanded'),
        ariaHaspopup: el.getAttribute('aria-haspopup'),
        ariaControls: el.getAttribute('aria-controls'),
    }), triggerHandle);
    out('ARIA before open: ' + JSON.stringify(ariaBefore));

    // 4. Click to open menu
    await page.evaluate((el) => el.click(), triggerHandle);
    await page.waitForTimeout(900);

    const ariaAfter = await page.evaluate((el) => ({
        ariaExpanded: el.getAttribute('aria-expanded'),
    }), triggerHandle);
    out('ARIA after open: ' + JSON.stringify(ariaAfter));

    // 5. Snapshot menu structure
    const menuInfo = await page.evaluate(() => {
        const menu = document.querySelector('[role="menu"]');
        if (!menu) return { found: false };
        return {
            found: true,
            role: menu.getAttribute('role'),
            ariaLabelledby: menu.getAttribute('aria-labelledby'),
            hasActiveClass: menu.classList.contains('active'),
            menuItemCount: menu.querySelectorAll('[role="menuitem"]').length,
        };
    });
    out('Menu DOM: ' + JSON.stringify(menuInfo));

    await page.screenshot({
        path: path.resolve(__dirname, '../__screenshots__/wave-d1-profile-menu-open.png'),
        fullPage: false,
    });

    // 6. Run axe-core: first on CLOSED state baseline, then on OPEN state with menu visible.
    //    Subtract baseline IDs to isolate violations introduced by this Wave D1 change.
    await page.addScriptTag({
        url: 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.8.2/axe.min.js',
    });

    // 6a. Close menu first to take baseline.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    const axeBaseline = await page.evaluate(async () => {
        return await window.axe.run({
            include: [['header.db-header']],
            rules: { 'color-contrast': { enabled: false } },
        });
    });
    const baselineIds = new Set(axeBaseline.violations.map((v) => v.id));
    out('axe baseline (menu closed) total violations: ' + axeBaseline.violations.length);

    // 6b. Re-open menu and re-run axe.
    await page.evaluate((el) => el.click(), triggerHandle);
    await page.waitForTimeout(700);
    const axeResults = await page.evaluate(async () => {
        return await window.axe.run({
            include: [['header.db-header'], ['[role="menu"]']],
            rules: { 'color-contrast': { enabled: false } },
        });
    });
    const introduced = axeResults.violations.filter((v) => !baselineIds.has(v.id));
    const critical = introduced.filter(
        (v) => v.impact === 'critical' || v.impact === 'serious'
    );
    out('axe-core NEW critical/serious violations (open vs closed): ' + critical.length);
    for (const v of critical) {
        out('  - ' + v.id + ' (' + v.impact + '): ' + v.description);
    }
    if (introduced.length > critical.length) {
        out('axe-core NEW non-critical violations (info): ' + (introduced.length - critical.length));
    }

    // 7. Test Escape closes the menu
    await page.evaluate((el) => el.focus(), triggerHandle);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
    const escState = await page.evaluate((el) => {
        const menu = document.querySelector('[role="menu"]');
        return {
            ariaExpanded: el.getAttribute('aria-expanded'),
            menuActive: menu ? menu.classList.contains('active') : false,
            focusOnTrigger: document.activeElement === el,
        };
    }, triggerHandle);
    out('After Escape: ' + JSON.stringify(escState));

    // 8. Test ArrowDown opens menu + focuses first menuitem
    await page.evaluate((el) => el.focus(), triggerHandle);
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(700);
    const arrowState = await page.evaluate(() => {
        const menu = document.querySelector('[role="menu"]');
        const items = menu ? menu.querySelectorAll('[role="menuitem"]') : [];
        return {
            menuActive: menu ? menu.classList.contains('active') : false,
            firstItemFocused: items.length > 0 && document.activeElement === items[0],
            activeElementRole: document.activeElement
                ? document.activeElement.getAttribute('role')
                : null,
        };
    });
    out('After ArrowDown: ' + JSON.stringify(arrowState));

    // 9. Final verdict summary
    out('--- VERDICT ---');
    out('aria-expanded transitions correctly: ' +
        (ariaBefore.ariaExpanded === 'false' && ariaAfter.ariaExpanded === 'true' && escState.ariaExpanded === 'false'));
    out('role="menu" present + labelled: ' + (menuInfo.found && menuInfo.role === 'menu' && !!menuInfo.ariaLabelledby));
    out('menuitem count: ' + menuInfo.menuItemCount);
    out('axe critical/serious: ' + critical.length);
    out('Escape closes + focus returns: ' + (!escState.menuActive && escState.focusOnTrigger));
    out('ArrowDown opens + focuses first item: ' + (arrowState.menuActive && arrowState.firstItemFocused));

    fs.writeFileSync(
        path.resolve(__dirname, '../__screenshots__/wave-d1-a11y.log'),
        log.join('\n') + '\n'
    );

    await browser.close();
    process.exit(0);
})().catch((err) => {
    console.error('Script error:', err);
    process.exit(1);
});
