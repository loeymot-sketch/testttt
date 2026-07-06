#!/usr/bin/env node
/**
 * [CAISSE-BRIDGE 2026-06-28] Pont local d'impression SILENCIEUSE pour la caisse.
 *
 * POURQUOI : Laravel tourne sur le cloud OVH → il NE PEUT PAS sortir sur l'USB du
 * SAGA branché au PC caisse. Donc le navigateur de la caisse récupère les octets
 * ESC/POS rendus par le serveur (SSOT NF525, clean client + cuisine symbolique) et
 * les POSTe ICI ; ce pont les écrit TELS QUELS sur l'imprimante (winspool RAW).
 * Résultat : ticket papier == rendu serveur, sans fenêtre Chrome, sans charabia,
 * sans en-tête d'URL. C'est CE pont qui fait que l'impression devient correcte.
 *
 * [PRINT-INSTANT 2026-07-06] Deux causes de la latence ~20 s owner corrigées ICI :
 *   1. /raw répond désormais 202 {"queued":true} DÈS réception des octets — le
 *      navigateur rend la main instantanément, l'impression part en asynchrone
 *      (file FIFO interne, l'ordre des tickets est préservé).
 *   2. Le helper C# winspool n'est plus compilé À CHAQUE ticket (Add-Type = 3-9 s
 *      sur un PC faible) : un worker PowerShell PERSISTANT est démarré au boot,
 *      compile UNE SEULE fois, puis lit les jobs ligne à ligne sur stdin
 *      (« imprimante|fichier.bin ») et répond OK/ERR sur stdout. S'il meurt, il
 *      est relancé automatiquement (et re-compile une seule fois).
 *
 * ZÉRO dépendance npm : http + child_process intégrés. Tourne sur le PC Windows
 * de la caisse, à côté de Chrome.
 *
 * LANCER (PowerShell/CMD sur le PC caisse) :
 *     node caisse-bridge.js "NOM_EXACT_IMPRIMANTE"
 *   (le nom = Panneau de config > Périphériques et imprimantes, ex "SAGA")
 *   ou via variable : set CAISSE_PRINTER=SAGA && node caisse-bridge.js
 *
 * Puis lancer Chrome de la caisse avec le flag (page HTTPS → 127.0.0.1) :
 *     --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks
 *
 * Le front teste GET /health avant chaque impression (mémoïsé 20 s côté front) ;
 * si ce pont tourne, il imprime en RAW ; sinon il montre une erreur claire
 * (window.print n'est plus JAMAIS automatique côté caisse).
 */

const http = require('http');
const os = require('os');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const PORT = parseInt(process.env.CAISSE_BRIDGE_PORT || '9100', 10);
const PRINTER = process.argv[2] || process.env.CAISSE_PRINTER || 'SAGA';

// Win32 RawPrinterHelper (Microsoft KB 322090) — envoie les octets RAW à
// l'imprimante nommée via le spouleur, SANS rendu pilote (donc accents/€ et coupe
// fidèles aux octets serveur). Identique à WindowsRawPrinterTransport.php.
const CSHARP = [
  'using System; using System.IO; using System.Runtime.InteropServices;',
  'public class FKRaw {',
  ' [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Ansi)] public class DI { [MarshalAs(UnmanagedType.LPStr)] public string pDocName; [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile; [MarshalAs(UnmanagedType.LPStr)] public string pDataType; }',
  ' [DllImport("winspool.Drv", EntryPoint="OpenPrinterA", SetLastError=true, CharSet=CharSet.Ansi)] static extern bool OpenPrinter(string s, out IntPtr h, IntPtr p);',
  ' [DllImport("winspool.Drv", EntryPoint="ClosePrinter")] static extern bool ClosePrinter(IntPtr h);',
  ' [DllImport("winspool.Drv", EntryPoint="StartDocPrinterA", SetLastError=true, CharSet=CharSet.Ansi)] static extern bool StartDocPrinter(IntPtr h, int l, [In, MarshalAs(UnmanagedType.LPStruct)] DI di);',
  ' [DllImport("winspool.Drv", EntryPoint="EndDocPrinter")] static extern bool EndDocPrinter(IntPtr h);',
  ' [DllImport("winspool.Drv", EntryPoint="StartPagePrinter")] static extern bool StartPagePrinter(IntPtr h);',
  ' [DllImport("winspool.Drv", EntryPoint="EndPagePrinter")] static extern bool EndPagePrinter(IntPtr h);',
  ' [DllImport("winspool.Drv", EntryPoint="WritePrinter")] static extern bool WritePrinter(IntPtr h, IntPtr buf, int n, out int written);',
  ' public static bool Send(string printer, byte[] data){ IntPtr h; var di=new DI(); di.pDocName="FoodKing Ticket"; di.pDataType="RAW"; if(!OpenPrinter(printer, out h, IntPtr.Zero)) return false; bool ok=false; if(StartDocPrinter(h,1,di)){ if(StartPagePrinter(h)){ IntPtr p=Marshal.AllocCoTaskMem(data.Length); Marshal.Copy(data,0,p,data.Length); int w; ok=WritePrinter(h,p,data.Length,out w); Marshal.FreeCoTaskMem(p); EndPagePrinter(h);} EndDocPrinter(h);} ClosePrinter(h); return ok; }',
  '}',
].join('\n');

