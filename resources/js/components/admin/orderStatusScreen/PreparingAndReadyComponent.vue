<template>
  <LoadingContentComponent :props="loading" />
  <!--
    [iter15-mega-fix B-003/D-002 2026-05-10] Local ws-reconnect-banner removed —
    duplicate of the global ConnectionStatusBanner mounted by the parent
    OrderStatusScreenComponent. Showing two banners simultaneously
    ("Reconnexion en cours…" + "Mode secours actif") was UX clutter flagged
    in iter15 mega-audit Wave B/D. The global banner debounces 5s and is
    hidden in dev via foodkingConfig.appEnv.
  -->

  <!-- Colonne EN PRÉPARATION -->
  <!--
    [Wave S-3 TV-optim P-OWNER 2026-05-20] Customer wall must be readable from ≥3m.
    - Header bumped text-lg (~18px) → text-[40px] (40px) to surface column intent at distance.
    - Order tokens bumped text-[40px] → text-[56px] for triple-distance comfort margin (>= 40px mandate).
    - Brand colors #B0004D (preparing) / #1AB759 (ready) preserved per CLAUDE.md flat/organized doctrine
      + previous Wave Q-3 attestation (red-600/green-600 hint in spec = intent = already met).
    - Auto-scroll: items.length > 8 toggles `.oss-autoscroll` (pure-CSS keyframe loop, no JS RAF
      to avoid fighting transition-group on enter/leave).
  -->
  <div
    class="col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden"
    role="region"
    :aria-label="$t('label.preparing')"
  >
    <h3 class="oss-column-header text-[40px] font-bold text-white p-4 pb-3 bg-[#B0004D] mb-2 rounded-t-[10px] text-center tracking-wide">
      {{ $t("label.preparing") }}
    </h3>
    <div class="content-wrapper p-3 overflow-hidden thin-scrolling h-full">
      <transition-group name="oss-slide" tag="ul"
        :class="['oss-order-list', preparingItems.length > 8 ? 'oss-autoscroll' : '',
                 '[&_li]:mb-8 [&_li]:text-[56px] [&_li]:font-extrabold [&_li]:leading-[1.1] w-full text-center text-[#1F1F39] mb-20']">
        <li v-for="item in preparingItems" :key="item.id"
          class="oss-order-number"
          :class="item.queue_number ? 'text-[#991B1B]' : 'text-[#1F1F39]'">
          {{ item.queue_number ? 'N°' + item.queue_number : item.token }}
        </li>
      </transition-group>
      <p v-if="preparingItems.length === 0" class="text-center text-[#A0A3BD] text-[28px] mt-12">—</p>
    </div>
  </div>

  <!-- Colonne PRÊT -->
  <div class="col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden"
    :class="newReadyFlash ? 'oss-ready-flash' : ''"
    role="region"
    :aria-label="$t('label.ready')">
    <h3 class="oss-column-header text-[40px] font-bold text-[#1F1F39] p-4 pb-3 bg-[#1AB759] mb-2 rounded-t-[10px] text-center tracking-wide">
      {{ $t("label.ready") }}
    </h3>
    <div class="content-wrapper p-3 overflow-hidden thin-scrolling h-full">
      <transition-group name="oss-pop" tag="ul"
        role="status"
        aria-live="polite"
        :aria-label="$t('label.ready')"
        :class="['oss-order-list', preparedItems.length > 8 ? 'oss-autoscroll' : '',
                 '[&_li]:mb-8 [&_li]:text-[56px] [&_li]:font-extrabold [&_li]:leading-[1.1] w-full text-center text-[#1F1F39] mb-20']">
        <li v-for="item in preparedItems" :key="item.id"
          class="oss-order-number text-[#0E7C3A] font-extrabold"
          :class="newReadyIds.has(item.id) ? 'oss-new-ready oss-pulse-ready' : ''">
          {{ item.queue_number ? 'N°' + item.queue_number : item.token }}
        </li>
      </transition-group>
      <p v-if="preparedItems.length === 0" class="text-center text-[#A0A3BD] text-[28px] mt-12">—</p>
    </div>
  </div>
</template>

