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
 * [FILE-PERIMEE 2026-10-20 · owner « il imprime tous les anciens tickets »] Imprimante
 * débranchée/éteinte → le spouleur Windows ACCEPTE quand même les octets (la file existe
 * toujours) et les rejoue TOUS au retour de l'imprimante : des dizaines de vieux tickets
 * d'un coup. Trois garde-fous, tous dans le worker PowerShell (aucun process de plus) :
 *   1. PORTE : si Windows déclare l'imprimante HORS LIGNE, POST /raw répond 503
 *      {"queued":false,"error":"printer_offline"} et RIEN n'entre dans le spouleur (le front
 *      affiche l'échec : le caissier sait que le ticket n'est pas parti). Au moindre doute
 *      (statut inconnu, requête en erreur) la porte reste OUVERTE : on n'empêche jamais
 *      d'imprimer sur une imprimante qui marche.
 *   2. PÉREMPTION : toutes les 5 s, les jobs du spouleur de CETTE imprimante plus vieux que
 *      CAISSE_STALE_JOB_SECONDS (défaut 120 s) sont SUPPRIMÉS. Un ticket de caisse qui
 *      n'est pas sorti en 2 minutes n'a plus d'intérêt ; il ne doit pas ressortir 1 h plus tard.
 *   3. DÉMARRAGE : au lancement du pont, les jobs de plus de 30 s déjà dans le spouleur sont
 *      supprimés (reliquat d'une panne précédente).
 *   CAISSE_OFFLINE_GATE=0 désactive la porte (1) ; CAISSE_STALE_JOB_SECONDS=0 désactive (2)(3).
 *
 * ZÉRO dépendance npm : http + child_process intégrés. Tourne sur le PC Windows
 * de la caisse, à côté de Chrome.
 *
 * LANCER (PowerShell/CMD sur le PC caisse) :
 *     node caisse-bridge.js "NOM_EXACT_IMPRIMANTE"
 *   (le nom = Panneau de config > Périphériques et imprimantes, ex "Epson TM-m30II")
 *   ou via variable : set CAISSE_PRINTER=Epson TM-m30II && node caisse-bridge.js
 *
 * [CHANGEMENT-IMPRIMANTE 2026-08-09] SAGA → Epson TM-m30II. Le nom ci-dessous n'est
 * qu'un DÉFAUT de repli : le lanceur du PC caisse (C:\lecayenne\start-caisse.ps1 et
 * watchdog.ps1) passe le nom en ARGUMENT, ce qui écrase ce défaut. Changer l'imprimante
 * du restaurant impose donc de changer AUSSI ces deux scripts — sinon les tickets
 * partent dans la file de l'ancienne imprimante SANS erreur visible (le spouleur
 * Windows accepte les octets tant que la file existe encore). Le nom exact se lit avec
 * `Get-Printer | Select-Object Name` : il doit correspondre AU CARACTÈRE PRÈS.
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
const PRINTER = process.argv[2] || process.env.CAISSE_PRINTER || 'Epson TM-m30II';
const OFFLINE_GATE = process.env.CAISSE_OFFLINE_GATE !== '0';
const STALE_JOB_SECONDS = Math.max(0, parseInt(process.env.CAISSE_STALE_JOB_SECONDS || '120', 10) || 0);
const BOOT_STALE_SECONDS = Math.min(30, STALE_JOB_SECONDS) || (STALE_JOB_SECONDS > 0 ? 30 : 0);
const TICK_MS = 5000;
// Au-delà de cette ancienneté, un statut « hors ligne » mémorisé n'est plus cru (porte ouverte).
const STATE_FRESH_MS = 30000;

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
  // [FILE-PERIMEE 2026-10-20] « #TICK|imprimante|ageMaxSecondes » : renvoie l'état de l'imprimante
  // (online|offline|unknown) ET supprime du spouleur les jobs plus vieux que ageMax
  // (0 = ne rien supprimer). Réponse : « OK tick <état> <nb supprimés> ». Jamais d'exception
  // vers l'appelant : toute erreur de lecture → « unknown » (la porte reste ouverte).
  'function FKTick($p,$maxAge){',
  '  $state="unknown"',
  '  try{',
  "    $e=$p.Replace('\\','\\\\').Replace(\"'\",\"\\'\")",
  "    $q=Get-CimInstance Win32_Printer -Filter (\"Name='\"+$e+\"'\") -ErrorAction Stop",
  '    if($q){ if($q.WorkOffline -or $q.PrinterStatus -eq 7 -or $q.ExtendedPrinterStatus -eq 7 -or $q.DetectedErrorState -eq 9){$state="offline"}else{$state="online"} }',
  '  }catch{}',
  '  $n=0',
  '  if($maxAge -gt 0){',
  '    try{',
  '      $lim=(Get-Date).AddSeconds(-$maxAge)',
  '      Get-PrintJob -PrinterName $p -ErrorAction Stop | Where-Object { $_.SubmittedTime -and $_.SubmittedTime -lt $lim } | ForEach-Object { try{ Remove-PrintJob -InputObject $_ -ErrorAction Stop; $n++ }catch{} }',
  '    }catch{}',
  '  }',
  '  return "OK tick $state $n"',
  '}',
  '[Console]::Out.WriteLine("READY")',
  'while($true){',
  '  $line=[Console]::In.ReadLine()',
  '  if($null -eq $line){break}',
  '  if($line -eq ""){continue}',
  '  if($line.StartsWith("#TICK|")){',
  '    $a=$line.Split("|")',
  '    $ma=0; [void][int]::TryParse($a[2],[ref]$ma)',
  '    try{[Console]::Out.WriteLine((FKTick $a[1] $ma))}catch{[Console]::Out.WriteLine("OK tick unknown 0")}',
  '    continue',
  '  }',
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
        if (job.tmp) { try { fs.unlinkSync(job.tmp); } catch (_) {} }
        if (/^OK /.test(line)) job.resolve(job.tmp ? { ok: true } : { ok: true, line }); // « line » : commandes de contrôle seulement
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
      if (job.tmp) { try { fs.unlinkSync(job.tmp); } catch (_) {} }
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

