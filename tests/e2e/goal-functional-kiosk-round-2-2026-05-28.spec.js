/**
 * GOAL E2E — KIOSK CLIENT JOURNEY — Round 2 (2026-05-28)
 *
 * Mission: walk through the kiosk like a real client, post DB restore (45 items)
 *  + wizard corrections seeder (50 Cayenne sauces, bols 2 sauces only, gratine
 *  +2€ in supplement_bol). Capture visual + technical evidence at every step
 *  with viewport 1080×1920 (portrait kiosk).
 *
 * Surfaces:
 *   1. /kiosk/idle
 *   2. Catalogue (after tap "Commencer" / "À emporter")
 *   3. Wizard Sandwich Cayenne #22
 *   4. Wizard Tacos #26
 *   5. Wizard Bowl Frites Poulet mariné #41
 *   6. Cart (multi-item)
 *   7. Upsell
 *   8. Payment selection (card)
 *   9. Confirmation/waiting
 *
 * Outputs:
 *   - Screenshots to /tmp/foodking-round2-kiosk/<step>.png  (task verbatim)
 *   - DOM dumps + theme/raw-label captures to reports/.../KIOSK/round-2/dumps/
 *
 * No code changes. Audit-only. Frozen zones respected (KioskWizardComponent,
 * KioskAppComponent, KioskUpsellComponent — read-only).
 */

const fs = require('fs');
const path = require('path');
const { test } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');

// ---------- Output dirs ----------
const SHOT_DIR = '/tmp/foodking-round2-kiosk';
const REPORT_DIR = path.join(
  process.cwd(),
  'reports/test-e2e/goal-functional-validation-2026-05-28/KIOSK/round-2',
);
const DUMP_DIR = path.join(REPORT_DIR, 'dumps');
fs.mkdirSync(SHOT_DIR, { recursive: true });
fs.mkdirSync(REPORT_DIR, { recursive: true });
fs.mkdirSync(DUMP_DIR, { recursive: true });

// ---------- Shared accumulators ----------
const consoleErrors = [];
const failedRequests = [];
const themeProbes = [];
const rawLabelHits = [];

const RAW_LABEL_REGEX =
  /\b(kiosk|wizard|pos|admin|cart|payment|order)\.[a-z][a-z0-9_.-]+\b|\[object Object\]|\b0undefined\b|\bundefined undefined\b/i;

function attach(page, label) {
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push({ label, text: msg.text() });
  });
  page.on('requestfailed', (req) => {
    failedRequests.push({
      label,
      url: req.url(),
      failure: req.failure()?.errorText,
    });
  });
}

