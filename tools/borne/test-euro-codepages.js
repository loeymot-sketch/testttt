'use strict';

/**
 * [BORNE-EURO 2026-07-09] Test des pages de code ESC/POS de l'imprimante BORNE (SK1-31).
 *
 * PROBLÈME : depuis l'unification du renderer serveur (2026-07-01), la borne reçoit le vrai
 * symbole « € » en octet CP858 (0xD5). La SAGA caisse l'affiche bien, mais la SK1-31 imprime
 * « ⌐ » — son mappage de page de code diffère (bridge.js le savait déjà : « codepage
 * imprévisible » → il repliait € → EUR sur son ancien chemin JSON).
 *
 * CE SCRIPT imprime, pour une liste de pages de code candidates, une ligne montrant les DEUX
 * octets « euro » possibles (0x80 = WPC1252, 0xD5 = CP858). Regarde le ticket : repère la ligne
 * (page NN) où l'un des deux octets affiche un vrai « € ». Communique-moi « page NN, octet 80 »
 * (ou D5) et je câble RECEIPT_BORNE_CODE_PAGE en conséquence.
 *
 * PRÉ-REQUIS : le pont borne (bridge.js) doit tourner sur le PC borne (port 9100). Ce script
 * NE touche PAS l'USB directement — il POST les octets à /raw, exactement comme la borne.
 *
 * LANCER (sur le PC borne) :   node tools/borne/test-euro-codepages.js
 *   (ou avec une URL de pont custom :  node test-euro-codepages.js http://127.0.0.1:9100/raw )
 *
 * Si AUCUNE page n'affiche « € » → la SK1-31 n'honore pas ESC t : on gardera « EUR » en texte
 * (toujours lisible) sur la borne. Dis-le-moi et je bascule la borne en repli EUR.
 */

const http = require('http');

const RAW_URL = process.argv[2] || 'http://127.0.0.1:9100/raw';

const ESC = 0x1B, GS = 0x1D, LF = 0x0A;
const B = (...a) => Buffer.from(a);
const T = (s) => Buffer.from(s, 'binary'); // Latin-1 : garde les octets 0x80/0xD5 intacts

// Pages de code candidates (numéro ESC/POS → charset probable).
// 0=CP437(pas d'€), 2=CP850(pas d'€), 16=WPC1252(€=0x80), 17/18=variantes, 19=CP858(€=0xD5),
// 255=page espace utilisateur sur certains modèles.
const PAGES = [0, 2, 16, 17, 18, 19, 20, 255];

const EURO_1252 = 0x80; // € en WPC1252 / CP1252
const EURO_858  = 0xD5; // € en CP858

function pad(n, w) { let s = String(n); while (s.length < w) s = ' ' + s; return s; }

function build() {
  const out = [];
  out.push(B(ESC, 0x40));            // ESC @ : init
  out.push(B(ESC, 0x61, 1));         // centre
  out.push(B(ESC, 0x45, 1));         // gras
  out.push(T('TEST PAGES CODE EURO'), B(LF));
  out.push(T('SK1-31 borne'), B(LF));
  out.push(B(ESC, 0x45, 0));
  out.push(B(ESC, 0x61, 0));         // gauche
  out.push(T('--------------------------------'), B(LF));
  out.push(T('Cherche la ligne ou 80> ou D5>'), B(LF));
  out.push(T('affiche un vrai  E barre  (euro)'), B(LF));
  out.push(T('--------------------------------'), B(LF));

  for (const p of PAGES) {
    out.push(B(ESC, 0x74, p & 0xFF));                 // ESC t p : sélectionne la page de code
    // Ligne : "Pg NNN 80>[x]  D5>[y]"
    out.push(T('Pg ' + pad(p, 3) + '  80>'));
    out.push(B(EURO_1252));
    out.push(T('   D5>'));
    out.push(B(EURO_858));
    out.push(B(LF));
  }

  out.push(B(ESC, 0x74, 19));        // remet CP858 (défaut) avant la fin
  out.push(T('--------------------------------'), B(LF));
  out.push(T('Note la page + octet qui = euro'), B(LF));
  out.push(B(LF), B(LF), B(LF), B(LF), B(LF), B(LF));
  out.push(B(GS, 0x56, 1));          // GS V 1 : coupe partielle
  return Buffer.concat(out);
}

function main() {
  const body = build();
  const u = new URL(RAW_URL);
  const req = http.request(
    { hostname: u.hostname, port: u.port || 9100, path: u.pathname, method: 'POST',
      headers: { 'Content-Type': 'application/octet-stream', 'Content-Length': body.length } },
    (res) => {
      let d = '';
      res.on('data', (c) => (d += c));
      res.on('end', () => {
        if (res.statusCode === 200) console.log('[test-euro] imprimé OK — lis le ticket SK1-31.');
        else console.error('[test-euro] pont a répondu ' + res.statusCode + ' : ' + d);
      });
    }
  );
  req.on('error', (e) => console.error('[test-euro] pont injoignable (' + RAW_URL + ') : ' + e.message +
    '\n  → démarre bridge.js sur le PC borne, ou passe l\'URL en argument.'));
  req.write(body);
  req.end();
}

main();
