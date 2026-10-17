// [ETIQUETTES 2026-10-17] Réglage « tickets d'une même commande » sur le vrai serveur : visible seulement avec la coupe auto,
// mémorisé, et sans erreur réseau ni console.
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'brother-chain-2026-10-17');

test('réglage de la découpe par commande', async ({ page }) => {
    const bad = [];
    page.on('response', (r) => { if (r.status() >= 400 && /\/api\//.test(r.url())) bad.push(`${r.status()} ${r.url()}`); });
    page.on('pageerror', (e) => bad.push(`pageerror: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error' && !/favicon|WebSocket|pusher|6001/i.test(m.text())) bad.push(`console.error: ${m.text().slice(0, 200)}`); });

    await page.setViewportSize({ width: 1280, height: 800 });
    await loginAsAdmin(page);
    await page.goto('/admin/labels/printer');
    await page.evaluate(() => localStorage.removeItem('label_printer_settings'));
    await page.reload();

    const select = page.getByTestId('pr-cutmode');
    await expect(select).toBeVisible();
    await expect(select).toHaveValue('each');
    await select.scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'a1-par-defaut.png') });

    await select.selectOption('order');
    await expect(select).toHaveValue('order');
    const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('label_printer_settings')));
    expect(saved.cutMode).toBe('order');
    await select.scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'a2-par-commande.png') });

    await page.reload();
    await expect(page.getByTestId('pr-cutmode')).toHaveValue('order');

    await page.getByTestId('pr-autocut').uncheck();
    await expect(page.getByTestId('pr-cutmode-field')).toHaveCount(0);
    await page.getByTestId('pr-autocut').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'a3-coupe-auto-eteinte.png') });

    expect(bad, bad.join('\n')).toEqual([]);
});
