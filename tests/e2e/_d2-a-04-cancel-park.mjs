// R2 VAGUE A — couverture R1 manquante: (1) annulation mi-paiement, (2) commande parquée→rappelée→encaissée
import { boot, login, gotoPos, quartet, addItem, cartState, receiptText, confirmAndCapture, jsClick } from './_d2-a-lib.mjs';

const { browser, page, consoleLog, netLog } = await boot();

try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1200);

  // ===== (1) ANNULATION MI-PAIEMENT =====
  await addItem(page, 'Boissons', 'Coca-Cola 33cl');
  await page.waitForTimeout(700);
  let cart = await cartState(page);
  console.log('CANCEL: cart avant paiement:', JSON.stringify(cart.payLabel), JSON.stringify(cart.items));
  await page.locator('[data-testid="pos-v5-pay"]').click();
  await page.waitForTimeout(2000);
  await page.locator('[data-testid="pos-payment-mode-cash"]').click();
  await page.waitForTimeout(600);
  await page.fill('#cashInput', '5');
  await page.waitForTimeout(500);
  await quartet(page, consoleLog, netLog, 'cancel01-paymodal-open');
  // Annule en fermant le modal (croix) SANS confirmer
  const closed = await jsClick(page, '.pos-v5-payment-close');
  await page.waitForTimeout(1500);
  cart = await cartState(page);
  const modalGone = await page.evaluate(() => !document.querySelector('.pos-v5-payment-modal'));
  console.log('CANCEL: closeClicked=' + closed + ' modalGone=' + modalGone + ' cartPreserved=' + JSON.stringify(cart.payLabel) + ' items=' + JSON.stringify(cart.items));
  await quartet(page, consoleLog, netLog, 'cancel02-after-close');

  // Vide le panier pour le test parking (annule la dernière ligne)
  await page.evaluate(() => { const b = document.querySelector('[data-testid="pos-cancel-last-line"]'); if (b) b.click(); });
  await page.waitForTimeout(1000);
  // Si dialog de confirmation
  await page.evaluate(() => { document.querySelectorAll('button').forEach((b) => { if (/^oui|confirmer|vider/i.test(b.innerText.trim())) b.click(); }); }).catch(() => {});
  await page.waitForTimeout(1000);

  // ===== (2) PARKED → RECALL =====
  await addItem(page, 'Desserts', 'Tiramisu');
  await page.waitForTimeout(700);
  cart = await cartState(page);
  console.log('PARK: cart à parquer:', JSON.stringify(cart.payLabel), JSON.stringify(cart.items));
  // promptParkOrder utilise window.prompt — auto-accept
  page.on('dialog', async (d) => { console.log('DIALOG', d.type(), JSON.stringify(d.message())); await d.accept('R2 parked dessert'); });
  await page.evaluate(() => { document.querySelectorAll('button').forEach((b) => { if (/mettre en attente/i.test(b.innerText)) b.click(); }); });
  await page.waitForTimeout(2500);
  cart = await cartState(page);
  const parkedBadge = await page.evaluate(() => document.querySelector('.pos-v5-btn--park-toggle')?.innerText?.replace(/\s+/g, ' ').trim() || 'ABSENT');
  console.log('PARK: cart après park (vidé ?):', JSON.stringify(cart.payLabel), 'parkedBadge=' + parkedBadge);
  await quartet(page, consoleLog, netLog, 'park01-after-park');

  // Ouvre le panneau parquées + restaure
  await page.evaluate(() => { document.querySelectorAll('button').forEach((b) => { if (/en attente/i.test(b.innerText) && b.className.includes('park-toggle')) b.click(); }); });
  await page.waitForTimeout(1500);
  await quartet(page, consoleLog, netLog, 'park02-parked-panel');
  const parkedList = await page.evaluate(() => Array.from(document.querySelectorAll('[data-testid^="parked"], .parked-order, [class*="parked"]')).map((e) => e.innerText?.replace(/\s+/g, ' ').trim().slice(0, 120)).filter(Boolean).slice(0, 8));
  console.log('PARKED-LIST:', JSON.stringify(parkedList));
  // bouton restaurer (pos.restore = "Restaurer")
  const restored = await page.evaluate(() => { let done = false; document.querySelectorAll('button').forEach((b) => { if (/restaurer/i.test(b.innerText) && !done) { b.click(); done = true; } }); return done; });
  console.log('PARK: restoreClicked=' + restored);
  await page.waitForTimeout(2000);
  cart = await cartState(page);
  console.log('RECALL: cart restauré:', JSON.stringify(cart.payLabel), JSON.stringify(cart.items));
  await quartet(page, consoleLog, netLog, 'park03-recalled-cart');

  // Encaisse la commande rappelée (Espèces)
  if (cart.payLabel) {
    await page.locator('[data-testid="pos-v5-pay"]').click();
    await page.waitForTimeout(2000);
    await page.locator('[data-testid="pos-payment-mode-cash"]').click();
    await page.waitForTimeout(600);
    await page.fill('#cashInput', '10');
    await page.waitForTimeout(500);
    const { status, json } = await confirmAndCapture(page);
    console.log('RECALL ORDER-POST status=' + status + ' id=' + json?.data?.id + ' total=' + json?.data?.total);
    await page.waitForTimeout(3000);
    const rcpt = await receiptText(page);
    console.log('=== RECEIPT RECALLED ===\n' + rcpt.slice(0, 1500));
    await quartet(page, consoleLog, netLog, 'park04-recalled-receipt');
  }
} finally {
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 20)));
  await browser.close();
}
