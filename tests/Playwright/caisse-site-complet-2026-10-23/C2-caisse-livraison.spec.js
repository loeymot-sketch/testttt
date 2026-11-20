// [AUDIT E2E caisse-site-complet-2026-10-23 · vague C2] Caisse : livraison de bout en bout + écran livreur.
//
// Chemin RÉEL (navigateur) : caisse en mode Livraison → refus sans téléphone → refus hors zone (Lille) → devis Hénin-Beaumont →
// « Commande livraison » (le livreur encaisse à la porte) → base → planche « Tournées » + page /admin/livraisons → étiquette
// « heure dépassée » (wave_at d'UN arrêt de test reculé en base) → écran /livreur en 390×844 : départ, arrivée (photo JPEG +
// position), remise/encaissement → base (payé, numéro fiscal UNE fois) → planche « remise ».
//
// Seule préparation hors écran : la cuisine passe la commande à PRÊTE par l'API d'administration (ACCEPT → PREPARING → PREPARED) —
// l'écran cuisine n'est pas le périmètre de cette vague.
//
// Base LOCALE `foodking_e2e` uniquement. Laisse un client « E2E-C2 … » et une commande livrée fiscalisée.
//
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 npx playwright test \
//     tests/Playwright/caisse-site-complet-2026-10-23/C2-caisse-livraison.spec.js --workers=1 --retries=0 --reporter=line
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');

const ROOT = path.resolve(__dirname, '../../..');
const OUT = path.join(ROOT, 'reports/test-e2e/caisse-site-complet-2026-10-23/captures-C2');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = env('MIX_API_KEY') || env('API_KEY');
const POS = { email: 'pos@lecayenne.fr', password: '123456' };
const COURIER = { id: 10, email: 'livreur.e2e@lecayenne.test', password: 'Livreur123!', name: 'Livreur E2E' };
// Vrais produits (items, status=5). 2ᵉ boisson : la première À 1,90 € non « ÉPUISÉ » (une autre vague peut épuiser un produit).
const ITEMS = [{ id: 58, name: 'Eau Plate 50cl', price: 1.0 }];
const SECONDE = [{ id: 55, name: 'Sprite 33cl' }, { id: 54, name: 'Fanta Orange 33cl' }, { id: 53, name: 'Coca-Cola Zero 33cl' }, { id: 57, name: 'Orangina 33cl' }, { id: 52, name: 'Coca-Cola 33cl' }];
// Pas « 437 rue Elie Gruyelle » : c'est l'adresse du restaurant (0 km), elle ne prouverait rien sur l'aller simple.
const HENIN = 'boulevard albert schweitzer henin';
const LILLE = '1 rue de la Bourse Lille';

/** PHP dans l'application (tinker) : renvoie l'objet JSON de la dernière ligne imprimée. */
function php(code) {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8', timeout: 60_000 });
  const lignes = out.trim().split('\n').filter(Boolean);
  for (let i = lignes.length - 1; i >= 0; i -= 1) { try { return JSON.parse(lignes[i]); } catch (_) { /* bruit */ } }
  throw new Error('tinker sans JSON : ' + out.slice(-400));
}
const eur = (n) => Number(n).toFixed(2).replace('.', ',');
const mesures = { etapes: {} };
const noter = (k, v) => { mesures.etapes[k] = v; fs.mkdirSync(OUT, { recursive: true }); fs.writeFileSync(path.join(OUT, 'C2-mesures.json'), JSON.stringify(mesures, null, 2)); };

async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 60_000 }).catch(() => { /* soketi/polling : networkidle peut ne jamais venir */ });
  await page.waitForTimeout(1200);
}

async function lastToast(page, rx) {
  const t = page.locator('.Vue-Toastification__toast', { hasText: rx }).last();
  await expect(t).toBeVisible({ timeout: 20_000 });
  return (await t.innerText()).trim();
}

test.describe.configure({ mode: 'serial' });

