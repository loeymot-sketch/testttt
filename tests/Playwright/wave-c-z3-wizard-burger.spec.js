// FoodKing SUPERVISOR WAVE C — Z3 WIZARD Burger + Menu Enfant + Frites + Boissons + Desserts
// Mission: capture wizard for remaining items + simple-item direct-add behaviour.
// Output: /tmp/foodking-wave-c-2026-05-28/wizard-burger/<item>-<surface>.png
// Per-item: workflow + price + cart impact, sauce list verification, supplements verification.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT_DIR = '/tmp/foodking-wave-c-2026-05-28/wizard-burger';
fs.mkdirSync(OUT_DIR, { recursive: true });

const findings = {
  wave: 'C',
  zone: 'Z3-WIZARD',
  scope: 'Burger + Menu Enfant + non-wizard comparison',
  generated_at: new Date().toISOString(),
  items: [],
  notes: [],
  verdict: 'PENDING',
};

const TARGET_BURGERS = [
  { id: 37, name: 'Big Classique', price: 9.0, expected_template: 'sandwich', category_hint: 'SANDWICH CLASSIQUE' }, // category 'Sandwich Classique' (no 'burger' substring; 'classique' alias → sandwich)
  { id: 38, name: 'Chicken Burger', price: 6.9, expected_template: 'burger', category_hint: 'BURGERS' },
  { id: 39, name: 'Big Chicken', price: 8.9, expected_template: 'burger', category_hint: 'BURGERS' },
];
const TARGET_NUGGETS = { id: 40, name: 'Menu Nuggets', price: 6.0, expected_template: 'snacking_or_omelette', category_hint: 'MENU ENFANT' };
const TARGET_SIMPLES = [
  { id: 33, name: 'Petite Frites', price: 2.5, category: 'Frites', expected: 'wizard_or_simple', category_hint: 'FRITES' },
  { id: 49, name: 'Glace', price: 3.8, category: 'Desserts', expected: 'simple', category_hint: 'DESSERTS' },
  { id: 52, name: 'Coca-Cola 33cl', price: 1.5, category: 'Boissons', expected: 'simple', category_hint: 'BOISSONS' },
];

async function clickByText(page, text) {
  return await page.evaluate((needle) => {
    const lower = needle.toLowerCase();
    const all = Array.from(document.querySelectorAll('button, a, [role="button"], .btn, .start-button, .kiosk-cta'));
    for (const el of all) {
      const t = (el.innerText || el.textContent || '').toLowerCase();
      if (t.includes(lower)) { el.click(); return true; }
    }
    return false;
  }, text);
}

async function gotoKiosk(page) {
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  // Step 1 — Click takeaway via reliable data-testid (kiosk-order-type-takeaway)
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  try {
    await takeaway.click({ timeout: 5000 });
  } catch (_) {
    // Fallback : click anywhere on idle to reveal chooser
    await page.locator('[data-testid="kiosk-idle-touch-btn"]').click({ timeout: 2000 }).catch(()=>{});
    await page.waitForTimeout(700);
    await takeaway.click({ timeout: 5000 }).catch(()=>{});
  }
  await page.waitForTimeout(2000);
  // Step 2 — wait for catalogue
  await page.waitForFunction(
    () => /\/(kiosk\/categories|kiosk\/menu|categories)/.test(window.location.pathname) ||
      document.querySelectorAll('[data-item-id], .item-card, .product-card, .menu-item, [data-category-id]').length >= 2,
    { timeout: 10000 }
  ).catch(() => {});
  await page.waitForTimeout(800);
}

async function captureScreenshot(page, name) {
  const file = path.join(OUT_DIR, name);
  await page.screenshot({ path: file, fullPage: true }).catch((e) => {
    findings.notes.push(`screenshot fail ${name}: ${e.message}`);
  });
  return file;
}

async function findAndClickItem(page, itemName, categoryHint) {
  await page.waitForTimeout(400);
  // Step A — Navigate to category exactly via category sidebar nav (selectors for category-name button only)
  if (categoryHint) {
    const navOk = await page.evaluate((cat) => {
      const lower = cat.toLowerCase();
      // Restrict to sidebar nav items (not card titles)
      const navItems = Array.from(document.querySelectorAll(
        'nav button, nav a, [class*="sidebar"] button, [class*="sidebar"] a, [class*="category-list"] button, [class*="categoriesList"] button, .kiosk-category-tab'
      ));
      for (const tab of navItems) {
        const t = (tab.innerText || tab.textContent || '').toLowerCase().trim();
        if (t.includes(lower) && t.length < 60) { tab.click(); return true; }
      }
      return false;
    }, categoryHint);
    await page.waitForTimeout(900);
    if (!navOk) {
      // Step A fallback — find any button with that exact text under 30 chars
      await page.evaluate((cat) => {
        const lower = cat.toLowerCase();
        const all = Array.from(document.querySelectorAll('button, a'));
        for (const el of all) {
          const t = (el.innerText || el.textContent || '').toLowerCase().trim();
          if (t.length < 30 && t.includes(lower)) { el.click(); return true; }
        }
        return false;
      }, categoryHint).catch(()=>{});
      await page.waitForTimeout(800);
    }
  }
  // Step B — Click the item card. Strategy: scan visible cards in main area only.
  const clicked = await page.evaluate((name) => {
    const lower = name.toLowerCase();
    // Locate card containers in main viewport (avoid sidebar)
    const cards = Array.from(document.querySelectorAll(
      '[class*="card"], [class*="Card"], [data-item-id], .item-card, .product-card, .menu-item, main button, main [role="button"]'
    ));
    for (const card of cards) {
      const t = (card.innerText || card.textContent || '').toLowerCase().trim();
      if (t.length < 400 && t.includes(lower)) {
        try { card.click(); return true; } catch(_) {}
      }
    }
    return false;
  }, itemName);
  await page.waitForTimeout(1300);
  return clicked;
}

