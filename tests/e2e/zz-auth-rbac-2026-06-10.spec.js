// FoodKing — W-E AUTH/RBAC parcours validation profonde (GOAL VALIDATION PROFONDE 2026-06-10)
// Disposable clone ONLY:
//   PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   AUTH_CYCLE=1 npx playwright test tests/e2e/zz-auth-rbac-2026-06-10.spec.js --retries=0
//
// E1 admin login OK / logout / re-login + wrong-password FR generique (user dedie
//    e2e-we-lock) + lockout 429 sur user dedie -> purge rate-limit apres.
// E2 /kiosk/login (auto-login screen, capture) + diagnostic F3 anti-enumeration sur
//    machine dediee e2e-we-kiosk (active+wrong / unknown / inactive+wrong / inactive+correct).
// E3 RBAC : e2e-we-rbac (POS Operator) UI -> /admin/administrators + /admin/settings/site
//    refus propre ? + API kiosk:order token -> GET /api/admin/pos-order DOIT 403 structure.
// E4 logout -> back button -> pas d'acces dashboard.
//
// JAMAIS de lockout sur admin@lecayenne.fr / kiosk-lecayenne — echecs UNIQUEMENT sur e2e-we-*.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '../..');
const SERVED_CHECKOUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/pre-cloud-exec';
const REPORT_DIR = path.join(REPO, 'reports/test-e2e/validation-profonde-2026-06-10/auth');
const CYCLE = process.env.AUTH_CYCLE || '1';
const CAPS = path.join(REPORT_DIR, 'captures', `cycle-${CYCLE}`);
fs.mkdirSync(CAPS, { recursive: true });

const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const ADMIN = { email: 'admin@lecayenne.fr', password: '123456' };
const LOCK_USER = { email: 'e2e-we-lock@test.fr', password: 'WeLock!2026' };
const RBAC_USER = { email: 'e2e-we-rbac@test.fr', password: 'WeRbac!2026' };
const KIOSK_MACHINE = { username: 'e2e-we-kiosk', password: 'WeKiosk!2026', id: 13 };
const KIOSK_TOKEN_FILE = '/tmp/e2e-we-kiosk-token.txt';

const ERR_LOG_FILE = path.join(REPORT_DIR, `errors-cycle${CYCLE}.jsonl`);
const FINDINGS_FILE = path.join(REPORT_DIR, `findings-cycle${CYCLE}.jsonl`);
const JOURNEY_FILE = path.join(REPORT_DIR, `journey-cycle${CYCLE}.jsonl`);
const EVIDENCE_FILE = path.join(REPORT_DIR, `api-evidence-cycle${CYCLE}.json`);
function appendJsonl(file, obj) { try { fs.appendFileSync(file, JSON.stringify(obj) + '\n'); } catch (_) {} }
const findings = { push: (f) => appendJsonl(FINDINGS_FILE, f) };
function mark(step, status, proof) { appendJsonl(JOURNEY_FILE, { step, status, proof }); }
const apiEvidence = {};
function saveEvidence(key, obj) {
  apiEvidence[key] = obj;
  fs.writeFileSync(EVIDENCE_FILE, JSON.stringify(apiEvidence, null, 2));
}

let currentStep = 'init';
function wirePage(page) {
  page.setDefaultTimeout(20000);
  page.on('console', (msg) => {
    if (msg.type() === 'error') appendJsonl(ERR_LOG_FILE, { step: currentStep, kind: 'console', detail: msg.text().slice(0, 400) });
  });
  page.on('pageerror', (e) => appendJsonl(ERR_LOG_FILE, { step: currentStep, kind: 'pageerror', detail: String(e.message).slice(0, 400) }));
  page.on('response', (r) => {
    if (r.status() >= 400) {
      appendJsonl(ERR_LOG_FILE, { step: currentStep, kind: `http-${r.status()}`, detail: `${r.request().method()} ${r.url()}`.slice(0, 300) });
    }
  });
}

async function snap(page, name) {
  await page.screenshot({ path: path.join(CAPS, `${name}.jpg`), type: 'jpeg', quality: 70, fullPage: false });
}

