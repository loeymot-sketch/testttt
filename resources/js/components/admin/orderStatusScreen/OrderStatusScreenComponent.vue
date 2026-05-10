<template>
  <!--
    [iter15-mega-fix B-003/D-002 2026-05-10] suppress-transient: the global
    "Reconnexion en cours…" banner is suppressed on the OSS surface because
    the customer-facing screen already conveys the connection state via the
    fallback polling — no need to show a permanent yellow bar in addition.
    session_invalid (terminal) still surfaces via this component.
  -->
  <ConnectionStatusBanner suppress-transient />
  <div
    class="grid grid-cols-2 md:grid-cols-4 md:grid-flow-row gap-4 "
    role="main"
    :aria-label="$t('label.oss_main_aria')"
  >
    <PopularItemComponent />
    <div class="col-span-2 grid grid-cols-2 gap-4 md:mt-0 mt-[-20px]">
      <PreparingAndReadyComponent />
    </div>
  </div>
</template>
<script>
import PopularItemComponent from "./PopularItemComponent";
import PreparingAndReadyComponent from "./PreparingAndReadyComponent";
import ConnectionStatusBanner from "../../common/ConnectionStatusBanner.vue";


export default {
  name: "OrderStatusScreenComponent",
  components: {
    ConnectionStatusBanner,
    PopularItemComponent,
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