async function snap(page, name) {
  const file = path.join(SHOT_DIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

async function dump(page, name) {
  const file = path.join(DUMP_DIR, `${name}.html`);
  fs.writeFileSync(file, await page.content());
  return file;
}

async function dumpText(page, name) {
  const file = path.join(DUMP_DIR, `${name}.txt`);
  const txt = await page.locator('body').innerText().catch(() => '');
  fs.writeFileSync(file, txt.slice(0, 12000));
  return { file, txt };
}

async function probeTheme(page, name) {
  const probe = await page
    .evaluate(() => {
      const root = document.documentElement;
      const body = document.body;
      const wrapper =
        document.querySelector('.kiosk-app, .kiosk-shell, [class*="kiosk"]') ||
        body;
      const cs = (el) => window.getComputedStyle(el);
      const summary = {
        url: location.href,
        ts: Date.now(),
        htmlClass: root.className,
        htmlData: Array.from(root.attributes).reduce((a, x) => {
          a[x.name] = x.value;
          return a;
        }, {}),
        bodyClass: body.className,
        bodyBg: cs(body).backgroundColor,
        bodyColor: cs(body).color,
        wrapperBg: cs(wrapper).backgroundColor,
        wrapperColor: cs(wrapper).color,
        wrapperTag: wrapper.tagName,
        wrapperClass: wrapper.className,
      };
      // Spot-check first 8 visible main containers
      const containers = Array.from(
        document.querySelectorAll('main, .main, section, .panel, .modal, .drawer'),
      )
        .slice(0, 8)
        .map((el) => ({
          tag: el.tagName,
          cls: el.className,
          bg: cs(el).backgroundColor,
          color: cs(el).color,
        }));
      summary.spotChecks = containers;
      return summary;
    })
    .catch((e) => ({ error: String(e) }));
  themeProbes.push({ step: name, ...probe });
  return probe;
}

async function probeRawLabels(page, name) {
  const txt = await page.locator('body').innerText().catch(() => '');
  const matches = txt.match(new RegExp(RAW_LABEL_REGEX, 'gi')) || [];
  if (matches.length) {
    rawLabelHits.push({ step: name, count: matches.length, samples: matches.slice(0, 12) });
  }
  return matches;
}

async function capturePoint(page, name) {
  await snap(page, name);
  await dump(page, name);
  const { txt } = await dumpText(page, name);
  const theme = await probeTheme(page, name);
  const rawLabels = await probeRawLabels(page, name);
  return { name, theme, rawLabels, textPreview: txt.slice(0, 600) };
}

// ---------- Spec ----------
test.use({ viewport: { width: 1080, height: 1920 } });
test.describe.configure({ mode: 'serial', timeout: 600_000 });

test.describe('KIOSK Round 2 — Client journey 1080×1920', () => {
  test('R2-01 — Idle screen + start tap + catalog land', async ({ page }) => {
    attach(page, 'R2-01');
    try {
      await loginAsKiosk(page);
    } catch (e) {
      console.warn('[R2-01] kiosk login warn:', e.message);
    }

    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await capturePoint(page, '01-idle');

    // Tap the wakeup / start button. Owner UI uses several possible labels.
    const startCandidates = [
      'Commencer',
      'COMMENCER',
      'Toucher pour commander',
      'Touchez pour commander',
      'Start',
      'À emporter',
    ];
    let started = false;
    for (const txt of startCandidates) {
      const loc = page.getByText(txt, { exact: false }).first();
      if (await loc.isVisible({ timeout: 1500 }).catch(() => false)) {
        await loc.click().catch(() => null);
        started = true;
        break;
      }
    }
    await page.waitForTimeout(1500);
    await capturePoint(page, '02-after-start-tap');

    // Pick order type if shown
    const aEmporter = page.getByText(/^À emporter$/i, { exact: false }).first();
    if (await aEmporter.isVisible({ timeout: 1500 }).catch(() => false)) {
      await aEmporter.click().catch(() => null);
      await page.waitForTimeout(1500);
    }
    await capturePoint(page, '03-catalog-land');
  });

  test('R2-02 — Sandwich Cayenne #22 wizard probe', async ({ page }) => {
    attach(page, 'R2-02');
    try { await loginAsKiosk(page); } catch (_) {}
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);

    // Enter order-type if shown
    const aEmporter = page.getByText('À emporter', { exact: false }).first();
    if (await aEmporter.isVisible({ timeout: 2000 }).catch(() => false)) {
      await aEmporter.click().catch(() => null);
      await page.waitForTimeout(1500);
    }

    // Open Sandwich Cayenne category
    const catBtn = page.getByText(/Sandwich Cayenne/i).first();
    if (await catBtn.isVisible({ timeout: 2500 }).catch(() => false)) {
      await catBtn.click().catch(() => null);
      await page.waitForTimeout(1200);
    }
    await capturePoint(page, '04-cayenne-category');

    // Tap the Sandwich Cayenne product card (price 7,00 €)
    const productCard = page
      .locator(
        'button:has-text("Sandwich Cayenne"), [class*="card"]:has-text("Sandwich Cayenne"), [class*="product"]:has-text("Sandwich Cayenne")',
      )
      .first();
    if (await productCard.isVisible({ timeout: 2500 }).catch(() => false)) {
      await productCard.click().catch(() => null);
      await page.waitForTimeout(2000);
      await capturePoint(page, '05-cayenne-wizard-step1');

      // Walk through wizard steps — capture each
      for (let stepIdx = 1; stepIdx <= 6; stepIdx++) {
        await capturePoint(page, `06-cayenne-wizard-step${stepIdx}-before`);
        // Click first option / "Continuer"
        const nextBtn = page
          .getByRole('button', { name: /Continuer|Suivant|Valider|Ajouter/i })
          .first();
        const firstOpt = page
          .locator(
            '[class*="option"], [class*="choice"], button[data-choice], [role="radio"], [role="checkbox"]',
          )
          .first();
        if (await firstOpt.isVisible({ timeout: 1000 }).catch(() => false)) {
          await firstOpt.click().catch(() => null);
          await page.waitForTimeout(300);
        }
        if (await nextBtn.isVisible({ timeout: 1000 }).catch(() => false)) {
          await nextBtn.click().catch(() => null);
          await page.waitForTimeout(800);
        } else {
          break; // no next button = end of wizard or no wizard
        }
      }
      await capturePoint(page, '07-cayenne-wizard-final');
    } else {
      await capturePoint(page, '05-cayenne-NOT-FOUND');
    }
  });

  test('R2-03 — Tacos #26 wizard probe', async ({ page }) => {
    attach(page, 'R2-03');
    try { await loginAsKiosk(page); } catch (_) {}
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    const aEmporter = page.getByText('À emporter', { exact: false }).first();
    if (await aEmporter.isVisible({ timeout: 2000 }).catch(() => false)) {
      await aEmporter.click().catch(() => null);
      await page.waitForTimeout(1500);
    }

    const catBtn = page.getByText(/^Tacos$/i, { exact: false }).first();
    if (await catBtn.isVisible({ timeout: 2500 }).catch(() => false)) {
      await catBtn.click().catch(() => null);
      await page.waitForTimeout(1200);
    }
    await capturePoint(page, '08-tacos-category');

    const productCard = page
      .locator(
        'button:has-text("Tacos"), [class*="card"]:has-text("Tacos"), [class*="product"]:has-text("Tacos")',
      )
      .first();
    if (await productCard.isVisible({ timeout: 2500 }).catch(() => false)) {
      await productCard.click().catch(() => null);
      await page.waitForTimeout(2000);
      await capturePoint(page, '09-tacos-wizard-step1');
      for (let stepIdx = 1; stepIdx <= 6; stepIdx++) {
        const firstOpt = page
          .locator('[class*="option"], [class*="choice"], button[data-choice]')
          .first();
        if (await firstOpt.isVisible({ timeout: 1000 }).catch(() => false)) {
          await firstOpt.click().catch(() => null);
          await page.waitForTimeout(300);
        }
        const nextBtn = page
          .getByRole('button', { name: /Continuer|Suivant|Valider|Ajouter/i })
          .first();
        if (await nextBtn.isVisible({ timeout: 1000 }).catch(() => false)) {
          await nextBtn.click().catch(() => null);
          await page.waitForTimeout(800);
        } else {
          break;
        }
      }
      await capturePoint(page, '10-tacos-wizard-final');
    } else {
      await capturePoint(page, '09-tacos-NOT-FOUND');
    }
  });

  test('R2-04 — Bowl Frites Poulet mariné #41 wizard + gratine delta', async ({ page }) => {
    attach(page, 'R2-04');
    try { await loginAsKiosk(page); } catch (_) {}
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    const aEmporter = page.getByText('À emporter', { exact: false }).first();
    if (await aEmporter.isVisible({ timeout: 2000 }).catch(() => false)) {
      await aEmporter.click().catch(() => null);
      await page.waitForTimeout(1500);
    }

    const catBtn = page.getByText(/Bols Gourmands|Bowl|Bols/i).first();
    if (await catBtn.isVisible({ timeout: 2500 }).catch(() => false)) {
      await catBtn.click().catch(() => null);
      await page.waitForTimeout(1200);
    }
    await capturePoint(page, '11-bowls-category');

    const productCard = page
      .locator(
        'button:has-text("Bowl Frites Poulet mariné"), [class*="card"]:has-text("Bowl Frites Poulet mariné"), [class*="product"]:has-text("Bowl Frites Poulet mariné")',
      )
      .first();
    if (await productCard.isVisible({ timeout: 2500 }).catch(() => false)) {
      await productCard.click().catch(() => null);
      await page.waitForTimeout(2000);
      await capturePoint(page, '12-bowl-wizard-step1');

      // Walk wizard, but on the sauces/supplements step try to capture Gratine pricing
      let priceBeforeGratine = null;
      let priceAfterGratine = null;
      for (let stepIdx = 1; stepIdx <= 6; stepIdx++) {
        await capturePoint(page, `13-bowl-step${stepIdx}`);

        // Capture cart / running total before clicking gratine
        const bodyText = await page.locator('body').innerText().catch(() => '');
        const totalMatch = bodyText.match(/([0-9]+[,.][0-9]{2})\s*€/g) || [];

        // Look for "Boule gratinée" or "Gratiné" with +2€
        const gratine = page
          .getByText(/Gratin[ée]|Boule gratin[ée]e/i)
          .first();
        if (await gratine.isVisible({ timeout: 1000 }).catch(() => false)) {
          priceBeforeGratine = totalMatch.slice(-1)[0] || null;
          await gratine.click().catch(() => null);
          await page.waitForTimeout(700);
          await capturePoint(page, `14-bowl-step${stepIdx}-gratine-clicked`);
          const after = await page.locator('body').innerText().catch(() => '');
          const afterMatch = after.match(/([0-9]+[,.][0-9]{2})\s*€/g) || [];
          priceAfterGratine = afterMatch.slice(-1)[0] || null;
          fs.writeFileSync(
            path.join(DUMP_DIR, 'bowl-gratine-delta.json'),
            JSON.stringify(
              { stepIdx, priceBeforeGratine, priceAfterGratine, beforeAll: totalMatch, afterAll: afterMatch },
              null,
              2,
            ),
          );
        }

        const firstOpt = page
          .locator('[class*="option"], [class*="choice"], button[data-choice]')
          .first();
        if (await firstOpt.isVisible({ timeout: 1000 }).catch(() => false)) {
          await firstOpt.click().catch(() => null);
          await page.waitForTimeout(300);
        }
        const nextBtn = page
          .getByRole('button', { name: /Continuer|Suivant|Valider|Ajouter/i })
          .first();
        if (await nextBtn.isVisible({ timeout: 1000 }).catch(() => false)) {
          await nextBtn.click().catch(() => null);
          await page.waitForTimeout(800);
        } else {
          break;
        }
      }
      await capturePoint(page, '15-bowl-wizard-final');
    } else {
      await capturePoint(page, '12-bowl-NOT-FOUND');
    }
  });

  test('R2-05 — Cart + upsell + payment + confirmation flow', async ({ page }) => {
    attach(page, 'R2-05');
    try { await loginAsKiosk(page); } catch (_) {}
    await page.goto('/kiosk', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    const aEmporter = page.getByText('À emporter', { exact: false }).first();
    if (await aEmporter.isVisible({ timeout: 2000 }).catch(() => false)) {
      await aEmporter.click().catch(() => null);
      await page.waitForTimeout(1500);
    }
    await capturePoint(page, '16-catalog-pre-cart');

    // Try to open cart bottom-sheet
    const cartBtn = page
      .locator(
        'button:has-text("Panier"), [aria-label*="Panier"], [class*="cart"]',
      )
      .first();
    if (await cartBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
      await cartBtn.click().catch(() => null);
      await page.waitForTimeout(800);
      await capturePoint(page, '17-cart-panel');
    } else {
      await capturePoint(page, '17-cart-NOT-VISIBLE');
    }

    // Try Payer / Valider commande
    const payer = page
      .getByRole('button', { name: /Payer|Valider commande|Commander|Valider/i })
      .first();
    if (await payer.isVisible({ timeout: 1500 }).catch(() => false)) {
      await payer.click().catch(() => null);
      await page.waitForTimeout(2000);
      await capturePoint(page, '18-after-payer-click');
    }

    // Upsell screen probe
    const upsell = page.getByText(/Et avec ça|Suggestion|Souhaitez-vous/i).first();
    if (await upsell.isVisible({ timeout: 1500 }).catch(() => false)) {
      await capturePoint(page, '19-upsell-shown');
      const skip = page
        .getByRole('button', { name: /Passer|Non merci|Skip|Continuer sans/i })
        .first();
      if (await skip.isVisible({ timeout: 1000 }).catch(() => false)) {
        await skip.click().catch(() => null);
        await page.waitForTimeout(1500);
      }
    } else {
      await capturePoint(page, '19-upsell-NOT-SHOWN');
    }

    // Payment selection
    const card = page
      .getByText(/Carte bancaire|Carte CB|Carte|Card/i)
      .first();
    if (await card.isVisible({ timeout: 1500 }).catch(() => false)) {
      await capturePoint(page, '20-payment-selection');
      await card.click().catch(() => null);
      await page.waitForTimeout(2000);
      await capturePoint(page, '21-after-card-tap');
    } else {
      await capturePoint(page, '20-payment-NOT-SHOWN');
    }

    // Confirmation page — check auto-redirect 10s
    const t0Url = page.url();
    const t0Capture = await capturePoint(page, '22-confirmation-t0');
    await page.waitForTimeout(11_000);
    const t11Url = page.url();
    const t11Capture = await capturePoint(page, '23-confirmation-t11s');

    fs.writeFileSync(
      path.join(DUMP_DIR, 'confirmation-redirect.json'),
      JSON.stringify(
        { t0Url, t11Url, redirected: t0Url !== t11Url },
        null,
        2,
      ),
    );

    // Home button presence
    const homeBtn = page
      .getByRole('button', { name: /Accueil|Home|Retour à l.accueil/i })
      .first();
    const homePresent = await homeBtn.isVisible({ timeout: 1000 }).catch(() => false);
    fs.writeFileSync(
      path.join(DUMP_DIR, 'home-button-presence.json'),
      JSON.stringify({ visible: homePresent }, null, 2),
    );
  });

  test.afterAll(() => {
    fs.writeFileSync(
      path.join(REPORT_DIR, 'console-errors.json'),
      JSON.stringify(consoleErrors, null, 2),
    );
    fs.writeFileSync(
      path.join(REPORT_DIR, 'failed-requests.json'),
      JSON.stringify(failedRequests, null, 2),
    );
    fs.writeFileSync(
      path.join(REPORT_DIR, 'theme-probes.json'),
      JSON.stringify(themeProbes, null, 2),
    );
    fs.writeFileSync(
      path.join(REPORT_DIR, 'raw-label-hits.json'),
      JSON.stringify(rawLabelHits, null, 2),
    );
  });
});
