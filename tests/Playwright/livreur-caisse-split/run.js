// [LIVREUR CAISSE 2026-11-02] Banc NAVIGATEUR réel (Chromium 390×844) : accès livreur, suivi de statut, règlement à la porte en un ou
// plusieurs moyens (écran /livreur ET QR de remise), « Ma caisse », clôture avec écart. Serveur PHP local de CET arbre + base SQLITE
// JETABLE (livcaisse-e2e) : jamais la base locale ni la production.
//   node tests/Playwright/livreur-caisse-split/run.js [dossier-captures] [--only=guard]
// --only=guard : seul le garde-fou « la somme doit être le total » (sert à prouver que le banc MORD quand on le casse).
// Sort avec un code ≠ 0 au premier écart ; imprime un tableau de preuves.
const { chromium } = require('playwright');
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const net = require('net');

const ROOT = path.resolve(__dirname, '../../..');
const args = process.argv.slice(2);
const ONLY = (args.find((a) => a.startsWith('--only=')) || '').replace('--only=', '');
const OUT = args.find((a) => !a.startsWith('--')) || fs.mkdtempSync(path.join(os.tmpdir(), 'livcaisse-captures-'));
fs.mkdirSync(OUT, { recursive: true });
const DB = path.join(os.tmpdir(), `livcaisse-e2e-${process.pid}.sqlite`);
const API_KEY = 'e2e-api-key';
const proofs = [];
const ok = (cond, label) => { proofs.push([cond ? 'OK ' : 'KO ', label]); if (!cond) throw new Error('ÉCHEC : ' + label); };

function freePort() { return new Promise((res) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); }); }

function envFor(port) {
  return {
    ...process.env,
    APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: `http://127.0.0.1:${port}`, APP_KEY: 'base64:xStyFkYpE7z2419nS+F0eDk1xQ89H9xY4S/r+F+Z4eY=',
    DB_CONNECTION: 'sqlite', DB_DATABASE: DB, CACHE_DRIVER: 'file', QUEUE_CONNECTION: 'sync', SESSION_DRIVER: 'file', BROADCAST_DRIVER: 'log', MAIL_MAILER: 'log',
    MIX_API_KEY: API_KEY, API_KEY: API_KEY, IDEMPOTENCY_MIDDLEWARE_ENABLED: 'true', LOGIN_LOCKOUT_MAX_ATTEMPTS: '1000', API_THROTTLE_PER_MINUTE: '600',
    DELIVERY_SERVER_GEOCODING: 'false', DELIVERY_ROUTING_ENABLED: 'false', PUSHER_APP_KEY: '', PUSHER_APP_SECRET: '', PUSHER_APP_ID: '', TELESCOPE_ENABLED: 'false',
    BCRYPT_ROUNDS: '4', FISCAL_AUDIT_SECRET: 'testing-fiscal-audit-secret-padding-48chars-ok', FISCAL_Z_REPORT_SECRET: 'testing-fiscal-zreport-secret-padding-48chars-ok',
    POS_SIMULATION_HARDWARE: 'true', PHP_CLI_SERVER_WORKERS: '4', REDIS_CLIENT: 'phpredis',
  };
}
const php = (env, a) => execFileSync('php', a, { cwd: ROOT, env, encoding: 'utf8', timeout: 300_000 });
const dbJson = (env, code) => JSON.parse(php(env, ['artisan', 'tinker', '--execute', code]).trim().split('\n').filter(Boolean).pop());
const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', 'base64');

