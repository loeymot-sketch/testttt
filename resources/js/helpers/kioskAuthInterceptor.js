/**
 * kioskAuthInterceptor — Wave Y D1 (2026-05-21)
 * -----------------------------------------------------------------------------
 * Mission: kill the duplicate "Session rafraîchie automatiquement" toast that
 * appears whenever N concurrent kiosk requests race on a 401 burst.
 *
 * Why this exists (and why it is NOT the literal queue from the mission spec)
 * -----------------------------------------------------------------------------
 * The mission spec proposes a single-flight axios interceptor that queues
 * waiters and replays them after one refresh. That pattern is correct in
 * principle — but FoodKing already implements equivalent single-flight at
 * TWO lower layers, both shipped during iter15 round-7 (2026-05-10):
 *
 *   1. `store/modules/kioskCart.js` line 20-477 — module-scope
 *      `_inFlightKioskLogin` coalesces concurrent `kioskLogin` actions, so
 *      the SERVER-side token rotation happens exactly once per burst.
 *
 *   2. `resources/js/app.js` line 100-148 — the kiosk 401 response handler
 *      shares ONE `kioskCart/kioskLogin` dispatch across all racing 401s
 *      via the same Promise, then replays each request with
 *      `__retry401Kiosk = true`.
 *
 * Both are tested under the original C-037 / D5-003 burst race (7 parallel
 * 401s on `/frontend/menu` within 780ms). Rewriting them with the mission's
 * pseudo-spec would regress a hardened path and offer no behavior gain.
 *
 * The REMAINING defect (A-003 from Wave Y Round 1) is at the EVENT layer:
 * `app.js:125` dispatches `kiosk-auth-retried` once PER replayed request.
 * So 5 parallel 401s → 1 login (coalesced) → 5 replays → 5 CustomEvents →
 * 5 toasts overlap the cart "Payer" CTA.
 *
 * Fix here (surgical, frozen-zone-safe):
 *   - Install a capture-phase listener on `window` for `kiosk-auth-retried`.
 *   - Within a debounce window (1500ms), swallow duplicates via
 *     `stopImmediatePropagation()` so the downstream KioskAppComponent
 *     listener fires exactly once per refresh burst.
 *   - Same treatment for `kiosk-auth-failed` (terminal path also dispatches
 *     per request from app.js:142 and :153 — same N-toast risk on burst).
 *
 * Frozen-zone discipline:
 *   - KioskAppComponent.vue UNTOUCHED (still listens on the events normally).
 *   - KioskWizardComponent.vue / KioskUpsellComponent.vue UNTOUCHED.
 *   - app.js untouched — we never change the dispatch site, only debounce
 *     the receiver side.
 *
 * Double-install guard:
 *   - `window.__kioskAuthInterceptorInstalled` set on first install. Bundle
 *     can be re-imported (HMR / multiple entries) without stacking N
 *     debounce listeners that would silence legitimate retries.
 *
 * Why capture-phase + stopImmediatePropagation:
 *   - Capture phase runs BEFORE the bubble-phase listeners installed by
 *     KioskAppComponent (`window.addEventListener('kiosk-auth-retried',
 *     this._authRetriedListener)` — default bubble). `stopImmediatePropagation`
 *     prevents any other listener on the same phase + bubble listeners from
 *     firing. The FIRST event in a burst still propagates normally because
 *     the debounce check returns false on first call.
 */

const DEBOUNCE_MS = 1500;

/**
 * Per-event-name "last delivered" timestamps. The first event in a burst
 * passes through (timestamp 0 → diff > debounce). Subsequent events within
 * DEBOUNCE_MS are swallowed by `stopImmediatePropagation()`.
 *
 * Keyed by event name so `kiosk-auth-retried` and `kiosk-auth-failed`
 * debounce independently — they are semantically different signals.
 */
const lastDeliveredAt = Object.create(null);

