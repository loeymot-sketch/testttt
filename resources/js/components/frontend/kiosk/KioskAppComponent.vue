<template>
  <div class="kiosk-app" @touchstart="resetIdleTimer" @click="resetIdleTimer" @keydown="resetIdleTimer">

    <ConnectionStatusBanner />

    <!-- Initialisation : overlay pendant le chargement de la branche -->
    <transition name="fade">
      <div v-if="branchLoading" class="kiosk-init-overlay">
        <div class="kiosk-init-spinner"></div>
        <p class="kiosk-init-label">{{ $t('kiosk.app.init_loading') }}</p>
      </div>
    </transition>

    <!-- Erreur critique : branche non disponible -->
    <transition name="fade">
      <div v-if="branchError && !branchLoading" class="kiosk-init-overlay kiosk-init-error">
        <div class="kiosk-init-error-icon">⚠️</div>
        <p class="kiosk-init-error-title">{{ $t('kiosk.app.service_unavailable') }}</p>
        <p class="kiosk-init-error-sub">{{ branchError }}</p>
        <button class="kiosk-init-retry-btn" @click="loadBranch">{{ $t('kiosk.app.retry') }}</button>
      </div>
    </transition>

    <!-- Panier flottant (visible hors idle) -->
    <transition name="slide-down">
      <div v-if="showCartBar && cartCount > 0" class="kiosk-cart-bar" @click.stop="goToCart">
        <div class="kiosk-cart-bar-left">
          <span class="kiosk-cart-bar-badge">{{ cartCount }}</span>
          <span class="kiosk-cart-bar-label">{{ $t('kiosk.app.cart_bar_label') }}</span>
        </div>
        <div class="kiosk-cart-bar-right">
          <span class="kiosk-cart-bar-total">{{ formatPrice(cartTotal) }}</span>
          <span class="kiosk-cart-bar-arrow">›</span>
        </div>
      </div>
    </transition>

    <!-- Offline sync indicator -->
    <transition name="slide-down">
      <div v-if="offlinePending > 0" class="kiosk-offline-indicator">
        <span class="kiosk-offline-dot" />
        <span>{{
          offlinePending > 1
            ? $t('kiosk.app.offline_pending_many', { n: offlinePending })
            : $t('kiosk.app.offline_pending_one', { n: offlinePending })
        }}</span>
      </div>
    </transition>

    <!-- Commandes non synchronisées après plusieurs échecs — action personnel requise -->
    <transition name="slide-down">
      <div
        v-if="offlineAbandoned > 0"
        class="kiosk-abandoned-indicator"
        :class="{ 'kiosk-abandoned-below-offline': offlinePending > 0 }"
      >
        <span class="kiosk-abandoned-icon">⚠</span>
        <span>{{
          offlineAbandoned > 1
            ? $t('kiosk.app.offline_abandoned_many', { n: offlineAbandoned })
            : $t('kiosk.app.offline_abandoned_one', { n: offlineAbandoned })
        }}</span>
      </div>
    </transition>

    <!-- Vue enfant (page courante) -->
    <router-view
      v-slot="{ Component }"
      @add-to-cart="handleAddToCart"
      @go-to-cart="goToCart"
      @start-order="startOrder"
      @reset-kiosk="resetKiosk"
    >
      <transition :name="transitionName" mode="out-in">
        <!--
          Ne pas inclure ?cat= dans la clé sur kiosk.categories : sinon chaque clic
          catégorie refait toute la transition slide-left du shell (effet « toujours pareil »).
          La liste produits anime alors localement dans KioskCategoriesComponent.
        -->
        <component :is="Component" :key="kioskRouterViewKey" />
      </transition>
    </router-view>

    <!-- Feedback tactile visuel -->
    <transition name="ripple-fade">
      <div v-if="ripple.show" class="kiosk-touch-ripple" :style="rippleStyle" />
    </transition>

    <!-- Phase 8.7 — KioskInactivityOverlayComponent (Écran 13 DESIGN_BRIEF).
         Remplace le modal "Still here" simplifié par un overlay avec countdown
         + 2 CTA (Je suis là / Abandonner). Conforme WAI-ARIA 1.2 alertdialog. -->
    <KioskInactivityOverlayComponent
      :visible="showStillHere"
      :countdown-ms="inactivityCountdownMs"
      @stay="dismissStillHere"
      @leave="onInactivityLeave"
    />

    <!-- Admin panel (accessible via 5 taps rapides sur la zone secrète) -->
    <div
      class="kiosk-admin-trigger"
      @click="handleAdminTap"
      :title="$t('kiosk.app.admin_trigger_title')"
    />
    <transition name="fade">
      <KioskAdminComponent
        v-if="showAdmin"
        @close="showAdmin = false"
      />
    </transition>

    <!-- Toast notifications globales -->
    <KioskToastComponent ref="toast" />
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';
import { getPendingCount, getAbandonedCount, startAutoSync, stopAutoSync } from '../../../helpers/kioskOfflineQueue';
import KioskAdminComponent from './KioskAdminComponent.vue';
import KioskToastComponent from './KioskToastComponent.vue';
import KioskInactivityOverlayComponent from './KioskInactivityOverlayComponent.vue';
import ConnectionStatusBanner from '../../common/ConnectionStatusBanner.vue';
import axios from 'axios';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { onEvent } from '../../../services/eventContract';
// [PHASE-4.4] Sync store kioskSettings → <html data-kiosk-* / lang / dir>.
import { applyKioskA11yFromStore } from '../../../composables/useKioskA11y';
// [PHASE-5] Hardware bridge + analytics helpers
import kioskHardware from '../../../services/kioskHardware';
import kioskAnalytics from '../../../helpers/kioskAnalytics';

