#!/usr/bin/env node
/**
 * Fausse Brother QL-820NWB en Wi-Fi (TCP, port 9100) pour tester l'application native SANS imprimante :
 * répond à la demande d'état (ESC i S) « prête, rouleau 62 mm continu », décode chaque travail avec le décodeur INDÉPENDANT
 * (tests/Playwright/helpers/brotherDecode.js, écrit d'après le manuel Brother) et confirme chaque page imprimée.
 *
 *   node native/labels-android/tools/fake-brother-tcp.js [port] [dossier-de-sortie]
 */
const net = require('net');
const fs = require('fs');
const path = require('path');
const { decodeJob } = require('../../../tests/Playwright/helpers/brotherDecode.js');

const port = Number(process.argv[2]) || 9100;
const outDir = process.argv[3] || null;
if (outDir) fs.mkdirSync(outDir, { recursive: true });

const frame = ({ statusType = 0x00, phase = 0 } = {}) => {
    const f = Buffer.alloc(32);
    Buffer.from([0x80, 0x20, 0x42, 0x34, 0x41, 0x30, 0x30, 0x00, 0, 0, 62, 0x4a, 0, 0, 0x3f, 0, 0, 0, statusType, phase, 0, 0, 0, 0]).copy(f);
    return f;
};

let jobs = 0;
net.createServer((sock) => {
    console.log(`[fake-brother] connexion de ${sock.remoteAddress}`);
    let current = Buffer.alloc(0);
    sock.on('data', (chunk) => {
        if (chunk.length === 3 && chunk[0] === 0x1b && chunk[1] === 0x69 && chunk[2] === 0x53) {
            console.log('[fake-brother] demande d’état → prête, rouleau 62 mm continu');
            sock.write(frame());
            return;
        }
        current = Buffer.concat([current, chunk]);
        const d = decodeJob(new Uint8Array(current));
        if (d.error) { console.log(`[fake-brother] ERREUR de décodage : ${d.error}`); current = Buffer.alloc(0); return; }
        if (d.complete) {
            jobs += 1;
            const dims = d.pages.map((p) => `${p.rows.length} lignes`).join(', ');
            console.log(`[fake-brother] TRAVAIL #${jobs} complet : ${d.pages.length} page(s) (${dims}), ${current.length} octets`);
            if (outDir) fs.writeFileSync(path.join(outDir, `job-${jobs}.bin`), current);
            const pages = d.pages.length;
            current = Buffer.alloc(0);
            setTimeout(() => { for (let i = 0; i < pages; i++) sock.write(frame({ statusType: 0x01 })); }, 50);
        }
    });
    sock.on('close', () => console.log('[fake-brother] liaison fermée'));
    sock.on('error', () => {});
}).listen(port, '0.0.0.0', () => console.log(`[fake-brother] en écoute sur :${port}`));
