/**
 * Round-5 GOAL W3 recapture — Sandwichs / Galette / Frites wizards
 * FROM Dashboard Catalogue. Isolated Playwright CLI (no MCP, no globalSetup).
 * Do NOT publish, do NOT open POS, do NOT invent products.
 *
 * Artifacts:
 *   wave-D-sandwichs/02-wizard.png
 *   wave-D-galette/02-wizard.png
 *   wave-D-frites/03-frites-wizard.png
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const REPO = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
process.chdir(REPO);

const require = createRequire(path.join(REPO, 'package.json'));
const { chromium } = require('playwright');

const BASE = (
  process.env.FOODKING_E2E_BASE_URL
  || process.env.PLAYWRIGHT_BASE_URL
  || 'http://127.0.0.1:8766'
).replace(/\/+$/, '');
const ROUND = path.join(REPO, 'reports/test-e2e/grok-dashboard-cockpit-10j/round-5');
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';

function mkdirp(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function resolveChromiumExecutable() {
  if (process.env.PLAYWRIGHT_CHROMIUM) return process.env.PLAYWRIGHT_CHROMIUM;
  const home = process.env.HOME || '';
  const candidates = [
    path.join(home, 'Library/Caches/ms-playwright/chromium_headless_shell-1237/chrome-headless-shell-mac-arm64/chrome-headless-shell'),
    path.join(home, 'Library/Caches/ms-playwright/chromium-1237/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'),
    path.join(home, 'Library/Caches/ms-playwright/chromium_headless_shell-1208/chrome-headless-shell-mac-arm64/chrome-headless-shell'),
    path.join(home, 'Library/Caches/ms-playwright/chromium-1208/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'),
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ];
  return candidates.find((c) => fs.existsSync(c)) || null;
}

function isWsOrDebugbar(url) {
  const u = String(url || '');
  return (
    /^wss?:/i.test(u)
    || /_debugbar|clockwork|telescope|horizon/i.test(u)
    || /:6001\b|:8080\/app\//i.test(u)
    || /\/app\/[A-Za-z0-9]/i.test(u)
    || /pusher|reverb|soketi/i.test(u)
  );
}

function isEnglishStorageLeftover(url) {
  return /\/storage\/1\/english\.png/i.test(String(url || ''));
}

function isIgnored4xx(row) {
  return isWsOrDebugbar(row.url) || isEnglishStorageLeftover(row.url);
}

function clearRateLimits() {
  const r = spawnSync(
    'php',
    [
      'artisan',
      'tinker',
      '--execute',
      `
    $limiter = app(\\Illuminate\\Cache\\RateLimiter::class);
    $ids = \\App\\Models\\User::whereIn('email', ['admin@lecayenne.fr'])->pluck('id')->map(fn($id) => (string) $id)->all();
    $keys = array_unique(array_merge($ids, [
      '127.0.0.1','::1','localhost',
      'admin@lecayenne.fr|127.0.0.1','admin@lecayenne.fr|::1',
    ]));
    foreach (['api','login-lockout'] as $name) {
      foreach ($keys as $key) { $limiter->clear(md5($name.$key)); }
    }
    echo 'ok';
  `,
    ],
    { cwd: REPO, encoding: 'utf8', timeout: 20_000 },
  );
  if (r.status !== 0) {
    console.warn('rate-limit clear failed', r.stderr || r.stdout);
  }
}

function attachCollectors(page) {
  const consoleBuf = [];
  const networkBuf = [];
  const allNetwork = [];
  const mutations = [];
  const profileBodies = [];

  page.on('console', (msg) => {
    const type = msg.type();
    if (!['error', 'warning', 'assert'].includes(type)) return;
    const text = msg.text();
    if (/WebSocket connection to 'ws[s]?:\/\/[^']*' failed/i.test(text)) return;
    if (/^Pusher\s*:/i.test(text)) return;
    consoleBuf.push({
      level: type,
      text: String(text).substring(0, 4000),
      location: msg.location(),
      ts: Date.now(),
    });
  });
  page.on('pageerror', (err) => {
    consoleBuf.push({
      level: 'pageerror',
      text: String(err && err.message ? err.message : err).substring(0, 4000),
      stack: String(err && err.stack ? err.stack : '').substring(0, 6000),
      ts: Date.now(),
    });
  });
  page.on('request', (req) => {
    const method = req.method();
    if (!/^(POST|PUT|PATCH|DELETE)$/i.test(method)) return;
    mutations.push({
      method,
      url: String(req.url()).substring(0, 500),
      ts: Date.now(),
    });
  });
  page.on('response', (resp) => {
    const status = resp.status();
    const url = resp.url();
    const row = {
      kind: 'http',
      url: String(url).substring(0, 500),
      method: resp.request().method(),
      status,
      resourceType: resp.request().resourceType(),
      ws_or_debugbar: isWsOrDebugbar(url),
      english_storage_leftover: isEnglishStorageLeftover(url),
      ts: Date.now(),
    };
    allNetwork.push(row);
    if (status >= 400) networkBuf.push(row);

    const method = resp.request().method();
    if (
      method === 'GET'
      && status >= 200
      && status < 300
      && /\/admin\/composer\/categories\/\d+\/profile/i.test(url)
    ) {
      resp.json().then((body) => {
        const data = body && body.data ? body.data : body;
        const steps = Array.isArray(data && data.steps) ? data.steps : [];
        profileBodies.push({
          url: String(url).substring(0, 500),
          category_id: (String(url).match(/categories\/(\d+)/) || [])[1] || null,
          is_published: data ? data.is_published : null,
          template: data ? data.template : null,
          branch_id_scope: data ? data.branch_id_scope : null,
          step_count: steps.length,
          steps: steps.map((s, i) => ({
            index: i,
            id: s && s.id != null ? s.id : null,
            step_key: s ? s.step_key : null,
            label: s ? s.label : null,
            source_type: s ? s.source_type : null,
            source_ref: s ? s.source_ref : null,
            min_select: s ? s.min_select : null,
            max_select: s ? s.max_select : null,
            is_active: s ? s.is_active : null,
            visible_on: s ? s.visible_on : null,
          })),
        });
      }).catch(() => {});
    }
  });
  page.on('requestfailed', (req) => {
    const url = req.url();
    const failure = req.failure();
    const row = {
      kind: 'failed',
      url: String(url).substring(0, 500),
      method: req.method(),
      status: 0,
      resourceType: req.resourceType(),
      errorText: failure ? failure.errorText : 'requestfailed',
      ws_or_debugbar: isWsOrDebugbar(url),
      english_storage_leftover: isEnglishStorageLeftover(url),
      ts: Date.now(),
    };
    allNetwork.push(row);
    networkBuf.push(row);
  });

  function snapshotBuffers() {
    return {
      console: consoleBuf.slice(),
      network: networkBuf.slice(),
    };
  }

  function clearStateBuffers() {
    consoleBuf.length = 0;
    networkBuf.length = 0;
  }

  return { consoleBuf, networkBuf, allNetwork, mutations, profileBodies, snapshotBuffers, clearStateBuffers };
}

async function disableCache(page) {
  const client = await page.context().newCDPSession(page);
  await client.send('Network.enable');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  return client;
}

async function hideDebugbar(target) {
  await target.evaluate(() => {
    document.querySelectorAll('.phpdebugbar, #phpdebugbar, .sf-toolbar, .phpdebugbar-openhandler').forEach((el) => {
      el.style.setProperty('display', 'none', 'important');
    });
    document.documentElement.classList.remove('phpdebugbar-shown');
    document.body.style.setProperty('padding-bottom', '0', 'important');
    document.body.style.setProperty('margin-bottom', '0', 'important');
  }).catch(() => {});
}

async function snapQuartet(page, outDir, name, buffers, { frame = null } = {}) {
  mkdirp(outDir);
  await hideDebugbar(page);
  if (frame) await hideDebugbar(frame);
  const png = path.join(outDir, `${name}.png`);
  const dom = path.join(outDir, `${name}.dom.html`);
  const consolePath = path.join(outDir, `${name}.console.json`);
  const networkPath = path.join(outDir, `${name}.network.json`);

  if (frame) {
    await page.evaluate(() => {
      const iframe = document.querySelector('[data-testid="catalog-studio-composer-frame"]');
      const overlay = document.querySelector('[data-testid="catalog-studio-composer-overlay"]');
      if (iframe) {
        iframe.style.setProperty('height', '2400px', 'important');
        iframe.style.setProperty('min-height', '2400px', 'important');
        iframe.style.setProperty('width', '100%', 'important');
      }
      if (overlay) {
        overlay.style.setProperty('overflow', 'visible', 'important');
        overlay.style.setProperty('max-height', 'none', 'important');
      }
    }).catch(() => {});
    await page.waitForTimeout(200);
    const root = frame.locator('[data-testid="admin-composer-root"]');
    if (await root.count()) {
      await root.screenshot({ path: png }).catch(async () => {
        await page.screenshot({ path: png, fullPage: true });
      });
    } else {
      await page.screenshot({ path: png, fullPage: true });
    }
    const parentHtml = await page.content();
    const iframeHtml = await frame.content().catch(() => '');
    const combined = `<!-- PARENT_URL ${page.url()} -->\n${parentHtml}\n\n<!-- IFRAME_COMPOSER_URL ${frame.url()} -->\n${iframeHtml}`;
    fs.writeFileSync(dom, combined.substring(0, 2_500_000), 'utf8');
  } else {
    await page.screenshot({ path: png, fullPage: true });
    fs.writeFileSync(dom, (await page.content()).substring(0, 2_500_000), 'utf8');
  }
  fs.writeFileSync(consolePath, JSON.stringify(buffers.console, null, 2), 'utf8');
  fs.writeFileSync(networkPath, JSON.stringify(buffers.network, null, 2), 'utf8');
  return { png, dom, console: consolePath, network: networkPath, url: page.url() };
}

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.locator('#formEmail').waitFor({ state: 'visible', timeout: 20_000 });
  await page.locator('#formEmail').fill(EMAIL);
  await page.locator('#formPassword').fill(PASSWORD);
  const submit = page.getByRole('button', { name: /^(login|connexion)$/i });
  const loginResponse = page.waitForResponse(
    (res) => res.request().method() === 'POST' && /\/api\/auth\/login/i.test(res.url()),
    { timeout: 25_000 },
  );
  await submit.click();
  const resp = await loginResponse;
  const status = resp.status();
  if (status !== 201) {
    const body = await resp.text().catch(() => '');
    throw new Error(`Login API failed: HTTP ${status} ${body.slice(0, 400)}`);
  }
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 25_000 });
  await page.waitForTimeout(800);
  return { status, url: page.url() };
}

async function waitDashboardReady(page) {
  await page.getByText(/Ventes du jour/i).first().waitFor({ state: 'visible', timeout: 30_000 });
  await page.getByText(/Accès rapides|Catalogue/i).first().waitFor({ state: 'visible', timeout: 20_000 });
  await page.waitForTimeout(600);
}

async function listCategories(page) {
  return page.evaluate(() => {
    return Array.from(document.querySelectorAll('[data-testid^="catalog-studio-category-row-"]')).map((row) => {
      const strong = row.querySelector('button.catalog-studio__category strong');
      const small = row.querySelector('button.catalog-studio__category small');
      const name = strong ? String(strong.textContent || '').replace(/\s+/g, ' ').trim() : '';
      const countText = small ? String(small.textContent || '').replace(/\s+/g, ' ').trim() : '';
      const countMatch = countText.match(/(\d+)/);
      return {
        testid: row.getAttribute('data-testid'),
        id: (row.getAttribute('data-testid') || '').replace('catalog-studio-category-row-', ''),
        name,
        count_text: countText,
        product_count: countMatch ? Number(countMatch[1]) : null,
        empty: countMatch ? Number(countMatch[1]) === 0 : /aucun|empty|0 produit/i.test(countText),
      };
    });
  });
}

async function waitComposerReady(page) {
  const overlay = page.getByTestId('catalog-studio-composer-overlay');
  const overlayVisible = await overlay.isVisible().catch(() => false);
  const result = {
    overlay_visible: overlayVisible,
    iframe_src: null,
    frame_ready: false,
    full_page_composer: false,
    wait_error: null,
  };

  if (overlayVisible) {
    const frameEl = page.getByTestId('catalog-studio-composer-frame');
    result.iframe_src = await frameEl.getAttribute('src').catch(() => null);
    const frame = page.frameLocator('[data-testid="catalog-studio-composer-frame"]');
    try {
      await frame.getByTestId('admin-composer-root').waitFor({ state: 'visible', timeout: 30_000 });
      result.frame_ready = true;
    } catch (err) {
      result.wait_error = String(err && err.message ? err.message : err).slice(0, 400);
      await page.waitForTimeout(2500);
    }
    return result;
  }

  const root = page.getByTestId('admin-composer-root');
  if (await root.isVisible().catch(() => false)) {
    result.full_page_composer = true;
    result.frame_ready = true;
    return result;
  }

  try {
    await page.waitForURL(/\/admin\/categories\/\d+\/composer/, { timeout: 8_000 });
    await page.getByTestId('admin-composer-root').waitFor({ state: 'visible', timeout: 20_000 });
    result.full_page_composer = true;
    result.frame_ready = true;
  } catch (err) {
    result.wait_error = String(err && err.message ? err.message : err).slice(0, 400);
  }
  return result;
}

function composerExtractFn() {
  const visible = (el) => (el && el.innerText ? el.innerText.replace(/\s+/g, ' ').trim() : '');
  const stepRows = Array.from(document.querySelectorAll('[data-testid^="composer-step-row-"]'));
  const steps = stepRows.map((row, index) => {
    const labelEl = row.querySelector('.text-sm.font-semibold, [data-testid^="composer-step-select-"] .truncate');
    const labels = Array.from(row.querySelectorAll('span.block')).map((s) => visible(s)).filter(Boolean);
    const chips = Array.from(row.querySelectorAll('.rounded-full')).map((c) => visible(c)).filter(Boolean);
    return {
      testid: row.getAttribute('data-testid'),
      index,
      label: labels[0] || visible(labelEl),
      source_line: labels[1] || null,
      chips,
      chip_inactive: chips.some((c) => /inactiv/i.test(c)),
      text: visible(row).slice(0, 240),
    };
  });
  const body = document.body ? document.body.innerText : '';
  const vueSteps = [];
  const vueMeta = { found: false, is_published: null };
  const walk = (el) => {
    if (!el || vueMeta.found) return;
    const inst = el.__vueParentComponent || el.__vue__;
    const proxy = inst && (inst.proxy || inst.ctx || inst.setupState || inst);
    if (proxy && Array.isArray(proxy.steps)) {
      vueMeta.found = true;
      vueMeta.is_published = proxy.profile ? proxy.profile.is_published : null;
      vueMeta.pages_count = proxy.steps.length;
      for (const s of proxy.steps) {
        vueSteps.push({
          id: s.id ?? null,
          step_key: s.step_key ?? null,
          label: s.label ?? null,
          source_type: s.source_type ?? null,
          source_ref: s.source_ref ?? null,
          min_select: s.min_select ?? null,
          max_select: s.max_select ?? null,
          is_active: s.is_active ?? null,
          visible_on: s.visible_on ?? null,
        });
      }
      return;
    }
    for (const child of el.children || []) walk(child);
  };
  walk(document.body);

  const pagesHeading = Array.from(document.querySelectorAll('h2, h3, .uppercase, [class*="pages"]'))
    .map((el) => visible(el))
    .find((t) => /^PAGES\b/i.test(t) || /\bPAGES\s+\d+/i.test(t));
  const pagesMatch = body.match(/\bPAGES\s+(\d+)\b/i);

  return {
    url: location.href,
    title: document.title,
    composer_name: visible(document.querySelector('[data-testid="admin-composer-product-name"]')),
    composer_category: visible(document.querySelector('[data-testid="admin-composer-product-category"]')),
    publish_state: visible(document.querySelector('[data-testid="admin-composer-publish-state"]')),
    draft_not_till: visible(document.querySelector('[data-testid="admin-composer-draft-not-till"]')),
    empty_state: visible(document.querySelector('[data-testid="admin-composer-empty-state"]')),
    load_error: visible(document.querySelector('[data-testid="admin-composer-load-error"]')),
    pages_heading: pagesHeading || null,
    pages_count_from_body: pagesMatch ? Number(pagesMatch[1]) : null,
    step_count: steps.length,
    step_labels: steps.map((s) => s.label).filter(Boolean),
    steps,
    vue_steps: vueSteps,
    vue_step_keys: vueSteps.map((s) => s.step_key).filter(Boolean),
    vue_meta: vueMeta,
    body_has_viande_2: /Viande\s*2/i.test(body),
    body_has_viande_3: /Viande\s*3/i.test(body),
    body_has_brouillon: /Brouillon/i.test(body),
    body_has_ajouter_une_page: /Ajouter une page/i.test(body),
    raw_labels: (body.match(/Label\.[A-Za-z0-9_.-]+|kiosk\.[A-Za-z0-9_.-]+|0undefined/g) || []),
    body_snippet: body.replace(/\s+/g, ' ').trim().slice(0, 2200),
  };
}

async function extractComposer(page, waitInfo) {
  if (waitInfo.overlay_visible) {
    const frame = page.frame({ url: /\/admin\/categories\/\d+\/composer/ });
    if (frame) {
      const inner = await frame.evaluate(composerExtractFn).catch((err) => ({ evaluate_error: String(err.message || err) }));
      const overlayMeta = await page.evaluate(() => {
        const overlay = document.querySelector('[data-testid="catalog-studio-composer-overlay"]');
        const iframe = document.querySelector('[data-testid="catalog-studio-composer-frame"]');
        const h3 = overlay ? overlay.querySelector('h3') : null;
        const help = overlay ? overlay.querySelector('.catalog-studio__composer-help') : null;
        return {
          overlay_title: h3 ? String(h3.textContent || '').replace(/\s+/g, ' ').trim() : null,
          overlay_help: help ? String(help.textContent || '').replace(/\s+/g, ' ').trim() : null,
          iframe_src: iframe ? iframe.getAttribute('src') : null,
        };
      });
      return { mode: 'drawer-iframe', ...overlayMeta, inner };
    }
    return { mode: 'drawer-iframe-missing-frame', iframe_src: waitInfo.iframe_src, wait_error: waitInfo.wait_error };
  }
  if (waitInfo.full_page_composer) {
    const inner = await page.evaluate(composerExtractFn);
    return { mode: 'full-page', inner };
  }
  return { mode: 'not-found', wait: waitInfo };
}

async function clickCategory(page, cat) {
  const btn = page.locator(`[data-testid="catalog-studio-category-row-${cat.id}"] button.catalog-studio__category`);
  await btn.scrollIntoViewIfNeeded();
  await btn.click();
  await page.waitForTimeout(900);
}

async function closeComposerOverlay(page) {
  const overlay = page.getByTestId('catalog-studio-composer-overlay');
  if (!(await overlay.isVisible().catch(() => false))) {
    return { closed: true, already_hidden: true };
  }
  const closeBtn = page.getByTestId('catalog-studio-composer-close');
  if (await closeBtn.isVisible().catch(() => false)) {
    await closeBtn.click();
  } else {
    await page.keyboard.press('Escape');
  }
  await overlay.waitFor({ state: 'hidden', timeout: 12_000 }).catch(() => {});
  const still = await overlay.isVisible().catch(() => false);
  return { closed: !still, already_hidden: false };
}

function stepFacts(apiProfile, inner) {
  const apiSteps = (apiProfile && Array.isArray(apiProfile.steps)) ? apiProfile.steps : [];
  const vueSteps = (inner && Array.isArray(inner.vue_steps)) ? inner.vue_steps : [];
  const source = apiSteps.length ? apiSteps : vueSteps;
  const keys = source.map((s) => String(s.step_key || ''));
  const labels = source.map((s) => String(s.label || ''));
  const hay = [...keys, ...labels, inner && inner.body_snippet ? inner.body_snippet : ''].join(' | ').toLowerCase();
  const pagesFromUi = inner && inner.pages_count_from_body != null ? inner.pages_count_from_body : null;
  const pageCount = apiSteps.length
    ? apiSteps.length
    : (vueSteps.length || (inner && inner.step_count) || 0);
  return {
    step_keys: keys,
    step_labels: labels,
    step_count: source.length,
    page_count: pageCount,
    pages_count_from_body: pagesFromUi,
    has_viande_2: keys.map((k) => k.toLowerCase()).includes('viande_2') || /viande\s*2/.test(hay),
    has_viande_3: keys.map((k) => k.toLowerCase()).includes('viande_3') || /viande\s*3/.test(hay),
    body_has_viande_2: !!(inner && inner.body_has_viande_2),
    is_published: apiProfile ? apiProfile.is_published : (inner && inner.vue_meta ? inner.vue_meta.is_published : null),
    template: apiProfile ? apiProfile.template : null,
    publish_state: inner && inner.publish_state ? inner.publish_state : null,
    empty_state: inner && inner.empty_state ? inner.empty_state : null,
    steps: source,
  };
}

async function openCategoryWizard(page) {
  const wizardBtn = page.getByTestId('catalog-studio-category-wizard-button');
  const visible = await wizardBtn.isVisible().catch(() => false);
  if (!visible) {
    return { clicked: false, wait: null, frame: null, button_text: null };
  }
  const buttonText = (await wizardBtn.innerText()).replace(/\s+/g, ' ').trim();
  const profileWait = page.waitForResponse(
    (res) => res.request().method() === 'GET' && /\/admin\/composer\/categories\/\d+\/profile/i.test(res.url()),
    { timeout: 20_000 },
  ).catch(() => null);
  await wizardBtn.click();
  const wait = await waitComposerReady(page);
  await profileWait;
  await page.waitForTimeout(1200);
  const frame = page.frame({ url: /\/admin\/categories\/\d+\/composer/ });
  return { clicked: true, wait, frame, button_text: buttonText };
}

async function main() {
  mkdirp(path.join(ROUND, 'wave-D-sandwichs'));
  mkdirp(path.join(ROUND, 'wave-D-galette'));
  mkdirp(path.join(ROUND, 'wave-D-frites'));
  clearRateLimits();

  const executablePath = resolveChromiumExecutable();
  const launchOpts = {
    headless: true,
    args: ['--disable-dev-shm-usage', '--disk-cache-size=1'],
  };
  if (executablePath) launchOpts.executablePath = executablePath;
  const browser = await chromium.launch(launchOpts);
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    baseURL: BASE,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
  });
  const page = await context.newPage();
  await page.emulateMedia({ reducedMotion: 'reduce' });
  page.on('dialog', async (dialog) => { await dialog.dismiss(); });
  await disableCache(page);
  const rec = attachCollectors(page);
  const states = [];
  const blockers = [];
  const clicks = [];
  const waveResults = {};

  const targets = [
    {
      wave: 'D-sandwichs',
      exactName: /^Sandwichs$/i,
      outDir: path.join(ROUND, 'wave-D-sandwichs'),
      artifact: '02-wizard',
      notesFile: 'wave-D-sandwichs-notes.json',
    },
    {
      wave: 'D-galette',
      exactName: /^Galette$/i,
      outDir: path.join(ROUND, 'wave-D-galette'),
      artifact: '02-wizard',
      notesFile: 'wave-D-galette-notes.json',
    },
    {
      wave: 'D-frites',
      exactName: /^Frites$/i,
      outDir: path.join(ROUND, 'wave-D-frites'),
      artifact: '03-frites-wizard',
      notesFile: 'wave-D-frites-notes.json',
    },
  ];

  try {
    clicks.push({ step: 'goto_login', label: '/login' });
    const loginInfo = await login(page);
    clicks.push({ step: 'login_submit', label: 'Connexion', email: EMAIL, http: loginInfo.status });
    if (!/\/admin\/dashboard/.test(page.url())) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
      clicks.push({ step: 'goto_dashboard', label: '/admin/dashboard' });
    }
    rec.clearStateBuffers();
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await waitDashboardReady(page);
    clicks.push({ step: 'dashboard_ready', label: 'Ventes du jour / Accès rapides', url: page.url() });

    const catalogueLink = page.locator('nav[aria-label*="Accès" i] a, nav[aria-label*="quick" i] a').filter({ hasText: /^Catalogue$/i }).first();
    if (!(await catalogueLink.count())) {
      throw new Error('Accès rapides « Catalogue » not found — aborting (did not click POS).');
    }
    await catalogueLink.scrollIntoViewIfNeeded();
    await catalogueLink.click();
    clicks.push({ step: 'click_acces_catalogue', label: 'Catalogue' });
    await page.waitForURL(/\/admin\/items\/studio/, { timeout: 25_000 });
    await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 30_000 });
    await page.locator('[data-testid^="catalog-studio-category-row-"]').first().waitFor({ state: 'visible', timeout: 25_000 });
    await page.waitForTimeout(800);

    const categories = await listCategories(page);

    for (const target of targets) {
      const chosen = categories.find((c) => target.exactName.test(c.name)) || null;
      if (!chosen) {
        blockers.push(`Category « ${target.wave} » exact name not found. Did not invent a product / click POS.`);
        waveResults[target.wave] = { chosen: null, step_keys: [], blockers: true };
        continue;
      }

      rec.clearStateBuffers();
      await clickCategory(page, chosen);
      clicks.push({
        step: `click_category_${target.wave}`,
        label: chosen.name,
        testid: chosen.testid,
        count_text: chosen.count_text,
      });
      await page.getByTestId('catalog-studio-category-wizard-entry').waitFor({ state: 'visible', timeout: 12_000 }).catch(() => {});
      await page.waitForTimeout(400);

      const opened = await openCategoryWizard(page);
      if (!opened.clicked) {
        blockers.push(`${chosen.name}: « Wizard de la catégorie » not visible — skipped ${target.artifact}.`);
        const missBuffers = rec.snapshotBuffers();
        const missQuartet = await snapQuartet(page, target.outDir, target.artifact, missBuffers);
        states.push({
          name: `${target.wave}-wizard`,
          url: missQuartet.url,
          png: missQuartet.png,
          dom: missQuartet.dom,
          console: missQuartet.console,
          network: missQuartet.network,
          notes: `${chosen.name} selected from Dashboard Catalogue but Wizard button missing. Captured studio as fallback. Did not publish / POS.`,
        });
        waveResults[target.wave] = {
          chosen,
          wizard_clicked: false,
          step_keys: [],
          step_labels: [],
          facts: null,
        };
        continue;
      }

      clicks.push({
        step: `click_wizard_${target.wave}`,
        label: opened.button_text,
        category: chosen.name,
      });
      const wizardObs = await extractComposer(page, opened.wait);
      const inner = wizardObs && wizardObs.inner ? wizardObs.inner : {};
      const apiProfile = rec.profileBodies.filter((p) => String(p.category_id) === String(chosen.id)).pop()
        || rec.profileBodies[rec.profileBodies.length - 1]
        || null;
      const facts = stepFacts(apiProfile, inner);
      const wizBuffers = rec.snapshotBuffers();
      const wizQuartet = await snapQuartet(page, target.outDir, target.artifact, wizBuffers, {
        frame: opened.frame,
      });
      const notes = `From Dashboard Accès rapides Catalogue, clicked « ${chosen.name} » then « Wizard de la catégorie ». Overlay=${opened.wait.overlay_visible} iframe_src=${opened.wait.iframe_src || ''}. step_keys=${JSON.stringify(facts.step_keys)} page_count=${facts.page_count} has_viande_2=${facts.has_viande_2}. Did not publish / create / flip flags / POS.`;
      states.push({
        name: `${target.wave}-wizard`,
        url: wizQuartet.url,
        png: wizQuartet.png,
        dom: wizQuartet.dom,
        console: wizQuartet.console,
        network: wizQuartet.network,
        notes,
      });

      const waveNotes = {
        wave: target.wave,
        round: 5,
        goal: 'W3-recapture',
        base_url: BASE,
        login: loginInfo,
        dashboard_to_catalogue: true,
        pos_clicked: false,
        published: false,
        products_created: false,
        flags_flipped: false,
        chosen_category: chosen,
        wizard_clicked: true,
        wizard_wait: opened.wait,
        composer: wizardObs,
        api_profile: apiProfile,
        facts,
        step_keys: facts.step_keys,
        step_labels: facts.step_labels,
        page_count: facts.page_count,
        has_viande_2: facts.has_viande_2,
        has_viande_3: facts.has_viande_3,
        states: [states[states.length - 1]],
        blockers: [],
        final_url: page.url(),
      };
      fs.writeFileSync(path.join(target.outDir, target.notesFile), JSON.stringify(waveNotes, null, 2), 'utf8');
      fs.copyFileSync(
        path.join(ROUND, '_capture-w3-sandwichs-galette-frites.mjs'),
        path.join(target.outDir, '_capture.mjs'),
      );
      waveResults[target.wave] = {
        chosen,
        wizard_clicked: true,
        overlay_title: wizardObs.overlay_title || null,
        step_keys: facts.step_keys,
        step_labels: facts.step_labels,
        page_count: facts.page_count,
        has_viande_2: facts.has_viande_2,
        has_viande_3: facts.has_viande_3,
        publish_state: facts.publish_state,
        template: facts.template,
        png: wizQuartet.png,
      };

      const closed = await closeComposerOverlay(page);
      clicks.push({ step: `close_wizard_${target.wave}`, ...closed });
      if (!closed.closed) {
        blockers.push(`${chosen.name} wizard overlay did not close; returning to studio without POS.`);
        await page.goto(`${BASE}/admin/items/studio`, { waitUntil: 'domcontentloaded', timeout: 25_000 });
        await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 25_000 });
      }
      await page.waitForTimeout(400);
    }

    const relevant4xx = rec.allNetwork.filter((n) => !isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const mutatingAfterLogin = rec.mutations.filter((m) => !/\/api\/auth\/(login|authcheck|logout)/i.test(m.url));
    const summary = {
      wave: 'W3-recapture-D-sandwichs-galette-frites',
      base_url: BASE,
      chromium: executablePath,
      login: { status: 201 },
      pos_clicked: false,
      published: false,
      products_created: false,
      sandwichs: waveResults['D-sandwichs'] || null,
      galette: waveResults['D-galette'] || null,
      frites: waveResults['D-frites'] || null,
      states,
      clicks,
      relevant4xx_count: relevant4xx.length,
      mutating_after_login: mutatingAfterLogin,
      blockers,
    };
    fs.writeFileSync(path.join(ROUND, 'wave-W3-recapture-notes.json'), JSON.stringify(summary, null, 2), 'utf8');
    console.log(JSON.stringify(summary, null, 2));
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
