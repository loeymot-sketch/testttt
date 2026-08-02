// Phase 9 — recall 60s (undo bump), annulation d'une carte VISIBLE, flush zombie E4MASS, panneau Rupture.
import { launch, loginAndGetContext, api, idem, BASE, SHOTS, log } from './lib.mjs';

const browser = await launch();
try {
  const { ctx, token } = await loginAndGetContext(browser);
  const client = api(token);
  const kds = await ctx.newPage();
  await kds.goto(`${BASE}/admin/kitchen-display-system`, { waitUntil: 'networkidle' });
  await kds.waitForTimeout(5000);

  const gridIds = await kds.evaluate(() => Array.from(document.querySelectorAll('.kds-card[data-order-id]')).map((c) => ({
    id: c.getAttribute('data-order-id'),
    cta: Array.from(c.querySelectorAll('button')).map((b) => b.textContent.trim()).join('|'),
    state: c.querySelector('.kds-card__state-pill')?.textContent.trim(),
  })));
  log('grid now:', JSON.stringify(gridIds));

  // A) CANCEL a VISIBLE card (6043) while the cook watches.
  const target = gridIds.find((g) => g.id === '6043');
  if (target) {
    const tC = Date.now();
    const cxl = await client.post('/admin/pos-order/change-status/6043', { status: 16, reason: 'ZZ-TEST annulation carte visible' }, idem());
    log('cancel 6043:', cxl.status, JSON.stringify(cxl.body?.message ?? '').slice(0, 140));
    let gone = null;
    for (let i = 0; i < 120; i++) {
      const still = await kds.evaluate(() => !!document.querySelector('.kds-card[data-order-id="6043"]'));
      if (!still) { gone = Date.now(); break; }
      await kds.waitForTimeout(200);
    }
    log(gone ? `VISIBLE card removed ${(gone - tC) / 1000}s after cashier cancel (no F5)` : 'card still there 24s after cancel');
    const cue = await kds.evaluate(() => {
      const m = document.body.innerText.match(/.{0,100}(annul|cancel).{0,100}/i);
      return m ? m[0].replace(/\s+/g, ' ') : null;
    });
    log('explicit "annulée" cue for the cook:', JSON.stringify(cue));
    await kds.screenshot({ path: SHOTS + 'p9-after-visible-cancel.png' });
  } else {
    log('6043 not visible — skip cancel-visible test');
  }

  // B) RECALL — bump 6049 then undo within 60s.
  await kds.waitForTimeout(1500);
  const bumped = await kds.evaluate(() => {
    const c = document.querySelector('.kds-card[data-order-id="6049"]');
    if (!c) return false;
    const b = Array.from(c.querySelectorAll('button')).find((x) => /prêt/i.test(x.textContent));
    if (!b) return false;
    b.click();
    return true;
  });
  log('bumped 6049 via UI:', bumped);
  await kds.waitForTimeout(2000);
  const recall = await client.post('/admin/kds-order/recall/6049', {}, idem());
  log('recall 6049 (<60s):', recall.status, JSON.stringify(recall.body).slice(0, 220));
  let reinjected = null; const tR = Date.now();
  for (let i = 0; i < 60; i++) {
    const has = await kds.evaluate(() => {
      const c = document.querySelector('.kds-card[data-order-id="6049"]');
      return c ? c.innerText.replace(/\s+/g, ' ').slice(0, 120) : null;
    });
    if (has) { reinjected = { at: Date.now(), text: has }; break; }
    await kds.waitForTimeout(300);
  }
  log(reinjected ? `RAPPELÉ card re-injected ${(reinjected.at - tR) / 1000}s — text: ${reinjected.text}` : 'recall card NOT re-injected in 18s');
  const recall2 = await client.post('/admin/kds-order/recall/6049', {}, idem());
  log('second recall (cap N=1):', recall2.status, JSON.stringify(recall2.body?.message ?? '').slice(0, 140));

  // C) ZOMBIE FLUSH — can the cook get rid of E4MASS (0-line, ACCEPT, 9 days old)?
  const zombieCta = await kds.evaluate(() => {
    const c = document.querySelector('.kds-card[data-order-id="5935"]');
    return c ? Array.from(c.querySelectorAll('button')).map((b) => b.textContent.trim()) : null;
  });
  log('zombie card CTAs:', JSON.stringify(zombieCta));
  const z1 = await client.post('/admin/kds-order/change-status/5935', { status: 7, expected_status: 4 }, idem());
  log('zombie ACCEPT→PREPARING:', z1.status, JSON.stringify(z1.body?.message ?? '').slice(0, 140));
  const z2 = await client.post('/admin/kds-order/change-status/5935', { status: 8, expected_status: 7 }, idem());
  log('zombie PREPARING→PREPARED:', z2.status, JSON.stringify(z2.body?.message ?? '').slice(0, 140));
  await kds.waitForTimeout(3000);
  const zombieGone = await kds.evaluate(() => !document.querySelector('.kds-card[data-order-id="5935"]'));
  log('zombie left the active grid after manual flush:', zombieGone);

  // D) Rupture panel — 86 item 24 (Galette Cayenne) then open the panel.
  await client.post('/admin/menu/availability/toggle', { item_id: 24, branch_id: 1, is_available: false, unavailable_reason: 'ZZ-TEST rupture panel' });
  const opened = await kds.evaluate(() => {
    const b = Array.from(document.querySelectorAll('button')).find((x) => /rupture/i.test(x.textContent));
    if (b) { b.click(); return true; }
    return false;
  });
  await kds.waitForTimeout(2500);
  const panel = await kds.evaluate(() => {
    const t = document.body.innerText;
    const m = t.match(/.{0,80}Galette Cayenne.{0,160}/i);
    return { opened: /rupture/i.test(t), extract: m ? m[0].replace(/\s+/g, ' ') : null };
  });
  log('rupture panel opened:', opened, '| Galette Cayenne listed:', JSON.stringify(panel.extract));
  await kds.screenshot({ path: SHOTS + 'p9-rupture-panel.png' });
  await client.post('/admin/menu/availability/toggle', { item_id: 24, branch_id: 1, is_available: true });
  log('restored item 24');
} finally {
  await browser.close();
}
