// KDS cook-logic audit — shared helpers (test harness only, no app code).
import { chromium } from 'playwright';

export const BASE = 'http://127.0.0.1:8000';
export const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
export const SHOTS = new URL('./shots/', import.meta.url).pathname;

export async function loginAndGetContext(browser, { email = 'admin@lecayenne.fr', password = '123456' } = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#formEmail, input[type="email"]', { timeout: 30000 });
  const emailSel = (await page.$('#formEmail')) ? '#formEmail' : 'input[type="email"]';
  await page.fill(emailSel, email);
  await page.fill('input[type="password"]', password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(2500);
  const token = await page.evaluate(() => {
    try { return JSON.parse(localStorage.getItem('vuex') || '{}')?.auth?.authToken || null; } catch { return null; }
  });
  return { ctx, page, token };
}

export function api(token) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'x-api-key': API_KEY,
    Authorization: `Bearer ${token}`,
  };
  return {
    async get(path) {
      const r = await fetch(`${BASE}/api${path}`, { headers });
      let body = null; try { body = await r.json(); } catch {}
      return { status: r.status, body };
    },
    async post(path, payload, extraHeaders = {}) {
      const r = await fetch(`${BASE}/api${path}`, {
        method: 'POST', headers: { ...headers, ...extraHeaders },
        body: JSON.stringify(payload ?? {}),
      });
      let body = null; try { body = await r.json(); } catch {}
      return { status: r.status, body };
    },
  };
}

export function idem() {
  return { 'X-Idempotency-Key': `zz-test-${Date.now()}-${Math.random().toString(36).slice(2, 10)}` };
}

// Create a POS order the way the caisse does: quote → store (quote binding).
export async function createPosOrder(client, { items, scheduledAt = null, customerName = null, note = null }) {
  const payload = {
    customer_id: 2, // Client passage
    branch_id: 1,
    order_type: 15, // OrderType::POS
    is_advance_order: 0,
    source: 15, // Source::POS
    pos_payment_method: 1, // CASH
    items: JSON.stringify(items),
  };
  if (scheduledAt) payload.scheduled_at = scheduledAt;
  if (customerName) payload.pos_customer_name = customerName;
  if (note) payload.pos_payment_note = note;

  const quote = await client.post('/admin/pos/quote', payload);
  if (quote.status !== 200) return { step: 'quote', ...quote };
  const q = quote.body?.data || {};
  payload.subtotal = q.subtotal;
  payload.total = q.total_ttc ?? q.total;
  payload.pos_received_amount = q.total_ttc ?? q.total;
  payload.quote_token = q.quote_token;
  payload.quote_signature = q.signature;
  const store = await client.post('/admin/pos', payload, idem());
  return { step: 'store', quote: q, ...store };
}

export async function launch() {
  return chromium.launch({ headless: true });
}

export function log(...a) { console.log(...a); }
