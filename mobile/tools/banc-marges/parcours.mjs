#!/usr/bin/env node
// Parcours complet de l'app avec relevé des marges à chaque écran (accueil de marque, présentation,
// accueil, carte, assistant de personnalisation de plusieurs plats, panier, connexion, code SMS,
// paiement, confirmation, commandes, détail, profil, suppression de compte, fidélité, recherche).
//
// Prérequis : l'app servie (python3 -m http.server 8797 dans mobile/) et le serveur local (:8766).
// Usage     : node mobile/tools/banc-marges/parcours.mjs <profil> [dossier-sortie] [url]
//   profils : iphone15 · iphone-se · pixel-geste · android-3b · android-ancien · sans-marge
// Sortie    : captures (zones interdites teintées) + journal.json ; code de sortie 1 s'il reste un
//             élément dans une marge, coupé sur un côté, ou une étape non atteinte.
// La commande, la liste et le détail des commandes sont SIMULÉS (aucune commande n'est créée) ; la
// connexion utilise le code de développement renvoyé par le serveur local.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, ouvrir, releve, taper, PROFILS } from './lib.mjs';

const profil = process.argv[2] || 'iphone15';
if (!PROFILS[profil]) { console.error('Profil inconnu : ' + profil + ' (' + Object.keys(PROFILS).join(', ') + ')'); process.exit(2); }
const dossier = path.resolve(process.argv[3] || path.join(path.dirname(fileURLToPath(import.meta.url)), 'sortie', profil));
const url = process.argv[4] || 'http://127.0.0.1:8797/index.html';
fs.mkdirSync(dossier, { recursive: true });

const browser = await chromium.launch();
const { page, p, cdpOk, erreurs } = await ouvrir(browser, profil, { url });
await page.evaluate(() => localStorage.setItem('lecayenne.dev', 'true')); await page.reload(); await page.waitForSelector('.lc-device');
const S = { page, p, dossier, journal: [], n: 1 };
const cmd = (id, statut, extra = {}) => ({ id, order_serial_no: 'ORD-900' + id + '-BC', queue_number: 'A04' + id, status_name: statut, order_datetime: '15 nov. 2026, 13:5' + id, total_amount_price: '30.10', total: 30.1, order_items_quantity: 4, loyalty_points_awarded: statut === 'Récupérée' ? 301 : 0, ...extra });
const lignes = [1, 2, 3, 4].map(i => ({ id: i, item_name: ['Tacos M', 'Menu Enfant Nuggets', 'Bol Frites', 'Bol Frites'][i - 1], quantity: 1, price: '7.90', total_currency_price: '7,90 €', total_convert_price: 7.9, item_variations: [], item_extras: [], instruction: 'Sauce Mayonnaise' }));
await page.context().route(/\/api\/frontend\/order(\?.*)?$/, async (route) => {
  const m = route.request().method();
  if (m === 'POST') return route.fulfill({ json: { status: true, data: cmd(2, 'En préparation') } });
  if (m === 'GET') return route.fulfill({ json: { status: true, data: [cmd(2, 'En préparation'), cmd(1, 'Récupérée'), cmd(0, 'Annulée')] } });
  return route.continue();
});
await page.context().route(/\/api\/frontend\/order\/show\/\d+/, (route) => route.fulfill({ json: { status: true, data: cmd(2, 'En préparation', { order_items: lignes, subtotal: 30.1 }) } }));
const etapesRatees = [];
const etape = async (nom, fn) => {
  try { await fn(); } catch (e) { etapesRatees.push(nom + ' : ' + String(e.message).split('\n')[0].slice(0, 160)); }
};
const ecran = () => page.evaluate(() => (document.querySelector('.lc-stage [data-screen-label]') || {}).dataset?.screenLabel || '?');
const onglet = (nom) => taper(page, page.locator('.lc-tab', { hasText: nom }));

