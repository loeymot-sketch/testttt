const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const SHOTS = 'tests/e2e/__screenshots__/deep-S3-kds-round2';
const BASE = 'http://127.0.0.1:8000';

if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

const consoleLog = [];
const networkLog = [];

async function snap(page, name) {
  await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true });
  fs.writeFileSync(path.join(SHOTS, name + '.dom.html'), await page.content());
  fs.writeFileSync(path.join(SHOTS, name + '.console.json'), JSON.stringify(consoleLog.slice(-50), null, 2));
  fs.writeFileSync(path.join(SHOTS, name + '.network.json'), JSON.stringify(networkLog.slice(-50), null, 2));
  console.log('SNAP ' + name + ' (url=' + page.url() + ')');
}

async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForSelector('#formEmail', { timeout: 20000 });
  await page.locator('#formEmail').fill('admin@lecayenne.fr');
  await page.locator('#formPassword').fill('123456');
  await page.getByRole('button', { name: /connexion|login/i }).click();
  await page.waitForURL(u => !u.toString().includes('/login'), { timeout: 25000 }).catch(()=>{});
  await page.waitForTimeout(3500);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await ctx.newPage();

  // Hook console + network
  page.on('console', msg => {
    consoleLog.push({ type: msg.type(), text: msg.text().slice(0, 500) });
  });
  page.on('pageerror', err => {
    consoleLog.push({ type: 'pageerror', text: String(err).slice(0, 500) });
  });
  page.on('response', resp => {
    const url = resp.url();
    if (url.includes('/api/') || url.includes('/kds') || url.includes('kitchen-display')) {
      networkLog.push({ status: resp.status(), url: url.slice(0, 200) });
    }
  });

  await login(page);
  console.log('Logged in. URL:', page.url());

  // S3R2-01 KDS mount (default route /kds)
  try {
    await page.goto(BASE + '/kds', { waitUntil: 'networkidle', timeout: 30000 });
  } catch (e) {
    console.log('Navigation /kds threw:', e.message);
  }
  await page.waitForTimeout(4000);
  console.log('After /kds, URL:', page.url());
  await snap(page, 'S3R2-01-kds-mount');

  // S3R2-02 alt route
  try {
    await page.goto(BASE + '/admin/kitchen-display-system', { waitUntil: 'networkidle', timeout: 30000 });
  } catch (e) {
    console.log('Navigation /admin/kitchen-display-system threw:', e.message);
  }
  await page.waitForTimeout(4000);
  console.log('After /admin/kitchen-display-system, URL:', page.url());
  await snap(page, 'S3R2-02-kds-alt-route');

  // S3R2-03 header pill visible — scroll top
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(500);
  await snap(page, 'S3R2-03-header-pill');

  // S3R2-04 click Historique du jour
  let historiqueFound = false;
  let historiqueOpened = false;
  try {
    const histBtn = page.getByRole('button', { name: /historique du jour|day history/i });
    if (await histBtn.count() > 0) {
      historiqueFound = true;
      await histBtn.first().click();
      await page.waitForTimeout(2000);
      await snap(page, 'S3R2-04-historique-drawer-clicked');
      historiqueOpened = true;
    } else {
      console.log('No Historique button found by role; trying text fallback');
      const altBtn = page.locator('button:has-text("Historique")');
      if (await altBtn.count() > 0) {
        historiqueFound = true;
        await altBtn.first().click();
        await page.waitForTimeout(2000);
        await snap(page, 'S3R2-04-historique-drawer-clicked');
        historiqueOpened = true;
      } else {
        await snap(page, 'S3R2-04-no-historique-btn');
      }
    }
  } catch (e) {
    console.log('Historique click failed:', e.message);
    await snap(page, 'S3R2-04-historique-error');
  }

  // S3R2-05 if drawer open, drawer content (full page)
  if (historiqueOpened) {
    await page.waitForTimeout(1000);
    await snap(page, 'S3R2-05-drawer-content');
  }

  // S3R2-06 console + network status (snapshot again with fresh logs)
  await snap(page, 'S3R2-06-console-net');

  // S3R2-07 DOM probe — count of order-card-like elements
  const cardProbe = await page.evaluate(() => {
    const selectors = ['.order-card', '.kds-order', '[data-order-id]', '.card-order', '.kds-card', '.order-tile'];
    const result = {};
    for (const sel of selectors) {
      try { result[sel] = document.querySelectorAll(sel).length; } catch (e) { result[sel] = 'err'; }
    }
    result.allDataOrder = document.querySelectorAll('[data-order]').length;
    result.bodyLen = document.body.innerHTML.length;
    result.kdsRootPresent = !!document.querySelector('#kds, .kds-root, [class*="kds"]');
    result.vueAppPresent = !!document.querySelector('#app, [data-v-app]');
    result.title = document.title;
    return result;
  });
  fs.writeFileSync(path.join(SHOTS, 'S3R2-07-dom-probe.json'), JSON.stringify(cardProbe, null, 2));
  await snap(page, 'S3R2-07-dom-state');

  // S3R2-08 final state
  await page.waitForTimeout(1500);
  await snap(page, 'S3R2-08-final');

  // Master log dump
  fs.writeFileSync(path.join(SHOTS, '_FULL_console.json'), JSON.stringify(consoleLog, null, 2));
  fs.writeFileSync(path.join(SHOTS, '_FULL_network.json'), JSON.stringify(networkLog, null, 2));
  fs.writeFileSync(path.join(SHOTS, '_meta.json'), JSON.stringify({
    historiqueFound,
    historiqueOpened,
    cardProbe,
    finalUrl: page.url(),
  }, null, 2));

  await browser.close();
  console.log('DONE');
})().catch(err => {
  console.error('SCRIPT ERROR:', err);
  process.exit(1);
});
