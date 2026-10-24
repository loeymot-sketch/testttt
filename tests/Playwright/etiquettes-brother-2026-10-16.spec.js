// [ETIQUETTES 2026-10-16, owner : « imprimante Brother QL-820NWB — DLC / hygiène et une étiquette par
// produit de commande, branchée en Bluetooth sur la tablette, semi-automatique puis automatique »]
//
// INSTRUMENT. Un faux `navigator.serial` joue la Brother : il ENREGISTRE chaque octet reçu, répond aux
// demandes d'état (ESC i S) avec une vraie trame de 32 octets, et confirme chaque page imprimée comme le
// ferait la machine. Les octets sont ensuite décodés par un décodeur INDÉPENDANT (écrit d'après le manuel)
// et l'image reconstruite est comparée, point par point, à l'APERÇU affiché à l'écran.
//
// Ce que cela prouve : l'écran, le rendu, l'encodeur, le gestionnaire Bluetooth et le serveur (journal,
// verrou) fonctionnent ensemble. Ce que cela NE prouve PAS : le papier. Le sens physique (G à gauche) et
// l'appairage Bluetooth d'Android se vérifient sur la machine — d'où l'étiquette test.
//
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/etiquettes-brother-2026-10-16.spec.js --workers=1
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { decodeJob, pageToBitmap } = require('./helpers/brotherDecode.js');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.LABEL_SHOTS || path.join(ROOT, 'tests', 'captures', 'etiquettes-brother-2026-10-16');
const SEED = path.join(__dirname, 'helpers', 'seed-label-orders.php');
fs.mkdirSync(SHOTS, { recursive: true });

function php(args) {
    return execFileSync('php', [SEED, ...args], { cwd: ROOT, encoding: 'utf8', timeout: 30000 });
}
const seed = (ids) => JSON.parse(php(['seed', ids.join(',')]));

/** Fausse Brother QL-820NWB sur Bluetooth (profil SPP). */
async function installFakePrinter(context) {
    await context.addInitScript({ path: path.join(__dirname, 'helpers', 'brotherDecode.js') });
    // La barre de débogage Laravel (développement seulement, absente en production) se pose en bas d'écran par-dessus les
    // boutons fixes des fenêtres : masquée, comme dans audit-dashboard-comptable-2026-08-29.spec.js.
    await context.addInitScript(() => {
        document.addEventListener('DOMContentLoaded', () => {
            const st = document.createElement('style');
            st.textContent = '.phpdebugbar, #phpdebugbar, .phpdebugbar-closed { display: none !important; }';
            document.head.appendChild(st);
        });
    });
    await context.addInitScript(() => {
        const SPP = '00001101-0000-1000-8000-00805f9b34fb';
        const L = (window.__lbl = {
            mode: 'ready', // ready | cover_open | wrong_media | silent
            granted: true,
            requestPortCalls: 0,
            openOpts: null,
            jobs: [],
            statusRequests: 0,
            chunks: [],
        });
        let controller;
        let current = [];
        const frame = ({ err1 = 0, err2 = 0, width = 62, type = 0x4a, length = 0, statusType = 0x00, phase = 0 } = {}) => {
            const f = new Uint8Array(32);
            f.set([0x80, 0x20, 0x42, 0x34, 0x41, 0x30, 0x30, 0x00, err1, err2, width, type, 0, 0, 0x3f, 0, 0, length, statusType, phase, 0, 0, 0, 0]);
            return f;
        };
        const statusFor = (mode) => ({
            ready: frame(),
            cover_open: frame({ err2: 0x10 }),
            wrong_media: frame({ width: 29, type: 0x4b, length: 90 }),
        }[mode] || null);
        L.fire = (name) => { (L._handlers[name] || []).forEach((fn) => fn({ target: port })); };
        L._handlers = {};
        const port = {
            readable: null,
            writable: null,
            getInfo: () => ({ bluetoothServiceClassId: SPP }),
            async open(opts) {
                L.openOpts = opts;
                port.readable = new ReadableStream({ start(c) { controller = c; }, cancel() {} });
                port.writable = new WritableStream({
                    write(chunk) {
                        const bytes = Uint8Array.from(chunk);
                        L.chunks.push(bytes.length);
                        if (bytes.length === 3 && bytes[0] === 0x1b && bytes[1] === 0x69 && bytes[2] === 0x53) {
                            L.statusRequests++;
                            const s = statusFor(L.mode);
                            if (s) controller.enqueue(s);
                            return;
                        }
                        const merged = new Uint8Array(current.length + bytes.length);
                        merged.set(current, 0);
                        merged.set(bytes, current.length);
                        current = merged;
                        const d = window.BrotherDecode.decodeJob(current);
                        if (d.error) { L.jobs.push({ error: d.error, bytes: Array.from(current) }); current = []; return; }
                        if (d.complete) {
                            L.jobs.push({ pages: d.pages.length, bytes: Array.from(current) });
                            current = [];
                            if (L.mode !== 'silent') {
                                // l'imprimante imprime puis confirme chaque page
                                setTimeout(() => { for (let i = 0; i < d.pages.length; i++) controller.enqueue(frame({ statusType: 0x01 })); }, 30);
                            }
                        }
                    },
                });
            },
            async close() { port.readable = null; port.writable = null; },
        };
        const fake = {
            async getPorts() { return L.granted ? [port] : []; },
            async requestPort() { L.requestPortCalls++; L.granted = true; return port; },
            addEventListener(name, fn) { (L._handlers[name] = L._handlers[name] || []).push(fn); },
        };
        Object.defineProperty(Navigator.prototype, 'serial', { get: () => fake, configurable: true });
    });
}

