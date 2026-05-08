<template>
  <div class="kiosk-voice-wrapper">
    <button
      class="kiosk-voice-button"
      :class="{ 'is-listening': isListening, 'is-disabled': !isSupported }"
      :aria-label="ariaLabel"
      :aria-pressed="isListening ? 'true' : 'false'"
      :disabled="!isSupported"
      type="button"
      data-testid="kiosk-voice-button"
      @click="onMicClick"
    >
      <span class="kiosk-voice-icon" aria-hidden="true">
        {{ isListening ? '🎤' : '🔊' }}
      </span>
      <span class="kiosk-voice-label">{{ buttonLabel }}</span>
    </button>

    <transition name="kvo-fade">
      <div
        v-if="transcript && isSupported"
        class="kiosk-voice-transcript"
        role="status"
        aria-live="polite"
        data-testid="kiosk-voice-transcript"
      >
        {{ transcript }}
      </div>
    </transition>

    <p
      v-if="!isSupported"
      class="kiosk-voice-unsupported"
      role="note"
      data-testid="kiosk-voice-unsupported"
    >
      {{ unsupportedLabel }}
    </p>

    <KioskMicConsentDialog
      v-if="showConsent"
      @confirm="onConsentGranted"
      @cancel="onConsentDenied"
    />
  </div>
</template>

<script>
/**
 * V2-4 Phase A — KioskVoiceOrderingButton (greenfield, standalone).
 *
 * IMPORTANT : ce composant N'EST PAS importé par `KioskAppComponent` /
 * `KioskWizardComponent` (frozen zones). Il est livré seul, testable via
 * vitest et démontrable en preview. Une intégration future devra passer
 * par un wizard non-frozen ou un nouvel écran "Voice Mode".
 *
 * Émet :
 *   - 'voice-input'  : transcript final (string)
 *   - 'voice-error'  : code d'erreur (string)
 *   - 'voice-start'  : début écoute
 *   - 'voice-end'    : fin écoute
 *
 * Voir plans/PLAN_DESIGN_V2_4_VOICE_ORDERING_2026-05-08.md.
 */
import voiceService from '../../../services/kioskVoiceOrdering';
import KioskMicConsentDialog from './KioskMicConsentDialog.vue';