<script>
// [RED-team R4 V1.0.2 2026-05-17] wakeLock screen for TV wall surfaces.
// Customer TV idles long between order events; without `navigator.wakeLock.request('screen')`
// the OS screen-saver sleeps the display, making the green flash + chime invisible/inaudible
// when a new order moves to PREPARED. Acquire on mount, re-acquire on `visibilitychange`
// (browsers auto-release on tab switch / OS lock), release on unmount. Graceful degrade on
// browsers without API (Safari iOS <16.4); feature-flag `window.foodkingConfig.ossWakeLockEnabled`
// (default true). No external deps — native browser API.
import LoadingContentComponent from "../components/LoadingContentComponent";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import alertService from "../../../services/alertService";
import { onEvents } from "../../../services/eventContract";
import ossSyncService from "../../../services/OssSyncService";

export default {
  name: "PreparingAndReadyComponent",
  components: { LoadingContentComponent },
  data() {
    return {
      loading: { isActive: false },
      preparedItems: [],
      preparingItems: [],
      enums: { orderStatusEnum },
      wsConnected: !!(window._wsService?.isConnected()),
      _eventSub: null,
      ossSyncUnsubscribers: [],
      // IDs des commandes nouvellement passées à PREPARED (pour animation)
      newReadyIds: new Set(),
      newReadyFlash: false,
      _flashTimer: null,
      // [#14] Per-order "clear highlight" timers — tracked so they are cleared on
      // unmount and replaced on rapid re-marks (the inner 6s setTimeout was
      // previously untracked → post-unmount mutation + timer leak on a 24/7 wall).
      _readyClearTimers: {},
      // [iter15-mega-fix C-034 round-7 2026-05-10] AudioContext is now
      // lazy-initialized on the first user gesture. Prior implementation
      // created a fresh suspended context on EVERY Echo `prepared` event, which
      // flooded the customer screen console with autoplay warnings (~8x per
      // session) because Chrome blocks AudioContext until a user gesture.
      _audioCtx: null,
      _audioInitListener: null,
      // [RED-team R4 V1.0.2 2026-05-17] wakeLock sentinel + visibility handler refs
      _wakeLockSentinel: null,
      _onVisibilityChange: null,
    };
  },
  computed: {},
  mounted() {
    this.list();
    window.addEventListener('realtime-order-update', this.list);
    this.subscribeEcho();
    this._bindWsService();
    this.startOssSync();
    // [iter15-mega-fix C-034 round-7 2026-05-10] Wire a one-shot user-gesture
    // listener that creates the shared AudioContext. Until the user clicks
    // anywhere on the screen, _playReadySound() is a silent no-op so the
    // browser does not log "AudioContext was not allowed to start" warnings.
    this._audioInitListener = () => {
      try {
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (Ctor) this._audioCtx = new Ctor();
      } catch (_) { this._audioCtx = null; }
    };
    // [GOAL Round 2 Impl C — P0-OSS-01 2026-05-18] Skip audio-unlock listener
    // wiring on the public customer wall. A public TV wall (`authBranchId() === 0`)
    // never receives a `pointerdown` / `keydown` gesture, so the `{ once: true }`
    // listeners would sit forever and `_playReadySound()` would silently no-op
    // (Agent 4 finding `[OSS-B-02]` — chime dead on the only surface that
    // needs it). Mirror the `subscribeEcho()` early-return idiom (line ~233:
    // `if (branchId <= 0) return`). Operator-attended surfaces (admin /
    // branch staff sessions) keep the original lazy-init pattern: the
    // operator clicks Vue routes on mount, which unlocks AudioContext and
    // allows the 3-tone chime to play on PREPARED transitions. Visual
    // notification (`.oss-ready-flash` + `.oss-new-ready` bounce) remains the
    // sole feedback channel on the public wall and was attested working by
    // Agent 4 §3 — no degradation.
    if (this.authBranchId() > 0) {
      try {
        window.addEventListener('pointerdown', this._audioInitListener, { once: true, passive: true });
        window.addEventListener('keydown', this._audioInitListener, { once: true, passive: true });
      } catch (_) { /* never block mount on listener wiring */ }
    }
    // [RED-team R4 V1.0.2 2026-05-17] Acquire screen wakeLock + re-acquire on visibilitychange.
    this._acquireWakeLock();
    this._onVisibilityChange = () => {
      if (document.visibilityState === 'visible') this._acquireWakeLock();
    };
    try { document.addEventListener('visibilitychange', this._onVisibilityChange); } catch (_) { /* noop */ }
  },
  beforeUnmount() {
    window.removeEventListener('realtime-order-update', this.list);
    this.unsubscribeEcho();
    this._unbindWsService();
    this.stopOssSync();
    if (this._flashTimer) clearTimeout(this._flashTimer);
    // [#14] Clear any pending per-order highlight timers so none fire post-unmount.
    Object.values(this._readyClearTimers || {}).forEach((t) => { try { clearTimeout(t); } catch (_) { /* noop */ } });
    this._readyClearTimers = {};
    // [iter15-mega-fix C-034 round-7 2026-05-10] Tear down audio listeners +
    // close the context so the next mount starts clean.
    try {
      if (this._audioInitListener) {
        window.removeEventListener('pointerdown', this._audioInitListener);
        window.removeEventListener('keydown', this._audioInitListener);
      }
    } catch (_) { /* noop */ }
    try { this._audioCtx?.close?.(); } catch (_) { /* noop */ }
    this._audioCtx = null;
    this._audioInitListener = null;
    // [RED-team R4 V1.0.2 2026-05-17] Release wakeLock + drop visibility listener.
    try {
      if (this._onVisibilityChange) document.removeEventListener('visibilitychange', this._onVisibilityChange);
    } catch (_) { /* noop */ }
    this._onVisibilityChange = null;
    this._releaseWakeLock();
  },
  methods: {
    authBranchId() {
      const candidates = [
        this.$store.getters['auth/authBranchId'],
        this.$store.getters.authBranchId,
        this.$store.state?.auth?.authBranchId,
      ];

      for (const candidate of candidates) {
        if (candidate === '' || candidate === null || typeof candidate === 'undefined') {
          continue;
        }

        const value = parseInt(candidate, 10);
        if (Number.isFinite(value)) {
          return value;
        }
      }

      return 0;
    },
    // [RED-team R4 V1.0.2 2026-05-17] Best-effort screen wakeLock for TV walls.
    async _acquireWakeLock() {
      const flag = window?.foodkingConfig?.ossWakeLockEnabled;
      if (flag === false) return;
      if (!('wakeLock' in navigator) || typeof navigator.wakeLock?.request !== 'function') return;
      if (this._wakeLockSentinel) return;
      try {
        const sentinel = await navigator.wakeLock.request('screen');
        this._wakeLockSentinel = sentinel;
        try { sentinel.addEventListener?.('release', () => { this._wakeLockSentinel = null; }); } catch (_) { /* noop */ }
      } catch (_) { this._wakeLockSentinel = null; /* graceful degrade */ }
    },
    _releaseWakeLock() {
      const sentinel = this._wakeLockSentinel;
      this._wakeLockSentinel = null;
      if (!sentinel) return;
      try { sentinel.release?.(); } catch (_) { /* noop */ }
    },
    _bindWsService() {
      const ws = window._wsService;
      if (!ws) return;
      this._onWsConnected = () => {
        this.wsConnected = true;
        this.list();
      };
      this._onWsDisconnected = () => {
        this.wsConnected = false;
      };
      ws.on('connected', this._onWsConnected);
      ws.on('disconnected', this._onWsDisconnected);
    },
    _unbindWsService() {
      const ws = window._wsService;
      if (!ws) return;
      if (this._onWsConnected) ws.off('connected', this._onWsConnected);
      if (this._onWsDisconnected) ws.off('disconnected', this._onWsDisconnected);
    },
    startOssSync() {
      this.ossSyncUnsubscribers.push(
        ossSyncService.on('sync', ({ rows = [] }) => {
          this._hydrateFromRows(rows);
        })
      );
      this.ossSyncUnsubscribers.push(
        ossSyncService.on('ws_state', ({ state }) => {
          this.wsConnected = String(state || '').toLowerCase() === 'connected';
        })
      );
      // [TRAP-4 2026-06-04] Public/unauth customer status wall: branchId<=0 so
      // subscribeEcho() early-returns (line ~263) and we never join the private
      // branch.{id} channel — zero push reaches this surface. But the WS
      // *transport* still reports 'connected' (Echo/Pusher is up), so
      // OssSyncService picks intervalMsWhenConnected (60_000ms) and the wall lags
      // PRÉPARATION→PRÊT by up to ~1 min, blowing the SYNC-2 8s budget (POS pay →
      // OSS visible). Since this surface is poll-only (no push subscription),
      // override the connected cadence to a snappy 5s for it alone via ctx.options
      // (highest precedence in start()/_runtimeConfig). Bus untouched — no
      // channel/event/payload change, no new broadcast channel, no LOCK needed.
      // Authed staff OSS (branchId>0) is unaffected and keeps 60_000ms.
      const isPublicWall = this.authBranchId() <= 0;
      ossSyncService.start({
        store: this.$store,
        webSocketService: window._wsService,
        ...(isPublicWall ? { options: { intervalMsWhenConnected: 5_000 } } : {}),
      });
    },
    stopOssSync() {
      try { ossSyncService.stop(); } catch (_) {}
      (this.ossSyncUnsubscribers || []).forEach((u) => {
        try { u && u(); } catch (_) {}
      });
      this.ossSyncUnsubscribers = [];
    },
    subscribeEcho() {
      if (!window.Echo) return;
      const branchId = this.authBranchId();
      if (branchId <= 0) return;
      // [AUDIT-52-BUG2] Always unsubscribe first to prevent duplicate listeners on re-mount
      this.unsubscribeEcho();
      try {
        this._eventSub = onEvents(branchId, [
          {
            broadcastAs: 'OrderStatusChanged',
            handler: (event) => {
              const data = event.payload || {};
              // [AUDIT-P1] De-duplicate _markNewReady: Echo fires it here, then list() would fire it
              // again because the order is absent from prevPreparedIds (list hasn't refreshed yet).
              // Solution: pre-register the ID in _echoMarkedReady so list() skips it.
              if (parseInt(data.new_status, 10) === orderStatusEnum.PREPARED) {
                const oid = parseInt(data.order_id, 10);
                this._echoMarkedReady = this._echoMarkedReady || new Set();
                this._echoMarkedReady.add(oid);
                this._markNewReady(oid);
              }
              this.list();
            },
          },
          {
            broadcastAs: 'OrderCreated',
            handler: () => { this.list(); },
          },
        ]);
        // [P13_LOG_HYGIENE] console.log(`[OSS] Echo subscribed to branch.${branchId}`);
      } catch (e) {
        console.warn('[OSS] Echo subscription failed:', e.message);
      }
    },
    unsubscribeEcho() {
      const branchId = this.authBranchId();
      if (branchId <= 0) return;
      try {
        this._eventSub?.unsubscribe();
        // [P13_LOG_HYGIENE] console.log(`[OSS] Echo unsubscribed from branch.${branchId}`);
      } catch (e) {
        console.warn('[OSS] Echo unsubscribe error:', e.message);
      }
      this._eventSub = null;
    },
    // Mark an order as newly ready: plays sound + triggers flash animation.
    // [Wave S-3 TV-optim P-OWNER 2026-05-20] Window extended 6s → 10s total
    // (4s column-flash + 6s per-card pulse) per owner directive — TV walls
    // are scanned at ≥3m so attention-grabbing needs to persist long enough
    // for a customer who looks up from a 2-3s task to catch the transition.
    // CSS `.oss-pulse-ready` runs `oss-pulse 1.6s ease infinite` while the
    // class is applied (not a fixed-duration keyframe), so the visual cue
    // tracks `newReadyIds` exactly.
    _markNewReady(orderId) {
      if (!orderId) return;
      const id = parseInt(orderId);
      this.newReadyIds = new Set([...this.newReadyIds, id]);
      this._playReadySound();
      // Column-level flash: a single shared 4s timer is fine (the whole column
      // flashes), so re-marking simply restarts the column flash.
      this.newReadyFlash = true;
      if (this._flashTimer) clearTimeout(this._flashTimer);
      this._flashTimer = setTimeout(() => { this.newReadyFlash = false; }, 4000);
      // [#14] Per-order highlight clear (~10s total) tracked INDEPENDENTLY of the
      // shared column-flash timer. Nesting it inside _flashTimer (RED cross-order
      // finding) meant marking a 2nd order <4s later clobbered the shared timer
      // and the 1st order's clear was never registered → it pulsed forever.
      // Replaced on a same-id re-mark; all cleared in beforeUnmount.
      if (this._readyClearTimers[id]) clearTimeout(this._readyClearTimers[id]);
      this._readyClearTimers[id] = setTimeout(() => {
        const ids = new Set(this.newReadyIds);
        ids.delete(id);
        this.newReadyIds = ids;
        delete this._readyClearTimers[id];
      }, 10000);
    },
    // Splash-inspired: 3-tone ascending chime when order is ready
    _playReadySound() {
      // [GOAL Round 2 Impl C — P0-OSS-01 2026-05-18] Public-wall gate.
      // `authBranchId() <= 0` indicates the unauthenticated customer wall
      // (Vuex `authStatus=false` branch in `orderStatusScreenOrder.js`).
      // That surface has no operator and no audio-unlock gesture, so the
      // chime is structurally inaudible — early-return graceful skip
      // (visual `.oss-ready-flash` continues to fire from `_markNewReady()`,
      // which is the documented `[OSS-B-02]` heal path Option C). Operator-
      // attended surfaces (`authBranchId() > 0`) keep full chime behaviour.
      if (this.authBranchId() <= 0) return;
      // [iter15-mega-fix C-034 round-7 2026-05-10] Lazy-init pattern: bail out
      // silently if the user has not yet interacted with the screen. We do
      // NOT create a fresh AudioContext per call (that was flooding the
      // console with `AudioContext was not allowed to start` warnings on the
      // customer screen which never receives user gestures). When _audioCtx
      // exists but is suspended (Safari, screen-saver wake), best-effort
      // resume() before playing.
      const ctx = this._audioCtx;
      if (!ctx) return;
      try {
        if (ctx.state === 'suspended') {
          // resume() returns a Promise — fire-and-forget; if it rejects we
          // skip this chime rather than spam the console.
          ctx.resume?.().catch(() => {});
          if (ctx.state !== 'running') return;
        }
        [523, 659, 784, 1047].forEach((freq, i) => {
          const osc  = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.frequency.value = freq;
          osc.type = 'sine';
          gain.gain.setValueAtTime(0.25, ctx.currentTime + i * 0.15);
          gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.15 + 0.35);
          osc.start(ctx.currentTime + i * 0.15);
          osc.stop(ctx.currentTime + i * 0.15 + 0.4);
        });
      } catch (_) { /* never throw from chime */ }
    },
    _hydrateFromRows(rows) {
      // [OSS-01 HEAL 2026-07-10] Le mur CLIENT n'affiche que les commandes ayant un identifiant
      // visible (n° de file OU token) : sans ça, une commande à queue_number ET token null peignait
      // un <li> VIDE et restait « zombie » sur le mur. Le client ne peut de toute façon pas
      // récupérer une commande sans numéro affiché.
      const identified = (rows || []).filter(i => i && (i.queue_number || i.token));
      const prevPreparedIds = new Set(this.preparedItems.map(i => i.id));
      this.preparingItems = identified.filter(i => i.status === orderStatusEnum.PREPARING);
      const newPrepared = identified.filter(i => i.status === orderStatusEnum.PREPARED);

      // Detect orders that just moved to PREPARED (not in previous list).
      // [AUDIT-P1] Skip IDs already marked via Echo to prevent double chime/flash.
      // [OSS-FALSE-READY HEAL 2026-07-16] Au TOUT PREMIER chargement (reload de l'écran),
      // preparedItems est vide → SANS garde, TOUTES les commandes déjà PRÊTES seraient traitées
      // comme « viennent de passer prêtes » → carillon + flash de MASSE à chaque reload (fausse
      // notification). On amorce via _primed : au 1er render on peuple la liste SANS notifier ;
      // les vraies nouveautés ne sont détectées qu'aux hydratations suivantes.
      const echoMarked = this._echoMarkedReady || new Set();
      if (this._primed) {
        newPrepared.forEach(item => {
          if (!prevPreparedIds.has(item.id) && !echoMarked.has(item.id)) {
            this._markNewReady(item.id);
          }
        });
      } else {
        this._primed = true;
      }
      // Clear the echo-marked set after list() processes it (one-shot guard)
      this._echoMarkedReady = new Set();

      this.preparedItems = newPrepared;
    },
    list() {
      this.loading.isActive = true;
      this.$store
        .dispatch("orderStatusScreenOrder/lists")
        .then((res) => {
          this._hydrateFromRows(res.data.data || []);
          this.loading.isActive = false;
        })
        .catch((err) => {
          this.loading.isActive = false;
          alertService.error(err?.response?.data?.message || this.$t('message.something_wrong'));
        });
    },
  },
};
</script>

