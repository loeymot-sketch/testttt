// Wave B — audit e2e site web Le Cayenne (T52 round-1) — LECTURE SEULE STRICTE.
// AUCUN POST de commande : on ne clique JAMAIS « Payer » / « Confirmer la commande »,
// on n'envoie pas d'OTP. Ajout panier = 100 % localStorage (wizard local).
// Site : http://127.0.0.1:8899 (SPA statique ; API = VPS PROD, GET only attendus).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const OUT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/web-t52-2026-08-05/round-ACTIVE/waveB';
const BASE = 'http://127.0.0.1:8899';

// Accumulateurs module-scope (workers:1 → même process pour tous les tests)
const allPosts = [];
const states = [];

test.describe.configure({ mode: 'serial' });
test.setTimeout(300_000);

function wire(page, bucket) {
  page.on('console', (m) => {
    if (m.type() === 'error') bucket.consoleErrors.push(m.text().slice(0, 300));
  });
  page.on('response', (r) => {
    if (r.status() >= 400) bucket.badResponses.push({ status: r.status(), url: r.url().slice(0, 200) });
  });
  page.on('request', (r) => {
    if (r.method() === 'POST') {
      const entry = { method: 'POST', url: r.url(), when: new Date().toISOString() };
      bucket.posts.push(entry);
      allPosts.push(entry);
    }
  });
}

function freshBucket() {
  return { consoleErrors: [], badResponses: [], posts: [] };
}

async function snap(page, bucket, id, notes) {
  const png = path.join(OUT, `${id}.png`);
  await page.screenshot({ path: png, fullPage: false });
  const state = {
    state: id,
    url: page.url(),
    consoleErrors: bucket.consoleErrors.splice(0),
    badResponses: bucket.badResponses.splice(0),
    notes,
  };
  states.push(state);
  fs.writeFileSync(path.join(OUT, `${id}.json`), JSON.stringify(state, null, 2));
  return state;
}

async function waitApp(page) {
  // Babel standalone compile le JSX au chargement — attendre le rendu React réel.
  await page.waitForSelector('.lc-nav-brand', { timeout: 60_000 });
  await page.waitForTimeout(1500);
}

