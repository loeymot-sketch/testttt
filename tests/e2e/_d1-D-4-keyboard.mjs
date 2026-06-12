// DISPUTE R1 vague D — scénario 4 : clavier virtuel (numpad téléphone fidélité + AZERTY email inscription)
import { chromium } from 'playwright';
import fs from 'fs';
import { BASE, OUT, attachRecorder, kioskBoot, enterFromIdle, addSimpleProduct, cartState } from './_d1-D-helper.mjs';

const log = [];
const L = (m) => { log.push(m); console.log(m); };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR', hasTouch: true });
const p = await ctx.newPage();
const rec = attachRecorder(p);

await kioskBoot(p);
await enterFromIdle(p);
await addSimpleProduct(p);
await p.goto(BASE + '/kiosk/cart', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(1300);

// ---------- 4a. Loyalty : saisie tel via numpad ----------
await p.locator('[data-testid="kiosk-cart-loyalty-btn"]').click();
await p.waitForTimeout(1500);
L(`loyalty → ${p.url().replace(BASE, '')}`);
const inp = p.locator('.kiosk-loyalty-input').first();
const inpVisible = await inp.isVisible().catch(() => false);
L(`input visible: ${inpVisible}`);
await rec.snap('d4-01-loyalty-empty');

// saisir 0612345678 via numpad
for (const d of '0612345678') {
  await p.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click().catch(e => L(`numpad ${d} err`));
  await p.waitForTimeout(70);
}
let val = await inp.inputValue().catch(() => '(?)');
L(`après saisie 0612345678 → input="${val}"`);
await rec.snap('d4-02-loyalty-typed');

// corriger : 3 suppressions puis retaper
const delBtn = p.locator('.kiosk-numpad-btn-del, .kiosk-numpad-btn:has-text("⌫"), [data-testid="kiosk-numpad-del"], button[aria-label*="upprimer"]').first();
const delFound = await delBtn.isVisible().catch(() => false);
L(`bouton suppression trouvé: ${delFound}`);
for (let i = 0; i < 3; i++) { await delBtn.click().catch(() => {}); await p.waitForTimeout(80); }
val = await inp.inputValue().catch(() => '(?)');
L(`après 3 del → input="${val}"`);
await rec.snap('d4-03-loyalty-deleted');
for (const d of '678') { await p.locator(`.kiosk-numpad-btn:has-text("${d}")`).first().click().catch(() => {}); await p.waitForTimeout(70); }
val = await inp.inputValue().catch(() => '(?)');
L(`après re-saisie 678 → input="${val}" (attendu 0612345678)`);

// valider (numéro inconnu en DB → erreur attendue / écran inscription)
await p.locator('.kiosk-btn-primary.full').first().click().catch(() => {});
await p.waitForTimeout(2500);
const err = await p.locator('.kiosk-loyalty-error').innerText().catch(() => '(pas de message)');
L(`validation numéro inconnu → message="${err.replace(/\n/g, ' ')}"`);
await rec.snap('d4-04-loyalty-validated');

// ---------- 4b. Écran inscription : AZERTY complet sur email ----------
const reg = p.locator('.kiosk-loyalty-register-btn');
if (await reg.isVisible().catch(() => false)) {
  await reg.click();
  await p.waitForTimeout(1500);
  L(`inscription → visible name=${await p.locator('[data-testid="kiosk-loyalty-register-name"]').isVisible().catch(() => false)}`);
  await rec.snap('d4-05-register-screen');

  // focus champ nom → clavier virtuel ?
  const nameInp = p.locator('[data-testid="kiosk-loyalty-register-name"]');
  await nameInp.click().catch(() => {});
  await p.waitForTimeout(900);
  const kbVisible1 = await p.locator('.ks-vkeyb, [data-testid="kiosk-virtual-keyboard"], .ks-virtual-keyboard').first().isVisible().catch(() => false);
  L(`clavier virtuel sur champ nom: ${kbVisible1}`);

  // layout du clavier : relever la 1re rangée de lettres
  const rows = await p.evaluate(() => {
    const kb = document.querySelector('.ks-vkeyb, [data-testid="kiosk-virtual-keyboard"], .ks-virtual-keyboard');
    if (!kb) return null;
    return kb.innerText.replace(/\n+/g, ' ').slice(0, 220);
  });
  L(`clavier contenu: "${rows}"`);
  await rec.snap('d4-06-keyboard-name');

  // taper "Jean" via clavier virtuel si présent, sinon fill
  const typeVk = async (txt, inputLoc) => {
    for (const ch of txt) {
      const key = p.locator(`.ks-vkeyb button:text-is("${ch}"), .ks-vkeyb button:text-is("${ch.toUpperCase()}"), .ks-virtual-keyboard button:text-is("${ch}")`).first();
      if (await key.isVisible().catch(() => false)) { await key.click(); await p.waitForTimeout(60); }
      else { L(`touche "${ch}" introuvable au clavier`); return false; }
    }
    return true;
  };
  let usedVk = false;
  if (kbVisible1) usedVk = await typeVk('jean', nameInp);
  if (!usedVk) { await nameInp.fill('Jean'); L('fallback fill(name)'); }
  L(`name value="${await nameInp.inputValue().catch(() => '?')}"`);

  // email : AZERTY complet attendu (lettres + @ + .)
  const emailInp = p.locator('[data-testid="kiosk-loyalty-register-email"]');
  if (await emailInp.isVisible().catch(() => false)) {
    await emailInp.click().catch(() => {});
    await p.waitForTimeout(900);
    const kbVisible2 = await p.locator('.ks-vkeyb, [data-testid="kiosk-virtual-keyboard"], .ks-virtual-keyboard').first().isVisible().catch(() => false);
    const kbTxt = await p.evaluate(() => {
      const kb = document.querySelector('.ks-vkeyb, [data-testid="kiosk-virtual-keyboard"], .ks-virtual-keyboard');
      return kb ? kb.innerText.replace(/\n+/g, ' ').slice(0, 300) : null;
    });
    L(`clavier sur email: visible=${kbVisible2} contenu="${kbTxt}"`);
    // layout AZERTY ? (1re rangée a z e r t y)
    if (kbTxt) {
      const flat = kbTxt.toLowerCase().replace(/\s+/g, '');
      L(`AZERTY detect: contient "azerty"=${flat.includes('azerty')} contient "qwerty"=${flat.includes('qwerty')} arobase=${kbTxt.includes('@')}`);
    }
    await rec.snap('d4-07-keyboard-email');
    let ok = false;
    if (kbVisible2) ok = await typeVk('jean@x.fr', emailInp);
    if (!ok) { await emailInp.fill('jean@x.fr'); L('fallback fill(email)'); }
    L(`email value="${await emailInp.inputValue().catch(() => '?')}"`);
    await rec.snap('d4-08-email-typed');
  } else L('champ email ABSENT de l écran inscription');

  // consent + submit si possible
  const consent = p.locator('[data-testid="kiosk-loyalty-register-consent"], input[type="checkbox"]').first();
  if (await consent.isVisible().catch(() => false)) { await consent.click().catch(() => {}); await p.waitForTimeout(300); }
  const submit = p.locator('[data-testid="kiosk-loyalty-register-submit"], .kiosk-loyalty-register-submit, button:has-text("Créer")').first();
  if (await submit.isVisible().catch(() => false)) {
    await submit.click().catch(() => {});
    await p.waitForTimeout(2500);
    L(`après submit inscription → ${p.url().replace(BASE, '')}`);
    const result = await p.evaluate(() => document.body.innerText.slice(0, 400));
    L(`texte: "${result.replace(/\n+/g, ' | ').slice(0, 300)}"`);
    await rec.snap('d4-09-register-submitted');
  } else L('bouton submit inscription introuvable');
} else {
  L('CTA inscription introuvable après numéro inconnu');
}

fs.writeFileSync(`${OUT}/_d4-keyboard-log.txt`, log.join('\n'));
await browser.close();
console.log('DONE-D4');
