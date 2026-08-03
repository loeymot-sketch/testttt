// ============================================================================
// CAPTUREUR-ADVERSAIRE W8-web — États dégradés du site DÉPLOYÉ
// Cible : https://site-lecayenne.vercel.app/?dev  (version live vérifiée = v=20260720h,
//         identique au repo lecayenne-web-deploy/Site lecayenne — sélecteurs vérifiés source).
// Run :
//   PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=https://site-lecayenne.vercel.app \
//     npx playwright test tests/e2e/web-degraded-states-2026-07-20.spec.js --project=chromium
// Aucune commande n'est créée (T7 coupe en plus l'endpoint POST /api/frontend/order).
// ============================================================================
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOT_DIR = path.join(__dirname, '__screenshots__', 'web-degraded-2026-07-20');
fs.mkdirSync(SHOT_DIR, { recursive: true });
const shot = (name) => path.join(SHOT_DIR, name);

const VPS_GLOB = '**://vps-418872ac.vps.ovh.net/**';
const COCA = 'Voir Coca-Cola 33cl';
const PRIX_COCA = 1.90;

test.describe.configure({ retries: 0 });
test.setTimeout(240_000); // réseau réel Vercel + VPS + Babel-standalone compile les .jsx au vol

// ---------------------------------------------------------------------------
// Helpers (routage = state React : tout passe par des clics, aucune URL par vue)
// ---------------------------------------------------------------------------
async function gotoDev(page) {
  // L'edge Vercel répond parfois net::ERR_TIMED_OUT sur une rafale de contextes neufs
  // (observé au 7e chargement d'affilée) → 3 tentatives espacées avant de déclarer mort.
  let lastErr = null;
  for (let i = 0; i < 3; i++) {
    try {
      await page.goto('/?dev', { waitUntil: 'domcontentloaded', timeout: 45_000 });
      lastErr = null;
      break;
    } catch (e) {
      lastErr = e;
      console.log(`[gotoDev] tentative ${i + 1}/3 échouée: ${String(e.message).split('\n')[0]}`);
      await page.waitForTimeout(4_000);
    }
  }
  if (lastErr) throw lastErr;
  // Babel-standalone compile screens/flows/funnel/wizard à la volée → attendre le rendu réel.
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  await expect(page.locator('.lc-nav')).toBeVisible({ timeout: 15_000 });
}

async function openMenu(page) {
  const direct = page.locator('.lc-nav-links').getByRole('button', { name: 'Menu', exact: true });
  if (await direct.isVisible().catch(() => false)) {
    await direct.click();
  } else {
    // mobile : burger → menu drawer
    await page.locator('.lc-nav-burger').click();
    await page.locator('#lc-mobile-menu').getByRole('button', { name: 'Menu' }).click();
  }
  await expect(page.locator('.lc-menu-grid')).toBeVisible({ timeout: 15_000 });
}

async function addCoca(page) {
  const card = page.getByRole('button', { name: COCA, exact: true });
  await card.scrollIntoViewIfNeeded();
  await card.click();
  const detail = page.locator('.lc-detail');
  await expect(detail).toBeVisible({ timeout: 10_000 });
  await detail.getByRole('button', { name: /Ajouter au panier/ }).click();
  await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 10_000 });
}

// « Passer commande » → UpsellFlow (0-3 étapes, « Non merci » avance) → CheckoutPage.
async function throughUpsellToCheckout(page) {
  await page.locator('.lc-cart-drawer.is-open').getByRole('button', { name: /Passer commande/ }).click();
  const checkoutCta = page.getByRole('button', { name: /Continuer vers paiement/ });
  for (let i = 0; i < 8; i++) {
    if (await checkoutCta.isVisible().catch(() => false)) break;
    const skip = page.getByRole('button', { name: 'Non merci' });
    if (await skip.isVisible().catch(() => false)) { await skip.click(); }
    await page.waitForTimeout(400);
  }
  await expect(checkoutCta).toBeVisible({ timeout: 10_000 });
}

