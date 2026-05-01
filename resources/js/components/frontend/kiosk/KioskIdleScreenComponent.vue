<template>
  <div
    class="kiosk-idle"
    data-testid="kiosk-idle-root"
  >
    <!-- [PHASE-37] Language selector — only if multiple languages enabled -->
    <div
      v-if="enabledLanguages.length > 1"
      class="kiosk-lang-selector"
      role="group"
      :aria-label="$t('kiosk.choose_language')"
      data-testid="kiosk-idle-lang-selector"
      @click.stop
    >
      <button type="button"
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
    <button type="button"
      class="kiosk-idle-a11y-btn"
      :aria-label="$t('kiosk.a11y.open')"
      data-testid="kiosk-idle-a11y-btn"
      @click.stop="openSettings"
      @keydown.enter.stop.prevent="openSettings"
      @keydown.space.stop.prevent="openSettings">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" />
        <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
        <path d="M12 4v1M12 19v1M4 12h1M19 12h1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
      </svg>
    </button>
    <KsA11ySettings v-model="settingsOpen" @click.stop />

    <!-- Vidéo de fond -->
    <video
      v-if="videoSrc"
      class="kiosk-idle-video"
      :src="videoSrc"
      autoplay
      loop
      muted
      playsinline
      ref="videoEl"
    />
    <!-- Fallback : fond animé gradient si pas de vidéo -->
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

      <div
        class="kiosk-order-type-chooser"
        role="group"
        :aria-label="text('kiosk.order_type.choose_label', 'Choisir le mode de commande')"
        data-testid="kiosk-order-type-chooser"
      >
        <button
          type="button"
          class="kiosk-order-type-card"
          data-testid="kiosk-order-type-dine-in"
          @click.stop="selectOrderTypeAndStart(orderTypes.KIOSK)"
          @touchstart.stop
        >
          <span class="kiosk-order-type-title">{{ text('kiosk.order_type.dine_in', 'Sur place') }}</span>
          <span class="kiosk-order-type-subtitle">{{ text('kiosk.order_type.dine_in_hint', 'Je mange ici') }}</span>
        </button>
        <button
          type="button"
          class="kiosk-order-type-card kiosk-order-type-card--takeaway"
          data-testid="kiosk-order-type-takeaway"
          @click.stop="selectOrderTypeAndStart(orderTypes.TAKEAWAY)"
          @touchstart.stop
        >
          <span class="kiosk-order-type-title">{{ text('kiosk.order_type.takeaway', 'À emporter') }}</span>
          <span class="kiosk-order-type-subtitle">{{ text('kiosk.order_type.takeaway_hint', 'Je récupère ma commande') }}</span>
        </button>
      </div>

      <p class="kiosk-idle-tap-hint">{{ text('kiosk.order_type.required_hint', 'Choisissez une option pour commencer') }}</p>
    </div>

    <!-- Bas de page -->
    <div class="kiosk-idle-footer" aria-hidden="true">
      <div class="kiosk-idle-footer-dot" v-for="n in 3" :key="n" :class="{ active: activeDot === n }" />
    </div>
  </div>
</template>

<script>
// [PHASE-37] Multi-language support
import { setLocale, getCurrentLocale } from '../../../i18n';
// [PHASE-4.4] A11y drawer (lang/AAA/PMR/audio).
import KsA11ySettings from './ds/KsA11ySettings.vue';
import { KIOSK_ORDER_TYPES } from '../../../store/modules/kioskCart';

