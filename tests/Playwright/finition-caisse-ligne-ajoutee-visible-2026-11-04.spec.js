/**
 * [FINITION 2026-11-04] Caisse : l'article qu'on vient d'ajouter doit être VISIBLE sur le ticket.
 *
 * Sur un écran bas (1366×768, 1024×768), le ticket montre 2 lignes entières. Les lignes s'ajoutent en
 * bas de la liste : sans défilement, le 3ᵉ, 4ᵉ, 5ᵉ article ajouté arrive hors de vue — le caissier ne peut
 * plus relire la viande ou la sauce qu'il vient de choisir, ni corriger une quantité, sans aller faire
 * défiler le ticket à la main. Ce banc ajoute 5 articles différents et vérifie, après CHAQUE ajout, que
 * la dernière ligne du ticket est entièrement dans la zone visible du ticket.
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/finition-caisse-ligne-ajoutee-visible-2026-11-04.spec.js --workers=1
 *
 * Le panier n'est jamais encaissé : il est vidé à la fin (aucune commande créée).
 */
const { test, expect } = require('@playwright/test');

const IDENTIFIANT = process.env.POS_EMAIL || 'pos@lecayenne.fr';
const MOT_DE_PASSE = process.env.POS_PASSWORD || '123456';
// Produits RÉELS du catalogue local (SELECT name FROM items WHERE status = 5), tous différents pour que
// chaque ajout crée une NOUVELLE ligne au lieu d'incrémenter une quantité.
const PRODUITS = ['Cheese Burger', 'Coca-Cola 33cl', 'Fish Burger', 'Tiramisu', 'Sprite 33cl'];

async function ouvrirLaCaisse(page) {
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.fill('#formEmail', IDENTIFIANT);
    await page.fill('#formPassword, input[type="password"]', MOT_DE_PASSE);
    await page.click('button:has-text("Connexion")', { timeout: 10_000 });
    await page.waitForTimeout(3500);
    await page.goto('/admin/pos', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-testid="pos-category-grid"]', { timeout: 20_000 });
    await page.waitForTimeout(2500);
    await page.addStyleTag({ content: '.phpdebugbar{display:none !important}' });
}

async function lignes(page) {
    return page.evaluate(() => document.querySelectorAll('.pos-v5-cart__body .pos-v5-cart-item').length);
}

async function ajouter(page, nom) {
    const avant = await lignes(page);
    const recherche = page.locator('input[placeholder="Rechercher un article du menu"]');
    await recherche.fill(nom, { timeout: 5_000 });
    await page.waitForTimeout(900);
    await page.evaluate((n) => {
        const b = [...document.querySelectorAll('[data-pos-item-id]')]
            .find((x) => !x.disabled && x.offsetParent !== null && (x.innerText || '').includes(n));
        if (b) b.click();
    }, nom);
    await page.waitForTimeout(1300);
    // Un produit composé ouvre l'assistant : viande, sauce, puis « Ajouter au panier » (le bouton VISIBLE du
    // wizard, cf. mémoire « deux boutons Ajouter au panier »). Un produit simple s'ajoute directement.
    await page.evaluate(() => {
        const vis = (s) => [...document.querySelectorAll(s)].find((e) => e.offsetParent !== null);
        const v = vis('[data-viande]'); if (v) v.click();
    });
    await page.waitForTimeout(250);
    await page.evaluate(() => { const c = [...document.querySelectorAll('button.sauce-chip')].find((e) => e.offsetParent); if (c) c.click(); });
    await page.waitForTimeout(250);
    await page.evaluate(() => { const b = [...document.querySelectorAll('button.wizard-btn-cart')].find((e) => e.offsetParent !== null); if (b) b.click(); });
    await page.waitForTimeout(1300);
    await recherche.fill('', { timeout: 5_000 });
    await page.waitForTimeout(700);
    return { avant, apres: await lignes(page) };
}

async function derniereLigne(page) {
    return page.evaluate(() => {
        const body = document.querySelector('.pos-v5-cart__body');
        const items = body ? body.querySelectorAll('.pos-v5-cart-item') : [];
        const der = items[items.length - 1];
        if (!body || !der) return null;
        const zb = body.getBoundingClientRect();
        const r = der.getBoundingClientRect();
        return {
            nom: (der.querySelector('.pos-v5-cart-item__name, [class*="name"]') || der).innerText.split('\n')[0].trim().slice(0, 40),
            visibleEntiere: r.top >= zb.top - 1 && r.bottom <= zb.bottom + 1,
            haut: Math.round(r.top - zb.top), bas: Math.round(r.bottom - zb.bottom), defile: Math.round(body.scrollTop),
        };
    });
}

for (const [w, h] of [[1366, 768], [1024, 768]]) {
    test(`caisse ${w}×${h} : la ligne ajoutée reste visible sur le ticket`, async ({ page }) => {
        test.setTimeout(180_000);
        await page.setViewportSize({ width: w, height: h });
        await ouvrirLaCaisse(page);
        const constats = [];
        try {
            for (const nom of PRODUITS) {
                const { avant, apres } = await ajouter(page, nom);
                const d = await derniereLigne(page);
                constats.push({ nom, avant, apres, ...d });
            }
            // La pastille : en haut du ticket, elle annonce les lignes cachées ; en bas, elle disparaît.
            const pastille = page.locator('[data-testid="pos-cart-hidden-below"]');
            await page.evaluate(() => { const c = document.querySelector('.pos-v5-cart__body'); c.scrollTo({ top: 0, behavior: 'auto' }); });
            await page.waitForTimeout(400);
            const enHaut = await pastille.count() ? (await pastille.innerText()).trim() : '';
            await page.screenshot({ path: `test-results/finition-ticket-pastille-${w}x${h}.png` });
            await page.evaluate(() => { const c = document.querySelector('.pos-v5-cart__body'); c.scrollTo({ top: c.scrollHeight, behavior: 'auto' }); });
            await page.waitForTimeout(400);
            const enBas = await pastille.count();
            constats.push({ pastilleEnHaut: enHaut, pastilleEnBas: enBas });
        } finally {
            // Vider le panier : aucune commande n'est créée par ce banc.
            await page.evaluate(() => {
                const b = [...document.querySelectorAll('button')].find((x) => /vider|tout supprimer/i.test(x.innerText || x.getAttribute('aria-label') || '') && x.offsetParent);
                if (b) b.click();
            });
            await page.waitForTimeout(500);
            await page.evaluate(() => {
                const ok = [...document.querySelectorAll('button')].find((x) => /^(oui|confirmer|vider)/i.test((x.innerText || '').trim()) && x.offsetParent && x.closest('[role="dialog"], .modal, .swal2-popup'));
                if (ok) ok.click();
            });
        }
        console.log(`${w}×${h}`, JSON.stringify(constats));
        const p = constats.find((c) => 'pastilleEnHaut' in c);
        expect(p.pastilleEnHaut, 'en haut du ticket, la pastille annonce les lignes cachées').toMatch(/^↓ \d+ lignes? en dessous$/);
        expect(p.pastilleEnBas, 'tout en bas, plus rien n’est caché : pas de pastille').toBe(0);
        const ajoutes = constats.filter((c) => c.apres > c.avant);
        expect(ajoutes.length, 'au moins 4 des 5 ajouts doivent créer une ligne (sinon le banc ne mesure rien)').toBeGreaterThanOrEqual(4);
        for (const c of ajoutes) {
            expect(c.visibleEntiere, `après l'ajout de « ${c.nom} », la dernière ligne du ticket est visible en entier (${JSON.stringify(c)})`).toBe(true);
        }
    });
}
