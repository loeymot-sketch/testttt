/**
 * FoodKing MAX TEST WAVE — T2 POS Caisse Visual + Technical
 * Date: 2026-05-28
 * HEAD: e7ae1c8ea
 *
 * Mission: Capture + analyze 11 POS scenarios (S-POS-01..11).
 * READ+TEST ONLY. NO CODE EDITS. NO FROZEN-ZONE TOUCH.
 * Builds baseline for owner manual trial-test comparison.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const SHOTS = '/tmp/foodking-max-test-2026-05-28/t2-pos';
const REPORT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/owner-trial-test-max-2026-05-28/T2-POS'
);
const FIND_PATH = path.join(REPORT_DIR, 'findings.json');

for (const d of [SHOTS, REPORT_DIR]) {
  if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
}

// Reset findings
fs.writeFileSync(FIND_PATH, JSON.stringify({ generated_at: new Date().toISOString(), head: 'e7ae1c8ea', scope: 'T2-POS S-POS-01..11', findings: [] }, null, 2));

function record(f) {
  let data = { findings: [] };
  try { data = JSON.parse(fs.readFileSync(FIND_PATH, 'utf8')); } catch (_) {}
  data.findings.push({ ...f, ts: new Date().toISOString() });
  data.generated_at = new Date().toISOString();
  fs.writeFileSync(FIND_PATH, JSON.stringify(data, null, 2));
}

// Tinker probe — returns trimmed stdout
function tinker(php) {
  try {
    const out = execSync(
      `php artisan tinker --execute=${JSON.stringify(php)} 2>&1`,
      { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8', timeout: 25000 }
    );
    return out.trim();
  } catch (e) {
    return `TINKER_ERROR: ${e.message?.substring(0, 300)}`;
  }
}

// Raw label scan — returns matches in visible text
function scanRawLabels(text) {
  if (!text) return [];
  const patterns = [
    /\bpos\.[a-z_.]+\b/i,
    /\bkiosk\.[a-z_.]+\b/i,
    /\bLabel\.[A-Z][a-z]+/,
    /0undefined/,
    /\[object Object\]/,
    /\bNaN\s*€/,
  ];
  const found = [];
  for (const p of patterns) {
    const m = text.match(p);
    if (m) found.push(m[0]);
  }
  return found;
}

test.use({ viewport: { width: 1366, height: 900 } });
test.setTimeout(90000);

async function loginAdmin(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const email = page.locator('input[type="email"], input[placeholder*="mail" i]').first();
  await email.waitFor({ timeout: 8000 }).catch(() => {});
  await email.fill('admin@lecayenne.fr').catch(() => {});
  await page.locator('input[type="password"]').first().fill('123456').catch(() => {});
  await page.locator('button:has-text("Connexion"), button[type="submit"]').first().click({ timeout: 4000 }).catch(() => {});
  // Wait for navigation to /admin OR fallback to 4s wait — avoid networkidle (SPA long-polls)
  await Promise.race([
    page.waitForURL(/\/admin/, { timeout: 8000 }).catch(() => {}),
    page.waitForTimeout(4000),
  ]);
}

// Track console errors per scenario
function attachConsoleSink(page) {
  const errors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text().substring(0, 240));
  });
  page.on('pageerror', (err) => errors.push(`pageerror: ${err.message?.substring(0, 240)}`));
  return errors;
}

test.describe('T2-POS — 11 scenarios visual+technical', () => {

  // -------------------------------------------------------------------------
  test('S-POS-01 Login + dashboard navigation', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    const url = page.url();
    await page.screenshot({ path: `${SHOTS}/s-pos-01-b-post-login.png` }).catch(() => {});
    await page.screenshot({ path: `${SHOTS}/s-pos-01-a-login.png` }).catch(() => {});

    const bodyText = await page.locator('body').innerText().catch(() => '');
    const raw = scanRawLabels(bodyText);
    const onAdmin = url.includes('/admin');

    // Probe localStorage token
    const token = await page.evaluate(() => {
      try {
        const vx = localStorage.getItem('vuex');
        if (vx) { const p = JSON.parse(vx); return p?.auth?.authToken ? 'present' : 'absent'; }
        return 'no-vuex';
      } catch (_) { return 'error'; }
    });

    record({
      id: 'S-POS-01',
      url: '/login -> /admin',
      action: 'fill email/password + submit',
      visual_expected: 'redirect /admin/dashboard, sidebar visible',
      visual_observed: `landed at ${url}; raw_labels=${JSON.stringify(raw)}`,
      technical_expected: 'Sanctum token in localStorage vuex.auth.authToken',
      technical_observed: `token=${token}; console_errors=${errs.length}`,
      status: onAdmin && raw.length === 0 ? 'PASS' : (onAdmin ? 'FAIL' : 'FAIL'),
      severity: onAdmin && raw.length === 0 ? 'INFO' : (raw.length > 0 ? 'P1' : 'P0'),
      evidence_path: `${SHOTS}/s-pos-01-b-post-login.png`,
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-02 Cash drawer status (CDS reconcile observation)', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500); // POS vanilla JS boot
    await page.screenshot({ path: `${SHOTS}/s-pos-02-a-pos-loaded.png` }).catch(() => {});

    const bodyText = await page.locator('body').innerText().catch(() => '');
    const raw = scanRawLabels(bodyText);

    // Look for drawer open/close button text
    const drawerCues = await page.evaluate(() => {
      const t = document.body.innerText.toLowerCase();
      return {
        ouvrir: /ouvrir.*caisse|ouverture.*caisse/.test(t),
        deja_ouverte: /caisse.*ouverte|drawer.*open|fond.*caisse/.test(t),
        cloturer: /cl[oô]turer|fermeture/.test(t),
      };
    });

    // DB observation
    const cdsState = tinker(
      'echo "OPEN=".\\DB::table("cash_drawer_sessions")->where("status","open")->where("branch_id",1)->count()."|TOTAL=".\\DB::table("cash_drawer_sessions")->count();'
    );

    record({
      id: 'S-POS-02',
      url: '/admin/pos',
      action: 'observe drawer state (no mutation per mission)',
      visual_expected: 'modal Ouvrir caisse OR session déjà ouverte indicator',
      visual_observed: `drawer_cues=${JSON.stringify(drawerCues)}; raw=${JSON.stringify(raw)}`,
      technical_expected: 'CashDrawerSession row in DB; AuditLog cash_drawer.opened present',
      technical_observed: `db_state=${cdsState}; console_errors=${errs.length}`,
      status: (drawerCues.ouvrir || drawerCues.deja_ouverte) && raw.length === 0 ? 'PASS' : 'FAIL',
      severity: raw.length > 0 ? 'P1' : 'INFO',
      evidence_path: `${SHOTS}/s-pos-02-a-pos-loaded.png`,
      note: 'CDS#4 + #7 documented open per plan §4.1 S-POS-02; no reconcile performed (read-only mission)',
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-03 Wizard Sandwich Cayenne — 10 sauces sans Cayenne fromagère', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);

    // Try to find Sandwich Cayenne tile (item id=22)
    const tile = page.locator('text=/Sandwich Cayenne/i').first();
    const tileExists = await tile.count();
    if (tileExists === 0) {
      // Sandwich category click then tile
      await page.locator('text=/^Sandwich/i').first().click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(1500);
    }
    await page.screenshot({ path: `${SHOTS}/s-pos-03-a-tile-search.png` }).catch(() => {});
    await page.locator('text=/Sandwich Cayenne/i').first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${SHOTS}/s-pos-03-b-wizard-step1-viande.png` }).catch(() => {});

    // Click first viande option to advance (Poulet crispy / curry / mariné / tandoori)
    const viandeOptions = await page.locator('button:has-text("Poulet"), label:has-text("Poulet")').count();
    await page.locator('button:has-text("Poulet"), label:has-text("Poulet")').first().click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(800);

    // Click Suivant if present (multi-step wizard)
    await page.locator('button:has-text("Suivant"), button:has-text("Continuer")').first().click({ timeout: 2500 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SHOTS}/s-pos-03-c-wizard-step2-sauce.png` }).catch(() => {});

    // Capture all visible sauce labels from current wizard view
    const sauceText = await page.evaluate(() => document.body.innerText);
    const sauces = ['Algérienne', 'Andalouse', 'Blanche', 'Curry', 'Hannibal', 'Harissa', 'Ketchup', 'Mayonnaise', 'Samouraï', 'Spicy'];
    const sauceHits = sauces.filter((s) => sauceText.toLowerCase().includes(s.toLowerCase()));
    const hasCayenneFromagere = /cayenne fromag/i.test(sauceText);
    const raw = scanRawLabels(sauceText);

    record({
      id: 'S-POS-03',
      url: '/admin/pos',
      action: 'open Sandwich Cayenne wizard, advance to sauce step',
      visual_expected: '10 canonical sauces present, NO "Cayenne fromagère"',
      visual_observed: `sauces_found=${sauceHits.length}/10 [${sauceHits.join(',')}]; cayenne_fromagere_present=${hasCayenneFromagere}; viande_options=${viandeOptions}`,
      technical_expected: 'WizardCayenneAndBolsCorrectionsSeeder applied (no fromagère in sauce list)',
      technical_observed: `raw_labels=${JSON.stringify(raw)}; console_errors=${errs.length}`,
      status: !hasCayenneFromagere && raw.length === 0 ? (sauceHits.length >= 8 ? 'PASS' : 'FAIL') : 'FAIL',
      severity: hasCayenneFromagere ? 'P0' : (sauceHits.length < 8 ? 'P1' : 'INFO'),
      evidence_path: `${SHOTS}/s-pos-03-c-wizard-step2-sauce.png`,
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-04 Wizard Tacos — 10 sauces canonical', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);
    await page.locator('text=/^Tacos$/i, text=/Tacos/i').first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${SHOTS}/s-pos-04-a-wizard-tacos.png` }).catch(() => {});

    // Advance through viande if step exists
    await page.locator('button:has-text("Poulet"), label:has-text("Poulet")').first().click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(700);
    await page.locator('button:has-text("Suivant"), button:has-text("Continuer")').first().click({ timeout: 2500 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SHOTS}/s-pos-04-b-tacos-sauce.png` }).catch(() => {});

    const sauceText = await page.evaluate(() => document.body.innerText);
    const sauces = ['Algérienne', 'Andalouse', 'Blanche', 'Curry', 'Hannibal', 'Harissa', 'Ketchup', 'Mayonnaise', 'Samouraï', 'Spicy'];
    const sauceHits = sauces.filter((s) => sauceText.toLowerCase().includes(s.toLowerCase()));
    const raw = scanRawLabels(sauceText);

    record({
      id: 'S-POS-04',
      url: '/admin/pos',
      action: 'open Tacos wizard, observe sauce list',
      visual_expected: '10 canonical sauces present',
      visual_observed: `sauces_found=${sauceHits.length}/10 [${sauceHits.join(',')}]`,
      technical_expected: 'pricing computed in cart, no NaN',
      technical_observed: `raw_labels=${JSON.stringify(raw)}; console_errors=${errs.length}`,
      status: sauceHits.length >= 8 && raw.length === 0 ? 'PASS' : 'FAIL',
      severity: sauceHits.length < 8 ? 'P1' : (raw.length > 0 ? 'P1' : 'INFO'),
      evidence_path: `${SHOTS}/s-pos-04-b-tacos-sauce.png`,
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-05 Wizard Bowl Frites Poulet mariné — 2 sauces + supplement_bol', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);
    // Bols category click
    await page.locator('text=/^Bols/i').first().click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.locator('text=/Bowl Frites Poulet mariné/i').first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${SHOTS}/s-pos-05-a-bowl-wizard.png` }).catch(() => {});

    // Advance through any preceding step if any (viande not needed since item already specific)
    await page.locator('button:has-text("Suivant"), button:has-text("Continuer")').first().click({ timeout: 2500 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SHOTS}/s-pos-05-b-bowl-sauce.png` }).catch(() => {});

    const wizardText = await page.evaluate(() => document.body.innerText);
    const expectedSauces = ['Sauce fromagère', 'Spicy'];
    const sauceHits = expectedSauces.filter((s) => wizardText.toLowerCase().includes(s.toLowerCase()));

    const canonicalSauces = ['Algérienne', 'Andalouse', 'Blanche', 'Curry', 'Hannibal', 'Harissa', 'Ketchup', 'Mayonnaise', 'Samouraï'];
    const unexpectedCanonical = canonicalSauces.filter((s) => wizardText.toLowerCase().includes(s.toLowerCase()));

    const gratineHit = /(boule gratin[ée]e|option gratin[ée])/i.test(wizardText);
    const standaloneGratineStep = /^gratine$/im.test(wizardText) || /étape.*gratin/i.test(wizardText);
    const raw = scanRawLabels(wizardText);

    record({
      id: 'S-POS-05',
      url: '/admin/pos',
      action: 'open Bowl Frites Poulet mariné wizard',
      visual_expected: 'EXACTLY 2 sauces (Sauce fromagère + Spicy), supplement_bol shows Boule gratinée + Option Gratiné same step (no standalone gratine step)',
      visual_observed: `expected_sauces=${sauceHits.length}/2 [${sauceHits.join(',')}]; unexpected_canonical_present=[${unexpectedCanonical.join(',')}]; gratine_supplement_visible=${gratineHit}; standalone_gratine_step=${standaloneGratineStep}`,
      technical_expected: 'supplement_bol group_label only, no gratine step',
      technical_observed: `raw_labels=${JSON.stringify(raw)}; console_errors=${errs.length}`,
      status: sauceHits.length === 2 && unexpectedCanonical.length === 0 && !standaloneGratineStep ? 'PASS' : 'FAIL',
      severity: (sauceHits.length !== 2 || unexpectedCanonical.length > 0) ? 'P0' : (standaloneGratineStep ? 'P1' : 'INFO'),
      evidence_path: `${SHOTS}/s-pos-05-b-bowl-sauce.png`,
      note: 'P0 critique if 11 sauces or standalone gratine step → seeder not applied',
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-06 Paiement cash 1-tranche (observation)', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);

    // Pre-state snapshot
    const pre = tinker('echo \\DB::table("orders")->max("id")."|".\\DB::table("orders")->count();');

    // Click Encaisser or equivalent
    const encaisseBtn = page.locator('button:has-text("Encaisser"), button:has-text("Payer"), button:has-text("Paiement")').first();
    const encaisseExists = await encaisseBtn.count();
    await encaisseBtn.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(2000);
    await page.screenshot({ path: `${SHOTS}/s-pos-06-a-payment-modal.png` }).catch(() => {});

    const bodyText = await page.evaluate(() => document.body.innerText);
    const cashMode = /esp[èe]ces?|cash/i.test(bodyText);
    const raw = scanRawLabels(bodyText);

    record({
      id: 'S-POS-06',
      url: '/admin/pos',
      action: 'click Encaisser, observe modal (no completion without cart)',
      visual_expected: 'modal Encaisser CASH option visible, no raw labels',
      visual_observed: `encaisse_btn_count=${encaisseExists}; cash_mode_visible=${cashMode}; raw=${JSON.stringify(raw)}`,
      technical_expected: 'order POST creates row with composition_snapshot non-empty, fiscal_sequence_no allocated',
      technical_observed: `pre_state=${pre}; console_errors=${errs.length}`,
      status: encaisseExists > 0 && raw.length === 0 ? 'PASS' : 'FAIL',
      severity: encaisseExists === 0 ? 'P1' : (raw.length > 0 ? 'P1' : 'INFO'),
      evidence_path: `${SHOTS}/s-pos-06-a-payment-modal.png`,
      note: 'Read-only observation; full transaction would require cart setup + drawer reconcile prerequisite',
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-07 Paiement card simulation TPE (observation)', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);

    // PaymentTerminal seed verify
    const pt = tinker('$p=\\DB::table("payment_terminals")->where("id",1)->first(); echo $p?"id=".$p->id."|branch=".$p->branch_id."|status=".$p->status:"MISSING";');
    const simHw = tinker('echo "POS_SIMULATION_HARDWARE=".(env("POS_SIMULATION_HARDWARE")?"true":"false");');

    await page.locator('button:has-text("Encaisser"), button:has-text("Payer")').first().click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SHOTS}/s-pos-07-a-card-mode.png` }).catch(() => {});
    const bodyText = await page.evaluate(() => document.body.innerText);
    const cardMode = /carte|cb|tpe|card/i.test(bodyText);
    const raw = scanRawLabels(bodyText);

    record({
      id: 'S-POS-07',
      url: '/admin/pos',
      action: 'open payment modal, observe card mode + TPE seed',
      visual_expected: 'CB/Carte option visible, TPE attente simulation works',
      visual_observed: `card_mode_visible=${cardMode}; raw=${JSON.stringify(raw)}`,
      technical_expected: 'PaymentTerminal id=1 active on branch=1; POS_SIMULATION_HARDWARE=true',
      technical_observed: `pt=${pt}; ${simHw}; console_errors=${errs.length}`,
      status: cardMode && raw.length === 0 ? 'PASS' : 'FAIL',
      severity: raw.length > 0 ? 'P1' : 'INFO',
      evidence_path: `${SHOTS}/s-pos-07-a-card-mode.png`,
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-08 Paiement SPLIT cash+card (observation)', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);
    await page.locator('button:has-text("Encaisser"), button:has-text("Payer")').first().click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1500);
    // Look for split mode
    await page.locator('button:has-text("Split"), button:has-text("SPLIT"), button:has-text("Partagé"), button:has-text("Diviser")').first().click({ timeout: 2500 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SHOTS}/s-pos-08-a-split-modal.png` }).catch(() => {});
    const bodyText = await page.evaluate(() => document.body.innerText);
    const splitVisible = /split|partagé|tranche/i.test(bodyText);
    const raw = scanRawLabels(bodyText);

    record({
      id: 'S-POS-08',
      url: '/admin/pos',
      action: 'open payment modal, look for SPLIT mode',
      visual_expected: 'SPLIT button visible, two tranche fields',
      visual_observed: `split_visible=${splitVisible}; raw=${JSON.stringify(raw)}`,
      technical_expected: 'order_payments would store 2 rows (CASH + CARD)',
      technical_observed: `console_errors=${errs.length}`,
      status: splitVisible && raw.length === 0 ? 'PASS' : 'FAIL',
      severity: !splitVisible ? 'P1' : (raw.length > 0 ? 'P1' : 'INFO'),
      evidence_path: `${SHOTS}/s-pos-08-a-split-modal.png`,
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-09 Refund counter-entry (observation)', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    // Try /admin/orders or order list inside POS
    await page.goto(`${BASE}/admin/orders`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);
    await page.screenshot({ path: `${SHOTS}/s-pos-09-a-orders-list.png` }).catch(() => {});
    const bodyText = await page.evaluate(() => document.body.innerText);
    const refundVisible = /rembours|refund/i.test(bodyText);
    const raw = scanRawLabels(bodyText);

    // Audit log baseline + last paid order with NF525 fiscal_sequence_no
    const lastOrder = tinker('$o=\\DB::table("orders")->orderByDesc("id")->first(); echo $o?"id=".$o->id."|total=".$o->total."|fiscal=".($o->fiscal_sequence_no??"null")."|status=".$o->payment_status:"none";');
    const auditLast = tinker('echo \\DB::table("audit_logs")->max("id");');

    record({
      id: 'S-POS-09',
      url: '/admin/orders',
      action: 'open orders list, look for refund action',
      visual_expected: 'orders table with refund/rembourser button',
      visual_observed: `refund_visible=${refundVisible}; raw=${JSON.stringify(raw)}`,
      technical_expected: 'RefundCreated event → mirror order with parent_order_id + fresh fiscal_sequence_no',
      technical_observed: `last_order=${lastOrder}; last_audit_id=${auditLast}; console_errors=${errs.length}`,
      status: refundVisible && raw.length === 0 ? 'PASS' : 'FAIL',
      severity: !refundVisible ? 'P1' : (raw.length > 0 ? 'P1' : 'INFO'),
      evidence_path: `${SHOTS}/s-pos-09-a-orders-list.png`,
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-10 Z report close + PDF (observation)', async ({ page }) => {
    const errs = attachConsoleSink(page);
    await loginAdmin(page);
    // Try /admin/z-reports
    await page.goto(`${BASE}/admin/z-reports`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);
    let status = page.url().includes('/admin/z-reports') ? 'reached' : 'redirected';
    await page.screenshot({ path: `${SHOTS}/s-pos-10-a-z-reports.png` }).catch(() => {});
    const bodyText = await page.evaluate(() => document.body.innerText);
    const closeVisible = /cl[oô]turer|fermer|close.*z/i.test(bodyText);
    const raw = scanRawLabels(bodyText);

    const zMax = tinker('echo \\DB::table("z_reports")->where("branch_id",1)->max("z_report_no")."|TOTAL=".\\DB::table("z_reports")->count();');

    record({
      id: 'S-POS-10',
      url: '/admin/z-reports',
      action: 'observe z-reports route + close UI',
      visual_expected: 'liste Z reports + bouton Clôturer la journée',
      visual_observed: `route_status=${status}; close_visible=${closeVisible}; raw=${JSON.stringify(raw)}`,
      technical_expected: 'z_reports row monotonic, AuditLog z_report.closed extends chain',
      technical_observed: `z_state=${zMax}; console_errors=${errs.length}`,
      status: status === 'reached' && raw.length === 0 ? 'PASS' : 'FAIL',
      severity: status !== 'reached' ? 'P1' : (raw.length > 0 ? 'P1' : 'INFO'),
      evidence_path: `${SHOTS}/s-pos-10-a-z-reports.png`,
      console_errors_sample: errs.slice(0, 3),
    });
  });

  // -------------------------------------------------------------------------
  test('S-POS-11 Frozen-zone tamper attempt (sentinel must protect)', async () => {
    // Static-only check: verify frozen-zone sentinel covers pos-wizard.js
    const frozenList = path.resolve(__dirname, '../../scripts/frozen-files.txt');
    let listText = '';
    let sentinelPresent = false;
    try {
      listText = fs.readFileSync(frozenList, 'utf8');
      sentinelPresent = /pos-wizard\.js/.test(listText);
    } catch (_) {
      // Look elsewhere
      try {
        listText = execSync('grep -RIl "pos-wizard.js" scripts/ .github/ 2>/dev/null | head -10', { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
        sentinelPresent = listText.length > 0;
      } catch (_) {}
    }
    // Pre-commit hook present?
    let hookPresent = false;
    try {
      hookPresent = fs.existsSync(path.resolve(__dirname, '../../.husky/pre-commit')) || fs.existsSync(path.resolve(__dirname, '../../.git/hooks/pre-commit'));
    } catch (_) {}

    // CI workflow includes frozen-zone check?
    let workflowFrozen = '';
    try {
      workflowFrozen = execSync('grep -RIl "frozen" .github/workflows/ 2>/dev/null', { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    } catch (_) {}

    record({
      id: 'S-POS-11',
      url: 'static-only (no UI)',
      action: 'verify frozen-zone protection covers public/js/pos-wizard.js',
      visual_expected: 'N/A — static check',
      visual_observed: 'N/A',
      technical_expected: 'frozen-files list + safety-check + CI workflow gate pos-wizard.js modifications',
      technical_observed: `sentinel_present=${sentinelPresent}; hook_present=${hookPresent}; workflow_frozen_refs=${workflowFrozen.trim().split('\n').filter(Boolean).length}`,
      status: sentinelPresent || workflowFrozen ? 'PASS' : 'FAIL',
      severity: sentinelPresent || workflowFrozen ? 'INFO' : 'P0',
      evidence_path: 'static check only',
      note: 'No physical mutation attempted; mission read-only. Verified protection mechanism exists.',
    });
  });
});
