<template>
  <div
    class="kiosk-idle"
    role="button"
    tabindex="0"
    :aria-label="$t('kiosk.idle_screen.default_tap_hint', tapHint)"
    data-testid="kiosk-idle-root"
    @touchstart.prevent="handleIdleTouch"
    @click="handleIdleClick"
    @keydown.enter.prevent="startOrder"
    @keydown.space.prevent="startOrder"
  >
    <!--
      [PHASE-37] Language selector — only if multiple languages enabled.
      [FR-LOCK 2026-05-08 ADR-007] Sélecteur désactivé (v-if="false") sous policy
      FR-lock V1 : `ensureKioskLocale()` (router.beforeEach) reforce `fr` sur chaque
      navigation `/kiosk/*`, ce qui rendait le switch UI dead (la locale repassait
      à fr immédiatement après reload). `enabledLanguages`/`loadSettings`/imports
      restent en place pour permettre la réactivation V2 (cf. ADR-007 §Phase 2).
      Voir docs/ADR/ADR-2026-05-09-kiosk-locale-policy.md.
    -->
    <div
      v-if="false"
      class="kiosk-lang-selector"
      role="group"
      :aria-label="$t('kiosk.choose_language')"
      data-testid="kiosk-idle-lang-selector"
      @click.stop
    >
      <button
        v-for="lang in enabledLanguages"
        :key="lang"
        class="kiosk-lang-btn"
        :class="{ active: currentLocale === lang }"
        :aria-pressed="String(currentLocale === lang)"
        :data-testid="`kiosk-idle-lang-${lang}`"
        @click="changeLanguage(lang)"
      >
        {{ languageLabels[lang] }}
      </button>
    </div>

    <!-- [PHASE-4.4] A11y settings button — opens drawer with lang/AAA/PMR/audio -->
    <button
      type="button"
      class="kiosk-idle-a11y-btn"
      :aria-label="$t('kiosk.a11y.open')"
      data-testid="kiosk-idle-a11y-btn"
      @click.stop="openSettings"
      @keydown.enter.stop.prevent="openSettings"
      @keydown.space.stop.prevent="openSettings"
    >
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" />
        <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
        <path d="M12 4v1M12 19v1M4 12h1M19 12h1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
      </svg>
    </button>
    <KsA11ySettings v-model="settingsOpen" @click.stop />

    <!-- Vidéo de fond — UX 4.7 : fallback animé si video stalled/error/timeout 3s -->
    <video
      v-if="videoSrc && !videoFailed"
      class="kiosk-idle-video"
      :src="videoSrc"
      autoplay
      loop
      muted
      playsinline
      ref="videoEl"
      @error="onVideoError"
      @stalled="onVideoStalled"
      @loadstart="onVideoLoadstart"
      @loadeddata="onVideoLoaded"
    />
    <!-- Fallback : fond animé gradient si pas de vidéo OU video failed -->
    <div v-else class="kiosk-idle-fallback" />

    <!-- Overlay sombre -->
    <div class="kiosk-idle-overlay" />

    <!-- Contenu central -->
    <div class="kiosk-idle-content">
      <!-- Logo restaurant -->
      <div class="kiosk-idle-logo-wrap" v-if="restaurantLogo">
        <img :src="restaurantLogo" class="kiosk-idle-logo" alt="" data-testid="kiosk-idle-logo" />
      </div>
      <h1 v-else class="kiosk-idle-brand" data-testid="kiosk-idle-brand">{{ restaurantName }}</h1>

      <!-- Message principal -->
      <div class="kiosk-idle-headline">
        <h2 class="kiosk-idle-title" data-testid="kiosk-idle-title">{{ welcomeTitle }}</h2>
        <p class="kiosk-idle-subtitle">{{ welcomeSubtitle }}</p>
      </div>

      <!-- CTA animé Splash-style — décoratif, a11y géré au niveau du .kiosk-idle root -->
      <div class="kiosk-idle-cta" aria-hidden="true">
        <div class="kiosk-idle-pulse-ring" />
        <div class="kiosk-idle-pulse-ring delay-1" />
        <div class="kiosk-idle-touch-btn" data-testid="kiosk-idle-touch-btn">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <path d="M24 8C15.2 8 8 15.2 8 24s7.2 16 16 16 16-7.2 16-16S32.8 8 24 8zm0 28c-6.6 0-12-5.4-12-12S17.4 12 24 12s12 5.4 12 12-5.4 12-12 12zm-2-8l8-4-8-4v8z" fill="white"/>
          </svg>
        </div>
      </div>

      <p class="kiosk-idle-tap-hint">{{ tapHint }}</p>
    </div>

    <!-- Bas de page -->
    <div class="kiosk-idle-footer" aria-hidden="true">
      <div class="kiosk-idle-footer-dot" v-for="n in 3" :key="n" :class="{ active: activeDot === n }" />
    </div>

    <!-- V2-4 — Voice ordering CTA (additif, opt-in via kioskSettings.voiceOrderingEnabled).
         Bottom-right de l'idle screen, ne touche PAS aux frozen wizards. -->
    <div
      v-if="isVoiceFeatureEnabled"
      class="kiosk-idle-voice-cta"
      data-testid="kiosk-idle-voice-cta"
      @click.stop
    >
      <KioskVoiceOrderingButton
        :lang="voiceLang"
        @voice-input="onVoiceInput"
        @voice-error="onVoiceError"
      />
    </div>

    <!-- V2-4 — Confirmation dialog : pré-flight avant routing vers wizard. -->
    <KioskVoiceOrderingDialog
      v-if="showVoiceDialog"
      :transcript="voiceTranscript"
      @confirm="onConfirmVoice"
      @cancel="onCancelVoice"
    />
  </div>
