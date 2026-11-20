/**
 * [CHEF-MESURE 2026-09-02] « PAGE MORTE » — un pas OPTIONNEL dont les choix se
 * résolvent à ZÉRO produit-il une page vide à franchir, ou est-il escamoté ?
 *
 * Cas mesurés (tous EXISTENT en base, vérifié par SELECT) :
 *   - Cayenne (Sandwichs, item 22) : viande_2 = 0 choix, min_select=0.
 *   - Tacos M (Tacos, item 26)     : viande_2 ET viande_3 = 0 choix, min_select=0.
 *   - Méga    (Sandwichs, item 104): viande_2 = 7 choix — TÉMOIN SAIN, doit s'afficher.
 *
 * Deux instruments INDÉPENDANTS, tous deux enregistrés :
 *   (A) la RÉPONSE RÉSEAU /admin/item/{id} → composer_profile projeté par
 *       ComposerProfileProjection (ce que le serveur envoie vraiment) ;
 *   (B) le DOM RÉEL du wizard caisse après ouverture du produit (ce que le
 *       client voit vraiment).
 * Si (A) et (B) divergent, on le dit — on ne choisit pas.
 *
 * N'ENCAISSE RIEN, N'AJOUTE RIEN AU PANIER, NE PUBLIE RIEN, N'ÉCRIT RIEN EN BASE.
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
const OUT = path.join(REPO, 'reports/test-e2e/chef-mesure/page-morte');
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';

const CAS = [
  { produit: 'Cayenne', item_id: 22, rayon: 'Sandwichs', attendu: 'viande_2 vide (0 choix)' },
  { produit: 'Méga', item_id: 104, rayon: 'Sandwichs', attendu: 'TÉMOIN — viande_2 à 7 choix' },
  { produit: 'Tacos M', item_id: 26, rayon: 'Tacos', attendu: 'viande_2 ET viande_3 vides' },
];

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

/**
 * INSTRUMENT B — ce que le DOM affiche vraiment.
 * On compte les PAGES de deux façons indépendantes :
 *   - `.step-label` = barre de progression du wizard MULTI-PAGES ;
 *   - `.wizard-section`/`.wizard-2col-block`/`.pain-section` = blocs du wizard SINGLE-PAGE.
 * Puis on cherche explicitement une zone « Viande 2 » / « Viande 3 ».
 */
async function lireWizard(page) {
  return page.evaluate(() => {
    const wiz = document.querySelector('.pos-wizard');
    if (!wiz) return { present: false };
    const txt = (el) => (el ? String(el.textContent || '').replace(/\s+/g, ' ').trim() : '');
    const vis = (el) => {
      if (!el) return false;
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
    };

    const pagesBarre = Array.from(wiz.querySelectorAll('.step-label')).map(txt);
    const blocs = Array.from(
      wiz.querySelectorAll('.wizard-section, .wizard-2col-block, .pain-section, .wizard-step'),
    ).map((el) => ({
      classe: String(el.className || ''),
      titre: txt(el.querySelector('h3, h4')),
      visible: vis(el),
      // nb d'options cliquables réellement offertes DANS ce bloc
      nb_options: el.querySelectorAll(
        '.wizard-viande-tile, .wizard-option, .pain-btn, [data-viande], [data-type="supplement"], [data-type="crudite"], [data-type="sauce"], [data-type="pain"], .sauce-chips-grid > *',
      ).length,
      badge: txt(el.querySelector('.quota-badge, .sauce-badge')),
      texte: txt(el).slice(0, 260),
    }));

    // Y a-t-il un intitulé « Viande 2 » / « Viande 3 » quelque part dans le wizard ?
    const mentions = [];
    wiz.querySelectorAll('*').forEach((el) => {
      if (el.children.length) return; // feuilles seulement, pas de doublons parents
      const t = txt(el);
      if (/viande\s*[23]\b/i.test(t)) {
        mentions.push({ tag: el.tagName, classe: String(el.className || '').slice(0, 60), texte: t.slice(0, 120), visible: vis(el) });
      }
    });

    // Contre-épreuve : les INTITULÉS EXACTS du profil composeur (« Choisis ta viande »,
    // « Viande 2 »…) apparaissent-ils quelque part dans le wizard ? Si aucun n'apparaît,
    // c'est que le rendu n'utilise pas les pas du profil.
    const wizTexte = txt(wiz);
    const intitulesComposeur = [
      'Choisis ton pain', 'Choisis ta viande', 'Choisis tes viandes', 'Viande 2', 'Viande 3',
      'Choisis ta sauce', 'Choisis tes garnitures', 'Suppléments', 'Choisis ta formule', 'Choisis la taille',
    ];
    const intitules_trouves = intitulesComposeur.filter((l) => wizTexte.includes(l));

    const navBoutons = Array.from(wiz.querySelectorAll('button')).map((b) => ({
      texte: txt(b), disabled: !!b.disabled, visible: vis(b), classe: String(b.className || '').slice(0, 70),
    })).filter((b) => b.texte);

    return {
      present: true,
      mode_single_page: wiz.classList.contains('single-page'),
      pages_barre_progression: pagesBarre,
      nb_pages_barre: pagesBarre.length,
      blocs,
      nb_blocs_visibles: blocs.filter((b) => b.visible).length,
      mentions_viande_2_ou_3: mentions,
      intitules_composeur_trouves_dans_le_dom: intitules_trouves,
      nav_boutons: navBoutons.slice(0, 20),
      texte_wizard: txt(wiz).slice(0, 2500),
    };
  });
}

