// [LIVRAISON GEO 2026-10-21 · round-1] Captures « quartet » pour le superviseur adversarial.
// Pour chaque état : PNG plein écran + DOM (conteneur) + texte visible + console + réseau (/api) + analyse automatique
// (textes bruts, débordements, chevauchements, contrastes, états vides) + checks.json (verdicts de l'instrument).
//
//   export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8767 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-supervisor-captures.spec.js --workers=1 --retries=0 --reporter=line
//
// BASE LOCALE SEULEMENT. Laisse des clients/commandes « TEST-LIVRAISON … ». Ne modifie aucun code produit.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const ROOT = path.resolve(__dirname, '../..');
const OUT = path.join(ROOT, process.env.CAPTURE_OUT || 'reports/test-e2e/livraison-geo-2026-10-21/round-1/captures');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8767';
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = env('MIX_API_KEY') || env('API_KEY');
const COURIER = { email: 'livreur.test@lecayenne.local', password: 'Livreur-Test-2026', name: 'TEST-LIVRAISON Kiki' };
const uuid = () => require('crypto').randomUUID();

function php(code) {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8', timeout: 90_000 });
  return out.trim().split('\n').filter(Boolean).pop();
}

// ── Résultats globaux ────────────────────────────────────────────────────────────────────────────────────────────────
const G = { initialToggle: null, rateLimitEvents: [], states: [], orders: {}, posOrder: null, notes: [] };
fs.mkdirSync(OUT, { recursive: true });
const flush = () => fs.writeFileSync(path.join(OUT, '_summary.json'), JSON.stringify(G, null, 2));

// ── Enregistreur console + réseau (tous les appels /api, avec corps des appels livraison) ─────────────────────────────
function record(page) {
  page.setDefaultTimeout(20000); // jamais d'attente infinie : une action impossible échoue en 20 s
  const buf = { console: [], net: [], failed: [], all: [] };
  page.on('console', (m) => { try { buf.console.push({ level: m.type(), text: m.text().slice(0, 1500), url: m.location().url, ts: Date.now() }); } catch (_) {} });
  page.on('pageerror', (e) => buf.console.push({ level: 'pageerror', text: String(e.message || e).slice(0, 1500), ts: Date.now() }));
  page.on('requestfailed', (r) => buf.failed.push({ method: r.method(), url: r.url().slice(0, 300), error: r.failure() && r.failure().errorText }));
  page.on('response', async (resp) => {
    try {
      const req = resp.request();
      const url = new URL(resp.url());
      if (!/^\/api\//.test(url.pathname)) return;
      const e = { method: req.method(), url: url.pathname + url.search, status: resp.status(), ts: Date.now() };
      if (/pos-delivery|delivery|api\/admin\/pos(\/quote)?$|delivery-run/.test(url.pathname)) {
        try { e.body = (await resp.text()).slice(0, 2500); } catch (_) {}
      }
      if (req.method() !== 'GET') { try { e.request = (req.postData() || '').slice(0, 600); } catch (_) {} }
      buf.net.push(e); buf.all.push(e);
    } catch (_) {}
  });
  buf.lastBody = (re) => { for (let i = buf.all.length - 1; i >= 0; i--) { if (re.test(buf.all[i].url) && buf.all[i].body) { try { return JSON.parse(buf.all[i].body); } catch (_) { return null; } } } return null; };
  buf.count = (method, re) => buf.all.filter((n) => n.method === method && re.test(n.url)).length;
  return buf;
}

// ── Analyse exécutée DANS la page (textes bruts, débordements, chevauchements, contrastes, états vides) ────────────────
function analysePage(rootSel) {
  const root = (rootSel && document.querySelector(rootSel)) || document.body;
  const vw = innerWidth, vh = innerHeight;
  const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const sel = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '');
  const out = { viewport: [vw, vh], suspects: [], overflow: [], pageHScroll: document.documentElement.scrollWidth > vw + 1, offscreen: [], overlaps: [], contrast: [], smallTargets: [], empties: [], toasts: [], alerts: [] };

  const bodyText = document.body.innerText || '';
  const re = /\bundefined\b|\bNaN\b|\[object Object\]|\bnull\b|0undefined|Label\.[A-Za-z_]+|\b(?:label|message|menu|button|validation|kiosk|pos|common|error)\.[a-z][a-z0-9_]{2,}(?:\.[a-z0-9_]+)*\b|\{\{|\}\}|\bInfinity\b/g;
  let m; while ((m = re.exec(bodyText)) && out.suspects.length < 20) out.suspects.push(bodyText.slice(Math.max(0, m.index - 30), m.index + m[0].length + 30).replace(/\s+/g, ' '));

  document.querySelectorAll('.Vue-Toastification__toast').forEach((t) => out.toasts.push((t.innerText || '').trim()));
  document.querySelectorAll('[role="alert"],[role="status"]').forEach((a) => { if (vis(a) && (a.innerText || '').trim()) out.alerts.push({ sel: sel(a), text: a.innerText.trim().slice(0, 300) }); });

  const all = Array.from(root.querySelectorAll('*')).slice(0, 4000);
  for (const el of all) {
    if (!vis(el)) continue;
    const s = getComputedStyle(el);
    if (el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1 && !/^(svg|path|tr|td|th|html|body|input|select|textarea)$/i.test(el.tagName) && (el.innerText || '').trim()) {
      out.overflow.push({ sel: sel(el), clientWidth: el.clientWidth, scrollWidth: el.scrollWidth, overflowX: s.overflowX, textOverflow: s.textOverflow, text: (el.innerText || '').trim().slice(0, 80) });
    }
  }
  out.overflow = out.overflow.slice(0, 30);

  const inter = Array.from(root.querySelectorAll('button,a[href],input:not([type=hidden]):not([type=radio]):not([type=file]),select,[role=button]')).filter(vis);
  const hit = (el) => { const r = el.getBoundingClientRect(); const x = Math.min(Math.max(r.left + r.width / 2, 0), vw - 1), y = Math.min(Math.max(r.top + r.height / 2, 0), vh - 1); const t = document.elementFromPoint(x, y); return !!t && (t === el || el.contains(t) || t.contains(el)); };
  const live = inter.filter(hit).slice(0, 220);
  for (const el of live) {
    const r = el.getBoundingClientRect();
    if (r.right > vw + 1 || r.left < -1) out.offscreen.push({ sel: sel(el), text: (el.innerText || el.value || '').trim().slice(0, 40), rect: [Math.round(r.left), Math.round(r.top), Math.round(r.right), Math.round(r.bottom)] });
    if ((r.width < 32 || r.height < 32) && out.smallTargets.length < 20) out.smallTargets.push({ sel: sel(el), text: (el.innerText || el.getAttribute('aria-label') || '').trim().slice(0, 30), w: Math.round(r.width), h: Math.round(r.height) });
  }
  for (let i = 0; i < live.length; i++) for (let j = i + 1; j < live.length; j++) {
    const a = live[i], b = live[j]; if (a.contains(b) || b.contains(a)) continue;
    const ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect();
    const w = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left), h = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
    if (w > 4 && h > 4 && (w * h) / Math.min(ra.width * ra.height, rb.width * rb.height) > 0.2 && out.overlaps.length < 20) out.overlaps.push({ a: sel(a) + ' « ' + (a.innerText || '').trim().slice(0, 25) + ' »', b: sel(b) + ' « ' + (b.innerText || '').trim().slice(0, 25) + ' »', w: Math.round(w), h: Math.round(h) });
  }

  const parse = (c) => { const k = c.match(/rgba?\(([^)]+)\)/); if (!k) return null; const p = k[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const bgOf = (el) => { let bg = { r: 255, g: 255, b: 255, a: 1 }, unknown = false; const chain = []; for (let e = el; e; e = e.parentElement) chain.push(e); for (const e of chain) { const s = getComputedStyle(e); if (s.backgroundImage !== 'none') unknown = true; const c = parse(s.backgroundColor); if (c && c.a > 0) { if (c.a >= 0.98) { return { c, unknown }; } bg = { r: c.r * c.a + bg.r * (1 - c.a), g: c.g * c.a + bg.g * (1 - c.a), b: c.b * c.a + bg.b * (1 - c.a), a: 1 }; } } return { c: bg, unknown }; };
  const seen = new Set();
  for (const el of all) {
    if (out.contrast.length >= 30) break;
    if (!vis(el)) continue;
    const own = Array.from(el.childNodes).filter((n) => n.nodeType === 3 && n.textContent.trim()).map((n) => n.textContent.trim()).join(' ');
    if (!own) continue;
    const s = getComputedStyle(el); const fg = parse(s.color); if (!fg) continue;
    let op = 1; for (let e = el; e; e = e.parentElement) op *= parseFloat(getComputedStyle(e).opacity || '1');
    const { c: bg, unknown } = bgOf(el);
    const a = fg.a * op; const fc = { r: fg.r * a + bg.r * (1 - a), g: fg.g * a + bg.g * (1 - a), b: fg.b * a + bg.b * (1 - a) };
    const L1 = lum(fc), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const px = parseFloat(s.fontSize), bold = parseInt(s.fontWeight, 10) >= 700; const large = px >= 24 || (px >= 18.66 && bold);
    const need = large ? 3 : 4.5;
    const key = own.slice(0, 40) + sel(el); if (seen.has(key)) continue; seen.add(key);
    if (ratio < need) out.contrast.push({ sel: sel(el), text: own.slice(0, 50), ratio: Math.round(ratio * 100) / 100, need, px, fg: s.color, bg: `rgb(${Math.round(bg.r)},${Math.round(bg.g)},${Math.round(bg.b)})`, opacity: op, bgImage: unknown });
  }

  const emptyRe = /\b(Aucun|Aucune|vide|Chargement|Rien à|pas de )/i;
  for (const el of all) { if (!vis(el) || el.children.length > 0) continue; const t = (el.innerText || '').trim(); if (t && t.length < 220 && emptyRe.test(t) && out.empties.length < 10) out.empties.push(t); }
  return out;
}

