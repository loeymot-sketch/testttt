// FoodKing E2E — FIX-6 : VÉRIFICATION VISUELLE du suivi caisse après correctifs
// =============================================================================
// Mandat visuel CLAUDE.md §6 : un test unitaire vert ne prouve pas que l'écran est
// correct. Cette spec SÈME les cas exacts relevés par la vague A du superviseur
// (préfixe EXCLUSIF `FIX6-`), ouvre le suivi, et capture :
//   01 — le tableau avec les trois canaux côte à côte (couleurs de pastille)
//   02 — la carte téléphone (numéro + lien tel:)
//   03 — l'état vide sous filtre « Téléphone »
//   04 — le panneau « en souffrance » (bouton rafraîchir nommé)
// Puis elle nettoie ce qu'elle a semé.

const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const REPO_ROOT = path.resolve(__dirname, '..', '..');
const SHOTS_DIR = path.join(REPO_ROOT, 'tests', 'e2e', '__screenshots__', 'fix6-suivi-canal');
const TRACKER_URL = '/admin/pos-orders-tracker';
const TOKEN = 'FIX6';

function php(snippet) {
    const res = spawnSync('php', ['artisan', 'tinker', '--execute', snippet], {
        cwd: REPO_ROOT, encoding: 'utf8', timeout: 90_000,
    });
    return (res.stdout || '') + (res.stderr || '');
}