export default {
  name: 'KioskVoiceOrderingButton',

  components: { KioskMicConsentDialog },

  props: {
    /**
     * Permet d'injecter une instance custom (utile pour tests / multi-locale).
     * Par défaut : singleton runtime.
     */
    service: {
      type: Object,
      default: null,
    },
    lang: {
      type: String,
      default: 'fr-FR',
    },
  },

  emits: ['voice-input', 'voice-error', 'voice-start', 'voice-end'],

  data() {
    return {
      isSupported: false,
      isListening: false,
      transcript: '',
      lastError: null,
      // [A4 Privacy P2] Pre-flight consent state.
      // `showConsent` toggles the modal dialog; `hasGrantedThisSession` is
      // a SESSION-SCOPED flag stored in component state ONLY (no localStorage,
      // no sessionStorage, no cookie). Rationale : tracking consent across
      // kiosk sessions would imply a persistent identifier on a shared public
      // device — that is exactly what RGPD forbids without explicit opt-in.
      // Re-prompting on every kiosk session is the privacy-preserving default.
      showConsent: false,
      hasGrantedThisSession: false,
    };
  },

  computed: {
    ariaLabel() {
      return this.tr('kiosk.voice.button_label', this.isListening ? 'Arrêter la dictée vocale' : 'Démarrer la dictée vocale');
    },
    buttonLabel() {
      return this.isListening
        ? this.tr('kiosk.voice.listening', 'Écoute…')
        : this.tr('kiosk.voice.tap_to_speak', 'Toucher pour parler');
    },
    unsupportedLabel() {
      return this.tr('kiosk.voice.not_supported', 'Reconnaissance vocale non disponible sur ce navigateur.');
    },
    activeService() {
      return this.service || voiceService;
    },
  },

  mounted() {
    // Si la prop service injecte une nouvelle instance, on l'utilise telle
    // quelle. Sinon on travaille sur le singleton et on s'assure qu'il est
    // initialisé avec la bonne langue.
    const svc = this.activeService;
    if (svc && typeof svc.setLanguage === 'function' && this.lang) {
      svc.setLanguage(this.lang);
    }
    this.isSupported = svc && typeof svc.isSupported === 'function' ? svc.isSupported() : false;
    if (this.isSupported && typeof svc.initialize === 'function') {
      svc.initialize();
    }
    this.attachHandlers();
  },

  beforeUnmount() {
    this.detachHandlers();
    if (this.isListening && this.activeService && typeof this.activeService.stop === 'function') {
      this.activeService.stop();
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
     * [A4 Privacy P2] Entry point for any user click on the mic button.
     * - If the user is currently listening → stop (no consent needed to stop).
     * - If consent was already granted in this session → start directly.
     * - Otherwise → show pre-flight consent dialog. `start()` only fires
     *   AFTER explicit confirm — see `onConsentGranted()`.
     */
    onMicClick() {
      if (!this.isSupported) return;
      if (this.isListening) {
        this.activeService.stop();
        return;
      }
      if (this.hasGrantedThisSession) {
        this.startListening();
      } else {
        this.showConsent = true;
      }
    },

    /**
     * Legacy alias kept for backwards compatibility with any external caller
     * still wiring `toggle()`. Internally now routes through `onMicClick`.
     */
    toggle() {
      this.onMicClick();
    },

    /**
     * Actually starts the underlying recognition service. Must NEVER be
     * called directly from a click handler — always go through onMicClick →
     * (consent path) → here. Direct call would bypass consent dialog.
     */
    startListening() {
      if (!this.isSupported) return;
      this.transcript = '';
      this.activeService.start();
    },

    /**
     * [A4 Privacy P2] User explicitly granted mic access for this session.
     * Set the session-scoped flag, dismiss the dialog, then start listening
     * on the next tick (let the dialog unmount cleanly first).
     */
    onConsentGranted() {
      this.hasGrantedThisSession = true;
      this.showConsent = false;
      this.$nextTick(() => this.startListening());
    },

    /**
     * [A4 Privacy P2] User explicitly denied (or dismissed via Esc/overlay).
     * Just close the dialog — no `start()` call, no flag flip.
     */
    onConsentDenied() {
      this.showConsent = false;
    },

    handleResult({ transcript, isFinal }) {
      this.transcript = transcript || '';
      if (isFinal && transcript) {
        this.$emit('voice-input', transcript);
      }
    },

    handleStart() {
      this.isListening = true;
      this.$emit('voice-start');
    },

    handleEnd() {
      this.isListening = false;
      this.$emit('voice-end');
    },

    handleError({ error }) {
      this.lastError = error || 'unknown_error';
      this.isListening = false;
      // eslint-disable-next-line no-console
      console.warn('[VoiceOrdering] error:', this.lastError);
      this.$emit('voice-error', this.lastError);
    },

    attachHandlers() {
      const svc = this.activeService;
      if (!svc || typeof svc.on !== 'function') return;
      svc.on('result', this.handleResult);
      svc.on('start', this.handleStart);
      svc.on('end', this.handleEnd);
      svc.on('error', this.handleError);
    },

    detachHandlers() {
      const svc = this.activeService;
      if (!svc || typeof svc.off !== 'function') return;
      svc.off('result', this.handleResult);
      svc.off('start', this.handleStart);
      svc.off('end', this.handleEnd);
      svc.off('error', this.handleError);
    },
  },
};
</script>

<style scoped>
.kiosk-voice-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.kiosk-voice-button {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  padding: 16px 28px;
  border-radius: 50px;
  font-size: 17px;
  font-weight: 700;
  background: rgba(26, 26, 46, 0.92);
  color: white;
  border: 1px solid rgba(255, 255, 255, 0.15);
  cursor: pointer;
  transition: transform 0.15s ease, background 0.2s ease;
}

.kiosk-voice-button:hover:not(.is-disabled) {
  transform: translateY(-1px);
}

.kiosk-voice-button.is-listening {
  background: rgba(232, 0, 28, 0.92);
  animation: kvo-pulse 1.4s ease-in-out infinite;
}

.kiosk-voice-button.is-disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.kiosk-voice-icon {
  font-size: 20px;
}

.kiosk-voice-transcript {
  font-size: 15px;
  font-style: italic;
  color: rgba(26, 26, 46, 0.85);
  background: rgba(255, 255, 255, 0.85);
  padding: 8px 14px;
  border-radius: 8px;
  max-width: 320px;
  text-align: center;
}

.kiosk-voice-unsupported {
  font-size: 13px;
  color: rgba(26, 26, 46, 0.6);
  margin: 0;
}

.kvo-fade-enter-active,
.kvo-fade-leave-active {
  transition: opacity 0.2s ease;
}
.kvo-fade-enter-from,
.kvo-fade-leave-to {
  opacity: 0;
}

@keyframes kvo-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(232, 0, 28, 0.4); }
  50%      { box-shadow: 0 0 0 12px rgba(232, 0, 28, 0); }
}
</style>
