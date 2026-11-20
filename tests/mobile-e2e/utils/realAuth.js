// [GOAL-SYNC 2026-07-08] Auth RÉELLE pour la suite mobile-e2e.
// Le modèle mock (token 'test' + solde localStorage) a été remplacé par la
// couche réseau réelle (mobile/api/client.js — contrat §1/§7). Les specs qui
// vérifient le QR/l'historique/le profil réels ont besoin d'un VRAI token
// Sanctum kiosk:order : ce helper fait guest-signup OTP+verify sur le backend
// local :8766 et met le token en cache (1 verify par worker — évite le
// throttle Laravel sur /guest-signup/verify).
'use strict';

function loopbackApiBase(value) {
  const raw = value || 'http://127.0.0.1:8766';
  let parsed;
  try { parsed = new URL(raw); } catch (_error) { throw new Error('LC_API_BASE invalide : seule la boucle locale est autorisée.'); }
  const loopbackHosts = new Set(['127.0.0.1', 'localhost', '::1']);
  const hostname = String(parsed.hostname || '').replace(/^\[|\]$/g, '');
  if (!['http:', 'https:'].includes(parsed.protocol) || !loopbackHosts.has(hostname) || parsed.username || parsed.password || parsed.search || parsed.hash) {
    throw new Error('LC_API_BASE non autorisée : utilisez une adresse de boucle locale.');
  }
  return parsed.origin;
}

const API_BASE = loopbackApiBase(process.env.LC_API_BASE);
// Clé publique client (même valeur que mobile/index.html LC.config.apiKey / web api.js).
const API_KEY = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120';

let cached = null; // { token, phone, userId } — cache par process worker

async function post(request, path, body) {
  let r;
  try {
    r = await request.post(API_BASE + path, {
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-API-Key': API_KEY,
      },
      data: body,
    });
  } catch (_error) {
    throw new Error('API locale indisponible.');
  }
  const status = r.status();
  let payload = null;
  try { payload = await r.json(); } catch (_error) { payload = null; }
  if (path.endsWith('/otp')) {
    const devCode = payload && payload.dev_code;
    return { status, devCode: typeof devCode === 'string' && /^\d{4}$/.test(devCode) ? devCode : null };
  }
  const token = payload && (payload.token || (payload.data && payload.data.token));
  const user = payload && payload.user;
  return { status, token: typeof token === 'string' && token ? token : null, userId: user && user.id != null ? user.id : null };
}

function httpFailure(action, status) {
  if (status === 429) return new Error(action + ' limité (HTTP 429). Réessaie plus tard.');
  return new Error(action + ' refusé (HTTP ' + status + ').');
}

// Obtient (et met en cache) un token réel. `request` = fixture Playwright APIRequestContext.
// Retry doux sur 429 (throttle) — max ~90 s.
async function getRealToken(request) {
  if (cached) return cached;
  const phone = '0699' + String(Math.floor(100000 + Math.random() * 899999));
  for (let attempt = 0; attempt < 10; attempt++) {
    const otp = await post(request, '/api/auth/guest-signup/otp', { phone, code: '+33' });
    if (otp.status === 429) { await new Promise(r => setTimeout(r, 10000)); continue; }
    if (otp.status < 200 || otp.status >= 300) throw httpFailure('Envoi OTP local', otp.status);
    if (!otp.devCode) throw new Error('API locale OTP sans dev_code : SMS local non exposé, aucun code ne sera deviné.');
    const v = await post(request, '/api/auth/guest-signup/verify', { phone, code: '+33', token: otp.devCode });
    if (v.status === 429) { await new Promise(r => setTimeout(r, 10000)); continue; }
    if (v.status < 200 || v.status >= 300) throw httpFailure('Vérification OTP locale', v.status);
    if (v.token) {
      cached = { token: v.token, phone, userId: v.userId };
      return cached;
    }
    throw new Error('Vérification OTP locale sans session exploitable.');
  }
  throw new Error('OTP local toujours limité après plusieurs tentatives.');
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

module.exports = { getRealToken, seedRealAuth, API_BASE, API_KEY, loopbackApiBase };
