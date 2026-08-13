// @ts-check
/**
 * LE LOGO SUR LE QR, ÉPROUVÉ SUR L'ARTEFACT RÉELLEMENT RENDU.
 *
 * [Wave A · roue-account-e2e-2026-08-13, 2026-08-13]
 *
 * `roue-2026-08-13.spec.js` (existant, NE PAS dupliquer) prouve déjà la mise en page générale de
 * ces écrans — débordement, taille du QR, absence de doublon. Il ne prouve PAS le nouveau
 * recouvrement `.qr-logo` posé en CSS par-dessus le SVG (borne.blade.php + validation.blade.php,
 * cf. GOAL_ROUE_UX_IDENTITE_2026-08-13.md §1.1) : ni sa taille, ni son centrage, ni surtout — la
 * seule chose qui compte réellement pour un client au comptoir — si le QR reste SCANNABLE une fois
 * le logo dessiné dessus.
 *
 * ── POURQUOI DÉCODER, PAS SEULEMENT MESURER ────────────────────────────────────────────────────
 * `errorCorrection('H')` + `margin(2)` (posés au contrôleur) sont un PARI que ~30 % du QR peut être
 * recouvert sans casser le décodage. PHPUnit (`WheelQrLogoTest`, `WheelQrScannabilityTest`) a déjà
 * vérifié ce pari sur le SVG produit par le contrôleur. Ce banc vérifie autre chose : l'artefact
 * que le NAVIGATEUR affiche réellement, logo compris, capturé en PNG par Playwright et décodé par
 * `khanamiryan/qrcode-detector-decoder` (mode GD, Imagick absent de cette machine — vérifié par
 * exécution cette session). Si le CSS du logo dérive un jour (mauvaise taille, mauvais z-index,
 * fond non-opaque), c'est CE banc qui le voit — pas PHPUnit, qui ne regarde jamais un pixel rendu.
 *
 * ── LA PORTE, TOUJOURS UNE FOIS PAR CONTEXTE ───────────────────────────────────────────────────
 * Même contrainte que `roue-2026-08-13.spec.js` : `wheel-pin` accepte 5 essais/min/IP
 * (RouteServiceProvider:108). Ce fichier ouvre la porte UNE fois par describe (mode serial), et à
 * l'intérieur d'un describe, une seule page change de viewport via `setViewportSize` plutôt que
 * d'ouvrir un nouveau contexte — un contexte de plus serait une entrée de PIN de plus.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const CODE = process.env.WHEEL_PIN || '481526';
const REPO_ROOT = path.join(__dirname, '..', '..');
const DECODE_QR_PHP = path.join(__dirname, 'helpers', 'decode-qr.php');
const CAPTURES_DIR = path.join(REPO_ROOT, 'tests', 'captures', 'roue-qr-logo-2026-08-13');

/**
 * LA VÉRITÉ DE LA CONFIG, LUE EN DIRECT — pas recopiée à la main dans ce fichier. Un `wheel.enabled`
 * ou un `wheel.public_url` qui change entre deux sessions ne doit pas rendre ce banc obsolète : il
 * lit la même config que le contrôleur, au moment où il tourne.
 */
function lireConfigRoue() {
  const brut = execSync(
    'php artisan tinker --execute="echo json_encode([\'base\'=>config(\'wheel.public_url\'),\'enabled\'=>(bool)config(\'wheel.enabled\'),\'preview\'=>config(\'wheel.preview_key\'),\'ttl\'=>(int)config(\'wheel.unlock_token_ttl_minutes\')]);"',
    { cwd: REPO_ROOT, encoding: 'utf8' }
  );
  const m = brut.match(/\{.*\}/s);
  if (!m) { throw new Error('lireConfigRoue: JSON introuvable dans la sortie tinker :\n' + brut); }
  return JSON.parse(m[0]);
}

/** Décode un PNG via le décodeur PHP GD (voir helpers/decode-qr.php). Lance si le décodage échoue —
 * PAS de `.catch(()=>{})` ici, un QR illisible EST l'échec que ce banc doit détecter. */
