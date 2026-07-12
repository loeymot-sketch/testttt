#!/usr/bin/env node
/**
 * [KITCHEN-BRIDGE 2026-07-09] Tests du contrat HTTP du pont cuisine — ZÉRO
 * dépendance, lancés avec `node tools/kitchen-bridge/kitchen-bridge.test.js`
 * (mac/linux/windows : powershell.exe est REMPLACÉ par un faux worker via
 * monkey-patch de child_process.spawn AVANT le require du pont, donc aucun
 * matériel requis). Miroir exact de caisse-bridge.test.js.
 *
 * Contrat vérifié :
 *   1. GET  /health  → 200 "UP"
 *   2. POST /raw     → 202 {"queued":true} IMMÉDIAT (la réponse ne dépend PAS de la
 *                      durée d'impression — le faux worker répond avec 300 ms de délai
 *                      et la réponse HTTP doit arriver bien avant).
 *   3. POST /raw vide → 400.
 *   4. Le worker winspool n'est démarré (= compilé) QU'UNE SEULE fois pour N tickets.
 *   5. Le spawn du worker garde windowsHide:true + powershell.exe.
 */

const assert = require('assert');
const http = require('http');
const { EventEmitter } = require('events');
const cp = require('child_process');

// ── Faux worker PowerShell (persistant) ──────────────────────────────────────
const spawnCalls = [];
function fakeSpawn(cmd, args, opts) {
  spawnCalls.push({ cmd, args, opts });
  const child = new EventEmitter();
  child.stdout = new EventEmitter();
  child.stderr = new EventEmitter();
  child.stdin = {
    writable: true,
    write(line) {
      // Répond READY une fois, puis OK <fichier> avec 300 ms de délai (simule
      // l'impression LENTE) — la réponse HTTP 202 doit partir AVANT.
      const file = String(line).trim().split('|')[1];
      setTimeout(() => child.stdout.emit('data', 'OK ' + file + '\n'), 300);
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
  const server = bridge.createServer();
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const port = server.address().port;

  // 1. /health
  const health = await request(port, 'GET', '/health');
  assert.strictEqual(health.status, 200, '/health doit répondre 200');
  assert.match(health.body, /UP/, '/health doit répondre UP');

  // 2. /raw → 202 {"queued":true} IMMÉDIAT (impression simulée = 300 ms)
  const t0 = Date.now();
  const raw1 = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x41, 0x42]));
  const elapsed = Date.now() - t0;
  assert.strictEqual(raw1.status, 202, '/raw doit répondre 202 (queued), reçu ' + raw1.status);
  assert.deepStrictEqual(JSON.parse(raw1.body), { queued: true }, '/raw doit répondre {"queued":true}');
  assert.ok(elapsed < 200, '/raw doit répondre IMMÉDIATEMENT (avant la fin de l\'impression 300 ms) — mesuré ' + elapsed + ' ms');

  // 3. /raw vide → 400
  const empty = await request(port, 'POST', '/raw', null);
  assert.strictEqual(empty.status, 400, '/raw vide doit répondre 400');

  // 4. Une SEULE compile pour N tickets : 2e ticket, spawn toujours = 1.
  const raw2 = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x43]));
  assert.strictEqual(raw2.status, 202);
  // Laisse la file async consommer les 2 jobs (2 × 300 ms + marge).
  await new Promise((r) => setTimeout(r, 800));
  assert.strictEqual(spawnCalls.length, 1, 'le worker winspool doit être démarré/compilé UNE SEULE fois (spawns=' + spawnCalls.length + ')');
  assert.strictEqual(bridge._workerState.spawnCount, 1, 'spawnCount interne doit rester à 1');
  assert.strictEqual(bridge._workerState.pending.length, 0, 'tous les jobs doivent être consommés');

  // 5. Le spawn du worker garde windowsHide:true (anti-flash console owner).
  assert.strictEqual(spawnCalls[0].opts && spawnCalls[0].opts.windowsHide, true, 'windowsHide:true requis (flash console)');
  assert.strictEqual(spawnCalls[0].cmd, 'powershell.exe');

  await new Promise((r) => server.close(r));
  console.log('kitchen-bridge.test.js — 5/5 assertions groupes OK (202 queued immédiat, compile unique, health, 400 vide, windowsHide)');
  process.exit(0);
}

main().catch((e) => { console.error('TEST FAILED:', e.message); process.exit(1); });