// Fallback defaults si kioskSettings.* absents (pré-hydration).
const IDLE_FALLBACK_MS = 180000;
const STILL_HERE_FALLBACK_MS = 30000;
const HEALTHCHECK_INTERVAL_MS = 90000; // brief §5.2 — 90s
// Ordre canonique pour l'animation de transition directionnelle
const ROUTE_ORDER = [
  'kiosk.idle',
  'kiosk.categories',
  'kiosk.products',
  'kiosk.wizard',
  'kiosk.cart',
  'kiosk.loyalty',
  'kiosk.upsell',
  'kiosk.payment',
  'kiosk.waiting',
  'kiosk.confirmation',
];

export default {
  name: 'KioskAppComponent',
  mixins: [kioskPriceMixin],
  components: { ConnectionStatusBanner, KioskAdminComponent, KioskToastComponent, KioskInactivityOverlayComponent },

  // Provide showToast to all kiosk child components via inject('showToast')
  provide() {
    return {
      showToast: (message, type = 'info', duration = 2800) => {
        this.$refs.toast?.show(message, type, duration);
      },
    };
  },

  data() {
    return {
      idleTimer: null,
      stillHereTimer: null,
      showStillHere: false,
      transitionName: 'slide-left',
      ripple: { show: false, x: 0, y: 0 },
      rippleTimer: null,
      branchLoading: true,
      branchError: null,
      offlinePending: 0,
      offlineAbandoned: 0,
      offlineCheckTimer: null,
      // Admin panel — accessible via 5 taps rapides sur la zone secrète
      showAdmin: false,
      _adminTapCount: 0,
      _adminTapTimer: null,
      _eventSub: null,
      // Phase 5 — healthcheck + hardware listeners
      _healthcheckTimer: null,
      _hardwareUnsub: null,
    };
  },
  computed: {
    ...mapGetters('kioskCart', ['count', 'total']),
    cartCount() { return this.count; },
    cartTotal() { return this.total; },
    showCartBar() {
      const hiddenRoutes = ['kiosk.idle', 'kiosk.categories', 'kiosk.cart', 'kiosk.payment', 'kiosk.waiting', 'kiosk.confirmation', 'kiosk.upsell'];
      return !hiddenRoutes.includes(this.$route.name);
    },
    rippleStyle() {
      return { left: this.ripple.x + 'px', top: this.ripple.y + 'px' };
    },
    /**
     * Clé du <component> sous le router-view : si elle change, toute la page repasse
     * par slide-left/slide-right (0.3s). Sur le catalogue, ?cat= ne doit PAS changer la clé.
     * On s’appuie sur meta.kioskStableShell + repli path/name (sans query : route.path).
     */
    kioskRouterViewKey() {
      const r = this.$route;
      if (r.meta?.kioskStableShell) return 'kiosk-shell-catalog';
      const n = r.name;
      if (n === 'kiosk.categories') return 'kiosk-shell-catalog';
      const p = (r.path || '').replace(/\/+$/, '') || '';
      if (p.endsWith('/kiosk/categories')) return 'kiosk-shell-catalog';
      return r.fullPath;
    },
    /**
     * Phase 8.7 — Countdown de l'overlay d'inactivité, dérivé de
     * kioskSettings.confirmMs. Borné [3s, 60s] pour a11y (EAA 2025).
     */
    inactivityCountdownMs() {
      const s = this.$store?.state?.kioskSettings;
      const raw = Number.isFinite(s?.confirmMs) ? s.confirmMs : 15000;
      return Math.min(60000, Math.max(3000, raw));
    },
  },
  watch: {
    $route(to, from) {
      if (to.meta?.kioskStableShell && from.meta?.kioskStableShell) {
        this.transitionName = 'kiosk-shell-static';
        this.resetIdleTimer();
        return;
      }
      const toIdx   = ROUTE_ORDER.indexOf(to.name);
      const fromIdx = ROUTE_ORDER.indexOf(from.name);
      // Unknown routes (e.g. deep links): default forward
      this.transitionName = (fromIdx === -1 || toIdx >= fromIdx) ? 'slide-left' : 'slide-right';
      // Reset idle timer on every navigation
      this.resetIdleTimer();
    },
  },
  mounted() {
    this.startIdleTimer();
    this.loadBranch();
    this._loadSettingsIntoGlobalState();
    // [PHASE-4.4] Applique immédiatement les préférences a11y persistées
    //             sur le <html> root (AAA/PMR/dir/lang). Les watchers Vuex
    //             dans _wireA11yWatchers maintiennent la synchro ensuite.
    applyKioskA11yFromStore(this.$store);
    this._wireA11yWatchers();
    // [PHASE-5.2] Healthcheck initial puis périodique (90s) + boot info()
    this._bootHardware();
    // [PHASE-5.5] Restaure l'état analytics (consent + branch_id) depuis le store.
    this._bootAnalyticsGate();
    document.addEventListener('touchstart', this.handleTouch, { passive: true });
    // Start offline sync and check pending count every 15s
    // [FIX-54-4] Pass config (headers) so syncQueue can send X-Idempotency-Key on replay
    const syncCb = () => {
      this.offlinePending = getPendingCount();
      this.offlineAbandoned = getAbandonedCount();
    };
    startAutoSync((url, data, config) => axios.post(url, data, config || {}), syncCb);
    this.offlinePending = getPendingCount();
    this.offlineAbandoned = getAbandonedCount();
    this.offlineCheckTimer = setInterval(syncCb, 15000);
    if (window._wsService) {
      this._onWsReconnect = () => {
        if (getPendingCount() > 0) {
          startAutoSync((url, data, config) => axios.post(url, data, config || {}), syncCb);
        }
      };
      window._wsService.on('connected', this._onWsReconnect);
    }
  },
  beforeUnmount() {
    this.clearIdleTimer();
    clearTimeout(this._adminTapTimer);
    clearTimeout(this.rippleTimer);
    clearInterval(this.offlineCheckTimer);
    stopAutoSync();
    document.removeEventListener('touchstart', this.handleTouch);
    this._leaveEchoChannel();
    // [PHASE-5.2] Stop healthcheck + unsubscribe hardware events
    if (this._healthcheckTimer) { clearInterval(this._healthcheckTimer); this._healthcheckTimer = null; }
    if (typeof this._hardwareUnsub === 'function') { try { this._hardwareUnsub(); } catch (_) {} }
    this._hardwareUnsub = null;
    // [PHASE-4.4] Dispose les watchers a11y pour éviter les fuites inter-route.
    this._unwatchA11y && this._unwatchA11y.forEach((fn) => { try { fn(); } catch (_) {} });
    this._unwatchA11y = [];
    if (window._wsService && this._onWsReconnect) {
      window._wsService.off('connected', this._onWsReconnect);
    }
  },
  methods: {
    ...mapActions('kioskCart', ['reset', 'setBranch']),
    ...mapActions('frontendBranch', { loadBranchList: 'lists' }),
    ...mapActions('globalState', { _setGlobalState: 'set' }),

    /**
     * [PHASE-4.4] Wire des watchers Vuex → attributs <html>.
     *  Maintient data-kiosk-contrast / data-kiosk-pmr / data-kiosk-audio /
     *  lang / dir synchronisés avec kioskSettings, sans nécessiter de reload.
     *  Les disposers sont stockés pour cleanup au beforeUnmount.
     */
    _wireA11yWatchers() {
      const applyLocale = (lang) => {
        const v = lang || 'fr';
        document.documentElement.setAttribute('lang', v);
        document.documentElement.setAttribute('dir', v === 'ar' ? 'rtl' : 'ltr');
      };
      this._unwatchA11y = [
        this.$store.watch(
          (state) => state.kioskSettings?.contrast,
          (v) => document.documentElement.setAttribute('data-kiosk-contrast', v || 'aa')
        ),
        this.$store.watch(
          (state) => state.kioskSettings?.pmr,
          (v) => document.documentElement.setAttribute('data-kiosk-pmr', v ? 'true' : 'false')
        ),
        this.$store.watch(
          (state) => state.kioskSettings?.audio,
          (v) => document.documentElement.setAttribute('data-kiosk-audio', v ? 'true' : 'false')
        ),
        this.$store.watch(
          (state) => state.kioskSettings?.locale,
          applyLocale
        ),
      ];
    },

    // 5 taps rapides sur la zone secrète (coin bas-gauche) ouvre le panel admin
    handleAdminTap() {
      this._adminTapCount++;
      clearTimeout(this._adminTapTimer);
      this._adminTapTimer = setTimeout(() => { this._adminTapCount = 0; }, 2000);
      if (this._adminTapCount >= 5) {
        this._adminTapCount = 0;
        this.showAdmin = true;
      }
    },

    // [KIOSK-16/17] Load all settings into globalState so kiosk components can read
    // company_name, kiosk_admin_pin, loyalty rates, etc. without individual axios calls.
    // Uses globalState/set (not init) to ensure values are always overwritten — init
    // skips keys that already exist, which would leave stale defaults in place.
    async _loadSettingsIntoGlobalState() {
      try {
        const res = await axios.get('frontend/setting');
        const data = res?.data?.data || {};
        await this._setGlobalState(data);
      } catch (e) {
        // Non-blocking — individual components have their own fallbacks
        console.warn('[KioskApp] Failed to load settings into globalState:', e.message);
      }
    },

    async loadBranch() {
      this.branchLoading = true;
      this.branchError = null;
      try {
        const res = await this.loadBranchList({ vuex: false });
        const branch = res?.data?.data?.[0];
        if (branch?.id) {
          this.setBranch(branch.id);
          this.branchLoading = false;
          // Pre-warm menu cache in background so Categories screen is instant
          this.$store.dispatch('kioskMenu/fetchMenu', { branchId: branch.id }).catch(() => {});
          // [C3] Subscribe to item availability updates for this branch
          this._subscribeEchoChannel(branch.id);
        } else {
          this.branchError = this.$t('kiosk.app.branch_unavailable');
          this.branchLoading = false;
        }
      } catch (err) {
        const msg = err?.response?.status === 401
          ? this.$t('kiosk.app.session_expired')
          : this.$t('kiosk.app.server_unreachable');
        this.branchError = msg;
        this.branchLoading = false;
      }
    },

    // [C3] Subscribe to branch Echo channel for real-time item availability updates.
    // No-op if Echo is not configured (Pusher/Soketi absent) — TTL cache remains the fallback.
    _subscribeEchoChannel(branchId) {
      if (!window.Echo || !branchId) return;
      this._leaveEchoChannel();
      try {
        this._eventSub = onEvent(branchId, 'ItemAvailabilityChanged', (event) => {
          const payload = event.payload;
          this.$store.commit('kioskMenu/UPDATE_ITEM', payload);
          this.$store.dispatch('kioskCart/pruneUnavailableLines');
          // If full refetch needed (price/variations changed), trigger it in background
          if (payload.type === 'full') {
            this.$store.dispatch('kioskMenu/fetchMenu', { force: true, branchId }).catch(() => {});
          }
        });
      } catch (_) {
        // Echo auth may fail if token not ready — silent fallback to TTL
      }
    },

    _leaveEchoChannel() {
      this._eventSub?.unsubscribe();
      this._eventSub = null;
    },

    startIdleTimer() {
      this.clearIdleTimer();
      // [AUDIT-52-BUG3] Also disable timer on payment and confirmation screens:
      // - kiosk.payment: client interacts with physical TPE (no touchstart on screen) — 60s reset
      //   would fire mid-transaction, creating a paid order with no ticket printed.
      // - kiosk.confirmation: order already placed, resetting here loses the receipt display.
      const noTimerRoutes = ['kiosk.idle', 'kiosk.waiting', 'kiosk.payment', 'kiosk.confirmation'];
      if (noTimerRoutes.includes(this.$route?.name)) return;

      // [PHASE-5.3] Lire timeouts depuis kioskSettings store (admin-configurable).
      // Fallback sur les valeurs historiques si le store n'est pas hydraté.
      const s = this.$store?.state?.kioskSettings;
      const idleMs = Number.isFinite(s?.idleMs) ? s.idleMs : IDLE_FALLBACK_MS;
      const confirmMs = Number.isFinite(s?.confirmMs) ? s.confirmMs : STILL_HERE_FALLBACK_MS;
      // stillHere s'affiche `confirmMs` avant le reset final : warnAt = idle - confirm.
      const warnAt = Math.max(1000, idleMs - confirmMs);

      this.stillHereTimer = setTimeout(() => {
        this.showStillHere = true;
        // [PHASE-6.4] Analytics : warning "Toujours là ?" affiché.
        try { kioskAnalytics.track('idle_warning_shown', { warn_at_ms: warnAt }); } catch (_) {}
      }, warnAt);

      this.idleTimer = setTimeout(() => {
        this.showStillHere = false;
        // [PHASE-6.4] Analytics : reset idle effectif (retour écran idle).
        try { kioskAnalytics.track('idle_reset', { idle_ms: idleMs }); } catch (_) {}
        this.resetKiosk();
      }, idleMs);
    },

    clearIdleTimer() {
      if (this.idleTimer)     { clearTimeout(this.idleTimer);     this.idleTimer = null; }
      if (this.stillHereTimer){ clearTimeout(this.stillHereTimer); this.stillHereTimer = null; }
      this.showStillHere = false;
    },

    resetIdleTimer() {
      this.startIdleTimer();
    },

    dismissStillHere() {
      this.showStillHere = false;
      // [PHASE-6.4 / 8.7] Analytics : l'utilisateur a confirmé sa présence.
      try { kioskAnalytics.track('idle_reset', { trigger: 'user' }); } catch (_) {}
      this.startIdleTimer();
    },

    /**
     * Phase 8.7 — Handler "Abandonner" (bouton OU timeout du countdown).
     * Vide panier + reset kiosk → écran idle. Cohérent avec l'invariant §12
     * DATA_CONTRACT (pas de PII persistée après un abandon).
     */
    onInactivityLeave() {
      this.showStillHere = false;
      try { kioskAnalytics.track('idle_dismissed', { trigger: 'overlay' }); } catch (_) {}
      try { this.$store.dispatch('kioskSettings/clearCustomerProfile'); } catch (_) {}
      this.resetKiosk();
    },

    handleTouch(e) {
      this.resetIdleTimer();
      if (e.touches?.[0]) {
        this.showRipple(e.touches[0].clientX, e.touches[0].clientY);
      }
    },

    showRipple(x, y) {
      clearTimeout(this.rippleTimer);
      this.ripple = { show: true, x, y };
      this.rippleTimer = setTimeout(() => { this.ripple.show = false; }, 400);
    },

    startOrder() {
      this.reset();
      this.$router.push({ name: 'kiosk.categories' });
    },

    goToCart() {
      this.$router.push({ name: 'kiosk.cart' });
    },

    handleAddToCart(item) {
      this.$store.dispatch('kioskCart/addItem', item);
    },

    resetKiosk() {
      this.reset();
      this.clearIdleTimer();
      // [PHASE-5.5] Session analytics éphémère — renouvelée à chaque idle reset.
      try { kioskAnalytics.resetSession(); } catch (_) {}
      this.$router.push({ name: 'kiosk.idle' });
    },

    /**
     * [PHASE-5.2] Boot hardware : healthcheck immédiat + info() + abonnement
     * événements bridge, puis healthcheck périodique toutes les 90 s.
     *
     * Non-bloquant : si le bridge est stub/unavailable, ne fait rien de bruyant.
     */
    async _bootHardware() {
      try {
        const hc = await kioskHardware.healthcheck();
        this._reportHealthcheck(hc);
        if (hc?.state === 'critical') {
          const inf = await kioskHardware.info();
          this._reportInfo(inf);
        }
      } catch (_) {}
      // Listener hardware events (printer_paper_out, tpe_disconnected, ...)
      this._hardwareUnsub = kioskHardware.onHardwareEvent((evt) => {
        try {
          kioskHardware.reportHardwareEvent({
            component: evt?.type?.split('_')[0] || 'unknown',
            severity: evt?.severity || 'warning',
            code: evt?.type || 'unknown',
            extra: evt || {},
          });
        } catch (_) {}
      });
      // Healthcheck périodique (90s)
      this._healthcheckTimer = setInterval(async () => {
        try {
          const hc = await kioskHardware.healthcheck();
          this._reportHealthcheck(hc);
        } catch (_) {}
      }, HEALTHCHECK_INTERVAL_MS);
    },

    _reportHealthcheck(hc) {
      if (!hc) return;
      try {
        axios.post('frontend/kiosk-event', {
          type: 'hardware_health',
          details: 'state=' + (hc.state || 'unknown') + ' | degradation=' + (hc.degradation || 'unknown'),
          payload: {
            state: hc.state || null,
            degradation: hc.degradation || null,
            components: hc.components || {},
          },
        }).catch(() => {});
      } catch (_) {}
    },

    _reportInfo(info) {
      if (!info?.ok) return;
      try {
        axios.post('frontend/kiosk-event', {
          type: 'hardware_event',
          details: 'info snapshot',
          payload: info.data || info,
        }).catch(() => {});
      } catch (_) {}
    },

    /**
     * [PHASE-5.5] Initialise le gate consent analytics. Consommé par
     * kioskAnalytics.track() — sans consent, aucun event n'est envoyé.
     */
    _bootAnalyticsGate() {
      try {
        const s = this.$store?.state?.kioskSettings;
        kioskAnalytics.setConsent(!!s?.consentAnalytics);
        const branchId = this.$store?.state?.kioskCart?.branchId;
        if (branchId) kioskAnalytics.setBranchId(branchId);
        // Watcher pour refléter les changements live du store.
        this._unwatchConsent = this.$store.watch(
          (state) => state.kioskSettings?.consentAnalytics,
          (v) => { try { kioskAnalytics.setConsent(!!v); } catch (_) {} }
        );
        this._unwatchBranchForAnalytics = this.$store.watch(
          (state) => state.kioskCart?.branchId,
          (v) => { try { kioskAnalytics.setBranchId(v); } catch (_) {} }
        );
        if (!this._unwatchA11y) this._unwatchA11y = [];
        this._unwatchA11y.push(this._unwatchConsent, this._unwatchBranchForAnalytics);
      } catch (_) {}
    },

    // formatPrice() provided by kioskPriceMixin
  },
};
</script>

