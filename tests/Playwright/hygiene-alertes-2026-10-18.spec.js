/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0 « alertes hors écran »] E2E réel : vrai serveur, vraie base, vrai bundle.
 * Réglage des alertes e-mail : refus clairs, enregistrement, mémorisation, essai qui ÉCHOUE honnêtement (la messagerie locale
 * pointe sur un hôte inexistant) et journal des envois. Remet l'état à zéro à la fin (alertes désactivées, journal vidé).
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/Playwright/hygiene-alertes-2026-10-18.spec.js
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('../e2e/helpers/login');

const OUT = process.env.CAPTURES || path.join(__dirname, '..', 'captures', 'hygiene-alertes-2026-10-18');
fs.mkdirSync(OUT, { recursive: true });

function tinker(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (/error|exception/i.test(out)) throw new Error('tinker : ' + out);
    return out;
}
const reset = () => tinker(`DB::table('hygiene_alert_settings')->delete(); DB::table('hygiene_alert_dispatches')->delete();`);

test.setTimeout(180_000);
test.beforeAll(reset);
test.afterAll(reset);

test('alertes e-mail : refus clairs, enregistrement, mémorisation, essai en échec dit et journalisé', async ({ page }) => {
    const bad = [];
    page.on('pageerror', (e) => bad.push(`pageerror: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|WebSocket|6001/.test(m.text())) bad.push(`console.error: ${m.text().slice(0, 200)}`); });

    await page.setViewportSize({ width: 1280, height: 800 });
    await loginAsAdmin(page);
    await page.goto('/admin/hygiene/home', { waitUntil: 'domcontentloaded' });
    await page.getByTestId('home-alerts-link').click({ timeout: 30_000 });
    await expect(page).toHaveURL(/\/admin\/hygiene\/alerts$/);
    await expect(page.getByTestId('al-enabled')).toHaveAttribute('aria-pressed', 'false', { timeout: 20_000 });
    await expect(page.getByTestId('al-log-empty')).toBeVisible();
    await expect(page.getByTestId('al-save')).toBeDisabled(); // rien de modifié
    await page.screenshot({ path: path.join(OUT, 'a1-depart.png') });

    // activer sans destinataire : refusé, avec la raison
    await page.getByTestId('al-enabled').click();
    // tous les types sont cochés par défaut : on décoche « Huile » pour prouver que le choix est gardé
    await expect(page.getByTestId('al-domain-oil')).toHaveAttribute('aria-pressed', 'true');
    await page.getByTestId('al-domain-oil').click();
    await page.getByTestId('al-save').click();
    await expect(page.getByTestId('al-message')).toContainText('Ajoutez au moins une adresse e-mail');
    await page.screenshot({ path: path.join(OUT, 'a2-refus-sans-destinataire.png') });

    // une adresse invalide : refusée côté écran
    await page.getByTestId('al-new-email').fill('pas-une-adresse');
    await page.getByTestId('al-add').click();
    await expect(page.getByTestId('al-email-error')).toContainText("pas valide");

    // une adresse valide, un type, une plage : enregistré
    await page.getByTestId('al-new-email').fill('Responsable@Example.test');
    await page.getByTestId('al-add').click();
    await expect(page.getByText('responsable@example.test').first()).toBeVisible();
    await page.getByTestId('al-from').fill('10:00');
    await page.getByTestId('al-until').fill('22:30');
    await page.getByTestId('al-save').click();
    await expect(page.getByTestId('al-message')).toContainText('les alertes sont actives', { timeout: 15_000 });
    await page.screenshot({ path: path.join(OUT, 'a3-enregistre.png') });

    // mémorisé après rechargement
    await page.reload();
    await expect(page.getByTestId('al-enabled')).toHaveAttribute('aria-pressed', 'true', { timeout: 20_000 });
    await expect(page.getByTestId('al-from')).toHaveValue('10:00');
    await expect(page.getByTestId('al-domain-temperature')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('al-domain-oil')).toHaveAttribute('aria-pressed', 'false');
    await expect(page.getByText('responsable@example.test').first()).toBeVisible();

    // l'essai échoue (messagerie locale factice) : dit, et journalisé « Échec »
    await page.getByTestId('al-test').click();
    await expect(page.getByTestId('al-message')).toContainText("n'a pas pu partir", { timeout: 120_000 });
    await expect(page.getByTestId('al-log-row').first()).toContainText('Échec');
    await page.getByTestId('al-log').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'a4-essai-echec.png') });
    const row = tinker(`echo json_encode(DB::table('hygiene_alert_dispatches')->latest('id')->first(['status','recipient','channel']));`);
    expect(row).toContain('"status":"failed"');
    expect(row).toContain('responsable@example.test');

    expect(bad, bad.join('\n')).toEqual([]);
});
