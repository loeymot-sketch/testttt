/**
 * labelPrinterMixin.js — [ETIQUETTES 2026-10-16] Ce que partagent les trois écrans d'étiquettes : l'état
 * de l'imprimante Bluetooth (réactif), les réglages de la tablette, la reconnexion automatique.
 */
import { getMedia, mediaFromStatus, mediaMismatchMessage } from './brotherQl.js';
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
        if (state.noDevice && !state.error) {
            return {
                level: 'warn', ready: false,
                title: 'Imprimante non trouvée par Chrome',
                detail: 'Aucune imprimante choisie. Si Chrome a affiché « Aucun appareil compatible détecté » : '
                    + '1) allumez l’imprimante, Bluetooth activé et « visible » ; '
                    + '2) dans Android › Bluetooth, « Supprimer » QL-820NWB puis appairez‑la de nouveau (l’appairage doit se faire imprimante allumée à côté) ; '
                    + '3) vérifiez que Chrome est en version 138 ou plus (Réglages › Imprimante › Diagnostic) ; '
                    + '4) rappuyez sur « Connecter ».',
            };
        }
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
    if (blocking) {
        // Le titre dit déjà la panne : le détail ne la répète pas (« Capot ouvert » / « Fermez le capot… »).
        const lead = blocking.short ? `${blocking.short} : ` : '';
        const detail = lead && blocking.message.startsWith(lead)
            ? blocking.message.slice(lead.length).replace(/^./, (c) => c.toUpperCase())
            : blocking.message;
        return { level: 'error', ready: false, title: blocking.short || 'Imprimante en défaut', detail };
    }
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
        /** Rouleau chargé reconnu ET différent du réglage : proposé en un appui. */
        detectedMedia() {
            const status = this.printerState.printer;
            // Jamais proposé pendant une PANNE (capot ouvert…) : le vrai problème n'est pas le format, et un appui
            // réécrirait le réglage sans rien réparer.
            if (!status || status.hasError) return null;
            const found = mediaFromStatus(status);
            return found && found.id !== this.settings.mediaId ? found : null;
        },
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
        adoptDetectedMedia() {
            if (this.detectedMedia) this.updateSettings({ mediaId: this.detectedMedia.id });
        },
        async connectPrinter() { return labelPrinter().connect(); },
        async disconnectPrinter() { return labelPrinter().disconnect(); },
    },
};
