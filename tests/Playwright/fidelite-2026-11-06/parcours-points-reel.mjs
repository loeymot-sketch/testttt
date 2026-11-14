// Banc RÉEL (aucune réponse simulée) : site miroir :8942 → backend privé :8781 (base foodking_points, copie isolée).
// Client réel commande « payer sur place » → caisse accepte → caisse encaisse → le navigateur voit les points tomber,
// sans recharger, et jamais un montant dépensé. Puis tentatives d'abus par le client.
import { chromium } from '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/node_modules/playwright/index.mjs';
import fs from 'fs';
import { execSync } from 'child_process';
import crypto from 'crypto';

const S = '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/d8f87420-2dda-4fc6-a18a-1d5c61abf968/scratchpad';
const C = JSON.parse(fs.readFileSync(S + '/e2e-comptes.json', 'utf8'));
const API = 'http://127.0.0.1:8781', SITE = 'http://127.0.0.1:8942';
const KEY = execSync("grep -E '^MIX_API_KEY=' /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.env | cut -d= -f2-").toString().trim();
const sql = (q) => execSync(`mysql -uroot -h127.0.0.1 -N -B foodking_points -e "${q}"`).toString().trim();
let echecs = 0;
const verifier = (nom, ok, d = '') => { console.log((ok ? '  ✓ ' : '  ✗ ') + nom + (ok || !d ? '' : '  → ' + d)); if (!ok) echecs++; };
async function api(method, path, token, body, idem = true) {
  const h = { Accept: 'application/json', 'Content-Type': 'application/json', 'x-api-key': KEY, Authorization: 'Bearer ' + token };
  if (idem && method !== 'GET') h['X-Idempotency-Key'] = crypto.randomUUID();
  const r = await fetch(API + path, { method, headers: h, body: body ? JSON.stringify(body) : undefined });
  const txt = await r.text(); let json = null; try { json = JSON.parse(txt); } catch (_) {}
  return { http: r.status, json, txt };
}
const soldeBrut = () => Number(sql(`select loyalty_points from users where id=${C.client_id}`));
const DEPART = soldeBrut(); // le banc compare des ÉCARTS : un client déjà crédité par un passage précédent reste valable
const solde = () => soldeBrut() - DEPART;

