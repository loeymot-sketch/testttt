// D2 ADVERSARIAL re-verification — Vague E cross-surface (dispute round 2)
// (1) facture 4520: champs NF525 + bloc totaux ; (2) replay quote consommé 4520 → attendu 409/410 ;
// (3) tamper items sur quote frais → attendu 409 ; (4) preuve directe drop fidélité status=5 (quote only).
import { boot, login, BASE } from './_d1-E-lib.mjs';
import fs from 'fs';
const APIKEY = fs.readFileSync(new URL('../../.env.e2e', import.meta.url).pathname, 'utf8').match(/^MIX_API_KEY=(.*)$/m)?.[1]?.trim();

const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-2/E-cross-surface/', import.meta.url).pathname;
const lines = [];
const L = (m) => { const s = `[${new Date().toISOString().slice(11, 19)}] ${m}`; lines.push(s); console.log(s); };
const flush = () => fs.writeFileSync(`${OUT}_log-E-RED2-verify.txt`, lines.join('\n') + '\n');
async function quartet(page, consoleBuf, netBuf, name) {
  await page.screenshot({ path: `${OUT}${name}.png` }).catch((e) => console.log(`SHOT-FAIL ${name}: ${e.message?.slice(0, 100)}`));
  const dom = await page.content().catch(() => '(content unavailable)');
  fs.writeFileSync(`${OUT}${name}.dom.html`, dom.slice(0, 80 * 1024));
  fs.writeFileSync(`${OUT}${name}.console.txt`, (consoleBuf.length ? consoleBuf.join('\n') : '(aucune erreur/warning console depuis le boot)') + '\n');
  fs.writeFileSync(`${OUT}${name}.network.txt`, (netBuf.length ? netBuf.join('\n') : '(aucune réponse >=400 depuis le boot)') + '\n');
  console.log(`QUARTET ${name}`);
}

const ITEMS = JSON.stringify([
  { item_id: 26, instruction: 'Viandes : Poulet mariné ×1', quantity: 1, item_variations: [{ id: 43, name: 'Poulet mariné', variation_name: 'Viande 1' }, { id: 311, name: 'Algérienne', variation_name: 'Sauce (1ère Gratuite)' }], item_extras: [] },
  { item_id: 52, instruction: '', quantity: 1, item_variations: [], item_extras: [] },
]);
const ITEMS_TAMPERED = JSON.stringify([
  { item_id: 26, instruction: 'Viandes : Poulet mariné ×1', quantity: 2, item_variations: [{ id: 43, name: 'Poulet mariné', variation_name: 'Viande 1' }, { id: 311, name: 'Algérienne', variation_name: 'Sauce (1ère Gratuite)' }], item_extras: [] },
  { item_id: 52, instruction: '', quantity: 1, item_variations: [], item_extras: [] },
]);

