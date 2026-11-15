// Banc « marges d'écran » de l'app mobile Le Cayenne.
// Mesure, écran par écran, ce qui tombe dans la zone de la barre d'état / de l'encoche (haut), dans celle
// de la barre d'accueil ou de navigation (bas), et ce qui est coupé sur les côtés.
//
// Les marges du téléphone sont simulées de deux façons, comme sur de vrais appareils :
//   - env(safe-area-inset-*) via le protocole du navigateur (iOS, Android récent) ;
//   - --safe-area-inset-* posées sur <html>, comme le fait Capacitor (SystemBars) sur un Android plus ancien.
// Mode d'emploi : voir parcours.mjs.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
function trouverPlaywright() {
  // Playwright n'est pas une dépendance de mobile/ : on prend celui du dépôt (ou du dépôt voisin testttt).
  for (const base of [path.resolve(ICI, '../..'), path.resolve(ICI, '../../..'), path.resolve(ICI, '../../../../testttt')]) {
    try { return createRequire(path.join(base, 'package.json'))('playwright'); } catch (_) { /* suivant */ }
  }
  throw new Error('playwright introuvable (npm i -D playwright dans le dépôt)');
}
export const { chromium } = trouverPlaywright();

export const PROFILS = {
  // largeur, hauteur, marge haute (barre d'état / encoche), marge basse (barre d'accueil / navigation)
  'iphone15':   { w: 393, h: 852, top: 59, bottom: 34, ua: 'ios' },
  'iphone-se':  { w: 375, h: 667, top: 20, bottom: 0,  ua: 'ios' },
  'pixel-geste':{ w: 412, h: 915, top: 48, bottom: 24, ua: 'android' },
  'android-3b': { w: 360, h: 740, top: 24, bottom: 48, ua: 'android' },
  // Android plus ancien : env() vaut 0, c'est Capacitor (SystemBars) qui pose --safe-area-inset-*.
  'android-ancien': { w: 412, h: 915, top: 40, bottom: 24, ua: 'android', viaCapacitor: true },
  // Aucune marge (navigateur, ou enveloppe native qui réserve déjà la barre d'état).
  'sans-marge': { w: 390, h: 760, top: 0, bottom: 0, ua: 'android' },
};

