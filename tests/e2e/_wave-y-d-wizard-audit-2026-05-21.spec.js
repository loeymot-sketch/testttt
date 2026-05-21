/**
 * Wave Y-D — GStack Capture Agent (AUDIT-ONLY).
 *
 * Scope: Wizard templates — kiosk (Sandwich, Tacos, Burger, Bols)
 *        + POS Vanilla JS wizard popup.
 *
 * NO FIX AUTHORIZATION. Capture + numeric integrity inspection only.
 *
 * Output:
 *   reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-D/*.png
 *   reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-D/_console-*.log
 *   reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-D/_network-*.log
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/captures/wave-D';

if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

test.use({ viewport: { width: 1366, height: 1200 } });
test.setTimeout(180_000);

/** Helper: attach a console + network recorder per page. */
function attachRecorders(page, tag) {
  const consoleLog = path.join(OUT, `_console-${tag}.log`);
  const networkLog = path.join(OUT, `_network-${tag}.log`);
  fs.writeFileSync(consoleLog, '');
  fs.writeFileSync(networkLog, '');

  page.on('console', (msg) => {
    try {
      fs.appendFileSync(
        consoleLog,
        `[${msg.type()}] ${msg.text()}\n`
      );
    } catch (e) {}
  });
  page.on('pageerror', (err) => {
    try {
      fs.appendFileSync(consoleLog, `[pageerror] ${err.message}\n`);
    } catch (e) {}
  });
  page.on('response', async (res) => {
    try {
      const url = res.url();
      if (/\/api\/|\/kiosk\/|\/orders|\/pricing|\/composition/.test(url)) {
        fs.appendFileSync(
          networkLog,
          `${res.status()} ${res.request().method()} ${url}\n`
        );
      }
    } catch (e) {}
  });
}

/** Helper: shoot fullPage + viewport screenshots with sortable index. */
async function shoot(page, idx, label) {
  const base = path.join(OUT, `${String(idx).padStart(3, '0')}-${label}`);
  await page.screenshot({ path: `${base}-full.png`, fullPage: true }).catch(() => {});
  await page.screenshot({ path: `${base}-viewport.png`, fullPage: false }).catch(() => {});
}

/** Helper: enter kiosk and reach category strip. */
async function enterKiosk(page) {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  // Tap idle screen (overlay or button "À emporter") to enter
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
}

