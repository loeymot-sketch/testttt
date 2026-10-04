// [Owner 2026-09-25 : « assure système de fidélité sur borne fonctionne ! »]
//
// Complète kiosk-loyalty-register-e2e.spec.js (qui mocke /loyalty/register pour
// éviter le throttle 5/min) avec le cas qui n'a AUCUN mock : un client fidélité
// RÉEL (créé en base, code connu) tape son code sur la borne, et la borne
// affiche le VRAI solde renvoyé par le VRAI endpoint /frontend/loyalty/check —
// aucune route interceptée, preuve bout-en-bout backend+frontend.
//
// Client fixture : créé une fois via `php artisan tinker` (id 685, téléphone
// 0600000156, code FIDE2E01, 250 points, nom "E2E Fidelite"). Ce test suppose
// son existence en base locale ; s'il est absent (autre environnement), le
// premier `expect` (solde visible) échoue avec un message clair plutôt qu'un
// faux vert.
//
// [Root cause 2026-09-25] Première tentative de fixture : un script tinker
// find-or-create sur le téléphone 0699887766 a trouvé un utilisateur dev-DB
// PRÉEXISTANT sans rapport ("Victime Test", id 156, fixture inerte du
// 2026-07-02) et lui a écrasé son loyalty_code/loyalty_points au lieu de
// créer un nouveau client — le test échouait alors sur un nom inattendu.
// Corrigé : id 156 restauré à son état d'origine (code=null, points=0,
// aucune loyalty_transactions ne le référençait), et un client DÉDIÉ créé
// sur un téléphone vérifié libre. Toujours vérifier l'unicité avant
// réutilisation d'un enregistrement trouvé par recherche.
const { test, expect } = require('@playwright/test');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const FIXTURE = { code: 'FIDE2E01', points: 250, name: 'E2E Fidelite' };

test('borne : un code fidélité réel affiche le vrai solde (aucun mock réseau)', async ({ page, request }) => {
  const login = await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  const loginData = await login.json();
  expect(loginData.token, 'le jeton de la borne doit être réel').toBeTruthy();

  await page.addInitScript(({ token, machineId, branchId }) => {
    localStorage.setItem('vuex', JSON.stringify({
      kioskCart: {
        kioskToken: token,
        kioskMachineId: machineId,
        branchId,
        orderType: 10,
        items: [{
          item_id: 103,
          name: 'Fixture fidélité',
          quantity: 1,
          convert_price: 0,
          item_variation_total: 0,
          item_extra_total: 0,
          item_variations: [],
          item_extras: [],
        }],
      },
    }));
  }, {
    token: loginData.token,
    machineId: loginData.kiosk?.machine_id,
    branchId: loginData.kiosk?.branch_id || 1,
  });

  let checkRequestFired = false;
  let checkResponseBody = null;
  page.on('response', async (res) => {
    if (/\/frontend\/loyalty\/check$/.test(res.url())) {
      checkRequestFired = true;
      try { checkResponseBody = await res.json(); } catch (_) {}
    }
  });

  await page.goto(`${BASE}/kiosk/loyalty`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.kiosk-loyalty-input')).toBeVisible({ timeout: 12_000 });
  await page.locator('.kiosk-loyalty-input').fill(FIXTURE.code);
  await page.locator('.kiosk-loyalty-step .kiosk-btn-primary.full').click();

  const points = page.locator('.kiosk-loyalty-points-value');
  await expect(points, 'le solde doit s\'afficher après un VRAI appel /loyalty/check (aucune route mockée)').toBeVisible({ timeout: 12_000 });

  expect(checkRequestFired, 'la borne doit avoir réellement appelé /frontend/loyalty/check').toBe(true);
  expect(checkResponseBody, 'la réponse réelle du serveur doit être exploitable').toBeTruthy();

  await expect(page.locator('.kiosk-loyalty-profile')).toContainText(FIXTURE.name);
  const shownPoints = parseInt((await points.innerText()).replace(/\D/g, ''), 10);
  expect(shownPoints, `le solde affiché (${shownPoints}) doit être le vrai solde en base (${FIXTURE.points})`).toBe(FIXTURE.points);
  await expect(page.locator('.kiosk-loyalty-error')).toHaveCount(0);
});

test('borne : un code fidélité inconnu affiche une erreur claire, jamais une page blanche', async ({ page, request }) => {
  const login = await request.post(`${BASE}/api/auth/kiosk-login`, {
    headers: { 'x-api-key': API_KEY, Accept: 'application/json' },
    data: { username: 'kiosk-lecayenne', password: 'kiosk123' },
  });
  const loginData = await login.json();

  await page.addInitScript(({ token, machineId, branchId }) => {
    localStorage.setItem('vuex', JSON.stringify({
      kioskCart: {
        kioskToken: token,
        kioskMachineId: machineId,
        branchId,
        orderType: 10,
        items: [{
          item_id: 103, name: 'Fixture fidélité', quantity: 1, convert_price: 0,
          item_variation_total: 0, item_extra_total: 0, item_variations: [], item_extras: [],
        }],
      },
    }));
  }, {
    token: loginData.token,
    machineId: loginData.kiosk?.machine_id,
    branchId: loginData.kiosk?.branch_id || 1,
  });

  await page.goto(`${BASE}/kiosk/loyalty`, { waitUntil: 'domcontentloaded' });
  await page.locator('.kiosk-loyalty-input').fill('CODEINEXISTANT999');
  await page.locator('.kiosk-loyalty-step .kiosk-btn-primary.full').click();

  await expect(page.locator('.kiosk-loyalty-error'), 'un code inconnu doit produire un message d\'erreur visible, pas un blocage silencieux').toBeVisible({ timeout: 12_000 });
  await expect(page.locator('.kiosk-loyalty-screen')).toBeVisible();
});
