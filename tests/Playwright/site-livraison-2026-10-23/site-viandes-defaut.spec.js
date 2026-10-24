// [PROPRIÉTAIRE 2026-10-24] « Le Cayenne m'oblige à choisir deux viandes — ça doit être UNE seule (poulet mariné par défaut) et tout ce qui est en plus
// est un supplément à 2,50 € ; pareil pour le sandwich classique et les deux galettes. »
//
// Deux preuves indépendantes, sur la copie du site servie en :8013 :
//  (A) navigateur réel : l'étape « viandes » de chacun des 4 produits demande UNE viande, « Poulet mariné » est PRÉ-COCHÉ, on peut continuer
//      sans rien toucher ; en cocher une 2ᵉ ajoute exactement 2,50 € au total affiché ;
//  (B) contre le catalogue RÉEL de production figé (`fixtures-prod/`, lecture seule) : pour 1, 2 et 3 viandes, la ligne de commande construite par le
//      site est valide pour la production — variations dans leurs min/max, supplément viande trouvé (« Viande supplémentaire » OU « Viande en plus »,
//      nom propre au Cayenne), une seule viande « incluse », N-1 suppléments. Avant le correctif, un Cayenne à 2 viandes était refusé par le site
//      (« Viande supplémentaire n'est plus disponible pour Cayenne »), et le site imposait 2 viandes au Cayenne et au Sandwich Classique.
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//     npx playwright test tests/Playwright/site-livraison-2026-10-23/site-viandes-defaut.spec.js --workers=1 --retries=0 --reporter=line
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const SITE = process.env.SITE_URL || 'http://127.0.0.1:8013';
const FIX = path.join(__dirname, 'fixtures-prod');
const items = fs.readFileSync(path.join(FIX, 'items.json'), 'utf8');
const detail = (id) => { try { return JSON.parse(fs.readFileSync(path.join(FIX, 'details', id + '.json'), 'utf8')).data; } catch (e) { return null; } };
const PRODUITS = ['Cayenne', 'Sandwich Classique', 'Galette Classique', 'Galette Cayenne'];
const PROD_RX = /vps-418872ac|ovh\.net|(^|\.)lecayenne\.fr|js\.mollie\.com/i;