// [PRINT-INSTANT 2026-07-06] Worker PowerShell PERSISTANT : Add-Type UNE fois au
// boot, puis boucle stdin (« imprimante|chemin.bin » par ligne) → stdout
// (« OK <chemin> » | « ERR <raison> <chemin> »). Le tube stdin/stdout évite tout
// re-spawn (= re-compile) par ticket.
const WORKER_PS = [
  "$ErrorActionPreference='Stop'",
  'Add-Type -TypeDefinition @"',
  CSHARP,
  '"@',
  '[Console]::Out.WriteLine("READY")',
  'while($true){',
  '  $line=[Console]::In.ReadLine()',
  '  if($null -eq $line){break}',
  '  if($line -eq ""){continue}',
  '  $parts=$line.Split("|",2)',
  '  try{',
  '    $bytes=[System.IO.File]::ReadAllBytes($parts[1])',
  '    if([FKRaw]::Send($parts[0], $bytes)){[Console]::Out.WriteLine("OK "+$parts[1])}',
  '    else{[Console]::Out.WriteLine("ERR winspool_send_failed "+$parts[1])}',
  '  }catch{[Console]::Out.WriteLine("ERR "+($_.Exception.Message -replace "\\s+"," ")+" "+$parts[1])}',
  '}',
].join('\n');

// État du worker + compteur de spawns (observabilité/test : DOIT rester à 1 tant
// que le worker vit — « une seule compile »).
const workerState = {
  child: null,
  ready: false,
  spawnCount: 0,
  pending: [], // jobs { tmp, resolve } dans l'ordre d'envoi (le worker répond FIFO)
};

function startWorker() {
  if (workerState.child) return workerState.child;
  workerState.spawnCount += 1;
  const b64 = Buffer.from(WORKER_PS, 'utf16le').toString('base64');
  // [FLASH-FIX 2026-07-03] windowsHide:true → PAS de fenêtre console qui « flashe »
  // (owner : « un Terminal s'ouvre d'un coup, un flash »). Conservé sur le worker.
  let child;
  try {
    child = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', b64], { windowsHide: true });
  } catch (e) {
    console.error('[caisse-bridge] worker spawn threw:', e.message);
    return null;
  }
  workerState.child = child;
  workerState.ready = false;

  let buf = '';
  child.stdout.on('data', (d) => {
    buf += d.toString();
    let idx;
    while ((idx = buf.indexOf('\n')) !== -1) {
      const line = buf.slice(0, idx).replace(/\r$/, '').trim();
      buf = buf.slice(idx + 1);
      if (!line) continue;
      if (line === 'READY') {
        workerState.ready = true;
        console.log('[caisse-bridge] worker winspool prêt (compile unique au boot)');
        continue;
      }
      const job = workerState.pending.shift();
      if (job) {
        try { fs.unlinkSync(job.tmp); } catch (_) {}
        if (/^OK /.test(line)) job.resolve({ ok: true });
        else job.resolve({ ok: false, error: line });
      }
    }
  });
  child.stderr.on('data', (d) => { console.error('[caisse-bridge] worker stderr:', d.toString().trim()); });
  const onGone = (why) => {
    if (workerState.child !== child) return;
    console.error('[caisse-bridge] worker terminé (' + why + ') — relance au prochain job');
    workerState.child = null;
    workerState.ready = false;
    // Les jobs en vol échouent proprement (le front toast une erreur claire).
    const orphans = workerState.pending.splice(0);
    orphans.forEach((job) => {
      try { fs.unlinkSync(job.tmp); } catch (_) {}
      job.resolve({ ok: false, error: 'worker_died: ' + why });
    });
  };
  child.on('error', (e) => onGone('spawn_failed: ' + e.message));
  child.on('close', (code) => onGone('exit_' + code));
  return child;
}

