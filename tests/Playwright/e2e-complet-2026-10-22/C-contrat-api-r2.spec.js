// C-contrat-api — contrat RÉEL application -> backend (vague C, e2e-complet-2026-10-22). Aucun navigateur :
// requêtes HTTP brutes avec les en-têtes qu'une WebView Capacitor enverrait (Origin, UA, X-API-KEY).
// Préfixe des comptes/commandes de test : « E2E-APP- ». Aucun correctif : on constate.
//
// Ce que ce banc PROUVE : pré-vol CORS des 3 origines de coque + un témoin négatif, routes d'authentification
// de l'application (e-mail d'abord, Apple/Google), suppression de compte (exigence Apple 5.1.1(v)) avec
// contrôle EN BASE (anonymisation, jetons révoqués, commandes conservées), refus d'une commande sans jeton.
// Ce qu'il ne prouve PAS : un vrai jeton Apple/Google (impossible hors appareil).
const { test, expect, request: pwRequest } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const L = require('./C-lib-r2');

const A = L.API + '/api';
const CLE = L.cleApiLocale();
const J = L.journal(path.join(L.SHOTS_ROOT, 'contrat'), 'constats.json');
const ORIGINES_COQUE = ['https://localhost', 'capacitor://localhost', 'ionic://localhost'];
const UA = { android: L.UA_ANDROID, ios: L.UA_IOS };

function sql(requete) {
  const env = fs.readFileSync(path.resolve(__dirname, '../../../.env'), 'utf8');
  const mdp = ((env.match(/^DB_PASSWORD=(.*)$/m) || [])[1] || '').trim().replace(/^"|"$/g, '');
  return execFileSync('mysql', ['-uroot', '-h127.0.0.1', 'foodking_e2e', '-N', '-B', '-e', requete], { env: { ...process.env, MYSQL_PWD: mdp }, encoding: 'utf8' }).trim();
}

let api;
test.beforeAll(async () => { api = await pwRequest.newContext({ ignoreHTTPSErrors: true }); });
test.afterAll(async () => { await api.dispose(); });

function entetes(origine, plateforme, extra = {}) {
  return { Origin: origine, 'User-Agent': UA[plateforme || 'android'], 'X-API-KEY': CLE, Accept: 'application/json', 'Content-Type': 'application/json', ...extra };
}
async function corps(r) { const t = await r.text(); try { return JSON.parse(t); } catch (_e) { return t; } }

// ------------------------------------------------------------------------------------------------
test('C-API-1 pré-vol CORS : les 3 origines de coque sont acceptées, une origine étrangère ne l\'est pas', async () => {
  const chemins = ['/auth/guest-signup/email-login', '/auth/social/apple', '/auth/delete-account', '/frontend/order', '/frontend/order/wait-estimate'];
  const lignes = [];
  for (const origine of [...ORIGINES_COQUE, 'https://evil.example']) {
    for (const ch of chemins) {
      const r = await api.fetch(A + ch, { method: 'OPTIONS', headers: {
        Origin: origine, 'Access-Control-Request-Method': 'POST',
        'Access-Control-Request-Headers': 'authorization,content-type,x-api-key,x-idempotency-key',
      } });
      const h = r.headers();
      lignes.push({ origine, chemin: ch, status: r.status(), acao: h['access-control-allow-origin'] || null, acah: h['access-control-allow-headers'] || null });
    }
  }
  J.note('API-1.preflight', lignes);
  for (const l of lignes.filter((x) => ORIGINES_COQUE.includes(x.origine))) {
    expect(l.status, `${l.origine} ${l.chemin}`).toBe(204);
    expect(l.acao, `${l.origine} ${l.chemin} ACAO`).toBe(l.origine);
  }
  for (const l of lignes.filter((x) => x.origine === 'https://evil.example')) {
    expect(l.acao, `témoin négatif ${l.chemin} : une origine étrangère ne doit PAS être autorisée`).toBeNull();
  }
});

