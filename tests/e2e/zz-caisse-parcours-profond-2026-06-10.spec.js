// FoodKing — W-B CAISSE/POS parcours profond 100% (GOAL VALIDATION PROFONDE)
// 2026-06-10. Clone jetable UNIQUEMENT (:8766 / foodking_e2e) :
//   CYCLE=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
//   npx playwright test tests/e2e/zz-caisse-parcours-profond-2026-06-10.spec.js
//
// Parcours : B1 catalogue / B2 wizard FROZEN observe-only / B3 panier ops /
// B4 paiement cash inline + reçu + DB / B5 sessions tiroir / B6 posOrders /
// B7 file encaissement. Chaque état = capture JPEG q70 + log console/pageerror/HTTP>=400.
//
// FROZEN (lecture/pilotage seulement, AUCUNE modification) :
//   public/js/pos-wizard.js, admin-pos-v4.blade.php, PaymentComponent.vue, PosV5TrancheRow.vue
//
// Self-contained : login UI direct (pas d'artisan — le worktree d'exécution n'a
// pas vendor/) + réutilisation localStorage 'vuex' (1 seul login par cycle,
// évite throttle login-lockout 10/10min). posCart est STRIPPÉ du state restauré
// (posCart est persisté vuex → sinon le panier d'un test fuit dans le suivant).

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const CYCLE = process.env.CYCLE || '1';
const REPO_ROOT = path.resolve(__dirname, '../..');
const OUT = path.join(
  REPO_ROOT,
  'reports/test-e2e/validation-profonde-2026-06-10/caisse',
  `cycle-${CYCLE}`,
);
fs.mkdirSync(OUT, { recursive: true });

const STATE_PATH = path.join(OUT, 'run-state.json');
const STEPLOG_PATH = path.join(OUT, 'steps-log.json');
const AUTH_PATH = path.join(OUT, 'auth-localstorage.json');

const ADMIN_EMAIL = process.env.E2E_ADMIN_USER || 'admin@lecayenne.fr';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASS || '123456';
// Caissier branch-scoped (branch_id=1) — REQUIS pour park/recall :
// ParkedOrderController::resolveOperatorContext (P0-POS-04) renvoie 403 aux
// users branch_id=0 (Admin). Le parcours B3 park se joue donc en caissier.
const POS_EMAIL = process.env.E2E_POS_USER || 'pos@lecayenne.fr';
const POS_PASSWORD = process.env.E2E_POS_PASS || '123456';

const PERSONAS = {
  admin: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD, authFile: 'auth-admin.json', landing: '/admin/dashboard' },
  pos: { email: POS_EMAIL, password: POS_PASSWORD, authFile: 'auth-pos.json', landing: '/admin/pos' },
};

// ───────────────────────── shared run state (serial worker) ─────────────────
const runState = { cycle: CYCLE, started_at: new Date().toISOString() };
function saveState(patch) {
  Object.assign(runState, patch);
  fs.writeFileSync(STATE_PATH, JSON.stringify(runState, null, 2));
}

const stepLog = [];
function flushStepLog() {
  fs.writeFileSync(STEPLOG_PATH, JSON.stringify(stepLog, null, 2));
}

// ───────────────────────── DB helper (mysql CLI, clone jetable) ─────────────
function sql(query) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', query], {
    encoding: 'utf8',
    timeout: 20_000,
  }).trim();
}
function sqlRows(query) {
  const out = sql(query);
  if (!out) return [];
  return out.split('\n').map((l) => l.split('\t'));
}

// ───────────────────────── auth (login UI 1×/cycle puis restore) ────────────
async function uiLogin(page, persona) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });
  await page.locator('#formEmail').fill(persona.email);
  await page.locator('#formPassword').fill(persona.password);
  const submit = page.getByRole('button', { name: /^(login|connexion)$/i });
  const loginResp = page.waitForResponse(
    (r) => r.request().method() === 'POST' && /\/api\/auth\/login/i.test(r.url()),
    { timeout: 25_000 },
  );
  await submit.click();
  const resp = await loginResp;
  if (resp.status() !== 201) {
    const body = await resp.text().catch(() => '');
    throw new Error(`Login API failed: HTTP ${resp.status()} ${body.slice(0, 300)}`);
  }
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(1500);
  // persist le vuex (token inclus) pour réutilisation sans relogin
  const vuex = await page.evaluate(() => localStorage.getItem('vuex'));
  if (vuex) fs.writeFileSync(path.join(OUT, persona.authFile), vuex);
}

