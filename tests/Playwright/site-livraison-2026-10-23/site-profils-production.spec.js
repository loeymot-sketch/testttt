// Le site construit-il des lignes de commande que la PRODUCTION accepte ? — contre le catalogue RÉEL de la production.
//
// Pourquoi ce banc existe (2026-10-23) : le correctif de viandes du site (S-102) avait été validé sur la base LOCALE, où les
// burgers ont un attribut « Viande 1 ». En production ils n'en ont AUCUN (viande_count 0) : le nouveau code refusait alors
// « La viande … n'est pas proposée pour Cheese Burger » et les 6 burgers devenaient incommandables, sous des bancs verts.
// Les fixtures `fixtures-prod/` sont une copie en lecture seule du catalogue public de la production (GET item, GET item/details)
// relevée le 2026-10-23 : aucune commande, aucun secret. Le test n'appelle JAMAIS la production (tout hôte de prod est coupé).
//
// Prérequis : copie du site servie sur :8013 (voir site-livraison.spec.js). Aucun backend n'est sollicité.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const FIX = path.join(__dirname, 'fixtures-prod');
const SITE = process.env.SITE_URL || 'http://127.0.0.1:8013/';
const items = fs.readFileSync(path.join(FIX, 'items.json'), 'utf8');
const detail = (id) => {
  try { return JSON.parse(fs.readFileSync(path.join(FIX, 'details', id + '.json'), 'utf8')).data; } catch (e) { return null; }
};

test('les lignes construites respectent min/max du profil réel de la production, burgers et Suprême compris', async ({ page }) => {
  test.setTimeout(120000);
  await page.route('**/*', async (route) => {
    const u = route.request().url();
    if (/vps-418872ac|lecayenne\.fr\/api/.test(u)) return route.abort();
    const m = u.match(/\/api\/frontend\/item\/details\/(\d+)/);
    if (m) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: detail(m[1]) }) });
    if (/\/api\/frontend\/item(\?|$)/.test(u)) return route.fulfill({ status: 200, contentType: 'application/json', body: items });
    return route.continue();
  });
  await page.goto(SITE, { waitUntil: 'load' });
  await page.waitForFunction(() => window.LC && window.LC.api && window.LC.menu && window.LC.menu.meats, null, { timeout: 20000 });

  const lignes = await page.evaluate(async () => {
    const M = window.LC.menu;
    // Viandes que les produits à choix proposent réellement en production (Cayenne/Classique n'en offrent que 3).
    const pool = M.meats.filter((x) => /^(Poulet mariné|Viande Hachée|Nuggets|Tenders|Fricadelle)$/i.test(x.name));
    const out = [];
    for (const it of M.items.filter((i) => (i.viandes || 0) > 0 || /burger|cheese|big|grill|fish|suprêm/i.test(i.name))) {
      const n = Number(it.viandes || 0);
      try {
        const r = await window.LC.api.resolveLine({ slug: it.slug, name: it.name, qty: 1, viande_count: n, viandes: n, state: { viandes: pool.slice(0, n).map((x) => x.id) } });
        out.push({ name: it.name, ok: true, item_id: r.item_id, vars: (r.item_variations || []).map((v) => v.id) });
      } catch (e) { out.push({ name: it.name, ok: false, err: (e && e.message) || String(e) }); }
    }
    return out;
  });

  expect(lignes.length).toBeGreaterThan(15);
  const problemes = [];
  for (const l of lignes) {
    if (!l.ok) { problemes.push(`${l.name} : refusé par le SITE — ${l.err}`); continue; }
    const d = detail(l.item_id);
    const attrOf = {}; const meta = {};
    Object.keys((d && d.variations) || {}).forEach((a) => {
      const arr = Array.isArray(d.variations[a]) ? d.variations[a] : [d.variations[a]];
      arr.forEach((x) => { attrOf[x.id] = a; });
      const ia = (arr[0] && arr[0].item_attribute) || {};
      meta[a] = { min: ia.min_select || 0, max: ia.max_select || 0, nom: ia.name };
    });
    const inconnues = l.vars.filter((id) => attrOf[id] == null);
    if (inconnues.length) problemes.push(`${l.name} : variations inconnues de la production ${JSON.stringify(inconnues)}`);
    const cnt = {}; l.vars.forEach((id) => { if (attrOf[id] != null) cnt[attrOf[id]] = (cnt[attrOf[id]] || 0) + 1; });
    Object.keys(meta).forEach((a) => {
      const c = cnt[a] || 0;
      if (c < meta[a].min) problemes.push(`${l.name} : « ${meta[a].nom} » manque (${c} < ${meta[a].min})`);
      if (meta[a].max && c > meta[a].max) problemes.push(`${l.name} : « ${meta[a].nom} » en trop (${c} > ${meta[a].max})`);
    });
  }
  expect(problemes, problemes.join('\n')).toEqual([]);
});