</template>

<script>
// [PHASE-37] Multi-language support
import { setLocale, getCurrentLocale } from '../../../i18n';
// [PHASE-4.4] A11y drawer (lang/AAA/PMR/audio).
import KsA11ySettings from './ds/KsA11ySettings.vue';
// V2-4 Phase A — Voice ordering CTA + dialog (additif, opt-in via settings).
import KioskVoiceOrderingButton from './KioskVoiceOrderingButton.vue';
import KioskVoiceOrderingDialog from './KioskVoiceOrderingDialog.vue';

export default {
  name: 'KioskIdleScreenComponent',
  emits: ['start-order'],
  components: { KsA11ySettings, KioskVoiceOrderingButton, KioskVoiceOrderingDialog },
  data() {
    return {
      activeDot: 1,
      dotTimer: null,
      videoSrc: null,
      // UX 4.7 — état du chargement vidéo. Si la vidéo idle échoue (network,
      // codec) ou n'envoie aucun signal de chargement après 3s, on bascule
      // sur le fallback animé pour éviter un écran noir 5min.
      videoLoaded: false,
      videoFailed: false,
      videoTimeoutHandle: null,
      restaurantLogo: null,
      restaurantName: '',
      welcomeTitle: '',
      welcomeSubtitle: '',
      tapHint: '',
      settingsOpen: false,
      enabledLanguages: ['fr', 'en'], // Default, will be overridden by settings
      languageLabels: {
        fr: 'FR',
        en: 'EN',
        ar: 'العربية',
      },
      // V2-4 — Voice ordering state (additif, opt-in via kioskSettings).
      // Default OFF : safe rollout — owner enables via settings store /
      // kiosk admin once micro permission + HTTPS conditions sont validées.
      isVoiceFeatureEnabled: false,
      showVoiceDialog: false,
      voiceTranscript: '',
    };
  },
  computed: {
    currentLocale() {
      return getCurrentLocale();
    },
    // V2-4 — map de la locale courante vers la langue Web Speech API.
    // [FR-LOCK 2026-05-08 ADR-007] Sous policy FR-lock V1, le runtime kiosk est
    // toujours `fr` (cf. `ensureKioskLocale()` dans i18n.js). Le switch est
    // simplifié en constante `'fr-FR'` pour refléter explicitement l'invariant
    // et fermer la dead branch `en-US`/`ar-SA`. La logique multi-locale sera
    // réintroduite quand la policy sera relâchée (cf. ADR-007 §Phase 2 BACKLOG).
    voiceLang() {
      return 'fr-FR';
    },
  },
  watch: {
    videoSrc(src) {
      if (src) {
        // Reset l'état avant chaque nouvelle source video.
        this.videoLoaded = false;
        this.videoFailed = false;
        this.armVideoTimeout();
        // Attendre que Vue rende l'élément <video> avant d'appeler play()
        this.$nextTick(() => {
          this.$refs.videoEl?.play().catch(() => {
            // play() peut échouer (autoplay policy, codec). On bascule
            // proprement sur le fallback animé.
            this.videoFailed = true;
          });
        });
      }
    },
  },
  mounted() {
    this.applyLocalizedDefaults();
    this.loadSettings();
    this.startDotAnimation();
    this.applyVoiceFeatureFlag();
    // Always clear any leftover cart when landing on idle (back-nav, timeout, etc.)
    this.$store.dispatch('kioskCart/reset');
  },
  beforeUnmount() {
    clearInterval(this.dotTimer);
    if (this.videoTimeoutHandle) {
      clearTimeout(this.videoTimeoutHandle);
      this.videoTimeoutHandle = null;
    }
  },
  methods: {
    applyLocalizedDefaults() {
      this.restaurantName = this.$t('kiosk.idle_screen.default_restaurant_name');
      this.welcomeTitle = this.$t('kiosk.idle_screen.default_title');
      this.welcomeSubtitle = this.$t('kiosk.idle_screen.default_subtitle');
      this.tapHint = this.$t('kiosk.idle_screen.default_tap_hint');
    },
    handleIdleTouch() {
      // touchstart fires before the synthetic click — set a flag so handleIdleClick ignores it.
      this._touchActivated = true;
      this.startOrder();
      // Clear flag after the synthetic click window (300ms is the classic delay on most browsers)
      setTimeout(() => { this._touchActivated = false; }, 400);
    },
    handleIdleClick() {
      // Ignore the synthetic click that follows a touchstart (already handled above)
      if (this._touchActivated) return;
      this.startOrder();
    },
    startOrder() {
      this.$emit('start-order');
      this.$router.push({ name: 'kiosk.categories' });
    },
    changeLanguage(lang) {
      // [FR-LOCK 2026-05-08 ADR-007] No-op sous policy FR-lock V1.
      // L'ancienne logique appelait `setLocale(lang)` + `window.location.reload()`,
      // mais `ensureKioskLocale()` (router.beforeEach) reforce `fr` sur la
      // prochaine nav, rendant ce switch UI dead. Le sélecteur est désormais
      // hidden (`v-if="false"`), donc cette méthode n'est plus appelée. Conservée
      // pour préserver le call-graph et permettre la réactivation V2 (cf. ADR-007
      // §Phase 2 BACKLOG : déverrouiller `KIOSK_LOCALE`, scoper sessionStorage,
      // compléter `ar.json` clés `kiosk.voice.*` / `kiosk.builder.*` / `kiosk.admin.*`).
      // eslint-disable-next-line no-unused-vars
      void lang;
    },
    openSettings() {
      this.settingsOpen = true;
    },
    startDotAnimation() {
      this.dotTimer = setInterval(() => {
        this.activeDot = (this.activeDot % 3) + 1;
      }, 800);
    },
    // UX 4.7 — gestion du fallback vidéo
    armVideoTimeout() {
      if (this.videoTimeoutHandle) clearTimeout(this.videoTimeoutHandle);
      this.videoTimeoutHandle = setTimeout(() => {
        if (!this.videoLoaded) {
          this.videoFailed = true;
        }
      }, 3000);
    },
    onVideoLoadstart() {
      // Le navigateur a démarré le chargement, le pipeline est vivant.
      this.videoLoaded = true;
      if (this.videoTimeoutHandle) {
        clearTimeout(this.videoTimeoutHandle);
        this.videoTimeoutHandle = null;
      }
    },
    onVideoLoaded() {
      this.videoLoaded = true;
    },
    onVideoError() {
      this.videoFailed = true;
    },
    onVideoStalled() {
      // Si la vidéo stalled avant d'être considérée comme chargée, fallback.
      if (!this.videoLoaded) this.videoFailed = true;
    },
    async loadSettings() {
      try {
        const res = await this.$store.dispatch('frontendSetting/lists', { vuex: false });
        const data = res?.data?.data || res?.data || {};

        // [KIOSK-12-1] Use logo_full_path (alias of theme_logo added in SettingResource)
        this.restaurantName = data.company_name || data.site_name || this.$t('kiosk.idle_screen.default_restaurant_name');
        this.restaurantLogo = data.logo_full_path || data.theme_logo || null;

        // [KIOSK-12-1] Kiosk idle video — null means animated gradient fallback
        this.videoSrc = data.kiosk_idle_video || null;

        // [KIOSK-12-2] Configurable idle screen texts with locale-aware defaults
        if (data.kiosk_welcome_title)    this.welcomeTitle    = data.kiosk_welcome_title;
        if (data.kiosk_welcome_subtitle) this.welcomeSubtitle = data.kiosk_welcome_subtitle;
        if (data.kiosk_tap_hint)         this.tapHint         = data.kiosk_tap_hint;

        // [PHASE-37] Load enabled languages from settings
        if (data.kiosk_languages_enabled) {
          this.enabledLanguages = data.kiosk_languages_enabled;
        }

        // V2-4 — Voice ordering settings (server-side opt-in, defaults OFF).
        if (typeof data.kiosk_voice_ordering_enabled !== 'undefined') {
          this.isVoiceFeatureEnabled = !!data.kiosk_voice_ordering_enabled;
        }
      } catch (_) {}
    },

    // V2-4 — Voice ordering handlers (additif, opt-in via settings).
    applyVoiceFeatureFlag() {
      // Lire d'abord le flag store kioskSettings si le module est branché ;
      // sinon attendre loadSettings(). Default OFF — safe rollout.
      try {
        const flag = this.$store?.state?.kioskSettings?.voiceOrderingEnabled;
        if (typeof flag !== 'undefined') {
          this.isVoiceFeatureEnabled = !!flag;
        }
      } catch (_) {
        // store non câblé — ignore, loadSettings() prendra le relais.
      }
    },
    onVoiceInput(transcript) {
      const trimmed = (transcript || '').trim();
      if (!trimmed) return;
      this.voiceTranscript = trimmed;
      this.showVoiceDialog = true;
    },
    onVoiceError(error) {
      // Erreur micro / browser non supporté / pas de speech captured.
      // On ne bloque PAS le parcours kiosk (fallback tap/click intact).
      // eslint-disable-next-line no-console
      console.warn('[KioskIdle][Voice] error:', error);
    },
    onConfirmVoice() {
      const intent = this.voiceTranscript;
      this.showVoiceDialog = false;
      this.voiceTranscript = '';
      // Route vers wizard avec voice_intent en query — le wizard
      // (frozen côté composant, mais lit ses query params) pourra dans une
      // wave ultérieure parser intent → pré-remplir le panier.
      this.$emit('start-order');
      this.$router.push({
        name: 'kiosk.categories',
        query: { voice_intent: intent },
      });
    },
    onCancelVoice() {
      this.showVoiceDialog = false;
      this.voiceTranscript = '';
    },
  },
};
</script>