async function toPayment(page) {
  await page.getByRole('button', { name: /Continuer vers paiement/ }).click();
  const cta = page.locator('.lcf-cta-bar-next');
  await expect(cta).toBeVisible({ timeout: 10_000 });
  await expect(cta).toContainText('Confirmer la commande', { timeout: 10_000 });
}

// Vérité UTILISATEUR : la surface de scroll réelle (documentElement) ne déborde pas
// ET aucun pan horizontal n'est possible. Le body peut « déborder » en interne tout en
// étant clippé (overflow-x hidden) — diagnostiqué + LOGGÉ (P3 cosmétique), pas bloquant.
async function assertNoHScroll(page, label) {
  const m = await page.evaluate(() => {
    const iw = window.innerWidth;
    const out = {
      iw,
      sw: document.documentElement.scrollWidth,
      bsw: document.body ? document.body.scrollWidth : 0,
      offender: null,
    };
    if (out.bsw > iw + 1) {
      let worst = null;
      document.querySelectorAll('body *').forEach((el) => {
        const r = el.getBoundingClientRect();
        // ignorer le contenu des rails à défilement horizontal volontaire (chips catégories…)
        const scroller = el.closest('.lc-cat-tabs, .lc-diet-chips, [style*="overflow-x"]');
        if (scroller && scroller !== el) return;
        if (r.right > iw + 1 && (!worst || r.right > worst.right)) {
          worst = { tag: el.tagName, cls: String(el.className).slice(0, 60), right: Math.round(r.right) };
        }
      });
      out.offender = worst;
    }
    window.scrollTo(50, 0);
    out.panX = window.scrollX;
    window.scrollTo(0, 0);
    return out;
  });
  console.log(`[overflow ${label}] htmlScrollWidth=${m.sw} innerWidth=${m.iw} bodyScrollWidth=${m.bsw} panX=${m.panX}` +
    (m.offender ? ` · débord interne clippé (P3): ${m.offender.tag}.${m.offender.cls}→${m.offender.right}px` : ''));
  expect(m.sw, `${label} : débordement horizontal réel (${m.sw} > ${m.iw})`).toBeLessThanOrEqual(m.iw + 1);
  expect(m.panX, `${label} : la page peut être pannée horizontalement`).toBe(0);
}

// ---------------------------------------------------------------------------
// 1. API invalide — tout appel VPS avorté : le site doit vivre sur data/menu.js
//    et l'échec « Recevoir le code » doit afficher une ERREUR VISIBLE (pas un blanc).
// ---------------------------------------------------------------------------
test('T1 — API morte : menu local vivant + erreur OTP visible', async ({ page }) => {
  let aborted = 0;
  await page.route(VPS_GLOB, (route) => { aborted++; route.abort('failed'); });

  await gotoDev(page);
  await openMenu(page);
  const cards = page.locator('.lc-menu-grid .lc-card-item');
  await expect(cards.first()).toBeVisible({ timeout: 10_000 });
  const nCards = await cards.count();
  expect(nCards, 'grille menu doit vivre sur data/menu.js sans API').toBeGreaterThanOrEqual(10);
  await page.screenshot({ path: shot('01a-api-down-menu.png'), fullPage: false });

  await addCoca(page);
  await throughUpsellToCheckout(page);
  await toPayment(page);

  // Invité → « Confirmer la commande » ouvre la gate OTP (téléphone)
  await page.locator('.lcf-cta-bar-next').click();
  await expect(page.locator('#auth-phone')).toBeVisible({ timeout: 10_000 });
  await page.fill('#auth-phone', '0699999999');
  await page.getByRole('button', { name: /Recevoir le code/ }).click();

  const err = page.locator('.lcf-field-error');
  await expect(err.first(), 'échec API doit produire un message d’erreur VISIBLE').toBeVisible({ timeout: 10_000 });
  const errText = (await err.first().innerText()).trim();
  console.log(`[T1] requêtes VPS avortées=${aborted} · erreur affichée="${errText}" · cartes menu=${nCards}`);
  expect(errText.length).toBeGreaterThan(5);
  await page.screenshot({ path: shot('01b-api-down-otp-error.png'), fullPage: true });
});