<style scoped>
/* [iter15-mega-fix B-003/D-002 2026-05-10] .ws-reconnect-banner CSS removed:
   the only consumer of this class was the duplicate banner deleted from the
   template above. Connection status is owned by ConnectionStatusBanner.vue. */
/* Slide-in for preparing column */
.oss-slide-enter-active { transition: all 0.4s ease; }
.oss-slide-leave-active { transition: all 0.3s ease; }
.oss-slide-enter-from   { opacity: 0; transform: translateX(-20px); }
.oss-slide-leave-to     { opacity: 0; transform: translateX(20px); }

/* Pop-in for ready column */
.oss-pop-enter-active { transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); }
.oss-pop-leave-active { transition: all 0.3s ease; }
.oss-pop-enter-from   { opacity: 0; transform: scale(0.6); }
.oss-pop-leave-to     { opacity: 0; transform: scale(0.8); }

/* Highlight for newly-ready orders — initial bounce burst */
.oss-new-ready {
  animation: oss-bounce 0.6s ease 2;
}
@keyframes oss-bounce {
  0%, 100% { transform: scale(1); }
  50%       { transform: scale(1.12); }
}

/* [Wave S-3 TV-optim P-OWNER 2026-05-20] Long-tail pulse to attract customer
   attention at ≥3m for the full 10s window while the order ID is in
   newReadyIds. Subtle scale + green halo via text-shadow — does NOT shift
   layout (transform-only) so neighbouring items don't reflow. Pulse runs
   alongside the initial .oss-new-ready bounce (different keyframe names,
   no conflict) and continues as `infinite` until the class is removed by
   the JS timeout in _markNewReady. */