/**
 * [FILE-PERIMEE 2026-10-20] Commande « #TICK » au worker : état de l'imprimante + purge des jobs
 * périmés du spouleur. Passe par le MÊME tube que les tickets (réponses FIFO), sans toucher à
 * `printChain` : un ticket en cours d'impression n'est jamais retardé par un contrôle.
 */
function sendCommand(line) {
  return new Promise((resolve) => {
    const child = workerState.child;
    if (!child || !workerState.ready || !child.stdin || !child.stdin.writable) {
      return resolve({ ok: false, error: 'worker_unavailable' });
    }
    workerState.pending.push({ tmp: null, resolve });
    try {
      child.stdin.write(line + '\n');
    } catch (e) {
      const i = workerState.pending.findIndex((j) => j.resolve === resolve);
      if (i !== -1) workerState.pending.splice(i, 1);
      resolve({ ok: false, error: 'worker_write_failed: ' + e.message });
    }
  });
}

// Dernier état connu de l'imprimante (alimenté par le contrôle périodique).
const printerState = { status: 'unknown', at: 0, purged: 0 };

async function tickPrinter(maxAgeSeconds) {
  const name = String(PRINTER).replace(/[|\r\n]/g, ' ');
  const r = await sendCommand('#TICK|' + name + '|' + (maxAgeSeconds > 0 ? maxAgeSeconds : 0));
  const m = r && r.ok && /^OK tick (online|offline|unknown) (\d+)/.exec(r.line || '');
  if (!m) return printerState; // pas de réponse exploitable : on ne change rien
  printerState.status = m[1];
  printerState.at = Date.now();
  printerState.purged = parseInt(m[2], 10) || 0;
  if (printerState.purged > 0) {
    console.log('[caisse-bridge] ' + printerState.purged + ' ticket(s) périmé(s) supprimé(s) du spouleur');
  }
  return printerState;
}

