const { expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login } = require('../../helpers/login');

const repoRoot = path.resolve(__dirname, '../../../..');
const axeSourcePath = require.resolve('axe-core/axe.min.js', { paths: [repoRoot] });

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function sanitizeName(name) {
  return String(name).replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase();
}

async function collectConsole(page) {
  const events = [];
  const onConsole = (message) => {
    if (['error', 'warning'].includes(message.type())) {
      events.push(`${message.type()}: ${message.text()}`);
    }
  };
  const onPageError = (error) => events.push(`pageerror: ${error.message}`);

  page.on('console', onConsole);
  page.on('pageerror', onPageError);

  return {
    events,
    dispose: () => {
      page.off('console', onConsole);
      page.off('pageerror', onPageError);
    },
  };
}

function criticalConsole(events) {
  return events.filter((entry) => /TypeError|ReferenceError|Cannot read|is not a function|is not defined|Whoops|Fatal error|Server Error/i.test(entry));
}

async function loginStaff(page, role) {
  if (role === 'pos') {
    await login(page, 'pos@lecayenne.fr', '123456');
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 20_000 });
    return;
  }

  if (role === 'chef') {
    await login(page, 'chef@lecayenne.fr', '123456');
    await expect(page).toHaveURL(/\/(kds|admin\/kitchen-display-system)/, { timeout: 20_000 });
    return;
  }

  await login(page, 'admin@example.com', '123456');
}

async function runAxe(page) {
  await page.addScriptTag({ path: axeSourcePath });
  return page.evaluate(async () => {
    return window.axe.run(document, {
      resultTypes: ['violations'],
      runOnly: {
        type: 'tag',
        values: ['wcag2a', 'wcag2aa'],
      },
    });
  });
}

async function clearTransientUi(page) {
  await page.evaluate(() => {
    const selectors = [
      '.Vue-Toastification__container',
      '.Vue-Toastification__toast-container',
      '.kiosk-toast-container',
    ];

    selectors.forEach((selector) => {
      document.querySelectorAll(selector).forEach((node) => node.remove());
    });
  });
}

async function summarizePage(page) {
  return page.evaluate(() => {
    const bodyText = (document.body.innerText || '').trim();
    const interactive = Array.from(document.querySelectorAll('button, a, input, select, textarea, [role="button"]'));
    const smallTargets = interactive
      .map((element) => {
        const rect = element.getBoundingClientRect();
        return {
          tag: element.tagName.toLowerCase(),
          text: (element.textContent || element.getAttribute('aria-label') || element.getAttribute('data-testid') || '').trim().slice(0, 80),
          width: Math.round(rect.width),
          height: Math.round(rect.height),
        };
      })
      .filter((entry) => entry.width > 0 && entry.height > 0 && (entry.width < 44 || entry.height < 44));

    const testIds = Array.from(document.querySelectorAll('[data-testid]'))
      .map((element) => element.getAttribute('data-testid'))
      .filter(Boolean)
      .slice(0, 60);

    return {
      title: document.title,
      textLength: bodyText.length,
      hasServerError: /Whoops|Fatal error|Server Error/i.test(bodyText),
      testIds,
      interactiveCount: interactive.length,
      smallTargets,
    };
  });
}

async function auditRoute(page, options) {
  const {
    domain,
    name,
    url,
    rootTestId,
    screenshotDir,
    viewport = { width: 1280, height: 800 },
    waitMs = 800,
  } = options;

  await page.setViewportSize(viewport);
  const consoleMonitor = await collectConsole(page);
  const consoleEvents = consoleMonitor.events;
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(waitMs);
  await clearTransientUi(page);

  const summary = await summarizePage(page);
  const critical = criticalConsole(consoleEvents);
  const axe = await runAxe(page).catch((error) => ({ violations: [{ id: 'axe-runtime-error', impact: 'critical', help: error.message }] }));
  const seriousAxe = (axe.violations || []).filter((violation) => ['critical', 'serious'].includes(violation.impact));

  consoleMonitor.dispose();

  if (rootTestId) {
    const root = page.getByTestId(rootTestId);
    if (await root.count()) {
      await expect(root.first()).toBeVisible({ timeout: 10_000 });
    }
  }

  expect(summary.hasServerError).toBe(false);
  expect(critical).toHaveLength(0);
  expect(summary.textLength).toBeGreaterThan(5);

  ensureDir(screenshotDir);
  const iterationSuffix = options.iteration !== undefined ? `-iter-${options.iteration + 1}` : '';
  const screenshotPath = path.join(screenshotDir, `${sanitizeName(domain)}-${sanitizeName(name)}${iterationSuffix}-${viewport.width}x${viewport.height}.png`);
  await page.screenshot({ path: screenshotPath, fullPage: true });

  return {
    domain,
    name,
    iteration: options.iteration ?? 0,
    url: page.url(),
    expectedUrl: url,
    viewport,
    screenshotPath,
    textLength: summary.textLength,
    testIds: summary.testIds,
    interactiveCount: summary.interactiveCount,
    smallTargetCount: summary.smallTargets.length,
    smallTargets: summary.smallTargets.slice(0, 12),
    seriousAxeCount: seriousAxe.length,
    seriousAxe: seriousAxe.slice(0, 10).map((violation) => ({
      id: violation.id,
      impact: violation.impact,
      help: violation.help,
      nodes: violation.nodes.length,
      targets: violation.nodes.slice(0, 5).map((node) => ({
        target: node.target,
        html: String(node.html || '').slice(0, 220),
      })),
    })),
  };
}

function writeJsonReport(filePath, payload) {
  ensureDir(path.dirname(filePath));
  fs.writeFileSync(filePath, JSON.stringify(payload, null, 2));
}

module.exports = {
  auditRoute,
  loginStaff,
  writeJsonReport,
};