// ── Un « état » = un sous-dossier avec le quartet + analyse + checks ───────────────────────────────────────────────────
async function etat(page, rec, name, fn, opts = {}) {
  const dir = path.join(OUT, name);
  fs.mkdirSync(dir, { recursive: true });
  const S = {
    name, dir, checks: [], proof: !!opts.proof, analysis: null,
    check(label, ok, detail, sev = 'bug') { S.checks.push({ label, ok: !!ok, sev, detail: detail === undefined ? '' : String(detail).slice(0, 600) }); },
    async snap(tag = '', root) {
      const base = path.join(dir, name + (tag ? '--' + tag : ''));
      await page.screenshot({ path: base + '.png', fullPage: false });
      let dom = '', text = '';
      try { dom = await page.evaluate((sel) => { const el = sel && document.querySelector(sel); return (el || document.documentElement).outerHTML; }, root || null); } catch (_) {}
      try { text = await page.evaluate(() => document.body.innerText); } catch (_) {}
      fs.writeFileSync(base + '.dom.html', dom.slice(0, 600_000));
      fs.writeFileSync(base + '.text.txt', text);
      fs.writeFileSync(base + '.console.json', JSON.stringify(rec.console, null, 2));
      fs.writeFileSync(base + '.network.json', JSON.stringify({ calls: rec.net, requestfailed: rec.failed }, null, 2));
      let an = null;
      try { an = await page.evaluate(analysePage, root || null); } catch (e) { an = { error: String(e) }; }
      fs.writeFileSync(base + '.analysis.json', JSON.stringify(an, null, 2));
      S.analysis = an;
      rec.console = []; rec.net = []; rec.failed = [];
      return an;
    },
  };
  await page.waitForFunction(() => !document.querySelector('.Vue-Toastification__toast'), null, { timeout: 9000 }).catch(() => {}); // pas de toast résiduel d'un état précédent
  try { await fn(S); } catch (e) {
    S.check('exception pendant la capture', false, e.message.split('\n')[0], 'instrument');
    try { await page.screenshot({ path: path.join(dir, name + '--ERREUR.png') }); } catch (_) {}
  }
  fs.writeFileSync(path.join(dir, 'checks.json'), JSON.stringify(S.checks, null, 2));
  if (!S.proof) { G.states = G.states.filter((x) => x.name !== name); G.states.push({ name, checks: S.checks }); }
  flush();
  return S;
}

// ── API (APIRequestContext) ───────────────────────────────────────────────────────────────────────────────────────────
const pub = { 'X-API-KEY': API_KEY, Accept: 'application/json' };
async function withRate(request, fn, label) {
  let r = await fn();
  if (r.status() === 429) {
    G.rateLimitEvents.push({ label, ts: new Date().toISOString(), at: '429 Too Many Attempts' });
    execFileSync('php', ['artisan', 'cache:clear'], { cwd: ROOT, encoding: 'utf8' });
    r = await fn();
  }
  return r;
}
async function adminHeaders(request, email, password) {
  const r = await request.post('/api/auth/login', { headers: pub, data: { email, password } });
  const j = await r.json();
  return { ...pub, Authorization: `Bearer ${j.token}` };
}

async function fabriquerCommande(request, { customerToken, addressId, prepare }) {
  const siteHeaders = { ...pub, Authorization: `Bearer ${customerToken}` };
  const commande = await withRate(request, () => request.post('/api/frontend/order', {
    headers: { ...siteHeaders, 'X-Idempotency-Key': uuid() },
    data: {
      branch_id: 1, order_type: 5, source: 5, is_advance_order: 10, payment_method: 1,
      address_id: addressId, delivery_time: 'ASAP', delivery_distance_km: 0, delivery_charge: 0,
      items: JSON.stringify([{ item_id: 58, item_price: 1, quantity: 1, discount: 0, total_price: 1, item_variations: [], item_extras: [], instruction: '' }]),
    },
  }), 'POST /api/frontend/order');
  const txt = await commande.text();
  if (commande.status() >= 300) throw new Error('commande web ' + commande.status() + ' ' + txt.slice(0, 300));
  const orderId = JSON.parse(txt).data.id;
  const row = JSON.parse(php(`$o = \\App\\Models\\Order::withoutGlobalScopes()->find(${orderId}); $s = \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', ${orderId})->first(); echo json_encode(['charge' => (float) $o->delivery_charge, 'total' => (float) $o->total, 'tier' => $o->delivery_tier, 'serial' => $o->order_serial_no, 'src' => $o->source_surface, 'stop' => (bool) $s, 'wave' => (string) $s?->wave_at, 'collect' => $s?->collect_method, 'due' => $s?->amount_due_cents]);`));
  const admin = await adminHeaders(request, 'admin@lecayenne.fr', '123456');
  for (const status of prepare) {
    const r = await request.post(`/api/admin/online-order/change-status/${orderId}`, { headers: { ...admin, 'X-Idempotency-Key': uuid() }, data: { status } });
    if (r.status() >= 300) throw new Error(`statut ${status} -> ${r.status()} ${(await r.text()).slice(0, 200)}`);
  }
  return { orderId, ...row };
}