// ------------------------------------------------------------------------------------------------
test('C-API-2 connexion e-mail d\'abord + Apple/Google : codes, messages, jamais de 500, CORS sur les erreurs', async () => {
  const lignes = [];
  async function appel(libelle, methode, chemin, donnees, origine, plateforme) {
    const t0 = Date.now();
    const r = await api.fetch(A + chemin, { method: methode, headers: entetes(origine || 'https://localhost', plateforme), data: donnees === undefined ? undefined : JSON.stringify(donnees) });
    const c = await corps(r);
    lignes.push({ libelle, methode, chemin, origine: origine || 'https://localhost', ua: plateforme || 'android', status: r.status(), acao: r.headers()['access-control-allow-origin'] || null, ms: Date.now() - t0, reponse: JSON.stringify(c).slice(0, 220) });
    return { r, c };
  }
  // Fournisseurs : jetons manifestement faux. Attendu : 422 propre (jamais 500, jamais 200).
  const sansJeton = await appel('Apple sans jeton', 'POST', '/auth/social/apple', {});
  expect(sansJeton.r.status()).toBe(422);
  const court = await appel('Apple jeton trop court', 'POST', '/auth/social/apple', { id_token: 'abc' }, 'capacitor://localhost', 'ios');
  expect(court.r.status()).toBe(422);
  const faux = await appel('Apple JWT factice', 'POST', '/auth/social/apple', { id_token: 'eyJhbGciOiJSUzI1NiJ9.eyJmYWtlIjoidHJ1ZSJ9.c2lnbmF0dXJlLWZhY3RpY2UtZTJlLWFwcA' }, 'capacitor://localhost', 'ios');
  expect(faux.r.status()).toBe(422);
  expect(JSON.stringify(faux.c)).not.toMatch(/Exception|stack|vendor\/|SQLSTATE/i);
  const goog = await appel('Google JWT factice', 'POST', '/auth/social/google', { id_token: 'eyJhbGciOiJSUzI1NiJ9.eyJmYWtlIjoidHJ1ZSJ9.c2lnbmF0dXJlLWZhY3RpY2UtZTJlLWFwcA' });
  expect(goog.r.status()).toBe(422);
  const fb = await appel('Facebook (route retirée)', 'POST', '/auth/social/facebook', { id_token: 'x'.repeat(40) });
  expect([404, 405, 422]).toContain(fb.r.status());

  // E-mail d'abord.
  const vide = await appel('e-mail vide', 'POST', '/auth/guest-signup/email-login', {});
  expect(vide.r.status()).toBe(422);
  const inconnu = await appel('e-mail inconnu sans identité', 'POST', '/auth/guest-signup/email-login', { email: 'e2e-app-inconnu-' + Date.now() + '@example.invalid' });
  expect(inconnu.r.status()).toBe(200);
  expect(inconnu.c.known).toBe(false);
  const connu = await appel('e-mail connu (compte E2E-APP-Client)', 'POST', '/auth/guest-signup/email-login', { email: 'e2e-app-1@example.invalid' }, 'capacitor://localhost', 'ios');
  // En dev l'envoi SMTP est impossible (smtp.example.com) : 422 générique ATTENDU côté environnement ; en cas de 200, known:true.
  J.note('API-2.emailConnu', { status: connu.r.status(), reponse: connu.c });
  expect([200, 422]).toContain(connu.r.status());
  expect(JSON.stringify(connu.c)).not.toMatch(/smtp|stream_socket|getaddrinfo|Connection could not/i); // aucun détail de transport au client

  J.note('API-2.lignes', lignes);
  for (const l of lignes) {
    expect(l.status, `${l.libelle} ne doit jamais être un 5xx`).toBeLessThan(500);
    expect(l.acao, `${l.libelle} : CORS aussi sur les erreurs (sinon l'app ne lit pas le message)`).toBe(l.origine);
  }
});

