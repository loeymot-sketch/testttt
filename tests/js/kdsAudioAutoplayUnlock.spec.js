import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [F1 2026-07-24] Son « nouvelle commande » KDS bloqué par autoplay au boot.
 * Source: reports/goal-global-validation-2026-07-24/ACCES-cuisine-mobile-findings.md
 *
 * Une tablette KDS qui auto-charge /kds n'a reçu AUCUN geste utilisateur → le navigateur
 * rejette HTMLMediaElement.play() → le .catch avalait l'échec en silence → le carillon de
 * la 1re commande ne sonnait pas. Le heal :
 *   - amorce l'audio au 1er geste (pointerdown/touchstart/keydown) : play muted puis pause,
 *   - si play() est encore rejeté ET tant que non débloqué → bandeau + navigator.vibrate,
 *   - le bandeau disparaît au 1er geste (qui débloque),
 *   - le déclencheur ID-diff du chime N'EST PAS touché ; le son n'est PAS doublé.
 */

import KDS from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';

const unlock = KDS.methods._unlockKdsAudio;
const play = KDS.methods.playKdsNewOrderSound;
const setup = KDS.methods._setupKdsAudioUnlockListeners;
// [OWNER 2026-08-19] `playKdsNewOrderSound` ne joue plus lui-même : il cadence « 3 sonneries
// espacées » et délègue CHAQUE sonnerie à `_emettreCarillonKds`. C'est cette émission qui
// porte le repli autoplay (bandeau + vibreur) vérifié ici — le contexte doit donc la fournir.
const emettre = KDS.methods._emettreCarillonKds;
const teardown = KDS.methods._teardownKdsAudioUnlockListeners;

const flush = () => new Promise((r) => setTimeout(r, 0));

const audioEl = ({ reject = false } = {}) => ({
    muted: false,
    volume: 0,
    currentTime: 999,
    play: vi.fn(() => (reject ? Promise.reject(new Error('NotAllowedError')) : Promise.resolve())),
    pause: vi.fn(),
});

const ctx = (overrides = {}) => ({
    soundEnabled: true,
    soundVolume: 80,
    kdsAudioUnlocked: false,
    kdsAudioBlockedHint: false,
    _kdsLastNewOrderSoundAt: 0,
    _kdsAudioUnlockBound: false,
    _kdsAudioUnlockHandler: null,
    $refs: {},
    _unlockKdsAudio: unlock,
    _emettreCarillonKds: emettre,
    _setupKdsAudioUnlockListeners: setup,
    _teardownKdsAudioUnlockListeners: teardown,
    ...overrides,
});

describe('F1 — KDS audio autoplay unlock on first gesture', () => {
    let vibrateSpy;
    beforeEach(() => {
        vibrateSpy = vi.fn();
        Object.defineProperty(global.navigator, 'vibrate', { value: vibrateSpy, configurable: true });
    });
    afterEach(() => { vi.restoreAllMocks(); });

    it('primes the audio element (muted play) on gesture and marks it unlocked', async () => {
        const el = audioEl();
        const c = ctx({ $refs: { kdsNewOrderAudio: el }, kdsAudioBlockedHint: true });
        unlock.call(c);
        expect(c.kdsAudioUnlocked).toBe(true);
        expect(c.kdsAudioBlockedHint).toBe(false); // hint cleared by the gesture
        expect(el.play).toHaveBeenCalledTimes(1);
        await flush();
        expect(el.pause).toHaveBeenCalled(); // primed then paused → no audible double
    });

    it('a second gesture is a no-op (idempotent — never double-plays)', () => {
        const el = audioEl();
        const c = ctx({ $refs: { kdsNewOrderAudio: el }, kdsAudioUnlocked: true });
        unlock.call(c);
        expect(el.play).not.toHaveBeenCalled();
    });

    it('surfaces the hint banner + haptic fallback when autoplay is blocked (still locked)', async () => {
        const el = audioEl({ reject: true });
        const c = ctx({ $refs: { kdsNewOrderAudio: el } });
        play.call(c);
        await flush();
        expect(c.kdsAudioBlockedHint).toBe(true);
        expect(vibrateSpy).toHaveBeenCalled();
    });

    it('does NOT resurface the hint once unlocked (a later play rejection is transient, not autoplay)', async () => {
        const el = audioEl({ reject: true });
        const c = ctx({ $refs: { kdsNewOrderAudio: el }, kdsAudioUnlocked: true, kdsAudioBlockedHint: false });
        play.call(c);
        await flush();
        expect(c.kdsAudioBlockedHint).toBe(false);
    });

    it('setup binds one-shot gesture listeners; teardown removes them', () => {
        const addSpy = vi.spyOn(window, 'addEventListener');
        const removeSpy = vi.spyOn(window, 'removeEventListener');
        const c = ctx();
        setup.call(c);
        expect(c._kdsAudioUnlockBound).toBe(true);
        ['pointerdown', 'touchstart', 'keydown'].forEach((evt) => {
            expect(addSpy).toHaveBeenCalledWith(evt, expect.any(Function), expect.anything());
        });
        teardown.call(c);
        expect(c._kdsAudioUnlockBound).toBe(false);
        expect(removeSpy).toHaveBeenCalled();
    });
});

describe('F1 — source wiring sentinels (method exists AND is wired)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
        'utf8',
    );
    it('mounted() sets up the gesture-unlock listeners', () => {
        expect(source).toMatch(/mounted\(\)\s*\{[\s\S]*_setupKdsAudioUnlockListeners\(\)/);
    });
    it('beforeUnmount() tears down the gesture-unlock listeners', () => {
        expect(source).toMatch(/beforeUnmount\(\)\s*\{[\s\S]*_teardownKdsAudioUnlockListeners\(\)/);
    });
    it('template exposes the discreet "tap to enable sound" hint', () => {
        expect(source).toContain('data-testid="kds-audio-blocked-hint"');
        expect(source).toContain('kds_sound_tap_to_enable');
    });
    it('the ID-diff chime trigger is preserved (not regressed to length-based)', () => {
        expect(source).toMatch(/const\s+oldIds\s*=\s*new\s+Set\(/);
        expect(source).not.toMatch(/newVal\.length\s*>\s*oldVal\.length/);
    });
});