<style scoped>
.kiosk-idle {
  position: relative;
  width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  cursor: pointer;
}

/* Vidéo de fond */
.kiosk-idle-video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  z-index: 0;
}

/* Fallback Splash DNA — fond très sombre + lueur radiale rouge subtile */
.kiosk-idle-fallback {
  position: absolute;
  inset: 0;
  background: #0C0C14;
  z-index: 0;
}

/* Lueur radiale centrale style Splash */
.kiosk-idle-fallback::after {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse 80% 60% at 50% 65%, rgba(232,0,28,0.15), transparent 70%);
  pointer-events: none;
}

/* Overlay */
.kiosk-idle-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(to bottom, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.55) 60%, rgba(0,0,0,0.8) 100%);
  z-index: 1;
}

/* Contenu */
.kiosk-idle-content {
  position: relative;
  z-index: 2;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 32px;
  padding: 0 40px;
  text-align: center;
}

.kiosk-idle-logo-wrap {
  animation: floatUpDown 3s ease-in-out infinite;
}

@keyframes floatUpDown {
  0%, 100% { transform: translateY(0); }
  50%       { transform: translateY(-8px); }
}

.kiosk-idle-logo {
  width: 140px;
  height: 140px;
  object-fit: contain;
  filter: drop-shadow(0 8px 24px rgba(0,0,0,0.6));
}