/**
 * Envoie un job au worker persistant. Écrit les octets dans un fichier temporaire
 * (le tube stdin est réservé au protocole ligne) puis « imprimante|chemin ».
 */
function printRaw(buffer) {
  return new Promise((resolve) => {
    const tmp = path.join(os.tmpdir(), 'fk_caisse_' + Date.now() + '_' + Math.floor(Math.random() * 1e9) + '.bin');
    try {
      fs.writeFileSync(tmp, buffer);
    } catch (e) {
      return resolve({ ok: false, error: 'tempfile_write_failed: ' + e.message });
    }
    const child = workerState.child || startWorker();
    if (!child || !child.stdin || !child.stdin.writable) {
      try { fs.unlinkSync(tmp); } catch (_) {}
      return resolve({ ok: false, error: 'worker_unavailable' });
    }
    workerState.pending.push({ tmp, resolve });
    try {
      child.stdin.write(String(PRINTER).replace(/[|\r\n]/g, ' ') + '|' + tmp + '\n');
    } catch (e) {
      const i = workerState.pending.findIndex((j) => j.tmp === tmp);
      if (i !== -1) workerState.pending.splice(i, 1);
      try { fs.unlinkSync(tmp); } catch (_) {}
      resolve({ ok: false, error: 'worker_write_failed: ' + e.message });
    }
  });
}

// File FIFO d'impression : les jobs /raw sont sérialisés pour préserver l'ordre
// papier (client puis cuisine…), mais la RÉPONSE HTTP 202 part immédiatement.
let printChain = Promise.resolve();
function enqueuePrint(buffer) {
  printChain = printChain
    .then(() => printRaw(buffer))
    .then((r) => {
      if (r && r.ok) console.log('[caisse-bridge] imprimé (' + buffer.length + ' octets)');
      else console.error('[caisse-bridge] print failed:', (r && r.error) || 'unknown');
      return r;
    })
    .catch((e) => { console.error('[caisse-bridge] print failed:', e.message); return { ok: false, error: e.message }; });
  return printChain;
}

function cors(res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Access-Control-Allow-Private-Network', 'true');
}

function createServer() {
  return http.createServer((req, res) => {
    cors(res);
    if (req.method === 'OPTIONS') { res.writeHead(204); return res.end(); }

    if (req.method === 'GET' && req.url.startsWith('/health')) {
      res.writeHead(200, { 'Content-Type': 'text/plain' });
      return res.end('UP');
    }

    if (req.method === 'POST' && req.url.startsWith('/raw')) {
      const chunks = [];
      req.on('data', (c) => chunks.push(c));
      req.on('end', () => {
        const body = Buffer.concat(chunks);
        if (!body.length) { res.writeHead(400); return res.end('empty'); }
        // [PRINT-INSTANT 2026-07-06] 202 IMMÉDIAT : le navigateur ne poireaute plus
        // pendant l'impression (l'ancienne réponse post-impression ajoutait 3-10 s
        // et déclenchait des aborts « faux échec » alors que le papier sortait).
        enqueuePrint(body);
        res.writeHead(202, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ queued: true }));
      });
      return;
    }

    res.writeHead(404); res.end('not found');
  });
}

if (require.main === module) {
  // Compile winspool UNE SEULE fois, au boot (pas au 1er ticket).
  startWorker();
  const server = createServer();
  server.listen(PORT, '127.0.0.1', () => {
    console.log('[caisse-bridge] écoute http://127.0.0.1:' + PORT + ' → imprimante "' + PRINTER + '"');
    console.log('[caisse-bridge] GET /health  POST /raw (octets ESC/POS bruts, réponse 202 immédiate)');
  });
}

// Export test-only (contrat HTTP + worker unique) — inerte quand lancé en direct.
module.exports = { createServer, startWorker, printRaw, enqueuePrint, _workerState: workerState, PRINTER };
