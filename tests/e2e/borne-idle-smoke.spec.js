// [SYNC/UI-E2E 2026-07-04 — borne] Smoke e2e de l'écran d'accueil borne (attract). Protège contre les
// régressions « idle cassé / écran blanc » (cf. historique : bundle stale, Vue NOT_MOUNTED, raw labels).
// Portrait 1080×1920 (vraie borne). Lancer : PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 npx playwright test tests/e2e/borne-idle-smoke.spec.js
const { test, expect } = require('@playwright/test');

test.describe('Borne — écran accueil (attract)', () => {
  test('rend le branding + le CTA de commande, sans label brut', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.waitForTimeout(2500); // laisse le carrousel/attract se monter

    // innerText = texte RENDU visible (exclut <script>/<style>) → un check de label brut porte sur ce
    // que le client VOIT, pas sur des commentaires JS inline (ex. « config/kiosk.php »).
    const body = await page.innerText('body');

    // 1. Le CTA de commande est présent (l'écran est actionnable, pas figé/blanc).
    expect(body).toContain('Touchez');

    // 2. Le branding Cayenne + la garantie Halal sont là (attract correct).
    expect(body).toMatch(/HALAL/i);

    // 3. AUCUN label i18n brut non résolu (kiosk.x, Label.x, {{ ... }}, undefined).
    expect(body).not.toMatch(/kiosk\.[a-z]|Label\.[A-Za-z]|\{\{|undefined|null%/);

    // 4. Le body a du contenu réel (pas un écran blanc = Vue monté).
    expect((body || '').trim().length).toBeGreaterThan(40);
  });
});