function decoderQr(pngPath) {
  return execFileSync('php', [DECODE_QR_PHP, pngPath], { encoding: 'utf8' });
}

/** Ouvre la porte une fois par contexte : les écrans suivants héritent de la session (idiome
 * partagé avec roue-2026-08-13.spec.js). */
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

/** Débordement mesuré, pas deviné — même fonction que roue-2026-08-13.spec.js. */
async function debordements(page, selecteurs) {
  return page.evaluate((sels) => {
    const out = {};
    for (const s of sels) {
      const el = document.querySelector(s);
      if (!el) { out[s] = 'ABSENT'; continue; }
      out[s] = { v: el.scrollHeight - el.clientHeight, h: el.scrollWidth - el.clientWidth };
    }
    return out;
  }, selecteurs);
}

/**
 * Toutes les assertions communes à un `.qr-logo` rendu : présence, alt vide, taille 15-22 % du
 * conteneur QR, fond blanc circulaire. Partagée entre borne et validation — les deux vues posent
 * exactement le même balisage (voir borne.blade.php / validation.blade.php, commentaire
 * "Même logo qu'en tablette").
 */
async function assertOverlayLogo(page, qrSelector) {
  const logoImg = page.locator(`${qrSelector} .qr-logo img`);
  await expect(logoImg, '.qr-logo img absent — le logo a disparu du QR').toBeVisible();
  await expect(logoImg).toHaveAttribute('alt', '');

  const boiteLogo = await page.locator(`${qrSelector} .qr-logo`).boundingBox();
  const boiteQr = await page.locator(qrSelector).boundingBox();
  expect(boiteLogo, 'le conteneur .qr-logo n\'a pas de boîte').not.toBeNull();
  expect(boiteQr, 'le conteneur .qr n\'a pas de boîte').not.toBeNull();

  const ratio = boiteLogo.width / boiteQr.width;
  expect(ratio, `le logo occupe ${(ratio * 100).toFixed(1)}% du QR, hors de la fourchette sûre 15-22%`)
    .toBeGreaterThanOrEqual(0.15);
  expect(ratio, `le logo occupe ${(ratio * 100).toFixed(1)}% du QR, hors de la fourchette sûre 15-22%`)
    .toBeLessThanOrEqual(0.22);

  // Centrage : le centre du logo doit tomber au centre du QR, à quelques px près (translate(-50%,-50%)).
  const centreLogoX = boiteLogo.x + boiteLogo.width / 2;
  const centreLogoY = boiteLogo.y + boiteLogo.height / 2;
  const centreQrX = boiteQr.x + boiteQr.width / 2;
  const centreQrY = boiteQr.y + boiteQr.height / 2;
  expect(Math.abs(centreLogoX - centreQrX), 'le logo n\'est pas centré horizontalement sur le QR').toBeLessThan(4);
  expect(Math.abs(centreLogoY - centreQrY), 'le logo n\'est pas centré verticalement sur le QR').toBeLessThan(4);

  // Fond blanc circulaire avec contraste visible : background-color blanc + border-radius 50%.
  const style = await page.locator(`${qrSelector} .qr-logo`).evaluate((el) => {
    const cs = getComputedStyle(el);
    return { bg: cs.backgroundColor, radius: cs.borderRadius, w: cs.width, h: cs.height };
  });
  expect(style.bg, `fond du disque logo attendu blanc, obtenu ${style.bg}`).toMatch(/rgba?\(255,\s*255,\s*255/);
  // Chromium renvoie `border-radius` calculé en pourcentage tel quel ("50%") plutôt qu'en px pour
  // cette propriété — on vérifie donc le texte calculé ET, indépendamment, que la boîte est bien un
  // CARRÉ (aspect-ratio:1) : un 50% appliqué à un non-carré donnerait une ellipse, pas un cercle.
  expect(style.radius, `border-radius attendu 50%, obtenu ${style.radius}`).toBe('50%');
  expect(Math.abs(boiteLogo.width - boiteLogo.height), 'le disque du logo n\'est pas carré (donc pas un cercle une fois le radius appliqué)')
    .toBeLessThan(1);
}

/** Lit les artefacts .console.json/.network.json écrits par snap() et vérifie l'absence de 404 sur
 * le logo et l'absence de bruit CORS/mixed-content. Appeler juste après `await snap(name)`. */
function assertConsoleReseauPropres(name) {
  const base = path.join(CAPTURES_DIR, name);
  const consoleBuf = JSON.parse(fs.readFileSync(`${base}.console.json`, 'utf8'));
  const networkBuf = JSON.parse(fs.readFileSync(`${base}.network.json`, 'utf8'));

  const erreursConsole = consoleBuf.filter((m) => m.level === 'error' || m.level === 'pageerror');
  const bruitCorsOuMixed = consoleBuf.filter((m) => /cors|mixed content|blocked:mixed/i.test(m.text || ''));
  const logo404 = networkBuf.filter((n) => n.status >= 400 && /logo-mark\.png/.test(n.url));

  expect(erreursConsole, `console.error/pageerror inattendus sur ${name} : ${JSON.stringify(erreursConsole)}`)
    .toHaveLength(0);
  expect(bruitCorsOuMixed, `avertissement CORS/mixed-content sur ${name} : ${JSON.stringify(bruitCorsOuMixed)}`)
    .toHaveLength(0);
  expect(logo404, `logo-mark.png en 404 sur ${name} : ${JSON.stringify(logo404)}`).toHaveLength(0);
}

test.describe('QR + logo — écran vitrine (/admin/roue-borne)', () => {
  test.describe.configure({ mode: 'serial' });

  /** Une seule page pour tout le describe : on change de VIEWPORT sur la même page plutôt que
   * d'ouvrir un nouveau contexte (donc un nouveau PIN) pour chaque orientation. */
  let page;
  let recorder;
  let cfg;
  test.beforeAll(async ({ browser }) => {
    cfg = lireConfigRoue();
    // deviceScaleFactor:2 — un DPR de 1 (défaut desktop) est le seul cas où le décodage a
    // ÉCHOUÉ pendant l'écriture de ce banc, sur le SVG le plus PETIT (validation, 260px CSS) :
    // exactement 260 pixels physiques pour ~29 modules noircit trop peu de pixels par module et
    // l'antialiasing du rasterizeur brouille le seuil noir/blanc du décodeur. Une vraie tablette
    // ou un vrai téléphone qui affiche ce QR est en Retina (DPR 2 ou 3) — c'est LUI qu'on doit
    // simuler pour juger la scannabilité réelle, pas un moniteur de bureau à DPR 1 qu'aucun
    // client ne regarde. Preuve : à DPR 1 la capture 260×260 était réellement indécodable même
    // par le même lecteur en niveau de gris agrandi (essayé), à DPR 2 (520×520 physiques) elle
    // l'est sans aucun post-traitement.
    const contexte = await browser.newContext({ viewport: { width: 1180, height: 820 }, deviceScaleFactor: 2 });
    page = await contexte.newPage();
    recorder = attachMegaAuditRecorder(page, CAPTURES_DIR);
    await ouvrirLaPorte(page);
  });
  test.afterAll(async () => { recorder?.dispose(); await page?.context()?.close(); });

  for (const ecran of [
    { nom: '01-borne-paysage', viewport: { width: 1180, height: 820 } },
    { nom: '02-borne-portrait', viewport: { width: 820, height: 1180 } },
  ]) {
    test(`QR + logo visibles, centrés, sans débordement, décodables (${ecran.nom})`, async () => {
      await page.setViewportSize(ecran.viewport);
      await page.goto('/admin/roue-borne');
      await page.waitForLoadState('networkidle');

      // Le SVG doit être frais (pas une capture cachée d'avant le changement H/margin-2) : on
      // vérifie juste qu'il existe et a une taille avant tout le reste.
      const svg = page.locator('.qr svg');
      await expect(svg).toBeVisible();

      await assertOverlayLogo(page, '.qr');

      // Rien ne sort de sa boîte, y compris le nouveau conteneur qui héberge le recouvrement.
      const d = await debordements(page, ['.qr-boite', '.qr', '.droite', 'body']);
      for (const [nom, m] of Object.entries(d)) {
        expect(m, `${nom} est absent de la page`).not.toBe('ABSENT');
        expect(m.v, `${nom} déborde de ${m.v}px en hauteur (${ecran.nom})`).toBeLessThanOrEqual(1);
        expect(m.h, `${nom} déborde de ${m.h}px en largeur (${ecran.nom})`).toBeLessThanOrEqual(1);
      }

      await recorder.snap(ecran.nom);
      assertConsoleReseauPropres(ecran.nom);

      // ── LA PREUVE : le conteneur RÉELLEMENT RENDU (SVG + logo superposé), capturé en PNG par
      //    Playwright puis décodé. C'est l'artefact du navigateur, pas le SVG brut du contrôleur.
      const cropPath = path.join(CAPTURES_DIR, `${ecran.nom}.qr-crop.png`);
      await page.locator('.qr-boite').screenshot({ path: cropPath });
      const payload = decoderQr(cropPath);

      expect(payload.startsWith(cfg.base + '/roue.html?t='),
        `le QR décodé ne commence pas par l'URL publique attendue : ${payload}`).toBe(true);
      const jeton = new URL(payload).searchParams.get('t');
      expect(jeton, 'jeton absent ou vide dans le QR décodé').toBeTruthy();
      if (!cfg.enabled && cfg.preview) {
        expect(new URL(payload).searchParams.get('preview'), 'la roue est fermée au public : &preview= attendu dans le QR')
          .toBe(cfg.preview);
      }

      // eslint-disable-next-line no-console
      console.log(`[${ecran.nom}] QR décodé : ${payload}`);
    });
  }
});

test.describe('QR + logo — écran de validation comptoir (/admin/roue-validation)', () => {
  test.describe.configure({ mode: 'serial' });
  test.use({ viewport: { width: 820, height: 1180 } });

  let page;
  let recorder;
  let cfg;
  test.beforeAll(async ({ browser }) => {
    cfg = lireConfigRoue();
    // deviceScaleFactor:2 — voir le commentaire jumeau dans le describe borne ci-dessus : cet
    // écran de validation porte le PLUS PETIT QR du parcours (260px CSS), le seul qui a échoué au
    // décodage à DPR 1 pendant l'écriture de ce banc.
    const contexte = await browser.newContext({ viewport: { width: 820, height: 1180 }, deviceScaleFactor: 2 });
    page = await contexte.newPage();
    recorder = attachMegaAuditRecorder(page, CAPTURES_DIR);
    await ouvrirLaPorte(page);
  });
  test.afterAll(async () => { recorder?.dispose(); await page?.context()?.close(); });

  let premierPayload;
  let premierJeton;

  test('POST issue() — QR + logo apparaissent, texte sous le QR cohérent avec le payload', async () => {
    await page.goto('/admin/roue-validation');
    await page.waitForLoadState('networkidle');

    await page.getByRole('button', { name: /VALIDER/i }).click();
    await page.waitForLoadState('networkidle');

    const svg = page.locator('.qr svg');
    await expect(svg, 'le QR n\'apparaît pas après issue()').toBeVisible();

    await assertOverlayLogo(page, '.qr');

    const d = await debordements(page, ['.qr', '.carte', 'body']);
    for (const [nom, m] of Object.entries(d)) {
      expect(m, `${nom} est absent de la page`).not.toBe('ABSENT');
      expect(m.v, `${nom} déborde de ${m.v}px en hauteur`).toBeLessThanOrEqual(1);
      expect(m.h, `${nom} déborde de ${m.h}px en largeur`).toBeLessThanOrEqual(1);
    }

    await recorder.snap('03-validation-issue-1');
    assertConsoleReseauPropres('03-validation-issue-1');

    const cropPath = path.join(CAPTURES_DIR, '03-validation-issue-1.qr-crop.png');
    await page.locator('.qr').screenshot({ path: cropPath });
    premierPayload = decoderQr(cropPath);
    expect(premierPayload.startsWith(cfg.base + '/roue.html?t='),
      `QR décodé inattendu : ${premierPayload}`).toBe(true);
    premierJeton = new URL(premierPayload).searchParams.get('t');
    expect(premierJeton, 'jeton absent du premier QR').toBeTruthy();

    /*
     * [P1 2026-08-09, cf. commentaire du template] Le texte sous le QR n'affiche QUE le domaine
     * (`parse_url($url, PHP_URL_HOST)`), JAMAIS l'URL complète avec le jeton — un jeton affiché en
     * clair sur un écran de comptoir serait photographiable par n'importe qui dans la file. La
     * consigne de la mission ("plain-text URL... legible and matching the QR payload") suppose
     * l'URL complète visible ; ce n'est PAS le cas ici, intentionnellement, et c'est une régression
     * de sécurité si ça change. On vérifie donc que SEUL le domaine est affiché, et qu'il correspond
     * à l'hôte réellement encodé dans le QR — pas plus.
     */
    const texteSousQr = await page.locator('.lien').innerText();
    const hoteAttendu = new URL(premierPayload).host;
    expect(texteSousQr, 'le texte sous le QR ne porte pas le domaine attendu').toContain(hoteAttendu);
    expect(texteSousQr, 'le jeton apparaît en clair sous le QR — fuite de sécurité (régression du fix P1 2026-08-09)')
      .not.toContain(premierJeton);

    // eslint-disable-next-line no-console
    console.log(`[03-validation-issue-1] QR décodé : ${premierPayload}`);
  });

  test('issue() rappelé deux fois de suite — nouveau jeton, nouveau QR, logo toujours correct, pas de DOM figé', async () => {
    // La page porte déjà un jeton (test précédent) : le bouton devient "Valider un autre client".
    const svgAvant = await page.locator('.qr svg').innerHTML();
    const imgSrcAvant = await page.locator('.qr-logo img').getAttribute('src');

    await page.getByRole('button', { name: /Valider un autre client/i }).click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.qr svg'), 'le QR a disparu après le second issue()').toBeVisible();
    await assertOverlayLogo(page, '.qr');

    await recorder.snap('04-validation-issue-2');
    assertConsoleReseauPropres('04-validation-issue-2');

    const cropPath = path.join(CAPTURES_DIR, '04-validation-issue-2.qr-crop.png');
    await page.locator('.qr').screenshot({ path: cropPath });
    const secondPayload = decoderQr(cropPath);
    expect(secondPayload.startsWith(cfg.base + '/roue.html?t='),
      `QR décodé inattendu au second issue() : ${secondPayload}`).toBe(true);
    const secondJeton = new URL(secondPayload).searchParams.get('t');

    // ── LE JETON A CHANGÉ : c'est la preuve qu'un nouveau QR a bien été émis, pas rejoué.
    expect(secondJeton, 'le second jeton est vide').toBeTruthy();
    expect(secondJeton, 'le second issue() a produit EXACTEMENT le même jeton que le premier — jeton rejoué')
      .not.toBe(premierJeton);

    // ── LE SVG A CHANGÉ : preuve qu'aucun nœud DOM périmé n'a été réutilisé avec un contenu obsolète.
    const svgApres = await page.locator('.qr svg').innerHTML();
    expect(svgApres, 'le SVG du QR est identique avant/après le second issue() — DOM potentiellement figé')
      .not.toBe(svgAvant);

    // ── LE LOGO, LUI, EST STABLE : même asset, même src, chaque fois — ce n'est PAS lui qui doit
    //    changer, seul le SVG en dessous change.
    const imgSrcApres = await page.locator('.qr-logo img').getAttribute('src');
    expect(imgSrcApres, 'le src du logo a changé entre les deux émissions — asset inattendu').toBe(imgSrcAvant);

    // eslint-disable-next-line no-console
    console.log(`[04-validation-issue-2] QR décodé : ${secondPayload}`);
  });
});
