// [E2E 2026-10-22 · tour 2 · vague B] Fermetures B-002 (alerte uber_attention) et B-009 (en-tête collant de la modale Tournées)
// dans le VRAI navigateur, avec le bundle compilé. Serveur simulé par page.route (Uber éteint, aucun appel réel).
// Preuve par elementFromPoint ; contrôle de morsure : on retire `sticky` en direct et l'instrument doit alors DÉTECTER la disparition.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../../e2e/helpers/login');
const { shot, DIR } = require('./r2-lib');

const RES = path.join(DIR, '_resultats-fermetures.json');
const R = {};
const stop = (o) => ({ id: 1, order_id: 9001, order_number: '9001', customer: 'Karim', address: '10 rue Test, Hénin-Beaumont', collect_method: 'paid_online', amount_due: 0, left_at: null, arrived_at: null, delivered_at: null, has_photo: false, dispatch: 'own', uber: null, uber_blocked: null, uber_attention: null, ...o });
let waves;
function fabrique() {
  const w = (i, stops) => ({ wave_at: `2026-10-23 ${String(18 + Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}:00`, time: `${18 + Math.floor(i / 2)}:${i % 2 ? '30' : '00'}`, date: '2026-10-23', is_today: true, day_label: "aujourd'hui", left: false, courier: 'Kiki', courier_id: 3, stops });
  let id = 1;
  const mk = (n, extra = {}) => Array.from({ length: n }, () => { id += 1; return stop({ id, order_id: 9000 + id, order_number: String(9000 + id), customer: 'Client ' + id, ...extra }); });
  return [
    w(0, mk(3)),
    w(1, [stop({ id: 500, order_id: 9500, order_number: '9500', customer: 'Sonia (sac pris)', left_at: '2026-10-23 18:40:00', dispatch: 'uber', uber: { status: 'dropoff', tracking_url: 'https://uber.test/t/1', fee: 7.42, courier_name: 'Marc', bag_taken: true }, uber_attention: 'Uber a ramené la commande au restaurant alors que le coursier avait le sac : la commande n\'est pas livrée. À traiter avec le client.' })]),
    w(2, mk(4)),
    w(3, mk(4)),
    w(4, mk(4)),
  ];
}
const planche = () => ({ status: true, enabled: true, uber_enabled: true, couriers: [], data: waves });

async function ouvrir(page) {
  await page.setViewportSize({ width: 1280, height: 720 });
  await loginAsPosOperator(page);
  await page.route('**/api/admin/pos-delivery/board', (r) => r.fulfill({ json: planche() }));
  await page.goto('/admin/pos');
  await page.waitForLoadState('networkidle');
  await page.locator('[data-testid="pos-delivery-board-open"]').click({ timeout: 20000 });
  await expect(page.locator('[data-testid="pos-delivery-board"]')).toBeVisible();
}
const carte = (page) => page.locator('[data-testid="pos-delivery-board"] > div');
const toucheFermer = (page) => page.evaluate(() => {
  const b = [...document.querySelectorAll('[data-testid="pos-delivery-board"] button')].find((x) => /Fermer/.test(x.textContent));
  const r = b.getBoundingClientRect();
  const e = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
  return { rect: { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) }, hit: !!e && (e === b || b.contains(e)), dansViewport: r.top >= 0 && r.bottom <= innerHeight, cible: e ? e.tagName + ' ' + String(e.textContent || '').trim().slice(0, 30) : null };
});

test.afterAll(() => { fs.writeFileSync(RES, JSON.stringify(R, null, 2)); });

test('B-002 : alerte rouge role=alert visible sur la planche, avec le texte du serveur', async ({ page }) => {
  test.setTimeout(120_000);
  const erreurs = []; page.on('pageerror', (e) => erreurs.push(String(e.message || e)));
  waves = fabrique();
  await ouvrir(page);
  const al = page.locator('[data-testid="pos-delivery-uber-attention"]');
  await expect(al).toHaveCount(1);
  await al.scrollIntoViewIfNeeded();
  await expect(al).toBeVisible();
  const info = await al.evaluate((el) => { const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); return { role: el.getAttribute('role'), texte: el.textContent.trim(), couleur: cs.color, fond: cs.backgroundColor, dansViewport: r.top >= 0 && r.bottom <= innerHeight, rect: [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)] }; });
  R.b002 = info;
  expect(info.role).toBe('alert');
  expect(info.texte).toContain('la commande n\'est pas livrée');
  expect(info.dansViewport, 'l\'alerte est dans la fenêtre après scrollIntoView').toBe(true);
  // la planche ne propose PAS de reprendre le sac : « Nos livreurs » grisé (bag_taken) pour cet arrêt
  await expect(page.locator('[data-testid="pos-delivery-dispatch-500"] [data-testid="pos-delivery-use-own"]')).toBeDisabled();
  await shot(page, { path: path.join(DIR, 'F1-alerte-uber-attention-1280x720.png') });
  expect(erreurs).toEqual([]);
});

