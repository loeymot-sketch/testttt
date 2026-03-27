<template>
  <div class="kiosk-idle" @touchstart.prevent="handleIdleTouch" @click="handleIdleClick">
    <!-- [PHASE-37] Language selector — only if multiple languages enabled -->
    <div v-if="enabledLanguages.length > 1" class="kiosk-lang-selector" @click.stop>
      <button
        v-for="lang in enabledLanguages"
        :key="lang"
        class="kiosk-lang-btn"
        :class="{ active: currentLocale === lang }"
        @click="changeLanguage(lang)"
      >
        {{ languageLabels[lang] }}
      </button>
    </div>

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
        <img :src="restaurantLogo" class="kiosk-idle-logo" alt="Logo" />
      </div>
      <h1 v-else class="kiosk-idle-brand">{{ restaurantName }}</h1>

      <!-- Message principal -->
      <div class="kiosk-idle-headline">
        <h2 class="kiosk-idle-title">{{ welcomeTitle }}</h2>
        <p class="kiosk-idle-subtitle">{{ welcomeSubtitle }}</p>
      </div>

      <!-- CTA animé Splash-style -->
      <div class="kiosk-idle-cta">
        <div class="kiosk-idle-pulse-ring" />
        <div class="kiosk-idle-pulse-ring delay-1" />
        <div class="kiosk-idle-touch-btn">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <path d="M24 8C15.2 8 8 15.2 8 24s7.2 16 16 16 16-7.2 16-16S32.8 8 24 8zm0 28c-6.6 0-12-5.4-12-12S17.4 12 24 12s12 5.4 12 12-5.4 12-12 12zm-2-8l8-4-8-4v8z" fill="white"/>
          </svg>
        </div>
      </div>

      <p class="kiosk-idle-tap-hint">{{ tapHint }}</p>
    </div>

    <!-- Bas de page -->
    <div class="kiosk-idle-footer">
      <div class="kiosk-idle-footer-dot" v-for="n in 3" :key="n" :class="{ active: activeDot === n }" />
    </div>
  </div>
</template>

<script>
// [PHASE-37] Multi-language support
import { setLocale, getCurrentLocale } from '../../../i18n';

export default {
  name: 'KioskIdleScreenComponent',
  emits: ['start-order'],
  data() {
    return {
      activeDot: 1,
      dotTimer: null,
      videoSrc: null,
      restaurantLogo: null,
      restaurantName: 'Notre Restaurant',
      welcomeTitle: 'Bienvenue !',
      welcomeSubtitle: 'Commandez en quelques touches',
      tapHint: 'Touchez l\'écran pour commander',
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
    this.loadSettings();
    this.startDotAnimation();
    // Always clear any leftover cart when landing on idle (back-nav, timeout, etc.)
    this.$store.dispatch('kioskCart/reset');
  },
  beforeUnmount() {
    clearInterval(this.dotTimer);
  },
  methods: {
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
      // [PHASE-37] Change locale and reload page to apply RTL if needed
      if (this.currentLocale !== lang) {
        setLocale(lang);
        // Force reload to apply RTL and re-render all translations
        window.location.reload();
      }
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
        this.restaurantName = data.company_name || data.site_name || 'Notre Restaurant';
        this.restaurantLogo = data.logo_full_path || data.theme_logo || null;

        // [KIOSK-12-1] Kiosk idle video — null means animated gradient fallback
        this.videoSrc = data.kiosk_idle_video || null;

        // [KIOSK-12-2] Configurable idle screen texts with sensible French defaults
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
  font-size: 52px;
  font-weight: 900;
  color: white;
  margin: 0;
  text-shadow: 0 4px 20px rgba(0,0,0,0.5);
  letter-spacing: -1px;
}

.kiosk-idle-headline {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.kiosk-idle-title {
  font-size: 48px;
  font-weight: 900;
  color: white;
  margin: 0;
  text-shadow: 0 2px 16px rgba(0,0,0,0.4);
  letter-spacing: -0.5px;
  animation: fadeInUp 0.8s ease;
}

.kiosk-idle-subtitle {
  font-size: 22px;
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
  font-size: 20px;
  color: rgba(255,255,255,0.85);
  margin: 0;
  letter-spacing: 0.5px;
  animation: fadeInUp 0.8s ease 0.4s both;
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
  background: rgba(0,0,0,0.4);
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
</style>
