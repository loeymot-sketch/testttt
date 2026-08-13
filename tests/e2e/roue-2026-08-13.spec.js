// @ts-check
/**
 * LA ROUE, ÉPROUVÉE SUR DE VRAIS ÉCRANS.
 *
 * [2026-08-13 · propriétaire : « validation sur l'interface réelle sur le Web », « la page deux
 * pour tablette, ça fonctionne », « le code QR fonctionne »]
 *
 * ── POURQUOI CE BANC EXISTE, ALORS QUE PHPUnit EST VERT ──────────────────────────────────────
 * Les 242 tests PHP prouvent des RÈGLES. Ils ne prouvent rien de ce qui a réellement cassé sur ce
 * chantier, et qui n'a été trouvé qu'en REGARDANT :
 *   · un commentaire mal fermé a rendu la règle CSS de la roue invalide — elle est repassée à sa
 *     taille brute de canvas, débordant du cadre et chassant le logo hors de l'écran ;
 *   · deux lots sur sept n'avaient pas de photo, parce que leur adresse était relative ;
 *   · une rotation envoyait les vignettes à l'opposé de la roue.
 * Aucun de ces trois défauts n'aurait fait rougir un test unitaire. Ils font rougir celui-ci.
 *
 * ── LA RÈGLE QUE CE BANC FAIT RESPECTER ──────────────────────────────────────────────────────
 * « Rien ne sort de sa boîte », mesuré conteneur par conteneur, en PAYSAGE ET EN PORTRAIT. Le
 * portrait n'avait jamais pu être vérifié jusqu'ici : le redimensionnement de fenêtre ne change
 * pas le viewport dans l'outil que j'utilisais, les unités `vmin` restaient donc fausses et la
 * mesure ne prouvait rien. Playwright, lui, impose un vrai viewport.
 *
 * ── LA PORTE S'OUVRE UNE FOIS PAR GROUPE, ET C'EST UNE CONTRAINTE DU PRODUIT ────────────────
 * `wheel-pin` n'accepte que 5 essais par minute et par IP (RouteServiceProvider:108) — une garde
 * anti-force-brute parfaitement saine sur un code à six chiffres. Ma première version ouvrait la
 * porte à CHAQUE test : neuf saisies, budget épuisé, et quatre tests échouaient en 429 sans que
 * rien ne soit cassé côté produit. Un banc qui se tire dessus accuse le code à sa place.
 *
 * Chaque groupe partage donc UNE page, ouverte une seule fois, et tourne en série. Trois saisies
 * au total au lieu de neuf. Ce n'est pas un contournement : c'est exactement ce que fait une
 * tablette de comptoir, qui s'ouvre le matin et sert toute la journée.
 */
const { test, expect } = require('@playwright/test');

const CODE = process.env.WHEEL_PIN || '481526';

/** Les deux géométries qui comptent : une tablette de comptoir, debout et couchée. */
const ECRANS = [
  { nom: 'paysage', viewport: { width: 1180, height: 820 } },
  { nom: 'portrait', viewport: { width: 820, height: 1180 } },
];

/** Ouvre la porte une fois par contexte : les écrans suivants héritent de la session. */
async function ouvrirLaPorte(page) {
  await page.goto('/admin/roue');
  const champ = page.locator('#pin');
  if (await champ.count()) {
    await champ.fill(CODE);
    await page.getByRole('button', { name: 'Ouvrir' }).click();
    await page.waitForLoadState('networkidle');
  }
  await expect(page.getByRole('link', { name: /Écran vitrine/ })).toBeVisible();
}

/**
 * LE DÉBORDEMENT, MESURÉ DANS LA PAGE. On compare `scrollHeight` à `clientHeight` sur chaque
 * conteneur : c'est la seule mesure qui attrape un contenu sorti de sa boîte, y compris quand il
 * est masqué par `overflow:hidden` et donc invisible sur une capture.
 */
async function debordements(page, selecteurs) {
  return page.evaluate((sels) => {
    const out = {};
    for (const s of sels) {
      const el = s === 'body' ? document.body : document.querySelector(s);
      if (!el) { out[s] = 'ABSENT'; continue; }
      out[s] = { v: el.scrollHeight - el.clientHeight, h: el.scrollWidth - el.clientWidth };
    }
    return out;
  }, selecteurs);
}

