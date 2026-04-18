/**
 * kioskWizardSubmitGuard — Phase 9.3.12 (extracted helper)
 *
 * Small, zero-dependency guard factory that serializes wizard submissions.
 * Prevents double-taps, triple-taps, and rapid-fire clicks on the "add to
 * cart" CTA. Exposes a cooldown window so that a fast `catch` path does not
 * accidentally allow an instant resubmit.
 *
 * Usage:
 *   const guard = createSubmitGuard({ cooldownMs: 1200 });
 *   if (!guard.canProceed()) { // emit analytics, ignore
 *     return;
 *   }
 *   try { await doSubmit(); } catch (e) { guard.release(); throw e; }
 *
 * The guard intentionally does NOT touch the DOM or Vue reactive state; the
 * caller is responsible for driving any reactive `isSubmitting` flag used to
 * disable UI buttons. This separation keeps the helper framework-agnostic
 * and trivially unit-testable.
 */

const DEFAULT_COOLDOWN_MS = 1200;

export function createSubmitGuard({ cooldownMs = DEFAULT_COOLDOWN_MS, now = () => Date.now() } = {}) {
    let busy = false;
    let lastAcceptedAt = -Infinity;

    return {
        canProceed() {
            const currentTime = now();
            if (busy) {
                return false;
            }
            if (currentTime - lastAcceptedAt < cooldownMs) {
                return false;
            }
            busy = true;
            lastAcceptedAt = currentTime;
            return true;
        },
        release() {
            busy = false;
        },
        reset() {
            busy = false;
            lastAcceptedAt = -Infinity;
        },
        isBusy() {
            return busy;
        },
    };
}

export default { createSubmitGuard };