// ===== Part A — kiosk API probes (machine token via auto-login page) =====
{
  const { browser, page, consoleBuf, netBuf } = await boot({ kiosk: true });
  try {
    await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);
    const token = await page.evaluate(() => {
      for (let i = 0; i < localStorage.length; i++) {
        const v = localStorage.getItem(localStorage.key(i));
        try {
          const j = JSON.parse(v);
          const stack = [j];
          while (stack.length) {
            const o = stack.pop();
            if (o && typeof o === 'object') {
              if (typeof o.kioskToken === 'string' && o.kioskToken.length > 10) return o.kioskToken;
              for (const k of Object.keys(o)) stack.push(o[k]);
            }
          }
        } catch { /* not json */ }
      }
      return null;
    });
    L(`kiosk token found: ${token ? token.slice(0, 12) + '…' : 'NULL'}`);
    if (!token) throw new Error('no kiosk token');

    const callApi = (path, body) => page.evaluate(async ({ path, body, token, apiKey }) => {
      const r = await fetch(path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json', Accept: 'application/json',
          Authorization: `Bearer ${token}`,
          'X-Idempotency-Key': crypto.randomUUID(),
          'x-api-key': apiKey,
        },
        body: JSON.stringify(body),
      });
      const t = await r.text();
      return { status: r.status, body: t.slice(0, 700) };
    }, { path, body, token, apiKey: APIKEY });

    const baseQuote = {
      order_type: 10, is_advance_order: 10, source: 5, payment_method: 0, items: ITEMS,
    };

    // (4) preuve directe: fidélité status=5 droppée au quote (AUCUNE commande créée)
    const q1 = await callApi('/api/frontend/order/quote', { ...baseQuote, loyalty_code: 'VICT1234', loyalty_redeem_discount: 1.65, kiosk_promo_code: 'BORNEAUDIT5' });
    L(`P4 QUOTE loyalty+promo (status=5): ${q1.status} ${q1.body}`);

    // (2) replay du quote CONSOMMÉ de 4520 (token+signature de _E11-order.json)
    const replay = await callApi('/api/frontend/order', {
      order_type: 10, loyalty_code: 'VICT1234', loyalty_redeem_discount: 1.65, kiosk_promo_code: 'BORNEAUDIT5',
      is_advance_order: 10, source: 5, payment_method: 1, items: ITEMS,
      quote_token: '2569656f-277d-43a5-a672-d0837a886253',
      quote_signature: 'e0f2c84fea6333a535dac04e18ca7a39cb3407f38fff92df13da00ed07934e81',
      subtotal: 10, discount: 5, delivery_charge: 0, total: 5,
    });
    L(`P2 REPLAY quote consommé 4520 → ${replay.status} ${replay.body.slice(0, 300)}`);

    // (3) quote frais promo, puis order avec items TAMPERÉS (qty 2)
    const q2 = await callApi('/api/frontend/order/quote', { ...baseQuote, kiosk_promo_code: 'BORNEAUDIT5', loyalty_code: null, loyalty_redeem_discount: 0 });
    L(`P3a QUOTE frais: ${q2.status} ${q2.body.slice(0, 350)}`);
    let q2j = null; try { q2j = JSON.parse(q2.body); } catch {}
    const qt = q2j?.quote_token || q2j?.data?.quote_token;
    const qs = q2j?.signature || q2j?.data?.signature;
    if (qt && qs) {
      const tampered = await callApi('/api/frontend/order', {
        order_type: 10, loyalty_code: null, loyalty_redeem_discount: 0, kiosk_promo_code: 'BORNEAUDIT5',
        is_advance_order: 10, source: 5, payment_method: 1, items: ITEMS_TAMPERED,
        quote_token: qt, quote_signature: qs,
        subtotal: 10, discount: 5, delivery_charge: 0, total: 5,
      });
      L(`P3b ORDER items tamperés sur quote frais → ${tampered.status} ${tampered.body.slice(0, 300)}`);
    } else {
      L('P3b SKIP: quote frais sans token/signature lisible');
    }
    await quartet(page, consoleBuf, netBuf, 'E-RED2-01-kiosk-probes');
  } catch (e) {
    L(`PART-A FAIL: ${e.message}`);
  } finally {
    flush();
    await browser.close();
  }
}

// ===== Part B — facture 4520 (champs NF525 + bloc totaux) =====
{
  const { browser, page, consoleBuf, netBuf } = await boot();
  try {
    await login(page);
    L('login admin OK');
    await page.goto(BASE + '/admin/pos-orders/show/4520', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);
    const printBtn = page.locator('button:has-text("Imprimer la facture"), button:has-text("Imprimer La Facture")').first();
    await printBtn.click();
    await page.waitForTimeout(3000);
    const receiptText = await page.evaluate(() => {
      const cands = [...document.querySelectorAll('.modal, [class*="receipt"], [id*="receipt"], [class*="print"], [id*="print"]')]
        .filter((el) => el.offsetParent !== null || el.offsetHeight > 0);
      const el = cands.sort((a, b) => (b.innerText || '').length - (a.innerText || '').length)[0];
      return el ? el.innerText : '(modal introuvable)';
    });
    L('FACTURE 4520 LIVE:\n' + receiptText.split('\n').filter((s) => s.trim()).join('\n'));
    await quartet(page, consoleBuf, netBuf, 'E-RED2-02-facture-4520');
  } catch (e) {
    L(`PART-B FAIL: ${e.message}`);
  } finally {
    flush();
    await browser.close();
  }
}
L('E-RED2 done');
flush();
