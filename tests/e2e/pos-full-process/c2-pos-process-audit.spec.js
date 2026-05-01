const { test, expect } = require('@playwright/test');
const paymentStatusEnum = require('../../../resources/js/enums/modules/paymentStatusEnum.js').default;
const orderStatusEnum = require('../../../resources/js/enums/modules/orderStatusEnum.js').default;
const {
  cleanupProcessAudit,
  createProcessOrder,
  inspectOrder,
  loginAsPOS,
  expectNoCriticalBrowserErrors,
} = require('../helpers/process-audit');

const PREFIX = 'PW-C2';

async function waitForPendingOrderInPosApi(page, orderId) {
  await page.waitForFunction(async (id) => {
    try {
      const response = await window.axios.get('admin/pos/counter-collect/pending');
      const rows = response?.data?.data || [];
      window.__c2PendingCounterState = {
        ok: true,
        hasOrder: rows.some((order) => Number(order.id) === Number(id)),
        count: rows.length,
      };
    } catch (error) {
      window.__c2PendingCounterState = {
        ok: false,
        status: error?.response?.status || 0,
        message: error?.response?.data?.message || error.message,
      };
    }

    return window.__c2PendingCounterState.ok && window.__c2PendingCounterState.hasOrder;
  }, orderId, { timeout: 20_000 });
}

async function openCounterCollectCard(page, order) {
  await waitForPendingOrderInPosApi(page, order.id);
  await expect(page.locator('.kiosk-cash-fab')).toBeVisible({ timeout: 20_000 });
  await page.locator('.kiosk-cash-fab').click();
  await page.locator('.kiosk-cash-refresh-btn').click();
  const card = page.locator('.kiosk-cash-order-card').filter({ hasText: order.queue_number });
  await expect(card).toBeVisible({ timeout: 20_000 });
  return card;
}