async function ensureLoggedIn(page, personaName = 'admin') {
  const persona = PERSONAS[personaName];
  const authPath = path.join(OUT, persona.authFile);
  if (fs.existsSync(authPath)) {
    const raw = fs.readFileSync(authPath, 'utf8');
    let cleaned = raw;
    try {
      const obj = JSON.parse(raw);
      delete obj.posCart; // panier persisté → ne JAMAIS le réinjecter entre tests
      cleaned = JSON.stringify(obj);
    } catch (_e) { /* garde brut */ }
    await page.addInitScript((v) => { try { localStorage.setItem('vuex', v); } catch (_) {} }, cleaned);
    await page.goto(persona.landing, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    if (!/\/login(\?|$)/.test(page.url())) return; // session restaurée
  }
  await uiLogin(page, persona);
}

// ───────────────────────── per-test instrumentation ──────────────────────────
function attachRecorder(page, testName) {
  const ctx = { step: 'init', consoleErrors: [], pageErrors: [], httpErrors: [], shots: 0 };
  page.on('console', (m) => {
    if (m.type() === 'error') ctx.consoleErrors.push({ step: ctx.step, text: m.text().slice(0, 300) });
  });
  page.on('pageerror', (e) => ctx.pageErrors.push({ step: ctx.step, text: String(e.message).slice(0, 300) }));
  page.on('response', (r) => {
    if (r.status() >= 400 && !/favicon|\.map$/.test(r.url())) {
      ctx.httpErrors.push({ step: ctx.step, status: r.status(), url: r.url().slice(0, 180) });
    }
  });

  const snap = async (name, opts = {}) => {
    ctx.step = name;
    ctx.shots += 1;
    const p = path.join(OUT, `${name}.jpg`);
    try {
      await page.screenshot({ path: p, type: 'jpeg', quality: 70, fullPage: !!opts.fullPage, timeout: 15_000 });
    } catch (e) {
      stepLog.push({ test: testName, step: name, screenshot_error: String(e.message).slice(0, 200) });
    }
    return p;
  };

  const finish = (notes = {}) => {
    const realConsole = ctx.consoleErrors.filter(
      (c) => !/websocket|ws:|wss:|soketi|pusher|6001|favicon/i.test(c.text),
    );
    stepLog.push({
      test: testName,
      console_errors: realConsole,
      console_errors_filtered_ws: ctx.consoleErrors.length - realConsole.length,
      page_errors: ctx.pageErrors,
      http_errors: ctx.httpErrors,
      screenshots: ctx.shots,
      notes,
      ts: new Date().toISOString(),
    });
    flushStepLog();
  };

  return { ctx, snap, finish };
}

// ───────────────────────── helpers parcours ──────────────────────────────────
async function gotoPos(page) {
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 20_000 });
  await expect(page.locator('.pos-v5-tile, .pos-item-tile').first()).toBeVisible({ timeout: 30_000 });
}

async function dismissCashDialogIfAny(page) {
  const overlay = page.locator('[data-testid="cash-session-overlay"]');
  if (await overlay.isVisible({ timeout: 2_500 }).catch(() => false)) {
    const close = page.locator('[data-testid="cash-session-close"]');
    if (await close.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await close.click().catch(() => {});
      await page.waitForTimeout(600);
    }
  }
}

function parseMoney(text) {
  if (!text) return null;
  const m = String(text).replace(/[  ]/g, ' ').match(/(\d+(?:[.,]\d{1,2})?)/);
  if (!m) return null;
  return parseFloat(m[1].replace(',', '.'));
}

/**
 * B2 — pilote le wizard vanilla FROZEN (observe-only) sur un item composé.
 * Capture chaque interaction. Retourne { wizardTotal, picks }.
 */
async function driveWizard(page, snap, prefix, { withMenu = true, note = null } = {}) {
  const modal = page.locator('#item-variation-modal');
  await expect(modal).toBeVisible({ timeout: 15_000 });
  await page.waitForTimeout(1500); // wizard vanilla remplace le body
  await snap(`${prefix}-01-wizard-initial`);

  const picks = [];

  // a) sauce gratuite (1ère dispo)
  const sauce = modal.locator('.sauce-grid .wizard-option').first();
  if (await sauce.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await sauce.click().catch(() => {});
    picks.push('sauce#1');
    await page.waitForTimeout(400);
    await snap(`${prefix}-02-sauce`);
  }

  // b) viandes si compteur requis (tacos) — '+' jusqu'à complet (max 4)
  let viandeClicked = false;
  for (let i = 0; i < 4; i++) {
    const done = await modal.locator('.viande-complete-badge').first().isVisible({ timeout: 300 }).catch(() => false);
    const plus = modal.locator('.viande-btn.plus:not(.disabled)').first();
    if (done || !(await plus.isVisible({ timeout: 500 }).catch(() => false))) break;
    await plus.click().catch(() => {});
    viandeClicked = true;
    picks.push('viande+');
    await page.waitForTimeout(350);
  }
  if (viandeClicked) await snap(`${prefix}-03-viandes`);

  if (withMenu) {
    // c) menu/formule "full" (frites + boisson)
    const menuFull = modal.locator(
      '[data-action="menu-choice"][data-value="full"], .formule-card[data-action="menu-choice"]:not([data-value="none"])',
    ).first();
    if (await menuFull.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await menuFull.click().catch(() => {});
      picks.push('menu=full');
      await page.waitForTimeout(800);
      await snap(`${prefix}-04-menu-full`);
    }

    // d) frites grande portion (CAISSE-01)
    const grande = modal.locator('[data-action="frites-size"][data-value="grande"], [data-upgrade="fritesGrande"]').first();
    if (await grande.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await grande.click().catch(() => {});
      picks.push('frites=grande');
      await page.waitForTimeout(400);
      await snap(`${prefix}-05-frites-grande`);
    }

    // e) cheddar fondu (CAISSE-01)
    const cheddar = modal.locator('[data-action="frites-cheddar"][data-value="yes"], [data-upgrade="fritesCheddar"]').first();
    if (await cheddar.isVisible({ timeout: 1_500 }).catch(() => false)) {
      await cheddar.click().catch(() => {});
      picks.push('frites+cheddar');
      await page.waitForTimeout(400);
      await snap(`${prefix}-06-frites-cheddar`);
    }

    // f) boisson (1ère référencée)
    const boisson = modal.locator('[data-action="boisson-choice"][data-id]').first();
    if (await boisson.isVisible({ timeout: 1_500 }).catch(() => false)) {
      await boisson.click().catch(() => {});
      picks.push('boisson#1');
      await page.waitForTimeout(400);
      await snap(`${prefix}-07-boisson`);
    }
  }

  // g) note commande (champ commentaire wizard)
  if (note) {
    const comment = modal.locator('.wizard-comment-field').first();
    if (await comment.isVisible({ timeout: 1_500 }).catch(() => false)) {
      await comment.fill(note).catch(() => {});
      picks.push('note');
      await page.waitForTimeout(300);
    }
  }

  // h) total wizard (sticky bar)
  await page.waitForTimeout(400);
  const totalTxt = await modal.locator('.sticky-total .total-value').first().innerText().catch(() => '');
  const wizardTotal = parseMoney(totalTxt);
  await snap(`${prefix}-08-recap-total`);

  // i) Ajouter au panier — jusqu'à 3 tentatives si validation manquante
  for (let attempt = 1; attempt <= 3; attempt++) {
    const addBtn = modal.locator('.wizard-btn-cart, button[data-action="add-to-cart"]').first();
    await addBtn.click({ timeout: 5_000 }).catch(() => {});
    await page.waitForTimeout(1300);
    const still = await modal.isVisible({ timeout: 800 }).catch(() => false);
    if (!still) break;
    await snap(`${prefix}-09-validation-retry-${attempt}`);
    const plus = modal.locator('.viande-btn.plus:not(.disabled)').first();
    if (await plus.isVisible({ timeout: 500 }).catch(() => false)) await plus.click().catch(() => {});
    const sauceRetry = modal.locator('.sauce-grid .wizard-option').first();
    if (await sauceRetry.isVisible({ timeout: 500 }).catch(() => false)) await sauceRetry.click().catch(() => {});
    await page.waitForTimeout(500);
    if (attempt === 3) await page.keyboard.press('Escape').catch(() => {});
  }

  return { wizardTotal, totalTxt: totalTxt.trim(), picks };
}

