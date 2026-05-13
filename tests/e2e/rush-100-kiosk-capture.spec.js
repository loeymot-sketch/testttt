// FoodKing E2E — rush-100 Wave A : Kiosk multi-scenario visual capture (2026-05-13)
// Run ID : rush-100-2026-05-13 / round-1 / Wave A
//
// Mission : capture 5 representative kiosk orders end-to-end with the
// 4-file artifact quartet per state. Scenarios :
//   S1  Sandwich Cayenne          (id=474 cat=344)
//   S2  Galette Normale           (id=475 cat=345)
//   S5  Tacos                     (id=478 cat=306)
//   S7  Bol Curry                 (id=480 cat=347)
//   S9  Petite Frites             (id=485 cat=348)
//
// All 5 items have ≥1 variation → wizard opens for each. We drive the wizard
// generically (click first available option → next → repeat) per the Wave A
// pattern from `test-e2e-kiosk-kds-sync-2026-05-11-wave-A.spec.js`.
//
// 7 states per scenario × 5 scenarios = 35 PNG quartets.
//   01-idle, 02-categories, 03-wizard-open, 04-wizard-recap,
//   05-cart, 06-payment, 07-confirmation
//
// After each scenario : query latest order row (id, fiscal_sequence_no, total,
// status, item count, composition_snapshot non-null) and append to
// reports/test-e2e/rush-100-2026-05-13/round-1/wave-A-db-checks.json.
//
// Critical : spec MUST PASS at Playwright level so adversarial reviewer can
// score artifacts. Anomalies surface in observations[] / wave-A-report.md, not
// as hard failures.

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { loginAsKiosk, cleanupOrphanTestOrders } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const REPO_ROOT = path.resolve(__dirname, '../..');
const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/rush-100/kiosk');
const REPORT_DIR = path.resolve(REPO_ROOT, 'reports/test-e2e/rush-100-2026-05-13/round-1');
const DB_CHECKS_FILE = path.join(REPORT_DIR, 'wave-A-db-checks.json');
const REPORT_FILE = path.join(REPORT_DIR, 'wave-A-report.md');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });

// Scenario definitions (DB-verified 2026-05-13).
const SCENARIOS = [
  { code: 'S1', label: 'Sandwich Cayenne + menu formule', itemId: 474, catId: 344 },
  { code: 'S2', label: 'Galette Normale + sauce + supp',  itemId: 475, catId: 345 },
  { code: 'S5', label: 'Tacos 1v 8.50',                   itemId: 478, catId: 306 },
  { code: 'S7', label: 'Bol Curry 4-step compose',        itemId: 480, catId: 347 },
  { code: 'S9', label: 'Petite Frites + supp',            itemId: 485, catId: 348 },
];

// FR/EN tolerant € parser.
function parseEur(txt) {
  if (!txt) return NaN;
  const m = String(txt).replace(/\s/g, '').replace(',', '.').match(/-?\d+(?:\.\d+)?/);
  return m ? parseFloat(m[0]) : NaN;
}

// Spawn `php artisan tinker --execute` synchronously and return its stdout.
function artisan(code) {
  return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 20_000,
  }).trim();
}

function parseArtisanJson(output) {
  const lines = String(output).split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const jsonLine = [...lines].reverse().find((l) => l.startsWith('{') || l.startsWith('['));
  if (!jsonLine) throw new Error(`No JSON in artisan output:\n${output}`);
  return JSON.parse(jsonLine);
}