for (const ecran of ECRANS) {
  test.describe(`vitrine tablette — ${ecran.nom}`, () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ viewport: ecran.viewport });

    /** UNE page pour tout le groupe : voir l'en-tête (limite de 5 saisies par minute). */
    let page;
    test.beforeAll(async ({ browser }) => {
      const contexte = await browser.newContext({ viewport: ecran.viewport });
      page = await contexte.newPage();
      await ouvrirLaPorte(page);
    });
    test.afterAll(async () => { await page?.context()?.close(); });

    test(`rien ne sort de sa boîte, et le QR est là (${ecran.nom})`, async () => {
      await page.goto('/admin/roue-borne');
      await page.waitForLoadState('networkidle');

      // ── LE QR : il EXISTE et il a une taille. Un SVG rendu à 0×0 passerait un simple
      //    `toBeVisible()` sur certains navigateurs — on mesure.
      const qr = page.locator('#qr svg');
      await expect(qr).toBeVisible();
      const boite = await qr.boundingBox();
      expect(boite, 'le QR n\'a pas de boîte : il ne sera pas scannable').not.toBeNull();
      expect(boite.width, 'QR trop petit pour être scanné à bout de bras').toBeGreaterThan(120);

      // ── LA RÈGLE : aucun débordement, nulle part.
      const d = await debordements(page, ['.gauche', '.droite', '.scene', '.actes', 'body']);
      for (const [nom, m] of Object.entries(d)) {
        expect(m, `${nom} est absent de la page`).not.toBe('ABSENT');
        expect(m.v, `${nom} déborde de ${m.v}px en hauteur (${ecran.nom})`).toBeLessThanOrEqual(1);
        expect(m.h, `${nom} déborde de ${m.h}px en largeur (${ecran.nom})`).toBeLessThanOrEqual(1);
      }

      // ── LA ROUE tient dans sa ligne. Le défaut du 13/08 : une règle CSS invalidée par un
      //    commentaire mal fermé l'a fait passer à 720px bruts, hors cadre.
      const roue = await page.locator('canvas#roue').boundingBox();
      expect(roue, 'la roue est absente').not.toBeNull();
      expect(roue.width, 'la roue est à sa taille brute de canvas : sa règle CSS ne s\'applique plus')
        .toBeLessThan(720);
      expect(Math.abs(roue.width - roue.height), 'la roue est devenue une ellipse').toBeLessThan(2);

      // ── LE LOGO reste visible : c'est lui qui dit de qui vient l'offre.
      const logo = await page.locator('img.logo').boundingBox();
      expect(logo, 'le logo a été chassé hors de l\'écran').not.toBeNull();
      expect(logo.y, 'le logo est sorti par le haut').toBeGreaterThanOrEqual(-1);

      await page.screenshot({ path: `tests/captures/roue-2026-08-13/vitrine-${ecran.nom}.png`, fullPage: false });
    });

    test(`la liste des lots n'est PAS répétée sous la roue (${ecran.nom})`, async () => {
      await page.goto('/admin/roue-borne');
      await page.waitForLoadState('networkidle');

      /*
       * LE REPROCHE DU PROPRIÉTAIRE, FAIT TEST : « tu affiches la roue avec les produits à gagner
       * et EN BAS tu affiches encore les photos ainsi que leur nom — faire lire deux fois la même
       * chose ». L'ancien acte 2 réimprimait les sept libellés en pastilles. Il est supprimé ; ce
       * test empêche qu'il revienne.
       */
      await expect(page.locator('.pastille')).toHaveCount(0);
      await expect(page.locator('.lots')).toHaveCount(0);
      await expect(page.getByText("Aujourd'hui, on distribue")).toHaveCount(0);
    });
  });
}