async function clickTileByName(page, name) {
  const tile = page.locator('.pos-v5-tile, .pos-item-tile')
    .filter({ hasText: new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i') })
    .first();
  await expect(tile, `tuile "${name}" visible`).toBeVisible({ timeout: 15_000 });
  await tile.click({ timeout: 5_000 });
}

async function readGrandTotal(page) {
  const txt = await page.locator('[data-testid="pos-grand-total"]').first().innerText().catch(() => '');
  return { value: parseMoney(txt), text: txt.trim() };
}

// ─────────────────────────────── SPEC ────────────────────────────────────────
test.describe.configure({ mode: 'serial', retries: 0 });

test.describe(`W-B caisse parcours profond — cycle ${CYCLE}`, () => {
  test.setTimeout(300_000);

  test('T0 — purge backlog + préconditions DB (clone jetable)', async () => {
    const purged = sql(
      'UPDATE orders SET status=13 WHERE status IN (4,7,8) AND created_at < NOW() - INTERVAL 10 MINUTE; SELECT ROW_COUNT();',
    );
    // Préconditions B5 : fermer les sessions tiroir restées open (SQL direct,
    // clone jetable — DISCLOSED) pour que le dialog auto-ouvre en mode "open".
    const closedSessions = sql(
      "UPDATE cash_drawer_sessions SET status='closed', closed_at=NOW(), closed_by_user_id=opened_by_user_id, closing_amount=COALESCE(closing_amount, opening_amount) WHERE status='open'; SELECT ROW_COUNT();",
    );
    const baseline = sqlRows('SELECT MAX(id), MAX(fiscal_sequence_no), COUNT(*) FROM orders;')[0];
    saveState({
      t0: {
        purged_backlog_rows: purged,
        force_closed_open_sessions: closedSessions,
        baseline_max_order_id: Number(baseline[0]),
        baseline_max_fiscal: Number(baseline[1]),
        baseline_orders_count: Number(baseline[2]),
      },
    });
    stepLog.push({ test: 'T0', db: runState.t0, ts: new Date().toISOString() });
    flushStepLog();
  });

  test('T1 — B5-ouverture session (fond 50) + B1 catalogue/catégories/recherche', async ({ page }) => {
    const { snap, finish } = attachRecorder(page, 'T1');
    const notes = {};
    try {
      await ensureLoggedIn(page);
      await gotoPos(page);

      // B5.1 — dialog session auto-ouvert (aucune session open pour admin)
      const overlay = page.locator('[data-testid="cash-session-overlay"]');
      const autoOpened = await overlay.isVisible({ timeout: 8_000 }).catch(() => false);
      notes.session_dialog_auto_opened = autoOpened;
      if (!autoOpened) {
        await page.locator('[data-testid="pos-cash-session-open"]').click({ timeout: 5_000 });
        await expect(overlay).toBeVisible({ timeout: 8_000 });
      }
      await snap('b5-01-session-dialog-open-mode');

      const openingInput = page.locator('[data-testid="cash-session-opening-input"]');
      await expect(openingInput).toBeVisible({ timeout: 8_000 });
      await openingInput.fill('50');
      await page.waitForTimeout(300);
      await snap('b5-02-fond-de-caisse-50');
      await page.locator('[data-testid="cash-session-open-submit"]').click({ timeout: 5_000 });
      await page.waitForTimeout(1800);
      await snap('b5-03-session-ouverte');
      await dismissCashDialogIfAny(page);

      const sess = sqlRows(
        "SELECT id, status, opening_amount FROM cash_drawer_sessions WHERE status='open' ORDER BY id DESC LIMIT 1;",
      )[0] || [];
      notes.session_db = { id: sess[0], status: sess[1], opening: sess[2] };
      expect(sess[1], 'session DB open').toBe('open');
      expect(parseFloat(sess[2]), 'fond de caisse 50').toBe(50.0);
      saveState({ session_id: Number(sess[0]) });

      // B1 — catalogue : grille complète
      const tiles = page.locator('.pos-item-tile');
      const tileCount = await tiles.count();
      notes.tiles_all = tileCount;
      expect(tileCount, 'grille non vide').toBeGreaterThan(10);
      await snap('b1-01-grille-toutes-categories');

      // catégories : clic pill "Tacos"
      const tacosPill = page.locator('.pos-v5-category').filter({ hasText: /tacos/i }).first();
      if (await tacosPill.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await tacosPill.click();
        await page.waitForTimeout(1500);
        notes.tiles_tacos = await tiles.count();
        await snap('b1-02-categorie-tacos');
      } else {
        notes.tacos_pill = 'NOT_VISIBLE';
        await snap('b1-02-categorie-tacos-INTROUVABLE');
      }

      // retour toutes catégories (1ère pill)
      await page.locator('.pos-v5-category').first().click().catch(() => {});
      await page.waitForTimeout(1000);

      // recherche produit
      const search = page.locator('.pos-v5-search-input__field').first();
      if (await search.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await search.fill('Cayenne');
        await search.press('Enter');
        await page.waitForTimeout(1800);
        notes.tiles_search_cayenne = await tiles.count();
        const names = await tiles.allInnerTexts();
        notes.search_all_match = names.length > 0 && names.every((n) => /cayenne/i.test(n));
        await snap('b1-03-recherche-cayenne');
        const clear = page.locator('.pos-v5-search-input__clear').first();
        if (await clear.isVisible({ timeout: 1_000 }).catch(() => false)) await clear.click();
        await page.waitForTimeout(1000);
        await snap('b1-04-recherche-effacee');
      } else {
        notes.search = 'NOT_PRESENT';
      }
      saveState({ t1: notes });
    } finally {
      finish(notes);
    }
  });

  test('T2 — B2 wizard FROZEN observe-only (Sandwich Cayenne, chaque étape) + prix panier', async ({ page }) => {
    const { snap, finish } = attachRecorder(page, 'T2');
    const notes = {};
    try {
      await ensureLoggedIn(page);
      await gotoPos(page);
      await dismissCashDialogIfAny(page);

      await clickTileByName(page, 'Sandwich Cayenne');
      const wiz = await driveWizard(page, snap, 'b2', { withMenu: true, note: 'TEST W-B sans oignons' });
      notes.wizard = wiz;

      const cartItems = page.locator('.pos-v5-cart-item');
      await expect(cartItems.first()).toBeVisible({ timeout: 10_000 });
      await snap('b2-10-panier-apres-ajout');

      const gt = await readGrandTotal(page);
      notes.grand_total = gt;
      notes.price_match_wizard_vs_cart =
        wiz.wizardTotal != null && gt.value != null && Math.abs(wiz.wizardTotal - gt.value) < 0.005;
      expect(gt.value, 'grand total lisible').not.toBeNull();
      if (wiz.wizardTotal != null) {
        expect(Math.abs(wiz.wizardTotal - gt.value), 'prix wizard == prix panier').toBeLessThan(0.005);
      }
      saveState({ t2: notes });
    } finally {
      finish(notes);
    }
  });

  test('T3 — B3 panier : qty +/-, suppression, remise (gate), park/recall, clear', async ({ page }) => {
    const { snap, finish } = attachRecorder(page, 'T3');
    const notes = {};
    page.on('dialog', (d) => d.accept('TEST-PARK-W-B').catch(() => {}));
    try {
      // Caissier branch-scoped : park/recall renvoie 403 by design aux Admin
      // branch_id=0 (P0-POS-04, ParkedOrderController::resolveOperatorContext).
      await ensureLoggedIn(page, 'pos');
      await gotoPos(page);
      await dismissCashDialogIfAny(page);

      // item simple pour les opérations panier — même chemin éprouvé que T2
      // (heal cycle-1 : un clic immédiat sur le CTA arrivait AVANT bindEvents()
      // du wizard vanilla → clic sans effet ; driveWizard attend le settle et
      // boucle jusqu'à 3 tentatives sur le CTA).
      await clickTileByName(page, 'Tarte Daim');
      await driveWizard(page, snap, 'b3-add', { withMenu: false });
      const cartItems = page.locator('.pos-v5-cart-item');
      await expect(cartItems.first()).toBeVisible({ timeout: 10_000 });
      await snap('b3-01-panier-1-ligne');
      const t1 = await readGrandTotal(page);

      // qty + : bouton incrément du stepper (aria-label "Augmenter ...")
      const inc = cartItems.first().locator('button[aria-label*="ugmenter" i]').first();
      const incFallback = cartItems.first().locator('button').last();
      await ((await inc.isVisible({ timeout: 1_500 }).catch(() => false)) ? inc : incFallback).click({ timeout: 5_000 });
      await page.waitForTimeout(1000);
      const t2 = await readGrandTotal(page);
      notes.qty_increment = { before: t1.value, after: t2.value, doubled: Math.abs(t2.value - 2 * t1.value) < 0.005 };
      await snap('b3-02-qty-plus');

      // qty − : bouton décrément (aria-label "Diminuer ..." ou 1er bouton)
      const dec = cartItems.first().locator('button[aria-label*="iminuer" i], button[aria-label*="upprimer" i]').first();
      const decFallback = cartItems.first().locator('button').first();
      await ((await dec.isVisible({ timeout: 1_500 }).catch(() => false)) ? dec : decFallback).click({ timeout: 5_000 });
      await page.waitForTimeout(1000);
      const t3 = await readGrandTotal(page);
      notes.qty_decrement = { back_to: t3.value, restored: Math.abs(t3.value - t1.value) < 0.005 };
      await snap('b3-03-qty-moins');

      // remise manuelle — gate : montant sans raison => Apply disabled
      const discountInput = page.locator('[data-testid="pos-discount-input"]');
      const applyBtn = page.locator('[data-testid="pos-discount-apply"]');
      await discountInput.fill('10');
      await page.waitForTimeout(700);
      notes.discount_gate_apply_disabled_without_reason = await applyBtn.isDisabled().catch(() => null);
      await snap('b3-04-remise-gate-sans-raison');

      const reason = page.locator('[data-testid="pos-discount-reason"]');
      if (await reason.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await reason.fill('Remise test W-B');
        await page.waitForTimeout(500);
        if (await applyBtn.isEnabled().catch(() => false)) {
          await applyBtn.click();
          await page.waitForTimeout(1000);
          const t4 = await readGrandTotal(page);
          notes.discount_applied = {
            total_after: t4.value,
            expected_minus_10pct: Math.round(t3.value * 0.9 * 100) / 100,
          };
          await snap('b3-05-remise-appliquee');
          // retirer la remise pour la suite
          await discountInput.fill('0');
          if (await applyBtn.isEnabled().catch(() => false)) await applyBtn.click();
          await page.waitForTimeout(800);
        } else {
          notes.discount_applied = 'APPLY_STILL_DISABLED';
          await snap('b3-05-remise-toujours-bloquee');
        }
      } else {
        notes.discount_reason_field = 'NOT_VISIBLE';
        await snap('b3-05-remise-champ-raison-absent');
      }

      // park (mettre en attente — window.prompt accepté via handler dialog)
      const parkBtn = page.locator('button').filter({ hasText: /mettre en attente/i }).first();
      await expect(parkBtn).toBeVisible({ timeout: 8_000 });
      const parkResp = page.waitForResponse(
        (r) => /\/api\/admin\/pos\/parked-orders$/.test(r.url()) && r.request().method() === 'POST',
        { timeout: 15_000 },
      ).catch(() => null);
      await parkBtn.click();
      const pr = await parkResp;
      notes.park_api_status = pr ? pr.status() : 'NO_RESPONSE';
      await page.waitForTimeout(1800);
      notes.park_cart_emptied = await page.locator('.pos-v5-cart__empty').isVisible({ timeout: 5_000 }).catch(() => false);
      await snap('b3-06-apres-park-panier-vide');
      notes.parked_rows_db = Number(sql('SELECT COUNT(*) FROM pos_parked_orders;'));
      expect([200, 201], 'park API (caissier branch-scoped)').toContain(notes.park_api_status);
      expect(notes.park_cart_emptied, 'panier vidé après park').toBe(true);

      // recall
      const parkedToggle = page.locator('.pos-v5-btn--park-toggle').first();
      await parkedToggle.click({ timeout: 5_000 });
      await page.waitForTimeout(1300);
      await snap('b3-07-drawer-commandes-en-attente');
      const restoreBtn = page.locator('.parked-orders-card button').filter({ hasText: /restaurer|rappeler|reprendre|recall/i }).first();
      if (await restoreBtn.isVisible({ timeout: 4_000 }).catch(() => false)) {
        await restoreBtn.click();
      } else {
        await page.locator('.parked-orders-card').first().click({ timeout: 4_000 }).catch(() => {});
      }
      await page.waitForTimeout(1800);
      // fermer le drawer s'il est resté ouvert (sinon l'overlay bloque le panier)
      const drawerClose = page.locator('.parked-orders-close').first();
      if (await drawerClose.isVisible({ timeout: 1_500 }).catch(() => false)) {
        await drawerClose.click().catch(() => {});
        await page.waitForTimeout(600);
      }
      notes.recall_cart_restored = await cartItems.first().isVisible({ timeout: 6_000 }).catch(() => false);
      const tR = await readGrandTotal(page);
      notes.recall_total = {
        restored_total: tR.value,
        expected: t3.value,
        match: tR.value != null && Math.abs(tR.value - t3.value) < 0.005,
      };
      await snap('b3-08-panier-restaure-apres-recall');

      // clear panier : suppression ligne par ligne — bouton stepper minus/trash
      // EXACT (.pos-v5-qty__btn--minus). Heal cycle-1 : `button.first()` matchait
      // l'affordance d'édition de la ligne → le wizard se ré-ouvrait en mode edit
      // au lieu de supprimer.
      let guard = 0;
      while ((await cartItems.count()) > 0 && guard < 10) {
        guard += 1;
        const wizModal = page.locator('#item-variation-modal');
        if (await wizModal.isVisible({ timeout: 400 }).catch(() => false)) {
          const cancel = wizModal.locator('[data-action="cancel-wizard"]').first();
          if (await cancel.isVisible({ timeout: 800 }).catch(() => false)) await cancel.click().catch(() => {});
          else await page.keyboard.press('Escape').catch(() => {});
          await page.waitForTimeout(600);
        }
        await cartItems.first().locator('.pos-v5-qty__btn--minus').first().click({ timeout: 4_000 }).catch(() => {});
        await page.waitForTimeout(800);
      }
      notes.clear_cart_empty_state = await page.locator('.pos-v5-cart__empty').isVisible({ timeout: 5_000 }).catch(() => false);
      await snap('b3-09-panier-vide-etat-final');
      expect(notes.clear_cart_empty_state, 'empty state panier').toBe(true);
      saveState({ t3: notes });
    } finally {
      finish(notes);
    }
  });

  test('T4 — B4 paiement inline CASH : rendu monnaie, reçu, DB (PAID/pos/fiscal/transaction) + CAISSE-01', async ({ page }) => {
    const { snap, finish } = attachRecorder(page, 'T4');
    const notes = {};
    try {
      await ensureLoggedIn(page);
      await gotoPos(page);
      await dismissCashDialogIfAny(page);

      // panier composé : Sandwich Cayenne menu full + frites grande + cheddar (CAISSE-01)
      await clickTileByName(page, 'Sandwich Cayenne');
      const wiz = await driveWizard(page, snap, 'b4-wiz', { withMenu: true });
      notes.wizard = wiz;
      const cartItems = page.locator('.pos-v5-cart-item');
      await expect(cartItems.first()).toBeVisible({ timeout: 10_000 });
      const gt = await readGrandTotal(page);
      notes.cart_total_ui = gt;
      await snap('b4-01-panier-pret');

      // ouvrir paiement + capter le quote serveur (PaymentComponent FROZEN — observe only)
      const quotePromise = page.waitForResponse(
        (r) => /\/api\/admin\/pos\/quote(\?|$)/.test(r.url()) && r.request().method() === 'POST',
        { timeout: 20_000 },
      ).catch(() => null);
      await page.locator('[data-testid="pos-v5-pay"]').click({ timeout: 8_000 });
      await page.waitForTimeout(1800);
      await snap('b4-02-modal-paiement');

      const quoteResp = await quotePromise;
      if (quoteResp) {
        try {
          const qb = await quoteResp.json();
          const qTotal = parseFloat(qb?.data?.total ?? qb?.total ?? qb?.data?.grand_total ?? qb?.grand_total ?? 'NaN');
          notes.quote_server = { status: quoteResp.status(), total: qTotal, raw_keys: Object.keys(qb?.data || qb || {}).slice(0, 12) };
          notes.price_match_cart_vs_quote = !Number.isNaN(qTotal) && gt.value != null && Math.abs(qTotal - gt.value) < 0.005;
        } catch (_e) {
          notes.quote_server = { status: quoteResp.status(), parse: 'FAILED' };
        }
      } else {
        notes.quote_server = 'NO_QUOTE_CALL_OBSERVED';
      }

      // mode CASH + montant reçu > total → rendu monnaie
      const cashMode = page.locator('[data-testid="pos-payment-mode-cash"]');
      if (await cashMode.isVisible({ timeout: 4_000 }).catch(() => false)) await cashMode.click();
      await page.waitForTimeout(600);
      const cashInput = page.locator('#cashInput');
      await expect(cashInput).toBeVisible({ timeout: 8_000 });
      const received = Math.ceil((gt.value ?? 20) + 10);
      await cashInput.fill(String(received));
      await page.waitForTimeout(900);
      const changeTxt = await page.locator('.pos-v5-payment-change-value').first().innerText().catch(() => '');
      notes.change_due = {
        received,
        displayed: changeTxt.trim(),
        value: parseMoney(changeTxt),
        expected: gt.value != null ? Math.round((received - gt.value) * 100) / 100 : null,
      };
      await snap('b4-03-cash-rendu-monnaie');

      // confirmer → POST /api/admin/pos exact (pas les sous-routes)
      const orderResp = page.waitForResponse(
        (r) => /\/api\/admin\/pos(\?[^/]*)?$/.test(r.url()) && r.request().method() === 'POST',
        { timeout: 30_000 },
      );
      await page.locator('[data-testid="pos-payment-confirm"]').click({ timeout: 8_000 });
      const resp = await orderResp;
      notes.order_post_status = resp.status();
      let orderId = null;
      try {
        const body = await resp.json();
        orderId = body?.data?.id ?? body?.id ?? null;
        notes.order_post_total = body?.data?.total ?? null;
      } catch (_e) { /* ignore */ }
      expect([200, 201], 'POST /api/admin/pos').toContain(resp.status());
      await page.waitForTimeout(2500);

      // reçu modal
      const receipt = page.locator('#receiptModal');
      const receiptVisible = await receipt.isVisible({ timeout: 12_000 }).catch(() => false);
      notes.receipt_visible = receiptVisible;
      await snap('b4-04-recu-modal');
      if (receiptVisible) {
        const rTxt = await receipt.innerText().catch(() => '');
        fs.writeFileSync(path.join(OUT, 'b4-recu-texte.txt'), rTxt);
        notes.receipt_mentions = {
          operateur: /op[ée]rateur/i.test(rTxt),
          tva: /tva/i.test(rTxt),
          siret: /siret|10417050100019/i.test(rTxt),
          vat_intra: /FR19104170501/i.test(rTxt),
          designation_sandwich: /sandwich cayenne/i.test(rTxt),
          total_eur: rTxt.includes('€'),
        };
        const printBtn = page.locator('[data-testid="receipt-print-client"]').first();
        notes.print_button_visible = await printBtn.isVisible({ timeout: 3_000 }).catch(() => false);
        if (notes.print_button_visible) {
          await printBtn.click().catch(() => {});
          await page.waitForTimeout(1500);
          await snap('b4-05-apres-clic-imprimer');
        }
        const closeBtn = receipt.locator('button').filter({ hasText: /fermer|nouvelle|close/i }).first();
        if (await closeBtn.isVisible({ timeout: 2_000 }).catch(() => false)) await closeBtn.click().catch(() => {});
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(900);
      }

      // ─── DB checks ───
      const row = sqlRows(
        `SELECT id, source, payment_status, status, total, fiscal_sequence_no, pos_received_amount, pos_payment_method
         FROM orders ${orderId ? `WHERE id=${Number(orderId)}` : 'WHERE source=15 ORDER BY id DESC LIMIT 1'};`,
      )[0] || [];
      const dbOrder = {
        id: Number(row[0]),
        source: Number(row[1]),
        payment_status: Number(row[2]),
        status: Number(row[3]),
        total: parseFloat(row[4]),
        fiscal_sequence_no: row[5] === 'NULL' || row[5] === '' || row[5] == null ? null : Number(row[5]),
        pos_received_amount: parseFloat(row[6]),
        pos_payment_method: Number(row[7]),
      };
      notes.db_order = dbOrder;
      expect(dbOrder.source, 'source=pos(15)').toBe(15);
      expect(dbOrder.payment_status, 'payment_status=PAID(5)').toBe(5);
      expect(dbOrder.fiscal_sequence_no, 'fiscal_sequence_no alloué').not.toBeNull();
      expect(dbOrder.pos_received_amount, 'montant reçu DB').toBe(received);

      // DISCLOSED (vérifié empiriquement) : la table `transactions` n'est PAS
      // écrite pour les ventes POS cash (748 rows en clone : sources 5/10
      // online/kiosk uniquement, 0 source=15). La piste financière POS cash =
      // cash_movements (order_payment) + fiscal_sequence_no.
      const txn = sqlRows(`SELECT id, amount, payment_method, type FROM transactions WHERE order_id=${dbOrder.id};`);
      notes.db_transactions = txn.map((t) => ({ id: t[0], amount: parseFloat(t[1]), method: t[2], type: t[3] }));
      notes.db_transactions_note = 'table transactions = gateways online uniquement (0 rows attendu pour POS cash)';

      const mv = sqlRows(
        `SELECT type, direction, amount, cash_drawer_session_id FROM cash_movements WHERE order_id=${dbOrder.id};`,
      );
      notes.db_cash_movements = mv.map((m) => ({ type: m[0], dir: m[1], amount: parseFloat(m[2]), session: Number(m[3]) }));
      expect(mv.length, 'cash_movement order_payment présent (piste financière POS cash)').toBeGreaterThan(0);
      expect(parseFloat(mv[0][2]), 'montant mouvement == total DB').toBeCloseTo(dbOrder.total, 2);

      // CAISSE-01 : frites grande + cheddar réellement facturés ?
      const items = sqlRows(
        `SELECT id, item_id, quantity, total_price, item_extra_total, REPLACE(LEFT(composition_snapshot, 900), '\\t', ' ')
         FROM order_items WHERE order_id=${dbOrder.id};`,
      );
      notes.db_order_items = items.map((it) => ({
        id: it[0],
        item_id: it[1],
        qty: it[2],
        total_price: parseFloat(it[3]),
        item_extra_total: parseFloat(it[4]),
        snapshot_head: (it[5] || '').slice(0, 400),
      }));
      const upgradesPicked = (wiz.picks || []).filter((p) => p === 'frites=grande' || p === 'frites+cheddar');
      notes.caisse01 = {
        upgrades_picked: upgradesPicked,
        wizard_total_shown: wiz.wizardTotal,
        ui_cart_total: gt.value,
        db_total: dbOrder.total,
        delta_ui_vs_db: gt.value != null ? Math.round((gt.value - dbOrder.total) * 100) / 100 : null,
        sum_item_extra_total: notes.db_order_items.reduce((s, it) => s + (it.item_extra_total || 0), 0),
        verdict:
          upgradesPicked.length === 0
            ? 'DATA-GAP: aucun upgrade frites (Grande/Cheddar) proposé par le wizard sur cet item — seed owner'
            : gt.value != null && Math.abs(gt.value - dbOrder.total) < 0.005
              ? 'OK: total UI == total DB (upgrades facturés)'
              : 'CONFIRMED UNDER-BILLING: total affiché != total facturé DB (CAISSE-01, wizard frozen)',
      };

      saveState({ t4: notes, order_id: dbOrder.id });
      await snap('b4-06-pos-apres-encaissement');
    } finally {
      finish(notes);
    }
  });

  test('T5 — B5 suite : no-sale (mouvement), vue session, clôture comptée + réconciliation', async ({ page }) => {
    const { snap, finish } = attachRecorder(page, 'T5');
    const notes = {};
    try {
      await ensureLoggedIn(page);
      await gotoPos(page);
      await dismissCashDialogIfAny(page);

      // mouvement manuel : no-sale / ouverture tiroir (M1-01 audit backend)
      const noSale = page.locator('[data-testid="pos-no-sale"]');
      if (await noSale.isVisible({ timeout: 5_000 }).catch(() => false)) {
        const noSaleResp = page.waitForResponse(
          (r) => /cash-drawer\/open/.test(r.url()) && r.request().method() === 'POST',
          { timeout: 15_000 },
        ).catch(() => null);
        await noSale.click();
        const nr = await noSaleResp;
        notes.no_sale = { clicked: true, api_status: nr ? nr.status() : 'NO_RESPONSE' };
        await page.waitForTimeout(1200);
        await snap('b5-04-no-sale-tiroir');
      } else {
        notes.no_sale = 'BUTTON_NOT_VISIBLE';
      }

      // dialog session — vue active
      await page.locator('[data-testid="pos-cash-session-open"]').click({ timeout: 8_000 });
      const overlay = page.locator('[data-testid="cash-session-overlay"]');
      await expect(overlay).toBeVisible({ timeout: 8_000 });
      await page.waitForTimeout(900);
      await snap('b5-05-session-vue-active');
      const expectedTxt = await page.locator('[data-testid="cash-session-stat-expected"]').innerText().catch(() => '');
      const expectedVal = parseMoney(expectedTxt);
      notes.expected_in_drawer = { text: expectedTxt.trim(), value: expectedVal };

      // mouvements
      const viewMvts = page.locator('[data-testid="cash-session-view-movements"]');
      if (await viewMvts.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await viewMvts.click();
        await page.waitForTimeout(1000);
        notes.movement_rows_ui = await page.locator('[data-testid="cash-session-movement-row"]').count();
        await snap('b5-06-session-mouvements');
        const back = overlay.locator('button').filter({ hasText: /retour|back/i }).first();
        if (await back.isVisible({ timeout: 2_000 }).catch(() => false)) await back.click();
        await page.waitForTimeout(700);
      }

      // clôture : compté = attendu + 2.00 → écart +2 + raison
      await page.locator('[data-testid="cash-session-go-close"]').click({ timeout: 6_000 });
      await page.waitForTimeout(900);
      const counted = Math.round(((expectedVal ?? 50) + 2) * 100) / 100;
      await page.locator('[data-testid="cash-session-closing-input"]').fill(String(counted));
      await page.waitForTimeout(800);
      const varianceTxt = await page.locator('[data-testid="cash-session-close-variance"]').innerText().catch(() => '');
      notes.variance_displayed = { counted, text: varianceTxt.trim() };
      await snap('b5-07-cloture-ecart-affiche');
      const reasonInput = page.locator('[data-testid="cash-session-reason-input"]');
      if (await reasonInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await reasonInput.fill('Ecart test W-B +2');
        await page.waitForTimeout(400);
      }
      await page.locator('[data-testid="cash-session-close-submit"]').click({ timeout: 6_000 });
      await page.waitForTimeout(2200);
      await snap('b5-08-apres-cloture-reconciliation');

      // DB : session fermée + variance + mouvements liés
      const sid = runState.session_id;
      const srow = sqlRows(
        `SELECT status, opening_amount, closing_amount, expected_closing_amount, variance, variance_reason
         FROM cash_drawer_sessions WHERE id=${Number(sid)};`,
      )[0] || [];
      notes.db_session = {
        id: sid,
        status: srow[0],
        opening: parseFloat(srow[1]),
        closing: parseFloat(srow[2]),
        expected: parseFloat(srow[3]),
        variance: parseFloat(srow[4]),
        reason: srow[5],
      };
      const mvts = sqlRows(
        `SELECT type, direction, amount FROM cash_movements WHERE cash_drawer_session_id=${Number(sid)};`,
      );
      notes.db_session_movements = mvts.map((m) => ({ type: m[0], dir: m[1], amount: parseFloat(m[2]) }));
      expect(['closed', 'reconciled'], 'session fermée DB').toContain(srow[0]);
      saveState({ t5: notes });
    } finally {
      finish(notes);
    }
  });

  test('T6 — B6 posOrders : liste (badges FR), détail commande B4 (pas de nullXXXX), tracker', async ({ page }) => {
    const { snap, finish } = attachRecorder(page, 'T6');
    const notes = {};
    try {
      await ensureLoggedIn(page);

      // liste
      await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3500);
      await snap('b6-01-pos-orders-liste');
      const bodyTxt = await page.locator('body').innerText().catch(() => '');
      notes.list_fr_badges = {
        has_paye: /pay[ée]/i.test(bodyTxt),
        has_status_fr: /livr[ée]|en attente|accept[ée]e|pr[ée]par/i.test(bodyTxt),
        raw_label_leak: /label\.|status\.|message\./.test(bodyTxt),
        null_leak: /null\d|nullnull|undefined/i.test(bodyTxt),
      };

      // détail commande B4
      const orderId = runState.order_id;
      if (orderId) {
        await page.goto(`/admin/pos-orders/show/${orderId}`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3500);
        await snap('b6-02-pos-order-show-b4');
        const showTxt = await page.locator('body').innerText().catch(() => '');
        notes.show = {
          order_id: orderId,
          phone_null_leak: /null\d{2,}|nullX|undefined/i.test(showTxt),
          has_total_eur: showTxt.includes('€'),
        };
        fs.writeFileSync(path.join(OUT, 'b6-show-texte.txt'), showTxt.slice(0, 6000));
      } else {
        notes.show = 'NO_ORDER_ID_FROM_T4';
      }

      // tracker
      await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(4000);
      await snap('b6-03-pos-orders-tracker');
      const trackTxt = await page.locator('body').innerText().catch(() => '');
      notes.tracker = {
        has_columns: /accept|pr[ée]paration|pr[ée]t|livr/i.test(trackTxt),
        raw_label_leak: /label\.|pos\.tracker\./.test(trackTxt),
      };
      saveState({ t6: notes });
    } finally {
      finish(notes);
    }
  });

  test('T7 — B7 file encaissement (P2 connu N°A0001 inter-jours : capture si visible)', async ({ page }) => {
    const { snap, finish } = attachRecorder(page, 'T7');
    const notes = {};
    try {
      await ensureLoggedIn(page);
      await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(4000);
      await snap('b7-01-file-encaissement');
      const bodyTxt = await page.locator('body').innerText().catch(() => '');
      notes.queue = {
        pending_count_chip: (await page.locator('.enc-count-chip').innerText().catch(() => 'N/A')).trim(),
        collect_buttons: await page.locator('.enc-collect-btn').count(),
      };
      // P2 connu : deux tickets même N° inter-jours
      const ticketNos = bodyTxt.match(/[A-Z]\d{4}/g) || [];
      const dupes = ticketNos.filter((n, i, a) => a.indexOf(n) !== i);
      notes.duplicate_ticket_numbers = [...new Set(dupes)];
      if (dupes.length > 0) await snap('b7-02-doublon-numero-visible');
      saveState({ t7: notes, finished_at: new Date().toISOString() });
    } finally {
      finish(notes);
    }
  });
});
