<template>
  <!-- CV1-KIOSK-VISUAL-REDESIGN-001 V2.B — Bold Appétissant refonte
       Plan : plans/PLAN_CV1-KIOSK-VISUAL-REDESIGN-001_2026-05-02.md §9.2.1
       Refonte : template + style uniquement. Script intact (data, methods,
       computed, watchers, lifecycle, refs, emits). Tous les data-testid
       conservés à l'identique pour ne pas casser les sentinels Vitest /
       Playwright (cf. tests/e2e/c3-runtime-multi-surface.spec.js, etc.). -->
  <div
    class="kiosk-idle kiosk-idle--bold"
    data-testid="kiosk-idle-root"
  >
    <!-- Floating top-right — langue + a11y settings -->
    <div class="kiosk-idle-floating">
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

      <!-- [PHASE-4.4] A11y settings button — opens drawer with lang/AAA/PMR/audio/theme -->
      <button type="button"
        class="kiosk-idle-a11y-btn"
        :aria-label="$t('kiosk.a11y.open')"
        data-testid="kiosk-idle-a11y-btn"
        @click.stop="openSettings"
        @keydown.enter.stop.prevent="openSettings"
        @keydown.space.stop.prevent="openSettings">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8" />
          <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          <path d="M12 4v1M12 19v1M4 12h1M19 12h1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
        </svg>
      </button>
    </div>
    <KsA11ySettings v-model="settingsOpen" @click.stop />

    <!-- Vidéo de fond (héritée — toujours fonctionnelle) -->
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
    <!-- Fallback : fond animé gradient warm si pas de vidéo -->
    <div v-else class="kiosk-idle-fallback" />

    <!-- Overlay warm gradient (chaud, pas le noir froid actuel) -->
    <div class="kiosk-idle-overlay" />

    <!-- Décor : food emojis flottants (très subtils, fond uniquement) -->
    <div class="kiosk-idle-decor" aria-hidden="true">
      <span class="kiosk-idle-decor-em em-1">🌮</span>
      <span class="kiosk-idle-decor-em em-2">🍔</span>
      <span class="kiosk-idle-decor-em em-3">🍟</span>
    </div>

    <!-- Contenu central — Bold Appétissant -->
    <div class="kiosk-idle-content">
      <!-- Logo OU brand (Fraunces hero) -->
      <div class="kiosk-idle-brand-block">
        <div class="kiosk-idle-logo-wrap" v-if="restaurantLogo">
          <img :src="restaurantLogo" class="kiosk-idle-logo" alt="" data-testid="kiosk-idle-logo" />
        </div>
        <h1 v-else class="kiosk-idle-brand kiosk-display-hero" data-testid="kiosk-idle-brand">
          {{ restaurantName }}
        </h1>
      </div>

      <!-- Message principal — title Fraunces XL, subtitle Inter L -->
      <div class="kiosk-idle-headline">
        <h2 class="kiosk-idle-title kiosk-display-xl" data-testid="kiosk-idle-title">
          {{ welcomeTitle }}
        </h2>
        <p class="kiosk-idle-subtitle">{{ welcomeSubtitle }}</p>
      </div>

      <!-- CTA animé décoratif (conservé pour cohérence visuelle, aria-hidden) -->
      <div class="kiosk-idle-cta" aria-hidden="true">
        <div class="kiosk-idle-pulse-ring" />
        <div class="kiosk-idle-pulse-ring delay-1" />
        <div class="kiosk-idle-touch-btn" data-testid="kiosk-idle-touch-btn">
          <svg width="44" height="44" viewBox="0 0 48 48" fill="none">
            <path d="M24 8C15.2 8 8 15.2 8 24s7.2 16 16 16 16-7.2 16-16S32.8 8 24 8zm0 28c-6.6 0-12-5.4-12-12S17.4 12 24 12s12 5.4 12 12-5.4 12-12 12zm-2-8l8-4-8-4v8z" fill="currentColor"/>
          </svg>
        </div>
      </div>

      <!-- Order type chooser — cards bold avec iconographie warm -->
      <div
        class="kiosk-order-type-chooser"
        role="group"
        :aria-label="text('kiosk.order_type.choose_label', 'Choisir le mode de commande')"
        data-testid="kiosk-order-type-chooser"
      >
        <button
          type="button"
          class="kiosk-order-type-card kiosk-order-type-card--dine-in"
          data-testid="kiosk-order-type-dine-in"
          @click.stop="selectOrderTypeAndStart(orderTypes.KIOSK)"
          @touchstart.stop
        >
          <span class="kiosk-order-type-icon" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="36" height="36" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
              <path d="M5 28h22M9 28V12a3 3 0 016 0v16M17 28V18a3 3 0 016 0v10M11 28V18M19 28V22"/>
              <circle cx="12" cy="6" r="2.2"/><circle cx="20" cy="6" r="2.2"/>
            </svg>
          </span>
          <span class="kiosk-order-type-text">
            <span class="kiosk-order-type-title kiosk-display-m">{{ text('kiosk.order_type.dine_in', 'Sur place') }}</span>
            <span class="kiosk-order-type-subtitle">{{ text('kiosk.order_type.dine_in_hint', 'Je mange ici') }}</span>
          </span>
        </button>
        <button
          type="button"
          class="kiosk-order-type-card kiosk-order-type-card--takeaway"
          data-testid="kiosk-order-type-takeaway"
          @click.stop="selectOrderTypeAndStart(orderTypes.TAKEAWAY)"
          @touchstart.stop
        >
          <span class="kiosk-order-type-icon" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="36" height="36" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
              <path d="M7 12h18l-2 16H9z M11 12V8a5 5 0 0110 0v4 M14 18v6 M18 18v6"/>
            </svg>
          </span>
          <span class="kiosk-order-type-text">
            <span class="kiosk-order-type-title kiosk-display-m">{{ text('kiosk.order_type.takeaway', 'À emporter') }}</span>
            <span class="kiosk-order-type-subtitle">{{ text('kiosk.order_type.takeaway_hint', 'Je récupère ma commande') }}</span>
          </span>
        </button>
      </div>

      <p class="kiosk-idle-tap-hint">{{ text('kiosk.order_type.required_hint', 'Choisissez une option pour commencer') }}</p>
    </div>

    <!-- Footer dots animés -->
    <div class="kiosk-idle-footer" aria-hidden="true">
      <div class="kiosk-idle-footer-dot" v-for="n in 3" :key="n" :class="{ active: activeDot === n }" />
    </div>
  </div>