<style>
/* Variables CSS kiosk — scopées à .kiosk-app pour ne pas polluer admin/frontend */
/* ═══════════════════════════════════════════════════════════════
   SPLASH / GUR THEME — Fond BLANC, accent ROUGE, aéré, retail
   ═══════════════════════════════════════════════════════════════ */
.kiosk-app {
  --kiosk-primary:     #E8001C;
  --kiosk-primary-dark:#C0001A;
  --kiosk-primary-light: rgba(232,0,28,0.08);
  --kiosk-accent:      #E8001C;
  --kiosk-success:     #2ECC71;
  --kiosk-warn:        #F39C12;

  /* Fond CLAIR — style Splash/GUR */
  --kiosk-bg:          #FFFFFF;
  --kiosk-bg-2:        #F7F7F8;
  --kiosk-bg-3:        #EFEFEF;

  /* Compat: ancien nom → nouveau fond */
  --kiosk-dark:        #FFFFFF;
  --kiosk-dark-2:      #F7F7F8;
  --kiosk-dark-3:      #EFEFEF;

  --kiosk-text:        #1A1A1A;
  --kiosk-text-2:      #555555;
  --kiosk-text-muted:  #999999;
  --kiosk-border:      #E0E0E0;
  --kiosk-border-light:rgba(0,0,0,0.06);
  --kiosk-shadow:      0 2px 8px rgba(0,0,0,0.06);
  --kiosk-shadow-lg:   0 4px 20px rgba(0,0,0,0.10);

  /* Typography */
  --kiosk-title:       28px;
  --kiosk-subtitle:    20px;
  --kiosk-body:        16px;
  --kiosk-small:       13px;

  /* Spacing & sizing */
  --kiosk-gap:         16px;
  --kiosk-pad:         24px;
  --kiosk-card-radius: 16px;
  --kiosk-btn-radius:  12px;
  --kiosk-btn-height:  56px;

  --kiosk-font:        'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;

  -webkit-tap-highlight-color: transparent;
  box-sizing: border-box;
  font-family: var(--kiosk-font);
  color: var(--kiosk-text);
}
.kiosk-app *, .kiosk-app *::before, .kiosk-app *::after {
  -webkit-tap-highlight-color: transparent;
  box-sizing: border-box;
}
</style>