// ── Caisse : helpers de navigation ─────────────────────────────────────────────────────────────────────────────────────
const toggleLoc = (page) => page.locator('[data-testid="pos-delivery-toggle"]');
async function ouvrirCaisse(page) {
  await loginAsPosOperator(page);
  await page.goto('/admin/pos');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2500);
  await expect(toggleLoc(page)).toBeVisible({ timeout: 15000 });
}
const etatToggle = async (page) => (/suspendue/.test(await toggleLoc(page).innerText()) ? 'suspendue' : 'active');
async function basculer(page) {
  await toggleLoc(page).click();
  await page.waitForTimeout(250);
  await toggleLoc(page).click();
  await page.waitForTimeout(900);
}
async function ajouterEauPlate(page) {
  await page.locator('button:visible', { hasText: /^\s*Boissons\s*$/ }).first().click({ timeout: 15000 });
  await page.locator('button[data-pos-item-id="58"]').click({ timeout: 15000 });
  await page.waitForTimeout(1000);
}
const boutonCommande = (page) => page.locator('button:visible', { hasText: /Commande\s*·/ }).first();

// Oracle INDÉPENDANT du code produit : barème de docs/LIVRAISON.md §1 appliqué à la distance haversine calculée ici.
function haversine(a, b) { const R = 6371.0088, rad = (d) => (d * Math.PI) / 180; const dLat = rad(b.lat - a.lat), dLon = rad(b.lon - a.lon); const x = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLon / 2) ** 2; return 2 * R * Math.asin(Math.sqrt(x)); }
const RESTO = { lat: 50.4215667, lon: 2.9549060 }; // lu dans branches (tinker, round-1)
function oracleFee(sug) {
  const d = haversine(RESTO, { lat: sug.latitude, lon: sug.longitude });
  const hb = /h[ée]nin-beaumont/i.test(sug.city || '') && /^62110$/.test(sug.postal_code || '');
  if (hb) return { fee: 3.9, km: d };
  if (d <= 5) return { fee: 4.9, km: d }; if (d <= 8) return { fee: 5.9, km: d }; if (d <= 10) return { fee: 6.9, km: d };
  return { fee: null, km: d };
}
const fmt = (f) => f.toFixed(2).replace('.', ',');

/** Tape une adresse, capture la liste, choisit une suggestion, capture le devis. Renvoie {texte, quote, sug}. */
async function devisAdresse(S, page, rec, requete, { pick, attenduMission, tag } = {}) {
  const champ = page.locator('#pos-delivery-address');
  await champ.scrollIntoViewIfNeeded();
  await champ.fill('');
  await champ.fill(requete);
  const premier = page.locator('#orderdelivery ul li').first();
  await expect(premier).toBeVisible({ timeout: 15000 });
  const items = await page.locator('#orderdelivery ul li').allInnerTexts();
  await S.snap((tag || 'a') + '-suggestions');
  const li = pick ? page.locator('#orderdelivery ul li', { hasText: pick }).first() : premier;
  const chosen = (await li.innerText()).trim();
  await li.dispatchEvent('mousedown');
  const quoteBox = page.locator('[data-testid="pos-delivery-quote"]');
  await page.waitForTimeout(1500);
  const hasQuote = await quoteBox.isVisible().catch(() => false);
  const texte = hasQuote ? (await quoteBox.innerText()).trim() : '';
  if (hasQuote) await quoteBox.scrollIntoViewIfNeeded();
  await S.snap((tag || 'a') + '-devis');
  const suggest = rec.lastBody(/pos-delivery\/suggest/);
  const sug = suggest && suggest.data ? suggest.data.find((x) => x.label === chosen) : null;
  const quote = rec.lastBody(/pos-delivery\/quote/);
  return { items, chosen, texte, hasQuote, sug, quote };
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════

/** [après relecture adverse] Le devis doit être ENTIÈREMENT visible dans l'en-tête défilant du ticket, pas rogné sous son bord. */
async function verifieDevisVisible(S, page) {
  await page.waitForTimeout(500);
  const vis = await page.evaluate(() => {
    const q = document.querySelector('[data-testid="pos-delivery-quote"]'); const h = document.querySelector('aside.pos-v5-cart > .pos-v5-cart__head');
    if (!q || !h) return null; const a = q.getBoundingClientRect(); const b = h.getBoundingClientRect();
    return { ratio: Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top)) / a.height, quote: [Math.round(a.top), Math.round(a.bottom)], head: [Math.round(b.top), Math.round(b.bottom)] };
  });
  S.check('le devis est ENTIÈREMENT visible dans l\'en-tête du ticket (pas rogné sous son bord) — mesure ajoutée après relecture adverse', vis && vis.ratio >= 0.98, JSON.stringify(vis));
}

