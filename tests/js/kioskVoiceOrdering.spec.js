/**
 * V2-4 Phase A — kioskVoiceOrdering service + button component.
 *
 * Vérifie (happy-dom, mock window.SpeechRecognition) :
 *   - isSupported() détection feature
 *   - initialize() / start() / stop() lifecycle
 *   - événements result/start/end/error
 *   - on() / off() registration
 *   - composant KioskVoiceOrderingButton : disabled si non supporté,
 *     toggle, transcript display, émission voice-input
 *
 * Mirror du pattern `kioskSpeechComposable.spec.js`.
 *
 * Voir plans/PLAN_DESIGN_V2_4_VOICE_ORDERING_2026-05-08.md.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

import { VoiceOrderingService, SUPPORTED_LANGUAGES } from '../../resources/js/services/kioskVoiceOrdering';
import KioskVoiceOrderingButton from '../../resources/js/components/frontend/kiosk/KioskVoiceOrderingButton.vue';

let originalSpeechRecognition;
let originalWebkitSpeechRecognition;
let lastInstance;
let startSpy;
let stopSpy;

function makeMockRecognition() {
    const inst = {
        continuous: false,
        interimResults: false,
        lang: 'fr-FR',
        onresult: null,
        onerror: null,
        onend: null,
        start: vi.fn(),
        stop: vi.fn(),
    };
    startSpy = inst.start;
    stopSpy = inst.stop;
    lastInstance = inst;
    return inst;
}

function installSpeechRecognitionMock() {
    window.SpeechRecognition = vi.fn(() => makeMockRecognition());
    delete window.webkitSpeechRecognition;
}

function uninstallSpeechRecognitionMock() {
    delete window.SpeechRecognition;
    delete window.webkitSpeechRecognition;
}

describe('VoiceOrderingService', () => {
    beforeEach(() => {
        originalSpeechRecognition = window.SpeechRecognition;
        originalWebkitSpeechRecognition = window.webkitSpeechRecognition;
        lastInstance = null;
        startSpy = null;
        stopSpy = null;
    });

    afterEach(() => {
        uninstallSpeechRecognitionMock();
        if (typeof originalSpeechRecognition !== 'undefined') {
            window.SpeechRecognition = originalSpeechRecognition;
        }
        if (typeof originalWebkitSpeechRecognition !== 'undefined') {
            window.webkitSpeechRecognition = originalWebkitSpeechRecognition;
        }
    });

    it('exporte la liste des langues supportées', () => {
        expect(Array.isArray(SUPPORTED_LANGUAGES)).toBe(true);
        expect(SUPPORTED_LANGUAGES).toContain('fr-FR');
    });

    it('isSupported() retourne false quand SpeechRecognition est absent', () => {
        uninstallSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        expect(svc.isSupported()).toBe(false);
    });

    it('isSupported() retourne true quand window.SpeechRecognition existe', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        expect(svc.isSupported()).toBe(true);
    });

    it('isSupported() retourne true via webkitSpeechRecognition (fallback)', () => {
        delete window.SpeechRecognition;
        window.webkitSpeechRecognition = vi.fn(() => makeMockRecognition());
        const svc = new VoiceOrderingService();
        expect(svc.isSupported()).toBe(true);
        delete window.webkitSpeechRecognition;
    });

    it('initialize() configure recognition et est idempotent', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService({ lang: 'fr-FR' });
        expect(svc.initialize()).toBe(true);
        expect(svc.recognition).not.toBeNull();
        expect(svc.recognition.lang).toBe('fr-FR');
        expect(svc.recognition.interimResults).toBe(true);

        // 2e appel : idempotent.
        const refBefore = svc.recognition;
        expect(svc.initialize()).toBe(true);
        expect(svc.recognition).toBe(refBefore);
    });

    it('initialize() retourne false si non supporté', () => {
        uninstallSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        expect(svc.initialize()).toBe(false);
    });

    it('start() appelle recognition.start et émet événement start', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        const onStart = vi.fn();
        svc.on('start', onStart);

        const ok = svc.start();
        expect(ok).toBe(true);
        expect(svc.isListening).toBe(true);
        expect(startSpy).toHaveBeenCalledTimes(1);
        expect(onStart).toHaveBeenCalledTimes(1);
    });

    it('start() ignore appel si déjà en écoute', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        svc.start();
        expect(svc.start()).toBe(false);
        expect(startSpy).toHaveBeenCalledTimes(1);
    });

    it('stop() appelle recognition.stop', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        svc.start();
        const ok = svc.stop();
        expect(ok).toBe(true);
        expect(stopSpy).toHaveBeenCalledTimes(1);
    });

    it('result event émet transcript + isFinal', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        svc.initialize();
        const onResult = vi.fn();
        svc.on('result', onResult);

        const event = {
            results: [
                Object.assign(
                    [{ transcript: 'ajouter un burger' }],
                    { isFinal: true }
                ),
            ],
        };
        svc.recognition.onresult(event);

        expect(onResult).toHaveBeenCalledTimes(1);
        const payload = onResult.mock.calls[0][0];
        expect(payload.transcript).toBe('ajouter un burger');
        expect(payload.isFinal).toBe(true);
    });

    it('error event émet code erreur', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        svc.initialize();
        const onError = vi.fn();
        svc.on('error', onError);

        svc.recognition.onerror({ error: 'no-speech' });
        expect(onError).toHaveBeenCalledWith({ error: 'no-speech' });
    });

    it('end event reset isListening', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        svc.start();
        const onEnd = vi.fn();
        svc.on('end', onEnd);

        svc.recognition.onend();
        expect(svc.isListening).toBe(false);
        expect(onEnd).toHaveBeenCalledTimes(1);
    });

    it('on() / off() enregistrent et retirent handlers', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        svc.initialize();

        const handler = vi.fn();
        svc.on('result', handler);
        svc.recognition.onresult({ results: [Object.assign([{ transcript: 'a' }], { isFinal: false })] });
        expect(handler).toHaveBeenCalledTimes(1);

        svc.off('result', handler);
        svc.recognition.onresult({ results: [Object.assign([{ transcript: 'b' }], { isFinal: false })] });
        expect(handler).toHaveBeenCalledTimes(1); // pas appelé une 2e fois
    });

    it('setLanguage() met à jour la langue si valide', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        svc.initialize();
        expect(svc.setLanguage('en-US')).toBe(true);
        expect(svc.lang).toBe('en-US');
        expect(svc.recognition.lang).toBe('en-US');

        expect(svc.setLanguage('zz-ZZ')).toBe(false);
        expect(svc.lang).toBe('en-US'); // inchangé
    });

    it('reset() vide handlers et stoppe écoute', () => {
        installSpeechRecognitionMock();
        const svc = new VoiceOrderingService();
        svc.start();
        const handler = vi.fn();
        svc.on('result', handler);

        svc.reset();
        expect(svc.isListening).toBe(false);
        expect(svc.recognition).toBeNull();
        // Émettre maintenant ne doit toucher aucun handler.
        svc._emit('result', { transcript: 'x', isFinal: true });
        expect(handler).not.toHaveBeenCalled();
    });
});

describe('KioskVoiceOrderingButton component', () => {
    beforeEach(() => {
        originalSpeechRecognition = window.SpeechRecognition;
        originalWebkitSpeechRecognition = window.webkitSpeechRecognition;
    });

    afterEach(() => {
        uninstallSpeechRecognitionMock();
        if (typeof originalSpeechRecognition !== 'undefined') {
            window.SpeechRecognition = originalSpeechRecognition;
        }
        if (typeof originalWebkitSpeechRecognition !== 'undefined') {
            window.webkitSpeechRecognition = originalWebkitSpeechRecognition;
        }
    });

    function makeFakeService(isSupported = true) {
        const handlers = new Map();
        return {
            isSupported: () => isSupported,
            initialize: vi.fn(() => isSupported),
            start: vi.fn(),
            stop: vi.fn(),
            setLanguage: vi.fn(),
            on(event, h) {
                if (!handlers.has(event)) handlers.set(event, []);
                handlers.get(event).push(h);
            },
            off(event, h) {
                const arr = handlers.get(event);
                if (!arr) return;
                const i = arr.indexOf(h);
                if (i > -1) arr.splice(i, 1);
            },
            __emit(event, data) {
                (handlers.get(event) || []).forEach((h) => h(data));
            },
        };
    }

    it('rend le bouton désactivé si Web Speech non supporté', () => {
        const svc = makeFakeService(false);
        const wrapper = mount(KioskVoiceOrderingButton, {
            props: { service: svc },
        });
        const btn = wrapper.find('.kiosk-voice-button');
        expect(btn.exists()).toBe(true);
        expect(btn.attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="kiosk-voice-unsupported"]').exists()).toBe(true);
    });

    it('toggle déclenche start puis stop', async () => {
        const svc = makeFakeService(true);
        const wrapper = mount(KioskVoiceOrderingButton, {
            props: { service: svc },
        });
        // Attente que mounted() ait flippé isSupported puis attaché les handlers.
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.isSupported).toBe(true);

        await wrapper.find('.kiosk-voice-button').trigger('click');
        expect(svc.start).toHaveBeenCalledTimes(1);

        // Simuler "start" event reçu du service.
        svc.__emit('start');
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.isListening).toBe(true);

        await wrapper.find('.kiosk-voice-button').trigger('click');
        expect(svc.stop).toHaveBeenCalledTimes(1);
    });

    it('affiche le transcript intermédiaire et émet voice-input au final', async () => {
        const svc = makeFakeService(true);
        const wrapper = mount(KioskVoiceOrderingButton, {
            props: { service: svc },
        });
        svc.__emit('result', { transcript: 'ajout', isFinal: false });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="kiosk-voice-transcript"]').text()).toContain('ajout');
        expect(wrapper.emitted('voice-input')).toBeUndefined();

        svc.__emit('result', { transcript: 'ajouter un burger', isFinal: true });
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('voice-input')).toBeTruthy();
        expect(wrapper.emitted('voice-input')[0]).toEqual(['ajouter un burger']);
    });

    it('émet voice-error quand le service échoue', async () => {
        const svc = makeFakeService(true);
        const wrapper = mount(KioskVoiceOrderingButton, {
            props: { service: svc },
        });
        svc.__emit('error', { error: 'no-speech' });
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('voice-error')).toBeTruthy();
        expect(wrapper.emitted('voice-error')[0]).toEqual(['no-speech']);
        expect(wrapper.vm.isListening).toBe(false);
    });
});
