<template>
  <!--
    [iter15-mega-fix B-003/D-002 2026-05-10] suppress-transient: the global
    "Reconnexion en cours…" banner is suppressed on the OSS surface because
    the customer-facing screen already conveys the connection state via the
    fallback polling — no need to show a permanent yellow bar in addition.
    session_invalid (terminal) still surfaces via this component.
  -->
  <ConnectionStatusBanner suppress-transient />
  <!--
    [Wave Q-3 P-OWNER 2026-05-20] PopularItemComponent ("Articles à préparer"
    sidebar) removed from OSS layout per owner directive — the customer
    status screen must show ONLY two columns: EN PRÉPARATION (orange/red)
    and PRÊT (green). The PopularItemComponent.vue file is kept (still
    referenced by OssSyncService + admin PosComponent dashboard widget),
    but it is no longer mounted on the OSS surface.
    Grid collapsed from md:grid-cols-4 (popular=2 + columns=2) to
    md:grid-cols-2 (preparing=1 + ready=1) — PreparingAndReadyComponent
    is a multi-root component whose two siblings carry col-span-1 each,
    so they map cleanly to the 2-col grid without an inner wrapper.
  -->
  <div
    class="grid grid-cols-2 md:grid-cols-2 md:grid-flow-row gap-4"
    role="main"
    :aria-label="$t('label.oss_main_aria')"
  >
    <PreparingAndReadyComponent />
  </div>
</template>
<script>
import PreparingAndReadyComponent from "./PreparingAndReadyComponent";
import ConnectionStatusBanner from "../../common/ConnectionStatusBanner.vue";


export default {
  name: "OrderStatusScreenComponent",
  components: {
    ConnectionStatusBanner,
    PreparingAndReadyComponent
  },
  data() {
    return {
    };
  },
  mounted() {
    this.closeSidebar();
  },
  methods: {
    openSidebar: function () {
      document?.querySelector(".db-main")?.classList?.remove("expand");
      const activeMenu = document.querySelector('.db-sidebar-nav-item.active');
      if (activeMenu) {
        activeMenu.classList.remove('active');
      }

      document?.querySelector('.router-link-exact-active')?.parentElement?.classList?.add('active');
    },
    closeSidebar: function () {
      document?.querySelector(".db-main")?.classList?.add("expand");
      // [W8 FIX] Full optional chain — querySelector can return null if .db-header is absent
      document?.querySelector('.db-header')?.classList?.remove("active", "hidden");
    },
  },
  beforeUnmount() {
    this.openSidebar();

  },
};
</script>