// ---------------------------------------------------------------------------
// 2. Session expirée — token Sanctum invalide posé AVANT navigation Fidélité.
//    Attendu : modal login rouverte OU état non-connecté propre (pas un faux « 0 pts »).
// ---------------------------------------------------------------------------
test('T2 — token invalide : Fidélité rouvre la connexion, pas de faux connecté', async ({ page }) => {
  await gotoDev(page);
  const keys = await page.evaluate(() => Object.keys(localStorage));
  console.log(`[T2] localStorage keys découvertes = ${JSON.stringify(keys)}`);
  await page.evaluate(() => {
    localStorage.setItem('lecayenne.authToken', 'invalid-token-xyz');
    localStorage.setItem('lecayenne.authPhone', '0699999999');
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });

  // Le token présent ⇒ le shell se croit connecté (« Mon compte » au nav)
  const navAccount = await page.locator('.lc-nav-btn-account').first().innerText().catch(() => '');
  await page.locator('.lc-nav-links').getByRole('button', { name: 'Fidélité', exact: true }).click();

  // profile() → 401 réel VPS → purge token + réouverture connexion (heal HONESTY 2026-07-19)
  const modal = page.locator('.lc-acc');
  let ok = false; let how = '';
  try { await modal.waitFor({ state: 'visible', timeout: 20_000 }); ok = true; how = 'modal Connexion rouverte'; } catch (_) {}
  if (!ok) {
    const loggedOut = await page.locator('.lc-nav-btn-account', { hasText: 'Se connecter' }).isVisible().catch(() => false);
    if (loggedOut) { ok = true; how = 'état non-connecté propre (nav Se connecter)'; }
  }
  const tokenAfter = await page.evaluate(() => localStorage.getItem('lecayenne.authToken'));
  console.log(`[T2] nav avant="${navAccount.trim()}" · issue="${how || 'AUCUNE — faux connecté'}" · token après=${JSON.stringify(tokenAfter)}`);
  await page.screenshot({ path: shot('02-expired-token-loyalty.png'), fullPage: false });
  expect(ok, 'Ni modal login ni état déconnecté → faux « 0 pts connecté » = défaut').toBe(true);
  expect(tokenAfter, 'le token invalide doit être PURGÉ après le 401').toBeNull();
});

