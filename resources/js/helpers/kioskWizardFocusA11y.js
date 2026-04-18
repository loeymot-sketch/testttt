/**
 * kioskWizardFocusA11y — Phase 9.3.15 (extracted helper)
 *
 * Wizard accessibility primitives. Keeps focus management, scroll memory,
 * and screen-reader step announcements in one reusable module so that any
 * multi-step kiosk/POS flow (not just the main wizard) can adopt the same
 * a11y contract without duplicating DOM logic.
 *
 * The helper is intentionally DOM-level (no Vue dependency) to maximise
 * reuse.
 */

const FOCUSABLE_SELECTOR = [
    'button:not([disabled]):not([aria-disabled="true"])',
    'input:not([disabled]):not([aria-disabled="true"])',
    'select:not([disabled]):not([aria-disabled="true"])',
    'textarea:not([disabled]):not([aria-disabled="true"])',
    '[role="radio"]:not([aria-disabled="true"])',
    '[role="checkbox"]:not([aria-disabled="true"])',
    '[tabindex]:not([tabindex="-1"]):not([aria-disabled="true"])',
].join(', ');

export function focusFirstInteractive(root, { fallback = null } = {}) {
    if (!root || typeof root.querySelector !== 'function') return false;

    const firstControl = root.querySelector(FOCUSABLE_SELECTOR);
    if (firstControl && typeof firstControl.focus === 'function') {
        try {
            firstControl.focus({ preventScroll: true });
        } catch (_) {
            firstControl.focus();
        }
        return true;
    }

    if (fallback && typeof fallback.focus === 'function') {
        try {
            fallback.focus({ preventScroll: true });
        } catch (_) {
            fallback.focus();
        }
        return true;
    }

    return false;
}

export function captureScrollPosition(root) {
    if (!root) return 0;
    const top = root.scrollTop;
    return Number.isFinite(top) ? top : 0;
}

export function restoreScrollPosition(root, offset) {
    if (!root) return;
    root.scrollTop = Number.isFinite(offset) ? offset : 0;
}

export function announceStep(announcer, message) {
    if (!announcer) return false;
    if (typeof announcer.setAttribute === 'function') {
        announcer.setAttribute('aria-live', 'polite');
    }
    try {
        announcer.textContent = '';
        if (typeof message === 'string' && message.length > 0) {
            announcer.textContent = message;
        }
        return true;
    } catch (_) {
        return false;
    }
}

export default {
    focusFirstInteractive,
    captureScrollPosition,
    restoreScrollPosition,
    announceStep,
    FOCUSABLE_SELECTOR,
};
