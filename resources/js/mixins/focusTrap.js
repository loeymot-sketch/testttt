/**
 * focusTrap — reusable WCAG 2.4.3 (Focus Order) / 2.1.1 (Keyboard) focus trap
 * for modal dialogs, factored verbatim from the canonical implementation in
 * resources/js/components/frontend/kiosk/ds/KsModal.vue:133-180 so the POS
 * money modals (refund / counter-collect / loyalty-redeem) and the kiosk
 * "clear cart" confirm honour the `aria-modal="true"` contract they already
 * announce:
 *   - focus enters the panel on open,
 *   - Tab / Shift+Tab cycle first <-> last focusable (e.preventDefault),
 *   - focus is restored to the trigger element on close.
 *
 * Why a mixin (not <KsModal>): these modals are pre-existing bespoke surfaces
 * with their own open/close signal (a `visible` computed, an `open` prop or a
 * `showXxx` data flag) and their own panel markup. The mixin exposes two
 * imperative methods the host calls from its OWN open/close watcher, so no
 * structural rewrite (and no frozen-zone risk) is needed:
 *
 *   import focusTrap from '@/mixins/focusTrap';
 *   mixins: [focusTrap],
 *   watch: {
 *     open(v) {
 *       if (v) this.$nextTick(() => this.activateFocusTrap(this.$refs.panel, {
 *                 initialFocus: this.$refs.codeInput }));
 *       else  this.deactivateFocusTrap();
 *     }
 *   }
 *
 * `beforeUnmount` auto-deactivates (belt-and-braces, restores focus if the
 * host is torn down while open). Internals are stored as plain instance
 * properties (NOT in data()) to avoid wrapping DOM nodes in a reactive proxy
 * — mirrors KsModal's `_modalTrapListener` handling.
 */

const FOCUSABLE_SELECTOR =
    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

export default {
    beforeUnmount() {
        // Restore focus if we are torn down while still trapping.
        this.deactivateFocusTrap();
    },
    methods: {
        /**
         * Visible, enabled, non-aria-hidden focusables inside the trapped
         * panel, in DOM order. Mirrors KsModal's filter EXACTLY (disabled +
         * aria-hidden only — no offsetParent check, which would break in
         * jsdom where offsetParent is always null).
         */
        _ftFocusables() {
            const panel = this._ftPanel;
            if (!panel || typeof panel.querySelectorAll !== 'function') return [];
            return Array.from(panel.querySelectorAll(FOCUSABLE_SELECTOR)).filter(
                (el) =>
                    !el.hasAttribute('disabled') &&
                    el.getAttribute('aria-hidden') !== 'true'
            );
        },
        _ftOnKeydown(e) {
            if (e.key !== 'Tab') return;
            const focusables = this._ftFocusables();
            if (focusables.length === 0) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            const active = typeof document !== 'undefined' ? document.activeElement : null;
            if (e.shiftKey && active === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && active === last) {
                e.preventDefault();
                first.focus();
            }
        },
        /**
         * Start trapping focus inside `panelEl`.
         * @param {HTMLElement} panelEl  the dialog panel element (ref).
         * @param {Object} [opts]
         * @param {HTMLElement} [opts.initialFocus] element to focus first
         *        (e.g. the primary input); falls back to the first focusable.
         */
        activateFocusTrap(panelEl, opts = {}) {
            if (typeof document === 'undefined' || !panelEl) return;
            // Re-entrant guard: detach any prior trap WITHOUT restoring focus
            // (the previous trigger is being superseded, not closed).
            this.deactivateFocusTrap(true);
            this._ftPrevActive = document.activeElement;
            this._ftPanel = panelEl;
            this._ftHandler = (e) => this._ftOnKeydown(e);
            panelEl.addEventListener('keydown', this._ftHandler);
            this.$nextTick(() => {
                const preferred = opts.initialFocus;
                const target =
                    preferred && typeof preferred.focus === 'function'
                        ? preferred
                        : this._ftFocusables()[0];
                if (target && typeof target.focus === 'function') {
                    try {
                        target.focus({ preventScroll: true });
                    } catch (_) {
                        /* jsdom / detached node — ignore */
                    }
                }
            });
        },
        /**
         * Stop trapping. Restores focus to the element that was active before
         * the trap was installed, unless `skipRestore` is true (re-entrancy).
         */
        deactivateFocusTrap(skipRestore = false) {
            if (this._ftPanel && this._ftHandler) {
                this._ftPanel.removeEventListener('keydown', this._ftHandler);
            }
            this._ftHandler = null;
            this._ftPanel = null;
            const prev = this._ftPrevActive;
            this._ftPrevActive = null;
            if (!skipRestore && prev && typeof prev.focus === 'function') {
                try {
                    prev.focus({ preventScroll: true });
                } catch (_) {
                    /* element gone — ignore */
                }
            }
        },
    },
};