.kiosk-idle-brand {
  font-size: calc(var(--kiosk-font-size-hero, 64px) * var(--kiosk-text-scale, 1));
  font-weight: var(--kiosk-font-weight-black, 900);
  color: white;
  margin: 0;
  text-shadow: 0 4px 20px rgba(0,0,0,0.5);
  letter-spacing: var(--kiosk-letter-spacing-tight, -1px);
}

.kiosk-idle-headline {
  display: flex;
  flex-direction: column;
  gap: var(--kiosk-space-2, 8px);
}

.kiosk-idle-title {
  font-size: calc(var(--kiosk-font-size-display, 48px) * var(--kiosk-text-scale, 1));
  font-weight: var(--kiosk-font-weight-black, 900);
  color: white;
  margin: 0;
  text-shadow: 0 2px 16px rgba(0,0,0,0.4);
  letter-spacing: var(--kiosk-letter-spacing-tight, -0.5px);
  animation: fadeInUp 0.8s ease;
}

.kiosk-idle-subtitle {
  font-size: calc(var(--kiosk-font-size-subtitle, 22px) * var(--kiosk-text-scale, 1));
  color: rgba(255,255,255,0.75);
  margin: 0;
  animation: fadeInUp 0.8s ease 0.2s both;
}

/* CTA pulse Splash-style */
.kiosk-idle-cta {
  position: relative;
  width: 120px;
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 16px 0;
}

