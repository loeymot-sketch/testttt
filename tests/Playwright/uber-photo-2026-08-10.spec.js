// [UBER-PHOTO 2026-08-10 · owner] Vérification VISUELLE de l'écran tablette « Commande Uber —
// photo du ticket » (CLAUDE.md §6 : un test technique vert ne prouve pas que l'écran est correct).
//
// On ne se contente pas de capturer : on MESURE ce qui rend l'écran utilisable debout, une
// tablette en main — taille des zones tactiles, présence de la ligne symbolique et du bandeau de
// cuisson, absence de libellé brut (« label.x », « undefined »). Une capture jolie ne prouve
// rien ; un chiffre si.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = 'http://127.0.0.1:8000';
const SHOTS = 'tests/captures/uber-photo-2026-08-10';
const MODELE = path.resolve(__dirname, '../fixtures/uber/ticket-exemple.json');

/**
 * Le serveur dédoublonne sur l'EMPREINTE du contenu des photos : rejouer deux fois le même
 * fichier retomberait sur la capture déjà confirmée, dont le bouton d'envoi est (à raison)
 * désactivé — et la spec échouerait pour une bonne raison. On fabrique donc un ticket au numéro
 * unique à chaque exécution. Une spec qui ne passe que sur une base vierge ne prouve rien.
 */
function ticketUnique(suffixe) {
    const modele = JSON.parse(fs.readFileSync(MODELE, 'utf8'));
    modele.display_id = `#${Date.now().toString(36).toUpperCase()}${suffixe}`;
    const cible = path.join(os.tmpdir(), `uber-ticket-${Date.now()}-${suffixe}.json`);
    fs.writeFileSync(cible, JSON.stringify(modele));
    return cible;
}

async function login(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.fill('#formEmail', 'pos@lecayenne.fr');
    await page.fill('#formPassword', '123456');
    await page.click('button:has-text("Connexion")');
    await page.waitForTimeout(3500);
}

/** Libellés bruts / valeurs mortes visibles à l'écran — le défaut que le mandat visuel traque. */
const TEXTE_MORT = `(() => {
  const t = document.body.innerText || '';
  const motifs = [/\\blabel\\.[a-z_]+/i, /\\bundefined\\b/, /\\bNaN\\b/, /\\[object Object\\]/, /\\bnull\\b/];
  return motifs.filter((re) => re.test(t)).map(String);
})()`;

