#!/usr/bin/env node
/**
 * [BORNE-BRIDGE 2026-07-03] Pont d'impression BORNE Le Cayenne — Sanei SK1-31 (WinUSB).
 * ============================================================================
 * SOURCE UNIQUE (repo) du pont déployé sur le PC borne en `C:\borne-print\bridge.js`.
 * Toute correction se fait ICI puis se recopie sur la borne (voir runbook
 * docs/runbooks/BORNE_LOCAL_BRIDGE_SETUP.md).
 *
 * POURQUOI un pont : le SK1-31 est en driver WinUSB (aucune file d'impression
 * Windows) → impossible via window.print(). La page borne (cloud HTTPS) POSTe le
 * ticket ici ; ce pont écrit l'ESC/POS directement sur l'USB (node-usb).
 *
 * CE QUE CETTE VERSION CORRIGE (plaintes owner 2026-07-03) vs l'ancien bridge.js :
 *   1. « on dirait un paragraphe, tout à la même taille » → HIÉRARCHIE :
 *        - nom de produit en GRAS + double hauteur (bodySize),
 *        - composition (lignes indentées) en taille NORMALE, compacte.
 *   2. « ticket trop court / il tombe par terre » → avance LONGUE (ESC d 30 ≈ 12 cm)
 *      + coupe PARTIELLE (GS V 1 : reste accroché au rouleau, ne tombe JAMAIS ;
 *      le client le détache à la main). L'ANCIEN faisait 3 LF + coupe ENTIÈRE.
 *   3. « le téléphone n'apparaît pas » → ligne « Tel : 03 65 67 82 91 » en en-tête.
 *
 * RÉSILIENCE : feed / coupe / téléphone sont pris du PAYLOAD serveur si présents,
 * SINON des DÉFAUTS ci-dessous → le ticket sort correct MÊME si le bundle cloud
 * n'a pas encore été redéployé (le bundle est plus lent à mettre à jour que ce pont).
 *
 * ENDPOINTS :
 *   GET  /health → 'UP'
 *   GET  /test   → imprime un ticket de démonstration
 *   POST /       → JSON {title,subtitle,order,lines[],total,footer,bodySize,titleSize,
 *                        phone,feedLines,cutPartial} → rendu + impression
 *   POST /raw    → octets ESC/POS bruts (future : rendu serveur) → écrits tels quels
 *
 * LANCER :  node bridge.js     (port 9100 ; chrome kiosque avec les flags LNA — cf. runbook)
 */

'use strict';

const http = require('http');

const VID = 0x10C5, PID = 0x0007;
const PORT = parseInt(process.env.BORNE_BRIDGE_PORT || '9100', 10);

// ── Défauts SÛRS (appliqués si le payload ne les fournit pas — vieux bundle) ──
const DEFAULT_PHONE = process.env.BORNE_PHONE || '03 65 67 82 91';
const DEFAULT_ADDRESS = process.env.BORNE_ADDRESS || ''; // adresse Le Cayenne (mise via config serveur)
// [COMPACT 2026-07-03] 8 lignes suffisent à dégager la barre de coupe : la coupe PARTIELLE
// garde le ticket accroché → plus besoin d'une longue queue (fini le grand espace blanc).
const DEFAULT_FEED_LINES = clampFeed(parseInt(process.env.BORNE_FEED_LINES || '8', 10));
const DEFAULT_CUT_PARTIAL = (process.env.BORNE_CUT_MODE || 'partial').toLowerCase() !== 'full';

function clampFeed(n) { n = Number.isFinite(n) ? Math.floor(n) : 30; return Math.max(0, Math.min(255, n)); }

// ── ESC/POS ──────────────────────────────────────────────────────────────────
const ESC = 0x1B, GS = 0x1D;
const B = (...a) => Buffer.from(a);

