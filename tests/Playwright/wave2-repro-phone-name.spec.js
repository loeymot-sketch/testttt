// WAVE2 REPRO 1 — 2026-08-05 — « Je ne trouve pas où noter le nom du client sur une commande téléphone »
// Agent de REPRODUCTION : aucun code applicatif modifié. Spec jetable.
const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsPosOperator } = require('../e2e/helpers/login');

const OUT = path.resolve(__dirname, '../../reports/goal-8axes-2026-08-05/wave2');
fs.mkdirSync(OUT, { recursive: true });

test.setTimeout(240_000);

async function addFritesToCart(page, log) {
  // Retour catégories si besoin
  await page.evaluate(() => {
    const els = [...document.querySelectorAll('button, div, span, a')];
    const b = els.find((e) => /toutes les cat/i.test((e.innerText || '').trim()) && (e.innerText || '').length < 40);
    if (b) b.click();
  });
  await page.waitForTimeout(800);
  try {
    await page.locator('[data-testid="pos-category-tile"]').filter({ hasText: /^Frites/i }).first().click({ timeout: 8000 });
    await page.waitForTimeout(900);
    await page.locator('.pos-v5-tile').filter({ has: page.locator('.pos-v5-tile__name', { hasText: /^Petite Frites$/ }) }).first().click({ timeout: 8000 });
    await page.waitForTimeout(1500);
    // Wizard éventuel → Ajouter
    const w = await page.evaluate(() => {
      const m = document.querySelector('#item-variation-modal');
      return m && getComputedStyle(m).display !== 'none';
    });
    if (w) {
      await page.evaluate(() => {
        const m = document.querySelector('#item-variation-modal');
        const btn = [...m.querySelectorAll('button')].find((b) => /ajouter/i.test(b.innerText || ''));
        if (btn) btn.click();
      });
      await page.waitForTimeout(1500);
    }
    const lines = await page.locator('.pos-v5-cart-item').count();
    log('cart_lines_after_add', lines);
    return lines > 0;
  } catch (e) {
    log('add_item_fail', e.message.slice(0, 150));
    return false;
  }
}

async function fieldForensics(page, vp) {
  return await page.evaluate((vp) => {
    const q = (sel) => document.querySelector(sel);
    const info = (el) => {
      if (!el) return { inDom: false };
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      // remonte les ancêtres pour trouver un conteneur scrollable (colonne ticket)
      let scroller = null;
      for (let p = el.parentElement; p; p = p.parentElement) {
        const pcs = getComputedStyle(p);
        if (/(auto|scroll)/.test(pcs.overflowY) && p.scrollHeight > p.clientHeight + 4) {
          scroller = {
            cls: String(p.className).slice(0, 80),
            scrollTop: p.scrollTop,
            scrollHeight: p.scrollHeight,
            clientHeight: p.clientHeight,
          };
          break;
        }
      }
      return {
        inDom: true,
        display: cs.display,
        visibility: cs.visibility,
        rect: { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) },
        inViewport: r.top >= 0 && r.bottom <= vp.h && r.width > 0 && r.height > 0,
        belowFold: r.top >= vp.h,
        partiallyBelow: r.top < vp.h && r.bottom > vp.h,
        scroller,
        placeholder: el.placeholder || null,
      };
    };
    return {
      viewport: vp,
      nameField: info(q('[data-testid="pos-customer-name"]')),
      phoneField: info(q('[data-testid="pos-customer-phone"]')),
      phoneCta: info(q('[data-testid="pos-phone-order"]')),
      payBtn: info(q('[data-testid="pos-v5-pay"]')),
      orderTypeValue: (() => {
        const r = document.querySelector('input[name="orderType"]:checked');
        return r ? r.value : null;
      })(),
      phoneCtaText: (q('[data-testid="pos-phone-order"]')?.innerText || '').replace(/\s+/g, ' ').trim() || null,
    };
  }, vp);
}

test('REPRO1 — champ nom client vs CTA commande téléphone (1366x768 + 1920x1080)', async ({ page }) => {
  const R = { bundles: [], sizes: {} };
  const log = (k, v) => { R[k] = R[k] ?? v; console.log('R1|' + k + '|' + JSON.stringify(v)); };

  // Trace les bundles JS chargés (preuve bundle périmé ou pas)
  page.on('response', (res) => {
    const u = res.url();
    if (/\/js\/(pos-app|pos-shell|vendor|manifest)[^?]*\.js/.test(u)) R.bundles.push({ url: u.replace(/^.*\/js\//, 'js/'), status: res.status() });
  });

  await loginAsPosOperator(page);
  await page.waitForTimeout(2500);

  const added = await addFritesToCart(page, (k, v) => console.log('R1|' + k + '|' + JSON.stringify(v)));
  R.itemAdded = added;

  for (const vp of [{ w: 1366, h: 768 }, { w: 1920, h: 1080 }]) {
    await page.setViewportSize({ width: vp.w, height: vp.h });
    await page.waitForTimeout(1200);
    // ÉTAT FROID : remet tous les scrollers à 0 (comme un caissier qui arrive sur l'écran)
    await page.evaluate(() => {
      document.querySelectorAll('*').forEach((e) => { if (e.scrollTop > 0) e.scrollTop = 0; });
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(600);
    const key = `${vp.w}x${vp.h}`;
    R.sizes[key] = await fieldForensics(page, vp);
    console.log('R1|forensics-' + key + '|' + JSON.stringify(R.sizes[key]));
    await page.screenshot({ path: path.join(OUT, `repro1-${key}-viewport.png`), fullPage: false });
    await page.screenshot({ path: path.join(OUT, `repro1-${key}-fullpage.png`), fullPage: true });
    // Zoom sur la colonne ticket si elle existe
    await page.locator('[data-testid="pos-customer-name"]').first().scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(400);
    R.sizes[key].afterScroll = await fieldForensics(page, vp);
    await page.screenshot({ path: path.join(OUT, `repro1-${key}-after-scroll.png`), fullPage: false });
  }

  fs.writeFileSync(path.join(OUT, 'repro1-report.json'), JSON.stringify(R, null, 2));
});
