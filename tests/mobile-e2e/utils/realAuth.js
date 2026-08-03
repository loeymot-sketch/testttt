// [GOAL-SYNC 2026-07-08] Auth RÉELLE pour la suite mobile-e2e.
// Le modèle mock (token 'test' + solde localStorage) a été remplacé par la
// couche réseau réelle (mobile/api/client.js — contrat §1/§7). Les specs qui
// vérifient le QR/l'historique/le profil réels ont besoin d'un VRAI token
// Sanctum kiosk:order : ce helper fait guest-signup OTP+verify sur le backend
// local :8766 et met le token en cache (1 verify par worker — évite le
// throttle Laravel sur /guest-signup/verify).
'use strict';

const API_BASE = process.env.LC_API_BASE || 'http://127.0.0.1:8766';
// Clé publique client (même valeur que mobile/index.html LC.config.apiKey / web api.js).
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

let cached = null; // { token, phone, userId } — cache par process worker

async function post(request, path, body) {
  const r = await request.post(API_BASE + path, {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-API-Key': API_KEY,
    },
    data: body,
  });
  return { status: r.status(), json: await r.json().catch(() => null) };
}

// Obtient (et met en cache) un token réel. `request` = fixture Playwright APIRequestContext.
// Retry doux sur 429 (throttle) — max ~90 s.
async function getRealToken(request) {
  if (cached) return cached;
  const phone = '0699' + String(Math.floor(100000 + Math.random() * 899999));
  for (let attempt = 0; attempt < 10; attempt++) {
    const otp = await post(request, '/api/auth/guest-signup/otp', { phone, code: '+33' });
    if (otp.status === 429) { await new Promise(r => setTimeout(r, 10000)); continue; }
    const v = await post(request, '/api/auth/guest-signup/verify', { phone, code: '+33', token: '1234' });
    if (v.status === 429) { await new Promise(r => setTimeout(r, 10000)); continue; }
    const token = v.json && (v.json.token || (v.json.data && v.json.data.token));
    if (token) {
      cached = { token, phone, userId: (v.json.user && v.json.user.id) || null };
      return cached;
    }
    throw new Error('guest-signup verify inattendu: HTTP ' + v.status + ' ' + JSON.stringify(v.json).slice(0, 200));
  }
  throw new Error('guest-signup toujours throttlé (429) après 10 tentatives');
}

// Boot l'app avec une session RÉELLE : storage.setAuth(token réel) puis reload.
// À appeler APRÈS waitForLoyaltyReady(page) (qui a posé onboarding_seen etc.).
async function seedRealAuth(page, request) {
  const auth = await getRealToken(request);
  await page.evaluate((a) => {
    window.LC.storage.setAuth({ token: a.token, phone: a.phone, user_id: a.userId });
  }, auth);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-screen-label]', { timeout: 15000 });
  return auth;
}

module.exports = { getRealToken, seedRealAuth, API_BASE, API_KEY };