(async () => {
  const port = await freePort();
  const env = envFor(port);
  fs.writeFileSync(DB, '');
  php(env, ['artisan', 'migrate:fresh', '--force']);
  const seed = JSON.parse(php(env, ['tests/Playwright/livreur-caisse-split/seed.php']).trim().split('\n').pop());
  const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], { cwd: ROOT, env, stdio: 'ignore' });
  const base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 80; i++) { try { if ((await fetch(base + '/livreur')).ok) break; } catch (e) {} await new Promise((r) => setTimeout(r, 500)); }

  const browser = await chromium.launch();
  const phone = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'fr-FR', geolocation: { latitude: 50.4301, longitude: 2.9601, accuracy: 15 }, permissions: ['geolocation'] };
  const shot = (page, name) => page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: false });
  const shotFull = (page, name) => page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: true });
  const text = (page) => page.evaluate(() => document.body.innerText);
  const N = (s) => s.replace(/\s+/g, ' ').replace(/ /g, ' ');
  const card = (page, ref) => page.locator('.stop', { hasText: 'Rue ' + ref });
  const pageErrors = []; const consoleErrors = [];
  const tokenOf = async (page) => page.evaluate(() => localStorage.getItem('lc_livreur_token'));
  const api = (page, method, p, body, idem) => page.evaluate(async ([m, u, b, k, key]) => {
    const t = localStorage.getItem('lc_livreur_token');
    const r = await fetch(u, { method: m, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-API-KEY': k, Authorization: 'Bearer ' + t, ...(m !== 'GET' ? { 'X-Idempotency-Key': key } : {}) }, body: b ? JSON.stringify(b) : undefined });
    return { status: r.status, body: await r.json().catch(() => ({})) };
  }, [method, p, body || null, API_KEY, idem || (Math.random().toString(16).slice(2) + Date.now())]);

  try {
    const ctx = await browser.newContext(phone);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => pageErrors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/ERR_INTERNET_DISCONNECTED|Failed to load resource/.test(m.text())) consoleErrors.push(m.text()); });
    page.on('dialog', (d) => d.accept());
    await page.goto(base + '/livreur');

    // ── 1. Accès livreur ─────────────────────────────────────────────────────────────────────────────────────
    await page.waitForSelector('#login:not(.hidden)');
    await shot(page, '01-connexion');
    await page.fill('#email', 'livreur.a@e2e.test'); await page.fill('#password', 'mauvais'); await page.click('#login-btn');
    await page.waitForFunction(() => document.getElementById('login-err').textContent.length > 3);
    ok(!/Failed to fetch|undefined/.test(await page.textContent('#login-err')), '1a mauvais mot de passe : message lisible, pas de texte technique : « ' + (await page.textContent('#login-err')).trim().slice(0, 60) + ' »');
    await page.fill('#password', 'password'); await page.click('#login-btn');
    await page.waitForSelector('#app:not(.hidden)');
    await page.waitForSelector('.stop');
    ok((await page.locator('.stop').count()) === 8, '1b après connexion e-mail + mot de passe : la tournée affiche les 8 livraisons du livreur A');

    if (ONLY === 'race') {
      // ── Course réelle à DEUX processus PHP (4 workers) : deux appuis simultanés, clés d'idempotence différentes ──
      await page.click('[data-depart]');
      await page.waitForFunction(() => document.querySelectorAll('.stop .chip.go').length >= 8);
      const url = `/api/frontend/delivery-run/stops/${seed.stops.guard.stop_id}/deliver`;
      const body = { payments: [{ mode: 'cash', amount: 10 }, { mode: 'card', amount: 17.5 }] };
      const res = await page.evaluate(async ([u, b, k, tok]) => {
        const go = (key) => fetch(u, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-API-KEY': k, Authorization: 'Bearer ' + tok, 'X-Idempotency-Key': key }, body: JSON.stringify(b) }).then(async (r) => ({ status: r.status, message: (await r.json().catch(() => ({}))).message }));
        return Promise.all([go('race-a-' + Date.now()), go('race-b-' + Date.now()), go('race-c-' + Date.now())]);
      }, [url, body, API_KEY, await tokenOf(page)]);
      proofs.push(['   ', 'réponses : ' + res.map((r) => r.status + (r.message ? ' (' + r.message.slice(0, 40) + ')' : '')).join(' | ')]);
      const w = res.filter((r) => r.status === 200).length;
      const d = dbJson(env, `$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${seed.stops.guard.order_id}); echo json_encode(['status'=>$o->status,'rows'=>\\App\\Models\\OrderPayment::withoutGlobalScopes()->where('order_id',$o->id)->count(),'mv'=>\\App\\Models\\DeliveryBoyCashMovement::withoutGlobalScopes()->where('order_id',$o->id)->count(),'esc'=>\\App\\Models\\AuditLog::where('action','delivery.cash_collected_escrow')->count(),'tr'=>\\App\\Models\\AuditLog::where('action','order.payment_tranche_persisted')->count(),'fiscal'=>\\App\\Models\\Order::withoutGlobalScopes()->whereNotNull('fiscal_sequence_no')->count()]);`);
      ok(w === 1, `R1 trois appuis simultanés : exactement UN succès (${w})`);
      ok(d.status === 13 && d.rows === 2 && d.mv === 1 && d.esc === 1 && d.tr === 1 && d.fiscal === 1, `R2 base : livrée, 2 lignes de paiement, 1 mouvement, 1 escrow, 1 trace de tranche, 1 numéro fiscal (${JSON.stringify(d)})`);
      return;
    }
    if (ONLY === 'guard') {
      // ── Garde-fou seul : une somme fausse envoyée À LA MAIN (le bouton de l'écran est grisé) doit être refusée par le SERVEUR ──
      await page.click('[data-depart]');
      await page.waitForFunction(() => document.querySelectorAll('.stop .chip.go').length >= 8);
      const g = await api(page, 'POST', `/api/frontend/delivery-run/stops/${seed.stops.guard.stop_id}/deliver`, { payments: [{ mode: 'cash', amount: 10 }, { mode: 'card', amount: 10 }] });
      ok(g.status === 422 && /doit être exactement le total/.test(g.body.message || ''), 'G1 somme fausse (20 € pour 27,50 €) : le serveur répond 422 en français « ' + (g.body.message || g.status) + ' »');
      const st = dbJson(env, `$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${seed.stops.guard.order_id}); echo json_encode(['status'=>$o->status,'pay'=>$o->payment_status,'rows'=>\\App\\Models\\OrderPayment::withoutGlobalScopes()->where('order_id',$o->id)->count(),'fiscal'=>$o->fiscal_sequence_no]);`);
      ok(st.status === 10 && st.pay === 10 && st.rows === 0 && st.fiscal === null, 'G2 rien n\'a été écrit : commande toujours en route, non payée, 0 ligne de paiement, pas de n° fiscal');
      return;
    }

    ok(/Départ/.test(await text(page)) && /Je pars \(8\/8 prêtes\)/.test(await text(page)), '1c « Je pars (8/8 prêtes) » : toutes les commandes sont prêtes');
    ok((await page.locator('.stop .chip.wait').count()) === 0, '1d aucune livraison marquée « En préparation »');
    await shot(page, '02-tournee-avant-depart');

    // ── 2. Départ → suivi de statut ──────────────────────────────────────────────────────────────────────────
    await page.click('[data-depart]');
    await page.waitForFunction(() => document.querySelectorAll('.stop .chip.go').length === 8);
    ok(/En route/.test(await card(page, 'ESPECES').innerText()), '2a après « Je pars » : chaque arrêt passe à « En route · ETA hh:mm »');
    const st1 = dbJson(env, `echo json_encode(\\App\\Models\\Order::withoutGlobalScopes()->pluck('status')->unique()->values());`);
    ok(JSON.stringify(st1) === '[10]', '2b base : les 8 commandes sont EN ROUTE (statut 10)');
    await shotFull(page, '03-tournee-en-route');

    // ── 3. Arrivée avec photo, puis remise en ESPÈCES seules par le bouton historique ───────────────────────
    const c1 = card(page, 'ESPECES');
    ok(/Encaisser en espèces : 27,50 €/.test(N(await c1.innerText())), '3a l\'arrêt dit « Encaisser en espèces : 27,50 € »');
    const jpeg = await page.evaluate(async () => { const c = document.createElement('canvas'); c.width = 640; c.height = 480; const g = c.getContext('2d'); g.fillStyle = '#F4501E'; g.fillRect(0, 0, 640, 480); g.fillStyle = '#fff'; g.font = '48px sans-serif'; g.fillText('Porte 2B', 200, 250); const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9)); return Array.from(new Uint8Array(await blob.arrayBuffer())); });
    await c1.locator('input[data-arrive]').setInputFiles({ name: 'porte.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg) });
    await page.waitForFunction(() => /Photo prise/.test(document.body.innerText), null, { timeout: 20000 });
    ok(/Arrivé \d\d:\d\d/.test(await c1.innerText()) && /Photo prise/.test(await c1.innerText()), '3b arrivée + photo enregistrées (« Arrivé hh:mm », « Photo prise »)');
    await c1.locator('button[data-deliver]').click();
    await page.waitForFunction(() => /Remise terminée/.test(document.body.innerText));
    ok(/Remise terminée/.test(await c1.innerText()) && /Espèces 27,50 €/.test(N(await c1.innerText())), '3c remise en espèces seules : « Remise terminée » + « Espèces 27,50 € »');

    // ── 4. Carte seule, titre-restaurant seul : la feuille de règlement ────────────────────────────────────────
    const c2 = card(page, 'CARTE');
    await c2.locator('[data-pay-open]').click();
    await page.waitForSelector('#pay-sheet:not(.hidden)');
    ok(/Régler la commande/.test(await page.textContent('#pay-title')) && /27,50/.test(await page.textContent('#pay-total')), '4a la feuille annonce la commande et son TOTAL (27,50 €, non modifiable)');
    ok((await page.locator('#pay-total').evaluate((e) => e.tagName)) === 'B' && (await page.locator('#pay-sheet input').first().isHidden()), '4b le total n\'est pas un champ de saisie');
    ok((await page.getAttribute('[data-pay-mode=card]', 'aria-pressed')) === 'true', '4c le choix du client (carte) est présélectionné');
    for (const m of ['cash', 'card', 'meal_voucher', 'split']) ok((await page.locator(`[data-pay-mode=${m}]`).count()) === 1, `4d bouton « ${m} » présent`);
    ok(/Espèces|Carte|Titre-restaurant|En plusieurs fois/.test(await page.locator('.modes4').innerText()) && !/split|meal_voucher/.test(await page.locator('#pay-sheet').innerText()), '4e libellés français, aucun mot technique à l\'écran');
    await shot(page, '04-feuille-carte');
    await page.click('#pay-ok');
    await page.waitForSelector('#pay-sheet.hidden', { state: 'attached' });
    await page.waitForFunction(() => /Carte 27,50 €/.test(document.body.innerText));
    ok(/Remise terminée/.test(await c2.innerText()) && /Carte 27,50 €/.test(N(await c2.innerText())), '4f carte seule : « Remise terminée » + « Carte 27,50 € »');

    const c3 = card(page, 'TITRE');
    await c3.locator('[data-pay-open]').click();
    await page.waitForSelector('#pay-sheet:not(.hidden)');
    ok((await page.getAttribute('[data-pay-mode=meal_voucher]', 'aria-pressed')) === 'true', '4g titre-restaurant : présélectionné (choix du client)');
    await page.click('#pay-ok');
    await page.waitForFunction(() => /Titre-restaurant 27,50 €/.test(document.body.innerText));
    ok(/Titre-restaurant 27,50 €/.test(N(await c3.innerText())), '4h titre-restaurant seul : « Titre-restaurant 27,50 € »');

    // ── 5. « En plusieurs fois » : 3 moyens, reste à encaisser, bouton grisé tant que la somme ≠ total ───────
    const c4 = card(page, 'MIXTE');
    await c4.locator('[data-pay-open]').click();
    await page.waitForSelector('#pay-sheet:not(.hidden)');
    await page.click('[data-pay-mode=split]');
    ok(await page.locator('#pay-ok').isDisabled(), '5a « En plusieurs fois » : Valider est grisé tant que rien n\'est saisi');
    ok(/Reste à encaisser : 27,50 €/.test(N(await page.textContent('#pay-rest'))), '5b « Reste à encaisser : 27,50 € » au départ');
    await page.fill('#amt-cash', '30');
    ok(/Trop perçu : 2,50 €/.test(N(await page.textContent('#pay-rest'))) && (await page.locator('#pay-ok').isDisabled()), '5c 30 € d\'espèces : « Trop perçu : 2,50 € », Valider reste grisé');
    await page.fill('#amt-cash', '10');
    ok(/Reste à encaisser : 17,50 €/.test(N(await page.textContent('#pay-rest'))), '5d 10 € d\'espèces : « Reste à encaisser : 17,50 € » (mise à jour en direct)');
    await page.fill('#amt-card', '10');
    ok(/Reste à encaisser : 7,50 €/.test(N(await page.textContent('#pay-rest'))) && (await page.locator('#pay-ok').isDisabled()), '5e + 10 € de carte : « Reste à encaisser : 7,50 € », Valider grisé');
    await page.fill('#amt-meal', '7,50');
    ok(/Reste à encaisser : 0,00 € — c'est le compte/.test(N(await page.textContent('#pay-rest'))) && !(await page.locator('#pay-ok').isDisabled()), '5f + 7,50 € de titre-restaurant (virgule française) : le compte est bon, Valider actif');
    await page.fill('#amt-meal', '7,5x');
    ok(/illisible/.test(await page.textContent('#pay-rest')) && (await page.locator('#pay-ok').isDisabled()), '5g un montant illisible est signalé, Valider grisé');
    await page.fill('#amt-meal', '');
    await page.click('[data-fill=meal_voucher]');
    ok((await page.inputValue('#amt-meal')) === '7,50', '5h le bouton « Reste » complète le titre-restaurant à 7,50');
    await shot(page, '05-feuille-plusieurs-fois');
    await page.click('#pay-ok');
    await page.waitForFunction(() => /Espèces 10,00 € · Carte 10,00 € · Titre-restaurant 7,50 €/.test(document.body.innerText.replace(/\s+/g, ' ')));
    ok(/Remise terminée/.test(await c4.innerText()), '5i remise en 3 moyens : « Espèces 10,00 € · Carte 10,00 € · Titre-restaurant 7,50 € »');
    await shotFull(page, '06-tournee-apres-remises');

    // ── 6. Ma caisse ─────────────────────────────────────────────────────────────────────────────────────────
    await page.click('#tab-cash');
    await page.waitForSelector('[data-testid=caisse-fond]');
    const t6 = N(await text(page));
    ok(/40,00 €/.test(await page.textContent('[data-testid=caisse-fond]')), '6a « Fond de début » = 40,00 € (remis par l\'administration)');
    ok(/37,50 €/.test(await page.textContent('[data-testid=caisse-especes]')), '6b espèces encaissées 27,50 + 10 = 37,50 €');
    ok(/37,50 €/.test(await page.textContent('[data-testid=caisse-carte]')), '6c carte 27,50 + 10 = 37,50 €');
    ok(/35,00 €/.test(await page.textContent('[data-testid=caisse-titres]')), '6d titres-restaurant 27,50 + 7,50 = 35,00 €');
    ok(/110,00 €/.test(await page.textContent('[data-testid=caisse-total]')) && (await page.textContent('[data-testid=caisse-nb]')).trim() === '4', '6e total encaissé 110,00 € ; 4 livraisons réglées');
    ok(/77,50 €/.test(await page.textContent('[data-testid=caisse-attendu]')), '6f espèces attendues dans la sacoche = fond 40 + espèces 37,50 = 77,50 €');
    ok(/Espèces 10,00 € · Carte 10,00 € · Titre-restaurant 7,50 €/.test(t6), '6g la liste des commandes donne la répartition de chacune');
    ok(!/undefined|NaN|\[object|label\./.test(t6), '6h aucun texte brut (undefined, NaN, clé de traduction)');
    await shotFull(page, '07-ma-caisse');

    // ── 7. Isolement : le livreur B ne voit rien de la caisse de A ───────────────────────────────────────────
    {
      const cb = await browser.newContext(phone);
      const pb = await cb.newPage();
      pb.on('pageerror', (e) => pageErrors.push(String(e)));
      await pb.goto(base + '/livreur');
      await pb.fill('#email', 'livreur.b@e2e.test'); await pb.fill('#password', 'password'); await pb.click('#login-btn');
      await pb.waitForSelector('#app:not(.hidden)');
      await pb.click('#tab-cash');
      await pb.waitForSelector('[data-testid=caisse-aucun]');
      const tb = N(await text(pb));
      ok(/Aucun service ouvert/.test(tb) && !/40,00|110,00|77,50/.test(tb), '7a le livreur B voit « Aucun service ouvert » : rien de la caisse de A');
      const closeB = await api(pb, 'POST', '/api/frontend/delivery-run/cash/close', { counted_cash: 0, session_id: seed.shift_id });
      ok(closeB.status === 404, '7b B ne peut pas clôturer le service de A (404, aucune fuite)');
      await shot(pb, '08-livreur-b-aucun-service');
      await cb.close();
    }

    // ── 8. Voie QR de remise : règlement en plusieurs fois ───────────────────────────────────────────────────
    {
      const p2 = await ctx.newPage();
      p2.on('pageerror', (e) => pageErrors.push(String(e)));
      await p2.goto(seed.stops.qr.qr_url);
      await p2.waitForSelector('[data-testid=connexion]');
      await p2.fill('#email', 'livreur.a@e2e.test'); await p2.fill('#password', 'password'); await p2.click('#go');
      await p2.waitForSelector('[data-testid=arret]');
      ok((await p2.locator('[data-mode=split]').count()) === 1 && /En plusieurs fois/.test(await p2.locator('.modes').innerText()), '8a page QR : le bouton « En plusieurs fois » est proposé');
      await p2.click('[data-mode=split]');
      await p2.waitForSelector('[data-testid=split]');
      ok(await p2.locator('#remis').isDisabled() && /Reste à encaisser : 27,50 €/.test(N(await p2.textContent('#rest'))), '8b QR : « Reste à encaisser : 27,50 € », « Remis » grisé');
      await p2.fill('#amt-cash', '5');
      ok(/Reste à encaisser : 22,50 €/.test(N(await p2.textContent('#rest'))), '8c QR : 5 € d\'espèces → reste 22,50 €');
      await p2.fill('#amt-card', '20');
      ok(/Reste à encaisser : 2,50 €/.test(N(await p2.textContent('#rest'))) && (await p2.locator('#remis').isDisabled()), '8d QR : + 20 € de carte → reste 2,50 €, « Remis » grisé');
      await p2.click('[data-fill=card]');
      ok((await p2.inputValue('#amt-card')) === '22,50' && !(await p2.locator('#remis').isDisabled()), '8e QR : « Reste » met la carte à 22,50 € → le compte est bon, « Remis » actif');
      await shot(p2, '09-qr-plusieurs-fois');
      await p2.click('#remis');
      await p2.waitForSelector('[data-testid=remise-ok]');
      const done = N(await p2.textContent('[data-testid=ecart-mode]'));
      ok(/Réglé en plusieurs fois : espèces 5,00 €, carte 22,50 €\. C'est enregistré\./.test(done), '8f QR : « ' + done + ' »');
      ok(!/split/.test(await text(p2)), '8g QR : le mot technique « split » n\'apparaît jamais');
      await shot(p2, '10-qr-remis');
      await p2.waitForURL('**/livreur', { timeout: 15000 });
      ok(new URL(p2.url()).pathname === '/livreur', '8h QR : retour automatique à /livreur');
      await p2.close();
    }

    // ── 9. Garde-fous serveur ────────────────────────────────────────────────────────────────────────────────
    const bad = await api(page, 'POST', `/api/frontend/delivery-run/stops/${seed.stops.guard.stop_id}/deliver`, { payments: [{ mode: 'cash', amount: 10 }, { mode: 'card', amount: 10 }] });
    ok(bad.status === 422 && /doit être exactement le total/.test(bad.body.message || ''), '9a somme fausse envoyée à la main : 422 « ' + bad.body.message + ' »');
    const dup = await api(page, 'POST', `/api/frontend/delivery-run/stops/${seed.stops.guard.stop_id}/deliver`, { payments: [{ mode: 'cash', amount: 10 }, { mode: 'cash', amount: 17.5 }] });
    ok(dup.status === 422 && /indiqué deux fois/.test(dup.body.message || ''), '9b moyen en double : 422 « ' + dup.body.message + ' »');
    const zero = await api(page, 'POST', `/api/frontend/delivery-run/stops/${seed.stops.guard.stop_id}/deliver`, { payments: [{ mode: 'cash', amount: 0 }, { mode: 'card', amount: 27.5 }] });
    ok(zero.status === 422, '9c montant nul : 422 « ' + zero.body.message + ' »');
    const g0 = dbJson(env, `$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${seed.stops.guard.order_id}); echo json_encode(['status'=>$o->status,'pay'=>$o->payment_status,'rows'=>\\App\\Models\\OrderPayment::withoutGlobalScopes()->where('order_id',$o->id)->count(),'fiscal'=>$o->fiscal_sequence_no]);`);
    ok(g0.status === 10 && g0.pay === 10 && g0.rows === 0 && g0.fiscal === null, '9d après 3 refus : rien d\'écrit (en route, non payée, 0 paiement, pas de n° fiscal)');

    // ── 10. Clôture refusée tant qu'il reste des livraisons en route à encaisser ────────────────────────────
    await page.reload();
    await page.waitForSelector('.stop');
    await page.click('#tab-cash');
    await page.waitForSelector('#cash-counted');
    const t10 = N(await text(page));
    ok(/Il reste 3 livraisons en route à encaisser/.test(t10), '10a Ma caisse : « Il reste 3 livraisons en route à encaisser … »');
    await page.fill('#cash-counted', '100');
    ok(await page.locator('#cash-close-open').isDisabled(), '10b le bouton « Clôturer ma caisse » reste grisé');
    const early = await api(page, 'POST', '/api/frontend/delivery-run/cash/close', { counted_cash: 100, session_id: seed.shift_id });
    ok(early.status === 422 && /Il reste 3 livraisons en route à encaisser/.test(early.body.message || ''), '10c le serveur refuse aussi (422) : « ' + early.body.message + ' »');
    await shotFull(page, '11-cloture-refusee-en-route');

    // ── 11. Réseau coupé pendant un règlement ───────────────────────────────────────────────────────────────
    await page.click('#tab-liv');
    const cn = card(page, 'RESEAU');
    await cn.locator('[data-pay-open]').click();
    await page.waitForSelector('#pay-sheet:not(.hidden)');
    await ctx.setOffline(true);
    await page.click('#pay-ok');
    await page.waitForFunction(() => document.getElementById('pay-err').textContent.length > 3);
    const offMsg = await page.textContent('#pay-err');
    ok(/Pas de réseau/.test(offMsg) && /rien ne sera encaissé deux fois/.test(offMsg) && !/Failed to fetch/.test(offMsg), '11a hors ligne : « ' + offMsg.trim() + ' »');
    ok(await page.locator('#pay-sheet').isVisible() && !(await page.locator('#pay-ok').isDisabled()), '11b la feuille reste ouverte, Valider redevient actif (jamais d\'écran blanc)');
    await shot(page, '12-hors-ligne');
    await ctx.setOffline(false);
    await page.click('#pay-ok');
    await page.waitForFunction(() => [...document.querySelectorAll('.stop')].some((s) => /Rue RESEAU/.test(s.innerText) && /Remise terminée/.test(s.innerText)));
    const rn = dbJson(env, `echo json_encode(\\App\\Models\\OrderPayment::withoutGlobalScopes()->where('order_id',${seed.stops.net.order_id})->count());`);
    ok(rn === 1, '11c le réseau revenu, la même demande aboutit : UNE seule ligne de paiement (' + rn + ')');

    // ── 12. Les deux dernières livraisons, en espèces seules ────────────────────────────────────────────────
    for (const ref of ['DERNIERE', 'GARDE']) {
      const c = card(page, ref);
      await c.locator('[data-pay-open]').click();
      await page.waitForSelector('#pay-sheet:not(.hidden)');
      await page.click('[data-pay-mode=cash]');
      await page.click('#pay-ok');
      await page.waitForFunction((r) => [...document.querySelectorAll('.stop')].some((s) => s.innerText.includes('Rue ' + r) && /Remise terminée/.test(s.innerText)), ref);
    }
    ok(true, '12 DERNIERE et GARDE remises en espèces seules par la feuille');

    // ── 13. Ma caisse complète, clôture avec écart ──────────────────────────────────────────────────────────
    await page.click('#tab-cash');
    await page.waitForFunction(() => { const e = document.querySelector('[data-testid=caisse-attendu]'); return e && /137,50 €/.test(e.textContent); });
    proofs.push(['   ', 'tuiles lues : ' + N(await page.textContent('[data-testid=caisse-especes]')) + ' | ' + N(await page.textContent('[data-testid=caisse-carte]')) + ' | ' + N(await page.textContent('[data-testid=caisse-titres]'))]);
    ok(/97,50 €/.test(await page.textContent('[data-testid=caisse-especes]')) && /87,50 €/.test(await page.textContent('[data-testid=caisse-carte]')) && /35,00 €/.test(await page.textContent('[data-testid=caisse-titres]')), '13a espèces 97,50 € · carte 87,50 € · titres 35,00 €');
    ok(/220,00 €/.test(await page.textContent('[data-testid=caisse-total]')) && (await page.textContent('[data-testid=caisse-nb]')).trim() === '8', '13b total 220,00 € ; 8 livraisons réglées');
    ok(/137,50 €/.test(await page.textContent('[data-testid=caisse-attendu]')), '13c espèces attendues = 40,00 + 97,50 = 137,50 €');
    await shotFull(page, '13-ma-caisse-complete');
    await page.fill('#cash-counted', '135');
    await page.click('#cash-close-open');
    await page.waitForSelector('#close-sheet:not(.hidden)');
    const cs = N(await page.locator('#close-sum').innerText());
    ok(/Fond de début 40,00 €/.test(cs) && /Espèces attendues 137,50 €/.test(cs) && /Espèces comptées 135,00 €/.test(cs) && /Écart −2,50 €/.test(cs) && /Il manque 2,50 € dans la sacoche/.test(cs), '13d feuille de clôture : fond 40, attendues 137,50, comptées 135, écart −2,50 « Il manque 2,50 € »');
    await shot(page, '14-cloture-ecart');
    await page.click('#close-ok');
    await page.waitForSelector('[data-testid=caisse-cloture]');
    const fin = N(await text(page));
    ok(/Aucun service ouvert/.test(fin) && /Votre caisse vient d'être clôturée/.test(fin), '13e après clôture : « Aucun service ouvert » + « Votre caisse vient d\'être clôturée »');
    ok(/Fond de début 40,00 €/.test(fin) && /Espèces comptées 135,00 €/.test(fin) && /Écart −2,50 €/.test(fin) && /Il manque 2,50 € dans la sacoche/.test(fin), '13f récap final : fond 40, comptées 135, écart −2,50');
    ok(/Espèces\s*97,50 €/.test(fin) && /Carte\s*87,50 €/.test(fin) && /Titres-resto\s*35,00 €/.test(fin), '13g récap final : par moyen');
    await shotFull(page, '15-cloture-recap-final');
    // Rejeu : un second appel de clôture ne change rien (idempotent).
    const again = await api(page, 'POST', '/api/frontend/delivery-run/cash/close', { counted_cash: 999, session_id: seed.shift_id });
    ok(again.status === 200 && again.body.already === true && Number(again.body.data.counted_cash) === 135, '13h clôture rejouée : « already », comptées toujours 135 (999 ignoré)');
    await page.reload();
    await page.waitForSelector('.stop');
    await page.click('#tab-cash');
    await page.waitForSelector('[data-testid=caisse-aucun]');
    ok(/Dernier service/.test(await text(page)) && /−2,50 €/.test(N(await text(page))), '13i après rechargement : « Aucun service ouvert » + « Dernier service » avec l\'écart');
    await shotFull(page, '16-apres-rechargement');

    // ── 14. Ce que la base a réellement enregistré ───────────────────────────────────────────────────────────
    const db = dbJson(env, `
      $o=\\App\\Models\\Order::withoutGlobalScopes()->get();
      $p=\\App\\Models\\OrderPayment::withoutGlobalScopes()->where('reference','livraison')->get();
      $m=\\App\\Models\\DeliveryBoyCashMovement::withoutGlobalScopes()->where('delivery_boy_cash_session_id',${seed.shift_id})->get();
      $fis=$o->pluck('fiscal_sequence_no')->filter()->values();
      echo json_encode(['orders'=>$o->count(),'delivered'=>$o->where('status',13)->count(),'paid'=>$o->where('payment_status',5)->count(),'fiscal'=>$fis->count(),'fiscal_unique'=>$fis->unique()->count(),
        'rows'=>$p->count(),'rows_cash'=>(float)$p->where('mode',1)->sum('amount'),'rows_card'=>(float)$p->where('mode',2)->sum('amount'),'rows_meal'=>(float)$p->where('mode',5)->sum('amount'),
        'mv'=>$m->where('type','order_collect')->count(),'mv_sum'=>(float)$m->where('type','order_collect')->sum('amount'),
        'shift'=>\\App\\Models\\DeliveryBoyCashSession::withoutGlobalScopes()->find(${seed.shift_id})->only(['status','opening_amount','closing_amount']),
        'sessions'=>\\App\\Models\\DeliveryBoyCashSession::withoutGlobalScopes()->count(),
        'chain'=>app(\\App\\Services\\Fiscal\\AuditLogService::class)->verifyChain(${seed.branch_id}),
        'tranche_audits'=>\\App\\Models\\AuditLog::where('action','order.payment_tranche_persisted')->count(),'escrow_audits'=>\\App\\Models\\AuditLog::where('action','delivery.cash_collected_escrow')->count()]);`);
    ok(db.delivered === 8 && db.paid === 8, '14a base : 8 commandes LIVRÉES et PAYÉES');
    ok(db.fiscal === 8 && db.fiscal_unique === 8, '14b base : 8 numéros fiscaux, tous distincts');
    ok(db.rows === 10 && Math.abs(db.rows_card - 87.5) < 0.001 && Math.abs(db.rows_meal - 35) < 0.001, '14c base : lignes de paiement « livraison » : 10 (carte 87,50 · titres 35,00 ; espèces seules historiques = sans ligne)');
    ok(db.mv === 5 && Math.abs(db.mv_sum - 97.5) < 0.001, '14d base : 5 mouvements de caisse livreur « encaissement » = 97,50 € (les espèces seulement)');
    ok(db.shift.status === 'closed' && Number(db.shift.opening_amount) === 40 && Number(db.shift.closing_amount) === 135 && db.sessions === 1, '14e base : service clôturé, fond 40, comptées 135 ; un seul service pour la journée');
    ok(db.chain === null, '14f chaîne d\'audit NF525 intacte (verifyChain = null)');
    ok(db.tranche_audits === 6 && db.escrow_audits === 5, '14g audits : ' + db.tranche_audits + ' traces de tranche (carte/titre) ; ' + db.escrow_audits + ' traces d\'escrow (espèces)');

    // ── 15. Administration : la fiche du service porte le récap ──────────────────────────────────────────────
    {
      const pa = await ctx.newPage();
      await pa.goto(base + '/livreur');
      const login = await pa.evaluate(async ([k]) => { const r = await fetch('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-API-KEY': k }, body: JSON.stringify({ email: 'admin@e2e.test', password: 'password' }) }); return { status: r.status, body: await r.json().catch(() => ({})) }; }, [API_KEY]);
      const adm = await pa.evaluate(async ([k, tok, id]) => { const r = await fetch('/api/admin/delivery-boy/cash-sessions/' + id, { headers: { Accept: 'application/json', 'X-API-KEY': k, Authorization: 'Bearer ' + tok } }); return { status: r.status, body: await r.json().catch(() => ({})) }; }, [API_KEY, login.body.token, seed.shift_id]);
      const rc = adm.body.data && adm.body.data.recap;
      ok(adm.status === 200 && rc && rc.opening_amount === 40 && rc.by_mode.cash.amount === 97.5 && rc.by_mode.card.amount === 87.5 && rc.by_mode.meal_voucher.amount === 35 && rc.variance === -2.5, '15 administration : GET cash-sessions/{id} → fond 40, espèces 97,50, carte 87,50, titres 35,00, écart −2,50');
      await pa.close();
    }

    ok(pageErrors.length === 0, '16a aucune erreur JavaScript dans la page' + (pageErrors.length ? ' : ' + pageErrors.join(' | ') : ''));
    ok(consoleErrors.length === 0, '16b aucune erreur dans la console du navigateur' + (consoleErrors.length ? ' : ' + consoleErrors.join(' | ').slice(0, 300) : ''));
  } finally {
    await browser.close();
    server.kill('SIGTERM');
    try { fs.unlinkSync(DB); } catch (e) {}
    console.log(proofs.map((p) => p.join(' ')).join('\n'));
    console.log('captures :', OUT);
  }
})().catch((e) => { console.error(e.message); process.exit(1); });
