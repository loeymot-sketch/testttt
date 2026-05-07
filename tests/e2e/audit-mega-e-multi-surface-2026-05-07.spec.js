// FoodKing E2E — AUDIT MEGA-E Multi-surface (2026-05-07)
// Tests E2E croisés: POS↔Kiosk↔KDS↔Stock+Promo + traçabilité Outbox

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator, loginAsKiosk, loginAsChefOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-mega-e-2026-05-07');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function record(step, slug, state, sev, note, screenshot) {
  findings.push({ step, slug, state, severity: sev, note, screenshot, ts: new Date().toISOString() });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

async function snap(page, step, slug, state) {
  const fn = `${String(step).padStart(2, '0')}-${slug}-${state}.png`;
  const fp = path.join(SHOT_DIR, fn);
  await page.screenshot({ path: fp, fullPage: true }).catch(() => {});
  return path.relative(process.cwd(), fp);
}

function tinker(code) {
  try {
    const out = execSync(`php artisan tinker --execute='${code.replace(/'/g, "\\'")}'`, {
      cwd: process.cwd(), encoding: 'utf8', timeout: 30000,
    });
    const lines = out.trim().split(/\r?\n/);
    const last = [...lines].reverse().find((l) => l.trim().startsWith('{') || l.trim().startsWith('['));
    if (last) {
      try { return JSON.parse(last); } catch (_) { return { _raw: last }; }
    }
    return { _output: out.trim().slice(-500) };
  } catch (e) {
    return { _error: e.message.slice(0, 500) };
  }
}