async function login(page, email = 'admin@lecayenne.fr') {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#formEmail')).toBeVisible({ timeout: 30000 });
    await page.fill('#formEmail', email);
    await page.fill('#formPassword', '123456');
    await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30000 });
    await page.waitForTimeout(1500);
}

const jobs = (page) => page.evaluate(() => window.__lbl.jobs.map((j) => ({ pages: j.pages, error: j.error || null })));
const jobBytes = (page, index) => page.evaluate((i) => window.__lbl.jobs[i].bytes, index);

/** Masque noir/blanc d'une image d'aperçu (data URL) — largeur, hauteur, chaîne de 0/1. */
async function previewMask(page, selector, nth = 0) {
    return page.evaluate(async ({ selector, nth }) => {
        const el = document.querySelectorAll(selector)[nth];
        if (!el) return null;
        const img = new Image();
        img.src = el.src;
        await img.decode();
        const c = document.createElement('canvas');
        c.width = img.naturalWidth; c.height = img.naturalHeight;
        const ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0);
        const d = ctx.getImageData(0, 0, c.width, c.height).data;
        let s = '';
        for (let i = 0; i < d.length; i += 4) s += d[i] < 128 ? '1' : '0';
        return { width: c.width, height: c.height, mask: s };
    }, { selector, nth });
}

function decodedMask(bytes, pageIndex) {
    const d = decodeJob(Uint8Array.from(bytes));
    if (d.error || !d.complete) throw new Error(`travail invalide : ${d.error || 'incomplet'}`);
    const bm = pageToBitmap(d.pages[pageIndex], 12, 696, true);
    return { width: bm.width, height: bm.height, mask: Array.from(bm.mask).join(''), pages: d.pages.length, info: d.pages[pageIndex].info };
}

async function savePng(page, name, bitmap) {
    const dataUrl = await page.evaluate(({ mask, width, height }) => {
        const c = document.createElement('canvas');
        c.width = width; c.height = height;
        const ctx = c.getContext('2d');
        const img = ctx.createImageData(width, height);
        for (let i = 0; i < mask.length; i++) {
            const v = mask[i] === '1' ? 0 : 255;
            img.data[i * 4] = v; img.data[i * 4 + 1] = v; img.data[i * 4 + 2] = v; img.data[i * 4 + 3] = 255;
        }
        ctx.putImageData(img, 0, 0);
        return c.toDataURL('image/png');
    }, bitmap);
    fs.writeFileSync(path.join(SHOTS, name), Buffer.from(dataUrl.split(',')[1], 'base64'));
}