test.describe('C2 POS full process audit', () => {
  test.describe.configure({ timeout: 120_000 });

  test.beforeEach(() => cleanupProcessAudit(PREFIX));
  test.afterEach(() => cleanupProcessAudit(PREFIX));

  test('P1 dine-in/walk-in cash: POS surface opens and paid order remains fiscal and stocked', async ({ page }) => {
    await loginAsPOS(page);

    await expectNoCriticalBrowserErrors(page, async () => {
      await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 20_000 });
      const order = createProcessOrder({
        prefix: PREFIX,
        label: 'P1_DINE_IN_CASH',
        surface: 'pos',
        orderType: 'POS',
        paymentGateway: 'CASH_ON_DELIVERY',
        paymentStatus: 'PAID',
        posPaymentMethod: 'CASH',
        status: 'ACCEPT',
        total: 10,
        stockOnHand: 5,
        decrementStock: true,
      });

      const persisted = inspectOrder(order.id);
      expect(persisted.payment_status).toBe(paymentStatusEnum.PAID);
      expect(persisted.fiscal_sequence_no).toBeGreaterThan(0);
      expect(persisted.stock_on_hand).toBe(4);
      expect(persisted.movement_reasons).toContain('order_created');
    });
  });

  test('P2 takeaway customer card: POS surface handles card-paid takeaway invariant', async ({ page }) => {
    await loginAsPOS(page);
    await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 20_000 });

    const order = createProcessOrder({
      prefix: PREFIX,
      label: 'P2_TAKEAWAY_CARD',
      surface: 'pos',
      orderType: 'TAKEAWAY',
      paymentGateway: 'CARD',
      paymentStatus: 'PAID',
      posPaymentMethod: 'CARD',
      status: 'ACCEPT',
      total: 12.5,
      stockOnHand: 4,
      decrementStock: true,
      withComposition: true,
    });

    const persisted = inspectOrder(order.id);
    expect(persisted.payment_status).toBe(paymentStatusEnum.PAID);
    expect(persisted.fiscal_sequence_no).toBeGreaterThan(0);
    expect(persisted.composition_snapshot.lines.length).toBeGreaterThan(0);
  });

  test('P3 delivery quote: POS backend recomputes forged delivery charge at 5 EUR per 5 km', async ({ page }) => {
    await loginAsPOS(page);
    await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 20_000 });

    const fixture = createProcessOrder({
      prefix: PREFIX,
      label: 'P3_DELIVERY_FIXTURE',
      surface: 'pos',
      orderType: 'POS',
      paymentGateway: 'CARD',
      paymentStatus: 'PAID',
      posPaymentMethod: 'CARD',
      status: 'ACCEPT',
      total: 10,
      stockOnHand: 4,
      decrementStock: false,
    });

    const quote = await page.evaluate(async ({ branchId, itemId }) => {
      const response = await window.axios.post('admin/pos/quote', {
        branch_id: branchId,
        discount: 0,
        delivery_charge: 999,
        delivery_distance_km: 5.01,
        order_type: 5,
        source: 1,
        pos_payment_method: 1,
        items: JSON.stringify([{
          item_id: itemId,
          quantity: 1,
          item_variations: [],
          item_extras: [],
        }]),
      });
      return response.data;
    }, { branchId: fixture.branch_id, itemId: fixture.item_id });

    expect(Number(quote?.data?.delivery_charge)).toBe(10);
  });

  test('P4 counter-collect confirm: POS collects kiosk pending cash and allocates fiscal sequence', async ({ page }) => {
    const order = createProcessOrder({
      prefix: PREFIX,
      label: 'P4_COUNTER_CONFIRM',
      surface: 'kiosk',
      orderType: 'KIOSK',
      paymentGateway: 'CASH_ON_DELIVERY',
      paymentStatus: 'PENDING_COUNTER',
      posPaymentMethod: 'COUNTER_DEFERRED',
      status: 'ACCEPT',
      total: 14,
      stockOnHand: 3,
      decrementStock: true,
    });

    await loginAsPOS(page);
    const card = await openCounterCollectCard(page, order);

    const [confirmResponse] = await Promise.all([
      page.waitForResponse((response) =>
        response.url().includes('/api/admin/pos/counter-collect/')
          && response.url().includes('/confirm')
          && response.request().method() === 'POST'
      ),
      card.getByRole('button', { name: /Encaisser/i }).click(),
    ]);
    expect(confirmResponse.ok(), await confirmResponse.text().catch(() => '')).toBeTruthy();
    await expect(card).toBeHidden({ timeout: 20_000 });

    const persisted = inspectOrder(order.id);
    expect(persisted.payment_status).toBe(paymentStatusEnum.PAID);
    expect(persisted.pos_payment_method).toBe(1);
    expect(persisted.fiscal_sequence_no).toBeGreaterThan(0);
    expect(persisted.stock_on_hand).toBe(2);
    expect(persisted.domain_events).toContain('OrderPaidAtCounter');
  });

  test('P5 counter-collect cancel: POS cancels kiosk pending cash without fiscal allocation and releases stock', async ({ page }) => {
    const order = createProcessOrder({
      prefix: PREFIX,
      label: 'P5_COUNTER_CANCEL',
      surface: 'kiosk',
      orderType: 'KIOSK',
      paymentGateway: 'CASH_ON_DELIVERY',
      paymentStatus: 'PENDING_COUNTER',
      posPaymentMethod: 'COUNTER_DEFERRED',
      status: 'ACCEPT',
      total: 15,
      stockOnHand: 3,
      decrementStock: true,
    });

    await loginAsPOS(page);
    const card = await openCounterCollectCard(page, order);

    const [cancelResponse] = await Promise.all([
      page.waitForResponse((response) =>
        response.url().includes('/api/admin/pos/counter-collect/')
          && response.url().includes('/cancel')
          && response.request().method() === 'POST'
      ),
      card.getByRole('button', { name: /Annuler/i }).click(),
    ]);
    expect(cancelResponse.ok(), await cancelResponse.text().catch(() => '')).toBeTruthy();
    await expect(card).toBeHidden({ timeout: 20_000 });

    const persisted = inspectOrder(order.id);
    expect(persisted.payment_status).toBe(paymentStatusEnum.REFUNDED);
    expect(persisted.status).toBe(orderStatusEnum.CANCELED);
    expect(persisted.fiscal_sequence_no).toBeNull();
    expect(persisted.stock_on_hand).toBe(3);
    expect(persisted.movement_reasons).toEqual(expect.arrayContaining(['order_created', 'order_canceled']));
  });
});
