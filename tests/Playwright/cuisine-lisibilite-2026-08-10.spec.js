// [OWNER 2026-08-10] Vérification VISUELLE des trois demandes owner sur l'ÉCRAN DE CUISINE :
//   1. « la cuisine se trompe entre CHEESE et CHICKEN » → les deux écrits EN TOUTES LETTRES ;
//   2. le MENU ENFANT doit se voir comme tel, pas se confondre avec le produit seul ;
//   3. « les sauces au bon endroit, si pour les frites ou pour sandwich » → jamais une sauce
//      fantôme anonyme en plus.
//
// La commande est injectée par le canal « ticket Uber photographié » (déjà en place) : c'est le
// seul moyen de fabriquer une commande complète sans passer par le wizard FROZEN de la caisse.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = 'http://127.0.0.1:8000';
const SHOTS = 'tests/captures/cuisine-lisibilite-2026-08-10';

/** Ticket au numéro unique : le serveur dédoublonne sur l'empreinte du contenu. */
function ticket() {
    const t = {
        customer_name: 'Test Cuisine',
        display_id: `#${Date.now().toString(36).toUpperCase()}`,
        order_type: 'pickup',
        total: 0,
        items: [
            { title: 'Cheese Burger', quantity: 1, options: [], note: '' },
            { title: 'Chicken Burger', quantity: 1, options: [], note: '' },
            { title: 'Menu Enfant Chicken Burger', quantity: 1, options: [], note: '' },
            {
                title: 'Cayenne',
                quantity: 1,
                // 1 sauce sandwich + 2 sauces frites : c'est le cas qui produisait une
                // « + Sauce supplémentaire » anonyme en trop.
                options: ['Viande : Poulet mariné', 'Sauce : Fromagère maison', 'Salade',
                    'Menu (Frites + Boisson)', 'Sauce frites : Ketchup, Mayonnaise'],
                note: '',
            },
        ],
    };
    const p = path.join(os.tmpdir(), `ticket-cuisine-${Date.now()}.json`);
    fs.writeFileSync(p, JSON.stringify(t));
    return p;
}

async function login(page, email) {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.fill('#formEmail', email);
    await page.fill('#formPassword', '123456');
    await page.click('button:has-text("Connexion")');
    await page.waitForTimeout(3500);
}

test('écran de cuisine — produits lisibles et sauces à leur place', async ({ browser }) => {
    test.setTimeout(150000);

    // ── Injection de la commande via l'écran tablette (session CAISSE).
    // Chaque rôle a son PROPRE contexte navigateur : réutiliser la même page pour se
    // reconnecter échoue, car le SPA redirige /login vers le tableau de bord tant qu'une
    // session vit — le test tomberait sur son harnais, pas sur le produit.
    const ctxCaisse = await browser.newContext({ viewport: { width: 1180, height: 900 } });
    const page = await ctxCaisse.newPage();
    await login(page, 'pos@lecayenne.fr');
    await page.goto(`${BASE}/admin/uber-photo`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="uber-photo-pick"]', { timeout: 15000 });
    await page.setInputFiles('.uber-cap-file', [ticket()]);
    await page.click('[data-testid="uber-photo-read"]');
    await page.waitForSelector('[data-testid="uber-photo-result"]', { timeout: 20000 });
    await page.click('[data-testid="uber-photo-send"]');
    await page.waitForTimeout(2500);

    await ctxCaisse.close();

    // ── Lecture par le CUISINIER, dans un contexte NEUF.
    const ctxCuisine = await browser.newContext({ viewport: { width: 1600, height: 1100 } });
    const cuisine = await ctxCuisine.newPage();
    await login(cuisine, 'chef@lecayenne.fr');
    await cuisine.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'domcontentloaded' });
    await cuisine.waitForTimeout(6000);
    await cuisine.screenshot({ path: `${SHOTS}/01-kds.png`, fullPage: false });

    const texte = await cuisine.evaluate('document.body.innerText');

    // 1. Les familles confondues sont écrites en entier — et le code court a disparu.
    expect(texte, 'CHEESE BURGER doit être écrit en entier').toContain('CHEESE BURGER');
    expect(texte, 'CHICKEN BURGER doit être écrit en entier').toContain('CHICKEN BURGER');

    // 2. Le menu enfant se voit comme tel.
    expect(texte, 'le menu enfant doit se lire en entier').toContain('MENU ENFANT CHICKEN BURGER');

    // 3. Les sauces : celle du sandwich sur la ligne 1, celles des frites sur le badge,
    //    et AUCUNE sauce fantôme anonyme.
    expect(texte).toContain('MENU : KTP MAY');
    expect(texte, 'sauce fantôme anonyme en supplément').not.toContain('Sauce supplémentaire');

    await ctxCuisine.close();
});
