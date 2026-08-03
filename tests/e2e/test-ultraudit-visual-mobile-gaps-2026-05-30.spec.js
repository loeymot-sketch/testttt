// test-ultraudit-visual-mobile-gaps-2026-05-30 — follow-up capture for states the
// main ultraudit spec could not reach with generic heuristics:
//   onb2/onb3/onb4 (onboarding posters), login, otp, wizard RECAP (sticky-CTA
//   occlusion check), stripe card-pay screen.
// READ-ONLY. Same config / port 8087.

const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT_DIR = path.resolve(__dirname, '../../reports/test-e2e/ultraudit-visual-2026-05-30/screenshots/mobile');
const MOBILE_URL = process.env.MOBILE_URL || 'http://127.0.0.1:8087/index.html';
if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

async function snap(page, name) { await page.waitForTimeout(350); await page.screenshot({ path: path.join(OUT_DIR, `${name}.png`), fullPage: false }); }

async function bootCold(page) {
  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.evaluate(() => { localStorage.removeItem('lecayenne.onboarding_seen'); localStorage.removeItem('lecayenne.auth'); });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
}
async function bootHome(page) {
  await page.goto(MOBILE_URL, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.evaluate(() => {
    localStorage.setItem('lecayenne.onboarding_seen', 'true');
    localStorage.setItem('lecayenne.auth', JSON.stringify({ token: 'test', phone: '0642799884', user_id: 'test' }));
  });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu, { timeout: 30000 });
  await page.waitForTimeout(500);
}

// Click the onboarding "next" = the circular black arrow button (bottom-right,
// 64x64, containing an Arrow svg, NOT the "Passer" skip text button).
async function clickOnbNext(page) {
  return await page.evaluate(() => {
    const btns = [...document.querySelectorAll('button')];
    // round next: ~64px square, near bottom-right, no text or only svg
    const next = btns.find(b => {
      const r = b.getBoundingClientRect();
      const t = (b.textContent || '').trim();
      return r.width >= 48 && r.width <= 80 && r.height >= 48 && r.height <= 80 &&
             r.left > 250 && r.top > 600 && t.length === 0;
    });
    if (next) { next.click(); return true; }
    return false;
  });
}

