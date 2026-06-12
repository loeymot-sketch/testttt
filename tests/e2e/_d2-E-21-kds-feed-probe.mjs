// D2 VAGUE E — probe : la commande encaissée 4520 (A0009) est-elle dans le FEED KDS ?
// (grille FIFO 8 cartes + badge overflow — la présence dans le feed = boundary OK)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d2-E-lib.mjs';

const L = makeLogger('E21-kds-probe');
const { browser, page, consoleBuf, netBuf } = await boot();
await login(page);

const feed = await page.evaluate(async () => {
  const r = await window.axios.get('/api/admin/kds-order', { params: { branch_id: 1 } }).catch((e) => ({ error: e?.response?.status }));
  const list = r?.data?.data || r?.data || [];
  return Array.isArray(list) ? list.map((o) => ({ id: o.id, queue: o.queue_number, status: o.status, payment_status: o.payment_status, total: o.total })) : { raw: r?.data, error: r?.error };
});
const arr = Array.isArray(feed) ? feed : [];
L(`KDS FEED: ${arr.length} commandes`);
L(JSON.stringify(arr.slice(0, 20)));
const mine = arr.find((o) => o.id === 4520);
L(`ORDER 4520 dans le feed: ${JSON.stringify(mine)}`);

// scroll la page KDS et re-capture l'état avec le badge overflow
await gotoAdmin(page, '/kds');
await page.waitForTimeout(4000);
const overflow = await page.evaluate(() => {
  const el = Array.from(document.querySelectorAll('*')).find((e) => /en attente/i.test(e.innerText || '') && e.children.length === 0);
  return el ? el.innerText.trim() : null;
});
L(`overflow badge: ${JSON.stringify(overflow)}`);
await quartet(page, consoleBuf, netBuf, 'E21-01-kds-overflow');
fs.writeFileSync(`${OUT}_E21-kds-feed.json`, JSON.stringify({ feed: arr, mine, overflow }, null, 2));
L.flush();
await browser.close();
