const { test, expect } = require('@playwright/test');
const paymentStatusEnum = require('../../../resources/js/enums/modules/paymentStatusEnum.js').default;
const orderStatusEnum = require('../../../resources/js/enums/modules/orderStatusEnum.js').default;
const {
  artisan,
  parseArtisanJson,
  cleanupProcessAudit,
  createProcessOrder,
  inspectOrder,
  expectNoCriticalBrowserErrors,
} = require('../helpers/process-audit');

const PREFIX = 'PW-C1';

async function assertWaitingReachesConfirmation(page, order) {
  await page.goto(`/kiosk/waiting/${order.id}?queue=${order.queue_number}&total=${order.total}`);
  await expect(page).toHaveURL(/\/kiosk\/confirmation/, { timeout: 10_000 });
  await expect(page.getByTestId('kiosk-confirmation-number')).toContainText(order.queue_number);
}

test.describe('C1 kiosk full process audit', () => {
  test.describe.configure({ timeout: 90_000 });

  test.beforeEach(() => cleanupProcessAudit(PREFIX));
  test.afterEach(() => cleanupProcessAudit(PREFIX));

  test('K1 card simple: paid kiosk order confirms, persists fiscal number, decrements stock', async ({ page }) => {
    await expectNoCriticalBrowserErrors(page, async () => {
      const order = createProcessOrder({
        prefix: PREFIX,
        label: 'K1_CARD_SIMPLE',
        surface: 'kiosk',
        orderType: 'KIOSK',
        paymentGateway: 'CARD',
        paymentStatus: 'PAID',
        status: 'ACCEPT',
        total: 8,
        stockOnHand: 4,
        decrementStock: true,
      });

      expect(order.payment_status).toBe(paymentStatusEnum.PAID);
      expect(order.status).toBe(orderStatusEnum.ACCEPT);
      expect(order.fiscal_sequence_no).toBeGreaterThan(0);
      expect(order.stock_on_hand).toBe(3);

      await assertWaitingReachesConfirmation(page, order);

      const persisted = inspectOrder(order.id);
      expect(persisted.payment_status).toBe(paymentStatusEnum.PAID);
      expect(persisted.fiscal_sequence_no).toBeGreaterThan(0);
      expect(persisted.stock_on_hand).toBe(3);
      expect(persisted.movement_reasons).toContain('order_created');
    });
  });

  test('K2 composition tacos: immutable composition snapshot survives confirmation flow', async ({ page }) => {
    const order = createProcessOrder({
      prefix: PREFIX,
      label: 'K2_TACOS_COMPOSITION',
      surface: 'kiosk',
      orderType: 'KIOSK',
      paymentGateway: 'CARD',
      paymentStatus: 'PAID',
      status: 'ACCEPT',
      total: 13.5,
      stockOnHand: 5,
      decrementStock: true,
      withComposition: true,
    });

    await assertWaitingReachesConfirmation(page, order);

    const persisted = inspectOrder(order.id);
    expect(persisted.composition_snapshot.schema_version).toBe(1);
    expect(persisted.composition_snapshot.lines.map((line) => line.role)).toEqual(
      expect.arrayContaining(['viande', 'sauce', 'extra'])
    );
    expect(persisted.payment_status).toBe(paymentStatusEnum.PAID);
    expect(persisted.fiscal_sequence_no).toBeGreaterThan(0);
  });

  test('K3 cash-at-counter: kiosk confirms and remains non-fiscal before POS collection', async ({ page }) => {
    const order = createProcessOrder({
      prefix: PREFIX,
      label: 'K3_COUNTER_DEFERRED',
      surface: 'kiosk',
      orderType: 'KIOSK',
      paymentGateway: 'CASH_ON_DELIVERY',
      paymentStatus: 'PENDING_COUNTER',
      posPaymentMethod: 'COUNTER_DEFERRED',
      status: 'ACCEPT',
      total: 11,
      stockOnHand: 3,
      decrementStock: true,
    });

    expect(order.payment_status).toBe(paymentStatusEnum.PENDING_COUNTER);
    expect(order.fiscal_sequence_no).toBeNull();

    await assertWaitingReachesConfirmation(page, order);
    await expect(page.getByText(/comptoir/i).first()).toBeVisible();

    const persisted = inspectOrder(order.id);
    expect(persisted.payment_status).toBe(paymentStatusEnum.PENDING_COUNTER);
    expect(persisted.fiscal_sequence_no).toBeNull();
    expect(persisted.stock_on_hand).toBe(2);
  });

  test('K4 rupture projection: zero stock marks kiosk projection unavailable and blocks decrement', async () => {
    const order = createProcessOrder({
      prefix: PREFIX,
      label: 'K4_RUPTURE',
      surface: 'kiosk',
      orderType: 'KIOSK',
      paymentGateway: 'CARD',
      paymentStatus: 'UNPAID',
      status: 'PENDING',
      total: 9,
      stockOnHand: 0,
      decrementStock: false,
    });

    const result = artisan(`
      $order = App\\Models\\Order::with('orderItems')->findOrFail(${Number(order.id)});
      $itemId = (int) $order->orderItems->first()->item_id;
      App\\Models\\ItemBranchAvailability::query()->updateOrCreate(
        ['item_id' => $itemId, 'branch_id' => (int) $order->branch_id],
        [
          'is_available' => false,
          'unavailable_reason' => 'stock_rupture',
          'unavailable_since' => now(),
          'daily_consumed_qty' => 0,
          'daily_reset_at' => now()->toDateString(),
        ]
      );
      $blocked = false;
      try {
        app(App\\Services\\Stock\\StockService::class)->decrementForOrder($order, '${PREFIX}-K4_RUPTURE');
      } catch (App\\Exceptions\\Stock\\StockUnavailableException $exception) {
        $blocked = true;
      }
      $menu = app(App\\Services\\Kiosk\\KioskMenuService::class)->build(App\\Models\\Branch::findOrFail((int) $order->branch_id));
      $projected = collect($menu['items'])->firstWhere('id', $itemId);
      echo json_encode([
        'blocked' => $blocked,
        'is_available' => $projected['is_available'] ?? null,
        'unavailable_reason' => $projected['unavailable_reason'] ?? null,
      ]);
    `);
    const parsed = parseArtisanJson(result);
    expect(parsed.blocked).toBe(true);
    expect(parsed.is_available).toBe(false);
    expect(parsed.unavailable_reason).toBe('stock_rupture');
  });

  test('K5 abandon/new-order path: confirmation CTA resets to locked idle surface', async ({ page }) => {
    const order = createProcessOrder({
      prefix: PREFIX,
      label: 'K5_ABANDON_RESET',
      surface: 'kiosk',
      orderType: 'KIOSK',
      paymentGateway: 'CARD',
      paymentStatus: 'PAID',
      status: 'ACCEPT',
      total: 7,
      stockOnHand: 2,
      decrementStock: true,
    });

    await assertWaitingReachesConfirmation(page, order);
    await page.getByTestId('kiosk-confirmation-cta-home').click();
    await expect(page).toHaveURL(/\/kiosk\/idle/, { timeout: 10_000 });

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(/Administration|Aller a la caisse|Aller à la caisse|Whoops|Fatal error/i);
  });
});
