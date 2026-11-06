/**
 * [ETIQUETTES 2026-10-19 · owner « une salade iceberg, tomates, oignons, poivrons coupés → les quatre DLC »] E2E réel de la dictée :
 * le VRAI micro du navigateur (un fichier audio parlé sert de micro), l'enregistrement est converti en WAV 16 kHz mono, envoyé, et la
 * réponse remplit la sélection SANS rien imprimer. Le modèle distant est simulé (aucune dépense, aucun secret) : sa réponse est
 * vérifiée côté serveur par PHPUnit.
 *   AUDIO=/chemin/phrase.wav PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/label-voice-2026-10-19.spec.js
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'label-voice-2026-10-19');
fs.mkdirSync(OUT, { recursive: true });
const AUDIO = process.env.AUDIO;

test.skip(!AUDIO || !fs.existsSync(AUDIO), 'AUDIO=/chemin/vers/phrase.wav requis (le fichier parlé qui sert de micro)');
test.use({
    permissions: ['microphone'],
    launchOptions: { args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', `--use-file-for-fake-audio-capture=${AUDIO}`] },
});
test.setTimeout(180_000);

test('dictée : le vrai micro → WAV 16 kHz → sélection pré-remplie, rien d’imprimé', async ({ page }) => {
    const bad = [];
    const printed = [];
    let voiceBody = null;
    page.on('pageerror', (e) => { if (!/^(Failed to fetch|Network Error)$/.test(e.message)) bad.push(`pageerror: ${e.message}`); });
    page.on('request', (r) => { if (/\/api\/admin\/labels\/dlc\/print/.test(r.url())) printed.push(r.url()); });
    await page.setViewportSize({ width: 1280, height: 900 });
    await loginAsAdmin(page);
    await page.goto('/admin/labels/dlc', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('dlc-voice')).toBeVisible({ timeout: 30_000 });

    // les quatre premiers produits imprimables de la grille : ce que le « modèle » répondra
    const tiles = page.locator('[data-testid^="dlc-product-"]');
    await expect(tiles.first()).toBeVisible();
    const found = await tiles.evaluateAll((els) => els.slice(0, 4).map((e) => ({ id: Number(e.getAttribute('data-testid').replace('dlc-product-', '')), name: (e.querySelector('.dlc-tile-name') || e).textContent.trim() })));
    expect(found.length, 'il faut au moins 4 produits au catalogue local').toBe(4);

    await page.route('**/api/admin/labels/dlc/voice', async (route) => {
        voiceBody = route.request().postDataBuffer();
        await route.fulfill({
            status: 200, contentType: 'application/json',
            body: JSON.stringify({ data: { driver: 'openai', transcript: 'une salade iceberg, tomates, oignons, poivrons coupés',
                items: found.map((f) => ({ product_id: f.id, name: f.name, copies: 1, heard: f.name.toLowerCase() })), unmatched: ['licornes'] }, message: null }),
        });
    });

    await page.screenshot({ path: path.join(OUT, 'v1-avant.png') });
    await page.getByTestId('dlc-voice').click();
    await expect(page.getByTestId('dlc-voice')).toHaveAttribute('aria-pressed', 'true');
    await page.waitForTimeout(4500); // la phrase parlée dure ~4 s
    await page.screenshot({ path: path.join(OUT, 'v2-enregistrement.png') });
    await page.getByTestId('dlc-voice').click();

    await expect(page.getByTestId('dlc-voice-note')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('dlc-voice-heard')).toContainText('salade iceberg');
    await expect(page.getByTestId('dlc-voice-added')).toContainText('4 produits sélectionnés');
    await expect(page.getByTestId('dlc-voice-unmatched')).toContainText('licornes');
    await expect(page.getByTestId('dlc-basket-total')).toContainText('étiquette');
    for (const f of found) await expect(page.getByTestId(`dlc-product-${f.id}`)).toHaveAttribute('aria-pressed', 'true');
    expect(printed, 'la dictée ne doit RIEN imprimer').toEqual([]);
    // aucune ligne de la note n'est cachée derrière le panier fixé en bas d'écran (la capture du premier jet cachait « Pas compris »)
    for (const id of ['dlc-voice-heard', 'dlc-voice-added', 'dlc-voice-unmatched']) {
        const hit = await page.getByTestId(id).evaluate((el) => {
            const r = el.getBoundingClientRect();
            const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);

            return { inView: r.top >= 0 && r.bottom <= window.innerHeight, visibleToUser: !!top && el.contains(top) };
        });
        expect(hit, `« ${id} » est caché ou hors écran`).toEqual({ inView: true, visibleToUser: true });
    }
    await page.screenshot({ path: path.join(OUT, 'v3-selection-remplie.png') });
    await page.getByTestId('dlc-voice-note').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'v3b-note.png') });

    // ce qui est parti sur le réseau est un vrai WAV PCM 16 bits mono 16 kHz, d'au moins une seconde de parole
    expect(voiceBody, 'aucune requête de dictée').toBeTruthy();
    const at = voiceBody.indexOf(Buffer.from('RIFF'));
    expect(at, 'pas de RIFF dans la requête').toBeGreaterThan(-1);
    const wav = voiceBody.subarray(at);
    expect(wav.subarray(8, 12).toString()).toBe('WAVE');
    expect(wav.readUInt16LE(22)).toBe(1);
    expect(wav.readUInt32LE(24)).toBe(16000);
    expect(wav.readUInt16LE(34)).toBe(16);
    expect(wav.readUInt32LE(40), 'moins d’une seconde enregistrée').toBeGreaterThan(32000);
    let peak = 0;
    for (let i = 44; i + 1 < Math.min(wav.length, 44 + 64000); i += 2) peak = Math.max(peak, Math.abs(wav.readInt16LE(i)));
    expect(peak, 'le fichier est du silence').toBeGreaterThan(700);

    // petit écran (tablette étroite) : le panneau et le bouton tiennent
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(400);
    const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(over, 'débordement horizontal à 390 px').toBeLessThanOrEqual(1);
    await page.getByTestId('dlc-voice').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'v4-mobile.png') });

    expect(bad).toEqual([]);
});
