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
let writeCount = 0;
const spawnCalls = [];
// [FILE-PERIMEE 2026-10-20] État simulé de l'imprimante + journal des lignes reçues par le worker.
const fake = { status: 'online', purged: 0, tickResponds: true, lines: [] };
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
      writeCount += 1;
      const txt = String(line).trim();
      fake.lines.push(txt);
      if (txt.startsWith('#TICK|')) {
        // Commande de contrôle : « OK tick <état> <supprimés> » (ou silence si worker figé).
        if (fake.tickResponds) setTimeout(() => child.stdout.emit('data', 'OK tick ' + fake.status + ' ' + fake.purged + '\n'), 1);
        return true;
      }
      const file = txt.split('|')[1];
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

  // 7. [KITCHEN-RESILIENCE] Anti-doublon : un job dont le client a ABANDONNÉ (abortState.aborted)
  // AVANT son tour d'impression est SAUTÉ (jamais envoyé au worker) → seul le retry imprimera.
  await new Promise((r) => setTimeout(r, 50)); // file au repos
  const writesBefore = writeCount;
  const abortedResult = await bridge.enqueuePrint(Buffer.from([0x1b, 0x40, 0x60]), { aborted: true });
  assert.strictEqual(abortedResult.ok, false, 'un job abandonné doit résoudre {ok:false}');
  assert.match(String(abortedResult.error || ''), /client_aborted/, 'un job abandonné doit indiquer client_aborted');
  assert.strictEqual(writeCount, writesBefore, 'un job abandonné ne doit PAS être envoyé au worker (anti-doublon head-of-line)');

  // ── [FILE-PERIMEE 2026-10-20] Porte « imprimante hors ligne » + purge des tickets périmés ──
  const jobsRecus = () => fake.lines.filter((l) => !l.startsWith('#TICK|')).length;
  const ticks = () => fake.lines.filter((l) => l.startsWith('#TICK|'));

  // 8. HORS LIGNE : 503 {ok:false, printer_offline} (le KDS réessaiera) et RIEN pour le spouleur.
  fake.status = 'offline';
  await bridge.tickPrinter(900);
  assert.strictEqual(bridge._printerState.status, 'offline', 'le contrôle doit mémoriser « offline »');
  const avant = jobsRecus();
  const refus = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x70]));
  assert.strictEqual(refus.status, 503, 'hors ligne : /raw doit répondre 503, reçu ' + refus.status);
  assert.deepStrictEqual(JSON.parse(refus.body), { ok: false, error: 'printer_offline' });
  await new Promise((r) => setTimeout(r, 100));
  assert.strictEqual(jobsRecus(), avant, 'hors ligne : AUCUN octet ne doit atteindre le spouleur');

  // 9. Imprimante REVENUE alors que l'état mémorisé dit « offline » : le contrôle en direct l'emporte.
  fake.status = 'online';
  const revenue = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x71]));
  assert.strictEqual(revenue.status, 200, 'imprimante revenue : 200 (imprimé) attendu, reçu ' + revenue.status);
  assert.strictEqual(jobsRecus(), avant + 1, 'le ticket doit atteindre le spouleur');

  // 10. Statut INCONNU : la porte reste OUVERTE.
  fake.status = 'unknown';
  await bridge.tickPrinter(900);
  const inconnu = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x72]));
  assert.strictEqual(inconnu.status, 200, 'statut inconnu : on imprime quand même (porte ouverte)');

  // 11. Purge : nom d'imprimante + âge max transmis ; nombre supprimé remonté.
  fake.status = 'online'; fake.purged = 4;
  const avantTicks = ticks().length;
  await bridge.tickPrinter(900);
  assert.strictEqual(bridge._printerState.purged, 4, 'le nombre de tickets supprimés doit remonter');
  assert.strictEqual(ticks().length, avantTicks + 1);
  assert.strictEqual(ticks()[ticks().length - 1], '#TICK|' + bridge.PRINTER + '|900', 'commande attendue');
  fake.purged = 0;

  // 12. Worker FIGÉ (ne répond plus à RIEN, ni contrôles ni tickets) alors que l'état mémorisé dit
  //     « offline » : la porte ne suspend pas la réponse HTTP, elle laisse passer après ~3 s ; le ticket
  //     part alors dans le circuit normal, qui échoue proprement (500 print_timeout → le KDS réessaiera)
  //     et tue le worker figé. Jamais un blocage, jamais un faux « imprimé ».
  fake.status = 'offline';
  await bridge.tickPrinter(900);            // état mémorisé « offline »
  fake.tickResponds = false; workerResponds = false;
  const t1 = Date.now();
  const fige = await request(port, 'POST', '/raw', Buffer.from([0x1b, 0x40, 0x73]));
  const attente = Date.now() - t1;
  assert.strictEqual(fige.status, 500, 'worker figé : 500 print_timeout attendu (pas 503, pas 200), reçu ' + fige.status);
  assert.match(fige.body, /print_timeout/, 'worker figé : print_timeout attendu');
  assert.ok(attente >= 2500 && attente < 5000, 'la porte doit attendre ~3 s puis laisser passer — mesuré ' + attente + ' ms');
  await new Promise((r) => setTimeout(r, 60));
  assert.strictEqual(bridge._workerState.pending.length, 0, 'plus aucune commande orpheline après la mort du worker');
  fake.tickResponds = true; workerResponds = true; fake.status = 'online';

  // 13. Périmètre PowerShell : seulement cette imprimante, seulement les jobs datés et périmés.
  const src = require('fs').readFileSync(require('path').join(__dirname, 'kitchen-bridge.js'), 'utf8');
  assert.ok(/Get-PrintJob -PrinterName \$p/.test(src), 'la purge doit être bornée à l\'imprimante du pont');
  assert.ok(/\$_\.SubmittedTime -and \$_\.SubmittedTime -lt \$lim/.test(src), 'jamais de suppression d\'un job sans date');
  assert.ok(!/Get-PrintJob\s*\|/.test(src) && !/Get-PrintJob -ErrorAction/.test(src), 'jamais « tous les jobs de toutes les imprimantes »');

  await new Promise((r) => server.close(r));
  console.log('kitchen-bridge.test.js — 13/13 groupes OK (200 résultat réel, compile unique, health, 400 vide, windowsHide, TIMEOUT worker-figé → 500+kill+relance, anti-doublon client-aborted sauté, porte hors ligne 503, imprimante revenue, fail-open, purge périmés, contrôle figé, périmètre PowerShell)');
  process.exit(0);
}

main().catch((e) => { console.error('TEST FAILED:', e.message); process.exit(1); });
