/**
 * [CHEF 2026-09-02] Un burger est-il VENDABLE en caisse ?
 *
 * Constat en base : les 6 burgers ont 0 variation sur l'attribut « Viande 1 »,
 * alors que le profil composeur PUBLIÉ (id 37 v2) porte un pas `viande`
 * min_select=1 (obligatoire). Le drapeau FK_POS_WIZARD_COMPOSER_AWARE_ENABLED
 * est à true : la caisse construit donc ses pages depuis ce profil.
 *
 * La lecture de `canProceedFromStep()` suggère un blocage, mais une lecture de
 * code n'est pas une mesure. Ce script OUVRE la caisse, compose un burger et
 * regarde ce qui se passe vraiment. Il n'encaisse RIEN et ne valide aucune
 * commande : il s'arrête au panier.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
process.chdir(REPO);
const require = createRequire(path.join(REPO, 'package.json'));
const { chromium } = require('playwright');

const BASE = (process.env.FOODKING_E2E_BASE_URL || 'http://127.0.0.1:8766').replace(/\/+$/, '');
const OUT = path.join(REPO, process.env.CHEF_OUT || 'reports/test-e2e/chef-round-6/pos-burger');
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';
const PRODUIT = process.env.CHEF_PRODUIT || 'Chicken Burger';

fs.mkdirSync(OUT, { recursive: true });

function clearRateLimits() {
  spawnSync('php', ['artisan', 'tinker', '--execute', `
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    $ids = \\App\\Models\\User::whereIn('email', ['${EMAIL}'])->pluck('id')->map(fn($id) => (string) $id)->all();
    $keys = array_unique(array_merge($ids, ['127.0.0.1','::1','localhost','${EMAIL}|127.0.0.1','${EMAIL}|::1']));
    foreach (['api','login-lockout'] as $name) { foreach ($keys as $key) { $limiter->clear(md5($name.$key)); } }
    echo 'ok';
  `], { cwd: REPO, encoding: 'utf8', timeout: 20_000 });
}

function resolveChromium() {
  const home = process.env.HOME || '';
  return [
    path.join(home, 'Library/Caches/ms-playwright/chromium_headless_shell-1237/chrome-headless-shell-mac-arm64/chrome-headless-shell'),
    path.join(home, 'Library/Caches/ms-playwright/chromium-1237/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'),
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ].find((c) => fs.existsSync(c)) || null;
}

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 20_000 });
  await page.locator('#formEmail').fill(EMAIL);
  await page.locator('#formPassword').fill(PASSWORD);
  const wait = page.waitForResponse(
    (r) => r.request().method() === 'POST' && /\/api\/auth\/login/i.test(r.url()),
    { timeout: 25_000 },
  );
  await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
  const resp = await wait;
  if (resp.status() !== 201) throw new Error(`Login HTTP ${resp.status()}`);
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(600);
}

/** L'état du wizard caisse, lu dans le DOM RÉEL (pas d'inférence). */
async function lireWizard(page) {
  return page.evaluate(() => {
    const wiz = document.querySelector('.pos-wizard');
    if (!wiz) return { present: false };
    const txt = (el) => (el ? String(el.textContent || '').replace(/\s+/g, ' ').trim() : null);
    const pages = Array.from(document.querySelectorAll('.pos-wizard .step-label')).map((e) => txt(e));
    // Les options proposées dans la zone visible du wizard.
    const options = Array.from(
      document.querySelectorAll('.pos-wizard [class*="option"], .pos-wizard [class*="choice"], .pos-wizard .viande-item'),
    ).map((e) => txt(e)).filter(Boolean).slice(0, 40);
    const erreur = txt(document.querySelector('.pos-wizard [class*="validation-error"], .validation-error, [class*="error-message"]'));
    const boutons = Array.from(document.querySelectorAll('.pos-wizard button')).map((b) => ({
      texte: txt(b),
      disabled: !!b.disabled,
      classe: String(b.className || '').slice(0, 90),
    })).filter((b) => b.texte);
    return {
      present: true,
      pages,
      nb_options_visibles: options.length,
      options_echantillon: options.slice(0, 12),
      erreur_visible: erreur,
      boutons,
      texte_wizard: txt(wiz).slice(0, 1200),
    };
  });
}

