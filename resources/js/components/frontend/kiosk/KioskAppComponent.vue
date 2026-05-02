<template>
  <div
    class="kiosk-app"
    :class="`kiosk-theme--${themeMode}`"
    :data-kiosk-theme="themeMode"
    @touchstart="resetIdleTimer"
    @click="resetIdleTimer"
    @keydown="resetIdleTimer"
  >

    <ConnectionStatusBanner suppress-transient suppress-session-invalid />

    <CatalogChangeToastComponent
      :visible="catalogChangeToastVisible"
      :message="catalogChangeToastMessage"
      :removed-selections="catalogChangeRemovedSelections"
      :severity="catalogChangeSeverity"
      @review="reviewCatalogChangedCart"
      @dismiss="dismissCatalogChangeToast"
    />

    <button
      type="button"
      class="kiosk-theme-toggle"
      data-testid="kiosk-theme-toggle"
      :aria-label="themeToggleAriaLabel"
      :aria-pressed="String(themeMode === 'dark')"
      @click.stop="toggleTheme"
    >
      <span aria-hidden="true">{{ themeMode === 'dark' ? '☀' : '☾' }}</span>
    </button>

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
        <button type="button" class="kiosk-init-retry-btn" @click="loadBranch">{{ $t('kiosk.app.retry') }}</button>
      </div>
    </transition>

    <!-- Panier flottant (visible hors idle) -->
    <transition name="slide-down">
      <button
        v-if="showCartBar && cartCount > 0"
        type="button"
        class="kiosk-cart-bar"
        :aria-label="cartBarAriaLabel"
        @click.stop="goToCart"
      >
        <span class="kiosk-cart-bar-left">
          <span class="kiosk-cart-bar-badge">{{ cartCount }}</span>
          <span class="kiosk-cart-bar-label">{{ $t('kiosk.app.cart_bar_label') }}</span>
        </span>
        <span class="kiosk-cart-bar-right">
          <span class="kiosk-cart-bar-total">{{ formatPrice(cartTotal) }}</span>
          <span class="kiosk-cart-bar-arrow" aria-hidden="true">›</span>
        </span>
      </button>
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

    <transition name="slide-down">
      <button
        v-if="showOfflineConflictCta"
        type="button"
        class="kiosk-offline-conflict-cta"
        data-testid="kiosk-offline-conflict-cta"
        @click="openOfflineConflictModal"
      >
        Voir
      </button>
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

    <KioskOfflineConflictModalComponent
      v-model="offlineConflictModalOpen"
      :entries="offlineConflictEntries"
      @opened="trackOfflineConflictModalOpened"
      @cancel-entry="cancelOfflineConflictEntry"
      @force-entry="forceOfflineConflictEntry"
    />

    <!-- Toast notifications globales -->
    <KioskToastComponent ref="toast" />
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';
import {
  cancelStaleEntry,
  forceRetryEntry,
  getAbandonedCount,
  getPendingCount,
  getStaleEntries,
  startAutoSync,
  stopAutoSync,
} from '../../../helpers/kioskOfflineQueue';

