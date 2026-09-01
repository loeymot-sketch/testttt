/**
 * Round-4 Wave D — Bols wizard + Frites rail FROM DASHBOARD.
 * Isolated Playwright CLI (no MCP, no project globalSetup).
 * No publish, no product create, no flag flips, no POS click.
 *
 * Flow:
 *   Dashboard → Catalogue → Bols → Wizard → 01-bols-wizard
 *   Close overlay → Frites → 02-frites-rail
 *   If « Wizard de la catégorie » exists → 03-frites-wizard else NO WIZARD
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
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-cockpit-10j/round-4/wave-D-bols-frites');
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
    const bar = document.querySelector('.phpdebugbar, #phpdebugbar, .sf-toolbar, .phpdebugbar-openhandler');
    if (bar) bar.style.setProperty('display', 'none', 'important');
    document.querySelectorAll('.phpdebugbar, #phpdebugbar, .sf-toolbar, .phpdebugbar-openhandler').forEach((el) => {
      el.style.setProperty('display', 'none', 'important');
    });
    document.documentElement.classList.remove('phpdebugbar-shown');
    document.body.style.setProperty('padding-bottom', '0', 'important');
    document.body.style.setProperty('margin-bottom', '0', 'important');
  }).catch(() => {});
}

async function expandInnerOverflow(target) {
  await target.evaluate(() => {
    const nodes = Array.from(document.querySelectorAll('html, body, main, #app, .db-main, .db-container, .db-content, [class*="db-"]'));
    const extras = Array.from(document.querySelectorAll('body *')).filter((el) => {
      const s = window.getComputedStyle(el);
      const oy = s.overflowY;
      return (oy === 'auto' || oy === 'scroll' || s.overflow === 'auto' || s.overflow === 'scroll')
        && el.scrollHeight > el.clientHeight + 20;
    });
    const seen = new Set();
    for (const el of [...nodes, ...extras]) {
      if (!el || seen.has(el)) continue;
      seen.add(el);
      el.style.setProperty('overflow', 'visible', 'important');
      el.style.setProperty('overflow-y', 'visible', 'important');
      el.style.setProperty('height', 'auto', 'important');
      el.style.setProperty('max-height', 'none', 'important');
    }
    document.documentElement.style.setProperty('height', 'auto', 'important');
    document.body.style.setProperty('height', 'auto', 'important');
  });
}

async function snapQuartet(page, name, buffers, { expand = true, frame = null } = {}) {
  await hideDebugbar(page);
  if (frame) await hideDebugbar(frame);
  if (expand) {
    await expandInnerOverflow(page);
    if (frame) {
      await expandInnerOverflow(frame).catch(() => {});
    }
    await page.waitForTimeout(250);
  }
  const png = path.join(OUT, `${name}.png`);
  const dom = path.join(OUT, `${name}.dom.html`);
  const consolePath = path.join(OUT, `${name}.console.json`);
  const networkPath = path.join(OUT, `${name}.network.json`);

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

function rawLabelHits(text) {
  const body = String(text || '');
  const hits = [];
  const re = /(Label\.[A-Za-z0-9_.-]+|kiosk\.[A-Za-z0-9_.-]+|studio\.[A-Za-z0-9_.-]+|0undefined|undefined\b|\[object Object\])/g;
  let m;
  while ((m = re.exec(body))) {
    hits.push(m[1]);
    if (hits.length >= 40) break;
  }
  return [...new Set(hits)];
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

async function extractRail(page) {
  return page.evaluate(() => {
    const visible = (el) => (el && el.innerText ? el.innerText.replace(/\s+/g, ' ').trim() : '');
    const wizardEntry = document.querySelector('[data-testid="catalog-studio-category-wizard-entry"]');
    const wizardBtn = document.querySelector('[data-testid="catalog-studio-category-wizard-button"]');
    const empty = document.querySelector('.catalog-studio__empty');
    const products = Array.from(document.querySelectorAll('article.catalog-studio__product')).map((card) => {
      const img = card.querySelector('img.catalog-studio__product-thumb');
      return {
        name: visible(card.querySelector('h4')),
        category: visible(card.querySelector('p')),
        meta: visible(card.querySelector('.catalog-studio__product-meta')),
        photo_src: img ? (img.getAttribute('src') || '') : '',
        photo_alt: img ? (img.getAttribute('alt') || '') : '',
        has_photo: !!(img && img.getAttribute('src')),
      };
    });
    const activeCat = document.querySelector('button.catalog-studio__category--active');
    return {
      url: location.href,
      title: document.title,
      active_category: activeCat ? visible(activeCat) : null,
      wizard_entry_visible: !!(wizardEntry && wizardEntry.offsetParent !== null),
      wizard_button_present: !!wizardBtn,
      wizard_button_visible: !!(wizardBtn && wizardBtn.offsetParent !== null),
      wizard_button_text: wizardBtn ? visible(wizardBtn) : null,
      wizard_hint: wizardEntry ? visible(wizardEntry.querySelector('small')) : null,
      empty_state: empty ? visible(empty) : null,
      product_count: products.length,
      products,
      body_snippet: visible(document.querySelector('.catalog-studio')).slice(0, 1400),
      raw_labels: (document.body.innerText.match(/Label\.[A-Za-z0-9_.-]+|kiosk\.[A-Za-z0-9_.-]+|0undefined/g) || []),
    };
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
  const photo = document.querySelector('[data-testid="admin-composer-product-photo"]');
  const photoImg = photo && photo.tagName === 'IMG' ? photo : (photo ? photo.querySelector('img') : null);
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
      inactive_chip: chips.some((c) => /inactiv/i.test(c)),
      text: visible(row).slice(0, 240),
    };
  });
  const sourceRef = document.querySelector('[data-testid="composer-step-source-ref"]');
  const sourceEmpty = document.querySelector('[data-testid="composer-step-source-empty"]');
  const sourceType = document.querySelector('[data-testid="composer-step-source-type"]');
  const labelInput = document.querySelector('[data-testid="composer-step-label-input"]');
  const selectedOption = (sel) => {
    if (!sel) return null;
    const opt = sel.options && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
    const options = sel.options
      ? Array.from(sel.options).slice(0, 80).map((o) => ({
        value: o.value,
        label: String(o.textContent || '').replace(/\s+/g, ' ').trim(),
      }))
      : [];
    return {
      value: sel.value,
      label: opt ? String(opt.textContent || '').replace(/\s+/g, ' ').trim() : '',
      empty_value: sel.value === '' || sel.value == null,
      options,
    };
  };
  const body = document.body ? document.body.innerText : '';
  const vueSteps = [];
  const vueMeta = { found: false, branch_id_scope: null, is_published: null, branches: [] };
  const walk = (el) => {
    if (!el || vueMeta.found) return;
    const inst = el.__vueParentComponent || el.__vue__;
    const proxy = inst && (inst.proxy || inst.ctx || inst.setupState || inst);
    if (proxy && Array.isArray(proxy.steps) && proxy.steps.length) {
      vueMeta.found = true;
      vueMeta.branch_id_scope = proxy.branchIdScope ?? proxy.branch_id_scope ?? null;
      vueMeta.is_published = proxy.profile ? proxy.profile.is_published : null;
      vueMeta.branches = Array.isArray(proxy.branches)
        ? proxy.branches.map((b) => ({ id: b.id, name: b.name }))
        : [];
      vueMeta.preview_branches = Array.isArray(proxy.previewBranches)
        ? proxy.previewBranches.map((b) => ({ id: b.id, name: b.name }))
        : [];
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

  const branchScope = document.querySelector('[data-testid="admin-composer-branch-scope"]');
  const previewBranch = document.querySelector('[data-testid="admin-item-preview-branch-select"]');
  const previewEmpty = document.querySelector('[data-testid="admin-composer-preview-empty"]');
  const livePreview = document.querySelector('[data-testid="admin-composer-live-preview"], [data-testid="admin-item-preview"]');
  const previewPosSteps = Array.from(document.querySelectorAll('[data-testid="admin-item-preview-pos"] li .font-medium')).map((el) => visible(el));
  const previewKioskSteps = Array.from(document.querySelectorAll('[data-testid="admin-item-preview-kiosk"] li .font-medium')).map((el) => visible(el));
  const publishBtn = document.querySelector('[data-testid="admin-composer-publish"], button');

  return {
    url: location.href,
    title: document.title,
    composer_name: visible(document.querySelector('[data-testid="admin-composer-product-name"]')),
    composer_category: visible(document.querySelector('[data-testid="admin-composer-product-category"]')),
    publish_state: visible(document.querySelector('[data-testid="admin-composer-publish-state"]')),
    draft_not_till: visible(document.querySelector('[data-testid="admin-composer-draft-not-till"]')),
    empty_state: visible(document.querySelector('[data-testid="admin-composer-empty-state"]')),
    load_error: visible(document.querySelector('[data-testid="admin-composer-load-error"]')),
    step_count: steps.length,
    step_labels: steps.map((s) => s.label).filter(Boolean),
    steps,
    vue_steps: vueSteps,
    vue_step_keys: vueSteps.map((s) => s.step_key).filter(Boolean),
    vue_meta: vueMeta,
    branch_scope: selectedOption(branchScope),
    preview_branch: selectedOption(previewBranch),
    preview_empty: previewEmpty ? visible(previewEmpty) : null,
    live_preview_visible: !!(livePreview && livePreview.offsetParent !== null),
    preview_pos_step_labels: previewPosSteps,
    preview_kiosk_step_labels: previewKioskSteps,
    body_has_collier: /Collier/i.test(body),
    body_has_le_cayenne: /Le Cayenne/i.test(body),
    body_has_sauce_bol: /Sauce bol/i.test(body),
    body_has_supplement_bol: /supplement_bol|Supplément bol|Supplement bol/i.test(body),
    source_ref: selectedOption(sourceRef),
    source_type: selectedOption(sourceType),
    source_empty_message: sourceEmpty ? visible(sourceEmpty) : null,
    current_step_label_input: labelInput ? labelInput.value : null,
    photo: {
      tag: photo ? photo.tagName : null,
      is_img: !!(photoImg && photoImg.tagName === 'IMG'),
      src: photoImg && photoImg.tagName === 'IMG' ? (photoImg.getAttribute('src') || '') : '',
      alt: photoImg && photoImg.tagName === 'IMG' ? (photoImg.getAttribute('alt') || '') : '',
      fallback_text: photo && photo.tagName !== 'IMG' ? visible(photo) : null,
    },
    raw_labels: (body.match(/Label\.[A-Za-z0-9_.-]+|kiosk\.[A-Za-z0-9_.-]+|0undefined/g) || []),
    body_has_source_ref_human: /Limiter à un groupe précis|source_ref/i.test(body),
    body_has_toutes_les_options: /Toutes les options/i.test(body),
    publish_button_visible: !!(publishBtn && /publier/i.test(visible(publishBtn))),
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
          overlay_text: overlay ? String(overlay.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 800) : null,
        };
      });
      return { mode: 'drawer-iframe', ...overlayMeta, inner };
    }
    return {
      mode: 'drawer-iframe-missing-frame',
      iframe_src: waitInfo.iframe_src,
      wait_error: waitInfo.wait_error,
    };
  }
  if (waitInfo.full_page_composer) {
    const inner = await page.evaluate(composerExtractFn);
    return { mode: 'full-page', inner };
  }
  return { mode: 'not-found', wait: waitInfo };
}

async function inspectComposerSteps(page, waitInfo) {
  const frame = waitInfo.overlay_visible
    ? page.frame({ url: /\/admin\/categories\/\d+\/composer/ })
    : null;
  const target = frame || (waitInfo.full_page_composer ? page : null);
  if (!target) return { error: 'no composer target', count: 0, inspections: [] };
  try {
    await target.evaluate(() => {
      const nodes = Array.from(document.querySelectorAll('html, body, [data-testid="admin-composer-root"], body *')).filter((el) => {
        const s = window.getComputedStyle(el);
        const oy = s.overflowY;
        return (oy === 'auto' || oy === 'scroll' || s.overflow === 'auto' || s.overflow === 'scroll')
          && el.scrollHeight > el.clientHeight + 8;
      });
      for (const el of nodes) {
        el.style.setProperty('overflow', 'visible', 'important');
        el.style.setProperty('max-height', 'none', 'important');
        el.style.setProperty('height', 'auto', 'important');
      }
    }).catch(() => {});
    const n = await target.locator('[data-testid^="composer-step-row-"]').count();
    const inspections = [];
    for (let i = 0; i < n; i++) {
      await target.evaluate((idx) => {
        const rows = Array.from(document.querySelectorAll('[data-testid^="composer-step-row-"]'));
        const row = rows[idx];
        if (!row) return;
        row.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        row.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
      }, i);
      await page.waitForTimeout(300);
      const detail = await target.evaluate(composerExtractFn).catch((err) => ({ evaluate_error: String(err.message || err) }));
      const vueStep = Array.isArray(detail.vue_steps) ? (detail.vue_steps[i] || null) : null;
      inspections.push({
        index: i,
        row: Array.isArray(detail.steps) ? (detail.steps[i] || null) : null,
        current_step_label_input: detail.current_step_label_input || null,
        source_type: detail.source_type || null,
        source_ref: detail.source_ref
          ? { value: detail.source_ref.value, label: detail.source_ref.label, empty_value: detail.source_ref.empty_value }
          : null,
        vue_step: vueStep,
      });
    }
    return { count: n, inspections };
  } catch (err) {
    return {
      error: String(err && err.message ? err.message : err).slice(0, 500),
      count: 0,
      inspections: [],
    };
  }
}

function bolsVerdict(apiProfile, inner, inspections) {
  const vueSteps = (inner && Array.isArray(inner.vue_steps)) ? inner.vue_steps : [];
  const apiSteps = (apiProfile && Array.isArray(apiProfile.steps)) ? apiProfile.steps : [];
  const steps = apiSteps.length ? apiSteps : vueSteps;
  const hay = JSON.stringify({
    steps,
    inspections,
    labels: inner ? inner.step_labels : [],
    body: inner ? inner.body_snippet : '',
  }).toLowerCase();

  const findStep = (pred) => steps.find(pred) || null;
  const viande = findStep((s) => String(s.step_key || '').toLowerCase() === 'viande' || /viande/i.test(String(s.label || '')));
  const pain = findStep((s) => String(s.step_key || '').toLowerCase() === 'pain' || /pain/i.test(String(s.label || '')));
  const sauce = findStep((s) => String(s.step_key || '').toLowerCase() === 'sauce' || /sauce/i.test(String(s.label || '')));
  const supplements = findStep((s) => /supplement/i.test(String(s.step_key || '')) || /suppl/i.test(String(s.label || '')));

  const sauceRef = [
    sauce && sauce.source_ref,
    inspections && inspections.find((i) => /sauce/i.test(JSON.stringify(i)))?.source_ref?.label,
    inspections && inspections.find((i) => /sauce/i.test(JSON.stringify(i)))?.source_ref?.value,
    inspections && inspections.find((i) => /sauce/i.test(JSON.stringify(i)))?.vue_step?.source_ref,
  ].filter(Boolean).join(' ');

  const supplementRef = [
    supplements && supplements.source_ref,
    inspections && inspections.find((i) => /suppl/i.test(JSON.stringify(i)))?.source_ref?.label,
    inspections && inspections.find((i) => /suppl/i.test(JSON.stringify(i)))?.source_ref?.value,
    inspections && inspections.find((i) => /suppl/i.test(JSON.stringify(i)))?.vue_step?.source_ref,
  ].filter(Boolean).join(' ');

  return {
    step_count: steps.length,
    step_keys: steps.map((s) => s.step_key),
    step_labels: steps.map((s) => s.label),
    steps_compact: steps.map((s) => ({
      step_key: s.step_key,
      label: s.label,
      source_type: s.source_type,
      source_ref: s.source_ref,
      is_active: s.is_active,
    })),
    has_sauce_bol: /sauce\s*bol/i.test(sauceRef) || /sauce\s*bol/i.test(hay),
    sauce_source_ref: sauce ? sauce.source_ref : null,
    has_supplement_bol: /supplement_bol|suppl[ée]ment\s*bol/i.test(supplementRef) || /supplement_bol/i.test(hay),
    supplement_source_ref: supplements ? supplements.source_ref : null,
    viande_present: !!viande,
    viande_inactive: viande ? viande.is_active === false : null,
    viande_is_active: viande ? viande.is_active : null,
    pain_present: !!pain,
    pain_inactive: pain ? pain.is_active === false : null,
    pain_is_active: pain ? pain.is_active : null,
    inactive_steps: steps.filter((s) => s.is_active === false).map((s) => ({
      step_key: s.step_key,
      label: s.label,
      source_ref: s.source_ref,
    })),
    looks_like_tacos_clone: steps.some((s) => /viande_2|viande_3/.test(String(s.step_key || ''))),
  };
}

async function clickCategory(page, cat) {
  const btn = page.locator(`[data-testid="catalog-studio-category-row-${cat.id}"] button.catalog-studio__category`);
  await btn.scrollIntoViewIfNeeded();
  await btn.click();
  await page.waitForTimeout(900);
}

async function openCategoryWizard(page) {
  const wizardBtn = page.getByTestId('catalog-studio-category-wizard-button');
  const visible = await wizardBtn.isVisible().catch(() => false);
  if (!visible) {
    return { clicked: false, wait: null, frame: null };
  }
  await wizardBtn.click();
  const wait = await waitComposerReady(page);
  await page.waitForTimeout(1500);
  const frame = page.frame({ url: /\/admin\/categories\/\d+\/composer/ });
  return { clicked: true, wait, frame };
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

async function main() {
  mkdirp(OUT);
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
  let categories = [];
  let bolsCat = null;
  let fritesCat = null;
  let bolsRail = null;
  let fritesRail = null;
  let bolsWizard = null;
  let fritesWizard = null;
  let bolsWait = null;
  let fritesWait = null;
  let bolsInspections = null;
  let fritesInspections = null;
  let fritesHasWizard = null;
  let closeInfo = null;

  try {
    const loginInfo = await login(page);
    if (!/\/admin\/dashboard/.test(page.url())) {
      await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    }
    rec.clearStateBuffers();
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {});
    await waitDashboardReady(page);

    const catalogueLink = page.locator('nav[aria-label*="Accès" i] a, nav[aria-label*="quick" i] a').filter({ hasText: /^Catalogue$/i }).first();
    if (!(await catalogueLink.count())) {
      throw new Error('Accès rapides « Catalogue » not found — aborting Wave D Bols+Frites (did not click POS).');
    }
    await catalogueLink.scrollIntoViewIfNeeded();
    await catalogueLink.click();
    await page.waitForURL(/\/admin\/items\/studio/, { timeout: 25_000 });
    await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 30_000 });
    await page.locator('[data-testid^="catalog-studio-category-row-"]').first().waitFor({ state: 'visible', timeout: 25_000 });
    await page.waitForTimeout(800);

    categories = await listCategories(page);
    bolsCat = categories.find((c) => /^Bols$/i.test(c.name)) || null;
    fritesCat = categories.find((c) => /^Frites$/i.test(c.name)) || null;
    if (!bolsCat) blockers.push('Category « Bols » not found (exact name).');
    if (!fritesCat) blockers.push('Category « Frites » not found (exact name).');

    if (bolsCat) {
      await clickCategory(page, bolsCat);
      await page.getByTestId('catalog-studio-category-wizard-entry').waitFor({ state: 'visible', timeout: 12_000 }).catch(() => {});
      bolsRail = await extractRail(page);

      rec.clearStateBuffers();
      const opened = await openCategoryWizard(page);
      bolsWait = opened.wait;
      if (!opened.clicked) {
        blockers.push('Bols: « Wizard de la catégorie » button not visible — skipped 01-bols-wizard.');
        const missBuffers = rec.snapshotBuffers();
        const missQuartet = await snapQuartet(page, '01-bols-wizard', missBuffers, { expand: true });
        states.push({
          name: 'bols-wizard',
          url: missQuartet.url,
          png: missQuartet.png,
          dom: missQuartet.dom,
          console: missQuartet.console,
          network: missQuartet.network,
          notes: 'Bols selected but Wizard button missing. Captured studio rail as fallback. Did not publish / POS.',
        });
      } else {
        bolsWizard = await extractComposer(page, bolsWait);
        const wizBuffers = rec.snapshotBuffers();
        const wizQuartet = await snapQuartet(page, '01-bols-wizard', wizBuffers, {
          expand: false,
          frame: opened.frame,
        });
        states.push({
          name: 'bols-wizard',
          url: wizQuartet.url,
          png: wizQuartet.png,
          dom: wizQuartet.dom,
          console: wizQuartet.console,
          network: wizQuartet.network,
          notes: `Clicked Bols then « Wizard de la catégorie ». Overlay=${bolsWait.overlay_visible} iframe_src=${bolsWait.iframe_src || ''} full_page=${bolsWait.full_page_composer}. Did not publish / create / flip flags / POS.`,
        });
        bolsInspections = await inspectComposerSteps(page, bolsWait);
        closeInfo = await closeComposerOverlay(page);
        if (!closeInfo.closed) {
          blockers.push('Bols wizard overlay did not close; retrying studio navigation without POS.');
          await page.goto(`${BASE}/admin/items/studio`, { waitUntil: 'domcontentloaded', timeout: 25_000 });
          await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 25_000 });
        }
        await page.waitForTimeout(500);
      }
    }

    if (fritesCat) {
      rec.clearStateBuffers();
      await clickCategory(page, fritesCat);
      await page.waitForTimeout(700);
      fritesRail = await extractRail(page);
      fritesHasWizard = !!(fritesRail && (fritesRail.wizard_button_visible || fritesRail.wizard_entry_visible));
      const railBuffers = rec.snapshotBuffers();
      const railQuartet = await snapQuartet(page, '02-frites-rail', railBuffers, { expand: true });
      states.push({
        name: 'frites-rail',
        url: railQuartet.url,
        png: railQuartet.png,
        dom: railQuartet.dom,
        console: railQuartet.console,
        network: railQuartet.network,
        notes: `Back/studio then clicked Frites. wizard_button_visible=${fritesRail ? fritesRail.wizard_button_visible : null} product_cards=${fritesRail ? fritesRail.product_count : 0}.`,
      });

      const wizardBtn = page.getByTestId('catalog-studio-category-wizard-button');
      const wizVisible = await wizardBtn.isVisible().catch(() => false);
      fritesHasWizard = wizVisible;
      if (wizVisible) {
        rec.clearStateBuffers();
        const opened = await openCategoryWizard(page);
        fritesWait = opened.wait;
        if (opened.clicked) {
          fritesWizard = await extractComposer(page, fritesWait);
          fritesInspections = await inspectComposerSteps(page, fritesWait);
          const wizBuffers = rec.snapshotBuffers();
          const wizQuartet = await snapQuartet(page, '03-frites-wizard', wizBuffers, {
            expand: false,
            frame: opened.frame,
          });
          states.push({
            name: 'frites-wizard',
            url: wizQuartet.url,
            png: wizQuartet.png,
            dom: wizQuartet.dom,
            console: wizQuartet.console,
            network: wizQuartet.network,
            notes: `Frites HAS wizard. Clicked « Wizard de la catégorie ». Overlay=${fritesWait.overlay_visible} iframe_src=${fritesWait.iframe_src || ''}. Did not publish.`,
          });
        }
      } else {
        blockers.push('Frites: NO WIZARD — « Wizard de la catégorie » not visible after Frites select. Skipped 03-frites-wizard.');
      }
    }

    const relevant4xx = rec.allNetwork.filter((n) => !isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const ignored4xx = rec.allNetwork.filter((n) => isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const mutatingAfterLogin = rec.mutations.filter((m) => !/\/api\/auth\/(login|authcheck|logout)/i.test(m.url));
    const bolsInner = bolsWizard && bolsWizard.inner ? bolsWizard.inner : {};
    const fritesInner = fritesWizard && fritesWizard.inner ? fritesWizard.inner : {};
    const bolsProfile = rec.profileBodies.find((p) => String(p.category_id) === String(bolsCat && bolsCat.id))
      || rec.profileBodies[0]
      || null;
    const fritesProfile = rec.profileBodies.find((p) => String(p.category_id) === String(fritesCat && fritesCat.id)) || null;
    const verdict = bolsVerdict(bolsProfile, bolsInner, bolsInspections ? bolsInspections.inspections : []);

    const notes = {
      wave: 'D-bols-frites-from-dashboard-round-4',
      base_url: BASE,
      login: loginInfo,
      dashboard_to_catalogue: true,
      pos_clicked: false,
      published: false,
      products_created: false,
      flags_flipped: false,
      categories,
      bols_category: bolsCat,
      frites_category: fritesCat,
      bols_rail: bolsRail,
      frites_rail: fritesRail,
      bols_wizard_wait: bolsWait,
      frites_wizard_wait: fritesWait,
      bols_composer: bolsWizard,
      frites_composer: fritesWizard,
      bols_step_inspections: bolsInspections,
      frites_step_inspections: fritesInspections,
      bols_api_profile: bolsProfile,
      frites_api_profile: fritesProfile,
      bols_verdict: verdict,
      frites_has_wizard: fritesHasWizard,
      frites_wizard_note: fritesHasWizard ? 'YES_WIZARD' : 'NO_WIZARD',
      close_overlay: closeInfo,
      photos: {
        bols_rail: (bolsRail && bolsRail.products) ? bolsRail.products.map((p) => ({ name: p.name, src: p.photo_src, has_photo: p.has_photo })) : [],
        frites_rail: (fritesRail && fritesRail.products) ? fritesRail.products.map((p) => ({ name: p.name, src: p.photo_src, has_photo: p.has_photo })) : [],
        bols_composer: bolsInner.photo || null,
        frites_composer: fritesInner.photo || null,
      },
      raw_labels: {
        bols_rail: bolsRail ? bolsRail.raw_labels : [],
        frites_rail: fritesRail ? fritesRail.raw_labels : [],
        bols_composer: bolsInner.raw_labels || [],
        frites_composer: fritesInner.raw_labels || [],
        parent_dom: rawLabelHits(await page.content()),
      },
      http_4xx_5xx_excluding_ws_debugbar_english_storage: relevant4xx,
      http_4xx_ignored_debugbar_english_storage: ignored4xx.map((n) => ({ url: n.url, status: n.status })),
      mutating_requests_after_login_excluding_auth: mutatingAfterLogin,
      states,
      blockers,
      final_url: page.url(),
    };
    fs.writeFileSync(path.join(OUT, 'wave-D-bols-frites-notes.json'), JSON.stringify(notes, null, 2), 'utf8');
    console.log(JSON.stringify({
      wave: notes.wave,
      base_url: BASE,
      chromium: executablePath,
      png_bols_wizard: states.find((s) => s.name === 'bols-wizard') ? states.find((s) => s.name === 'bols-wizard').png : null,
      png_frites_rail: states.find((s) => s.name === 'frites-rail') ? states.find((s) => s.name === 'frites-rail').png : null,
      png_frites_wizard: states.find((s) => s.name === 'frites-wizard') ? states.find((s) => s.name === 'frites-wizard').png : null,
      bols_category: bolsCat,
      frites_category: fritesCat,
      bols_verdict: verdict,
      frites_has_wizard: fritesHasWizard,
      frites_wizard_note: notes.frites_wizard_note,
      bols_step_labels: bolsInner.step_labels || (bolsProfile ? bolsProfile.steps.map((s) => s.label) : []),
      bols_products: bolsRail ? bolsRail.products.map((p) => p.name) : [],
      frites_products: fritesRail ? fritesRail.products.map((p) => p.name) : [],
      relevant4xx_count: relevant4xx.length,
      mutating_after_login: mutatingAfterLogin,
      blockers,
    }, null, 2));
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