async function main() {
  clearRateLimits();
  const exe = resolveChromium();
  const browser = await chromium.launch({ headless: true, ...(exe ? { executablePath: exe } : {}) });
  const context = await browser.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'fr-FR' });
  const page = await context.newPage();

  const consoleBuf = [];
  page.on('console', (m) => {
    const t = m.text();
    // Le pas escamoté par buildStepsFromComposerProfile logge « step skipped (unsupported) ».
    if (['error', 'warning'].includes(m.type()) || /composer/i.test(t)) {
      consoleBuf.push({ t: m.type(), text: t.slice(0, 400) });
    }
  });
  page.on('pageerror', (e) => consoleBuf.push({ t: 'pageerror', text: String(e.message).slice(0, 400) }));
  page.on('dialog', async (d) => { await d.dismiss(); });

  const echecsApi = [];
  page.on('response', (resp) => {
    if (resp.status() >= 400 && /\/api\//.test(resp.url())) {
      echecsApi.push({ statut: resp.status(), url: resp.url().slice(0, 160) });
    }
  });

  // INSTRUMENT A — la projection serveur, captée au vol.
  const profilsReseau = {};
  page.on('response', async (resp) => {
    const u = resp.url();
    if (!/admin\/item\/details\/\d+/.test(u)) return;
    try {
      const j = await resp.json();
      const d = j && j.data;
      if (!d) return;
      const cp = d.composer_profile || null;
      profilsReseau[String(d.id ?? 'inconnu')] = {
        url: u,
        nom: d.name,
        composer_profile_present: !!cp,
        profil_id: cp ? cp.id : null,
        version: cp ? cp.version : null,
        pas: cp && Array.isArray(cp.steps) ? cp.steps.map((s) => ({
          step_key: s.step_key, label: s.label, min_select: s.min_select, max_select: s.max_select,
          is_active: s.is_active, source_type: s.source_type, source_ref: s.source_ref,
          nb_choix: Array.isArray(s.choices) ? s.choices.length : null,
          choix: Array.isArray(s.choices) ? s.choices.map((c) => c.name).slice(0, 10) : null,
        })) : null,
        attributs_envoyes: Array.isArray(d.itemAttributes) ? d.itemAttributes.map((a) => a.name) : null,
        variations_par_attribut: d.variations && typeof d.variations === 'object'
          ? Object.fromEntries(Object.entries(d.variations).map(([k, v]) => [k, Array.isArray(v) ? v.length : null]))
          : null,
      };
    } catch (_) { /* pas du JSON exploitable */ }
  });

  const rapport = { base: BASE, date: new Date().toISOString(), cas: [] };

  try {
    await login(page);

    for (const c of CAS) {
      const fiche = { ...c, etapes: [] };
      try {
        await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded', timeout: 40_000 });
        await page.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {});
        await page.waitForTimeout(2200);

        // Drapeau composer-aware tel que le NAVIGATEUR le voit (pas tel que .env le dit).
        fiche.drapeau_composer_aware = await page.evaluate(() => {
          const f = window.foodkingConfig && window.foodkingConfig.posWizardComposerAware;
          return f ? !!f.enabled : 'absent de window.foodkingConfig';
        });

        // Caisse CATÉGORIE-D'ABORD : sans ouvrir le rayon, aucune grille produit n'existe.
        // Attendre la PRÉSENCE des tuiles : « 0 tuile » = page pas encore peuplée, pas un défaut.
        await page.locator('[data-testid="pos-category-tile"]').first()
          .waitFor({ state: 'visible', timeout: 30_000 })
          .catch(async () => {
            await page.screenshot({ path: path.join(OUT, `ECHEC-rayons-${c.item_id}.png`), fullPage: true });
          });
        const tuile = page.locator('[data-testid="pos-category-tile"]')
          .filter({ hasText: new RegExp(`^\\s*${c.rayon}\\s*$`, 'i') }).first();
        if (!(await tuile.count())) {
          const vus = await page.locator('[data-testid="pos-category-tile"]').allTextContents();
          throw new Error(`Rayon « ${c.rayon} » introuvable. Vus : ${vus.map((t) => t.trim()).join(' | ')}`);
        }
        await tuile.scrollIntoViewIfNeeded();
        await tuile.click();
        await page.waitForTimeout(1600);
        fiche.etapes.push({ etape: 'rayon_ouvert', rayon: c.rayon });

        // Nom EXACT : « Cayenne » ne doit pas attraper « Galette Cayenne ».
        const esc = c.produit.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const carte = page.locator('[data-testid^="pos-product"], .pos-v5-product, [class*="product-card"]')
          .filter({ has: page.getByText(new RegExp(`^\\s*${esc}\\s*$`, 'i')) }).first();

        let ouvert = false;
        if (await carte.count()) { await carte.scrollIntoViewIfNeeded(); await carte.click(); ouvert = true; }
        else {
          const parNom = page.getByText(new RegExp(`^\\s*${esc}\\s*$`, 'i')).first();
          if (await parNom.count()) { await parNom.scrollIntoViewIfNeeded(); await parNom.click(); ouvert = true; }
        }
        fiche.etapes.push({ etape: 'produit_clique', ouvert });
        await page.locator('.pos-wizard').first()
          .waitFor({ state: 'visible', timeout: 25_000 })
          .catch(() => { fiche.etapes.push({ etape: 'wizard_jamais_visible' }); });
        await page.waitForTimeout(1500);

        const slug = c.produit.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, '-').toLowerCase();
        await page.screenshot({ path: path.join(OUT, `${slug}-wizard.png`), fullPage: true });
        fiche.capture = path.join(OUT, `${slug}-wizard.png`);

        fiche.dom = await lireWizard(page);
        fiche.reseau = profilsReseau[String(c.item_id)] || null;

        fs.writeFileSync(path.join(OUT, `${slug}.dom.html`), (await page.content()).slice(0, 3_000_000), 'utf8');
        fiche.dom_html = path.join(OUT, `${slug}.dom.html`);
      } catch (e) {
        fiche.erreur = String(e && e.message ? e.message : e).slice(0, 600);
      }
      rapport.cas.push(fiche);
    }
  } catch (e) {
    rapport.erreur_globale = String(e && e.message ? e.message : e).slice(0, 600);
  }

  rapport.echecs_api = echecsApi;
  rapport.console = consoleBuf;
  rapport.profils_reseau_bruts = profilsReseau;
  fs.writeFileSync(path.join(OUT, 'rapport.json'), JSON.stringify(rapport, null, 2), 'utf8');

  console.log(JSON.stringify(rapport.cas.map((c) => ({
    produit: c.produit,
    erreur: c.erreur || null,
    drapeau_composer_aware: c.drapeau_composer_aware,
    profil_reseau: c.reseau
      ? {
          reponse_captee: true,
          composer_profile_present: c.reseau.composer_profile_present,
          id: c.reseau.profil_id,
          v: c.reseau.version,
          pas: Array.isArray(c.reseau.pas)
            ? c.reseau.pas.map((p) => `${p.step_key}(min=${p.min_select},choix=${p.nb_choix},actif=${p.is_active})`)
            : 'PAS DE STEPS DANS LA REPONSE',
          attributs_envoyes: c.reseau.attributs_envoyes,
          variations_par_attribut: c.reseau.variations_par_attribut,
        }
      : 'AUCUNE REPONSE CAPTEE',
    dom_single_page: c.dom ? c.dom.mode_single_page : null,
    dom_pages_barre: c.dom ? c.dom.pages_barre_progression : null,
    dom_present: c.dom ? c.dom.present : null,
    dom_blocs: (c.dom && Array.isArray(c.dom.blocs)) ? c.dom.blocs.filter((b) => b.visible).map((b) => `${b.titre || '(sans titre)'} [${b.nb_options} opt]${b.badge ? ' {' + b.badge + '}' : ''}`) : null,
    mentions_viande_2_3: c.dom ? c.dom.mentions_viande_2_ou_3 : null,
    intitules_composeur_dans_le_dom: c.dom ? c.dom.intitules_composeur_trouves_dans_le_dom : null,
  })), null, 2));
  console.log('--- ECHECS API (4xx/5xx) ---');
  console.log(JSON.stringify(echecsApi.slice(0, 20), null, 2));
  console.log('--- CONSOLE ---');
  console.log(JSON.stringify(consoleBuf.slice(0, 25), null, 2));

  await browser.close();
}

main().catch((e) => { console.error(e); process.exit(1); });
