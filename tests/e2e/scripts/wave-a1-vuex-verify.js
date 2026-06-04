/**
 * [UR4-002 V1.0.2 Wave A1] Verify Vuex `auth.authInfo.phone` is sanitized
 * of the `PENDING_<id>` / `PENDING_CREATE_<hex>` server sentinel at BOTH
 * boundaries:
 *
 *   Scenario 1 — Write boundary
 *     Fresh login → check localStorage contains no PENDING_ substring.
 *     (Tests `authLogin` mutation sanitize in store/modules/auth.js.)
 *
 *   Scenario 2 — Read/rehydrate boundary (THE critical scenario)
 *     Manually inject `PENDING_CREATE_deadbeef` into the persisted vuex
 *     state, reload, and verify the rehydrate-time `getState` override
 *     in store/index.js purges it before the store boots. This is the
 *     ACTUAL UR4-002 vector — pre-existing polluted localStorage from
 *     sessions that ran before backend PhoneDisplay::safe was deployed.
 *
 * Run:  node tests/e2e/scripts/wave-a1-vuex-verify.js
 * Pass: both scenarios report "has PENDING_: false"
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const LOG_PATH = path.resolve(__dirname, '../__screenshots__/wave-a1-verify.log');
const BASE_URL = 'http://127.0.0.1:8000';

(async () => {
    const lines = [];
    const log = (msg) => { lines.push(msg); console.log(msg); };

    const browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    log(`[Wave A1] ${new Date().toISOString()}`);
    log('');
    log('=== Scenario 1: Fresh login (write boundary) ===');

    await page.goto(`${BASE_URL}/login`);
    await page.locator('#formEmail').fill('admin@lecayenne.fr');
    await page.locator('#formPassword').fill('123456');
    await page.getByRole('button', { name: /connexion|login/i }).click();
    await page.waitForURL((u) => !u.toString().includes('/login'), { timeout: 15000 });
    await page.waitForTimeout(3500);

    const ls1 = await page.evaluate(() => JSON.stringify(localStorage));
    const hasPending1 = /PENDING_/.test(ls1);
    log(`  localStorage size: ${ls1.length} bytes`);
    log(`  has PENDING_     : ${hasPending1}`);

    log('');
    log('=== Scenario 2: Pollute + reload (rehydrate boundary) ===');

    // Inject a PENDING_ sentinel into the persisted vuex state to simulate
    // legacy polluted localStorage (pre-PhoneDisplay::safe sessions).
    const injected = await page.evaluate(() => {
        const raw = localStorage.getItem('vuex');
        if (!raw) return { ok: false, reason: 'no vuex key in localStorage' };
        const vuex = JSON.parse(raw);
        if (!vuex.auth || !vuex.auth.authInfo) {
            return { ok: false, reason: 'vuex.auth.authInfo missing' };
        }
        vuex.auth.authInfo.phone = 'PENDING_CREATE_deadbeef';
        localStorage.setItem('vuex', JSON.stringify(vuex));
        return { ok: true, before: vuex.auth.authInfo.phone };
    });
    log(`  injection: ${JSON.stringify(injected)}`);

    // Verify it landed in storage before reload
    const lsPolluted = await page.evaluate(() => JSON.stringify(localStorage));
    const pollutedHasPending = /PENDING_CREATE_deadbeef/.test(lsPolluted);
    log(`  storage holds PENDING_CREATE_deadbeef pre-reload: ${pollutedHasPending}`);

    // Reload — vuex-persistedstate's getState override runs at boot
    await page.reload();
    await page.waitForTimeout(2500);

    const ls2 = await page.evaluate(() => JSON.stringify(localStorage));
    const hasPending2 = /PENDING_/.test(ls2);
    const phoneNow = await page.evaluate(() => {
        const raw = localStorage.getItem('vuex');
        if (!raw) return null;
        try {
            return JSON.parse(raw)?.auth?.authInfo?.phone ?? null;
        } catch {
            return null;
        }
    });
    log(`  localStorage size post-reload: ${ls2.length} bytes`);
    log(`  authInfo.phone post-reload   : ${JSON.stringify(phoneNow)}`);
    log(`  has PENDING_                 : ${hasPending2}`);

    log('');
    log('=== Verdict ===');
    const greenWrite = !hasPending1;
    const greenRead = !hasPending2 && injected.ok && pollutedHasPending;
    log(`  Scenario 1 (write):  ${greenWrite ? 'GREEN' : 'RED'}`);
    log(`  Scenario 2 (read) :  ${greenRead ? 'GREEN' : 'RED'}`);
    log(`  Overall           :  ${(greenWrite && greenRead) ? 'GREEN' : 'RED'}`);

    fs.mkdirSync(path.dirname(LOG_PATH), { recursive: true });
    fs.writeFileSync(LOG_PATH, lines.join('\n') + '\n');

    await browser.close();
    process.exit((greenWrite && greenRead) ? 0 : 1);
})().catch((err) => {
    console.error('FATAL:', err);
    process.exit(2);
});
