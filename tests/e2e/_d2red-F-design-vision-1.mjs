// D2 RED (adversarial round-2, vague F) — RE-RUN INDÉPENDANT du P0 ADV-F-P0-1
// Modal « Encaisser la commande borne » : hit-test 14 touches + clics réels séquence
// C,1..9,0,00,C avec listener POST confirm — aux 2 résolutions 1440×900 et 1366×768.
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const BASE = 'http://127.0.0.1:8768';
const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-2/F-design-vision/', import.meta.url).pathname;

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const page = await context.newPage();

const confirmPosts = [];
page.on('request', (r) => {
  if (r.method() === 'POST' && /counter-collect\/\d+\/confirm/.test(r.url())) confirmPosts.push(r.url());
});

// login (compte vague F)
await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await page.waitForSelector('input[autocomplete="email"]', { timeout: 15000 });
await page.fill('input[autocomplete="email"]', 'bm.t2admin@lecayenne.fr');
await page.fill('input[type="password"]', '123456');
await page.click('button[type="submit"]');
await page.waitForURL((u) => !String(u).includes('/login'), { timeout: 20000 });
await page.waitForTimeout(2500);

await page.goto(BASE + '/admin/pos', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);

// session overlay / dialog : ouvrir si nécessaire, sinon fermer
const overlay = page.locator('[data-testid="cash-session-overlay"]');
if (await overlay.isVisible().catch(() => false)) {
  const amountInput = overlay.locator('input').first();
  if (await amountInput.isVisible().catch(() => false)) await amountInput.fill('50').catch(() => {});
  const openBtn = overlay.locator('button', { hasText: /ouvrir|démarrer|commencer/i }).first();
  if (await openBtn.isVisible().catch(() => false)) { await openBtn.click(); await page.waitForTimeout(2500); }
  await page.locator('[data-testid="cash-session-close"]').click().catch(() => page.keyboard.press('Escape'));
  await page.waitForTimeout(1200);
}

async function openModal() {
  // si le drawer est déjà ouvert (bouton collect visible), ne pas re-toggler
  const already = await page.locator('[data-testid^="kiosk-cash-collect-"]').first().isVisible().catch(() => false);
  if (!already) {
    const enc = page.locator('[data-testid="kiosk-cash-open"]');
    if (await enc.isVisible().catch(() => false)) await enc.click();
    else await page.locator('button:has-text("À encaisser")').first().click().catch(() => {});
  }
  await page.waitForTimeout(1500);
  await page.locator('[data-testid^="kiosk-cash-collect-"]').first().click({ timeout: 8000 })
    .catch(async () => { await page.locator('button:has-text("Encaisser")').first().click(); });
  await page.waitForSelector('[data-testid="pos-counter-collect-modal"]', { timeout: 10000 });
  await page.waitForTimeout(800);
}

async function probeAndType(label) {
  const probe = await page.evaluate(() => {
    const modal = document.querySelector('[data-testid="pos-counter-collect-modal"]');
    if (!modal) return { found: false };
    const body = modal.querySelector('.cc-modal-body');
    const footer = modal.querySelector('.cc-modal-footer');
    const keys = Array.from(modal.querySelectorAll('.pos-v5-numpad button, [class*="numpad"] button'));
    const res = keys.map((k) => {
      const r = k.getBoundingClientRect();
      const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
      const at = document.elementFromPoint(cx, cy);
      const ok = at === k || k.contains(at);
      return { key: k.innerText.trim() || k.getAttribute('aria-label') || '?', h: Math.round(r.height), ok, at: ok ? null : (at?.className || at?.tagName || 'null').toString().slice(0, 40) };
    });
    return {
      found: true,
      bodyScroll: body ? { sh: body.scrollHeight, ch: body.clientHeight } : null,
      footerPosition: footer ? getComputedStyle(footer).position : null,
      nKeys: res.length,
      blocked: res.filter((x) => !x.ok).map((x) => x.key + '←' + x.at),
      keyHeights: [...new Set(res.map((x) => x.h))],
      confirmVisible: (() => { const c = modal.querySelector('[data-testid="pos-counter-collect-confirm"]'); if (!c) return false; const r = c.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; })(),
    };
  });
  console.log(label + ' PROBE:', JSON.stringify(probe));

  // clics réels
  const seq = ['C', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '00', 'C'];
  const typed = [];
  for (const key of seq) {
    let clicked = true;
    try {
      await page.locator(`[data-testid="pos-counter-collect-modal"] button`, { hasText: new RegExp(`^${key}$`) }).first().click({ timeout: 4000 });
    } catch { clicked = false; }
    const st = await page.evaluate(() => ({
      value: document.querySelector('[data-testid="pos-counter-collect-received-input"]')?.value ?? null,
      open: !!document.querySelector('[data-testid="pos-counter-collect-modal"]'),
    }));
    typed.push({ key, clicked, value: st.value, open: st.open });
    if (!st.open) break;
  }
  console.log(label + ' TYPED:', JSON.stringify(typed));
  console.log(label + ' CONFIRM-POSTS:', confirmPosts.length);
  await page.screenshot({ path: path.join(OUT, `RED2-F-keypad-${label}.png`) });
}

await openModal();
await probeAndType('1440x900');
await page.locator('[data-testid="pos-counter-collect-cancel"]').click().catch(() => page.keyboard.press('Escape'));
await page.waitForTimeout(1000);

await page.setViewportSize({ width: 1366, height: 768 });
await page.waitForTimeout(1000);
await openModal();
await probeAndType('1366x768');
await page.locator('[data-testid="pos-counter-collect-cancel"]').click().catch(() => page.keyboard.press('Escape'));

console.log('TOTAL CONFIRM POSTS:', confirmPosts.length, confirmPosts);
await browser.close();
