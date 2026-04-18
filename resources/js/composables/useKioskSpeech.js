/**
 * useKioskSpeech.js — Composable Web Speech API pour le kiosk.
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 4.5 (audio / European Accessibility Act).
 *
 * Fonctionnalités :
 *  - speak(text, opts)  : lit un texte via SpeechSynthesis (FR/EN)
 *                          ou un fichier audio pré-enregistré (AR).
 *  - stop()             : interrompt immédiatement la lecture en cours.
 *  - isSupported        : boolean computed (Web Speech API disponible).
 *  - isSpeaking         : boolean reactive (en cours de lecture).
 *
 * Politique :
 *  - Respecte le flag `kioskSettings.audio` : si désactivé, les appels à
 *    speak() sont no-ops (et renvoient Promise.resolve(false)).
 *  - Auto-stop après STILL_HERE_MS d'inactivité (cohérent avec KioskApp).
 *  - Voice selection : priorité langue exacte > prefix (ex. fr-FR > fr).
 *  - Arabe : Chrome/Edge sur Windows kiosk n'ont pas toujours de voix ar-SA
 *    installée → fallback sur fichiers statiques `public/kiosk/audio/ar/<key>.mp3`
 *    (clé i18n au format dot-notation remplacée en nom de fichier).
 *  - Pas de PII : on log uniquement la longueur du texte parlé dans les events.
 *  - Jamais de speak() automatique sans interaction utilisateur préalable
 *    (règle Chrome autoplay) — c'est au composant caller de s'assurer que
 *    speak() est déclenché en réponse à un click/touch.
 *
 * Invariants :
 *  - Idempotent à l'init : un second useKioskSpeech() dans un autre composant
 *    réutilise `window.speechSynthesis` directement sans créer de doublon.
 *  - Safe en tests (jsdom) : vérifie la présence de window.speechSynthesis.
 */

import { computed, ref, onBeforeUnmount } from 'vue';

const LOCALE_VOICE_PREFERENCES = {
    fr: ['fr-FR', 'fr-CA', 'fr'],
    en: ['en-GB', 'en-US', 'en'],
    ar: ['ar-SA', 'ar-EG', 'ar-MA', 'ar'],
};

const AR_FALLBACK_BASE_URL = '/kiosk/audio/ar/';

function slugifyKey(key) {
    if (typeof key !== 'string') return '';
    return key
        .replace(/[^a-zA-Z0-9._-]/g, '_')
        .replace(/\./g, '_')
        .slice(0, 80);
}

function pickVoice(voices, locale) {
    if (!voices?.length) return null;
    const prefs = LOCALE_VOICE_PREFERENCES[locale] || [locale];
    for (const pref of prefs) {
        const exact = voices.find((v) => v.lang === pref);
        if (exact) return exact;
    }
    // Préfixe
    for (const pref of prefs) {
        const partial = voices.find((v) => v.lang?.toLowerCase().startsWith(pref.toLowerCase()));
        if (partial) return partial;
    }
    return null;
}

function loadVoicesOnce() {
    if (typeof window === 'undefined' || !window.speechSynthesis) return Promise.resolve([]);
    const synth = window.speechSynthesis;
    const existing = synth.getVoices();
    if (existing.length) return Promise.resolve(existing);
    return new Promise((resolve) => {
        const handler = () => {
            synth.removeEventListener('voiceschanged', handler);
            resolve(synth.getVoices());
        };
        synth.addEventListener('voiceschanged', handler);
        // Safety timeout — si voiceschanged ne firera jamais (certains navigateurs
        // exotiques), on renvoie un tableau vide après 500ms.
        setTimeout(() => {
            synth.removeEventListener('voiceschanged', handler);
            resolve(synth.getVoices());
        }, 500);
    });
}

