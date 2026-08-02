// Phase 1 — login, token, KDS board baseline, websocket state.
import { launch, loginAndGetContext, api, BASE, SHOTS, log } from './lib.mjs';

const browser = await launch();
try {
  const { ctx, page, token } = await loginAndGetContext(browser);
  log('TOKEN?', token ? token.slice(0, 12) + '…' : 'NULL');
  if (!token) {
    await page.screenshot({ path: SHOTS + 'p1-login-fail.png' });
    log('URL after login attempt:', page.url());
    process.exit(2);
  }

  const client = api(token);
  const me = await client.get('/admin/kds-order?paginate=0&order_column=id&order_by=desc');
  log('KDS list API status:', me.status, '| orders on board:', me.body?.data?.length, '| overflow:', me.body?.meta?.overflow, '| scheduled_upcoming:', JSON.stringify(me.body?.meta?.scheduled_upcoming ?? null));
  if (Array.isArray(me.body?.data)) {
    for (const o of me.body.data.slice(0, 10)) {
      log(`  board order id=${o.id} serial=${o.order_serial_no} status=${o.status} queue=${o.queue_number} type=${o.order_type} src=${o.source_surface ?? '?'}`);
    }
  }

  const kds = await ctx.newPage();
  await kds.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kds.waitForTimeout(4000);
  await kds.screenshot({ path: SHOTS + 'p1-kds-board.png', fullPage: true });

  const state = await kds.evaluate(() => {
    const txt = (sel) => Array.from(document.querySelectorAll(sel)).map((e) => e.textContent.replace(/\s+/g, ' ').trim());
    return {
      title: document.title,
      wsState: window._wsService?.state ?? 'no _wsService',
      echoPresent: !!window.Echo,
      v2Cards: document.querySelectorAll('.kds2-card, [class*="kds2"]').length,
      legacyCards: document.querySelectorAll('.kds-order-card, [class*="kds-card"]').length,
      bodySample: document.body.innerText.slice(0, 1200),
      banners: txt('[class*="banner"], [class*="Banner"]').slice(0, 6),
    };
  });
  log('KDS page state:', JSON.stringify(state, null, 2).slice(0, 2200));
} finally {
  await browser.close();
}