export async function ouvrir(browser, profil, { url, storageState } = {}) {
  const p = PROFILS[profil];
  const ctx = await browser.newContext({
    viewport: { width: p.w, height: p.h }, deviceScaleFactor: 2, hasTouch: true, isMobile: true,
    locale: 'fr-FR', storageState,
  });
  const page = await ctx.newPage();
  page.setDefaultTimeout(8000);
  const cdp = await ctx.newCDPSession(page);
  let cdpOk = true;
  if (p.viaCapacitor) {
    cdpOk = 'capacitor';
    await ctx.addInitScript((p) => { const poser = () => { if (!document.documentElement) return; document.documentElement.style.setProperty('--safe-area-inset-top', p.top + 'px'); document.documentElement.style.setProperty('--safe-area-inset-bottom', p.bottom + 'px'); }; poser(); new MutationObserver((_, o) => { if (document.documentElement) { poser(); o.disconnect(); } }).observe(document, { childList: true }); document.addEventListener('DOMContentLoaded', poser); }, p);
  } else {
    try {
      await cdp.send('Emulation.setSafeAreaInsetsOverride', { insets: { top: p.top, bottom: p.bottom, left: 0, right: 0 } });
    } catch (e) { cdpOk = false; }
  }
  const erreurs = [];
  page.on('pageerror', e => erreurs.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') erreurs.push('console: ' + m.text().slice(0, 200)); });
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForSelector('.lc-device', { timeout: 20000 });
  return { ctx, page, p, cdpOk, erreurs };
}

// Mesure dans la page. ins = { top, bottom }.
export function mesureDansLaPage(ins) {
  const H = innerHeight, W = innerWidth;
  const bandes = [['haut', 0, ins.top], ['bas', H - ins.bottom, H]];
  const vus = new Map();
  const visibleChaine = (el) => {
    for (let e = el; e && e !== document.documentElement; e = e.parentElement) {
      const cs = getComputedStyle(e);
      if (cs.visibility === 'hidden' || cs.display === 'none' || Number(cs.opacity) < 0.05) return false;
    }
    return true;
  };
  const auPoint = (el, x, y) => {
    const t = document.elementFromPoint(Math.min(W - 1, Math.max(1, x)), Math.min(H - 1, Math.max(0, y)));
    return !!t && (t === el || el.contains(t) || t.contains(el));
  };
  // Partie réellement dessinée : le rectangle rogné par tous les ancêtres qui coupent leur contenu.
  const rogner = (el, r) => {
    let x0 = r.left, x1 = r.right, y0 = r.top, y1 = r.bottom;
    for (let e = el.parentElement; e && e !== document.documentElement; e = e.parentElement) {
      const cs = getComputedStyle(e);
      if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible' || /paint|strict|content/.test(cs.contain)) {
        const b = e.getBoundingClientRect();
        x0 = Math.max(x0, b.left); x1 = Math.min(x1, b.right); y0 = Math.max(y0, b.top); y1 = Math.min(y1, b.bottom);
      }
    }
    return { left: x0, right: x1, top: y0, bottom: y1, width: x1 - x0, height: y1 - y0 };
  };
  const noter = (genre, el, rectBrut, texte) => {
    const rect = rogner(el, rectBrut);
    if (rect.width < 2 || rect.height < 2) return;
    for (const [nom, b0, b1] of bandes) {
      if (b1 - b0 < 1) continue;
      // Bas : un contenu qui DÉFILE encore sous la barre d'accueil est normal (il remonte) ;
      // seul compte ce qui y reste une fois arrivé en fin de défilement, ou ce qui est fixe.
      if (nom === 'bas') {
        let d = el.parentElement;
        while (d && !(/(auto|scroll)/.test(getComputedStyle(d).overflowY) && d.scrollHeight > d.clientHeight + 4)) d = d.parentElement;
        if (d && d.scrollTop < d.scrollHeight - d.clientHeight - 2) continue;
      }
      const rec = Math.min(rect.bottom, b1) - Math.max(rect.top, b0);
      if (rec < 2 || rect.width < 2) continue;
      const cy = (Math.max(rect.top, b0) + Math.min(rect.bottom, b1)) / 2;
      if (!auPoint(el, rect.left + rect.width / 2, cy) && !auPoint(el, rect.left + 3, cy) && !auPoint(el, rect.right - 3, cy)) continue;
      const cle = nom + '|' + genre + '|' + texte.slice(0, 40);
      if (!vus.has(cle)) vus.set(cle, { zone: nom, genre, texte: texte.slice(0, 60), haut: Math.round(rect.top), bas: Math.round(rect.bottom), depasse: Math.round(rec) });
    }
  };
  // 1. Textes
  const tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n; const hautsContenu = [];
  while ((n = tw.nextNode())) {
    const txt = n.nodeValue.replace(/\s+/g, ' ').trim();
    if (!txt) continue;
    const el = n.parentElement;
    if (!el || el.closest('script,style,[data-banc]') || !visibleChaine(el)) continue;
    if (el.closest('.lc-sr-only')) continue;
    const r = document.createRange(); r.selectNodeContents(n);
    for (const rect of r.getClientRects()) {
      if (rect.bottom < 0 || rect.top > H || rect.width < 1) continue;
      noter('texte', el, rect, txt);
      // Coupé sur le côté : texte collé au bord de l'écran, ou rogné par un parent qui ne défile pas
      // (hors bandeau défilant, rangée à faire glisser et troncature voulue « … »).
      {
        const v = rogner(el, rect);
        if (v.height >= 2 && v.top >= 0 && v.bottom <= H && !el.closest('.lc-marquee, [data-lc-decoration]')) {
          let glisse = false, ellipse = false;
          for (let e = el; e && e !== document.body; e = e.parentElement) {
            const cs = getComputedStyle(e);
            if (/(auto|scroll)/.test(cs.overflowX) && e.scrollWidth > e.clientWidth + 2) glisse = true;
            if (cs.textOverflow === 'ellipsis' || cs.webkitLineClamp !== 'none') ellipse = true;
          }
          const rogneX = (v.left - rect.left > 1.5 || rect.right - v.right > 1.5) && v.width > 2;
          const colle = rect.left < 3 || rect.right > W - 3;
          if (!glisse && !ellipse && (rogneX || colle) && auPoint(el, v.left + v.width / 2, v.top + v.height / 2)) {
            const cle = 'cote|' + txt.slice(0, 40);
            if (!vus.has(cle)) vus.set(cle, { zone: 'côté', genre: 'texte', texte: txt.slice(0, 60), haut: Math.round(rect.top), bas: Math.round(rect.bottom), depasse: Math.round(Math.max(v.left - rect.left, rect.right - v.right, 3 - rect.left, rect.right - (W - 3))) });
          }
        }
      }
      { const v = rogner(el, rect); if (v.height >= 2 && v.width >= 2 && v.top >= 0 && auPoint(el, v.left + v.width / 2, v.top + v.height / 2)) hautsContenu.push(v.top); }
    }
  }
  // 2. Commandes tactiles
  const cmds = [];
  for (const el of document.querySelectorAll('button, a[href], input, select, textarea, [role="button"], [role="tab"]')) {
    if (el.closest('[data-banc]') || !visibleChaine(el)) continue;
    const rect = el.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > H || rect.width < 2 || rect.height < 2) continue;
    const nom = (el.getAttribute('aria-label') || el.textContent || el.getAttribute('placeholder') || el.tagName).replace(/\s+/g, ' ').trim();
    noter('commande', el, rect, nom);
    { const v = rogner(el, rect); if (v.height >= 2 && v.width >= 2 && auPoint(el, v.left + v.width / 2, v.top + v.height / 2)) { cmds.push(v); hautsContenu.push(v.top); } }
  }
  // 3. Place perdue : écart entre la marge et le premier / dernier contenu.
  const premier = hautsContenu.length ? Math.min(...hautsContenu) : null;
  const dernierCmd = cmds.length ? Math.max(...cmds.map(r => r.bottom)) : null;
  // 4. Défilables de la couche visible
  const defil = [...document.querySelectorAll('.lc-device *')].filter(e => {
    const cs = getComputedStyle(e);
    return /(auto|scroll)/.test(cs.overflowY) && e.scrollHeight > e.clientHeight + 4 && visibleChaine(e) && e.getBoundingClientRect().height > 80;
  }).map(e => ({ haut: e.scrollTop, max: e.scrollHeight - e.clientHeight }));
  const cs = getComputedStyle(document.documentElement);
  return {
    ecran: (document.querySelector('.lc-stage [data-screen-label]') || {}).dataset?.screenLabel || '?',
    modale: (document.querySelector('[data-modal-kind]') || {}).dataset?.modalKind || null,
    trouvailles: [...vus.values()],
    ecartHaut: premier == null ? null : Math.round(premier - ins.top),
    ecartBas: dernierCmd == null ? null : Math.round(H - ins.bottom - dernierCmd),
    defil,
    envTop: cs.getPropertyValue('--lc-inset-top').trim(), envBottom: cs.getPropertyValue('--lc-inset-bottom').trim(),
  };
}

