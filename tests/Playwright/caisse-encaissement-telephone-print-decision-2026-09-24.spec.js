// [Root cause 2026-09-24, owner : « je veux pas que ça imprime toujours, c'est du
// gaspillage de papier » — pour les commandes téléphone/web encaissées au comptoir]
//
// Preuve E2E réelle (vrai navigateur, vrai login caissier, vraie commande téléphone
// créée via phoneOrderSubmit, vrai encaissement comptoir confirmé via
// PosCounterCollectModal, vraie commande fiscale scellée à l'encaissement) que :
//   1. onCounterCollectConfirmed() ne déclenche PLUS aucune requête d'impression
//      automatique (GET admin/pos/orders/{id}/escpos) — seulement la question
//      "Imprimer le ticket client ?" (mêmes data-testid que la vente directe :
//      caisse-print-decision-2026-09-21.spec.js).
//   2. "Non merci" fait disparaître la question sans jamais imprimer.
//   3. "Oui, imprimer" (sur une commande séparée) déclenche réellement la requête
//      /escpos — pas juste un changement d'affichage.
//
// Sélecteurs vérifiés par lecture directe du code (jamais devinés) :
//   commande téléphone      : [data-testid="pos-phone-order"] (PosComponent.vue:1799)
//   file "à encaisser"      : [data-testid^="pos-shortcut-encaisser-"] (PosComponent.vue:655)
//   modale de collecte      : [data-testid="pos-counter-collect-modal"]
//   confirmer l'encaissement: [data-testid="pos-counter-collect-confirm"]
//   la question             : [data-testid="counter-collect-print-decision-yes|no"]
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
  await page.evaluate(() => {
    const btns = [...document.querySelectorAll('button')].filter((b) => /ajouter au panier/i.test(b.textContent || '') && b.offsetParent !== null);
    if (btns[0]) btns[0].click();
  });
  await page.waitForTimeout(800);
}

// Crée une commande téléphone (paiement différé, PENDING_COUNTER) via le vrai
// bouton "Commande téléphone" du POS — pas via DB directe — pour prouver
// l'intégration bout en bout, pas juste la logique du modal isolée.
async function createPhoneOrderAndReturnId(page) {
  await addAvailableDrinkToCart(page);
  const before = await page.evaluate(() => [...document.querySelectorAll('[data-testid^="pos-shortcut-encaisser-"]')].map((b) => b.getAttribute('data-testid')));
  await page.click('[data-testid="pos-phone-order"]');
  await page.waitForTimeout(2500);
  await page.waitForFunction((beforeIds) => {
    const ids = [...document.querySelectorAll('[data-testid^="pos-shortcut-encaisser-"]')].map((b) => b.getAttribute('data-testid'));
    return ids.some((id) => !beforeIds.includes(id));
  }, before, { timeout: 15000 });
  const newTestId = await page.evaluate((beforeIds) => {
    const ids = [...document.querySelectorAll('[data-testid^="pos-shortcut-encaisser-"]')].map((b) => b.getAttribute('data-testid'));
    return ids.find((id) => !beforeIds.includes(id));
  }, before);
  const orderId = parseInt(newTestId.replace('pos-shortcut-encaisser-', ''), 10);
  expect(Number.isFinite(orderId) && orderId > 0, 'la commande téléphone doit apparaître dans la file "à encaisser" avec un id réel').toBe(true);
  return orderId;
}

async function collectAtCounter(page, orderId) {
  await page.click(`[data-testid="pos-shortcut-encaisser-${orderId}"]`);
  await page.waitForSelector('[data-testid="pos-counter-collect-modal"]', { timeout: 10000 });
  await page.waitForTimeout(500); // pré-remplissage cashReceivedRaw = total (watcher async)
  await page.click('[data-testid="pos-counter-collect-confirm"]');
  await page.waitForTimeout(2000);
}

test('encaissement téléphone : aucune impression automatique, "Non merci" n\'imprime pas', async ({ page }) => {
  test.setTimeout(90000);
  await login(page);

  let printRequestFired = false;
  page.on('request', (req) => { if (/\/escpos(\?|$)/i.test(req.url())) printRequestFired = true; });

  const orderId = await createPhoneOrderAndReturnId(page);
  await collectAtCounter(page, orderId);

  expect(printRequestFired, 'aucune requête /escpos ne doit partir automatiquement à la confirmation d\'encaissement').toBe(false);

  const body = await page.locator('body').innerText();
  expect(body, 'la question doit apparaître après l\'encaissement comptoir d\'une commande téléphone').toMatch(/Imprimer le ticket client/i);

  await page.locator('[data-testid="counter-collect-print-decision-no"]').click();
  await page.waitForTimeout(800);

  expect(printRequestFired, '"Non merci" ne doit jamais déclencher l\'impression').toBe(false);
  const after = await page.locator('body').innerText();
  expect(after, 'la question disparaît après "Non merci"').not.toMatch(/Imprimer le ticket client/i);
});

test('encaissement téléphone : "Oui, imprimer" déclenche réellement la requête escpos', async ({ page }) => {
  test.setTimeout(90000);
  await login(page);

  const orderId = await createPhoneOrderAndReturnId(page);
  await collectAtCounter(page, orderId);

  const body = await page.locator('body').innerText();
  expect(body, 'la question doit apparaître').toMatch(/Imprimer le ticket client/i);

  let printRequestFired = false;
  page.on('request', (req) => { if (/\/escpos(\?|$)/i.test(req.url())) printRequestFired = true; });
  await page.locator('[data-testid="counter-collect-print-decision-yes"]').click();
  await page.waitForTimeout(2000);

  expect(printRequestFired, '"Oui, imprimer" doit déclencher la VRAIE requête réseau /escpos').toBe(true);
  const after = await page.locator('body').innerText();
  expect(after, 'la question disparaît après "Oui, imprimer"').not.toMatch(/Imprimer le ticket client/i);
});
