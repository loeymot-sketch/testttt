// FoodKing — W-A/W-C GOAL ultra-audit 2026-06-10
// Test 1: encaisse 6 commandes pending en MIXTE (2 CASH, 2 CARD+réf, 2 TICKET-restaurant)
//         et prouve en DB le bon mode (order_payments.mode 1/2/5) + séquence fiscale gap-free.
// Test 2: RACE — double-encaissement simultané de la MÊME commande depuis 2 onglets :
//         exactement UN succès ; 1 seul order_payments ; 1 seul fiscal_sequence_no.
// Clone jetable UNIQUEMENT :
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-encaissement-mixed-race-2026-06-10.spec.js --retries=0

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../../reports/test-e2e/validation-100-2026-06-10/encaissement-mixed');
fs.mkdirSync(OUT, { recursive: true });
const REPO = path.resolve(__dirname, '../..');
function db(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], {
    cwd: REPO, encoding: 'utf8', timeout: 15_000,
  }).trim();
}

test.describe.configure({ mode: 'serial', timeout: 600_000 });

async function collectFirstPending(page, mode, shotName) {
  await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
  const firstBtn = page.locator('.enc-collect-btn').first();
  await expect(firstBtn, 'pending ticket visible').toBeVisible({ timeout: 25_000 });
  await firstBtn.click();
  const modal = page.locator('[data-testid="pos-counter-collect-modal"]');
  await expect(modal).toBeVisible({ timeout: 15_000 });

  const modeBtn = page.locator(`[data-testid="pos-counter-collect-mode-${mode}"]`);
  await expect(modeBtn, `mode ${mode} selectable`).toBeVisible({ timeout: 8_000 });
  await modeBtn.click();
  await page.waitForTimeout(300);

  if (mode === 'CASH') {
    await page.locator('[data-testid="pos-counter-collect-received-input"]').fill('999');
  } else if (mode === 'CARD') {
    const ref = page.locator('[data-testid="pos-counter-collect-card-ref-input"]');
    if (await ref.isVisible().catch(() => false)) await ref.fill('SUMUP-TEST-4242');
  }
  await page.waitForTimeout(200);
  if (shotName) await page.screenshot({ path: path.join(OUT, shotName) }).catch(() => {});

  const confirm = page.locator('[data-testid="pos-counter-collect-confirm"]');
  await expect(confirm).toBeEnabled({ timeout: 8_000 });
  const apiResp = page.waitForResponse(
    (r) => /\/admin\/pos\/counter-collect\/\d+\/confirm/i.test(r.url()) && r.request().method() === 'POST',
    { timeout: 25_000 },
  );
  await confirm.click();
  const resp = await apiResp;
  const m = resp.url().match(/counter-collect\/(\d+)\/confirm/);
  await expect(modal).toBeHidden({ timeout: 15_000 }).catch(() => {});
  await page.waitForTimeout(500);
  return { orderId: m ? m[1] : '?', status: resp.status() };
}

test('encaisser 6 commandes en modes mixtes (CASH/CARD/TICKET) — DB-prouvé', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  await loginAsAdmin(page);

  const plan = [
    { mode: 'CASH', expectDb: 1 }, { mode: 'CASH', expectDb: 1 },
    { mode: 'CARD', expectDb: 2 }, { mode: 'CARD', expectDb: 2 },
    { mode: 'TICKET', expectDb: 5 }, { mode: 'TICKET', expectDb: 5 },
  ];
  const results = [];
  for (let i = 0; i < plan.length; i++) {
    const r = await collectFirstPending(page, plan[i].mode, `mixed-${i + 1}-${plan[i].mode}.png`);
    console.log(`[MIX] ${i + 1}/6 mode=${plan[i].mode} order=${r.orderId} http=${r.status}`);
    expect([200, 201], `confirm ${plan[i].mode}`).toContain(r.status);
    results.push({ ...r, ...plan[i] });
  }

  for (const r of results) {
    const row = db(`SELECT CONCAT(mode,'|',amount,'|',IFNULL(reference,'')) FROM order_payments WHERE order_id=${r.orderId} ORDER BY id DESC LIMIT 1;`);
    console.log(`[MIX] order=${r.orderId} payment=${row}`);
    expect(row.split('|')[0], `order ${r.orderId} payment mode in DB`).toBe(String(r.expectDb));
    const fiscal = db(`SELECT fiscal_sequence_no FROM orders WHERE id=${r.orderId};`);
    expect(fiscal, `order ${r.orderId} fiscal allocated`).not.toBe('NULL');
    expect(fiscal).not.toBe('');
  }
  // fiscal gap-free over the 6
  const seqs = results.map((r) => parseInt(db(`SELECT fiscal_sequence_no FROM orders WHERE id=${r.orderId};`), 10)).sort((a, b) => a - b);
  for (let i = 1; i < seqs.length; i++) expect(seqs[i], `fiscal consecutive at ${i}`).toBe(seqs[i - 1] + 1);

  fs.writeFileSync(path.join(OUT, 'mixed-results.json'), JSON.stringify({ results, seqs, pageErrors }, null, 2));
  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});

