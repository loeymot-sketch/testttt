<template>
  <LoadingContentComponent :props="loading" />
  <div v-if="!wsConnected" class="ws-reconnect-banner">
    {{ $t('label.oss_fallback_banner') }}
  </div>

  <!-- Colonne EN PRÉPARATION -->
  <div
    class="col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden"
    role="region"
    :aria-label="$t('label.preparing')"
  >
    <h3 class="text-lg font-semibold text-white p-3 pb-2 bg-[#B0004D] mb-2 rounded-t-[10px] text-center">
      {{ $t("label.preparing") }}
    </h3>
    <div class="content-wrapper p-3 overflow-auto thin-scrolling h-full">
      <transition-group name="oss-slide" tag="ul"
        class="[&_li]:mb-6 [&_li]:text-[40px] [&_li]:font-semibold [&_li]:leading-10 w-full text-center text-[#1F1F39] mb-20">
        <li v-for="item in preparingItems" :key="item.id"
          :class="item.queue_number ? 'text-[#991B1B]' : 'text-[#1F1F39]'">
          {{ item.queue_number ? 'N°' + item.queue_number : item.token }}
        </li>
      </transition-group>
      <p v-if="preparingItems.length === 0" class="text-center text-[#A0A3BD] text-base mt-8">—</p>
    </div>
  </div>

  <!-- Colonne PRÊT -->
  <div class="col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden"
    :class="newReadyFlash ? 'oss-ready-flash' : ''"
    role="region"
    :aria-label="$t('label.ready')">
    <h3 class="text-lg font-semibold text-[#1F1F39] p-3 pb-2 bg-[#1AB759] mb-2 rounded-t-[10px] text-center">
      {{ $t("label.ready") }}
    </h3>
    <div class="content-wrapper p-3 overflow-auto thin-scrolling h-full">
      <transition-group name="oss-pop" tag="ul"
        class="[&_li]:mb-6 [&_li]:text-[40px] [&_li]:font-semibold [&_li]:leading-10 w-full text-center text-[#1F1F39] mb-20">
        <li v-for="item in preparedItems" :key="item.id"
          class="text-[#2AC769] font-extrabold"
          :class="newReadyIds.has(item.id) ? 'oss-new-ready' : ''">
          {{ item.queue_number ? 'N°' + item.queue_number : item.token }}
        </li>
      </transition-group>
      <p v-if="preparedItems.length === 0" class="text-center text-[#A0A3BD] text-base mt-8">—</p>
    </div>
  </div>
</template>

<script>
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
    };
  },
  computed: {},
  mounted() {
    this.list();
    window.addEventListener('realtime-order-update', this.list);
    this.subscribeEcho();
    this._bindWsService();
    this.startOssSync();
  },
  beforeUnmount() {
    window.removeEventListener('realtime-order-update', this.list);
    this.unsubscribeEcho();
    this._unbindWsService();
    this.stopOssSync();
    if (this._flashTimer) clearTimeout(this._flashTimer);
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
      ossSyncService.start({
        store: this.$store,
        webSocketService: window._wsService,
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
    // Mark an order as newly ready: plays sound + triggers flash animation for 4s
    _markNewReady(orderId) {
      if (!orderId) return;
      this.newReadyIds = new Set([...this.newReadyIds, parseInt(orderId)]);
      this._playReadySound();
      this.newReadyFlash = true;
      if (this._flashTimer) clearTimeout(this._flashTimer);
      this._flashTimer = setTimeout(() => {
        this.newReadyFlash = false;
        // Clear the highlight after 6s so it doesn't persist forever
        setTimeout(() => {
          const ids = new Set(this.newReadyIds);
          ids.delete(parseInt(orderId));
          this.newReadyIds = ids;
        }, 2000);
      }, 4000);
    },
    // Splash-inspired: 3-tone ascending chime when order is ready
    _playReadySound() {
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
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
      } catch (_) {}
    },
    _hydrateFromRows(rows) {
      const prevPreparedIds = new Set(this.preparedItems.map(i => i.id));
      this.preparingItems = rows.filter(i => i.status === orderStatusEnum.PREPARING);
      const newPrepared = rows.filter(i => i.status === orderStatusEnum.PREPARED);

      // Detect orders that just moved to PREPARED (not in previous list).
      // [AUDIT-P1] Skip IDs already marked via Echo to prevent double chime/flash.
      const echoMarked = this._echoMarkedReady || new Set();
      newPrepared.forEach(item => {
        if (!prevPreparedIds.has(item.id) && !echoMarked.has(item.id)) {
          this._markNewReady(item.id);
        }
      });
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
.ws-reconnect-banner {
  background: #fef3c7;
  color: #92400e;
  text-align: center;
  padding: 6px 12px;
  font-size: 0.85rem;
  font-weight: 600;
}
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

/* Highlight for newly-ready orders */
.oss-new-ready {
  animation: oss-bounce 0.6s ease 2;
}
@keyframes oss-bounce {
  0%, 100% { transform: scale(1); }
  50%       { transform: scale(1.12); }
}

/* Flash the entire ready column green when a new order is ready */
.oss-ready-flash {
  animation: oss-flash 0.8s ease 2;
}
@keyframes oss-flash {
  0%, 100% { background-color: transparent; }
  50%       { background-color: rgba(26, 183, 89, 0.15); }
}
</style>
