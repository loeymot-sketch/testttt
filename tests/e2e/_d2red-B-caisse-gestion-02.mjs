// ADVERSARIAL R2 — fullPage bas-de-page encaissement + cash-overview (l'inner-scroll-container
// rend window.scrollTo inopérant — cause racine des captures « bas » identiques du GStack ET de mon 01)
import { BASE, OUT, boot, mkLogger, login } from './_d2-B-lib.mjs';

const L = mkLogger('red2-b2');
const { browser, page } = await boot();
try {
  await login(page, L);
  await page.goto(BASE + '/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  await page.screenshot({ path: OUT + 'red2-b-10-encaissement-FULLPAGE.png', fullPage: true });
  L('fullpage encaissement OK');
  await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  await page.screenshot({ path: OUT + 'red2-b-11-cash-overview-FULLPAGE.png', fullPage: true });
  L('fullpage overview OK');
  L('DONE');
} catch (e) { L('FATAL ' + e.message); } finally { L.flush(); await browser.close(); }
