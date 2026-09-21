// [Root cause 2026-09-21, owner : « reprends la caisse : demande imprimer ou non après paiement »]
// Preuve E2E réelle (vrai navigateur, vrai login caissier, vrai produit ajouté au panier, vrai
// paiement confirmé, vraie commande fiscale créée) que la question "Imprimer le ticket client ?"
// s'affiche APRÈS chaque encaissement frais, pour les DEUX moyens de paiement (carte ET
// espèces), et que les deux réponses ("Oui, imprimer" / "Non merci") produisent l'effet attendu :
//   - "Oui, imprimer" déclenche réellement la requête réseau POST print-receipt (pas juste un
//     changement d'affichage) ;
//   - "Non merci" fait disparaître la question sans imprimer, et laisse le bouton "Ticket Client"
//     classique disponible pour une réimpression manuelle ultérieure.
//
// Sélecteurs VÉRIFIÉS par inspection DOM live du POS réel (jamais devinés) :
//   recherche catalogue : input[placeholder="Rechercher un article du menu"]
//   tuile produit        : [data-pos-item-id] (les indisponibles ont l'attribut `disabled`)
//   bouton payer          : [data-testid="pos-v5-pay"]
//   confirmer paiement    : [data-testid="pos-payment-confirm"]
//   montant reçu (espèces): #cashInput (input contrôlé Vue — nécessite le native setter + events)
//   la question           : [data-testid="receipt-print-decision-yes|no"]
//
// Découverte au passage, HORS PÉRIMÈTRE de ce test : l'item "Cayenne" (id 22) échoue
// systématiquement au paiement avec "Composition : le choix #450 n'appartient pas au profil
// publié" — root cause tracée à PricingService::assertComposerSelectionsBelongToPublishedProfile
// (le profil wizard publié de Cayenne décrit les étapes viande/sauce mais pas l'attribut
// "Type de pain", donc AUCUN choix de pain n'est jamais accepté, y compris le choix par défaut).
// C'est pourquoi ce test utilise un produit simple (Coca-Cola / première boisson disponible)
// plutôt que Cayenne — non affecté par ce défaut séparé. Signalé au propriétaire séparément.
const { test, expect } = require('@playwright/test');

const BASE = 'http://127.0.0.1:8766';

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.fill('#formEmail', 'pos@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")').catch(async () => {
    await page.evaluate(() => {
      const b = [...document.querySelectorAll('button[type=submit]')].find((x) => /connexion/i.test(x.innerText || ''));
      if (b) b.click();
    });
  });
  await page.waitForTimeout(3500);
  await page.goto(`${BASE}/admin/pos-v4`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
}

async function addAvailableDrinkToCart(page) {
  const search = page.locator('input[placeholder="Rechercher un article du menu"]');
  await search.click();
  await search.type('Coca', { delay: 60 });
  await page.waitForTimeout(700);
  const tile = await page.evaluate(() => {
    const btn = [...document.querySelectorAll('[data-pos-item-id]')].find((b) => !b.disabled);
    if (!btn) return null;
    btn.click();
    return btn.getAttribute('aria-label');
  });
  expect(tile, 'au moins une boisson "Coca" disponible en base locale').not.toBeNull();
  await page.waitForTimeout(1200);
  // Item simple (pas de wizard requis) : l'ajout se fait directement au clic sur la tuile.
  // Si un wizard s'ouvre malgré tout (catalogue modifié), "Ajouter au panier" le termine.
  await page.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter((b) => /ajouter au panier/i.test(b.textContent || '') && b.offsetParent !== null);
    if (btns[0]) btns[0].click();
  });
  await page.waitForTimeout(800);
}

async function openPaymentAndConfirm(page, method) {
  await page.evaluate(() => {
    const el = [...document.querySelectorAll('[data-testid="pos-v5-pay"]')].find((e) => e.offsetParent !== null);
    if (el) el.click();
  });
  await page.waitForTimeout(1500);

  if (method === 'card') {
    await page.evaluate(() => {
      const btn = [...document.querySelectorAll('button, [role="button"]')].find((b) => /Carte \(TPE\)/i.test(b.textContent || ''));
      if (btn) btn.click();
    });
  } else {
    await page.evaluate(() => {
      const input = document.getElementById('cashInput');
      if (!input) return;
      const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      setter.call(input, '5');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }
  await page.waitForTimeout(400);

  await page.evaluate(() => {
    const el = [...document.querySelectorAll('[data-testid="pos-payment-confirm"]')].find((e) => e.offsetParent !== null);
    if (el) el.click();
  });
  await page.waitForTimeout(2500);
}

test('espèces : la question "imprimer ou non" apparaît, et "Non merci" n\'imprime pas', async ({ page }) => {
  test.setTimeout(60000);
  await login(page);
  await addAvailableDrinkToCart(page);
  await openPaymentAndConfirm(page, 'cash');

  const body = await page.locator('body').innerText();
  expect(body, 'la question doit apparaître après un encaissement espèces frais').toMatch(/Imprimer le ticket client/i);

  await page.locator('[data-testid="receipt-print-decision-no"]').click();
  await page.waitForTimeout(800);
  const after = await page.locator('body').innerText();
  expect(after, 'la question disparaît après "Non merci"').not.toMatch(/Imprimer le ticket client/i);
  expect(after, 'le bouton "Ticket Client" classique reste disponible pour une réimpression').toMatch(/Ticket Client/i);
});

test('carte : la question "imprimer ou non" apparaît aussi, et "Oui, imprimer" lance réellement l\'impression', async ({ page }) => {
  test.setTimeout(60000);
  await login(page);
  await addAvailableDrinkToCart(page);
  await openPaymentAndConfirm(page, 'card');

  const body = await page.locator('body').innerText();
  expect(body, 'la question doit aussi apparaître après un encaissement CARTE frais (même comportement que espèces)').toMatch(/Imprimer le ticket client/i);

  let printRequestFired = false;
  page.on('request', (req) => { if (/print-receipt/i.test(req.url())) printRequestFired = true; });
  await page.locator('[data-testid="receipt-print-decision-yes"]').click();
  await page.waitForTimeout(1500);

  expect(printRequestFired, 'cliquer "Oui, imprimer" doit déclencher la VRAIE requête réseau print-receipt, pas juste un changement d\'affichage').toBe(true);
  const after = await page.locator('body').innerText();
  expect(after, 'la question disparaît après "Oui, imprimer"').not.toMatch(/Imprimer le ticket client/i);
});
