/**
 * Mesure BORNE (kiosk) — un pas obligatoire SANS choix bloque-t-il le client ?
 *
 * Cas mesuré : Chicken Burger (item 38, cat 4 Burgers). Profil composeur PUBLIÉ
 *              de la catégorie Burgers = id 37 v2, pas "viande" min_select=1,
 *              source_ref "Viande 1", 0 variation active sur cet attribut.
 * Témoin 1   : Galette Cayenne (item 24) — même forme de pas viande obligatoire
 *              (profil catégorie 36) MAIS 8 variations actives « Viande 1 ».
 * Témoin 2   : Bol Frites (item 41) — profil composeur AU NIVEAU ITEM (id 8),
 *              seul cas où la borne reçoit réellement un composer_profile.
 *
 * Aucune commande n'est validée : on s'arrête à l'écran panier.
 * Node requis : ~/.nvm/versions/node/v22.23.2/bin/node
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const BASE = 'http://127.0.0.1:8766';
const OUT = path.dirname(new URL(import.meta.url).pathname);
const shots = path.join(OUT, 'captures');
fs.mkdirSync(shots, { recursive: true });

const CASES = [
  { tag: 'cas-chicken-burger',     itemId: 38, catId: 4, name: 'Chicken Burger' },
  { tag: 'temoin1-galette-cayenne', itemId: 24, catId: 2, name: 'Galette Cayenne' },
  { tag: 'temoin2-bol-frites',      itemId: 41, catId: 6, name: 'Bol Frites' },
];

// Cartes de choix cliquables de chaque étape (classes réelles des composants d'étape).
const CHOICE = [
  '.kiosk-step-content .kiosk-option-card',
  '.kiosk-step-content .kiosk-viande-card',
  '.kiosk-step-content .kiosk-menu-card',
  '.kiosk-step-content .kiosk-garniture-row',
  '.kiosk-step-content .kiosk-supplement-row',
  '.kiosk-step-content .kiosk-pain-card',
  '.kiosk-step-content .kiosk-taille-card',
].join(', ');

const log = [];
const say = (...a) => { const s = a.join(' '); console.log(s); log.push(s); };

async function shot(page, tag, n, label) {
  const f = path.join(shots, `${tag}-${String(n).padStart(2, '0')}-${label}.png`);
  await page.screenshot({ path: f, fullPage: false });
  say(`   [capture] ${path.basename(f)}`);
  return f;
}

const run = async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1080, height: 1500 }, locale: 'fr-FR' });
  const page = await ctx.newPage();
  // test.use({reducedMotion}) est INERTE dans ce dépôt → emulateMedia + clics forcés
  // (les cartes borne animent en boucle : Playwright refuse sinon « element is not stable »).
  await page.emulateMedia({ reducedMotion: 'reduce' });
  // serveur de dev mono-processus : 4 s par page observées -> marges larges
  page.setDefaultTimeout(90000);
  page.setDefaultNavigationTimeout(120000);

  const consoleMsgs = [];
  page.on('console', (m) => consoleMsgs.push(`[${m.type()}] ${m.text()}`));

  const details = {};   // payload EXACT du endpoint détail, par item
  page.on('response', async (res) => {
    const u = res.url();
    const m = u.match(/\/api\/frontend\/item\/details\/(\d+)/);
    if (!m) return;
    try {
      const b = await res.json();
      const d = b?.data?.data || b?.data || b;
      details[m[1]] = { url: u, composer_profile: d?.composer_profile ?? null,
        wizard_template: d?.wizard_template, viande_count: d?.viande_count,
        has_menu: d?.has_menu,
        itemAttributes: (d?.itemAttributes || []).map?.(a => a?.name) ?? d?.itemAttributes,
        variationsParAttribut: (d?.variations || []).reduce((acc, v) => {
          const k = v?.item_attribute_id; acc[k] = (acc[k] || 0) + 1; return acc; }, {}) };
    } catch (_) {}
  });

  const results = {};

  for (const c of CASES) {
    say(`\n===== ${c.name} (item ${c.itemId}) =====`);
    await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { try { localStorage.clear(); sessionStorage.clear(); } catch (e) {} });
    await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-testid="kiosk-idle-root"]', { timeout: 90000 });
    await page.waitForTimeout(3500);
    await shot(page, c.tag, 1, 'idle');

    const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
    if (await takeaway.count() > 0 && await takeaway.first().isVisible()) {
      await takeaway.first().click({ force: true });
    } else {
      await page.locator('[data-testid="kiosk-idle-root"]').click({ position: { x: 540, y: 900 }, force: true });
      await page.waitForTimeout(1200);
      if (await takeaway.count() > 0 && await takeaway.first().isVisible()) await takeaway.first().click({ force: true });
    }

    await page.waitForSelector('[data-testid="kiosk-categories-root"]', { timeout: 90000 });
    await page.waitForTimeout(1500);

    const side = page.locator(`[data-testid="kiosk-categories-sidebar-item-${c.catId}"]`);
    if (await side.count() > 0) { await side.first().click({ force: true }); await page.waitForTimeout(1200); }
    await shot(page, c.tag, 3, 'liste-produits');

    const card = page.locator(`[data-testid="kiosk-product-card-${c.itemId}"]`);
    if (await card.count() === 0) { say('   !! carte produit introuvable'); results[c.tag] = { error: 'carte introuvable' }; continue; }
    await card.first().click({ force: true });
    await page.waitForSelector('.kiosk-wizard', { timeout: 90000 });
    await page.waitForTimeout(2000);

    const steps = await page.locator('.kiosk-step-visual-label').allTextContents();
    say(`   ÉTAPES AFFICHÉES (${steps.length}) : ${JSON.stringify(steps.map(s => s.replace(/\s+/g, ' ').trim()))}`);
    const viandeStepPresent = steps.some((s) => /viande/i.test(s));
    say(`   étape « viande » présente dans le bandeau : ${viandeStepPresent ? 'OUI' : 'NON'}`);

    const walk = [];
    let bloque = false;
    for (let i = 0; i < 14; i++) {
      const question = ((await page.locator('.kiosk-step-question').first().textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
      const nextBtn = page.locator('.kiosk-btn-next').first();
      let nextLabel = ((await nextBtn.textContent().catch(() => '')) || '').trim();
      let disabled = await nextBtn.isDisabled().catch(() => null);
      const nbChoix = await page.locator(CHOICE).count();
      await shot(page, c.tag, 10 + i, `etape-${i}-${(question || 'vide').replace(/[^a-zA-Z0-9]+/g, '_').slice(0, 24)}`);

      // Si l'étape bloque, on tente de la satisfaire : premier choix non désactivé.
      let clics = 0;
      while (disabled && clics < 2) {
        const usable = page.locator(CHOICE).filter({ hasNot: page.locator('.kiosk-variation--disabled') });
        const n = await usable.count();
        if (n === 0) break;
        await usable.first().click({ force: true });
        await page.waitForTimeout(900);
        disabled = await nextBtn.isDisabled().catch(() => null);
        clics++;
      }
      nextLabel = ((await nextBtn.textContent().catch(() => '')) || '').trim();
      walk.push({ i, question, nbChoix, clics, nextLabel, disabledApresChoix: disabled });
      say(`   [${i}] "${question}" | choix_cliquables=${nbChoix} | clics=${clics} | bouton="${nextLabel}" bloqué=${disabled}`);

      if (disabled) { bloque = true; say(`   >>> IMPASSE : aucun moyen d'activer « ${nextLabel} » à l'étape « ${question} ».`); break; }
      if (/panier/i.test(nextLabel)) break;
      await nextBtn.click({ force: true });
      await page.waitForTimeout(900);
    }

    let panier = null;
    const dernier = walk[walk.length - 1];
    if (!bloque && dernier && /panier/i.test(dernier.nextLabel)) {
      await shot(page, c.tag, 39, 'recap-avant-ajout');
      const recapTxt = ((await page.locator('.kiosk-step-content').first().innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim().slice(0, 500);
      say(`   RÉCAP : ${recapTxt}`);
      await page.locator('.kiosk-btn-next').first().click({ force: true });
      await page.waitForTimeout(2500);
      await shot(page, c.tag, 40, 'apres-ajout-panier');
      const cartTxt = ((await page.locator('[data-testid="kiosk-categories-cart-indicator"]').textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
      const total = ((await page.locator('[data-testid="kiosk-categories-cart-total"]').textContent().catch(() => '')) || '').trim();
      say(`   PANIER (bandeau bas) : "${cartTxt}" total="${total}"`);
      const openCart = page.locator('[data-testid="kiosk-categories-cart-indicator"]').first();
      if (await openCart.count() > 0 && !(await openCart.isDisabled())) {
        await openCart.click({ force: true });
        await page.waitForTimeout(2500);
        await shot(page, c.tag, 41, 'ecran-panier');
        const body = ((await page.locator('body').innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim().slice(0, 700);
        say(`   ÉCRAN PANIER (url=${page.url()}) : ${body}`);
        panier = { cartTxt, total, url: page.url(), body };
      } else panier = { cartTxt, total };
      say(`   >>> AUCUN BLOCAGE : le produit est arrivé au panier.`);
    }

    results[c.tag] = { item: c.itemId, name: c.name, steps, viandeStepPresent, walk, bloque, panier };
  }

  fs.writeFileSync(path.join(OUT, 'resultat.json'), JSON.stringify({ results, details,
    console: consoleMsgs.filter((m) => /composer|wizard|skipped/i.test(m)) }, null, 2));

  say('\n=== composer_profile renvoyé par le endpoint DÉTAIL borne ===');
  for (const [id, d] of Object.entries(details)) {
    const cp = d.composer_profile;
    say(` item ${id} : composer_profile=${cp ? `profil ${cp.id} v${cp.version}` : 'NULL'} | wizard_template=${d.wizard_template} | viande_count=${d.viande_count} | attributs=${JSON.stringify(d.itemAttributes)} | variations/attr=${JSON.stringify(d.variationsParAttribut)}`);
    if (cp) (cp.steps || []).forEach((s) => say(`    - ${s.step_key} "${s.label}" min=${s.min_select} choix=${(s.choices || []).length}`));
  }
  fs.writeFileSync(path.join(OUT, 'journal.txt'), log.join('\n'));
  await browser.close();
};

run().catch((e) => { console.error('ECHEC', e); process.exit(1); });
