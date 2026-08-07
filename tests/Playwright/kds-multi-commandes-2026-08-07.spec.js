// [KDS-UI-MULTI 2026-08-07 owner] « améliore le max l'UI de plusieurs commandes sur écran de
// cuisine ». Capture + MESURE l'écran de cuisine chargé de commandes, aux 3 réglages de
// colonnes (4/6/8) et aux 2 résolutions réelles d'un écran cuisine.
//
// On ne se contente pas de capturer : on MESURE ce qui rend une carte lisible à 2 mètres —
// taille du numéro de commande, du bandeau CUISSON, nombre de lignes produit réellement
// visibles sans défiler, débordements. Une capture jolie ne prouve rien ; un chiffre si.
const { test, expect } = require('@playwright/test');

const BASE = 'http://127.0.0.1:8000';
const SHOTS = 'reports/kds-ui-multi-2026-08-07';

async function login(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.fill('#formEmail', 'pos@lecayenne.fr');
    await page.fill('#formPassword', '123456');
    await page.click('button:has-text("Connexion")');
    await page.waitForTimeout(3500);
}

/** Mesures lisibilité + débordement, dans la page. */
const MESURE = `(() => {
  const px = (el, prop) => el ? parseFloat(getComputedStyle(el)[prop]) : 0;
  const grid = document.querySelector('.kds-v2__grid');
  if (!grid) return { erreur: 'grille absente' };
  const cards = [...grid.children];
  const c0 = cards[0];
  const r0 = c0 ? c0.getBoundingClientRect() : null;

  // Combien de lignes produit sont VISIBLES sans défiler dans la carte ?
  let lignesVisibles = 0, lignesTotales = 0;
  if (c0) {
    const body = c0.querySelector('.kds-card__body');
    const lignes = [...c0.querySelectorAll('.kds-line, .kds-card__item-block')];
    lignesTotales = lignes.length;
    if (body) {
      const bb = body.getBoundingClientRect();
      lignesVisibles = lignes.filter((l) => {
        const b = l.getBoundingClientRect();
        return b.top >= bb.top - 1 && b.bottom <= bb.bottom + 1;
      }).length;
    }
  }

  // Débordement vertical réel du corps de carte (contenu coupé).
  const corpsCoupes = cards.filter((c) => {
    const b = c.querySelector('.kds-card__body');
    return b && b.scrollHeight > b.clientHeight + 2;
  }).length;

  return {
    cartesRendues: cards.length,
    largeurCarte: r0 ? Math.round(r0.width) : 0,
    hauteurCarte: r0 ? Math.round(r0.height) : 0,
    cartesEntieresVisibles: cards.filter((c) => {
      const b = c.getBoundingClientRect();
      return b.left >= -1 && b.right <= window.innerWidth + 1;
    }).length,
    tailleNumero: px(document.querySelector('.kds-card__queue'), 'fontSize'),
    tailleCuisson: px(document.querySelector('.kds-card__cuisson-value'), 'fontSize'),
    tailleLigneProduit: px(document.querySelector('.kds-line__main, .kds-line'), 'fontSize'),
    lignesVisibles, lignesTotales, corpsCoupes,
    grilleDefile: grid.scrollWidth > grid.clientWidth + 2,
    pageDefileH: document.documentElement.scrollWidth > window.innerWidth + 2,
  };
})()`;

const RESOS = [
    { w: 1920, h: 1080, nom: '1920' },
    { w: 1366, h: 768, nom: '1366' },
];

for (const vp of RESOS) {
    for (const cols of [4, 6, 8]) {
        test(`KDS ${cols} colonnes @ ${vp.nom}`, async ({ page }) => {
            await page.setViewportSize({ width: vp.w, height: vp.h });
            await login(page);
            // Le réglage est mémorisé sur le poste : on le pose AVANT le rendu.
            await page.addInitScript((n) => {
                try { window.localStorage.setItem('kds.cartesParEcran', String(n)); } catch (e) {}
            }, cols);
            await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(5000);

            const m = await page.evaluate(MESURE);
            console.log(`\n[${vp.nom} · ${cols} col] ${JSON.stringify(m)}`);

            await page.screenshot({ path: `${SHOTS}/kds-${vp.nom}-${cols}col.png` });

            expect(m.erreur, 'grille KDS attendue').toBeUndefined();
            // Le réglage doit être RESPECTÉ : autant de cartes entières que demandé.
            expect(m.cartesEntieresVisibles, `${cols} cartes entières attendues`).toBe(cols);
            // Aucune page ne doit défiler horizontalement : seule la GRILLE défile.
            expect(m.pageDefileH, 'la page ne doit jamais défiler horizontalement').toBe(false);
        });
    }
}

test('le sélecteur de colonnes change vraiment la mise en page', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await login(page);
    await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);

    const largeur = async () => (await page.evaluate(MESURE)).largeurCarte;

    await page.click('[data-testid="kds-cols-8"]');
    await page.waitForTimeout(600);
    const l8 = await largeur();
    await page.screenshot({ path: `${SHOTS}/kds-picker-8.png` });

    await page.click('[data-testid="kds-cols-4"]');
    await page.waitForTimeout(600);
    const l4 = await largeur();
    await page.screenshot({ path: `${SHOTS}/kds-picker-4.png` });

    console.log(`\n[sélecteur] largeur carte : 8 col = ${l8}px · 4 col = ${l4}px`);
    expect(l4, '4 colonnes doivent donner des cartes plus larges que 8').toBeGreaterThan(l8 * 1.5);
});

test('le défilement horizontal atteint la fin de la file', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await login(page);
    await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);

    const grid = page.locator('.kds-v2__grid');
    const avant = await grid.evaluate((g) => g.scrollLeft);
    await grid.evaluate((g) => { g.scrollLeft = g.scrollWidth; });
    await page.waitForTimeout(700);
    const apres = await grid.evaluate((g) => ({ left: g.scrollLeft, max: g.scrollWidth - g.clientWidth }));

    await page.screenshot({ path: `${SHOTS}/kds-scroll-fin.png` });
    console.log(`\n[défilement] ${avant} → ${apres.left} / max ${apres.max}`);
    expect(apres.left, 'la fin de la file doit être atteignable').toBeGreaterThan(0);
});