export default {
  name: 'KioskIdleScreenComponent',
  emits: ['start-order'],
  components: { KsA11ySettings },
  data() {
    return {
      activeDot: 1,
      dotTimer: null,
      videoSrc: null,
      restaurantLogo: null,
      restaurantName: '',
      welcomeTitle: '',
      welcomeSubtitle: '',
      tapHint: '',
      settingsOpen: false,
      orderTypes: KIOSK_ORDER_TYPES,
      enabledLanguages: ['fr', 'en'], // Default, will be overridden by settings
      languageLabels: {
        fr: 'FR',
        en: 'EN',
        ar: 'العربية',
      },
    };
  },
  computed: {
    currentLocale() {
      return getCurrentLocale();
    },
  },
  watch: {
    videoSrc(src) {
      if (src) {
        // Attendre que Vue rende l'élément <video> avant d'appeler play()
        this.$nextTick(() => {
          this.$refs.videoEl?.play().catch(() => {});
        });
      }
    },
  },
  mounted() {
    this.applyLocalizedDefaults();
    this.loadSettings();
    this.startDotAnimation();
    // Always clear any leftover cart when landing on idle (back-nav, timeout, etc.)
    this.$store.dispatch('kioskCart/reset');
  },
  beforeUnmount() {
    clearInterval(this.dotTimer);
  },
  methods: {
    applyLocalizedDefaults() {
      this.restaurantName = this.$t('kiosk.idle_screen.default_restaurant_name');
      this.welcomeTitle = this.$t('kiosk.idle_screen.default_title');
      this.welcomeSubtitle = this.$t('kiosk.idle_screen.default_subtitle');
      this.tapHint = this.$t('kiosk.idle_screen.default_tap_hint');
    },
    text(key, fallback) {
      const value = this.$t(key);
      return value && value !== key ? value : fallback;
    },
    selectOrderTypeAndStart(orderType) {
      this.$store.dispatch('kioskCart/setOrderType', orderType);
      this.$emit('start-order', orderType);
      this.$router.push({ name: 'kiosk.categories' });
    },
    changeLanguage(lang) {
      // [PHASE-37] Change locale and reload page to apply RTL if needed
      if (this.currentLocale !== lang) {
        setLocale(lang);
        // [PHASE-4.4] Mettre à jour le store kioskSettings — useKioskA11y
        //             applique data-kiosk-* / lang / dir sans reload.
        try { this.$store.dispatch('kioskSettings/setLocale', lang); } catch (_) {}
        // Force reload to apply RTL and re-render all translations
        window.location.reload();
      }
    },
    openSettings() {
      this.settingsOpen = true;
    },
    startDotAnimation() {
      this.dotTimer = setInterval(() => {
        this.activeDot = (this.activeDot % 3) + 1;
      }, 800);
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
      } catch (_) {}
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
  background: var(--kiosk-idle-bg);
  color: var(--kiosk-text);
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

.kiosk-idle-fallback {
  position: absolute;
  inset: 0;
  background: var(--kiosk-idle-bg);
  z-index: 0;
}

.kiosk-idle-fallback::before {
  content: '🍔  🌯  🍟  🥤  🍗';
  position: absolute;
  inset-inline: -12%;
  top: 38%;
  color: rgba(255,255,255,0.10);
  font-size: clamp(72px, 13vw, 160px);
  font-weight: 900;
  letter-spacing: 0.16em;
  white-space: nowrap;
  transform: rotate(-12deg);
  animation: fkIdleDrift 14s ease-in-out infinite alternate;
}

.kiosk-idle-fallback::after {
  content: '';
  position: absolute;
  inset: 0;
  background:
    linear-gradient(115deg, rgba(0,0,0,0.38) 0%, transparent 42%),
    radial-gradient(ellipse 70% 58% at 50% 70%, rgba(0,0,0,0.26), transparent 70%);
  pointer-events: none;
}

@keyframes fkIdleDrift {
  from { transform: translateX(-3%) rotate(-12deg); }
  to   { transform: translateX(3%) rotate(-12deg); }
}

/* Overlay */
.kiosk-idle-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, rgba(0,0,0,0.18) 0%, rgba(0,0,0,0.22) 46%, rgba(0,0,0,0.68) 100%);
  z-index: 1;
}