async function apiToken(page) {
    return page.evaluate(() => {
        for (const k of Object.keys(localStorage)) {
            const v = localStorage.getItem(k);
            if (v && /^\d+\|[A-Za-z0-9]{30,}$/.test(v)) return v;
        }
        return (window.localStorage.getItem('token') || window.localStorage.getItem('auth_token') || '');
    });
}

test.describe.configure({ mode: 'serial' });

let seeded = [];
const problems = [];

test.beforeAll(() => {
    php(['clean']);
    execFileSync('mysql', ['-uroot', 'foodking_e2e', '-e', "delete from dlc_label_products where name like '%(test)'; delete from label_print_logs where kind='dlc' and product_name like '%(test)';"]);
});

test.afterAll(() => {
    php(['clean']);
    execFileSync('mysql', ['-uroot', 'foodking_e2e', '-e', "delete from dlc_label_products where name like '%(test)'; delete from label_print_logs where kind='dlc' and product_name like '%(test)';"]);
});

test.beforeEach(async ({ page, context }) => {
    page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
    page.on('console', (m) => {
        if (m.type() === 'error' && !/Failed to load resource|favicon|net::ERR/i.test(m.text())) problems.push(`console: ${m.text()}`);
    });
    await installFakePrinter(context);
});

test('1 · imprimante : reconnexion sans geste, état lu, diagnostic, étiquette test identique à l’aperçu', async ({ page }) => {
    test.setTimeout(120000);
    await page.setViewportSize({ width: 1280, height: 800 });
    await login(page);
    await page.goto(`${BASE}/admin/labels/printer`, { waitUntil: 'domcontentloaded' });

    const bar = page.getByTestId('label-printer-bar');
    await expect(bar).toBeVisible({ timeout: 30000 });
    await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 20000 });
    await expect(page.getByTestId('label-printer-detail')).toContainText('QL-820NWB · 62 mm continu');
    expect(await page.evaluate(() => window.__lbl.requestPortCalls), 'aucun geste requis : port déjà autorisé').toBe(0);
    expect(await page.evaluate(() => window.__lbl.openOpts.baudRate)).toBeGreaterThan(0);

    // diagnostic : le faux user-agent de test n'est pas Android, on contrôle seulement que les 4 lignes existent
    for (const k of ['secure', 'browser', 'version', 'serial']) await expect(page.getByTestId(`pr-diag-${k}`)).toBeVisible();
    await expect(page.getByTestId('pr-diag-serial')).toContainText('disponible');
    await page.screenshot({ path: path.join(SHOTS, '01-imprimante.png'), fullPage: true });

    await page.getByTestId('pr-test').click();
    await expect(page.getByTestId('pr-test-message')).toContainText('Étiquette test envoyée', { timeout: 20000 });
    const j = await jobs(page);
    expect(j).toEqual([{ pages: 1, error: null }]);

    const printed = decodedMask(await jobBytes(page, 0), 0);
    expect(printed.info).toMatchObject({ mediaType: 0x0a, widthMm: 62, lengthMm: 0, pageIndex: 0 });
    const preview = await previewMask(page, '[data-testid="pr-test-preview"]');
    expect(printed.width).toBe(preview.width);
    expect(printed.height).toBe(preview.height);
    expect(printed.mask === preview.mask, 'les octets envoyés reproduisent EXACTEMENT l’aperçu').toBe(true);
    expect(printed.mask.includes('1')).toBe(true);
    await savePng(page, '01b-etiquette-test-decodee.png', printed);
});

