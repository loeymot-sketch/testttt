/**
 * announcer.js — A11y ARIA live region helper.
 *
 * Lightweight wrapper around an aria-live="polite" region so that any
 * Vue component (kiosk wizard, admin warnings, toast notifier) can
 * announce a transient message to screen-reader users without forcing
 * them to manually re-read the surrounding content.
 *
 * The implementation creates ONE singleton region appended to <body>
 * the first time it is used. Subsequent calls re-use it and toggle the
 * text content (with a microsleep) so that AT actually re-reads it.
 *
 * Two priority levels are exposed:
 *   - 'polite' (default): aria-live="polite", aria-atomic="true".
 *     Used for non-urgent updates: toast catalog change, badge updates.
 *   - 'assertive': aria-live="assertive", aria-atomic="true".
 *     Used for urgent updates: order rejection, hard rupture, error.
 *
 * Both regions are visually hidden via inline styles equivalent to
 * Tailwind sr-only.
 *
 * Audit: WCAG 2.2 success criterion 4.1.3 (Status Messages).
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 1.3
 *        and plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md task 1.7
 *
 * SAFE in SSR / tests: all DOM access is guarded by typeof document checks.
 */

const REGION_IDS = Object.freeze({
    polite: 'fk-a11y-live-polite',
    assertive: 'fk-a11y-live-assertive',
});

const SR_ONLY_STYLES = [
    'position:absolute',
    'width:1px',
    'height:1px',
    'padding:0',
    'margin:-1px',
    'overflow:hidden',
    'clip:rect(0,0,0,0)',
    'white-space:nowrap',
    'border:0',
].join(';');

let lastMessage = { polite: '', assertive: '' };

/**
 * Lazily create or retrieve the singleton live region for a given politeness.
 *
 * @param {'polite'|'assertive'} politeness
 * @returns {HTMLElement|null} The DOM node, or null when no document.
 */
function getOrCreateRegion(politeness) {
    if (typeof document === 'undefined') {
        return null;
    }
    const id = REGION_IDS[politeness] || REGION_IDS.polite;
    let region = document.getElementById(id);
    if (region) {
        return region;
    }
    region = document.createElement('div');
    region.id = id;
    region.setAttribute('aria-live', politeness === 'assertive' ? 'assertive' : 'polite');
    region.setAttribute('aria-atomic', 'true');
    region.setAttribute('role', politeness === 'assertive' ? 'alert' : 'status');
    region.setAttribute('style', SR_ONLY_STYLES);
    document.body.appendChild(region);
    return region;
}

/**
 * Announce a message to screen readers.
 *
 * @param {string} message     The text to announce.
 * @param {'polite'|'assertive'} [politeness='polite']
 *
 * Implementation note: setting textContent identical to the previous one
 * does not trigger AT re-read. We set it to '' first, then the new value
 * on the next animation frame so the region is observably mutated.
 */
export function announce(message, politeness = 'polite') {
    if (!message || typeof message !== 'string') {
        return;
    }
    const level = politeness === 'assertive' ? 'assertive' : 'polite';
    const region = getOrCreateRegion(level);
    if (!region) {
        return;
    }

    // Clear first so identical successive messages still trigger re-read.
    region.textContent = '';
    lastMessage[level] = message;

    if (typeof requestAnimationFrame === 'function') {
        requestAnimationFrame(() => {
            // Re-check in case stop() was called between frames.
            if (lastMessage[level] === message) {
                region.textContent = message;
            }
        });
    } else {
        region.textContent = message;
    }
}

/**
 * Convenience helpers for the common UX patterns of the catalog mission.
 */
export const announcer = Object.freeze({
    polite: (message) => announce(message, 'polite'),
    assertive: (message) => announce(message, 'assertive'),

    /**
     * Specialized announcer for catalog-mid-session changes.
     * The default i18n key is 'catalog.menu_refreshed_polite' but the
     * caller may pass a pre-resolved string.
     */
    catalogChanged: (message) => announce(message, 'polite'),

    /**
     * Specialized announcer for hard rejection (composer mismatch, hard
     * rupture, payment refusal). Uses 'assertive' for screen-reader urgency.
     */
    orderRejected: (message) => announce(message, 'assertive'),
});

export default announcer;