function sql(query) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', query], {
    encoding: 'utf8', timeout: 15000,
  }).trim();
}

function tinker(code) {
  return execFileSync('php', [path.join(SERVED_CHECKOUT, 'artisan'), 'tinker', '--execute', code], {
    encoding: 'utf8', timeout: 60000,
    env: { ...process.env, APP_ENV: 'e2e' },
  }).trim();
}

function purgeLockRateLimit() {
  tinker(`
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    foreach (['e2e-we-lock@test.fr', 'e2e-we-rbac@test.fr', 'e2e-we-ghost@test.fr'] as $email) {
      foreach (['127.0.0.1', '::1', 'localhost'] as $ip) {
        $limiter->clear(md5('login-lockout'.$email.'|'.$ip));
      }
    }
    foreach (['127.0.0.1', '::1'] as $ip) {
      $limiter->clear(md5('kiosk-login'.'kiosk:e2e-we-kiosk|'.$ip));
    }
    echo 'purged';
  `);
}

async function apiLogin(request, email, password) {
  const r = await request.post('/api/auth/login', {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { email, password },
  });
  let body = null;
  try { body = await r.json(); } catch (_) { body = await r.text().catch(() => ''); }
  return { status: r.status(), body };
}

async function apiKioskLogin(request, username, password) {
  const r = await request.post('/api/auth/kiosk-login', {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username, password },
  });
  let body = null;
  try { body = await r.json(); } catch (_) { body = await r.text().catch(() => ''); }
  return { status: r.status(), body };
}

async function uiLogin(page, { email, password }) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20000 });
  await page.locator('#formEmail').fill(email);
  await page.locator('#formPassword').fill(password);
  const respP = page.waitForResponse(
    (r) => r.request().method() === 'POST' && /\/api\/auth\/login/i.test(r.url()),
    { timeout: 25000 },
  );
  await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
  return respP;
}

async function uiLogout(page) {
  const trigger = page.locator('header .dropdown-group button.dropdown-btn').last();
  await trigger.click();
  const logoutBtn = page.getByRole('menuitem', { name: /deconnexion|déconnexion|logout/i })
    .or(page.locator('button', { hasText: /deconnexion|déconnexion|logout/i }));
  await logoutBtn.first().click();
  await page.waitForURL((u) => u.pathname.endsWith('/login'), { timeout: 20000 });
}

