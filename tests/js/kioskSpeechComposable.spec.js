/**
 * Kiosk Design V1 — Phase 4.5 : useKioskSpeech composable
 *
 * Vérifie (jsdom, pas de vraie Web Speech API) :
 *  - audio désactivé → speak() renvoie false sans action
 *  - audio activé + pas de SpeechSynthesis → renvoie false gracieusement
 *  - Mock SpeechSynthesis → speak() déclenche .speak(utter) et résout true
 *  - stop() cancel le SpeechSynthesis en cours
 *  - AR + key → fallback Audio .mp3 (classe Audio mockée)
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createStore } from 'vuex';
import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';

import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import { useKioskSpeech } from '../../resources/js/composables/useKioskSpeech';

function makeStore() {
    return createStore({ modules: { kioskSettings } });
}

let originalSpeechSynthesis;
let originalUtterance;
let speakSpy;
let cancelSpy;

function mockSpeechApi({ voices = [] } = {}) {
    speakSpy = vi.fn((utter) => {
        // Déclenche onend async
        setTimeout(() => utter.onend && utter.onend({}), 0);
    });
    cancelSpy = vi.fn();
    window.speechSynthesis = {
        getVoices: () => voices,
        speak: speakSpy,
        cancel: cancelSpy,
        addEventListener: () => {},
        removeEventListener: () => {},
    };
    window.SpeechSynthesisUtterance = function (t) {
        this.text = t;
        this.lang = 'fr';
        this.voice = null;
        this.rate = 1; this.pitch = 1; this.volume = 1;
        this.onend = null; this.onerror = null;
    };
}

describe('useKioskSpeech composable', () => {
    beforeEach(() => {
        originalSpeechSynthesis = window.speechSynthesis;
        originalUtterance = window.SpeechSynthesisUtterance;
    });
    afterEach(() => {
        window.speechSynthesis = originalSpeechSynthesis;
        window.SpeechSynthesisUtterance = originalUtterance;
    });

    it('speak() no-op quand audio désactivé', async () => {
        mockSpeechApi();
        const store = makeStore();
        let api;
        mount(defineComponent({
            setup() { api = useKioskSpeech({ store }); return () => h('div'); },
        }));
        const ok = await api.speak('Bonjour');
        expect(ok).toBe(false);
        expect(speakSpy).not.toHaveBeenCalled();
    });

    it('speak() appelle speechSynthesis.speak quand audio activé', async () => {
        mockSpeechApi({ voices: [{ lang: 'fr-FR', name: 'Amelie' }] });
        const store = makeStore();
        await store.dispatch('kioskSettings/setAudio', true);
        let api;
        mount(defineComponent({
            setup() { api = useKioskSpeech({ store }); return () => h('div'); },
        }));
        const ok = await api.speak('Bonjour');
        expect(speakSpy).toHaveBeenCalledTimes(1);
        expect(ok).toBe(true);
    });

    it('speak() sélectionne voix fr-FR si disponible', async () => {
        mockSpeechApi({ voices: [
            { lang: 'en-US', name: 'Mary' },
            { lang: 'fr-FR', name: 'Amelie' },
        ] });
        const store = makeStore();
        await store.dispatch('kioskSettings/setAudio', true);
        let api;
        mount(defineComponent({
            setup() { api = useKioskSpeech({ store }); return () => h('div'); },
        }));
        await api.speak('Bonjour');
        const utter = speakSpy.mock.calls[0][0];
        expect(utter.voice?.lang).toBe('fr-FR');
    });

    it('stop() appelle speechSynthesis.cancel', async () => {
        mockSpeechApi();
        const store = makeStore();
        let api;
        mount(defineComponent({
            setup() { api = useKioskSpeech({ store }); return () => h('div'); },
        }));
        api.stop();
        expect(cancelSpy).toHaveBeenCalled();
    });

    it('AR + key → tente Audio fallback (mp3)', async () => {
        mockSpeechApi();
        const playSpy = vi.fn(() => Promise.resolve());
        const audioInstances = [];
        const OriginalAudio = window.Audio;
        window.Audio = class MockAudio {
            constructor(url) {
                this.src = url; this.volume = 1;
                this.play = playSpy;
                this._listeners = {};
                audioInstances.push(this);
                // Simule un ended immédiat pour résoudre la Promise
                setTimeout(() => {
                    (this._listeners.ended || []).forEach((fn) => fn());
                }, 0);
            }
            addEventListener(event, cb) {
                (this._listeners[event] = this._listeners[event] || []).push(cb);
            }
            removeEventListener() {}
        };
        const store = makeStore();
        await store.dispatch('kioskSettings/setLocale', 'ar');
        await store.dispatch('kioskSettings/setAudio', true);
        let api;
        mount(defineComponent({
            setup() { api = useKioskSpeech({ store }); return () => h('div'); },
        }));
        const ok = await api.speak('مرحبا', { key: 'kiosk.a11y.title' });
        expect(playSpy).toHaveBeenCalled();
        expect(audioInstances[0].src).toContain('/kiosk/audio/ar/');
        expect(ok).toBe(true);
        window.Audio = OriginalAudio;
    });
});