</template>

<script>
// [PHASE-37] Multi-language support
// [ADR-007 / iter15-P1a] `setLocale` retiré : kiosk runtime FR-immutable.
// Seul `getCurrentLocale` reste utilisé pour l'affichage `aria-pressed`.
import { getCurrentLocale } from '../../../i18n';
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
      // Navigation + reset panier + setOrderType : uniquement via le parent
      // `KioskAppComponent.startOrder` pour éviter un double `router.push`
      // vers kiosk.categories (cassait les transitions slide-left / écran noir).
      this.$emit('start-order', orderType);
    },
    changeLanguage(/* lang */) {
      // [ADR-007 / iter15-P1a] FR-lock immutable au runtime kiosk.
      // Le sélecteur de langue reste rendu pour des raisons de continuité
      // visuelle (legacy PHASE-37) mais NE déclenche plus de changement de
      // locale : ni setLocale(), ni dispatch store, ni reload. Toute mutation
      // runtime contredirait KIOSK_LOCALE='fr' (resources/js/i18n.js).
      // Voir aussi : applyKioskA11yFromStore (composables/useKioskA11y.js)
      // qui force la locale au boot depuis le store kioskSettings.
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
/* =============================================================================
   CV1-KIOSK-VISUAL-REDESIGN-001 V2.B — KioskIdleScreen Bold Appétissant
   Plan : §9.2.1
   Tokens : --kiosk-bold-* (cf. resources/css/kiosk/tokens-bold.css)
   Typo  : .kiosk-display-* (cf. resources/css/kiosk/typography-bold.css)
   ============================================================================= */
.kiosk-idle--bold {
  position: relative;
  width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  cursor: pointer;
  background: #1A1410;
  /* Texte toujours clair : l'idle screen a TOUJOURS un overlay warm sombre par-dessus
     vidéo/image/gradient. On ne consomme PAS les tokens text-inverse (qui s'inversent
     en dark mode) — on fixe la couleur claire en dur. */
  color: #FFF5E8;
}

/* Vidéo de fond */
.kiosk-idle-video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  z-index: 0;
  filter: saturate(1.08);
}

