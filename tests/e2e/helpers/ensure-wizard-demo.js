/**
 * Aligns SPA + Laravel composer (Demo V2) for Playwright without toggling `.env`.
 *
 * Sends HTTP header `X-Foodking-Playwright-E2e: 1` (must match
 * `App\Support\WizardPerItemDemo::PLAYWRIGHT_HEADER`) on every request from this
 * browser context. Backend enables wizard when **APP_ENV=local** (only) and
 * **header=1** (see `WizardPerItemDemo::playwrightLocalBypass`). `APP_DEBUG` is not required.
 *
 * Call on a **BrowserContext** right after `browser.newContext()` (before `newPage()`),
 * or pass a **Page** to attach headers to `page.context()`.
 *
 * Backend: {@see App\Support\WizardPerItemDemo} — avec header, le bypass exige `APP_ENV=local`
 * **ou** `FOODKING_E2E_WIZARD_HEADER=true` (sinon le middleware composer répond **404**).
 *
 * @param {import('@playwright/test').Page | import('@playwright/test').BrowserContext} pageOrContext
 */
async function ensureWizardPerItemDemoForComposer(pageOrContext) {
  const context =
    pageOrContext && typeof pageOrContext.newPage === 'function'
      ? pageOrContext
      : pageOrContext.context();

  await context.setExtraHTTPHeaders({
    'X-Foodking-Playwright-E2e': '1',
  });
}

module.exports = { ensureWizardPerItemDemoForComposer };