// ── Démarrage : accueil de marque puis présentation ─────────────────────────────
await etape('splash', async () => { await releve(S, 'splash'); });
await etape('presentation', async () => {
  await page.waitForSelector('[data-screen-label="01 Onboarding"]', { timeout: 6000 });
  for (let i = 1; i <= 4; i++) {
    await releve(S, 'presentation-' + i);
    await taper(page, i === 4 ? 'button[aria-label="Commencer"]' : 'button[aria-label^="Écran suivant"]');
  }
});
// ── Accueil, carte ───────────────────────────────────────────────────────────────
await etape('accueil', async () => {
  if (!/Home|Accueil/i.test(await ecran())) { /* la présentation mène à l'accueil ou à la connexion */ }
  await page.waitForSelector('.lc-tabs', { timeout: 8000 });
  await releve(S, 'accueil');
});
await etape('carte', async () => { await onglet('Menu'); await page.waitForSelector('[data-screen-label="08 Menu"]'); await releve(S, 'carte'); });

// ── Personnalisation : on traverse chaque étape de l'assistant de plusieurs plats ─
async function traverserAssistant(nomPlat, etiquette) {
  await onglet('Menu').catch(() => {});
  await page.waitForSelector('[data-screen-label="08 Menu"]');
  await taper(page, page.locator('.lc-menu-card', { hasText: nomPlat }).first());
  await page.waitForFunction(() => /^09 /.test((document.querySelector('.lc-stage [data-screen-label]') || {}).dataset?.screenLabel || ''));
  await page.waitForTimeout(900);
  for (let i = 1; i <= 14; i++) {
    const titre = await ecran();
    if (!/^09 /.test(titre)) break;
    // choisir ce qui manque pour débloquer « Suivant »
    for (let essai = 0; essai < 4; essai++) {
      const bloque = await page.evaluate(() => { const b = document.querySelector('.rdw-cta'); return b ? (b.disabled || b.getAttribute('aria-disabled') === 'true') : false; });
      if (!bloque) break;
      const choix = page.locator('.rdw-choice:not(.is-on):not([disabled]):not([aria-disabled="true"])');
      if (!(await choix.count())) break;
      await choix.nth(essai % (await choix.count())).click({ timeout: 3000 }).catch((e) => etapesRatees.push('choix ' + etiquette + ' ' + titre + ' : ' + String(e.message).split('\n').slice(0, 3).join(' ')));
      await page.waitForTimeout(350);
    }
    await releve(S, `${etiquette}-etape${i}-${titre.replace(/^09 Item (Wizard )?/, '').replace(/[^a-zA-Z0-9À-ÿ]+/g, '_').slice(0, 24)}`);
    const cta = page.locator('.rdw-cta').last();
    const avant = titre;
    if (await page.evaluate(() => { const b = document.querySelector('.rdw-cta'); return !!b && b.disabled && /puis/.test(b.textContent); })) break;
    await cta.click({ timeout: 4000 }).catch(async (e) => { throw new Error('CTA bloqué à « ' + titre + ' » : ' + (await page.evaluate(() => (document.querySelector('#wizard-cta-hint') || {}).textContent || '(pas d\'indication)'))); });
    await page.waitForTimeout(700);
    const apres = await ecran();
    if (apres === avant) { const b = await page.evaluate(() => (document.querySelector('.rdw-cta') || {}).disabled); if (b) break; }
    if (/^10 /.test(apres)) break;
  }
}
const plats = await page.evaluate(() => [...document.querySelectorAll('.lc-menu-card')].map(e => (e.getAttribute('aria-label') || '').replace(/^Voir /, '').split(' — ')[0]));
fs.writeFileSync(path.join(dossier, 'plats.json'), JSON.stringify(plats, null, 1));
const viser = (re) => plats.find(n => re.test(n));
const cibles = [[viser(/tacos/i), 'tacos'], [viser(/menu|formule/i) || viser(/burger/i), 'menu'], [viser(/bol/i), 'bol'], [viser(/frites|tenders|nuggets/i), 'accompagnement'], [viser(/coca|boisson|eau|ice tea/i), 'boisson']];
for (const [nom, et] of cibles) {
  if (!nom) continue;
  await etape('assistant ' + et + ' (' + nom + ')', async () => {
    await traverserAssistant(nom, et);
    if (/^10 /.test(await ecran())) await taper(page, page.locator('button[aria-label*="Retour"], button[aria-label*="retour"]').first()).catch(() => {});
  });
  // revenir à la carte quoi qu'il arrive
  for (let k = 0; k < 16 && !(await page.locator('.lc-tabs').count()); k++) {
    await page.locator('button[aria-label*="etour"], button[aria-label*="Fermer"]').first().click({ timeout: 1500 }).catch(() => {});
    await page.waitForTimeout(300);
  }
}

