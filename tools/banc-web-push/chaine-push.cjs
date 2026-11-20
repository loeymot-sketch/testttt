// Chaîne réelle : vraie commande (API) → vrais changements de statut (caisse) → vrai écouteur → vraie file Redis → vraie requête
// Web Push CHIFFRÉE (RFC 8291) vers un faux service de notification local, que ce script DÉCHIFFRE pour lire le texte.
const http = require('http'); const crypto = require('crypto'); const fs = require('fs'); const path = require('path');
const { execFileSync } = require('child_process');
const ROOT = process.env.LC_BACKEND_ROOT; const API = process.env.LC_API || 'http://127.0.0.1:8766';
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const KEY = env('MIX_API_KEY');
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8', timeout: 90000 }).trim().split('\n').filter(Boolean).pop();
const attendre = (ms) => new Promise((r) => setTimeout(r, ms));
const b64u = (buf) => Buffer.from(buf).toString('base64url');
const resultats = []; const verifier = (ok, l, d) => { resultats.push(!!ok); console.log((ok ? '  ✓ ' : '  ✗ ') + l + (ok || d === undefined ? '' : '  → ' + JSON.stringify(d).slice(0, 300))); };
async function api(m, url, { token, corps } = {}) {
  const h = { 'X-API-KEY': KEY, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Idempotency-Key': crypto.randomUUID() };
  if (token) h.Authorization = 'Bearer ' + token;
  const r = await fetch(API + url, { method: m, headers: h, body: corps ? JSON.stringify(corps) : undefined });
  let j = {}; try { j = await r.json(); } catch (_) {} return { http: r.status, j };
}
const hkdf = (salt, ikm, info, len) => Buffer.from(crypto.hkdfSync('sha256', ikm, salt, info, len));
function dechiffrer(corps, ua) { // RFC 8188 / 8291, aes128gcm
  const salt = corps.subarray(0, 16); const idlen = corps[20]; const asPub = corps.subarray(21, 21 + idlen); const chiffre = corps.subarray(21 + idlen);
  const secret = ua.ecdh.computeSecret(asPub);
  const ikm = hkdf(ua.auth, secret, Buffer.concat([Buffer.from('WebPush: info\0'), ua.pub, asPub]), 32);
  const cek = hkdf(salt, ikm, Buffer.from('Content-Encoding: aes128gcm\0'), 16);
  const nonce = hkdf(salt, ikm, Buffer.from('Content-Encoding: nonce\0'), 12);
  const d = crypto.createDecipheriv('aes-128-gcm', cek, nonce); d.setAuthTag(chiffre.subarray(chiffre.length - 16));
  const clair = Buffer.concat([d.update(chiffre.subarray(0, chiffre.length - 16)), d.final()]);
  let i = clair.length - 1; while (i >= 0 && clair[i] === 0) i--; // retire le remplissage + délimiteur 0x02
  return clair.subarray(0, i).toString('utf8');
}
(async () => {
  // 0. faux service de notification
  const recus = []; let refus410 = false;
  const faux = http.createServer((req, res) => { const ch = []; req.on('data', (c) => ch.push(c)); req.on('end', () => { recus.push({ url: req.url, h: req.headers, corps: Buffer.concat(ch), t: Date.now() }); res.writeHead(refus410 ? 410 : 201).end(); }); });
  await new Promise((r) => faux.listen(9201, '127.0.0.1', r));
  // 1. client + appareil abonné (vraies clés P-256)
  const ecdh = crypto.createECDH('prime256v1'); ecdh.generateKeys(); const ua = { ecdh, pub: ecdh.getPublicKey(), auth: crypto.randomBytes(16) };
  const stamp = String(Date.now());
  const c = JSON.parse(tinker(`
    $u = \\App\\Models\\User::create(['name' => 'E2E-PUSH ${stamp}', 'email' => 'e2e-push-${stamp}@example.test', 'phone' => '06${stamp.slice(-8)}', 'country_code' => '+33', 'username' => 'e2epush${stamp}', 'password' => bcrypt('x${stamp}'), 'status' => 5, 'branch_id' => 1]);
    $u->assignRole('Customer'); $u->forceFill(['loyalty_points' => 0, 'loyalty_code' => 'EPU' . substr('${stamp}', -8)])->save();
    echo json_encode(['id' => $u->id, 'token' => $u->createToken('auth_token', ['*'])->plainTextToken]);`));
  const cle = await api('GET', '/api/frontend/push/key');
  verifier(cle.http === 200 && cle.j.data && cle.j.data.enabled === true && cle.j.data.key, '(anti-test-vide) le serveur local annonce Web Push actif et sa clé publique', cle.j);
  // abonnement par la VRAIE route (https exigé en production → le banc écrit la ligne comme le ferait la route, endpoint http local)
  const refusHote = await api('POST', '/api/frontend/push/subscription', { token: c.token, corps: { endpoint: 'https://evil.example/push/1', keys: { p256dh: b64u(ua.pub), auth: b64u(ua.auth) } } });
  verifier(refusHote.http === 422, 'la route refuse un hôte hors liste (422)', { http: refusHote.http });
  const accepte = await api('POST', '/api/frontend/push/subscription', { token: c.token, corps: { endpoint: 'http://127.0.0.1:9201/route/' + stamp, keys: { p256dh: b64u(ua.pub), auth: b64u(ua.auth) } }, });
  verifier(accepte.http === 201, 'la VRAIE route enregistre l\'appareil (hôte de banc autorisé par WEBPUSH_ALLOWED_HOSTS)', { http: accepte.http, j: accepte.j });
  tinker(`\\App\\Models\\WebPushSubscription::where('user_id', ${c.id})->delete(); echo 'ok';`);
  const endpoint = 'http://127.0.0.1:9201/push/' + stamp;
  tinker(`\\App\\Models\\WebPushSubscription::create(['user_id'=>${c.id},'endpoint_hash'=>hash('sha256','${endpoint}'),'endpoint'=>'${endpoint}','p256dh'=>'${b64u(ua.pub)}','auth'=>'${b64u(ua.auth)}']); echo 'ok';`);
  const pos = await api('POST', '/api/auth/login', { corps: { email: 'pos@lecayenne.fr', password: '123456' } });
  // 2. commande réelle, payée (comme après le webhook de paiement)
  const cmd = await api('POST', '/api/frontend/order', { token: c.token, corps: { branch_id: 1, order_type: 10, source: 5, is_advance_order: 10, payment_method: 1,
    items: JSON.stringify([{ item_id: 58, item_price: 1, quantity: 12, discount: 0, total_price: 12, item_variations: [], item_extras: [], instruction: 'E2E-PUSH' }]) } });
  const id = cmd.j && cmd.j.data && cmd.j.data.id; verifier(cmd.http === 201 && id, 'commande à emporter créée par le client', { http: cmd.http, j: cmd.http >= 300 ? cmd.j : undefined });
  verifier(recus.length === 0, '(anti-test-vide) aucune notification avant la validation du restaurant');
  tinker(`\\App\\Models\\Order::withoutGlobalScopes()->whereKey(${id})->update(['payment_status' => \\App\\Enums\\PaymentStatus::PAID]); echo 'ok';`);
  const numero = tinker(`$o = \\App\\Models\\Order::withoutGlobalScopes()->find(${id}); echo $o->queue_number ?: $o->order_serial_no;`);
  const t0 = Date.now();
  const marques = {};
  for (const st of [4, 7, 8]) {
    const r = await api('POST', '/api/admin/pos-order/change-status/' + id, { token: pos.j.token, corps: { status: st } }); marques[st] = Date.now() - t0; verifier(r.http === 200, 'la caisse passe la commande au statut ' + st, { http: r.http });
    if (st === 4) { for (let i = 0; i < 40 && recus.length < 1; i++) await attendre(500); } // « validée » (retard 5 s) arrive AVANT que la cuisine avance
    else await attendre(900);
  }
  for (let i = 0; i < 40 && recus.length < 2; i++) await attendre(500);
  const textes = recus.map((r) => { try { return JSON.parse(dechiffrer(r.corps, ua)); } catch (e) { return { erreur: String(e.message) }; } });
  verifier(recus.length === 2, 'EXACTEMENT 2 notifications reçues (validée, prête) — « en préparation » reste muet', { recus: recus.length, textes });
  console.log('  ℹ chronologie (ms depuis t0) : statuts', JSON.stringify(marques), '· notifications', JSON.stringify(recus.map((r, i) => ({ statut: textes[i].status, t: r.t - t0 }))));
  const v = textes.find((t) => t.status === 4); const p = textes.find((t) => t.status === 8);
  const N = 'n°\u00a0' + numero;
  verifier(v && v.title === 'Commande ' + N + ' validée' && v.body.startsWith('On s’en occupe\u00a0: vous recevrez une alerte dès qu’elle est prête.'), 'validée : titre et texte exacts déchiffrés' + (v ? ' « ' + v.title + ' — ' + v.body + ' »' : ''), v);
  verifier(v && /120\u00a0points gagnés, solde\u00a0: 120\u00a0points\.$/.test(v.body), 'validée : les points gagnés (120) et le solde sont dits grâce au retard de 5 s', v);
  verifier(v && !('urgency' in v) && !/€|euro|dépens/i.test(v.title + v.body), 'validée : l\'urgence n\'est pas dans le message chiffré, aucun montant', v);
  verifier(p && p.title === 'Commande ' + N + ' prête\u00a0!' && p.body === 'Elle vous attend au comptoir.' && p.tag === v.tag && v.tag === 'order-' + id && p.url === '/#orders', 'prête : texte exact, même tag que « validée », lien « Mes commandes »', p);
  const h = recus[0].h;
  verifier(/^vapid t=.+, k=.+$/.test(h.authorization || '') && h['content-encoding'] === 'aes128gcm' && Number(h.ttl) > 0 && h.urgency === 'high', 'requête conforme VAPID : Authorization, aes128gcm, TTL, urgence haute', { auth: (h.authorization || '').slice(0, 40), enc: h['content-encoding'], ttl: h.ttl, urg: h.urgency });
  const iv = textes.findIndex((t) => t.status === 4); const delai = recus[iv].t - t0 - marques[4]; verifier(iv === 0 && recus[iv].t - t0 >= marques[4] + 3500, '« validée » part ~5 s après la validation (' + (recus[iv].t - t0 - marques[4]) + ' ms après le retour de l\'appel)', { delai, marques });
  // 3. doublon : re-passer 8 ne renotifie pas
  const avant = recus.length; await api('POST', '/api/admin/pos-order/change-status/' + id, { token: pos.j.token, corps: { status: 8 } }); await attendre(2500);
  verifier(recus.length === avant, 'même statut rejoué : aucune nouvelle notification', { avant, apres: recus.length });
  // 4. remise → « Bon appétit »
  const rem = await api('POST', '/api/admin/pos-order/change-status/' + id, { token: pos.j.token, corps: { status: 13 } });
  for (let i = 0; i < 20 && recus.length < avant + 1; i++) await attendre(500);
  const dern = recus.length > avant ? JSON.parse(dechiffrer(recus[recus.length - 1].corps, ua)) : null;
  verifier(rem.http === 200 && dern && dern.title === 'Bon appétit\u00a0!' && dern.body === 'Votre commande ' + N + ' vous a été remise. Merci et à très vite au Cayenne\u00a0!', 'remise : « Bon appétit ! »', { http: rem.http, j: rem.j, dern });
  verifier(recus[recus.length - 1].h.urgency === 'normal', 'remise : urgence « normale » (le client n\'attend plus rien)', { urg: recus[recus.length - 1].h.urgency });
  // 4 bis. cuisine ultra-rapide : 4 puis 8 dans la seconde → « validée » (retardée) est périmée, seule « prête » arrive, une seule fois
  const cmdR = await api('POST', '/api/frontend/order', { token: c.token, corps: { branch_id: 1, order_type: 10, source: 5, is_advance_order: 10, payment_method: 1,
    items: JSON.stringify([{ item_id: 58, item_price: 1, quantity: 1, discount: 0, total_price: 1, item_variations: [], item_extras: [], instruction: 'E2E-PUSH-R' }]) } });
  const idR = cmdR.j && cmdR.j.data && cmdR.j.data.id;
  tinker(`\\App\\Models\\Order::withoutGlobalScopes()->whereKey(${idR})->update(['payment_status' => \\App\\Enums\\PaymentStatus::PAID]); echo 'ok';`);
  const avantR = recus.length;
  const stR = [];
  for (const st of [4, 7, 8]) { const r = await api('POST', '/api/admin/pos-order/change-status/' + idR, { token: pos.j.token, corps: { status: st } }); stR.push(r.http); }
  verifier(stR.every((h) => h === 200), '(anti-test-vide) la commande rapide a bien traversé 4 → 7 → 8', stR);
  await attendre(11000);
  const recusR = recus.slice(avantR).map((r) => JSON.parse(dechiffrer(r.corps, ua))).filter((t) => t.order_id === idR);
  verifier(recusR.length === 1 && recusR[0].status === 8, 'cuisine ultra-rapide (4 → 7 → 8 en 1 s) : « validée » périmée abandonnée, « prête » seule, jamais l\'inverse', recusR.map((t) => t.status));
  // 5. service de notification qui répond 410 → l'abonnement est supprimé
  refus410 = true;
  const cmd2 = await api('POST', '/api/frontend/order', { token: c.token, corps: { branch_id: 1, order_type: 10, source: 5, is_advance_order: 10, payment_method: 1,
    items: JSON.stringify([{ item_id: 58, item_price: 1, quantity: 1, discount: 0, total_price: 1, item_variations: [], item_extras: [], instruction: 'E2E-PUSH-2' }]) } });
  const id2 = cmd2.j && cmd2.j.data && cmd2.j.data.id;
  tinker(`\\App\\Models\\Order::withoutGlobalScopes()->whereKey(${id2})->update(['payment_status' => \\App\\Enums\\PaymentStatus::PAID]); echo 'ok';`);
  await api('POST', '/api/admin/pos-order/change-status/' + id2, { token: pos.j.token, corps: { status: 4 } });
  await api('POST', '/api/admin/pos-order/change-status/' + id2, { token: pos.j.token, corps: { status: 8 } });
  let restant = 1; for (let i = 0; i < 30 && restant > 0; i++) { await attendre(500); restant = Number(tinker(`echo \\App\\Models\\WebPushSubscription::where('user_id', ${c.id})->count();`)); }
  verifier(restant === 0, 'le service répond 410 (abonnement expiré) : la ligne est supprimée', { restant });
  // 6. la caisse n'a jamais été ralentie ni cassée : les commandes ont bien avancé
  const st = Number(tinker(`echo \\App\\Models\\Order::withoutGlobalScopes()->find(${id})->status;`));
  verifier(st === 13, 'la commande est bien « remise » (la notification n\'a rien perturbé)', { st });
  faux.close();
  console.log('\n' + (resultats.every(Boolean) ? '✓ ' + resultats.length + ' vérifications vertes' : '✗ ' + resultats.filter((x) => !x).length + ' échec(s) sur ' + resultats.length));
  process.exit(resultats.every(Boolean) ? 0 : 1);
})().catch((e) => { console.error('ERREUR', e); process.exit(2); });