test('RACE — double-encaissement simultané de la même commande = 1 seul succès', async ({ browser }) => {
  const ctxA = await browser.newContext();
  const ctxB = await browser.newContext();
  const pageA = await ctxA.newPage();
  const pageB = await ctxB.newPage();
  await loginAsAdmin(pageA);
  await loginAsAdmin(pageB);

  // find the next pending order both tabs will fight over
  const target = db(`SELECT id FROM orders WHERE payment_status=15 AND status IN (1,4,7,8) ORDER BY id ASC LIMIT 1;`);
  expect(target, 'a pending order exists for the race').not.toBe('');
  console.log(`[RACE] target order=${target}`);
  const payBefore = parseInt(db(`SELECT COUNT(*) FROM order_payments WHERE order_id=${target};`), 10);

  // open the SAME order's collect modal in both tabs
  async function openModalFor(page) {
    await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
    const btn = page.locator('.enc-collect-btn').first();
    await expect(btn).toBeVisible({ timeout: 25_000 });
    await btn.click();
    await expect(page.locator('[data-testid="pos-counter-collect-modal"]')).toBeVisible({ timeout: 15_000 });
    const cash = page.locator('[data-testid="pos-counter-collect-mode-CASH"]');
    if (await cash.isVisible().catch(() => false)) await cash.click();
    await page.locator('[data-testid="pos-counter-collect-received-input"]').fill('999');
    await page.waitForTimeout(200);
  }
  await openModalFor(pageA);
  await openModalFor(pageB);

  const respOf = (page) => page.waitForResponse(
    (r) => /\/admin\/pos\/counter-collect\/\d+\/confirm/i.test(r.url()) && r.request().method() === 'POST',
    { timeout: 30_000 },
  ).then((r) => r.status()).catch(() => -1);

  const [ra, rb] = await Promise.all([
    (async () => { const p = respOf(pageA); await pageA.locator('[data-testid="pos-counter-collect-confirm"]').click(); return p; })(),
    (async () => { const p = respOf(pageB); await pageB.locator('[data-testid="pos-counter-collect-confirm"]').click(); return p; })(),
  ]);
  console.log(`[RACE] responses A=${ra} B=${rb}`);
  await pageA.screenshot({ path: path.join(OUT, 'race-A.png') }).catch(() => {});
  await pageB.screenshot({ path: path.join(OUT, 'race-B.png') }).catch(() => {});

  const successes = [ra, rb].filter((s) => s >= 200 && s < 300).length;
  // server must accept at most one mutation for the same order
  const payAfter = parseInt(db(`SELECT COUNT(*) FROM order_payments WHERE order_id=${target};`), 10);
  const fiscalCount = parseInt(db(`SELECT COUNT(DISTINCT fiscal_sequence_no) FROM orders WHERE id=${target} AND fiscal_sequence_no IS NOT NULL;`), 10);
  const dupSeq = db(`SELECT COUNT(*) FROM orders o1 JOIN orders o2 ON o1.fiscal_sequence_no=o2.fiscal_sequence_no AND o1.id<o2.id AND o1.branch_id=o2.branch_id WHERE o1.id=${target} OR o2.id=${target};`);
  console.log(`[RACE] successes=${successes} payments ${payBefore}->${payAfter} fiscalDistinct=${fiscalCount} dupSeq=${dupSeq}`);

  fs.writeFileSync(path.join(OUT, 'race-results.json'), JSON.stringify({ target, ra, rb, successes, payBefore, payAfter, fiscalCount, dupSeq }, null, 2));

  expect(payAfter - payBefore, 'exactly ONE payment row created').toBe(1);
  expect(fiscalCount, 'exactly one fiscal seq on the order').toBe(1);
  expect(dupSeq, 'no duplicated fiscal sequence with any other order').toBe('0');
  expect(successes, 'at most one 2xx between the two racers').toBeLessThanOrEqual(1);

  await ctxA.close(); await ctxB.close();
});