test('2 · imprimante en défaut : capot ouvert et mauvais rouleau bloquent l’envoi, avec un message clair', async ({ page }) => {
    test.setTimeout(120000);
    await page.setViewportSize({ width: 1280, height: 800 });
    await login(page);
    await page.goto(`${BASE}/admin/labels/printer`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 30000 });

    // capot ouvert : la barre dit LA panne, le test est indisponible (rien ne part), et le bouton « rouleau détecté »
    // n'est PAS proposé (le problème n'est pas le format — revue superviseur PR-001)
    await page.evaluate(() => { window.__lbl.mode = 'cover_open'; });
    await expect(page.getByTestId('label-printer-title')).toHaveText('Capot ouvert', { timeout: 20000 });
    await expect(page.getByTestId('label-printer-detail')).toContainText('Fermez le capot');
    await expect(page.getByTestId('pr-test')).toBeDisabled();
    await expect(page.getByTestId('label-printer-adopt-media')).toHaveCount(0);
    expect((await jobs(page)).length, 'rien n’est envoyé quand le capot est ouvert').toBe(0);
    await page.screenshot({ path: path.join(SHOTS, '02-capot-ouvert.png'), fullPage: true });

    // mauvais rouleau (29×90 chargé, 62 mm réglé) : message explicite, test indisponible, correction en UN appui
    await page.evaluate(() => { window.__lbl.mode = 'wrong_media'; });
    await expect(page.getByTestId('label-printer-title')).toHaveText('Mauvais rouleau', { timeout: 20000 });
    await expect(page.getByTestId('label-printer-detail')).toContainText('29 × 90 mm');
    await expect(page.getByTestId('label-printer-detail')).toContainText('62 mm continu');
    await expect(page.getByTestId('pr-test')).toBeDisabled();
    expect((await jobs(page)).length).toBe(0);
    await expect(page.getByTestId('label-printer-adopt-media')).toContainText('29 × 90 mm');
    await page.getByTestId('label-printer-adopt-media').click();
    await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 20000 });
    await expect(page.getByTestId('pr-media')).toHaveValue('d29x90');
    await page.getByTestId('pr-test').click();
    await expect(page.getByTestId('pr-test-message')).toContainText('Étiquette test envoyée', { timeout: 20000 });
    const j = await jobs(page);
    expect(j).toEqual([{ pages: 1, error: null }]);
    const d = decodeJob(Uint8Array.from(await jobBytes(page, 0)));
    expect(d.pages[0].info).toMatchObject({ mediaType: 0x0b, widthMm: 29, lengthMm: 90 });
    expect(d.pages[0].rows.length, 'étiquette prédécoupée 29×90 : 991 lignes').toBe(991);
    await page.evaluate(() => localStorage.removeItem('label_printer_settings'));
});

