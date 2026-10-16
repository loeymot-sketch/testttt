/**
 * labelPrinter.js — [ETIQUETTES 2026-10-16] Pilote une Brother QL‑820NWB DEPUIS LE CHROME DE LA TABLETTE
 * ANDROID, en Bluetooth, via Web Serial (profil SPP/RFCOMM).
 *
 * Pourquoi pas le serveur : Laravel tourne sur le cloud (OVH) ; il ne peut pas joindre une imprimante
 * Bluetooth posée à côté de la tablette. Même constat et même technique que l'afficheur client
 * (posCustomerDisplay.js). Prérequis : Chrome Android ≥ 138 (Web Serial sur Bluetooth RFCOMM), page en
 * HTTPS, imprimante APPAIRÉE une fois dans les réglages Bluetooth d'Android.
 *
 * Ce que ce module garantit :
 *  - aucune fonction publique ne lève d'exception : tout est rendu sous forme de résultat lisible ;
 *  - les impressions sont SÉRIALISÉES (le mode automatique et un appui manuel ne s'entremêlent jamais) ;
 *  - avant d'envoyer, on lit l'état de l'imprimante : capot ouvert, plus de rouleau, mauvais rouleau →
 *    message clair en français, rien n'est envoyé ;
 *  - après l'envoi, on attend la confirmation « impression terminée » de l'imprimante ; si elle ne vient
 *    pas, on le DIT (« envoyé, non confirmé ») sans jamais réimprimer de soi‑même (pas de doublon) ;
 *  - une imprimante qui s'éteint ou sort de portée est redétectée et reconnectée sans geste.
 */
import {
    STATUS_REQUEST,
    STATUS_LENGTH,
    extractStatusFrames,
    parseStatus,
    mediaMismatchMessage,
    DEFAULT_MEDIA_ID,
} from './brotherQl.js';
import { nativeBridge, createNativeSerial } from './labelPrinterNative.js';

export const SPP_UUID = '00001101-0000-1000-8000-00805f9b34fb';
export const PORT_STORAGE_KEY = 'label_printer_port';
export const SETTINGS_STORAGE_KEY = 'label_printer_settings';

export const DEFAULT_SETTINGS = Object.freeze({
    mediaId: DEFAULT_MEDIA_ID,
    autoCut: true,
    /** Miroir du sens des données dans la ligne raster (voir brotherQl.bitmapToRasterRows). */
    mirror: true,
    /** Rotation de 180° si les étiquettes sortent à l'envers. */
    turn180: false,
    includeDrinks: false,
    /** Impression automatique des nouvelles commandes (écran « Commandes » ouvert sur la tablette). */
    autoPrint: false,
    /** Horodatage (ms) de l'activation du mode automatique : seules les commandes ARRIVÉES APRÈS s'impriment seules. */
    autoPrintSince: null,
    /** Ids des commandes DÉJÀ sur le tableau à l'activation : elles ne s'impriment jamais seules (les suivantes, si). */
    autoPrintBaseline: [],
});

const WRITE_CHUNK = 4096;
const STATUS_TIMEOUT_MS = 2500;
/** Délai accordé à l'imprimante : 6 s de base + 6 s par étiquette. */
const PAGE_TIMEOUT_MS = 6000;