<style scoped>
.kiosk-app {
  position: fixed;
  inset: 0;
  width: 100vw;
  height: 100vh;
  background: var(--kiosk-dark);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  user-select: none;
  touch-action: pan-y;
}

/* Offline sync indicator */
.kiosk-offline-indicator {
  position: absolute;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 200;
  background: rgba(255,165,0,0.15);
  border: 1px solid rgba(255,165,0,0.4);
  border-radius: 50px;
  padding: 6px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: #ffa500;
  white-space: nowrap;
}

.kiosk-offline-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ffa500;
  animation: offlinePulse 1.5s ease-in-out infinite;
}

@keyframes offlinePulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}

.kiosk-abandoned-indicator {
  position: absolute;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 200;
  background: rgba(232, 0, 28, 0.12);
  border: 1px solid rgba(232, 0, 28, 0.45);
  border-radius: 50px;
  padding: 6px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--kiosk-primary, #E8001C);
  white-space: nowrap;
  max-width: calc(100vw - 32px);
}

.kiosk-abandoned-below-offline {
  top: 52px;
}

.kiosk-abandoned-icon {
  flex-shrink: 0;
}

/* Barre panier flottante */
.kiosk-cart-bar {
  position: absolute;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: calc(100% - 48px);
  max-width: 600px;
  background: var(--kiosk-primary);
  border-radius: 20px;
  padding: 16px 24px;
  box-shadow: 0 8px 32px rgba(232, 0, 28, 0.4);
  cursor: pointer;
  gap: 16px;
}

