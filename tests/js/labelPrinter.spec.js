// @vitest-environment node
/**
 * [ETIQUETTES 2026-10-16] Le gestionnaire Web Serial face à une imprimante SIMULÉE qui répond comme la
 * vraie (statut 32 octets, « impression terminée » par étiquette). Prouve : connexion Bluetooth, contrôle
 * avant envoi, confirmation, sérialisation, reconnexion, et qu'aucune erreur ne remonte en exception.
 */
import { describe, it, expect } from 'vitest';
import { MEDIA, createBitmap, buildPrintJob, STATUS_LENGTH } from '../../resources/js/helpers/brotherQl.js';
import { createLabelPrinter, SPP_UUID, loadSettings, saveSettings, DEFAULT_SETTINGS } from '../../resources/js/helpers/labelPrinter.js';

function statusFrame({ err1 = 0, err2 = 0, width = 62, type = 0x4a, length = 0, statusType = 0x00 } = {}) {
    const f = new Uint8Array(STATUS_LENGTH);
    f.set([0x80, 0x20, 0x42, 0x34, 0x41, 0x30, 0x30, 0x00, err1, err2, width, type, 0, 0, 0x3f, 0, 0, length, statusType, 0, 0, 0, 0, 0]);
    return f;
}

const isStatusRequest = (c) => c.length === 3 && c[0] === 0x1b && c[1] === 0x69 && c[2] === 0x53;

/**
 * Imprimante simulée. `script` : { loaded: {...frame}, onJob: (emit)=>void }
 * Un travail est « reçu » quand `jobLength` octets (hors demandes de statut) sont arrivés.
 */
function fakePrinter({ loaded = {}, jobLength = 0, pages = 1, completions = 'auto', failWrites = false, info = {} } = {}) {
    let controller;
    const received = [];
    let jobBytes = 0;
    const emit = (frame) => controller.enqueue(frame);
    const readable = new ReadableStream({ start(c) { controller = c; }, cancel() {} });
    const writable = new WritableStream({
        write(chunk) {
            if (failWrites) throw Object.assign(new Error('write failed'), { name: 'NetworkError' });
            received.push(chunk);
            if (isStatusRequest(chunk)) { emit(statusFrame(loaded)); return; }
            jobBytes += chunk.length;
            if (completions === 'early' && jobBytes === chunk.length) {
                for (let i = 0; i < pages; i++) emit(statusFrame({ statusType: 0x01 }));
            } else if (completions === 'auto' && jobBytes >= jobLength) {
                for (let i = 0; i < pages; i++) emit(statusFrame({ statusType: 0x01 }));
            } else if (typeof completions === 'function' && jobBytes >= jobLength) {
                completions(emit);
            }
        },
    });
    const listeners = {};
    const port = {
        opened: null, closed: false, readable, writable, getInfo: () => info,
        async open(o) { port.opened = o; },
        async close() { port.closed = true; },
        emit,
    };
    return { port, received, jobPayload: () => received.filter((c) => !isStatusRequest(c)), listeners };
}

function fakeSerial(ports, { requestPortImpl } = {}) {
    const handlers = {};
    return {
        requested: [],
        getPorts: async () => ports,
        requestPort: async function (o) {
            this.requested.push(o);
            if (requestPortImpl) return requestPortImpl(o);
            return ports[0];
        },
        addEventListener(name, fn) { handlers[name] = fn; },
        fire(name, target) { handlers[name]({ target }); },
    };
}

const smallJob = (n = 1) => {
    const b = createBitmap(696, 150);
    b.pixels[5] = 1;
    return buildPrintJob(Array.from({ length: n }, () => b), MEDIA.c62);
};

const mem = () => {
    const m = new Map();
    return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), _m: m };
};

const make = (serial, extra = {}) => createLabelPrinter({ serial, storage: mem(), statusTimeoutMs: 80, pageTimeoutMs: 150, ...extra });

