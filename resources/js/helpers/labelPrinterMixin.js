/**
 * labelPrinterMixin.js — [ETIQUETTES 2026-10-16] Ce que partagent les trois écrans d'étiquettes : l'état
 * de l'imprimante Bluetooth (réactif), les réglages de la tablette, la reconnexion automatique.
 */
import { getMedia, mediaMismatchMessage } from './brotherQl.js';
import { labelPrinter, loadSettings, saveSettings } from './labelPrinter.js';

function safeStorage() {
    try { return window.localStorage; } catch (_) { return undefined; }
}

/** Version majeure de Chrome d'après le user‑agent, ou null. */
export function chromeMajor(ua) {
    const m = /(?:Chrome|CriOS)\/(\d+)/.exec(String(ua || ""));
    return m ? Number(m[1]) : null;
}

/**
 * Traduit l'état brut du gestionnaire en un verdict affichable.
 * @returns {{level:'ok'|'warn'|'error'|'info', title:string, detail:string, ready:boolean}}
 */
export function describePrinter(state, settings, supported) {
    const media = getMedia(settings.mediaId);
    if (!supported) {
        return {
            level: 'error', ready: false,
            title: 'Impression Bluetooth indisponible sur ce navigateur',
            detail: 'Ouvrez cette page avec Chrome sur Android (version 138 ou plus récente). Voir « Imprimante ».',
        };
    }
    if (state.status === 'connecting') {
        return { level: 'info', ready: false, title: 'Connexion à l’imprimante…', detail: 'Patientez quelques secondes.' };
    }
    if (state.status === 'disconnected') {
        return {
            level: 'warn', ready: false,
            title: 'Imprimante non connectée',
            detail: state.error || 'Allumez l’imprimante puis appuyez sur « Connecter ».',
        };
    }
    if (state.status === 'busy') {
        return { level: 'info', ready: false, title: 'Impression en cours…', detail: '' };
    }
    const status = state.printer;
    if (!status) {
        return { level: 'info', ready: true, title: 'Imprimante connectée', detail: 'Lecture de l’état…' };
    }
    const blocking = status.errors.find((e) => e.blocking);
    if (blocking) return { level: 'error', ready: false, title: 'Imprimante en défaut', detail: blocking.message };
    const mismatch = mediaMismatchMessage(status, media);
    if (mismatch) return { level: 'error', ready: false, title: 'Mauvais rouleau', detail: mismatch };
    return {
        level: 'ok', ready: true,
        title: 'Imprimante prête',
        detail: `${status.model !== 'inconnu' ? status.model : 'Brother QL'} · ${media.label}`,
    };
}

export default {
    data() {
        const printer = labelPrinter();
        return {
            printerState: printer.getState(),
            settings: loadSettings(safeStorage()),
        };
    },
    computed: {
        printerSupported() { return labelPrinter().isSupported(); },
        printerInfo() { return describePrinter(this.printerState, this.settings, this.printerSupported); },
        printerReady() { return this.printerInfo.ready && this.printerState.status === 'connected'; },
        labelMedia() { return getMedia(this.settings.mediaId); },
    },
    created() {
        const printer = labelPrinter();
        this._labelUnsub = printer.onChange((s) => { this.printerState = s; });
        this.printerState = printer.getState();
        if (printer.isSupported()) {
            printer.startKeepAlive();
        }
    },
    beforeUnmount() {
        if (this._labelUnsub) this._labelUnsub();
        labelPrinter().stopKeepAlive();
    },
    methods: {
        printerInstance() { return labelPrinter(); },
        updateSettings(patch) {
            this.settings = { ...this.settings, ...patch };
            saveSettings(safeStorage(), this.settings);
        },
        async connectPrinter() { return labelPrinter().connect(); },
        async disconnectPrinter() { return labelPrinter().disconnect(); },
    },
};
