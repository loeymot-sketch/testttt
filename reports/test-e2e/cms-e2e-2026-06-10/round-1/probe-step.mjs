// read-only probe: which step gets selected and what the prices panel shows
import { chromium } from 'playwright';
const BASE = 'http://127.0.0.1:8767';
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1400 } });
await page.goto(`${BASE}/login`);
await page.waitForLoadState('networkidle').catch(() => {});
await page.waitForTimeout(1500);
const email = page.getByPlaceholder(/email/i).first();
if (await email.count()) await email.fill('admin@lecayenne.fr');
else await page.locator('input[type="email"], input[type="text"]').first().fill('admin@lecayenne.fr');
const pwd = page.getByPlaceholder(/mot de passe/i).first();
if (await pwd.count()) await pwd.fill('123456');
else await page.locator('input[type="password"]').first().fill('123456');
await page.getByRole('button', { name: /connexion/i }).click();
await page.waitForLoadState('networkidle').catch(() => {});
await page.waitForTimeout(2000);
await page.goto(`${BASE}/admin/categories/5/composer`);
await page.waitForLoadState('networkidle').catch(() => {});
await page.waitForTimeout(2500);

async function readPanel(tag) {
  const data = await page.evaluate(() => {
    const input = document.querySelector('[data-testid="composer-step-label-input"]');
    const prices = [...document.querySelectorAll('[data-testid="composer-step-choice-prices"] li span:first-child')].map(e => e.textContent.trim()).slice(0, 6);
    const sourceRef = document.querySelector('[data-testid="composer-step-source-ref"]');
    const selectedRow = [...document.querySelectorAll('[data-testid^="composer-step-row-"]')].find(r => r.className.includes('emerald') || r.className.includes('selected') || r.querySelector('[aria-current]'));
    return {
      labelInputValue: input ? input.value : null,
      sourceRefSelected: sourceRef ? sourceRef.selectedOptions[0]?.textContent.trim() : null,
      pricesFirst6: prices,
    };
  });
  console.log(tag, JSON.stringify(data, null, 2));
}

console.log('--- default selection on load ---');
await readPanel('DEFAULT:');
console.log('--- after clicking "Choisis la taille" ---');
await page.locator('[data-testid^="composer-step-select-"]', { hasText: 'Choisis la taille' }).first().click();
await page.waitForTimeout(1200);
await readPanel('TAILLE:');
console.log('--- after clicking "Choisis tes viandes" ---');
await page.locator('[data-testid^="composer-step-select-"]', { hasText: 'Choisis tes viandes' }).first().click();
await page.waitForTimeout(1200);
await readPanel('VIANDES:');
await browser.close();
