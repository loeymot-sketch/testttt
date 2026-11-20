// C-captures — captures du quatuor (png/dom/console/network) de l'APPLICATION dans la coque, vague C,
// e2e-complet-2026-10-22. 11 états x 2 viewports (390x844 et 360x640). Pont corrigé de ./C-lib.js.
// Paramètres : C_SITE (défaut :8012 = paquet app/www construit depuis origin/main), C_TAG.
// Pour chaque état : zones tactiles < 44 px, texte tronqué, débordement horizontal, clés i18n brutes, valeurs
// « undefined/NaN/[object] », éléments fixes ancrés en haut/bas et prise en compte de safe-area-inset, boutons
// sans nom accessible. Les mesures vont dans metriques.json ; elles ne SONT PAS des verdicts (relecture à l'image).
const { test, expect, chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const L = require('./C-lib');

const SITE = process.env.C_SITE || 'http://127.0.0.1:8012';
const TAG = process.env.C_TAG || 'natif-8012';
const DIR = path.join(L.SHOTS_ROOT, 'captures-' + TAG);
const J = L.journal(DIR, 'metriques.json');
const VIEWPORTS = [{ n: '390', width: 390, height: 844 }, { n: '360', width: 360, height: 640 }];
const API = L.API + '/api';

function sql(requete) {
  const env = fs.readFileSync(path.resolve(__dirname, '../../../.env'), 'utf8');
  const mdp = ((env.match(/^DB_PASSWORD=(.*)$/m) || [])[1] || '').trim().replace(/^"|"$/g, '');
  return execFileSync('mysql', ['-uroot', '-h127.0.0.1', 'foodking_e2e', '-N', '-B', '-e', requete], { env: { ...process.env, MYSQL_PWD: mdp }, encoding: 'utf8' }).trim();
}

/** Mesures sur l'écran COURANT (éléments visibles dans la fenêtre). */
function mesures(page) {
  return page.evaluate(() => {
    const vw = innerWidth; const vh = innerHeight;
    const vis = (e) => {
      const r = e.getBoundingClientRect(); const s = getComputedStyle(e);
      return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0.05 && r.bottom > 0 && r.right > 0 && r.top < vh && r.left < vw;
    };
    const court = (e) => ((e.innerText || e.getAttribute('aria-label') || e.title || e.placeholder || '') + '').replace(/\s+/g, ' ').trim().slice(0, 40);
    const desc = (e) => ({ tag: e.tagName.toLowerCase(), cls: (e.className && e.className.toString ? e.className.toString() : '').split(' ').slice(0, 2).join(' '), texte: court(e) });
    const cibles = Array.from(document.querySelectorAll('button, a[href], input:not([type=hidden]), select, textarea, [role=button], [role=tab], [onclick]')).filter(vis)
      .filter((e) => !e.classList.contains('lc-skip-link'));
    const petites = cibles.map((e) => { const r = e.getBoundingClientRect(); return { ...desc(e), w: Math.round(r.width), h: Math.round(r.height), enTexte: !!e.closest('p, li, span') && e.tagName === 'A' }; })
      .filter((c) => c.w < 44 || c.h < 44);
    const tronques = Array.from(document.querySelectorAll('body *')).filter(vis).filter((e) => {
      const s = getComputedStyle(e);
      return (s.textOverflow === 'ellipsis' && e.scrollWidth > e.clientWidth + 2) || (s.webkitLineClamp && s.webkitLineClamp !== 'none' && e.scrollHeight > e.clientHeight + 2);
    }).map((e) => ({ ...desc(e), titre: e.title || null })).slice(0, 12);
    const debordeX = document.documentElement.scrollWidth > innerWidth + 1;
    const hors = Array.from(document.querySelectorAll('body *')).filter(vis).filter((e) => e.getBoundingClientRect().right > vw + 1 && getComputedStyle(e).position !== 'fixed').map((e) => ({ ...desc(e), droite: Math.round(e.getBoundingClientRect().right) })).slice(0, 8);
    const textes = []; const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (w.nextNode()) { const n = w.currentNode; const p = n.parentElement; if (!p || ['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(p.tagName) || !vis(p)) continue; const t = n.textContent.replace(/\s+/g, ' ').trim(); if (t) textes.push(t); }
    const cles = textes.filter((t) => /^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,4}$/i.test(t) && !/\.(fr|com|org|net|js|css|png|jpg|webp|html)$/i.test(t));
    const parasites = textes.filter((t) => /\bundefined\b|\bNaN\b|\[object Object\]|\bnull\b/.test(t));
    const regles = [];
    const collecter = (rs) => { for (const r of rs) { if (r.cssRules && !r.selectorText) collecter(r.cssRules); else if (r.selectorText && /safe-area-inset/.test(r.cssText)) regles.push(r); } };
    for (const ss of document.styleSheets) { try { collecter(ss.cssRules); } catch (_e) { /* feuille inter-origine */ } }
    const fixes = Array.from(document.querySelectorAll('body *')).filter((e) => getComputedStyle(e).position === 'fixed' && vis(e)).map((e) => {
      const r = e.getBoundingClientRect();
      const ancre = r.top <= 1 ? 'haut' : (r.bottom >= vh - 1 ? 'bas' : null);
      const surEnv = /safe-area-inset/.test(e.getAttribute('style') || '') || regles.some((x) => { try { return e.matches(x.selectorText); } catch (_e) { return false; } });
      return { ...desc(e), ancre, safeArea: surEnv, h: Math.round(r.height) };
    }).filter((f) => f.ancre);
    const sansNom = cibles.filter((e) => !court(e) && !e.querySelector('img[alt]:not([alt=""])') && !e.getAttribute('aria-labelledby')).map(desc).slice(0, 10);
    const alertes = Array.from(document.querySelectorAll('[role=alert], [role=status], .lc-toast, [class*=error]')).filter(vis).map((e) => court(e)).filter(Boolean).slice(0, 6);
    return { viewport: { vw, vh }, nbCibles: cibles.length, petites, tronques, debordeX, hors, cles, parasites, fixes, sansNom, alertes, nbReglesSafeArea: regles.length };
  });
}

let NAV = null;
test.setTimeout(240_000);
test.beforeAll(async () => { NAV = await chromium.launch({ channel: 'chromium', args: ['--lang=fr-FR'] }); });
test.afterAll(async () => { if (NAV) await NAV.close(); });

function onglet(page, libelle) {
  return page.locator('button.lc-navx-item', { has: page.locator('.lc-navx-lbl', { hasText: new RegExp('^' + libelle + '$') }) }).first();
}
async function passerUpsell(page) {
  for (let i = 0; i < 4; i++) {
    const b = page.getByRole('button', { name: /^\s*(Non merci(, continuer|, régler)?|Continuer sans|Pas maintenant)\b/ }).first();
    if (!(await b.isVisible().catch(() => false))) break;
    await b.click(); await page.waitForTimeout(600);
  }
}
async function creerJetable() {
  const { request } = require('@playwright/test');
  const api = await request.newContext();
  const h = { Origin: 'https://localhost', 'X-API-KEY': L.cleApiLocale(), Accept: 'application/json', 'Content-Type': 'application/json' };
  const tel = '06 12 ' + String(10 + Math.floor(Math.random() * 89)) + ' ' + String(10 + Math.floor(Math.random() * 89)) + ' ' + String(10 + Math.floor(Math.random() * 89));
  const otp = await api.post(API + '/auth/guest-signup/otp', { headers: h, data: { phone: tel, code: '+33' } });
  const code = (await otp.json()).dev_code;
  const ver = await api.post(API + '/auth/guest-signup/verify', { headers: h, data: { phone: tel, code: '+33', token: code, first_name: 'E2E-APP-Suppr', email: 'e2e-app-suppr-' + Date.now() + '@example.invalid' } });
  const v = await ver.json(); await api.dispose();
  return v && v.token ? { token: v.token, id: v.user.id } : null;
}

for (const vp of VIEWPORTS) {
  test(`C-CAP-${vp.n} états de l'application à ${vp.width}x${vp.height}`, async () => {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: vp.width, height: vp.height });
    const rec = L.enregistreur(page, DIR, true);
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 200)));
    const etat = async (nom, suite) => {
      const m = await mesures(page);
      J.note(`${vp.n}.${nom}`, m);
      await rec.snap(`${nom}-${vp.n}`);
      return m;
    };

    // 1 accueil
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(3000);
    await etat('01-accueil');
    // 2 carte
    await onglet(page, 'Menu').click(); await page.waitForTimeout(1500);
    await expect(page.locator('.lc-card-item-name').first()).toBeVisible({ timeout: 15_000 });
    await etat('02-carte');
    // 3 fiche / wizard
    await page.locator('button.lc-card-item-name:not([disabled])').first().click(); await page.waitForTimeout(1200);
    const perso = page.locator('button', { hasText: /^\s*Personnaliser/ }).first();
    if (await perso.count()) { await perso.click(); await page.waitForTimeout(1200); }
    await etat('03-wizard');
    // le wizard va jusqu'au panier
    for (let i = 0; i < 20; i++) {
      const cta = page.locator('.lc-wiz-foot-next').first();
      if (!(await cta.count())) break;
      const txt = ((await cta.innerText()) || '').replace(/\s+/g, ' ');
      if ((await cta.getAttribute('aria-disabled')) === 'true') { const o = page.locator('button.lc-wiz-choice:not(.is-on):visible').first(); if (!(await o.count())) break; await o.click(); await page.waitForTimeout(300); continue; }
      await cta.click(); await page.waitForTimeout(700);
      if (/Ajouter au panier/.test(txt)) break;
    }
    await page.waitForTimeout(800); await passerUpsell(page);
    // 4 panier
    const barre = page.locator('.lc-menu-cartbar');
    if (await barre.count()) await barre.click(); else await page.locator('.lc-nav-btn-cart').click();
    await page.waitForTimeout(1000);
    await etat('04-panier');

    // 5 compte déconnecté : étape e-mail (le panier est conservé : on revient le chercher après)
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(2000);
    await page.locator('.lc-nav-btn-account').first().click(); await page.waitForTimeout(1500);
    await etat('05-compte-deconnecte');
    // 6 inscription invité : e-mail inconnu -> prénom + téléphone
    const email = page.locator('input[type=email]').first();
    await email.fill('e2e-app-inconnu-' + vp.n + '-' + Date.now() + '@example.invalid');
    await page.getByRole('button', { name: /Recevoir mon code/ }).click(); await page.waitForTimeout(2000);
    await etat('06-compte-inscription-invite');
    // 7 e-mail connu : le serveur local ne peut pas envoyer l'e-mail (SMTP de dev injoignable) -> l'écran doit le DIRE
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(1500);
    await page.locator('.lc-nav-btn-account').first().click(); await page.waitForTimeout(1200);
    await page.locator('input[type=email]').first().fill('e2e-app-1@example.invalid');
    await page.getByRole('button', { name: /Recevoir mon code/ }).click(); await page.waitForTimeout(2500);
    await etat('07-compte-connu-erreur-envoi');

    // 8 fidélité / compte connecté
    await page.goto(SITE + '/index.html', { waitUntil: 'load' });
    await page.evaluate((t) => localStorage.setItem('lecayenne.authToken', t), L.jeton());
    await page.reload({ waitUntil: 'load' }); await page.waitForTimeout(2500);
    await page.locator('.lc-nav-btn-account').first().click(); await page.waitForTimeout(2000);
    await etat('08-fidelite-compte');
    // zone dangereuse : « Se déconnecter » / « Supprimer mon compte » (mesure des cibles tactiles)
    const supprimer = page.getByRole('button', { name: /^Supprimer mon compte$/ });
    await supprimer.scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(500);
    await supprimer.click(); await page.waitForTimeout(600);
    await page.getByRole('button', { name: /Oui, supprimer mon compte/ }).scrollIntoViewIfNeeded().catch(() => {});
    await etat('10-suppression-confirmation');
    await page.getByRole('button', { name: /^Annuler$/ }).click().catch(() => {});

    // 9 suivi de commande : une commande réelle depuis la coque (client E2E-APP-Client)
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(2000);
    const barre2 = page.locator('.lc-menu-cartbar');
    if (await barre2.count()) await barre2.click(); else await page.locator('.lc-nav-btn-cart').first().click();
    await page.waitForTimeout(1000);
    const commander = page.locator('.lc-cart-drawer button:visible', { hasText: /^\s*(Passer commande|Commander)/ }).first();
    if (await commander.count()) {
      await commander.click(); await page.waitForTimeout(1500); await passerUpsell(page);
      const note = page.locator('textarea').first();
      if (await note.count()) await note.fill(`E2E-APP- captures ${vp.n} (${TAG})`);
      const att = page.waitForResponse((r) => /\/api\/frontend\/order$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30_000 }).catch(() => null);
      await page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer ma commande/ }).click();
      const rep = await att; let c = null; try { c = rep ? await rep.json() : null; } catch (_e) { /* */ }
      J.note(`${vp.n}.commande`, { status: rep ? rep.status() : null, id: c && c.data ? c.data.id : null, total: c && c.data ? c.data.total : null });
      await page.waitForTimeout(2500);
      await etat('09a-confirmation');
      const suivre = page.locator('button', { hasText: /Suivre ma commande/ }).first();
      if (await suivre.count()) { await suivre.click(); await page.waitForTimeout(3000); }
      await etat('09-suivi');
    } else {
      J.note(`${vp.n}.09`, 'NON EXERCÉ : panier vide ou bouton Commander absent');
    }

    // 11 hors ligne
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(2500);
    await page.evaluate(() => window.__pont.emettre('Network', 'networkStatusChange', { connected: false, connectionType: 'none' }));
    await page.waitForTimeout(900);
    await etat('11-hors-ligne');

    J.note(`${vp.n}.pageerrors`, erreurs);
    rec.dispose(); await ctx.close();
  });
}

