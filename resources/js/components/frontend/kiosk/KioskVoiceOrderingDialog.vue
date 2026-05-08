<template>
    <div
        class="voice-dialog-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="voice-dialog-title"
        data-testid="kiosk-voice-dialog"
        @click.self="$emit('cancel')"
        @keydown.esc.prevent="$emit('cancel')"
    >
        <div class="voice-dialog" @keydown.tab="onTabKey">
            <h2 id="voice-dialog-title" class="voice-dialog-title">
                {{ tr('kiosk.voice.dialog_title', "J'ai entendu...") }}
            </h2>
            <p class="voice-dialog-subtitle">
                {{ tr('kiosk.voice.dialog_subtitle', 'Est-ce bien ce que vous souhaitez commander ?') }}
            </p>
            <blockquote
                class="voice-transcript"
                data-testid="kiosk-voice-dialog-transcript"
            >
                "{{ transcript }}"
            </blockquote>
            <p class="voice-disclaimer">
                {{ tr('kiosk.voice.dialog_disclaimer', 'Vous pourrez ajuster votre commande dans le menu suivant.') }}
            </p>
            <div class="voice-dialog-actions">
                <button
                    type="button"
                    class="ks-btn-secondary"
                    data-testid="kiosk-voice-dialog-cancel"
                    ref="cancelBtn"
                    @click="$emit('cancel')"
                >
                    {{ tr('label.cancel', 'Annuler') }}
                </button>
                <button
                    type="button"
                    class="ks-btn-primary"
                    data-testid="kiosk-voice-dialog-confirm"
                    ref="confirmBtn"
                    @click="$emit('confirm')"
                >
                    {{ tr('kiosk.voice.dialog_confirm', 'OUI, CONTINUER') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * V2-4 Phase A — Kiosk voice ordering confirm dialog (greenfield).
 *
 * Affiché après que `KioskVoiceOrderingButton` a émis 'voice-input' avec
 * un transcript final. Le client confirme ou annule avant que l'IdleScreen
 * route vers le wizard kiosk avec le `voice_intent` en query string.
 *
 * Standalone — n'importe pas les frozen components, peut être testé
 * unitairement via vitest.
 *
 * Voir plans/PLAN_DESIGN_V2_4_VOICE_ORDERING_2026-05-08.md.
 */
export default {
    name: 'KioskVoiceOrderingDialog',
    props: {
        transcript: {
            type: String,
            required: true,
        },
    },
    emits: ['confirm', 'cancel'],
    data() {
        return {
            // [A11y-HEAL-2026-05-08] WCAG 2.4.3 — focus restoration target.
            // Captured in mounted() before focus is moved into the dialog so
            // we can return focus to the trigger on unmount. Mirrors the
            // canonical pattern from `ds/KsModal.vue`.
            _prevActive: null,
        };
    },
    /**
     * [A11y-HEAL-2026-05-08] Ultra-review P0 finding : modal manquait
     * Escape handler + autofocus + focus trap. Conforme WCAG 2.4.3.
     * Escape handler : @keydown.esc dans le template overlay.
     * Autofocus : mounted() pose focus sur "OUI, CONTINUER" (action primaire).
     * Focus trap : onTabKey() boucle entre Cancel et Confirm.
     * Focus restore : beforeUnmount() rend le focus à l'élément déclencheur.
     */
    mounted() {
        // [A11y] Save the element that had focus before the dialog opened
        // (typically the trigger button). On unmount we restore focus there
        // so screen-reader / keyboard users keep their place.
        if (typeof document !== 'undefined') {
            this._prevActive = document.activeElement;
        }
        this.$nextTick(() => {
            // Autofocus sur l'action primaire pour les screen readers.
            this.$refs.confirmBtn?.focus();
        });
    },
    beforeUnmount() {
        // [A11y-HEAL-2026-05-08] WCAG 2.4.3 — restore focus to the trigger.
        // Vue 3 hook (`beforeDestroy` would be Vue 2). Wrapped in try/catch
        // because the previous active element may have been removed from the
        // DOM while the dialog was open.
        if (this._prevActive && typeof this._prevActive.focus === 'function') {
            try {
                this._prevActive.focus({ preventScroll: true });
            } catch (_) {
                /* silent — element gone or focus refused */
            }
        }
    },
    methods: {
        /**
         * Tente de traduire via $t si vue-i18n est branché ; sinon retourne
         * `fallback`. Permet au composant de fonctionner aussi en preview /
         * tests sans plugin i18n.
         */
        tr(key, fallback) {
            if (typeof this.$t === 'function') {
                const value = this.$t(key);
                return value === key ? fallback : value;
            }
            return fallback;
        },
        /**
         * [A11y] Focus trap minimal entre les 2 boutons : Tab depuis Confirm
         * boucle vers Cancel, Shift+Tab depuis Cancel boucle vers Confirm.
         * WCAG 2.4.3 — focus order préservé dans le contexte modal.
         */
        onTabKey(event) {
            const cancel = this.$refs.cancelBtn;
            const confirm = this.$refs.confirmBtn;
            if (!cancel || !confirm) return;
            const active = document.activeElement;
            if (event.shiftKey && active === cancel) {
                event.preventDefault();
                confirm.focus();
            } else if (!event.shiftKey && active === confirm) {
                event.preventDefault();
                cancel.focus();
            }
        },
    },
};
</script>

<style scoped>
.voice-dialog-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 24px;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

.voice-dialog {
    background: #fff;
    border-radius: 12px;
    padding: 32px;
    max-width: 540px;
    width: 100%;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
    animation: voiceDialogIn 0.22s ease;
}

@keyframes voiceDialogIn {
    from { transform: translateY(12px) scale(0.98); opacity: 0; }
    to   { transform: translateY(0)   scale(1);    opacity: 1; }
}

.voice-dialog-title {
    margin: 0 0 8px 0;
    font-size: 24px;
    font-weight: 800;
    color: #1A1A1A;
}

.voice-dialog-subtitle {
    margin: 0 0 16px 0;
    color: #5A5A5A;
    font-size: 16px;
}

.voice-transcript {
    background: #F7F3EC;
    border-left: 4px solid #E8001C;
    padding: 16px;
    margin: 16px 0;
    font-style: italic;
    font-size: 18px;
    color: #1A1A1A;
    border-radius: 4px;
}

.voice-disclaimer {
    font-size: 13px;
    color: #5A5A5A;
    margin: 0 0 16px 0;
}

.voice-dialog-actions {
    display: flex;
    gap: 16px;
    justify-content: flex-end;
    margin-top: 24px;
}

.ks-btn-primary {
    background: #E8001C;
    color: #fff;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 800;
    cursor: pointer;
    min-width: 160px;
    transition: transform 0.12s ease, background 0.18s ease;
}

.ks-btn-primary:hover { background: #C30019; }
.ks-btn-primary:active { transform: scale(0.98); }

.ks-btn-secondary {
    background: transparent;
    border: 1px solid #EEE6D9;
    padding: 12px 24px;
    border-radius: 8px;
    cursor: pointer;
    color: #1A1A1A;
    font-weight: 600;
    transition: background 0.18s ease;
}

.ks-btn-secondary:hover { background: #F7F3EC; }

[dir="rtl"] .voice-transcript {
    border-left: none;
    border-right: 4px solid #E8001C;
}
</style>
