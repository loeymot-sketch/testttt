<template>
  <transition name="ks-modal">
    <div
      v-if="modelValue"
      ref="modalRoot"
      :class="[
        'ks-modal',
        `ks-modal--tone-${tone}`,
        { 'ks-modal--no-pad': bare }
      ]"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="titleId"
      @mousedown.self="onBackdrop"
    >
      <div
        ref="panel"
        :class="['ks-modal__panel', `ks-modal__panel--${size}`]"
        @mousedown.stop
      >
        <header v-if="$slots.header || title" class="ks-modal__header">
          <h2 :id="titleId" class="ks-modal__title">
            <slot name="header">{{ title }}</slot>
          </h2>
          <button type="button"
            v-if="closable"
            class="ks-modal__close"
            :aria-label="closeLabel"
            @click="close('close-button')">×</button>
        </header>

        <div class="ks-modal__body"><slot /></div>

        <footer v-if="$slots.footer" class="ks-modal__footer">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </transition>
</template>

<script>
/**
 * KsModal — Modal plein écran ou panneau centré.
 *
 * v-model   : modelValue (Boolean) — contrôle l'ouverture.
 * Props :
 *  - title         : titre (alternative au slot header)
 *  - size          : sm | md | lg | full (full = bottom-sheet plein-écran)
 *  - closable      : affiche le bouton × et active la fermeture (default true)
 *  - backdropClose : clic sur overlay → close (default true)
 *  - escClose      : Escape → close (default true)
 *  - bare          : supprime les paddings body (usage inner scroller)
 *
 * Emits :
 *  - update:modelValue(false)
 *  - close(reason) — reason: 'backdrop'|'escape'|'close-button'
 *
 * Notes a11y :
 *  - aria-modal, role=dialog, focus déplacé sur le panneau à l'ouverture.
 *  - Focus trap minimal : on ramène le focus dans le panel s'il sort.
 *  - Body scroll locké pendant l'ouverture.
 */
let uid = 0;

export default {
    name: 'KsModal',
    props: {
        modelValue: { type: Boolean, default: false },
        title: { type: String, default: null },
        size: {
            type: String,
            default: 'md',
            validator: (v) => ['sm', 'md', 'lg', 'full'].includes(v),
        },
        closable: { type: Boolean, default: true },
        backdropClose: { type: Boolean, default: true },
        escClose: { type: Boolean, default: true },
        bare: { type: Boolean, default: false },
        closeLabel: { type: String, default: 'Fermer' },
        /**
         * V1.8 Bold Appétissant — tone visuel.
         *  - default   : surface kiosk legacy (--kiosk-surface)
         *  - warm-blur : backdrop blur warm + surface bold (--kiosk-bold-surface)
         *                + ombre modal-bold (premium feel pour wizard abandon, etc.)
         */
        tone: {
            type: String,
            default: 'default',
            validator: (v) => ['default', 'warm-blur'].includes(v),
        },
    },
    emits: ['update:modelValue', 'close'],
    data() {
        return {
            titleId: `ks-modal-title-${++uid}`,
            _prevOverflow: null,
            _prevActive: null,
        };
    },
    watch: {
        modelValue: {
            immediate: true,
            handler(open) {
                if (typeof document === 'undefined') return;
                if (open) {
                    this._prevActive = document.activeElement;
                    this._prevOverflow = document.body.style.overflow;
                    document.body.style.overflow = 'hidden';
                    this.$nextTick(() => {
                        this._attachFocusTrap();
                        this._focusFirstFocusable();
                    });
                } else {
                    this._detachFocusTrap();
                    document.body.style.overflow = this._prevOverflow || '';
                    if (this._prevActive && typeof this._prevActive.focus === 'function') {
                        try { this._prevActive.focus({ preventScroll: true }); } catch (_) { /* ignore */ }
                    }
                }
            },
        },
    },
    mounted() {
        document.addEventListener('keydown', this.onKeydown);
    },
    beforeUnmount() {
        document.removeEventListener('keydown', this.onKeydown);
        this._detachFocusTrap();
        if (this._prevOverflow != null) document.body.style.overflow = this._prevOverflow;
    },
    methods: {
        _focusFirstFocusable() {
            const panel = this.$refs.panel;
            if (!panel) return;
            const sel = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
            const nodes = [...panel.querySelectorAll(sel)].filter(
                (el) => !el.hasAttribute('disabled') && el.getAttribute('aria-hidden') !== 'true'
            );
            const first = nodes[0];
            if (first && typeof first.focus === 'function') {
                first.focus({ preventScroll: true });
            } else {
                panel.setAttribute('tabindex', '-1');
                panel.focus({ preventScroll: true });
            }
        },
        _trapKeydownHandler(e) {
            if (e.key !== 'Tab') return;
            const panel = this.$refs.panel;
            if (!panel || !this.modelValue) return;
            const sel = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
            const focusables = [...panel.querySelectorAll(sel)].filter(
                (el) => !el.hasAttribute('disabled') && el.getAttribute('aria-hidden') !== 'true'
            );
            if (focusables.length === 0) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        },
        _attachFocusTrap() {
            this._detachFocusTrap();
            const root = this.$refs.modalRoot;
            if (!root) return;
            this._modalTrapListener = (e) => this._trapKeydownHandler(e);
            root.addEventListener('keydown', this._modalTrapListener);
        },
        _detachFocusTrap() {
            const root = this.$refs.modalRoot;
            if (root && this._modalTrapListener) {
                root.removeEventListener('keydown', this._modalTrapListener);
            }
            this._modalTrapListener = null;
        },
        close(reason = 'programmatic') {
            this.$emit('close', reason);
            this.$emit('update:modelValue', false);
        },
        onBackdrop() {
            if (this.backdropClose) this.close('backdrop');
        },
        onKeydown(e) {
            if (!this.modelValue) return;
            if (e.key === 'Escape' && this.escClose && this.closable) {
                e.stopPropagation();
                this.close('escape');
            }
        },
    },
};
</script>

