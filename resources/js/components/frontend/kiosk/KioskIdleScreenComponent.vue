<template>
  <!-- Borne Accueil — Attract redesign 2026-06-28 (owner design import
       "Le Cayenne - Borne Accueil.dc.html").
       Refonte template + style. Le <script> conserve toute la logique métier
       (order-type chooser, emit start-order, sélecteur langue FR-lock, drawer
       a11y, vidéo idle, dineInEnabled, reset panier). Tous les data-testid
       fonctionnels sont conservés (sentinels Vitest / Playwright). Le visuel
       1080×1920 est rendu dans une « stage » scalée pour remplir n'importe quel
       écran de borne sans déformation. -->
  <div
    class="kiosk-idle kiosk-idle--bold kiosk-attract-viewport"
    data-testid="kiosk-idle-root"
    @click="onScreenTap"
  >
    <!-- [BORNE-UX 2026-07-11 #4] Ripple « touché pour démarrer » au point de contact. -->
    <span
      v-if="tapRipple"
      :key="tapRipple.key"
      class="kiosk-tap-ripple"
      :style="{ left: tapRipple.x + 'px', top: tapRipple.y + 'px' }"
      aria-hidden="true"
    ></span>

    <KsA11ySettings v-model="settingsOpen" @click.stop />

    <!-- Stage 1080×1920 scalée au viewport (fidélité pixel au design) -->
    <div class="kiosk-attract-stage" :style="stageStyle">

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
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8" />
            <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            <path d="M12 4v1M12 19v1M4 12h1M19 12h1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
        </button>
      </div>

      <!-- Vidéo de fond (héritée — toujours fonctionnelle si configurée) -->
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
      <!-- Fallback : fond animé du design (radial orange Cayenne) -->
      <div v-else class="kiosk-idle-fallback" />
      <!-- Overlay (conservé, transparent — le fond design porte déjà la teinte) -->
      <div class="kiosk-idle-overlay" />

      <!-- Décor : formes flottantes + néons (echo de la photographie produit) -->
      <div class="kiosk-idle-decor" aria-hidden="true">
        <span class="kiosk-idle-decor-em em-1 cay-shape-a"></span>
        <span class="kiosk-idle-decor-em em-2 cay-shape-b"></span>
        <span class="kiosk-idle-decor-em em-3 cay-shape-c"></span>
        <span class="cay-neon cay-neon-l"></span>
        <span class="cay-neon cay-neon-r"></span>
      </div>

      <!-- ===== HEADER / LOGO ===== -->
      <div class="kiosk-idle-brand-block">
        <div class="kiosk-idle-logo-wrap" :class="{ 'logo-in': logoIn }">
          <!-- Logo wordmark de l'import design owner (transparent, intégral à
               l'identité attract Le Cayenne V1). Prioritaire sur le theme-logo
               générique des settings (fond blanc, mauvais ratio). -->
          <img
            v-if="brandLogo"
            :src="brandLogo"
            class="kiosk-idle-logo"
            alt=""
            data-testid="kiosk-idle-logo"
            @error="onAttractImgError"
          />
          <h1 v-else class="kiosk-idle-brand" data-testid="kiosk-idle-brand">
            {{ restaurantName }}
          </h1>
        </div>
      </div>

      <!-- ===== EYEBROW ===== -->
      <div class="cay-eyebrow">
        <span class="cay-eyebrow-dot"></span>
        <span class="cay-eyebrow-text">{{ text('kiosk.idle_screen.eyebrow', 'Nos incontournables') }}</span>
        <span class="cay-eyebrow-dot"></span>
      </div>

      <!-- ===== HERO PRODUCT STAGE — carrousel ===== -->
      <div class="cay-hero">
        <div class="cay-hero-glow"></div>
        <div class="cay-hero-card">
          <div
            v-for="(p, i) in products"
            :key="p.name"
            class="cay-hero-slide"
            :class="{ 'is-active': i === heroIdx }"
          >
            <img :src="p.img" :alt="p.name" class="cay-hero-img" @error="onAttractImgError" />
          </div>

          <!-- légeretés visuelles -->
          <div class="cay-hero-bottom-grad"></div>
          <div class="cay-hero-vignette"></div>
          <div class="cay-hero-gloss"></div>

          <!-- chips catégorie rotatifs -->
          <div class="cay-chips">
            <div
              v-for="(p, i) in products"
              :key="'chip-' + p.name"
              class="cay-chip"
              :class="{ 'is-active': i === heroIdx }"
            >
              <span class="cay-chip-dot"></span>
              <span class="cay-chip-label">{{ p.name }}</span>
            </div>
          </div>

          <!-- dots de progression -->
          <div class="cay-dots">
            <span
              v-for="(p, i) in products"
              :key="'dot-' + i"
              class="cay-dot"
              :class="{ 'is-active': i === heroIdx }"
            ></span>
          </div>

          <!-- stamp 100% Halal -->
          <div class="cay-stamp">
            <span class="cay-stamp-num">100%</span>
            <span class="cay-stamp-lab">Halal</span>
          </div>
        </div>
      </div>

      <!-- ===== CYCLING HEADLINE ===== -->
      <div class="kiosk-idle-headline">
        <h2 class="kiosk-idle-title" data-testid="kiosk-idle-title">
          <transition name="cay-line" mode="out-in">
            <!-- eslint-disable-next-line vue/no-v-html -->
            <span :key="lineIdx" v-html="safeHtml(headlines[lineIdx])"></span>
          </transition>
        </h2>
        <div class="cay-underline"></div>
        <p class="kiosk-idle-subtitle" data-testid="kiosk-idle-subtitle">{{ welcomeSubtitle }}</p>
      </div>

      <!-- ===== BADGES ===== -->
      <div class="cay-badges" aria-hidden="true">
        <span class="cay-badge"><span class="cay-badge-dot"></span>{{ text('kiosk.idle_screen.badge_1', 'Ultra gourmand') }}</span>
        <span class="cay-badge"><span class="cay-badge-dot"></span>{{ text('kiosk.idle_screen.badge_2', 'Frais du jour') }}</span>
        <span class="cay-badge"><span class="cay-badge-dot"></span>{{ text('kiosk.idle_screen.badge_3', 'Préparé minute') }}</span>
      </div>

      <!-- ===== CTA — Order type chooser (action réelle borne) ===== -->
      <div
        class="kiosk-order-type-chooser"
        :class="{ 'is-single': !dineInEnabled }"
        role="group"
        :aria-label="text('kiosk.order_type.choose_label', 'Choisir le mode de commande')"
        data-testid="kiosk-order-type-chooser"
      >
        <!--
          [iter15-mega-fix C-019 2026-05-10] V1 dine-in feature flag.
          La tuile "Sur place" reste masquée tant que le floorplan n'est pas
          livré (pos_dine_in_enabled default FALSE).
        -->
        <button
          v-if="dineInEnabled"
          type="button"
          class="kiosk-order-type-card kiosk-order-type-card--dine-in"
          data-testid="kiosk-order-type-dine-in"
          @click.stop="selectOrderTypeAndStart(orderTypes.KIOSK)"
          @touchstart.stop
        >
          <span class="kiosk-order-type-icon" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="40" height="40" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
              <path d="M5 28h22M9 28V12a3 3 0 016 0v16M17 28V18a3 3 0 016 0v10M11 28V18M19 28V22"/>
              <circle cx="12" cy="6" r="2.2"/><circle cx="20" cy="6" r="2.2"/>
            </svg>
          </span>
          <span class="kiosk-order-type-text">
            <span class="kiosk-order-type-title">{{ text('kiosk.order_type.dine_in', 'Sur place') }}</span>
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
          <!-- indicateur tactile « touchez pour commander » (décoratif) -->
          <span class="kiosk-idle-cta" data-testid="kiosk-idle-cta" aria-hidden="true">
            <span class="kiosk-idle-pulse-ring"></span>
            <span class="kiosk-idle-pulse-ring delay-1"></span>
            <span class="kiosk-idle-touch-btn" data-testid="kiosk-idle-touch-btn">
              <svg width="42" height="42" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                <path d="M24 8C15.2 8 8 15.2 8 24s7.2 16 16 16 16-7.2 16-16S32.8 8 24 8zm0 28c-6.6 0-12-5.4-12-12S17.4 12 24 12s12 5.4 12 12-5.4 12-12 12zm-2-8l8-4-8-4v8z" fill="currentColor"/>
              </svg>
            </span>
          </span>
          <span class="kiosk-order-type-icon kiosk-order-type-icon--takeaway" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="40" height="40" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
              <path d="M7 12h18l-2 16H9z M11 12V8a5 5 0 0110 0v4 M14 18v6 M18 18v6"/>
            </svg>
          </span>
          <span class="kiosk-order-type-text">
            <span class="kiosk-order-type-title">{{ ctaTitle }}</span>
            <span class="kiosk-order-type-subtitle">{{ ctaSubtitle }}</span>
          </span>
        </button>
      </div>

      <p class="kiosk-idle-tap-hint" data-testid="kiosk-idle-tap-hint">
        {{ text('kiosk.order_type.required_hint', 'Commande rapide · Sur place ou à emporter') }}
      </p>

      <!-- Footer dots animés (conservé) -->
      <div class="kiosk-idle-footer" aria-hidden="true">
        <div class="kiosk-idle-footer-dot" v-for="n in 3" :key="n" :class="{ active: activeDot === n }" />
      </div>

      <!-- grain premium -->
      <div class="cay-grain" aria-hidden="true"></div>
    </div>
  </div>
</template>

<script>
// [PHASE-37] Multi-language support
// [ADR-007 / iter15-P1a] `setLocale` retiré : kiosk runtime FR-immutable.
// Seul `getCurrentLocale` reste utilisé pour l'affichage `aria-pressed`.
import { getCurrentLocale } from '../../../i18n';
// [ULTRA-AUDIT 2026-07-02] Sanitizer XSS pour les headlines statiques (contenu contrôlé,
// mais VHtmlStaticGuard exige un wrap safeHtml). Config locale : autorise `class` (cay-accent)
// que le safeHtml partagé (utils/safeHtml, ALLOWED_ATTR=['href']) strip — sinon l'accent orange
// des titres attract disparaîtrait. DOMPurify bloque script/handlers dans tous les cas.
import DOMPurify from 'dompurify';
// [PHASE-4.4] A11y drawer (lang/AAA/PMR/audio).
import KsA11ySettings from './ds/KsA11ySettings.vue';
import { KIOSK_ORDER_TYPES } from '../../../store/modules/kioskCart';

// Base des assets attract (servis depuis public/). Les 8 visuels produits +
// le logo proviennent de l'import design owner (2026-06-28).
const ATTRACT_BASE = '/images/kiosk-attract/';

export default {
  name: 'KioskIdleScreenComponent',
  emits: ['start-order'],
  components: { KsA11ySettings },
  data() {
    return {
      activeDot: 1,
      dotTimer: null,
      heroIdx: 0,
      lineIdx: 0,
      heroTimer: null,
      lineTimer: null,
      logoIn: false,
      stageScale: 1,
      videoSrc: null,
      restaurantLogo: null,
      restaurantName: '',
      welcomeTitle: '',
      welcomeSubtitle: '',
      tapHint: '',
      settingsOpen: false,
      orderTypes: KIOSK_ORDER_TYPES,
      // [BORNE-UX 2026-07-11 #4] Ripple visuel « touché pour démarrer ».
      tapRipple: null,
      tapRippleSeq: 0,
      // [WEBP-MIGRATION 2026-07-07] Visuels attract servis en WebP (-64% de
      // poids réseau vs PNG, ~8 Mo → ~2,9 Mo sur l'écran d'accueil). Le PNG
      // reste sur disque : `onAttractImgError` y retombe si le navigateur ne
      // décode pas le WebP (repli automatique, 0 régression).
      brandLogo: ATTRACT_BASE + 'logo.webp',
      products: [
        { name: 'Le Terminator', img: ATTRACT_BASE + 'terminator.webp' },
        { name: 'Double Cheese', img: ATTRACT_BASE + 'double-cheese.webp' },
        { name: 'Le Cayenne',    img: ATTRACT_BASE + 'cayenne.webp' },
        { name: 'Grill Burger',  img: ATTRACT_BASE + 'grill-burger.webp' },
        { name: 'Le Suprême',    img: ATTRACT_BASE + 'supreme.webp' },
        { name: 'Menu Maxi',     img: ATTRACT_BASE + 'menu-maxi.webp' },
        { name: 'Bol de riz',    img: ATTRACT_BASE + 'bol-riz.webp' },
        { name: 'Bol de frites', img: ATTRACT_BASE + 'bol-frites.webp' },
      ],
      enabledLanguages: ['fr', 'en'], // Default, will be overridden by settings
      languageLabels: {
        fr: 'FR',
        en: 'EN',
        ar: 'العربية',
      },
      // [iter15-mega-fix C-019 2026-05-10] Raw frontend settings payload kept
      // around so `dineInEnabled` can re-evaluate after loadSettings() resolves.
      settingsRaw: {},
    };
  },
  computed: {
    currentLocale() {
      return getCurrentLocale();
    },
    stageStyle() {
      return { transform: `translate(-50%, -50%) scale(${this.stageScale})` };
    },
    headlines() {
      const lc = (k, fb) => this.text(k, fb);

      /*
       * [ONB-01 2026-08-28] La borne affichait le nom d'un AUTRE établissement.
       *
       * « Le Cayenne » était CONCATÉNÉ EN DUR après le titre d'accueil réglable :
       * même en réglant son titre, le commerçant voyait le nom du premier
       * établissement s'y ajouter. Or `this.restaurantName` est renseigné vingt-cinq
       * lignes plus bas (`data.company_name || data.site_name`) — la donnée était là.
       *
       * Pour une « publication vierge », c'est le défaut le plus visible qui soit :
       * le premier écran que voit un client porte le nom de quelqu'un d'autre.
       */
      // Le nom vient d'un réglage LIBRE et entre dans une chaîne HTML : on
      // l'échappe ici. Le composant assainit les titres plus bas via DOMPurify,
      // mais une injection ne doit pas dépendre de l'ordre dans lequel deux
      // protections se rencontrent.
      const nom = String(this.restaurantName || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

      const etablissement = nom ? ' <span class="cay-accent">' + nom + '</span>' : '';

      return [
        (this.welcomeTitle || lc('kiosk.idle_screen.default_title', 'Bienvenue chez')) + etablissement,
        lc('kiosk.idle_screen.line_compose', "Composez votre carte<br>comme vous l'aimez"),
        /*
         * Cette ligne AFFIRMAIT « Halal », sans clé ni repli. Un nouveau commerçant
         * n'est pas forcément halal, et une borne ne doit pas faire à sa place une
         * déclaration qu'il n'a peut-être pas le droit de faire. Le repli est
         * désormais neutre, et la ligne passe par une clé — donc modifiable.
         *
         * La rendre éditable depuis le Dashboard relève des réglages borne :
         * fiche de renvoi ONB-10, pas correctif silencieux ici.
         */
        lc('kiosk.idle_screen.line_claims', 'Frais · Préparé minute'),
        lc('kiosk.idle_screen.line_taste', 'Un goût qui<br>vous ressemble'),
      ];
    },
    ctaTitle() {
      return this.dineInEnabled
        ? this.text('kiosk.order_type.takeaway', 'À emporter')
        : this.text('kiosk.idle_screen.cta_title', "Touchez l'écran");
    },
    ctaSubtitle() {
      return this.dineInEnabled
        ? this.text('kiosk.order_type.takeaway_hint', 'Je récupère ma commande')
        : this.text('kiosk.idle_screen.cta_subtitle', 'pour commander');
    },
    /**
     * [iter15-mega-fix C-019 2026-05-10] Kiosk dine-in feature flag.
     * Mirror of PosComponent.dineInEnabled (POS-9.1.6 / V10 #1) — copied
     * verbatim to keep the typeof guard hardening (rejects arrays/objects
     * before string coercion: `String([1]) === '1'` would otherwise activate
     * the flag). Defaults to FALSE so a regressed/empty backend stays safe.
     * V1 mandate per feedback_v1_dine_in_disabled_2026-05-06.
     */
    dineInEnabled() {
      const s = this.settingsRaw || {};
      const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
      const t = typeof raw;
      if (t !== 'boolean' && t !== 'number' && t !== 'string') return false;
      return String(raw) === '1' || raw === true;
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
    this.startCarousel();
    this.computeStageScale();
    if (typeof window !== 'undefined') {
      window.addEventListener('resize', this.computeStageScale);
    }
    // Entrée logo (one-shot, état de repos visible).
    this.$nextTick(() => { this.logoIn = true; });
    // Always clear any leftover cart when landing on idle (back-nav, timeout, etc.)
    this.$store.dispatch('kioskCart/reset');
  },
  beforeUnmount() {
    clearInterval(this.dotTimer);
    clearInterval(this.heroTimer);
    clearInterval(this.lineTimer);
    if (typeof window !== 'undefined') {
      window.removeEventListener('resize', this.computeStageScale);
    }
  },
  methods: {
    // [ULTRA-AUDIT 2026-07-02] Wrap exigé par VHtmlStaticGuard. Headlines = HTML statique
    // contrôlé (<span class="cay-accent">, <br>) ; DOMPurify neutralise tout vecteur XSS et
    // on autorise `class` pour préserver le style accent (le safeHtml partagé le strip).
    safeHtml(raw) {
      if (raw == null) return '';
      return DOMPurify.sanitize(String(raw), {
        ALLOWED_TAGS: ['span', 'br', 'b', 'strong', 'em', 'i'],
        ALLOWED_ATTR: ['class'],
      });
    },
    applyLocalizedDefaults() {
      this.restaurantName = this.$t('kiosk.idle_screen.default_restaurant_name');
      this.welcomeTitle = this.$t('kiosk.idle_screen.default_title');
      this.welcomeSubtitle = this.$t('kiosk.idle_screen.default_subtitle');
      this.tapHint = this.$t('kiosk.idle_screen.default_tap_hint');
    },
    text(key, fallback) {
      // [i18n-clean 2026-07-08] Ne consulter $t QUE si la clé existe (via $te) :
      // sinon vue-i18n émet un warning « Not found key » à chaque rendu pour les
      // libellés purement à-repli (eyebrow/badge_*), inondant la console (194
      // warnings observés sur l'idle). $te ne loggue pas ; repli FR identique
      // (clé présente → $t, sinon → fallback).
      if (!this.$te || !this.$te(key)) return fallback;
      const value = this.$t(key);
      return value && value !== key ? value : fallback;
    },
    /**
     * [WEBP-MIGRATION 2026-07-07] Repli automatique WebP → PNG. Si un navigateur
     * (très ancien) ne décode pas le `.webp`, on bascule une seule fois sur le
     * jumeau `.png` resté sur disque. Le drapeau dataset évite toute boucle si
     * le PNG échoue aussi.
     */
    onAttractImgError(event) {
      const el = event && event.target;
      if (!el || el.dataset.pngFallback === '1') return;
      const src = el.getAttribute('src') || '';
      if (!/\.webp(\?.*)?$/i.test(src)) return;
      el.dataset.pngFallback = '1';
      el.setAttribute('src', src.replace(/\.webp(\?.*)?$/i, '.png$1'));
    },
    computeStageScale() {
      const w = (typeof window !== 'undefined' && window.innerWidth) || 1080;
      const h = (typeof window !== 'undefined' && window.innerHeight) || 1920;
      const s = Math.min(w / 1080, h / 1920);
      this.stageScale = (isFinite(s) && s > 0) ? s : 1;
    },
    selectOrderTypeAndStart(orderType) {
      // Navigation + reset panier + setOrderType : uniquement via le parent
      // `KioskAppComponent.startOrder` pour éviter un double `router.push`
      // vers kiosk.categories (cassait les transitions slide-left / écran noir).
      this.$emit('start-order', orderType);
    },

    // [BORNE-UX 2026-07-11 #4] Toucher N'IMPORTE OÙ sur l'écran démarre la commande
    // (owner : les clients touchent l'écran au hasard sans trouver le bouton). Les
    // boutons langue/a11y et les cards sur-place/à-emporter utilisent @click.stop →
    // ils ne déclenchent PAS ce handler (une card garde son propre type). Le fond
    // démarre le type primaire (à emporter). Effet ripple au point de contact.
    onScreenTap(event) {
      if (this.settingsOpen) return; // drawer a11y ouvert : ne pas démarrer.
      this.tapRippleSeq += 1;
      this.tapRipple = {
        key: this.tapRippleSeq,
        x: event && event.clientX != null ? event.clientX : window.innerWidth / 2,
        y: event && event.clientY != null ? event.clientY : window.innerHeight / 2,
      };
      const started = this.tapRippleSeq;
      // Petit délai pour laisser le ripple s'amorcer (feedback « touché »), puis démarrage.
      window.setTimeout(() => {
        if (this.tapRipple && this.tapRipple.key === started) this.tapRipple = null;
      }, 520);
      this.$emit('start-order', this.orderTypes.TAKEAWAY);
    },
    changeLanguage(/* lang */) {
      // [ADR-007 / iter15-P1a] FR-lock immutable au runtime kiosk.
      // Le sélecteur de langue reste rendu pour des raisons de continuité
      // visuelle (legacy PHASE-37) mais NE déclenche plus de changement de
      // locale : ni setLocale(), ni dispatch store, ni reload. Toute mutation
      // runtime contredirait KIOSK_LOCALE='fr' (resources/js/i18n.js).
    },
    openSettings() {
      this.settingsOpen = true;
    },
    startDotAnimation() {
      this.dotTimer = setInterval(() => {
        this.activeDot = (this.activeDot % 3) + 1;
      }, 800);
    },
    startCarousel() {
      this.heroTimer = setInterval(() => {
        this.heroIdx = (this.heroIdx + 1) % this.products.length;
      }, 3800);
      this.lineTimer = setInterval(() => {
        this.lineIdx = (this.lineIdx + 1) % this.headlines.length;
      }, 3400);
    },
    async loadSettings() {
      try {
        const res = await this.$store.dispatch('frontendSetting/lists', { vuex: false });
        const data = res?.data?.data || res?.data || {};

        // [iter15-mega-fix C-019 2026-05-10] Snapshot raw payload so the
        // dineInEnabled computed can read pos_dine_in_enabled without
        // re-querying the store. Set BEFORE the early-returning assignments
        // below so a partial payload still flips the flag correctly.
        this.settingsRaw = data;

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
   Borne Accueil — Attract redesign 2026-06-28
   Stage fixe 1080×1920 (design owner) scalée au viewport via transform.
   Palette Cayenne : orange #F4501E / accent jaune #FFB800 / noir #1A1A1A.
   ============================================================================= */
/* [BORNE-UX 2026-07-11 #4] Ripple « touché pour démarrer » — cercle qui s'étend
   au point de contact quand on touche n'importe où pour démarrer. */
.kiosk-tap-ripple {
  position: fixed;
  z-index: 9999;
  width: 12px;
  height: 12px;
  margin: -6px 0 0 -6px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255,184,0,0.55) 0%, rgba(244,80,30,0.35) 45%, rgba(244,80,30,0) 72%);
  pointer-events: none;
  animation: kiosk-tap-ripple 520ms ease-out forwards;
}
@keyframes kiosk-tap-ripple {
  0%   { transform: scale(1);  opacity: 0.9; }
  100% { transform: scale(46); opacity: 0; }
}
[data-kiosk-reduced-motion='true'] .kiosk-tap-ripple { animation-duration: 1ms; }

.kiosk-attract-viewport {
  position: fixed;
  inset: 0;
  width: 100vw;
  height: 100vh;
  overflow: hidden;
  cursor: pointer;
  background: #1A1A1A;
  cursor: pointer;
  font-family: 'Hanken Grotesk', system-ui, sans-serif;
}

.kiosk-attract-stage {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 1080px;
  height: 1920px;
  transform-origin: center center;
  color: #fff;
  overflow: hidden;
  background:
    radial-gradient(120% 80% at 50% -8%, #FF6A3D 0%, rgba(255,106,61,0) 55%),
    radial-gradient(90% 60% at 50% 118%, #D8380C 0%, rgba(216,56,12,0) 60%),
    #F4501E;
}

/* ---------- fond hérité (vidéo / fallback / overlay) ---------- */
.kiosk-idle-video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  z-index: 0;
  filter: saturate(1.08);
}
/* Le fond design (radial orange Cayenne). On surcharge ici en !important via une
   spécificité supérieure (.kiosk-attract-stage descendant) pour battre la règle
   globale `.kiosk-app.kiosk-theme--light .kiosk-idle-fallback` (tokens-bold.css)
   qui peint un gradient blanc → l'attract a SA propre identité orange, pas la
   palette light-mode de l'idle hérité. */
.kiosk-attract-stage .kiosk-idle-fallback {
  position: absolute;
  inset: 0;
  z-index: 0;
  background:
    radial-gradient(120% 80% at 50% -8%, #FF6A3D 0%, rgba(255,106,61,0) 55%),
    radial-gradient(90% 60% at 50% 118%, #D8380C 0%, rgba(216,56,12,0) 60%),
    #F4501E !important;
}
.kiosk-idle-overlay {
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
}

/* ---------- décor : formes flottantes + néons ---------- */
.kiosk-idle-decor { position: absolute; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
.kiosk-idle-decor-em { position: absolute; border-radius: 50%; }
.cay-shape-a {
  top: 240px; left: -120px; width: 360px; height: 360px;
  background: radial-gradient(circle at 35% 35%, rgba(255,184,0,.45), rgba(255,184,0,0) 70%);
  filter: blur(2px); animation: cay-floatA 13s ease-in-out infinite;
}
.cay-shape-b {
  top: 1180px; right: -150px; width: 420px; height: 420px;
  background: radial-gradient(circle at 60% 40%, rgba(255,255,255,.18), rgba(255,255,255,0) 70%);
  animation: cay-floatB 17s ease-in-out infinite;
}
.cay-shape-c {
  top: 1560px; left: -90px; width: 300px; height: 300px;
  background: radial-gradient(circle at 50% 50%, rgba(255,184,0,.3), rgba(255,184,0,0) 70%);
  animation: cay-floatC 11s ease-in-out infinite;
}
.cay-neon { position: absolute; width: 9px; border-radius: 999px; filter: blur(7px); }
.cay-neon-l {
  top: -90px; left: 16%; height: 820px; transform: rotate(21deg);
  background: linear-gradient(180deg, rgba(255,184,0,0), #FFC23D 45%, rgba(255,184,0,0));
  animation: cay-glow 5s ease-in-out infinite;
}
.cay-neon-r {
  top: -70px; right: 14%; height: 880px; transform: rotate(-21deg);
  background: linear-gradient(180deg, rgba(255,184,0,0), #FFD56E 45%, rgba(255,184,0,0));
  animation: cay-glow 6.5s ease-in-out infinite 1s;
}
@keyframes cay-floatA { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(34px,-46px) scale(1.12); } }
@keyframes cay-floatB { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-40px,40px) scale(1.18); } }
@keyframes cay-floatC { 0%,100% { transform: translate(0,0); } 50% { transform: translate(26px,30px); } }
@keyframes cay-glow { 0%,100% { opacity: .32; } 50% { opacity: .66; } }

/* ---------- logo ---------- */
.kiosk-idle-brand-block {
  position: absolute; top: 78px; left: 0; right: 0; z-index: 6;
  display: flex; flex-direction: column; align-items: center;
}
.kiosk-idle-logo-wrap {
  opacity: 0; transform: translateY(-22px) scale(.94);
  transition: opacity .9s ease, transform .9s cubic-bezier(.16,1,.3,1);
}
.kiosk-idle-logo-wrap.logo-in { opacity: 1; transform: none; }
.kiosk-idle-logo {
  width: 812px; height: auto; object-fit: contain;
  filter: drop-shadow(0 6px 16px rgba(70,14,0,.28));
}
.kiosk-idle-brand {
  font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800;
  font-size: 120px; line-height: 1; letter-spacing: -.02em; color: #1A1A1A;
  margin: 0; text-transform: uppercase;
}

/* ---------- eyebrow ---------- */
.cay-eyebrow {
  position: absolute; top: 348px; left: 0; right: 0; z-index: 6;
  display: flex; align-items: center; justify-content: center; gap: 14px;
}
.cay-eyebrow::before, .cay-eyebrow::after { content: none; }
.cay-eyebrow {
  flex-wrap: nowrap;
}
.cay-eyebrow > .cay-eyebrow-dot {
  width: 14px; height: 14px; border-radius: 50%; background: #FFB800;
  box-shadow: 0 0 14px rgba(255,184,0,.9); flex: 0 0 auto;
}
.cay-eyebrow-text {
  display: inline-flex; align-items: center;
  padding: 14px 32px; border-radius: 999px;
  background: rgba(26,26,26,.32); border: 1.5px solid rgba(255,184,0,.55);
  backdrop-filter: blur(6px);
  font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800;
  font-size: 30px; letter-spacing: .18em; text-transform: uppercase; color: #fff;
  animation: cay-eyebrow 3.6s ease-in-out infinite;
}
@keyframes cay-eyebrow {
  0%,100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}

/* ---------- hero carrousel ---------- */
.cay-hero { position: absolute; top: 452px; left: 90px; width: 900px; height: 884px; z-index: 5; }
.cay-hero-glow {
  position: absolute; inset: -26px; border-radius: 64px; filter: blur(8px);
  background: radial-gradient(60% 55% at 50% 42%, rgba(255,184,0,.55), rgba(255,184,0,0) 72%);
}
.cay-hero-card {
  position: absolute; inset: 0; border-radius: 52px; overflow: hidden; background: #1A1A1A;
  box-shadow: 0 54px 120px rgba(80,16,0,.55), 0 12px 30px rgba(0,0,0,.3),
              inset 0 0 0 4px #FFB800, inset 0 0 0 11px rgba(0,0,0,.55);
}
.cay-hero-slide {
  position: absolute; inset: 0; opacity: 0;
  transition: opacity 1.1s ease;
}
.cay-hero-slide.is-active { opacity: 1; z-index: 2; }
.cay-hero-img { width: 100%; height: 100%; object-fit: cover; display: block; transform: scale(1.02); }
.cay-hero-slide.is-active .cay-hero-img { animation: cay-kenburns 5s ease-out forwards; }
@keyframes cay-kenburns {
  from { transform: scale(1.02) translate(0,0); }
  to   { transform: scale(1.12) translate(-1.5%,-1%); }
}
.cay-hero-bottom-grad {
  position: absolute; left: 0; right: 0; bottom: 0; height: 260px; z-index: 3; pointer-events: none;
  background: linear-gradient(to top, rgba(10,10,10,.7), rgba(10,10,10,0));
}
.cay-hero-vignette {
  position: absolute; inset: 0; z-index: 3; pointer-events: none;
  background: radial-gradient(125% 95% at 50% 32%, rgba(0,0,0,0) 52%, rgba(0,0,0,.42) 100%);
}
.cay-hero-gloss {
  position: absolute; left: 0; right: 0; top: 0; height: 42%; z-index: 3; pointer-events: none;
  background: linear-gradient(180deg, rgba(255,255,255,.16), rgba(255,255,255,0));
}

/* chips */
.cay-chips { position: absolute; left: 34px; bottom: 34px; z-index: 4; height: 74px; }
.cay-chip {
  position: absolute; left: 0; bottom: 0; display: flex; align-items: center; gap: 12px;
  padding: 16px 30px; border-radius: 999px; white-space: nowrap;
  background: linear-gradient(180deg,#FF6A33,#E8420F);
  box-shadow: 0 14px 30px rgba(110,26,0,.5), inset 0 1.5px 0 rgba(255,255,255,.4), inset 0 0 0 1.5px rgba(255,184,0,.5);
  opacity: 0; transform: translateY(8px); transition: opacity .6s ease, transform .6s ease;
}
.cay-chip.is-active { opacity: 1; transform: translateY(0); }
.cay-chip-dot { width: 10px; height: 10px; border-radius: 50%; background: #FFB800; }
.cay-chip-label { font-weight: 800; font-size: 30px; color: #fff; }

/* dots */
.cay-dots { position: absolute; right: 38px; bottom: 60px; z-index: 4; display: flex; align-items: center; gap: 10px; }
.cay-dot {
  width: 11px; height: 11px; border-radius: 999px; background: rgba(255,255,255,.3);
  box-shadow: 0 2px 8px rgba(0,0,0,.3); transition: width .5s cubic-bezier(.16,1,.3,1), background .5s ease;
}
.cay-dot.is-active { width: 46px; background: #FFB800; }

/* stamp */
.cay-stamp {
  position: absolute; right: 30px; top: 30px; z-index: 4;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  width: 132px; height: 132px; border-radius: 50%;
  background: radial-gradient(circle at 38% 32%, #FFD45E, #FFB800 62%); color: #1A1A1A;
  box-shadow: 0 14px 30px rgba(0,0,0,.32), inset 0 0 0 4px rgba(255,255,255,.45);
  animation: cay-wobble 4.5s ease-in-out infinite;
}
.cay-stamp-num { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 35px; line-height: .9; }
.cay-stamp-lab { font-weight: 800; font-size: 20px; letter-spacing: .06em; text-transform: uppercase; }
@keyframes cay-wobble { 0%,100% { transform: rotate(-9deg) scale(1); } 50% { transform: rotate(-5deg) scale(1.05); } }

/* ---------- headline ---------- */
.kiosk-idle-headline {
  position: absolute; top: 1372px; left: 60px; right: 60px; z-index: 6;
  display: flex; flex-direction: column; align-items: center; text-align: center;
}
.kiosk-idle-title {
  min-height: 170px; display: flex; align-items: center; justify-content: center; margin: 0;
  font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 78px;
  line-height: 1.02; letter-spacing: -.02em; color: #fff;
  text-shadow: 0 4px 18px rgba(120,30,0,.3);
}
.kiosk-idle-title :deep(.cay-accent) { color: #FFB800; }
.cay-line-enter-active, .cay-line-leave-active { transition: opacity .6s ease, transform .6s ease, filter .6s ease; }
.cay-line-enter-from { opacity: 0; transform: translateY(20px); filter: blur(12px); }
.cay-line-leave-to { opacity: 0; transform: translateY(-20px); filter: blur(12px); }
.cay-underline {
  width: 140px; height: 7px; margin: 18px 0 14px; border-radius: 999px;
  background: linear-gradient(90deg, rgba(255,184,0,0), #FFB800, rgba(255,184,0,0));
  animation: cay-underline 3.4s ease-in-out infinite;
}
@keyframes cay-underline { 0%,100% { width: 80px; opacity: .65; } 50% { width: 180px; opacity: 1; } }
.kiosk-idle-subtitle {
  margin: 0; font-size: 30px; line-height: 1.3; font-weight: 600; color: rgba(255,255,255,.92);
  text-shadow: 0 2px 12px rgba(0,0,0,.35);
}

/* ---------- badges ---------- */
.cay-badges {
  position: absolute; top: 1612px; left: 0; right: 0; z-index: 6;
  display: flex; align-items: center; justify-content: center; gap: 18px;
}
.cay-badge {
  display: flex; align-items: center; gap: 11px; padding: 13px 26px; border-radius: 999px;
  background: rgba(255,255,255,.16); border: 1.5px solid rgba(255,255,255,.32);
  font-weight: 700; font-size: 26px; color: #fff; backdrop-filter: blur(4px);
}
.cay-badge-dot { width: 9px; height: 9px; border-radius: 50%; background: #FFB800; }

/* ---------- CTA / order type ---------- */
.kiosk-order-type-chooser {
  position: absolute; top: 1700px; left: 60px; right: 60px; z-index: 6;
  display: grid; grid-template-columns: 1fr 1fr; gap: 22px; align-items: stretch;
}
.kiosk-order-type-chooser.is-single { grid-template-columns: 1fr; justify-items: center; }

.kiosk-order-type-card {
  position: relative; display: flex; align-items: center; gap: 26px;
  min-height: 120px; padding: 24px 48px; border: 0; border-radius: 999px;
  background: #fff; color: #F4501E; cursor: pointer; overflow: hidden;
  box-shadow: 0 18px 50px rgba(0,0,0,.28);
  text-align: start; font-family: inherit;
  transition: transform .24s cubic-bezier(.34,1.56,.64,1), box-shadow .24s ease;
}
.kiosk-order-type-chooser.is-single .kiosk-order-type-card--takeaway {
  width: min(760px, 100%); justify-content: center; animation: cay-pulse 2.6s ease-in-out infinite;
}
.kiosk-order-type-card:active { transform: scale(.985); }
.kiosk-order-type-card:focus-visible { outline: 4px solid #FFB800; outline-offset: 4px; }
@keyframes cay-pulse {
  0%,100% { transform: scale(1); box-shadow: 0 18px 50px rgba(0,0,0,.28); }
  50% { transform: scale(1.025); box-shadow: 0 26px 70px rgba(0,0,0,.34); }
}

/* indicateur tactile (ripple) repris du design, à gauche du libellé */
.kiosk-idle-cta { position: relative; width: 86px; height: 86px; flex: 0 0 auto; }
.kiosk-idle-pulse-ring {
  position: absolute; inset: 0; border-radius: 50%; border: 4px solid #F4501E;
  animation: cay-ripple 2.2s ease-out infinite;
}
.kiosk-idle-pulse-ring.delay-1 { border-color: #FFB800; animation-delay: .7s; }
@keyframes cay-ripple {
  0% { transform: scale(.55); opacity: .55; }
  70% { opacity: .12; }
  100% { transform: scale(1.45); opacity: 0; }
}
.kiosk-idle-touch-btn {
  position: absolute; inset: 22px; border-radius: 50%; background: #F4501E; color: #fff;
  display: flex; align-items: center; justify-content: center;
}

/* icône mode (cachée quand single — le ripple suffit) */
.kiosk-order-type-icon {
  flex: 0 0 auto; width: 70px; height: 70px; border-radius: 50%;
  display: grid; place-items: center; background: #FFF3D6; color: #F4501E;
}
.kiosk-order-type-chooser.is-single .kiosk-order-type-icon--takeaway { display: none; }

.kiosk-order-type-text { display: flex; flex-direction: column; line-height: 1; min-width: 0; }
.kiosk-order-type-title {
  font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 50px;
  letter-spacing: -.01em; color: #F4501E;
}
.kiosk-order-type-subtitle { font-weight: 700; font-size: 30px; color: #1A1A1A; margin-top: 6px; }
.kiosk-order-type-card--dine-in .kiosk-order-type-title,
.kiosk-order-type-card--dine-in .kiosk-order-type-subtitle { font-size: 40px; }
.kiosk-order-type-card--dine-in .kiosk-order-type-subtitle { font-size: 24px; }

/* ---------- tap hint ---------- */
.kiosk-idle-tap-hint {
  position: absolute; top: 1858px; left: 0; right: 0; z-index: 6; margin: 0; text-align: center;
  font-weight: 700; font-size: 24px; letter-spacing: .04em; color: rgba(255,255,255,.85);
  animation: cay-arrow 1.8s ease-in-out infinite;
}
@keyframes cay-arrow { 0%,100% { opacity: .6; } 50% { opacity: 1; } }

/* ---------- footer dots (conservé) ---------- */
.kiosk-idle-footer { position: absolute; bottom: 26px; left: 50%; transform: translateX(-50%); z-index: 6; display: flex; gap: 10px; }
.kiosk-idle-footer-dot { width: 9px; height: 9px; border-radius: 50%; background: rgba(255,255,255,.3); transition: width .24s ease, background-color .24s ease, border-radius .24s ease; }
.kiosk-idle-footer-dot.active { width: 34px; border-radius: 4px; background: #FFB800; }

/* ---------- grain ---------- */
.cay-grain {
  position: absolute; inset: 0; z-index: 9; pointer-events: none; opacity: .05; mix-blend-mode: soft-light;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
  background-size: 170px 170px;
}

/* ---------- floating top-right : langue + a11y ---------- */
.kiosk-idle-floating { position: absolute; top: 28px; right: 28px; z-index: 12; display: flex; gap: 12px; align-items: center; }
[dir="rtl"] .kiosk-idle-floating { right: auto; left: 28px; }
.kiosk-lang-selector {
  display: flex; gap: 4px; padding: 6px; border-radius: 999px;
  background: rgba(255,248,241,.92); border: 1px solid rgba(232,221,212,.3);
  backdrop-filter: blur(12px);
}
.kiosk-lang-btn {
  min-height: 56px; min-width: 56px; padding: 8px 18px; border-radius: 999px; border: 0;
  background: transparent; color: #6B5D52; font-family: inherit; font-size: 22px; font-weight: 700; cursor: pointer;
  transition: background-color .12s ease, color .12s ease;
}
.kiosk-lang-btn.active { background: #1A1A1A; color: #fff; }
.kiosk-lang-btn:focus-visible { outline: 3px solid #FFB800; outline-offset: 2px; }
.kiosk-idle-a11y-btn {
  width: 64px; height: 64px; border-radius: 50%; border: 1px solid rgba(232,221,212,.3);
  background: rgba(255,248,241,.92); color: #1A1A1A; display: flex; align-items: center; justify-content: center;
  cursor: pointer; backdrop-filter: blur(12px);
  transition: background .12s ease, transform .12s cubic-bezier(.34,1.56,.64,1);
}
.kiosk-idle-a11y-btn:hover { transform: scale(1.05); }
.kiosk-idle-a11y-btn:active { transform: scale(.96); }
.kiosk-idle-a11y-btn:focus-visible { outline: 3px solid #FFB800; outline-offset: 3px; }

/* ---------- reduced motion ---------- */
@media (prefers-reduced-motion: reduce) {
  .cay-shape-a, .cay-shape-b, .cay-shape-c, .cay-neon-l, .cay-neon-r,
  .cay-eyebrow-text, .cay-stamp, .cay-underline, .kiosk-idle-tap-hint,
  .kiosk-order-type-card--takeaway, .kiosk-idle-pulse-ring,
  .cay-hero-slide.is-active .cay-hero-img { animation: none !important; }
  .kiosk-idle-logo-wrap { transition: none !important; opacity: 1 !important; transform: none !important; }
}
[data-kiosk-reduced-motion='true'] .cay-shape-a,
[data-kiosk-reduced-motion='true'] .cay-shape-b,
[data-kiosk-reduced-motion='true'] .cay-shape-c,
[data-kiosk-reduced-motion='true'] .cay-neon-l,
[data-kiosk-reduced-motion='true'] .cay-neon-r,
[data-kiosk-reduced-motion='true'] .cay-eyebrow-text,
[data-kiosk-reduced-motion='true'] .cay-stamp,
[data-kiosk-reduced-motion='true'] .cay-underline,
[data-kiosk-reduced-motion='true'] .kiosk-idle-tap-hint,
[data-kiosk-reduced-motion='true'] .kiosk-order-type-card--takeaway,
[data-kiosk-reduced-motion='true'] .kiosk-idle-pulse-ring,
[data-kiosk-reduced-motion='true'] .cay-hero-slide.is-active .cay-hero-img { animation: none !important; }
</style>