test('3 · DLC : fiche produit complète, un appui imprime l’événement, la DLC (jour en pastille), le lot — journalisés', async ({ page }) => {
    test.setTimeout(180000);
    await page.setViewportSize({ width: 1280, height: 800 });
    await login(page);
    await page.goto(`${BASE}/admin/labels/dlc`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 30000 });

    // aucun produit : appel à l'action → éditeur complet
    await page.getByTestId('dlc-add-first').click();
    await expect(page.getByTestId('dlc-editor')).toBeVisible();
    await page.getByTestId('dlc-ed-name').fill('Oignons émincés (test)');
    await page.getByTestId('dlc-ed-category').fill('Légumes & crudités');
    await page.getByTestId('dlc-ed-storage').fill('+4 °C');
    // fiche neuve « ouverture » : la DLC du fabricant est demandée par défaut ; légumes frais = sans emballage d'origine
    await expect(page.getByTestId('dlc-ed-ask-original')).toBeChecked();
    await page.getByTestId('dlc-ed-ask-original').uncheck();
    await page.getByTestId('dlc-ed-copies-plus').click(); // 2 étiquettes par appui
    await expect(page.getByTestId('dlc-ed-preview')).toBeVisible();
    await page.getByTestId('dlc-ed-save').click();
    await expect(page.getByTestId('dlc-editor')).toHaveCount(0);
    const tile = page.locator('[data-testid^="dlc-product-"]', { hasText: 'Oignons émincés (test)' });
    await expect(tile).toBeVisible({ timeout: 15000 });
    await expect(tile).toContainText('Ouverture · DLC +2 jours');

    // doublon refusé, message dans l'éditeur
    await page.getByTestId('dlc-new').click();
    await page.getByTestId('dlc-ed-name').fill('OIGNONS ÉMINCÉS (TEST)');
    await page.getByTestId('dlc-ed-save').click();
    // la conservation est obligatoire : l'éditeur le dit avant tout envoi, près du bouton
    await expect(page.getByTestId('dlc-ed-error')).toContainText('température de conservation');
    await page.getByTestId('dlc-ed-storage-1').click();
    await page.getByTestId('dlc-ed-save').click();
    await expect(page.getByTestId('dlc-ed-error')).toContainText('porte déjà ce nom');
    await page.getByTestId('dlc-ed-close').click();

    // un appui = l'étiquette, tout de suite, avec le nombre habituel du produit
    await tile.click();
    await expect(page.getByTestId('dlc-last')).toContainText('2 étiquettes', { timeout: 30000 });
    await expect(page.getByTestId('dlc-last')).toContainText('À consommer jusqu’au');
    await expect(page.getByTestId('dlc-last')).toContainText('N° L');

    const j = await jobs(page);
    expect(j).toEqual([{ pages: 2, error: null }]);
    const bytes = await jobBytes(page, 0);
    const p0 = decodedMask(bytes, 0);
    const p1 = decodedMask(bytes, 1);
    expect(p0.mask === p1.mask, 'les deux exemplaires sont identiques').toBe(true);
    const preview = await previewMask(page, '.dlc-last-img');
    expect(p0.width).toBe(preview.width);
    expect(p0.height).toBe(preview.height);
    expect(p0.mask === preview.mask, 'DLC imprimée == aperçu').toBe(true);
    await savePng(page, '03-dlc-decodee.png', p0);

    await expect(page.getByTestId('dlc-history')).toContainText('Oignons émincés (test) ×2');
    await expect(page.getByTestId('dlc-history')).toContainText('imprimée');
    const row = execFileSync('mysql', ['-uroot', 'foodking_e2e', '-N', '-e',
        "select status, copies, product_name, timestampdiff(day, opened_at, expires_at), action, lot like 'L%' from label_print_logs where product_name like '%(test)' order by id desc limit 1"], { encoding: 'utf8' }).trim().split('\t');
    expect(row).toEqual(['printed', '2', 'Oignons émincés (test)', '2', 'opened', '1']);
    await page.screenshot({ path: path.join(SHOTS, '03-dlc.png'), fullPage: true });

    // ── second produit, plusieurs événements : la fiche « ⋯ » choisit la décongélation ──────────
    await page.getByTestId('dlc-new').click();
    await page.getByTestId('dlc-ed-name').fill('Tomates tranchées (test)');
    await page.getByTestId('dlc-ed-value-opened').fill('24');
    await page.getByTestId('dlc-ed-unit-opened').selectOption('hours');
    await page.getByTestId('dlc-ed-action-thawed').check();
    await page.getByTestId('dlc-ed-value-thawed').fill('3');
    await page.getByTestId('dlc-ed-storage-2').click(); // ≤ −18 °C : surgelé
    // décongélation d'un surgelé : la conservation APRÈS décongélation est obligatoire (jamais une valeur imposée)
    await page.getByTestId('dlc-ed-save').click();
    await expect(page.getByTestId('dlc-ed-error')).toContainText('conservation après décongélation');
    await page.getByTestId('dlc-ed-thawed-2').click(); // 0 à +4 °C
    await expect(page.getByTestId('dlc-ed-thawed-note')).toContainText('NE PAS RECONGELER');
    await page.getByTestId('dlc-ed-ask-original').uncheck();
    await page.getByTestId('dlc-ed-save').click();
    const tile2 = page.locator('[data-testid^="dlc-product-"]', { hasText: 'Tomates tranchées (test)' });
    await expect(tile2).toBeVisible({ timeout: 15000 });
    await expect(tile2).toContainText('2 événements');
    const id2 = (await tile2.getAttribute('data-testid')).replace('dlc-product-', '');
    await page.getByTestId(`dlc-more-${id2}`).click();
    await expect(page.getByTestId('dlc-sheet')).toBeVisible();
    await page.getByTestId('dlc-sheet-action-thawed').click();
    await page.screenshot({ path: path.join(SHOTS, '03a-dlc-fiche.png'), fullPage: true });
    await page.getByTestId('dlc-sheet-print').click();
    await expect(page.getByTestId('dlc-sheet')).toHaveCount(0, { timeout: 30000 });
    await expect(page.getByTestId('dlc-last')).toContainText('Décongélation');
    await expect.poll(async () => (await jobs(page)).length, { timeout: 30000 }).toBe(2);

    // ── sélection multiple : tout sélectionner, retirer une, imprimer la sélection ──────────────
    await page.getByTestId('dlc-mode-list').click();
    await expect(page.getByTestId('dlc-basket'), 'panier masqué tant qu’il est vide').toHaveCount(0);
    await page.getByTestId('dlc-select-all').click();
    await expect(page.getByTestId('dlc-basket-total')).toHaveText('3 étiquettes'); // 2 (oignons) + 1 (tomates)
    const id1 = (await tile.getAttribute('data-testid')).replace('dlc-product-', '');
    await page.getByTestId(`dlc-minus-${id1}`).click();
    await expect(page.getByTestId('dlc-basket-total')).toHaveText('2 étiquettes');
    expect((await jobs(page)).length, 'rien ne part avant « Imprimer la sélection »').toBe(2);
    await page.screenshot({ path: path.join(SHOTS, '03b-dlc-selection.png'), fullPage: true });

    await page.getByTestId('dlc-basket-print').click();
    await expect.poll(async () => (await jobs(page)).length, { timeout: 40000 }).toBe(4);
    const all = await jobs(page);
    expect(all.slice(2).map((x) => x.pages).sort(), 'un travail par produit').toEqual([1, 1]);
    await expect(page.getByTestId('dlc-basket'), 'sélection vidée après impression').toHaveCount(0);
    await expect(page.getByTestId('dlc-last-batch')).toContainText('2 étiquettes imprimées');
    const tomato = decodedMask(await jobBytes(page, 1), 0);
    await savePng(page, '03c-dlc-tomates-decongelees-decodee.png', tomato);
    const counts = execFileSync('mysql', ['-uroot', 'foodking_e2e', '-N', '-e',
        "select product_name, copies, status, action, timestampdiff(hour, opened_at, expires_at) from label_print_logs where product_name like '%(test)' order by id"], { encoding: 'utf8' }).trim().split('\n');
    expect(counts.length).toBe(4);
    expect(counts[0]).toBe('Oignons émincés (test)\t2\tprinted\topened\t48');
    expect(counts[1]).toBe('Tomates tranchées (test)\t1\tprinted\tthawed\t72');
    // le journal porte la conservation IMPRIMÉE : froid positif pour la décongélation, congélateur pour l'ouverture
    const storages = execFileSync('mysql', ['-uroot', 'foodking_e2e', '-N', '-e',
        "select action, storage from label_print_logs where product_name = 'Tomates tranchées (test)' order by id"], { encoding: 'utf8' }).trim().split('\n');
    expect(storages).toEqual(['thawed\t0 à +4 °C', 'opened\t≤ −18 °C']);
    expect(counts.slice(2).sort()).toEqual([
        'Oignons émincés (test)\t1\tprinted\topened\t48',
        'Tomates tranchées (test)\t1\tprinted\topened\t24',
    ]);
});