test.describe('Une seule viande obligatoire, poulet mariné par défaut, le reste à 2,50 €', () => {
  test.setTimeout(240000);

  for (const nom of PRODUITS) {
    test(`(A) ${nom} : 1 viande requise, « Poulet mariné » pré-cochée, +2,50 € par viande en plus`, async ({ browser }) => {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fr-FR' });
      const bloquees = [];
      await ctx.route((u) => PROD_RX.test(u.href), (r) => { bloquees.push(r.request().url()); return r.abort(); });
      const page = await ctx.newPage();
      try {
        await page.goto(`${SITE}/#menu`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30000 });
        await page.waitForTimeout(800);
        await page.locator(`[aria-label="Voir ${nom}"]`).first().click();
        await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
        await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15000 });
        // avance jusqu'à l'étape « viandes » (le Cayenne commence par le pain)
        const wiz = page.locator('.lc-wiz');
        for (let i = 0; i < 6 && !/CHOISIS\s+\d+\s+VIANDE/i.test(await page.locator('.lc-wiz-title').first().innerText()); i++) {
          await page.locator('.lc-wiz-foot-next').click(); await page.waitForTimeout(500);
        }
        const texte = async () => (await wiz.innerText()).replace(/\s+/g, ' ');
        let t = await texte();
        expect(t, 'une seule viande demandée').toMatch(/CHOISIS 1 VIANDE/i);
        expect(t, 'jamais deux').not.toMatch(/CHOISIS 2 VIANDES/i);
        expect(t).toMatch(/1 viande incluse · viande en plus \+2,50 € chacune/);
        expect(t, 'pré-cochée').toMatch(/1 sélectionné · 1 minimum/);
        expect(t, 'Poulet mariné est celle marquée « Inclus »').toMatch(/Poulet mariné Inclus/);
        // on peut continuer sans rien toucher
        await expect(page.locator('.lc-wiz-foot-next')).toBeEnabled();
        const prixAvant = (t.match(/Continuer\s+(\d+,\d{2}) €/) || [])[1];
        expect(prixAvant, 'prix affiché avant ajout').toBeTruthy();
        // une 2ᵉ viande : exactement +2,50 €
        await wiz.getByText('Viande Hachée', { exact: true }).first().click();
        await page.waitForTimeout(500);
        t = await texte();
        expect(t).toMatch(/2 sélectionné/);
        const prixApres = (t.match(/Continuer\s+(\d+,\d{2}) €/) || [])[1];
        const n = (s) => Number(s.replace(',', '.'));
        expect(Math.round((n(prixApres) - n(prixAvant)) * 100), `${nom} : ${prixAvant} → ${prixApres}`).toBe(250);
        await page.screenshot({ path: path.join(__dirname, '..', '..', '..', 'reports', 'test-e2e', 'site-livraison-2026-10-23', 'captures', `V-${nom.replace(/ /g, '-')}-viandes.png`) });
        expect(bloquees).toEqual([]);
      } finally { await ctx.close(); }
    });
  }

  test('(B) 1, 2 et 3 viandes : la ligne construite est valide pour la PRODUCTION (supplément « Viande en plus » du Cayenne compris)', async ({ page }) => {
    await page.route('**/*', async (route) => {
      const u = route.request().url();
      if (/vps-418872ac|lecayenne\.fr\/api/.test(u)) return route.abort();
      const m = u.match(/\/api\/frontend\/item\/details\/(\d+)/);
      if (m) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: detail(m[1]) }) });
      if (/\/api\/frontend\/item(\?|$)/.test(u)) return route.fulfill({ status: 200, contentType: 'application/json', body: items });
      return route.continue();
    });
    await page.goto(SITE + '/', { waitUntil: 'load' });
    await page.waitForFunction(() => window.LC && window.LC.api && window.LC.menu && window.LC.menu.meats, null, { timeout: 20000 });
    const lignes = await page.evaluate(async () => {
      const M = window.LC.menu; const out = []; const meat = (nm) => M.meats.find((x) => x.name === nm).id;
      for (const slug of ['cayenne', 'sandwich-classique', 'galette-classique', 'galette-cayenne']) {
        const it = M.items.find((i) => i.slug === slug);
        for (const noms of [['Poulet mariné'], ['Poulet mariné', 'Viande Hachée'], ['Poulet mariné', 'Viande Hachée', 'Nuggets']]) {
          try {
            const r = await window.LC.api.resolveLine({ slug: it.slug, name: it.name, qty: 1, viande_count: it.viandes, viandes: it.viandes, state: { viandes: noms.map(meat) } });
            out.push({ slug, viandes_incluses_site: it.viandes, n: noms.length, ok: true, item_id: r.item_id, vars: (r.item_variations || []).map((v) => v.id), extras: (r.item_extras || []).map((e) => ({ id: e.id, q: e.quantity })) });
          } catch (e) { out.push({ slug, n: noms.length, ok: false, err: (e && e.message) || String(e) }); }
        }
      }
      return out;
    });
    const problemes = [];
    for (const l of lignes) {
      if (l.viandes_incluses_site !== 1) problemes.push(`${l.slug} : le site impose ${l.viandes_incluses_site} viandes incluses (attendu 1)`);
      if (!l.ok) { problemes.push(`${l.slug} à ${l.n} viande(s) : refusé par le site — ${l.err}`); continue; }
      const d = detail(l.item_id);
      const attrOf = {}; const meta = {};
      Object.keys(d.variations || {}).forEach((a) => { const arr = Array.isArray(d.variations[a]) ? d.variations[a] : [d.variations[a]]; arr.forEach((x) => { attrOf[x.id] = a; }); const ia = (arr[0] && arr[0].item_attribute) || {}; meta[a] = { min: ia.min_select || 0, max: ia.max_select || 0, nom: ia.name }; });
      const cnt = {}; l.vars.forEach((id) => { if (attrOf[id] != null) cnt[attrOf[id]] = (cnt[attrOf[id]] || 0) + 1; else problemes.push(`${l.slug} : variation ${id} inconnue de la production`); });
      Object.keys(meta).forEach((a) => { const c = cnt[a] || 0; if (c < meta[a].min) problemes.push(`${l.slug} : « ${meta[a].nom} » manque`); if (meta[a].max && c > meta[a].max) problemes.push(`${l.slug} : « ${meta[a].nom} » en trop`); });
      const viandeAttrs = Object.keys(meta).filter((a) => /^viande/i.test(meta[a].nom || ''));
      const incluses = viandeAttrs.reduce((s, a) => s + (cnt[a] || 0), 0);
      if (incluses !== 1) problemes.push(`${l.slug} à ${l.n} viande(s) : ${incluses} viande(s) « incluse(s) » envoyée(s) (attendu 1)`);
      const exById = {}; (d.extras || []).forEach((e) => { exById[e.id] = e; });
      const supp = l.extras.filter((e) => exById[e.id] && /viande/i.test(exById[e.id].name || ''));
      const nbSupp = supp.reduce((s, e) => s + e.q, 0);
      if (nbSupp !== l.n - 1) problemes.push(`${l.slug} à ${l.n} viande(s) : ${nbSupp} supplément(s) viande envoyé(s) (attendu ${l.n - 1})`);
      supp.forEach((e) => { const prix = Number(exById[e.id].convert_price || exById[e.id].price || 0); if (Math.abs(prix - 2.5) > 0.001) problemes.push(`${l.slug} : supplément « ${exById[e.id].name} » à ${prix} € (attendu 2,50 €)`); });
      l.extras.forEach((e) => { if (!exById[e.id]) problemes.push(`${l.slug} : extra ${e.id} inconnu de la production`); });
    }
    expect(lignes.length).toBe(12);
    expect(problemes, problemes.join('\n')).toEqual([]);
  });
  // (C)(D) Compositions FIXES en production (viande_count 0) : 6 burgers et Suprême. Le site leur demandait « Choisis 1 viande » / « Choisis 2 viandes » : un choix
  // abandonné en silence (aucune viande à choisir côté serveur). Désormais : AUCUNE étape viande ; « viande en plus » reste un supplément à 2,50 €.
  for (const nom of ['Cheese Burger', 'Chicken Burger', 'Suprême']) {
    test(`(C) ${nom} : aucune étape « Choisis N viande(s) » (composition fixe)`, async ({ browser }) => {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fr-FR' });
      await ctx.route((u) => PROD_RX.test(u.href), (r) => r.abort());
      const page = await ctx.newPage();
      try {
        await page.goto(`${SITE}/#menu`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await expect(page.locator('.lc-menu-grid').first()).toBeVisible({ timeout: 30000 }); await page.waitForTimeout(800);
        await page.locator(`[aria-label="Voir ${nom}"]`).first().click();
        await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser|Ajouter/ }).first().click();
        await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 15000 }); await page.waitForTimeout(1500);
        const titres = [];
        for (let i = 0; i < 8; i++) {
          titres.push((await page.locator('.lc-wiz-title').first().innerText().catch(() => '')).replace(/\s+/g, ' '));
          const next = page.locator('.lc-wiz-foot-next');
          if (!(await next.isEnabled().catch(() => false))) break;
          if (/RÉCAP/i.test(titres[titres.length - 1])) break;
          await next.click(); await page.waitForTimeout(400);
        }
        expect(titres.join(' | '), `étapes de ${nom}`).not.toMatch(/CHOISIS\s+\d+\s+VIANDE/i);
      } finally { await ctx.close(); }
    });
  }

  test('(D) burgers et Suprême : sans viande à choisir, et « viande en plus » (supplément) donne une ligne valide pour la PRODUCTION', async ({ page }) => {
    await page.route('**/*', async (route) => {
      const u = route.request().url();
      if (/vps-418872ac|lecayenne\.fr\/api/.test(u)) return route.abort();
      const m = u.match(/\/api\/frontend\/item\/details\/(\d+)/);
      if (m) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: detail(m[1]) }) });
      if (/\/api\/frontend\/item(\?|$)/.test(u)) return route.fulfill({ status: 200, contentType: 'application/json', body: items });
      return route.continue();
    });
    await page.goto(SITE + '/', { waitUntil: 'load' });
    await page.waitForFunction(() => window.LC && window.LC.api && window.LC.menu && window.LC.menu.meats, null, { timeout: 20000 });
    const lignes = await page.evaluate(async () => {
      const M = window.LC.menu; const out = []; const meat = (nm) => M.meats.find((x) => x.name === nm).id;
      for (const slug of ['chicken-burger', 'cheese-burger', 'double-cheese', 'fish-burger', 'big-burger', 'grill-burger', 'supreme']) {
        const it = M.items.find((i) => i.slug === slug);
        for (const extra of [[], ['Viande Hachée']]) {
          try {
            const r = await window.LC.api.resolveLine({ slug: it.slug, name: it.name, qty: 1, viande_count: it.viandes, viandes: it.viandes, state: { viandes: [], viande_extra: extra.map(meat) } });
            out.push({ slug, viandes_site: it.viandes, extra: extra.length, ok: true, item_id: r.item_id, vars: (r.item_variations || []).map((v) => v.id), extras: (r.item_extras || []).map((e) => ({ id: e.id, q: e.quantity })) });
          } catch (e) { out.push({ slug, extra: extra.length, ok: false, err: (e && e.message) || String(e) }); }
        }
      }
      return out;
    });
    const problemes = [];
    for (const l of lignes) {
      if (l.viandes_site !== 0) problemes.push(`${l.slug} : le site compte ${l.viandes_site} viande(s) à choisir (production : 0)`);
      if (!l.ok) { problemes.push(`${l.slug} (+${l.extra}) : refusé par le site — ${l.err}`); continue; }
      const d = detail(l.item_id); const exById = {}; (d.extras || []).forEach((e) => { exById[e.id] = e; });
      const supp = l.extras.filter((e) => exById[e.id] && /viande/i.test(exById[e.id].name || ''));
      const n = supp.reduce((s, e) => s + e.q, 0);
      if (n !== l.extra) problemes.push(`${l.slug} : ${n} supplément(s) viande envoyé(s) (attendu ${l.extra})`);
      supp.forEach((e) => { if (Math.abs(Number(exById[e.id].convert_price || 0) - 2.5) > 0.001) problemes.push(`${l.slug} : supplément à ${exById[e.id].convert_price} € (attendu 2,50 €)`); });
      const attrOf = {}; const meta = {};
      Object.keys(d.variations || {}).forEach((a) => { const arr = Array.isArray(d.variations[a]) ? d.variations[a] : [d.variations[a]]; arr.forEach((x) => { attrOf[x.id] = a; }); const ia = (arr[0] && arr[0].item_attribute) || {}; meta[a] = { min: ia.min_select || 0, max: ia.max_select || 0, nom: ia.name }; });
      const cnt = {}; l.vars.forEach((id) => { if (attrOf[id] == null) problemes.push(`${l.slug} : variation ${id} inconnue`); else cnt[attrOf[id]] = (cnt[attrOf[id]] || 0) + 1; });
      Object.keys(meta).forEach((a) => { const c = cnt[a] || 0; if (c < meta[a].min) problemes.push(`${l.slug} : « ${meta[a].nom} » manque`); if (meta[a].max && c > meta[a].max) problemes.push(`${l.slug} : « ${meta[a].nom} » en trop`); });
    }
    expect(lignes.length).toBe(14);
    expect(problemes, problemes.join('\n')).toEqual([]);
  });
});