test.describe('livraison — captures superviseur', () => {
  let customerToken, addressId, adresseLabel;

  test.beforeAll(async ({ playwright }) => {
    test.setTimeout(240_000);
    const request = await playwright.request.newContext({ baseURL: BASE });
    // Un test qui plante relance un worker : on reprend l'état du run (sans écraser le toggle initial ni refabriquer).
    if (fs.existsSync(path.join(OUT, '_summary.json'))) {
      try {
        const prev = JSON.parse(fs.readFileSync(path.join(OUT, '_summary.json'), 'utf8'));
        if (prev.customerToken) { Object.assign(G, prev); customerToken = prev.customerToken; addressId = prev.orders && prev.orders.addressId; adresseLabel = prev.orders && prev.orders.adresseLabel; return; }
      } catch (_) { /* run neuf */ }
    }
    G.startedAt = new Date().toISOString();
    G.base = BASE;
    G.resto = JSON.parse(php(`echo json_encode(\\App\\Models\\Branch::find(1)->only(['latitude','longitude','address']));`));
    // état initial du toggle (restauré en fin de run)
    const pos = await adminHeaders(request, 'pos@lecayenne.fr', '123456');
    G.initialToggle = (await (await request.get('/api/admin/pos-delivery/status', { headers: pos })).json()).enabled;
    // client web de test + livreur de test + nettoyage des arrêts non partis des clients TEST-LIVRAISON (comme livraison-parcours-complet)
    const stamp = Date.now();
    const phone = '06' + String(stamp).slice(-8);
    customerToken = php(`
      $u = \\App\\Models\\User::create(['name' => 'TEST-LIVRAISON Captures ${stamp}', 'email' => 'test-livraison-cap-${stamp}@example.test', 'phone' => '${phone}', 'country_code' => '+33', 'username' => 'tlc${stamp}', 'password' => bcrypt('Test-Livraison-2026'), 'branch_id' => 0, 'status' => 5, 'is_guest' => 10, 'email_verified_at' => now()]);
      $u->assignRole('Customer');
      echo $u->createToken('auth_token', ['*'])->plainTextToken;`);
    expect(customerToken).toMatch(/^\d+\|/);
    G.customerToken = customerToken;
    G.customer ={ stamp, phone, name: 'TEST-LIVRAISON Captures ' + stamp };
    php(`
      \\Spatie\\Permission\\Models\\Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
      $c = \\App\\Models\\User::withoutGlobalScopes()->where('email', '${COURIER.email}')->first()
        ?? \\App\\Models\\User::create(['name' => '${COURIER.name}', 'email' => '${COURIER.email}', 'phone' => '0600000001', 'country_code' => '+33', 'username' => 'tlkiki', 'password' => bcrypt('${COURIER.password}'), 'branch_id' => 1, 'status' => 5, 'is_guest' => 10]);
      $c->syncRoles([\\Spatie\\Permission\\Models\\Role::where('name', 'Delivery Boy')->where('guard_name', 'sanctum')->first()]);
      $c->forceFill(['password' => bcrypt('${COURIER.password}'), 'status' => 5])->save();
      \\App\\Models\\DeliveryStop::withoutGlobalScopes()->whereNull('left_at')->whereIn('order_id', \\App\\Models\\Order::withoutGlobalScopes()->whereIn('user_id', \\App\\Models\\User::withoutGlobalScopes()->where('name', 'like', 'TEST-LIVRAISON%')->select('id'))->select('id'))->delete();
      echo 'ok';`);
    flush();
  });

  test.afterAll(async ({ playwright }) => {
    const request = await playwright.request.newContext({ baseURL: BASE });
    try {
      const pos = await adminHeaders(request, 'pos@lecayenne.fr', '123456');
      const cur = (await (await request.get('/api/admin/pos-delivery/status', { headers: pos })).json()).enabled;
      if (G.initialToggle !== null && cur !== G.initialToggle) {
        await request.post('/api/admin/pos-delivery/toggle', { headers: pos, data: { enabled: G.initialToggle } });
        G.notes.push('toggle livraison restauré à ' + G.initialToggle);
      }
      G.finalToggle = (await (await request.get('/api/admin/pos-delivery/status', { headers: pos })).json()).enabled;
    } catch (e) { G.notes.push('restauration toggle échouée: ' + e.message); }
    G.finishedAt = new Date().toISOString();
    flush();
  });

  // ── 0. Fabrication de deux commandes de livraison réelles (comme le site) ──────────────────────────────────────────
  test('00 fabrication : adresse + 2 commandes web (A prête, B acceptée seulement)', async ({ request }) => {
    test.setTimeout(240_000);
    const devis = await (await withRate(request, () => request.post('/api/frontend/delivery/quote', { headers: pub, data: { q: '437 rue elie gruyelle 62110 henin-beaumont' } }), 'POST /api/frontend/delivery/quote')).json();
    expect(devis.allowed).toBe(true);
    adresseLabel = devis.address.label;
    const adresse = await (await request.post('/api/frontend/address', { headers: { ...pub, Authorization: `Bearer ${customerToken}` }, data: { label: 'Captures ' + Date.now().toString(36), address: adresseLabel, apartment: '2B' } })).json();
    addressId = adresse.data.id;
    const A = await fabriquerCommande(request, { customerToken, addressId, prepare: [4, 7, 8] });
    const B = await fabriquerCommande(request, { customerToken, addressId, prepare: [4] });
    G.orders = { A, B, addressId, adresseLabel, devisWeb: { fee: devis.fee, tier: devis.tier, distance_km: devis.distance_km, waves: devis.waves.length } };
    G.notes.push(`A et B dans la même tournée: ${A.wave === B.wave} (${A.wave} / ${B.wave})`);
    flush();
    expect(A.charge).toBe(3.9);
  });

  // ── 1 à 4 : caisse, deux viewports ───────────────────────────────────────────────────────────────────────────────────
  for (const [w, h] of [[1440, 900], [1280, 720]]) {
    const vp = `${w}x${h}`;
    test(`01-04 caisse ${vp} : toggle, adresses, hors zone, adresses refusées`, async ({ page, request }) => {
      test.setTimeout(420_000);
      const rec = record(page);
      await page.setViewportSize({ width: w, height: h });
      await ouvrirCaisse(page);
      const pos = await adminHeaders(request, 'pos@lecayenne.fr', '123456');
      const statusServeur = async () => (await (await request.get('/api/admin/pos-delivery/status', { headers: pos })).json()).enabled;

      // 1. suspendue
      await etat(page, rec, `01a-livraison-suspendue-${vp}`, async (S) => {
        if ((await etatToggle(page)) === 'active') await basculer(page);
        await page.waitForTimeout(1200);
        await S.snap('', '#app');
        const t = await toggleLoc(page).innerText();
        S.check('le bouton annonce « suspendue »', /suspendue/.test(t), t);
        S.check('l\'option Livraison du sélecteur de type disparaît', (await page.locator('label[for="delivery"]').count()) === 0, 'count=' + (await page.locator('label[for="delivery"]').count()));
        S.check('le bouton Tournées disparaît', (await page.locator('[data-testid="pos-delivery-board-open"]').count()) === 0);
        S.check('le serveur confirme enabled=false', (await statusServeur()) === false, 'GET /api/admin/pos-delivery/status');
      });
      // 1. active
      await etat(page, rec, `01b-livraison-active-${vp}`, async (S) => {
        await basculer(page);
        await page.waitForTimeout(500);
        await S.snap('', '#app');
        const t = await toggleLoc(page).innerText();
        S.check('le bouton annonce « active »', /active/.test(t), t);
        S.check('l\'option Livraison apparaît', await page.locator('label[for="delivery"]').isVisible());
        S.check('le bouton Tournées apparaît', await page.locator('[data-testid="pos-delivery-board-open"]').isVisible());
        S.check('le serveur confirme enabled=true', (await statusServeur()) === true);
        S.check('toast de confirmation lisible', (S.analysis.toasts || []).some((x) => /Livraison réactivée/.test(x)), JSON.stringify(S.analysis.toasts), 'ux');
      });

      // panier + mode livraison
      await ajouterEauPlate(page);
      await page.locator('label[for="delivery"]').click();
      await page.locator('#pos-delivery-phone').fill('0600000042');
      await page.locator('#pos-delivery-name').fill('TEST-LIVRAISON Captures');
      await page.waitForTimeout(1200);

      const communes = [
        ['02-henin-beaumont', '437 rue elie gruyelle henin', /Gruyelle/i, '3,90'],
        ['02-oignies', '1 rue Jean Jaurès 62590 Oignies', /Oignies/i, '5,90'],
        ['02-courrieres', '1 rue Jean Jaurès 62710 Courrières', /Courri/i, '4,90'],
        ['02-lens', '1 rue de Paris 62300 Lens', /Lens/i, '6,90'],
      ];
      for (const [nom, requete, pick, attenduMission] of communes) {
        await etat(page, rec, `${nom}-${vp}`, async (S) => {
          const r = await devisAdresse(S, page, rec, requete, { pick, tag: 'a' });
          S.check('liste de suggestions visible', r.items.length >= 1, r.items.join(' | '));
          S.check('un devis est affiché', r.hasQuote, r.texte);
          const sug = r.sug;
          if (sug) {
            const o = oracleFee(sug);
            const shown = (r.texte.match(/(\d+,\d{2})\s*€/) || [])[1];
            S.check(`prix affiché = barème docs/LIVRAISON.md (oracle haversine ${o.km.toFixed(2)} km)`, o.fee !== null && shown === fmt(o.fee), `affiché=${shown} oracle=${o.fee === null ? 'refus' : fmt(o.fee)} km_oracle=${o.km.toFixed(2)} km_serveur=${r.quote && r.quote.distance_km}`);
            S.check(`prix attendu par la mission (${attenduMission}) — informatif`, shown === attenduMission, `affiché=${shown}`, 'info');
          } else S.check('coordonnées de la suggestion retrouvées dans /suggest', false, 'sug introuvable pour ' + r.chosen, 'instrument');
          const btn = await boutonCommande(page).innerText().catch(() => '');
          S.check('le total du bouton Commande inclut les frais', new RegExp(fmt(1 + (r.quote && r.quote.fee || 0))).test(btn), btn);
          S.check('aucun texte brut (clé i18n / undefined / NaN)', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
          S.check('pas de défilement horizontal de page', !S.analysis.pageHScroll);
          await verifieDevisVisible(S, page);
          S.check('le champ complément « Étage, interphone, code » est atteignable', (await page.locator('#pos-delivery-apartment').count()) > 0, 'occurrences DOM=' + (await page.locator('#pos-delivery-apartment').count()), 'ux');
        });
      }

      // 3. hors zone
      await etat(page, rec, `03-hors-zone-lille-${vp}`, async (S) => {
        const r = await devisAdresse(S, page, rec, '1 rue de Béthune 59800 Lille', { pick: /Lille/i, tag: 'a' });
        S.check('le devis affiche un refus + Uber Eats', /Uber Eats/i.test(r.texte), r.texte);
        S.check('la distance serveur est > 10 km', r.quote && r.quote.distance_km > 10 && r.quote.allowed === false, JSON.stringify(r.quote && { d: r.quote.distance_km, allowed: r.quote.allowed, tier: r.quote.tier }));
        const btn0 = await boutonCommande(page).innerText().catch(() => '');
        S.check('aucun frais de livraison dans le total (Commande · 1,00 €)', /1,00/.test(btn0), btn0);
        const avant = rec.count('POST', /^\/api\/admin\/pos(\?.*)?$/);
        const quoteBox = page.locator('[data-testid="pos-delivery-quote"]');
        const bouton = boutonCommande(page);
        const attrs = await bouton.evaluate((b) => ({ disabled: b.disabled, ariaDisabled: b.getAttribute('aria-disabled'), cls: b.className.slice(0, 120) }));
        await bouton.scrollIntoViewIfNeeded();
        await bouton.click();
        await page.waitForTimeout(1200);
        await quoteBox.scrollIntoViewIfNeeded().catch(() => {});
        await S.snap('b-apres-clic-commande');
        S.check('le clic sur Commande ne crée AUCUNE commande (pas de POST /api/admin/pos)', rec.count('POST', /^\/api\/admin\/pos(\?.*)?$/) === avant && !G.states.some(() => false), `bouton=${JSON.stringify(attrs)}`);
        S.check('le choix du moyen de paiement n\'est pas atteint (pas de bouton Espèces)', (await page.locator('button:visible', { hasText: /Espèces/ }).count()) === 0);
        const toasts = (S.analysis && S.analysis.toasts) || [];
        const dansForm = await page.locator('#orderdelivery [role="status"], #orderdelivery [role="alert"]').allInnerTexts();
        S.check('le bloc devis (dans le formulaire) explique le refus : hors zone / Uber Eats', dansForm.some((t) => /Uber|10 km/i.test(t)), JSON.stringify(dansForm), 'ux');
        S.check('le TOAST du clic sur Commande explique le refus (hors zone / Uber Eats / 10 km), pas un message générique', toasts.some((t) => /zone|Uber|10 km|livrable|impossible/i.test(t)), 'toasts=' + JSON.stringify(toasts), 'ux');
      });

      // 4. commune seule + illisible
      await etat(page, rec, `04a-commune-seule-henin-beaumont-${vp}`, async (S) => {
        const champ = page.locator('#pos-delivery-address');
        await champ.scrollIntoViewIfNeeded(); await champ.fill(''); await champ.fill('Hénin-Beaumont');
        await expect(page.locator('#orderdelivery ul li').first()).toBeVisible({ timeout: 15000 });
        const items = await page.locator('#orderdelivery ul li').allInnerTexts();
        await S.snap('a-suggestions');
        S.check('la commune seule est PROPOSÉE comme suggestion cliquable (info)', items.some((t) => /^\s*H[ée]nin-Beaumont\s*$/i.test(t)), items.join(' | '), 'info');
        const li = page.locator('#orderdelivery ul li', { hasText: /^\s*H[ée]nin-Beaumont\s*$/i }).first();
        if (await li.count()) await li.dispatchEvent('mousedown'); else await page.locator('#orderdelivery ul li').first().dispatchEvent('mousedown');
        await page.waitForTimeout(1800);
        const alerte = page.locator('#orderdelivery [role="alert"]').first();
        if (await alerte.count()) await alerte.scrollIntoViewIfNeeded();
        await S.snap('b-apres-choix');
        const al = (await alerte.count()) ? (await alerte.innerText()).trim() : '';
        S.check('la commune seule est refusée avec un message lisible', al.length > 5 && !/undefined|GEOCODE_FAILED|Label\./.test(al), `alerte="${al}"`);
        S.check('aucun devis facturé pour la commune seule', !(await page.locator('[data-testid="pos-delivery-quote"]').isVisible().catch(() => false)));
        const avant = rec.count('POST', /^\/api\/admin\/pos(\?.*)?$/);
        await boutonCommande(page).click(); await page.waitForTimeout(1000);
        await S.snap('c-apres-clic-commande');
        S.check('Commande ne crée aucune commande', rec.count('POST', /^\/api\/admin\/pos(\?.*)?$/) === avant);
      });
      await etat(page, rec, `04b-texte-illisible-zzzzqqq-${vp}`, async (S) => {
        const champ = page.locator('#pos-delivery-address');
        await champ.scrollIntoViewIfNeeded(); await champ.fill(''); await champ.fill('zzzzqqq');
        await page.waitForTimeout(2500);
        await S.snap('a-saisie');
        const zone = (await page.locator('#orderdelivery').innerText()).trim();
        // Périmètre STRICT : le formulaire de livraison uniquement (une bannière « Article indisponible » ailleurs sur l'écran ne compte pas).
        const dansForm = await page.locator('#orderdelivery [role="status"], #orderdelivery [role="alert"]').allInnerTexts();
        const toastsAvant = await page.locator('.Vue-Toastification__toast').allInnerTexts();
        S.check('un message lisible DANS le formulaire explique « aucune adresse trouvée » (pas de silence)', dansForm.length > 0 || toastsAvant.length > 0, `status/alert du formulaire=${JSON.stringify(dansForm)} toasts=${JSON.stringify(toastsAvant)} texte du formulaire="${zone.replace(/\n/g, ' | ').slice(0, 300)}"`, 'ux');
        const avant = rec.count('POST', /^\/api\/admin\/pos(\?.*)?$/);
        await boutonCommande(page).click(); await page.waitForTimeout(1000);
        await S.snap('b-apres-clic-commande');
        S.check('Commande ne crée aucune commande', rec.count('POST', /^\/api\/admin\/pos(\?.*)?$/) === avant);
        S.check('un message lisible s\'affiche au clic sur Commande', (S.analysis.toasts || []).some((t) => t.length > 8 && !/undefined|Label\./.test(t)), JSON.stringify(S.analysis.toasts), 'ux');
        S.check('le message du clic dit ce qui ne va pas avec l\'adresse tapée', (S.analysis.toasts || []).some((t) => /adresse/i.test(t)), JSON.stringify(S.analysis.toasts), 'ux');
      });
    });
  }

  // ── 2d. une VRAIE commande de caisse livraison, validée (écrit en base) ─────────────────────────────────────────────
  test('02d caisse 1440x900 : commande livraison validée par la caisse (espèces) — écrit en base', async ({ page }) => {
    test.setTimeout(240_000);
    const rec = record(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await ouvrirCaisse(page);
    if ((await etatToggle(page)) === 'suspendue') await basculer(page);
    await ajouterEauPlate(page);
    await page.locator('label[for="delivery"]').click();
    const phone = '06' + String(Date.now()).slice(-8);
    G.posPhone = phone;
    await page.locator('#pos-delivery-phone').fill(phone);
    await page.locator('#pos-delivery-name').fill('TEST-LIVRAISON Captures POS');
    await page.waitForTimeout(800);
    await etat(page, rec, '02d-commande-pos-livraison-validee-1440x900', async (S) => {
      const r = await devisAdresse(S, page, rec, '437 rue elie gruyelle henin', { pick: /Gruyelle/i, tag: 'a' });
      S.check('devis Hénin-Beaumont 3,90 €', /3,90/.test(r.texte), r.texte);
      const apt = await page.locator('#pos-delivery-apartment').count();
      S.check('le champ « Étage, interphone, code » est accessible après le choix de l\'adresse', apt > 0, 'occurrences de #pos-delivery-apartment dans le DOM = ' + apt + ' (PosComponent.vue:1559 exige quote.allowed && !confirmed ; :7502 pose confirmed=true dans la foulée)');
      if (apt > 0) await page.locator('#pos-delivery-apartment').fill('Étage 2 - code 1234');
      await boutonCommande(page).click({ timeout: 15000 });
      await page.waitForTimeout(1800);
      await S.snap('b-ecran-paiement');
      await page.locator('button:visible', { hasText: /Espèces/ }).first().click({ timeout: 15000 });
      await page.locator('button:visible', { hasText: /^5$/ }).first().click();
      await page.waitForTimeout(500);
      await S.snap('c-montant-saisi');
      await page.locator('button:visible', { hasText: /Confirmer/ }).first().click({ timeout: 15000 });
      await expect.poll(() => rec.count('POST', /^\/api\/admin\/pos(\?.*)?$/), { timeout: 25000 }).toBeGreaterThan(0);
      await page.waitForTimeout(2000);
      const post = rec.all.find((n) => n.method === 'POST' && /^\/api\/admin\/pos(\?.*)?$/.test(n.url));
      S.check('POST /api/admin/pos → 201', post && post.status === 201, post && post.status);
      const mid = post && post.body && post.body.match(/"data":\{"id":(\d+)/); G.posOrder = mid ? { id: Number(mid[1]) } : { raw: post && post.body && post.body.slice(0, 300) };
      await S.snap('d-apres-confirmation');
      S.check('aucun texte brut après validation', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
    });
    flush();
  });

  // ── 5. Tournées ───────────────────────────────────────────────────────────────────────────────────────────────────────
  for (const [w, h] of [[1440, 900], [1280, 720]]) {
    const vp = `${w}x${h}`;
    test(`05 caisse ${vp} : tableau des tournées + choix du livreur`, async ({ page, request }) => {
      test.setTimeout(240_000);
      const rec = record(page);
      await page.setViewportSize({ width: w, height: h });
      await ouvrirCaisse(page);
      if ((await etatToggle(page)) === 'suspendue') await basculer(page);
      const serial = G.orders.A.serial;
      // [après relecture adverse] Fuite d'état entre viewports : le tour 1440 désigne le livreur, le tour 1280 le retrouvait déjà
      // désigné et « Aucun livreur avant choix » ne pouvait plus se vérifier. On remet les arrêts non partis de CET audit à zéro.
      php(`\\App\\Models\\DeliveryStop::withoutGlobalScopes()->whereNull('left_at')->whereIn('order_id', [${[G.orders.A.orderId, G.orders.B.orderId].filter(Boolean).join(',')}])->update(['delivery_boy_id' => null]); \\App\\Models\\Order::withoutGlobalScopes()->whereIn('id', [${[G.orders.A.orderId, G.orders.B.orderId].filter(Boolean).join(',')}])->update(['delivery_boy_id' => null]); echo 'ok';`);
      await etat(page, rec, `05a-tournees-avant-choix-livreur-${vp}`, async (S) => {
        await page.locator('[data-testid="pos-delivery-board-open"]').click();
        const board = page.locator('[data-testid="pos-delivery-board"]');
        await expect(board).toContainText(serial, { timeout: 15000 });
        await page.waitForTimeout(600);
        await board.locator('section', { hasText: serial }).first().evaluate((el) => el.scrollIntoView({ block: 'center' })); // la tournée de la commande A est sous des tournées anciennes
        await S.snap('', '[data-testid="pos-delivery-board"]');
        const texte = await board.innerText();
        S.check('la commande A figure dans une tournée', texte.includes(serial));
        S.check('la commande B figure dans une tournée', texte.includes(G.orders.B.serial));
        S.check('montant à encaisser lisible (Espèces 4,90 €)', /Espèces 4,90 €/.test(texte), texte.replace(/\n/g, ' | ').slice(0, 400));
        const vague = board.locator('section', { hasText: serial }).first();
        const opts = await vague.locator('select option').allInnerTexts();
        G.boardOptions = opts;
        S.check('un sélecteur de livreur est proposé', (await vague.locator('select').count()) === 1, 'options=' + opts.join(' | '));
        S.check('« Aucun livreur » affiché avant choix', /Aucun livreur/.test(texte));
        S.check('la boîte tient dans la fenêtre', S.analysis.pageHScroll === false);
      });
      await etat(page, rec, `05b-tournees-apres-choix-livreur-${vp}`, async (S) => {
        const board = page.locator('[data-testid="pos-delivery-board"]');
        const vague = board.locator('section', { hasText: serial }).first();
        await vague.locator('select').selectOption({ label: COURIER.name });
        await expect(vague).toContainText(`Livreur : ${COURIER.name}`, { timeout: 15000 });
        await page.waitForTimeout(900);
        await vague.evaluate((el) => el.scrollIntoView({ block: 'center' }));
        await S.snap('', '[data-testid="pos-delivery-board"]');
        const assign = rec.all.find((n) => n.method === 'POST' && /waves\/assign/.test(n.url));
        S.check('POST waves/assign → 200', assign && assign.status === 200, assign && `${assign.status} ${assign.request}`);
        S.check('le livreur choisi est affiché', /Livreur : TEST-LIVRAISON Kiki/.test(await board.innerText()));
        S.check('aucun texte brut', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
      });
    });
  }

  // ── 6. Livreur ────────────────────────────────────────────────────────────────────────────────────────────────────────
  const jpeg = async (page) => Buffer.from(await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 640; c.height = 480;
    const g = c.getContext('2d'); g.fillStyle = '#F4501E'; g.fillRect(0, 0, 640, 480); g.fillStyle = '#fff'; g.font = '48px sans-serif'; g.fillText('Porte 2B', 200, 250);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
    return Array.from(new Uint8Array(await blob.arrayBuffer()));
  }));

  async function parcoursLivreur(browser, { vp, mobile, serial, wave, tag, prefix }) {
    const ctx = await browser.newContext({ baseURL: BASE, viewport: vp, isMobile: mobile, hasTouch: mobile, locale: 'fr-FR', timezoneId: 'Europe/Paris', deviceScaleFactor: mobile ? 2 : 1 });
    const page = await ctx.newPage();
    const rec = record(page);
    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push({ type: d.type(), message: d.message() }); await d.accept(); });
    const vpName = `${vp.width}x${vp.height}`;
    const N = (s) => `${prefix}${s}-${tag}-${vpName}`;
    await page.goto('/livreur');
    await page.waitForLoadState('networkidle');

    await etat(page, rec, N('06a-livreur-connexion'), async (S) => {
      await S.snap('');
      S.check('formulaire de connexion visible (e-mail + mot de passe + bouton)', (await page.locator('#email').isVisible()) && (await page.locator('#password').isVisible()) && (await page.locator('#login-btn').isVisible()));
      S.check('aucun champ « téléphone » : la connexion se fait par e-mail (info)', (await page.locator('input[type=tel]').count()) === 0, 'docs/LIVRAISON.md §3 dit « page /livreur (téléphone) » = écran pensé pour téléphone', 'info');
      S.check('aucun texte brut', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
      const box = await page.locator('#login-btn').boundingBox();
      S.check('bouton de connexion ≥ 44 px de haut', box.height >= 44, box.height);
    });
    await etat(page, rec, N('06b-livreur-connexion-erreur'), async (S) => {
      await page.locator('#email').fill(COURIER.email);
      await page.locator('#password').fill('mauvais-mot-de-passe');
      await page.locator('#login-btn').click();
      await expect(page.locator('#login-err')).not.toHaveText('', { timeout: 15000 });
      await S.snap('');
      const msg = (await page.locator('#login-err').innerText()).trim();
      G.livreurLoginError = msg;
      S.check('message d\'erreur de connexion lisible en français', msg.length > 5 && !/undefined|Label\.|^Erreur \d+$/.test(msg), `"${msg}"`);
    });
    await page.locator('#password').fill(COURIER.password);
    await page.locator('#login-btn').click();
    const arret = page.locator('.stop', { hasText: serial });
    await expect(arret).toBeVisible({ timeout: 20000 });
    await etat(page, rec, N('06c-livreur-liste-avant-depart'), async (S) => {
      await page.waitForTimeout(600);
      await page.locator(`button[data-depart="${wave}"]`).first().evaluate((b) => { b.scrollIntoView({ block: 'start' }); window.scrollBy(0, -100); }); // l'en-tête sticky (≈70 px) masquerait le bouton
      await S.snap('a-bouton-depart');
      await arret.evaluate((el) => el.scrollIntoView({ block: 'center' }));
      await S.snap('b-arret');
      const t = await arret.innerText();
      const depart = await page.locator(`button[data-depart="${wave}"]`).first().innerText();
      S.check('adresse affichée', /437 Rue Elie Gruyelle/i.test(t), t.replace(/\n/g, ' | ').slice(0, 300));
      S.check('montant à encaisser affiché (Encaisser en espèces : 4,90 €)', /Encaisser en espèces : 4,90 €/.test(t));
      S.check('lien Waze valide', /^https:\/\/waze\.com\/ul\?ll=/.test((await arret.locator('a:has-text("Waze")').getAttribute('href')) || ''));
      S.check('bouton Départ présent', /Je pars/.test(depart), depart);
      S.check('aucun texte brut', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
      S.check('pas de débordement horizontal de la page', !S.analysis.pageHScroll);
      if (mobile) S.check('cibles tactiles ≥ 44 px (boutons .btn)', (await page.locator('.btn:visible').evaluateAll((els) => els.filter((e) => e.getBoundingClientRect().height < 44).length)) === 0);
    });
    await etat(page, rec, N('06d-livreur-apres-depart'), async (S) => {
      await page.locator(`button[data-depart="${wave}"]`).first().click();
      await expect(arret).toContainText('En route', { timeout: 15000 });
      await page.waitForTimeout(600);
      await arret.evaluate((el) => el.scrollIntoView({ block: 'center' }));
      await S.snap('');
      const err = (await page.locator('#app-err').innerText()).trim();
      G.livreurAppErr = G.livreurAppErr || {}; G.livreurAppErr[N('06d')] = err;
      S.check('chip « En route · ETA hh:mm » lisible', /En route · ETA \d{2}:\d{2}/.test(await arret.innerText()), (await arret.innerText()).replace(/\n/g, ' | ').slice(0, 200));
      S.check('boutons Photo + Remis visibles', (await arret.locator('label:has-text("Arrivé + photo")').isVisible()) && (await arret.locator('button[data-deliver]').isVisible()));
      const dep = rec.all.find((n) => n.method === 'POST' && /\/depart$/.test(n.url));
      let notReady = []; try { notReady = JSON.parse(dep.body).not_ready || []; } catch (_) {}
      if (notReady.length) {
        // [relecture adverse] Présent dans le DOM ne suffit pas : le message doit être DANS LA FENÊTRE, sous l'en-tête collant.
        const boite = await page.evaluate(() => { const e = document.getElementById('app-err'); const hd = document.querySelector('header'); if (!e || !hd) return null; const a = e.getBoundingClientRect(); return { top: Math.round(a.top), bottom: Math.round(a.bottom), headerBottom: Math.round(hd.getBoundingClientRect().bottom), vh: window.innerHeight, texte: e.textContent.length }; });
        S.check('le message « pas prête » est VISIBLE dans la fenêtre (sous l\'en-tête, pas hors cadre)', boite && boite.texte > 0 && boite.top >= boite.headerBottom - 1 && boite.bottom <= boite.vh, JSON.stringify(boite), 'ux');
      }
      if (notReady.length) S.check('le message « commande(s) pas encore prête(s) » reste lisible après le départ (la réponse contient not_ready)', /pas encore prête/.test(err), `not_ready=${JSON.stringify(notReady)} app-err="${err}"`, 'ux');
      else S.check('réponse depart sans not_ready (info)', true, JSON.stringify(dep && dep.body), 'info');
    });
    await etat(page, rec, N('06e-livreur-arrivee-photo'), async (S) => {
      await arret.locator('input[data-arrive]').setInputFiles({ name: 'porte.jpg', mimeType: 'image/jpeg', buffer: await jpeg(page) });
      await expect(arret).toContainText('Arrivé', { timeout: 20000 });
      await expect(arret).toContainText('Photo prise');
      await page.waitForTimeout(500);
      await arret.evaluate((el) => el.scrollIntoView({ block: 'center' }));
      await S.snap('');
      const arrive = rec.all.find((n) => n.method === 'POST' && /\/arrive$/.test(n.url));
      S.check('POST …/arrive → 200', arrive && arrive.status === 200, arrive && arrive.status);
      S.check('aucun texte brut', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
    });
    await etat(page, rec, N('06f-livreur-remise-encaissement'), async (S) => {
      await arret.locator('button[data-deliver]').click();
      await expect(arret).toContainText('Remise', { timeout: 20000 });
      await page.waitForTimeout(600);
      await arret.evaluate((el) => el.scrollIntoView({ block: 'center' }));
      await S.snap('');
      S.check('confirmation d\'encaissement demandée (confirm natif)', dialogs.some((d) => /encaissé 4,90 €/.test(d.message)), JSON.stringify(dialogs));
      const dl = rec.all.find((n) => n.method === 'POST' && /\/deliver$/.test(n.url));
      S.check('POST …/deliver → 200', dl && dl.status === 200, dl && `${dl.status} ${dl.request}`);
      S.check('le montant encaissé envoyé = 490 centimes', dl && /"collected_cents":490/.test(dl.request || ''), dl && dl.request);
      S.check('chip « Remise hh:mm »', /Remise \d{2}:\d{2}/.test(await arret.innerText()));
      S.check('la carte livrée ne réclame plus « Encaisser en espèces » (déjà encaissé)', !/Encaisser en espèces/.test(await arret.innerText()), (await arret.innerText()).replace(/\n/g, ' | ').slice(0, 250), 'ux');
      S.check('aucun texte brut', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
    });
    G.dialogs = G.dialogs || {}; G.dialogs[N('06')] = dialogs;
    await ctx.close();
  }

  test('06 livreur MOBILE 390x844 — commande A (départ avec B pas prête)', async ({ browser }) => {
    test.setTimeout(300_000);
    await parcoursLivreur(browser, { vp: { width: 390, height: 844 }, mobile: true, serial: G.orders.A.serial, wave: G.orders.A.wave, tag: 'mobile', prefix: '' });
  });

  test('06 livreur DESKTOP 1440x900 — commande B (prête après coup)', async ({ browser, request }) => {
    test.setTimeout(300_000);
    const admin = await adminHeaders(request, 'admin@lecayenne.fr', '123456');
    const stB = Number(php(`echo (int) \\App\\Models\\Order::withoutGlobalScopes()->find(${G.orders.B.orderId})->status;`));
    if (stB !== 8) {
      for (const status of [7, 8]) {
        const r = await request.post(`/api/admin/online-order/change-status/${G.orders.B.orderId}`, { headers: { ...admin, 'X-Idempotency-Key': uuid() }, data: { status } });
        expect(r.status(), await r.text()).toBeLessThan(300);
      }
    }
    await parcoursLivreur(browser, { vp: { width: 1440, height: 900 }, mobile: false, serial: G.orders.B.serial, wave: G.orders.B.wave, tag: 'desktop', prefix: '' });
  });

  // ── 8. Preuve de l'instrument : des défauts INJECTÉS au niveau réseau/DOM doivent faire échouer les checks ──────────────
  test('08 preuve de l\'instrument : les checks échouent quand le défaut est présent', async ({ page }) => {
    test.setTimeout(300_000);
    const rec = record(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await ouvrirCaisse(page);
    if ((await etatToggle(page)) === 'suspendue') await basculer(page);
    await ajouterEauPlate(page);
    await page.locator('label[for="delivery"]').click();
    const verifieHenin = async (S) => {
      const r = await devisAdresse(S, page, rec, '437 rue elie gruyelle henin', { pick: /Gruyelle/i, tag: 'a' });
      S.check('devis Hénin-Beaumont affiche 3,90 €', /3,90/.test(r.texte), r.texte);
      S.check('aucun texte brut', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
      return r;
    };
    const verifieLille = async (S) => {
      const r = await devisAdresse(S, page, rec, '1 rue de Béthune 59800 Lille', { pick: /Lille/i, tag: 'a' });
      S.check('Lille : refus + Uber Eats affichés', /Uber Eats/i.test(r.texte), r.texte);
      return r;
    };
    // témoin (sans défaut)
    const T1 = await etat(page, rec, 'P0-temoin-henin-sans-defaut', verifieHenin, { proof: true });
    const T2 = await etat(page, rec, 'P0-temoin-lille-sans-defaut', verifieLille, { proof: true });
    // défaut 1 : le serveur répondrait 4,90 € au lieu de 3,90 €
    await page.route('**/api/admin/pos-delivery/quote', async (route) => { const resp = await route.fetch(); const j = await resp.json(); j.fee = 4.9; await route.fulfill({ response: resp, json: j }); });
    const M1 = await etat(page, rec, 'P1-defaut-injecte-frais-faux-4_90', verifieHenin, { proof: true });
    await page.unroute('**/api/admin/pos-delivery/quote');
    // défaut 2 : le serveur accepterait Lille (hors zone) à 6,90 €
    await page.route('**/api/admin/pos-delivery/quote', async (route) => { const resp = await route.fetch(); const j = await resp.json(); j.allowed = true; j.fee = 6.9; j.suggest_uber_eats = false; j.tier = 'radius_3'; await route.fulfill({ response: resp, json: j }); });
    const M2 = await etat(page, rec, 'P2-defaut-injecte-hors-zone-accepte', verifieLille, { proof: true });
    await page.unroute('**/api/admin/pos-delivery/quote');
    // défaut 3 : un libellé brut « Label.delivery_fee undefined » s'affiche dans le devis (scanner de textes bruts)
    const M3 = await etat(page, rec, 'P3-defaut-injecte-texte-brut', async (S) => {
      const r = await verifieHenin(S);
      await page.evaluate(() => { const q = document.querySelector('[data-testid="pos-delivery-quote"]'); if (q) q.textContent = 'Label.delivery_fee undefined NaN €'; });
      await S.snap('b-apres-injection');
      S.check('aucun texte brut (après injection)', (S.analysis.suspects || []).length === 0, JSON.stringify(S.analysis.suspects));
    }, { proof: true });
    // défaut 4 : le devis est rogné sous le bord de l'en-tête (le défaut P-6 de la relecture adverse)
    const M4 = await etat(page, rec, 'P4-defaut-injecte-devis-rogne', async (S) => {
      await verifieHenin(S);
      await page.evaluate(() => { const h = document.querySelector('aside.pos-v5-cart > .pos-v5-cart__head'); h.style.scrollBehavior = 'auto'; h.scrollTop = 0; });
      await verifieDevisVisible(S, page);
    }, { proof: true });
    const fails = (S) => S.checks.filter((c) => !c.ok && c.sev !== 'info').map((c) => c.label);
    G.proof = {
      temoin_henin_echecs: fails(T1), temoin_lille_echecs: fails(T2),
      defaut1_frais_faux_echecs: fails(M1), defaut2_hors_zone_accepte_echecs: fails(M2), defaut3_texte_brut_echecs: fails(M3), defaut4_devis_rogne_echecs: fails(M4),
    };
    flush();
    expect(fails(T1), 'témoin Hénin doit passer').toEqual([]);
    expect(fails(T2), 'témoin Lille doit passer').toEqual([]);
    expect(fails(M1).some((l) => /3,90/.test(l)), 'le check 3,90 doit ÉCHOUER avec le défaut injecté').toBe(true);
    expect(fails(M2).some((l) => /Uber Eats/.test(l)), 'le check Uber Eats doit ÉCHOUER avec le défaut injecté').toBe(true);
    expect(fails(M3).some((l) => /texte brut/.test(l)), 'le scanner de textes bruts doit ÉCHOUER avec le défaut injecté').toBe(true);
    expect(fails(M4).some((l) => /ENTIÈREMENT visible/.test(l)), 'la mesure de rognage doit ÉCHOUER quand le devis est rogné').toBe(true);
  });

  // ── 9. Relecture de la base pour chaque flux qui écrit ─────────────────────────────────────────────────────────────────
  test('09 relecture de la base + chaîne fiscale', async () => {
    test.setTimeout(240_000);
    let posId = G.posOrder && G.posOrder.id;
    if (!posId && G.posPhone) posId = Number(php(`echo (int) \\App\\Models\\Order::withoutGlobalScopes()->whereIn('user_id', \\App\\Models\\User::withoutGlobalScopes()->where('phone', '${G.posPhone}')->select('id'))->max('id');`)) || null;
    const ids = [G.orders.A.orderId, G.orders.B.orderId, posId].filter(Boolean);
    G.posOrderId = posId;
    const dump = JSON.parse(php(`
      $out = [];
      foreach ([${ids.join(',')}] as $id) {
        $o = \\App\\Models\\Order::withoutGlobalScopes()->find($id);
        if (!$o) { $out[] = ['id' => $id, 'missing' => true]; continue; }
        $s = \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', $id)->first();
        $out[] = ['id' => $o->id, 'serial' => $o->order_serial_no, 'order_type' => $o->order_type, 'source_surface' => $o->source_surface, 'status' => (int) $o->status, 'payment_status' => (int) $o->payment_status,
          'delivery_charge' => (float) $o->delivery_charge, 'subtotal' => (float) $o->subtotal, 'total' => (float) $o->total, 'delivery_tier' => $o->delivery_tier, 'delivery_distance_km' => $o->delivery_distance_km ?? null,
          'fiscal_sequence_no' => $o->fiscal_sequence_no, 'user_id' => $o->user_id, 'address' => optional($o->address)->only(['id','address','apartment','city','postal_code']), 'delivery_boy_id' => $o->delivery_boy_id, 'user_name' => optional(\\App\\Models\\User::withoutGlobalScopes()->find($o->user_id))->name,
          'stop' => $s ? ['id' => $s->id, 'wave_at' => (string) $s->wave_at, 'sequence' => $s->sequence, 'left_at' => (string) $s->left_at, 'arrived_at' => (string) $s->arrived_at, 'delivered_at' => (string) $s->delivered_at, 'collect_method' => $s->collect_method, 'amount_due_cents' => $s->amount_due_cents, 'amount_collected_cents' => $s->amount_collected_cents, 'delivery_boy_id' => $s->delivery_boy_id, 'photo' => (bool) $s->proof_photo_path && \\Illuminate\\Support\\Facades\\Storage::disk('local')->exists($s->proof_photo_path)] : null];
      }
      echo json_encode($out);`));
    G.db = dump;
    let chain = '';
    try { chain = execFileSync('php', ['artisan', 'fiscal:verify-chain', '--all'], { cwd: ROOT, encoding: 'utf8', timeout: 120_000 }); } catch (e) { chain = 'ERREUR ' + (e.stdout || e.message); }
    G.fiscalChain = chain.trim().split('\n').slice(-4).join(' | ');
    G.posCustomer = G.posPhone ? JSON.parse(php(`echo json_encode(\\App\\Models\\User::withoutGlobalScopes()->where('phone', '${G.posPhone}')->get(['id','name','phone','email','is_guest','branch_id'])->toArray());`)) : null;
    fs.writeFileSync(path.join(OUT, 'db-verification.json'), JSON.stringify({ orders: dump, fiscalChain: G.fiscalChain, posCustomer: G.posCustomer }, null, 2));
    flush();
  });
});
