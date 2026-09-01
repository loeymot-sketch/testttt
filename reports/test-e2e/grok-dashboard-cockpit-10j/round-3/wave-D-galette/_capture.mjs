/**
 * Round-3 Wave D — Galette composer FROM DASHBOARD.
 * Isolated Playwright CLI (no MCP, no project globalSetup).
 * No publish, no product create, no flag flips, no POS click.
 * Goal: prove Galette wizard is NOT a Tacos clone (no viande_2 / viande_3).
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
const OUT = path.join(REPO, 'reports/test-e2e/grok-dashboard-cockpit-10j/round-3/wave-D-galette');
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
        iframe.style.setProperty('height', '2200px', 'important');
        iframe.style.setProperty('min-height', '2200px', 'important');
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
      wizard_button_text: wizardBtn ? visible(wizardBtn) : null,
      wizard_hint: wizardEntry ? visible(wizardEntry.querySelector('small')) : null,
      empty_state: empty ? visible(empty) : null,
      product_count: products.length,
      products,
      body_snippet: visible(document.querySelector('.catalog-studio')).slice(0, 1200),
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
    return {
      testid: row.getAttribute('data-testid'),
      index,
      label: labels[0] || visible(labelEl),
      source_line: labels[1] || null,
      chips: Array.from(row.querySelectorAll('.rounded-full')).map((c) => visible(c)).filter(Boolean),
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
      ? Array.from(sel.options).map((o) => ({
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
    body_snippet: body.replace(/\s+/g, ' ').trim().slice(0, 1800),
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

function cloneVerdict(stepKeys, stepLabels) {
  const keys = (stepKeys || []).map((k) => String(k || '').toLowerCase());
  const labels = (stepLabels || []).map((k) => String(k || '').toLowerCase());
  const hay = [...keys, ...labels].join(' | ');
  const hasViande2 = keys.includes('viande_2') || /viande\s*2/.test(hay);
  const hasViande3 = keys.includes('viande_3') || /viande\s*3/.test(hay);
  const expectedKeys = ['pain', 'viande', 'sauce', 'garnitures', 'supplements'];
  const presentExpected = expectedKeys.filter((k) => keys.includes(k) || labels.some((l) => l.includes(k)));
  return {
    looks_like_tacos_clone: hasViande2 || hasViande3,
    has_viande_2: hasViande2,
    has_viande_3: hasViande3,
    expected_keys: expectedKeys,
    present_expected_keys: presentExpected,
    step_keys: keys,
    step_labels: stepLabels || [],
  };
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
  let railObs = null;
  let wizardObs = null;
  let wizardWait = null;
  let wizardClicked = false;
  let wizardUrl = null;
  let railQuartet = null;
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
      throw new Error('Accès rapides « Catalogue » not found — aborting Wave D Galette (did not click POS).');
    }
    await catalogueLink.scrollIntoViewIfNeeded();
    await catalogueLink.click();
    await page.waitForURL(/\/admin\/items\/studio/, { timeout: 25_000 });
    await page.getByTestId('catalog-studio-page').waitFor({ state: 'visible', timeout: 30_000 });
    await page.locator('[data-testid^="catalog-studio-category-row-"]').first().waitFor({ state: 'visible', timeout: 25_000 });
    await page.waitForTimeout(800);

    categories = await listCategories(page);
    const galette = categories.find((c) => /^Galette$/i.test(c.name));
    chosen = galette || null;
    if (!chosen) {
      blockers.push('Category « Galette » not found (exact name). Did not click Tacos.');
    } else {
      const btn = page.locator(`[data-testid="catalog-studio-category-row-${chosen.id}"] button.catalog-studio__category`);
      await btn.scrollIntoViewIfNeeded();
      await btn.click();
      await page.getByTestId('catalog-studio-category-wizard-entry').waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
      await page.waitForTimeout(900);
    }

    rec.clearStateBuffers();
    railObs = await extractRail(page);
    const railBuffers = rec.snapshotBuffers();
    railQuartet = await snapQuartet(page, '01-galette-rail', railBuffers, { expand: true });
    states.push({
      name: 'galette-rail',
      url: railQuartet.url,
      png: railQuartet.png,
      dom: railQuartet.dom,
      console: railQuartet.console,
      network: railQuartet.network,
      notes: `From dashboard Accès rapides Catalogue, clicked category « ${chosen ? chosen.name : '(missing)'} ». Product cards=${railObs.product_count}.`,
    });

    const wizardBtn = page.getByTestId('catalog-studio-category-wizard-button');
    if (await wizardBtn.isVisible().catch(() => false)) {
      rec.clearStateBuffers();
      await wizardBtn.click();
      wizardClicked = true;
      wizardWait = await waitComposerReady(page);
      await page.waitForTimeout(1500);
      wizardObs = await extractComposer(page, wizardWait);
      wizardUrl = page.url();
      composerFrame = page.frame({ url: /\/admin\/categories\/\d+\/composer/ });
      const wizBuffers = rec.snapshotBuffers();
      const wizQuartet = await snapQuartet(page, '02-galette-wizard', wizBuffers, {
        expand: false,
        frame: composerFrame,
      });
      states.push({
        name: 'galette-wizard',
        url: wizQuartet.url,
        png: wizQuartet.png,
        dom: wizQuartet.dom,
        console: wizQuartet.console,
        network: wizQuartet.network,
        notes: `Clicked « Wizard de la catégorie ». Overlay=${wizardWait.overlay_visible} iframe_src=${wizardWait.iframe_src || ''} full_page=${wizardWait.full_page_composer}. Did not publish / create / flip flags / POS.`,
      });
    } else {
      blockers.push('« Wizard de la catégorie » button not visible after Galette select — skipped 02-galette-wizard.');
    }

    const relevant4xx = rec.allNetwork.filter((n) => !isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const ignored4xx = rec.allNetwork.filter((n) => isIgnored4xx(n) && (n.status >= 400 || n.kind === 'failed'));
    const mutatingAfterLogin = rec.mutations.filter((m) => !/\/api\/auth\/(login|authcheck|logout)/i.test(m.url));
    const inner = wizardObs && wizardObs.inner ? wizardObs.inner : {};
    const apiProfile = rec.profileBodies[rec.profileBodies.length - 1] || null;
    const stepKeys = (apiProfile && apiProfile.steps
      ? apiProfile.steps.map((s) => s.step_key)
      : (inner.vue_step_keys || []));
    const stepLabels = inner.step_labels || (apiProfile ? apiProfile.steps.map((s) => s.label) : []);
    const notes = {
      wave: 'D-galette-composer-from-dashboard-round-3',
      base_url: BASE,
      login: loginInfo,
      dashboard_to_catalogue: true,
      pos_clicked: false,
      published: false,
      products_created: false,
      flags_flipped: false,
      categories,
      chosen_category: chosen,
      wizard_clicked: wizardClicked,
      wizard_url: wizardUrl,
      wizard_wait: wizardWait,
      rail: railObs,
      composer: wizardObs,
      api_profile: apiProfile,
      step_labels: stepLabels,
      step_keys: stepKeys,
      publish_state: inner.publish_state || null,
      draft_not_till: inner.draft_not_till || null,
      branch_scope: inner.branch_scope || null,
      preview_branch: inner.preview_branch || null,
      preview_empty: inner.preview_empty || null,
      live_preview_visible: inner.live_preview_visible || false,
      preview_pos_step_labels: inner.preview_pos_step_labels || [],
      preview_kiosk_step_labels: inner.preview_kiosk_step_labels || [],
      body_has_collier: !!inner.body_has_collier,
      body_has_le_cayenne: !!inner.body_has_le_cayenne,
      clone_verdict: cloneVerdict(stepKeys, stepLabels),
      source_ref: inner.source_ref || null,
      source_empty_message: inner.source_empty_message || null,
      photos: {
        rail: (railObs && railObs.products) ? railObs.products.map((p) => ({ name: p.name, src: p.photo_src, has_photo: p.has_photo })) : [],
        composer: inner.photo || null,
      },
      raw_labels: {
        rail: railObs ? railObs.raw_labels : [],
        composer: inner.raw_labels || [],
        parent_dom: rawLabelHits(await page.content()),
      },
      http_4xx_5xx_excluding_ws_debugbar_english_storage: relevant4xx,
      http_4xx_ignored_debugbar_english_storage: ignored4xx.map((n) => ({ url: n.url, status: n.status })),
      mutating_requests_after_login_excluding_auth: mutatingAfterLogin,
      states,
      blockers,
      final_url: page.url(),
    };
    fs.writeFileSync(path.join(OUT, 'wave-D-galette-notes.json'), JSON.stringify(notes, null, 2), 'utf8');
    console.log(JSON.stringify({
      wave: notes.wave,
      base_url: BASE,
      png_rail: railQuartet ? railQuartet.png : null,
      png_wizard: states[1] ? states[1].png : null,
      wizard_url: wizardUrl,
      chosen_category: chosen,
      step_labels: notes.step_labels,
      step_keys: notes.step_keys,
      publish_state: notes.publish_state,
      branch_scope: notes.branch_scope,
      preview_branch: notes.preview_branch,
      clone_verdict: notes.clone_verdict,
      product_names: railObs ? railObs.products.map((p) => p.name) : [],
      overlay_title: wizardObs ? wizardObs.overlay_title : null,
      composer_name: inner.composer_name || null,
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
