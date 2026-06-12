// D1 VAGUE E — étape 5 : KDS boundary fullpage (présence A0006 / A0012, lecture seule)
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d1-E-lib.mjs';
import fs from 'fs';

const L = makeLogger('E24-kds');
const { browser, page, consoleBuf, netBuf } = await boot();
await login(page);
await gotoAdmin(page, '/kds');
await page.waitForTimeout(4000);

const scan = await page.evaluate(() => {
  const body = document.body.innerText;
  const queues = [...new Set(body.match(/[AZ]\d{4}/g) || [])];
  return { hasA0006: body.includes('A0006'), hasA0012: body.includes('A0012'), queues, pendingBadge: (body.match(/\+\d+ en attente/) || [null])[0] };
});
L(`KDS scan: ${JSON.stringify(scan)}`);
await page.screenshot({ path: `${OUT}E24-01-kds-fullpage.png`, fullPage: true });
await quartet(page, consoleBuf, netBuf, 'E24-02-kds-viewport');

// Historique KDS (bouton) — toujours lecture seule
const hist = page.locator('button:has-text("Historique"), a:has-text("Historique")').first();
if (await hist.isVisible().catch(() => false)) {
  await hist.click().catch(() => {});
  await page.waitForTimeout(2500);
  const scanH = await page.evaluate(() => {
    const body = document.body.innerText;
    return { hasA0006: body.includes('A0006'), hasA0012: body.includes('A0012'), head: body.split('\n').slice(0, 30).join(' | ') };
  });
  L(`KDS historique: ${JSON.stringify(scanH).slice(0, 600)}`);
  await quartet(page, consoleBuf, netBuf, 'E24-03-kds-historique');
}
L.flush();
await browser.close();
