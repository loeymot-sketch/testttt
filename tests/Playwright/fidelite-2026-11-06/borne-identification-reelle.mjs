// Contrôle visuel RÉEL de l'écran fidélité de la borne (serveur privé :8781, base foodking_points).
import { chromium } from '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/node_modules/playwright/index.mjs';
import { execSync } from 'child_process';
const S = '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/d8f87420-2dda-4fc6-a18a-1d5c61abf968/scratchpad';
const B = 'http://127.0.0.1:8781';
const sql = (q) => execSync(`mysql -uroot -h127.0.0.1 -N -B foodking_points -e "${q}"`).toString().trim();
const email = sql(`select email from users where id=1157`);
let echecs = 0; const v = (n, ok, d = '') => { console.log((ok ? '  ✓ ' : '  ✗ ') + n + (ok || !d ? '' : '  → ' + d)); if (!ok) echecs++; };

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1080, height: 1920 }, locale: 'fr-FR' })).newPage();
const err = []; page.on('pageerror', (e) => err.push(String(e.message).slice(0, 160)));
await page.goto(B + '/kiosk/idle', { waitUntil: 'load' });
await page.waitForTimeout(5000);
await page.goto(B + '/kiosk/cart', { waitUntil: 'load' });
await page.waitForTimeout(2500);
// Contrôle VISUEL de l'écran fidélité : un article fictif dans le panier EN MÉMOIRE (aucune commande créée), puis le routeur.
const ok = await page.evaluate(() => {
  const el = document.querySelector('#app'); const app = el && el.__vue_app__; if (!app) return 'pas d\'app';
  const gp = app.config.globalProperties; const st = gp.$store.state.kioskCart;
  st.items.push({ id: 101, item_id: 101, name: 'Big Burger', price: 9, quantity: 1, total: 9, variations: [], extras: [], addons: [], cartKey: 'banc-visuel' });
  gp.$router.push({ name: 'kiosk.loyalty' }); return 'ok:' + st.items.length;
});
console.log('  · panier en mémoire :', ok);
await page.waitForSelector('[data-testid="kiosk-loyalty-mode-tel"]', { timeout: 20000 }).catch(() => {});
v('écran fidélité : 3 onglets visibles', (await page.locator('[data-testid^="kiosk-loyalty-mode-"]').count()) === 3, page.url());
await page.screenshot({ path: S + '/borne-1-tel.png' });
await page.click('[data-testid="kiosk-loyalty-mode-qr"]');
await page.waitForTimeout(500);
v('onglet QR : champ de scan focalisé', await page.evaluate(() => document.activeElement?.getAttribute('data-testid') === 'kiosk-loyalty-qr-input'));
await page.screenshot({ path: S + '/borne-2-qr.png' });
await page.click('[data-testid="kiosk-loyalty-mode-email"]');
await page.waitForTimeout(500);
await page.screenshot({ path: S + '/borne-3-email.png' });
await page.fill('[data-testid="kiosk-loyalty-email-input"]', email);
await page.keyboard.press('Enter');
await page.waitForTimeout(3000);
const t = await page.evaluate(() => document.body.innerText);
v('identification RÉELLE par e-mail : solde du client affiché', /720|1720|points/i.test(t) && !/introuvable|Aucun compte/i.test(t), t.slice(0, 200).replace(/\n/g, ' | '));
v('aucune erreur JavaScript', err.length === 0, err.join(' | '));
await page.screenshot({ path: S + '/borne-4-solde.png' });
await nav.close();
console.log(echecs ? `✗ ${echecs}` : '✓ borne fidélité vérifiée');