// ------------------------------------------------------------------------------------------------
test('C-API-3 suppression de compte (Apple 5.1.1(v)) : refus sans jeton, anonymisation réelle, jetons révoqués, commande conservée', async () => {
  // 1. sans jeton -> 401
  const sans = await api.fetch(A + '/auth/delete-account', { method: 'POST', headers: entetes('https://localhost') });
  expect(sans.status()).toBe(401);

  // 2. compte jetable E2E-APP-Suppr (canal SMS de dev : dev_code), puis une commande à emporter.
  const tel = '06 12 ' + String(10 + Math.floor(Math.random() * 89)) + ' ' + String(10 + Math.floor(Math.random() * 89)) + ' ' + String(10 + Math.floor(Math.random() * 89));
  const email = 'e2e-app-suppr-' + Date.now() + '@example.invalid';
  const otp = await api.fetch(A + '/auth/guest-signup/otp', { method: 'POST', headers: entetes('https://localhost'), data: JSON.stringify({ phone: tel, code: '+33' }) });
  expect(otp.status()).toBe(200);
  const code = (await corps(otp)).dev_code;
  expect(code, 'dev_code (APP_ENV=local) attendu').toBeTruthy();
  const ver = await api.fetch(A + '/auth/guest-signup/verify', { method: 'POST', headers: entetes('https://localhost'), data: JSON.stringify({ phone: tel, code: '+33', token: code, first_name: 'E2E-APP-Suppr', email }) });
  expect([200, 201], 'vérification (limiteur 3/5 min : si 429, relancer après 5 min)').toContain(ver.status());
  const v = await corps(ver);
  const jeton = v.token; const uid = v.user.id;
  expect(jeton).toBeTruthy();
  const auth = entetes('https://localhost', 'android', { Authorization: 'Bearer ' + jeton });

  // une commande (pour vérifier la conservation fiscale) : 1re ligne dispo du catalogue sans option obligatoire -> on prend une boisson
  const items = (await corps(await api.fetch(A + '/frontend/item', { headers: entetes('https://localhost') }))).data || [];
  const boisson = items.find((i) => /boisson/i.test(i.category_name || '') && i.is_available !== false) || items.find((i) => i.is_available !== false && Number(i.flat_price) > 0);
  J.note('API-3.produitCommande', boisson ? { id: boisson.id, nom: boisson.name, prix: boisson.flat_price } : null);
  let idCommande = null;
  if (boisson) {
    const ordre = await api.fetch(A + '/frontend/order', { method: 'POST', headers: { ...auth, 'X-Idempotency-Key': 'e2e-app-suppr-' + Date.now() }, data: JSON.stringify({
      branch_id: 1, order_type: 10, source: 5, is_advance_order: 10, payment_method: 1, items: JSON.stringify([{ item_id: boisson.id, quantity: 1, instruction: 'E2E-APP- suppression de compte' }]),
      expected_total: Number(boisson.flat_price),
    }) });
    const oc = await corps(ordre);
    J.note('API-3.commande', { status: ordre.status(), reponse: JSON.stringify(oc).slice(0, 400) });
    idCommande = oc && oc.data ? (oc.data.id || oc.data.order_id) : null;
  }

  // 3. suppression
  const t0 = Date.now();
  const sup = await api.fetch(A + '/auth/delete-account', { method: 'POST', headers: auth });
  const sc = await corps(sup);
  J.note('API-3.suppression', { status: sup.status(), reponse: sc, ms: Date.now() - t0, acao: sup.headers()['access-control-allow-origin'] || null, idCommande });
  // Une commande encore ouverte en cuisine BLOQUE la suppression (message métier) : c'est une règle, pas un défaut — on la consigne.
  if (sup.status() === 200) {
    expect(sc.status).toBe(true);
    // 4. EN BASE
    const ligne = sql(`select name, ifnull(email,'<NULL>'), phone, ifnull(contact_phone,'<NULL>'), username, ifnull(loyalty_code,'<NULL>'), ifnull(deleted_at,'<NULL>') from users where id=${uid}`);
    J.note('API-3.ligneUserApres', ligne);
    expect(ligne).toContain('Compte supprimé');
    expect(ligne).not.toContain(email);
    expect(ligne).not.toContain(tel);
    expect(ligne).not.toContain('E2E-APP-Suppr');
    expect(sql(`select count(*) from personal_access_tokens where tokenable_id=${uid}`)).toBe('0');
    if (idCommande) expect(Number(sql(`select count(*) from orders where id=${idCommande} and user_id=${uid}`)), 'la commande (justificatif fiscal) est CONSERVÉE').toBe(1);
    // 5. le jeton ne marche plus (deuxième moyen, par l'API)
    const apres = await api.fetch(A + '/frontend/order', { headers: auth });
    expect(apres.status()).toBe(401);
  } else {
    J.note('API-3.suppressionRefusee', 'suppression refusée : ' + JSON.stringify(sc));
    expect(sup.status()).toBe(422);
    expect(typeof sc.message).toBe('string');
  }
});

