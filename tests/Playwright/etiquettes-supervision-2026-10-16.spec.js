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
const SHOTS = process.env.SUP_SHOTS || path.join(ROOT, 'tests', 'captures', 'etiquettes-supervision-2026-10-16');
const SEED = path.join(__dirname, 'helpers', 'seed-label-orders.php');
fs.mkdirSync(SHOTS, { recursive: true });

function php(args) {
    return execFileSync('php', [SEED, ...args], { cwd: ROOT, encoding: 'utf8', timeout: 30000 });
}
const seed = (ids) => JSON.parse(php(['seed', ids.join(',')]));

/** Fausse Brother QL-820NWB sur Bluetooth (profil SPP). */
async function installFakePrinter(context) {
    await context.addInitScript({ path: path.join(__dirname, 'helpers', 'brotherDecode.js') });
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

const SQL = (q) => execFileSync('mysql', ['-uroot', 'foodking_e2e', '-N', '-e', q], { encoding: 'utf8' });
const manifest = [];
const problems = [];

/** Le quatuor d'artefacts d'un état : PNG + DOM + console + réseau. */
async function snap(page, name, extra = {}) {
    await page.waitForTimeout(700);
    const dir = path.join(SHOTS, name);
    fs.mkdirSync(dir, { recursive: true });
    await page.screenshot({ path: path.join(dir, 'screen.png'), fullPage: true });
    fs.writeFileSync(path.join(dir, 'dom.html'), await page.content());
    const text = await page.locator('body').innerText();
    fs.writeFileSync(path.join(dir, 'text.txt'), text);
    fs.writeFileSync(path.join(dir, 'console.json'), JSON.stringify(page.__console || [], null, 1));
    fs.writeFileSync(path.join(dir, 'network.json'), JSON.stringify(page.__net || [], null, 1));
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    const small = await page.evaluate(() => [...document.querySelectorAll('.db-card-body button, .db-card-body a.lbl-btn, .db-card-body input, .db-card-body select')]
        .filter((e) => e.offsetParent !== null).map((e) => ({ t: (e.innerText || e.getAttribute('aria-label') || e.id || '').trim().slice(0, 40), w: Math.round(e.getBoundingClientRect().width), h: Math.round(e.getBoundingClientRect().height) }))
        .filter((x) => x.h < 44 || x.w < 44));
    const rawKeys = (text.match(/\b(?:label|menu|kds|kiosk|pos|admin)\.[a-z_]+(?:\.[a-z_]+)*\b/g) || []);
    fs.writeFileSync(path.join(dir, 'probe.json'), JSON.stringify({ name, overflowPx: overflow, smallTargets: small, rawKeys, ...extra }, null, 1));
    manifest.push(name);
}

test.beforeAll(() => {
    php(['clean']);
    SQL("delete from dlc_label_products where name like '%(sup)'; delete from label_print_logs where product_name like '%(sup)';");
});
test.afterAll(() => {
    php(['clean']);
    SQL("delete from dlc_label_products where name like '%(sup)'; delete from label_print_logs where product_name like '%(sup)';");
    fs.writeFileSync(path.join(SHOTS, 'manifest.json'), JSON.stringify(manifest, null, 1));
});

test.beforeEach(async ({ page, context }) => {
    page.__console = [];
    page.__net = [];
    page.on('console', (m) => page.__console.push({ type: m.type(), text: m.text().slice(0, 400) }));
    page.on('pageerror', (e) => { page.__console.push({ type: 'pageerror', text: String(e.message).slice(0, 400) }); problems.push(e.message); });
    page.on('response', (r) => { if (r.status() >= 400) page.__net.push({ status: r.status(), url: r.url().replace(BASE, '') }); });
    page.on('requestfailed', (r) => page.__net.push({ failed: r.failure() && r.failure().errorText, url: r.url().replace(BASE, '') }));
    await installFakePrinter(context);
});

for (const [vp, w, h] of [['paysage', 1280, 800], ['portrait', 800, 1280]]) {
    test(`supervision ${vp} · imprimante`, async ({ page }) => {
        test.setTimeout(150000);
        await page.setViewportSize({ width: w, height: h });
        await login(page);
        await page.goto(`${BASE}/admin/labels/printer`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 30000 });
        await snap(page, `printer-${vp}-prete`);
        await page.evaluate(() => { window.__lbl.mode = 'wrong_media'; });
        await expect(page.getByTestId('label-printer-adopt-media')).toBeVisible({ timeout: 20000 });
        await snap(page, `printer-${vp}-rouleau-detecte`);
        await page.getByTestId('label-printer-adopt-media').click();
        await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 20000 });
        await page.evaluate(() => { window.__lbl.mode = 'cover_open'; });
        await expect(page.getByTestId('label-printer-title')).toHaveText('Capot ouvert', { timeout: 20000 });
        await snap(page, `printer-${vp}-capot`);
    });

    test(`supervision ${vp} · DLC`, async ({ page }) => {
        test.setTimeout(150000);
        await page.setViewportSize({ width: w, height: h });
        SQL("delete from dlc_label_products where name like '%(sup)'; delete from label_print_logs where product_name like '%(sup)';");
        await login(page);
        await page.goto(`${BASE}/admin/labels/dlc`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 30000 });
        await expect(page.getByTestId('dlc-empty')).toBeVisible({ timeout: 15000 });
        await snap(page, `dlc-${vp}-vide`);

        const names = ['Oignons émincés', 'Tomates tranchées', 'Olives noires', 'Champignons', 'Salade lavée', 'Fromage râpé', 'Sauce blanche', 'Poivrons', 'Cornichons', 'Jambon', 'Maïs'];
        names.forEach((n, i) => SQL(`insert into dlc_label_products (branch_id,name,shelf_life_value,shelf_life_unit,storage,sort_order,is_active,created_at,updated_at) values (1,'${n} (sup)',${i % 2 ? 24 : 2},'${i % 2 ? 'hours' : 'days'}',${i % 3 ? "'+4 °C'" : 'NULL'},0,1,now(),now())`));
        const idOf = (n) => SQL(`select id from dlc_label_products where name='${n} (sup)'`).trim();
        // usage : « Tomates » le plus utilisées, puis « Olives »
        const log = (n, copies, exp) => SQL(`insert into label_print_logs (branch_id,user_id,kind,dlc_product_id,product_name,shelf_life_value,shelf_life_unit,opened_at,expires_at,copies,status,claimed_at,finished_at,created_at,updated_at) values (1,1,'dlc',${idOf(n)},'${n} (sup)',24,'hours',now(),${exp},${copies},'printed',now(),now(),now(),now())`);
        log('Tomates tranchées', 8, 'now() + interval 5 hour');
        log('Olives noires', 3, 'now() - interval 3 hour');
        log('Oignons émincés', 2, 'now() + interval 30 hour');
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('[data-testid^="dlc-product-"]').first()).toBeVisible({ timeout: 20000 });
        await snap(page, `dlc-${vp}-produits`);
        await page.getByTestId('dlc-search').fill('olive');
        await snap(page, `dlc-${vp}-recherche`);
        await page.getByTestId('dlc-search').fill('zzz');
        await snap(page, `dlc-${vp}-recherche-vide`);
        await page.getByTestId('dlc-search').fill('');
        await page.getByTestId('dlc-mode-list').click();
        await page.locator('[data-testid^="dlc-product-"]').nth(0).click();
        await page.locator('[data-testid^="dlc-product-"]').nth(0).click();
        await page.locator('[data-testid^="dlc-product-"]').nth(1).click();
        await snap(page, `dlc-${vp}-liste-du-jour`);
        await page.getByTestId('dlc-basket-print').click();
        await expect.poll(async () => (await jobs(page)).length, { timeout: 40000 }).toBe(2);
        await snap(page, `dlc-${vp}-apres-impression`);
        if (!(await page.getByTestId('dlc-form').isVisible())) await page.getByTestId('dlc-manage-toggle').click();
        await snap(page, `dlc-${vp}-gestion`);
    });

    test(`supervision ${vp} · commandes`, async ({ page }) => {
        test.setTimeout(240000);
        await page.setViewportSize({ width: w, height: h });
        php(['clean']);
        await login(page);
        await page.goto(`${BASE}/admin/labels/orders`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByTestId('label-printer-title')).toHaveText('Imprimante prête', { timeout: 30000 });
        await expect(page.getByTestId('label-orders-empty')).toBeVisible({ timeout: 20000 });
        await snap(page, `orders-${vp}-vide`);

        const seeded = seed([7593, 6101, 6277]);
        await expect(page.getByTestId(`label-order-${seeded[2].id}`)).toBeVisible({ timeout: 30000 });
        await snap(page, `orders-${vp}-a-imprimer`);
        await page.getByTestId(`label-preview-${seeded[2].id}`).click();
        await snap(page, `orders-${vp}-apercu`);
        await page.getByTestId(`label-print-one-${seeded[2].id}-2`).click();
        await expect.poll(async () => (await jobs(page)).length, { timeout: 30000 }).toBe(1);
        expect((await jobs(page))[0].pages, 'une seule étiquette réimprimée').toBe(1);
        // ORD-001 : UNE étiquette sur quatre ne marque PAS la commande « imprimée »
        await expect(page.getByTestId(`label-order-${seeded[2].id}`).getByTestId('label-order-state')).toHaveText('À imprimer', { timeout: 15000 });
        await page.getByTestId(`label-preview-${seeded[2].id}`).click();
        await page.getByTestId('label-print-all').click();
        await expect(page.getByTestId('label-print-all')).toContainText('(0)', { timeout: 60000 });
        await snap(page, `orders-${vp}-imprimees`);

        // automatique + imprimante en défaut + commande qui arrive : bloqué
        await page.getByTestId('label-auto-toggle').click();
        await page.evaluate(() => { window.__lbl.mode = 'cover_open'; });
        await expect(page.getByTestId('label-printer-title')).toHaveText('Capot ouvert', { timeout: 25000 });
        const fresh = seed([7593]);
        await expect(page.getByTestId(`label-order-${fresh[0].id}`)).toBeVisible({ timeout: 30000 });
        await expect(page.getByTestId('label-blocked')).toBeVisible({ timeout: 20000 });
        await snap(page, `orders-${vp}-bloque`);
        php(['clean']);
    });
}

test('supervision · aucune erreur de page', async () => {
    expect(problems, problems.join('\n')).toEqual([]);
});