test('GAP-01 — onboarding onb1..onb4 + login + otp', async ({ page }) => {
  await bootCold(page);
  // Splash auto-advances after 1.8s; wait then we should be on onb1
  await page.waitForTimeout(2200);
  await snap(page, 'g01-onb1');
  for (const n of [2, 3, 4]) {
    const ok = await clickOnbNext(page);
    await page.waitForTimeout(600);
    await snap(page, `g01-onb${n}`);
    if (!ok) break;
  }
  // One more next from onb4 → login
  await clickOnbNext(page);
  await page.waitForTimeout(700);
  await snap(page, 'g01-login');

  // Type a French mobile number then "Recevoir le code" → OTP
  await page.evaluate(() => {
    const inp = document.querySelector('input[inputmode="tel"], input[autocomplete="tel-national"], input');
    if (inp) {
      const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      setter.call(inp, '6 42 79 98 84');
      inp.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  await page.waitForTimeout(300);
  await snap(page, 'g01-login-filled');
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Recevoir le code/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(700);
  await snap(page, 'g01-otp');
  // Type the demo OTP 1234 to see filled state (auto-advances to home at 4 digits)
  await page.evaluate(() => {
    const inputs = [...document.querySelectorAll('input')].filter(i => {
      const r = i.getBoundingClientRect(); return r.width > 0 && r.width < 90;
    });
    '123'.split('').forEach((d, i) => {
      if (inputs[i]) {
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        setter.call(inputs[i], d);
        inputs[i].dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  });
  await page.waitForTimeout(300);
  await snap(page, 'g01-otp-filled');
});

// Wait until the footer CTA ("Suivant"/"Ajouter au panier") is enabled, then return
// whether we are on recap.
async function ctaEnabled(page) {
  return await page.evaluate(() => {
    const b = [...document.querySelectorAll('button.rdw-cta, button')].find(x => /Suivant|Ajouter au panier/i.test(x.textContent || ''));
    return b ? { enabled: !b.disabled, recap: /Ajouter au panier/i.test(b.textContent || '') } : { enabled: false, recap: false };
  });
}

test('GAP-02 — wizard recap (sticky CTA occlusion)', async ({ page }) => {
  await bootHome(page);
  // Open Tacos L (viandes ×2 → supplements → menu → recap). Real Playwright clicks
  // (not in-page .click()) so React processes each event with event-loop ticks.
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const item = window.LC.menu.findItem('big-tacos-2-viandes');
    const card = [...document.querySelectorAll('[aria-label]')].find(e => (e.getAttribute('aria-label') || '').includes('Voir ' + item.name));
    if (card) card.click();
  });
  await page.waitForTimeout(700);

  for (let step = 1; step <= 9; step++) {
    const state = await ctaEnabled(page);
    if (state.recap) {
      await snap(page, 'g02-wizard-recap-top');
      await page.evaluate(() => { const el = document.querySelector('.lc-screen') || document.scrollingElement; if (el) el.scrollTop = el.scrollHeight; });
      await page.waitForTimeout(400);
      await snap(page, 'g02-wizard-recap-bottom');
      break;
    }
    await snap(page, `g02-wizard-s${step}`);

    // If CTA already enabled (e.g. crudites/supplements default-valid), just advance.
    if (!state.enabled) {
      // Try checkboxes first (viandes/supplements), then radios (menu), then any tile.
      const checkboxes = page.locator('[role="checkbox"]');
      const radios = page.locator('[role="radio"]');
      const nCheck = await checkboxes.count();
      const nRadio = await radios.count();
      if (nRadio > 0) {
        // MENU step: pick the first radio (e.g. "Sans formule"/"Menu")
        await radios.first().click().catch(() => {});
      } else if (nCheck > 0) {
        // Tick as many as needed; for viandes ×2 tick 2, else tick 1
        const need = await page.evaluate(() => {
          const m = (document.body.textContent || '').match(/Choisis\s+(\d+)\s+viande/i);
          return m ? parseInt(m[1], 10) : 1;
        });
        for (let i = 0; i < Math.min(need, nCheck); i++) {
          await checkboxes.nth(i).click().catch(() => {});
          await page.waitForTimeout(120);
        }
      } else {
        // Sauce or other tile-based step: click the first selectable option tile
        await page.evaluate(() => {
          const isCta = (e) => /Suivant|Ajouter|précédent|retour|fermer/i.test(e.textContent || '');
          const opt = [...document.querySelectorAll('.rdw-step *')].find(e => {
            const r = e.getBoundingClientRect();
            return !isCta(e) && r.top > 150 && r.top < 740 && r.width > 60 && r.height > 36 && (e.onclick || e.getAttribute('role'));
          });
          if (opt) opt.click();
        });
      }
      await page.waitForTimeout(350);
    }

    const adv = await page.evaluate(() => {
      const b = [...document.querySelectorAll('button')].find(x => /^Suivant/i.test((x.textContent || '').trim()) && !x.disabled);
      if (b) { b.click(); return true; }
      return false;
    });
    await page.waitForTimeout(500);
    if (!adv) {
      const s2 = await ctaEnabled(page);
      if (s2.recap) { continue; } // loop will catch recap next iteration
      await snap(page, `g02-wizard-s${step}-stuck`);
      break;
    }
  }
});

test('GAP-04 — TALL wizard recap (sandwich + 6 supplements) — true occlusion check', async ({ page }) => {
  await bootHome(page);
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim()));
    if (t) t.click();
  });
  await page.waitForTimeout(500);
  // sandwich-classique-faluche: sauce + crudites + supplements(9) + menu + recap.
  await page.evaluate(() => {
    const item = window.LC.menu.findItem('sandwich-classique-faluche');
    const card = [...document.querySelectorAll('[aria-label]')].find(e => (e.getAttribute('aria-label') || '').includes('Voir ' + item.name));
    if (card) card.click();
  });
  await page.waitForTimeout(700);

  for (let step = 1; step <= 10; step++) {
    const state = await page.evaluate(() => {
      const b = [...document.querySelectorAll('button.rdw-cta, button')].find(x => /Suivant|Ajouter au panier/i.test(x.textContent || ''));
      const title = (document.querySelector('.rdw-title') || {}).textContent || '';
      return { enabled: b ? !b.disabled : false, recap: b ? /Ajouter au panier/i.test(b.textContent || '') : false, title };
    });
    if (state.recap) {
      // Measure occlusion: is the QUANTITÉ bar's bottom above the sticky CTA's top after scrolling to bottom?
      await page.evaluate(() => { const el = document.querySelector('.lc-screen') || document.scrollingElement; if (el) el.scrollTop = 0; });
      await page.waitForTimeout(300);
      await snap(page, 'g04-tallrecap-top');
      await page.evaluate(() => { const el = document.querySelector('.lc-screen') || document.scrollingElement; if (el) el.scrollTop = el.scrollHeight; });
      await page.waitForTimeout(400);
      await snap(page, 'g04-tallrecap-bottom');
      const occ = await page.evaluate(() => {
        const scrollEl = document.querySelector('.lc-screen') || document.scrollingElement;
        const qtyBar = [...document.querySelectorAll('*')].find(e => /QUANTIT[ÉE]/i.test((e.textContent || '')) && e.getBoundingClientRect().height < 120 && e.getBoundingClientRect().height > 30);
        const cta = [...document.querySelectorAll('button')].find(b => /Ajouter au panier/i.test(b.textContent || ''));
        const qr = qtyBar ? qtyBar.getBoundingClientRect() : null;
        const cr = cta ? cta.getBoundingClientRect() : null;
        return {
          scrollH: scrollEl ? scrollEl.scrollHeight : null,
          clientH: scrollEl ? scrollEl.clientHeight : null,
          isScrollable: scrollEl ? scrollEl.scrollHeight > scrollEl.clientHeight + 20 : false,
          qtyBottom: qr ? Math.round(qr.bottom) : null,
          ctaTop: cr ? Math.round(cr.top) : null,
          overlapsPx: (qr && cr) ? Math.round(qr.bottom - cr.top) : null, // >0 means QUANTITÉ bottom is behind CTA top
        };
      });
      console.log('TALL RECAP OCCLUSION MEASURE:', JSON.stringify(occ));
      require('fs').writeFileSync(
        require('path').resolve(__dirname, '../../reports/test-e2e/ultraudit-visual-2026-05-30/round-1/tall-recap-occlusion.json'),
        JSON.stringify(occ, null, 2));
      break;
    }
    // Select for this step
    const radios = page.locator('[role="radio"]');
    const checks = page.locator('[role="checkbox"]');
    const nR = await radios.count();
    const nC = await checks.count();
    if (/Suppl[ée]ments/i.test(state.title) && nC > 0) {
      // tick 6+ supplements to force a long recap row
      const toTick = Math.min(7, nC);
      for (let i = 0; i < toTick; i++) { await checks.nth(i).click().catch(() => {}); await page.waitForTimeout(100); }
    } else if (nR > 0) {
      await radios.first().click().catch(() => {});
    } else if (nC > 0 && !state.enabled) {
      const need = await page.evaluate(() => { const m = (document.body.textContent || '').match(/Choisis\s+(\d+)/i); return m ? parseInt(m[1], 10) : 1; });
      for (let i = 0; i < Math.min(need, nC); i++) { await checks.nth(i).click().catch(() => {}); await page.waitForTimeout(100); }
    } else if (!state.enabled) {
      // tile step (sauce): click first option tile
      await page.evaluate(() => {
        const opt = [...document.querySelectorAll('.rdw-step *')].find(e => { const r = e.getBoundingClientRect(); return r.top > 150 && r.top < 740 && r.width > 60 && r.height > 36 && (e.onclick || e.getAttribute('role')); });
        if (opt) opt.click();
      });
    }
    await page.waitForTimeout(300);
    const adv = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /^Suivant/i.test((x.textContent || '').trim()) && !x.disabled); if (b) { b.click(); return true; } return false; });
    await page.waitForTimeout(500);
    if (!adv) {
      const recapNow = await page.evaluate(() => [...document.querySelectorAll('button')].some(b => /Ajouter au panier/i.test(b.textContent || '')));
      if (recapNow) continue;
      await snap(page, `g04-stuck-step${step}`);
      break;
    }
  }
});

test('GAP-03 — stripe card-pay screen', async ({ page }) => {
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
  await page.evaluate(() => { const t = [...document.querySelectorAll('button,[role="tab"],a')].find(e => /^menu$/i.test((e.textContent || '').trim())); if (t) t.click(); });
  await page.waitForTimeout(400);
  await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /Voir le panier/i.test(x.textContent || '')); if (b) b.click(); });
  await page.waitForTimeout(500);
  await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /valider ma commande|payer|commander|confirmer/i.test(x.textContent || '')); if (b) b.click(); });
  await page.waitForTimeout(600);
  // Pay modal: pick "PAYER MAINTENANT" (CB Stripe) — match the stripe path explicitly,
  // avoiding the gain modal's "VOIR MA CARTE".
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button, [role="button"]')].find(x => /payer maintenant|cb sécurisée|stripe|visa|mastercard/i.test(x.textContent || ''));
    if (b) b.click();
  });
  await page.waitForTimeout(900);
  await snap(page, 'g03-stripe-card');
});
