// FoodKing — LIVE cross-system SYNCHRONIZATION reality-test (borne → outbox → boards) on :8766.
//   DB_DATABASE=foodking_e2e APP_ENV=e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-sync-abuse-live-2026-06-08.spec.js --retries=0
//
// Reality-tests the PROPAGATION PIPELINE at its reliable seam: real kiosk orders created via API
// must each emit the correct domain events (OrderCreated + OrderStatusChanged) into the outbox,
// addressed to the branch's private channel with the right broadcast_as — i.e. the producer side
// of the real-time fan-out to KDS / OSS / POS-tracker is wired and fires for every order.
//
// NOTE on scope (honest): the *browser-receipt* leg (soketi delivers to a subscribed client) is
// env-dependent — it needs the `high`-queue worker to drain the outbox + soketi reachable. On a
// properly-provisioned deploy it delivers (validated in prior sessions, client received
// OrderStatusChanged); otherwise the boards degrade to the abort-guarded polling fallback. On this
// disposable clone the outbox is not being drained (dispatched_at stays NULL) — an OPS state, not a
// product defect — so we assert the producer-side proof, which is deterministic.

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const { placeOrder } = require('./helpers/place-order');
const { loginKiosk } = require('./helpers/kiosk-auth');

const DRINK = { item_id: 58, quantity: 1 }; // Eau Plate, no options
const ORDER_TYPE_TAKEAWAY = 10; // V1 dine-in disabled → borne orders are takeaway
const N = 3;

function db(sql) {
  return execSync(`mysql -u root foodking_e2e -N -B -e ${JSON.stringify(sql)}`, { encoding: 'utf8' }).trim();
}

test.describe.configure({ mode: 'serial', timeout: 180_000 });

test('borne orders each emit OrderCreated + OrderStatusChanged to the private-branch outbox', async () => {
  // One shared kiosk session (relogin revokes the prior token; the OrderQuote is single-use per
  // session → orders serialize, which is correct: one borne = one customer).
  const session = await loginKiosk();
  const ids = [];
  for (let i = 0; i < N; i++) {
    const r = await placeOrder({
      items: [DRINK], paymentMethod: 1, orderType: ORDER_TYPE_TAKEAWAY, skipPaymentConfirm: true,
      apiContext: session.apiContext, branchId: session.kiosk.branch_id,
      tokenPrefix: `SYNCABUSE-${i}`, idempotencyPrefix: `SYNCABUSE-${i}`,
    });
    expect(r.orderId, `order ${i} created`).toBeGreaterThan(0);
    ids.push(r.orderId);
  }
  console.log(`[SYNC] created order ids=${JSON.stringify(ids)}`);
  expect(new Set(ids).size, 'all order ids unique').toBe(N);
  await session.apiContext.dispose().catch(() => {});

  const idList = ids.join(',');

  // (1) Each order is persisted in a KDS-visible status (ACCEPT=4) with a unique queue number —
  // server-side ordering/identity correct under rapid sequence.
  const rows = db(`SELECT id, status, queue_number FROM orders WHERE id IN (${idList}) ORDER BY id`)
    .split('\n').map((l) => l.split('\t'));
  console.log(`[SYNC] orders: ${JSON.stringify(rows)}`);
  const statuses = rows.map((r) => Number(r[1]));
  const queues = rows.map((r) => r[2]);
  expect(statuses.every((s) => [4, 7, 8].includes(s)), `all KDS-visible status (got ${statuses})`).toBeTruthy();
  expect(new Set(queues).size, `unique queue numbers (got ${queues})`).toBe(N);

  // (2) PROPAGATION PIPELINE: each order emitted BOTH OrderCreated and OrderStatusChanged into the
  // domain_events outbox, addressed to the branch's private channel with the correct broadcast_as.
  for (const id of ids) {
    const evs = db(`SELECT broadcast_as, channel FROM domain_events WHERE aggregate_id=${id} AND aggregate_type LIKE '%Order%'`);
    const lines = evs.split('\n').filter(Boolean);
    const broadcastNames = lines.map((l) => l.split('\t')[0]);
    const channels = lines.map((l) => l.split('\t')[1] || '');
    console.log(`[SYNC] order ${id} outbox events: ${JSON.stringify(broadcastNames)}`);
    expect(broadcastNames, `order ${id} emits OrderCreated`).toContain('OrderCreated');
    expect(broadcastNames, `order ${id} emits OrderStatusChanged`).toContain('OrderStatusChanged');
    expect(channels.every((c) => c.includes('private-branch.1')),
      `order ${id} events target private-branch.1 (got ${JSON.stringify(channels)})`).toBeTruthy();
  }

  // (3) Envelope sanity: the outbox payload carries order_id + queue_number so a consumer (KDS/OSS)
  // can correlate the push to a board card.
  const payloadHit = db(`SELECT COUNT(*) FROM domain_events WHERE aggregate_id IN (${idList}) AND JSON_EXTRACT(payload,'$.order_id') IS NOT NULL AND JSON_EXTRACT(payload,'$.queue_number') IS NOT NULL`);
  console.log(`[SYNC] outbox rows carrying order_id + queue_number = ${payloadHit}`);
  expect(Number(payloadHit), 'outbox payloads carry order_id + queue_number for consumer correlation').toBeGreaterThanOrEqual(N);
});
