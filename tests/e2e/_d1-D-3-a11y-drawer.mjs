// DISPUTE R1 vague D — scénario 3 : drawer accessibilité, chaque option, effet réel + retour normale
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

const htmlState = () => p.evaluate(() => ({
  contrast: document.documentElement.getAttribute('data-kiosk-contrast'),
  pmr: document.documentElement.getAttribute('data-kiosk-pmr'),
  audio: document.documentElement.getAttribute('data-kiosk-audio'),
  reducedMotion: document.documentElement.getAttribute('data-kiosk-reduced-motion'),
  htmlClass: document.documentElement.className,
  bodyClass: document.body.className,
  vars: {
    text: getComputedStyle(document.documentElement).getPropertyValue('--kiosk-text').trim(),
    primary: getComputedStyle(document.documentElement).getPropertyValue('--kiosk-primary').trim(),
    focusWidth: getComputedStyle(document.documentElement).getPropertyValue('--kiosk-focus-width').trim(),
    tapMin: getComputedStyle(document.documentElement).getPropertyValue('--kiosk-tap-min').trim(),
  },
}));

// mesure visuelle d'un élément clé (CTA idle) pour prouver l'effet PMR/contraste
const ctaMetrics = () => p.evaluate(() => {
  const el = document.querySelector('[data-testid="kiosk-order-type-takeaway"], [data-testid="kiosk-idle-touch-btn"]');
  if (!el) return null;
  const r = el.getBoundingClientRect();
  const cs = getComputedStyle(el);
  return { top: Math.round(r.top), height: Math.round(r.height), fontSize: cs.fontSize, bg: cs.backgroundColor, color: cs.color };
});

await kioskBoot(p);
await p.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2500);
L(`baseline html=${JSON.stringify(await htmlState())}`);
L(`baseline CTA=${JSON.stringify(await ctaMetrics())}`);
await rec.snap('d3-01-idle-baseline');

// ouvrir le drawer
await p.locator('[data-testid="kiosk-idle-a11y-btn"]').click({ force: true });
await p.waitForTimeout(900);
const drawerVisible = await p.locator('[data-testid="kiosk-a11y-drawer"]').isVisible().catch(() => false);
L(`drawer ouvert: ${drawerVisible}`);
const options = await p.evaluate(() => [...document.querySelectorAll('[data-testid="kiosk-a11y-drawer"] [data-testid]')].map(e => e.getAttribute('data-testid')));
L(`options présentes: ${JSON.stringify(options)}`);
await rec.snap('d3-02-drawer-open');

// --- contraste AAA ---
await p.locator('[data-testid="kiosk-a11y-contrast-aaa"]').click();
await p.waitForTimeout(700);
L(`après AAA: html=${JSON.stringify(await htmlState())}`);
const aaaChecked = await p.locator('[data-testid="kiosk-a11y-contrast-aaa"]').getAttribute('aria-checked');
L(`aria-checked AAA=${aaaChecked}`);
await rec.snap('d3-03-contrast-aaa-drawer');

// fermer pour voir l'effet plein écran
await p.locator('[data-testid="kiosk-a11y-done"]').click();
await p.waitForTimeout(600);
L(`AAA effet idle: CTA=${JSON.stringify(await ctaMetrics())} html=${JSON.stringify(await htmlState())}`);
await rec.snap('d3-04-contrast-aaa-idle');

// rouvrir → PMR ON
await p.locator('[data-testid="kiosk-idle-a11y-btn"]').click({ force: true });
await p.waitForTimeout(700);
await p.locator('[data-testid="kiosk-a11y-pmr-toggle"]').click();
await p.waitForTimeout(700);
L(`PMR toggle aria-checked=${await p.locator('[data-testid="kiosk-a11y-pmr-toggle"]').getAttribute('aria-checked')}`);
await p.locator('[data-testid="kiosk-a11y-done"]').click();
await p.waitForTimeout(800);
L(`PMR effet idle: CTA=${JSON.stringify(await ctaMetrics())} html=${JSON.stringify(await htmlState())}`);
await rec.snap('d3-05-pmr-on-idle');