function seedOrdre({ suffix, sourceSurface, statusInt, typeInt, lignes, posName = null, posPhone = null }) {
    const lignesPhp = lignes.map((l) => {
        const snap = JSON.stringify({
            schema_version: 1, lines: l.options || [], extras: l.extras || [], addons: [],
        }).replace(/'/g, "\\'");
        const aQuelqueChose = (l.options || []).length || (l.extras || []).length;
        return `$i = new App\\Models\\OrderItem; $i->order_id = $o->id; $i->branch_id = 1; `
            + `$i->item_id = ${l.itemId}; $i->quantity = ${l.qty}; $i->price = 6.50; `
            + `$i->total_price = ${(l.qty * 6.5).toFixed(2)}; $i->discount = 0; $i->tax_rate = '0'; `
            + `$i->tax_amount = 0; $i->tax_type = 1; $i->item_variation_total = 0; $i->item_extra_total = 0; `
            + (aQuelqueChose ? `$i->composition_snapshot = json_decode('${snap}', true); ` : '')
            + `$i->save(); `;
    }).join('');

    const snippet = `$o = new App\\Models\\Order; $o->order_serial_no = '${TOKEN}-${suffix}'; `
        + `$o->user_id = 1; $o->branch_id = 1; $o->subtotal = 19.40; $o->total = 19.40; `
        + `$o->order_type = ${typeInt}; $o->source_surface = '${sourceSurface}'; `
        + `$o->order_datetime = now('UTC'); $o->preparation_time = 5; `
        + `$o->is_advance_order = App\\Enums\\Ask::NO; $o->payment_method = 1; $o->payment_status = 5; `
        + `$o->status = ${statusInt}; `
        + (posName ? `$o->pos_customer_name = '${posName.replace(/'/g, "\\'")}'; ` : '')
        + (posPhone ? `$o->pos_customer_phone = '${posPhone}'; ` : '')
        + `$o->business_date = now()->toDateString(); $o->save(); ${lignesPhp}echo 'SEEDED:' . $o->id;`;

    const out = php(snippet);
    const m = out.match(/SEEDED:(\d+)/);
    if (!m) console.warn(`[FIX6] semis ${suffix} KO :\n${out.slice(-600)}`);
    return m ? parseInt(m[1], 10) : null;
}

function cleanup() {
    php(`App\\Models\\Order::withoutGlobalScopes()->where('order_serial_no', 'like', '${TOKEN}-%')->get()->each(function ($o) { `
        + `App\\Models\\OrderItem::withoutGlobalScopes()->where('order_id', $o->id)->forceDelete(); `
        + `DB::table('orders')->where('id', $o->id)->update(['fiscal_sequence_no' => null]); `
        + `$o->forceDelete(); }); echo 'CLEANED';`);
}

test.describe.configure({ mode: 'serial' });

test('FIX-6 — le suivi caisse après correctifs : canaux, numéro, état vide, bouton nommé', async ({ page }) => {
    test.setTimeout(180_000);
    fs.mkdirSync(SHOTS_DIR, { recursive: true });
    cleanup();

    // Trois canaux côte à côte, pour comparer les pastilles À L'ŒIL.
    seedOrdre({
        suffix: 'COMPO', sourceSurface: 'pos', statusInt: 4, typeInt: 15,
        lignes: [{
            itemId: 1, qty: 2,
            options: [
                { attribute_name: 'Pain', variation_name: 'Galette' },
                { attribute_name: 'Sauce', variation_name: 'Algerienne' },
                { attribute_name: 'Cuisson', variation_name: 'Bien cuit' },
            ],
            extras: [{ name: 'Cheddar', quantity: 2 }, { name: 'Salade' }],
        }],
    });
    seedOrdre({ suffix: 'UBER', sourceSurface: 'uber_eats', statusInt: 4, typeInt: 10, lignes: [{ itemId: 1, qty: 1 }] });
    seedOrdre({
        suffix: 'TEL', sourceSurface: 'phone', statusInt: 4, typeInt: 10,
        posName: 'Karim Bensalah', posPhone: '0612345678',
        lignes: [{ itemId: 1, qty: 1 }],
    });

    await loginAsAdmin(page);
    await page.goto(TRACKER_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.pos-tracker-grid', { timeout: 30_000 });
    await page.waitForTimeout(2500);

    await page.screenshot({ path: path.join(SHOTS_DIR, '01-tableau.png'), fullPage: false });

    // --- A-002 : chaque canal a SA couleur, calculée par le navigateur.
    const fonds = await page.evaluate(() => {
        const out = {};
        document.querySelectorAll('.pos-tracker-card-source').forEach((el) => {
            const canal = [...el.classList].find((c) => c.startsWith('pos-tracker-card-source--'));
            if (canal) out[canal.replace('pos-tracker-card-source--', '')] = getComputedStyle(el).backgroundColor;
        });
        return out;
    });
    console.log('[FIX6] fonds de pastille calculés :', JSON.stringify(fonds));
    const canauxVus = Object.keys(fonds);
    expect(canauxVus.length, 'au moins deux canaux à comparer').toBeGreaterThan(1);
    expect(new Set(Object.values(fonds)).size, `canaux confondus : ${JSON.stringify(fonds)}`).toBe(canauxVus.length);

    // --- A-002 : nom accessible de la pastille téléphone.
    const nomPastille = await page.evaluate(() => {
        const el = document.querySelector('.pos-tracker-card-source--phone');
        return el ? el.textContent.trim() : null;
    });
    console.log('[FIX6] nom accessible pastille téléphone :', JSON.stringify(nomPastille));
    expect(nomPastille || '').toContain('Téléphone');

    // --- A-006 : la composition n'est plus rognée par le navigateur. Preuve
    // GÉOMÉTRIQUE, pas déclarative : on compare le contenu à la boîte qui l'affiche,
    // et le texte rendu à celui du `title` (qui portait seul la vérité auparavant).
    const compo = await page.evaluate(() => {
        const el = document.querySelector('.pos-tracker-card-compo');
        if (!el) return null;
        return {
            rendu: el.textContent.trim(),
            title: (el.getAttribute('title') || '').trim(),
            debordeX: el.scrollWidth - el.clientWidth,
            debordeY: el.scrollHeight - el.clientHeight,
        };
    });
    console.log('[FIX6] composition rendue :', JSON.stringify(compo));
    expect(compo, 'aucune ligne de composition à l\'écran').not.toBeNull();
    expect(compo.rendu, 'le texte affiché ne dit plus la même chose que le title').toBe(compo.title);
    expect(compo.debordeX, `la composition déborde encore de ${compo.debordeX}px horizontalement`).toBeLessThanOrEqual(1);
    expect(compo.debordeY, `la composition déborde encore de ${compo.debordeY}px verticalement`).toBeLessThanOrEqual(1);
    expect(compo.rendu, 'les extras PAYANTS restent invisibles').toContain('+2 Cheddar');
    expect(compo.rendu).toContain('+Salade');

    // --- A-012 : le numéro est LÀ, et cliquable.
    const carteTel = page.locator('.pos-tracker-card', { hasText: 'FIX6-TEL' }).first();
    const carteVisible = await carteTel.count();
    if (carteVisible) {
        await carteTel.scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(SHOTS_DIR, '02-carte-telephone.png') });
    }
    const lienTel = await page.evaluate(() => {
        const a = document.querySelector('a[href^="tel:"]');
        return a ? { href: a.getAttribute('href'), texte: a.textContent.trim() } : null;
    });
    console.log('[FIX6] lien tel: sur la carte téléphone :', JSON.stringify(lienTel));
    expect(lienTel, 'aucun lien tel: — le canal téléphone reste non rappelable').not.toBeNull();
    expect(lienTel.href).toContain('0612345678');

    // --- A-011 : état vide sous filtre canal.
    const ongletTel = page.locator('.pos-tracker-shell button', { hasText: 'Téléphone' }).first();
    if (await ongletTel.count()) {
        await ongletTel.click();
        await page.waitForTimeout(800);
        await page.screenshot({ path: path.join(SHOTS_DIR, '03-filtre-telephone.png') });

        const phrases = await page.evaluate(() =>
            [...document.querySelectorAll('.pos-tracker-col-empty p')].map((p) => p.textContent.trim()));
        console.log('[FIX6] phrases d\'état vide sous filtre :', JSON.stringify(phrases, null, 1));
        expect(phrases.length).toBeGreaterThan(0);
        phrases.forEach((p) => {
            expect(p, 'un état vide affirme encore un absolu sous filtre').not.toBe('Aucune commande livrée pour l\'instant.');
            expect(p).toContain('Téléphone');
        });

        const sortie = await page.evaluate(() => {
            const b = document.querySelector('[data-testid="tracker-empty-reset"]');
            return b ? b.textContent.trim() : null;
        });
        console.log('[FIX6] sortie proposée :', JSON.stringify(sortie));
        expect(sortie, 'aucune sortie depuis un couloir vide filtré').toBeTruthy();

        await page.locator('[data-testid="tracker-empty-reset"]').first().click();
        await page.waitForTimeout(600);
    }

    // --- A-003 : le bouton rafraîchir a un nom.
    const pilule = page.locator('[data-testid="tracker-stale-pill"]');
    if (await pilule.count()) {
        await pilule.click();
        await page.waitForTimeout(1500);
        const refresh = page.locator('[data-testid="tracker-stale-refresh"]');
        await refresh.scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(SHOTS_DIR, '04-panneau-souffrance.png') });
        const nom = await refresh.getAttribute('aria-label');
        console.log('[FIX6] nom du bouton rafraîchir :', JSON.stringify(nom));
        expect((nom || '').toLowerCase()).toContain('rafra');
    }

    // --- Aucun bouton anonyme sur le suivi. Le balayage est SCOPÉ à
    // `.pos-tracker-shell` : la Debugbar Laravel (`sf-dump-*`, `close-db-menu`)
    // injecte ses propres boutons sans nom en dev, ils ne sont pas l'écran audité.
    const anonymes = await page.evaluate(() =>
        [...document.querySelectorAll('.pos-tracker-shell button')]
            .filter((b) => !((b.getAttribute('aria-label') || '').trim() || b.textContent.trim() || (b.getAttribute('title') || '').trim()))
            .map((b) => b.className));
    console.log('[FIX6] boutons sans aucun nom :', JSON.stringify(anonymes));
    expect(anonymes.length, `boutons anonymes restants : ${JSON.stringify(anonymes)}`).toBe(0);

    cleanup();
});