test('C2 — caisse : livraison de bout en bout jusqu\'à la remise par le livreur', async ({ page, browser }) => {
  test.setTimeout(600_000);
  fs.mkdirSync(OUT, { recursive: true });
  await page.setViewportSize({ width: 1280, height: 720 });
  const { snap } = attachMegaAuditRecorder(page, OUT);

  // Toutes les réponses de l'application ≥ 400 (le bruit tiers est filtré à la lecture).
  const erreursReseau = [];
  const devisServeur = [];
  let devisCommande = null;
  let reponseCommande = null;
  page.on('response', async (r) => {
    const u = r.url();
    if (!u.startsWith(BASE)) return;
    if (r.status() >= 400) erreursReseau.push({ url: u.replace(BASE, ''), method: r.request().method(), status: r.status() });
    try {
      if (r.request().method() === 'POST' && /\/api\/admin\/pos-delivery\/quote$/.test(u)) devisServeur.push(await r.json());
      if (r.request().method() === 'POST' && /\/api\/admin\/pos\/quote$/.test(u)) devisCommande = await r.json();
      if (r.request().method() === 'POST' && /\/api\/admin\/pos$/.test(u)) reponseCommande = { status: r.status(), body: await r.json() };
    } catch (_) { /* corps illisible */ }
  });

  // ── 0. Connexion caisse (jeton capturé pour relire les API que l'écran consomme) ─────────────────────────────
  await page.goto('/login');
  await expect(page.locator('#formEmail')).toBeVisible({ timeout: 60_000 });
  await page.locator('#formEmail').fill(POS.email);
  await page.locator('#formPassword').fill(POS.password);
  const [loginResp] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/auth\/login/.test(r.url()), { timeout: 60_000 }),
    page.getByRole('button', { name: /^(login|connexion)$/i }).click(),
  ]);
  expect(loginResp.status(), 'connexion caisse').toBe(201);
  const posToken = (await loginResp.json()).token;
  expect(posToken).toBeTruthy();
  const posHeaders = { 'X-API-KEY': API_KEY, Authorization: `Bearer ${posToken}`, Accept: 'application/json' };
  await page.waitForURL((u) => !/\/login/.test(u.pathname), { timeout: 60_000 });

  const stamp = Date.now();
  const phone = '06' + String(stamp).slice(-8);
  const nom = 'E2E-C2 Client ' + String(stamp).slice(-5);
  const avant = php(`echo json_encode(['max' => (int) DB::table('orders')->max('id'), 'n' => DB::table('orders')->count()]);`);
  noter('0-terrain', { phone, nom, max_order_id_avant: avant.max, n_orders_avant: avant.n });

  await page.goto('/admin/pos');
  await settle(page);
  const toggle = page.locator('[data-testid="pos-delivery-toggle"]');
  await expect(toggle).toBeVisible({ timeout: 60_000 });
  // On NE touche PAS au réglage : il doit être actif (pré-vol de l'orchestrateur).
  await expect(toggle, 'la livraison doit être active localement (pré-vol) — ce spec ne la bascule jamais').toContainText('active');

  // ── 1. Deux vrais produits, puis mode Livraison ─────────────────────────────────────────────────────────────
  // Caisse « catégorie d'abord » : après chaque ajout elle revient aux catégories.
  await page.locator('button:visible', { hasText: /^\s*Boissons\s*$/ }).first().click({ timeout: 60_000 });
  await page.locator(`button[data-pos-item-id="58"]:visible`).first().click({ timeout: 60_000 });
  await page.waitForTimeout(900);
  await page.locator('button:visible', { hasText: /^\s*Boissons\s*$/ }).first().click({ timeout: 60_000 });
  await page.waitForTimeout(600);
  let seconde = null;
  for (const c of SECONDE) {
    const b = page.locator(`button[data-pos-item-id="${c.id}"]:visible`).first();
    if (await b.count() && await b.isEnabled()) { seconde = c; await b.click(); break; }
  }
  expect(seconde, 'une boisson à 1,90 € disponible').not.toBeNull();
  ITEMS.push({ ...seconde, price: 1.9 });
  await page.waitForTimeout(900);
  const totalArticles = ITEMS.reduce((s, i) => s + i.price, 0);
  await expect(page.locator('[data-testid="pos-grand-total"]').first(), 'les 2 boissons sont au ticket (pas d\'assistant intercalé)').toContainText(eur(totalArticles), { timeout: 20_000 });

  await page.locator('label[for="delivery"]').click();
  await expect(page.locator('#pos-delivery-phone')).toBeVisible({ timeout: 20_000 });
  const btnLivraison = page.locator('[data-testid="pos-delivery-order"]');
  await expect(btnLivraison, '« Commande livraison » présent en livraison').toBeVisible();
  await expect(btnLivraison).toContainText('Commande livraison');
  await expect(page.locator('[data-testid="pos-phone-order"]'), '« Commande téléphone » ABSENT en livraison').toHaveCount(0);
  await settle(page);
  await snap('01-caisse-mode-livraison');
  noter('1-mode-livraison', { commande_livraison: true, commande_telephone_absent: true, total_articles: totalArticles });

  // ── 4. « Commande livraison » SANS téléphone : refus avec motif, aucune commande ────────────────────────────
  const nAvantRefus = php(`echo json_encode(['n' => DB::table('orders')->count()]);`).n;
  await btnLivraison.click();
  const toastSansTel = await lastToast(page, /téléphone/i);
  expect(toastSansTel).toMatch(/livreur doit pouvoir l.appeler/);
  await page.waitForTimeout(1500);
  await snap('02-refus-sans-telephone');
  const nApresRefus = php(`echo json_encode(['n' => DB::table('orders')->count(), 'mine' => DB::table('orders')->where('id', '>', ${avant.max})->where('creator_id', 3)->where('order_type', 5)->count()]);`);
  expect(nApresRefus.mine, 'aucune commande livraison créée sans téléphone').toBe(0);
  noter('4-sans-telephone', { toast: toastSansTel, n_orders_avant: nAvantRefus, n_orders_apres: nApresRefus.n, livraisons_creees_par_pos: nApresRefus.mine,
    remarque: nApresRefus.n !== nAvantRefus ? 'le compte global a bougé : autres vagues en parallèle (le filtre creator_id=pos@ + order_type=livraison reste à 0)' : 'compte global inchangé' });

  // ── Saisie client : téléphone + nom ────────────────────────────────────────────────────────────────────────
  await page.locator('#pos-delivery-phone').fill(phone);
  await page.locator('#pos-delivery-name').fill(nom);
  await page.waitForTimeout(1200);

  // ── 3. Adresse HORS ZONE (Lille) : refus clair + Uber Eats, aucun prix ─────────────────────────────────────
  const adresse = page.locator('#pos-delivery-address');
  await adresse.fill(LILLE);
  const suggLille = page.locator('#orderdelivery ul li', { hasText: /Lille/i }).first();
  await expect(suggLille, 'suggestions BAN (réseau réel) pour Lille').toBeVisible({ timeout: 30_000 });
  const libelleLille = (await suggLille.innerText()).trim();
  await suggLille.dispatchEvent('mousedown');
  const devisBox = page.locator('[data-testid="pos-delivery-quote"]');
  await expect(devisBox).toContainText('Uber Eats', { timeout: 30_000 });
  const texteLille = (await devisBox.innerText()).trim();
  expect(texteLille).toMatch(/Au-delà de 10 km : livraison impossible/);
  expect(texteLille, 'aucun prix inventé pour une adresse refusée').not.toMatch(/€/);
  await expect(page.locator('[data-testid="pos-grand-total"]').first(), 'aucun frais ajouté au total').toContainText(eur(totalArticles));
  const devisLille = devisServeur[devisServeur.length - 1];
  expect(devisLille.allowed).toBe(false);
  expect(devisLille.suggest_uber_eats).toBe(true);
  // Tenter quand même : refus, aucune commande.
  await btnLivraison.click();
  const toastLille = await lastToast(page, /Uber Eats/);
  await page.waitForTimeout(1000);
  await snap('03-hors-zone-lille');
  const apresLille = php(`echo json_encode(['mine' => DB::table('orders')->where('id', '>', ${avant.max})->where('creator_id', 3)->where('order_type', 5)->count()]);`);
  expect(apresLille.mine).toBe(0);
  noter('3-hors-zone', { suggestion: libelleLille, texte_devis: texteLille, toast_tentative: toastLille, devis_serveur: devisLille, livraisons_creees: apresLille.mine });

  // ── 2. Adresse réelle à Hénin-Beaumont : devis 3,90 €, distance, durée — ALLER SIMPLE ──────────────────────
  await adresse.fill(HENIN);
  const suggHenin = page.locator('#orderdelivery ul li', { hasText: /H[ée]nin/i }).first();
  await expect(suggHenin, 'suggestions BAN pour Hénin-Beaumont').toBeVisible({ timeout: 30_000 });
  const libelleHenin = (await suggHenin.innerText()).trim();
  await suggHenin.dispatchEvent('mousedown');
  await expect(devisBox).toContainText('3,90', { timeout: 30_000 });
  await page.waitForTimeout(800);
  const texteHenin = (await devisBox.innerText()).trim();
  const devisHenin = devisServeur[devisServeur.length - 1];
  expect(devisHenin.allowed).toBe(true);
  expect(Number(devisHenin.fee)).toBe(3.9);
  // Le texte du caissier reprend EXACTEMENT les chiffres aller simple du serveur (jamais doublés).
  expect(texteHenin).toContain(`${String(devisHenin.distance_km).replace('.', ',')} km`);
  expect(texteHenin).toContain(`~${devisHenin.travel_minutes} min`);
  expect(Number(devisHenin.travel_minutes), 'durée plausible d\'un aller simple de quelques km').toBeLessThan(30);
  await expect(page.locator('[data-testid="pos-grand-total"]').first()).toContainText(eur(totalArticles + 3.9), { timeout: 15_000 });
  await adresse.scrollIntoViewIfNeeded();
  await snap('04-devis-henin');
  noter('2-devis-henin', { suggestion: libelleHenin, texte_devis: texteHenin, depart: await page.locator('[data-testid="pos-delivery-departure"]').innerText().catch(() => null),
    devis_serveur: { fee: devisHenin.fee, distance_km: devisHenin.distance_km, travel_minutes: devisHenin.travel_minutes, tier: devisHenin.tier, address: devisHenin.address, waves: (devisHenin.waves || []).slice(0, 4) } });

  // ── 5. « Commande livraison » complète ──────────────────────────────────────────────────────────────────────
  await btnLivraison.click();
  const toastOk = await lastToast(page, /Livraison enregistrée/);
  expect(toastOk).toMatch(/à encaisser à la porte par le livreur/);
  const mToast = toastOk.match(/(\d+,\d{2}) € à encaisser/);
  expect(mToast, 'le toast annonce le montant').not.toBeNull();
  await page.waitForTimeout(800);
  await snap('05-toast-commande-livraison');
  expect(reponseCommande && reponseCommande.status, 'POST /api/admin/pos').toBe(201);

  const cmd = php(`
    $o = \\App\\Models\\Order::withoutGlobalScopes()->where('id', '>', ${avant.max})->where('creator_id', 3)->where('order_type', 5)->latest('id')->first();
    $s = \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', $o->id)->first();
    $u = \\App\\Models\\User::withoutGlobalScopes()->find($o->user_id);
    echo json_encode(['id' => $o->id, 'serial' => $o->order_serial_no, 'order_type' => (int) $o->order_type, 'status' => (int) $o->status, 'payment_status' => (int) $o->payment_status,
      'payment_method' => $o->payment_method, 'pos_payment_method' => $o->pos_payment_method ?? null, 'source_surface' => $o->source_surface, 'fiscal' => $o->fiscal_sequence_no, 'creator_id' => (int) $o->creator_id,
      'delivery_charge' => (float) $o->delivery_charge, 'subtotal' => (float) $o->subtotal, 'total' => (float) $o->total, 'address_id' => $o->address_id, 'user' => $u?->name, 'user_phone' => $u?->phone,
      // L'adresse d'une commande vit dans order_addresses (Order::address = HasOne OrderAddress), pas dans orders.address_id.
      'addr' => $o->address ? $o->address->only(['address', 'apartment', 'city', 'postal_code', 'latitude', 'longitude']) : null,
      'stop' => $s ? ['id' => $s->id, 'wave_at' => (string) $s->wave_at, 'eta_minutes' => $s->eta_minutes, 'distance_km' => (float) $s->distance_km, 'collect' => $s->collect_method, 'due' => (int) $s->amount_due_cents, 'boy' => $s->delivery_boy_id] : null]);`);
  noter('5-commande-base', { toast: toastOk, devis_commande_total_ttc: devisCommande?.data?.total_ttc, commande: cmd });
  expect(cmd.order_type, 'order_type = livraison').toBe(5);
  expect(cmd.payment_status, 'PENDING_COUNTER (15)').toBe(15);
  expect(String(cmd.source_surface).toLowerCase()).toBe('delivery');
  expect(cmd.fiscal, 'pas de numéro fiscal à la création').toBeNull();
  expect(cmd.creator_id).toBe(3);
  expect(cmd.delivery_charge).toBeCloseTo(3.9, 2);
  expect(cmd.total).toBeCloseTo(totalArticles + 3.9, 2);
  expect(eur(cmd.total), 'montant du toast = total en base').toBe(mToast[1]);
  expect(cmd.user).toBe(nom);
  expect(cmd.stop, 'une ligne delivery_stops').not.toBeNull();
  expect(cmd.stop.wave_at).toMatch(/:(00|30):00$/);
  expect(cmd.stop.eta_minutes).toBeGreaterThan(0);
  expect(cmd.stop.due, 'à encaisser à la porte = total').toBe(Math.round(cmd.total * 100));

  // Aller simple vérifié par un 2ᵉ moyen indépendant : vol d'oiseau restaurant → point ENREGISTRÉ (celui que reçoit le livreur).
  const lat = Number(cmd.addr && cmd.addr.latitude);
  const lon = Number(cmd.addr && cmd.addr.longitude);
  const R = 6371; const rad = (d) => d * Math.PI / 180; const lat0 = 50.4215667; const lon0 = 2.954906;
  const hav = 2 * R * Math.asin(Math.sqrt(Math.sin(rad(lat - lat0) / 2) ** 2 + Math.cos(rad(lat0)) * Math.cos(rad(lat)) * Math.sin(rad(lon - lon0) / 2) ** 2));
  expect(Number.isFinite(hav), 'adresse enregistrée géocodée').toBe(true);
  expect(Math.abs(Number(devisHenin.distance_km) - hav), 'distance du devis = aller simple (jamais doublée)').toBeLessThan(0.15);
  noter('2b-aller-simple', { haversine_km: Number(hav.toFixed(3)), devis_km: devisHenin.distance_km, stop_km: cmd.stop.distance_km, devis_min: devisHenin.travel_minutes, eta_arret_min: cmd.stop.eta_minutes });

  // Pas dans la file « À encaisser au comptoir » : API que la caisse consomme + écran.
  const pending = await (await page.request.get('/api/admin/pos/counter-collect/pending', { headers: posHeaders })).json();
  const pendingIds = JSON.stringify(pending);
  expect(pendingIds.includes(`"id":${cmd.id},`) || pendingIds.includes(`"id":${cmd.id}}`), 'absente de la file « À encaisser au comptoir » (API)').toBe(false);
  const fileCaisse = page.locator('[data-testid="pos-shortcuts-cash"]');
  const texteFile = (await fileCaisse.count()) ? await fileCaisse.innerText() : '';
  expect(texteFile.includes(cmd.serial), 'absente de la file « À encaisser au comptoir » (écran)').toBe(false);
  noter('5-file-comptoir', { present_api: false, present_ecran: false, extrait_file: texteFile.slice(0, 300) });

  // ── 6a. Planche « Tournées » (fenêtre caisse) — tournée naturelle de la commande ─────────────────────────
  const waveDate = cmd.stop.wave_at.slice(0, 10);
  const waveTime = cmd.stop.wave_at.slice(11, 16);
  await page.locator('[data-testid="pos-delivery-board-open"]').click();
  const board = page.locator('[data-testid="pos-delivery-board"]');
  await expect(board).toBeVisible({ timeout: 20_000 });
  const section = board.locator(`[data-testid="pos-delivery-wave-${waveDate}-${waveTime}"]`);
  await expect(section).toContainText(cmd.serial, { timeout: 30_000 });
  const ligneArret = section.locator('li', { hasText: cmd.serial });
  await expect(ligneArret).toContainText(`Espèces ${eur(cmd.total)} €`);
  const tourLine = board.locator(`[data-testid="pos-delivery-wave-tour-${waveDate}-${waveTime}"]`);
  await expect(tourLine, 'ligne « Tournée aller-retour »').toBeVisible({ timeout: 30_000 });
  const texteTour = (await tourLine.innerText()).trim();
  expect(texteTour).toMatch(/Tournée aller-retour ≈ .+ · retour au restaurant vers \d{2}:\d{2}/);
  expect(texteTour).toMatch(/\((itinéraire routier réel|estimation, itinéraire indisponible) · dont .+ de retour\)/);
  await ligneArret.scrollIntoViewIfNeeded();
  await settle(page);
  await snap('06-planche-tournees-caisse');

  const boardJson = await (await page.request.get('/api/admin/pos-delivery/board', { headers: posHeaders })).json();
  const w = boardJson.data.find((x) => x.wave_at === cmd.stop.wave_at);
  const label = (m) => { m = Math.round(m); return m >= 60 ? Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') : m + ' min'; };
  expect(w && w.tour, 'tour présent dans /board').toBeTruthy();
  expect(texteTour).toContain(label(w.tour.tour_minutes));
  expect(texteTour).toContain(String(w.tour.back_at).slice(11, 16));
  expect(texteTour).toContain(`dont ${label(w.tour.return_minutes)} de retour`);
  expect(texteTour).toContain(w.tour.source === 'road' ? 'itinéraire routier réel' : 'estimation, itinéraire indisponible');
  const maxEta = Math.max(...w.stops.map((s) => {
    const row = php(`echo json_encode(['eta' => (int) \\App\\Models\\DeliveryStop::withoutGlobalScopes()->whereKey(${s.id})->value('eta_minutes')]);`);
    return row.eta;
  }));
  expect(w.tour.tour_minutes, 'tournée aller-retour > ETA aller simple du dernier arrêt + remise').toBeGreaterThan(maxEta + 3);
  expect(w.tour.return_minutes).toBeGreaterThan(0);
  const nowMs = Date.now();
  const backMs = new Date(String(w.tour.back_at).replace(' ', 'T')).getTime();
  expect(backMs, 'retour ≥ maintenant').toBeGreaterThanOrEqual(nowMs - 120_000);
  noter('6a-planche-naturelle', { wave_at: cmd.stop.wave_at, etat: w.overdue ? 'heure dépassée' : (w.left ? 'parti' : 'à venir'), n_arrets: w.stops.length, texte_tour: texteTour, tour_api: w.tour, eta_max_aller_simple: maxEta,
    montant_planche: await ligneArret.locator('.text-right').innerText() });

  // ── 6b. Page /admin/livraisons (barre latérale « Livraisons ») ─────────────────────────────────────────────
  await board.getByRole('button', { name: 'Fermer' }).click();
  await page.goto('/admin/livraisons');
  await settle(page);
  const pageBoard = page.locator('[data-testid="pos-delivery-board"]');
  await expect(pageBoard).toBeVisible({ timeout: 30_000 });
  const pageSection = pageBoard.locator(`[data-testid="pos-delivery-wave-${waveDate}-${waveTime}"]`);
  await expect(pageSection).toContainText(cmd.serial, { timeout: 30_000 });
  await expect(pageSection.locator('li', { hasText: cmd.serial })).toContainText(`Espèces ${eur(cmd.total)} €`);
  const texteTourPage = (await pageBoard.locator(`[data-testid="pos-delivery-wave-tour-${waveDate}-${waveTime}"]`).innerText()).trim();
  expect(texteTourPage).toMatch(/Tournée aller-retour ≈/);
  await pageSection.scrollIntoViewIfNeeded();
  await snap('07-page-admin-livraisons');
  noter('6b-page-livraisons', { texte_tour: texteTourPage, identique_planche: texteTourPage === texteTour });

  // ── 6c. « heure dépassée, pas encore partie » : on recule UN arrêt de test (le nôtre) sur un créneau passé LIBRE ──
  //    Effet voulu aussi : isoler notre arrêt de la tournée naturelle (pleine d'autres commandes de test, départ hors fenêtre).
  const recul = php(`
    $now = now(); $slot = null;
    for ($k = 1; $k <= 48; $k++) { $c = $now->copy()->subMinutes(30 * $k)->setSecond(0); $c->setMinute($c->minute >= 30 ? 30 : 0);
      if (! $slot && $c->lt($now) && ! \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('wave_at', $c->toDateTimeString())->exists()) { $slot = $c; } }
    if ($slot) { \\App\\Models\\DeliveryStop::withoutGlobalScopes()->whereKey(${cmd.stop.id})->update(['wave_at' => $slot->toDateTimeString()]);
      // [round 2 · C2-006] recalcule ordre de passage et ETA comme le produit le ferait (sinon pastille « 3 » dans une tournée d'1 arrêt).
      app(\\App\\Services\\Delivery\\DeliveryWaveService::class)->resequence(1, $slot); }
    echo json_encode(['slot' => $slot ? $slot->toDateTimeString() : null]);`);
  expect(recul.slot, 'un créneau passé libre existe dans les 24 dernières heures').toBeTruthy();
  const slotDate = recul.slot.slice(0, 10);
  const slotTime = recul.slot.slice(11, 16);
  await page.reload();
  await settle(page);
  const secRecul = pageBoard.locator(`[data-testid="pos-delivery-wave-${slotDate}-${slotTime}"]`);
  await expect(secRecul).toContainText(cmd.serial, { timeout: 30_000 });
  await expect(pageBoard.locator(`[data-testid="pos-delivery-wave-state-${slotDate}-${slotTime}"]`)).toHaveText('heure dépassée, pas encore partie');
  // Désignation du livreur par la planche (plusieurs livreurs actifs → personne n'est choisi en silence).
  await expect(secRecul).toContainText('Aucun livreur');
  await secRecul.locator('select').selectOption(String(COURIER.id));
  await expect(secRecul).toContainText(`Livreur : ${COURIER.name}`, { timeout: 30_000 });
  const tourRecul = pageBoard.locator(`[data-testid="pos-delivery-wave-tour-${slotDate}-${slotTime}"]`);
  await expect(tourRecul).toBeVisible({ timeout: 30_000 });
  const texteTourRecul = (await tourRecul.innerText()).trim();
  const b2 = await (await page.request.get('/api/admin/pos-delivery/board', { headers: posHeaders })).json();
  const w2 = b2.data.find((x) => x.wave_at === recul.slot);
  const back2 = new Date(String(w2.tour.back_at).replace(' ', 'T')).getTime();
  expect(back2, 'tournée en retard : retour au plus tôt maintenant, jamais dans le passé').toBeGreaterThanOrEqual(Date.now() - 120_000);
  // Seul dans sa tournée : aller-retour > aller simple du devis + remise (4 min). (eta_minutes de l'arrêt est celui de son rang dans la
  // tournée d'origine — 3ᵉ arrêt — : le recul en base ne le recalcule pas, ce n'est pas une mesure du produit.)
  expect(w2.tour.tour_minutes, 'aller-retour > aller simple + remise').toBeGreaterThan(Number(devisHenin.travel_minutes) + 4);
  expect(w2.tour.stops).toBe(1);
  await secRecul.scrollIntoViewIfNeeded();
  await snap('08-planche-heure-depassee-livreur-designe');
  noter('6c-heure-depassee', { slot: recul.slot, etat: 'heure dépassée, pas encore partie', texte_tour: texteTourRecul, tour_api: w2.tour, eta_aller_simple_arret: cmd.stop.eta_minutes });

  // ── Cuisine (préparation hors périmètre) : ACCEPT → PREPARING → PREPARED par l'API d'administration ──────────
  for (const status of [4, 7, 8]) {
    const cur = php(`echo json_encode(['s' => (int) \\App\\Models\\Order::withoutGlobalScopes()->whereKey(${cmd.id})->value('status')]);`).s;
    if (cur >= status) continue;
    const r = await page.request.post(`/api/admin/pos-order/change-status/${cmd.id}`, { headers: { ...posHeaders, 'X-Idempotency-Key': crypto.randomUUID() }, data: { status } });
    expect(r.status(), `cuisine → statut ${status} : ${await r.text()}`).toBeLessThan(300);
  }

  // ── 7. Écran livreur /livreur en 390×844 ───────────────────────────────────────────────────────────────────
  const geo = { latitude: lat || 50.42, longitude: lon || 2.95 };
  const ctxL = await browser.newContext({ baseURL: BASE, viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
    locale: 'fr-FR', timezoneId: 'Europe/Paris', permissions: ['geolocation'], geolocation: geo });
  const pl = await ctxL.newPage();
  const recL = attachMegaAuditRecorder(pl, OUT);
  pl.on('response', (r) => { if (r.url().startsWith(BASE) && r.status() >= 400) erreursReseau.push({ url: r.url().replace(BASE, ''), method: r.request().method(), status: r.status(), surface: 'livreur' }); });
  const dialogues = [];
  pl.on('dialog', (d) => { dialogues.push(d.message()); d.accept(); });

  await pl.goto('/livreur');
  await pl.locator('#email').fill(COURIER.email);
  await pl.locator('#password').fill(COURIER.password);
  await pl.locator('#login-btn').click();
  const arret = pl.locator('.stop', { hasText: cmd.serial });
  await expect(arret).toBeVisible({ timeout: 30_000 });
  await expect(arret).toContainText(`Encaisser en espèces : ${eur(cmd.total)} €`);
  await expect(arret).toContainText(nom);
  await expect(arret).toContainText(phone);
  const runJson = await pl.evaluate(async (k) => (await fetch('/api/frontend/delivery-run', { headers: { Accept: 'application/json', 'X-API-KEY': k, Authorization: 'Bearer ' + localStorage.getItem('lc_livreur_token') } })).json(), API_KEY);
  const wl = runJson.data.find((x) => x.wave_at === recul.slot);
  expect(wl && wl.tour, 'tour présent sur l\'écran livreur pour notre tournée').toBeTruthy();
  const departBtn = pl.locator(`button[data-depart="${recul.slot}"]`);
  await expect(departBtn).toBeVisible();
  await expect(departBtn).toBeEnabled();
  // La ligne « Tournée aller-retour » de NOTRE tournée = le frère précédent le bouton « Je pars ».
  const texteTourLivreur = (await departBtn.locator('xpath=preceding-sibling::div[@data-testid="livreur-tour"][1]').innerText()).trim();
  expect(texteTourLivreur).toContain(`Tournée aller-retour ≈ ${label(wl.tour.tour_minutes)}`);
  expect(texteTourLivreur).toContain(`retour au restaurant vers ${String(wl.tour.back_at).slice(11, 16)}`);
  await arret.scrollIntoViewIfNeeded();
  await departBtn.scrollIntoViewIfNeeded();
  await settle(pl);
  await recL.snap('09-livreur-tournee-390');
  noter('7a-livreur-liste', { texte_tour: texteTourLivreur, tour_api_livreur: wl.tour, tour_api_caisse: w2.tour, montant_affiche: `Encaisser en espèces : ${eur(cmd.total)} €` });

  // « Je pars »
  await departBtn.click();
  await expect(arret).toContainText('En route', { timeout: 30_000 });
  await settle(pl);
  await arret.scrollIntoViewIfNeeded();
  await recL.snap('10-livreur-en-route');

  // Arrivée : vraie photo JPEG (canvas) + position (géolocalisation accordée au contexte)
  const jpeg = await pl.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 640; c.height = 480;
    const g = c.getContext('2d'); g.fillStyle = '#F4501E'; g.fillRect(0, 0, 640, 480); g.fillStyle = '#fff'; g.font = '44px sans-serif'; g.fillText('E2E-C2 porte', 170, 250);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.8));
    return Array.from(new Uint8Array(await blob.arrayBuffer()));
  });
  fs.writeFileSync(path.join(OUT, 'C2-photo-preuve.jpg'), Buffer.from(jpeg));
  await arret.locator('input[data-arrive]').setInputFiles({ name: 'porte.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg) });
  await expect(arret).toContainText('Arrivé', { timeout: 30_000 });
  await expect(arret).toContainText('Photo prise');
  await settle(pl);
  await arret.scrollIntoViewIfNeeded();
  await recL.snap('11-livreur-arrive-photo');

  // Remise + encaissement (confirmation du montant)
  await arret.locator('button[data-deliver]').click();
  await expect(arret).toContainText('Remise', { timeout: 30_000 });
  await expect(arret).toContainText('✓ Remise terminée');
  expect(dialogues.some((m) => m.includes(`${eur(cmd.total)} €`)), 'la confirmation demande le MÊME montant').toBe(true);
  await settle(pl);
  await arret.scrollIntoViewIfNeeded();
  await recL.snap('12-livreur-remise');

  // Double remise (rejeu) : refusée, aucun 2ᵉ numéro fiscal.
  const rejeu = await pl.evaluate(async ([k, id]) => {
    const r = await fetch(`/api/frontend/delivery-run/stops/${id}/deliver`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-API-KEY': k, 'X-Idempotency-Key': crypto.randomUUID(), Authorization: 'Bearer ' + localStorage.getItem('lc_livreur_token') }, body: JSON.stringify({ collected_cents: 1 }) });
    return { status: r.status, body: await r.text() };
  }, [API_KEY, cmd.stop.id]);
  expect(rejeu.status, 'une 2ᵉ remise est refusée').toBe(409);

  const fin = php(`
    $o = \\App\\Models\\Order::withoutGlobalScopes()->find(${cmd.id});
    $s = \\App\\Models\\DeliveryStop::withoutGlobalScopes()->find(${cmd.stop.id});
    echo json_encode(['status' => (int) $o->status, 'payment_status' => (int) $o->payment_status, 'fiscal' => $o->fiscal_sequence_no, 'delivery_boy_id' => (int) $o->delivery_boy_id,
      'fiscal_meme_numero' => \\App\\Models\\Order::withoutGlobalScopes()->where('branch_id', $o->branch_id)->where('fiscal_sequence_no', $o->fiscal_sequence_no)->count(),
      'left' => (string) $s->left_at, 'arrived' => (string) $s->arrived_at, 'delivered' => (string) $s->delivered_at, 'collected' => (int) $s->amount_collected_cents, 'due' => (int) $s->amount_due_cents,
      'arrival' => [$s->arrival_lat, $s->arrival_lng], 'photo' => (bool) $s->proof_photo_path && \\Illuminate\\Support\\Facades\\Storage::disk('local')->exists($s->proof_photo_path),
      'payments' => \\Illuminate\\Support\\Facades\\DB::table('order_payments')->where('order_id', $o->id)->get(['amount', 'mode', 'tendered', 'paid_at'])]);`);
  noter('7b-base-apres-remise', { ...fin, rejeu_remise: rejeu, dialogues });
  expect(fin.status, 'LIVRÉE (13)').toBe(13);
  expect(fin.payment_status, 'PAYÉE (5)').toBe(5);
  expect(fin.fiscal, 'numéro fiscal attribué à la remise').not.toBeNull();
  expect(fin.fiscal_meme_numero, 'numéro fiscal unique').toBe(1);
  expect(fin.delivered).not.toBe('');
  expect(fin.collected, 'encaissé = montant de la caisse').toBe(Math.round(cmd.total * 100));
  expect(fin.photo).toBe(true);
  expect(fin.arrival[0], 'position enregistrée à l\'arrivée').not.toBeNull();

  // Planche : arrêt remis
  await page.reload();
  await settle(page);
  const secFin = pageBoard.locator(`[data-testid="pos-delivery-wave-${slotDate}-${slotTime}"]`);
  await expect(secFin.locator('li', { hasText: cmd.serial })).toContainText(/Remise \d{2}:\d{2}/, { timeout: 30_000 });
  const texteArretFin = (await secFin.locator('li', { hasText: cmd.serial }).innerText()).trim();
  // [round 2 · C2-002] Tournée toute remise = « terminée » ; l'arrêt remis dit « Encaissé … (espèces) », plus « Espèces … » comme un dû.
  await expect(pageBoard.locator(`[data-testid="pos-delivery-wave-state-${slotDate}-${slotTime}"]`)).toHaveText('terminée');
  await expect(secFin.locator('li', { hasText: cmd.serial })).toContainText(`Encaissé ${eur(cmd.total)} € (espèces)`);
  await expect(secFin.locator('li', { hasText: cmd.serial })).not.toContainText(`Espèces ${eur(cmd.total)} €`);
  await secFin.scrollIntoViewIfNeeded();
  await snap('13-planche-arret-remis');
  noter('7c-planche-apres-remise', { texte_arret: texteArretFin, tour_visible: await pageBoard.locator(`[data-testid="pos-delivery-wave-tour-${slotDate}-${slotTime}"]`).count() });

  // ── 8. Réseau : aucun 5xx ; les 4xx de l'application listés ─────────────────────────────────────────────────
  noter('8-reseau', { erreurs_app: erreursReseau });
  expect(erreursReseau.filter((e) => e.status >= 500), 'aucun 5xx').toEqual([]);
  await ctxL.close();
});
