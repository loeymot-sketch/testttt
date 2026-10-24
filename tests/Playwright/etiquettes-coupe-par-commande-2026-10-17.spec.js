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

// L'aperçu d'une commande à plusieurs tickets montre le pointillé entre les tickets (sauf après le dernier) en mode « attachés ».
// Prérequis local : une commande à ≥ 2 produits visible sur l'écran (id 5368 datée d'aujourd'hui dans la base e2e).
test('aperçu d’une commande : pointillé entre les tickets, jamais après le dernier', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await loginAsAdmin(page);
    const heights = async (mode) => {
        await page.goto('/admin/labels/orders');
        await page.evaluate((m) => localStorage.setItem('label_printer_settings', JSON.stringify({ mediaId: 'c62', autoCut: true, cutMode: m })), mode);
        await page.reload();
        const btn = page.locator('[data-testid^="label-preview-"]').first();
        await expect(btn).toBeVisible({ timeout: 20_000 });
        await btn.click();
        const imgs = page.locator('[data-testid="label-previews"] img');
        await expect(imgs.first()).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(500);
        return imgs.evaluateAll((els) => els.map((e) => e.naturalHeight));
    };
    const each = await heights('each');
    expect(each.length).toBeGreaterThan(1);
    await page.screenshot({ path: path.join(OUT, 'b1-apercu-chaque.png') });
    const order = await heights('order');
    expect(order.length).toBe(each.length);
    await page.screenshot({ path: path.join(OUT, 'b2-apercu-attaches.png') });
    // chaque ticket sauf le dernier est plus haut de 28 points ; le dernier est identique à « chaque ticket »
    order.slice(0, -1).forEach((h, i) => expect(h).toBe(each[i] + 28));
    expect(order[order.length - 1]).toBe(each[each.length - 1]);
});
