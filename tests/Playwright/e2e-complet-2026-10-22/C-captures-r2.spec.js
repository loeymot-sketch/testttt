// C-captures-r2 — TOUR 2 de la vague C (e2e-complet-2026-10-22). Copie de C-captures.spec.js AVEC DE VRAIES ASSERTIONS
// (C-105 : l'original n'avait que 4 `expect`, « 3/3 verts » voulait dire « rien n'a levé »).
//
// Ce qui est asserté, état par état (chaque état = 1 appel à `verifier`) :
//   - TEXTE ATTENDU visible (locators Playwright `toBeVisible` + regex sur le texte visible),
//   - 0 clé i18n brute, 0 « undefined / NaN / [object Object] / null » dans le texte visible,
//   - 0 débordement horizontal (scrollWidth) ET 0 élément non rogné dépassant à droite,
//   - CTA principal de l'état : largeur ET hauteur MESURÉES >= 44 px,
//   - (soft) AUCUNE zone tactile < 44x44 hors lien en ligne -> reproduit C-007 (boutons − / + de 28 px) tant qu'il est ouvert,
//   - 0 erreur JS de page.
// Plus : états ajoutés par la revue adverse (C-104 panier vide, C-103 barre d'état) avec contraste CALCULÉ (WCAG),
// et un test « C-R2-MORD » qui casse volontairement chaque catégorie d'assertion pour prouver qu'elle mord.
// Casser le vrai flux : R2_CASSER=<nom d'état>:<cle|texte|debord|cta> (ex. R2_CASSER=04-panier:cles).
// Budget disque : au plus 6 PNG neufs (regex PNG_OK).
const { test, expect, chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const L = require('./C-lib-r2');

const SITE = process.env.C_SITE || 'http://127.0.0.1:8012';
const TAG = process.env.C_TAG || 'natif-8012';
const DIR = path.join(L.SHOTS_ROOT, 'captures-' + TAG);
const J = L.journal(DIR, 'metriques-r2.json');
const VIEWPORTS = [{ n: '390', width: 390, height: 844 }, { n: '360', width: 360, height: 640 }];
const API = L.API + '/api';
const PNG_OK = /^(04-panier-390|13a-panier-vide-accueil-390|13b-panier-vide-menu-390|14a-barre-menu-390|14b-barre-accueil-390|12-suppression-resultat-390)$/;
const CASSER = (process.env.R2_CASSER || '').split(':'); // [état, catégorie]

function sql(requete) {
  const env = fs.readFileSync(path.resolve(__dirname, '../../../.env'), 'utf8');
  const mdp = ((env.match(/^DB_PASSWORD=(.*)$/m) || [])[1] || '').trim().replace(/^"|"$/g, '');
  return execFileSync('mysql', ['-uroot', '-h127.0.0.1', 'foodking_e2e', '-N', '-B', '-e', requete], { env: { ...process.env, MYSQL_PWD: mdp }, encoding: 'utf8' }).trim();
}

/** Mesures sur l'écran COURANT. `cta` : liste de regex (source) du texte des boutons principaux à mesurer. */
function mesures(page, cta) {
  // LARGEUR DE RÉFÉRENCE = largeur de l'APPAREIL émulé (page.viewportSize), PAS innerWidth : en émulation mobile le
  // viewport de mise en page S'ÉLARGIT dès qu'un élément déborde (innerWidth 390 -> 690), ce qui rendait le test
  // « scrollWidth > innerWidth » du tour 1 AVEUGLE (instrument défaillant, découvert au tour 2 par l'injection d'un défaut).
  const ref = page.viewportSize().width;
  return page.evaluate(({ ctaSrc, ref }) => {
    const vw = ref; const vh = innerHeight;
    const vis = (e) => {
      const r = e.getBoundingClientRect(); const s = getComputedStyle(e);
      return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0.05 && r.bottom > 0 && r.right > 0 && r.top < vh && r.left < vw;
    };
    const court = (e) => ((e.innerText || e.getAttribute('aria-label') || e.title || e.placeholder || '') + '').replace(/\s+/g, ' ').trim().slice(0, 40);
    const desc = (e) => ({ tag: e.tagName.toLowerCase(), cls: (e.className && e.className.toString ? e.className.toString() : '').split(' ').slice(0, 2).join(' '), texte: court(e) });
    const cibles = Array.from(document.querySelectorAll('button, a[href], input:not([type=hidden]), select, textarea, [role=button], [role=tab], [onclick]')).filter(vis)
      .filter((e) => !e.classList.contains('lc-skip-link'));
    const petites = cibles.map((e) => { const r = e.getBoundingClientRect(); return { ...desc(e), w: Math.round(r.width), h: Math.round(r.height), enTexte: e.tagName === 'A' && !!e.parentElement && (e.parentElement.innerText || '').replace(/\s+/g, ' ').trim().length > (e.innerText || '').trim().length + 12 }; })
      .filter((c) => c.w < 44 || c.h < 44);
    const tronques = Array.from(document.querySelectorAll('body *')).filter(vis).filter((e) => {
      const s = getComputedStyle(e);
      return (s.textOverflow === 'ellipsis' && e.scrollWidth > e.clientWidth + 2) || (s.webkitLineClamp && s.webkitLineClamp !== 'none' && e.scrollHeight > e.clientHeight + 2);
    }).map((e) => ({ ...desc(e), titre: e.title || null })).slice(0, 12);
    const debordeX = document.documentElement.scrollWidth > ref + 1 || innerWidth > ref + 1;
    // dépassement à droite NON rogné par un ancêtre INTERNE overflow-x:hidden/clip/auto/scroll (marquee = rogné). body/html ne comptent PAS : s'ils rognent (overflow-x:hidden), scrollWidth est aveugle et le contenu est coupé en silence.
    const rogne = (e) => { for (let p = e.parentElement; p && p !== document.body && p !== document.documentElement; p = p.parentElement) { const s = getComputedStyle(p); if (/hidden|clip|auto|scroll/.test(s.overflowX) && p.getBoundingClientRect().right <= vw + 1) return true; } return false; };
    const horsNonRognes = Array.from(document.querySelectorAll('body *')).filter(vis).filter((e) => e.getBoundingClientRect().right > vw + 1 && getComputedStyle(e).position !== 'fixed' && !rogne(e)).map((e) => ({ ...desc(e), droite: Math.round(e.getBoundingClientRect().right) })).slice(0, 8);
    const textes = []; const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (w.nextNode()) { const n = w.currentNode; const p = n.parentElement; if (!p || ['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(p.tagName) || !vis(p)) continue; const t = n.textContent.replace(/\s+/g, ' ').trim(); if (t) textes.push(t); }
    const cles = textes.filter((t) => /^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,4}$/i.test(t) && !/\.(fr|com|org|net|js|css|png|jpg|webp|html)$/i.test(t));
    const parasites = textes.filter((t) => /\bundefined\b|\bNaN\b|\[object Object\]|\bnull\b/.test(t));
    const rendu = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
    const toutes = Array.from(document.querySelectorAll('button, a[href], [role=button]')).filter(rendu); // CTA possiblement sous la ligne de flottaison
    const ctas = (ctaSrc || []).map((src) => {
      const re = new RegExp(src, 'i');
      const e = toutes.find((c) => re.test((c.innerText || c.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim()));
      if (!e) return { src, trouve: false };
      const r = e.getBoundingClientRect(); return { src, trouve: true, w: Math.round(r.width), h: Math.round(r.height) };
    });
    return { viewport: { vw, vh }, nbCibles: cibles.length, petites, tronques, debordeX, horsNonRognes, cles, parasites, ctas, texteVisible: textes.join(' | '), texteRendu: (document.body.innerText || '').replace(/[\u00a0\u202f\s]+/g, ' ').replace(/[\u2019]/g, "'") };
  }, { ctaSrc: cta || [], ref });
}

/** Verdicts durs (liste de violations) + verdict tactile (soft). Fonction PURE : réutilisée par le test « mord ». */
function verifier(m, att) {
  const v = [];
  for (const re of (att.texte || [])) if (!new RegExp(re.source, 'i').test(m.texteRendu)) v.push('texte attendu absent : ' + re);
  if (m.cles.length) v.push('clé i18n brute : ' + JSON.stringify(m.cles));
  if (m.parasites.length) v.push('valeur parasite : ' + JSON.stringify(m.parasites));
  if (m.debordeX) v.push('débordement horizontal (scrollWidth ou innerWidth > largeur de l\'appareil émulé)');
  if (m.horsNonRognes.length) v.push('élément non rogné hors écran à droite : ' + JSON.stringify(m.horsNonRognes));
  for (const c of m.ctas) {
    if (!c.trouve) v.push('CTA introuvable : ' + c.src);
    else if (c.w < 44 || c.h < 44) v.push(`CTA ${c.src} trop petit : ${c.w}x${c.h}`);
  }
  const tactile = m.petites.filter((p) => !p.enTexte).map((p) => `${p.tag}.${p.cls} « ${p.texte} » ${p.w}x${p.h}`);
  return { v, tactile };
}

/** Contraste WCAG du texte d'un élément contre son fond EFFECTIF (ancêtres composités). */
function contrasteDe(page, selecteur, texte) {
  return page.evaluate(({ selecteur, texte }) => {
    const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); const p = m[1].split(',').map((x) => parseFloat(x)); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
    const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
    const els = Array.from(document.querySelectorAll(selecteur));
    const e = els.find((x) => x.children.length === 0 && (!texte || new RegExp(texte).test(x.textContent)));
    if (!e) return { trouve: false };
    const fg0 = parse(getComputedStyle(e).color);
    let bg = { r: 255, g: 255, b: 255, a: 0 }; const pile = [];
    for (let p = e; p; p = p.parentElement) { const s = getComputedStyle(p); const c = parse(s.backgroundColor); if (s.backgroundImage !== 'none') pile.push('image:' + p.tagName); if (c.a > 0) { pile.push(s.backgroundColor + '@' + p.tagName + '.' + (p.className || '').toString().split(' ')[0]); if (c.a >= 1) { bg = c; break; } } }
    if (bg.a === 0) bg = { r: 255, g: 255, b: 255, a: 1 };
    const fg = { r: fg0.r * fg0.a + bg.r * (1 - fg0.a), g: fg0.g * fg0.a + bg.g * (1 - fg0.a), b: fg0.b * fg0.a + bg.b * (1 - fg0.a) };
    const a = lum(fg); const b = lum(bg); const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    return { trouve: true, couleur: getComputedStyle(e).color, fond: `rgb(${bg.r},${bg.g},${bg.b})`, ratio: Math.round(ratio * 100) / 100, texte: e.textContent.trim().slice(0, 40), pile, bodyClass: document.body.className };
  }, { selecteur, texte });
}

/** Contraste de la couleur d'icône `icone` {r,g,b} contre la bande supérieure (hautPx) d'une capture, décodée en mémoire. */
async function contrasteBande(page, buffer, icone, hautPx) {
  const b64 = buffer.toString('base64');
  return page.evaluate(async ({ b64, icone, hautPx }) => {
    const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
    const k = img.naturalWidth / innerWidth; const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = Math.round(hautPx * k);
    const g = c.getContext('2d'); g.drawImage(img, 0, 0); const d = g.getImageData(0, 0, c.width, c.height).data;
    const compte = {}; for (let i = 0; i < d.length; i += 4) { const cle = d[i] + ',' + d[i + 1] + ',' + d[i + 2]; compte[cle] = (compte[cle] || 0) + 1; }
    const top = Object.entries(compte).sort((a, b) => b[1] - a[1])[0][0].split(',').map(Number);
    const lum = (r, gg, b) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(gg) + 0.0722 * f(b); };
    const a = lum(icone.r, icone.g, icone.b); const bgL = lum(top[0], top[1], top[2]);
    return { fond: top, ratio: Math.round(((Math.max(a, bgL) + 0.05) / (Math.min(a, bgL) + 0.05)) * 100) / 100 };
  }, { b64, icone, hautPx });
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
  const ver = await api.post(API + '/auth/guest-signup/verify', { headers: h, data: { phone: tel, code: '+33', token: code, first_name: 'E2E-APP-Suppr', email: 'e2e-app-suppr-r2-' + Date.now() + '@example.invalid' } });
  const v = await ver.json(); await api.dispose();
  return v && v.token ? { token: v.token, id: v.user.id } : null;
}
async function ouvrirPanier(page) {
  const barre = page.locator('.lc-menu-cartbar');
  if (await barre.count()) await barre.click(); else await page.locator('.lc-nav-btn-cart').first().click();
  await page.waitForTimeout(1000);
}

for (const vp of VIEWPORTS) {
  test(`C-R2-CAP-${vp.n} états de l'application à ${vp.width}x${vp.height} (assertions réelles)`, async () => {
    const { ctx, page } = await L.contexte(NAV, 'android', { width: vp.width, height: vp.height });
    const rec = L.enregistreur(page, DIR, PNG_OK);
    const erreurs = [];
    page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 200)));
    const tactileTout = [];
    let nbAssertions = 0;
    /** att : { texte:[regex], cta:[regexSrc], visibles:[()=>locator] } */
    const etat = async (nom, att = {}) => {
      if (CASSER[0] === nom) { // cassage volontaire du vrai flux (preuve que l'assertion mord)
        await page.evaluate((cat) => {
          const d = document.createElement('div'); d.id = 'r2-casse';
          if (cat === 'cles') { d.textContent = 'cart.empty_label'; d.style.cssText = 'position:fixed;top:50px;left:0;background:#fff;color:#000;z-index:99999'; }
          if (cat === 'debord') { d.style.cssText = 'position:absolute;top:0;left:0;width:' + (innerWidth + 300) + 'px!important;max-width:none!important;height:5px;background:red'; }
          document.body.appendChild(d);
        }, CASSER[1]);
        if (CASSER[1] === 'texte') att = { ...att, texte: [...(att.texte || []), /ZZZ-TEXTE-INEXISTANT/] };
      }
      const m = await mesures(page, att.cta);
      J.note(`${vp.n}.${nom}`, m);
      await rec.snap(`${nom}-${vp.n}`);
      for (const f of (att.visibles || [])) { nbAssertions++; await expect.soft(f(page).first(), `${vp.n} ${nom} : élément attendu visible`).toBeVisible(); }
      const { v, tactile } = verifier(m, att);
      nbAssertions += (att.texte || []).length + 4 + m.ctas.length;
      expect.soft(v, `${vp.n} ${nom}`).toEqual([]);
      tactile.forEach((t) => tactileTout.push(`${nom}: ${t}`));
      return m;
    };

    // 1 accueil
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(3000);
    await etat('01-accueil', { texte: [/Le sandwich autrement/, /Voir le menu/], cta: ['^Voir le menu'] });
    // 2 carte
    await onglet(page, 'Menu').click(); await page.waitForTimeout(1500);
    await expect(page.locator('.lc-card-item-name').first()).toBeVisible({ timeout: 15_000 });
    await etat('02-carte', { texte: [/Menu complet/, /Catégories/, /Sandwichs/] });
    // 3 wizard
    await page.locator('button.lc-card-item-name:not([disabled])').first().click(); await page.waitForTimeout(1200);
    const perso = page.locator('button', { hasText: /^\s*Personnaliser/ }).first();
    if (await perso.count()) { await perso.click(); await page.waitForTimeout(1200); }
    await etat('03-wizard', { visibles: [(p) => p.locator('.lc-wiz-foot-next')] });
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
    await ouvrirPanier(page);
    await etat('04-panier', { texte: [/Panier/, /1 article/, /Sous-total/, /Total\s*\d+,\d{2}\s*€/], cta: ['^Passer commande'], visibles: [(p) => p.locator('.lc-cart-drawer.is-open')] });

    // 5 compte déconnecté
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(2000);
    await page.locator('.lc-nav-btn-account').first().click(); await page.waitForTimeout(1500);
    await etat('05-compte-deconnecte', { texte: [/Recevoir mon code par e-mail/, /1 € = 10 points/], cta: ['^Recevoir mon code'], visibles: [(p) => p.locator('input[type=email]')] });
    // 6 inscription invité
    await page.locator('input[type=email]').first().fill('e2e-app-inconnu-' + vp.n + '-' + Date.now() + '@example.invalid');
    await page.getByRole('button', { name: /Recevoir mon code/ }).click(); await page.waitForTimeout(2000);
    await etat('06-compte-inscription-invite', { texte: [/Téléphone/, /Créer mon compte et recevoir le code/], cta: ['^Créer mon compte'] });
    // 7 e-mail connu : SMTP de dev injoignable -> l'écran DOIT le dire, en français
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(1500);
    await page.locator('.lc-nav-btn-account').first().click(); await page.waitForTimeout(1200);
    await page.locator('input[type=email]').first().fill('e2e-app-1@example.invalid');
    await page.getByRole('button', { name: /Recevoir mon code/ }).click(); await page.waitForTimeout(2500);
    await etat('07-compte-connu-erreur-envoi', { texte: [/Le code n'a pas pu être envoyé/, /Réessaie dans un instant/], cta: ['^Recevoir mon code'] });
    expect.soft(await page.evaluate(() => document.body.innerText), 'aucun fragment anglais « (and » à l\'écran (C-101)').not.toMatch(/\(and \d|more errors?\)/);

    // 8 fidélité / compte connecté
    await page.goto(SITE + '/index.html', { waitUntil: 'load' });
    await page.evaluate((t) => localStorage.setItem('lecayenne.authToken', t), L.jeton());
    await page.reload({ waitUntil: 'load' }); await page.waitForTimeout(2500);
    await page.locator('.lc-nav-btn-account').first().click(); await page.waitForTimeout(2000);
    await etat('08-fidelite-compte', { texte: [/Mon solde/, /Présente ton QR à la caisse/], visibles: [(p) => p.getByRole('button', { name: /^Supprimer mon compte$/ })] });
    const supprimer = page.getByRole('button', { name: /^Supprimer mon compte$/ });
    await supprimer.scrollIntoViewIfNeeded().catch(() => {}); await page.waitForTimeout(500);
    await supprimer.click(); await page.waitForTimeout(600);
    await page.getByRole('button', { name: /Oui, supprimer mon compte/ }).scrollIntoViewIfNeeded().catch(() => {});
    await etat('10-suppression-confirmation', { cta: ['Oui, supprimer mon compte', '^Annuler$'], visibles: [(p) => p.getByRole('button', { name: /Oui, supprimer mon compte/ }), (p) => p.getByRole('button', { name: /^Annuler$/ })] });
    await page.getByRole('button', { name: /^Annuler$/ }).click().catch(() => {});

    // 9 suivi : commande réelle depuis la coque (client E2E-APP-Client)
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(2000);
    await ouvrirPanier(page);
    const commander = page.locator('.lc-cart-drawer button:visible', { hasText: /^\s*(Passer commande|Commander)/ }).first();
    if (await commander.count()) {
      await commander.click(); await page.waitForTimeout(1500); await passerUpsell(page);
      const note = page.locator('textarea').first();
      if (await note.count()) await note.fill(`E2E-APP- r2 captures ${vp.n} (${TAG})`);
      const att = page.waitForResponse((r) => /\/api\/frontend\/order$/.test(r.url()) && r.request().method() === 'POST', { timeout: 30_000 }).catch(() => null);
      await page.locator('button.lcf-cta-bar-next', { hasText: /Confirmer ma commande/ }).click();
      const rep = await att; let c = null; try { c = rep ? await rep.json() : null; } catch (_e) { /* */ }
      J.note(`${vp.n}.commande`, { status: rep ? rep.status() : null, id: c && c.data ? c.data.id : null, total: c && c.data ? c.data.total : null });
      expect.soft(rep ? rep.status() : null, 'commande 201').toBe(201);
      await page.waitForTimeout(2500);
      await etat('09a-confirmation', { texte: [/C'est parti/, /N°A\d+/, /Total\s*\d+,\d{2}\s*€/, /Envoyée au restaurant/] });
      const suivre = page.locator('button', { hasText: /Suivre ma commande/ }).first();
      if (await suivre.count()) { await suivre.click(); await page.waitForTimeout(3000); }
      await etat('09-suivi', { texte: [/Suivi de ta commande/, /Réf\./, /Total\s*\d+,\d{2}\s*€/, /Cayenne/] });
    } else {
      J.note(`${vp.n}.09`, 'NON EXERCÉ : panier vide ou bouton Commander absent');
      expect.soft(false, 'bouton Commander absent : la commande n\'a pas pu être exercée').toBe(true);
    }

    // 11 hors ligne
    await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(2500);
    await page.evaluate(() => window.__pont.emettre('Network', 'networkStatusChange', { connected: false, connectionType: 'none' }));
    await page.waitForTimeout(900);
    await etat('11-hors-ligne', { visibles: [(p) => p.locator('#lc-hors-ligne')] });

    J.note(`${vp.n}.pageerrors`, erreurs);
    J.note(`${vp.n}.nbAssertionsDeclarees`, nbAssertions);
    J.note(`${vp.n}.tactileSousSeuil`, tactileTout);
    expect.soft(erreurs, 'aucune erreur JS de page').toEqual([]);
    expect.soft(tactileTout, `zones tactiles < 44x44 hors lien en ligne (${vp.n}) — C-007`).toEqual([]);
    rec.dispose(); await ctx.close();
  });
}

// ---------------------------------------------------------------------------------------------------------------
// États ajoutés suite à la revue adverse du tour 1.
// ---------------------------------------------------------------------------------------------------------------
test('C-R2-VIDE panier VIDE : contraste calculé du titre « PANIER » et de « Ton panier est vide » (C-104), accueil (nuit) ET menu (jour)', async () => {
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  const rec = L.enregistreur(page, DIR, PNG_OK);
  await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(3000);
  const res = {};
  // (a) accueil : <body class="lcx-nuit">
  await page.locator('.lc-nav-btn-cart').first().click(); await page.waitForTimeout(1000);
  res.accueil = { titre: await contrasteDe(page, '.lc-cart-title', 'Panier'), vide: await contrasteDe(page, '.lc-cart-body div', '^Ton panier est vide$'), secondaire: await contrasteDe(page, '.lc-cart-body div', '^Faim \\? Va voir le menu\\.$') };
  await rec.snap('13a-panier-vide-accueil-390');
  await page.locator('button[aria-label="Fermer le panier"]').click(); await page.waitForTimeout(500);
  // (b) onglet Menu : pas de lcx-nuit sur <body>
  await onglet(page, 'Menu').click(); await page.waitForTimeout(1500);
  await page.locator('.lc-nav-btn-cart').first().click(); await page.waitForTimeout(1000);
  res.menu = { titre: await contrasteDe(page, '.lc-cart-title', 'Panier'), vide: await contrasteDe(page, '.lc-cart-body div', '^Ton panier est vide$'), secondaire: await contrasteDe(page, '.lc-cart-body div', '^Faim \\? Va voir le menu\\.$') };
  await rec.snap('13b-panier-vide-menu-390');
  J.note('C-104.contrastes', res);
  const lignes = [];
  for (const [ou, o] of Object.entries(res)) for (const [qui, c] of Object.entries(o)) { expect(c.trouve, `${ou}.${qui} trouvé`).toBe(true); lignes.push(`${ou}.${qui} ${c.ratio}:1 (${c.couleur} sur ${c.fond}, body=${c.bodyClass || '∅'})`); }
  J.note('C-104.lignes', lignes);
  // WCAG 1.4.3 : 4.5:1 (texte normal). Un ÉCHEC ici = C-104 reproduit.
  for (const [ou, o] of Object.entries(res)) {
    expect.soft(o.titre.ratio, `${ou} : titre « PANIER » du tiroir vide`).toBeGreaterThanOrEqual(4.5);
    expect.soft(o.vide.ratio, `${ou} : « Ton panier est vide »`).toBeGreaterThanOrEqual(4.5);
    expect.soft(o.secondaire.ratio, `${ou} : « Faim ? Va voir le menu. »`).toBeGreaterThanOrEqual(4.5);
  }
  rec.dispose(); await ctx.close();
});

test('C-R2-BARRE barre d\'état (C-103) : appels natifs au démarrage + contraste calculé des icônes claires contre ce qui est dessous', async () => {
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  const rec = L.enregistreur(page, DIR, PNG_OK);
  await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(3000);
  const sb = await page.evaluate(() => window.__pont.appels.filter((a) => a.plugin === 'StatusBar').map((a) => a.methode + JSON.stringify(a.options)));
  J.note('C-103.appelsStatusBar', sb);
  expect(sb, 'démarrage : style DARK = icônes claires, fond demandé #0A0A0A, pas de recouvrement').toEqual(['setStyle{"style":"DARK"}', 'setBackgroundColor{"color":"#0A0A0A"}', 'setOverlaysWebView{"overlay":false}']);
  const ICONE = { r: 255, g: 255, b: 255 }; // style DARK => icônes claires
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const rapport = (a, b) => (Math.max(lum(a), lum(b)) + 0.05) / (Math.min(lum(a), lum(b)) + 0.05);
  const mesure = {};
  // (1) ce que l'app DEMANDE : #0A0A0A
  mesure.fondDemande = Math.round(rapport([255, 255, 255], [10, 10, 10]) * 100) / 100;
  // (2) PROXY Chromium : si la barre est transparente (edge-to-edge forcé, targetSdk 36), ce qui est DESSOUS est le haut de la page
  const accueil = await page.screenshot({ type: 'png' });
  mesure.dessousAccueil = await contrasteBande(page, accueil, ICONE, 24);
  await rec.snap('14b-barre-accueil-390');
  await onglet(page, 'Menu').click(); await page.waitForTimeout(1500);
  const menu = await page.screenshot({ type: 'png' });
  mesure.dessousMenu = await contrasteBande(page, menu, ICONE, 24);
  await rec.snap('14a-barre-menu-390');
  // (3) MESURE SUR APPAREIL (émulateur Android 36, tour 1) : bande supérieure des PNG existants
  const dev = path.resolve(__dirname, '../../e2e/__screenshots__/e2e-complet-C/device-android');
  mesure.appareil = {};
  for (const f of ['panier-ouvert.png', '03b-tiroir.png', '04-hors-ligne.png', 'apres-retour-panier.png']) mesure.appareil[f] = null;
  // la bande d'état de l'appareil = 3 % de la hauteur de l'image (1080x2400 : 72 px)
  for (const f of Object.keys(mesure.appareil)) {
    const buf = fs.readFileSync(path.join(dev, f)); const b64 = buf.toString('base64');
    mesure.appareil[f] = await page.evaluate(async ({ b64 }) => {
      const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
      const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = Math.round(img.naturalHeight * 0.03);
      const g = c.getContext('2d'); g.drawImage(img, 0, 0); const d = g.getImageData(0, 0, c.width, c.height).data;
      const compte = {}; for (let i = 0; i < d.length; i += 4) { const k = d[i] + ',' + d[i + 1] + ',' + d[i + 2]; compte[k] = (compte[k] || 0) + 1; }
      const top = Object.entries(compte).sort((a, b) => b[1] - a[1])[0][0].split(',').map(Number);
      const lum = (r, gg, b) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(gg) + 0.0722 * f(b); };
      const a = lum(255, 255, 255); const bl = lum(top[0], top[1], top[2]);
      return { fond: top, ratio: Math.round(((Math.max(a, bl) + 0.05) / (Math.min(a, bl) + 0.05)) * 100) / 100 };
    }, { b64 });
  }
  J.note('C-103.mesures', mesure);
  // WCAG 1.4.11 (éléments graphiques) : 3:1. Un ÉCHEC = C-103 reproduit.
  expect(mesure.fondDemande, 'le fond DEMANDÉ est lisible (contrôle : l\'intention du code est bonne)').toBeGreaterThanOrEqual(3);
  for (const [f, r] of Object.entries(mesure.appareil)) expect.soft(r.ratio, `appareil réel (émulateur API 36) ${f} : icônes claires sur ${r.fond}`).toBeGreaterThanOrEqual(3);
  expect.soft(mesure.dessousMenu.ratio, 'proxy Chromium : haut de l\'onglet Menu sous une barre transparente').toBeGreaterThanOrEqual(3);
  expect.soft(mesure.dessousAccueil.ratio, 'proxy Chromium : haut de l\'accueil (nuit) sous une barre transparente').toBeGreaterThanOrEqual(3);
  rec.dispose(); await ctx.close();
});

// ---------------------------------------------------------------------------------------------------------------
// Preuve que les assertions MORDENT : on casse volontairement chaque catégorie sur une vraie page et on exige
// que `verifier` le signale ; la page intacte doit rester propre sur les catégories dures.
// ---------------------------------------------------------------------------------------------------------------
test('C-R2-MORD chaque catégorie d\'assertion détecte un défaut injecté (et se tait sur la page saine)', async () => {
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  await page.goto(SITE + '/index.html', { waitUntil: 'load' }); await page.waitForTimeout(3000);
  const att = { texte: [/Le sandwich autrement/], cta: ['^Voir le menu'] };
  const sain = verifier(await mesures(page, att.cta), att);
  J.note('MORD.sain', sain);
  expect(sain.v, 'page saine : aucune violation dure').toEqual([]);
  const casses = {};
  const injecter = async (nom, fn, attX = att) => { await page.evaluate(fn); const r = verifier(await mesures(page, attX.cta), attX); casses[nom] = r; await page.evaluate(() => document.querySelectorAll('.r2-mord').forEach((e) => e.remove())); return r; };
  let r = await injecter('cle-i18n', () => { const d = document.createElement('p'); d.className = 'r2-mord'; d.textContent = 'cart.empty_label'; document.body.prepend(d); });
  expect(r.v.join('|')).toMatch(/clé i18n brute/);
  r = await injecter('undefined', () => { const d = document.createElement('p'); d.className = 'r2-mord'; d.textContent = 'Total undefined €'; document.body.prepend(d); });
  expect(r.v.join('|')).toMatch(/valeur parasite/);
  r = await injecter('debordement', () => { const d = document.createElement('div'); d.className = 'r2-mord'; d.style.cssText = 'position:absolute;top:0;left:0;width:' + (innerWidth + 300) + 'px!important;max-width:none!important;height:4px'; document.body.appendChild(d); });
  // NB : html ET body ont overflow-x:clip et le viewport mobile s'élargit : l'ancien test scrollWidth>innerWidth était aveugle (voir mesures()).
  expect(r.v.join('|')).toMatch(/débordement horizontal/);
  expect(r.v.join('|')).toMatch(/hors écran à droite/);
  r = await injecter('cta-trop-petit', () => { const b = Array.from(document.querySelectorAll('a,button')).find((x) => /^\s*Voir le menu/.test(x.innerText)); b.dataset.r2 = b.getAttribute('style') || ''; b.style.cssText += ';height:20px!important;min-height:0!important;width:30px!important;min-width:0!important;overflow:hidden;display:inline-block'; });
  expect(r.v.join('|')).toMatch(/trop petit/);
  r = await injecter('texte-absent', () => {}, { texte: [/ZZZ-INEXISTANT/], cta: [] });
  expect(r.v.join('|')).toMatch(/texte attendu absent/);
  await page.reload({ waitUntil: 'load' }); await page.waitForTimeout(2500);
  // tactile : un bouton de 20 px doit apparaître dans `tactile`
  await page.evaluate(() => { const b = document.createElement('button'); b.className = 'r2-mord'; b.textContent = '+'; b.style.cssText = 'position:fixed;top:300px;left:10px;width:20px;height:20px'; document.body.appendChild(b); });
  r = verifier(await mesures(page, []), {}); casses.tactile = r;
  expect(r.tactile.join('|')).toMatch(/« \+ » 20x\d+/);
  J.note('MORD.casses', Object.fromEntries(Object.entries(casses).map(([k, v]) => [k, { violations: v.v.length, tactile: v.tactile.length, extrait: (v.v[0] || v.tactile[0] || '').slice(0, 110) }])));
  await ctx.close();
});

test('C-R2-SUPPR suppression de compte DE BOUT EN BOUT dans l\'app (390x844) : bouton -> confirmation -> message -> base', async () => {
  const j = await creerJetable();
  test.skip(!j, 'compte jetable non créé (limiteur de vérification : attendre 5 min)');
  const { ctx, page } = await L.contexte(NAV, 'android', { width: 390, height: 844 });
  const rec = L.enregistreur(page, DIR, PNG_OK);
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
  const nbJetons = sql(`select count(*) from personal_access_tokens where tokenable_id=${j.id}`);
  const texte = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
  const jeton = await page.evaluate(() => localStorage.getItem('lecayenne.authToken'));
  J.note('SUPPR', { id: j.id, avant, apres, nbJetons, jetonLocalApres: jeton, messageSurAccueil: /Ton compte a été supprimé/.test(texte) });
  expect(avant).toContain('E2E-APP-Suppr');
  expect(apres).toContain('Compte supprimé');
  expect(apres).not.toContain('example.invalid');
  expect(apres.split('\t')[2], 'deleted_at posé').not.toBe('<NULL>');
  expect(Number(nbJetons), 'plus aucun jeton').toBe(0);
  expect(jeton, 'le jeton local est effacé après suppression').toBeNull();
  expect(texte).toMatch(/Ton compte a été supprimé/);
  rec.dispose(); await ctx.close();
});
