// AGENT CLUSTER-PAY — drive non-CASH counter-collect by SPECIFIC order id (axios),
// then a Bash DB harness verifies the NF525 money-path invariants.
// Disposable clone ONLY:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-cluster-pay-nonash-2026-06-07.spec.js --retries=0 --timeout=70000
//
// Order ids are passed via env CP_CARD / CP_TICKET / CP_MOBILE so the harness
// can pick fresh PENDING_COUNTER ids each run (modal is FIFO-capped at 200).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { generatePureUuid } = require('./helpers/idempotency-key');

const OUT = path.resolve(__dirname, '__screenshots__', 'cluster-pay-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

// PosPaymentMethod: CASH=1 CARD=2 MOBILE_BANKING=3 OTHER=4 TICKET_RESTAURANT=5
const JOBS = [
  { tag: 'card',   mode: 2, id: process.env.CP_CARD },
  { tag: 'ticket', mode: 5, id: process.env.CP_TICKET },
  { tag: 'mobile', mode: 3, id: process.env.CP_MOBILE },
];

test('drive non-CASH counter-collect via authenticated axios by id', async ({ page }) => {
  await loginAsAdmin(page);
  const results = {};

  for (const job of JOBS) {
    if (!job.id) { results[job.tag] = { skipped: 'no id' }; continue; }
    const key = generatePureUuid();
    const out = await page.evaluate(async ({ id, mode, key }) => {
      // page axios carries the bearer token + XSRF; mirror prior zz specs
      const ax = window.axios || (await import('/js/app.js').then(() => window.axios));
      try {
        // axios.defaults.baseURL is already API_URL + '/api'; use the modal's relative path
        const resp = await ax.post(`admin/pos/counter-collect/${id}/confirm`,
          { mode, received: null, note: 'cluster-pay nonash audit' },
          { headers: { 'X-Idempotency-Key': key } });
        return { status: resp.status, data: resp.data };
      } catch (e) {
        return { status: e?.response?.status ?? 0, data: e?.response?.data ?? { err: String(e) } };
      }
    }, { id: job.id, mode: job.mode, key });
    const o = out.data?.data ?? out.data ?? {};
    console.log(`[CP] ${job.tag} id=${job.id} -> HTTP ${out.status} pos_payment_method=${o.pos_payment_method ?? '?'} payment_status=${o.payment_status ?? '?'} fiscal=${o.fiscal_sequence_no ?? '?'}`);
    results[job.tag] = { id: job.id, http: out.status, pos_payment_method: o.pos_payment_method, payment_status: o.payment_status, fiscal_sequence_no: o.fiscal_sequence_no, raw: out.data };
    expect([200, 201], `[${job.tag}] confirm HTTP`).toContain(out.status);
  }

  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
  console.log('[CP] RESULTS ' + JSON.stringify(results));
});