test.describe('Wave B desktop 1366x768', () => {
  test.use({ viewport: { width: 1366, height: 768 } });

  test('B01-B06 parcours lecture seule', async ({ page }) => {
    const bucket = freshBucket();
    wire(page, bucket);
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await waitApp(page);

    // ---------- B01 : wizard → panier local ----------
    await page.locator('.lc-nav-links button', { hasText: 'Menu' }).first().click();
    await page.waitForTimeout(1200);
    await page.locator('.lc-card-item:not(.is-soldout)').first().click();
    await page.waitForTimeout(800);

    // Fiche produit → Personnaliser (wizard) ou Ajouter direct
    const perso = page.locator('button', { hasText: 'Personnaliser' }).first();
    let usedWizard = false;
    if (await perso.isVisible().catch(() => false)) {
      usedWizard = true;
      await perso.click();
      await page.waitForTimeout(800);
      // Boucle wizard : sélectionner si nécessaire, avancer via le bouton pied de page.
      for (let i = 0; i < 15; i++) {
        const addBtn = page
          .locator('button:has-text("Ajouter au panier"), button:has-text("Voir récap"), button:has-text("Continuer")')
          .last();
        if (!(await addBtn.isVisible().catch(() => false))) break;
        const label = (await addBtn.textContent()) || '';
        const disabled = await addBtn.isDisabled().catch(() => false);
        if (disabled) {
          // step REQUIS : choisir l'option « Sans … » (0€) si dispo, sinon la 1ère non sélectionnée
          const sans = page.locator('.lc-wiz-choice:not(.is-on)', { hasText: /^Sans/i }).first();
          const opt = (await sans.isVisible().catch(() => false))
            ? sans
            : page.locator('.lc-wiz-choice:not(.is-on)').first();
          if (await opt.isVisible().catch(() => false)) await opt.click();
          else break;
          await page.waitForTimeout(400);
          continue;
        }
        const isAdd = /Ajouter au panier/.test(label);
        await addBtn.click();
        await page.waitForTimeout(700);
        if (isAdd) break;
      }
    } else {
      const addDirect = page.locator('button', { hasText: 'Ajouter au panier' }).first();
      await addDirect.click();
      await page.waitForTimeout(700);
    }

    // Panier ouvert (auto post-add sinon via nav)
    const cartDrawer = page.locator('.lc-cart-drawer.is-open');
    if (!(await cartDrawer.isVisible().catch(() => false))) {
      await page.locator('.lc-nav-btn-cart').first().click();
      await page.waitForTimeout(600);
    }
    await expect(page.locator('.lc-cart-drawer.is-open')).toBeVisible({ timeout: 10_000 });
    const cartTotal = await page
      .locator('.lc-cart-totals-row.is-total b')
      .first()
      .textContent()
      .catch(() => 'INTROUVABLE');
    const cartLines = await page.locator('.lc-cart-row-name').allTextContents().catch(() => []);
    const lineQty = await page.locator('.lc-cart-stepper b').allTextContents().catch(() => []);
    const linePrices = await page.locator('.lc-cart-row-price').allTextContents().catch(() => []);
    await snap(page, bucket, 'B01-panier-ouvert', {
      usedWizard,
      cartLines,
      lineQty,
      linePrices,
      totalAffiche: (cartTotal || '').trim(),
    });

    // ---------- B02 : checkout, écran infos client ----------
    await page.locator('.lc-cart-drawer.is-open button', { hasText: 'Passer commande' }).click();
    await page.waitForTimeout(1000);
    // Upsell éventuel (jusqu'à 3 pages) → « Non merci »
    for (let i = 0; i < 4; i++) {
      const skip = page.locator('button', { hasText: 'Non merci' }).first();
      if (await skip.isVisible().catch(() => false)) {
        await skip.click();
        await page.waitForTimeout(700);
      } else break;
    }
    await page.waitForTimeout(1500);
    // Champs visibles sur la page une-page « Commander »
    const fields = await page
      .locator('input:visible, textarea:visible')
      .evaluateAll((els) =>
        els.map((e) => ({
          type: e.type || e.tagName.toLowerCase(),
          placeholder: e.placeholder || null,
          ariaLabel: e.getAttribute('aria-label'),
        }))
      )
      .catch(() => []);
    await snap(page, bucket, 'B02-checkout-infos-client', {
      fields,
      remark: 'NE PAS soumettre — aucun clic sur Valider mes coordonnées / Payer',
    });

    // ---------- B03 : écran paiement ----------
    const payGroup = page.locator('[role="radiogroup"][aria-label="Mode de paiement"]');
    let payOptions = [];
    if (await payGroup.isVisible().catch(() => false)) {
      await payGroup.scrollIntoViewIfNeeded();
      payOptions = await payGroup.locator('[role="radio"], .lcf-paymethod').allTextContents().catch(() => []);
      await page.waitForTimeout(500);
    }
    // Bannière « paiement non finalisé » : uniquement si spontanée
    const banner = await page
      .locator('text=/paiement non finalis|pas été finalis|réessayer par carte/i')
      .first()
      .isVisible()
      .catch(() => false);
    await snap(page, bucket, 'B03-paiement-options', {
      payOptions: payOptions.map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean),
      banniereNonFinalise: banner,
    });

    // Option carte en ligne → montage Mollie Components (client-side, pas de paiement)
    const onlineOpt = payGroup.locator('.lcf-paymethod, [role="radio"]').filter({ hasText: /en ligne|Mollie/i }).first();
    if (await onlineOpt.isVisible().catch(() => false)) {
      await onlineOpt.click();
      await page.waitForTimeout(3000); // laisser mollie.js monter les iframes
      const mollieIframes = await page.locator('iframe[src*="mollie"]').count().catch(() => 0);
      await snap(page, bucket, 'B03b-mollie-components', {
        mollieIframes,
        remark: 'Montage seul — AUCUN clic sur Payer',
      });
    }

    // ---------- B04 : compte / connexion OTP ----------
    await page.locator('.lc-nav-brand').click();
    await page.waitForTimeout(1000);
    await page.locator('.lc-nav-btn-account').first().click();
    await page.waitForTimeout(1200);
    const acctFields = await page
      .locator('.lc-modal input:visible, [role="dialog"] input:visible')
      .evaluateAll((els) =>
        els.map((e) => ({ type: e.type, placeholder: e.placeholder || null, ariaLabel: e.getAttribute('aria-label') }))
      )
      .catch(() => []);
    await snap(page, bucket, 'B04-compte-connexion', {
      acctFields,
      remark: 'Écran de saisie seul — aucun OTP envoyé',
    });
    // fermer la modale
    const closeBtn = page.locator('button[aria-label="Fermer"], button[aria-label="Fermer le panier"]').first();
    if (await closeBtn.isVisible().catch(() => false)) await closeBtn.click();
    await page.waitForTimeout(500);

    // ---------- B05 : suivi de commande sans id valide ----------
    await page.goto(BASE + '/#track', { waitUntil: 'domcontentloaded' });
    await waitApp(page);
    const bodyText = ((await page.locator('main, body').first().textContent().catch(() => '')) || '')
      .replace(/\s+/g, ' ')
      .slice(0, 400);
    await snap(page, bucket, 'B05-suivi-sans-id', {
      routeApresColdLoad: page.url(),
      extraitTexte: bodyText,
      honnete: 'voir screenshot — garde cold-load peut rediriger home',
    });

    // ---------- B06 : mentions légales / CGV ----------
    await page.goto(BASE + '/legal/mentions.html', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await snap(page, bucket, 'B06-mentions-legales', {});
    await page.goto(BASE + '/legal/cgv.html', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await snap(page, bucket, 'B06b-cgv', {});

    // ---------- Nettoyage : vider le panier local ----------
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await waitApp(page);
    await page.evaluate(() => {
      try { localStorage.removeItem('lc.cart.v1'); } catch (_) {}
      try { localStorage.clear(); } catch (_) {}
    });
    const cartAfter = await page.evaluate(() => localStorage.getItem('lc.cart.v1'));
    expect(cartAfter).toBeNull();

    // GARDE-FOU : aucun POST de commande ne doit être parti
    const orderPosts = allPosts.filter((p) =>
      /\/api\/frontend\/order|mollie-checkout|guest-signup|coupon-checking|loyalty\/(redeem|register|qr)/.test(p.url)
    );
    expect(orderPosts, 'AUCUN POST commande/OTP autorisé en Wave B').toEqual([]);
  });
});

test.describe('Wave B mobile 390x844', () => {
  test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

  test('B07-B09 accueil / menu / panier mobile', async ({ page }) => {
    const bucket = freshBucket();
    wire(page, bucket);

    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await waitApp(page);
    await snap(page, bucket, 'B07-mobile-accueil', {
      burgerVisible: await page.locator('.lc-nav-burger').isVisible().catch(() => false),
    });

    // Nav mobile : burger → Menu
    const burger = page.locator('.lc-nav-burger');
    let navOk = false;
    if (await burger.isVisible().catch(() => false)) {
      await burger.click();
      await page.waitForTimeout(600);
      const menuLink = page.locator('#lc-mobile-menu button, #lc-mobile-menu a', { hasText: 'Menu' }).first();
      if (await menuLink.isVisible().catch(() => false)) {
        await menuLink.click();
        navOk = true;
      } else {
        await page.keyboard.press('Escape');
        await page.goto(BASE + '/#menu', { waitUntil: 'domcontentloaded' });
        await waitApp(page);
      }
    }
    await page.waitForTimeout(1200);
    await snap(page, bucket, 'B08-mobile-menu', {
      navMobileIntacte: navOk,
      cartes: await page.locator('.lc-card-item').count().catch(() => 0),
    });

    // Panier (vide — le panier desktop a été nettoyé, contexte séparé de toute façon)
    await page.locator('.lc-nav-btn-cart').first().click();
    await page.waitForTimeout(800);
    await snap(page, bucket, 'B09-mobile-panier', {
      drawerOuvert: await page.locator('.lc-cart-drawer.is-open').isVisible().catch(() => false),
    });

    // Nettoyage localStorage mobile aussi
    await page.evaluate(() => { try { localStorage.clear(); } catch (_) {} });
  });
});

test.afterAll(async () => {
  const manifest = {
    generatedAt: new Date().toISOString(),
    base: BASE,
    readOnly: true,
    states: states.map((s) => s.state),
    allPosts,
    orderPosts: allPosts.filter((p) =>
      /\/api\/frontend\/order|mollie-checkout|guest-signup|coupon-checking|loyalty\/(redeem|register|qr)/.test(p.url)
    ),
  };
  fs.writeFileSync(path.join(OUT, 'waveB-manifest.json'), JSON.stringify(manifest, null, 2));
});