.kiosk-cart-bar-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.kiosk-cart-bar-badge {
  width: 36px;
  height: 36px;
  background: white;
  color: var(--kiosk-primary);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  font-weight: 800;
}

.kiosk-cart-bar-label {
  color: white;
  font-size: 18px;
  font-weight: 600;
}

.kiosk-cart-bar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.kiosk-cart-bar-total {
  color: white;
  font-size: 20px;
  font-weight: 800;
}

.kiosk-cart-bar-arrow {
  color: rgba(255,255,255,0.7);
  font-size: 24px;
  font-weight: 300;
}

/* Feedback tactile */
.kiosk-touch-ripple {
  position: fixed;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.15);
  transform: translate(-50%, -50%);
  pointer-events: none;
  z-index: 9999;
}

/* Transitions entre pages */
.slide-left-enter-active,
.slide-left-leave-active,
.slide-right-enter-active,
.slide-right-leave-active {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.slide-left-enter-from { transform: translateX(100%); opacity: 0; }
.slide-left-leave-to   { transform: translateX(-100%); opacity: 0; }
.slide-right-enter-from { transform: translateX(-100%); opacity: 0; }
.slide-right-leave-to   { transform: translateX(100%); opacity: 0; }

.kiosk-shell-static-enter-active,
.kiosk-shell-static-leave-active {
  transition: none;
}

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s ease; }
.slide-down-enter-from, .slide-down-leave-to { transform: translateX(-50%) translateY(120%); opacity: 0; }

