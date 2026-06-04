// test-ultraudit-visual-mobile-2026-05-30 — DEEP VISUAL ULTRAUDIT (standalone mobile V1 Le Cayenne)
//
// READ-ONLY capture spec. Does NOT modify app source. Drives the standalone mobile
// SPA (mobile/index.html @ 127.0.0.1:8087) via DOM clicks + inner-scroll segment
// captures, pre-seeding cart/state through window.LC.storage where re-driving the
// wizard would be brittle.
//
// Focus per owner brief: ALIGNMENT, ASPECT-RATIO, CROPPING (food cut off in thumb
// box), sizing, button/card/box/poster quality, layout/spacing/overflow, palette.
// NOT photo-subject (already validated).
//
// Output PNGs → reports/test-e2e/ultraudit-visual-2026-05-30/screenshots/mobile/
// Plus a machine-readable image-fit metrics dump (aspect mismatch per <img>) →
//   reports/test-e2e/ultraudit-visual-2026-05-30/round-1/mobile-image-metrics.json
//
// Run :
//   npx playwright test --config=tests/mobile-e2e/playwright.config.js \
//     tests/e2e/test-ultraudit-visual-mobile-2026-05-30.spec.js \
//     --reporter=list --workers=1 --timeout=180000

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT_DIR = path.resolve(__dirname, '../../reports/test-e2e/ultraudit-visual-2026-05-30/screenshots/mobile');
const META_DIR = path.resolve(__dirname, '../../reports/test-e2e/ultraudit-visual-2026-05-30/round-1');
const MOBILE_URL = process.env.MOBILE_URL || 'http://127.0.0.1:8087/index.html';

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });
if (!fs.existsSync(META_DIR)) fs.mkdirSync(META_DIR, { recursive: true });

const allMetrics = {};

async function snap(page, name) {
  await page.waitForTimeout(350);
  await page.screenshot({ path: path.join(OUT_DIR, `${name}.png`), fullPage: false });
}

// Scroll the inner .lc-screen container and capture each segment so below-the-fold
// content (long menus, recap, cart-full, loyalty history) is audited, not just top.
async function snapScrolled(page, baseName, maxSegments = 6) {
  const info = await page.evaluate(() => {
    const el = document.querySelector('.lc-screen') ||
               document.querySelector('[class*="screen"]') ||
               document.scrollingElement;
    if (!el) return { scrollH: 0, clientH: 0 };
    el.scrollTop = 0;
    return { scrollH: el.scrollHeight, clientH: el.clientHeight };
  });
  const step = Math.max(1, (info.clientH || 800) - 80); // overlap 80px between segments
  const segCount = Math.min(maxSegments, Math.max(1, Math.ceil((info.scrollH || 0) / step)));
  for (let i = 0; i < segCount; i++) {
    await page.evaluate((y) => {
      const el = document.querySelector('.lc-screen') ||
                 document.querySelector('[class*="screen"]') ||
                 document.scrollingElement;
      if (el) el.scrollTop = y;
    }, i * step);
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(OUT_DIR, `${baseName}-seg${i + 1}.png`), fullPage: false });
  }
  // reset
  await page.evaluate(() => {
    const el = document.querySelector('.lc-screen') || document.scrollingElement;
    if (el) el.scrollTop = 0;
  });
}

// Collect aspect-ratio / fit metrics for every rendered <img> — flags the
// natural-vs-box mismatch that produces hard cropping under object-fit:cover.
async function collectImageMetrics(page, screenName) {
  const metrics = await page.evaluate(() => {
    const out = [];
    document.querySelectorAll('img').forEach((img) => {
      const r = img.getBoundingClientRect();
      if (r.width < 4 || r.height < 4) return; // skip hidden
      const cs = getComputedStyle(img);
      const natR = img.naturalWidth && img.naturalHeight ? img.naturalWidth / img.naturalHeight : null;
      const boxR = r.width && r.height ? r.width / r.height : null;
      out.push({
        src: (img.getAttribute('src') || '').replace(/^.*assets\//, 'assets/'),
        natW: img.naturalWidth, natH: img.naturalHeight,
        boxW: Math.round(r.width), boxH: Math.round(r.height),
        natRatio: natR ? +natR.toFixed(3) : null,
        boxRatio: boxR ? +boxR.toFixed(3) : null,
        ratioMismatch: (natR && boxR) ? +(Math.max(natR, boxR) / Math.min(natR, boxR)).toFixed(2) : null,
        objectFit: cs.objectFit,
        objectPosition: cs.objectPosition,
        broken: img.complete && img.naturalWidth === 0,
        display: cs.display,
      });
    });
    return out;
  });
  allMetrics[screenName] = metrics;
  return metrics;
}

async function bootHome(page) {
  page._errs = [];
  page.on('pageerror', e => page._errs.push(e.message));
  page.on('console', m => { if (m.type() === 'error') page._errs.push(m.text()); });
  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.evaluate(() => {
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'test', phone: '0642799884', user_id: 'test' }));
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
  await page.waitForTimeout(600);
}

