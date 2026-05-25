/**
 * Wave Y-D — GStack Capture Agent Round 2 (AUDIT-ONLY).
 *
 * Re-verifies Round 1 findings F1/F2/F3/F4 post-fix.
 * Critical discriminating signals (per advisor 2026-05-21):
 *   - D3 (F3) — Algérienne (épuisée) MUST refuse click → no order badge, no composition entry
 *   - D5 (F4) — sauce-extra line MUST show dynamic N ("+2 sauces supplémentaires"), never hardcoded "14"
 *   - F1 — CORS / 401 / session-rafraîchie toast MUST be absent post config/cors.php fix
 *   - F2 — pricing preview MUST return 200 on valid composition; OOS-driven 422 now impossible per D3 guard
 *
 * NO FIX AUTHORIZATION. Capture + numeric integrity inspection only.
 *
 * Output:
 *   reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-2/captures/wave-D/*.png
 *   reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-2/captures/wave-D/_console-*.log
 *   reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-2/captures/wave-D/_network-*.log
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const OUT = 'reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-2/captures/wave-D';

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
      fs.appendFileSync(consoleLog, `[${msg.type()}] ${msg.text()}\n`);
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
      if (/\/api\/|\/kiosk\/|\/orders|\/pricing|\/composition|\/broadcasting/.test(url)) {
        fs.appendFileSync(
          networkLog,
          `${res.status()} ${res.request().method()} ${url}\n`
        );
      }
    } catch (e) {}
  });
}

/** Helper: shoot fullPage + viewport screenshots. */
async function shoot(page, idx, label) {
  const base = path.join(OUT, `${String(idx).padStart(3, '0')}-${label}`);
  await page.screenshot({ path: `${base}-full.png`, fullPage: true }).catch(() => {});
  await page.screenshot({ path: `${base}-viewport.png`, fullPage: false }).catch(() => {});
}

