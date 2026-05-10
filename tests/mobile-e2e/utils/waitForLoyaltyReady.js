// Helper — wait for Le Cayenne mobile app to fully boot through Babel-standalone.
// Babel transpiles the JSX at runtime so `domcontentloaded` fires before the
// app is interactive. We wait for `window.LC` + storage + dev helpers + an
// auth seed that puts us past the splash/onboarding gates.

async function waitForLoyaltyReady(page, options = {}) {
  const opts = Object.assign({
    seedAccount: null,
    seedHistory: null,
    seedConsent: 'opted_in',
    clearFirst: true,
  }, options);

  await page.goto('/index.html', { waitUntil: 'domcontentloaded' });

  // Wait for data-layer + dev helpers (loaded by index.html before babel scripts)
  await page.waitForFunction(
    () => !!window.LC && !!window.LC.storage && !!window.LC.loyalty && !!window.LC.dev,
    { timeout: 15_000 }
  );

  // Bypass auth + onboarding so we land on home, then navigate to loyalty.
  await page.evaluate((o) => {
    if (o.clearFirst) {
      window.LC.dev.clearAll();
      window.LC.storage.clearAuth();
    }
    window.LC.storage.setAuth({ token: 'test', phone: '+33600000000', user_id: 12345 });
    window.LC.storage.markOnboardingSeen();
    window.LC.storage.setConsent(o.seedConsent);
    if (o.seedAccount) window.LC.dev.seedAccount(o.seedAccount);
    if (o.seedHistory != null) {
      // Replace history entirely (not append)
      window.LC.loyalty._replaceHistory([]);
      if (Array.isArray(o.seedHistory)) {
        window.LC.loyalty._replaceHistory(o.seedHistory);
      } else if (typeof o.seedHistory === 'number') {
        window.LC.dev.seedHistory(o.seedHistory);
      }
    }
  }, opts);

  await page.reload({ waitUntil: 'domcontentloaded' });

  // Wait for React to render the home screen
  await page.waitForSelector('[data-screen-label]', { timeout: 15_000 });
}

async function gotoLoyaltyScreen(page) {
  // Click Profile tab → Loyalty card to navigate. Alternative: use go() directly.
  await page.waitForSelector('[data-screen-label]');
  await page.evaluate(() => {
    // The App component holds screen state. There's no global `go()` exposed
    // by default, but we can dispatch a synthetic event that the App listens
    // to — OR we use the storage to force-render. Simpler: click the profile
    // tab then the loyalty preview card.
    const profileTab = Array.from(document.querySelectorAll('.lc-tab')).find(el => /Profil/i.test(el.textContent));
    if (profileTab) profileTab.click();
  });
  await page.waitForTimeout(150);
  await page.evaluate(() => {
    // Click the loyalty preview card (any element navigating to loyalty)
    const card = document.querySelector('[data-screen-label] [onClick]');
    // Fallback: click first ink-bg card with "Carte fidélité" text
    const el = Array.from(document.querySelectorAll('div')).find(d => /Carte fidélité/i.test(d.textContent || '') && d.style && d.style.cursor === 'pointer');
    if (el) el.click();
  });
  await page.waitForSelector('[data-testid="loyalty-screen"]', { timeout: 10_000 });
}

module.exports = { waitForLoyaltyReady, gotoLoyaltyScreen };