// ── Panier, connexion, confirmation ──────────────────────────────────────────────
await etape('barre panier sur la carte', async () => { await onglet('Menu'); await page.waitForSelector('[data-testid="menu-cartbar"]', { timeout: 4000 }); await releve(S, 'carte-avec-panier'); });
await etape('panier', async () => { await taper(page, '[data-testid="menu-cartbar"] button'); await page.waitForSelector('[data-screen-label="10 Cart"]'); await releve(S, 'panier'); });
await etape('connexion', async () => {
  await taper(page, page.locator('[data-screen-label="10 Cart"] .lc-btn').last());
  await page.waitForSelector('[data-screen-label="05 Login"]', { timeout: 5000 });
  await releve(S, 'connexion-avec-message');
  await page.waitForTimeout(3800); // le message disparaît
  await releve(S, 'connexion');
  await page.fill('input[inputmode="tel"]', ({'iphone15':'0611110001','iphone-se':'0611110002','pixel-geste':'0611110003','android-3b':'0611110004','android-ancien':'0611110005','sans-marge':'0611110006'})[profil] || ('06111100' + (10 + Math.floor(Math.random() * 80))));
  await releve(S, 'connexion-saisie');
  await taper(page, page.locator('[data-screen-label="05 Login"] .lc-btn').last());
  await page.waitForSelector('[data-screen-label="06 OTP"]', { timeout: 8000 });
  await releve(S, 'code-sms');
  await taper(page, '[data-testid="otp-dev-code"]', { timeout: 5000 });
  await page.waitForFunction(() => !document.querySelector('[data-screen-label="06 OTP"]'), null, { timeout: 10000 });
});
await etape('choix du paiement', async () => {
  if (!(await page.locator('[data-modal-kind]').count())) {
    if (!(await page.locator('[data-screen-label="10 Cart"]').count())) { await onglet('Menu'); await taper(page, '[data-testid="menu-cartbar"] button'); }
    await taper(page, page.locator('[data-screen-label="10 Cart"] .lc-btn').last());
  }
  await page.waitForSelector('[data-testid="pay-counter"]', { timeout: 6000 });
  await releve(S, 'feuille-paiement');
});
await etape('confirmation', async () => {
  await taper(page, '[data-testid="pay-counter"]');
  await page.waitForSelector('[data-screen-label="11 Confirmation"]', { timeout: 15000 }).catch(async (e) => { throw new Error('pas de confirmation ; message : ' + (await page.evaluate(() => (document.querySelector('[data-testid="toast"]') || {}).textContent || '(aucun)'))); });
  await page.waitForTimeout(400);
  await releve(S, 'confirmation');
  await page.waitForTimeout(1200);
  if (await page.locator('[data-modal-kind="points-gain"]').count()) {
    await releve(S, 'points-gagnes');
    await page.locator('[data-modal-kind="points-gain"] button').last().click().catch(() => {}); await page.waitForTimeout(500);
  }
  await releve(S, 'confirmation-apres');
});
if (!(await page.locator('.lc-tabs').count())) {
  await etape('retour accueil', async () => { await taper(page, page.locator('[data-screen-label="11 Confirmation"] button').last()); await page.waitForTimeout(600); });
  if (!(await page.locator('.lc-tabs').count())) { await page.reload(); await page.waitForSelector('.lc-tabs', { timeout: 15000 }); }
}
// ── Commandes, détail, profil, fidélité, feuilles ────────────────────────────────
await etape('commandes', async () => { await onglet('Commandes'); await page.waitForSelector('[data-screen-label="12 Orders"]'); await page.waitForTimeout(1200); await releve(S, 'commandes'); });
await etape('detail commande', async () => {
  await taper(page, page.locator('[data-testid^="orders-active-card-"], [data-testid^="orders-history-card-"]').first());
  await page.waitForSelector('[data-screen-label="12b Order detail"]'); await page.waitForTimeout(900);
  await releve(S, 'commande-detail');
  await taper(page, page.locator('[data-screen-label="12b Order detail"] button').first());
});
await etape('profil', async () => { await onglet('Profil'); await page.waitForSelector('[data-screen-label="13 Profile"]'); await page.waitForTimeout(900); await releve(S, 'profil'); });
await etape('suppression de compte (feuille)', async () => {
  await page.keyboard.press('Escape'); await onglet('Profil'); await page.waitForTimeout(900);
  await page.evaluate(() => document.querySelector('[data-testid="delete-account-btn"]').scrollIntoView({ block: 'center', behavior: 'instant' }));
  await page.waitForTimeout(300);
  await taper(page, '[data-testid="delete-account-btn"]');
  await page.waitForSelector('[data-testid="modal-delete-account"]', { state: 'attached' }); await releve(S, 'feuille-suppression');
  await page.keyboard.press('Escape'); await page.waitForTimeout(500);
  if (await page.locator('[data-testid="modal-delete-account"]').count()) await page.locator('[data-testid="modal-delete-account"] button', { hasText: /annuler|garder|non/i }).first().click();
});
await etape('fidelite', async () => {
  await page.waitForTimeout(500);
  if (await page.locator('[data-modal-kind]').count()) throw new Error('une feuille est restée ouverte : ' + await page.locator('[data-modal-kind]').first().getAttribute('data-modal-kind'));
  await page.evaluate(() => document.querySelector('[aria-label^="Carte fidélité"]').scrollIntoView({ block: 'center', behavior: 'instant' }));
  await taper(page, page.locator('[data-screen-label="13 Profile"] [aria-label^="Carte fidélité"]'));
  await page.waitForSelector('[data-screen-label="14 Loyalty"]'); await page.waitForTimeout(1200);
  await releve(S, 'fidelite');
  const tabs = page.locator('[data-testid^="loyalty-tab-"]'); const nb = await tabs.count();
  for (let i = 1; i < nb; i++) { await tabs.nth(i).click(); await page.waitForTimeout(500); await releve(S, 'fidelite-onglet' + (i + 1)); }
});
await etape('fidelite : feuille de désinscription', async () => {
  const b = page.locator('[data-screen-label="14 Loyalty"] button', { hasText: /désactiver|désinscri|quitter/i }).first();
  await b.scrollIntoViewIfNeeded(); await b.click();
  await page.waitForSelector('[data-modal-kind]', { state: 'attached' }); await releve(S, 'feuille-desinscription');
  await page.keyboard.press('Escape'); await page.waitForTimeout(400);
});
await etape('recherche sur la carte', async () => {
  for (let k = 0; k < 6 && !(await page.locator('.lc-tabs').count()); k++) { await page.locator('button[aria-label*="etour"]').first().click({ timeout: 1500 }).catch(() => {}); await page.waitForTimeout(350); }
  await onglet('Menu'); await page.waitForSelector('[data-screen-label="08 Menu"]');
  await taper(page, page.locator('[data-screen-label="08 Menu"] [aria-label="Rechercher dans le menu"]').first());
  await page.waitForSelector('[data-testid="menu-search"]'); await page.fill('[data-testid="menu-search"]', 'tacos'); await releve(S, 'carte-recherche');
});
fs.writeFileSync(path.join(dossier, 'journal.json'), JSON.stringify({ profil, p, cdpOk, etapesRatees, erreurs, journal: S.journal }, null, 1));
await page.context().storageState({ path: path.join(dossier, 'etat.json') });
await browser.close();