test('4 · commandes : une étiquette par produit, k/N, aperçu == papier, imprimées une fois, réimpression possible', async ({ page }) => {
    test.setTimeout(180000);
    await page.setViewportSize({ width: 1280, height: 900 });
    // 7593 : Cayenne + menu · 6101 : 2 × Cayenne menu · 6277 : commande Uber (tacos + 2 × cayenne + frites)
    seeded = seed([7593, 6101, 6277]);
    expect(seeded).toHaveLength(3);
    await login(page);
    await page.goto(`${BASE}/admin/labels/orders`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 30000 });

    for (const s of seeded) await expect(page.getByTestId(`label-order-${s.id}`)).toBeVisible({ timeout: 30000 });
    const uber = seeded[2];
    const uberCard = page.getByTestId(`label-order-${uber.id}`);
    await expect(uberCard).toContainText(`N° ${uber.queue_number}`);
    await expect(uberCard.getByTestId('label-order-state')).toHaveText('À imprimer');
    await expect(uberCard).toContainText('4 étiquettes');

    // aperçu des 4 étiquettes, numérotées 1/4 … 4/4
    await page.getByTestId(`label-preview-${uber.id}`).click();
    await expect(uberCard.locator('.lbl-preview img')).toHaveCount(4);
    await expect(uberCard.locator('figcaption').nth(3)).toHaveText('4/4');
    await page.screenshot({ path: path.join(SHOTS, '04-commandes-apercu.png'), fullPage: true });

    await page.getByTestId(`label-print-${uber.id}`).click();
    await expect(uberCard.getByTestId('label-order-state')).toHaveText('✓ Imprimées', { timeout: 45000 });
    let j = await jobs(page);
    expect(j).toEqual([{ pages: 4, error: null }]);

    const bytes = await jobBytes(page, 0);
    for (let i = 0; i < 4; i++) {
        const printed = decodedMask(bytes, i);
        const preview = await previewMask(page, `[data-testid="label-order-${uber.id}"] .lbl-preview img`, i);
        expect(printed.height, `étiquette ${i + 1}/4`).toBe(preview.height);
        expect(printed.mask === preview.mask, `étiquette ${i + 1}/4 : papier == aperçu`).toBe(true);
        await savePng(page, `04-uber-${i + 1}sur4.png`, printed);
    }
    expect(decodeJob(Uint8Array.from(bytes)).pages.map((p) => p.info.pageIndex)).toEqual([0, 1, 1, 1]);

    // « Tout imprimer » ne touche pas ce qui est déjà sorti : 2 commandes restantes
    await expect(page.getByTestId('label-print-all')).toContainText('(2)');
    await page.getByTestId('label-print-all').click();
    await expect(page.getByTestId('label-print-all')).toContainText('(0)', { timeout: 60000 });
    j = await jobs(page);
    expect(j[0].pages).toBe(4);
    expect(j.slice(1).map((x) => x.pages).sort(), 'les deux autres commandes, chacune en un travail').toEqual([1, 2]);

    // réimprimer à la main est toujours permis
    await expect(uberCard.getByTestId(`label-print-${uber.id}`)).toContainText('Réimprimer');
    await page.getByTestId(`label-print-${uber.id}`).click();
    await expect.poll(async () => (await jobs(page)).length, { timeout: 30000 }).toBe(4);
    await page.screenshot({ path: path.join(SHOTS, '04-commandes-imprimees.png'), fullPage: true });

    // Le verdict de la 4ᵉ impression part APRÈS la fin du travail (file locale) : on attend qu'il soit inscrit au lieu de
    // lire la base à l'instant (course relevée le 2026-10-16 : « 4  3 » une fois sur trois juste après un redémarrage).
    const logs = () => execFileSync('mysql', ['-uroot', 'foodking_e2e', '-N', '-e',
        `select count(*), sum(status='printed') from label_print_logs where kind='order' and order_id in (${seeded.map((s) => s.id).join(',')})`], { encoding: 'utf8' }).trim();
    await expect.poll(logs, { timeout: 15000 }).toBe('4\t4');
});

