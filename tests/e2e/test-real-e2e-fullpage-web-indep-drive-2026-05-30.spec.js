// INDEPENDENT ADVERSARIAL DRIVE — web wizard option-step photos on a DIFFERENT product
// than the provided Tacos M captures. Targets the `custom` template (Bowl) sauce +
// bol_supplements steps (wizard-v2.jsx line 111 reads composer choices `c.image`; line
// 114-119 bol_supplements rebuilds via suppBolsOptions pool) AND Sandwich Cayenne supplements.
// Asserts each option thumb is a REAL <img> with naturalWidth>0 — distinguishes a rendered
// board photo from display:none+emoji-fallback (the onError path in the renderer).
// READ-ONLY against app source. Captures durable PNGs.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test.use({ baseURL: 'http://127.0.0.1:8095', actionTimeout: 8000 });

const SHOT_DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/frontends-abuse-2026-05-30/round-3/independent-drive';
fs.mkdirSync(SHOT_DIR, { recursive: true });

async function bootWeb(page) {
  page._errors = []; page._imgErrors = [];
  page.on('pageerror', err => page._errors.push(err.message));
  page.on('console', msg => { if (msg.type() === 'error') page._errors.push(msg.text()); });
  page.on('response', r => {
    try { if (r.request().resourceType() === 'image' && r.status() >= 400) page._imgErrors.push(`${r.status()} ${r.url()}`); }
    catch (e) {}
  });
  await page.goto('/index.html', { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.LC && window.LC.menu && window.LC.menu.items && window.LC.menu.items.length > 0, { timeout: 30000 });
  await page.waitForTimeout(400);
}

// Returns per-option-thumb audit of the CURRENTLY visible wizard step.
async function auditStepThumbs(page) {
  return await page.evaluate(() => {
    const out = { title: '', options: [] };
    const titleEl = document.querySelector('.lc-wiz-title');
    out.title = titleEl ? titleEl.textContent.trim() : '';
    const choices = Array.from(document.querySelectorAll('.lc-wiz-options .lc-wiz-choice'));
    for (const c of choices) {
      const nameEl = c.querySelector('.lc-wiz-choice-name');
      const name = nameEl ? nameEl.textContent.trim() : '(?)';
      const img = c.querySelector('.lc-wiz-choice-thumb img');
      const thumb = c.querySelector('.lc-wiz-choice-thumb');
      let kind, detail;
      if (img) {
        const visible = img.style.display !== 'none';
        kind = (visible && img.naturalWidth > 0) ? 'PHOTO' : (img.naturalWidth === 0 ? 'BROKEN_IMG' : 'IMG_HIDDEN');
        detail = `src=${(img.getAttribute('src') || '').split('/').pop()} natW=${img.naturalWidth} disp=${img.style.display || 'block'}`;
      } else {
        // no <img> => emoji/icon text fallback path
        const txt = thumb ? thumb.textContent.trim() : '';
        kind = txt ? 'EMOJI_TEXT' : 'EMPTY_THUMB';
        detail = `thumbText="${txt}"`;
      }
      out.options.push({ name, kind, detail });
    }
    return out;
  });
}

async function shoot(page, name) {
  await page.screenshot({ path: path.join(SHOT_DIR, name + '.png'), fullPage: false }).catch(() => {});
}

// Open a product's wizard by slug via the live UI, then return when wizard modal visible.
async function openWizard(page, slug) {
  const name = await page.evaluate((s) => {
    const it = (window.W_ITEMS || []).find(i => i.slug === s || i.id === s);
    return it ? it.name : null;
  }, slug);
  if (!name) return null;
  // navigate to Menu
  const menuLink = page.locator('button.lc-nav-link, a', { hasText: /^Menu$/ }).first();
  if (await menuLink.isVisible().catch(() => false)) { await menuLink.click().catch(() => {}); await page.waitForTimeout(500); }
  const card = page.locator('button.lc-card-item', { hasText: name }).first();
  await card.scrollIntoViewIfNeeded().catch(() => {});
  await card.click().catch(() => {});
  await page.waitForTimeout(400);
  // detail modal → Personnaliser (or wizard opens directly)
  const perso = page.locator('button', { hasText: /Personnaliser|Composer|Configurer/ }).first();
  if (await perso.isVisible().catch(() => false)) { await perso.click().catch(() => {}); await page.waitForTimeout(500); }
  return name;
}

test('DRIVE Bowl (custom) sauce + bol_supplements steps — assert REAL photos', async ({ page }) => {
  await bootWeb(page);
  // pick a custom-template bowl product
  const slug = await page.evaluate(() => {
    const it = (window.W_ITEMS || []).find(i => (i.wizard_template === 'custom' || (i.composer_profile && /bol/.test((i.composer_profile.template||'')))) && /bowl|bol/i.test(i.slug || i.name));
    return it ? (it.slug || it.id) : null;
  });
  expect(slug, 'a custom-template bowl product exists in W_ITEMS').toBeTruthy();
  console.log('[BOWL] driving slug=', slug);
  const name = await openWizard(page, slug);
  expect(name, 'bowl wizard opened').toBeTruthy();

  const seen = [];
  for (let i = 0; i < 6; i++) {
    const next = page.locator('button.lc-wiz-foot-next').first();
    if (!(await next.isVisible().catch(() => false))) break;
    const audit = await auditStepThumbs(page);
    if (audit.options.length > 0) {
      await shoot(page, `bowl-step-${i}-${audit.title.toLowerCase().replace(/[^a-z]/g, '').slice(0, 14)}`);
      seen.push(audit);
      console.log(`[BOWL step ${i}] "${audit.title}":`, JSON.stringify(audit.options));
    }
    const btnTxt = (await next.innerText().catch(() => '')) || '';
    if (/Ajouter au panier/i.test(btnTxt)) break;
    // satisfy required: click first option if next disabled
    if (await next.isDisabled().catch(() => true)) {
      await page.locator('.lc-wiz-options .lc-wiz-choice').first().click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(300);
    }
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(400);
  }

  // Assert: across all option-bearing steps, EVERY thumb is a real PHOTO (none EMOJI/BROKEN)
  const bad = [];
  for (const step of seen) {
    for (const o of step.options) {
      if (o.kind !== 'PHOTO') bad.push(`[${step.title}] ${o.name}: ${o.kind} (${o.detail})`);
    }
  }
  console.log('[BOWL] image 404s:', JSON.stringify(page._imgErrors));
  console.log('[BOWL] console/page errors:', JSON.stringify(page._errors));
  expect(seen.length, 'bowl exposed at least one option step').toBeGreaterThan(0);
  expect(bad, 'all bowl option thumbs are real PHOTOs (no emoji/broken)').toEqual([]);
  expect(page._imgErrors, 'no image 404 during bowl wizard').toEqual([]);
});

test('DRIVE Sandwich Cayenne supplements step — assert REAL photos', async ({ page }) => {
  await bootWeb(page);
  const slug = await page.evaluate(() => {
    const it = (window.W_ITEMS || []).find(i => /sandwich-cayenne/i.test(i.slug || '') && (i.wizard_template === 'sandwich' || i.wizard_template === 'tacos' || (i.has_supplements !== false)));
    return it ? (it.slug || it.id) : null;
  });
  expect(slug, 'a sandwich-cayenne product exists').toBeTruthy();
  console.log('[SANDWICH] driving slug=', slug);
  const name = await openWizard(page, slug);
  expect(name, 'sandwich wizard opened').toBeTruthy();

  const seen = [];
  for (let i = 0; i < 9; i++) {
    const next = page.locator('button.lc-wiz-foot-next').first();
    if (!(await next.isVisible().catch(() => false))) break;
    const audit = await auditStepThumbs(page);
    if (audit.options.length > 0 && /suppl|sauce|viande|crudit/i.test(audit.title)) {
      await shoot(page, `sandwich-step-${i}-${audit.title.toLowerCase().replace(/[^a-z]/g, '').slice(0, 14)}`);
      seen.push(audit);
      console.log(`[SANDWICH step ${i}] "${audit.title}":`, JSON.stringify(audit.options));
    }
    const btnTxt = (await next.innerText().catch(() => '')) || '';
    if (/Ajouter au panier/i.test(btnTxt)) break;
    if (await next.isDisabled().catch(() => true)) {
      await page.locator('.lc-wiz-options .lc-wiz-choice').first().click({ timeout: 4000 }).catch(() => {});
      await page.waitForTimeout(300);
    }
    await next.click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(400);
  }

  const bad = [];
  for (const step of seen) {
    for (const o of step.options) {
      if (o.kind !== 'PHOTO') bad.push(`[${step.title}] ${o.name}: ${o.kind} (${o.detail})`);
    }
  }
  console.log('[SANDWICH] image 404s:', JSON.stringify(page._imgErrors));
  console.log('[SANDWICH] console/page errors:', JSON.stringify(page._errors));
  expect(seen.length, 'sandwich exposed supplement/sauce/viande/crudite step').toBeGreaterThan(0);
  expect(bad, 'all sandwich option thumbs are real PHOTOs').toEqual([]);
  expect(page._imgErrors, 'no image 404 during sandwich wizard').toEqual([]);
});
