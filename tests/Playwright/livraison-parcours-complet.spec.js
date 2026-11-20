// [LIVRAISON 2026-10-20] TEST RÉEL de bout en bout, trois surfaces, une seule livraison :
//   1. SITE (par l'API, exactement comme le tunnel de commande) : adresse enregistrée + commande de livraison payable à la porte ;
//   2. CAISSE + CUISINE : la commande est acceptée et préparée, la caisse désigne le livreur dans « Tournées » (navigateur) ;
//   3. LIVREUR (/livreur, navigateur) : il part, arrive avec une VRAIE photo, remet et encaisse.
// Puis on relit la base : commande livrée et payée, horodatages, photo privée, chaîne NF525 intacte.
//
// Pré-requis : serveur LOCAL (PLAYWRIGHT_BASE_URL), comptes de test locaux (admin@/pos@lecayenne.fr), caisse OUVERTE, réseau
// ouvert (la Base Adresse Nationale est interrogée pour de vrai). Laisse un client « TEST-LIVRAISON … » et une commande
// FISCALISÉE dans la base LOCALE — ne jamais lancer contre la production.
//
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//     npx playwright test tests/Playwright/livraison-parcours-complet.spec.js --workers=1 --retries=0
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const ROOT = path.resolve(__dirname, '../..');
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const API_KEY = env('MIX_API_KEY') || env('API_KEY');
const COURIER = { email: 'livreur.test@lecayenne.local', password: 'Livreur-Test-2026', name: 'TEST-LIVRAISON Kiki' };

/** Exécute du PHP dans l'application (tinker) et renvoie la dernière ligne imprimée. */
function php(code) {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8', timeout: 60_000 });
  return out.trim().split('\n').filter(Boolean).pop();
}
const uuid = () => require('crypto').randomUUID();
const json = (r) => r.json();