/* Contenu */
.kiosk-idle-content {
  position: relative;
  z-index: 2;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 26px;
  width: min(900px, calc(100vw - 80px));
  min-height: 76vh;
  justify-content: center;
  padding: 48px 40px 120px;
  text-align: start;
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
  color: var(--kiosk-idle-text, white);
  margin: 0;
  text-shadow: 0 4px 20px rgba(0,0,0,0.5);
  letter-spacing: 0;
  text-transform: uppercase;
}

.kiosk-idle-headline {
  display: flex;
  flex-direction: column;
  gap: var(--kiosk-space-2, 8px);
}

.kiosk-idle-title {
  font-size: clamp(54px, 8.4vw, 96px);
  font-weight: var(--kiosk-font-weight-black, 900);
  color: var(--kiosk-idle-text, white);
  margin: 0;
  line-height: 0.98;
  text-shadow: 0 2px 16px rgba(0,0,0,0.4);
  letter-spacing: 0;
  animation: fadeInUp 0.8s ease;
}

.kiosk-idle-subtitle {
  max-width: 680px;
  font-size: clamp(22px, 3vw, 34px);
  line-height: 1.28;
  color: var(--kiosk-idle-muted, rgba(255,255,255,0.88));
  margin: 0;
  animation: fadeInUp 0.8s ease 0.2s both;
}

/* CTA pulse Splash-style */
.kiosk-idle-cta {
  position: relative;
  width: 108px;
  height: 108px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 4px 0 2px;
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

.kiosk-order-type-chooser {
  display: grid;
  grid-template-columns: repeat(2, minmax(220px, 1fr));
  gap: 20px;
  width: min(820px, calc(100vw - 48px));
  animation: fadeInUp 0.8s ease 0.35s both;
}

.kiosk-order-type-card {
  min-height: 132px;
  min-width: var(--kiosk-tap-min, 56px);
  padding: 26px 28px;
  border: 3px solid rgba(255,255,255,0.50);
  border-radius: 32px;
  background: var(--kiosk-idle-card-bg);
  color: var(--kiosk-idle-card-text);
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  box-shadow: 0 22px 60px rgba(0,0,0,0.28);
  transition: transform 0.14s ease, background 0.14s ease, border-color 0.14s ease, box-shadow 0.14s ease;
}

.kiosk-order-type-card:hover,
.kiosk-order-type-card:focus-visible {
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  border-color: rgba(255,255,255,0.86);
  box-shadow: 0 26px 70px rgba(232,0,28,0.42);
  outline: 4px solid rgba(255,255,255,0.78);
  outline-offset: 4px;
  transform: translateY(-3px);
}

.kiosk-order-type-card:active {
  transform: translateY(0) scale(0.98);
}

.kiosk-order-type-card--takeaway:hover,
.kiosk-order-type-card--takeaway:focus-visible {
  background: #0F8A62;
  color: #FFFFFF;
  box-shadow: 0 26px 70px rgba(15,138,98,0.36);
}

.kiosk-order-type-title {
  font-size: clamp(30px, 4vw, 42px);
  font-weight: var(--kiosk-font-weight-black, 900);
  line-height: 1;
}

.kiosk-order-type-subtitle {
  font-size: calc(17px * var(--kiosk-text-scale, 1));
  color: currentColor;
  opacity: 0.76;
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
  transition:
    width 0.3s ease,
    background-color 0.3s ease,
    border-radius 0.3s ease;
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

@media (max-width: 720px) {
  .kiosk-order-type-chooser {
    grid-template-columns: 1fr;
    width: min(420px, calc(100vw - 36px));
  }

  .kiosk-order-type-card {
    min-height: 94px;
    padding: 18px;
  }
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
  min-height: var(--kiosk-touch-min, 48px);
  min-width: var(--kiosk-touch-min, 48px);
  padding: 8px 16px;
  border-radius: 999px;
  border: 1.5px solid rgba(255,255,255,0.3);
  background: rgba(0,0,0,0.4);
  color: rgba(255,255,255,0.9);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    color 0.2s ease,
    box-shadow 0.2s ease,
    transform 0.2s ease;
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
  width: 60px;
  height: 60px;
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
</style>