/* Fallback : gradient warm appétissant + food emojis fond */
.kiosk-idle-fallback {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(ellipse 80% 60% at 30% 20%, rgba(230, 57, 70, 0.18) 0%, transparent 60%),
    radial-gradient(ellipse 70% 50% at 80% 80%, rgba(255, 182, 39, 0.12) 0%, transparent 55%),
    linear-gradient(135deg, #1A1410 0%, #0E0A07 100%);
  z-index: 0;
}

/* Overlay warm (jamais pure noir) */
.kiosk-idle-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    180deg,
    rgba(26, 20, 16, 0.20) 0%,
    rgba(26, 20, 16, 0.40) 50%,
    rgba(26, 20, 16, 0.85) 100%
  );
  z-index: 1;
}

/* Décor : food emojis flottants — très subtil, fond uniquement */
.kiosk-idle-decor {
  position: absolute;
  inset: 0;
  z-index: 1;
  pointer-events: none;
  overflow: hidden;
}
.kiosk-idle-decor-em {
  position: absolute;
  font-size: clamp(80px, 16vw, 220px);
  opacity: 0.06;
  filter: saturate(1.4);
  animation: kiosk-decor-drift 18s ease-in-out infinite alternate;
}
.kiosk-idle-decor-em.em-1 { top: 10%; left: -3%; transform: rotate(-15deg); animation-delay: 0s; }
.kiosk-idle-decor-em.em-2 { top: 65%; right: -3%; transform: rotate(12deg); animation-delay: 6s; }
.kiosk-idle-decor-em.em-3 { top: 38%; left: 70%; transform: rotate(-8deg); animation-delay: 12s; opacity: 0.04; }

@keyframes kiosk-decor-drift {
  from { transform: translateY(0) rotate(var(--rot, 0deg)); }
  to   { transform: translateY(-30px) rotate(var(--rot, 0deg)); }
}

/* Contenu central — alignement centré pour identité bold */
.kiosk-idle-content {
  position: relative;
  z-index: 2;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 28px;
  width: min(960px, calc(100vw - 64px));
  min-height: 80vh;
  justify-content: center;
  padding: 40px 32px 100px;
  text-align: center;
}

