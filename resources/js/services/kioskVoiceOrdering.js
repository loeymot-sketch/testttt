/**
 * V2-4 Phase A — Voice Ordering service (Web Speech API wrapper).
 *
 * Greenfield. NON câblé dans `KioskAppComponent` / `KioskWizardComponent`
 * (frozen zones). Composant Vue standalone `KioskVoiceOrderingButton.vue`
 * peut être prévisualisé seul, mais ne sera pas embarqué dans le wizard
 * frozen tant qu'un ticket non-frozen n'aura pas déclenché l'intégration.
 *
 * Web Speech API (window.SpeechRecognition / webkitSpeechRecognition) —
 *   STT (Speech-to-Text). Complémentaire à `useKioskSpeech.js` (TTS,
 *   Text-to-Speech, output audio).
 *
 * Limites V1.x (cf. plan §2.4) :
 *   - Chrome/Edge majoritairement, Safari partiel
 *   - HTTPS strict
 *   - Permission micro requise
 *   - Précision dégradée en bruit ambiant
 *
 * Voir plans/PLAN_DESIGN_V2_4_VOICE_ORDERING_2026-05-08.md.
 */

const SUPPORTED_LANGUAGES = ['fr-FR', 'en-US', 'ar-SA'];

/**
 * Wrapper testable. Exporté nommé pour tests unitaires (mocks de
 * window.SpeechRecognition), et instancié en singleton (export default)
 * pour usage runtime.
 */
export class VoiceOrderingService {
    constructor(options = {}) {
        const lang = options.lang || 'fr-FR';
        this.lang = SUPPORTED_LANGUAGES.includes(lang) ? lang : 'fr-FR';
        this.recognition = null;
        this.isListening = false;
        this._listeners = new Map();
    }

    /**
     * Détection support browser (Web Speech API).
     * @returns {boolean}
     */
    isSupported() {
        if (typeof window === 'undefined') return false;
        return (
            typeof window.SpeechRecognition !== 'undefined' ||
            typeof window.webkitSpeechRecognition !== 'undefined'
        );
    }

    /**
     * Initialise l'instance SpeechRecognition + handlers.
     * Idempotent — appelable plusieurs fois sans effet secondaire.
     * @returns {boolean}  true si initialisé, false si non supporté
     */
    initialize() {
        if (!this.isSupported()) return false;
        if (this.recognition) return true; // déjà init

        const SpeechRecognitionCtor =
            window.SpeechRecognition || window.webkitSpeechRecognition;
        this.recognition = new SpeechRecognitionCtor();
        this.recognition.continuous = false;
        this.recognition.interimResults = true;
        this.recognition.lang = this.lang;

        this.recognition.onresult = (event) => {
            const results = event && event.results ? event.results : [];
            const transcript = Array.from(results)
                .map((r) => (r && r[0] ? r[0].transcript : ''))
                .join('');
            const isFinal = results.length > 0 ? !!results[0].isFinal : false;
            this._emit('result', { transcript, isFinal });
        };

        this.recognition.onerror = (event) => {
            const errorCode = event && event.error ? event.error : 'unknown_error';
            this._emit('error', { error: errorCode });
        };

        this.recognition.onend = () => {
            this.isListening = false;
            this._emit('end');
        };

        return true;
    }

    /**
     * Démarre la reconnaissance vocale. Initialise si pas déjà fait.
     * @returns {boolean}  true si démarré, false sinon
     */
    start() {
        if (!this.recognition && !this.initialize()) return false;
        if (this.isListening) return false;
        try {
            this.recognition.start();
        } catch (err) {
            // Web Speech peut throw si déjà actif sur certains browsers.
            this._emit('error', { error: 'start_failed' });
            return false;
        }
        this.isListening = true;
        this._emit('start');
        return true;
    }

    /**
     * Arrête la reconnaissance.
     * @returns {boolean}
     */
    stop() {
        if (!this.recognition || !this.isListening) return false;
        try {
            this.recognition.stop();
        } catch (err) {
            // ignore
        }
        return true;
    }

    /**
     * Change la langue (met à jour si SpeechRecognition initialisée).
     * @param {string} lang
     */
    setLanguage(lang) {
        if (!SUPPORTED_LANGUAGES.includes(lang)) return false;
        this.lang = lang;
        if (this.recognition) {
            this.recognition.lang = lang;
        }
        return true;
    }

    /**
     * Enregistre un handler pour un événement.
     * Évents émis : 'start' | 'result' | 'end' | 'error'.
     * @param {string} event
     * @param {Function} handler
     */
    on(event, handler) {
        if (typeof handler !== 'function') return;
        if (!this._listeners.has(event)) {
            this._listeners.set(event, []);
        }
        this._listeners.get(event).push(handler);
    }

    /**
     * Désenregistre un handler.
     * @param {string} event
     * @param {Function} handler
     */
    off(event, handler) {
        const handlers = this._listeners.get(event);
        if (!handlers) return;
        const idx = handlers.indexOf(handler);
        if (idx > -1) handlers.splice(idx, 1);
    }

    /**
     * Vide tous les handlers — utile pour tests.
     */
    reset() {
        this._listeners.clear();
        if (this.isListening) this.stop();
        this.recognition = null;
        this.isListening = false;
    }

    _emit(event, data) {
        const handlers = this._listeners.get(event) || [];
        handlers.forEach((h) => {
            try {
                h(data);
            } catch (e) {
                // un handler buggué ne doit pas faire crasher les autres
                // eslint-disable-next-line no-console
                console.warn('[VoiceOrdering] handler error', e);
            }
        });
    }
}

// Singleton runtime (utilisé par le composant Vue par défaut).
const voiceService = new VoiceOrderingService();
export default voiceService;
export { SUPPORTED_LANGUAGES };