describe('connexion', () => {
    it('sans Web Serial : état « unsupported » et impression refusée avec un message, jamais d’exception', async () => {
        const p = createLabelPrinter({ serial: undefined });
        expect(p.getState().status).toBe('unsupported');
        expect(p.isSupported()).toBe(false);
        const r = await p.print(smallJob(), MEDIA.c62);
        expect(r.ok).toBe(false);
        expect(r.message).toMatch(/Chrome Android 138/);
        expect(await p.connect()).toBe(false);
        expect(await p.autoConnect()).toBe(false);
    });

    it('connect() demande les imprimantes Bluetooth SPP, ouvre le port et passe à « connected »', async () => {
        const fp = fakePrinter();
        const serial = fakeSerial([fp.port]);
        const p = make(serial);
        expect(await p.connect()).toBe(true);
        expect(serial.requested[0]).toEqual({ allowedBluetoothServiceClassIds: [SPP_UUID], filters: [{ bluetoothServiceClassId: SPP_UUID }] });
        expect(fp.port.opened.baudRate).toBeGreaterThan(0);
        expect(p.getState().status).toBe('connected');
        expect(p.isConnected()).toBe(true);
    });

    it('navigateur sans filtre Bluetooth (TypeError) : repli sur la liste complète des ports', async () => {
        const fp = fakePrinter();
        let calls = 0;
        const serial = fakeSerial([fp.port], {
            requestPortImpl: (o) => {
                calls += 1;
                if (o && o.filters) throw Object.assign(new TypeError('unknown filter'), { name: 'TypeError' });
                return fp.port;
            },
        });
        const p = make(serial);
        expect(await p.connect()).toBe(true);
        expect(calls).toBe(2);
    });

    it('fenêtre fermée sans choix : pas d’erreur, simplement « disconnected »', async () => {
        const serial = fakeSerial([], { requestPortImpl: () => { throw Object.assign(new Error('x'), { name: 'NotFoundError' }); } });
        const p = make(serial);
        expect(await p.connect()).toBe(false);
        expect(p.getState().status).toBe('disconnected');
        expect(p.getState().error).toBeNull();
    });

    it('imprimante éteinte (open échoue) : message clair, état « disconnected »', async () => {
        const fp = fakePrinter();
        fp.port.open = async () => { throw Object.assign(new Error('Failed to open serial port.'), { name: 'NetworkError' }); };
        const p = make(fakeSerial([fp.port]));
        expect(await p.connect()).toBe(false);
        expect(p.getState().error).toMatch(/éteinte|hors de portée/);
    });

    it('autoConnect rouvre SANS geste un port Bluetooth déjà autorisé, de préférence celui mémorisé', async () => {
        const usb = fakePrinter({ info: { usbVendorId: 1, usbProductId: 2 } });
        const bt = fakePrinter({ info: { bluetoothServiceClassId: SPP_UUID } });
        const p = make(fakeSerial([usb.port, bt.port]));
        expect(await p.autoConnect()).toBe(true);
        expect(bt.port.opened).not.toBeNull();
        expect(usb.port.opened).toBeNull();
    });

    it('autoConnect avec plusieurs ports inconnus et aucun mémorisé : ne devine pas', async () => {
        const a = fakePrinter({ info: {} });
        const b = fakePrinter({ info: {} });
        const p = make(fakeSerial([a.port, b.port]));
        expect(await p.autoConnect()).toBe(false);
    });

    it('déconnexion physique (événement « disconnect ») : état mis à jour puis reconnexion automatique', async () => {
        const fp = fakePrinter({ info: { bluetoothServiceClassId: SPP_UUID } });
        const serial = fakeSerial([fp.port]);
        const p = make(serial);
        await p.connect();
        serial.fire('disconnect', fp.port);
        expect(p.getState().status).toBe('disconnected');
        expect(p.getState().error).toMatch(/Reconnexion automatique/);
        fp.port.opened = null;
        expect(await p.autoConnect()).toBe(true);
        expect(fp.port.opened).not.toBeNull();
    });
});

