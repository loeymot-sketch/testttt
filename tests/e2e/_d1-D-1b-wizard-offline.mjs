// DISPUTE R1 vague D — focus : wizard ouvert + coupure réseau (repro fine du close silencieux)
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, openWizard, cartState } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

await kioskBoot(p);
await enterFromIdle(p);

const tid = await openWizard(p);
L(`wizard ouvert via ${tid} — cart=${JSON.stringify(await cartState(p))}`);
const stepInfo = async () => p.evaluate(() => {
  const t = document.querySelector('.kiosk-wizard-overlay');
  if (!t) return null;
  const title = t.querySelector('h1,h2,h3,.kiosk-wizard-step-title,.kiosk-step-title')?.innerText || '?';
  const next = t.querySelector('.kiosk-btn-next');
  return { title: title.slice(0, 80), nextLabel: next?.innerText?.trim().slice(0, 40), nextDisabled: next?.disabled ?? null };
});
L(`étape AVANT offline: ${JSON.stringify(await stepInfo())}`);
await rec.snap('d1b-01-wizard-online');

await ctx.setOffline(true);
L('OFFLINE=true — clic 1 option');
await p.locator('.kiosk-option-card:not(.disabled), .kiosk-viande-card:not(.disabled)').first().click().catch(e => L('option click err: ' + e));
await p.waitForTimeout(800);
L(`après option: overlay=${await p.locator('.kiosk-wizard-overlay').isVisible().catch(() => false)} step=${JSON.stringify(await stepInfo())}`);
await rec.snap('d1b-02-wizard-offline-option');

L('clic next (1)');
await p.locator('.kiosk-btn-next').first().click().catch(e => L('next click err: ' + e));
await p.waitForTimeout(1200);
L(`après next1: overlay=${await p.locator('.kiosk-wizard-overlay').isVisible().catch(() => false)} step=${JSON.stringify(await stepInfo())} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d1b-03-wizard-offline-next1');

if (await p.locator('.kiosk-wizard-overlay').isVisible().catch(() => false)) {
  for (let s = 2; s <= 6; s++) {
    await p.locator('.kiosk-option-card:not(.disabled), .kiosk-viande-card:not(.disabled)').first().click().catch(() => {});
    await p.waitForTimeout(500);
    await p.locator('.kiosk-btn-next').first().click().catch(() => {});
    await p.waitForTimeout(1000);
    const ov = await p.locator('.kiosk-wizard-overlay').isVisible().catch(() => false);
    L(`après next${s}: overlay=${ov} step=${JSON.stringify(await stepInfo())} cart=${JSON.stringify(await cartState(p))}`);
    if (!ov) break;
  }
  await rec.snap('d1b-04-wizard-offline-end');
}

// le toast d'erreur est-il visible quelque part ?
const toasts = await p.evaluate(() => [...document.querySelectorAll('.kiosk-toast, [class*="toast"]')].map(t => t.innerText.replace(/\n/g, ' ').slice(0, 120)));
L(`toasts visibles: ${JSON.stringify(toasts)}`);
L(`URL finale: ${p.url().replace(BASE, '')} cart=${JSON.stringify(await cartState(p))}`);
await rec.snap('d1b-05-final');
await ctx.setOffline(false);

fs.writeFileSync(`${OUT}/_d1b-wizard-offline-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D1B');