test.describe('Écran tablette — commande Uber par photo', () => {
    test.setTimeout(120000);

    test('parcours complet : photographier → aperçu cuisine → envoyer', async ({ page }) => {
        await page.setViewportSize({ width: 1180, height: 820 }); // tablette paysage
        await login(page);

        await page.goto(`${BASE}/admin/uber-photo`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('[data-testid="uber-photo-pick"]', { timeout: 15000 });
        await page.waitForTimeout(800);
        await page.screenshot({ path: `${SHOTS}/01-ecran-vide.png`, fullPage: true });

        // Les zones tactiles doivent rester grosses : cet écran s'utilise debout, en service.
        const tailleBoutons = await page.evaluate(`(() => {
            return [...document.querySelectorAll('.uber-cap-btn')].map((b) => Math.round(b.getBoundingClientRect().height));
        })()`);
        for (const h of tailleBoutons) {
            expect(h, `bouton trop bas (${h}px) pour un usage tablette`).toBeGreaterThanOrEqual(48);
        }

        // « Photographier » : on injecte le ticket d'exemple, que le lecteur local sait lire.
        await page.setInputFiles('.uber-cap-file', [ticketUnique('a')]);
        await page.waitForSelector('[data-testid="uber-photo-thumbs"]', { timeout: 5000 });

        await page.click('[data-testid="uber-photo-read"]');
        await page.waitForSelector('[data-testid="uber-photo-result"]', { timeout: 20000 });
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${SHOTS}/02-apercu-cuisine.png`, fullPage: true });

        // L'écran d'échec ne doit PAS s'afficher : le lecteur local lit ce ticket.
        expect(await page.locator('[data-testid="uber-photo-failed"]').count()).toBe(0);

        // Le bandeau de cuisson est la première chose que le cuisinier lira.
        const cuisson = (await page.locator('[data-testid="uber-photo-cuisson"]').innerText()).replace(/\s+/g, ' ');
        expect(cuisson).toContain('CUISSON');
        expect(cuisson).toMatch(/\d+K/);
        expect(cuisson, 'la frite du menu doit être comptée').toMatch(/\d+F/);

        // La composition est SYMBOLISÉE, pas recopiée en clair.
        const premiere = await page.locator('.uber-cap-sym').first().innerText();
        expect(premiere).toMatch(/\|/);

        // Le nom du client est repris de la lecture (exigence owner explicite).
        expect(await page.inputValue('[data-testid="uber-photo-client"]')).not.toBe('');

        const morts = await page.evaluate(TEXTE_MORT);
        expect(morts, `libellés bruts visibles : ${JSON.stringify(morts)}`).toEqual([]);

        // Envoi en cuisine.
        await page.click('[data-testid="uber-photo-send"]');

        // [UX 2026-08-12] L'écran ne reste PAS sur la commande partie : il annonce ce qui est
        // parti, offre de le réimprimer, puis se remet seul en position photo. On capture donc
        // le bandeau AVANT que le retour automatique (4 s) ne l'efface.
        await page.waitForSelector('[data-testid="uber-photo-done"]', { timeout: 15000 });
        await page.screenshot({ path: `${SHOTS}/03-envoyee-en-cuisine.png`, fullPage: true });

        const bandeau = await page.locator('[data-testid="uber-photo-done"]').innerText();
        expect(bandeau).toContain('partie en cuisine');
        // Le papier se perd : la réimpression doit être offerte là où on la cherche d'abord.
        await expect(page.locator('[data-testid="uber-photo-reprint-done"]')).toBeVisible();

        // …et l'écran redevient prêt tout seul, sans qu'on ait à chercher « Recommencer ».
        await page.waitForSelector('[data-testid="uber-photo-done"]', { state: 'detached', timeout: 15000 });
        await expect(page.locator('[data-testid="uber-photo-pick"]')).toBeVisible();
        await expect(page.locator('[data-testid="uber-photo-result"]')).toHaveCount(0);
    });

    /**
     * [HISTORIQUE 2026-08-12 · owner « voir historique … ça reste pas sur même page »] Tout se
     * fait sur cet écran : rouvrir une commande passée, revoir ce que la cuisine a reçu, et
     * ressortir le papier — sans jamais changer de page.
     */
    test('historique : une commande passée se rouvre sur place et se réimprime', async ({ page }) => {
        await page.setViewportSize({ width: 1180, height: 820 });
        await login(page);
        await page.goto(`${BASE}/admin/uber-photo`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('[data-testid="uber-photo-history"]', { timeout: 15000 });

        const lignes = page.locator('[data-testid="uber-photo-history"] .uber-cap-recent-row');
        expect(await lignes.count(), "l'historique du service est vide").toBeGreaterThan(0);

        // Le détail est REPLIÉ par défaut : la liste doit rester lisible d'un coup d'œil.
        expect(await page.locator('.uber-cap-recent-detail').count()).toBe(0);

        await lignes.first().click();
        await page.waitForSelector('.uber-cap-recent-detail', { timeout: 5000 });
        await page.screenshot({ path: `${SHOTS}/07-historique-deplie.png`, fullPage: true });

        // On n'a pas quitté l'écran de capture : le bouton photo est toujours là.
        await expect(page.locator('[data-testid="uber-photo-pick"]')).toBeVisible();
        expect(page.url()).toContain('/admin/uber-photo');

        const morts = await page.evaluate(TEXTE_MORT);
        expect(morts, `libellés bruts visibles : ${JSON.stringify(morts)}`).toEqual([]);
    });

    test('rendu portrait (tablette tenue à la verticale) sans débordement horizontal', async ({ page }) => {
        await page.setViewportSize({ width: 820, height: 1180 });
        await login(page);

        await page.goto(`${BASE}/admin/uber-photo`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('[data-testid="uber-photo-pick"]', { timeout: 15000 });
        await page.setInputFiles('.uber-cap-file', [ticketUnique('b')]);
        await page.click('[data-testid="uber-photo-read"]');
        await page.waitForSelector('[data-testid="uber-photo-result"]', { timeout: 20000 });
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${SHOTS}/04-portrait.png`, fullPage: true });

        const debordement = await page.evaluate(
            `document.documentElement.scrollWidth - document.documentElement.clientWidth`
        );
        expect(debordement, 'la page déborde horizontalement en portrait').toBeLessThanOrEqual(1);
    });
});