// ---------------------------------------------------------------------------
// 3. Double-clic « Ajouter au panier » (2 clics mêmes coordonnées, 50 ms d'écart).
//    Verdict : ce qui est AFFICHÉ (lignes/qté/total) == ce qui serait FACTURÉ.
// ---------------------------------------------------------------------------
test('T3 — double-clic ajout : affiché == facturé', async ({ page }) => {
  await gotoDev(page);
  await openMenu(page);
  const card = page.getByRole('button', { name: COCA, exact: true });
  await card.scrollIntoViewIfNeeded();
  await card.click();
  const addBtn = page.locator('.lc-detail').getByRole('button', { name: /Ajouter au panier/ });
  await addBtn.waitFor({ state: 'visible', timeout: 10_000 });
  const box = await addBtn.boundingBox();
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;
  await page.mouse.click(cx, cy);
  await page.waitForTimeout(50);
  await page.mouse.click(cx, cy); // double-clic réel : même point, la page a pu changer dessous
  await page.waitForTimeout(900);

  // Le 2e clic peut être tombé sur l'overlay (ferme le tiroir) → rouvrir pour inspecter.
  const drawer = page.locator('.lc-cart-drawer.is-open');
  if (!(await drawer.isVisible().catch(() => false))) {
    await page.locator('.lc-nav-btn-cart').click();
  }
  await expect(drawer).toBeVisible({ timeout: 10_000 });

  const rows = drawer.locator('.lc-cart-row');
  const lines = await rows.count();
  expect(lines, 'au moins une ligne doit exister').toBeGreaterThanOrEqual(1);
  let units = 0; let sumShown = 0;
  for (let i = 0; i < lines; i++) {
    units += parseInt(await rows.nth(i).locator('.lc-cart-stepper b').innerText(), 10);
    sumShown += parseFloat((await rows.nth(i).locator('.lc-cart-row-price').innerText()).replace(',', '.'));
  }
  const totalShown = parseFloat((await drawer.locator('.lc-cart-totals-row.is-total b').innerText()).replace(',', '.'));
  const badgeTxt = await page.locator('.lc-nav-btn-cart-dot').innerText().catch(() => '0');
  console.log(`[T3] double-clic → lignes=${lines} unités=${units} sommeLignes=${sumShown.toFixed(2)} totalAffiché=${totalShown.toFixed(2)} badge=${badgeTxt.trim()}`);
  // Cohérence stricte affiché == facturé (le payload part du même state que l'affichage)
  expect(Math.abs(totalShown - sumShown), 'total affiché ≠ somme des lignes affichées').toBeLessThan(0.005);
  expect(Math.abs(totalShown - units * PRIX_COCA), 'total affiché ≠ unités × 1,90 €').toBeLessThan(0.005);
  expect(parseInt(badgeTxt, 10), 'badge nav ≠ nombre de lignes').toBe(lines);
  await page.waitForTimeout(700); // laisser l'animation du tiroir se terminer pour une capture propre
  await page.screenshot({ path: shot('03-double-click-cart.png'), fullPage: false });
});

// ---------------------------------------------------------------------------
// 4. Refresh mid-funnel — reload au checkout : état PROPRE (retour accueil,
//    pas de page cassée ; perte du panier = design V1 accepté).
// ---------------------------------------------------------------------------
test('T4 — reload au checkout : retour accueil propre', async ({ page }) => {
  await gotoDev(page);
  await openMenu(page);
  await addCoca(page);
  await throughUpsellToCheckout(page);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('#root .lc-app')).toBeVisible({ timeout: 45_000 });
  const bodyText = await page.locator('body').innerText();
  expect(bodyText.length, 'page blanche après reload').toBeGreaterThan(200);
  await expect(page.locator('.lc-nav')).toBeVisible();
  const onHome = await page.getByRole('button', { name: /Commander maintenant/ }).first().isVisible().catch(() => false);
  const checkoutLeft = await page.locator('.lcf-page').isVisible().catch(() => false);
  const badgeVisible = await page.locator('.lc-nav-btn-cart-dot').isVisible().catch(() => false);
  console.log(`[T4] accueil=${onHome} resteCheckout=${checkoutLeft} badgePanier=${badgeVisible} (perte panier = design V1)`);
  await page.screenshot({ path: shot('04-refresh-midfunnel.png'), fullPage: false });
  expect(onHome, 'après reload : retour accueil attendu (routing state React)').toBe(true);
  expect(checkoutLeft, 'aucun résidu de checkout après reload').toBe(false);
  expect(badgeVisible, 'panier non persisté (V1 accepté) → badge absent').toBe(false);
});

