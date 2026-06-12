// D2 VAGUE E — sniff du feed KDS réel (réponse réseau de l'app elle-même)
import fs from 'fs';
import { BASE, OUT, boot, quartet, makeLogger, login, gotoAdmin } from './_d2-E-lib.mjs';

const L = makeLogger('E22-kds-sniff');
const { browser, page, consoleBuf, netBuf } = await boot();

const feeds = [];
page.on('response', async (r) => {
  if (r.url().includes('kds-order') && r.request().method() === 'GET' && !r.url().includes('items')) {
    let json = null; try { json = await r.json(); } catch {}
    feeds.push({ url: r.url().slice(30, 140), status: r.status(), count: json?.data?.length, meta: json?.meta, orders: (json?.data || []).map((o) => ({ id: o.id, queue: o.queue_number ?? o.queue, status: o.status, pay: o.payment_status, total: o.total })) });
  }
});

await login(page);
await gotoAdmin(page, '/kds');
await page.waitForTimeout(6000);
const last = feeds[feeds.length - 1];
L(`KDS feed calls: ${feeds.length}`);
L(`dernier: status=${last?.status} count=${last?.count} meta=${JSON.stringify(last?.meta)}`);
L(JSON.stringify(last?.orders?.slice(0, 25)));
const mine = (last?.orders || []).find((o) => o.id === 4520);
L(`ORDER 4520 dans le feed: ${JSON.stringify(mine)}`);
await quartet(page, consoleBuf, netBuf, 'E22-01-kds');
fs.writeFileSync(`${OUT}_E22-kds-feed.json`, JSON.stringify(feeds, null, 2));
L.flush();
await browser.close();