.ripple-fade-enter-active, .ripple-fade-leave-active { transition: all 0.4s ease; }
.ripple-fade-enter-from { transform: translate(-50%, -50%) scale(0); opacity: 0.8; }
.ripple-fade-leave-to   { transform: translate(-50%, -50%) scale(3); opacity: 0; }

/* fade-scale for "Still Here?" modal */
.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.25s ease; }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.92); }

/* "Toujours là ?" modal */
.kiosk-still-here-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-still-here-modal {
  background: white;
  border: 1px solid var(--kiosk-border);
  border-radius: 24px;
  padding: 3rem 2.5rem;
  text-align: center;
  max-width: 480px;
  width: 90%;
  box-shadow: 0 32px 80px rgba(0,0,0,0.2);
}

.kiosk-still-here-icon { font-size: 4rem; margin-bottom: 1rem; }

.kiosk-still-here-title {
  font-size: 2rem;
  font-weight: 800;
  color: var(--kiosk-text);
  margin: 0 0 0.5rem;
}

.kiosk-still-here-sub {
  font-size: 1.05rem;
  color: var(--kiosk-text-muted);
  margin: 0 0 2rem;
}

.kiosk-still-here-btn {
  background: var(--kiosk-primary);
  color: #fff;
  border: none;
  border-radius: 18px;
  padding: 1rem 3rem;
  font-size: 1.2rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(232,0,28,0.4);
  transition: transform 0.1s;
}
.kiosk-still-here-btn:active { transform: scale(0.96); }

