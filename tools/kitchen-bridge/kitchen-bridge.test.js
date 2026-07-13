#!/usr/bin/env node
/**
 * [KITCHEN-BRIDGE 2026-07-09 · timeout 2026-07-13] Tests du contrat HTTP du pont
 * cuisine — ZÉRO dépendance, lancés avec `node tools/kitchen-bridge/kitchen-bridge.test.js`
 * (mac/linux/windows : powershell.exe est REMPLACÉ par un faux worker via
 * monkey-patch de child_process.spawn AVANT le require du pont, donc aucun
 * matériel requis). Miroir exact de caisse-bridge.test.js.
 *
 * Contrat vérifié :
 *   1. GET  /health  → 200 "UP"
 *   2. POST /raw     → 200 {"ok":true} = RÉSULTAT RÉEL de l'impression (attend le vrai
 *                      verdict, borné par le timeout ; plus de 202 optimiste — sinon un
 *                      échec APRÈS acceptation serait cru imprimé → ticket perdu).
 *   3. POST /raw vide → 400.
 *   4. Le worker winspool n'est démarré (= compilé) QU'UNE SEULE fois pour N tickets.
 *   5. Le spawn du worker garde windowsHide:true + powershell.exe.
 *   6. [CLUSTER B] Worker VIVANT mais FIGÉ (accepte stdin, ne répond JAMAIS sur
 *      stdout) → /raw répond 500 {print_timeout} après le timeout borné (200 ms ici),
 *      la file FIFO CONTINUE (le job suivant est tenté), le worker figé est TUÉ puis
 *      RELANCÉ au job suivant → le pipe se décoince.
 */

// [CLUSTER B] Timeout bas pour le test (défaut prod = 15000 ms). DOIT être posé
// AVANT le require (la constante est lue à l'import du module).
process.env.KITCHEN_PRINT_TIMEOUT_MS = '200';

const assert = require('assert');
const http = require('http');
const { EventEmitter } = require('events');
const cp = require('child_process');

// ── Faux worker PowerShell (persistant) ──────────────────────────────────────
// `workerResponds` pilote le comportement : true = répond OK 40 ms après stdin ;
// false = worker VIVANT mais FIGÉ (accepte stdin, ne répond JAMAIS) → déclenche le
// timeout borné du pont.
let workerResponds = true;
let killCount = 0;
const spawnCalls = [];
function fakeSpawn(cmd, args, opts) {
  spawnCalls.push({ cmd, args, opts });
  const child = new EventEmitter();
  child.stdout = new EventEmitter();
  child.stderr = new EventEmitter();
  child.killed = false;
  // child.kill() réel → 'close' async. On simule pareil (setImmediate) pour
  // déclencher onGone (worker terminé → relance au prochain job).
  child.kill = () => {
    if (child.killed) return true;
    child.killed = true;
    killCount += 1;
    setImmediate(() => child.emit('close', null));
    return true;
  };
  child.stdin = {
    writable: true,
    write(line) {
      const file = String(line).trim().split('|')[1];
      // Répond OK avec 40 ms de délai (impression simulée) — la réponse HTTP 202
      // doit partir AVANT. En mode figé, AUCUNE réponse (le pont doit timeout).
      if (workerResponds) setTimeout(() => child.stdout.emit('data', 'OK ' + file + '\n'), 40);
      return true;
    },
  };
  setTimeout(() => child.stdout.emit('data', 'READY\n'), 5);
  return child;
}
const realSpawn = cp.spawn;
cp.spawn = fakeSpawn;

// windowsHide DOIT rester (flash de console owner) — vérifié sur l'appel réel.
const bridge = require('./kitchen-bridge.js');
cp.spawn = realSpawn; // le pont a capturé la référence au moment du require

function request(port, method, path, body) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port, method, path }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, body: Buffer.concat(chunks).toString() }));
    });
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