test.describe.configure({ mode: 'serial' });
test.describe(`W-E AUTH/RBAC cycle ${CYCLE}`, () => {
  test.beforeAll(() => {
    purgeLockRateLimit();
    sql(`UPDATE kiosk_machines SET status=5 WHERE id=${KIOSK_MACHINE.id};`);
  });

  test.afterAll(() => {
    purgeLockRateLimit();
    sql(`UPDATE kiosk_machines SET status=5 WHERE id=${KIOSK_MACHINE.id};`);
  });

  test('E1a admin login FR -> logout -> re-login', async ({ page }) => {
    currentStep = 'E1a';
    wirePage(page);
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#formEmail')).toBeVisible();
    await snap(page, 'e1a-1-login-page');
    const bodyText = await page.locator('body').innerText();
    const isFr = /connexion|mot de passe/i.test(bodyText);
    if (!isFr) findings.push({ id: 'AUTH-E1-FR', sev: 'P3', step: 'E1a', detail: `Page /login sans texte FR: ${bodyText.slice(0, 150)}` });

    const resp = await uiLogin(page, ADMIN);
    expect(resp.status()).toBe(201);
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25000 });
    await page.waitForTimeout(2000);
    await snap(page, 'e1a-2-admin-logged-in');
    const landed = new URL(page.url()).pathname;

    await uiLogout(page);
    await page.waitForTimeout(800);
    await snap(page, 'e1a-3-after-logout');

    const resp2 = await uiLogin(page, ADMIN);
    expect(resp2.status()).toBe(201);
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25000 });
    await page.waitForTimeout(1500);
    await snap(page, 'e1a-4-relogin-ok');
    await uiLogout(page);
    mark('E1a', 'PASS', `login 201 -> ${landed} -> logout -> re-login 201 -> logout`);
  });

  test('E1b wrong password = FR generic, no user-enumeration', async ({ page, request }) => {
    currentStep = 'E1b';
    wirePage(page);
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#formEmail')).toBeVisible();
    await page.locator('#formEmail').fill(LOCK_USER.email);
    await page.locator('#formPassword').fill('definitely-wrong-pw');
    const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/auth\/login/i.test(r.url()));
    await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
    const resp = await respP;
    expect(resp.status()).toBe(400);
    await page.waitForTimeout(800);
    await snap(page, 'e1b-1-wrong-password-fr');
    const errText = await page.locator('body').innerText();
    const hasGenericFr = /identifiants invalides/i.test(errText);
    if (!hasGenericFr) findings.push({ id: 'AUTH-E1B-MSG', sev: 'P2', step: 'E1b', detail: 'Message erreur login absent/non-FR apres mauvais mot de passe', capture: 'e1b-1-wrong-password-fr.jpg' });

    const existing = await apiLogin(request, LOCK_USER.email, 'definitely-wrong-pw');
    const ghost = await apiLogin(request, 'e2e-we-ghost@test.fr', 'definitely-wrong-pw');
    saveEvidence('e1b_existing_wrong_pw', existing);
    saveEvidence('e1b_ghost_wrong_pw', ghost);
    expect(existing.status).toBe(400);
    expect(ghost.status).toBe(400);
    expect(JSON.stringify(existing.body)).toBe(JSON.stringify(ghost.body));
    mark('E1b', hasGenericFr ? 'PASS' : 'PARTIAL', `400 generique identique existing/ghost; UI FR=${hasGenericFr}`);
  });

  test('E1c lockout 429 on dedicated user then purge', async ({ page, request }) => {
    currentStep = 'E1c';
    wirePage(page);
    // L'env e2e fixe LOGIN_LOCKOUT_MAX_ATTEMPTS=500 (delibere — evite que les
    // runs e2e s'auto-throttlent). Plutot que 500 round-trips HTTP (lent+bruit)
    // ou baisser le seuil dans le .env.e2e SERVI partage (casserait les autres
    // jobs paralleles sur :8766), on pre-seed le bucket rate-limiter EXACT de
    // NOTRE user dedie jusqu'au plafond, puis on prouve qu'UNE tentative reelle
    // declenche le 429. Cle = md5('login-lockout'.email|ip) — identique a celle
    // purgee par clearFoodKingRateLimits. N'affecte QUE e2e-we-lock.
    const maxAttempts = parseInt(tinker(`echo (int) config('auth.login_lockout.max_attempts');`).trim(), 10) || 500;
    tinker(`
      $l = app(\\Illuminate\\Cache\\RateLimiter::class);
      foreach (['127.0.0.1', '::1', 'localhost'] as $ip) {
        $k = md5('login-lockout'.'e2e-we-lock@test.fr|'.$ip);
        for ($i = 0; $i < ${maxAttempts}; $i++) { $l->hit($k, 600); }
      }
      echo 'seeded';
    `);
    const got429 = await apiLogin(request, LOCK_USER.email, 'definitely-wrong-pw');
    saveEvidence('e1c_lockout_429', { maxAttempts, method: 'seeded-to-cap-then-1-real-attempt', response: got429 });
    expect(got429.status).toBe(429);

    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#formEmail')).toBeVisible();
    await page.locator('#formEmail').fill(LOCK_USER.email);
    await page.locator('#formPassword').fill('definitely-wrong-pw');
    const respP = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/auth\/login/i.test(r.url()));
    await page.getByRole('button', { name: /^(login|connexion)$/i }).click();
    const resp = await respP;
    expect(resp.status()).toBe(429);
    await page.waitForTimeout(800);
    await snap(page, 'e1c-1-lockout-ui');
    const uiText = await page.locator('body').innerText();
    const lockoutShown = /too many login attempts|trop de tentatives/i.test(uiText);
    const lockoutFr = /trop de tentatives/i.test(uiText);
    if (!lockoutShown) findings.push({ id: 'AUTH-E1C-UI', sev: 'P2', step: 'E1c', detail: 'Message lockout 429 non affiche a l utilisateur', capture: 'e1c-1-lockout-ui.jpg' });
    else if (!lockoutFr) findings.push({ id: 'AUTH-E1C-EN', sev: 'P3', step: 'E1c', detail: 'Message lockout 429 en ANGLAIS ("Too many login attempts...") hardcode RouteServiceProvider login-lockout, viole mandat FR', capture: 'e1c-1-lockout-ui.jpg' });

    purgeLockRateLimit();
    const after = await apiLogin(request, LOCK_USER.email, LOCK_USER.password);
    saveEvidence('e1c_after_purge_good_login', { status: after.status });
    expect(after.status).toBe(201);
    mark('E1c', 'PASS', `429 au plafond (max=${maxAttempts}); UI shown=${lockoutShown} fr=${lockoutFr}; purge -> login 201`);
  });

  test('E2a kiosk login page capture', async ({ page }) => {
    currentStep = 'E2a';
    wirePage(page);
    await page.goto('/kiosk/login', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);
    await snap(page, 'e2a-1-kiosk-login-page');
    const txt = (await page.locator('body').innerText()).slice(0, 400);
    const finalUrl = page.url();
    saveEvidence('e2a_kiosk_login_page', { finalUrl, text: txt });
    mark('E2a', 'PASS', `url=${finalUrl} — page auto-login KioskLoginComponent`);
  });

  test('E2b kiosk machine login — F3 enumeration diagnosis', async ({ request }) => {
    currentStep = 'E2b';
    const activeWrong = await apiKioskLogin(request, KIOSK_MACHINE.username, 'wrong-pass-123');
    saveEvidence('e2b_active_wrong_pw', activeWrong);
    expect(activeWrong.status).toBe(400);

    const unknown = await apiKioskLogin(request, 'e2e-we-ghost-kiosk', 'wrong-pass-123');
    saveEvidence('e2b_unknown_username', unknown);
    expect(unknown.status).toBe(400);
    expect(JSON.stringify(unknown.body)).toBe(JSON.stringify(activeWrong.body));

    sql(`UPDATE kiosk_machines SET status=10 WHERE id=${KIOSK_MACHINE.id};`);
    const inactiveWrong = await apiKioskLogin(request, KIOSK_MACHINE.username, 'wrong-pass-123');
    saveEvidence('e2b_inactive_wrong_pw', inactiveWrong);
    expect(inactiveWrong.status).toBe(400);
    const f3Present = JSON.stringify(inactiveWrong.body) === JSON.stringify(activeWrong.body);

    const inactiveCorrect = await apiKioskLogin(request, KIOSK_MACHINE.username, KIOSK_MACHINE.password);
    saveEvidence('e2b_inactive_correct_pw', inactiveCorrect);
    expect(inactiveCorrect.status).toBe(400);

    sql(`UPDATE kiosk_machines SET status=5 WHERE id=${KIOSK_MACHINE.id};`);
    const goodLogin = await apiKioskLogin(request, KIOSK_MACHINE.username, KIOSK_MACHINE.password);
    saveEvidence('e2b_active_correct_pw', { status: goodLogin.status, hasToken: !!goodLogin.body?.token });
    expect(goodLogin.status).toBe(201);

    if (!f3Present) {
      findings.push({
        id: 'AUTH-F3-SPINE', sev: 'P2', step: 'E2b',
        detail: 'F3 ABSENT sur la spine : KioskMachineLoginController verifie status machine (l.66-70) et user lie (l.72-77) AVANT Hash::check (l.79). Appelant non-auth avec mauvais password sur borne inactive recoit "Cette borne est desactivee..." -> divulgation etat/existence sans connaitre le password.',
        file: 'app/Http/Controllers/Auth/KioskMachineLoginController.php:66-79',
        proof: 'api-evidence e2b_inactive_wrong_pw vs e2b_active_wrong_pw',
      });
    }
    mark('E2b', 'PASS', `F3 present=${f3Present}; generique actif/inconnu identiques; inactive+wrong="${(inactiveWrong.body?.errors?.validation || '').slice(0, 60)}"; relogin 201`);
  });

  test('E3a RBAC UI denial on /admin/administrators + /admin/settings/site', async ({ page }) => {
    currentStep = 'E3a';
    wirePage(page);
    const resp = await uiLogin(page, RBAC_USER);
    expect(resp.status()).toBe(201);
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25000 });
    await page.waitForTimeout(2000);
    await snap(page, 'e3a-1-rbac-landing');

    for (const [i, target] of [['2', '/admin/administrators'], ['3', '/admin/settings/site']]) {
      await page.goto(target, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
      const name = `e3a-${i}-denied-${target.split('/').pop()}`;
      await snap(page, name);
      const finalPath = new URL(page.url()).pathname;
      const bodyTxt = await page.locator('body').innerText();
      const blank = bodyTxt.trim().length < 40;
      const stayedOnTarget = finalPath === target;
      saveEvidence(`e3a_${target}`, { finalPath, blank, textSample: bodyTxt.slice(0, 200) });
      if (blank) findings.push({ id: 'AUTH-E3A-BLANK', sev: 'P2', step: 'E3a', detail: `${target} page blanche pour POS Operator (refus non gere)`, capture: `${name}.jpg` });
      if (stayedOnTarget && !blank) {
        const leaked = /administrateur|administrator|parametres du site|site settings/i.test(bodyTxt);
        if (leaked) findings.push({ id: 'AUTH-E3A-LEAK', sev: 'P1', step: 'E3a', detail: `${target} accessible au role POS Operator (contenu rendu)`, capture: `${name}.jpg` });
      }
      mark(`E3a-${target}`, 'OBSERVED', `final=${finalPath} blank=${blank}`);
    }
    // cleanup best-effort: POS Operator landing (/admin/pos) a une navbar shell
    // differente ; le contexte Playwright est isole par test donc pas de bleed.
    try { await uiLogout(page); } catch (_) { /* hygiene seulement */ }
  });

  test('E3b kiosk:order token blocked on /api/admin/pos-order', async ({ request }) => {
    currentStep = 'E3b';
    const token = fs.readFileSync(KIOSK_TOKEN_FILE, 'utf8').trim();
    const r = await request.get('/api/admin/pos-order', {
      headers: { 'x-api-key': API_KEY, Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const body = await r.json().catch(() => null);
    saveEvidence('e3b_kiosk_token_admin_route', { status: r.status(), body });
    expect(r.status()).toBe(403);
    expect(body?.error).toBe('token_ability_insufficient');

    const r2 = await request.get('/api/admin/pos-order', {
      headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    });
    saveEvidence('e3b_no_token', { status: r2.status() });
    expect(r2.status()).toBe(401);
    mark('E3b', 'PASS', `kiosk:order -> 403 token_ability_insufficient; no-token -> 401`);
  });

  test('E4 logout then back button denies dashboard', async ({ page }) => {
    currentStep = 'E4';
    wirePage(page);
    const resp = await uiLogin(page, ADMIN);
    expect(resp.status()).toBe(201);
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25000 });
    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    await uiLogout(page);
    await page.waitForTimeout(500);

    await page.goBack({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await snap(page, 'e4-1-back-after-logout');
    const finalPath = new URL(page.url()).pathname;
    const bodyTxt = await page.locator('body').innerText();
    const dashboardVisible = /chiffre|ventes du jour|total orders|commandes totales/i.test(bodyTxt);
    saveEvidence('e4_back_after_logout', { finalPath, dashboardVisible, textSample: bodyTxt.slice(0, 200) });
    if (dashboardVisible) findings.push({ id: 'AUTH-E4-BACK', sev: 'P1', step: 'E4', detail: `Back button apres logout reaffiche le dashboard (${finalPath})`, capture: 'e4-1-back-after-logout.jpg' });
    expect(dashboardVisible).toBe(false);

    await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await snap(page, 'e4-2-direct-dashboard-after-logout');
    const directPath = new URL(page.url()).pathname;
    saveEvidence('e4_direct_dashboard', { directPath });
    expect(directPath.endsWith('/login')).toBe(true);
    mark('E4', 'PASS', `back->${finalPath} (dashboard non visible); direct->${directPath}`);
  });
});
