// E2E-01 GStack+Adversarial — Kiosk Idle × Client persona
// Runs an isolated Playwright session and walks the journey.

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const OUT_DIR = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/bad-mood-final-2026-05-25';
const CAPTURE_DIR = path.join(OUT_DIR, 'captures');
const AGENT_DIR = path.join(OUT_DIR, 'agents');
const URL = 'http://127.0.0.1:8000/kiosk/idle';

fs.mkdirSync(CAPTURE_DIR, { recursive: true });
fs.mkdirSync(AGENT_DIR, { recursive: true });

const consoleErrors = [];
const networkErrors = [];
const requests = [];
const issues = [];
const findings = [];
const screenshots = [];
const stateLogs = {};

function logState(name, info) {
  stateLogs[name] = info;
  console.log(`>>> STATE ${name}:`, JSON.stringify(info).slice(0, 1500));
}

function addIssue(state, severity, description) {
  issues.push({ state, severity, description });
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    locale: 'fr-FR',
  });
  const page = await context.newPage();

  page.on('console', (msg) => {
    if (msg.type() === 'error' || msg.type() === 'warning') {
      consoleErrors.push({ type: msg.type(), text: msg.text().slice(0, 500) });
    }
  });
  page.on('pageerror', (err) => {
    consoleErrors.push({ type: 'pageerror', text: String(err).slice(0, 500) });
  });
  page.on('requestfailed', (req) => {
    networkErrors.push({ url: req.url(), failure: req.failure()?.errorText });
  });
  page.on('response', (resp) => {
    const status = resp.status();
    if (status >= 400) {
      networkErrors.push({ url: resp.url(), status });
    }
    if (resp.url().includes('/api/') || resp.url().includes('/kiosk/')) {
      requests.push({ url: resp.url(), status });
    }
  });

  // STATE 1 — Idle screen
  try {
    await page.goto(URL, { waitUntil: 'networkidle', timeout: 30000 });
  } catch (e) {
    addIssue('state1', 'P0', `Initial navigation failed: ${e.message}`);
  }
  await page.waitForTimeout(1500);
  const s1Path = path.join(CAPTURE_DIR, 'E2E-01-state1.png');
  await page.screenshot({ path: s1Path, fullPage: false });
  screenshots.push(s1Path);

  // Analyze idle screen
  const s1Analysis = await page.evaluate(() => {
    const txt = document.body.innerText || '';
    const rawLabels = (txt.match(/\b(kiosk|label|menu|cart|wizard)\.[a-zA-Z_.]+/g) || []).slice(0, 20);
    return {
      url: window.location.href,
      title: document.title,
      textPreview: txt.slice(0, 400),
      rawLabels,
      buttonCount: document.querySelectorAll('button').length,
      categoryCount: document.querySelectorAll('[data-category], .category, .kiosk-category, .menu-cat').length,
      hasHero: !!document.querySelector('.hero, .splash, .kiosk-hero, [class*="hero"]'),
      heading: document.querySelector('h1')?.innerText || null,
      a11yMissingAlt: Array.from(document.querySelectorAll('img:not([alt])')).length,
      lang: document.documentElement.lang,
    };
  });
  logState('state1_idle', s1Analysis);
  if (s1Analysis.rawLabels.length > 0) {
    addIssue('state1', 'P1', `Raw i18n labels visible: ${s1Analysis.rawLabels.join(', ')}`);
  }
  if (s1Analysis.a11yMissingAlt > 0) {
    addIssue('state1', 'P2', `${s1Analysis.a11yMissingAlt} images without alt attribute`);
  }

  // STATE 2 — Click first category
  // Kiosk idle is usually splash → tap to start → then categories
  // Try clicking anywhere first to dismiss splash if any
  try {
    await page.evaluate(() => document.querySelector('.splash, [data-splash], .kiosk-splash')?.click());
    await page.waitForTimeout(800);
  } catch (e) { /* ignore */ }

  // Look for navigation to menu/categories
  const s2Probe = await page.evaluate(() => {
    // Candidate selectors for first category card
    const candidates = [
      '.kiosk-category-card',
      '.category-card',
      '[data-category-id]',
      '.menu-category',
      'a[href*="/category"]',
      'a[href*="/menu"]',
      'button[data-category]',
      '.kiosk-cat',
    ];
    for (const sel of candidates) {
      const el = document.querySelector(sel);
      if (el) {
        return { selector: sel, text: el.innerText?.slice(0, 80) || '(no text)', tag: el.tagName };
      }
    }
    // Fallback : any clickable looking thing with category-ish text
    const allButtons = Array.from(document.querySelectorAll('button, a')).slice(0, 20);
    return { fallback: allButtons.map(b => ({ tag: b.tagName, text: (b.innerText || '').slice(0, 60), href: b.href })).filter(x => x.text) };
  });
  logState('state2_probe', s2Probe);

  let clickedCategory = false;
  if (s2Probe.selector) {
    try {
      await page.click(s2Probe.selector, { timeout: 5000 });
      clickedCategory = true;
    } catch (e) { addIssue('state2', 'P2', `Could not click category: ${e.message}`); }
  } else {
    // Try heuristic : click a button containing category-like keywords
    const ok = await page.evaluate(() => {
      const KW = ['sandwich', 'burger', 'menu', 'tacos', 'salade', 'bol', 'boisson', 'dessert', 'commander', 'start', 'commencer'];
      const all = Array.from(document.querySelectorAll('button, a, [role=button], .card'));
      for (const el of all) {
        const t = (el.innerText || '').toLowerCase();
        if (KW.some(k => t.includes(k))) {
          el.click();
          return { text: el.innerText.slice(0, 80) };
        }
      }
      return null;
    });
    if (ok) { clickedCategory = true; logState('state2_click_heuristic', ok); }
    else { addIssue('state2', 'P1', 'No category card or commande button found on idle screen'); }
  }

  await page.waitForTimeout(2000);
  const s2Path = path.join(CAPTURE_DIR, 'E2E-01-state2.png');
  await page.screenshot({ path: s2Path, fullPage: false });
  screenshots.push(s2Path);
  const s2Analysis = await page.evaluate(() => ({
    url: window.location.href,
    textPreview: (document.body.innerText || '').slice(0, 400),
    itemCount: document.querySelectorAll('.kiosk-item, .menu-item, [data-item-id], .item-card, .product-card').length,
    visibleButtons: Array.from(document.querySelectorAll('button:not([disabled])')).length,
  }));
  logState('state2_menu', s2Analysis);

  // STATE 3 — Click first item
  const s3Probe = await page.evaluate(() => {
    const candidates = ['.kiosk-item', '.menu-item', '[data-item-id]', '.item-card', '.product-card', '.kiosk-product'];
    for (const sel of candidates) {
      const el = document.querySelector(sel);
      if (el) return { selector: sel, text: el.innerText?.slice(0, 80) };
    }
    return null;
  });
  logState('state3_probe', s3Probe);
  if (s3Probe?.selector) {
    try { await page.click(s3Probe.selector, { timeout: 5000 }); }
    catch (e) { addIssue('state3', 'P1', `Could not click first item: ${e.message}`); }
  } else {
    addIssue('state3', 'P0', 'No item visible after category selection — menu may be empty or DOM unknown');
  }
  await page.waitForTimeout(2000);
  const s3Path = path.join(CAPTURE_DIR, 'E2E-01-state3.png');
  await page.screenshot({ path: s3Path, fullPage: false });
  screenshots.push(s3Path);
  const s3Analysis = await page.evaluate(() => ({
    url: window.location.href,
    textPreview: (document.body.innerText || '').slice(0, 400),
    hasWizard: !!document.querySelector('.kiosk-wizard, .wizard, [data-wizard]'),
    stepIndicator: document.querySelector('.step-indicator, .wizard-step, .step-progress')?.innerText || null,
  }));
  logState('state3_wizard', s3Analysis);

  // STATE 4 — Try to advance wizard (click next/continue)
  const s4Click = await page.evaluate(() => {
    const KW = ['suivant', 'next', 'continuer', 'continue', 'valider', 'ajouter'];
    const allBtn = Array.from(document.querySelectorAll('button:not([disabled]), [role=button]:not([aria-disabled=true])'));
    for (const el of allBtn) {
      const t = (el.innerText || '').toLowerCase().trim();
      if (KW.some(k => t === k || t.startsWith(k + ' '))) {
        // Try to also pick first option/choice in step
        const optionable = document.querySelector('[data-option-id]:not(.selected), .option-card:not(.selected), .wizard-option:not(.selected)');
        optionable?.click();
        el.click();
        return { clickedText: el.innerText.slice(0, 80), selectedOption: !!optionable };
      }
    }
    return null;
  });
  logState('state4_click', s4Click);
  await page.waitForTimeout(1500);
  const s4Path = path.join(CAPTURE_DIR, 'E2E-01-state4.png');
  await page.screenshot({ path: s4Path, fullPage: false });
  screenshots.push(s4Path);

  // STATE 5 — Try to add to cart
  const s5Click = await page.evaluate(() => {
    const KW = ['ajouter au panier', 'add to cart', 'ajouter', 'valider', 'confirmer', 'terminer'];
    const allBtn = Array.from(document.querySelectorAll('button:not([disabled])'));
    for (const el of allBtn) {
      const t = (el.innerText || '').toLowerCase().trim();
      if (KW.some(k => t.includes(k))) {
        el.click();
        return { text: el.innerText.slice(0, 80) };
      }
    }
    return null;
  });
  logState('state5_addcart', s5Click);
  await page.waitForTimeout(2000);
  const s5Path = path.join(CAPTURE_DIR, 'E2E-01-state5.png');
  await page.screenshot({ path: s5Path, fullPage: false });
  screenshots.push(s5Path);
  const s5Analysis = await page.evaluate(() => ({
    url: window.location.href,
    textPreview: (document.body.innerText || '').slice(0, 400),
    cartCount: document.querySelector('.cart-count, .badge-cart, [data-cart-count]')?.innerText || null,
    cartTotal: document.querySelector('.cart-total, [data-cart-total]')?.innerText || null,
  }));
  logState('state5_post', s5Analysis);

  // STATE 6 — Click cart
  const s6Click = await page.evaluate(() => {
    const candidates = ['.cart-button', '.kiosk-cart', '[data-cart-toggle]', 'button[aria-label*="panier"]', 'a[href*="cart"]'];
    for (const sel of candidates) {
      const el = document.querySelector(sel);
      if (el) { el.click(); return { selector: sel }; }
    }
    return null;
  });
  logState('state6_click', s6Click);
  await page.waitForTimeout(1500);
  const s6Path = path.join(CAPTURE_DIR, 'E2E-01-state6.png');
  await page.screenshot({ path: s6Path, fullPage: false });
  screenshots.push(s6Path);
  const s6Analysis = await page.evaluate(() => ({
    url: window.location.href,
    textPreview: (document.body.innerText || '').slice(0, 400),
    itemsInCart: document.querySelectorAll('.cart-item, [data-cart-line]').length,
  }));
  logState('state6_cart', s6Analysis);

  // STATE 7 — Proceed to payment
  const s7Click = await page.evaluate(() => {
    const KW = ['payer', 'pay', 'commander', 'order', 'paiement', 'checkout', 'valider'];
    const allBtn = Array.from(document.querySelectorAll('button:not([disabled])'));
    for (const el of allBtn) {
      const t = (el.innerText || '').toLowerCase().trim();
      if (KW.some(k => t === k || t.startsWith(k))) { el.click(); return { text: el.innerText.slice(0, 80) }; }
    }
    return null;
  });
  logState('state7_click', s7Click);
  await page.waitForTimeout(2000);
  const s7Path = path.join(CAPTURE_DIR, 'E2E-01-state7.png');
  await page.screenshot({ path: s7Path, fullPage: false });
  screenshots.push(s7Path);
  const s7Analysis = await page.evaluate(() => ({
    url: window.location.href,
    textPreview: (document.body.innerText || '').slice(0, 400),
    hasPaymentMethods: !!document.querySelector('.payment-method, [data-payment-method], .pay-method'),
  }));
  logState('state7_payment', s7Analysis);

  // STATE 8 — Back to home (idle)
  try {
    await page.goto(URL, { waitUntil: 'networkidle', timeout: 15000 });
  } catch (e) {
    addIssue('state8', 'P1', `Back-to-home nav failed: ${e.message}`);
  }
  await page.waitForTimeout(1000);
  const s8Path = path.join(CAPTURE_DIR, 'E2E-01-state8.png');
  await page.screenshot({ path: s8Path, fullPage: false });
  screenshots.push(s8Path);
  const s8Analysis = await page.evaluate(() => ({
    url: window.location.href,
    textPreview: (document.body.innerText || '').slice(0, 400),
    backOnIdle: !!document.querySelector('.kiosk-idle, .splash, [data-splash], h1'),
  }));
  logState('state8_home', s8Analysis);

  // Adversarial : check raw labels globally
  const globalRawLabels = await page.evaluate(() => {
    const t = document.body.innerText || '';
    return (t.match(/\b(kiosk|label|menu|cart|wizard|step|payment|item)\.[a-zA-Z][a-zA-Z0-9_.]+/g) || []).slice(0, 30);
  });

  await browser.close();

  // Compute scores honest based on actual evidence
  const hadErrors = consoleErrors.length > 0;
  const hadNetErrors = networkErrors.length > 0;
  const hadRawLabels = (stateLogs.state1_idle?.rawLabels?.length || 0) > 0 || globalRawLabels.length > 0;
  const journeyAdvanced = !!(clickedCategory && (stateLogs.state3_wizard?.url !== stateLogs.state1_idle?.url || stateLogs.state2_menu?.url !== stateLogs.state1_idle?.url));

  const out = {
    agent: 'E2E-01 Kiosk Idle Client',
    persona: 'real client anonymous, no login',
    server: 'http://127.0.0.1:8000',
    started_url: URL,
    started_at: new Date().toISOString(),
    states_captured: screenshots.length,
    screenshots,
    state_logs: stateLogs,
    raw_labels_found: Array.from(new Set([...(stateLogs.state1_idle?.rawLabels || []), ...globalRawLabels])),
    console_errors: consoleErrors.slice(0, 30),
    console_error_count: consoleErrors.length,
    network_errors: networkErrors.slice(0, 30),
    network_error_count: networkErrors.length,
    notable_requests: requests.slice(0, 30),
    issues,
    gstack_scores: {
      // populated below
    },
    adversarial_findings: findings,
    verdict: null,
  };

  // Scores (honest based on captures)
  const p0 = issues.filter(i => i.severity === 'P0').length;
  const p1 = issues.filter(i => i.severity === 'P1').length;
  out.gstack_scores = {
    ux: journeyAdvanced ? (p1 === 0 ? 7 : 5) : 3,
    perf: hadNetErrors ? 4 : 7,
    a11y: (stateLogs.state1_idle?.a11yMissingAlt || 0) > 0 ? 4 : 6,
    i18n: hadRawLabels ? 3 : 7,
    polish: (p0 + p1) === 0 ? 7 : 5,
  };

  // Adversarial findings synthesis
  if (p0 > 0) out.adversarial_findings.push(`${p0} P0 blocker(s) detected during journey`);
  if (hadRawLabels) out.adversarial_findings.push(`Raw i18n keys leaking to DOM: ${out.raw_labels_found.slice(0, 10).join(', ')}`);
  if (hadNetErrors) out.adversarial_findings.push(`Network errors (4xx/5xx/failed): ${networkErrors.length}`);
  if (hadErrors) out.adversarial_findings.push(`Console errors/warnings: ${consoleErrors.length}`);
  if (!journeyAdvanced) out.adversarial_findings.push('Journey did not advance beyond idle — entry point not discoverable by anonymous client');

  out.verdict = p0 > 0 ? 'RED' : (p1 > 0 || hadRawLabels || hadNetErrors ? 'AMBER' : 'GREEN');

  const outPath = path.join(AGENT_DIR, 'E2E-01-kiosk-idle-client.json');
  fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
  console.log('\n=== WRITTEN ===');
  console.log(outPath);
  console.log('VERDICT:', out.verdict);
  console.log('STATES:', out.states_captured);
  console.log('ISSUES:', issues.length, '| P0:', p0, '| P1:', p1);
  console.log('CONSOLE ERRORS:', consoleErrors.length);
  console.log('NETWORK ERRORS:', networkErrors.length);
})().catch((e) => {
  console.error('FATAL:', e);
  // Best-effort dump partial result
  try {
    fs.writeFileSync(path.join(AGENT_DIR, 'E2E-01-kiosk-idle-client.json'), JSON.stringify({
      agent: 'E2E-01 Kiosk Idle Client',
      fatal_error: String(e),
      partial_state_logs: stateLogs,
      partial_screenshots: screenshots,
      partial_issues: issues,
      verdict: 'RED',
    }, null, 2));
  } catch (_) {}
  process.exit(1);
});
