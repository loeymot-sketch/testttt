/**
 * [ETIQUETTES 2026-10-16] Le signal sonore de l'impression automatique bloquée : trois bips, pas plus d'un appel
 * toutes les 20 s, et silence (jamais d'exception) sans audio.
 */
import { describe, it, expect } from 'vitest';
import { createAlarm, ALARM_MIN_INTERVAL_MS } from '../../resources/js/helpers/labelAlarm.js';

function fakeAudio() {
    const log = { osc: 0, started: [], resumed: 0 };
    class Ctx {
        constructor() { this.currentTime = 0; this.state = 'suspended'; this.destination = {}; }
        resume() { log.resumed += 1; }
        createGain() { return { gain: { value: 0 }, connect() {} }; }
        createOscillator() {
            log.osc += 1;
            return { frequency: {}, connect() {}, start: (t) => log.started.push(t), stop() {} };
        }
    }
    return { Ctx, log };
}

describe('labelAlarm', () => {
    it('émet trois bips espacés', () => {
        const { Ctx, log } = fakeAudio();
        const a = createAlarm({ AudioCtx: Ctx, now: () => 100000 });
        expect(a.ring()).toBe(true);
        expect(log.osc).toBe(3);
        expect(log.started).toEqual([0, 0.32, 0.64]);
        expect(log.resumed).toBe(1);
    });

    it('au plus un appel toutes les 20 s', () => {
        const { Ctx, log } = fakeAudio();
        let t = 100000;
        const a = createAlarm({ AudioCtx: Ctx, now: () => t });
        expect(a.ring()).toBe(true);
        t += ALARM_MIN_INTERVAL_MS - 1;
        expect(a.ring()).toBe(false);
        t += 2;
        expect(a.ring()).toBe(true);
        expect(log.osc).toBe(6);
    });

    it('sans audio ou audio qui échoue : silence, jamais d’exception', () => {
        expect(createAlarm({ AudioCtx: undefined }).ring()).toBe(false);
        const Broken = class { constructor() { throw new Error('nope'); } };
        expect(createAlarm({ AudioCtx: Broken, now: () => 1e9 }).ring()).toBe(false);
    });
});