// PMR effet sur le catalogue (reflow réel en usage)
await p.goto(BASE + '/kiosk/categories?cat=9', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1800);
L(`PMR catalogue: html=${JSON.stringify(await htmlState())}`);
await rec.snap('d3-06-pmr-on-catalogue');

// retour idle, rouvrir → audio ON, audio-desc ON, reduced motion ON
await p.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1800);
await p.locator('[data-testid="kiosk-idle-a11y-btn"]').click({ force: true });
await p.waitForTimeout(700);
for (const t of ['kiosk-a11y-audio-toggle', 'kiosk-a11y-audio-description-toggle', 'kiosk-a11y-reduced-motion-toggle']) {
  await p.locator(`[data-testid="${t}"]`).click();
  await p.waitForTimeout(500);
  L(`${t} aria-checked=${await p.locator(`[data-testid="${t}"]`).getAttribute('aria-checked')}`);
}
L(`tous toggles ON: html=${JSON.stringify(await htmlState())}`);
await rec.snap('d3-07-all-toggles-on');

// reduced motion : le carousel promo est-il figé ? (comparaison 2 captures du DOM promo)
await p.locator('[data-testid="kiosk-a11y-done"]').click();
await p.waitForTimeout(600);
const promoA = await p.evaluate(() => document.querySelector('.kiosk-promo-carousel, [class*="promo"]')?.innerText?.slice(0, 120) || '(pas de promo)');
await p.waitForTimeout(9000);
const promoB = await p.evaluate(() => document.querySelector('.kiosk-promo-carousel, [class*="promo"]')?.innerText?.slice(0, 120) || '(pas de promo)');
L(`reduced-motion promo: A="${promoA.replace(/\n/g, ' | ')}" B="${promoB.replace(/\n/g, ' | ')}" identique=${promoA === promoB}`);
await rec.snap('d3-08-reduced-motion-idle');

// --- RESET → retour à la normale ---
await p.locator('[data-testid="kiosk-idle-a11y-btn"]').click({ force: true });
await p.waitForTimeout(700);
await p.locator('[data-testid="kiosk-a11y-reset"]').click();
await p.waitForTimeout(700);
const post = await htmlState();
L(`après RESET: html=${JSON.stringify(post)}`);
const ariaAfterReset = await p.evaluate(() => ({
  aa: document.querySelector('[data-testid="kiosk-a11y-contrast-aa"]')?.getAttribute('aria-checked'),
  aaa: document.querySelector('[data-testid="kiosk-a11y-contrast-aaa"]')?.getAttribute('aria-checked'),
  pmr: document.querySelector('[data-testid="kiosk-a11y-pmr-toggle"]')?.getAttribute('aria-checked'),
  audio: document.querySelector('[data-testid="kiosk-a11y-audio-toggle"]')?.getAttribute('aria-checked'),
  audioDesc: document.querySelector('[data-testid="kiosk-a11y-audio-description-toggle"]')?.getAttribute('aria-checked'),
  motion: document.querySelector('[data-testid="kiosk-a11y-reduced-motion-toggle"]')?.getAttribute('aria-checked'),
}));
L(`aria après reset: ${JSON.stringify(ariaAfterReset)}`);
await rec.snap('d3-09-after-reset');
await p.locator('[data-testid="kiosk-a11y-done"]').click();
await p.waitForTimeout(600);
L(`final idle: CTA=${JSON.stringify(await ctaMetrics())} html=${JSON.stringify(await htmlState())}`);
await rec.snap('d3-10-idle-back-to-normal');

// focus management + Escape
await p.locator('[data-testid="kiosk-idle-a11y-btn"]').click({ force: true });
await p.waitForTimeout(700);
const focused = await p.evaluate(() => document.activeElement?.className || '(rien)');
L(`focus initial drawer: "${focused}"`);
await p.keyboard.press('Escape');
await p.waitForTimeout(600);
L(`Escape ferme: drawer visible=${await p.locator('[data-testid="kiosk-a11y-drawer"]').isVisible().catch(() => false)}`);

fs.writeFileSync(`${OUT}/_d3-a11y-drawer-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D3');