describe('impression', () => {
    it('envoie exactement les octets du travail, par blocs ≤ 4096, et attend la confirmation', async () => {
        const job = smallJob(1);
        const fp = fakePrinter({ jobLength: job.bytes.length, pages: 1 });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const r = await p.print(job, MEDIA.c62);
        expect(r).toEqual({ ok: true, pages: 1 });
        const sent = fp.jobPayload();
        sent.forEach((c) => expect(c.length).toBeLessThanOrEqual(4096));
        const joined = new Uint8Array(sent.reduce((n, c) => n + c.length, 0));
        let o = 0;
        sent.forEach((c) => { joined.set(c, o); o += c.length; });
        expect(Array.from(joined)).toEqual(Array.from(job.bytes));
        expect(p.getState().status).toBe('connected');
        expect(p.getState().lastPrintAt).not.toBeNull();
    });

    it('plusieurs étiquettes : attend autant de confirmations que de pages', async () => {
        const job = smallJob(3);
        const fp = fakePrinter({ jobLength: job.bytes.length, pages: 3 });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        expect(await p.print(job, MEDIA.c62)).toEqual({ ok: true, pages: 3 });
    });

    it('la confirmation arrivée AVANT la fin de l’écriture est quand même comptée (l’imprimante imprime en recevant)', async () => {
        const job = smallJob(1);
        const fp = fakePrinter({ completions: 'early', pages: 1 });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        expect(await p.print(job, MEDIA.c62)).toEqual({ ok: true, pages: 1 });
    });

    it('capot ouvert : rien n’est envoyé, message en français', async () => {
        const fp = fakePrinter({ loaded: { err2: 0x10 } });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const r = await p.print(smallJob(), MEDIA.c62);
        expect(r.ok).toBe(false);
        expect(r.message).toMatch(/Capot ouvert/);
        expect(fp.jobPayload()).toHaveLength(0);
    });

    it('plus de rouleau : rien n’est envoyé', async () => {
        const fp = fakePrinter({ loaded: { err1: 0x01, width: 0, type: 0 } });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const r = await p.print(smallJob(), MEDIA.c62);
        expect(r.ok).toBe(false);
        expect(r.message).toMatch(/Aucun rouleau/);
        expect(fp.jobPayload()).toHaveLength(0);
    });

    it('mauvais rouleau (29×90 chargé, 62 mm réglé) : message explicite, rien n’est envoyé', async () => {
        const fp = fakePrinter({ loaded: { width: 29, type: 0x4b, length: 90 } });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const r = await p.print(smallJob(), MEDIA.c62);
        expect(r.ok).toBe(false);
        expect(r.message).toMatch(/29\s×\s90\smm.*62\smm\scontinu/);
        expect(fp.jobPayload()).toHaveLength(0);
    });

    it('l’état « imprimante occupée » seul ne bloque pas', async () => {
        const job = smallJob(1);
        const fp = fakePrinter({ loaded: { err1: 0x10 }, jobLength: job.bytes.length });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        expect((await p.print(job, MEDIA.c62)).ok).toBe(true);
    });

    it('panne en cours d’impression (fin de rouleau) : échec avec le nombre d’étiquettes sorties', async () => {
        const job = smallJob(3);
        const fp = fakePrinter({
            jobLength: job.bytes.length,
            completions: (emit) => { emit(statusFrame({ statusType: 0x01 })); emit(statusFrame({ err1: 0x02, statusType: 0x02 })); },
        });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const r = await p.print(job, MEDIA.c62);
        expect(r.ok).toBe(false);
        expect(r.pages).toBe(1);
        expect(r.message).toMatch(/Fin de rouleau.*1\/3/);
    });

    it('imprimante éteinte pendant le travail', async () => {
        const job = smallJob(1);
        const fp = fakePrinter({ jobLength: job.bytes.length, completions: (emit) => emit(statusFrame({ statusType: 0x04 })) });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const r = await p.print(job, MEDIA.c62);
        expect(r.ok).toBe(false);
        expect(r.message).toMatch(/éteinte/);
    });

    it('aucune confirmation : « envoyé, non confirmé » — SANS jamais renvoyer de soi‑même', async () => {
        const job = smallJob(1);
        const fp = fakePrinter({ completions: () => {} });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const r = await p.print(job, MEDIA.c62);
        expect(r.ok).toBe(true);
        expect(r.unconfirmed).toBe(true);
        expect(r.message).toMatch(/pas confirmé/);
        const totalJob = fp.jobPayload().reduce((n, c) => n + c.length, 0);
        expect(totalJob).toBe(job.bytes.length); // une seule fois
    });

    it('échec d’écriture (Bluetooth coupé) : message, port abandonné, aucune exception', async () => {
        const job = smallJob(1);
        const fp = fakePrinter({ failWrites: true });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const r = await p.print(job, MEDIA.c62);
        expect(r.ok).toBe(false);
        expect(p.getState().status).toBe('disconnected');
    });

    it('deux impressions simultanées sont SÉRIALISÉES : le 2ᵉ travail ne commence qu’après le 1ᵉʳ', async () => {
        const a = smallJob(1);
        const b = smallJob(2);
        const order = [];
        const fp = fakePrinter({ jobLength: 0, completions: 'auto' });
        // taille de travail variable : on branche la confirmation sur la fin de chaque travail
        let acc = 0;
        let current = a.bytes.length;
        const origWrite = fp.port.writable;
        const emit = fp.port.emit;
        const writable = new WritableStream({
            write(chunk) {
                if (isStatusRequest(chunk)) { emit(statusFrame()); return; }
                acc += chunk.length;
                if (acc === current) {
                    order.push(current === a.bytes.length ? 'A' : 'B');
                    const pages = current === a.bytes.length ? 1 : 2;
                    acc = 0;
                    current = b.bytes.length;
                    for (let i = 0; i < pages; i++) emit(statusFrame({ statusType: 0x01 }));
                }
            },
        });
        fp.port.writable = writable;
        void origWrite;
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const [ra, rb] = await Promise.all([p.print(a, MEDIA.c62), p.print(b, MEDIA.c62)]);
        expect(ra.ok && rb.ok).toBe(true);
        expect(order).toEqual(['A', 'B']);
    });

    it('imprimer sans connexion tente une reconnexion silencieuse, puis dit quoi faire', async () => {
        const p = make(fakeSerial([]));
        const r = await p.print(smallJob(), MEDIA.c62);
        expect(r.ok).toBe(false);
        expect(r.message).toMatch(/non connectée/);
    });
});

