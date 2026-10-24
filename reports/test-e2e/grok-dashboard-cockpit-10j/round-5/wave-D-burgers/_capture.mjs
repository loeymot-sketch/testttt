/**
 * Round-5 Wave D — Burgers wizard AFTER Mix admin-shell.57f379b6.js (GOAL W1).
 * Isolated Playwright CLI (no MCP, no project globalSetup).
 * Flow: Dashboard → Catalogue → Burgers → Wizard de la catégorie → HARD RELOAD.
 * Do not publish, do not create products, do not flip flags, do not open POS.
 * Record: Filiale name, Caisse/Borne Disponible|Indisponible, preview item name.
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
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-cockpit-10j/round-5/wave-D-burgers');
const EMAIL = 'admin@lecayenne.fr';
const PASSWORD = '123456';
const EXPECTED_MIX = 'admin-shell.57f379b6.js';

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
  const mixHits = [];
  const projectionBodies = [];

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
    if (/admin-shell\.[a-f0-9]+\.js/i.test(url)) {
      mixHits.push({ url: String(url).substring(0, 500), status, ts: Date.now() });
    }

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
          is_published: data ? data.is_published : null,
          template: data ? data.template : null,
          branch_id_scope: data ? data.branch_id_scope : null,
          item_id: data ? (data.item_id ?? null) : null,
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
    if (
      method === 'GET'
      && status >= 200
      && status < 300
      && /\/admin\/menu-projection/i.test(url)
    ) {
      const reqUrl = String(url);
      resp.json().then((body) => {
        const cats = Array.isArray(body && body.categories) ? body.categories : [];
        const itemNames = [];
        for (const c of cats) {
          for (const it of (c && c.items) || []) {
            if (it && it.name) itemNames.push(String(it.name));
          }
        }
        projectionBodies.push({
          url: reqUrl.substring(0, 500),
          channel: (reqUrl.match(/channel=([^&]+)/) || [])[1] || null,
          branch_id: (reqUrl.match(/branch_id=([^&]+)/) || [])[1] || null,
          category_count: cats.length,
          item_count: itemNames.length,
          sample_item_names: itemNames.slice(0, 12),
          ts: Date.now(),
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

  return {
    consoleBuf,
    networkBuf,
    allNetwork,
    mutations,
    profileBodies,
    mixHits,
    projectionBodies,
    snapshotBuffers,
    clearStateBuffers,
  };
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
    if (frame) await expandInnerOverflow(frame).catch(() => {});
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
        iframe.style.setProperty('height', '2600px', 'important');
        iframe.style.setProperty('min-height', '2600px', 'important');
        iframe.style.setProperty('width', '100%', 'important');
      }
      if (overlay) {
        overlay.style.setProperty('overflow', 'visible', 'important');
        overlay.style.setProperty('max-height', 'none', 'important');
      }
    }).catch(() => {});
    await page.waitForTimeout(250);
    await page.screenshot({ path: png, fullPage: true });
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

function w1ExtractFn() {
  const visible = (el) => (el && el.innerText ? el.innerText.replace(/\s+/g, ' ').trim() : '');
  const selectedOption = (sel) => {
    if (!sel) return null;
    const opt = sel.options && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
    return {
      value: sel.value,
      label: opt ? String(opt.textContent || '').replace(/\s+/g, ' ').trim() : '',
      empty_value: sel.value === '' || sel.value == null,
      options: sel.options
        ? Array.from(sel.options).map((o) => ({
          value: o.value,
          label: String(o.textContent || '').replace(/\s+/g, ' ').trim(),
        }))
        : [],
    };
  };

  const mixScripts = Array.from(document.querySelectorAll('script[src]'))
    .map((s) => s.getAttribute('src') || '')
    .filter((src) => /admin-shell/i.test(src));

  const previewRoot = document.querySelector('[data-testid="admin-item-preview"], [data-testid="admin-composer-live-preview"]');
  const previewBranch = document.querySelector('[data-testid="admin-item-preview-branch-select"]');
  const previewEmpty = document.querySelector('[data-testid="admin-composer-preview-empty"]');
  const pos = document.querySelector('[data-testid="admin-item-preview-pos"]');
  const kiosk = document.querySelector('[data-testid="admin-item-preview-kiosk"]');
  const posBadge = pos ? pos.querySelector('header span') : null;
  const kioskBadge = kiosk ? kiosk.querySelector('header span') : null;
  const posName = pos ? pos.querySelector('p.text-sm.font-semibold, p.font-semibold') : null;
  const kioskName = kiosk ? kiosk.querySelector('p.text-sm.font-semibold, p.font-semibold') : null;
  const posText = pos ? visible(pos) : '';
  const kioskText = kiosk ? visible(kiosk) : '';

  const classifyAvail = (text) => {
    const t = String(text || '');
    if (/Chargement/i.test(t)) return 'loading';
    if (/\bDisponible\b/i.test(t) && !/Indisponible|non disponible/i.test(t)) return 'Disponible';
    if (/\bIndisponible\b|non disponible/i.test(t)) return 'Indisponible';
    return t ? t.slice(0, 160) : null;
  };

  const vueMeta = {
    found: false,
    branch_id_scope: null,
    is_published: null,
    item_id: null,
    item_name: null,
    branches: [],
    preview_branches: [],
    preview_selected_branch_id: null,
  };
  const vueSteps = [];
  const walk = (el) => {
    if (!el || vueMeta.found) return;
    const inst = el.__vueParentComponent || el.__vue__;
    const proxy = inst && (inst.proxy || inst.ctx || inst.setupState || inst);
    if (proxy && Array.isArray(proxy.steps) && proxy.steps.length) {
      vueMeta.found = true;
      vueMeta.branch_id_scope = proxy.branchIdScope ?? proxy.branch_id_scope ?? null;
      vueMeta.is_published = proxy.profile ? proxy.profile.is_published : null;
      vueMeta.item_id = proxy.item && proxy.item.id != null ? proxy.item.id : null;
      vueMeta.item_name = proxy.item && proxy.item.name ? proxy.item.name : null;
      vueMeta.branches = Array.isArray(proxy.branches)
        ? proxy.branches.map((b) => ({ id: b.id, name: b.name, status: b.status ?? null }))
        : [];
      vueMeta.preview_branches = Array.isArray(proxy.previewBranches)
        ? proxy.previewBranches.map((b) => ({ id: b.id, name: b.name, status: b.status ?? null }))
        : [];
      for (const s of proxy.steps) {
        vueSteps.push({
          id: s.id ?? null,
          step_key: s.step_key ?? null,
          label: s.label ?? null,
          source_type: s.source_type ?? null,
          source_ref: s.source_ref ?? null,
          is_active: s.is_active ?? null,
        });
      }
      return;
    }
    for (const child of el.children || []) walk(child);
  };
  walk(document.body);

  let previewVue = { found: false, selectedBranchId: null, posName: null, kioskName: null, posAvailable: null, kioskAvailable: null };
  const walkPreview = (el) => {
    if (!el || previewVue.found) return;
    const inst = el.__vueParentComponent || el.__vue__;
    const proxy = inst && (inst.proxy || inst.ctx || inst.setupState || inst);
    if (proxy && (proxy.posProjection !== undefined || proxy.selectedBranchId !== undefined) && (proxy.posSummary || proxy.refreshAll)) {
      previewVue = {
        found: true,
        selectedBranchId: proxy.selectedBranchId ?? null,
        posName: proxy.posProjection ? (proxy.posProjection.name || null) : null,
        kioskName: proxy.kioskProjection
          ? (proxy.kioskProjection.kiosk_label || proxy.kioskProjection.name || null)
          : null,
        posAvailable: proxy.posProjection ? !!proxy.posProjection.is_available : null,
        kioskAvailable: proxy.kioskProjection ? !!proxy.kioskProjection.is_available : null,
        posProjectionNull: proxy.posProjection == null,
        kioskProjectionNull: proxy.kioskProjection == null,
        loading: !!proxy.loading,
      };
      return;
    }
    for (const child of el.children || []) walkPreview(child);
  };
  if (previewRoot) walkPreview(previewRoot);
  else walkPreview(document.body);

  const body = document.body ? document.body.innerText : '';
  return {
    url: location.href,
    title: document.title,
    mix_scripts: mixScripts,
    composer_name: visible(document.querySelector('[data-testid="admin-composer-product-name"]')),
    publish_state: visible(document.querySelector('[data-testid="admin-composer-publish-state"]')),
    preview_empty: previewEmpty ? visible(previewEmpty) : null,
    live_preview_visible: !!(previewRoot && previewRoot.offsetParent !== null),
    filiale: selectedOption(previewBranch),
    caisse_badge: posBadge ? visible(posBadge) : null,
    borne_badge: kioskBadge ? visible(kioskBadge) : null,
    caisse_status: classifyAvail(posBadge ? visible(posBadge) : posText),
    borne_status: classifyAvail(kioskBadge ? visible(kioskBadge) : kioskText),
    preview_item_name_pos: posName ? visible(posName) : null,
    preview_item_name_kiosk: kioskName ? visible(kioskName) : null,
    pos_text: posText.slice(0, 500),
    kiosk_text: kioskText.slice(0, 500),
    vue_meta: vueMeta,
    vue_steps: vueSteps,
    preview_vue: previewVue,
    body_has_collier: /Collier/i.test(body),
    body_has_le_cayenne: /Le Cayenne/i.test(body),
    body_has_disponible: /\bDisponible\b/i.test(body),
    body_has_indisponible: /\bIndisponible\b|non disponible/i.test(body),
    body_snippet: body.replace(/\s+/g, ' ').trim().slice(0, 2200),
  };
}

async function extractW1(page, waitInfo) {
  if (waitInfo.overlay_visible) {
    const frame = page.frame({ url: /\/admin\/categories\/\d+\/composer/ });
    if (frame) {
      const inner = await frame.evaluate(w1ExtractFn).catch((err) => ({ evaluate_error: String(err.message || err) }));
      const overlayMeta = await page.evaluate(() => {
        const overlay = document.querySelector('[data-testid="catalog-studio-composer-overlay"]');
        const iframe = document.querySelector('[data-testid="catalog-studio-composer-frame"]');
        const h3 = overlay ? overlay.querySelector('h3') : null;
        return {
          overlay_title: h3 ? String(h3.textContent || '').replace(/\s+/g, ' ').trim() : null,
          iframe_src: iframe ? iframe.getAttribute('src') : null,
        };
      });
      const parentMix = await page.evaluate(() => Array.from(document.querySelectorAll('script[src]'))
        .map((s) => s.getAttribute('src') || '')
        .filter((src) => /admin-shell/i.test(src)));
      return { mode: 'drawer-iframe', ...overlayMeta, parent_mix_scripts: parentMix, inner };
    }
    return { mode: 'drawer-iframe-missing-frame', iframe_src: waitInfo.iframe_src, wait_error: waitInfo.wait_error };
  }
  if (waitInfo.full_page_composer) {
    const inner = await page.evaluate(w1ExtractFn);
    return { mode: 'full-page', inner };
  }
  return { mode: 'not-found', wait: waitInfo };
}

async function hardReloadComposer(page, waitInfo) {
  const result = { attempted: false, method: null, url_before: null, url_after: null, error: null };
  if (waitInfo.overlay_visible) {
    const frame = page.frame({ url: /\/admin\/categories\/\d+\/composer/ });
    if (!frame) {
      result.error = 'iframe frame object missing before hard reload';
      return result;
    }
    result.attempted = true;
    result.method = 'iframe-goto-cache-bust';
    result.url_before = frame.url();
    const bust = result.url_before.includes('?')
      ? `${result.url_before}&_hard=${Date.now()}`
      : `${result.url_before}?_hard=${Date.now()}`;
    try {
      await frame.goto(bust, { waitUntil: 'domcontentloaded', timeout: 30_000 });
      await page.frameLocator('[data-testid="catalog-studio-composer-frame"]')
        .getByTestId('admin-composer-root')
        .waitFor({ state: 'visible', timeout: 30_000 });
      result.url_after = frame.url();
    } catch (err) {
      result.error = String(err && err.message ? err.message : err).slice(0, 400);
      result.url_after = frame.url();
    }
    return result;
  }
  result.attempted = true;
  result.method = 'page-reload';
  result.url_before = page.url();
  try {
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
    await page.getByTestId('admin-composer-root').waitFor({ state: 'visible', timeout: 30_000 });
    result.url_after = page.url();
  } catch (err) {
    result.error = String(err && err.message ? err.message : err).slice(0, 400);
    result.url_after = page.url();
  }
  return result;
}

async function prepareWizardChrome(page, frame) {
  await hideDebugbar(page);
  if (frame) await hideDebugbar(frame);
  await page.evaluate(() => {
    const iframe = document.querySelector('[data-testid="catalog-studio-composer-frame"]');
    const overlay = document.querySelector('[data-testid="catalog-studio-composer-overlay"]');
    if (iframe) {
      iframe.style.setProperty('height', '2600px', 'important');
      iframe.style.setProperty('min-height', '2600px', 'important');
      iframe.style.setProperty('width', '100%', 'important');
    }
    if (overlay) {
      overlay.style.setProperty('overflow', 'visible', 'important');
      overlay.style.setProperty('max-height', 'none', 'important');
    }
  }).catch(() => {});
  await expandInnerOverflow(page);
  if (frame) await expandInnerOverflow(frame).catch(() => {});
}

function previewLocator(page, waitInfo) {
  return waitInfo.overlay_visible
    ? page.frameLocator('[data-testid="catalog-studio-composer-frame"]')
    : page;
}

async function readPreviewBadges(page, waitInfo) {
  const target = previewLocator(page, waitInfo);
  const preview = target.locator('[data-testid="admin-item-preview"]').first();
  if (!(await preview.count())) {
    const empty = target.locator('[data-testid="admin-composer-preview-empty"]').first();
    return {
      present: false,
      empty: await empty.isVisible().catch(() => false),
      busy: null,
      posBadge: null,
      kioskBadge: null,
      filiale: null,
      item: null,
    };
  }
  return preview.evaluate((el) => {
    const vis = (n) => (n && n.innerText ? n.innerText.replace(/\s+/g, ' ').trim() : '');
    const pos = el.querySelector('[data-testid="admin-item-preview-pos"]');
    const kiosk = el.querySelector('[data-testid="admin-item-preview-kiosk"]');
    const branch = el.querySelector('[data-testid="admin-item-preview-branch-select"]');
    const opt = branch && branch.selectedIndex >= 0 ? branch.options[branch.selectedIndex] : null;
    const posName = pos ? pos.querySelector('p.text-sm.font-semibold, p.font-semibold') : null;
    return {
      present: true,
      empty: false,
      busy: el.getAttribute('aria-busy') === 'true',
      posBadge: vis(pos ? pos.querySelector('header span') : null),
      kioskBadge: vis(kiosk ? kiosk.querySelector('header span') : null),
      filiale: opt ? vis(opt) : null,
      item: vis(posName),
    };
  }).catch((err) => ({ present: false, error: String(err && err.message ? err.message : err).slice(0, 200) }));
}

function badgeSettled(badge) {
  const t = String(badge || '');
  if (!t) return false;
  if (/Chargement/i.test(t)) return false;
  return /Disponible|Indisponible|non disponible/i.test(t);
}

async function waitPreviewSettled(page, waitInfo) {
  const target = previewLocator(page, waitInfo);
  const started = Date.now();
  const samples = [];
  try {
    await Promise.race([
      target.getByTestId('admin-item-preview').waitFor({ state: 'visible', timeout: 25_000 }),
      target.getByTestId('admin-composer-preview-empty').waitFor({ state: 'visible', timeout: 25_000 }),
    ]);
  } catch (_) {}
  // ComposerStepFormPanel @change remounts live preview after 500ms debounce.
  await page.waitForTimeout(1600);
  let last = null;
  const deadline = Date.now() + 20_000;
  while (Date.now() < deadline) {
    last = await readPreviewBadges(page, waitInfo);
    samples.push({ ...last, t: Date.now() - started });
    if (last && last.empty) {
      return { waited_ms: Date.now() - started, settled: true, reason: 'preview-empty', last, samples };
    }
    if (last && last.present && last.busy === false && badgeSettled(last.posBadge) && badgeSettled(last.kioskBadge)) {
      await page.waitForTimeout(1100);
      const again = await readPreviewBadges(page, waitInfo);
      samples.push({ ...again, t: Date.now() - started, stable_check: true });
      if (again && again.present && again.busy === false && badgeSettled(again.posBadge) && badgeSettled(again.kioskBadge)) {
        return { waited_ms: Date.now() - started, settled: true, reason: 'badges-stable', last: again, samples };
      }
    }
    await page.waitForTimeout(300);
  }
  return { waited_ms: Date.now() - started, settled: false, reason: 'timeout', last, samples };
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
  let chosen = null;
  let wizardWait = null;
  let wizardObs = null;
  let hardReload = null;
  let previewWait = null;
  let composerFrame = null;

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
      throw new Error('Accès rapides « Catalogue » not found — aborting Wave D Burgers (did not click POS).');
    }
    await catalogueLink.scrollIntoViewIfNeeded();
    await catalogueLink.click();
    await page.waitForURL(/\/admin\/items\/studio/, { timeout: 25_000 });
    await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 30_000 });
    await page.locator('[data-testid^="catalog-studio-category-row-"]').first().waitFor({ state: 'visible', timeout: 25_000 });
    await page.waitForTimeout(800);

    categories = await listCategories(page);
    const burgers = categories.find((c) => /^Burgers$/i.test(c.name));
    chosen = burgers || null;
    if (!chosen) {
      blockers.push('Category « Burgers » not found (exact name). Did not click Tacos/POS.');
    } else {
      const btn = page.locator(`[data-testid="catalog-studio-category-row-${chosen.id}"] button.catalog-studio__category`);
      await btn.scrollIntoViewIfNeeded();
      await btn.click();
      await page.getByTestId('catalog-studio-category-wizard-entry').waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
      await page.waitForTimeout(700);
    }

    const wizardBtn = page.getByTestId('catalog-studio-category-wizard-button');
    if (!(await wizardBtn.isVisible().catch(() => false))) {
      blockers.push('« Wizard de la catégorie » button not visible after Burgers select — skipped 02-wizard.');
    } else {
      rec.clearStateBuffers();
      await wizardBtn.click();
      wizardWait = await waitComposerReady(page);
      await page.waitForTimeout(800);

      hardReload = await hardReloadComposer(page, wizardWait);
      wizardWait = await waitComposerReady(page);
      composerFrame = page.frame({ url: /\/admin\/categories\/\d+\/composer/ }) || page;
      await prepareWizardChrome(page, wizardWait && wizardWait.overlay_visible ? composerFrame : null);
      previewWait = await waitPreviewSettled(page, wizardWait);
      rec.clearStateBuffers();
      wizardObs = await extractW1(page, wizardWait);
      const wizBuffers = rec.snapshotBuffers();
      const wizQuartet = await snapQuartet(page, '02-wizard', wizBuffers, {
        expand: false,
        frame: wizardWait && wizardWait.overlay_visible ? composerFrame : null,
      });
      states.push({
        name: 'burgers-wizard-after-hard-reload',
        url: wizQuartet.url,
        png: wizQuartet.png,
        dom: wizQuartet.dom,
        console: wizQuartet.console,
        network: wizQuartet.network,
        notes: `Dashboard → Catalogue → Burgers → Wizard de la catégorie. HARD RELOAD iframe. Mix expected ${EXPECTED_MIX}. Did not publish.`,
      });
    }

    const inner = wizardObs && wizardObs.inner ? wizardObs.inner : {};
    const mixFromInner = inner.mix_scripts || [];
    const mixFromParent = wizardObs && wizardObs.parent_mix_scripts ? wizardObs.parent_mix_scripts : [];
    const mixLoaded = [...mixFromInner, ...mixFromParent, ...rec.mixHits.map((m) => m.url)]
      .some((u) => String(u).includes(EXPECTED_MIX));
    if (!mixLoaded) {
      blockers.push(`Expected Mix ${EXPECTED_MIX} not observed after hard reload.`);
    }

    const relevant4xx = rec.allNetwork.filter((n) => !isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const mutatingAfterLogin = rec.mutations.filter((m) => !/\/api\/auth\/(login|authcheck|logout)/i.test(m.url));

    const filialeName = inner.filiale && inner.filiale.label
      ? inner.filiale.label
      : (inner.vue_meta && inner.vue_meta.preview_branches && inner.vue_meta.preview_branches[0]
        ? inner.vue_meta.preview_branches[0].name
        : null);
    const previewItem = inner.preview_item_name_pos
      || inner.preview_item_name_kiosk
      || (inner.preview_vue && (inner.preview_vue.posName || inner.preview_vue.kioskName))
      || (inner.vue_meta && inner.vue_meta.item_name)
      || null;

    const w1 = {
      mix_expected: EXPECTED_MIX,
      mix_loaded: mixLoaded,
      mix_scripts_iframe: mixFromInner,
      mix_scripts_parent: mixFromParent,
      mix_network: rec.mixHits,
      hard_reload: hardReload,
      preview_wait: previewWait,
      filiale: filialeName,
      filiale_select: inner.filiale || null,
      caisse: inner.caisse_status || null,
      caisse_badge: inner.caisse_badge || null,
      borne: inner.borne_status || null,
      borne_badge: inner.borne_badge || null,
      preview_item_name: previewItem,
      preview_item_name_pos: inner.preview_item_name_pos || null,
      preview_item_name_kiosk: inner.preview_item_name_kiosk || null,
      preview_vue: inner.preview_vue || null,
      preview_empty: inner.preview_empty || null,
      live_preview_visible: !!inner.live_preview_visible,
    };

    const notes = {
      wave: 'D-burgers-wizard-round-5-after-mix-57f379b6-W1',
      base_url: BASE,
      login: loginInfo,
      dashboard_to_catalogue: true,
      pos_clicked: false,
      published: false,
      products_created: false,
      flags_flipped: false,
      chosen_category: chosen,
      categories,
      wizard_wait: wizardWait,
      hard_reload: hardReload,
      composer: wizardObs,
      api_profile: rec.profileBodies[rec.profileBodies.length - 1] || null,
      projections: rec.projectionBodies,
      w1,
      http_4xx_5xx_excluding_ws_debugbar_english_storage: relevant4xx,
      mutating_requests_after_login_excluding_auth: mutatingAfterLogin,
      states,
      blockers,
      final_url: page.url(),
    };
    fs.writeFileSync(path.join(OUT, 'wave-D-burgers-notes.json'), JSON.stringify(notes, null, 2), 'utf8');
    console.log(JSON.stringify({
      wave: notes.wave,
      base_url: BASE,
      png: states[0] ? states[0].png : null,
      mix_loaded: mixLoaded,
      filiale: w1.filiale,
      caisse: w1.caisse,
      borne: w1.borne,
      preview_item_name: w1.preview_item_name,
      publish_state: inner.publish_state || null,
      composer_name: inner.composer_name || null,
      hard_reload: hardReload,
      relevant4xx_count: relevant4xx.length,
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