// Résumé
let nbT = 0;
for (const j of S.journal) {
  const t = j.trouvailles;
  nbT += t.length;
  // Écart négatif au repos = un contenu fixe commence dans la marge (les feuilles et l'accueil de marque,
  // centrés, ne sont pas concernés).
  const drap = [];
  if (j.pos === 'debut' && !j.modale && j.ecartHaut != null && j.ecartHaut < 0) drap.push('écartHaut=' + j.ecartHaut);
  if (t.length || drap.length) console.log(`${j.capture.padEnd(52)} ${drap.join(' ')} ${t.map(x => `[${x.zone}/${x.genre} ${x.depasse}px «${x.texte.slice(0, 28)}»]`).join(' ')}`);
}
console.log(`\n${profil} : ${S.journal.length} relevés, ${nbT} éléments dans les marges, CDP marges=${cdpOk}, env=${S.journal[0]?.envTop || '(aucune)'}`);
if (etapesRatees.length) console.log('ÉTAPES RATÉES :\n - ' + etapesRatees.join('\n - '));
if (erreurs.length) console.log('ERREURS PAGE (' + erreurs.length + ') : ' + [...new Set(erreurs)].slice(0, 6).join(' | '));
process.exit(nbT > 0 || etapesRatees.length ? 1 : 0);
