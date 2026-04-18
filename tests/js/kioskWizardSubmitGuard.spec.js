import { describe, it, expect } from 'vitest';
import { createSubmitGuard } from '../../resources/js/helpers/kioskWizardSubmitGuard';

describe('kioskWizardSubmitGuard', () => {
    it('accepts the first call and rejects a synchronous second call', () => {
        const guard = createSubmitGuard({ cooldownMs: 1000, now: () => 0 });

        expect(guard.canProceed()).toBe(true);
        expect(guard.canProceed()).toBe(false);
        expect(guard.isBusy()).toBe(true);
    });

    it('enforces a cooldown window even after release()', () => {
        let clock = 0;
        const guard = createSubmitGuard({ cooldownMs: 500, now: () => clock });

        expect(guard.canProceed()).toBe(true);
        guard.release();

        clock = 100;
        expect(guard.canProceed()).toBe(false);

        clock = 600;
        expect(guard.canProceed()).toBe(true);
    });

    it('reset() clears busy and cooldown together', () => {
        let clock = 0;
        const guard = createSubmitGuard({ cooldownMs: 500, now: () => clock });

        expect(guard.canProceed()).toBe(true);
        guard.reset();

        expect(guard.isBusy()).toBe(false);
        clock = 1;
        expect(guard.canProceed()).toBe(true);
    });

    it('triple-tap scenario: two rapid taps are dropped, the first proceeds', () => {
        const calls = [];
        let clock = 0;
        const guard = createSubmitGuard({ cooldownMs: 1000, now: () => clock });

        const tap = () => {
            if (guard.canProceed()) {
                calls.push(clock);
            }
        };

        tap(); clock += 40;
        tap(); clock += 40;
        tap();

        expect(calls).toEqual([0]);
    });
});
