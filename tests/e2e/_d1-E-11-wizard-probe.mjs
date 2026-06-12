// D1 VAGUE E — probe structure wizard Tacos (jetable)
import { BASE, OUT, boot } from './_d1-E-lib.mjs';

const { browser, page } = await boot({ kiosk: true });
await page.goto(BASE + '/kiosk/login', { waitUntil: 'domcontentloaded' });
for (let i = 0; i < 10; i++) {
  await page.waitForTimeout(1500);
  if (await page.evaluate(() => { try { return !!JSON.parse(localStorage.vuex)?.kioskCart?.kioskToken; } catch { return false; } })) break;
}
await page.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500);
const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
if (await touch.isVisible().catch(() => false)) { await touch.click({ force: true }); await page.waitForTimeout(1000); }
const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
if (await takeaway.isVisible().catch(() => false)) { await takeaway.click(); await page.waitForTimeout(1800); }

await page.goto(`${BASE}/kiosk/categories?cat=5`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1800);
console.log('adds:', await page.locator('[data-testid^="kiosk-product-add-"]').count());
const tacosAdd = page.locator('[data-testid="kiosk-product-add-26"]');
console.log('add-26 visible:', await tacosAdd.isVisible().catch(() => false));
await tacosAdd.click().catch(async () => { await page.locator('[data-testid^="kiosk-product-add-"]').first().click(); });
await page.waitForTimeout(2000);

for (let s = 0; s < 10; s++) {
  const open = await page.locator('.kiosk-wizard-overlay').isVisible().catch(() => false);
  if (!open) { console.log(`STEP ${s}: overlay fermé (fini)`); break; }
  const info = await page.evaluate(() => {
    const ov = document.querySelector('.kiosk-wizard-overlay');
    const vis = (el) => el && el.offsetParent !== null;
    const classesCount = {};
    ov.querySelectorAll('*').forEach((el) => {
      el.classList.forEach((c) => { if (/option|card|step|choice|btn|next|chip|viande|sauce|menu/i.test(c)) classesCount[c] = (classesCount[c] || 0) + 1; });
    });
    const btns = Array.from(ov.querySelectorAll('button')).filter(vis).map((b) => ({
      cls: b.className.slice(0, 60), txt: b.innerText.replace(/\s+/g, ' ').trim().slice(0, 40), dis: b.disabled,
      tid: b.getAttribute('data-testid'),
    }));
    const title = ov.querySelector('h1,h2,h3,[class*="title"]')?.innerText?.replace(/\s+/g, ' ').trim().slice(0, 80);
    return { title, classesCount, btns: btns.slice(0, 20) };
  });
  console.log(`STEP ${s}: title="${info.title}"`);
  console.log('  classes:', JSON.stringify(info.classesCount));
  info.btns.forEach((b) => console.log(`  btn tid=${b.tid} dis=${b.dis} cls="${b.cls}" txt="${b.txt}"`));
  await page.screenshot({ path: `${OUT}_probe-wizard-step${s}.png` });
  // try to advance: click first enabled selectable then next
  const sel = await page.evaluate(() => {
    const ov = document.querySelector('.kiosk-wizard-overlay');
    const vis = (el) => el && el.offsetParent !== null;
    const cand = Array.from(ov.querySelectorAll('[class*="option"],[class*="card"],[class*="choice"],[role="radio"],[role="checkbox"]')).filter(vis).filter((el) => !el.className.includes('disabled'));
    if (cand[0]) { cand[0].click(); return cand[0].className.slice(0, 60); }
    return null;
  });
  console.log('  clicked selectable:', sel);
  await page.waitForTimeout(600);
  const adv = await page.evaluate(() => {
    const ov = document.querySelector('.kiosk-wizard-overlay');
    const vis = (el) => el && el.offsetParent !== null;
    const next = Array.from(ov.querySelectorAll('button')).filter(vis).find((b) => !b.disabled && /continuer|suivant|ajouter|valider|panier/i.test(b.innerText));
    if (next) { const t = next.innerText.trim().slice(0, 30); next.click(); return t; }
    return null;
  });
  console.log('  advanced via:', adv);
  await page.waitForTimeout(1200);
}
console.log('cart count:', await page.evaluate(() => { try { return JSON.parse(localStorage.vuex)?.kioskCart?.items?.length || 0; } catch { return 0; } }));
await browser.close();
