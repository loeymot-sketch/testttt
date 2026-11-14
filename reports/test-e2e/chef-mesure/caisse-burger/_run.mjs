/**
 * [CHEF-MESURE 2026-09-02] Un caissier peut-il VENDRE « Chicken Burger » ?
 *
 * Mesure réelle en caisse (/admin/pos), pas une lecture de code.
 * Cas mesuré     : Chicken Burger (rayon Burgers) — 0 variation sur « Viande 1 ».
 * Cas TÉMOIN     : Cayenne (rayon Sandwichs) — 3 viandes réelles.
 * Le même script, le même geste, deux produits : si le témoin passe et que le
 * burger bloque, le défaut est dans le produit, pas dans l'instrument.
 *
 * N'ENCAISSE RIEN. S'arrête au panier. Aucune écriture en base.
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
const OUT = path.join(REPO, 'reports/test-e2e/chef-mesure/caisse-burger');
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';

fs.mkdirSync(OUT, { recursive: true });

// Les deux cas. Le témoin est OBLIGATOIRE : sans lui je ne sais pas si un échec
// vient du produit ou de mon script.
const CAS = [
  { slug: 'burger',  rayon: 'Burgers',   produit: 'Chicken Burger', itemId: 38, role: 'CAS MESURÉ' },
  { slug: 'temoin',  rayon: 'Sandwichs', produit: 'Cayenne',        itemId: 22, role: 'TÉMOIN SAIN' },
].filter((c) => !process.env.CHEF_CAS || process.env.CHEF_CAS.split(',').includes(c.slug));

/** /admin/pos est parfois lent à répondre : on retente plutôt que d'abandonner. */
async function ouvrirCaisse(page) {
  let derniere = null;
  for (let i = 0; i < 3; i++) {
    try {
      await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded', timeout: 90_000 });
      await page.waitForSelector('[data-testid="pos-category-tile"]', { timeout: 40_000 });
      return;
    } catch (e) { derniere = e; await page.waitForTimeout(3000); }
  }
  throw derniere;
}

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
  await page.waitForTimeout(800);
}

/**
 * Lit l'état RÉEL du wizard vanilla (#pos-wizard-root, single-page S25).
 * Ne devine rien : compte les tuiles réellement présentes ET VISIBLES.
 */
async function lireWizard(page) {
  return page.evaluate(() => {
    const root = document.getElementById('pos-wizard-root');
    const txt = (el) => (el ? String(el.textContent || '').replace(/\s+/g, ' ').trim() : null);
    const visible = (el) => {
      if (!el) return false;
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
    };
    if (!root) {
      // Le wizard vanilla n'est pas là : y a-t-il seulement une modale Vue ouverte ?
      const modals = Array.from(document.querySelectorAll('.modal'))
        .filter(visible).map((m) => txt(m).slice(0, 300));
      return { root_present: false, modales_vue_visibles: modals };
    }
    const sections = Array.from(root.querySelectorAll('.wizard-section, .wizard-2col-block'))
      .map((s) => ({
        titre: txt(s.querySelector('h4')),
        classe: String(s.className || ''),
        visible: visible(s),
      }));
    const viandeSection = root.querySelector('.viande-section');
    const viandeTiles = Array.from(root.querySelectorAll('.wizard-viande-tile'));
    return {
      root_present: true,
      // « Pages » : le wizard S25 est SINGLE-PAGE. On compte donc les sections
      // et, s'il existe, l'indicateur d'étapes.
      single_page: !!root.querySelector('.pos-wizard.single-page'),
      barre_etapes: Array.from(root.querySelectorAll('.wizard-progress, .step-label')).map((e) => txt(e)),
      nb_sections: sections.length,
      sections,
      viande_section_presente: !!viandeSection,
      viande_section_visible: visible(viandeSection),
      viande_titre: txt(viandeSection && viandeSection.querySelector('h4')),
      viande_badge: txt(viandeSection && viandeSection.querySelector('.quota-badge')),
      viande_hint: txt(viandeSection && viandeSection.querySelector('.viande-hint')),
      nb_tuiles_viande: viandeTiles.length,
      nb_tuiles_viande_visibles: viandeTiles.filter(visible).length,
      noms_viandes: viandeTiles.map((t) => txt(t.querySelector('.viande-tile-name'))).filter(Boolean),
      erreur_validation: txt(root.querySelector('.wizard-validation-error')),
      erreur_validation_visible: visible(root.querySelector('.wizard-validation-error')),
      boutons: Array.from(root.querySelectorAll('button')).map((b) => ({
        texte: txt(b), visible: visible(b), disabled: !!b.disabled,
        classe: String(b.className || '').slice(0, 80),
      })).filter((b) => b.texte),
      titre_produit: txt(root.querySelector('.wizard-item-header h2')),
      prix_affiche: txt(root.querySelector('.wizard-item-price')),
    };
  });
}

