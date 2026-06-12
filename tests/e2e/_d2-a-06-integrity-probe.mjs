// R2 VAGUE A — INTÉGRITÉ: relève total/discount via admin/pos-order/show/{id} pour les commandes R2
import { boot, login, gotoPos } from './_d2-a-lib.mjs';

const IDS = [4512, 4514, 4526, 4528]; // remise espèces ×2, parquée espèces, TR
const { browser, page } = await boot();
try {
  await login(page);
  await gotoPos(page);
  await page.waitForTimeout(1000);
  const rows = await page.evaluate(async (ids) => {
    const out = [];
    for (const id of ids) {
      try {
        const r = await window.axios.get('admin/pos-order/show/' + id);
        const d = r.data?.data || r.data;
        out.push({
          id,
          serial: d?.order_serial_no,
          total: d?.total,
          discount: d?.discount,
          payStatus: d?.payment_status,
          payMethod: d?.pos_payment_method ?? d?.payment_method,
          source: d?.source,
          items: (d?.order_items || d?.items || []).map((it) => ({ n: it.item_name || it.name, q: it.quantity, t: it.total })),
        });
      } catch (e) { out.push({ id, err: e?.response?.status || e.message }); }
    }
    return out;
  }, IDS);
  console.log('INTEGRITY-SHOW:', JSON.stringify(rows, null, 1));
} finally { await browser.close(); }