// Boot WITHOUT auth so we can reach splash + onboarding + login + otp
async function bootCold(page) {
  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.evaluate(() => {
    localStorage.removeItem('lecayenne.onboarding_seen');
    localStorage.removeItem('lecayenne.auth');
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
  await page.waitForTimeout(500);
}

// ============================================================================
// 1 — ONBOARDING : splash + 4 onboarding posters + login + otp
// ============================================================================
test('01 — Splash + onboarding posters + login + otp', async ({ page }) => {
  await bootCold(page);
  await snap(page, '01-splash');
  await collectImageMetrics(page, 'splash');

  // Walk onboarding via "next" affordances. The onboarding screens expose
  // "Suivant"/arrow buttons; we click the most prominent CTA each step.
  for (let i = 1; i <= 4; i++) {
    // Try to advance: click a primary CTA / next arrow that is visible
    const advanced = await page.evaluate(() => {
      // Find a large primary button that is NOT "Passer" (skip)
      const btns = [...document.querySelectorAll('button')];
      const primary = btns.find(b => {
        const t = (b.textContent || '').trim().toLowerCase();
        const r = b.getBoundingClientRect();
        return r.width > 120 && r.height > 30 && !/passer|skip/.test(t);
      });
      if (primary) { primary.click(); return true; }
      return false;
    });
    await page.waitForTimeout(400);
    await snap(page, `01-onb${i}`);
    await collectImageMetrics(page, `onb${i}`);
    if (!advanced) break;
  }

  // After onboarding → login. Capture login + a representative OTP screen.
  await page.waitForTimeout(300);
  await snap(page, '01-login');
  await collectImageMetrics(page, 'login');

  // Drive login: type a phone then continue, to reach OTP
  await page.evaluate(() => {
    const inp = document.querySelector('input[type="tel"], input[inputmode="tel"], input[type="number"], input');
    if (inp) {
      const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      setter.call(inp, '0642799884');
      inp.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  await page.waitForTimeout(300);
  const toOtp = await page.evaluate(() => {
    const btns = [...document.querySelectorAll('button')];
    const cta = btns.find(b => /continuer|suivant|valider|envoyer|recevoir|code/i.test(b.textContent || ''));
    if (cta) { cta.click(); return true; }
    return false;
  });
  await page.waitForTimeout(500);
  await snap(page, '01-otp');
  await collectImageMetrics(page, 'otp');
});

// ============================================================================
// 2 — HOME : full scroll (hero/poster, marquee, featured card, category tiles,
//             envies carousel, nouveautés grid, restaurant info card)
// ============================================================================
test('02 — Home full scroll', async ({ page }) => {
  await bootHome(page);
  await collectImageMetrics(page, 'home');
  await snapScrolled(page, '02-home', 5);
});

// ============================================================================
// 3 — MENU : full scroll across all 11 categories (list cards + thumb boxes),
//             plus a few per-category filtered views
// ============================================================================
test('03 — Menu full scroll + per-category', async ({ page }) => {
  await bootHome(page);
  // Navigate to menu tab
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(700);
  await collectImageMetrics(page, 'menu');
  await snapScrolled(page, '03-menu', 6);

  // Filter chips: capture a representative subset (Bols, Frites, Tacos, Burgers,
  // Suppléments, Boissons) to inspect thumb-box framing per category.
  const chips = ['Bols', 'Frites', 'Tacos', 'Burgers', 'Suppléments', 'Boissons'];
  for (const label of chips) {
    const clicked = await page.evaluate((lbl) => {
      const b = [...document.querySelectorAll('button')].find(x => (x.textContent || '').includes(lbl) && x.getAttribute('aria-pressed') !== null);
      if (b) { b.click(); return true; }
      // fallback: any chip button containing label
      const b2 = [...document.querySelectorAll('button')].find(x => (x.textContent || '').includes(lbl));
      if (b2) { b2.click(); return true; }
      return false;
    }, label);
    if (clicked) {
      await page.waitForTimeout(500);
      await snap(page, `03-menu-cat-${label.toLowerCase().replace(/[^a-z]/g, '')}`);
    }
  }
});

// ============================================================================
// 4 — ITEM DETAIL : product detail header (hero crop), per template
// ============================================================================
test('04 — Item detail screens (hero crop per template)', async ({ page }) => {
  await bootHome(page);
  const items = [
    'sandwich-cayenne-classique', // sandwich hero
    'tacos-1-viande',             // tacos hero
    'bowl-frites-curry',          // bowl hero
    'chicken-burger',             // burger hero
    'galette-normale',            // galette
    'petite-frites',              // frites
  ];
  for (const slug of items) {
    const ok = await page.evaluate((s) => {
      // ScreenItem opens on go('item', id). We can't call go() (component-local),
      // so navigate to menu then click the matching card. Simplest: dispatch via
      // a synthetic route by clicking the home featured card is unreliable; instead
      // find the item id and click its menu card after switching to menu.
      const item = window.LC.menu.findItem(s);
      return item ? { id: item.id, name: item.name } : null;
    }, slug);
    if (!ok) continue;
    // go to menu, click the card with this aria-label
    await page.evaluate(() => {
      const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim()));
      if (t) t.click();
    });
    await page.waitForTimeout(500);
    const clicked = await page.evaluate((nm) => {
      const card = [...document.querySelectorAll('[aria-label]')].find(e => (e.getAttribute('aria-label') || '').includes('Voir ' + nm));
      if (card) { card.click(); return true; }
      return false;
    }, ok.name);
    if (!clicked) continue;
    await page.waitForTimeout(700);
    await collectImageMetrics(page, `item-${slug}`);
    await snapScrolled(page, `04-item-${slug}`, 3);
  }
});

// ============================================================================
// 5 — WIZARD STEPS : drive a full tacos/sandwich wizard step-by-step + recap
//     (recap is the known sticky-CTA-occlusion suspect — capture scrolled)
// ============================================================================
test('05 — Wizard steps + recap (sticky CTA occlusion check)', async ({ page }) => {
  await bootHome(page);
  // Open the Tacos L wizard (most steps: viandes/supplements/menu/recap).
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(500);
  const opened = await page.evaluate(() => {
    const item = window.LC.menu.findItem('big-tacos-2-viandes') || window.LC.menu.findItem('tacos-1-viande');
    if (!item) return false;
    const card = [...document.querySelectorAll('[aria-label]')].find(e => (e.getAttribute('aria-label') || '').includes('Voir ' + item.name));
    if (card) { card.click(); return true; }
    return false;
  });
  if (!opened) test.skip();
  await page.waitForTimeout(600);

  // The detail screen has a "Personnaliser"/"Commander"/CTA to enter the wizard.
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /personnaliser|composer|commander|choisir|ajouter/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(600);

  // Walk up to 8 steps. Each step: capture, then make a minimal valid selection
  // (pick first available option chip), then click "Suivant".
  for (let step = 1; step <= 8; step++) {
    await collectImageMetrics(page, `wizard-step${step}`);
    await snapScrolled(page, `05-wizard-step${step}`, 3);

    const isRecap = await page.evaluate(() => {
      const t = document.body.textContent || '';
      return /Récapitulatif|Ajouter au panier/i.test(t) &&
             [...document.querySelectorAll('button')].some(b => /Ajouter au panier/i.test(b.textContent || ''));
    });
    if (isRecap) {
      // On recap, explicitly capture the QUANTITÉ bar region scrolled fully down
      await page.evaluate(() => {
        const el = document.querySelector('.lc-screen') || document.scrollingElement;
        if (el) el.scrollTop = el.scrollHeight;
      });
      await page.waitForTimeout(400);
      await snap(page, '05-wizard-recap-bottom');
      break;
    }

    // Make a selection: click first selectable option (chip/card) in step body
    await page.evaluate(() => {
      // option affordances: buttons/divs with role button inside the step that are
      // not the footer CTA / back. Click the first that looks like a choice tile.
      const cta = [...document.querySelectorAll('button')].find(b => /Suivant|Ajouter au panier/i.test(b.textContent || ''));
      const candidates = [...document.querySelectorAll('[role="button"], button, label')]
        .filter(e => e !== cta);
      // prefer elements with a price or option-ish text inside the scroll area
      const opt = candidates.find(e => {
        const r = e.getBoundingClientRect();
        return r.top > 120 && r.height > 40 && r.width > 60 && !/Suivant|précédent|fermer|retour/i.test(e.textContent || '');
      });
      if (opt) opt.click();
    });
    await page.waitForTimeout(300);

    const advanced = await page.evaluate(() => {
      const next = [...document.querySelectorAll('button')].find(b => /^Suivant/i.test((b.textContent || '').trim()) && !b.disabled);
      if (next) { next.click(); return true; }
      // sometimes CTA label = "Ajouter au panier" already (recap) — caught above
      return false;
    });
    await page.waitForTimeout(500);
    if (!advanced) {
      // capture stuck state for diagnosis then stop
      await snap(page, `05-wizard-step${step}-stuck`);
      break;
    }
  }
});

// ============================================================================
// 6 — CART : empty + full (full pre-seeded via storage for robustness)
// ============================================================================
test('06 — Cart empty + full', async ({ page }) => {
  await bootHome(page);
  // Empty cart
  await page.evaluate(() => { window.LC.storage.clearCart && window.LC.storage.clearCart(); });
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /panier|cart/i.test(e.getAttribute('aria-label') || '')) ;
    // No dedicated cart tab; navigate via menu sticky bar requires items. Use go via home.
  });
  // Reach cart screen by seeding one then clearing won't show cart; instead build
  // an empty-cart view by navigating Menu (no sticky) — cart screen needs a route.
  // We pre-seed a real cart and open cart; empty-state we force by clearing in-cart.
  // FULL CART:
  await page.evaluate(() => {
    const m = window.LC.menu;
    const bowl = m.findItem('bowl-frites-curry');
    const tacos = m.findItem('big-tacos-2-viandes');
    const burger = m.findItem('chicken-burger');
    const mk = (item, qty, extra) => Object.assign(
      window.buildLineItem
        ? window.buildLineItem(item, Object.assign({
            meatIds: [], sauceIds: item.has_sauce ? ['s-mayo'] : [], cruditeIds: m.defaultCruditeIds(),
            supplementIds: [], bolSupplementIds: [], bolDrinkId: undefined, menuChoice: 'none',
            drinkId: undefined, fritesStyleId: undefined, fritesSauceIds: [], qty
          }, extra || {}))
        : { id: item.id, slug: item.slug, name: item.name, price: item.price, qty, image: item.image },
      {}
    );
    const lines = [];
    if (bowl) lines.push(mk(bowl, 2, { sauceIds: ['s-curry'], bolSupplementIds: ['sb-boule-gratinee'], bolDrinkId: 'd-coca' }));
    if (tacos) lines.push(mk(tacos, 1, { meatIds: ['m-marine', 'm-curry'] }));
    if (burger) lines.push(mk(burger, 3, {}));
    window.LC.storage.setCart(lines);
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu);
  await page.waitForTimeout(600);
  // Open cart: from menu, the sticky "Voir le panier" appears when cart>0
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Voir le panier/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(600);
  await collectImageMetrics(page, 'cart-full');
  await snapScrolled(page, '06-cart-full', 4);

  // EMPTY CART: clear then re-open cart screen (stay on cart route, setCart [])
  await page.evaluate(() => {
    // remove all lines via the in-screen remove buttons if present; else clear storage + reload won't keep route.
    window.LC.storage.setCart([]);
  });
  // Re-trigger cart render by clicking a remove-all path is unreliable; capture the
  // empty-state by removing items one by one through the UI:
  await page.waitForTimeout(300);
  await page.evaluate(() => {
    // click any "supprimer"/trash buttons repeatedly
    let guard = 0;
    let btn;
    while ((btn = [...document.querySelectorAll('button')].find(b => /supprimer|retirer|trash|×|✕/i.test((b.getAttribute('aria-label') || '') + (b.textContent || '')))) && guard++ < 12) {
      btn.click();
    }
  });
  await page.waitForTimeout(500);
  await snap(page, '06-cart-empty');
});

// ============================================================================
// 7 — PAY (modal) + CONFIRM
// ============================================================================
test('07 — Pay choice modal + confirmation', async ({ page }) => {
  await bootHome(page);
  await page.evaluate(() => {
    const m = window.LC.menu;
    const burger = m.findItem('chicken-burger');
    const line = window.buildLineItem
      ? window.buildLineItem(burger, { meatIds: [], sauceIds: ['s-mayo'], cruditeIds: m.defaultCruditeIds(), supplementIds: [], bolSupplementIds: [], bolDrinkId: undefined, menuChoice: 'none', drinkId: undefined, fritesStyleId: undefined, fritesSauceIds: [], qty: 1 })
      : { id: burger.id, slug: burger.slug, name: burger.name, price: burger.price, qty: 1 };
    window.LC.storage.setCart([line]);
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu);
  await page.waitForTimeout(500);
  // menu → cart → "Payer/Commander" → pay modal
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(400);
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Voir le panier/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(500);
  // click the pay/confirm CTA in cart
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /payer|commander|valider|confirmer|passer la commande/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(600);
  await snap(page, '07-pay-modal');
  await collectImageMetrics(page, 'pay-modal');

  // Pick "counter" (pay at counter) to reach confirmation
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /comptoir|sur place|caisse|counter|espèces|payer.*place/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(900);
  await snap(page, '07-confirm');
  await collectImageMetrics(page, 'confirm');

  // Also capture the Stripe/card pay screen path
  await bootHome(page);
  await page.evaluate(() => {
    const m = window.LC.menu;
    const burger = m.findItem('chicken-burger');
    const line = window.buildLineItem
      ? window.buildLineItem(burger, { meatIds: [], sauceIds: ['s-mayo'], cruditeIds: m.defaultCruditeIds(), supplementIds: [], bolSupplementIds: [], bolDrinkId: undefined, menuChoice: 'none', drinkId: undefined, fritesStyleId: undefined, fritesSauceIds: [], qty: 1 })
      : { id: burger.id, slug: burger.slug, name: burger.name, price: burger.price, qty: 1 };
    window.LC.storage.setCart([line]);
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu);
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(400);
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Voir le panier/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /payer|commander|valider|confirmer|passer la commande/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /carte|card|bancaire|cb/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(800);
  await snap(page, '07-stripe-card');
  await collectImageMetrics(page, 'stripe');
});

// ============================================================================
// 8 — ORDERS (list active+history) + ORDER DETAIL
// ============================================================================
test('08 — Orders list + order detail', async ({ page }) => {
  await bootHome(page);
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /commandes/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(700);
  await collectImageMetrics(page, 'orders');
  await snapScrolled(page, '08-orders', 4);

  // open first order card → detail
  await page.evaluate(() => {
    const c = [...document.querySelectorAll('[aria-label]')].find(e => /Commande .* voir détails|Commande .* en cours/i.test(e.getAttribute('aria-label') || ''));
    if (c) c.click();
    else {
      const c2 = document.querySelector('[data-testid^="orders-history-card-"]');
      if (c2) c2.click();
    }
  });
  await page.waitForTimeout(700);
  await collectImageMetrics(page, 'order-detail');
  await snapScrolled(page, '08-order-detail', 4);
});

// ============================================================================
// 9 — PROFILE + LOYALTY (card + history, scrolled)
// ============================================================================
test('09 — Profile + loyalty', async ({ page }) => {
  await bootHome(page);
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /profil/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(700);
  await collectImageMetrics(page, 'profile');
  await snapScrolled(page, '09-profile', 4);

  // open loyalty
  await page.evaluate(() => {
    const c = [...document.querySelectorAll('[aria-label]')].find(e => /Carte fidélité|Ma carte fidélité/i.test(e.getAttribute('aria-label') || ''));
    if (c) c.click();
  });
  await page.waitForTimeout(700);
  await collectImageMetrics(page, 'loyalty');
  await snapScrolled(page, '09-loyalty', 5);
});

// ============================================================================
// Z — Dump aggregated image metrics for the findings report
// ============================================================================
test.afterAll(async () => {
  fs.writeFileSync(
    path.join(META_DIR, 'mobile-image-metrics.json'),
    JSON.stringify(allMetrics, null, 2)
  );
});