/** État interne du shim wizard, exposé pour comprendre le message affiché. */
async function lireEtatInterne(page) {
  return page.evaluate(() => {
    const out = {};
    try {
      const modal = document.querySelector('.modal[data-wizard-total], .modal.show, .modal[style*="block"]');
      out.data_wizard_total = modal ? modal.getAttribute('data-wizard-total') : null;
    } catch (e) { out.err = String(e); }
    out.flag_composer_aware = !!(window.foodkingConfig
      && window.foodkingConfig.posWizardComposerAware
      && window.foodkingConfig.posWizardComposerAware.enabled);
    return out;
  });
}

async function lirePanier(page) {
  return page.evaluate(() => {
    const txt = (el) => (el ? String(el.textContent || '').replace(/\s+/g, ' ').trim() : '');
    const rows = Array.from(document.querySelectorAll('.pos-v5-cart-line, .pos-v4-cart-table tbody tr, [class*="cart-item"]'))
      .map((r) => txt(r)).filter(Boolean);
    const totalEl = document.querySelector('[data-testid="pos-grand-total"]');
    const vide = Array.from(document.querySelectorAll('*'))
      .some((e) => e.children.length === 0 && /Aucun article/i.test(e.textContent || ''));
    return { lignes: rows.slice(0, 10), nb_lignes: rows.length, total: txt(totalEl), etat_vide_affiche: vide };
  });
}