const BURGER = { item_id: 101, quantity: 1, item_variations: [{ id: 427, quantity: 1 }, { id: 1149, quantity: 1 }], item_extras: [] };
// Restaurant fermé à l'heure du banc : commande PROGRAMMÉE ce soir 19 h (même forme que le banc de l'audit).
// Créneau = demain 19:00 (une date figée devient un créneau passé le soir même → 422 « trop proche »).
const CRENEAU = (() => { const d = new Date(Date.now() + 864e5), z = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())} 19:00:00`; })();
const corps = { branch_id: 1, order_type: 10, source: 5, is_advance_order: 5, scheduled_at: CRENEAU, payment_method: 1, items: JSON.stringify([BURGER]) };

// ── 1. Le client commande (payer sur place) ────────────────────────────────────────────────
const o = await api('POST', '/api/frontend/order', C.client_token, corps);
const id = o.json?.data?.id;
console.log('commande', o.http, id, 'total', o.json?.data?.total, o.http >= 300 ? o.txt.slice(0, 300) : '');
if (!id) process.exit(2);
const total = Number(sql(`select total from orders where id=${id}`));
const attendu = Math.floor(Math.round(total * 100) / 100 * 10);
verifier('commande écrite dans la base PRIVÉE, absente de la base de l\'audit',
  sql(`select count(*) from orders where id=${id} and user_id=${C.client_id}`) === '1'
  && execSync(`mysql -uroot -h127.0.0.1 -N -B foodking_e2e -e "select count(*) from orders where id=${id} and user_id=${C.client_id}"`).toString().trim() === '0');

// ── 2. Le navigateur, client connecté ──────────────────────────────────────────────────────
const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 430, height: 932 }, locale: 'fr-FR' });
await ctx.addInitScript((t) => { try { localStorage.setItem('lecayenne.authToken', t); } catch (_) {} }, C.client_token);
const page = await ctx.newPage();
const erreurs = []; page.on('pageerror', (e) => erreurs.push(String(e.message).slice(0, 160)));
const versProd = []; page.on('request', (r) => { if (/vps-418872ac|lecayenne\.fr\/api|:8766\//.test(r.url())) versProd.push(r.url()); });
const texte = () => page.evaluate(() => (document.body && document.body.innerText) || '');
await page.goto(SITE + '/index.html#orders', { waitUntil: 'load' });
await page.getByText('commande', { exact: false }).first().waitFor({ timeout: 20000 });
await page.waitForTimeout(2500);
let t = await texte();
verifier('Mes commandes : aucun montant dépensé', !/dépens/i.test(t), (t.match(/.{0,50}dépens.{0,30}/i) || [''])[0]);
verifier('payer sur place, en attente : « crédités au règlement », aucun nombre', /Points crédités au règlement de votre commande/i.test(t), '');
verifier('solde 0 avant toute validation', solde() === 0, 'solde=' + solde());

// ── 3. La caisse accepte ───────────────────────────────────────────────────────────────────
const acc = await api('POST', `/api/admin/online-order/change-status/${id}`, C.pos_token, { status: 4, preparation_time: 15 });
verifier('caisse : acceptation 200', acc.http === 200, acc.txt.slice(0, 200));
verifier('acceptée mais pas réglée : toujours 0 point (pas de points sans achat)', solde() === 0, 'solde=' + solde());

// ── 4. La caisse encaisse : les points tombent, la page se met à jour SEULE ────────────────
const enc = await api('POST', `/api/admin/pos/counter-collect/${id}/confirm`, C.pos_token, { mode: 1, received: Math.ceil(total) });
verifier('caisse : encaissement 200', enc.http === 200, enc.txt.slice(0, 200));
verifier(`encaissée : +${attendu} points au solde réel`, solde() === attendu, 'solde=' + solde());
// Attendre le TOTAL attendu (et non le texte « +90 », que des commandes de passages précédents affichent déjà).
const totalAttendu = Number(sql(`select coalesce(sum(loyalty_points_awarded),0) from orders where user_id=${C.client_id} and loyalty_points_awarded > 0 and status not in (16,19,22)`));
await page.waitForFunction((n) => ((document.body && document.body.innerText) || '').replace(/[\u202f\u00a0\s]/g, '').includes(String(n) + 'pointsgagnés'), totalAttendu, { timeout: 25000 }).catch(() => {});
t = await texte();
verifier(`la page affiche « +${attendu} points fidélité crédités » sans être rechargée`, new RegExp('\\+' + attendu + ' points fidélité crédités', 'i').test(t.replace(/ | /g, '')), (t.match(/.{0,40}points.{0,40}/i) || [''])[0]);
verifier('résumé : points gagnés (total réel ' + totalAttendu + '), jamais d\'euros dépensés', new RegExp(totalAttendu + ' points gagnés').test(t.replace(/ | /g, '')) && !/dépens/i.test(t), (t.match(/.{0,30}au total.{0,40}/) || [''])[0]);
await page.screenshot({ path: S + '/e2e-reel-mes-commandes.png' });

// ── 5. Le suivi de cette commande ──────────────────────────────────────────────────────────
await page.evaluate((i) => { location.hash = '#orders'; }, id);
const boutonSuivre = page.getByRole('button', { name: /Suivre/i }).first();
if (await boutonSuivre.count()) {
  await boutonSuivre.click();
  await page.waitForTimeout(7000);
  t = await texte();
  verifier('suivi : points crédités et valeur des points, aucun montant dépensé', new RegExp('\\+' + attendu + ' pts crédités').test(t) && /Votre solde/.test(t) && !/dépens/i.test(t), (t.match(/.{0,40}pts.{0,80}/) || [''])[0]);
  await page.screenshot({ path: S + '/e2e-reel-suivi.png' });
} else {
  console.log('  · (commande payée et non « en cours » côté liste : pas de bouton Suivre — normal)');
}

// ── 6. Abus côté client (vraies routes) ────────────────────────────────────────────────────
const ann = await api('POST', `/api/frontend/order/change-status/${id}`, C.client_token, { status: 16, reason: 'customer_request', branch_id: 1 });
verifier('abus : le client ne peut pas annuler une commande payée', ann.http >= 400, ann.http + ' ' + ann.txt.slice(0, 120));
verifier('abus : solde intact après la tentative', solde() === attendu, 'solde=' + solde());
const trop = await api('POST', '/api/frontend/order', C.client_token, { ...corps, loyalty_code: C.code, loyalty_redeem_points: 100000 });
const remise = Number(trop.json?.data?.discount || 0);
verifier('abus : racheter plus de points que le solde est refusé (ou ramené au solde)', trop.http >= 400 || remise <= attendu / 100 + 0.001, trop.http + ' remise=' + remise);
if (trop.json?.data?.id) await api('POST', `/api/admin/online-order/change-status/${trop.json.data.id}`, C.pos_token, { status: 19, reason: 'banc e2e' });
const forge = await api('POST', '/api/frontend/loyalty/add-points', C.client_token, { points: 5000 });
verifier('abus : un client ne peut pas se créditer des points lui-même', forge.http >= 400 || solde() <= attendu, forge.http + ' solde=' + solde());

// ── 7. Décision propriétaire « bloquer jusqu'à la remise » ─────────────────────────────────
// Une commande « payer sur place » n'est PAS concernée (le règlement est la remise) : on vient de le vérifier, ses
// points sont dépensables. La règle vise une commande PRÉPAYÉE en ligne. Mollie n'existe pas en local : la 2ᵉ commande
// est marquée payée par carte EN BASE PRIVÉE (fixture), puis acceptée par la VRAIE caisse.
const o2 = await api('POST', '/api/frontend/order', C.client_token, corps);
const id2 = o2.json?.data?.id;
sql(`update orders set payment_method=4, payment_status=5 where id=${id2}`);
const acc2 = await api('POST', `/api/admin/online-order/change-status/${id2}`, C.pos_token, { status: 4, preparation_time: 15 });
verifier('commande prépayée acceptée par la caisse : 200', acc2.http === 200, acc2.txt.slice(0, 160));
const pts2 = Number(sql(`select coalesce(loyalty_points_awarded,0) from orders where id=${id2}`));
verifier('prépayée : points crédités dès l\'acceptation (' + pts2 + ')', pts2 > 0, 'awarded=' + pts2);
const prof1 = await api('GET', '/api/profile', C.client_token, null, false);
const enAttenteAvant = Number(prof1.json?.data?.loyalty_points_pending);
verifier('profil : ses ' + pts2 + ' points sont EN ATTENTE de remise', enAttenteAvant === pts2, JSON.stringify({ pending: enAttenteAvant, pts2 }));
await page.goto(SITE + '/index.html#loyalty', { waitUntil: 'load' });
await page.waitForTimeout(4000);
t = (await texte()).replace(/\u202f|\u00a0/g, ' ');
verifier('portefeuille : « dont N pts disponibles dès la remise »', new RegExp('dont ' + pts2 + ' pts disponibles dès la remise', 'i').test(t), (t.match(/Vos points.{0,160}/i) || [''])[0]);
await page.screenshot({ path: S + '/e2e-reel-portefeuille.png' });
// Fixture (base PRIVÉE) : on porte le solde au-dessus du minimum, puis on demande un rachat COUVERT par le solde mais
// SUPÉRIEUR au dépensable : seul le blocage « avant remise » peut le refuser.
sql(`update users set loyalty_points = loyalty_points + 1000 where id=${C.client_id}`);
const soldeTotal = soldeBrut();
const demande = Math.floor(soldeTotal / 100) * 100;
console.log('  · solde', soldeTotal, 'dont en attente', enAttenteAvant, '→ dépensable', soldeTotal - enAttenteAvant, '; rachat demandé', demande);
const rachatBloque = await api('POST', '/api/frontend/loyalty/redeem', C.client_token, { code: C.code, points: demande, branch_id: 1 });
verifier('rachat qui touche aux points en attente : refusé (« remise »)', soldeTotal - enAttenteAvant < demande && rachatBloque.http >= 400 && /remise/i.test(rachatBloque.txt), rachatBloque.http + ' ' + rachatBloque.txt.slice(0, 160));
const remiseCaisse = await api('POST', `/api/admin/online-order/change-status/${id2}`, C.pos_token, { status: 13 });
verifier('caisse : commande remise (13) 200', remiseCaisse.http === 200, remiseCaisse.txt.slice(0, 200));
const prof2 = await api('GET', '/api/profile', C.client_token, null, false);
verifier('à la remise : plus rien en attente', Number(prof2.json?.data?.loyalty_points_pending) === 0, JSON.stringify(prof2.json?.data?.loyalty_points_pending));
const rachatLibre = await api('POST', '/api/frontend/loyalty/redeem', C.client_token, { code: C.code, points: demande, branch_id: 1 });
verifier('après la remise, le même rachat est accepté', rachatLibre.http === 200, rachatLibre.http + ' ' + rachatLibre.txt.slice(0, 160));


verifier('aucune requête partie vers la production', versProd.length === 0, versProd.slice(0, 3).join(' | '));
verifier('aucune erreur JavaScript', erreurs.length === 0, erreurs.join(' | '));
console.log('grand livre :', sql(`select group_concat(concat_ws(':', ifnull(order_id,'-'), type, points, balance_after) order by id separator ' ; ') from loyalty_transactions where user_id=${C.client_id}`));
await nav.close();
console.log(echecs ? `\n✗ ${echecs} échec(s)` : '\n✓ parcours RÉEL vert : points à l\'encaissement, page à jour seule, aucun montant dépensé, abus refusés');
process.exit(echecs ? 1 : 0);
