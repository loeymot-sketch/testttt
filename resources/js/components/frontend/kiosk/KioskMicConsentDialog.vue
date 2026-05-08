<template>
    <div
        class="mic-consent-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mic-consent-title"
        aria-describedby="mic-consent-disclaimer"
        data-testid="kiosk-mic-consent-dialog"
        @click.self="$emit('cancel')"
        @keydown.esc.prevent="$emit('cancel')"
    >
        <div class="mic-consent-dialog" @keydown.tab="onTabKey">
            <div class="mic-consent-icon" aria-hidden="true">🎤</div>
            <h2 id="mic-consent-title" class="mic-consent-title">
                {{ tr('kiosk.voice.consent_title', 'Activation du microphone') }}
            </h2>
            <p class="mic-consent-subtitle">
                {{ tr('kiosk.voice.consent_subtitle', 'Cette borne va activer le micro pour environ 8 secondes.') }}
            </p>
            <p
                id="mic-consent-disclaimer"
                class="mic-consent-disclaimer"
                data-testid="kiosk-mic-consent-disclaimer"
            >
                {{
                    tr(
                        'kiosk.voice.consent_disclaimer',
                        'Vos paroles seront utilisées pour suggérer une commande puis effacées immédiatement après.'
                    )
                }}
            </p>
            <div class="mic-consent-actions">
                <button
                    type="button"
                    class="ks-btn-secondary"
                    data-testid="kiosk-mic-consent-cancel"
                    ref="cancelBtn"
                    @click="$emit('cancel')"
                >
                    {{ tr('kiosk.voice.consent_deny', 'Annuler') }}
                </button>
                <button
                    type="button"
                    class="ks-btn-primary"
                    data-testid="kiosk-mic-consent-confirm"
                    ref="confirmBtn"
                    @click="$emit('confirm')"
                >
                    {{ tr('kiosk.voice.consent_grant', 'Activer le micro') }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * A4 — KioskMicConsentDialog (greenfield, P2 RGPD privacy).
 *
 * Pre-flight consent dialog displayed *before* `SpeechRecognition.start()` on
 * the kiosk. RGPD article 7 requires explicit, informed consent for audio
 * capture in a public space. The browser's implicit prompt (Chrome/Edge OK 1×,
 * Safari can auto-deny silently) is not sufficient.
 *
 * Pattern mirrors `KioskVoiceOrderingDialog.vue` :
 *   - role="dialog" + aria-modal="true" + aria-labelledby + aria-describedby
 *   - mounted() autofocus on the SAFE default action (cancel — see below)
 *   - @keydown.esc → emit('cancel')
 *   - onTabKey() focus trap between the 2 buttons
 *
 * SAFE-DEFAULT autofocus rationale :
 *   For mic activation specifically, autofocus the *cancel* button rather than
 *   confirm. A user could press Enter accidentally on the dialog and grant
 *   audio capture without intending to. With cancel as the default, the worst
 *   case on accidental Enter is "no capture", which is privacy-conservative.
 *
 * Emits :
 *   - 'confirm' : user explicitly granted mic access for this session
 *   - 'cancel'  : user denied / dismissed (no `start()` should follow)
 *
 * Voir plans/pr-review/ULTRA_PLAN_CORRECTION_2026-05-08.md §A4.
 */
export default {
    name: 'KioskMicConsentDialog',
    emits: ['confirm', 'cancel'],
    mounted() {
        this.$nextTick(() => {
            // [Privacy + A11y] Safe-default autofocus on Cancel : prevents
            // accidental consent via Enter key. Screen-reader announces the
            // cancel button first, which matches the privacy-conservative
            // expectation (deny by default).
            this.$refs.cancelBtn?.focus();
        });
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
.mic-consent-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1100;
    padding: 24px;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

.mic-consent-dialog {
    background: #fff;
    border-radius: 12px;
    padding: 32px;
    max-width: 480px;
    width: 100%;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
    animation: micConsentIn 0.22s ease;
    text-align: center;
}

@keyframes micConsentIn {
    from { transform: translateY(12px) scale(0.98); opacity: 0; }
    to   { transform: translateY(0)   scale(1);    opacity: 1; }
}

.mic-consent-icon {
    font-size: 40px;
    margin-bottom: 12px;
}

.mic-consent-title {
    margin: 0 0 8px 0;
    font-size: 22px;
    font-weight: 800;
    color: #1A1A1A;
}

.mic-consent-subtitle {
    margin: 0 0 12px 0;
    color: #1A1A1A;
    font-size: 16px;
    line-height: 1.4;
}

.mic-consent-disclaimer {
    font-size: 13px;
    color: #5A5A5A;
    margin: 0 0 16px 0;
    line-height: 1.5;
}

.mic-consent-actions {
    display: flex;
    gap: 16px;
    justify-content: center;
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
.ks-btn-primary:focus-visible {
    outline: 3px solid rgba(232, 0, 28, 0.4);
    outline-offset: 2px;
}

.ks-btn-secondary {
    background: transparent;
    border: 1px solid #EEE6D9;
    padding: 12px 24px;
    border-radius: 8px;
    cursor: pointer;
    color: #1A1A1A;
    font-weight: 600;
    transition: background 0.18s ease;
    min-width: 140px;
}

.ks-btn-secondary:hover { background: #F7F3EC; }
.ks-btn-secondary:focus-visible {
    outline: 3px solid rgba(26, 26, 46, 0.4);
    outline-offset: 2px;
}
</style>
