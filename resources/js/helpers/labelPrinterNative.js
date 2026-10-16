/**
 * labelPrinterNative.js — [ETIQUETTES 2026-10-16 · owner « fais l'application Android native comme Komia »]
 *
 * Dans l'application Android « Le Cayenne Étiquettes » (native/labels-android), la page tourne dans une WebView qui expose
 * `window.LabelNative` : un pont Java qui ouvre une vraie prise Bluetooth RFCOMM (profil série SPP) ou TCP (Wi-Fi, port
 * 9100) vers la Brother. Chrome n'est plus dans la boucle : plus de « Aucun appareil compatible détecté ».
 *
 * Ce module fait ressembler ce pont à l'API Web Serial (`getPorts`, `requestPort`, `port.open/readable/writable`) :
 * `createLabelPrinter({ serial })` — donc tout le protocole Brother déjà prouvé (brotherQl.js, labelPrinter.js) — reste
 * INCHANGÉ. Seul le transport d'octets change.
 *
 * Protocole du pont (Java → JS, via `window.__labelNativeEvent(json)`) :
 *   { type: 'result', callId, ok, error?, device? }   réponse à un appel
 *   { type: 'data', b64 }                             octets lus sur l'imprimante
 *   { type: 'closed', reason? }                       la liaison est tombée
 */
export const SPP_UUID = '00001101-0000-1000-8000-00805f9b34fb';

function b64encode(bytes) {
    let s = '';
    const CHUNK = 0x8000;
    for (let i = 0; i < bytes.length; i += CHUNK) s += String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK));
    return btoa(s);
}

function b64decode(b64) {
    const bin = atob(b64);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
}

function namedError(name, message) {
    const e = new Error(message || name);
    e.name = name;
    return e;
}

/** Le pont est-il là ? (WebView de l'application native) */
export function nativeBridge(win) {
    const w = win || (typeof window !== 'undefined' ? window : undefined);
    const b = w && w.LabelNative;
    return b && typeof b.open === 'function' && typeof b.chooseDevice === 'function' ? b : null;
}

/**
 * @param {object} bridge  window.LabelNative
 * @param {object} [win]   cible des événements (window)
 */
export function createNativeSerial(bridge, win) {
    const target = win || (typeof window !== 'undefined' ? window : {});
    const pending = new Map();
    const listeners = new Set();
    let seq = 0;
    let current = null; // le port ouvert (un seul à la fois)

    function call(method, ...args) {
        return new Promise((resolve, reject) => {
            seq += 1;
            const callId = `c${Date.now().toString(36)}${seq}`;
            pending.set(callId, { resolve, reject });
            try {
                bridge[method](callId, ...args);
            } catch (err) {
                pending.delete(callId);
                reject(namedError('NetworkError', String((err && err.message) || err)));
            }
        });
    }

    target.__labelNativeEvent = (raw) => {
        let ev;
        try { ev = typeof raw === 'string' ? JSON.parse(raw) : raw; } catch (_) { return; }
        if (!ev) return;
        if (ev.type === 'result') {
            const p = pending.get(ev.callId);
            if (!p) return;
            pending.delete(ev.callId);
            if (ev.ok) p.resolve(ev);
            else p.reject(namedError(ev.name || 'NetworkError', ev.error || 'Erreur'));
        } else if (ev.type === 'data') {
            if (current && current._push) current._push(b64decode(ev.b64 || ''));
        } else if (ev.type === 'closed') {
            const p = current;
            if (p && p._closeStreams) p._closeStreams();
            current = null;
            if (p) listeners.forEach((fn) => { try { fn({ type: 'disconnect', target: p }); } catch (_) { /* un abonné ne casse rien */ } });
        }
    };

    function makePort(device) {
        const kind = device.kind === 'tcp' ? 'tcp' : 'bt';
        const port = {
            nativeDevice: device,
            readable: null,
            writable: null,
            getInfo() {
                return kind === 'bt'
                    ? { bluetoothServiceClassId: SPP_UUID, usbVendorId: null, usbProductId: null }
                    : { bluetoothServiceClassId: null, usbVendorId: null, usbProductId: null };
            },
            async open() {
                if (port.readable) throw namedError('InvalidStateError', 'Port déjà ouvert');
                await call('open', kind, kind === 'bt' ? String(device.address) : String(device.host), Number(device.port) || 9100);
                let controller;
                port.readable = new ReadableStream({ start(c) { controller = c; } });
                port.writable = new WritableStream({
                    async write(chunk) {
                        await call('write', b64encode(chunk instanceof Uint8Array ? chunk : new Uint8Array(chunk)));
                    },
                });
                port._push = (bytes) => { try { controller.enqueue(bytes); } catch (_) { /* flux déjà fermé */ } };
                port._closeStreams = () => {
                    try { controller.close(); } catch (_) { /* déjà fermé */ }
                    port.readable = null;
                    port.writable = null;
                };
                current = port;
            },
            async close() {
                try { bridge.close(); } catch (_) { /* déjà fermé */ }
                if (port._closeStreams) port._closeStreams();
                if (current === port) current = null;
            },
        };
        return port;
    }

    return {
        /** Appareil mémorisé par l'application (dernier choix) : reconnexion SANS geste. */
        async getPorts() {
            let saved = null;
            try { saved = JSON.parse(bridge.saved() || 'null'); } catch (_) { saved = null; }
            if (current) return [current];
            return saved && (saved.address || saved.host) ? [makePort(saved)] : [];
        },
        /** Fenêtre native : imprimantes Bluetooth appairées + « Adresse IP (Wi-Fi) ». */
        async requestPort() {
            let res;
            try {
                res = await call('chooseDevice');
            } catch (err) {
                throw namedError('NotFoundError', 'Aucune imprimante choisie.');
            }
            if (!res.device) throw namedError('NotFoundError', 'Aucune imprimante choisie.');
            return makePort(res.device);
        },
        addEventListener(type, fn) { if (type === 'disconnect') listeners.add(fn); },
        removeEventListener(type, fn) { if (type === 'disconnect') listeners.delete(fn); },
        isNative: true,
    };
}
