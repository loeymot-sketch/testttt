/**
 * [KDS-TABLETTE 2026-10-19 · owner] Poste tablette (pain / sauces / crudités) : l'écran cuisine ne
 * montre QUE les commandes — ni barre du haut, ni menu, ni logo, ni boutons, ni « Prêt ».
 *
 * Les commandes viennent de fixtures/kds-commandes-reelles.json (vraie réponse de l'API cuisine, sans donnée
 * personnelle) : le test ne dépend pas de l'état de la base.
 *
 *   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
 *     npx playwright test tests/Playwright/kds-tablette-2026-10-19.spec.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsChefOperator } = require('../e2e/helpers/login');

const SHOTS = path.join(__dirname, '..', 'captures', 'kds-tablette-2026-10-19');
fs.mkdirSync(SHOTS, { recursive: true });

test.setTimeout(240_000);

const TABLETTES = [
  { nom: '1280x800', w: 1280, h: 800, cols: 3 },
  { nom: '1024x600', w: 1024, h: 600, cols: 2 },
  { nom: '960x540', w: 960, h: 540, cols: 2 },
];

async function ouvrir(page, url) {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForSelector('.kds-card', { timeout: 30_000 });
  // La barre de débogage Laravel n'existe qu'en dev (APP_DEBUG) : elle mange 36 px en bas et fausse
  // toute mesure de hauteur. Elle est absente en production — on la masque pour mesurer l'écran réel.
  await page.addStyleTag({ content: '.phpdebugbar{display:none!important}' });
  await page.waitForTimeout(1500);
}

for (const t of TABLETTES) {
  test(`tablette ${t.nom} : seules les commandes sont à l'écran`, async ({ page }) => {
    await page.setViewportSize({ width: t.w, height: t.h });
    await loginAsChefOperator(page);
    await avecPayload(page, { data: fileDe(6), meta: BASE.meta });
    await ouvrir(page, '/kds-tablette');
    await page.screenshot({ path: path.join(SHOTS, `t-${t.nom}-plein-ecran.png`) });

    const m = await page.evaluate(() => {
      const vis = (el) => !!el && getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().height > 0;
      const grille = document.querySelector('.kds-v2__grid');
      const cartes = [...document.querySelectorAll('.kds-card')];
      const g = grille.getBoundingClientRect();
      const c0 = cartes[0].getBoundingClientRect();
      const corps = cartes.map((c) => {
        const b = c.querySelector('.kds-card__body');
        return { h: Math.round(b.clientHeight), scroll: b.scrollHeight > b.clientHeight + 1 };
      });
      return {
        entete: vis(document.querySelector('.db-header')),
        menu: vis(document.querySelector('.db-sidebar')),
        barre: !!document.querySelector('#kds-toolbar'),
        selecteur: !!document.querySelector('.kds-cols-picker'),
        bandeServies: !!document.querySelector('.kds-v2__served'),
        boutonsDefilement: !!document.querySelector('.kds-scroll-btn'),
        pret: document.querySelectorAll('[data-testid="kds-card-cta-ready"]').length,
        reimpression: document.querySelectorAll('[data-testid="kds-card-reprint"]').length,
        bandeauSecours: !!document.querySelector('[data-testid="kds-sync-mode-banner"]'),
        htmlClasse: document.documentElement.classList.contains('kds-tablette'),
        grilleHaut: Math.round(g.top), grilleBas: Math.round(g.bottom), vh: window.innerHeight,
        vw: window.innerWidth, grilleDroite: Math.round(g.right),
        carteHaut: Math.round(c0.top), carteBas: Math.round(c0.bottom), carteLarg: Math.round(c0.width),
        nbCartes: cartes.length, cols: Number(grille.dataset.cols),
        pastille: (() => { const p = document.querySelector('[data-testid="connexion-pastille"]'); if (!p) return null; const r = p.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), etat: p.dataset.etat, gauche: Math.round(r.left), bas: Math.round(r.bottom) }; })(),
        scrollPageV: (() => { window.scrollTo(0, 500); document.documentElement.scrollTop = 500; document.body.scrollTop = 500; const y = Math.max(window.scrollY, document.documentElement.scrollTop, document.body.scrollTop); window.scrollTo(0, 0); return y > 0; })(),
        corps,
      };
    });
    console.log('TABLETTE', t.nom, JSON.stringify(m));

    expect(m.htmlClasse).toBe(true);
    expect(m.entete).toBe(false);
    expect(m.menu).toBe(false);
    expect(m.barre).toBe(false);
    expect(m.selecteur).toBe(false);
    expect(m.bandeServies).toBe(false);
    expect(m.boutonsDefilement).toBe(false);
    expect(m.pret).toBe(0);
    expect(m.reimpression).toBe(0);
    expect(m.bandeauSecours).toBe(false);
    expect(m.cols).toBe(t.cols);
    // Rien au-dessus de la première commande : elle commence tout en haut de l'écran.
    expect(m.grilleHaut).toBeLessThanOrEqual(1);
    expect(m.carteHaut).toBeLessThanOrEqual(8);
    // … et la grille descend jusqu'en bas (pas de bande vide dessous).
    expect(m.grilleBas).toBeGreaterThanOrEqual(m.vh - 1);
    expect(m.scrollPageV).toBe(false);
    // La pastille de connexion n'est qu'un point dans le coin (elle recouvrait le bas de la 1re commande).
    expect(m.pastille).not.toBeNull();
    expect(m.pastille.etat).toBe('ok');
    expect(m.pastille.w).toBeLessThanOrEqual(24);
    expect(m.pastille.gauche).toBeGreaterThan(m.vw - 40);
    // La carte occupe quasi toute la hauteur.
    expect(m.carteBas - m.carteHaut).toBeGreaterThanOrEqual(m.vh - 24);
    // La largeur d'une fiche reste lisible.
    expect(m.carteLarg).toBeGreaterThanOrEqual(300);

    // Les commandes suivantes se découvrent en faisant glisser : on capture le milieu et la fin de la file.
    await page.evaluate(() => { const g = document.querySelector('.kds-v2__grid'); g.style.scrollBehavior = 'auto'; g.scrollLeft = g.clientWidth * 0.9; });
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(SHOTS, `t-${t.nom}-suite-1.png`) });
    await page.evaluate(() => { const g = document.querySelector('.kds-v2__grid'); g.scrollLeft = g.scrollWidth; });
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(SHOTS, `t-${t.nom}-suite-fin.png`) });
  });
}

test('tablette : le mode est mémorisé, `?tablette=0` rend le grand écran', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await loginAsChefOperator(page);
  await avecPayload(page, { data: fileDe(6), meta: BASE.meta });
  await ouvrir(page, '/kds-tablette');
  // Adresse nue : le poste se souvient.
  await ouvrir(page, '/admin/kitchen-display-system');
  expect(await page.evaluate(() => document.documentElement.classList.contains('kds-tablette'))).toBe(true);
  await expect(page.locator('#kds-toolbar')).toHaveCount(0);

  // Retour au grand écran : tout revient.
  await ouvrir(page, '/admin/kitchen-display-system?tablette=0');
  expect(await page.evaluate(() => document.documentElement.classList.contains('kds-tablette'))).toBe(false);
  await expect(page.locator('#kds-toolbar')).toBeVisible();
  await expect(page.locator('.db-header')).toBeVisible();
  await expect(page.locator('[data-testid="kds-card-cta-ready"]').first()).toBeVisible();
  await page.screenshot({ path: path.join(SHOTS, 'g-grand-ecran-inchange.png') });

  // Quitter l'écran cuisine EN NAVIGUANT dans la SPA (sans rechargement) : la classe ne doit pas fuir.
  await ouvrir(page, '/kds-tablette');
  await page.evaluate(() => {
    window.history.pushState({}, '', '/admin/dashboard');
    window.dispatchEvent(new PopStateEvent('popstate', { state: {} }));
  });
  await page.waitForTimeout(3000);
  expect(page.url()).toContain('/admin/dashboard');
  expect(await page.evaluate(() => document.documentElement.classList.contains('kds-tablette'))).toBe(false);
  await expect(page.locator('.db-header')).toBeVisible();
});

// ---------------------------------------------------------------------------------------------------
// Cas extrêmes, sur la PLUS PETITE tablette. Les commandes viennent d'une vraie réponse de l'API
// cuisine (fixtures/kds-commandes-reelles.json, sans aucune donnée personnelle), puis grossies :
// le test ne dépend plus de l'état de la base de dev.
// ---------------------------------------------------------------------------------------------------
const BASE = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'kds-commandes-reelles.json'), 'utf8'));
const NOTE_LONGUE = '\nSANS OIGNONS SVP ET SANS TOMATE, BIEN CUIT, COUPER EN DEUX ET METTRE LA SAUCE A PART DANS UN PETIT POT';

function maintenant(minutes) { return new Date(Date.now() - minutes * 60_000).toISOString(); }

function commande(base, id, queue, minutes, statut) {
  const o = JSON.parse(JSON.stringify(base));
  o.id = id; o.queue_number = queue; o.status = statut;
  o.created_at_iso = maintenant(minutes); o.updated_at = maintenant(minutes);
  o.order_items.forEach((it, i) => { it.id = id * 100 + i; it.order_id = id; });
  return o;
}

function grosseCommande() {
  const o = commande(BASE.data.find((x) => x.queue_number === 'A0039'), 9100, 'A0200', 21, 7);
  const copies = [];
  for (let k = 0; k < 3; k += 1) {
    o.order_items.slice(0, 3).forEach((it, i) => {
      const c = JSON.parse(JSON.stringify(it));
      c.id = 910000 + k * 10 + i;
      copies.push(c);
    });
  }
  o.order_items.push(...copies);
  o.order_items[0].instruction += NOTE_LONGUE;
  return o;
}

function fileDe(n) {
  const out = [];
  for (let i = 0; i < n; i += 1) out.push(commande(BASE.data[i % BASE.data.length], 9400 + i, `A04${i}`, 20 - i * 3, i % 2 ? 4 : 7));
  return out;
}

async function avecPayload(page, payload) {
  await page.route(/\/api\/admin\/kds-order(\?.*)?$/, (route) => route.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify(payload),
  }));
}

test('tablette 1024x600 : une grosse commande défile DANS sa fiche, l\'en-tête reste, la note est lisible', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 600 });
  await loginAsChefOperator(page);
  const base = BASE.data;
  await avecPayload(page, { data: [grosseCommande(), commande(base[0], 9101, 'A0201', 6, 7), commande(base[2], 9102, 'A0202', 3, 4)], meta: BASE.meta });
  await ouvrir(page, '/kds-tablette');
  await page.screenshot({ path: path.join(SHOTS, 'x-grosse-commande-1024x600.png') });

  const m = await page.evaluate(() => {
    const c = document.querySelector('.kds-card');
    const corps = c.querySelector('.kds-card__body');
    const note = [...c.querySelectorAll('.kds-line__instruction')][0];
    const r = c.getBoundingClientRect();
    const tete = c.querySelector('.kds-card__header').getBoundingClientRect();
    corps.scrollTop = 0;
    return {
      defile: corps.scrollHeight > corps.clientHeight + 1,
      corpsH: corps.clientHeight, contenuH: corps.scrollHeight,
      teteBas: Math.round(tete.bottom), carteH: Math.round(r.height),
      note: note ? { px: parseFloat(getComputedStyle(note).fontSize), poids: Number(getComputedStyle(note).fontWeight), texte: note.textContent.trim().slice(0, 40) } : null,
      deborde: [...c.querySelectorAll('*')].some((e) => e.getBoundingClientRect().right > r.right + 1),
    };
  });
  console.log('GROSSE', JSON.stringify(m));
  expect(m.defile).toBe(true);                 // trop long pour l'écran : la fiche défile…
  expect(m.corpsH).toBeGreaterThanOrEqual(300); // …et le corps garde plus de la moitié de l'écran
  expect(m.teteBas).toBeLessThanOrEqual(140);   // en-tête compact (n° + attente + cuisson)
  expect(m.deborde).toBe(false);                // rien ne dépasse de la fiche
  expect(m.note).not.toBeNull();
  expect(m.note.px).toBeGreaterThanOrEqual(20); // la note reste grande…
  expect(m.note.poids).toBeGreaterThanOrEqual(800); // …et grasse

  // On fait défiler la fiche jusqu'en bas : le dernier article est atteignable.
  await page.evaluate(() => { const b = document.querySelector('.kds-card .kds-card__body'); b.scrollTop = b.scrollHeight; });
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOTS, 'x-grosse-commande-1024x600-bas.png') });
});

test('tablette : file longue (9 commandes) → la rangée défile, largeur des fiches constante', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 600 });
  await loginAsChefOperator(page);
  const base = BASE.data;
  const file = [];
  for (let i = 0; i < 9; i += 1) file.push(commande(base[i % base.length], 9200 + i, `A03${i}`, 20 - i, i % 2 ? 4 : 7));
  await avecPayload(page, { data: file, meta: BASE.meta });
  await ouvrir(page, '/kds-tablette');
  const m = await page.evaluate(() => {
    const g = document.querySelector('.kds-v2__grid');
    const l = [...document.querySelectorAll('.kds-card')].map((c) => Math.round(c.getBoundingClientRect().width));
    g.style.scrollBehavior = 'auto';
    g.scrollLeft = g.scrollWidth;
    return { defile: g.scrollWidth > g.clientWidth + 1, largeurs: [...new Set(l)], n: l.length, fin: Math.round(g.scrollLeft) };
  });
  console.log('FILE', JSON.stringify(m));
  expect(m.n).toBe(9);
  expect(m.defile).toBe(true);
  expect(m.largeurs.length).toBe(1);            // même largeur pour toutes les fiches, quelle que soit la file
  expect(m.fin).toBeGreaterThan(0);
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, 'x-file-longue-fin.png') });
});

test('tablette : flèches ‹ › — apparaissent seulement quand il reste des commandes, et font défiler', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 600 });
  await loginAsChefOperator(page);
  const base = BASE.data;
  const file = [];
  for (let i = 0; i < 9; i += 1) file.push(commande(base[i % base.length], 9300 + i, `A04${i}`, 20 - i, i % 2 ? 4 : 7));
  await avecPayload(page, { data: file, meta: BASE.meta });
  await ouvrir(page, '/kds-tablette');
  const G = '[data-testid="kds-tablette-fleche-gauche"]';
  const D = '[data-testid="kds-tablette-fleche-droite"]';
  const pos = () => page.evaluate(() => Math.round(document.querySelector('.kds-v2__grid').scrollLeft));

  // Début de file : rien à gauche, une flèche à droite (taille tactile suffisante).
  await expect(page.locator(G)).toHaveCount(0);
  await expect(page.locator(D)).toBeVisible();
  const boite = await page.locator(D).boundingBox();
  expect(boite.width).toBeGreaterThanOrEqual(44);
  await page.screenshot({ path: path.join(SHOTS, 'fleches-debut.png') });

  // Un appui à droite : la grille avance, la flèche gauche apparaît.
  await page.locator(D).click();
  await page.waitForTimeout(900);
  const apres = await pos();
  expect(apres).toBeGreaterThan(100);
  await expect(page.locator(G)).toBeVisible();
  await page.screenshot({ path: path.join(SHOTS, 'fleches-milieu.png') });

  // Jusqu'au bout : la flèche droite disparaît.
  for (let i = 0; i < 8 && (await page.locator(D).count()) > 0; i += 1) {
    await page.locator(D).click();
    await page.waitForTimeout(800);
  }
  await expect(page.locator(D)).toHaveCount(0);
  await expect(page.locator(G)).toBeVisible();
  await page.screenshot({ path: path.join(SHOTS, 'fleches-fin.png') });

  // Retour : la flèche gauche ramène en arrière, puis disparaît au début.
  for (let i = 0; i < 8 && (await page.locator(G).count()) > 0; i += 1) {
    await page.locator(G).click();
    await page.waitForTimeout(800);
  }
  await expect(page.locator(G)).toHaveCount(0);
  expect(await pos()).toBeLessThanOrEqual(2);
});

test('tablette : file courte (tout tient à l\'écran) → aucune flèche', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await loginAsChefOperator(page);
  await avecPayload(page, { data: fileDe(3), meta: BASE.meta });
  await ouvrir(page, '/kds-tablette');
  await expect(page.locator('[data-testid="kds-tablette-fleche-gauche"]')).toHaveCount(0);
  await expect(page.locator('[data-testid="kds-tablette-fleche-droite"]')).toHaveCount(0);
});

test('tablette : file vide → message sobre, rien d\'autre', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 600 });
  await loginAsChefOperator(page);
  await avecPayload(page, { data: [], meta: BASE.meta });
  await page.goto('/kds-tablette', { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => {});
  await expect(page.locator('.kds-v2__empty')).toBeVisible({ timeout: 30_000 });
  await page.addStyleTag({ content: '.phpdebugbar{display:none!important}' });
  await page.waitForTimeout(800);
  await expect(page.locator('.db-header')).toBeHidden();
  await page.screenshot({ path: path.join(SHOTS, 'x-file-vide-1024x600.png') });
});

test('tablette : une commande ANNULÉE affiche toujours « à retirer du passe »', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 600 });
  await loginAsChefOperator(page);
  const meta = { ...BASE.meta, recently_canceled: [{ id: 9300, queue_number: 'A0777', canceled_at: maintenant(1), status_before: 'PREPARING' }] };
  await avecPayload(page, { data: [commande(BASE.data[0], 9301, 'A0300', 5, 7)], meta });
  await ouvrir(page, '/kds-tablette');
  await page.addStyleTag({ content: '.phpdebugbar{display:none!important}' });
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(SHOTS, 'x-annulee-1024x600.png') });
  console.log('ANNULEE', await page.locator('body').innerText().then((t) => /A0777/.test(t)));
});

// [KDS-TABLETTE auto 2026-10-20] La tablette de la cuisine passe toute seule en mode tablette
// (tactile sans souris + petit écran physique) ; le grand écran, jamais.
test('tablette tactile : mode tablette AUTOMATIQUE, sans adresse spéciale', async ({ browser }) => {
  const ctx = await browser.newContext({
    viewport: { width: 960, height: 540 }, screen: { width: 960, height: 540 },
    hasTouch: true, isMobile: true, deviceScaleFactor: 2,
  });
  const page = await ctx.newPage();
  await loginAsChefOperator(page);
  await avecPayload(page, { data: fileDe(6), meta: BASE.meta });
  await ouvrir(page, '/admin/kitchen-display-system');   // l'adresse HABITUELLE, rien d'autre
  expect(await page.evaluate(() => document.documentElement.classList.contains('kds-tablette'))).toBe(true);
  await expect(page.locator('#kds-toolbar')).toHaveCount(0);
  await expect(page.locator('.db-header')).toBeHidden();
  await expect(page.locator('[data-testid="kds-card-cta-ready"]')).toHaveCount(0);
  await page.screenshot({ path: path.join(SHOTS, 'auto-tablette-tactile-960x540.png') });

  // Issue de secours : `?tablette=0` rend le grand écran ET l'appareil s'en souvient.
  await ouvrir(page, '/admin/kitchen-display-system?tablette=0');
  await ouvrir(page, '/admin/kitchen-display-system');
  expect(await page.evaluate(() => document.documentElement.classList.contains('kds-tablette'))).toBe(false);
  await ctx.close();
});

test('grand écran à souris (1280×720) : JAMAIS en mode tablette tout seul', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 }, screen: { width: 1280, height: 720 } });
  const page = await ctx.newPage();
  await loginAsChefOperator(page);
  await avecPayload(page, { data: fileDe(6), meta: BASE.meta });
  await ouvrir(page, '/admin/kitchen-display-system');
  expect(await page.evaluate(() => document.documentElement.classList.contains('kds-tablette'))).toBe(false);
  await expect(page.locator('#kds-toolbar')).toBeVisible();
  await expect(page.locator('.db-header')).toBeVisible();
  await expect(page.locator('[data-testid="kds-card-cta-ready"]').first()).toBeVisible();
  await ctx.close();
});