export async function defiler(page, ou) {
  await page.evaluate((ou) => {
    for (const e of document.querySelectorAll('.lc-device *')) {
      const cs = getComputedStyle(e);
      if (/(auto|scroll)/.test(cs.overflowY) && e.scrollHeight > e.clientHeight + 4 && e.getBoundingClientRect().height > 80) {
        const max = e.scrollHeight - e.clientHeight;
        e.scrollTop = ou === 'fin' ? max : ou === 'milieu' ? Math.min(max, 140) : 0;
      }
    }
  }, ou);
  await page.waitForTimeout(250);
}

export async function capturer(page, p, fichier) {
  await page.evaluate((p) => {
    const mk = (css, txt) => { const d = document.createElement('div'); d.dataset.banc = '1'; d.style.cssText = 'position:fixed;left:0;right:0;z-index:99999;pointer-events:none;font:700 13px -apple-system,sans-serif;' + css; d.textContent = txt || ''; document.body.appendChild(d); return d; };
    if (p.top) mk(`top:0;height:${p.top}px;background:rgba(255,0,60,.16);border-bottom:1px dashed rgba(255,0,60,.9);display:flex;align-items:flex-end;justify-content:space-between;padding:0 26px 6px;box-sizing:border-box;color:#000;mix-blend-mode:multiply`, '9:41                                    ▮▮▮ 100%');
    if (p.bottom) { const b = mk(`bottom:0;height:${p.bottom}px;background:rgba(255,0,60,.16);border-top:1px dashed rgba(255,0,60,.9);`); const i = document.createElement('div'); i.style.cssText = 'position:absolute;left:50%;bottom:8px;width:134px;height:5px;margin-left:-67px;border-radius:9px;background:#000'; b.appendChild(i); }
  }, p);
  fs.mkdirSync(path.dirname(fichier), { recursive: true });
  await page.screenshot({ path: fichier });
  await page.evaluate(() => document.querySelectorAll('[data-banc]').forEach(e => e.remove()));
}

// Relevé complet d'un état : au repos, puis défilé (milieu, fin).
export async function releve(S, nom) {
  const { page, p, dossier, journal } = S;
  await page.waitForTimeout(650); // fin de l'animation d'entrée
  const positions = ['debut'];
  const m0 = await page.evaluate(mesureDansLaPage, { top: p.top, bottom: p.bottom });
  if (m0.defil.some(d => d.max > 20)) positions.push('milieu', 'fin');
  for (const pos of positions) {
    if (pos !== 'debut') await defiler(page, pos);
    const m = pos === 'debut' ? m0 : await page.evaluate(mesureDansLaPage, { top: p.top, bottom: p.bottom });
    const fichier = path.join(dossier, `${String(S.n).padStart(2, '0')}-${nom}-${pos}.png`);
    await capturer(page, p, fichier);
    journal.push({ nom, pos, ...m, capture: path.basename(fichier) });
  }
  if (positions.length > 1) await defiler(page, 'debut');
  S.n++;
}

export async function taper(page, cible, opts = {}) {
  const loc = typeof cible === 'string' ? page.locator(cible).first() : cible;
  await loc.click({ timeout: opts.timeout || 6000, force: !!opts.force });
}
