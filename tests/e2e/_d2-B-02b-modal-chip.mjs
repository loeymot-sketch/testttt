// VAGUE B ROUND 2 — B-R1-04 (suite): chip date dans le modal de confirmation (carte 10/06)
// + hit-test numpad (ADV-F-P0-1 post-rebuild)
import { BASE, OUT, boot, snap, mkLogger, login } from './_d2-B-lib.mjs';

const L = mkLogger('b2b-modal');
const { browser, page, state } = await boot();
try {
  await login(page, L);
  await page.goto(BASE + '/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);

  // cibler la carte d'une commande du 10/06 (badge enc-day-badge-4329) → son bouton .enc-collect-btn
  const target = page.locator('[data-testid="enc-day-badge-4329"]');
  L(`badge 4329 présent: ${await target.count()}`);
  const card = page.locator('.enc-order-card, [class*="enc-"]', { has: target }).first();
  // plus simple: trouver le bouton enc-collect-btn dans le même conteneur que le badge
  const clicked = await page.evaluate(() => {
    const badge = document.querySelector('[data-testid="enc-day-badge-4329"]');
    if (!badge) return 'NO-BADGE';
    let n = badge;
    for (let i = 0; i < 8 && n; i++) {
      const btn = n.querySelector?.('.enc-collect-btn');
      if (btn) { btn.click(); return 'CLICKED via ancestor ' + i; }
      n = n.parentElement;
    }
    return 'NO-BTN-FOUND';
  });
  L(`clic carte 4329: ${clicked}`);
  await page.waitForTimeout(2500);

  const modalVisible = await page.locator('[data-testid="pos-counter-collect-modal"]').isVisible().catch(() => false);
  const chip = await page.locator('[data-testid="pos-counter-collect-day-badge"]').innerText().catch(() => '(ABSENT)');
  const mTotal = await page.locator('[data-testid="pos-counter-collect-total"]').innerText().catch(() => '?');
  const mHead = await page.evaluate(() => document.querySelector('.cc-modal')?.innerText.replace(/\s+/g, ' ').slice(0, 450) || '(pas de .cc-modal)');
  L(`modal visible=${modalVisible} chip-date="${chip.replace(/\s+/g, ' ')}" total="${mTotal.replace(/\s+/g, ' ')}"`);
  L(`modal contenu: ${mHead}`);
  await snap(page, state, 'b2-05-collect-modal-1006-chip');

  // hit-test numpad ADV-F-P0-1 (post-rebuild, modal réel 1440×900)
  const hitTest = await page.evaluate(() => {
    const keys = Array.from(document.querySelectorAll('.cc-modal button')).filter((b) => /^([0-9]|00|,|C|⌫)$/.test(b.innerText.trim()));
    const blocked = [];
    keys.forEach((k) => {
      const r = k.getBoundingClientRect();
      if (!r.width) return;
      const el = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
      if (el !== k && !k.contains(el) && !el?.closest('button')?.isSameNode?.(k)) blocked.push(k.innerText.trim() + '←' + (el?.className?.toString().slice(0, 40) || el?.tagName));
    });
    const body = document.querySelector('.cc-modal-body');
    const footer = document.querySelector('.cc-modal-footer');
    return {
      keysFound: keys.length, blocked,
      bodyScroll: body ? `${body.scrollHeight}/${body.clientHeight}` : '(pas de .cc-modal-body)',
      footerPos: footer ? getComputedStyle(footer).position : '(pas de footer)',
      ctaVisible: !!document.querySelector('.cc-confirm-btn') && (() => { const c = document.querySelector('.cc-confirm-btn').getBoundingClientRect(); return c.bottom <= innerHeight; })(),
    };
  });
  L(`hit-test ADV-F-P0-1: ${JSON.stringify(hitTest)}`);
  await snap(page, state, 'b2-06-modal-numpad-hittest');

  // fermer SANS confirmer
  await page.locator('.cc-modal button:has-text("Annuler"), .cc-close-btn, .cc-modal-close').first().click().catch(async () => page.keyboard.press('Escape'));
  await page.waitForTimeout(800);
  const stillOpen = await page.locator('[data-testid="pos-counter-collect-modal"]').isVisible().catch(() => false);
  L(`modal fermé sans confirmer: ${!stillOpen}`);
} finally {
  L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