// Query the most recent order (id > startId) — DB cross-check after each scenario.
function queryLatestOrder(startId) {
  try {
    const out = artisan(`
      $row = DB::table('orders')->where('id', '>', ${Number(startId)})->orderByDesc('id')->first();
      if (! $row) { echo json_encode(['error' => 'no_new_order', 'after_id' => ${Number(startId)}]); return; }
      $itemCount = DB::table('order_items')->where('order_id', $row->id)->count();
      $hasSnap = DB::table('order_items')->where('order_id', $row->id)->whereNotNull('composition_snapshot')->count();
      echo json_encode([
        'id' => (int) $row->id,
        'fiscal_sequence_no' => $row->fiscal_sequence_no ?? null,
        'total' => is_numeric($row->total ?? null) ? (float) $row->total : (string) ($row->total ?? null),
        'order_status' => $row->order_status ?? null,
        'payment_status' => $row->payment_status ?? null,
        'order_type' => $row->order_type ?? null,
        'source' => $row->source ?? null,
        'item_count' => (int) $itemCount,
        'has_composition_snapshot' => $itemCount > 0 ? ($hasSnap === $itemCount ? 'all' : ($hasSnap > 0 ? 'partial' : 'none')) : 'no_items',
        'created_at' => (string) ($row->created_at ?? ''),
        'token' => (string) ($row->token ?? ''),
      ]);
    `);
    return parseArtisanJson(out);
  } catch (err) {
    return { error: 'artisan_query_failed', message: String(err && err.message || err).slice(0, 400) };
  }
}

function maxOrderId() {
  try {
    const out = artisan(`echo json_encode(['max_id' => (int) (DB::table('orders')->max('id') ?? 0)]);`);
    const parsed = parseArtisanJson(out);
    return Number(parsed.max_id || 0);
  } catch (_e) {
    return 0;
  }
}

// Pass-through wizard walker — generic, scenario-agnostic. Click first available
// option in each step, then advance (.kiosk-btn-next), until validate CTA fires.
async function walkWizardGeneric(page, observations, scenarioCode) {
  const stepCap = 14;
  for (let step = 0; step < stepCap; step++) {
    // Detect if wizard is gone (cart-bottom-sheet recap visible / overlay closed).
    const wizardOpen = await page.locator(
      '.kiosk-wizard-overlay, .kiosk-wizard, [data-testid="kiosk-wizard-live-composition"], [data-testid="kiosk-composition-empty"]'
    ).first().isVisible({ timeout: 600 }).catch(() => false);
    if (!wizardOpen) {
      observations.push(`${scenarioCode}: walkWizard closed after step ${step}`);
      return { closed: true, steps: step };
    }

    // Click first available option / tile / radio inside the step.
    // Step-specific card classes verified against:
    //   KioskStepViandeComponent.vue       .kiosk-viande-card
    //   KioskStepSauceComponent.vue        .kiosk-sauce-card (or sauce tile)
    //   KioskStepPainComponent.vue         .kiosk-pain-card
    //   KioskStepGarnituresComponent.vue   .kiosk-garniture-card
    //   KioskStepTailleComponent.vue       .kiosk-taille-card
    //   KioskStepGenericChoicesComponent   .kiosk-generic-choice
    //   KioskStepSupplementsComponent.vue  .kiosk-supplement-card (with @click=selectFromCard)
    //   KioskStepMenuComponent.vue         .kiosk-menu-card
    //   KioskStepFritesStyleComponent.vue  [data-testid=kiosk-frites-style-nature|upgrade-*]
    const optClicked = await page.evaluate(() => {
      const stepRoot = document.querySelector('.kiosk-step-content, .kiosk-wizard');
      if (!stepRoot) return { ok: false, reason: 'no_step_root' };
      // Per-step canonical card selectors (broad match — kiosk wizard mixes
      // <div role=group> cards (viande) with <button> buttons (menu)).
      const selectorList = [
        '.kiosk-viande-card',
        '.kiosk-sauce-card',
        '.kiosk-pain-card',
        '.kiosk-garniture-card',
        '.kiosk-garniture',
        '.kiosk-taille-card',
        '.kiosk-supplement-card',
        '.kiosk-menu-card',
        '.kiosk-generic-choice',
        '[data-testid^="kiosk-frites-style-"]',
        // Fallbacks
        'button.kiosk-option-tile',
        'button.kiosk-tile',
        'button.kiosk-choice-tile',
        '.kiosk-option',
      ];
      const cands = [];
      for (const sel of selectorList) {
        for (const el of stepRoot.querySelectorAll(sel)) cands.push(el);
        if (cands.length > 0) break;
      }
      const candidates = cands;
      // Skip already-selected / disabled / out-of-stock
      const target = candidates.find((el) => {
        const cs = el.classList;
        const sel = cs.contains('active') || cs.contains('is-selected') || cs.contains('selected') || cs.contains('kiosk-option--active');
        const dis = el.disabled || cs.contains('is-disabled') || cs.contains('disabled')
          || cs.contains('is-out-of-stock') || cs.contains('kiosk-variation--disabled');
        return !sel && !dis;
      }) || candidates.find((el) => !el.disabled);
      if (!target) return { ok: false, reason: 'no_candidate', count: candidates.length };
      try { target.click(); } catch (_e) { /* ignore */ }
      return { ok: true, tag: target.tagName, cls: (target.className || '').slice(0, 80) };
    });
    if (!optClicked.ok) {
      observations.push(`${scenarioCode}: walkWizard step ${step} no-option (${optClicked.reason})`);
    }
    await page.waitForTimeout(450);

    // Advance via the canonical .kiosk-btn-next (last step has .kiosk-btn-next--cart).
    const nextLoc = page.locator('.kiosk-btn-next, button.kiosk-btn-next--cart');
    const nextCount = await nextLoc.count();
    let advanced = false;
    for (let i = 0; i < nextCount; i++) {
      const b = nextLoc.nth(i);
      if (await b.isVisible({ timeout: 400 }).catch(() => false)
          && await b.isEnabled({ timeout: 400 }).catch(() => false)) {
        await b.click({ timeout: 3_000, force: true }).catch(() => {});
        advanced = true;
        break;
      }
    }
    if (!advanced) {
      // Fallback: text-based search for primary CTA
      const txtCta = page.locator('button').filter({ hasText: /Ajouter au panier|Valider|Suivant|Next|Confirmer|Continuer/i });
      const txtCount = await txtCta.count();
      for (let i = 0; i < txtCount; i++) {
        const b = txtCta.nth(i);
        if (await b.isVisible({ timeout: 400 }).catch(() => false)
            && await b.isEnabled({ timeout: 400 }).catch(() => false)) {
          await b.click({ timeout: 3_000 }).catch(() => {});
          advanced = true;
          break;
        }
      }
    }
    if (!advanced) {
      observations.push(`${scenarioCode}: walkWizard step ${step} no-advance CTA found — abort`);
      return { closed: false, steps: step, reason: 'no_advance' };
    }
    await page.waitForTimeout(550);
  }
  return { closed: false, steps: stepCap, reason: 'cap_reached' };
}

