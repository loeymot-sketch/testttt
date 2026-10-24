// [ETIQUETTES 2026-10-18] « Rien ne change » : on prouve, sur la VRAIE page (vrai bundle, vraie commande, vrai clic « Imprimer »),
// ce que l'imprimante reçoit réellement. Une fausse Brother (navigator.serial simulé) enregistre chaque octet ; le décodeur
// indépendant (écrit d'après le manuel) lit ensuite la commande de coupe de chaque page.
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/etiquettes-coupe-octets-reels-2026-10-18.spec.js
// Prérequis : base e2e avec une commande à plusieurs produits visible sur l'écran (N° A0039, id 5368, datée d'aujourd'hui).
const { test, expect } = require('@playwright/test');
const path = require('path');
const { decodeJob } = require('./helpers/brotherDecode.js');
const { loginAsAdmin } = require('../e2e/helpers/login');

async function installFakePrinter(context) {
    await context.addInitScript(() => {
        const SPP = '00001101-0000-1000-8000-00805f9b34fb';
        const L = (window.__lbl = { jobs: [] });
        let controller;
        let current = [];
        const frame = (statusType = 0x00) => {
            const f = new Uint8Array(32);
            f.set([0x80, 0x20, 0x42, 0x34, 0x41, 0x30, 0x30, 0x00, 0, 0, 62, 0x4a, 0, 0, 0x3f, 0, 0, 0, statusType, 0, 0, 0, 0, 0]);
            return f;
        };
        const port = {
            readable: null, writable: null,
            getInfo: () => ({ bluetoothServiceClassId: SPP }),
            async open() {
                port.readable = new ReadableStream({ start(c) { controller = c; }, cancel() {} });
                port.writable = new WritableStream({
                    write(chunk) {
                        const bytes = Uint8Array.from(chunk);
                        if (bytes.length === 3 && bytes[0] === 0x1b && bytes[1] === 0x69 && bytes[2] === 0x53) { controller.enqueue(frame()); return; }
                        const merged = new Uint8Array(current.length + bytes.length);
                        merged.set(current, 0); merged.set(bytes, current.length);
                        current = merged;
                        // un travail est complet quand il se termine par 0x1A (impression avec avance) après des lignes raster
                        if (current[current.length - 1] === 0x1a) {
                            L.jobs.push(Array.from(current));
                            const pages = current.filter((_, i) => false).length; // placeholder (confirmation ci-dessous)
                            setTimeout(() => { for (let i = 0; i < 12; i++) controller.enqueue(frame(0x01)); }, 30);
                            current = [];
                        }
                    },
                });
            },
            async close() { port.readable = null; port.writable = null; },
        };
        const fake = { async getPorts() { return [port]; }, async requestPort() { return port; }, addEventListener() {} };
        Object.defineProperty(Navigator.prototype, 'serial', { get: () => fake, configurable: true });
    });
}

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'brother-chain-2026-10-17');

for (const mode of ['each', 'order']) {
    test(`clic « Imprimer » sur la commande de 4 tickets, mode « ${mode} » : commande de coupe réellement envoyée`, async ({ page, context }) => {
        await installFakePrinter(context);
        await page.setViewportSize({ width: 1280, height: 800 });
        await loginAsAdmin(page);
        await page.goto('/admin/labels/orders');
        await page.evaluate((m) => localStorage.setItem('label_printer_settings', JSON.stringify({ mediaId: 'c62', autoCut: true, cutMode: m })), mode);
        await page.reload();
        const printBtn = page.locator('[data-testid^="label-print-"]:not([data-testid^="label-print-one-"]):not([data-testid="label-print-all"]):not([data-testid="label-print-all-why"])').first();
        await expect(printBtn).toBeVisible({ timeout: 20_000 });
        await printBtn.click();
        await expect.poll(() => page.evaluate(() => window.__lbl.jobs.length), { timeout: 30_000 }).toBeGreaterThan(0);
        const bytes = await page.evaluate(() => window.__lbl.jobs[0]);
        const d = decodeJob(Uint8Array.from(bytes));
        expect(d.error, d.error).toBeFalsy();
        const ctl = (p, c) => p.control.find((x) => x.cmd === c).n;
        const report = { mode, pages: d.pages.length, cutEvery: d.pages.map((p) => ctl(p, 'A')), autoCut: d.pages.map((p) => ctl(p, 'M')), cutAtEnd: d.pages.map((p) => ctl(p, 'K')), terminators: d.pages.map((p) => p.terminator), rows: d.pages.map((p) => p.rows.length) };
        console.log('JOB ' + JSON.stringify(report));
        expect(d.pages.length).toBeGreaterThan(1);
        // l'écran « Imprimante » dit ce que la tablette vient d'envoyer
        await page.goto('/admin/labels/printer');
        const line = page.getByTestId('pr-last-job');
        await expect(line).toContainText(mode === 'order' ? 'UNE coupe à la fin (tickets attachés)' : 'une coupe après chaque étiquette');
        await line.scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(OUT, `c-dernier-travail-${mode}.png`) });
        if (mode === 'order') {
            d.pages.forEach((p) => expect(ctl(p, 'A')).toBe(d.pages.length));
            expect(d.pages.map((p) => p.terminator)).toEqual([...Array(d.pages.length - 1).fill(0x0c), 0x1a]);
        } else {
            d.pages.forEach((p) => expect(ctl(p, 'A')).toBe(1));
        }
    });
}

test('« Test de coupe » : 3 tickets attachés envoyés même si le réglage est « chaque ticket »', async ({ page, context }) => {
    await installFakePrinter(context);
    await page.setViewportSize({ width: 1280, height: 800 });
    await loginAsAdmin(page);
    await page.goto('/admin/labels/printer');
    await page.evaluate(() => localStorage.setItem('label_printer_settings', JSON.stringify({ mediaId: 'c62', autoCut: true, cutMode: 'each' })));
    await page.reload();
    const btn = page.getByTestId('pr-cut-test');
    await expect(btn).toBeEnabled({ timeout: 20_000 });
    await btn.click();
    await expect.poll(() => page.evaluate(() => window.__lbl.jobs.length), { timeout: 30_000 }).toBeGreaterThan(0);
    const d = decodeJob(Uint8Array.from(await page.evaluate(() => window.__lbl.jobs[0])));
    expect(d.error, d.error).toBeFalsy();
    expect(d.pages).toHaveLength(3);
    d.pages.forEach((p) => expect(p.control.find((c) => c.cmd === 'A').n).toBe(3));
    expect(d.pages.map((p) => p.terminator)).toEqual([0x0c, 0x0c, 0x1a]);
    await expect(page.getByTestId('pr-test-message')).toContainText('3 tickets en une bande');
    await page.getByTestId('pr-cut-test').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'd-test-de-coupe.png') });
});
