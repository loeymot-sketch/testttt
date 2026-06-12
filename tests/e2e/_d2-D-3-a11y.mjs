// DISPUTE R2 vague D — vérif heals ADV-F-P1-3 (idle LIGHT) + D-009 (libellés sans jargon) + D-005 (Animations réduites EFFET RÉEL + persistance) + D-006 (toast Session rafraîchie silencé)
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot } from './_d2-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);
const bodyText = () => p.evaluate(() => document.body.innerText).catch(() => '(KO)');

const motionState = () => p.evaluate(() => {
  const html = document.documentElement;
  // élément animé représentatif : CTA idle (transition) + un ::before éventuel
  const cta = document.querySelector('[data-testid="kiosk-idle-touch-btn"], [data-testid="kiosk-order-type-takeaway"], button');
  const cs = cta ? getComputedStyle(cta) : null;
  return {
    attr: html.getAttribute('data-kiosk-reduced-motion'),
    hasClass: html.classList.contains('ks-reduced-motion'),
    varFast: getComputedStyle(html).getPropertyValue('--kiosk-duration-fast').trim(),
    varIdle: getComputedStyle(html).getPropertyValue('--kiosk-duration-idle').trim(),
    ctaTransition: cs ? cs.transitionDuration : null,
    ctaAnimation: cs ? cs.animationDuration : null,
    ls: localStorage.getItem('foodking:kiosk-a11y-motion'),
  };
});

const idleBg = () => p.evaluate(() => {
  const fb = document.querySelector('.kiosk-idle-fallback');
  const root = document.querySelector('.kiosk-idle, [class*="kiosk-idle"]');
  const title = document.querySelector('.kiosk-idle h1, .kiosk-idle-title, [class*="idle-title"]');
  const out = {};
  if (fb) { const c = getComputedStyle(fb); out.fallback = { backgroundImage: c.backgroundImage.slice(0, 220), backgroundColor: c.backgroundColor }; }
  if (root) { const c = getComputedStyle(root); out.root = { backgroundColor: c.backgroundColor, className: root.className.slice(0, 80) }; }
  if (title) { out.titleColor = getComputedStyle(title).color; }
  out.hasVideoClass = !!document.querySelector('.kiosk-idle--has-video');
  return out;
});

await kioskBoot(p);

// ---------- 3a. ADV-F-P1-3 : idle attract LIGHT ----------
await p.goto(BASE + '/kiosk/idle', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2800);
L(`IDLE-LIGHT computed: ${JSON.stringify(await idleBg())}`);
await rec.snap('d2D3-01-idle-light-mode');

// ---------- 3b. D-006 : event kiosk-auth-retried → AUCUN toast client ----------
const before006 = await bodyText();
await p.evaluate(() => window.dispatchEvent(new CustomEvent('kiosk-auth-retried', { detail: { probe: 'd2D3' } })));
await p.waitForTimeout(1500);
const after006 = await bodyText();
const toastVisible = /Session rafraîchie/i.test(after006) && !/Session rafraîchie/i.test(before006);
L(`D-006 toast « Session rafraîchie » visible après event: ${toastVisible}`);
await rec.snap('d2D3-02-auth-retried-no-toast');

// ---------- 3c. drawer : libellés sans jargon (D-009) ----------
await p.locator('[data-testid="kiosk-idle-a11y-btn"]').click({ force: true });
await p.waitForTimeout(900);
const drawerTxt = await p.evaluate(() => document.querySelector('[data-testid="kiosk-a11y-drawer"]')?.innerText || '(drawer introuvable)');
const jargonHits = (drawerTxt.match(/WCAG|EAA|AAA\s*•|7:1|\(FR\/EN\)|2\.3\.3|2\.2/gi) || []);
L(`D-009 drawer texte (${drawerTxt.length}c): jargon hits=${JSON.stringify(jargonHits)}`);
L(`drawer libellés: "${drawerTxt.replace(/\n+/g, ' | ').slice(0, 800)}"`);
await rec.snap('d2D3-03-drawer-labels-sober');

// ---------- 3d. D-005 : toggle Animations réduites → EFFET RÉEL LIVE ----------
L(`motion AVANT toggle: ${JSON.stringify(await motionState())}`);
await p.locator('[data-testid="kiosk-a11y-reduced-motion-toggle"]').click();
await p.waitForTimeout(800);
const onState = await motionState();
L(`motion APRÈS toggle ON (LIVE, sans F5): ${JSON.stringify(onState)}`);
await p.locator('[data-testid="kiosk-a11y-done"]').click();
await p.waitForTimeout(600);
L(`motion après fermeture drawer: ${JSON.stringify(await motionState())}`);
await rec.snap('d2D3-04-reduced-motion-on-live');

// ---------- 3e. D-005 : persistance au reload ----------
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(3000);
const afterF5 = await motionState();
L(`motion APRÈS F5: ${JSON.stringify(afterF5)}`);
await rec.snap('d2D3-05-reduced-motion-after-f5');

// ---------- 3f. reset → retour normale ----------
await p.locator('[data-testid="kiosk-idle-a11y-btn"]').click({ force: true });
await p.waitForTimeout(800);
await p.locator('[data-testid="kiosk-a11y-reset"]').click();
await p.waitForTimeout(700);
const afterReset = await motionState();
L(`motion APRÈS reset: ${JSON.stringify(afterReset)}`);
await p.locator('[data-testid="kiosk-a11y-done"]').click().catch(() => {});
await p.waitForTimeout(500);
await rec.snap('d2D3-06-after-reset');
// reload → reset persiste aussi (pas de résurrection)
await p.reload({ waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2500);
L(`motion après reset+F5: ${JSON.stringify(await motionState())}`);
await rec.snap('d2D3-07-reset-persists');

fs.writeFileSync(`${OUT}/_d2D-3-a11y-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D2D3');
