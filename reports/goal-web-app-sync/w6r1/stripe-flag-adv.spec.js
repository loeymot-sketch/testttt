// goal-sync-smoke-2026-07-08.spec.js — [GOAL-SYNC 2026-07-08] smoke d'intégration COMPLET
// du web standalone (:8096) contre l'API borne (:8766), après la vague d'implémentation
// goal-web-app-sync. Vérifie le contrat CONTRACTS.md §2/§3/§4/§5/§6 côté UI :
//   accueil → fidélité (gate non-authed → login OTP réel → solde + QR « lqr. » + historique)
//   → menu (38 items mirror, nouvelles boissons) → wizard Tacos M (crudités ×4, 1 sauce max)
//   → wizard Cayenne (pain + fromagère pré-sélectionnée) → panier → checkout → paiement
//   (flag OFF ⇒ comptoir uniquement) — avec 0 erreur console non-gérée.
// Captures : /Users/1millnonstop/Downloads/web/reports/goal-sync-2026-07-08/
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const SHOTS = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1';
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, name), fullPage: false });

// Erreurs console tolérées : UNIQUEMENT les échecs de chargement de ressources EXTERNES
// (Google Fonts / unpkg) — tout le reste (pageerror, erreurs applicatives) fait échouer.
const ALLOWED = /fonts\.googleapis|fonts\.gstatic|unpkg\.com|net::ERR_(NAME_NOT_RESOLVED|INTERNET_DISCONNECTED)/i;