.kiosk-idle-brand-block {
  display: flex;
  flex-direction: column;
  align-items: center;
  /* CV1-KIOSK-VISUAL-REDESIGN-001 V2.B-fix-2026-05-03 :
     Animation slideUp seulement (pas d'opacity initial 0) — sinon conflit
     avec la transition slide-left/right du shell qui force aussi opacity 0
     pendant le router change, causant des éléments invisibles bloqués. */
  animation: kiosk-slide-up 600ms var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-idle-logo-wrap {
  animation: kiosk-float 3.6s ease-in-out infinite;
}

@keyframes kiosk-float {
  0%, 100% { transform: translateY(0); }
  50%       { transform: translateY(-10px); }
}

.kiosk-idle-logo {
  width: 160px;
  height: 160px;
  object-fit: contain;
  filter: drop-shadow(0 12px 32px rgba(0, 0, 0, 0.6));
}

/* Brand mark — Fraunces hero (via classe .kiosk-display-hero) */
.kiosk-idle-brand {
  color: #FFF5E8;
  margin: 0;
  text-shadow: 0 4px 24px rgba(0, 0, 0, 0.7), 0 2px 8px rgba(0, 0, 0, 0.5);
  text-transform: none;
  /* La classe .kiosk-display-hero applique font-family Fraunces, weight 900,
     font-size 144px, line-height 1.0, letter-spacing -0.04em — voir typography-bold.css */
}

.kiosk-idle-headline {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--kiosk-space-3, 12px);
  max-width: 720px;
  animation: kiosk-slide-up 600ms 100ms var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-idle-title {
  color: #FFF5E8;
  margin: 0;
  text-shadow: 0 4px 24px rgba(0, 0, 0, 0.7), 0 2px 8px rgba(0, 0, 0, 0.5);
  /* La classe .kiosk-display-xl applique font Fraunces 80px black */
}

.kiosk-idle-subtitle {
  max-width: 640px;
  font-size: clamp(20px, 2.6vw, 28px);
  line-height: 1.35;
  font-weight: var(--kiosk-font-weight-medium, 500);
  color: rgba(255, 245, 232, 0.88);
  margin: 0;
}

/* CTA pulse — décoratif, conservé pour cohérence du flow visuel */
.kiosk-idle-cta {
  position: relative;
  width: 108px;
  height: 108px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 8px 0 4px;
  animation: kiosk-slide-up 600ms 200ms var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-idle-pulse-ring {
  position: absolute;
  width: 120px;
  height: 120px;
  border-radius: 50%;
  border: 3px solid var(--kiosk-bold-primary, #E63946);
  opacity: 0.5;
  animation: kiosk-pulse-ring 2s ease-out infinite;
}

.kiosk-idle-pulse-ring.delay-1 {
  animation-delay: 0.7s;
  opacity: 0.3;
}

@keyframes kiosk-pulse-ring {
  0%   { transform: scale(0.8); opacity: 0.5; }
  100% { transform: scale(1.6); opacity: 0; }
}

.kiosk-idle-touch-btn {
  width: 88px;
  height: 88px;
  background: var(--kiosk-bold-primary, #E63946);
  color: var(--kiosk-bold-text-on-primary, #FFF5E8);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 0 40px var(--kiosk-bold-primary-glow, rgba(230, 57, 70, 0.50));
  animation: kiosk-btn-pulse 2s ease-in-out infinite;
  position: relative;
  z-index: 2;
}

@keyframes kiosk-btn-pulse {
  0%, 100% { transform: scale(1); box-shadow: 0 0 40px var(--kiosk-bold-primary-glow, rgba(230,57,70,0.50)); }
  50%       { transform: scale(1.06); box-shadow: 0 0 60px var(--kiosk-bold-primary-glow, rgba(230,57,70,0.70)); }
}

.kiosk-idle-tap-hint {
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  font-size: calc(15px * var(--kiosk-text-scale, 1));
  font-weight: var(--kiosk-font-weight-bold, 700);
  color: rgba(255, 245, 232, 0.85);
  margin: 0;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  animation: kiosk-slide-up 600ms 300ms var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

/* Order type chooser — bold cards avec iconographie warm */
.kiosk-order-type-chooser {
  display: grid;
  grid-template-columns: repeat(2, minmax(260px, 1fr));
  gap: 20px;
  width: min(820px, calc(100vw - 48px));
  animation: kiosk-slide-up 600ms 250ms var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-order-type-card {
  position: relative;
  min-height: 156px;
  min-width: var(--kiosk-touch-min, 48px);
  padding: 28px 32px;
  border: 0;
  border-radius: var(--kiosk-radius-2xl, 32px);
  /* Cards toujours blanc warm (ressortent du fond sombre overlay) → texte dark fixe */
  background: rgba(255, 248, 241, 0.96);
  color: #1A1410;
  display: flex;
  align-items: center;
  gap: 20px;
  cursor: pointer;
  box-shadow: 0 24px 64px rgba(0, 0, 0, 0.30), 0 8px 24px rgba(0, 0, 0, 0.18);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  text-align: start;
  transition:
    transform var(--kiosk-duration-card, 240ms) var(--kiosk-motion-spring, cubic-bezier(0.34, 1.56, 0.64, 1)),
    background var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
    color var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
    box-shadow var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-order-type-card:hover,
.kiosk-order-type-card:focus-visible {
  background: #E63946;
  color: #FFF5E8;
  box-shadow: 0 28px 72px rgba(230, 57, 70, 0.45);
  transform: translateY(-4px) scale(1.01);
  outline: none;
}

.kiosk-order-type-card:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid #FFF5E8;
  outline-offset: 4px;
}

.kiosk-order-type-card:active {
  transform: translateY(-1px) scale(0.99);
}

.kiosk-order-type-card--takeaway:hover,
.kiosk-order-type-card--takeaway:focus-visible {
  background: #2D6A4F;
  color: #FFF5E8;
  box-shadow: 0 28px 72px rgba(45, 106, 79, 0.45);
}

/* Icon badge à gauche du titre — fixe (sur card warm permanent) */
.kiosk-order-type-icon {
  flex-shrink: 0;
  width: 64px;
  height: 64px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: #FFF3D6;
  color: #E63946;
  transition: background var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
              color var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-order-type-card:hover .kiosk-order-type-icon,
.kiosk-order-type-card:focus-visible .kiosk-order-type-icon {
  background: rgba(255, 245, 232, 0.18);
  color: currentColor;
}

.kiosk-order-type-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.kiosk-order-type-title {
  color: currentColor;
  /* La classe .kiosk-display-m applique Fraunces 36px bold */
}

.kiosk-order-type-subtitle {
  font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
  font-size: calc(15px * var(--kiosk-text-scale, 1));
  font-weight: var(--kiosk-font-weight-medium, 500);
  color: currentColor;
  opacity: 0.82;
}

/* Footer dots — accent doré bold */
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
  background: rgba(255, 245, 232, 0.30);
  transition:
    width var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
    background-color var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
    border-radius var(--kiosk-duration-card, 240ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-idle-footer-dot.active {
  width: 32px;
  border-radius: 4px;
  background: var(--kiosk-bold-accent, #FFB627);
}

/* CV1-KIOSK-VISUAL-REDESIGN-001 V2.B-fix-2026-05-03 :
   `kiosk-slide-up` n'utilise PAS opacity (qui crée des conflits avec la
   transition slide-left/right du shell). Juste un translateY subtil. */
@keyframes kiosk-slide-up {
  from { transform: translateY(20px); }
  to   { transform: translateY(0); }
}

/* Reduced motion — neutralise toutes les animations propres */
@media (prefers-reduced-motion: reduce) {
  .kiosk-idle-brand-block,
  .kiosk-idle-headline,
  .kiosk-idle-cta,
  .kiosk-order-type-chooser,
  .kiosk-idle-tap-hint { animation: none; }
  .kiosk-idle-pulse-ring,
  .kiosk-idle-touch-btn { animation: none; }
  .kiosk-idle-decor-em,
  .kiosk-idle-logo-wrap { animation: none; }
}
[data-kiosk-reduced-motion='true'] .kiosk-idle-brand-block,
[data-kiosk-reduced-motion='true'] .kiosk-idle-headline,
[data-kiosk-reduced-motion='true'] .kiosk-idle-cta,
[data-kiosk-reduced-motion='true'] .kiosk-order-type-chooser,
[data-kiosk-reduced-motion='true'] .kiosk-idle-tap-hint,
[data-kiosk-reduced-motion='true'] .kiosk-idle-pulse-ring,
[data-kiosk-reduced-motion='true'] .kiosk-idle-touch-btn,
[data-kiosk-reduced-motion='true'] .kiosk-idle-decor-em,
[data-kiosk-reduced-motion='true'] .kiosk-idle-logo-wrap { animation: none !important; }

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

/* Floating top-right group : langue + a11y + theme (V1.4 KsThemeToggle accessible via KsA11ySettings) */
.kiosk-idle-floating {
  position: absolute;
  top: 24px;
  right: 24px;
  z-index: 10;
  display: flex;
  gap: 8px;
  align-items: center;
  /* Pas d’opacity dans l’entrée : même famille de bug que le shell slide-* en SPA */
  animation: kiosk-slide-down 500ms var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

@keyframes kiosk-slide-down {
  from { transform: translateY(-12px); }
  to   { transform: translateY(0); }
}

[dir="rtl"] .kiosk-idle-floating {
  right: auto;
  left: 24px;
}

.kiosk-lang-selector {
  display: flex;
  gap: 4px;
  padding: 4px;
  border-radius: var(--kiosk-radius-pill, 999px);
  background: rgba(255, 248, 241, 0.92);
  border: 1px solid rgba(232, 221, 212, 0.3);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.kiosk-lang-btn {
  min-height: var(--kiosk-touch-min, 48px);
  min-width: var(--kiosk-touch-min, 48px);
  padding: 8px 14px;
  border-radius: var(--kiosk-radius-pill, 999px);
  border: 0;
  background: transparent;
  color: var(--kiosk-bold-text-secondary, #6B5D52);
  font-family: inherit;
  font-size: calc(13px * var(--kiosk-text-scale, 1));
  font-weight: var(--kiosk-font-weight-bold, 700);
  cursor: pointer;
  transition:
    background-color var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
    color var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-lang-btn:hover {
  color: var(--kiosk-bold-text-primary, #1A1410);
  background: rgba(26, 20, 16, 0.04);
}

.kiosk-lang-btn.active {
  background: var(--kiosk-bold-text-primary, #1A1410);
  color: var(--kiosk-bold-text-inverse, #FFF5E8);
  box-shadow: 0 4px 12px rgba(26, 20, 16, 0.20);
}

.kiosk-lang-btn:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-bold-primary, #E63946);
  outline-offset: 2px;
}

/* A11y settings button — pill warm, ouvre KsA11ySettings drawer (qui contient KsThemeToggle V1.4) */
.kiosk-idle-a11y-btn {
  width: 56px;
  height: 56px;
  min-width: var(--kiosk-touch-min, 48px);
  min-height: var(--kiosk-touch-min, 48px);
  border-radius: 50%;
  border: 1px solid rgba(232, 221, 212, 0.3);
  background: rgba(255, 248, 241, 0.92);
  color: var(--kiosk-bold-text-primary, #1A1410);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  transition:
    background var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1)),
    transform var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-spring, cubic-bezier(0.34, 1.56, 0.64, 1)),
    border-color var(--kiosk-duration-tap, 120ms) var(--kiosk-motion-smooth, cubic-bezier(0.4, 0, 0.2, 1));
}

.kiosk-idle-a11y-btn:hover {
  background: rgba(255, 245, 232, 1);
  border-color: var(--kiosk-bold-border-strong, #1A1410);
  transform: scale(1.05);
}

.kiosk-idle-a11y-btn:active { transform: scale(0.96); }

.kiosk-idle-a11y-btn:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-bold-primary, #E63946);
  outline-offset: 3px;
}

@media (prefers-reduced-motion: reduce) {
  .kiosk-idle-floating { animation: none; }
}
[data-kiosk-reduced-motion='true'] .kiosk-idle-floating { animation: none !important; }

/* Responsive — kiosk portrait small / paysage */
@media (max-width: 720px) {
  .kiosk-order-type-chooser {
    grid-template-columns: 1fr;
    width: min(420px, calc(100vw - 36px));
  }
  .kiosk-order-type-card {
    min-height: 110px;
    padding: 20px 24px;
  }
  .kiosk-order-type-icon {
    width: 56px;
    height: 56px;
  }
}
</style>