.oss-pulse-ready {
  animation: oss-pulse 1.6s ease-in-out infinite;
}
@keyframes oss-pulse {
  0%, 100% {
    transform: scale(1);
    text-shadow: 0 0 0 rgba(14, 124, 58, 0);
  }
  50% {
    transform: scale(1.04);
    text-shadow: 0 0 24px rgba(14, 124, 58, 0.55);
  }
}

/* Flash the entire ready column green when a new order is ready */
.oss-ready-flash {
  animation: oss-flash 0.8s ease 2;
}
@keyframes oss-flash {
  0%, 100% { background-color: transparent; }
  50%       { background-color: rgba(26, 183, 89, 0.15); }
}

/* [Wave S-3 TV-optim P-OWNER 2026-05-20] Vertical auto-scroll loop for busy
   columns (>8 orders). Pure-CSS keyframe — no JS RAF — so it never fights
   <transition-group> on enter/leave. Loops every 30s with a 2s pause at the
   start so freshly-arrived orders sit visible before scroll begins. We
   translateY a copy-free list and rely on overflow-hidden on the parent;
   when the column drops below the threshold the class is removed and the
   list snaps back to translateY(0) cleanly. Limit applies to either column
   independently. */
.oss-order-list {
  will-change: transform;
}
.oss-autoscroll {
  animation: oss-scroll-loop 30s linear infinite;
}
@keyframes oss-scroll-loop {
  0%   { transform: translateY(0); }
  10%  { transform: translateY(0); }
  90%  { transform: translateY(-50%); }
  100% { transform: translateY(0); }
}

/* Respect operator preferences — disable motion for sensitive contexts. */
@media (prefers-reduced-motion: reduce) {
  .oss-pulse-ready,
  .oss-autoscroll,
  .oss-new-ready,
  .oss-ready-flash {
    animation: none !important;
  }
}
</style>