test.describe('GOAL-SYNC — smoke intégration web standalone', () => {
  test('parcours complet fidélité + menu + wizards + paiement (flag OFF)', async ({ page }) => {
    const consoleErrors = [];
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));
    page.on('console', (m) => {
      if (m.type() === 'error' && !ALLOWED.test(m.text())) consoleErrors.push('console: ' + m.text());
    });

    // ---------- 1. Accueil ----------
    await page.goto('/');
    await expect(page.locator('.lc-nav-brand')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(800); // laisse Babel/react finir le premier rendu
    await shot(page, '01-accueil.png');

    // ---------- 2. Fidélité NON-authed : gate propre ----------
    await page.locator('.lc-nav-link', { hasText: 'Fidélité' }).click();
    await expect(page.getByText('Connecte-toi')).toBeVisible();
    // Taux servis par GET /loyalty/config (contrat §2) — pas de gate cassée / page blanche.
    await expect(page.getByText('100 points = 1 € de réduction')).toBeVisible();
    await shot(page, '02-fidelite-gate-non-authed.png');

    // ---------- 3. Login OTP RÉEL (0699xxxxxx + code 1234) ----------
    const phone = '0699' + String(Math.floor(100000 + Math.random() * 900000));
    await page.getByRole('button', { name: /Créer mon compte/ }).click();
    // Modal AccountFlow : « Se connecter » (mode login) route vers le flow téléphone (signup).
    await page.locator('.lc-acc-cta, .lc-acc button.lc-btn').first().waitFor({ state: 'visible' }).catch(() => {});
    const seConnecter = page.getByRole('button', { name: /Se connecter|Recevoir le code/ }).last();
    if ((await seConnecter.textContent() || '').includes('Se connecter')) await seConnecter.click();
    // Formulaire signup : prénom + email + téléphone puis « Recevoir le code ».
    await page.locator('#acc-first').fill('Smoke');
    await page.locator('#acc-email').fill('smoke.goalsync@exemple.fr');
    await page.locator('#acc-phone').fill(phone);
    const otpResp = page.waitForResponse((r) => r.url().includes('/api/auth/guest-signup/otp') && r.request().method() === 'POST', { timeout: 30_000 });
    await page.getByRole('button', { name: /Recevoir le code/ }).click();
    expect((await otpResp).status(), 'POST guest-signup/otp doit répondre 200').toBe(200);
    // Écran OTP : 4 cellules — code 1234 (n'importe quel code passe en V1 local, contrat §1).
    const cells = page.locator('.lc-otp-cell');
    await expect(cells.first()).toBeVisible({ timeout: 15_000 });
    const verifyResp = page.waitForResponse((r) => r.url().includes('/api/auth/guest-signup/verify') && r.request().method() === 'POST', { timeout: 30_000 });
    for (let i = 0; i < 4; i++) await cells.nth(i).fill(String(i + 1));
    expect([200, 201], 'POST guest-signup/verify doit répondre 2xx').toContain((await verifyResp).status());
    await expect(page.getByText('Connecté')).toBeVisible({ timeout: 15_000 });
    await shot(page, '03-login-success.png');

    // ---------- 4. Fidélité AUTHED : solde réel + QR « lqr. » + identifiant ----------
    const qrResp = page.waitForResponse((r) => r.url().includes('/api/frontend/loyalty/qr') && r.request().method() === 'POST', { timeout: 30_000 });
    await page.getByRole('button', { name: /Commencer à commander/ }).click();
    const qrJson = await (await qrResp).json();
    const qrToken = String((qrJson && (qrJson.data ? qrJson.data.token : qrJson.token)) || '');
    expect(qrToken, 'token QR signé backend').toMatch(/^lqr\./);
    await expect(page.locator('.lc-wallet-bal-num')).toBeVisible({ timeout: 15_000 });
    // SVG rendu par la lib vendorisée locale (contrat §3 — aucun CDN).
    await expect(page.locator('.lc-wallet-code svg')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('.lc-wallet-code-id')).not.toHaveText('—', { timeout: 15_000 });
    await expect(page.getByText('Présentez ce QR ou dictez votre numéro en caisse.')).toBeVisible();
    await shot(page, '04-fidelite-wallet-qr.png');

    // Historique réel (GET /loyalty/history) — nouveau compte = vide mais SANS erreur.
    const histResp = page.waitForResponse((r) => r.url().includes('/api/frontend/loyalty/history'), { timeout: 30_000 });
    await page.getByRole('tab', { name: 'Historique' }).click();
    expect((await histResp).status(), 'GET loyalty/history doit répondre 200').toBe(200);
    await page.waitForTimeout(600);
    await shot(page, '05-fidelite-historique.png');

    // ---------- 5. Menu : 38 items mirror + nouvelles boissons ----------
    await page.locator('.lc-nav-link', { hasText: 'Menu' }).click();
    // Desktop : les catégories vivent dans la side-rail .lc-menu-side (les .lc-cat-tabs sont mobile-only).
    await expect(page.locator('.lc-menu-side').first()).toBeVisible({ timeout: 15_000 });
    // Mirror web = 38 items (42 canoniques − 4 SKU frites gratinées repliées en styles ;
    // divergence structurelle ACCEPTÉE contrat §5 — le gate parity valide les 6 prix).
    await expect(page.getByText(/38\s*résultats?/)).toBeVisible();
    await shot(page, '06-menu-all.png');
    await page.locator('.lc-menu-side-link', { hasText: 'Boissons' }).click();
    for (const d of ['Coca Cherry 33cl', 'Tropico 33cl', 'Ice Tea Pêche 33cl', 'Fanta Citron 33cl', 'Fuze Tea 33cl', 'Hawaï 33cl', 'Perrier 33cl']) {
      const card = page.locator('.lc-card-item', { hasText: d }).first();
      await card.evaluate((el) => el.scrollIntoView({ block: 'center' })).catch(() => {});
      await expect(card).toBeVisible();
    }
    await shot(page, '07-menu-boissons-nouvelles.png');

    // ---------- 6. Wizard Tacos M : crudités ×4 + 1 sauce max ----------
    await page.locator('.lc-menu-side-link', { hasText: 'Tacos' }).click();
    const tacosCard = page.locator('.lc-card-item', { hasText: 'Tacos M' }).first();
    await tacosCard.evaluate((el) => el.scrollIntoView({ block: 'center' })).catch(() => {});
    await tacosCard.click();
    await page.getByRole('button', { name: /Personnaliser/ }).click();
    await expect(page.locator('.lc-wiz-title')).toHaveText(/Choisis 1 viande/, { timeout: 15_000 });
    await page.locator('.lc-wiz-choice', { hasText: 'Poulet mariné' }).first().click();
    await page.locator('.lc-wiz-foot-next').click();
    // Étape Sauce — min1/max1 (canonique) : une 2e sélection est REFUSÉE.
    await expect(page.locator('.lc-wiz-title')).toHaveText('Sauce');
    await page.locator('.lc-wiz-choice', { hasText: 'Mayonnaise' }).first().click();
    await page.locator('.lc-wiz-choice', { hasText: 'Ketchup' }).first().click();
    await expect(page.getByText('Maximum 1 sélections')).toBeVisible();
    await expect(page.locator('.lc-wiz-options .lc-wiz-choice.is-on')).toHaveCount(1);
    await shot(page, '08-wizard-tacos-sauce-max1.png');
    await page.locator('.lc-wiz-foot-next').click();
    // Étape Crudités — 4 entrées dont « Oignons cuits » (revert borne 05e5cacd0, contrat §5).
    await expect(page.locator('.lc-wiz-title')).toHaveText('Crudités');
    await expect(page.locator('.lc-wiz-options .lc-wiz-choice')).toHaveCount(4);
    for (const c of ['Salade', 'Tomate', 'Oignon', 'Oignons cuits']) {
      await expect(page.locator('.lc-wiz-choice', { hasText: c }).first()).toBeVisible();
    }
    await shot(page, '09-wizard-tacos-crudites.png');
    // Avance jusqu'au récap puis ajoute au panier (Sans formule au step menu).
    for (let guard = 0; guard < 12; guard++) {
      const nextBtn = page.locator('.lc-wiz-foot-next');
      const label = (await nextBtn.textContent()) || '';
      if (label.includes('Ajouter au panier')) { await nextBtn.click(); break; }
      const title = (await page.locator('.lc-wiz-title').textContent()) || '';
      if (title.includes('Faire un menu')) {
        await page.locator('.lc-wiz-choice', { hasText: 'Sans formule' }).click();
        await page.waitForTimeout(400); // auto-advance radio
        continue;
      }
      if (await nextBtn.isDisabled()) await page.locator('.lc-wiz-options .lc-wiz-choice').first().click();
      await nextBtn.click();
    }
    await expect(page.locator('.lc-cart, .lc-drawer, .lc-cart-drawer').first()).toBeVisible({ timeout: 10_000 }).catch(() => {});

    // ---------- 7. Wizard Cayenne : pain + fromagère PRÉ-sélectionnée ----------
    // Ferme le tiroir panier s'il est ouvert (bouton fermer ou Échap).
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    await page.locator('.lc-nav-link', { hasText: 'Menu' }).click();
    await expect(page.locator('.lc-menu-side').first()).toBeVisible({ timeout: 15_000 });
    await page.locator('.lc-menu-side-link', { hasText: 'Sandwichs' }).click();
    const cayenneCard = page.locator('.lc-card-item', { hasText: /Cayenne/ }).first();
    await cayenneCard.evaluate((el) => el.scrollIntoView({ block: 'center' })).catch(() => {});
    await cayenneCard.click();
    await page.getByRole('button', { name: /Personnaliser/ }).click();
    // Step 1 : Pain ou galette ? — « Pain » pré-sélectionné (has_pain_choice + défaut).
    await expect(page.locator('.lc-wiz-title')).toHaveText('Pain ou galette ?', { timeout: 15_000 });
    await expect(page.locator('.lc-wiz-choice.is-on', { hasText: 'Pain' })).toBeVisible();
    await shot(page, '10-wizard-cayenne-pain.png');
    await page.locator('.lc-wiz-foot-next').click();
    // Step 2 : Sauce — « Fromagère maison » PRÉ-sélectionnée (sauce_default contrat §5).
    await expect(page.locator('.lc-wiz-title')).toHaveText('Sauce');
    await expect(page.locator('.lc-wiz-choice.is-on', { hasText: 'Fromagère maison' })).toBeVisible();
    await expect(page.locator('.lc-wiz-options .lc-wiz-choice.is-on')).toHaveCount(1);
    await shot(page, '11-wizard-cayenne-fromagere-preselect.png');
    for (let guard = 0; guard < 12; guard++) {
      const nextBtn = page.locator('.lc-wiz-foot-next');
      const label = (await nextBtn.textContent()) || '';
      if (label.includes('Ajouter au panier')) { await nextBtn.click(); break; }
      const title = (await page.locator('.lc-wiz-title').textContent()) || '';
      if (title.includes('Faire un menu')) {
        await page.locator('.lc-wiz-choice', { hasText: 'Sans formule' }).click();
        await page.waitForTimeout(400);
        continue;
      }
      if (await nextBtn.isDisabled()) await page.locator('.lc-wiz-options .lc-wiz-choice').first().click();
      await nextBtn.click();
    }

    // ---------- 8. Panier → checkout ----------
    await expect(page.getByRole('button', { name: /Passer commande/ })).toBeVisible({ timeout: 10_000 });
    await shot(page, '12-panier.png');
    await page.getByRole('button', { name: /Passer commande/ }).click();
    // Upsell éventuel : « Non merci » jusqu'au checkout (≤ 4 pages).
    for (let guard = 0; guard < 5; guard++) {
      if (await page.getByText('Continuer vers paiement').isVisible().catch(() => false)) break;
      const skip = page.getByRole('button', { name: 'Non merci' });
      if (await skip.isVisible().catch(() => false)) { await skip.click(); await page.waitForTimeout(400); continue; }
      await page.waitForTimeout(500);
    }
    await expect(page.getByRole('button', { name: /Continuer vers paiement/ })).toBeEnabled({ timeout: 15_000 });
    await shot(page, '13-checkout.png');
    await page.getByRole('button', { name: /Continuer vers paiement/ }).click();

    // ---------- 9. Paiement : flag OFF ⇒ comptoir UNIQUEMENT (contrat §4) ----------
    await expect(page.getByText('Payer sur place')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/Espèces ou carte au comptoir/)).toBeVisible();
    // AUCUNE option carte-en-ligne / wallet / promesse Stripe dans le DOM.
    await expect(page.getByText('Carte bancaire (en ligne)')).toHaveCount(0);
    await expect(page.getByText('Apple Pay')).toHaveCount(0);
    await expect(page.getByText('Google Pay')).toHaveCount(0);
    await expect(page.getByText(/Stripe|3D-?Secure/)).toHaveCount(0);
    // Double vérif flag côté runtime (meta feature-online-card=0 → LC.api.config).
    const flag = await page.evaluate(() => window.LC && window.LC.api && window.LC.api.config.onlineCardEnabled);
    expect(flag, 'LC.api.config.onlineCardEnabled doit être false (flag OFF)').toBe(false);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(400);
    await shot(page, 'adv-01-paiement-OFF.png');

    // [ADV] Dump RAW DOM du bloc méthodes de paiement (flag OFF) — preuve DOM brute.
    const domOff = await page.locator('.lcf-paymethods').innerHTML();
    fs.writeFileSync(path.join(SHOTS, 'adv-dom-OFF.html'), domOff);
    console.log('ADV_DOM_OFF_START>>>' + domOff.replace(/\s+/g, ' ').trim() + '<<<ADV_DOM_OFF_END');
    // Compte des boutons méthode : flag OFF ⇒ exactement 1 (comptoir).
    const cntOff = await page.locator('.lcf-paymethod').count();
    console.log('ADV_METHOD_COUNT_OFF=' + cntOff);
    expect(cntOff, 'flag OFF ⇒ 1 seule méthode (comptoir)').toBe(1);

    // ---------- [ADV] Flag ON simulé au RUNTIME (override config) ----------
    // Prouve que le FLUX carte est PRÉSENT (testable) et gated UNIQUEMENT par le flag.
    await page.evaluate(() => { window.LC.api.config.onlineCardEnabled = true; });
    // Clic sur « Payer sur place » ⇒ setCtx ⇒ re-render du composant Payment ⇒ methods recalculé.
    await page.getByText('Payer sur place').click();
    await expect(page.getByText('Carte bancaire (en ligne)')).toBeVisible({ timeout: 10_000 });
    const cntOn = await page.locator('.lcf-paymethod').count();
    console.log('ADV_METHOD_COUNT_ON=' + cntOn);
    expect(cntOn, 'flag ON ⇒ 2 méthodes (comptoir + carte)').toBe(2);
    const domOn = await page.locator('.lcf-paymethods').innerHTML();
    fs.writeFileSync(path.join(SHOTS, 'adv-dom-ON.html'), domOn);
    console.log('ADV_DOM_ON_START>>>' + domOn.replace(/\s+/g, ' ').trim() + '<<<ADV_DOM_ON_END');
    await shot(page, 'adv-02-paiement-ON-override.png');

    // ---------- 10. Console : 0 erreur non-gérée ----------
    expect(consoleErrors, 'Console : 0 erreur non-gérée\n' + consoleErrors.join('\n')).toEqual([]);
  });
});