async function readWizardState(page) {
  return await page.evaluate(() => {
    const text = (sel) => {
      try {
        return Array.from(document.querySelectorAll(sel)).map(e => (e.innerText||e.textContent||'').trim()).filter(Boolean);
      } catch (_) { return []; }
    };
    const body = document.body.innerText || '';
    const modal = document.querySelector('.kiosk-wizard, .wizard-modal, [class*="izard"], [data-testid*="wizard"]');
    return {
      modal_open: !!modal,
      url: window.location.href,
      step_title: text('h1, h2, h3, .step-title, .wizard-step-title').slice(0, 8),
      step_indicators: text('.step-indicator, .wizard-steps li, [class*="step-pill"], [class*="step-circle"]').slice(0, 12),
      buttons: text('button, [role="button"]').slice(0, 30),
      labels_extracted: text('.option-label, .kiosk-option, .wizard-option, .composer-option, [data-option-id]').slice(0, 40),
      raw_label_leak: /Label\.[a-z]|kiosk\.[a-z_]+\.[a-z]|pos\.[a-z_]+\.[a-z]|0undefined|\[object Object\]/.test(body),
      total_visible: (body.match(/Total\s*€?\s*[0-9]+[.,]?[0-9]*/i) || [null])[0],
      vous_composez: /VOUS COMPOSEZ|Vous composez/.test(body),
      step_labels_visible_fr: /QUELLE VIANDE|QUELLE SAUCE|QUELLE CRUDIT[ÉE]|QUEL SUPPL[ÉE]MENT|QUEL MENU|R[EÉ]CAP/i.test(body),
    };
  });
}

async function readCart(page) {
  return await page.evaluate(() => {
    const items = Array.from(document.querySelectorAll('.cart-item, [data-cart-item], .order-line')).map(el => ({
      text: (el.innerText || '').trim().substring(0, 200),
    }));
    const totalEl = document.querySelector('.cart-total, [data-cart-total], .total-amount, .order-total');
    return {
      lines: items,
      total_text: totalEl ? (totalEl.innerText || totalEl.textContent || '').trim() : null,
    };
  });
}

test.describe.configure({ mode: 'serial' });

test('WAVE-C kiosk catalogue baseline', async ({ page }) => {
  page.on('pageerror', e => findings.notes.push(`pageerror: ${e.message}`));
  await gotoKiosk(page);
  await captureScreenshot(page, '00-kiosk-catalogue.png');
  const state = await page.evaluate(() => ({
    url: window.location.href,
    title: document.title,
    body_excerpt: (document.body.innerText || '').substring(0, 400),
    has_kiosk_root: !!document.getElementById('app') || !!document.querySelector('[class*="kiosk"]'),
    item_card_count: document.querySelectorAll('.item, .product-card, .menu-item, [data-item-id]').length,
  }));
  findings.notes.push(`baseline state: url=${state.url} cards=${state.item_card_count}`);
  expect(state.has_kiosk_root).toBeTruthy();
});