test.describe('les écrans de gestion', () => {
  test.describe.configure({ mode: 'serial' });
  test.use({ viewport: { width: 820, height: 1180 } });

  let page;
  test.beforeAll(async ({ browser }) => {
    const contexte = await browser.newContext({ viewport: { width: 820, height: 1180 } });
    page = await contexte.newPage();
    await ouvrirLaPorte(page);
  });
  test.afterAll(async () => { await page?.context()?.close(); });

  test('un mauvais code n\'ouvre RIEN', async ({ page }) => {
    /*
     * CE TEST A ÉCHOUÉ AU PREMIER PASSAGE, ET C'EST LE BANC QUI AVAIT TORT.
     *
     * Ma première version attendait le texte « Code incorrect. ». Il n'est jamais venu — non
     * parce que la porte s'était ouverte, mais parce que la LIMITE DE DÉBIT avait fait son
     * travail : `wheel-pin` n'accepte que 5 essais par minute et par IP
     * (RouteServiceProvider:108), et mes huit tests ouvrent chacun la porte. Le budget était
     * épuisé, le refus est arrivé en 429 au lieu du message.
     *
     * Autrement dit : mon test réclamait un libellé précis là où le produit a le droit de refuser
     * de DEUX façons également correctes. Ce qu'il faut éprouver n'est pas la phrase, c'est
     * l'EFFET — la porte reste close. On assertait la forme du refus ; on asserte maintenant sa
     * conséquence, la seule qui protège quelque chose.
     */
    await page.goto('/admin/roue');
    await page.locator('#pin').fill('000000');
    await page.getByRole('button', { name: 'Ouvrir' }).click();

    await page.goto('/admin/roue-reglages');
    await expect(page.locator('#pin'),
      'un mauvais code a ouvert les écrans de la roue').toBeVisible();

    await page.goto('/admin/roue-lot');
    await expect(page.locator('#pin'),
      "un mauvais code a ouvert l'écran de remise des lots").toBeVisible();
  });

  test('remettre un lot : on cherche par le CODE, et un code inconnu le dit', async () => {
    await page.goto('/admin/roue-lot');

    // Les deux chemins existent, le code en premier.
    await expect(page.locator('#code')).toBeVisible();
    await expect(page.locator('#phone')).toBeVisible();

    await page.locator('#code').fill('ROUE-NEXISTEPAS');
    await page.getByRole('button', { name: 'Chercher par le code' }).click();
    await expect(page.getByText(/Aucun lot ne correspond à ce code/)).toBeVisible();

    const d = await debordements(page, ['body']);
    expect(d.body.h, 'la page défile horizontalement sur une tablette debout').toBeLessThanOrEqual(1);
  });

  test("l'historique s'ouvre, filtre par période, et ne montre jamais un numéro entier", async () => {
    await page.goto('/admin/roue-historique?jours=90');

    await expect(page.getByRole('heading', { name: /Historique de la roue/i })).toBeVisible();

    // La période sélectionnée est DÉSIGNÉE, pas seulement colorée.
    await expect(page.locator('a[aria-current="page"]')).toHaveCount(1);

    /*
     * LA CONFIDENTIALITÉ, ÉPROUVÉE SUR LE TEXTE RENDU. Cet écran s'ouvre avec le code de la
     * maison, sur une tablette que d'autres regardent par-dessus l'épaule : aucun numéro à dix
     * chiffres ne doit s'y trouver, quelle que soit la ligne.
     */
    const texte = await page.locator('body').innerText();
    expect(texte, "un numéro de téléphone complet est lisible dans l'historique")
      .not.toMatch(/\b0[1-9]\d{8}\b/);

    await page.screenshot({ path: 'tests/captures/roue-2026-08-13/historique.png', fullPage: false });
  });

  test('les réglages portent la probabilité ET le nombre de chaque lot', async () => {
    await page.goto('/admin/roue-reglages');

    await expect(page.getByRole('heading', { name: /Réglages de la roue/i })).toBeVisible();
    // Le tableau des lots : au moins un poids et une quantité saisissables.
    await expect(page.locator('input[name$="_weight"]').first()).toBeVisible();
    await expect(page.locator('input[name$="_quantity"]').first()).toBeVisible();

    const d = await debordements(page, ['body']);
    expect(d.body.h, 'les réglages débordent en largeur sur une tablette debout').toBeLessThanOrEqual(1);
  });

  test("l'accueil mène à TOUS les écrans — aucun ne doit être orphelin", async () => {
    await page.goto('/admin/roue');

    /*
     * Le P0 du 10/08 : les écrans fonctionnaient et AUCUN lien n'y menait. Un écran de service
     * qu'on ne peut pas trouver n'existe pas. Ce test compte les portes.
     */
    for (const nom of [/Débloquer un tour/, /Remettre un lot/, /Écran vitrine/, /Réglages/, /Historique/]) {
      await expect(page.getByRole('link', { name: nom })).toBeVisible();
    }
  });
});
