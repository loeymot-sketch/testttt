/**
 * POS operator accessibility helpers (focus trap, screen reader announcements).
 */

// Trap focus inside modal/drawer for POS modals
export function trapFocus(rootEl) {
    if (!rootEl) return () => {};
    const focusable = rootEl.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    if (!focusable.length) return () => {};
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const handler = (e) => {
        if (e.key !== 'Tab') return;
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    };
    rootEl.addEventListener('keydown', handler);
    return () => rootEl.removeEventListener('keydown', handler);
}

export function announce(msg, priority = 'polite') {
    const live = document.getElementById('pos-a11y-live');
    if (!live) return;
    live.setAttribute('aria-live', priority);
    live.textContent = '';
    // Force re-read by SR
    setTimeout(() => {
        live.textContent = msg;
    }, 50);
}
