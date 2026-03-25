<template>
  <LoadingContentComponent :props="loading" />
  <div class="col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden">
    <h3 class="text-lg font-semibold text-white p-3 pb-2 bg-primary mb-2 rounded-t-[10px] text-center">{{
      $t("label.preparing") }}
    </h3>
    <div class="content-wrapper p-3 overflow-auto thin-scrolling h-full">
      <ul
        class="[&_li]:mb-6 [&_li]:text-[40px] [&_li]:font-semibold [&_li]:leading-10 w-full text-center text-[#1F1F39] mb-20">
        <li v-for="preparingItem in preparingItems" :key="preparingItem.id"
          :class="preparingItem.queue_number ? 'text-[#e53935]' : ''">
          {{ preparingItem.queue_number ? 'N°' + preparingItem.queue_number : preparingItem.token }}
        </li>
      </ul>
    </div>
  </div>
  <div class="col-span-1 customer-screen db-card rounded-[10px] h-screen md:h-[calc(100dvh-117px)] overflow-hidden">
    <h3 class="text-lg font-semibold text-white p-3 pb-2 bg-[#1AB759] mb-2 rounded-t-[10px] text-center">{{
      $t("label.ready") }}</h3>
    <div class="content-wrapper p-3 overflow-auto thin-scrolling h-full">
      <ul
        class="[&_li]:mb-6 [&_li]:text-[40px] [&_li]:font-semibold [&_li]:leading-10 w-full text-center text-[#1F1F39] mb-20">
        <li v-for="preparedItem in preparedItems" :key="preparedItem.id"
          :class="preparedItem.queue_number ? 'text-[#2AC769] font-extrabold animate-pulse' : ''">
          {{ preparedItem.queue_number ? 'N°' + preparedItem.queue_number : preparedItem.token }}
        </li>
      </ul>
    </div>
  </div>
</template>
<script>
import LoadingContentComponent from "../components/LoadingContentComponent";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";


export default {
  name: "PreparingAndReadyComponent",
  components: {
    LoadingContentComponent,
  },
  data() {
    return {
      loading: {
        isActive: false,
      },
      preparedItems: [],
      preparingItems: [],
      enums: {
        orderStatusEnum: orderStatusEnum,
      },
      autoRefreshInterval: null,
    };
  },
  computed: {
    orders: function () {
      return this.$store.getters["orderStatusScreenOrder/lists"];
    },
    items: function () {
      return this.$store.getters["orderStatusScreenOrder/mostPopularItems"];
    },
  },
  mounted() {
    this.list();
    this.startAutoRefresh();
    window.addEventListener('realtime-order-update', this.list);
    // [P4-1] Subscribe to branch Echo channel for real-time OSS updates
    this.subscribeEcho();
  },
  methods: {
    startAutoRefresh() {
      if (this.$route.path.includes('order-status-screen')) {
        this.autoRefreshInterval = setInterval(() => {
          this.list();
        }, 30000); // 30s fallback polling — Echo push is primary
      }
    },
    stopAutoRefresh() {
      if (this.autoRefreshInterval) {
        clearInterval(this.autoRefreshInterval);
        this.autoRefreshInterval = null;
      }
    },
    // [P4-1] Subscribe to branch Echo channel for real-time order updates
    // Admin users (branch_id=0) rely on 30s polling; branch staff get sub-second push
    subscribeEcho() {
      if (!window.Echo) return;
      const branchId = parseInt(this.$store.getters['auth/authBranchId'] || 0);
      if (branchId <= 0) return; // Admin: polling fallback is sufficient
      try {
        this._echoChannel = window.Echo.private(`branch.${branchId}`)
          .listen('.OrderStatusChanged', () => { this.list(); })
          .listen('.OrderCreated', () => { this.list(); });
      } catch (e) {
        // Echo not available or auth failed — polling fallback handles it
      }
    },
    unsubscribeEcho() {
      if (!window.Echo || !this._echoChannel) return;
      const branchId = parseInt(this.$store.getters['auth/authBranchId'] || 0);
      if (branchId <= 0) return;
      try { window.Echo.leave(`branch.${branchId}`); } catch (e) {}
      this._echoChannel = null;
    },
    list: function () {
      this.loading.isActive = true;
      this.$store
        .dispatch("orderStatusScreenOrder/lists")
        .then((res) => {
          this.preparingItems = res.data.data.filter(
            (item) => item.status === orderStatusEnum.PREPARING
          );
          this.preparedItems = res.data.data.filter(
            (item) => item.status === orderStatusEnum.PREPARED
          );

          this.loading.isActive = false;
        })
        .catch((err) => {
          this.loading.isActive = false;
        });
    },
  },
  beforeUnmount() {
    this.stopAutoRefresh();
    window.removeEventListener('realtime-order-update', this.list);
    this.unsubscribeEcho();
  },
};
</script>