/**
 * posCustomerDisplay.js — [AFFICHEUR-CLIENT 2026-09-30] Afficheur client SAGA (SGD200-II,
 * PD220C-VFD, 2×20) piloté DEPUIS LE CHROME DE LA CAISSE, via Web Serial.
 *
 * Pourquoi pas le serveur : Laravel tourne sur le cloud Linux (OVH) ; il ne peut pas sortir
 * sur l'USB du PC caisse. `WindowsSerialDisplayTransport` n'y renvoie que `false`, et
 * `printing.customer_display.enabled` y vaut `false` (constaté en production le 2026-09-30) :
 * l'afficheur ne recevait RIEN de la caisse. C'est le navigateur, assis sur la bonne machine,
 * qui écrit maintenant sur le port série.
 *
 * Octets : ESC @ · ESC t 19 (CP858) · CLR · <20 col haut><20 col bas> — même contenu que le
 * rendu serveur (CustomerDisplayService : « TOTAL » + montant aligné à droite), mais sans les
 * ESC Q A/B du CD5220 (voir frame()).
 *
 * Règle d'or : un afficheur absent, refusé ou occupé ne doit JAMAIS gêner l'encaissement.
 * Aucune fonction publique ne lève d'exception ; l'état est lisible via getState().
 */

export const COLS = 20;
export const PORT_STORAGE_KEY = 'pos_customer_display_port';
export const BAUD_STORAGE_KEY = 'pos_customer_display_baud';

// Caractères hors CP858 ramenés à un équivalent lisible AVANT le cadrage (compte des colonnes juste).
const FOLD = { 'œ': 'oe', 'Œ': 'OE', '’': "'", '‘': "'", '“': '"', '”': '"', '–': '-', '—': '-', '…': '...', ' ': ' ', ' ': ' ' };

// CP858 = CP850 avec € en 0xD5. Seule la moitié haute est tabulée (0x80 → 0xFF).
const CP858_HIGH =
    'ÇüéâäàåçêëèïîìÄÅÉæÆôöòûùÿÖÜø£Ø×ƒáíóúñÑªº¿®¬½¼¡«»░▒▓│┤ÁÂÀ©╣║╗╝¢¥┐' +
    '└┴┬├─┼ãÃ╚╔╩╦╠═╬¤ðÐÊËÈ€ÍÎÏ┘┌█▄¦Ì▀ÓßÔÒõÕµþÞÚÛÙýÝ¯´­±‗¾¶§÷¸°¨·¹³²■ ';
const CP858_MAP = (() => {
    const m = new Map();
    for (let i = 0; i < CP858_HIGH.length; i++) m.set(CP858_HIGH[i], 0x80 + i);
    return m;
})();

function fold(text) {
    return Array.from(String(text ?? '')).map((c) => FOLD[c] ?? c).join('');
}

function clean(text) {
    // eslint-disable-next-line no-control-regex
    return Array.from(fold(text).replace(/[\x00-\x1F\x7F]/g, '')).slice(0, COLS).join('');
}

/** Cadre à gauche sur 20 colonnes (tronque / complète d'espaces). */
export function fitLeft(text) {
    const c = clean(text);
    return c + ' '.repeat(COLS - Array.from(c).length);
}

/** Cadre à droite sur 20 colonnes (le total). */
export function fitRight(text) {
    const c = clean(text);
    return ' '.repeat(COLS - Array.from(c).length) + c;
}

/** « 1 234,50 EUR » — identique à CustomerDisplayService::money(). */
export function formatDisplayMoney(value) {
    const n = Number(value);
    const cents = Math.round((Number.isFinite(n) ? n : 0) * 100);
    const abs = Math.abs(cents);
    const euros = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const dec = String(abs % 100).padStart(2, '0');
    return (cents < 0 ? '-' : '') + euros + ',' + dec + ' EUR';
}

/** UTF-8 → octets CP858 (un octet par caractère ; inconnu → « ? »). */
export function encodeCp858(text) {
    const chars = Array.from(String(text ?? ''));
    const out = new Uint8Array(chars.length);
    chars.forEach((c, i) => {
        const code = c.codePointAt(0);
        out[i] = code < 0x80 ? code : (CP858_MAP.get(c) ?? 0x3f);
    });
    return out;
}

/**
 * Un écran complet. Volontairement SANS commande propre à une émulation : la SAGA PD220 peut
 * être réglée en CD5220 (ESC Q A/B) ou en EPSON (US $) et une commande de l'autre jeu
 * s'afficherait en caractères parasites. Les deux jeux partagent : ESC @ (init), ESC t n
 * (page de caractères), CLR 0x0C (efface + curseur en haut à gauche) et le mode
 * « écrasement » par défaut où la 21ᵉ colonne passe au début de la ligne du bas.
 * Un ESC t mal compris est de toute façon effacé par le CLR qui le suit.
 */
function frame(upper, lower, rightAlignLower) {
    const text =
        '\x1B\x40' + '\x1B\x74\x13' + '\x0C' +
        fitLeft(upper) + (rightAlignLower ? fitRight(lower) : fitLeft(lower));
    return encodeCp858(text);
}

/** Écran de vente : libellé en haut, total aligné à droite en bas. */
export function totalFrame(total, label = 'TOTAL') {
    return frame(label, formatDisplayMoney(total), true);
}

/** Écran de veille : nom du restaurant + message d'accueil. */
export function welcomeFrame(line1 = '', line2 = 'Soyez le bienvenu !') {
    return frame(line1, line2, false);
}

function readJson(storage, key) {
    try {
        const raw = storage && storage.getItem(key);
        return raw ? JSON.parse(raw) : null;
    } catch (_) {
        return null;
    }
}

