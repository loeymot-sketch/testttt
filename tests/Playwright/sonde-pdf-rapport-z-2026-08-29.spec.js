const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('../e2e/helpers/login');

/**
 * Le « PDF » d'un rapport Z en est-il un ?
 *
 * Un agent d'audit a signalé, sans le vérifier, que ce bouton livrerait du JSON sous un nom
 * de fichier `.pdf`. C'est une affirmation lourde — un rapport Z est une pièce fiscale — et
 * elle ne peut pas être relayée sur une lecture de code. On clique donc réellement sur le
 * bouton, et on lit les PREMIERS OCTETS du fichier reçu : un PDF commence par `%PDF-`.
 *
 * Cliquer plutôt que d'appeler l'API à la main garantit qu'on teste le chemin de
 * l'utilisateur, en-têtes et jeton compris — ma première sonde, un `fetch` nu, avait reçu
 * un 401 qui ne disait rien du produit.
 */

test.describe('Rapport Z — le fichier livré', () => {
    test.describe.configure({ timeout: 180_000 });

    test('le bouton PDF livre-t-il un vrai PDF ?', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/settings/z-reports', { waitUntil: 'networkidle', timeout: 60_000 });
        await page.waitForTimeout(4000);

        const boutons = page.locator('button:has-text("PDF"), a:has-text("PDF")');
        const n = await boutons.count();
        // eslint-disable-next-line no-console
        console.log(`[pdfz] ${n} bouton(s) PDF trouvé(s)`);
        expect(n).toBeGreaterThan(0);

        const attente = page.waitForEvent('download', { timeout: 45_000 }).catch(() => null);
        await boutons.first().click();
        const dl = await attente;

        if (!dl) {
            // eslint-disable-next-line no-console
            console.log('[pdfz] aucun téléchargement déclenché — le bouton n\'ouvre pas de fichier');
            return;
        }

        const fs = require('node:fs');
        const chemin = await dl.path();
        const octets = fs.readFileSync(chemin);
        const tete = octets.subarray(0, 16).toString('utf8');
        const lisible = tete.replace(/[^\x20-\x7e]/g, '.');

        // eslint-disable-next-line no-console
        console.log(`[pdfz] nom="${dl.suggestedFilename()}" taille=${octets.length}o `
            + `premiers_octets="${lisible}" `
            + `verdict=${tete.startsWith('%PDF-') ? 'VRAI PDF' : 'PAS UN PDF'}`);
    });
});