// Le pont envoie en 'binary' (Latin-1) et le codepage du SK1-31 est imprévisible →
// ASCII-fold (accents retirés, € → EUR) pour un ticket LISIBLE quel que soit le codepage.
// asciiFold = pour les jetons simples (titre, téléphone) : compacte les espaces + trim.
function asciiFold(s) {
  return String(s == null ? '' : s)
    .normalize('NFD').replace(/[̀-ͯ]/g, '') // diacritiques
    .replace(/€/g, 'EUR').replace(/[^\x20-\x7E]/g, ' ')
    .replace(/[ \t]{2,}/g, ' ').trim();
}

// foldKeep = pour les LIGNES du corps : conserve l'alignement (prix calé à droite,
// compo indentée « > … ») → NE compacte PAS les espaces internes, trim à DROITE seul.
function foldKeep(s) {
  return String(s == null ? '' : s)
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/€/g, 'EUR').replace(/[^\x20-\x7E]/g, ' ')
    .replace(/\s+$/, '');
}

/**
 * Construit les octets ESC/POS d'un ticket borne depuis le payload JSON.
 * Exporté pour les tests (aucune dépendance USB requise pour rendre les octets).
 */
function renderTicket(p) {
  p = p || {};
  const out = [];
  const size = (n) => out.push(B(GS, 0x21, n));   // GS ! n : taille
  const align = (n) => out.push(B(ESC, 0x61, n)); // ESC a n : 0 gauche / 1 centre
  const bold = (on) => out.push(B(ESC, 0x45, on ? 1 : 0));
  const text = (s) => out.push(Buffer.from(foldKeep(s) + '\n', 'binary')); // garde l'alignement (prix/compo)
  const rule = () => { size(0x00); text('--------------------------------'); };

  const bodySize = Number.isInteger(p.bodySize) ? p.bodySize : 0x01;  // double hauteur
  const titleSize = Number.isInteger(p.titleSize) ? p.titleSize : 0x11; // 2×2
  const phone = (p.phone != null && String(p.phone).trim() !== '') ? String(p.phone).trim() : DEFAULT_PHONE;
  const address = (p.address != null && String(p.address).trim() !== '') ? String(p.address).trim() : DEFAULT_ADDRESS;
  const feedLines = Number.isInteger(p.feedLines) ? clampFeed(p.feedLines) : DEFAULT_FEED_LINES;
  const cutPartial = (p.cutPartial === undefined || p.cutPartial === null) ? DEFAULT_CUT_PARTIAL : !!p.cutPartial;

  out.push(B(ESC, 0x40)); // ESC @ : init

  // ── En-tête ────────────────────────────────────────────────────────────────
  align(1);
  bold(1); size(titleSize); text(p.title || 'LE CAYENNE');
  size(0x00); bold(0);
  if (address) text(address);                 // adresse (design pro)
  if (phone) text('Tel : ' + phone);          // téléphone
  if (p.subtitle) text(p.subtitle);           // date
  if (p.order) { bold(1); size(titleSize); text('Commande ' + p.order); size(0x00); bold(0); }
  rule();

  // ── Corps : PRODUIT en gras (grand), COMPO compacte (normal) ────────────────
  align(0);
  for (const raw of (p.lines || [])) {
    const line = String(raw == null ? '' : raw);
    // Les lignes de composition commencent par des espaces ("  > sauce, crudités…").
    // Le nom de produit n'est pas indenté → gras + double hauteur.
    if (/^\s/.test(line)) { size(0x00); bold(0); text(line); }
    else { size(bodySize); bold(1); text(line); bold(0); }
  }
  size(0x00);
  rule();

  // ── Total ────────────────────────────────────────────────────────────────
  if (p.total) { align(0); bold(1); size(titleSize); text('TOTAL: ' + p.total); size(0x00); bold(0); }

  // ── Pied ─────────────────────────────────────────────────────────────────
  align(1);
  if (p.footer) text(p.footer);

  // ── Avance LONGUE + coupe (PARTIELLE par défaut → ne tombe pas) ─────────────
  out.push(B(ESC, 0x64, feedLines));       // ESC d n : avance n lignes avant coupe
  out.push(B(GS, 0x56, cutPartial ? 1 : 0)); // GS V m : 1 = partielle, 0 = entière

  return Buffer.concat(out);
}