async function main() {
  clearRateLimits();
  const exe = resolveChromium();
  const browser = await chromium.launch({ headless: true, ...(exe ? { executablePath: exe } : {}) });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
  const page = await context.newPage();
  const consoleBuf = [];
  page.on('console', (m) => {
    if (['error', 'warning'].includes(m.type())) consoleBuf.push({ t: m.type(), text: m.text().slice(0, 500) });
  });
  page.on('pageerror', (e) => consoleBuf.push({ t: 'pageerror', text: String(e.message).slice(0, 500) }));
  page.on('dialog', async (d) => { await d.dismiss(); });

  const rapport = { produit: PRODUIT, base: BASE, etapes: [] };

  try {
    await login(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded', timeout: 40_000 });
    await page.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {});
    await page.waitForTimeout(2500);
    rapport.etapes.push({ etape: 'pos_ouvert', url: page.url() });
    await page.screenshot({ path: path.join(OUT, '01-pos.png'), fullPage: false });

    // La caisse est CATÉGORIE-D'ABORD : la grille produits n'existe pas tant qu'un
    // rayon n'est pas ouvert. Cliquer le produit sans ouvrir le rayon ne mesure rien.
    const RAYON = process.env.CHEF_RAYON || 'Burgers';
    const tuile = page.locator('[data-testid="pos-category-tile"]')
      .filter({ hasText: new RegExp(`^\\s*${RAYON}\\s*$`, 'i') }).first();
    const tuiles = await page.locator('[data-testid="pos-category-tile"]').allTextContents();
    rapport.rayons_visibles = tuiles.map((t) => t.replace(/\s+/g, ' ').trim());
    if (!(await tuile.count())) throw new Error(`Rayon « ${RAYON} » introuvable. Vus : ${rapport.rayons_visibles.join(' | ')}`);
    await tuile.scrollIntoViewIfNeeded();
    await tuile.click();
    await page.waitForTimeout(1800);
    rapport.etapes.push({ etape: 'rayon_ouvert', rayon: RAYON });
    await page.screenshot({ path: path.join(OUT, '01b-rayon.png'), fullPage: false });

    // Le produit, ciblé par son nom EXACT. Un texte trop large attrape un bouton
    // caché et fabrique un faux résultat — piège déjà rencontré sur cette caisse.
    // Sélecteur ÉTABLI par inspection du DOM (reports/test-e2e/chef-round-6/pos-grille/) :
    // la tuile cliquable est button.pos-v5-tile.pos-item-tile. Un `[class*="product"]`
    // attrapait le CONTENEUR « pos-menu-products-region » — le clic partait dans le vide
    // et l'essai précédent n'a donc rien mesuré du tout.
    const esc = PRODUIT.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const carte = page.locator('button.pos-item-tile')
      .filter({ hasText: new RegExp(esc, 'i') }).first();

    const nbTuiles = await page.locator('button.pos-item-tile').count();
    rapport.tuiles_du_rayon = nbTuiles;
    let ouvert = false;
    if (await carte.count()) {
      await carte.scrollIntoViewIfNeeded();
      await carte.click();
      ouvert = true;
    }
    rapport.etapes.push({ etape: 'produit_clique', ouvert, selecteur: 'button.pos-item-tile' });
    await page.waitForTimeout(2500);
    await page.waitForTimeout(2500);
    await page.screenshot({ path: path.join(OUT, '02-apres-clic.png'), fullPage: false });

    // [CHEF] Diagnostic : que s'est-il RÉELLEMENT ouvert ? Sans ça, un
    // `present:false` ne distingue pas « rien ne s'ouvre » de « mon sélecteur rate ».
    rapport.overlays = await page.evaluate(() => {
      const vus = [];
      for (const e of document.querySelectorAll('div,section,aside')) {
        const st = getComputedStyle(e);
        if (st.display === 'none' || st.visibility === 'hidden') continue;
        const r = e.getBoundingClientRect();
        if (r.width < 320 || r.height < 240) continue;
        if (!/modal|overlay|wizard|popup|drawer|dialog/i.test(e.className + ' ' + (e.dataset.testid || ''))) continue;
        vus.push({
          cls: String(e.className).slice(0, 120),
          testid: e.dataset.testid || null,
          taille: Math.round(r.width) + 'x' + Math.round(r.height),
          texte: String(e.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 240),
        });
      }
      return vus.slice(0, 8);
    });

    const wiz1 = await lireWizard(page);
    rapport.wizard_a_l_ouverture = wiz1;

    // [CHEF] Sélectionner une viande AVANT d'ajouter — sinon on mesure un refus
    // de validation, pas la vendabilité. Puis cliquer le bouton d'ajout du WIZARD
    // (la caisse en porte un second, masqué, hors wizard : le viser fabrique un faux vert).
    rapport.viande = await page.evaluate(() => {
      const b = [...document.querySelectorAll('.pos-wizard button.viande-btn')][0];
      if (!b) return { trouvee: false };
      b.click();
      return { trouvee: true, texte: String(b.textContent || '').replace(/\s+/g, ' ').trim() };
    });
    await page.waitForTimeout(900);
    rapport.ajout_reel = await page.evaluate(() => {
      const boutons = [...document.querySelectorAll('.pos-wizard button, .pos-v4-item-wizard-dialog button')]
        .filter((b) => /ajouter au panier/i.test(b.textContent || ''));
      if (!boutons.length) return { trouve: false };
      const decrire = (b) => {
        const r = b.getBoundingClientRect();
        return { texte: String(b.textContent||'').replace(/\s+/g,' ').trim().slice(0,60), disabled: b.disabled,
                 visible: !!b.offsetParent && r.width > 0 && r.height > 0,
                 taille: Math.round(r.width)+'x'+Math.round(r.height), cls: String(b.className).slice(0,90) };
      };
      const tous = boutons.map(decrire);
      const idx = boutons.findIndex((b) => { const r=b.getBoundingClientRect(); return !!b.offsetParent && r.width>0 && r.height>0; });
      if (idx < 0) return { trouve: true, aucun_visible: true, candidats: tous };
      boutons[idx].click();
      return { trouve: true, nb_candidats: boutons.length, candidats: tous, clique: tous[idx] };
    });
    await page.waitForTimeout(2000);
    rapport.message_bloquant = await page.evaluate(() => {
      const out = [];
      for (const e of document.querySelectorAll('.pos-wizard *, .pos-v4-item-wizard-dialog *, .toast, [class*="alert"], [class*="error"]')) {
        const st = getComputedStyle(e);
        if (st.display === 'none' || st.visibility === 'hidden') continue;
        const t = String(e.textContent || '').replace(/\s+/g, ' ').trim();
        if (!t || t.length > 160) continue;
        if (/choisis|obligatoire|s[ée]lection|requis|manqu|au moins|erreur/i.test(t)) out.push(t);
      }
      return [...new Set(out)].slice(0, 6);
    });
    rapport.modal_encore_ouverte = await page.evaluate(() => !!document.querySelector('.pos-v4-item-wizard-modal.active'));
    rapport.lignes_panier = await page.evaluate(() => [...document.querySelectorAll('[data-testid="pos-cart-item"], .pos-v5-cart-item')]
      .map((e) => String(e.textContent||'').replace(/\s+/g,' ').trim().slice(0,160)));
    await page.screenshot({ path: path.join(OUT, '04-panier.png'), fullPage: false });

    // Tenter l'ajout au panier : c'est la mesure qui tranche P0 vs P1.
    if (false) {
      const ajout = page.locator('.pos-wizard button').filter({ hasText: /ajouter au panier|ajouter/i }).first();
      if (await ajout.count()) {
        rapport.bouton_ajout = { trouve: true, disabled: await ajout.isDisabled().catch(() => null) };
        await ajout.click({ timeout: 5000 }).catch((e) => { rapport.bouton_ajout.erreur_clic = String(e).slice(0, 200); });
        await page.waitForTimeout(1200);
        rapport.wizard_apres_ajout = await lireWizard(page);
        await page.screenshot({ path: path.join(OUT, '03-apres-ajout.png'), fullPage: false });
      } else {
        rapport.bouton_ajout = { trouve: false };
      }
    }

    rapport.panier = await page.evaluate(() => {
      const c = document.querySelector('[class*="cart"], [class*="panier"]');
      return c ? String(c.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 600) : null;
    });
  } catch (e) {
    rapport.erreur = String(e && e.message ? e.message : e).slice(0, 600);
  }

  rapport.console = consoleBuf;
  fs.writeFileSync(path.join(OUT, 'rapport.json'), JSON.stringify(rapport, null, 2), 'utf8');
  fs.writeFileSync(path.join(OUT, 'page.dom.html'), (await page.content()).slice(0, 2_000_000), 'utf8');
  console.log(JSON.stringify({
    produit: rapport.produit,
    erreur: rapport.erreur || null,
    wizard_present: rapport.wizard_a_l_ouverture ? rapport.wizard_a_l_ouverture.present : false,
    pages: rapport.wizard_a_l_ouverture ? rapport.wizard_a_l_ouverture.pages : null,
    options_visibles: rapport.wizard_a_l_ouverture ? rapport.wizard_a_l_ouverture.nb_options_visibles : null,
    erreur_visible: rapport.wizard_a_l_ouverture ? rapport.wizard_a_l_ouverture.erreur_visible : null,
    bouton_ajout: rapport.bouton_ajout || null,
    viande: rapport.viande || null,
    ajout_reel: rapport.ajout_reel || null,
    modal_encore_ouverte: rapport.modal_encore_ouverte,
    lignes_panier: rapport.lignes_panier || null,
    message_bloquant: rapport.message_bloquant || null,
    erreur_apres_ajout: rapport.wizard_apres_ajout ? rapport.wizard_apres_ajout.erreur_visible : null,
    console: consoleBuf.slice(0, 6),
  }, null, 2));

  await browser.close();
}

main().catch((e) => { console.error(e); process.exit(1); });
