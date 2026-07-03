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
 * Le front teste GET /health avant chaque impression ; si ce pont tourne, il
 * imprime en RAW ; sinon il retombe sur window.print() (le charabia actuel).
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

function printRaw(buffer) {
  return new Promise((resolve) => {
    const tmp = path.join(os.tmpdir(), 'fk_caisse_' + Date.now() + '_' + Math.floor(process.hrtime()[1]) + '.bin');
    try {
      fs.writeFileSync(tmp, buffer);
    } catch (e) {
      return resolve({ ok: false, error: 'tempfile_write_failed: ' + e.message });
    }
    const escPrinter = "'" + String(PRINTER).replace(/'/g, "''") + "'";
    const escPath = "'" + tmp.replace(/'/g, "''") + "'";
    const ps = [
      "$ErrorActionPreference='Stop'",
      'Add-Type -TypeDefinition @"',
      CSHARP,
      '"@',
      '$bytes=[System.IO.File]::ReadAllBytes(' + escPath + ')',
      'if(-not [FKRaw]::Send(' + escPrinter + ', $bytes)){ exit 2 }',
    ].join('\n');
    const b64 = Buffer.from(ps, 'utf16le').toString('base64');
    // [FLASH-FIX 2026-07-03] windowsHide:true → PAS de fenêtre console qui « flashe »
    // à chaque impression (owner : « un Terminal s'ouvre d'un coup, un flash »).
    const child = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', b64], { windowsHide: true });
    let err = '';
    child.stderr.on('data', (d) => { err += d.toString(); });
    child.on('error', (e) => { try { fs.unlinkSync(tmp); } catch (_) {} resolve({ ok: false, error: 'spawn_failed: ' + e.message }); });
    child.on('close', (code) => {
      try { fs.unlinkSync(tmp); } catch (_) {}
      resolve(code === 0 ? { ok: true } : { ok: false, error: 'winspool_exit_' + code + ' ' + err.trim() });
    });
  });
}

function cors(res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Access-Control-Allow-Private-Network', 'true');
}

const server = http.createServer((req, res) => {
  cors(res);
  if (req.method === 'OPTIONS') { res.writeHead(204); return res.end(); }

  if (req.method === 'GET' && req.url.startsWith('/health')) {
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    return res.end('UP');
  }

  if (req.method === 'POST' && req.url.startsWith('/raw')) {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', async () => {
      const body = Buffer.concat(chunks);
      if (!body.length) { res.writeHead(400); return res.end('empty'); }
      const r = await printRaw(body);
      if (r.ok) { res.writeHead(200); return res.end('PRINTED'); }
      console.error('[caisse-bridge] print failed:', r.error);
      res.writeHead(500); return res.end(r.error);
    });
    return;
  }

  res.writeHead(404); res.end('not found');
});

server.listen(PORT, '127.0.0.1', () => {
  console.log('[caisse-bridge] écoute http://127.0.0.1:' + PORT + ' → imprimante "' + PRINTER + '"');
  console.log('[caisse-bridge] GET /health  POST /raw (octets ESC/POS bruts)');
});