import KioskToastComponent from './KioskToastComponent.vue';
import CatalogChangeToastComponent from './CatalogChangeToastComponent.vue';
import KioskOfflineConflictModalComponent from './KioskOfflineConflictModalComponent.vue';
import KioskInactivityOverlayComponent from './KioskInactivityOverlayComponent.vue';
import ConnectionStatusBanner from '../../common/ConnectionStatusBanner.vue';
import axios from 'axios';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { onEvents } from '../../../services/eventContract';
import { useCatalogChangeNotifier } from '../../../composables/useCatalogChangeNotifier';
// [PHASE-4.4] Sync store kioskSettings → <html data-kiosk-* / lang / dir>.
import { applyKioskA11yFromStore } from '../../../composables/useKioskA11y';
import { setLocale } from '../../../i18n';
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
  components: {
    ConnectionStatusBanner,
    KioskToastComponent,
    CatalogChangeToastComponent,
    KioskOfflineConflictModalComponent,
    KioskInactivityOverlayComponent,
  },

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
      _eventSub: null,
      offlineConflictEntries: [],
      offlineConflictModalOpen: false,
      showOfflineConflictCta: false,
      _offlineQuotaListener: null,
      _staleToastDebounceTimer: null,
      _pendingStaleItemIds: new Set(),
      _catalogChangeNotifier: null,
      // Phase 5 — healthcheck + hardware listeners
      _healthcheckTimer: null,
      _hardwareUnsub: null,
      themeMode: 'dark',
    };
  },
  computed: {
    ...mapGetters('kioskCart', ['count', 'total']),
    cartCount() { return this.count; },
    cartTotal() { return this.total; },
    cartBarAriaLabel() {
      const n = this.cartCount;
      const articles = n > 1 ? this.$t('kiosk.article_plural') : this.$t('kiosk.article_singular');
      return `${this.$t('kiosk.app.cart_bar_label')}, ${n} ${articles}, ${this.$t('kiosk.total')} ${this.formatPrice(this.cartTotal)}`;
    },
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
    themeToggleAriaLabel() {
      return this.themeMode === 'dark'
        ? 'Passer en mode clair'
        : 'Passer en mode sombre';
    },
    catalogChangeToastVisible() {
      return !!this._catalogChangeNotifier?.toastVisible?.value;
    },
    catalogChangeToastMessage() {
      return this._catalogChangeNotifier?.toastMessage?.value || '';
    },
    catalogChangeRemovedSelections() {
      return this._catalogChangeNotifier?.removedSelectionsByStep?.value || {};
    },
    catalogChangeSeverity() {
      return this._catalogChangeNotifier?.toastSeverity?.value || 'info';
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
    this.loadKioskTheme();
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
    this.refreshOfflineConflictEntries();
    this._offlineQuotaListener = () => {
      this.$refs.toast?.show('File saturée. Veuillez relancer la borne.', 'error', 6000);
    };
    window.addEventListener('kiosk-offline-queue:quota-exceeded', this._offlineQuotaListener);
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
    if (this._offlineQuotaListener) {
      window.removeEventListener('kiosk-offline-queue:quota-exceeded', this._offlineQuotaListener);
    }
    if (this._staleToastDebounceTimer) {
      clearTimeout(this._staleToastDebounceTimer);
      this._staleToastDebounceTimer = null;
    }
    this._catalogChangeNotifier?.stop?.();
    this._catalogChangeNotifier = null;
    this._pendingStaleItemIds.clear();
  },
  methods: {
    ...mapActions('kioskCart', ['reset', 'setBranch', 'pruneOfflineQueueOnAvailabilityChanged']),
    ...mapActions('frontendBranch', { loadBranchList: 'lists' }),
    ...mapActions('globalState', { _setGlobalState: 'set' }),

    loadKioskTheme() {
      let stored = null;
      try { stored = window.localStorage?.getItem('foodking:kiosk-theme'); } catch (_) {}
      const next = ['dark', 'light'].includes(stored) ? stored : 'dark';
      this.themeMode = next;
      this.applyKioskTheme(next);
    },

    applyKioskTheme(mode) {
      try {
        document.documentElement.setAttribute('data-kiosk-visual-theme', mode);
      } catch (_) {}
    },

    toggleTheme() {
      const next = this.themeMode === 'dark' ? 'light' : 'dark';
      this.themeMode = next;
      try { window.localStorage?.setItem('foodking:kiosk-theme', next); } catch (_) {}
      this.applyKioskTheme(next);
    },

    /**
     * [PHASE-4.4] Wire des watchers Vuex → attributs <html>.
     *  Maintient data-kiosk-contrast / data-kiosk-pmr / data-kiosk-audio /
     *  lang / dir synchronisés avec kioskSettings, sans nécessiter de reload.
     *  Les disposers sont stockés pour cleanup au beforeUnmount.
     */
    _wireA11yWatchers() {
      const applyLocale = (lang) => {
        setLocale(lang || 'fr');
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

    // [KIOSK-16/17] Load public settings into globalState so kiosk components can read
    // company_name, loyalty rates, etc. without individual axios calls.
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
          // W7.A invariant: one kiosk instance operates on one active branch loaded at boot.
          // All real-time availability side effects must stay scoped to that branch only.
          this.setBranch(branch.id);
          this.branchLoading = false;
          // Pre-warm menu cache in background so Categories screen is instant
          this.$store.dispatch('kioskMenu/fetchMenu', { branchId: branch.id }).catch(() => {});
          this._startCatalogChangeNotifier(branch.id);
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
        this._eventSub = onEvents(branchId, [
          {
            broadcastAs: 'ItemAvailabilityChanged',
            handler: (event) => {
              this._handleItemAvailabilityChanged(event, branchId);
            },
          },
          {
            broadcastAs: 'CatalogChanged',
            handler: (event) => {
              this._handleCatalogChanged(event, branchId);
              this._handleCatalogChangeMidSession(event);
            },
          },
          {
            broadcastAs: 'ComposerProfileChanged',
            handler: (event) => {
              this._handleCatalogChangeMidSession(event);
            },
          },
        ]);
      } catch (_) {
        // Echo auth may fail if token not ready — silent fallback to TTL
      }
    },

    _startCatalogChangeNotifier(branchId) {
      this._catalogChangeNotifier?.stop?.();
      this._catalogChangeNotifier = useCatalogChangeNotifier({
        store: this.$store,
        eventContract: { onEvents },
        branchId,
        i18n: { t: this.$t.bind(this) },
        analytics: kioskAnalytics,
        hasOpenWizard: () => this.$route?.name === 'kiosk.wizard',
        autoSubscribe: false,
      });
    },

    _handleCatalogChangeMidSession(event) {
      return this._catalogChangeNotifier?.__onCatalogChanged?.(event)
        ?.catch?.(() => ({ ignored: true, reason: 'catalog_change_notifier_failed' }));
    },

    dismissCatalogChangeToast() {
      this._catalogChangeNotifier?.dismiss?.();
    },

    reviewCatalogChangedCart() {
      this.dismissCatalogChangeToast();
      this.goToCart();
      this.$nextTick(() => {
        setTimeout(() => {
          const panel = document.querySelector('[data-testid="kiosk-cart-root"], .kiosk-cart');
          if (!panel || typeof panel.focus !== 'function') {
            return;
          }
          if (!panel.hasAttribute('tabindex')) {
            panel.setAttribute('tabindex', '-1');
          }
          panel.focus({ preventScroll: true });
        }, 0);
      });
    },

    _normalizeBranchId(value) {
      const normalized = parseInt(value, 10);
      return Number.isFinite(normalized) ? normalized : null;
    },

    _getActiveBranchId() {
      return this._normalizeBranchId(
        this.$store?.getters?.['kioskCart/branchId']
          ?? this.$store?.state?.kioskCart?.branchId
          ?? null
      );
    },

    _showToast(message, type = 'info', duration = 2800) {
      this.$refs.toast?.show(message, type, duration);
    },

    _scheduleStaleToast(itemId) {
      const normalizedItemId = parseInt(itemId, 10);
      this._pendingStaleItemIds.add(Number.isFinite(normalizedItemId) ? normalizedItemId : String(itemId || 'unknown'));
      if (this._staleToastDebounceTimer) {
        clearTimeout(this._staleToastDebounceTimer);
      }
      this._staleToastDebounceTimer = setTimeout(() => {
        this._flushStaleToast();
      }, 800);
    },

    _flushStaleToast() {
      const count = this._pendingStaleItemIds.size;
      this._staleToastDebounceTimer = null;
      if (count === 0) return;

      const message = count === 1
        ? this.$t('kiosk.offline.stale_single')
        : this.$t('kiosk.offline.stale_multiple', { count });

      this._showToast(message, 'warning', 6000);
      this._pendingStaleItemIds.clear();
    },

    _handleItemAvailabilityChanged(event, subscribedBranchId = null) {
      const payload = event?.payload || {};
      const activeBranchId = this._getActiveBranchId();
      const eventBranchId = this._normalizeBranchId(
        event?.branchId
          ?? payload?.branch_id
          ?? payload?.branchId
          ?? subscribedBranchId
      );

      // Defensive guard: a kiosk should only react to the single branch selected at boot.
      if (
        activeBranchId !== null
        && eventBranchId !== null
        && eventBranchId !== activeBranchId
      ) {
        return Promise.resolve({ ignored: true, reason: 'branch_mismatch' });
      }

      // [F-04bis] Distinguish global catalogue update (is_available null/undefined,
      // type = 'status' | 'price' | 'full', branch_id null) from a real branch-scoped
      // availability flip (is_available === true|false, type = 'branch_availability').
      // Global updates MUST NOT prune the kiosk cart — they only require a menu refresh
      // if the change was structural (variations / categories changed).
      const hasAvailabilitySignal =
        payload.is_available === true || payload.is_available === false ||
        payload.is_available === 1 || payload.is_available === 0;

      if (!hasAvailabilitySignal) {
        if (payload.type === 'full') {
          this.$store.dispatch('kioskMenu/fetchMenu', {
            force: true,
            branchId: activeBranchId ?? eventBranchId ?? subscribedBranchId,
          }).catch(() => {});
        }
        return Promise.resolve({ ignored: true, reason: 'global_no_availability_signal' });
      }

      this.$store.commit('kioskMenu/UPDATE_ITEM', payload);
      this.$store.dispatch('kioskCart/pruneUnavailableLines');

      const scopedBranchId = activeBranchId ?? eventBranchId;

      return this.pruneOfflineQueueOnAvailabilityChanged({
        itemId: payload?.id || payload?.item_id,
        branchId: scopedBranchId,
      }).then((result) => {
        if ((result?.updatedEntries || 0) > 0) {
          this.refreshOfflineConflictEntries(result.entries || []);
          this.showOfflineConflictCta = true;
          this._scheduleStaleToast(payload?.id || payload?.item_id);
        }

        // If full refetch needed (price/variations changed), trigger it in background.
        if (payload.type === 'full') {
          this.$store.dispatch('kioskMenu/fetchMenu', {
            force: true,
            branchId: scopedBranchId ?? subscribedBranchId,
          }).catch(() => {});
        }

        return result;
      }).catch(() => ({ ignored: true, reason: 'queue_update_failed' }));
    },

    _handleCatalogChanged(event, subscribedBranchId = null) {
      const payload = event?.payload || {};
      const activeBranchId = this._getActiveBranchId();
      const eventBranchId = this._normalizeBranchId(
        event?.branchId
          ?? payload?.branch_id
          ?? payload?.branchId
          ?? subscribedBranchId
      );

      if (
        activeBranchId !== null
        && eventBranchId !== null
        && eventBranchId !== activeBranchId
      ) {
        return Promise.resolve({ ignored: true, reason: 'branch_mismatch' });
      }

      const branchId = activeBranchId ?? eventBranchId ?? subscribedBranchId;

      return this.$store.dispatch('kioskMenu/fetchMenu', {
        force: true,
        branchId,
      }).then(() => ({ refreshed: true, entityType: payload.entity_type || null }))
        .catch(() => ({ ignored: true, reason: 'menu_refresh_failed' }));
    },

    _leaveEchoChannel() {
      this._eventSub?.unsubscribe();
      this._eventSub = null;
    },

    async refreshOfflineConflictEntries(entries = null) {
      const nextEntries = Array.isArray(entries) ? entries : await getStaleEntries();
      this.offlineConflictEntries = nextEntries;
      this.showOfflineConflictCta = nextEntries.length > 0;
    },

    openOfflineConflictModal() {
      this.offlineConflictModalOpen = true;
    },

    trackOfflineConflictModalOpened() {
      try {
        kioskAnalytics.track('offline.queue.v2.conflict_modal_opened', {
          count: this.offlineConflictEntries.length,
        });
      } catch (_) {}
    },

    async cancelOfflineConflictEntry(localKey) {
      const removed = await cancelStaleEntry(localKey);
      if (removed) {
        await this.refreshOfflineConflictEntries();
        this.offlinePending = getPendingCount();
        this.offlineAbandoned = getAbandonedCount();
        this.$refs.toast?.show('Commande retirée de la file d\'attente.', 'success', 3000);
        try {
          kioskAnalytics.track('offline.queue.v2.conflict_resolved', {
            action: 'cancel',
            count: 1,
          });
        } catch (_) {}
      }
      if (this.offlineConflictEntries.length === 0) {
        this.offlineConflictModalOpen = false;
      }
    },

    async forceOfflineConflictEntry(localKey) {
      const changed = await forceRetryEntry(localKey);
      if (changed) {
        await this.refreshOfflineConflictEntries();
        this.offlinePending = getPendingCount();
        this.offlineAbandoned = getAbandonedCount();
        this.$refs.toast?.show('La commande sera réessayée au prochain cycle.', 'success', 3000);
        try {
          kioskAnalytics.track('offline.queue.v2.conflict_resolved', {
            action: 'force',
            count: 1,
          });
        } catch (_) {}
      }
      if (this.offlineConflictEntries.length === 0) {
        this.offlineConflictModalOpen = false;
      }
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

    startOrder(orderType = null) {
      this.reset();
      if (orderType !== null && orderType !== undefined) {
        this.$store.dispatch('kioskCart/setOrderType', orderType);
      }
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
/* Variables CSS kiosk — scopées à .kiosk-app pour ne pas polluer admin/frontend. */
.kiosk-app {
  --kiosk-primary:       #E8001C;
  --kiosk-primary-dark:  #B8000F;
  --kiosk-primary-soft:  rgba(232, 0, 28, 0.16);
  --kiosk-primary-light: rgba(232, 0, 28, 0.10);
  --kiosk-accent:        #E8001C;
  --kiosk-success:       #22C55E;
  --kiosk-error:         #FF4961;
  --kiosk-warning:       #F59E0B;
  --kiosk-warn:          var(--kiosk-warning);
  --kiosk-info:          #60A5FA;

  --kiosk-bg:            #070304;
  --kiosk-bg-2:          #12080A;
  --kiosk-bg-3:          #1F0A0F;
  --kiosk-surface:       #130B0E;
  --kiosk-surface-alt:   #211317;
  --kiosk-surface-strong:#2D151B;
  --kiosk-border:        rgba(255, 255, 255, 0.13);
  --kiosk-border-strong: rgba(255, 255, 255, 0.26);

  --kiosk-dark:          var(--kiosk-bg);
  --kiosk-dark-2:        var(--kiosk-bg-2);
  --kiosk-dark-3:        var(--kiosk-bg-3);

  --kiosk-text:          #FFFFFF;
  --kiosk-text-2:        rgba(255, 255, 255, 0.82);
  --kiosk-text-muted:    rgba(255, 255, 255, 0.70);
  --kiosk-text-mute:     rgba(255, 255, 255, 0.55);
  --kiosk-text-on-red:   #FFFFFF;
  --kiosk-border-light:  rgba(255, 255, 255, 0.08);

  --kiosk-page-bg:
    radial-gradient(circle at 20% 0%, rgba(232, 0, 28, 0.22), transparent 32%),
    linear-gradient(160deg, #050102 0%, #120407 42%, #2A070E 100%);
  --kiosk-idle-bg:
    linear-gradient(180deg, #180205 0%, #E8001C 55%, #74000B 100%);
  --kiosk-idle-text: #FFFFFF;
  --kiosk-idle-muted: rgba(255, 255, 255, 0.88);
  --kiosk-idle-card-bg: rgba(255, 255, 255, 0.94);
  --kiosk-idle-card-text: #9E0014;
  --kiosk-product-media-bg: radial-gradient(circle at 30% 22%, rgba(255,255,255,0.18), rgba(255,255,255,0.04) 62%);
  --kiosk-theme-control-bg: rgba(19, 11, 14, 0.82);

  --kiosk-shadow:        0 10px 30px rgba(0, 0, 0, 0.24);
  --kiosk-shadow-card:   0 18px 48px rgba(0, 0, 0, 0.28);
  --kiosk-shadow-lift:   0 24px 64px rgba(0, 0, 0, 0.34);
  --kiosk-shadow-cta:    0 18px 46px rgba(232, 0, 28, 0.34);
  --kiosk-shadow-modal:  0 28px 74px rgba(0, 0, 0, 0.42);
  --kiosk-shadow-sticky: 0 -14px 34px rgba(0, 0, 0, 0.24);
  --kiosk-overlay-modal: rgba(0, 0, 0, 0.72);
  --kiosk-focus-ring:    #FFFFFF;
  --kiosk-focus-width:   4px;
  --kiosk-focus-offset:  2px;
  --kiosk-status-bg:     rgba(33, 19, 23, 0.92);
  --kiosk-status-border: rgba(255, 255, 255, 0.20);
  --kiosk-status-offline:#FFB020;

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

.kiosk-app.kiosk-theme--light {
  --kiosk-primary:       #E8001C;
  --kiosk-primary-dark:  #A90014;
  --kiosk-primary-soft:  #FFF0F2;
  --kiosk-primary-light: rgba(232, 0, 28, 0.08);
  --kiosk-success:       #1B8A3A;
  --kiosk-error:         #C21E2F;
  --kiosk-warning:       #B8730B;
  --kiosk-info:          #2563EB;

  --kiosk-bg:            #FFFBF5;
  --kiosk-bg-2:          #FFFFFF;
  --kiosk-bg-3:          #F7F3EC;
  --kiosk-surface:       #FFFFFF;
  --kiosk-surface-alt:   #F7F3EC;
  --kiosk-surface-strong:#FFFFFF;
  --kiosk-border:        #EEE6D9;
  --kiosk-border-strong: #D9C9B8;

  --kiosk-dark:          var(--kiosk-bg);
  --kiosk-dark-2:        var(--kiosk-bg-2);
  --kiosk-dark-3:        var(--kiosk-bg-3);

  --kiosk-text:          #1A1A1A;
  --kiosk-text-2:        #3F3435;
  --kiosk-text-muted:    #5A5A5A;
  --kiosk-text-mute:     #7D7374;
  --kiosk-text-on-red:   #FFFFFF;
  --kiosk-border-light:  rgba(0, 0, 0, 0.06);

  --kiosk-page-bg:
    linear-gradient(180deg, #FFF9F0 0%, #FFFFFF 48%, #FFF0F2 100%);
  --kiosk-idle-bg:
    linear-gradient(180deg, #FFFFFF 0%, #FFF1F3 44%, #E8001C 100%);
  --kiosk-idle-text: #210006;
  --kiosk-idle-muted: #4A2A2F;
  --kiosk-idle-card-bg: #111111;
  --kiosk-idle-card-text: #FFFFFF;
  --kiosk-product-media-bg: radial-gradient(circle at 32% 24%, #FFFFFF, #F7F3EC 68%);
  --kiosk-theme-control-bg: rgba(255, 255, 255, 0.90);

  --kiosk-shadow:        0 2px 8px rgba(20, 20, 20, 0.06);
  --kiosk-shadow-card:   0 8px 24px rgba(20, 20, 20, 0.09);
  --kiosk-shadow-lift:   0 18px 42px rgba(20, 20, 20, 0.14);
  --kiosk-shadow-cta:    0 14px 30px rgba(232, 0, 28, 0.28);
  --kiosk-shadow-modal:  0 24px 48px rgba(26, 26, 26, 0.24);
  --kiosk-shadow-sticky: 0 -8px 24px rgba(0, 0, 0, 0.08);
  --kiosk-overlay-modal: rgba(26, 26, 26, 0.55);
  --kiosk-focus-ring:    #2563EB;
  --kiosk-status-bg:     rgba(255, 255, 255, 0.94);
  --kiosk-status-border: rgba(232, 0, 28, 0.18);
  --kiosk-status-offline:#A94700;
}
.kiosk-app *, .kiosk-app *::before, .kiosk-app *::after {
  -webkit-tap-highlight-color: transparent;
  box-sizing: border-box;
}

.kiosk-app .connection-status-banner {
  top: 14px;
  left: 50%;
  right: auto;
  width: auto;
  max-width: min(620px, calc(100vw - 160px));
  min-height: 42px;
  padding: 8px 44px 8px 18px;
  border-radius: 999px;
  border: 1px solid var(--kiosk-status-border);
  background: var(--kiosk-status-bg);
  color: var(--kiosk-text);
  box-shadow: 0 16px 44px rgba(0, 0, 0, 0.26);
  transform: translateX(-50%);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
}

.kiosk-app .connection-status-banner--reconnecting,
.kiosk-app .connection-status-banner--offline {
  background: var(--kiosk-status-bg);
}

.kiosk-app .connection-status-banner--offline .connection-status-banner__text,
.kiosk-app .connection-status-banner--reconnecting .connection-status-banner__text {
  color: var(--kiosk-status-offline);
}

.kiosk-app .connection-status-banner__text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 900;
}

.kiosk-app .connection-status-banner__close {
  right: 10px;
  width: 30px;
  height: 30px;
  padding: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.10);
}

.kiosk-app.kiosk-theme--light .connection-status-banner__close {
  background: rgba(232, 0, 28, 0.08);
  color: var(--kiosk-primary);
}

.kiosk-app:has(.kiosk-wizard) .connection-status-banner {
  top: 20px;
  left: 100px;
  max-width: min(310px, calc(100vw - 220px));
  transform: none;
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

.kiosk-theme-toggle {
  position: absolute;
  top: 24px;
  inset-inline-start: 24px;
  z-index: 230;
  width: 60px;
  height: 60px;
  min-width: var(--kiosk-touch-comfortable, 60px);
  min-height: var(--kiosk-touch-comfortable, 60px);
  border: 1.5px solid var(--kiosk-border-strong, rgba(255,255,255,0.26));
  border-radius: 50%;
  background: var(--kiosk-theme-control-bg);
  color: var(--kiosk-text);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  font-weight: 900;
  box-shadow: var(--kiosk-shadow-card);
  cursor: pointer;
  transition: transform 0.14s ease, border-color 0.14s ease, background 0.14s ease;
}

.kiosk-theme-toggle:active {
  transform: scale(0.94);
}

.kiosk-theme-toggle:focus-visible {
  outline: var(--kiosk-focus-width, 4px) solid var(--kiosk-focus-ring, #fff);
  outline-offset: var(--kiosk-focus-offset, 2px);
}

[dir="rtl"] .kiosk-theme-toggle {
  inset-inline-start: auto;
  inset-inline-end: 24px;
}

/* Offline sync indicator */
.kiosk-offline-indicator {
  position: absolute;
  top: 12px;
  inset-inline-start: 50%;
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
  inset-inline-start: 50%;
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

.kiosk-offline-conflict-cta {
  position: absolute;
  top: 92px;
  inset-inline-start: 50%;
  transform: translateX(-50%);
  z-index: 201;
  min-height: 44px;
  border: none;
  border-radius: 999px;
  padding: 10px 20px;
  background: var(--kiosk-primary, #E8001C);
  color: #fff;
  font: inherit;
  font-weight: 700;
  box-shadow: 0 8px 20px rgba(232, 0, 28, 0.22);
}

.kiosk-offline-conflict-cta:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563eb);
  outline-offset: 2px;
}

/* Barre panier flottante */
.kiosk-cart-bar {
  position: absolute;
  bottom: 24px;
  inset-inline-start: 50%;
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
  border: none;
  font: inherit;
  text-align: start;
  appearance: none;
  -webkit-appearance: none;
  color: inherit;
}

.kiosk-cart-bar:focus-visible {
  outline: 3px solid var(--kiosk-focus-ring, #2563eb);
  outline-offset: 3px;
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

[dir="rtl"] .kiosk-cart-bar-arrow {
  display: inline-block;
  transform: scaleX(-1);
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

[dir="rtl"] .slide-left-enter-from { transform: translateX(-100%); opacity: 0; }
[dir="rtl"] .slide-left-leave-to   { transform: translateX(100%); opacity: 0; }
[dir="rtl"] .slide-right-enter-from { transform: translateX(100%); opacity: 0; }
[dir="rtl"] .slide-right-leave-to   { transform: translateX(-100%); opacity: 0; }

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
.kiosk-init-error-sub { font-size: 0.95rem; color: #555; margin: 0; text-align: center; max-width: 400px; }
.kiosk-init-retry-btn {
  background: #E8001C; color: #fff;
  border: none; border-radius: 50px;
  padding: 0.85rem 2.5rem; font-size: 1.05rem; font-weight: 700;
  cursor: pointer; transition: background 0.2s;
}
.kiosk-init-retry-btn:hover { background: #c0001a; }

</style>