async function mesurerUnCas(page, cas, rapport) {
  const R = { ...cas, journal: [] };
  const shot = async (n) => {
    const p = path.join(OUT, `${cas.slug}-${n}.png`);
    await page.screenshot({ path: p, fullPage: false });
    return p;
  };

  await ouvrirCaisse(page);
  await page.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {});
  await page.waitForTimeout(2500);
  R.panier_avant = await lirePanier(page);

  // 1) Rayon (la caisse est CATÉGORIE-D'ABORD : pas de grille sans rayon ouvert)
  const tuile = page.locator('[data-testid="pos-category-tile"]')
    .filter({ hasText: new RegExp(`^\\s*${cas.rayon}\\s*$`, 'i') }).first();
  if (!(await tuile.count())) throw new Error(`Rayon « ${cas.rayon} » introuvable`);
  await tuile.click();
  await page.waitForTimeout(1500);
  R.journal.push('rayon ouvert : ' + cas.rayon);
  R.capture_rayon = await shot('01-rayon');

  // 2) Le produit, ciblé par son ID CATALOGUE, pas par son texte.
  //    data-pos-item-id est posé par ItemComponent.vue sur button.pos-item-tile —
  //    aucun risque d'attraper le bouton Vue caché de la caisse.
  const carte = page.locator(`button.pos-item-tile[data-pos-item-id="${cas.itemId}"]`);
  R.nb_cartes_ciblees = await carte.count();
  if (R.nb_cartes_ciblees !== 1) {
    R.journal.push(`ANOMALIE INSTRUMENT : ${R.nb_cartes_ciblees} carte(s) pour item ${cas.itemId}`);
  }
  R.aria_label_carte = await carte.first().getAttribute('aria-label').catch(() => null);
  R.reseau = [];
  const onResp = (r) => {
    if (/\/api\/admin\/(item|pos)/i.test(r.url())) R.reseau.push(`${r.status()} ${r.url().slice(0, 140)}`);
  };
  page.on('response', onResp);
  await carte.first().click();

  // 3) Attendre le wizard vanilla (#pos-wizard-root) — pas un timeout aveugle.
  R.wizard_apparu = await page.waitForSelector('#pos-wizard-root', { timeout: 15_000, state: 'attached' })
    .then(() => true).catch(() => false);
  await page.waitForTimeout(1800);
  page.off('response', onResp);
  R.capture_wizard = await shot('02-wizard');
  R.wizard = await lireWizard(page);
  R.etat_interne = await lireEtatInterne(page);

  // Le profil composeur PROJETÉ, tel que la caisse le reçoit — requête dédiée
  // (lire le corps des réponses en vol cassait les fetch de la page).
  R.details_api = await page.evaluate(async (id) => {
    const res = await fetch(`/api/admin/item/details/${id}?surface=pos&branch_id=1`, {
      headers: { Accept: 'application/json' }, credentials: 'same-origin',
    });
    const j = await res.json();
    const d = (j && (j.data || j)) || {};
    const prof = d.composer_profile || null;
    return {
      http: res.status,
      nom: d.name,
      profil: prof ? {
        id: prof.id, version: prof.version,
        pas: (prof.steps || []).map((st) => ({
          step_key: st.step_key, label: st.label, min_select: st.min_select,
          max_select: st.max_select, nb_choix: Array.isArray(st.choices) ? st.choices.length : null,
          visible_on: st.visible_on, source_ref: st.source_ref, addon_role: st.addon_role,
        })),
      } : null,
      attributs: (d.itemAttributes || []).map((a) => ({ id: a.id, name: a.name })),
      variations_par_attribut: Object.keys(d.variations || {}).map((k) => ({ attribut_id: k, nb: (d.variations[k] || []).length })),
    };
  }, cas.itemId).catch((e) => ({ erreur: String(e).slice(0, 200) }));
  fs.writeFileSync(path.join(OUT, `${cas.slug}-details-api.json`), JSON.stringify(R.details_api, null, 2), 'utf8');

  // 4) LA MESURE QUI TRANCHE : cliquer « Ajouter au panier » sans rien choisir.
  const ajout = page.locator('#pos-wizard-root button').filter({ hasText: /Ajouter au panier/i });
  R.nb_boutons_ajout = await ajout.count();
  if (R.nb_boutons_ajout > 0) {
    const b = ajout.first();
    R.bouton_ajout = {
      texte: (await b.textContent().catch(() => '') || '').replace(/\s+/g, ' ').trim(),
      visible: await b.isVisible().catch(() => null),
      disabled: await b.isDisabled().catch(() => null),
    };
    await b.click({ timeout: 8000 }).catch((e) => { R.bouton_ajout.erreur_clic = String(e).slice(0, 200); });
    await page.waitForTimeout(1500);
    R.capture_apres_ajout = await shot('03-apres-ajout');
    R.wizard_apres_ajout = await lireWizard(page);
    R.panier_apres = await lirePanier(page);
    R.wizard_encore_ouvert = R.wizard_apres_ajout.root_present;
  } else {
    R.bouton_ajout = { trouve: false };
  }

  // 5) Contre-épreuve : si bloqué, un caissier essaierait de choisir quelque chose.
  //    On clique la 1ère tuile viande si elle existe, puis on réessaie.
  if (R.wizard_encore_ouvert) {
    const tuilesViande = page.locator('#pos-wizard-root .wizard-viande-tile button.viande-tile-add');
    R.contre_epreuve = { nb_tuiles_viande_cliquables: await tuilesViande.count() };
    if (R.contre_epreuve.nb_tuiles_viande_cliquables > 0) {
      await tuilesViande.first().click().catch(() => {});
      await page.waitForTimeout(800);
      await ajout.first().click({ timeout: 8000 }).catch((e) => { R.contre_epreuve.erreur = String(e).slice(0, 150); });
      await page.waitForTimeout(1500);
      R.contre_epreuve.capture = await shot('04-contre-epreuve');
      R.contre_epreuve.wizard = await lireWizard(page);
      R.contre_epreuve.panier = await lirePanier(page);
    } else {
      R.contre_epreuve.verdict = 'AUCUNE tuile viande à cliquer : le caissier n\'a rien à sélectionner.';
      R.contre_epreuve.capture = await shot('04-aucune-issue');
      // Dernier recours d'un caissier : cliquer TOUT ce qui est cliquable dans le wizard
      // (hors quantité/fermeture) puis réessayer. Si ça ne débloque toujours pas → mort.
      const cliquables = page.locator('#pos-wizard-root button:not([data-qty]):not(.wizard-close):not([data-action="close"])');
      const n = Math.min(await cliquables.count(), 14);
      R.contre_epreuve.nb_boutons_essayes = n;
      for (let i = 0; i < n; i++) {
        const bb = cliquables.nth(i);
        const t = ((await bb.textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
        if (/Ajouter au panier/i.test(t)) continue;
        await bb.click({ timeout: 1500 }).catch(() => {});
      }
      await page.waitForTimeout(600);
      await ajout.first().click({ timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(1500);
      R.contre_epreuve.capture_apres_tout_cliquer = await shot('05-apres-tout-cliquer');
      R.contre_epreuve.wizard = await lireWizard(page);
      R.contre_epreuve.panier = await lirePanier(page);
    }
  }

  // ===== PHASE B — LE GESTE RÉALISTE DU CAISSIER =====
  // Rouvrir le produit proprement, choisir UNE seule sauce (Ketchup), rien d'autre,
  // puis Ajouter. C'est le geste que fait un vrai caissier. Mesure du DELTA panier.
  R.phase_b = {};
  try {
    await ouvrirCaisse(page);
    await page.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {});
    await page.waitForTimeout(2500);
    R.phase_b.panier_avant = await lirePanier(page);
    await page.locator('[data-testid="pos-category-tile"]')
      .filter({ hasText: new RegExp(`^\\s*${cas.rayon}\\s*$`, 'i') }).first().click();
    await page.waitForTimeout(1500);
    await page.locator(`button.pos-item-tile[data-pos-item-id="${cas.itemId}"]`).first().click();
    await page.waitForSelector('#pos-wizard-root', { timeout: 15_000, state: 'attached' }).catch(() => {});
    await page.waitForTimeout(1600);

    // Témoin : il FAUT aussi choisir la viande (le produit en a 3 réelles).
    const tv = page.locator('#pos-wizard-root .wizard-viande-tile button.viande-tile-add');
    R.phase_b.nb_viandes = await tv.count();
    if (R.phase_b.nb_viandes > 0) { await tv.first().click().catch(() => {}); await page.waitForTimeout(500); }

    // .sauce-chip contient des <span> masqués (« offerte », « +€0.50 ») : un match
    // exact sur le texte du bouton échoue. On cible le libellé, pas le bouton entier.
    const sauce = page.locator('#pos-wizard-root button.sauce-chip')
      .filter({ has: page.locator('.sauce-chip__label', { hasText: /^Ketchup$/ }) }).first();
    R.phase_b.sauce_trouvee = await sauce.count();
    if (R.phase_b.sauce_trouvee > 0) { await sauce.click().catch(() => {}); await page.waitForTimeout(700); }
    R.phase_b.capture_avant_ajout = path.join(OUT, `${cas.slug}-B1-avant-ajout.png`);
    await page.screenshot({ path: R.phase_b.capture_avant_ajout });

    const btn = page.locator('#pos-wizard-root button').filter({ hasText: /Ajouter au panier/i }).first();
    R.phase_b.total_wizard = ((await page.locator('#pos-wizard-root .wizard-sticky-bar').first()
      .textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim().slice(0, 160);
    // Le bouton Vue CACHÉ « Ajouter au panier · X € » existe hors du wizard : on prouve
    // qu'on ne l'a pas cliqué (piège connu de cette caisse).
    R.phase_b.bouton_vue_cache = await page.evaluate(() => {
      const b = document.querySelector('[data-wiz-vue-footer] button, .pos-v5-item-add-cta');
      if (!b) return null;
      const r = b.getBoundingClientRect();
      return { texte: String(b.textContent || '').replace(/\\s+/g, ' ').trim().slice(0, 60),
               dans_wizard_root: !!(document.getElementById('pos-wizard-root') || {}).contains
                 && document.getElementById('pos-wizard-root').contains(b),
               visible: r.width > 0 && r.height > 0 };
    });
    await btn.click({ timeout: 8000 }).catch((e) => { R.phase_b.erreur_clic = String(e).slice(0, 150); });
    await page.waitForTimeout(1800);
    R.phase_b.capture_apres_ajout = path.join(OUT, `${cas.slug}-B2-apres-ajout.png`);
    await page.screenshot({ path: R.phase_b.capture_apres_ajout });
    R.phase_b.wizard = await lireWizard(page);
    R.phase_b.wizard_ferme = !R.phase_b.wizard.root_present;
    R.phase_b.panier_apres = await lirePanier(page);
    R.phase_b.lignes_produit = await page.evaluate((nom) => Array.from(
      document.querySelectorAll('.pos-v5-cart-line, [class*="cart-item"]'))
      .map((e) => String(e.textContent || '').replace(/\s+/g, ' ').trim())
      .filter((t) => t.includes(nom)).slice(0, 4), cas.produit);
  } catch (e) { R.phase_b.erreur = String(e && e.message ? e.message : e).slice(0, 300); }

  rapport.cas.push(R);
  return R;
}

async function main() {
  clearRateLimits();
  const exe = resolveChromium();
  const browser = await chromium.launch({ headless: true, ...(exe ? { executablePath: exe } : {}) });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'fr-FR' });
  const page = await context.newPage();
  const consoleBuf = [];
  page.on('console', (m) => {
    const t = m.text();
    if (['error', 'warning'].includes(m.type()) && !/WebSocket|ERR_CONNECTION_REFUSED|favicon/i.test(t)) {
      consoleBuf.push({ t: m.type(), text: t.slice(0, 400) });
    }
  });
  page.on('pageerror', (e) => consoleBuf.push({ t: 'pageerror', text: String(e.message).slice(0, 400) }));
  page.on('dialog', async (d) => { consoleBuf.push({ t: 'dialog', text: d.message().slice(0, 300) }); await d.dismiss(); });

  const rapport = { base: BASE, date: new Date().toISOString(), cas: [] };
  try {
    await login(page);
    for (const cas of CAS) {
      try { await mesurerUnCas(page, cas, rapport); }
      catch (e) { rapport.cas.push({ ...cas, erreur: String(e && e.message ? e.message : e).slice(0, 500) }); }
    }
  } catch (e) {
    rapport.erreur_globale = String(e && e.message ? e.message : e).slice(0, 500);
  }
  rapport.console = consoleBuf;
  fs.writeFileSync(path.join(OUT, 'rapport.json'), JSON.stringify(rapport, null, 2), 'utf8');
  fs.writeFileSync(path.join(OUT, 'dernier-dom.html'), (await page.content()).slice(0, 3_000_000), 'utf8');

  console.log(JSON.stringify(rapport.cas.map((c) => ({
    role: c.role, produit: c.produit, erreur: c.erreur || null,
    cartes_ciblees: c.nb_cartes_ciblees,
    wizard_apparu: c.wizard_apparu,
    single_page: c.wizard && c.wizard.single_page,
    nb_sections: c.wizard && c.wizard.nb_sections,
    sections: c.wizard && c.wizard.sections && c.wizard.sections.map((s) => s.titre),
    viande_section: c.wizard && c.wizard.viande_section_presente,
    tuiles_viande: c.wizard && c.wizard.nb_tuiles_viande_visibles,
    noms_viandes: c.wizard && c.wizard.noms_viandes,
    bouton_ajout: c.bouton_ajout,
    erreur_apres_ajout: c.wizard_apres_ajout && c.wizard_apres_ajout.erreur_validation,
    wizard_encore_ouvert: c.wizard_encore_ouvert,
    panier_apres: c.panier_apres,
    details_api: c.details_api,
    PHASE_B_realiste: c.phase_b && {
      panier_avant_total: c.phase_b.panier_avant && c.phase_b.panier_avant.total,
      viandes: c.phase_b.nb_viandes, sauce_trouvee: c.phase_b.sauce_trouvee,
      total_wizard: c.phase_b.total_wizard,
      wizard_ferme: c.phase_b.wizard_ferme,
      erreur_restante: c.phase_b.wizard && c.phase_b.wizard.erreur_validation,
      panier_total: c.phase_b.panier_apres && c.phase_b.panier_apres.total,
      lignes_produit: c.phase_b.lignes_produit,
      erreur: c.phase_b.erreur,
    },
    contre_epreuve: c.contre_epreuve && {
      tuiles: c.contre_epreuve.nb_tuiles_viande_cliquables,
      verdict: c.contre_epreuve.verdict,
      erreur: c.contre_epreuve.wizard && c.contre_epreuve.wizard.erreur_validation,
      panier: c.contre_epreuve.panier,
    },
  })), null, 2));
  console.log('CONSOLE:', JSON.stringify(consoleBuf.slice(0, 10), null, 2));
  await browser.close();
}

main().catch((e) => { console.error(e); process.exit(1); });