// ---------------------------------------------------------------------------
// 5. Mobile 390×844 — accueil, menu, wizard Tacos étape 1, panier :
//    layout intact = scrollWidth ≤ innerWidth (+1 px de tolérance).
// ---------------------------------------------------------------------------
test('T5 — mobile 390×844 : 4 surfaces sans débordement horizontal', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await gotoDev(page);
  await assertNoHScroll(page, 'accueil');
  await page.screenshot({ path: shot('05a-mobile-home.png'), fullPage: false });

  await openMenu(page);
  await assertNoHScroll(page, 'menu');
  await page.screenshot({ path: shot('05b-mobile-menu.png'), fullPage: false });

  const tacos = page.getByRole('button', { name: 'Voir Tacos M', exact: true });
  await tacos.scrollIntoViewIfNeeded();
  await tacos.click();
  await page.locator('.lc-detail').getByRole('button', { name: /Personnaliser/ }).click();
  await expect(page.locator('.lc-wiz')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('.lc-wiz-title')).toContainText(/viande/i);
  await assertNoHScroll(page, 'wizard-tacos-etape1');
  await page.screenshot({ path: shot('05c-mobile-wizard-tacos.png'), fullPage: false });

  // Fermer le wizard (étape 0 : flèche retour = Fermer) puis panier avec 1 article
  await page.locator('.lc-wiz-foot-back').click();
  await expect(page.locator('.lc-wiz')).toBeHidden({ timeout: 10_000 });
  await addCoca(page);
  await page.waitForTimeout(700); // tiroir pleinement ouvert (390px) avant capture
  await assertNoHScroll(page, 'panier');
  await page.screenshot({ path: shot('05d-mobile-cart.png'), fullPage: false });
});

// ---------------------------------------------------------------------------
// 6. « États vides par URL » — IMPOSSIBLE : le routage est 100% state React
//    (App.route via useState, aucune URL par vue, deep-link inexistant). On le
//    DOCUMENTE (l'URL ne bouge pas en naviguant) puis on teste le panier vide.
// ---------------------------------------------------------------------------
test('T6 — routage state React documenté + panier vide propre', async ({ page }) => {
  await gotoDev(page);
  const urlBefore = await page.evaluate(() => location.pathname + location.search);
  await openMenu(page);
  const urlAfter = await page.evaluate(() => location.pathname + location.search);
  console.log(`[T6] URL avant="${urlBefore}" après navigation Menu="${urlAfter}" → routage 100% state React (pas d'URL par vue, pas de deep-link « état vide par URL » possible)`);
  expect(urlAfter, 'la navigation ne change pas l’URL (state routing)').toBe(urlBefore);

  await page.locator('.lc-nav-btn-cart').click();
  const drawer = page.locator('.lc-cart-drawer.is-open');
  await expect(drawer).toBeVisible({ timeout: 10_000 });
  await expect(drawer.getByText('Ton panier est vide')).toBeVisible();
  await expect(drawer.getByRole('button', { name: /Passer commande/ })).toHaveCount(0);
  console.log('[T6] panier vide : état propre, bouton « Passer commande » ABSENT du DOM');
  await page.waitForTimeout(700); // tiroir pleinement ouvert avant capture
  await page.screenshot({ path: shot('06-empty-cart-drawer.png'), fullPage: false });
});