async function main() {
  // Le pont doit avoir lu notre timeout bas.
  assert.strictEqual(bridge.PRINT_TIMEOUT_MS, 200, 'PRINT_TIMEOUT_MS doit refléter l\'env (200 ms)');

  const server = bridge.createServer();
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const port = server.address().port;

  // 1. /health
  const health = await request(port, 'GET', '/health');
  assert.strictEqual(health.status, 200, '/health doit répondre 200');
  assert.match(health.body, /UP/, '/health doit répondre UP');

  // 2. /raw → 200 {"ok":true} = RÉSULTAT RÉEL (attend l'impression simulée ~40 ms, < timeout 200 ms)
  const t0 = Date.now();
  const raw1 = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x41, 0x42]));
  const elapsed = Date.now() - t0;
  assert.strictEqual(raw1.status, 200, '/raw doit répondre 200 (imprimé), reçu ' + raw1.status);
  assert.deepStrictEqual(JSON.parse(raw1.body), { ok: true }, '/raw doit répondre {"ok":true}');
  assert.ok(elapsed < 200, '/raw doit répondre le vrai verdict AVANT le timeout — mesuré ' + elapsed + ' ms');

  // 3. /raw vide → 400
  const empty = await request(port, 'POST', '/raw', null);
  assert.strictEqual(empty.status, 400, '/raw vide doit répondre 400');

  // 4. Une SEULE compile pour N tickets : 2e ticket, spawn toujours = 1.
  const raw2 = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x43]));
  assert.strictEqual(raw2.status, 200, '2e ticket doit répondre 200 (imprimé)');
  // Laisse la file async consommer les jobs (40 ms chacun + marge).
  await new Promise((r) => setTimeout(r, 300));
  assert.strictEqual(spawnCalls.length, 1, 'le worker winspool doit être démarré/compilé UNE SEULE fois (spawns=' + spawnCalls.length + ')');
  assert.strictEqual(bridge._workerState.spawnCount, 1, 'spawnCount interne doit rester à 1');
  assert.strictEqual(bridge._workerState.pending.length, 0, 'tous les jobs doivent être consommés');

  // 5. Le spawn du worker garde windowsHide:true (anti-flash console owner).
  assert.strictEqual(spawnCalls[0].opts && spawnCalls[0].opts.windowsHide, true, 'windowsHide:true requis (flash console)');
  assert.strictEqual(spawnCalls[0].cmd, 'powershell.exe');

  // 6. [CLUSTER B] Worker VIVANT mais FIGÉ → timeout borné, file continue, worker tué+relancé.
  const spawnsBefore = spawnCalls.length;
  const killsBefore = killCount;
  workerResponds = false; // le worker actuel devient FIGÉ (accepte stdin, ne répond jamais)

  // job A : /raw avec worker FIGÉ → ATTEND le timeout (200 ms) puis répond 500 {print_timeout}
  // (le KDS le mettra en RETRY auto → aucun ticket perdu, contrairement à un 202 optimiste).
  const rawFrozen = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x50]));
  assert.strictEqual(rawFrozen.status, 500, '/raw doit répondre 500 (échec réel) après timeout worker figé, reçu ' + rawFrozen.status);
  assert.match(rawFrozen.body, /print_timeout/, '/raw figé doit indiquer print_timeout dans le corps');

  // Le worker figé a été TUÉ au timeout ; laisse le 'close' async (kill) se propager → child=null.
  await new Promise((r) => setTimeout(r, 60));
  assert.strictEqual(killCount, killsBefore + 1, 'le worker figé doit être TUÉ au timeout (kills=' + killCount + ')');
  assert.strictEqual(bridge._workerState.pending.length, 0, 'le job timeout doit être retiré de pending');
  assert.strictEqual(bridge._workerState.child, null, 'le worker tué doit être libéré (child=null → relance paresseuse)');

  // job B APRÈS timeout : prouve que la FILE CONTINUE. On re-permet la réponse ; un
  // NOUVEAU worker doit être spawn (relance) et le job aboutir (200 imprimé, pipe décoincé).
  workerResponds = true;
  const rawAfter = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x51]));
  assert.strictEqual(rawAfter.status, 200, 'la file doit accepter ET imprimer le job suivant après un timeout');
  assert.ok(spawnCalls.length > spawnsBefore, 'le worker doit être RELANCÉ après le kill (spawns ' + spawnsBefore + '→' + spawnCalls.length + ')');
  assert.strictEqual(bridge._workerState.pending.length, 0, 'la file doit avoir consommé le job post-timeout (pipe décoincé)');

  await new Promise((r) => server.close(r));
  console.log('kitchen-bridge.test.js — 6/6 groupes OK (200 résultat réel, compile unique, health, 400 vide, windowsHide, TIMEOUT worker-figé → 500+kill+relance+file continue)');
  process.exit(0);
}

main().catch((e) => { console.error('TEST FAILED:', e.message); process.exit(1); });