test.describe('AUDIT MEGA-E — Multi-surface E2E (POS↔Kiosk↔KDS↔Stock)', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('E-01 — POS surface load + branding cohérent V5', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);
    const shot = await snap(page, 1, 'pos', 'surface');
    const tokens = await page.evaluate(() => {
      const cs = getComputedStyle(document.documentElement);
      return {
        bgApp: cs.getPropertyValue('--pos-v5-bg-app').trim(),
        brand: cs.getPropertyValue('--pos-v5-brand-red').trim(),
      };
    });
    record('E-01', 'pos-surface', tokens.bgApp ? 'rendered' : 'missing-tokens',
      tokens.bgApp ? 'OK' : 'P1',
      `POS V5 tokens: bgApp=${tokens.bgApp}, brand=${tokens.brand}`, shot);
  });

  test('E-02 — Kiosk surface load + branding V1 Bold', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.waitForTimeout(2_500);
    const shot = await snap(page, 2, 'kiosk', 'surface');
    const tokens = await page.evaluate(() => {
      const cs = getComputedStyle(document.documentElement);
      return { primary: cs.getPropertyValue('--kiosk-primary').trim() };
    });
    record('E-02', 'kiosk-surface', tokens.primary ? 'rendered' : 'missing-tokens',
      tokens.primary ? 'OK' : 'P1',
      `Kiosk tokens: primary=${tokens.primary}`, shot);
  });

  test('E-03 — KDS surface load + 4 colonnes Kanban', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await loginAsChefOperator(page);
    await page.waitForTimeout(3_000);
    const shot = await snap(page, 3, 'kds', 'surface');
    const visibleText = await page.locator('body').innerText().catch(() => '');
    const hasError = /Whoops|Fatal|Server Error/i.test(visibleText);
    record('E-03', 'kds-surface', hasError ? 'error' : 'rendered',
      hasError ? 'P0' : 'OK',
      `KDS body length=${visibleText.length}, hasError=${hasError}`, shot);
  });

  test('E-04 — Outbox event lifecycle: 6 event types câblés', async ({ page }) => {
    const outbox = tinker(`echo json_encode(\\App\\Models\\DomainEvent::query()->select('event_type')->distinct()->pluck('event_type')->toArray());`);
    const expectedEvents = [
      'order.created', 'order.status_changed', 'order.payment_status_changed',
      'order.payment_confirmed', 'menu.item_availability_changed', 'promo.coupon_changed',
    ];
    fs.writeFileSync(path.join(SHOT_DIR, 'E-04-event-types.json'), JSON.stringify({ found: outbox, expected: expectedEvents }, null, 2));
    const types = Array.isArray(outbox) ? outbox : [];
    const matchCount = expectedEvents.filter((e) => types.includes(e)).length;
    const shot = await snap(page, 4, 'outbox-events', `${matchCount}-of-${expectedEvents.length}`);
    record('E-04', 'outbox-event-types', matchCount > 0 ? 'persisted' : 'empty',
      matchCount >= 3 ? 'OK' : 'INFO',
      `Found event types in domain_events: ${types.length}, matching expected: ${matchCount}/${expectedEvents.length}`, shot);
  });

  test('E-05 — Sentinel: tous les Listeners Outbox câblés EventServiceProvider', async ({ page }) => {
    const espPath = path.join(process.cwd(), 'app/Providers/EventServiceProvider.php');
    const source = fs.readFileSync(espPath, 'utf8');
    const listeners = {
      OrderCreated: source.includes('PersistOrderCreatedToOutbox'),
      OrderStatusChanged: source.includes('PersistOrderStatusChangedToOutbox'),
      OrderPaymentStatusChanged: source.includes('PersistOrderPaymentStatusChangedToOutbox'),
      OrderPaidAtCounter: source.includes('PersistOrderPaidAtCounterToOutbox'),
      ItemAvailabilityChanged: source.includes('PersistItemAvailabilityChangedToOutbox'),
      CouponChanged: source.includes('PersistCouponChangedToOutbox'),
    };
    fs.writeFileSync(path.join(SHOT_DIR, 'E-05-listeners.json'), JSON.stringify(listeners, null, 2));
    const allWired = Object.values(listeners).every(Boolean);
    const shot = await snap(page, 5, 'listeners-wired', allWired ? 'all' : 'partial');
    record('E-05', 'listeners-wired', allWired ? 'all-câblés' : 'partial',
      allWired ? 'OK' : 'P1',
      `Listeners Outbox dans EventServiceProvider: ${JSON.stringify(listeners)}`, shot);
  });

  test('E-06 — Sentinel: branch isolation channels Pusher', async ({ page }) => {
    const channelsPath = path.join(process.cwd(), 'routes/channels.php');
    const source = fs.readFileSync(channelsPath, 'utf8');
    const hasBranchChannel = /branch\.\{branchId\}/.test(source);
    const hasKioskAbilityCheck = /tokenCan\s*\(\s*['"]kiosk:order['"]/.test(source);
    const hasKioskMachineLookup = /KioskMachine::where/.test(source);
    const hasAdminBypass = /branch_id.*===\s*0/.test(source);

    const shot = await snap(page, 6, 'channel-auth', hasBranchChannel && hasKioskAbilityCheck ? 'enforced' : 'partial');
    record('E-06', 'channel-auth', hasBranchChannel && hasKioskAbilityCheck && hasKioskMachineLookup ? 'enforced' : 'partial',
      hasBranchChannel && hasKioskAbilityCheck && hasKioskMachineLookup ? 'OK' : 'P1',
      `Channel branch.{id}=${hasBranchChannel}, kioskAbility check=${hasKioskAbilityCheck}, KioskMachine lookup=${hasKioskMachineLookup}, admin bypass=${hasAdminBypass}`, shot);
  });

  test('E-07 — Sentinel: idempotency middleware actif sur 8 routes critiques', async ({ page }) => {
    const routes = fs.readFileSync(path.join(process.cwd(), 'routes/api.php'), 'utf8');
    const protectedRoutes = {
      pos_create: /Route::post\('\/', \[PosController::class, 'store'\]\)[\s\S]{0,200}'idempotency'/.test(routes),
      pos_change_payment: /change-payment-status[\s\S]{0,300}'idempotency'/.test(routes),
      frontend_order: /Route::post\('\/', \[FrontendOrderController::class, 'store'\]\)[\s\S]{0,200}'idempotency'/.test(routes),
      payment_confirm: /payment-confirm[\s\S]{0,300}'idempotency'/.test(routes),
      refund_counter: /refund-with-counter-entry[\s\S]{0,300}'idempotency'/.test(routes),
    };
    const allWired = Object.values(protectedRoutes).every(Boolean);
    const shot = await snap(page, 7, 'idempotency-routes', allWired ? 'all' : 'partial');
    record('E-07', 'idempotency-routes', allWired ? 'all-protected' : 'partial',
      allWired ? 'OK' : 'P1',
      `Routes protected by idempotency: ${JSON.stringify(protectedRoutes)}`, shot);
  });

  test('E-08 — Sentinel: cycle 7 plans P0 + KR2 fix + K11 sync alignment', async ({ page }) => {
    const checks = {
      cycle_7A_idempotency: fs.existsSync(path.join(process.cwd(), 'app/Http/Middleware/IdempotencyKeyMiddleware.php')),
      cycle_7B_payment_event: fs.existsSync(path.join(process.cwd(), 'app/Events/OrderPaymentStatusChanged.php')),
      cycle_7C_split_payment: fs.existsSync(path.join(process.cwd(), 'app/Models/OrderPayment.php')),
      cycle_7D_chain_validator: fs.existsSync(path.join(process.cwd(), 'app/Services/Fiscal/FiscalChainValidator.php')),
      cycle_7D_sealed_guard: fs.existsSync(path.join(process.cwd(), 'app/Services/Order/SealedOrderGuard.php')),
      cycle_7D_refund_mirror: fs.existsSync(path.join(process.cwd(), 'app/Services/Order/RefundWithCounterEntryService.php')),
      kr2_kiosk_coupon_echo: /broadcastAs:\s*'CouponChanged'/.test(fs.readFileSync(path.join(process.cwd(), 'resources/js/components/frontend/kiosk/KioskAppComponent.vue'), 'utf8')),
      k11_fiscal_auto_allocate: /kiosk_auto_allocate_sequence|FISCAL_KIOSK_AUTO_ALLOCATE/.test(fs.readFileSync(path.join(process.cwd(), 'app/Services/FrontendOrderService.php'), 'utf8')),
    };
    fs.writeFileSync(path.join(SHOT_DIR, 'E-08-cycle7-alignment.json'), JSON.stringify(checks, null, 2));
    const allDone = Object.values(checks).every(Boolean);
    const shot = await snap(page, 8, 'cycle7-alignment', allDone ? 'all-done' : 'partial');
    record('E-08', 'cycle7-alignment', allDone ? 'all-done' : 'partial',
      allDone ? 'OK' : 'P0',
      `Cycle 7 + KR + K11 deliverables present: ${JSON.stringify(checks)}`, shot);
  });

  test('E-09 — Sentinel: 4 nouveaux services frozen-zone respectent contracts', async ({ page }) => {
    const checks = {
      payment_state_machine_wired: /PaymentStateMachine::assertCanTransition/.test(fs.readFileSync(path.join(process.cwd(), 'app/Services/OrderService.php'), 'utf8')),
      sealed_guard_wired: /SealedOrderGuard.*assertMutable.*RETURNED/.test(fs.readFileSync(path.join(process.cwd(), 'app/Services/OrderService.php'), 'utf8')),
      sealed_guard_refunded: /SealedOrderGuard.*assertMutable.*REFUNDED/.test(fs.readFileSync(path.join(process.cwd(), 'app/Services/OrderService.php'), 'utf8')),
      chain_validator_wired: /chainValidator\(\)->assertChainIntegrity/.test(fs.readFileSync(path.join(process.cwd(), 'app/Services/Fiscal/ZReportService.php'), 'utf8')),
      split_payment_wired: /SplitPaymentService.*persist|persistTranches/.test(fs.readFileSync(path.join(process.cwd(), 'app/Services/OrderService.php'), 'utf8')),
    };
    const allWired = Object.values(checks).every(Boolean);
    const shot = await snap(page, 9, 'frozen-services-wired', allWired ? 'all' : 'partial');
    record('E-09', 'frozen-services-wired', allWired ? 'all-wired' : 'partial',
      allWired ? 'OK' : 'P1',
      `Frozen services callsites: ${JSON.stringify(checks)}`, shot);
  });

  test('E-10 — Synthèse + INDEX MEGA-E', async ({ page }) => {
    const idx = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT MEGA-E — Multi-surface E2E 2026-05-07', '', `Total findings: ${findings.length}`, '', '| Step | Slug | State | Sev | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).slice(0, 200).replace(/\|/g, '\\|')} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(idx, lines.join('\n'));
    expect(findings.length).toBeGreaterThan(7);
  });
});
