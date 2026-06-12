// D2 VAGUE E — receipt de 4520 (borne, remise 5,00) : extraction #receiptModal
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d2-E-lib.mjs';

const L = makeLogger('E50-receipt-4520');
const { browser, page, consoleBuf, netBuf } = await boot();
await login(page);
await gotoAdmin(page, '/admin/pos-orders/show/4520');
await page.waitForTimeout(3000);
// le modal receipt est TOUJOURS monté (caché) — textContent fonctionne sur nœud hidden
const receipt = await page.evaluate(() => {
  const r = document.querySelector('#receiptModal #print') || document.querySelector('#receiptModal');
  if (!r) return null;
  // reconstruire ligne-par-ligne depuis les blocs
  const walk = (el, out) => {
    for (const child of el.children) {
      if (child.children.length === 0) {
        const t = (child.textContent || '').replace(/\s+/g, ' ').trim();
        if (t) out.push(t);
      } else walk(child, out);
    }
    return out;
  };
  return walk(r, []);
});
L(`RECEIPT 4520 (${receipt?.length ?? 0} lignes):\n  ${(receipt || []).join('\n  ')}`);
await quartet(page, consoleBuf, netBuf, 'E50-01-receipt-4520');
fs.writeFileSync(`${OUT}_E50-receipt-4520.txt`, (receipt || []).join('\n') || 'ABSENT');
L.flush();
await browser.close();
