<template>
  <div :dir="direction">
    <div v-if="theme === 'frontend'">
      <FrontendNavbarComponent />
      <FrontendCartComponent v-if="!staffOnlyMode" />
      <router-view></router-view>
      <FrontendMobileNavBarComponent v-if="!staffOnlyMode" />
      <FrontendMobileAccountComponent v-if="!staffOnlyMode" />
      <FrontendCookiesComponent v-if="!staffOnlyMode" />
      <FrontendFooterComponent v-if="!staffOnlyMode" />
    </div>

    <div v-if="isKioskRoute || theme === 'kiosk'" class="kiosk-locked-shell">
      <router-view></router-view>
    </div>

    <div v-if="theme === 'backend' && !isKioskRoute">
      <main class="db-main" v-if="logged">
        <BackendNavbarComponent />
        <BackendMenuComponent />
        <router-view></router-view>
        <!-- [FLYER PROMO 2026-08-07] Imprimeur des tickets promotionnels.
             Monté ici, dans la coquille admin, et non sur l'écran de caisse :
             le serveur est dans le cloud et ne peut pas joindre l'imprimante du
             restaurant, il faut donc que quelque chose tourne sur le PC caisse
             quel que soit l'écran affiché. Le composant ne rend rien et reste
             inerte partout où le pont d'impression local est absent (téléphone,
             poste bureau). -->
        <PromoFlyerPrintListener />
      </main>

      <div v-if="!logged">
        <router-view></router-view>
      </div>
    </div>

    <div v-if="theme === 'table'">
      <TableNavbarComponent />
      <TableCartComponent />
      <router-view></router-view>
      <TableFooterComponent />
    </div>
  </div>
</template>

<script>
import BackendNavbarComponent from "./layouts/backend/BackendNavbarComponent";
import PromoFlyerPrintListener from "./admin/promo/PromoFlyerPrintListener";
import BackendMenuComponent from "./layouts/backend/BackendMenuComponent";
import FrontendNavbarComponent from "./layouts/frontend/FrontendNavBarComponent";
import FrontendFooterComponent from "./layouts/frontend/FrontendFooterComponent";
import FrontendMobileNavBarComponent from "./layouts/frontend/FrontendMobileNavBarComponent";
import FrontendMobileAccountComponent from "./layouts/frontend/FrontendMobileAccountComponent";
import FrontendCartComponent from "./layouts/frontend/FrontendCartComponent";
import FrontendCookiesComponent from "./layouts/frontend/FrontendCookiesComponent";
import TableNavbarComponent from "./layouts/table/TableNavBarComponent.vue";
import TableFooterComponent from "./layouts/table/TableFooterComponent.vue";
import TableCartComponent from "./layouts/table/TableCartComponent.vue";
import displayModeEnum from "../enums/modules/displayModeEnum";
import env from "../config/env";
import appService from "../services/appService";
import { routes } from "../router";

export default {
  name: "DefaultComponent",
  components: {
    PromoFlyerPrintListener,
    TableCartComponent,
    TableFooterComponent,
    TableNavbarComponent,
    FrontendCartComponent,
    FrontendMobileAccountComponent,
    FrontendMobileNavBarComponent,
    FrontendCookiesComponent,
    FrontendFooterComponent,
    FrontendNavbarComponent,
    BackendNavbarComponent,
    BackendMenuComponent,
  },
  data() {
    return {
      theme: "frontend",
    };
  },
  computed: {
    direction: function () {
      return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
    },
    logged: function () {
      return this.$store.getters.authStatus;
    },
    // [STAFF-ONLY-V1] Masque tout l'habillage vitrine client (cart, footer, cookies, mobile nav).
    staffOnlyMode: function () {
      return !!(window.foodkingConfig && window.foodkingConfig.staffOnlyMode);
    },
    isKioskRoute: function () {
      const routePath = String(this.$route?.path || "");
      return this.$route?.meta?.isKiosk === true || routePath.startsWith("/kiosk");
    },
  },
  created() {
    this.applyThemeFromRoute(this.$route);
  },
  beforeMount() {
    this.$store
      .dispatch("frontendSetting/lists")
      .then((res) => {
        this.$store.dispatch("globalState/init", {
          branch_id: res.data.data.site_default_branch,
          language_id: res.data.data.site_default_language,
        });
      })
      .catch();


    if (this.$store.getters.authStatus) {
      this.$store.dispatch("authcheck").then(res => {
        if (res.data.status === false && (this.theme == "frontend" || this.theme == "backend")) {
          // [STAFF-ONLY-V1] Session expirée : retour au login staff si staffOnlyMode, sinon home vitrine.
          this.$router.push({ name: this.staffOnlyMode ? "auth.login" : "frontend.home" });
        } else if (res.data.status !== false && res.data.permission) {
          // F5 / onglet : réaligner les routes sur les permissions renvoyées par authcheck.
          appService.recursiveRouter(routes, res.data.permission);
        }
      }).catch();
    }

  },
  watch: {
    $route(e) {
      this.applyThemeFromRoute(e);
    },
  },
  methods: {
    applyThemeFromRoute(route) {
      const routePath = String(route?.path || "");
      if (route?.meta?.isKiosk === true || routePath.startsWith("/kiosk")) {
        this.theme = "kiosk";
      } else if (route?.meta?.isFrontend === true) {
        this.theme = "frontend";
      } else if (route?.meta?.isTable === true) {
        this.theme = "table";
      } else {
        this.theme = "backend";
      }
    },
  },
};
</script>