test('C-CAP-SUPPR suppression de compte DE BOUT EN BOUT dans l\'app (390x844) : bouton -> confirmation -> message -> base', async () => {
  const j = await creerJetable();
  test.skip(!j, 'compte jetable non créé (limiteur de vérification : attendre 5 min)');
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  const rec = L.enregistreur(page, DIR, /^12-/);
  await page.goto(SITE + '/index.html', { waitUntil: 'load' });
  await page.evaluate((t) => localStorage.setItem('lecayenne.authToken', t), j.token);
  await page.reload({ waitUntil: 'load' }); await page.waitForTimeout(2500);
  await page.locator('.lc-nav-btn-account').first().click(); await page.waitForTimeout(2000);
  const avant = sql(`select name, ifnull(email,'<NULL>') from users where id=${j.id}`);
  await page.getByRole('button', { name: /^Supprimer mon compte$/ }).click(); await page.waitForTimeout(500);
  await page.getByRole('button', { name: /Oui, supprimer mon compte/ }).click();
  await page.waitForTimeout(3500);
  await rec.snap('12-suppression-resultat-390');
  const apres = sql(`select name, ifnull(email,'<NULL>'), ifnull(deleted_at,'<NULL>') from users where id=${j.id}`);
  const texte = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
  const jeton = await page.evaluate(() => localStorage.getItem('lecayenne.authToken'));
  const appels = await page.evaluate(() => window.__pont.appels.map((a) => a.plugin + '.' + a.methode));
  J.note('SUPPR', { avant, apres, jetonLocalApres: jeton, messageSurAccueil: /Ton compte a été supprimé/.test(texte), extraitEcran: texte.slice(0, 200), appelsNatifs: appels.filter((a) => a.startsWith('LocalNotifications')) });
  expect(apres).toContain('Compte supprimé');
  expect(apres).not.toContain('example.invalid');
  expect(jeton, 'le jeton local est effacé après suppression').toBeNull();
  expect(texte).toMatch(/Ton compte a été supprimé/);
  rec.dispose(); await ctx.close();
});