.kiosk-idle-pulse-ring {
  position: absolute;
  width: 120px;
  height: 120px;
  border-radius: 50%;
  border: 3px solid rgba(232, 0, 28, 0.5);
  animation: pulseRing 2s ease-out infinite;
}

.kiosk-idle-pulse-ring.delay-1 {
  animation-delay: 0.7s;
  border-color: rgba(232, 0, 28, 0.3);
}

@keyframes pulseRing {
  0%   { transform: scale(0.8); opacity: 1; }
  100% { transform: scale(1.6); opacity: 0; }
}

.kiosk-idle-touch-btn {
  width: 88px;
  height: 88px;
  background: var(--kiosk-primary);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 0 40px rgba(232, 0, 28, 0.6);
  animation: btnPulse 2s ease-in-out infinite;
  position: relative;
  z-index: 2;
}

@keyframes btnPulse {
  0%, 100% { transform: scale(1); box-shadow: 0 0 40px rgba(232,0,28,0.6); }
  50%       { transform: scale(1.05); box-shadow: 0 0 60px rgba(232,0,28,0.8); }
}

.kiosk-idle-tap-hint {
  font-size: calc(var(--kiosk-font-size-body, 20px) * var(--kiosk-text-scale, 1));
  color: rgba(255,255,255,0.85);
  margin: 0;
  letter-spacing: var(--kiosk-letter-spacing-wide, 0.5px);
  animation: fadeInUp 0.8s ease 0.4s both;
}