test('livraison réelle : commande web → préparation → caisse désigne le livreur → remise avec photo', async ({ page, request }) => {
  test.setTimeout(240_000);

  // ── 0. Terrain : un client du site (jeton), un livreur de test, et un tableau de tournées propre ──────────────────
  const stamp = Date.now();
  const phone = '06' + String(stamp).slice(-8);
  const customerToken = php(`
    $u = \\App\\Models\\User::create(['name' => 'TEST-LIVRAISON Site ${stamp}', 'email' => 'test-livraison-${stamp}@example.test', 'phone' => '${phone}', 'country_code' => '+33', 'username' => 'tl${stamp}', 'password' => bcrypt('Test-Livraison-2026'), 'branch_id' => 0, 'status' => 5, 'is_guest' => 10, 'email_verified_at' => now()]);
    $u->assignRole('Customer');
    echo $u->createToken('auth_token', ['*'])->plainTextToken;`);
  expect(customerToken).toMatch(/^\d+\|/);
  php(`
    \\Spatie\\Permission\\Models\\Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
    $c = \\App\\Models\\User::withoutGlobalScopes()->where('email', '${COURIER.email}')->first()
      ?? \\App\\Models\\User::create(['name' => '${COURIER.name}', 'email' => '${COURIER.email}', 'phone' => '0600000001', 'country_code' => '+33', 'username' => 'tlkiki', 'password' => bcrypt('${COURIER.password}'), 'branch_id' => 1, 'status' => 5, 'is_guest' => 10]);
    $c->syncRoles([\\Spatie\\Permission\\Models\\Role::where('name', 'Delivery Boy')->where('guard_name', 'sanctum')->first()]);
    $c->forceFill(['password' => bcrypt('${COURIER.password}'), 'status' => 5])->save();
    \\App\\Models\\DeliveryStop::withoutGlobalScopes()->whereNull('left_at')->whereIn('order_id', \\App\\Models\\Order::withoutGlobalScopes()->whereIn('user_id', \\App\\Models\\User::withoutGlobalScopes()->where('name', 'like', 'TEST-LIVRAISON%')->select('id'))->select('id'))->delete();
    echo 'ok';`);

  const siteHeaders = { 'X-API-KEY': API_KEY, Authorization: `Bearer ${customerToken}`, Accept: 'application/json' };

  // ── 1. SITE : l'adresse (géocodée par le serveur) puis la commande, comme le tunnel ─────────────────────────────────
  const devis = await json(await request.post('/api/frontend/delivery/quote', { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { q: '437 rue elie gruyelle 62110 henin-beaumont' } }));
  expect(devis.allowed).toBe(true);
  expect(devis.fee).toBe(3.9);
  expect(devis.waves.length).toBeGreaterThan(0);

  const adresse = await json(await request.post('/api/frontend/address', { headers: siteHeaders, data: { label: 'Livraison ' + stamp.toString(36), address: devis.address.label, apartment: '2B' } }));
  const addressId = adresse.data.id;
  expect(addressId).toBeGreaterThan(0);

  const commande = await request.post('/api/frontend/order', {
    headers: { ...siteHeaders, 'X-Idempotency-Key': uuid() },
    data: {
      branch_id: 1, order_type: 5, source: 5, is_advance_order: 10, payment_method: 1, // 1 = payer à la porte (espèces)
      address_id: addressId, delivery_time: 'ASAP', delivery_distance_km: 0, delivery_charge: 0, // ignorés : le serveur chiffre
      items: JSON.stringify([{ item_id: 58, item_price: 1, quantity: 1, discount: 0, total_price: 1, item_variations: [], item_extras: [], instruction: '' }]),
    },
  });
  expect(commande.status(), await commande.text()).toBeLessThan(300);
  const orderId = (await json(commande)).data.id;
  const apres = php(`$o = \\App\\Models\\Order::withoutGlobalScopes()->find(${orderId}); $s = \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', ${orderId})->first(); echo json_encode(['charge' => (float) $o->delivery_charge, 'total' => (float) $o->total, 'tier' => $o->delivery_tier, 'serial' => $o->order_serial_no, 'stop' => (bool) $s, 'collect' => $s?->collect_method, 'due' => $s?->amount_due_cents, 'wave' => (string) $s?->wave_at]);`);
  const o = JSON.parse(apres);
  expect(o).toMatchObject({ charge: 3.9, total: 4.9, tier: 'home', stop: true, collect: 'cash', due: 490 });

  // ── 2. CUISINE : acceptée, en préparation, prête (compte admin, API comme l'écran) ───────────────────────────────
  const login = await json(await request.post('/api/auth/login', { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' }, data: { email: 'admin@lecayenne.fr', password: '123456' } }));
  const admin = { 'X-API-KEY': API_KEY, Authorization: `Bearer ${login.token}`, Accept: 'application/json' };
  for (const status of [4, 7, 8]) { // ACCEPT → PREPARING → PREPARED
    const r = await request.post(`/api/admin/online-order/change-status/${orderId}`, { headers: { ...admin, 'X-Idempotency-Key': uuid() }, data: { status } });
    expect(r.status(), `statut ${status} : ${await r.text()}`).toBeLessThan(300);
  }

  // ── 3. CAISSE (navigateur) : « Tournées » → la commande est là → on désigne le livreur ───────────────────────────────
  await loginAsPosOperator(page);
  await page.goto('/admin/pos');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2500);
  const toggle = page.locator('[data-testid="pos-delivery-toggle"]');
  await expect(toggle).toBeVisible({ timeout: 15000 });
  if (/suspendue/.test(await toggle.innerText())) { await toggle.click(); await toggle.click(); await page.waitForTimeout(1500); }
  await page.locator('[data-testid="pos-delivery-board-open"]').click();
  const board = page.locator('[data-testid="pos-delivery-board"]');
  await expect(board).toContainText(o.serial, { timeout: 15000 });
  const vague = board.locator('section', { hasText: o.serial }).first();
  await expect(vague).toContainText('Espèces 4,90 €');
  await vague.locator('select').selectOption({ label: COURIER.name });
  await expect(vague).toContainText(`Livreur : ${COURIER.name}`, { timeout: 15000 });
  await page.screenshot({ path: path.join(ROOT, 'test-results', 'livraison-caisse-tournees.png') }).catch(() => {});

  // ── 4. LIVREUR (navigateur, /livreur) : il part, arrive avec une vraie photo, remet et encaisse ─────────────────────
  await page.goto('/livreur');
  await page.locator('#email').fill(COURIER.email);
  await page.locator('#password').fill(COURIER.password);
  await page.locator('#login-btn').click();
  const arret = page.locator('.stop', { hasText: o.serial });
  await expect(arret).toBeVisible({ timeout: 20000 });
  await expect(arret).toContainText('Encaisser en espèces : 4,90 €');
  await expect(arret).toContainText('437 Rue Elie Gruyelle');
  expect(await arret.locator('a:has-text("Waze")').getAttribute('href')).toContain('https://waze.com/ul?ll=');

  await page.locator('button[data-depart]').first().click();
  await expect(arret).toContainText('En route', { timeout: 15000 });

  // Vraie photo JPEG (canvas du navigateur), envoyée par le champ « Arrivé + photo ».
  const jpeg = await page.evaluate(async () => {
    const c = document.createElement('canvas'); c.width = 640; c.height = 480;
    const g = c.getContext('2d'); g.fillStyle = '#F4501E'; g.fillRect(0, 0, 640, 480); g.fillStyle = '#fff'; g.font = '48px sans-serif'; g.fillText('Porte 2B', 200, 250);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
    return Array.from(new Uint8Array(await blob.arrayBuffer()));
  });
  await arret.locator('input[data-arrive]').setInputFiles({ name: 'porte.jpg', mimeType: 'image/jpeg', buffer: Buffer.from(jpeg) });
  await expect(arret).toContainText('Arrivé', { timeout: 20000 });
  await expect(arret).toContainText('Photo prise');

  page.on('dialog', (d) => d.accept());
  await arret.locator('button[data-deliver]').click();
  await expect(arret).toContainText('Remise', { timeout: 20000 });
  await page.screenshot({ path: path.join(ROOT, 'test-results', 'livraison-livreur-remise.png') }).catch(() => {});

  // ── 5. LA BASE : ce qui s'est réellement passé ───────────────────────────────────────────────────────────────────
  const fin = JSON.parse(php(`
    $o = \\App\\Models\\Order::withoutGlobalScopes()->find(${orderId});
    $s = \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', ${orderId})->first();
    echo json_encode(['status' => (int) $o->status, 'paid' => (int) $o->payment_status, 'fiscal' => $o->fiscal_sequence_no, 'left' => (bool) $s->left_at, 'arrived' => (bool) $s->arrived_at, 'delivered' => (bool) $s->delivered_at,
      'collected' => (int) $s->amount_collected_cents, 'photo' => (bool) $s->proof_photo_path && \\Illuminate\\Support\\Facades\\Storage::disk('local')->exists($s->proof_photo_path),
      'ordre' => [$s->left_at <= $s->arrived_at, $s->arrived_at <= $s->delivered_at], 'courier' => (int) $s->delivery_boy_id === (int) $o->delivery_boy_id]);`));
  expect(fin).toMatchObject({ status: 13, paid: 5, left: true, arrived: true, delivered: true, collected: 490, photo: true, courier: true });
  expect(fin.ordre).toEqual([true, true]);
  expect(fin.fiscal, 'la remise espèces reçoit son numéro fiscal').not.toBeNull();

  // La photo n'est lisible que par le livreur concerné et la caisse : sans jeton, 401.
  const stopId = Number(php(`echo \\App\\Models\\DeliveryStop::withoutGlobalScopes()->where('order_id', ${orderId})->value('id');`));
  const sansJeton = await request.get(`/api/frontend/delivery-run/stops/${stopId}/photo`, { headers: { 'X-API-KEY': API_KEY, Accept: 'application/json' } });
  expect(sansJeton.status()).toBe(401);

  // La chaîne fiscale NF525 reste intacte après une remise réelle.
  const chaine = execFileSync('php', ['artisan', 'fiscal:verify-chain', '--all'], { cwd: ROOT, encoding: 'utf8', timeout: 120_000 });
  expect(chaine).toContain('CHAIN OK on every active branch');
});