test('5 · automatique : seules les commandes ARRIVÉES APRÈS l’activation s’impriment, une seule fois, et seulement imprimante prête', async ({ page }) => {
    test.setTimeout(240000);
    await page.setViewportSize({ width: 1280, height: 900 });
    php(['clean']);
    const old = seed([6277]); // déjà en cuisine AVANT l'activation : ne doit jamais s'imprimer seule
    await login(page);
    await page.goto(`${BASE}/admin/labels/orders`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 30000 });
    await expect(page.getByTestId(`label-order-${old[0].id}`)).toBeVisible({ timeout: 30000 });
    await page.waitForTimeout(1200);

    await page.getByTestId('label-auto-toggle').click();
    await expect(page.getByTestId('label-auto')).toContainText('activée');

    // imprimante en défaut : la commande qui arrive attend, rien ne part
    await page.evaluate(() => { window.__lbl.mode = 'cover_open'; });
    await page.waitForTimeout(9500); // un cycle de veille lit l'état (8 s)
    await expect(page.getByTestId('label-printer-title')).toHaveText('Capot ouvert', { timeout: 20000 });
    const fresh = seed([7593]);
    await expect(page.getByTestId(`label-order-${fresh[0].id}`)).toBeVisible({ timeout: 30000 });
    await page.waitForTimeout(7000);
    expect((await jobs(page)).length, 'imprimante en défaut : aucune impression').toBe(0);
    await expect(page.getByTestId(`label-order-${fresh[0].id}`).getByTestId('label-order-state')).toHaveText('À imprimer');
    await page.screenshot({ path: path.join(SHOTS, '05-auto-attente.png'), fullPage: true });

    // capot refermé → la commande s'imprime seule
    await page.evaluate(() => { window.__lbl.mode = 'ready'; });
    await expect(page.getByTestId(`label-order-${fresh[0].id}`).getByTestId('label-order-state')).toHaveText('✓ Imprimées', { timeout: 60000 });
    let j = await jobs(page);
    expect(j).toEqual([{ pages: 1, error: null }]);

    // une seconde commande arrive : elle s'imprime, UNE seule fois, même après plusieurs sondages
    const second = seed([6101]);
    await expect(page.getByTestId(`label-order-${second[0].id}`).getByTestId('label-order-state')).toHaveText('✓ Imprimées', { timeout: 60000 });
    await page.waitForTimeout(16000); // trois sondages de plus
    j = await jobs(page);
    expect(j.map((x) => x.pages), 'une commande = un travail, jamais deux').toEqual([1, 2]);
    // la commande d'avant l'activation n'a jamais été imprimée seule
    await expect(page.getByTestId(`label-order-${old[0].id}`).getByTestId('label-order-state')).toHaveText('À imprimer');

    // une AUTRE tablette (nouvelle page, même compte) ne réimprime pas ce qui est déjà réclamé
    const second_page = await page.context().newPage();
    await second_page.setViewportSize({ width: 1280, height: 900 });
    await second_page.goto(`${BASE}/admin/labels/orders`, { waitUntil: 'domcontentloaded' });
    await expect(second_page.getByTestId('label-auto-toggle')).toBeVisible({ timeout: 30000 });
    await second_page.getByTestId('label-auto-toggle').click();
    await second_page.waitForTimeout(12000);
    expect((await jobs(second_page)).length, 'la 2ᵉ tablette n’imprime rien de ce qui est déjà sorti').toBe(0);
    await second_page.close();
    await page.getByTestId('label-auto-toggle').click(); // coupe l'automatique
    await expect(page.getByTestId('label-auto')).toContainText('désactivée');
});