// ── Impression USB (node-usb chargé PARESSEUSEMENT : rendu/tests OK sans la lib) ─
function sendBytes(buf) {
  return new Promise((resolve, reject) => {
    let usb;
    try { usb = require('usb'); }
    catch (e) { return reject(new Error('lib usb absente: ' + e.message)); }
    const d = usb.findByIds(VID, PID);
    if (!d) return reject(new Error('imprimante introuvable (USB)'));
    try { d.open(); } catch (e) { return reject(new Error('open: ' + e.message)); }
    const itf = d.interfaces[0];
    try { itf.claim(); } catch (e) { try { d.close(); } catch (_) {} return reject(new Error('claim: ' + e.message)); }
    const outEp = itf.endpoints.find((e) => e.direction === 'out');
    if (!outEp) { try { itf.release(() => d.close()); } catch (_) {} return reject(new Error('pas endpoint OUT')); }
    outEp.transfer(buf, (err) => {
      try { itf.release(true, () => { try { d.close(); } catch (_) {} }); } catch (_) { try { d.close(); } catch (__) {} }
      if (err) reject(new Error('transfer: ' + err.message)); else resolve(true);
    });
  });
}

function cors(res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Access-Control-Allow-Private-Network', 'true');
}

function startServer() {
  const server = http.createServer((req, res) => {
    cors(res);
    if (req.method === 'OPTIONS') { res.writeHead(204); return res.end(); }
    if (req.method === 'GET' && req.url.indexOf('/health') === 0) { res.writeHead(200); return res.end('UP'); }
    if (req.method === 'GET' && req.url.indexOf('/test') === 0) {
      const t = renderTicket({
        title: 'LE CAYENNE', subtitle: '03/07/2026 14:32', order: '#A0002',
        lines: ['1x Tacos M              9,40', '  > Viande: Poulet mariné', '  > Sauce: Algérienne, Cheddar', '  > Formule (frites + boisson)', '  > (Coca-Cola 33cl)'],
        total: '9,40 EUR', footer: 'Merci et bon appetit !',
      });
      return sendBytes(t).then(() => { res.writeHead(200); res.end('TEST OK'); })
        .catch((e) => { res.writeHead(500); res.end('ERR ' + e.message); });
    }
    if (req.method === 'POST' && req.url.indexOf('/raw') === 0) {
      const chunks = [];
      req.on('data', (c) => chunks.push(c));
      req.on('end', () => {
        const body = Buffer.concat(chunks);
        if (!body.length) { res.writeHead(400); return res.end('empty'); }
        sendBytes(body).then(() => { res.writeHead(200); res.end('PRINTED'); })
          .catch((e) => { res.writeHead(500); res.end('ERR ' + e.message); });
      });
      return;
    }
    if (req.method === 'POST') {
      const chunks = [];
      req.on('data', (c) => chunks.push(c));
      req.on('end', () => {
        let payload;
        try { payload = JSON.parse(Buffer.concat(chunks).toString('utf8')); }
        catch (e) { res.writeHead(400); return res.end('JSON invalide'); }
        sendBytes(renderTicket(payload)).then(() => { res.writeHead(200); res.end('PRINTED'); })
          .catch((e) => { res.writeHead(500); res.end('ERR ' + e.message); });
      });
      return;
    }
    res.writeHead(404); res.end('Pont impression borne Le Cayenne — /health /test POST / POST /raw');
  });
  server.listen(PORT, '127.0.0.1', () => {
    console.log('[borne-bridge] écoute http://127.0.0.1:' + PORT + ' → SK1-31 (VID 0x10C5/PID 0x0007)');
    console.log('[borne-bridge] défauts : feed=' + DEFAULT_FEED_LINES + ' coupe=' + (DEFAULT_CUT_PARTIAL ? 'partielle' : 'entière') + ' tel="' + DEFAULT_PHONE + '"');
  });
  return server;
}

module.exports = { renderTicket, asciiFold, clampFeed, DEFAULT_PHONE, DEFAULT_FEED_LINES, DEFAULT_CUT_PARTIAL };

if (require.main === module) startServer();