function explain(err) {
    const name = err && err.name;
    const msg = String((err && err.message) || err || '');
    if (name === 'NetworkError' || /failed to open/i.test(msg)) {
        return 'Port de l’afficheur occupé : un autre logiciel l’utilise déjà. Fermez-le puis réessayez.';
    }
    if (name === 'SecurityError') {
        return 'Chrome refuse l’accès au port série sur cette page.';
    }
    return 'Afficheur injoignable : ' + (msg || 'erreur inconnue');
}

/**
 * Gestionnaire du port série. Dépendances injectables pour les tests.
 * @param {{serial?: any, storage?: Storage, welcome?: [string,string], label?: string}} opts
 */
export function createCustomerDisplay(opts = {}) {
    const serial = opts.serial;
    const storage = opts.storage;
    let welcome = opts.welcome || ['', 'Soyez le bienvenu !'];
    let label = opts.label || 'TOTAL';
    let port = null;
    let lastFrame = null;
    let queue = Promise.resolve();
    const listeners = new Set();
    let state = { status: serial ? 'disconnected' : 'unsupported', error: null };

    function setState(next) {
        state = { ...state, ...next };
        listeners.forEach((fn) => {
            try { fn(state); } catch (_) { /* un abonné ne casse pas l'afficheur */ }
        });
    }

    function baudRate() {
        const b = Number(storage && storage.getItem(BAUD_STORAGE_KEY));
        return Number.isFinite(b) && b > 0 ? b : 9600;
    }

    async function open(candidate) {
        try {
            await candidate.open({ baudRate: baudRate(), dataBits: 8, stopBits: 1, parity: 'none', flowControl: 'none' });
        } catch (err) {
            // Déjà ouvert par cette même page (retour sur la caisse) : le port est utilisable.
            if (!(err && err.name === 'InvalidStateError' && candidate.writable)) throw err;
        }
        port = candidate;
        try {
            const info = candidate.getInfo ? candidate.getInfo() : {};
            storage && storage.setItem(PORT_STORAGE_KEY, JSON.stringify({
                usbVendorId: info.usbVendorId ?? null,
                usbProductId: info.usbProductId ?? null,
            }));
        } catch (_) { /* mémorisation facultative */ }
        setState({ status: 'connected', error: null });
        await send(lastFrame || welcomeFrame(welcome[0], welcome[1]));
        return true;
    }

    function send(bytes) {
        lastFrame = bytes;
        const run = async () => {
            if (!port || !port.writable) return false;
            let writer = null;
            try {
                writer = port.writable.getWriter();
                await writer.write(bytes);
                return true;
            } catch (err) {
                setState({ status: 'error', error: explain(err) });
                return false;
            } finally {
                try { writer && writer.releaseLock(); } catch (_) { /* rien */ }
            }
        };
        queue = queue.then(run, run);
        return queue;
    }

    if (serial && typeof serial.addEventListener === 'function') {
        serial.addEventListener('disconnect', (ev) => {
            if (port && ev && ev.target === port) {
                port = null;
                setState({ status: 'disconnected', error: null });
            }
        });
    }

    return {
        isSupported: () => Boolean(serial),
        getState: () => state,
        onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },
        setWelcome(line1, line2) { welcome = [line1 || '', line2 || welcome[1]]; },
        setLabel(text) { if (text) label = text; },

        /** Reconnexion SANS geste : uniquement un port déjà autorisé dans ce Chrome. */
        async autoConnect() {
            if (!serial) return false;
            if (port) return true;
            try {
                const ports = await serial.getPorts();
                if (!ports || ports.length === 0) return false;
                const saved = readJson(storage, PORT_STORAGE_KEY);
                const match = saved && ports.find((p) => {
                    const i = p.getInfo ? p.getInfo() : {};
                    return (i.usbVendorId ?? null) === saved.usbVendorId && (i.usbProductId ?? null) === saved.usbProductId;
                });
                const chosen = match || (ports.length === 1 ? ports[0] : null);
                if (!chosen) return false;
                return await open(chosen);
            } catch (err) {
                setState({ status: 'error', error: explain(err) });
                return false;
            }
        },

        /** Appairage — DOIT être appelé depuis un clic (Chrome l'exige pour requestPort). */
        async connect() {
            if (!serial) return false;
            setState({ status: 'connecting', error: null });
            let chosen;
            try {
                chosen = await serial.requestPort();
            } catch (_) {
                // Fenêtre fermée sans choisir : pas une erreur, juste « pas branché ».
                setState({ status: port ? 'connected' : 'disconnected', error: null });
                return Boolean(port);
            }
            try {
                return await open(chosen);
            } catch (err) {
                port = null;
                setState({ status: 'error', error: explain(err) });
                return false;
            }
        },

        /** Total du panier (0 → écran d'accueil). */
        async showTotal(total) {
            const t = Number(total) || 0;
            const bytes = t > 0 ? totalFrame(t, label) : welcomeFrame(welcome[0], welcome[1]);
            if (!port) { lastFrame = bytes; return false; }
            return send(bytes);
        },

        async showWelcome() {
            return this.showTotal(0);
        },

        isConnected: () => Boolean(port),
    };
}

let singleton = null;

/** Instance unique pour la caisse : le port reste ouvert d'une page admin à l'autre. */
export function customerDisplay() {
    if (!singleton) {
        const serial = typeof navigator !== 'undefined' && navigator.serial ? navigator.serial : undefined;
        let storage;
        try { storage = window.localStorage; } catch (_) { storage = undefined; }
        singleton = createCustomerDisplay({ serial, storage });
    }
    return singleton;
}