test('6 · tablette en portrait et paysage : écrans lisibles, aucun débordement horizontal, aucune erreur console', async ({ page }) => {
    test.setTimeout(180000);
    seed([7593, 6277]);
    await login(page);
    for (const [name, w, h] of [['paysage', 1280, 800], ['portrait', 800, 1280]]) {
        await page.setViewportSize({ width: w, height: h });
        for (const route of ['orders', 'dlc', 'printer']) {
            await page.goto(`${BASE}/admin/labels/${route}`, { waitUntil: 'domcontentloaded' });
            await expect(page.getByTestId('label-printer-bar')).toBeVisible({ timeout: 30000 });
            await page.waitForTimeout(1500);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, `${route} ${name} : débordement horizontal`).toBeLessThanOrEqual(1);
            await page.screenshot({ path: path.join(SHOTS, `06-${route}-${name}.png`), fullPage: true });
        }
    }
    expect(problems, `erreurs console/page :\n${problems.join('\n')}`).toEqual([]);
});

test('7 · menu latéral : la rubrique « Étiquettes » et ses trois écrans sont atteignables', async ({ page }) => {
    test.setTimeout(120000);
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page);
    await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const entry = page.locator('a, button').filter({ hasText: /^\s*Étiquettes\s*$/ }).first();
    await expect(entry).toBeVisible({ timeout: 20000 });
    await entry.click();
    await page.waitForTimeout(800);
    for (const label of ['Étiquettes commandes', 'Étiquettes DLC / hygiène', "Imprimante d'étiquettes"]) {
        await expect(page.locator('a', { hasText: label }).first()).toBeVisible();
    }
    await page.screenshot({ path: path.join(SHOTS, '07-menu.png') });
    await page.locator('a', { hasText: 'Étiquettes DLC / hygiène' }).first().click();
    await expect(page).toHaveURL(/\/admin\/labels\/dlc/);
    await expect(page.locator('.db-card-title')).toContainText('Étiquettes DLC');
    // fil d'Ariane : jamais de clé brute
    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(/\blabel\.labels_|menu\.labels_/);
});