<style scoped>
.ks-modal {
    position: fixed;
    inset: 0;
    z-index: var(--kiosk-z-modal);
    background: var(--kiosk-overlay-modal);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--kiosk-space-6);
}

.ks-modal__panel {
    background: var(--kiosk-surface);
    color: var(--kiosk-text);
    border-radius: var(--kiosk-radius-2xl);
    box-shadow: var(--kiosk-shadow-modal);
    width: 100%;
    max-height: calc(100vh - var(--kiosk-space-12));
    display: flex;
    flex-direction: column;
    overflow: hidden;
    outline: none;
}

.ks-modal__panel--sm   { max-width: 480px; }
.ks-modal__panel--md   { max-width: 720px; }
.ks-modal__panel--lg   { max-width: 960px; }
.ks-modal__panel--full {
    max-width: none;
    width: 100%;
    height: 100%;
    max-height: 100vh;
    border-radius: 0;
}

.ks-modal__panel:focus-visible {
    outline: var(--kiosk-focus-width) solid var(--kiosk-focus-ring);
    outline-offset: -4px;
}

.ks-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--kiosk-space-4);
    padding: var(--kiosk-space-6) var(--kiosk-space-8);
    border-bottom: 1px solid var(--kiosk-border);
}
.ks-modal__title {
    margin: 0;
    font-size: calc(var(--kiosk-font-size-title) * var(--kiosk-text-scale));
    font-weight: var(--kiosk-font-weight-bold);
    line-height: var(--kiosk-line-height-tight);
}
.ks-modal__close {
    width: var(--kiosk-touch-comfortable);
    height: var(--kiosk-touch-comfortable);
    border-radius: 50%;
    background: var(--kiosk-surface);
    border: 2px solid var(--kiosk-border);
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    color: var(--kiosk-text-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.ks-modal__close:focus-visible {
    outline: var(--kiosk-focus-width) solid var(--kiosk-focus-ring);
    outline-offset: var(--kiosk-focus-offset);
}

.ks-modal__body {
    padding: var(--kiosk-space-8);
    overflow-y: auto;
    flex: 1;
    font-size: calc(var(--kiosk-font-size-body) * var(--kiosk-text-scale));
    line-height: var(--kiosk-line-height-normal);
}
.ks-modal--no-pad .ks-modal__body { padding: 0; }

.ks-modal__footer {
    padding: var(--kiosk-space-6) var(--kiosk-space-8);
    border-top: 1px solid var(--kiosk-border);
    display: flex;
    align-items: center;
    justify-content: end;
    gap: var(--kiosk-space-4);
}

/* ---------- Transitions ---------- */
.ks-modal-enter-active, .ks-modal-leave-active {
    transition: opacity var(--kiosk-duration-base) var(--kiosk-ease-standard);
}
.ks-modal-enter-active .ks-modal__panel,
.ks-modal-leave-active .ks-modal__panel {
    transition: transform var(--kiosk-duration-base) var(--kiosk-ease-standard);
}
.ks-modal-enter-from, .ks-modal-leave-to { opacity: 0; }
.ks-modal-enter-from .ks-modal__panel,
.ks-modal-leave-to   .ks-modal__panel {
    transform: translateY(16px) scale(0.98);
}

/* ---------- V1.8 Bold Appétissant — tone "warm-blur" ---------- */
.ks-modal--tone-warm-blur {
    background: var(--kiosk-backdrop-overlay-bold, rgba(26, 20, 16, 0.55));
    backdrop-filter: var(--kiosk-backdrop-blur-bold, blur(16px) saturate(180%));
    -webkit-backdrop-filter: var(--kiosk-backdrop-blur-bold, blur(16px) saturate(180%));
}
.ks-modal--tone-warm-blur .ks-modal__panel {
    background: var(--kiosk-bold-surface);
    color: var(--kiosk-bold-text-primary);
    box-shadow: var(--kiosk-shadow-modal-bold, var(--kiosk-shadow-modal));
    border-radius: var(--kiosk-radius-2xl);
}
.ks-modal--tone-warm-blur .ks-modal__header {
    border-bottom-color: var(--kiosk-bold-border);
}
.ks-modal--tone-warm-blur .ks-modal__title {
    font-family: var(--kiosk-font-display, 'Fraunces', Georgia, serif);
    font-weight: var(--kiosk-display-weight-bold, 700);
    letter-spacing: -0.02em;
}
.ks-modal--tone-warm-blur .ks-modal__close {
    background: var(--kiosk-bold-surface-subtle);
    border-color: var(--kiosk-bold-border);
    color: var(--kiosk-bold-text-primary);
}
.ks-modal--tone-warm-blur .ks-modal__footer {
    border-top-color: var(--kiosk-bold-border);
}

/* Reduced motion : conserver le fade mais sans transform scale (déjà géré
   par le @media query global, mais ceinture-bretelles ici aussi pour le tone bold). */
[data-kiosk-reduced-motion='true'] .ks-modal-enter-active,
[data-kiosk-reduced-motion='true'] .ks-modal-leave-active {
    transition: opacity 1ms;
}
[data-kiosk-reduced-motion='true'] .ks-modal-enter-from .ks-modal__panel,
[data-kiosk-reduced-motion='true'] .ks-modal-leave-to .ks-modal__panel {
    transform: none;
}
</style>
