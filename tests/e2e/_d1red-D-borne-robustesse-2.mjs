// DISPUTE R1 vague D — ADVERSAIRE probe 2bis : « Animations réduites » — store Vuex LIVE + aria + attr
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

const readState = () => p.evaluate(() => {
  const st = document.querySelector('#app')?.__vue_app__?.config?.globalProperties?.$store?.state?.kioskSettings || null;
  return {
    attr: document.documentElement.getAttribute('data-kiosk-reduced-motion'),
    attrAudioDesc: document.documentElement.getAttribute('data-kiosk-audio-description'),
    store: st ? { reducedMotion: st.reducedMotion, audioDescription: st.audioDescription, contrast: st.contrast, pmr: st.pmr } : null,
  };
});

await p.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2500);
L(`baseline: ${JSON.stringify(await readState())}`);

await p.locator('[data-testid="kiosk-a11y-open"], .ks-a11y-trigger, button[aria-label*="ccessibilit"]').first().click({ force: true }).catch(() => {});
await p.waitForTimeout(900);
const switches = p.locator('.ks-a11y-drawer [role="switch"]');
const nb = await switches.count();
for (let i = 0; i < nb; i++) {
  const lbl = await switches.nth(i).getAttribute('aria-label').catch(() => null);
  const checked = await switches.nth(i).getAttribute('aria-checked').catch(() => null);
  L(`switch[${i}] aria-label=${lbl} aria-checked=${checked}`);
}
// cible explicite : le switch dont le label contient Animations
const anim = p.locator('.ks-a11y-drawer [role="switch"][aria-label*="nimations"], .ks-a11y-drawer [role="switch"]').last();
await anim.click().catch(() => {});
await p.waitForTimeout(700);
L(`après clic: aria-checked=${await anim.getAttribute('aria-checked').catch(() => '?')} state=${JSON.stringify(await readState())}`);
await rec.snap('d1red-06-reduced-motion-store-live');

await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2800);
L(`après F5: ${JSON.stringify(await readState())}`);

fs.writeFileSync(`${OUT}/_d1red-probe2-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D1RED2');