const DEBOUNCED_EVENTS = ['kiosk-auth-retried', 'kiosk-auth-failed'];

// [dispute-r1 D-006 + C-ADV-05 2026-06-12] Le toast « Session rafraîchie
// automatiquement » (KioskAppComponent:380, frozen — déclenché par
// kiosk-auth-retried) est un message TECHNIQUE de session qui s'affichait
// DEVANT le client en plein parcours (d3-07 pendant le drawer a11y ;
// c1-11 où il CHEVAUCHE le CTA upsell « Non merci, continuer sans »).
// Le retry silencieux a réussi → il n'y a RIEN à dire au client. On avale
// donc l'event entièrement côté capture (le shell frozen ne le voit plus)
// et on garde la trace auditable en console pour les ingénieurs/Playwright.
// `kiosk-auth-failed` (borne déconnectée, actionnable) reste délivré,
// simplement débouncé comme avant.
const SILENCED_EVENTS = new Set(['kiosk-auth-retried']);

function makeDebouncer(eventName) {
  return function debouncer(evt) {
    try {
      if (SILENCED_EVENTS.has(eventName)) {
        // [dispute-r1 D-006] Silence total côté client — log console only.
        if (typeof evt.stopImmediatePropagation === 'function') {
          evt.stopImmediatePropagation();
        }
        if (typeof evt.preventDefault === 'function') {
          evt.preventDefault();
        }
        if (typeof console !== 'undefined' && typeof console.debug === 'function') {
          console.debug('[kioskAuthInterceptor] session auto-refreshed (toast client supprimé — dispute-r1 D-006)', evt?.detail || null);
        }
        return;
      }
      const now = Date.now();
      const previous = lastDeliveredAt[eventName] || 0;
      if (now - previous < DEBOUNCE_MS) {
        // Swallow this duplicate. stopImmediatePropagation prevents
        // KioskAppComponent's bubble listener from receiving it.
        if (typeof evt.stopImmediatePropagation === 'function') {
          evt.stopImmediatePropagation();
        }
        if (typeof evt.preventDefault === 'function') {
          evt.preventDefault();
        }
        return;
      }
      lastDeliveredAt[eventName] = now;
      // First event in burst — let it bubble through to the existing
      // KioskAppComponent listener which calls $refs.toast?.show(...).
    } catch (_) {
      // Defensive — never break the event chain. Worst case: duplicate
      // toast slips through, which is the pre-fix state, not worse.
    }
  };
}

/**
 * Install the single-flight CustomEvent debouncer on `window`.
 * Idempotent — calling installKioskAuthInterceptor() twice is a no-op.
 */
export function installKioskAuthInterceptor() {
  if (typeof window === 'undefined') {
    // SSR / Node — nothing to do.
    return false;
  }
  if (window.__kioskAuthInterceptorInstalled) {
    return false;
  }

  DEBOUNCED_EVENTS.forEach((eventName) => {
    // useCapture = true. Capture phase fires BEFORE bubble. Our handler
    // runs first; if it decides to drop the event, KioskAppComponent's
    // bubble listener never sees it.
    window.addEventListener(eventName, makeDebouncer(eventName), true);
  });

  window.__kioskAuthInterceptorInstalled = true;
  return true;
}

/**
 * Test-only: reset the debounce timestamps so a verification spec can
 * fire bursts back-to-back without waiting DEBOUNCE_MS between cycles.
 *
 * Exposed on `window` for Playwright `page.evaluate` access.
 */
export function _resetKioskAuthInterceptorForTests() {
  Object.keys(lastDeliveredAt).forEach((k) => { delete lastDeliveredAt[k]; });
}

// Expose the reset helper for E2E specs (no-op in prod since no spec calls it).
if (typeof window !== 'undefined') {
  window.__resetKioskAuthInterceptorForTests = _resetKioskAuthInterceptorForTests;
}

export default installKioskAuthInterceptor;