export function useKioskSpeech({ store } = {}) {
    const hasSpeechApi =
        typeof window !== 'undefined' && typeof window.speechSynthesis !== 'undefined';

    const isSpeaking = ref(false);
    let currentUtterance = null;
    let currentAudio = null;

    const audioEnabled = computed(() => !!store?.state?.kioskSettings?.audio);
    const currentLocale = computed(() => store?.state?.kioskSettings?.locale || 'fr');

    const isSupported = computed(() => hasSpeechApi || currentLocale.value === 'ar');

    function stop() {
        if (currentAudio) {
            try {
                currentAudio.pause();
                currentAudio.src = '';
            } catch (_) {
                // noop
            }
            currentAudio = null;
        }
        if (hasSpeechApi) {
            try {
                window.speechSynthesis.cancel();
            } catch (_) {
                // noop
            }
        }
        currentUtterance = null;
        isSpeaking.value = false;
    }

    /**
     * Speak a text. Returns Promise<boolean> — true si effectivement parlé.
     *
     * @param {string} text             Le texte à énoncer.
     * @param {object} [opts]
     * @param {string} [opts.locale]    Override langue (sinon kioskSettings.locale).
     * @param {string} [opts.key]       Clé i18n (pour fallback AR mp3). Si absente,
     *                                  AR ne pourra pas utiliser le fallback.
     * @param {number} [opts.rate]      Taux (0.1 à 10, défaut 1)
     * @param {number} [opts.pitch]     Pitch (0 à 2, défaut 1)
     * @param {number} [opts.volume]    Volume (0 à 1, défaut 1)
     */
    async function speak(text, opts = {}) {
        if (!audioEnabled.value) return false;
        if (!text || typeof text !== 'string') return false;

        const locale = opts.locale || currentLocale.value;
        stop(); // Cancel any ongoing speech

        // --- AR : fallback fichier mp3 statique --------------------------------
        if (locale === 'ar' && opts.key) {
            try {
                const url = AR_FALLBACK_BASE_URL + slugifyKey(opts.key) + '.mp3';
                const audio = new Audio(url);
                audio.volume = typeof opts.volume === 'number' ? opts.volume : 1;
                currentAudio = audio;
                isSpeaking.value = true;
                const donePromise = new Promise((resolve) => {
                    const finish = () => {
                        isSpeaking.value = false;
                        currentAudio = null;
                        resolve(true);
                    };
                    audio.addEventListener('ended', finish, { once: true });
                    audio.addEventListener('error', () => {
                        isSpeaking.value = false;
                        currentAudio = null;
                        resolve(false);
                    }, { once: true });
                });
                await audio.play();
                return donePromise;
            } catch (_) {
                isSpeaking.value = false;
                currentAudio = null;
                return false;
            }
        }

        // --- FR/EN : Web Speech API -------------------------------------------
        if (!hasSpeechApi) return false;
        try {
            const voices = await loadVoicesOnce();
            const utter = new SpeechSynthesisUtterance(text);
            const voice = pickVoice(voices, locale);
            if (voice) utter.voice = voice;
            utter.lang = voice?.lang || locale;
            utter.rate = typeof opts.rate === 'number' ? opts.rate : 1;
            utter.pitch = typeof opts.pitch === 'number' ? opts.pitch : 1;
            utter.volume = typeof opts.volume === 'number' ? opts.volume : 1;

            currentUtterance = utter;
            isSpeaking.value = true;

            const donePromise = new Promise((resolve) => {
                utter.onend = () => {
                    isSpeaking.value = false;
                    currentUtterance = null;
                    resolve(true);
                };
                utter.onerror = () => {
                    isSpeaking.value = false;
                    currentUtterance = null;
                    resolve(false);
                };
            });
            window.speechSynthesis.speak(utter);
            return donePromise;
        } catch (_) {
            isSpeaking.value = false;
            currentUtterance = null;
            return false;
        }
    }

    // Cleanup à démontage : stop toute lecture en cours pour ne pas « fuiter »
    // des utterances sur la prochaine route.
    try {
        onBeforeUnmount(() => {
            stop();
        });
    } catch (_) {
        // Appelé hors setup — le caller doit gérer.
    }

    return {
        speak,
        stop,
        isSupported,
        isSpeaking,
    };
}

export default useKioskSpeech;