/**
 * La porte : vrai UNIQUEMENT si Windows déclare l'imprimante hors ligne, d'après un état récent
 * ET confirmé par un contrôle en direct (un statut mémorisé il y a 4 s ne doit pas refuser un
 * ticket à une imprimante qui vient de revenir). Tout le reste — porte désactivée, état inconnu,
 * périmé, worker absent — laisse passer : on n'empêche jamais d'imprimer sur une machine saine.
 */
async function printerIsOffline() {
  if (!OFFLINE_GATE) return false;
  if (printerState.status !== 'offline' || Date.now() - printerState.at > STATE_FRESH_MS) return false;
  // Un worker figé ne doit jamais suspendre la réponse HTTP : sans réponse en 3 s, on laisse passer.
  // (La commande reste dans la file FIFO du worker : sa réponse tardive lui sera bien attribuée.)
  const live = await Promise.race([
    tickPrinter(STALE_JOB_SECONDS),
    new Promise((resolve) => { const t = setTimeout(() => resolve(null), 3000); if (t.unref) t.unref(); }),
  ]);
  return !!live && live.status === 'offline' && Date.now() - live.at <= STATE_FRESH_MS;
}

let tickTimer = null;
function startPrinterWatch() {
  if (tickTimer) return;
  // Démarrage : reliquat d'une panne précédente (jobs de plus de 30 s), dès que le worker est prêt.
  const boot = setInterval(() => {
    if (!workerState.ready) return;
    clearInterval(boot);
    tickPrinter(BOOT_STALE_SECONDS).catch(() => {});
  }, 500);
  if (boot.unref) boot.unref();
  tickTimer = setInterval(() => { tickPrinter(STALE_JOB_SECONDS).catch(() => {}); }, TICK_MS);
  if (tickTimer.unref) tickTimer.unref();
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
        // [FILE-PERIMEE 2026-10-20] Imprimante hors ligne : on REFUSE (503) au lieu de laisser
        // le spouleur empiler des tickets qui ressortiraient tous d'un coup au retour.
        printerIsOffline().catch(() => false).then((offline) => {
          if (offline) {
            console.error('[caisse-bridge] imprimante hors ligne — ticket refusé (rien dans le spouleur)');
            res.writeHead(503, { 'Content-Type': 'application/json' });
            return res.end(JSON.stringify({ queued: false, error: 'printer_offline' }));
          }
          // [PRINT-INSTANT 2026-07-06] 202 IMMÉDIAT : le navigateur ne poireaute plus
          // pendant l'impression (l'ancienne réponse post-impression ajoutait 3-10 s
          // et déclenchait des aborts « faux échec » alors que le papier sortait).
          enqueuePrint(body);
          res.writeHead(202, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({ queued: true }));
        });
        return;
      });
      return;
    }

    res.writeHead(404); res.end('not found');
  });
}

if (require.main === module) {
  // Compile winspool UNE SEULE fois, au boot (pas au 1er ticket).
  startWorker();
  startPrinterWatch();
  const server = createServer();
  server.listen(PORT, '127.0.0.1', () => {
    console.log('[caisse-bridge] écoute http://127.0.0.1:' + PORT + ' → imprimante "' + PRINTER + '"');
    console.log('[caisse-bridge] GET /health  POST /raw (octets ESC/POS bruts, réponse 202 immédiate)');
  });
}

// Export test-only (contrat HTTP + worker unique) — inerte quand lancé en direct.
module.exports = { createServer, startWorker, printRaw, enqueuePrint, tickPrinter, printerIsOffline, startPrinterWatch, _printerState: printerState, _workerState: workerState, PRINTER };
