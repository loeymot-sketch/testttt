// [Owner 2026-09-25 · /goal] « le client a du mal à créer un compte directement
// lorsqu'il a mis son numéro… il faut la mettre très très optimisée pour une
// expérience [qui fait] gagner le maximum de souscription »
//
// Preuve E2E réelle (vrai navigateur, vrai kiosk-login) que le nouveau parcours
// tient sa promesse : un client dont le numéro n'est pas encore enregistré tape
// SON NUMÉRO UNE SEULE FOIS (vrai appel /frontend/loyalty/check, non mocké — le
// 404 réel du serveur est ce qui déclenche la bascule automatique), ne voit
// QU'UN SEUL champ à remplir (prénom — l'email reste optionnel), et obtient son
// compte fidélité sans jamais avoir eu à retaper son téléphone ni à chercher un
// lien "S'inscrire" séparé.
//
// [Root cause 2026-09-25] Une première version de ce test appelait aussi le VRAI
// /frontend/loyalty/register (throttle:5,1) — Playwright en environnement partagé
// (plusieurs runs rapprochés + retries) épuise ce quota en quelques dizaines de
// secondes et le 429 qui en résulte est indiscernable d'un vrai défaut produit
// (repro : `php artisan cache:clear` n'a même pas suffi à le lever avant la
// prochaine minute). kiosk-loyalty-register-e2e.spec.js avait DÉJÀ documenté ce
// piège et mockait /register pour cette raison précise — corrigé ici en suivant
// la même convention. Le /check réel, lui, a un throttle plus large (10/min) et
// est la partie que ce test doit prouver de bout en bout (c'est lui qui décide
// d'ouvrir l'inscription rapide) ; /register est déjà prouvé par ailleurs
// (KioskRegisterKeepsEmailTest côté serveur, kiosk-loyalty-register-e2e.spec.js
// côté UI).
const { test, expect } = require('@playwright/test');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

// Même helper que kiosk-loyalty-register-e2e.spec.js : les champs register sont
// `readonly` (le clavier virtuel maison les pilote), donc on pose la valeur via
// le native setter + events, comme le ferait le clavier — sans dépendre de sa
// mécanique interne, déjà couverte par kioskLoyaltyConsentWiring.spec.js.
function setInputValue(page, testId, value) {
  return page.locator(`[data-testid="${testId}"]`).evaluate((input, nextValue) => {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(input, nextValue);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, value);
}

async function loginAndOpenLoyalty(page, request) {
  const login = await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  const loginData = await login.json();
  expect(loginData.token, 'le jeton de la borne doit être réel').toBeTruthy();

  await page.addInitScript(({ token, machineId, branchId }) => {
    localStorage.setItem('vuex', JSON.stringify({
      kioskCart: {
        kioskToken: token, kioskMachineId: machineId, branchId, orderType: 10,
        items: [{
          item_id: 103, name: 'Fixture fidélité', quantity: 1, convert_price: 0,
          item_variation_total: 0, item_extra_total: 0, item_variations: [], item_extras: [],
        }],
      },
    }));
  }, { token: loginData.token, machineId: loginData.kiosk?.machine_id, branchId: loginData.kiosk?.branch_id || 1 });

  await page.goto(`${BASE}/kiosk/loyalty`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.kiosk-loyalty-input')).toBeVisible({ timeout: 12_000 });
}

test('borne : numéro inconnu (vrai /check) → un seul champ (prénom) → compte créé, jamais retaper le téléphone', async ({ page, request }) => {
  await loginAndOpenLoyalty(page, request);

  const suffix = String(Date.now()).slice(-8);
  const phone = `07${suffix}`.slice(0, 10);
  const firstName = `Rapide${suffix}`;

  let checkRequestFired = false;
  let registerRequestBody = null;
  page.on('response', (res) => {
    if (/\/frontend\/loyalty\/check$/.test(res.url())) checkRequestFired = true;
  });
  await page.route('**/api/frontend/loyalty/register', async (route) => {
    const body = route.request().postDataJSON();
    registerRequestBody = body;
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ status: true, data: { name: body.name, points: 0, loyalty_code: `RAPID${suffix}` } }),
    });
  });

  // 1) Il tape SON NUMÉRO — une seule fois, dans le champ de saisie normal.
  //    Le VRAI /frontend/loyalty/check répond 404 (numéro inconnu) : c'est ce
  //    vrai 404, pas un mock, qui doit déclencher la bascule automatique.
  await page.locator('.kiosk-loyalty-input').fill(phone);
  await page.locator('.kiosk-loyalty-step .kiosk-btn-primary.full').click();

  // 2) Bascule AUTOMATIQUE sur l'inscription (jamais un simple message d'erreur
  //    qu'il faudrait remarquer, jamais de bouton "S'inscrire" séparé à trouver).
  await expect(page.getByTestId('kiosk-loyalty-phone-confirm'), 'le numéro déjà tapé doit être confirmé à l\'écran, jamais redemandé').toBeVisible({ timeout: 8000 });
  await expect(page.getByTestId('kiosk-loyalty-phone-confirm')).toContainText(phone);
  expect(checkRequestFired, 'le vrai /loyalty/check doit avoir répondu (aucun mock sur ce point)').toBe(true);

  // 3) Le champ téléphone du formulaire est ABSENT (déjà connu) — seul le prénom reste.
  await expect(page.getByTestId('kiosk-loyalty-register-phone'), 'le téléphone ne doit jamais être redemandé').toHaveCount(0);
  await expect(page.getByTestId('kiosk-loyalty-register-name')).toBeVisible();

  // 4) Il tape SEULEMENT son prénom (email volontairement laissé vide — reste optionnel,
  //    ne doit jamais bloquer la souscription).
  await setInputValue(page, 'kiosk-loyalty-register-name', firstName);

  const submitBtn = page.locator('.kiosk-loyalty-step .kiosk-btn-primary.full');
  await expect(submitBtn).toContainText(/créer mon compte/i);
  await submitBtn.click();

  // Consentement RGPD requis avant le premier POST /register.
  await expect(page.getByTestId('kiosk-consent-modal')).toBeVisible({ timeout: 8000 });
  await page.getByTestId('kiosk-consent-loyalty').check();
  await page.getByTestId('kiosk-consent-accept').click();

  // 5) Compte créé, solde affiché, ET le payload confirme qu'AUCUN second passage
  //    phone n'était nécessaire : le numéro envoyé au serveur == celui tapé au départ.
  const points = page.locator('.kiosk-loyalty-points-value');
  await expect(points, 'le compte doit être créé et le solde affiché — jamais un blocage').toBeVisible({ timeout: 12_000 });
  await expect(page.locator('.kiosk-loyalty-profile')).toContainText(firstName);
  await expect(page.locator('.kiosk-loyalty-error')).toHaveCount(0);

  expect(registerRequestBody, 'le /loyalty/register doit avoir été appelé').toBeTruthy();
  expect(registerRequestBody.phone, 'le téléphone envoyé au serveur doit être EXACTEMENT celui tapé au départ, jamais redemandé/modifié').toBe(phone);
  expect(registerRequestBody.name, 'le prénom saisi doit être transmis').toBe(firstName);
});