/** Helper: open an item wizard from catalog by category + item label. */
async function openItemWizard(page, categoryRegex, itemRegex) {
  // Click category if visible (some flows auto-show)
  const cat = page.getByText(categoryRegex).first();
  if (await cat.isVisible().catch(() => false)) {
    await cat.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  // Click item card
  const item = page.getByText(itemRegex).first();
  if (await item.isVisible().catch(() => false)) {
    await item.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  // Click "Personnaliser" if present
  const pers = page.getByText(/Personnaliser/i).first();
  if (await pers.isVisible().catch(() => false)) {
    await pers.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(2500);
  }
}

/** Helper: click SUIVANT with retry. */
async function clickNext(page) {
  const next = page.getByRole('button', { name: /^suivant$/i }).first();
  if (await next.isVisible().catch(() => false)) {
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1800);
    return true;
  }
  return false;
}

/** Probe DOM for inventory of a wizard step. */
async function inventory(page) {
  return page.evaluate(() => {
    const get = (sel) => {
      const list = Array.from(document.querySelectorAll(sel));
      return list.map((n) => (n.textContent || '').trim().split('\n')[0].slice(0, 60));
    };
    const stepTitle =
      document.querySelector('.kiosk-wizard__step-title, .kiosk-step-title, h1, h2')?.textContent?.trim() ||
      null;
    return {
      stepTitle,
      pain: get('.kiosk-pain-card'),
      viande: get('.kiosk-viande-card'),
      sauce: get('.kiosk-sauce-grid > *'),
      crudite: get('.kiosk-crudite-card, .kiosk-garniture-card'),
      supplement: get('.kiosk-supplement-card'),
      base: get('.kiosk-base-card, .kiosk-taille-card'),
      genericChoice: get('.kiosk-choice-card, .kiosk-generic-card'),
      menuAddon: get('.kiosk-menu-card'),
      suivantEnabled: !!document.querySelector(
        'button[name="suivant"]:not([disabled]), button.kiosk-next:not([disabled])'
      ) ||
        Array.from(document.querySelectorAll('button')).some(
          (b) => /suivant/i.test(b.textContent || '') && !b.disabled
        ),
      cartTotal:
        document.querySelector('.kiosk-cart-total, .kiosk-cart__total')?.textContent?.trim() || null,
      anyRawLabel: (() => {
        const txt = document.body.innerText || '';
        const m = txt.match(/\b(kiosk\.[a-z_\.]+|Label\.[A-Za-z0-9_.]+|undefined|null undefined)\b/);
        return m ? m[0] : null;
      })(),
    };
  });
}

// =====================================================================
// 1) SANDWICH WIZARD — Sandwich Cayenne
// =====================================================================
test('K1 Sandwich Cayenne wizard — full walk', async ({ page }) => {
  attachRecorders(page, 'K1-sandwich-cayenne');
  await enterKiosk(page);
  await shoot(page, 100, 'K1-00-catalog');

  await openItemWizard(page, /Sandwich Cayenne/i, /Sandwich Cayenne/i);
  await shoot(page, 101, 'K1-01-step-pain');
  let inv = await inventory(page);
  console.log('K1 step1 inv:', JSON.stringify(inv));

  // Pain step → click pain card
  await page.locator('.kiosk-pain-card').first().click({ timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(900);
  await shoot(page, 102, 'K1-02-pain-selected');
  await clickNext(page);
  await shoot(page, 103, 'K1-03-step-viande');
  inv = await inventory(page);
  console.log('K1 step2 inv:', JSON.stringify(inv));

  await page.locator('.kiosk-viande-card').first().click({ timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(900);
  await shoot(page, 104, 'K1-04-viande-selected');
  await clickNext(page);
  await shoot(page, 105, 'K1-05-step-sauce');
  inv = await inventory(page);
  console.log('K1 step3 inv (sauce count =', inv.sauce.length, '):', JSON.stringify(inv));

  // Pick 2 sauces (typical sandwich limit)
  const sauceCards = page.locator('.kiosk-sauce-grid > *');
  const sauceN = await sauceCards.count();
  for (let i = 0; i < Math.min(2, sauceN); i++) {
    await sauceCards.nth(i).click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  await shoot(page, 106, 'K1-06-sauces-selected');
  await clickNext(page);
  await shoot(page, 107, 'K1-07-step-crudite-or-menu');
  inv = await inventory(page);
  console.log('K1 step4 inv:', JSON.stringify(inv));

  // Crudités if shown (4 options expected: salade, tomate, oignon, cornichon)
  const crudN = await page.locator('.kiosk-crudite-card, .kiosk-garniture-card').count();
  if (crudN > 0) {
    for (let i = 0; i < Math.min(4, crudN); i++) {
      await page.locator('.kiosk-crudite-card, .kiosk-garniture-card').nth(i).click({ timeout: 2500 }).catch(() => {});
      await page.waitForTimeout(300);
    }
    await shoot(page, 108, 'K1-08-crudites-selected');
    await clickNext(page);
  }

  await shoot(page, 109, 'K1-09-step-menu-or-recap');
  inv = await inventory(page);
  console.log('K1 step5 inv:', JSON.stringify(inv));

  // Try advance to recap
  for (let i = 0; i < 3; i++) {
    const advanced = await clickNext(page);
    if (!advanced) break;
    await shoot(page, 110 + i, `K1-1${i}-extra-step`);
  }
  await shoot(page, 115, 'K1-15-recap-final');

  // Confirm add to cart
  const ajouter = page.getByRole('button', { name: /Ajouter|Add to cart/i }).first();
  if (await ajouter.isVisible().catch(() => false)) {
    await ajouter.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  await shoot(page, 116, 'K1-16-after-add-to-cart');
});

// =====================================================================
// 2) TACOS WIZARD
// =====================================================================
test('K2 Tacos wizard — full walk', async ({ page }) => {
  attachRecorders(page, 'K2-tacos');
  await enterKiosk(page);
  await openItemWizard(page, /Tacos/i, /^Tacos$/);
  await shoot(page, 200, 'K2-00-step-viande');
  let inv = await inventory(page);
  console.log('K2 step1 inv:', JSON.stringify(inv));

  await page.locator('.kiosk-viande-card').first().click({ timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(900);
  await shoot(page, 201, 'K2-01-viande-selected');
  await clickNext(page);
  await shoot(page, 202, 'K2-02-step-sauce');
  inv = await inventory(page);
  console.log('K2 step2 inv (sauce count =', inv.sauce.length, '):', JSON.stringify(inv));

  // Tacos = 3 sauces typical
  const sauceCards = page.locator('.kiosk-sauce-grid > *');
  const sauceN = await sauceCards.count();
  for (let i = 0; i < Math.min(3, sauceN); i++) {
    await sauceCards.nth(i).click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  await shoot(page, 203, 'K2-03-sauces-selected');
  await clickNext(page);
  await shoot(page, 204, 'K2-04-step-menu-or-next');
  inv = await inventory(page);
  console.log('K2 step3 inv:', JSON.stringify(inv));

  for (let i = 0; i < 3; i++) {
    const advanced = await clickNext(page);
    if (!advanced) break;
    await shoot(page, 205 + i, `K2-${5 + i}-extra`);
  }
  await shoot(page, 210, 'K2-10-recap');

  const ajouter = page.getByRole('button', { name: /Ajouter/i }).first();
  if (await ajouter.isVisible().catch(() => false)) {
    await ajouter.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  await shoot(page, 211, 'K2-11-after-add-to-cart');
});

// =====================================================================
// 3) BURGER WIZARD — Chicken Burger
// =====================================================================
test('K3 Chicken Burger wizard — full walk', async ({ page }) => {
  attachRecorders(page, 'K3-burger');
  await enterKiosk(page);
  await openItemWizard(page, /Burgers|Burger/i, /Chicken Burger|Burger/i);
  await shoot(page, 300, 'K3-00-step1');
  let inv = await inventory(page);
  console.log('K3 step1 inv:', JSON.stringify(inv));

  // Iterate up to 6 steps (burger has variable depth)
  for (let s = 0; s < 6; s++) {
    // Click first viable selection in each step (priority order)
    const selectors = [
      '.kiosk-pain-card',
      '.kiosk-viande-card',
      '.kiosk-sauce-grid > *',
      '.kiosk-crudite-card',
      '.kiosk-garniture-card',
      '.kiosk-supplement-card',
      '.kiosk-base-card',
      '.kiosk-taille-card',
      '.kiosk-menu-card',
      '.kiosk-choice-card',
    ];
    let picked = false;
    for (const sel of selectors) {
      const n = await page.locator(sel).count();
      if (n > 0) {
        await page.locator(sel).first().click({ timeout: 2500 }).catch(() => {});
        // for sauce step pick 2
        if (sel.includes('sauce')) {
          for (let i = 1; i < Math.min(2, n); i++) {
            await page.locator(sel).nth(i).click({ timeout: 2000 }).catch(() => {});
            await page.waitForTimeout(200);
          }
        }
        picked = true;
        break;
      }
    }
    await page.waitForTimeout(700);
    await shoot(page, 300 + s, `K3-0${s}-step-${s}`);
    inv = await inventory(page);
    console.log(`K3 step${s} inv:`, JSON.stringify(inv));
    const advanced = await clickNext(page);
    if (!advanced) break;
  }
  await shoot(page, 310, 'K3-10-recap');

  const ajouter = page.getByRole('button', { name: /Ajouter/i }).first();
  if (await ajouter.isVisible().catch(() => false)) {
    await ajouter.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  await shoot(page, 311, 'K3-11-after-add');
});

// =====================================================================
// 4) BOLS WIZARD — Bowl Frites
// =====================================================================
test('K4 Bols Frites wizard — full walk', async ({ page }) => {
  attachRecorders(page, 'K4-bols');
  await enterKiosk(page);
  // Try Bols category
  const bolsCat = page.getByText(/Bols/i).first();
  if (await bolsCat.isVisible().catch(() => false)) {
    await bolsCat.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  // Click first bowl item
  const bowlItem = page.getByText(/Bowl Frites|Bol Frites|Bowl Riz|Bol Riz/i).first();
  if (await bowlItem.isVisible().catch(() => false)) {
    await bowlItem.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  const pers = page.getByText(/Personnaliser/i).first();
  if (await pers.isVisible().catch(() => false)) {
    await pers.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(2200);
  }
  await shoot(page, 400, 'K4-00-step-base');
  let inv = await inventory(page);
  console.log('K4 step1 inv:', JSON.stringify(inv));

  // Walk up to 6 steps (base, viande, sauce, suppléments, gratiné, recap)
  for (let s = 0; s < 6; s++) {
    const selectors = [
      '.kiosk-base-card',
      '.kiosk-taille-card',
      '.kiosk-viande-card',
      '.kiosk-sauce-grid > *',
      '.kiosk-supplement-card',
      '.kiosk-garniture-card',
      '.kiosk-choice-card',
    ];
    for (const sel of selectors) {
      const n = await page.locator(sel).count();
      if (n > 0) {
        await page.locator(sel).first().click({ timeout: 2500 }).catch(() => {});
        if (sel.includes('sauce') && n > 1) {
          await page.locator(sel).nth(1).click({ timeout: 2000 }).catch(() => {});
        }
        break;
      }
    }
    await page.waitForTimeout(700);
    await shoot(page, 400 + s, `K4-0${s}-step-${s}`);
    inv = await inventory(page);
    console.log(`K4 step${s} inv:`, JSON.stringify(inv));
    const advanced = await clickNext(page);
    if (!advanced) break;
  }
  await shoot(page, 410, 'K4-10-recap');

  const ajouter = page.getByRole('button', { name: /Ajouter/i }).first();
  if (await ajouter.isVisible().catch(() => false)) {
    await ajouter.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  await shoot(page, 411, 'K4-11-after-add');
});

// =====================================================================
// 5) POS Vanilla JS wizard popup
// =====================================================================
test('P1 POS wizard popup — login + open wizard', async ({ page }) => {
  attachRecorders(page, 'P1-pos-wizard');
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.fill('input[name="email"]', 'admin@lecayenne.fr').catch(() => {});
  await page.fill('input[name="password"]', '123456').catch(() => {});
  await page.click('button[type="submit"]').catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

  await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  await shoot(page, 500, 'P1-00-pos-default');

  // Try v4 specifically (where wizard popup lives)
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(3000);
  await shoot(page, 501, 'P1-01-pos-v4');

  // Click an item that opens wizard popup (Sandwich Cayenne is canonical)
  const item = page.getByText(/Sandwich Cayenne|Tacos|Burger/i).first();
  if (await item.isVisible().catch(() => false)) {
    await item.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  await shoot(page, 502, 'P1-02-pos-item-clicked');

  // Look for wizard popup (typical class: .pos-wizard / .pos-wizard-modal / #pos-wizard)
  const popupSel = '.pos-wizard, .pos-wizard-modal, #pos-wizard, .wizard-popup';
  const popupVisible = await page.locator(popupSel).first().isVisible().catch(() => false);
  console.log('P1 wizard popup visible:', popupVisible);

  if (popupVisible) {
    await shoot(page, 503, 'P1-03-pos-wizard-step1');
    // Try walk through steps by clicking first card + next
    for (let s = 0; s < 6; s++) {
      const card = page.locator(`${popupSel} .wizard-card, ${popupSel} .pos-wizard-card, ${popupSel} button[data-choice]`).first();
      if (await card.isVisible().catch(() => false)) {
        await card.click({ timeout: 2500 }).catch(() => {});
        await page.waitForTimeout(500);
      }
      await shoot(page, 504 + s, `P1-0${4 + s}-pos-step-${s}`);
      const nextBtn = page.locator(`${popupSel} button`).filter({ hasText: /suivant|next/i }).first();
      if (await nextBtn.isVisible().catch(() => false)) {
        await nextBtn.click({ timeout: 2000 }).catch(() => {});
        await page.waitForTimeout(800);
      } else {
        break;
      }
    }
  } else {
    console.log('P1 — no wizard popup found at standard selectors');
    // Dump body HTML snippet for forensics
    const snippet = await page.evaluate(() => document.body.innerHTML.slice(0, 2000));
    fs.writeFileSync(path.join(OUT, '_P1-pos-body-snippet.html'), snippet);
  }

  await shoot(page, 520, 'P1-99-pos-final');
});