describe('revue adversariale : connexions croisées, coupure en cours de travail, confirmations tardives', () => {
    /** Port conforme à la spec Web Serial : open() sur un port déjà ouvert lève InvalidStateError. */
    function strictPort(fp) {
        let isOpen = false;
        fp.port.open = async (o) => {
            if (isOpen) throw Object.assign(new Error('The port is already open.'), { name: 'InvalidStateError' });
            isOpen = true;
            fp.port.opened = o;
        };
        return fp;
    }

    it('connect() deux fois de suite : le port déjà ouvert est réutilisé, jamais verrouillé', async () => {
        const fp = strictPort(fakePrinter({ info: { bluetoothServiceClassId: SPP_UUID } }));
        const p = make(fakeSerial([fp.port]));
        expect(await p.connect()).toBe(true);
        expect(await p.connect()).toBe(true);
        expect(p.getState().status).toBe('connected');
        expect(p.getState().error).toBeNull();
        expect(p.isConnected()).toBe(true);
    });

    it('autoConnect() croisé avec connect() (la veille pendant la fenêtre de choix) : un seul port, état connecté', async () => {
        const fp = strictPort(fakePrinter({ info: { bluetoothServiceClassId: SPP_UUID } }));
        const p = make(fakeSerial([fp.port]));
        const [a, b, c] = await Promise.all([p.autoConnect(), p.autoConnect(), p.connect()]);
        expect([a, b, c]).toEqual([true, true, true]);
        expect(p.getState().status).toBe('connected');
        expect(p.isConnected()).toBe(true);
    });

    it('coupure du Bluetooth APRÈS l’écriture : échec immédiat, pas « envoyé non confirmé »', async () => {
        const job = smallJob(2);
        const fp = fakePrinter({ completions: () => {} }); // l'imprimante ne confirme rien
        const serial = fakeSerial([fp.port]);
        const p = make(serial, { pageTimeoutMs: 5000 });
        await p.connect();
        const started = Date.now();
        const pending = p.print(job, MEDIA.c62);
        setTimeout(() => serial.fire('disconnect', fp.port), 120);
        const r = await pending;
        expect(r.ok).toBe(false);
        expect(r.pages).toBe(0);
        expect(r.message).toMatch(/déconnectée pendant l’impression/);
        expect(Date.now() - started, 'sans attendre le délai de confirmation').toBeLessThan(2000);
    });

    it('une confirmation tardive du travail A n’est pas comptée pour le travail B', async () => {
        const a = smallJob(1);
        const b = smallJob(1);
        const fp = fakePrinter({ completions: () => {} });
        const serial = fakeSerial([fp.port]);
        const p = make(serial);
        await p.connect();
        const ra = await p.print(a, MEDIA.c62); // aucune confirmation : non confirmé
        expect(ra.unconfirmed).toBe(true);

        // pendant B, la confirmation en retard de A arrive, puis la vraie confirmation de B beaucoup plus tard
        const pb = p.print(b, MEDIA.c62);
        setTimeout(() => fp.port.emit(statusFrame({ statusType: 0x01 })), 40); // retardataire de A
        const rb = await pb;
        expect(rb.unconfirmed, 'B ne doit pas être donné pour confirmé par la confirmation de A').toBe(true);
    });
});