// ---------------------------------------------------------------------------
// 7. OTP faux — flux RÉEL jusqu'au code, saisie 0000. Attendu SÉCURITÉ : un code
//    faux DOIT être REJETÉ (erreur claire, aucune session). ⚠️ Aucune commande :
//    POST /api/frontend/order coupé (ceinture) → si le code faux était accepté, la
//    commande partirait mais est avortée avant d'atteindre le backend.
//    >>> DÉFAUT P0 TROUVÉ : /api/auth/guest-signup/verify n'invalide PAS le code —
//        il ne fait que du rate-limiting (429). Voir l'assertion sécurité déterministe.
// ---------------------------------------------------------------------------
test('T7 — OTP faux : DOIT être rejeté (sinon bypass auth = P0)', async ({ page }) => {
  let orderAttempts = 0;
  await page.route('**/api/frontend/order', (route) => { orderAttempts++; route.abort('failed'); });

  await gotoDev(page);
  await openMenu(page);
  await addCoca(page);
  await throughUpsellToCheckout(page);
  await toPayment(page);

  await page.locator('.lcf-cta-bar-next').click();
  await expect(page.locator('#auth-phone')).toBeVisible({ timeout: 10_000 });
  await page.fill('#auth-phone', '0699999999');
  await page.getByRole('button', { name: /Recevoir le code/ }).click(); // APPEL RÉEL VPS (crée un OTP, PAS une commande)

  const otpInput = page.locator('#auth-otp');
  const otpErrEarly = page.locator('.lcf-field-error');
  let reachedOtp = true;
  try { await otpInput.waitFor({ state: 'visible', timeout: 25_000 }); } catch (_) { reachedOtp = false; }

  let uiOutcome = 'n/a';
  if (reachedOtp) {
    await otpInput.fill('0000');
    await page.getByRole('button', { name: /Valider et envoyer la commande/ }).click();
    await page.waitForTimeout(2500);
    const errVisible = await otpErrEarly.first().isVisible().catch(() => false);
    const onConfirm = await page.locator('.lc-confirm, .lcf-confirm').first().isVisible().catch(() => false);
    const authedNow = await page.evaluate(() => !!(window.LC && window.LC.api && window.LC.api.isAuthed()));
    uiOutcome = `errVisible=${errVisible} onConfirm=${onConfirm} authed=${authedNow} POSTorder=${orderAttempts}`;
  } else {
    uiOutcome = `étape code non atteinte (erreur envoi="${(await otpErrEarly.first().innerText().catch(() => '')).trim()}")`;
  }
  await page.screenshot({ path: shot('07-otp-wrong-code.png'), fullPage: true });

  // --- Verdict SÉCURITÉ déterministe (indépendant du throttle UI) --------------
  // Numéro NEUF + code aléatoire, SANS jamais demander d'OTP : un backend correct
  // renvoie 401/422 (code jamais émis). token émis = BYPASS. 429 = throttle SEUL
  // (ne prouve AUCUNE validation) → traité comme échec de validation.
  const freshPhone = '0677' + Math.floor(100000 + Math.random() * 899999);
  const randomCode = String(Math.floor(100000 + Math.random() * 899999));
  const sec = await page.evaluate(async (args) => {
    try {
      const r = await window.LC.api.guestVerify(args.p, args.c);
      return { verdict: 'BYPASS', tokenIssued: !!(r && r.token), userId: r && r.user && r.user.id };
    } catch (e) {
      if (e && e.status === 429) return { verdict: 'THROTTLE_ONLY', status: 429, message: e.message };
      return { verdict: 'REJECTED', status: e && e.status, message: e && e.message };
    }
  }, { p: freshPhone, c: randomCode });

  console.log(`[T7] UI(0000) → ${uiOutcome}`);
  console.log(`[T7] SÉCURITÉ verify(${freshPhone}, ${randomCode}, sans OTP) → ${JSON.stringify(sec)}`);
  if (sec.verdict === 'BYPASS') {
    console.log('[T7][P0] AUTH BYPASS — /api/auth/guest-signup/verify accepte un code JAMAIS émis et délivre un token Sanctum kiosk:order.');
  } else if (sec.verdict === 'THROTTLE_ONLY') {
    console.log('[T7][P0] verify ne VALIDE pas le code — il ne fait que throttler (429). Un code jamais émis devrait être 401/422, pas 429.');
  }

  // NB : orderAttempts>0 est lui-même une PREUVE du bypass (le code faux a laissé l'app
  // tenter la commande) ; la route l'avorte AVANT le backend → 0 commande créée (cleanup OK).
  console.log(`[T7] POST /order tentés (tous avortés avant backend) = ${orderAttempts} → 0 commande créée`);
  // Verdict = exigence sécurité seule : un code faux/jamais-émis DOIT être rejeté (401/422).
  expect(sec.verdict, `Code OTP faux non rejeté (${JSON.stringify(sec)}) — voir [T7][P0]`).toBe('REJECTED');
});