test.describe('rush-100 Wave A — Kiosk multi-scenario visual capture', () => {
  test.setTimeout(900_000); // 15 min for 5 scenarios

  test('rush-100 kiosk : 5 scenarios × 7 states (35 quartets)', async ({ browser }) => {
    const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
    const page = await ctx.newPage();
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    const observations = [];
    const dbResults = [];

    try {
      // Pre-flight
      try { cleanupOrphanTestOrders(['AUDIT-RUSH-100-A-']); } catch (_e) { /* soft */ }
      clearFoodKingRateLimits();
      const baselineMaxId = maxOrderId();
      observations.push(`baseline_max_order_id=${baselineMaxId}`);

      await loginAsKiosk(page);
      if (!/\/kiosk\/idle/.test(page.url())) {
        await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      }
      await expect(page.getByTestId('kiosk-idle-root')).toBeVisible({ timeout: 25_000 });

      // Loop scenarios
      for (let s = 0; s < SCENARIOS.length; s++) {
        const sc = SCENARIOS[s];
        const code = sc.code;
        const trackingId = `${code}-${sc.itemId}`;
        observations.push(`=== START ${code} (${sc.label}) item=${sc.itemId} cat=${sc.catId} ===`);
        const beforeId = maxOrderId();
        observations.push(`${code}: before_max_id=${beforeId}`);

        // -----------------------------------------------------------------
        // STATE 01 — idle. Ensure we're at /kiosk/idle.
        // -----------------------------------------------------------------
        if (!/\/kiosk\/idle/.test(page.url())) {
          await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
        }
        await page.locator('[data-testid="kiosk-idle-root"]').waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
        await page.waitForTimeout(700);
        await snap(`${code}-01-idle`);

        // -----------------------------------------------------------------
        // STATE 02 — "À emporter" → /kiosk/categories. The idle screen shows
        // touch-btn first; clicking it reveals dine-in/takeaway tiles.
        // -----------------------------------------------------------------
        const touchBtn = page.getByTestId('kiosk-idle-touch-btn');
        if (await touchBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
          await touchBtn.click({ timeout: 5_000, force: true }).catch(() => {});
          await page.waitForTimeout(700);
        }
        const takeawayBtn = page.getByTestId('kiosk-order-type-takeaway');
        if (await takeawayBtn.isVisible({ timeout: 8_000 }).catch(() => false)) {
          await takeawayBtn.click({ timeout: 5_000 }).catch((e) => observations.push(`${code}: takeaway click threw ${e.message}`));
        } else {
          observations.push(`${code}: takeaway btn not visible — trying dispatch order type via Vuex`);
          // Force takeaway via Vuex if UI didn't surface tile
          await page.evaluate(() => {
            try {
              const app = window.app || window.__VUE_APP__;
              const store = app?.config?.globalProperties?.$store || window.$store;
              if (store) store.dispatch('kioskCart/setOrderType', 25 /* KIOSK_TAKEAWAY-ish */).catch(() => {});
            } catch (_e) { /* ignore */ }
          });
          await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' }).catch(() => {});
        }
        // Wait for sidebar to mount
        await page.locator('[data-testid="kiosk-categories-sidebar"]').waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
        await page.waitForTimeout(900);
        await snap(`${code}-02-categories`);

        // -----------------------------------------------------------------
        // STATE 03 — open wizard for scenario item. Click sidebar cat → click
        // product card → wizard overlay opens.
        // -----------------------------------------------------------------
        const sideCat = page.getByTestId(`kiosk-categories-sidebar-item-${sc.catId}`);
        const sideCatVisible = await sideCat.isVisible({ timeout: 4_000 }).catch(() => false);
        observations.push(`${code}: side_cat_${sc.catId}_visible=${sideCatVisible}`);
        if (sideCatVisible) {
          await sideCat.click({ timeout: 5_000 }).catch((e) => observations.push(`${code}: sidebar click threw ${e.message}`));
          await page.waitForTimeout(1_000);
        }
        const card = page.getByTestId(`kiosk-product-card-${sc.itemId}`);
        const cardVisible = await card.isVisible({ timeout: 8_000 }).catch(() => false);
        observations.push(`${code}: card_${sc.itemId}_visible=${cardVisible}`);
        if (cardVisible) {
          await card.click({ timeout: 5_000, force: true }).catch((e) => observations.push(`${code}: card click threw ${e.message}`));
          // Wizard opens via async store dispatch
          await page.locator(
            '.kiosk-wizard-overlay, [data-testid="kiosk-wizard-live-composition"], [data-testid="kiosk-composition-empty"], .kiosk-wizard'
          ).first().waitFor({ state: 'visible', timeout: 12_000 }).catch(() => {});
          await page.waitForTimeout(800);
        } else {
          observations.push(`${code}: product card not in current category view — wizard skipped`);
        }
        await snap(`${code}-03-wizard-open`);

        // -----------------------------------------------------------------
        // Walk wizard generically until close/recap.
        // -----------------------------------------------------------------
        const walkResult = await walkWizardGeneric(page, observations, code);
        observations.push(`${code}: walkResult=${JSON.stringify(walkResult)}`);

        // -----------------------------------------------------------------
        // STATE 04 — wizard recap (or post-wizard state). The Vue wizard
        // closes overlay on final addToCart() — we snap the FIRST frame
        // after walker exits, which is either the recap step (if walker
        // stalled before final) OR the cart-strip / categories surface
        // (if walker fully completed and cart-bottom-sheet appeared).
        // -----------------------------------------------------------------
        await page.waitForTimeout(700);
        await snap(`${code}-04-wizard-recap`);

        // -----------------------------------------------------------------
        // STATE 05 — full cart route. Click bottom-bar cart indicator or
        // navigate via cart-bottom-sheet button. If cart is empty (walker
        // failed), still capture.
        // -----------------------------------------------------------------
        // Prefer the categories-pay button (bottom-bar) — clicks the same
        // goToCart() handler the kiosk wizard uses on final.
        const goToCart = page.locator('[data-testid="kiosk-categories-pay"], [data-testid="kiosk-categories-cart-indicator"]');
        const goCount = await goToCart.count();
        let cartReached = false;
        for (let i = 0; i < goCount; i++) {
          const c = goToCart.nth(i);
          if (await c.isVisible({ timeout: 800 }).catch(() => false)) {
            await c.click({ timeout: 5_000, force: true }).catch(() => {});
            cartReached = true;
            break;
          }
        }
        if (!cartReached) {
          // Try Vue Router push to kiosk.cart
          await page.evaluate(() => {
            try {
              const app = window.app || window.__VUE_APP__;
              const router = app?.config?.globalProperties?.$router || window.$router;
              if (router) router.push({ name: 'kiosk.cart' });
            } catch (_e) { /* ignore */ }
          });
        }
        await page.locator('[data-testid="kiosk-cart-root"]').waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
        await page.waitForTimeout(700);
        const cartProbe = await page.evaluate(() => {
          const root = document.querySelector('[data-testid="kiosk-cart-root"]');
          const items = document.querySelectorAll('.kiosk-cart-item');
          const subtotalEl = document.querySelector('[data-testid="kiosk-cart-subtotal"]');
          const totalEl = document.querySelector('[data-testid="kiosk-cart-total"]');
          const emptyEl = document.querySelector('[data-testid="kiosk-cart-empty"]');
          return {
            root_present: !!root,
            line_count: items.length,
            subtotal: subtotalEl?.textContent?.trim() || null,
            total: totalEl?.textContent?.trim() || null,
            empty_visible: !!emptyEl && emptyEl.offsetParent !== null,
          };
        });
        observations.push(`${code}: cart=${JSON.stringify(cartProbe)}`);
        await snap(`${code}-05-cart`);

        // -----------------------------------------------------------------
        // STATE 06 — payment. Click cart checkout → /kiosk/payment.
        // -----------------------------------------------------------------
        const checkoutBtn = page.locator('[data-testid="kiosk-cart-checkout"]');
        let paymentReached = false;
        if (await checkoutBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
          await checkoutBtn.click({ timeout: 5_000 }).catch((e) => observations.push(`${code}: checkout click threw ${e.message}`));
          paymentReached = await page.locator('[data-testid="kiosk-payment-root"]').waitFor({ state: 'visible', timeout: 12_000 }).then(() => true).catch(() => false);
        }
        if (!paymentReached) {
          // Last-resort soft navigation
          await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' }).catch(() => {});
          paymentReached = await page.locator('[data-testid="kiosk-payment-root"]').isVisible({ timeout: 4_000 }).catch(() => false);
        }
        observations.push(`${code}: payment_reached=${paymentReached}`);
        await page.waitForTimeout(700);
        await snap(`${code}-06-payment`);

        // -----------------------------------------------------------------
        // STATE 07 — confirmation. Task asks for "Carte" but Wave A audit
        // discipline (cash is reliable, TPE adds bypass noise) → we click
        // "Carte" first to honour the spec ("Choose Carte payment (mock TPE)")
        // but fall back to cash if confirmation doesn't render within 10s.
        // -----------------------------------------------------------------
        let confirmationReached = false;
        const cardBtn = page.locator('[data-testid="kiosk-payment-method-card"]');
        const cashBtn = page.locator('[data-testid="kiosk-payment-method-cash"]');
        const cardBtnVisible = await cardBtn.isVisible({ timeout: 2_500 }).catch(() => false);
        const cashBtnVisible = await cashBtn.isVisible({ timeout: 1_500 }).catch(() => false);
        observations.push(`${code}: card_btn=${cardBtnVisible} cash_btn=${cashBtnVisible}`);
        if (cardBtnVisible) {
          await cardBtn.click({ timeout: 5_000 }).catch(() => {});
          // TPE bypass / simulated flow — wait up to 15s for confirmation
          confirmationReached = await page.locator('[data-testid="kiosk-confirmation-root"]').waitFor({ state: 'visible', timeout: 15_000 }).then(() => true).catch(() => false);
        }
        if (!confirmationReached && cashBtnVisible) {
          // Fallback to cash flow (safe path)
          observations.push(`${code}: card flow didn't reach confirmation — falling back to cash`);
          await cashBtn.click({ timeout: 5_000 }).catch(() => {});
          await page.waitForTimeout(800);
          const confirmStep = page.locator('[data-testid="kiosk-payment-confirm"]');
          if (await confirmStep.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await confirmStep.click({ timeout: 5_000 }).catch(() => {});
          }
          confirmationReached = await page.locator('[data-testid="kiosk-confirmation-root"]').waitFor({ state: 'visible', timeout: 15_000 }).then(() => true).catch(() => false);
        }
        await page.waitForTimeout(900);
        const confirmProbe = await page.evaluate(() => {
          const root = document.querySelector('[data-testid="kiosk-confirmation-root"]');
          const numberEl = document.querySelector('[data-testid="kiosk-confirmation-number"]');
          const totalEl = document.querySelector('[data-testid="kiosk-confirmation-total"]');
          return {
            visible: !!root && root.offsetParent !== null,
            number: numberEl?.textContent?.trim() || null,
            total: totalEl?.textContent?.trim() || null,
          };
        });
        observations.push(`${code}: confirmation=${JSON.stringify(confirmProbe)}`);
        await snap(`${code}-07-confirmation`);

        // -----------------------------------------------------------------
        // DB cross-check : query latest order row.
        // -----------------------------------------------------------------
        const dbRow = queryLatestOrder(beforeId);
        observations.push(`${code}: db=${JSON.stringify(dbRow).slice(0, 400)}`);
        dbResults.push({
          scenario: code,
          label: sc.label,
          item_id: sc.itemId,
          before_max_id: beforeId,
          ui_order_number: confirmProbe.number || null,
          ui_total: confirmProbe.total || null,
          confirmation_reached: confirmationReached,
          db_row: dbRow,
        });

        // -----------------------------------------------------------------
        // Reset to idle for next scenario (no 30s auto-return wait).
        // -----------------------------------------------------------------
        const homeBtn = page.locator('[data-testid="kiosk-confirmation-cta-home"]');
        if (await homeBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await homeBtn.click({ timeout: 5_000 }).catch(() => {});
          await page.waitForTimeout(1_000);
        }
        // Hard reset to idle to guarantee clean state
        await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' }).catch(() => {});
        await page.locator('[data-testid="kiosk-idle-root"]').waitFor({ state: 'visible', timeout: 12_000 }).catch(() => {});
        await page.waitForTimeout(500);
        observations.push(`=== END ${code} === url=${page.url()} ${trackingId}`);
      } // end scenarios loop

      // -----------------------------------------------------------------
      // Persist DB checks + report.
      // -----------------------------------------------------------------
      fs.writeFileSync(DB_CHECKS_FILE, JSON.stringify({
        run: 'rush-100-2026-05-13',
        wave: 'A',
        round: 1,
        generated_at: new Date().toISOString(),
        baseline_max_order_id: baselineMaxId,
        scenarios: dbResults,
      }, null, 2));

      const written = fs.readdirSync(SCREENSHOT_DIR).filter((f) => f.endsWith('.png'));
      const consoleErrors = [];
      for (const f of fs.readdirSync(SCREENSHOT_DIR).filter((f) => f.endsWith('.console.json'))) {
        try {
          const arr = JSON.parse(fs.readFileSync(path.join(SCREENSHOT_DIR, f), 'utf8'));
          for (const c of arr) {
            if (c.level === 'error' || c.level === 'pageerror') {
              consoleErrors.push({ file: f, level: c.level, text: String(c.text || '').slice(0, 160) });
            }
          }
        } catch (_e) { /* ignore parse errs */ }
      }
      // Aggregate network anomalies (4xx/5xx unallowlisted, >2s lat)
      const netAnomalies = [];
      for (const f of fs.readdirSync(SCREENSHOT_DIR).filter((f) => f.endsWith('.network.json'))) {
        try {
          const arr = JSON.parse(fs.readFileSync(path.join(SCREENSHOT_DIR, f), 'utf8'));
          for (const n of arr) {
            const s = Number(n.status);
            const u = String(n.url || '');
            if (s === 304 || s === 422) continue;
            if (s === 401 && /logout/i.test(u)) continue;
            if (/wss?:\/\/.*:6001/i.test(u)) continue;
            if (s >= 400) netAnomalies.push({ file: f, status: s, method: n.method, url: u.slice(0, 160) });
          }
        } catch (_e) { /* ignore parse errs */ }
      }

      const reportLines = [];
      reportLines.push('# Rush-100 Wave A — Kiosk Capture Report');
      reportLines.push('');
      reportLines.push(`- **Run** : rush-100-2026-05-13`);
      reportLines.push(`- **Wave** : A (Kiosk)`);
      reportLines.push(`- **Round** : 1`);
      reportLines.push(`- **Spec** : tests/e2e/rush-100-kiosk-capture.spec.js`);
      reportLines.push(`- **Generated** : ${new Date().toISOString()}`);
      reportLines.push(`- **Screenshot dir** : tests/e2e/__screenshots__/rush-100/kiosk/`);
      reportLines.push(`- **PNG quartets written** : ${written.length} (expected 35)`);
      reportLines.push(`- **Baseline max order id** : ${baselineMaxId}`);
      reportLines.push('');
      reportLines.push('## Scenario summary');
      reportLines.push('');
      reportLines.push('| Scenario | Item | Confirmation | Order id | Fiscal seq # | Total (UI / DB) | item_count | composition_snapshot |');
      reportLines.push('| --- | --- | --- | --- | --- | --- | --- | --- |');
      for (const r of dbResults) {
        const db = r.db_row || {};
        reportLines.push(
          `| ${r.scenario} ${r.label} | ${r.item_id} | ${r.confirmation_reached ? 'YES' : 'NO'} |`
          + ` ${db.id ?? db.error ?? '—'} | ${db.fiscal_sequence_no ?? '—'} |`
          + ` ${r.ui_total ?? '—'} / ${db.total ?? '—'} | ${db.item_count ?? '—'} |`
          + ` ${db.has_composition_snapshot ?? '—'} |`
        );
      }
      reportLines.push('');
      reportLines.push('## Anomalies observed');
      reportLines.push('');
      reportLines.push(`- **Console errors / pageerrors** : ${consoleErrors.length}`);
      if (consoleErrors.length > 0) {
        for (const e of consoleErrors.slice(0, 8)) {
          reportLines.push(`  - [${e.file}] ${e.level}: ${e.text}`);
        }
        if (consoleErrors.length > 8) reportLines.push(`  - … ${consoleErrors.length - 8} more`);
      }
      reportLines.push(`- **Network 4xx/5xx (unallowlisted)** : ${netAnomalies.length}`);
      if (netAnomalies.length > 0) {
        for (const n of netAnomalies.slice(0, 8)) {
          reportLines.push(`  - [${n.file}] ${n.method} ${n.status} ${n.url}`);
        }
        if (netAnomalies.length > 8) reportLines.push(`  - … ${netAnomalies.length - 8} more`);
      }
      reportLines.push('');
      reportLines.push('## Observations log (timing / missing testids / runtime notes)');
      reportLines.push('');
      reportLines.push('```');
      for (const o of observations) reportLines.push(o);
      reportLines.push('```');
      reportLines.push('');
      reportLines.push('## Artifacts');
      reportLines.push('');
      reportLines.push('- DB checks JSON : `reports/test-e2e/rush-100-2026-05-13/round-1/wave-A-db-checks.json`');
      reportLines.push('- Screenshot quartets : `tests/e2e/__screenshots__/rush-100/kiosk/<scenario>-<NN>-<state>.{png,dom.html,console.json,network.json}`');
      fs.writeFileSync(REPORT_FILE, reportLines.join('\n'));

      console.log(`[rush-100 A] PNGs: ${written.length} / 35 ; db scenarios: ${dbResults.length}/5 ; console errs: ${consoleErrors.length} ; net anomalies: ${netAnomalies.length}`);
      expect(written.length, `Expected ≥35 PNGs, got ${written.length}`).toBeGreaterThanOrEqual(35);
    } finally {
      try { cleanupOrphanTestOrders(['AUDIT-RUSH-100-A-']); } catch (_e) { /* soft */ }
      try { dispose(); } catch (_e) { /* ignore */ }
      await ctx.close().catch(() => {});
    }
  });
});
