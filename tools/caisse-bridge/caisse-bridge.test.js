#!/usr/bin/env node
/**
 * [PRINT-INSTANT 2026-07-06] Tests du contrat HTTP du pont caisse — ZÉRO dépendance,
 * lancés avec `node tools/caisse-bridge/caisse-bridge.test.js` (mac/linux/windows :
 * powershell.exe est REMPLACÉ par un faux worker via monkey-patch de child_process.spawn
 * AVANT le require du pont, donc aucun matériel requis).
 *
 * Contrat vérifié :
 *   1. GET  /health  → 200 "UP"
 *   2. POST /raw     → 202 {"queued":true} IMMÉDIAT (la réponse ne dépend PAS de la
 *                      durée d'impression — le faux worker répond avec 300 ms de délai
 *                      et la réponse HTTP doit arriver bien avant).
 *   3. POST /raw vide → 400.
 *   4. Le worker winspool n'est démarré (= compilé) QU'UNE SEULE fois pour N tickets.
 *   5. Les jobs sont bien transmis au worker (ligne « imprimante|fichier »).
 */

const assert = require('assert');
const http = require('http');
const { EventEmitter } = require('events');
const cp = require('child_process');

// ── Faux worker PowerShell (persistant) ──────────────────────────────────────
const spawnCalls = [];
// [FILE-PERIMEE 2026-10-20] État simulé de l'imprimante + journal de ce que le worker reçoit.
const fake = { status: 'online', purged: 0, lines: [] };
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
      const txt = String(line).trim();
      fake.lines.push(txt);
      if (txt.startsWith('#TICK|')) {
        // Commande de contrôle : réponse immédiate « OK tick <état> <supprimés> ».
        setTimeout(() => child.stdout.emit('data', 'OK tick ' + fake.status + ' ' + fake.purged + '\n'), 1);
        return true;
      }
      const file = txt.split('|')[1];
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
const bridge = require('./caisse-bridge.js');
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

  // ── [FILE-PERIMEE 2026-10-20] Porte « imprimante hors ligne » + purge des tickets périmés ──
  const jobsRecus = () => fake.lines.filter((l) => !l.startsWith('#TICK|')).length;
  const ticks = () => fake.lines.filter((l) => l.startsWith('#TICK|'));

  // 6. Imprimante HORS LIGNE : 503 printer_offline, et RIEN n'est transmis au spouleur.
  fake.status = 'offline';
  await bridge.tickPrinter(120);
  assert.strictEqual(bridge._printerState.status, 'offline', 'le contrôle doit mémoriser « offline »');
  const avant = jobsRecus();
  const refus = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x44]));
  assert.strictEqual(refus.status, 503, 'hors ligne : /raw doit répondre 503, reçu ' + refus.status);
  assert.deepStrictEqual(JSON.parse(refus.body), { queued: false, error: 'printer_offline' });
  await new Promise((r) => setTimeout(r, 100));
  assert.strictEqual(jobsRecus(), avant, 'hors ligne : AUCUN octet ne doit atteindre le spouleur');

  // 7. Imprimante REVENUE alors que l'état mémorisé dit encore « offline » : le contrôle en
  //    direct l'emporte → le ticket passe (202), il n'est pas refusé à tort.
  fake.status = 'online';
  const revenue = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x45]));
  assert.strictEqual(revenue.status, 202, 'imprimante revenue : 202 attendu, reçu ' + revenue.status);
  await new Promise((r) => setTimeout(r, 400));
  assert.strictEqual(jobsRecus(), avant + 1, 'le ticket doit atteindre le spouleur');

  // 8. Statut INCONNU (requête en erreur côté Windows) : la porte reste OUVERTE.
  fake.status = 'unknown';
  await bridge.tickPrinter(120);
  const inconnu = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x46]));
  assert.strictEqual(inconnu.status, 202, 'statut inconnu : on imprime quand même (porte ouverte)');
  await new Promise((r) => setTimeout(r, 400));

  // 9. Purge : la commande transmet le nom d'imprimante ET l'âge maximum ; le nombre supprimé remonte.
  fake.status = 'online'; fake.purged = 3;
  const avantTicks = ticks().length;
  await bridge.tickPrinter(120);
  assert.strictEqual(bridge._printerState.purged, 3, 'le nombre de tickets supprimés doit remonter');
  const derniere = ticks()[ticks().length - 1];
  assert.strictEqual(ticks().length, avantTicks + 1);
  assert.strictEqual(derniere, '#TICK|' + bridge.PRINTER + '|120', 'commande attendue, reçu ' + derniere);
  fake.purged = 0;

  // 10. Le script PowerShell du worker ne touche QUE les jobs de CETTE imprimante, plus vieux que
  //     la limite, et jamais ceux sans date (un « null » est plus ancien que tout en PowerShell).
  const src = require('fs').readFileSync(require('path').join(__dirname, 'caisse-bridge.js'), 'utf8');
  assert.ok(/Get-PrintJob -PrinterName \$p/.test(src), 'la purge doit être bornée à l\'imprimante du pont');
  assert.ok(/\$_\.SubmittedTime -and \$_\.SubmittedTime -lt \$lim/.test(src), 'jamais de suppression d\'un job sans date');
  assert.ok(!/Get-PrintJob\s*\|/.test(src) && !/Get-PrintJob -ErrorAction/.test(src), 'jamais « tous les jobs de toutes les imprimantes »');

  await new Promise((r) => server.close(r));
  console.log('caisse-bridge.test.js — 10/10 assertions groupes OK (202 queued immédiat, compile unique, health, 400 vide, windowsHide, porte hors ligne 503, imprimante revenue, fail-open, purge périmés, périmètre PowerShell)');
  process.exit(0);
}

main().catch((e) => { console.error('TEST FAILED:', e.message); process.exit(1); });
