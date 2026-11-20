/**
 * [ESPACE ROUE 2026-11-19] Espace commerçant de la roue — parcours E2E réel + captures.
 *
 * Ce que ce banc PROUVE, par le navigateur, contre le serveur réel :
 *   1. la porte : sans code → écran de code ; code → l'espace ;
 *   2. chaque rubrique se rend (aucune clé brute, aucune erreur console) ;
 *   3. « Ma roue » : changer une chance → publier → le SERVEUR renvoie la nouvelle valeur ;
 *   4. « Mes cadeaux » : ajouter un vrai produit de la carte → publier → il est sur la roue
 *      PUBLIQUE (`/api/frontend/wheel/config` côté vitrine) ; le retirer → il disparaît ;
 *   5. « Avis & réseaux » : une adresse invalide est refusée avec un message français ; une
 *      adresse valide est enregistrée et relue après rechargement ;
 *   6. « Lots gagnés » : un mauvais code est refusé ; le bon numéro remet le lot (état « Remis ») ;
 *   7. « Réglages » : ouvrir puis suspendre le jeu change l'état affiché.
 *
 * Données : le lot à remettre est semé AVANT le banc par `tools/banc-espace-roue.sh`, qui sauve et
 * restaure aussi les réglages `wheel` / `pilotage` — le banc ne laisse rien derrière lui.
 *
 * Lancement : WHEEL_PIN=… PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *   npx playwright test tests/Playwright/espace-roue-2026-11-19.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const path = require('path');

const PIN = process.env.WHEEL_PIN || '';
const TEL = process.env.ESPACE_TEL || '0699887766';
const SORTIE = process.env.ESPACE_CAPTURES || path.join(__dirname, '..', 'captures', 'espace-roue');

test.describe.configure({ mode: 'serial', retries: 0 });
// actionTimeout vaut 0 dans ce dépôt : un clic sur un élément introuvable attendrait indéfiniment.
test.use({ actionTimeout: 15_000, navigationTimeout: 30_000 });
test.setTimeout(120_000);

async function ouvrir(page) {
    await page.goto('/admin/roue', { waitUntil: 'domcontentloaded' });
    if (await page.locator('input[name=pin]').count()) {
        await page.fill('input[name=pin]', PIN);
        await Promise.all([page.waitForURL(/\/admin\/roue\/espace/), page.locator('input[name=pin]').press('Enter')]);
    }
    await expect(page.locator('#chargement')).toBeHidden({ timeout: 30_000 });
}

async function donnees(page) {
    return page.evaluate(async () => (await (await fetch('/admin/roue/espace/donnees', { headers: { Accept: 'application/json' } })).json()).data);
}

// UNE seule ouverture par le code pour tout le banc : la porte a un anti-force-brute
// (`throttle:wheel-pin`) — se reconnecter à chaque test le déclenche (429), et ce serait
// l'instrument qui échoue, pas le produit.
const ETAT = path.join(require('os').tmpdir(), 'espace-roue-etat-' + process.pid + '.json');
test.beforeAll(async ({ browser }) => {
    if (!PIN) throw new Error('WHEEL_PIN manquant : le banc ne peut pas ouvrir la porte.');
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await ouvrir(page);
    await ctx.storageState({ path: ETAT });
    await ctx.close();
});
test.use({ storageState: async ({}, use) => { await use(require('fs').existsSync(ETAT) ? ETAT : undefined); } });

test('porte : sans code, pas d\'espace', async ({ browser }) => {
    // Contexte VIERGE explicite : `browser.newContext()` hérite sinon de l'état ouvert du banc.
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await ctx.newPage();
    const r = await page.request.get('/admin/roue/espace/donnees', { headers: { Accept: 'application/json' } });
    expect(r.status()).toBe(401);
    await page.goto('/admin/roue/espace');
    await expect(page).toHaveURL(/\/admin\/roue(\?|$)/);
    await expect(page.locator('input[name=pin]')).toBeVisible();
    await ctx.close();
});

test('chaque rubrique se rend, sans erreur console ni clé brute', async ({ page }) => {
    const erreurs = [];
    page.on('console', (m) => { if (m.type() === 'error' && !/fonts\.g/.test(m.text())) erreurs.push(m.text()); });
    page.on('pageerror', (e) => erreurs.push(String(e)));
    // Aucune réponse en erreur pendant la simple consultation : une 4xx/5xx avalée serait un échec muet.
    const reponsesKo = [];
    page.on('response', (r) => { if (r.status() >= 400 && !/fonts\.g/.test(r.url())) reponsesKo.push(r.status() + ' ' + r.url()); });
    await page.setViewportSize({ width: 1366, height: 900 });
    await ouvrir(page);

    const vues = ['tableau', 'roue', 'cadeaux', 'avis', 'comptoir', 'lots', 'stats', 'reglages'];
    for (const v of vues) {
        await page.goto('/admin/roue/espace#' + v);
        await expect(page.locator(`section[data-view=${v}]`)).toBeVisible();
        await page.waitForTimeout(500);
        const texte = await page.locator(`section[data-view=${v}]`).innerText();
        expect(texte).not.toMatch(/undefined|NaN|\[object Object\]|null €/);
        // Rien n'a été modifié : ni pastille « Brouillon », ni barre de publication.
        await expect(page.locator('#pillBrouillon')).toBeHidden();
        await expect(page.locator('#publish')).toBeHidden();
        await page.screenshot({ path: path.join(SORTIE, `desktop-${v}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    for (const v of ['tableau', 'cadeaux', 'lots']) {
        await page.goto('/admin/roue/espace#' + v);
        await page.waitForTimeout(500);
        const large = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        expect(large, `débordement horizontal sur ${v} (mobile)`).toBe(false);
        await page.screenshot({ path: path.join(SORTIE, `mobile-${v}.png`), fullPage: true });
    }
    expect(erreurs).toEqual([]);
    expect(reponsesKo).toEqual([]);
});

test('Ma roue : une chance modifiée est publiée et relue par le serveur', async ({ page }) => {
    await ouvrir(page);
    await page.goto('/admin/roue/espace#roue');
    const avant = await donnees(page);
    const premier = avant.lots.find((l) => !l.archived);
    const champ = page.locator('#tChances input[data-k=weight]').first();
    const nouvelle = premier.weight === 41 ? 42 : 41;
    await champ.fill(String(nouvelle));
    await expect(page.locator('#publish')).toHaveClass(/show/);
    await expect(page.locator('#legend')).toContainText('%');
    await page.click('#btnPublier');
    await expect(page.locator('.toast.ok').last()).toContainText('Roue publiée');
    const apres = await donnees(page);
    expect(apres.lots.find((l) => l.key === premier.key).weight).toBe(nouvelle);
});

test('Mes cadeaux : un produit de la carte ajouté apparaît sur la roue publique, puis disparaît une fois retiré', async ({ page }) => {
    await ouvrir(page);
    await page.goto('/admin/roue/espace#cadeaux');
    await page.click('[data-testid=esp-ajout-cadeau]');
    const carte = page.locator('#cadeauxActifs .lotcard').last();
    const select = carte.locator('select[data-k=cost_item_id]');
    // Un produit RÉEL de la carte : on prend la première option disponible (pas un nom inventé).
    const valeur = await select.locator('option[value]:not([value=""])').first().getAttribute('value');
    const nom = (await select.locator(`option[value="${valeur}"]`).innerText()).split(' — ')[0].trim();
    await select.selectOption(valeur);
    const label = 'E2E ' + Date.now().toString().slice(-5);
    await page.locator('#cadeauxActifs .lotcard').last().locator('input[data-k=label]').fill(label);
    await page.screenshot({ path: path.join(SORTIE, 'cadeau-ajoute-brouillon.png'), fullPage: true });
    await page.click('#btnPublier');
    await expect(page.locator('.toast.ok').last()).toContainText('Roue publiée');

    const d = await donnees(page);
    const cree = d.lots.find((l) => l.label === label);
    expect(cree, 'le cadeau créé est relu par le serveur').toBeTruthy();
    expect(cree.cost_item_id).toBe(Number(valeur));
    expect(cree.cost_item_name).toBe(nom);

    // Ce que lit le TÉLÉPHONE du client : l'API publique de la roue (clé de prévisualisation, le
    // jeu étant fermé au public en local). C'est la même liste que la tablette du comptoir.
    const pub = await page.request.get('/api/frontend/wheel/config?preview=' + encodeURIComponent(process.env.WHEEL_PREVIEW_KEY || ''), {
        headers: { Accept: 'application/json', 'X-API-KEY': process.env.MIX_API_KEY || '' },
    });
    expect(pub.status(), 'API publique de la roue').toBe(200);
    const segs = ((await pub.json()).data || {}).segments || (await pub.json()).segments || [];
    expect(segs.map((s) => s.label)).toContain(label);

    // Retirer ce cadeau jamais joué : il disparaît du catalogue.
    await page.goto('/admin/roue/espace#cadeaux');
    await expect(page.locator('#chargement')).toBeHidden();
    const sienne = page.locator('#cadeauxActifs .lotcard', { hasText: label });
    await sienne.locator('[data-act=retirer]').click();
    await page.click('#btnPublier');
    await expect(page.locator('.toast.ok').last()).toContainText('Roue publiée');
    const d2 = await donnees(page);
    expect(d2.lots.find((l) => l.label === label)).toBeFalsy();
});

test('Mes cadeaux : un produit offert sans produit choisi est refusé', async ({ page }) => {
    await ouvrir(page);
    await page.goto('/admin/roue/espace#cadeaux');
    await page.click('[data-testid=esp-ajout-cadeau]');
    await page.click('#btnPublier');
    await expect(page.locator('.toast.err').last()).toContainText('choisis le produit');
    await page.click('#btnAnnuler');
    await expect(page.locator('#publish')).not.toHaveClass(/show/);
});

test('Avis & réseaux : refus en français, puis enregistrement relu après rechargement', async ({ page }) => {
    await ouvrir(page);
    await page.goto('/admin/roue/espace#avis');
    const origine = await page.inputValue('#instagram_url');
    await page.fill('#instagram_url', 'ftp://pas-un-lien');
    await page.click('[data-testid=esp-save-liens]');
    await expect(page.locator('#errLiens')).toContainText(/adresse/i);

    await page.fill('#instagram_url', 'https://www.instagram.com/lecayenne_e2e');
    await page.click('[data-testid=esp-save-liens]');
    await expect(page.locator('.toast.ok').last()).toContainText('Liens enregistrés');
    await page.reload();
    await page.goto('/admin/roue/espace#avis');
    await expect(page.locator('#chargement')).toBeHidden();
    await expect(page.locator('#instagram_url')).toHaveValue('https://www.instagram.com/lecayenne_e2e');
    await expect(page.locator('#st_instagram_url')).toContainText('Votre lien');
    await expect(page.locator('#phoneSteps')).toContainText('Instagram');
    await expect(page.locator('#test_instagram_url')).toHaveAttribute('href', 'https://www.instagram.com/lecayenne_e2e');

    await page.fill('#instagram_url', origine);
    await page.click('[data-testid=esp-save-liens]');
    await expect(page.locator('.toast.ok').last()).toContainText('Liens enregistrés');
});

test('Lots gagnés : mauvais numéro refusé, bon numéro → lot remis', async ({ page }) => {
    await ouvrir(page);
    await page.goto('/admin/roue/espace#lots');
    const ligne = page.locator('#tLots tr', { hasText: TEL.slice(-4) }).first();
    await expect(ligne).toBeVisible({ timeout: 15_000 });
    await ligne.locator('[data-remettre]').click();
    // Un numéro sans chiffres ne passe pas, un AUTRE numéro non plus.
    await page.fill('#mTel', '---');
    await page.click('#mOui');
    await expect(page.locator('#mErr')).toContainText(/numéro complet/);
    await page.fill('#mTel', '0611111111');
    await page.click('#mOui');
    await expect(page.locator('#mErr')).toContainText(/pas celui du gagnant/);
    await page.fill('#mTel', TEL);
    await page.screenshot({ path: path.join(SORTIE, 'remise-modale.png') });
    await page.click('#mOui');
    await expect(page.locator('.toast.ok').last()).toContainText('Remis');
    await page.click('[data-f=remis]');
    await expect(page.locator('#tLots')).toContainText(TEL.slice(-4));
});

test('Réglages : ouvrir puis suspendre le jeu', async ({ page }) => {
    await ouvrir(page);
    await page.goto('/admin/roue/espace#reglages');
    const toggle = page.locator('[data-testid=esp-toggle-ouvert]');
    const initial = await toggle.getAttribute('aria-checked');
    await toggle.click();
    await page.click('#mOui');
    await expect(toggle).toHaveAttribute('aria-checked', initial === 'true' ? 'false' : 'true');
    await expect(page.locator('[data-testid=esp-etat-jeu]')).toContainText(initial === 'true' ? 'fermé' : 'en ligne');
    await toggle.click();
    await page.click('#mOui');
    await expect(toggle).toHaveAttribute('aria-checked', initial);
});

test('coupure réseau : l\'enregistrement dit que rien n\'est enregistré, la saisie reste ; menu mobile au clavier', async ({ page, context }) => {
    await ouvrir(page);
    await page.goto('/admin/roue/espace#reglages');
    await expect(page.locator('section[data-view=reglages]')).toBeVisible();
    await page.fill('#prize_validity_days', '45');
    await context.setOffline(true);
    await page.click('#btnJeu');
    await expect(page.locator('.toast.err').last()).toContainText('rien n’a été enregistré');
    await expect(page.locator('.toast.err').last()).toHaveAttribute('role', 'alert');
    await expect(page.locator('#btnJeu')).toBeEnabled();
    await context.setOffline(false);
    // Un rechargement (ex. ouverture du jeu ailleurs) n'efface pas la saisie non enregistrée.
    await page.evaluate(() => window.dispatchEvent(new HashChangeEvent('hashchange')));
    await expect(page.locator('#prize_validity_days')).toHaveValue('45');

    // Menu mobile : fermé = hors clavier ; ouvert = focus dedans ; Échap le ferme et rend le focus.
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('#side')).toBeHidden();
    await page.click('#burger');
    await expect(page.locator('#side')).toBeVisible();
    await expect(page.locator('#side a:focus')).toHaveCount(1);
    await page.keyboard.press('Escape');
    await expect(page.locator('#side')).toBeHidden();
    await expect(page.locator('#burger')).toBeFocused();
});