/* Initialisation / branch loading overlay — light theme */
.kiosk-init-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: white;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 1.25rem; color: #1A1A1A;
}
.kiosk-init-spinner {
  width: 48px; height: 48px;
  border: 4px solid #E0E0E0;
  border-top-color: #E8001C;
  border-radius: 50%;
  animation: kiosk-spin 0.9s linear infinite;
}
@keyframes kiosk-spin { to { transform: rotate(360deg); } }
.kiosk-init-label { font-size: 1.1rem; color: #999; }
.kiosk-init-error { background: #fff5f5; }
.kiosk-init-error-icon { font-size: 3.5rem; }
.kiosk-init-error-title { font-size: 1.4rem; font-weight: 700; margin: 0; color: #1A1A1A; }
.kiosk-init-error-sub { font-size: 0.95rem; color: #999; margin: 0; text-align: center; max-width: 400px; }
.kiosk-init-retry-btn {
  background: #E8001C; color: #fff;
  border: none; border-radius: 50px;
  padding: 0.85rem 2.5rem; font-size: 1.05rem; font-weight: 700;
  cursor: pointer; transition: background 0.2s;
}
.kiosk-init-retry-btn:hover { background: #c0001a; }

/* Zone secrète admin — coin bas-gauche, invisible */
.kiosk-admin-trigger {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 60px;
  height: 60px;
  z-index: 9990;
  cursor: default;
  /* Invisible but tappable */
  background: transparent;
}
</style>