export function loadSettings(storage) {
    try {
        const raw = storage && storage.getItem(SETTINGS_STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : {};
        return { ...DEFAULT_SETTINGS, ...(parsed && typeof parsed === 'object' ? parsed : {}) };
    } catch (_) {
        return { ...DEFAULT_SETTINGS };
    }
}

export function saveSettings(storage, settings) {
    try {
        storage && storage.setItem(SETTINGS_STORAGE_KEY, JSON.stringify(settings));
        return true;
    } catch (_) {
        return false;
    }
}

function readJson(storage, key) {
    try {
        const raw = storage && storage.getItem(key);
        return raw ? JSON.parse(raw) : null;
    } catch (_) {
        return null;
    }
}

/** Message français pour une erreur de port série. */
export function explainPortError(err) {
    const name = err && err.name;
    const msg = String((err && err.message) || err || '');
    if (name === 'NotFoundError') return 'Aucune imprimante choisie.';
    if (name === 'NetworkError' || /failed to open|failed to connect|connection/i.test(msg)) {
        return 'Imprimante injoignable : elle est éteinte, hors de portée, ou déjà connectée à un autre appareil (un téléphone par exemple). Rallumez‑la puis réessayez.';
    }
    if (name === 'SecurityError') return 'Chrome refuse l’accès à l’imprimante sur cette page (la page doit être en HTTPS).';
    if (name === 'InvalidStateError') return 'Le port de l’imprimante est déjà utilisé par cette page.';
    return 'Imprimante : ' + (msg || 'erreur inconnue');
}

function concat(a, b) {
    const out = new Uint8Array(a.length + b.length);
    out.set(a, 0);
    out.set(b, a.length);
    return out;
}

/**
 * @param {{serial?:any, storage?:Storage, now?:()=>number, statusTimeoutMs?:number, pageTimeoutMs?:number}} [opts]
 */
export function createLabelPrinter(opts = {}) {
    const serial = opts.serial;
    const storage = opts.storage;
    const now = opts.now || Date.now;
    const statusTimeoutMs = opts.statusTimeoutMs ?? STATUS_TIMEOUT_MS;
    const pageTimeoutMs = opts.pageTimeoutMs ?? PAGE_TIMEOUT_MS;

    let port = null;
    let reader = null;
    let readLoopDone = Promise.resolve();
    let buffer = new Uint8Array(0);
    let lastStatus = null;
    let queue = Promise.resolve();
    let busy = false;
    let keepAliveTimer = null;
    // Une seule tentative de connexion silencieuse à la fois (la veille et l'appui « Connecter » peuvent se croiser).
    let autoFlight = null;
    // Confirmations « terminé » attendues d'un travail resté sans réponse : elles peuvent arriver pendant le suivant.
    let owedStale = 0;
    // Signal du travail en cours : permet de réveiller l'attente quand le port tombe.
    let activeSignal = null;
    const frameListeners = new Set();
    const listeners = new Set();
    let state = {
        status: serial ? 'disconnected' : 'unsupported',
        error: null,
        printer: null, // dernier état lu (parseStatus)
        lastPrintAt: null,
    };

    function setState(next) {
        state = { ...state, ...next };
        listeners.forEach((fn) => {
            try { fn(state); } catch (_) { /* un abonné ne casse pas l'imprimante */ }
        });
    }

    // ── lecture des trames d'état ────────────────────────────────────────────
    function onFrame(frame) {
        const parsed = parseStatus(frame);
        if (!parsed.valid) return;
        lastStatus = parsed;
        if (!busy) setState({ printer: parsed });
        frameListeners.forEach((fn) => {
            try { fn(parsed); } catch (_) { /* un auditeur ne casse pas la lecture */ }
        });
    }

    /** Attend une trame vérifiant `predicate`, parmi celles qui arrivent APRÈS l'appel. */
    function waitFrame(predicate, timeoutMs) {
        return new Promise((resolve) => {
            let timer = null;
            const listener = (s) => {
                if (predicate(s)) { clearTimeout(timer); frameListeners.delete(listener); resolve(s); }
            };
            frameListeners.add(listener);
            timer = setTimeout(() => { frameListeners.delete(listener); resolve(null); }, timeoutMs);
        });
    }

    function startReader(candidate) {
        if (!candidate.readable) return;
        const current = candidate.readable.getReader();
        reader = current;
        readLoopDone = (async () => {
            try {
                for (;;) {
                    const { value, done } = await current.read();
                    if (done) break;
                    if (value && value.length) {
                        buffer = concat(buffer, value);
                        const { frames, rest } = extractStatusFrames(buffer);
                        buffer = rest;
                        frames.forEach(onFrame);
                        // garde‑fou : un tampon qui grossit sans trame valide n'est que du bruit
                        if (buffer.length > STATUS_LENGTH * 8) buffer = new Uint8Array(0);
                    }
                }
            } catch (_) {
                // port fermé / débranché : « disconnect » ou la prochaine écriture le signalera
            } finally {
                try { current.releaseLock(); } catch (_) { /* déjà libéré */ }
                if (reader === current) reader = null;
            }
        })();
    }

    async function stopReader() {
        if (reader) {
            try { await reader.cancel(); } catch (_) { /* déjà fermé */ }
        }
        await readLoopDone;
    }

    async function openPort(candidate) {
        // Déjà ouvert et lu par nous : on le réutilise (un second open/getReader verrouillerait le port pour de bon).
        if (port === candidate && reader) {
            setState({ status: 'connected', error: null });
            return true;
        }
        const base = { baudRate: 115200, dataBits: 8, stopBits: 1, parity: 'none', flowControl: 'none' };
        try {
            await candidate.open({ ...base, bufferSize: 8192 });
        } catch (err) {
            if (err && err.name === 'TypeError') {
                await candidate.open(base); // navigateur sans option bufferSize
            } else if (!(err && err.name === 'InvalidStateError' && candidate.writable)) {
                throw err;
            }
        }
        port = candidate;
        buffer = new Uint8Array(0);
        lastStatus = null;
        owedStale = 0;
        try {
            const info = candidate.getInfo ? candidate.getInfo() : {};
            storage && storage.setItem(PORT_STORAGE_KEY, JSON.stringify({
                bluetoothServiceClassId: info.bluetoothServiceClassId ?? null,
                usbVendorId: info.usbVendorId ?? null,
                usbProductId: info.usbProductId ?? null,
            }));
        } catch (_) { /* mémorisation facultative */ }
        if (!reader) startReader(candidate);
        setState({ status: 'connected', error: null });
        // Lit tout de suite l'état (rouleau, capot) plutôt que d'attendre le prochain tour de veille.
        setTimeout(() => { api.refreshStatus(); }, 0);
        return true;
    }

    function dropPort(reason) {
        port = null;
        owedStale = 0;
        if (reader) {
            try { reader.cancel(); } catch (_) { /* déjà fermé */ }
        }
        if (activeSignal && activeSignal.wake) activeSignal.wake(); // une impression en attente doit le savoir tout de suite
        setState({ status: 'disconnected', error: reason || null, printer: null });
    }

    if (serial && typeof serial.addEventListener === 'function') {
        serial.addEventListener('disconnect', (ev) => {
            if (port && ev && ev.target === port) {
                dropPort('Imprimante déconnectée (éteinte ou hors de portée). Reconnexion automatique…');
            }
        });
    }

    // ── écriture ──────────────────────────────────────────────────────────────
    async function writeAll(bytes) {
        const writer = port.writable.getWriter();
        try {
            for (let i = 0; i < bytes.length; i += WRITE_CHUNK) {
                await writer.write(bytes.subarray(i, Math.min(bytes.length, i + WRITE_CHUNK)));
            }
        } finally {
            try { writer.releaseLock(); } catch (_) { /* rien */ }
        }
    }

    async function requestStatus() {
        if (!port || !port.writable) return null;
        const pending = waitFrame((s) => s.statusType === 'reply', statusTimeoutMs);
        try {
            await writeAll(STATUS_REQUEST);
        } catch (_) {
            return null;
        }
        return pending;
    }

    function enqueue(task) {
        const result = queue.then(task, task);
        queue = result.catch(() => {});
        return result;
    }

    /**
     * Attend la fin de `pages` étiquettes. Les trames sont COLLECTÉES depuis le début de l'envoi : l'imprimante
     * imprime pendant qu'elle reçoit, et peut confirmer une étiquette avant même que l'écriture soit terminée.
     */
    async function awaitCompletion(seen, pages, signal) {
        const deadline = now() + pageTimeoutMs * (pages + 1);
        let index = 0;
        let completed = 0;
        while (completed < pages) {
            if (index >= seen.length) {
                if (!port) return { completed, failure: 'Imprimante déconnectée pendant l’impression.' };
                const remaining = deadline - now();
                if (remaining <= 0) break;
                await new Promise((resolve) => {
                    signal.wake = resolve;
                    setTimeout(resolve, Math.min(remaining, 1000));
                });
                signal.wake = null;
                continue;
            }
            const s = seen[index++];
            if (s.statusType === 'off') {
                return { completed, failure: 'L’imprimante s’est éteinte pendant l’impression.' };
            }
            if (s.hasError || s.statusType === 'error') {
                const blocking = s.errors.find((e) => e.blocking);
                return { completed, failure: blocking ? blocking.message : 'Erreur d’impression.' };
            }
            if (s.statusType === 'completed') completed += 1;
        }
        return { completed, failure: null };
    }

    const api = {
        isSupported: () => Boolean(serial),
        isConnected: () => Boolean(port),
        getState: () => state,
        onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },

        /** Reconnexion SANS geste : uniquement un port déjà autorisé dans ce Chrome. */
        autoConnect() {
            if (!serial) return Promise.resolve(false);
            if (port) return Promise.resolve(true);
            if (!autoFlight) {
                autoFlight = api.autoConnectOnce().finally(() => { autoFlight = null; });
            }
            return autoFlight;
        },

        async autoConnectOnce() {
            if (!serial) return false;
            if (port) return true;
            try {
                const ports = await serial.getPorts();
                if (!ports || ports.length === 0) return false;
                const saved = readJson(storage, PORT_STORAGE_KEY);
                const infoOf = (p) => (p.getInfo ? p.getInfo() : {});
                const bt = ports.filter((p) => infoOf(p).bluetoothServiceClassId);
                const pool = bt.length ? bt : ports;
                const match = saved && pool.find((p) => {
                    const i = infoOf(p);
                    return (i.usbVendorId ?? null) === saved.usbVendorId && (i.usbProductId ?? null) === saved.usbProductId
                        && (i.bluetoothServiceClassId ?? null) === saved.bluetoothServiceClassId;
                });
                const chosen = match || (pool.length === 1 ? pool[0] : null);
                if (!chosen) return false;
                setState({ status: 'connecting', error: null });
                return await openPort(chosen);
            } catch (err) {
                port = null;
                setState({ status: 'disconnected', error: explainPortError(err) });
                return false;
            }
        },

        /** Appairage — DOIT être appelé depuis un clic (Chrome l'exige pour requestPort). */
        async connect() {
            if (!serial) return false;
            setState({ status: 'connecting', error: null, noDevice: false });
            let chosen;
            try {
                // On propose d'abord les imprimantes Bluetooth (profil SPP) ; si le navigateur ne connaît pas ce
                // filtre (TypeError), on retombe sur la liste complète des ports.
                try {
                    chosen = await serial.requestPort({
                        allowedBluetoothServiceClassIds: [SPP_UUID],
                        filters: [{ bluetoothServiceClassId: SPP_UUID }],
                    });
                } catch (err) {
                    if (err && err.name === 'TypeError') {
                        chosen = await serial.requestPort();
                    } else {
                        throw err;
                    }
                }
            } catch (err) {
                // Fenêtre fermée sans choisir : ce n'est pas une erreur.
                // Chrome lève NotFoundError AUSSI quand sa fenêtre affiche « Aucun appareil compatible détecté » : on ne le
                // laisse plus passer en silence — l'écran explique quoi vérifier (appairage, profil série, version de Chrome).
                const notFound = err && err.name === 'NotFoundError';
                setState({
                    status: port ? 'connected' : 'disconnected',
                    error: notFound ? null : explainPortError(err),
                    // (dans l'application native, fermer la fenêtre de choix n'est qu'une annulation)
                    noDevice: Boolean(notFound && !port && !(serial && serial.isNative)),
                });
                return Boolean(port);
            }
            if (autoFlight) await autoFlight.catch(() => {});
            try {
                return await openPort(chosen);
            } catch (err) {
                // Si le port est ENCORE bien ouvert chez nous, un échec d'ouverture n'est pas une panne.
                if (port === chosen && reader) {
                    setState({ status: 'connected', error: null });
                    return true;
                }
                port = null;
                setState({ status: 'disconnected', error: explainPortError(err) });
                return false;
            }
        },

        async disconnect() {
            const p = port;
            if (!p) return true;
            await stopReader();
            try { await p.close(); } catch (_) { /* déjà fermé */ }
            dropPort(null);
            return true;
        },

        /** Lit l'état de l'imprimante (capot, rouleau…). Renvoie l'objet parseStatus ou null. */
        refreshStatus() {
            if (busy) return Promise.resolve(lastStatus);
            return enqueue(async () => {
                if (!port) return null;
                const s = await requestStatus();
                if (s) setState({ printer: s });
                return s;
            });
        },

        /**
         * Imprime un travail déjà fabriqué par brotherQl.buildPrintJob.
         * @param {{bytes:Uint8Array, pages:number}} job
         * @param {object} media  entrée de MEDIA (pour contrôler le rouleau chargé)
         * @returns {Promise<{ok:boolean, unconfirmed?:boolean, message?:string, pages?:number}>}
         */
        print(job, media) {
            return enqueue(async () => {
                busy = true;
                const seen = [];
                const signal = { wake: null };
                activeSignal = signal;
                const collector = (s) => {
                    // Une confirmation attendue d'un travail PRÉCÉDENT resté sans réponse n'est pas la nôtre.
                    if (s.statusType === 'completed' && owedStale > 0) { owedStale -= 1; return; }
                    seen.push(s);
                    if (signal.wake) signal.wake();
                };
                try {
                    if (!serial) {
                        return { ok: false, message: 'Ce navigateur ne sait pas parler à l’imprimante (Chrome Android 138 ou plus récent requis).' };
                    }
                    if (!port) {
                        const reconnected = await api.autoConnect();
                        if (!reconnected) {
                            return { ok: false, message: state.error || 'Imprimante non connectée. Ouvrez « Imprimante » et appuyez sur « Connecter ».' };
                        }
                    }

                    // 1) état avant envoi : on ne gaspille ni papier ni temps sur une panne connue
                    const before = await requestStatus();
                    if (before) {
                        setState({ printer: before });
                        if (before.hasError) return { ok: false, message: before.errors.find((e) => e.blocking).message };
                        const mismatch = mediaMismatchMessage(before, media);
                        if (mismatch) return { ok: false, message: mismatch };
                    }

                    // 2) envoi (le collecteur est branché AVANT : la confirmation peut précéder la fin de l'écriture)
                    frameListeners.add(collector);
                    setState({ status: 'busy', error: null });
                    try {
                        await writeAll(job.bytes);
                    } catch (err) {
                        const why = explainPortError(err);
                        dropPort(why);
                        return { ok: false, message: 'Envoi interrompu : ' + why };
                    }

                    // 3) confirmation de l'imprimante
                    const { completed, failure } = await awaitCompletion(seen, job.pages, signal);
                    if (failure) {
                        return {
                            ok: false,
                            pages: completed,
                            message: `${failure}${completed ? ` (${completed}/${job.pages} étiquette(s) sortie(s))` : ''}`,
                        };
                    }
                    setState({ lastPrintAt: now() });
                    if (completed >= job.pages) return { ok: true, pages: completed };
                    owedStale = Math.min(job.pages - completed, 20);
                    return {
                        ok: true,
                        unconfirmed: true,
                        pages: completed,
                        message: 'Envoyé à l’imprimante, mais elle n’a pas confirmé la fin. Vérifiez que les étiquettes sont sorties avant de relancer.',
                    };
                } catch (err) {
                    return { ok: false, message: explainPortError(err) };
                } finally {
                    frameListeners.delete(collector);
                    if (activeSignal === signal) activeSignal = null;
                    busy = false;
                    if (port) setState({ status: 'connected' });
                }
            });
        },

        /**
         * Veille : reconnexion automatique si l'imprimante est tombée, et lecture périodique de l'état
         * (capot ouvert / plus de rouleau remontent AVANT la prochaine commande). Idempotent.
         */
        startKeepAlive(intervalMs = 8000) {
            if (!serial || keepAliveTimer) return;
            const tick = async () => {
                if (busy) return;
                if (!port) { await api.autoConnect(); return; }
                await api.refreshStatus();
            };
            tick();
            keepAliveTimer = setInterval(tick, intervalMs);
        },

        stopKeepAlive() {
            if (keepAliveTimer) { clearInterval(keepAliveTimer); keepAliveTimer = null; }
        },
    };

    return api;
}

let singleton = null;

/** Instance unique : la connexion Bluetooth reste ouverte d'un écran d'étiquettes à l'autre. */
export function labelPrinter() {
    if (!singleton) {
        // Application Android native (WebView + pont Java) : vraie prise Bluetooth RFCOMM / TCP, sans Chrome ; sinon Web Serial.
        const bridge = nativeBridge();
        const serial = bridge
            ? createNativeSerial(bridge)
            : (typeof navigator !== 'undefined' && navigator.serial ? navigator.serial : undefined);
        let storage;
        try { storage = window.localStorage; } catch (_) { storage = undefined; }
        singleton = createLabelPrinter({ serial, storage });
    }
    return singleton;
}