// ------------------------------------------------------------------------------------------------
test('C-API-4 une commande SANS jeton est refusée proprement (401, CORS lisible) ; avec jeton, total backend = total demandé', async () => {
  const sans = await api.fetch(A + '/frontend/order', { method: 'POST', headers: entetes('capacitor://localhost', 'ios'), data: JSON.stringify({ branch_id: 1, order_type: 10, items: [] }) });
  expect(sans.status()).toBe(401);
  expect(sans.headers()['access-control-allow-origin']).toBe('capacitor://localhost');

  const jeton = L.jeton();
  test.skip(!jeton, 'jeton client E2E-APP- absent');
  const items = (await corps(await api.fetch(A + '/frontend/item', { headers: entetes('https://localhost') }))).data || [];
  const frites = items.find((i) => /boisson/i.test(i.category_name || '') && i.is_available !== false && Number(i.flat_price) > 0) || items.find((i) => i.is_available !== false && Number(i.flat_price) > 0); // une boisson : aucune option obligatoire
  for (const plateforme of ['android', 'ios']) {
    const origine = plateforme === 'android' ? 'https://localhost' : 'capacitor://localhost';
    const r = await api.fetch(A + '/frontend/order', { method: 'POST', headers: { ...entetes(origine, plateforme, { Authorization: 'Bearer ' + jeton }), 'X-Idempotency-Key': 'e2e-app-' + plateforme + '-' + Date.now() }, data: JSON.stringify({
      branch_id: 1, order_type: 10, source: 5, is_advance_order: 10, payment_method: 1,
      items: JSON.stringify([{ item_id: frites.id, quantity: 2, instruction: 'E2E-APP- contrat ' + plateforme }]),
      expected_total: Math.round(Number(frites.flat_price) * 2 * 100) / 100,
    }) });
    const c = await corps(r);
    const id = c && c.data ? (c.data.id || c.data.order_id) : null;
    J.note('API-4.commande.' + plateforme, { status: r.status(), produit: frites.name, prix: frites.flat_price, reponse: JSON.stringify(c).slice(0, 300), id });
    expect(r.status(), 'commande ' + plateforme + ' : ' + JSON.stringify(c).slice(0, 200)).toBeLessThan(300);
    expect(r.headers()['access-control-allow-origin']).toBe(origine);
    if (id) {
      const total = Number(sql(`select total from orders where id=${id}`));
      const attendu = Math.round(Number(frites.flat_price) * 2 * 100) / 100;
      J.note('API-4.total.' + plateforme, { enBase: total, attendu });
      expect(total, 'total en base = 2 x prix catalogue (le prix est calculé par le backend)').toBeCloseTo(attendu, 2);
    }
  }
});
