/* [T-F.3.1] PREUVE LIVE : le solde du modal redeem POS (surface canonique
   PosOrderShowComponent) se met à jour SANS reload quand un mouvement de
   points arrive d'ailleurs (event LoyaltyBalanceChanged → outbox → worker
   e2e redis db5 → soketi :6001 → Echo → modal). */
const path = require('path');
const { execSync } = require('child_process');
const { BASE, makePage, uiLogin } = require('../petits-systemes-2026-06-11/lib.cjs');
const OUT = __dirname;
const ORDER_ID = process.env.ORDER_ID;
const USER_ID = process.env.USER_ID;
const WT = path.resolve(__dirname, '../../..');

(async () => {
  const { browser, page, sink } = await makePage(process.env.E2E_TOKEN);
  await uiLogin(page);
  await page.goto(`${BASE}/admin/pos-orders/show/${ORDER_ID}`, { waitUntil: "networkidle", timeout: 45000 });
  await page.evaluate(() => document.querySelector("#app")?.__vue_app__?.config?.globalProperties?.$router?.isReady());
  await page.waitForTimeout(3000);
  await page.waitForTimeout(1500);

  // Étape 1 — ouvrir le modal redeem (surface canonique) — robuste à la
  // saturation du serve mono-process (reload + ré-attente si besoin)
  let cta = page.locator('[data-testid=pos-loyalty-redeem-open]');
  for (let i = 0; i < 3 && !(await cta.count()); i++) {
    await page.reload({ waitUntil: 'networkidle' });
    await page.evaluate(() => document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$router?.isReady());
    await page.waitForTimeout(4000);
  }
  await cta.first().click();
  await page.waitForTimeout(800);

  // Étape 2 — redeem réel 100 pts (code LIVE2TAB1) → balance_after affiché
  await page.locator('[data-testid=pos-loyalty-redeem-code-input]').fill('LIVE2TAB1');
  await page.locator('[data-testid=pos-loyalty-redeem-points-input]').fill('100');
  await page.locator('[data-testid=pos-loyalty-redeem-apply]').click();
  await page.waitForSelector('[data-testid=pos-loyalty-redeem-balance]', { timeout: 45000 });
  const before = (await page.locator('[data-testid=pos-loyalty-redeem-balance]').innerText()).trim();
  await page.screenshot({ path: path.join(OUT, 'live2tab-A-after-redeem.png') });
  console.log('STEP2 balance after redeem =', before, '(attendu 300 pts)');

  // Étape 3 — mouvement de points DEPUIS UNE AUTRE SURFACE (ledger réel + event canonique)
  execSync(
    `APP_ENV=e2e php artisan tinker --execute="` +
    `\\$u=\\App\\Models\\User::find(${USER_ID}); \\$nb=\\$u->loyalty_points+50; ` +
    `\\App\\Models\\LoyaltyTransaction::create(['user_id'=>\\$u->id,'loyalty_code'=>\\$u->loyalty_code,'type'=>'earn','points'=>50,'balance_after'=>\\$nb,'source_surface'=>'kiosk','description'=>'Earn live preuve 2-onglets']); ` +
    `\\$u->loyalty_points=\\$nb; \\$u->save(); ` +
    `\\App\\Events\\LoyaltyBalanceChanged::dispatch((int)\\$u->id,1,(int)\\$nb,50,'earn'); echo 'dispatched '.\\$nb;"`,
    { cwd: WT, stdio: 'pipe', shell: '/bin/zsh' },
  );
  console.log('STEP3 mouvement +50 dispatché (autre surface)');

  // Étape 4 — le modal DOIT refléter 350 pts SANS reload
  let after = before;
  for (let i = 0; i < 60; i++) {
    await page.waitForTimeout(500);
    after = (await page.locator('[data-testid=pos-loyalty-redeem-balance]').innerText()).trim();
    if (after !== before) break;
  }
  await page.screenshot({ path: path.join(OUT, 'live2tab-B-after-push.png') });
  console.log('STEP4 balance live =', after, '(attendu 350 pts, sans reload)');
  console.log('console/network errors:', JSON.stringify(sink.console.slice(0, 5)), JSON.stringify(sink.http.slice(0, 5)));
  console.log(after.startsWith('350') ? 'LIVE-PROOF: PASS' : 'LIVE-PROOF: FAIL');
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
