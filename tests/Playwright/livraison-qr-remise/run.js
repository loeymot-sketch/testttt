// [LIVRAISON QR 2026-11-01] Test NAVIGATEUR réel de la remise par QR — serveur PHP local + base SQLITE JETABLE (qr-remise-e2e).
// Jamais la base locale ni la production. Lancer (Node 20+, playwright de l'arbre principal) :
//   NODE_PATH=<testttt>/node_modules node tests/Playwright/livraison-qr-remise/run.js [dossier-captures]
// Sort avec un code ≠ 0 au premier écart ; imprime un tableau de preuves.
const { chromium } = require('playwright');
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const net = require('net');

const ROOT = path.resolve(__dirname, '../../..');
const OUT = process.argv[2] || fs.mkdtempSync(path.join(os.tmpdir(), 'qr-remise-captures-'));
fs.mkdirSync(OUT, { recursive: true });
const DB = path.join(os.tmpdir(), `qr-remise-e2e-${process.pid}.sqlite`);
const API_KEY = 'e2e-api-key';
const proofs = [];
const ok = (cond, label) => { proofs.push([cond ? 'OK ' : 'KO ', label]); if (!cond) throw new Error('ÉCHEC : ' + label); };

function freePort() { return new Promise((res) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); }); }

function envFor(port) {
  // Mêmes valeurs que phpunit.xml pour les secrets fiscaux et la clé ; tout le reste isolé (sqlite jetable, file/sync).
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
const php = (env, args) => execFileSync('php', args, { cwd: ROOT, env, encoding: 'utf8', timeout: 300_000 });
const dbQuery = (env, code) => php(env, ['artisan', 'tinker', '--execute', code]).trim().split('\n').filter(Boolean).pop();

(async () => {
  const port = await freePort();
  const env = envFor(port);
  fs.writeFileSync(DB, '');
  php(env, ['artisan', 'migrate:fresh', '--force']);
  const seed = JSON.parse(php(env, ['tests/Playwright/livraison-qr-remise/seed.php']).trim().split('\n').pop());
  const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], { cwd: ROOT, env, stdio: 'ignore' });
  const base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 60; i++) { try { if ((await fetch(base + '/livreur/remise')).ok) break; } catch (e) {} await new Promise((r) => setTimeout(r, 500)); }

  const browser = await chromium.launch();
  const phone = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'fr-FR', geolocation: { latitude: 50.4301, longitude: 2.9601, accuracy: 15 }, permissions: ['geolocation'] };
  const shot = (page, name) => page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: true });
  const text = (page) => page.evaluate(() => document.body.innerText);
  try {
    // ── A. Parcours nominal ─────────────────────────────────────────────────────────────────────────────────────
    {
      const ctx = await browser.newContext(phone);
      const page = await ctx.newPage();
      const reqs = [];
      page.on('request', (r) => reqs.push({ url: r.url(), auth: r.headers()['authorization'] || '', body: r.postData() || '', ref: r.headers()['referer'] || '' }));
      const pageErrors = []; page.on('pageerror', (e) => pageErrors.push(String(e)));
      await page.addInitScript(() => { try { localStorage.setItem('lc_livreur_token', 'SESSION-LIVREUR-INTACTE'); } catch (e) {} });
      await page.goto(seed.happy.qr_url);
      await page.waitForSelector('[data-testid=connexion]');
      ok(await page.evaluate(() => location.hash === ''), 'A1 le fragment (jeton) est effacé de la barre d\'adresse dès l\'arrivée');
      ok(!(await text(page)).match(/Rue Test|Karim|0612345678/), 'A2 avant connexion la page ne montre ni adresse, ni nom, ni téléphone');
      ok(!reqs.some((r) => r.url.includes(seed.happy.qr_token)), 'A3 aucune requête réseau ne porte le jeton dans son URL');
      await shot(page, 'A1-connexion');
      await page.fill('#email', 'livreur.a@e2e.test');
      await page.fill('#password', 'password');
      await page.click('#go');
      await page.waitForSelector('[data-testid=arret]');
      const t = await text(page);
      ok(/10 Rue Test HAPPY/.test(t) && /2B/.test(t), 'A4 après connexion : l\'adresse du livreur attribué s\'affiche');
      ok((await page.locator('a[href^="https://waze.com/ul?"]').count()) === 1, 'A5 lien Waze présent');
      ok(/27,50/.test(await page.textContent('[data-testid=du]')), 'A6 le montant à encaisser est affiché (27,50 €)');
      ok(!(await page.locator('#remis').isDisabled()) && (await page.getAttribute('[data-mode=cash]', 'aria-pressed')) === 'true', 'A7 le choix du client (espèces) est présélectionné, « Remis » actif');
      await shot(page, 'A2-arret');
      await page.click('[data-mode=card]');
      ok((await page.getAttribute('[data-mode=card]', 'aria-pressed')) === 'true', 'A8 le livreur déclare « Carte » (le client avait choisi espèces)');
      await shot(page, 'A3-mode-carte');
      await page.click('#remis');
      await page.waitForSelector('[data-testid=remise-ok]');
      ok(/Remis à \d\d:\d\d/.test(await text(page)), 'A9 écran « Remis à HH:MM »');
      await shot(page, 'A4-remis');
      const conf = reqs.find((r) => r.url.endsWith('/delivery-handover/confirm'));
      ok(!!conf && JSON.parse(conf.body).lat === 50.4301, 'A10 la position est envoyée quand elle est accordée');
      ok(reqs.every((r) => !r.auth.includes('SESSION-LIVREUR-INTACTE')), 'A11 la session /livreur du navigateur n\'est jamais utilisée');
      await page.waitForURL('**/livreur', { timeout: 6000 });
      ok(new URL(page.url()).pathname === '/livreur', 'A12 retour automatique à /livreur');
      ok((await page.evaluate(() => localStorage.getItem('lc_livreur_token'))) === 'SESSION-LIVREUR-INTACTE', 'A13 la session /livreur du livreur est conservée');
      ok(pageErrors.length === 0, 'A14 aucune erreur JavaScript');
      const o = JSON.parse(dbQuery(env, `$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${seed.happy.order_id}); $t=\\App\\Models\\DeliveryHandoverToken::withoutGlobalScopes()->first(); echo json_encode(['status'=>$o->status,'pay'=>$o->payment_status,'pos'=>$o->pos_payment_method,'fiscal'=>$o->fiscal_sequence_no,'used'=>(bool)$t->used_at,'declared'=>$t->declared_mode,'chosen'=>$t->chosen_mode,'mismatch'=>(bool)$t->mode_mismatch,'lat'=>$t->lat]);`));
      ok(o.status === 13 && o.pay === 5 && o.pos === 2 && o.fiscal > 0 && o.used && o.declared === 'card' && o.chosen === 'cash' && o.mismatch, 'A15 base : livrée, payée, rangée en CARTE, n° fiscal, jeton brûlé, écart tracé');
      await ctx.close();
    }
    // ── B. Le QR utilisé ne sert plus ───────────────────────────────────────────────────────────────────────────
    {
      const ctx = await browser.newContext(phone);
      const page = await ctx.newPage();
      await page.goto(seed.happy.qr_url);
      await page.fill('#email', 'livreur.b@e2e.test'); await page.fill('#password', 'password'); await page.click('#go');
      await page.waitForSelector('[data-testid=refus]');
      ok(!(await text(page)).match(/Rue Test|Karim|0612345678|27,50/), 'B1 un AUTRE livreur scannant un QR utilisé : refus, rien révélé');
      await shot(page, 'B1-refus-autre-livreur');
      await ctx.close();
    }
    // ── C. Session périmée dans le navigateur : on redemande TOUJOURS le mot de passe ───────────────────────────
    {
      const ctx = await browser.newContext(phone);
      const page = await ctx.newPage();
      const reqs = [];
      page.on('request', (r) => reqs.push(r.headers()['authorization'] || ''));
      await page.addInitScript((tok) => { try { localStorage.setItem('lc_livreur_token', tok); sessionStorage.setItem('lc_livreur_token', tok); document.cookie = 'lc_livreur_token=' + tok; } catch (e) {} }, seed.stale_session_token);
      await page.goto(seed.guarded.qr_url);
      await page.waitForSelector('[data-testid=connexion]');
      ok(await page.locator('#email').isVisible() && await page.locator('#password').isVisible(), 'C1 avec une session livreur VALIDE en mémoire du navigateur, e-mail + mot de passe sont demandés');
      await page.waitForTimeout(600);
      ok(reqs.every((a) => !a.includes(seed.stale_session_token.split('|')[1] || 'x')), 'C2 la session existante n\'est jamais envoyée au serveur');
      ok(!(await text(page)).match(/Rue Test|Karim/), 'C3 aucune donnée avant la connexion');
      // Identifiants d'un CLIENT : refusé, aucune donnée.
      await page.fill('#email', 'client@e2e.test'); await page.fill('#password', 'password'); await page.click('#go');
      await page.waitForSelector('#err:not(:empty)');
      ok(/n'est pas un compte livreur/.test(await page.textContent('#err')), 'C4 un compte client est refusé avec un message clair');
      ok(!(await text(page)).match(/Rue Test|Karim|0612345678/), 'C5 aucune donnée après le refus');
      await shot(page, 'C1-compte-client-refuse');
      await page.fill('#email', 'caisse@e2e.test'); await page.fill('#password', 'password'); await page.click('#go');
      await page.waitForFunction(() => /compte livreur/.test(document.getElementById('err')?.textContent || ''));
      ok(true, 'C6 un compte caisse est refusé aussi');
      await page.fill('#email', 'livreur.b@e2e.test'); await page.fill('#password', 'password'); await page.click('#go');
      await page.waitForSelector('[data-testid=refus]');
      ok(!(await text(page)).match(/Rue Test|Karim|0612345678/), 'C7 un livreur qui n\'est pas celui de l\'arrêt : refus générique, rien révélé');
      await shot(page, 'C2-refus-livreur-b');
      await ctx.close();
    }
    // ── D. Fragment hostile : ni XSS, ni redirection ouverte ─────────────────────────────────────────────────────
    {
      const ctx = await browser.newContext(phone);
      const page = await ctx.newPage();
      const dialogs = []; page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss(); });
      for (const frag of ['"><img src=x onerror="window.__pwn=1">', '<script>window.__pwn=1</script>', '//evil.example/x', 'javascript:window.__pwn=1', 'A'.repeat(43) + '&next=https://evil.example']) {
        await page.goto('about:blank');
        await page.goto(seed.happy.qr_url.split('#')[0] + '#' + frag);
        await page.waitForSelector('[data-testid=refus], [data-testid=connexion]');
        ok(!(await page.evaluate(() => window.__pwn)), `D XSS : fragment « ${frag.slice(0, 24)}… » n'exécute rien`);
        ok(new URL(page.url()).host === `127.0.0.1:${port}`, 'D le navigateur reste sur la plateforme (pas de redirection ouverte)');
      }
      ok(dialogs.length === 0, 'D aucune boîte de dialogue');
      await page.goto(seed.happy.qr_url.split('#')[0]);
      await page.waitForSelector('[data-testid=refus]');
      ok(/pas lisible/.test(await text(page)), 'D6 sans fragment : écran « QR non lisible », rien d\'autre');
      await shot(page, 'D1-sans-jeton');
      // Un jeton bien formé mais inconnu, après une vraie connexion : même refus générique.
      await page.goto('about:blank'); // un changement de fragment seul ne recharge pas la page
      await page.goto(seed.happy.qr_url.split('#')[0] + '#' + 'Z'.repeat(43));
      await page.fill('#email', 'livreur.a@e2e.test'); await page.fill('#password', 'password'); await page.click('#go');
      await page.waitForSelector('[data-testid=refus]');
      ok(!(await text(page)).match(/Rue Test|Karim/), 'D7 jeton inconnu : refus, rien révélé');
      await ctx.close();
    }
    // ── E. Encore un livreur valide ouvre l'arrêt gardé (le QR « guarded » est intact) puis on vérifie la base ────
    {
      const g = JSON.parse(dbQuery(env, `$o=\\App\\Models\\Order::withoutGlobalScopes()->find(${seed.guarded.order_id}); echo json_encode(['status'=>$o->status,'pay'=>$o->payment_status,'used'=>(bool)\\App\\Models\\DeliveryHandoverToken::withoutGlobalScopes()->where('order_id',${seed.guarded.order_id})->value('used_at')]);`));
      ok(g.status === 10 && g.pay === 10 && !g.used, 'E1 les refus n\'ont rien modifié : commande EN ROUTE, non payée, jeton intact');
      const chain = dbQuery(env, `echo var_export(app(\\App\\Services\\Fiscal\\AuditLogService::class)->verifyChain(${seed.branch_id}), true);`);
      ok(chain === 'NULL', 'E2 chaîne d\'audit NF525 intacte (verifyChain = null)');
    }
  } finally {
    await browser.close();
    server.kill('SIGTERM');
    try { fs.unlinkSync(DB); } catch (e) {}
    console.log(proofs.map((p) => p.join(' ')).join('\n'));
    console.log('captures :', OUT);
  }
})().catch((e) => { console.error(e); process.exit(1); });
