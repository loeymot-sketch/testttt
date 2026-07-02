#!/usr/bin/env node
/**
 * [BORNE-BRIDGE TEST 2026-07-03] Prouve, sur les OCTETS ESC/POS rendus, que le ticket
 * borne corrige les 3 plaintes owner : produit GRAS + compo compacte (hiérarchie),
 * feed LONG (≥30) + coupe PARTIELLE (ne tombe pas), téléphone présent, 0 ligne > 32 col.
 * Aucune dépendance USB (renderTicket est pur). Lancer : node tools/borne/bridge.test.js
 */
'use strict';
const assert = require('assert');
const { renderTicket, DEFAULT_PHONE } = require('./bridge.js');

const ESC = 0x1B, GS = 0x1D;
const has = (buf, seq) => buf.indexOf(Buffer.from(seq)) !== -1;

/** Extrait les lignes de TEXTE imprimé (runs ASCII imprimables séparés par LF), sans les octets de contrôle. */
function printedLines(buf) {
  const lines = [];
  let cur = '';
  for (let i = 0; i < buf.length; i++) {
    const b = buf[i];
    if (b === 0x0A) { lines.push(cur); cur = ''; }             // LF → fin de ligne
    else if (b === ESC || b === GS) { i += (b === GS && buf[i + 1] === 0x21) ? 2 : (buf[i + 1] === 0x64 || buf[i + 1] === 0x56 || buf[i + 1] === 0x61 || buf[i + 1] === 0x45 || buf[i + 1] === 0x21) ? 2 : 1; }
    else if (b >= 0x20 && b <= 0x7E) { cur += String.fromCharCode(b); }
  }
  if (cur) lines.push(cur);
  return lines;
}

let failures = 0;
function check(name, fn) {
  try { fn(); console.log('  ✓ ' + name); }
  catch (e) { failures++; console.log('  ✗ ' + name + ' → ' + e.message); }
}

// ── CAS A : payload complet (téléphone envoyé par le serveur, feed/cut du serveur) ──
console.log('CAS A — payload serveur complet :');
const a = renderTicket({
  title: 'LE CAYENNE', subtitle: '03/07/2026 14:32', order: '#A0002',
  lines: [
    '1x Tacos M              9,40',
    '  > Viande: Poulet marine',
    '  > Sauce: Algerienne, Cheddar',
    '  > Formule (frites + boisson)',
  ],
  total: '9,40 EUR', footer: 'Merci et bon appetit !',
  bodySize: 0x01, titleSize: 0x11,
  phone: '03 65 67 82 91', feedLines: 30, cutPartial: true,
});
check('téléphone imprimé en en-tête', () => assert.ok(has(a, Buffer.from('Tel : 03 65 67 82 91', 'binary'))));
check('avance papier LONGUE ESC d 30 (0x1B 0x64 0x1E)', () => assert.ok(has(a, [ESC, 0x64, 30])));
check('coupe PARTIELLE GS V 1 (0x1D 0x56 0x01)', () => assert.ok(has(a, [GS, 0x56, 0x01])));
check('coupe entière ABSENTE (pas de GS V 0 final)', () => assert.ok(!has(a, [GS, 0x56, 0x00])));
check('nom de produit en GRAS (ESC E 1 présent)', () => assert.ok(has(a, [ESC, 0x45, 0x01])));
check('compo rendue en taille NORMALE (GS ! 0 présent)', () => assert.ok(has(a, [GS, 0x21, 0x00])));
check('0 ligne de texte > 32 colonnes', () => {
  const over = printedLines(a).filter((l) => l.length > 32);
  assert.strictEqual(over.length, 0, 'lignes trop larges: ' + JSON.stringify(over));
});
check('ordre : GRAS(ESC E 1) AVANT le nom produit "1x Tacos"', () => {
  const boldAt = a.indexOf(Buffer.from([ESC, 0x45, 0x01]));
  const prodAt = a.indexOf(Buffer.from('1x Tacos', 'binary'));
  assert.ok(boldAt !== -1 && prodAt !== -1 && boldAt < prodAt, 'gras doit précéder le produit');
});

// ── CAS B : payload MINIMAL (vieux bundle cloud : ni phone, ni feed, ni cut) ──
// Prouve la RÉSILIENCE : le pont applique ses défauts → ticket correct SANS redéploiement.
console.log('CAS B — vieux bundle (payload minimal, défauts pont) :');
const b = renderTicket({
  title: 'LE CAYENNE', order: '#A0003',
  lines: ['1x Cheese Burger        6,00', '  > Salade, Tomate, Oignon'],
  total: '6,00 EUR',
});
check('téléphone par DÉFAUT présent (03 65 67 82 91)', () => assert.ok(has(b, Buffer.from('Tel : ' + DEFAULT_PHONE, 'binary'))));
check('avance LONGUE par défaut (ESC d 30)', () => assert.ok(has(b, [ESC, 0x64, 30])));
check('coupe PARTIELLE par défaut (GS V 1)', () => assert.ok(has(b, [GS, 0x56, 0x01])));
check('0 ligne > 32 colonnes', () => {
  const over = printedLines(b).filter((l) => l.length > 32);
  assert.strictEqual(over.length, 0, JSON.stringify(over));
});

console.log('');
if (failures) { console.log('❌ ' + failures + ' assertion(s) échouée(s)'); process.exit(1); }
console.log('✅ TICKET BORNE — toutes les assertions octets passent (produit gras + compo compacte + feed 30 + coupe partielle + téléphone).');