describe('statut et veille', () => {
    it('refreshStatus lit le rouleau chargé', async () => {
        const fp = fakePrinter({ loaded: { width: 62, type: 0x4a } });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        const s = await p.refreshStatus();
        expect(s.model).toBe('QL-820NWB');
        expect(s.media.widthMm).toBe(62);
        expect(p.getState().printer.media.type).toBe('continuous');
    });

    it('imprimante muette : refreshStatus renvoie null sans bloquer', async () => {
        const fp = fakePrinter();
        fp.port.writable = new WritableStream({ write() {} });
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        expect(await p.refreshStatus()).toBeNull();
    });

    it('disconnect ferme proprement le port', async () => {
        const fp = fakePrinter();
        const p = make(fakeSerial([fp.port]));
        await p.connect();
        await p.disconnect();
        expect(fp.port.closed).toBe(true);
        expect(p.getState().status).toBe('disconnected');
    });
});

describe('réglages de la tablette', () => {
    it('valeurs par défaut sûres : 62 mm continu, coupe auto, pas d’automatique, pas de boissons', () => {
        const s = loadSettings(mem());
        expect(s).toEqual(DEFAULT_SETTINGS);
        expect(s.autoPrint).toBe(false);
        expect(s.includeDrinks).toBe(false);
    });

    it('relit ce qui a été enregistré, complète les clés manquantes, et survit à un JSON corrompu', () => {
        const st = mem();
        saveSettings(st, { mediaId: 'd62x29', autoPrint: true });
        const s = loadSettings(st);
        expect(s.mediaId).toBe('d62x29');
        expect(s.autoPrint).toBe(true);
        expect(s.mirror).toBe(true);
        st.setItem('label_printer_settings', '{pas du json');
        expect(loadSettings(st)).toEqual(DEFAULT_SETTINGS);
    });

    it('un stockage indisponible ne casse rien', () => {
        const broken = { getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); } };
        expect(loadSettings(broken)).toEqual(DEFAULT_SETTINGS);
        expect(saveSettings(broken, {})).toBe(false);
    });
});