test('B-009 : en-tête et « Fermer » toujours touchables après défilement (1280x720), instrument qui mord', async ({ page }) => {
  test.setTimeout(120_000);
  waves = fabrique();
  await ouvrir(page);
  const c = carte(page);
  const dim = await c.evaluate((el) => ({ sh: el.scrollHeight, ch: el.clientHeight, pos: getComputedStyle(el.querySelector('div')).position }));
  R.b009 = { dim };
  expect(dim.sh, 'la modale défile réellement').toBeGreaterThan(dim.ch + 200);
  R.b009.avant = await toucheFermer(page);
  expect(R.b009.avant.hit).toBe(true);

  await c.evaluate((el) => { el.scrollTop = el.scrollHeight; });
  await page.waitForTimeout(200);
  const st = await c.evaluate((el) => el.scrollTop);
  R.b009.scrollTop_bas = st;
  expect(st).toBeGreaterThan(300);
  R.b009.apres_bas = await toucheFermer(page);
  const ent = await page.evaluate(() => { const card = document.querySelector('[data-testid="pos-delivery-board"] > div'); const h = card.firstElementChild; return { carteTop: Math.round(card.getBoundingClientRect().top), enteteTop: Math.round(h.getBoundingClientRect().top), position: getComputedStyle(h).position, titre: h.textContent.trim().slice(0, 40) }; });
  R.b009.entete_bas = ent;
  expect(R.b009.apres_bas.hit, 'Fermer touchable en bas de planche').toBe(true);
  expect(R.b009.apres_bas.dansViewport).toBe(true);
  R.b009.decalage_entete_px = ent.enteteTop - ent.carteTop; // observé, pas assertion : voir le constat
  // le contenu défilé est-il visible AU-DESSUS de l'en-tête (bande de 15 px) ?
  R.b009.bande_au_dessus = await page.evaluate(() => { const card = document.querySelector('[data-testid="pos-delivery-board"] > div'); const cr = card.getBoundingClientRect(); const h = card.firstElementChild.getBoundingClientRect(); const out = []; for (const el of card.querySelectorAll('section, li, header, p, span, button')) { if (card.firstElementChild.contains(el)) continue; const r = el.getBoundingClientRect(); if (r.height > 0 && r.top < h.top - 1 && r.bottom > cr.top + 1) out.push((el.tagName + ' ' + (el.textContent || '').trim().slice(0, 24))); } return { hauteur_bande: Math.round(h.top - cr.top), elements_visibles_dans_la_bande: out.slice(0, 5), n: out.length }; });
  await shot(page, { path: path.join(DIR, 'F2-planche-defilee-fermer-visible-1280x720.png') });

  // milieu de course aussi
  await c.evaluate((el) => { el.scrollTop = Math.round(el.scrollHeight / 2); });
  await page.waitForTimeout(150);
  R.b009.apres_milieu = await toucheFermer(page);
  expect(R.b009.apres_milieu.hit).toBe(true);

  // CONTRÔLE DE MORSURE : sans `sticky`, l'instrument doit voir « Fermer » disparaître (sinon la preuve ne prouve rien).
  await c.evaluate((el) => { const h = el.firstElementChild; h.style.position = 'static'; el.scrollTop = el.scrollHeight; });
  await page.waitForTimeout(150);
  R.b009.controle_sans_sticky = await toucheFermer(page);
  expect(R.b009.controle_sans_sticky.hit && R.b009.controle_sans_sticky.dansViewport, 'sans sticky, Fermer sort de la fenêtre : l\'instrument mord').toBe(false);

  // et la fermeture marche vraiment (clic réel après défilement, en-tête collant restauré)
  await c.evaluate((el) => { el.firstElementChild.style.position = ''; el.scrollTop = el.scrollHeight; });
  await page.waitForTimeout(150);
  await page.getByRole('button', { name: 'Fermer' }).last().click();
  await expect(page.locator('[data-testid="pos-delivery-board"]')).toHaveCount(0);
  R.b009.fermeture_par_clic = true;
});