/* A11y focus ring on the root interactive zone */
.kiosk-idle:focus-visible {
  outline: var(--kiosk-focus-width, 4px) solid var(--kiosk-focus-ring, #2563EB);
  outline-offset: -8px;
}

/* Footer dots */
.kiosk-idle-footer {
  position: absolute;
  bottom: 40px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 2;
  display: flex;
  gap: 10px;
}

.kiosk-idle-footer-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgba(255,255,255,0.3);
  transition: all 0.3s ease;
}

.kiosk-idle-footer-dot.active {
  width: 28px;
  border-radius: 4px;
  background: var(--kiosk-primary);
}

@keyframes fadeInUp {
  from { transform: translateY(20px); opacity: 0; }
  to   { transform: translateY(0);    opacity: 1; }
}

/* [PHASE-37] Language selector */
.kiosk-lang-selector {
  position: absolute;
  top: 24px;
  right: 24px;
  z-index: 10;
  display: flex;
  gap: 8px;
  animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to   { opacity: 1; transform: translateY(0); }
}

.kiosk-lang-btn {
  padding: 8px 16px;
  border-radius: 20px;
  border: 1.5px solid rgba(255,255,255,0.3);
  /* UX 4.9 : WCAG AA fix — fond renforcé 0.4 → 0.6 sur background sombre.
     Ratio rgba(255,255,255,0.9) text on rgba(0,0,0,0.6) bg ≈ 4.7:1 (AA pass). */
  background: rgba(0,0,0,0.6);
  color: rgba(255,255,255,0.9);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  transition: all 0.2s ease;
  min-width: 44px;
}

.kiosk-lang-btn:hover {
  background: rgba(255,255,255,0.15);
  border-color: rgba(255,255,255,0.5);
}

.kiosk-lang-btn.active {
  background: var(--kiosk-primary);
  border-color: var(--kiosk-primary);
  color: white;
  box-shadow: 0 2px 12px rgba(232, 0, 28, 0.4);
}

/* RTL support for Arabic */
[dir="rtl"] .kiosk-lang-selector {
  right: auto;
  left: 24px;
}

/* [PHASE-4.4] A11y settings button — bas gauche, discret mais accessible.
   Taille conforme PMR (56x56 minimum), focus visible.                   */
.kiosk-idle-a11y-btn {
  position: absolute;
  bottom: 24px;
  left: 24px;
  z-index: 10;
  width: 56px;
  height: 56px;
  min-width: var(--kiosk-tap-min, 56px);
  min-height: var(--kiosk-tap-min, 56px);
  border-radius: 50%;
  border: 1.5px solid rgba(255,255,255,0.3);
  background: rgba(0,0,0,0.45);
  color: rgba(255,255,255,0.92);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s ease, transform 0.1s ease, border-color 0.15s ease;
}

.kiosk-idle-a11y-btn:hover {
  background: rgba(0,0,0,0.7);
  border-color: rgba(255,255,255,0.55);
}

.kiosk-idle-a11y-btn:active { transform: scale(0.96); }

.kiosk-idle-a11y-btn:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, #fff);
  outline-offset: 3px;
}

[dir="rtl"] .kiosk-idle-a11y-btn {
  left: auto;
  right: 24px;
}

/* V2-4 — Voice ordering CTA, bottom-right, additif sans toucher les zones existantes. */
.kiosk-idle-voice-cta {
  position: absolute;
  bottom: 24px;
  right: 24px;
  z-index: 10;
  /* Le bouton enfant gère son propre fond / animation pulse en écoute. */
}

[dir="rtl"] .kiosk-idle-voice-cta {
  right: auto;
  left: 24px;
}
</style>
