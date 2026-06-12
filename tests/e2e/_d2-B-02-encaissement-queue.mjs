// VAGUE B ROUND 2 — heal B-R1-04 (part UI H3 d377da185): /admin/encaissement
// (a) badge date sur cartes d'un autre jour (toutes du 10/06 ici → badge « 10/06 »),
// (b) tri jour-récent-d'abord, (c) chip date dans le modal de confirmation.
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, bodyText } from './_d2-B-lib.mjs';

const L = mkLogger('b2-encaissement');
const { browser, page, state } = await boot();

try {
  await login(page, L);
  await page.goto(BASE + '/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  L(`url=${page.url().replace(BASE, '')}`);

  // cartes + badges
  const cards = await page.evaluate(() => {
    const out = [];
    document.querySelectorAll('[data-testid^="enc-card-"], .enc-card, [class*="enc-"][class*="card"]').forEach((c) => {
      out.push({ testid: c.getAttribute('data-testid'), text: c.innerText.replace(/\s+/g, ' ').trim().slice(0, 160) });
    });
    return out.slice(0, 12);
  });
  L(`cartes via testid enc-card: ${cards.length}`);
  cards.forEach((c, i) => L(`  carte${i + 1} [${c.testid}]: ${c.text}`));

  const badges = await page.locator('[data-testid^="enc-day-badge"]').allTextContents();
  const badgeIds = await page.locator('[data-testid^="enc-day-badge"]').evaluateAll((els) => els.map((e) => e.getAttribute('data-testid')));
  L(`badges enc-day-badge: ${badges.length} → ${JSON.stringify([...new Set(badges.map((b) => b.trim()))])}`);
  L(`badge testids (5 premiers): ${JSON.stringify(badgeIds.slice(0, 5))}`);

  const txt = await bodyText(page);
  L(`header file: ${JSON.stringify((txt.match(/À encaisser[^\n]*|en attente[^\n]*/gi) || []).slice(0, 4))}`);
  // ordre des numéros A affichés (tri jour-récent-d'abord attendu)
  const serials = (txt.match(/A\d{4}/g) || []).slice(0, 16);
  L(`numéros A dans l'ordre DOM: ${JSON.stringify(serials)}`);
  await snap(page, state, 'b2-01-encaissement-queue');

  // scroll bas pour voir si "Voir plus" + zombies en bas
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(800);
  await snap(page, state, 'b2-02-encaissement-queue-bas');

  // ouvrir le modal sur la PREMIÈRE carte (10/06 → chip « Commande du 10/06/2026 » attendue)
  const encBtn = page.locator('[data-testid^="enc-collect-"], .enc-card button, [data-testid^="enc-card-"] button').first();
  L(`bouton encaisser carte count=${await encBtn.count()}`);
  // fallback: bouton portant le texte Encaisser
  const btn = (await encBtn.count()) ? encBtn : page.locator('button:has-text("Encaisser")').first();
  await btn.click();
  await page.waitForTimeout(2200);
  const modalVisible = await page.locator('[data-testid="pos-counter-collect-modal"]').isVisible().catch(() => false);
  const chip = await page.locator('[data-testid="pos-counter-collect-day-badge"]').innerText().catch(() => '(ABSENT)');
  const mTotal = await page.locator('[data-testid="pos-counter-collect-total"]').innerText().catch(() => '?');
  const mHead = await page.evaluate(() => document.querySelector('.cc-modal')?.innerText.replace(/\s+/g, ' ').slice(0, 400) || '(pas de .cc-modal)');
  L(`modal visible=${modalVisible} chip-date="${chip.replace(/\s+/g, ' ')}" total="${mTotal.replace(/\s+/g, ' ')}"`);
  L(`modal head: ${mHead}`);
  await snap(page, state, 'b2-03-collect-modal-old-order');

  // ── bonus heal ADV-F-P0-1: hit-test numpad vs footer à 1440×900 (modal réel post-rebuild) ──
  const hitTest = await page.evaluate(() => {
    const keys = Array.from(document.querySelectorAll('.cc-modal [data-testid^="numpad-"], .cc-modal .pos-v5-numpad button, .cc-modal button'))
      .filter((b) => /^[0-9,C]$|^00$|^⌫$/.test(b.innerText.trim()));
    const blocked = [];
    keys.forEach((k) => {
      const r = k.getBoundingClientRect();
      if (!r.width) return;
      const el = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
      if (el !== k && !k.contains(el)) blocked.push(k.innerText.trim() + '←' + (el?.className?.toString().slice(0, 40) || el?.tagName));
    });
    const body = document.querySelector('.cc-modal-body');
    return { keysFound: keys.length, blocked, bodyScroll: body ? body.scrollHeight + '/' + body.clientHeight : '(pas de .cc-modal-body)' };
  });
  L(`hit-test numpad modal: keys=${hitTest.keysFound} blocked=${JSON.stringify(hitTest.blocked)} body=${hitTest.bodyScroll}`);

  // fermer SANS confirmer (l'encaissement réel = script session b3)
  await page.keyboard.press('Escape').catch(() => {});
  await page.locator('.cc-modal [data-testid="pos-counter-collect-cancel"], .cc-modal button:has-text("Annuler")').first().click().catch(() => {});
  await page.waitForTimeout(900);
  await snap(page, state, 'b2-04-modal-closed');
} finally {
  L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