test('sonde : un refus Uber (422) se lit-il quand le clic a eu lieu au bas de la planche ?', async ({ page }) => {
  test.setTimeout(120_000);
  waves = fabrique();
  waves[4].stops[3].uber_blocked = null;
  const ciblesId = waves[4].stops[3].id;
  await page.route('**/api/admin/pos-delivery/uber/quote', (r) => r.fulfill({ status: 422, json: { status: false, ok: false, message: 'Uber ne répond pas pour le moment : la commande reste à nos livreurs.' } }));
  await ouvrir(page);
  const c = carte(page);
  const bouton = page.locator(`[data-testid="pos-delivery-dispatch-${ciblesId}"] [data-testid="pos-delivery-use-uber"]`);
  await bouton.scrollIntoViewIfNeeded();
  await c.evaluate((el) => { el.scrollTop = el.scrollHeight; });
  await bouton.click();
  await page.waitForTimeout(800);
  const bandeau = page.locator('[data-testid="pos-delivery-board"] [role="alert"]:not([data-testid="pos-delivery-uber-attention"])');
  const n = await bandeau.count();
  const info = n ? await bandeau.first().evaluate((el) => { const r = el.getBoundingClientRect(); const card = document.querySelector('[data-testid="pos-delivery-board"] > div'); return { texte: el.textContent.trim(), top: Math.round(r.top), bottom: Math.round(r.bottom), carteTop: Math.round(card.getBoundingClientRect().top), carteBottom: Math.round(card.getBoundingClientRect().bottom), scrollTop: Math.round(card.scrollTop), visible: r.bottom > card.getBoundingClientRect().top + 50 && r.top < card.getBoundingClientRect().bottom }; }) : null;
  R.sonde_erreur_bas = { n, info };
  await shot(page, { path: path.join(DIR, 'F3-refus-uber-clic-en-bas-1280x720.png') });
});

test('sonde : le focus clavier (Shift+Tab, vers le haut) passe-t-il SOUS l\'en-tête collant ?', async ({ page }) => {
  test.setTimeout(120_000);
  waves = fabrique();
  await ouvrir(page);
  const c = carte(page);
  await c.evaluate((el) => { el.scrollTop = el.scrollHeight; });
  await page.evaluate(() => { const bs = [...document.querySelectorAll('[data-testid="pos-delivery-board"] button:not([disabled])')]; bs[bs.length - 1].focus(); });
  const obscurcis = [];
  let n = 0;
  for (let i = 0; i < 40; i++) {
    await page.keyboard.press('Shift+Tab');
    const m = await page.evaluate(() => {
      const el = document.activeElement; if (!el || !el.closest('[data-testid="pos-delivery-board"]')) return null;
      const card = document.querySelector('[data-testid="pos-delivery-board"] > div');
      const head = card.firstElementChild.getBoundingClientRect();
      const r = el.getBoundingClientRect();
      const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
      const top = document.elementFromPoint(cx, cy);
      return { t: (el.innerText || '').trim().slice(0, 28), recouvert: !(top === el || el.contains(top) || (top && top.contains(el))), top: Math.round(r.top), enteteBas: Math.round(head.bottom), sousEntete: r.top < head.bottom - 1 && r.bottom > head.top };
    });
    if (!m) break;
    n += 1;
    if (m.recouvert || m.sousEntete) obscurcis.push(m);
  }
  R.sonde_focus_clavier = { pas: n, obscurcis: obscurcis.length, exemples: obscurcis.slice(0, 4) };
  await shot(page, { path: path.join(DIR, 'F4-focus-clavier-sous-entete-1280x720.png') });
});

test('B-002 (forme réelle du serveur après « returned » post-sac) : dispatch=own, left_at posé, alerte visible, rien à reprendre', async ({ page }) => {
  test.setTimeout(120_000);
  waves = [{ wave_at: '2026-10-23 18:00:00', time: '18:00', date: '2026-10-23', is_today: true, day_label: "aujourd'hui", left: true, courier: 'Kiki', courier_id: 3, stops: [stop({ id: 700, order_id: 9700, order_number: '9700', customer: 'Sonia', left_at: '2026-10-23 18:05:00', dispatch: 'own', uber: null, uber_attention: 'Uber a ramené la commande au restaurant alors que le coursier avait le sac : la commande n\'est pas livrée. À traiter avec le client.' })] }];
  await ouvrir(page);
  const al = page.locator('[data-testid="pos-delivery-uber-attention"]');
  await expect(al).toBeVisible();
  await expect(al).toHaveAttribute('role', 'alert');
  const btn = page.locator('[data-testid="pos-delivery-dispatch-700"] [data-testid="pos-delivery-use-uber"]');
  await expect(btn).toBeDisabled();
  R.b002_forme_own = { alerte: true, bouton_uber_desactive: true, raison: (await page.locator('[data-testid="pos-delivery-dispatch-700"] [data-testid="pos-delivery-uber-reason"]').innerText()).trim(), actions_pour_acquitter: await page.locator('[data-testid="pos-delivery-board"] button').allInnerTexts() };
});