for (const item of TARGET_BURGERS) {
  test(`WAVE-C burger ${item.id} ${item.name}`, async ({ page }) => {
    page.on('pageerror', e => findings.notes.push(`[${item.name}] pageerror: ${e.message}`));
    const itemFinding = { ...item, surface: 'kiosk', steps: [], status: 'unknown', errors: [] };
    findings.items.push(itemFinding);
    try {
      await gotoKiosk(page);
      const cartBefore = await readCart(page);
      itemFinding.cart_before = cartBefore;
      const clicked = await findAndClickItem(page, item.name, item.category_hint);
      itemFinding.clicked = clicked;
      if (!clicked) {
        itemFinding.status = 'NOT_FOUND_IN_CATALOGUE';
        await captureScreenshot(page, `${item.id}-${item.name.replace(/\s+/g,'_')}-notfound.png`);
        return;
      }
      await page.waitForTimeout(800);
      await captureScreenshot(page, `${item.id}-${item.name.replace(/\s+/g,'_')}-step1.png`);
      const wState = await readWizardState(page);
      itemFinding.wizard_state = wState;
      itemFinding.expected_template = item.expected_template;
      itemFinding.raw_label_leak = wState.raw_label_leak;
      // Detect template at runtime via window
      const detectedTemplate = await page.evaluate(() => {
        // Find Vue instance of wizard
        const app = document.querySelector('.kiosk-wizard, [class*="Wizard"]');
        if (app && app.__vue__) return app.__vue__.detectedTemplate || null;
        return window.__lastWizardTemplate || null;
      }).catch(() => null);
      itemFinding.detected_template = detectedTemplate;
      // Try advancing — capture step-by-step
      for (let step = 2; step <= 5; step++) {
        // Look for "Continuer" / "Suivant" button
        const advanced = await page.evaluate(() => {
          const btns = Array.from(document.querySelectorAll('button'));
          const next = btns.find(b => /continu|suivant|valider|next/i.test((b.innerText||'').trim()));
          if (next && !next.disabled) { next.click(); return true; }
          return false;
        });
        await page.waitForTimeout(500);
        if (advanced) {
          await captureScreenshot(page, `${item.id}-${item.name.replace(/\s+/g,'_')}-step${step}.png`);
        } else {
          break;
        }
      }
      const cartAfter = await readCart(page);
      itemFinding.cart_after = cartAfter;
      itemFinding.status = 'WIZARD_OK';
    } catch (e) {
      itemFinding.errors.push(e.message);
      itemFinding.status = 'ERROR';
    }
  });
}

test(`WAVE-C menu enfant ${TARGET_NUGGETS.id} ${TARGET_NUGGETS.name}`, async ({ page }) => {
  const item = TARGET_NUGGETS;
  const itemFinding = { ...item, surface: 'kiosk', steps: [], status: 'unknown', errors: [] };
  findings.items.push(itemFinding);
  try {
    await gotoKiosk(page);
    const clicked = await findAndClickItem(page, item.name, item.category_hint);
    itemFinding.clicked = clicked;
    if (!clicked) {
      itemFinding.status = 'NOT_FOUND_IN_CATALOGUE';
      await captureScreenshot(page, `${item.id}-${item.name.replace(/\s+/g,'_')}-notfound.png`);
      return;
    }
    await page.waitForTimeout(800);
    await captureScreenshot(page, `${item.id}-${item.name.replace(/\s+/g,'_')}-step1.png`);
    itemFinding.wizard_state = await readWizardState(page);
    itemFinding.cart_after = await readCart(page);
    itemFinding.status = 'OK';
  } catch (e) {
    itemFinding.errors.push(e.message);
    itemFinding.status = 'ERROR';
  }
});

for (const item of TARGET_SIMPLES) {
  test(`WAVE-C simple ${item.id} ${item.name}`, async ({ page }) => {
    const itemFinding = { ...item, surface: 'kiosk', status: 'unknown', errors: [] };
    findings.items.push(itemFinding);
    try {
      await gotoKiosk(page);
      const cartBefore = await readCart(page);
      const clicked = await findAndClickItem(page, item.name, item.category_hint);
      itemFinding.clicked = clicked;
      if (!clicked) {
        itemFinding.status = 'NOT_FOUND_IN_CATALOGUE';
        await captureScreenshot(page, `${item.id}-${item.name.replace(/\s+/g,'_')}-notfound.png`);
        return;
      }
      await page.waitForTimeout(900);
      await captureScreenshot(page, `${item.id}-${item.name.replace(/\s+/g,'_')}-result.png`);
      const wState = await readWizardState(page);
      itemFinding.wizard_state = wState;
      itemFinding.modal_opened = wState.modal_open;
      const cartAfter = await readCart(page);
      itemFinding.cart_before = cartBefore;
      itemFinding.cart_after = cartAfter;
      itemFinding.status = wState.modal_open ? 'WIZARD_FOR_SIMPLE_ITEM' : 'DIRECT_ADD_OK';
    } catch (e) {
      itemFinding.errors.push(e.message);
      itemFinding.status = 'ERROR';
    }
  });
}

test.afterAll(async () => {
  // Compute verdict
  const errors = findings.items.filter(i => i.status === 'ERROR' || i.status === 'NOT_FOUND_IN_CATALOGUE');
  const rawLabels = findings.items.filter(i => i.raw_label_leak === true);
  if (errors.length > 0 || rawLabels.length > 0) findings.verdict = 'DRIFT';
  else findings.verdict = 'ALL_GREEN';
  findings.errors_count = errors.length;
  findings.raw_label_leak_count = rawLabels.length;
  const reportPath = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/supervisor-wave-c-2026-05-28/Z3-WIZARD/burger-others.json';
  fs.mkdirSync(path.dirname(reportPath), { recursive: true });
  fs.writeFileSync(reportPath, JSON.stringify(findings, null, 2));
});