async function enterKiosk(page) {
  await page.goto(`${BASE}/kiosk/idle`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.getByText(/À emporter/i).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(2500);
}

async function openItemWizard(page, categoryRegex, itemRegex) {
  const cat = page.getByText(categoryRegex).first();
  if (await cat.isVisible().catch(() => false)) {
    await cat.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  const item = page.getByText(itemRegex).first();
  if (await item.isVisible().catch(() => false)) {
    await item.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  const pers = page.getByText(/Personnaliser/i).first();
  if (await pers.isVisible().catch(() => false)) {
    await pers.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(2500);
  }
}

async function clickNext(page) {
  const next = page.getByRole('button', { name: /^suivant$/i }).first();
  if (await next.isVisible().catch(() => false)) {
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1800);
    return true;
  }
  return false;
}

/** Probe sauce-step specific DOM state (D3 + D5 discriminating). */
async function sauceProbe(page) {
  return page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.kiosk-sauce-grid > *'));
    const list = cards.map((card, idx) => {
      const name = (card.querySelector('.kiosk-sauce-name')?.textContent || '').trim();
      const orderBadge = card.querySelector('.kiosk-sauce-order')?.textContent?.trim() || null;
      const isSelected = card.classList.contains('selected');
      const isOOS = card.classList.contains('is-out-of-stock');
      const isDisabled = card.classList.contains('kiosk-variation--disabled');
      const ariaDisabled = card.getAttribute('aria-disabled');
      const tabindex = card.getAttribute('tabindex');
      return { idx, name, orderBadge, isSelected, isOOS, isDisabled, ariaDisabled, tabindex };
    });
    const extraLine = document.querySelector('.kiosk-sauce-extra')?.textContent?.trim() || null;
    const compositionStrip = document.querySelector(
      '.kiosk-wizard-composition, .kiosk-composition, .kiosk-composition-strip'
    )?.textContent?.trim()?.slice(0, 300) || null;
    return {
      sauceCount: cards.length,
      sauces: list,
      sauceExtraLine: extraLine,
      compositionStrip,
    };
  });
}

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
// 1) SANDWICH CAYENNE — D3+D5 discriminating
// =====================================================================
test('R2-K1 Sandwich Cayenne — D3 OOS-Algérienne + D5 dynamic-N', async ({ page }) => {
  attachRecorders(page, 'K1-sandwich-cayenne');
  await enterKiosk(page);
  await shoot(page, 100, 'K1-00-catalog');

  await openItemWizard(page, /Sandwich Cayenne/i, /Sandwich Cayenne/i);
  await shoot(page, 101, 'K1-01-step-pain');

  // Pain step
  await page.locator('.kiosk-pain-card').first().click({ timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(900);
  await shoot(page, 102, 'K1-02-pain-selected');
  await clickNext(page);
  await shoot(page, 103, 'K1-03-step-viande');

  // Viande step
  await page.locator('.kiosk-viande-card').first().click({ timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(900);
  await shoot(page, 104, 'K1-04-viande-selected');
  await clickNext(page);
  await shoot(page, 105, 'K1-05-step-sauce-fresh');

  // ==================  D3 + D5 critical probe  ==================
  const probe0 = await sauceProbe(page);
  console.log('K1 SAUCE INITIAL PROBE:', JSON.stringify(probe0));
  fs.writeFileSync(path.join(OUT, '_K1-sauce-probe-initial.json'), JSON.stringify(probe0, null, 2));

  // D3 — try to click Algérienne (OOS sauce)
  const algerienneIdx = probe0.sauces.findIndex((s) =>
    /algér|algerienne/i.test(s.name)
  );
  console.log('K1 Algerienne idx:', algerienneIdx, 'isOOS:', probe0.sauces[algerienneIdx]?.isOOS);
  if (algerienneIdx >= 0) {
    const algCard = page.locator('.kiosk-sauce-grid > *').nth(algerienneIdx);
    await algCard.scrollIntoViewIfNeeded().catch(() => {});
    // Force-click via DOM event (script-driven, simulating a stubborn user)
    await algCard.click({ timeout: 3000, force: true }).catch(() => {});
    await page.waitForTimeout(500);
    await shoot(page, 106, 'K1-06-algerienne-click-attempt');
    const probeAfterAlg = await sauceProbe(page);
    fs.writeFileSync(path.join(OUT, '_K1-sauce-probe-after-algerienne.json'), JSON.stringify(probeAfterAlg, null, 2));
    console.log('K1 SAUCE PROBE AFTER ALG:', JSON.stringify(probeAfterAlg));
  }

  // D5 — pick 3 in-stock sauces and verify "+2 sauces supplémentaires"
  // Find first 3 NON-OOS sauces
  const inStockIdxs = probe0.sauces.filter((s) => !s.isOOS && !s.isDisabled).slice(0, 3).map((s) => s.idx);
  for (const idx of inStockIdxs) {
    await page.locator('.kiosk-sauce-grid > *').nth(idx).click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  await shoot(page, 107, 'K1-07-three-sauces-selected');
  const probeAfter3 = await sauceProbe(page);
  fs.writeFileSync(path.join(OUT, '_K1-sauce-probe-after-3-instock.json'), JSON.stringify(probeAfter3, null, 2));
  console.log('K1 SAUCE PROBE AFTER 3 IN-STOCK:', JSON.stringify(probeAfter3));
  // Expected: sauceExtraLine matches /\+2 sauces supplémentaires/ (NOT 14)

  await clickNext(page);
  await shoot(page, 108, 'K1-08-step-after-sauce');
  let inv = await inventory(page);
  fs.writeFileSync(path.join(OUT, '_K1-step-after-sauce-inv.json'), JSON.stringify(inv, null, 2));
  console.log('K1 step-after-sauce inv:', JSON.stringify(inv));

  // Walk forward
  for (let i = 0; i < 4; i++) {
    // Click any visible cards generically
    const sels = ['.kiosk-crudite-card', '.kiosk-garniture-card', '.kiosk-supplement-card', '.kiosk-menu-card', '.kiosk-choice-card'];
    for (const sel of sels) {
      const n = await page.locator(sel).count();
      if (n > 0) {
        await page.locator(sel).first().click({ timeout: 2500 }).catch(() => {});
        await page.waitForTimeout(300);
        break;
      }
    }
    const advanced = await clickNext(page);
    await shoot(page, 109 + i, `K1-${9 + i}-step`);
    if (!advanced) break;
  }
  await shoot(page, 115, 'K1-15-recap-final');

  const ajouter = page.getByRole('button', { name: /Ajouter|Add to cart/i }).first();
  if (await ajouter.isVisible().catch(() => false)) {
    await ajouter.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
  await shoot(page, 116, 'K1-16-after-add-to-cart');
});

// =====================================================================
// 2) TACOS — D3+D5 discriminating
// =====================================================================
test('R2-K2 Tacos — D3 OOS-Algérienne + D5 dynamic-N', async ({ page }) => {
  attachRecorders(page, 'K2-tacos');
  await enterKiosk(page);
  await openItemWizard(page, /Tacos/i, /^Tacos$/);
  await shoot(page, 200, 'K2-00-step-viande');

  await page.locator('.kiosk-viande-card').first().click({ timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(900);
  await shoot(page, 201, 'K2-01-viande-selected');
  await clickNext(page);
  await shoot(page, 202, 'K2-02-step-sauce-fresh');

  // D3+D5 probe
  const probe0 = await sauceProbe(page);
  fs.writeFileSync(path.join(OUT, '_K2-sauce-probe-initial.json'), JSON.stringify(probe0, null, 2));
  console.log('K2 SAUCE INITIAL PROBE:', JSON.stringify(probe0));

  // D3 — Algérienne click attempt
  const algIdx = probe0.sauces.findIndex((s) => /algér|algerienne/i.test(s.name));
  if (algIdx >= 0) {
    await page.locator('.kiosk-sauce-grid > *').nth(algIdx).scrollIntoViewIfNeeded().catch(() => {});
    await page.locator('.kiosk-sauce-grid > *').nth(algIdx).click({ timeout: 3000, force: true }).catch(() => {});
    await page.waitForTimeout(500);
    await shoot(page, 203, 'K2-03-algerienne-click-attempt');
    const probeAfter = await sauceProbe(page);
    fs.writeFileSync(path.join(OUT, '_K2-sauce-probe-after-algerienne.json'), JSON.stringify(probeAfter, null, 2));
    console.log('K2 PROBE AFTER ALG:', JSON.stringify(probeAfter));
  }

  // D5 — pick 3 in-stock sauces (Tacos = 3 sauces typical)
  const inStockIdxs = probe0.sauces.filter((s) => !s.isOOS && !s.isDisabled).slice(0, 3).map((s) => s.idx);
  for (const idx of inStockIdxs) {
    await page.locator('.kiosk-sauce-grid > *').nth(idx).click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  await shoot(page, 204, 'K2-04-three-sauces-selected');
  const probe3 = await sauceProbe(page);
  fs.writeFileSync(path.join(OUT, '_K2-sauce-probe-after-3-instock.json'), JSON.stringify(probe3, null, 2));
  console.log('K2 PROBE AFTER 3:', JSON.stringify(probe3));

  await clickNext(page);
  await shoot(page, 205, 'K2-05-step-after-sauce');

  for (let i = 0; i < 4; i++) {
    const sels = ['.kiosk-supplement-card', '.kiosk-menu-card', '.kiosk-choice-card'];
    for (const sel of sels) {
      const n = await page.locator(sel).count();
      if (n > 0) {
        await page.locator(sel).first().click({ timeout: 2500 }).catch(() => {});
        await page.waitForTimeout(300);
        break;
      }
    }
    const advanced = await clickNext(page);
    await shoot(page, 206 + i, `K2-${6 + i}-extra`);
    if (!advanced) break;
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
// 3) BURGER — Chicken Burger
// =====================================================================
test('R2-K3 Chicken Burger — full walk', async ({ page }) => {
  attachRecorders(page, 'K3-burger');
  await enterKiosk(page);
  // Need explicit Burger category click first (Round 1 G1 gap remediation)
  const burgerCat = page.getByText(/^BURGERS?$/i).first();
  if (await burgerCat.isVisible().catch(() => false)) {
    await burgerCat.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  await shoot(page, 300, 'K3-00-burgers-cat');

  // Then click an item
  const chicken = page.getByText(/Chicken Burger|Big Chicken/i).first();
  if (await chicken.isVisible().catch(() => false)) {
    await chicken.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  const pers = page.getByText(/Personnaliser/i).first();
  if (await pers.isVisible().catch(() => false)) {
    await pers.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(2500);
  }
  await shoot(page, 301, 'K3-01-wizard-open');

  // Walk steps
  for (let s = 0; s < 6; s++) {
    const sels = [
      '.kiosk-pain-card',
      '.kiosk-viande-card',
      '.kiosk-sauce-grid > *',
      '.kiosk-crudite-card',
      '.kiosk-garniture-card',
      '.kiosk-supplement-card',
      '.kiosk-menu-card',
      '.kiosk-choice-card',
    ];
    for (const sel of sels) {
      const n = await page.locator(sel).count();
      if (n > 0) {
        // If sauce step — pick in-stock only via probe
        if (sel.includes('sauce') && s > 0) {
          const probe = await sauceProbe(page);
          fs.writeFileSync(path.join(OUT, `_K3-sauce-step${s}.json`), JSON.stringify(probe, null, 2));
          const inStock = probe.sauces.filter((x) => !x.isOOS && !x.isDisabled).slice(0, 2).map((x) => x.idx);
          for (const idx of inStock) {
            await page.locator('.kiosk-sauce-grid > *').nth(idx).click({ timeout: 2500 }).catch(() => {});
            await page.waitForTimeout(300);
          }
        } else {
          await page.locator(sel).first().click({ timeout: 2500 }).catch(() => {});
        }
        break;
      }
    }
    await page.waitForTimeout(700);
    await shoot(page, 302 + s, `K3-0${s}-step-${s}`);
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
// 4) BOLS
// =====================================================================
test('R2-K4 Bols — full walk', async ({ page }) => {
  attachRecorders(page, 'K4-bols');
  await enterKiosk(page);
  const bolsCat = page.getByText(/Bols/i).first();
  if (await bolsCat.isVisible().catch(() => false)) {
    await bolsCat.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1200);
  }
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

  for (let s = 0; s < 6; s++) {
    const sels = [
      '.kiosk-base-card',
      '.kiosk-taille-card',
      '.kiosk-viande-card',
      '.kiosk-sauce-grid > *',
      '.kiosk-supplement-card',
      '.kiosk-garniture-card',
      '.kiosk-choice-card',
    ];
    for (const sel of sels) {
      const n = await page.locator(sel).count();
      if (n > 0) {
        if (sel.includes('sauce')) {
          const probe = await sauceProbe(page);
          fs.writeFileSync(path.join(OUT, `_K4-sauce-step${s}.json`), JSON.stringify(probe, null, 2));
          const inStock = probe.sauces.filter((x) => !x.isOOS && !x.isDisabled).slice(0, 2).map((x) => x.idx);
          for (const idx of inStock) {
            await page.locator('.kiosk-sauce-grid > *').nth(idx).click({ timeout: 2500 }).catch(() => {});
            await page.waitForTimeout(300);
          }
        } else {
          await page.locator(sel).first().click({ timeout: 2500 }).catch(() => {});
        }
        break;
      }
    }
    await page.waitForTimeout(700);
    await shoot(page, 401 + s, `K4-0${s}-step-${s}`);
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
test('R2-P1 POS wizard popup — login + open wizard', async ({ page }) => {
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

  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.waitForTimeout(3000);
  await shoot(page, 501, 'P1-01-pos-v4');

  const item = page.getByText(/Sandwich Cayenne|Tacos|Burger/i).first();
  if (await item.isVisible().catch(() => false)) {
    await item.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  await shoot(page, 502, 'P1-02-pos-item-clicked');

  const popupSel = '.pos-wizard, .pos-wizard-modal, #pos-wizard, .wizard-popup';
  const popupVisible = await page.locator(popupSel).first().isVisible().catch(() => false);
  console.log('P1 wizard popup visible:', popupVisible);

  if (popupVisible) {
    await shoot(page, 503, 'P1-03-pos-wizard-step1');
    for (let s = 0; s < 6; s++) {
      const card = page
        .locator(`${popupSel} .wizard-card, ${popupSel} .pos-wizard-card, ${popupSel} button[data-choice]`)
        .first();
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
    const snippet = await page.evaluate(() => document.body.innerHTML.slice(0, 2000));
    fs.writeFileSync(path.join(OUT, '_P1-pos-body-snippet.html'), snippet);
  }
  await shoot(page, 520, 'P1-99-pos-final');
});
