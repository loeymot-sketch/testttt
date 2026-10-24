<template>
  <div :dir="direction">
    <div v-if="theme === 'frontend' && !isWallRoute">
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

    <div v-if="theme === 'backend' && !isKioskRoute && !isWallRoute">
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
        <!-- [WEB-PAYEE-MUETTE 2026-08-10] Imprimeur des tickets CUISINE. Monté au même
             endroit et pour la même raison que celui du dessus : le serveur ne peut pas
             joindre l'imprimante, donc c'est le poste qui vient chercher. Sans lui, une
             commande du site payée en ligne n'a JAMAIS produit de papier — constaté en
             production le 2026-08-10 sur une commande de 31,40 €. -->
        <KitchenTicketPrintListener />
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

    <!-- [AUDIT-SUPERVISEUR 2026-08-25 - E-005] MUR DE STATUT TOURNE VERS LA SALLE.
         Aucun habillage : ni navbar admin, ni menu utilisateur, ni navbar vitrine. La page
         compose son propre plein ecran.

         Pourquoi une branche a part plutot que `tracking` : le suivi client est PUBLIC et
         saute volontairement l'appel `authcheck`. Ce mur-ci, lui, reste une surface du
         personnel (`auth: true`, `permissionUrl`) qu'un membre du staff installe sur une
         tele - on garde donc son cycle d'authentification, on ne retire QUE l'habillage.
         Il ne rebondit pas vers /login non plus : `app.js` classe deja ce chemin dans
         `publicFriendlyPaths`, et la redirection de session expiree ne vise que les themes
         « frontend » et « backend ». Un mur qui affiche un formulaire de connexion devant
         des clients est exactement ce qu'on refuse.

         `isWallRoute` lit `window.location`, PAS `$route` : au chargement a froid - le seul
         chemin reel pour une tele de salle, qu'on allume sur une adresse directe - la route
         n'est pas encore resolue et `theme` vaut sa valeur par defaut « frontend ». Sans
         cette lecture synchrone, le mur montrerait brievement la navbar de la vitrine. -->
    <div v-if="isWallRoute || theme === 'wall'">
      <router-view></router-view>
    </div>

    <!-- [T-C SUIVI-CLIENT 2026-08-16] Page publique de suivi (téléphone client, lien/QR
         borne) : AUCUN habillage (ni sidebar admin, ni navbar vitrine/table, ni kiosk-shell
         plein-écran verrouillé) — la page compose son propre layout complet. -->
    <div v-if="theme === 'tracking'">
      <router-view></router-view>
    </div>
  </div>
</template>

<script>
import BackendNavbarComponent from "./layouts/backend/BackendNavbarComponent";
import PromoFlyerPrintListener from "./admin/promo/PromoFlyerPrintListener";
// Volontairement HORS de admin/kitchenDisplaySystem/ : ce composant n'est PAS chargé par le
// bundle KDS (il vit dans la coquille admin), et l'y ranger rendait la sentinelle de fraîcheur
// du bundle KDS rouge à vie — elle surveille ce dossier entier.
import KitchenTicketPrintListener from "./admin/kitchen/KitchenTicketPrintListener";
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
    KitchenTicketPrintListener,
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
    // [AUDIT-SUPERVISEUR 2026-08-25 - E-005] Vrai des le premier rendu, AVANT que le router
    // n'ait resolu quoi que ce soit : on lit l'adresse du navigateur, pas `$route`. Les deux
    // chemins de la route sont couverts (le principal et son alias).
    isWallRoute: function () {
      if (this.$route?.meta?.isWall === true) {
        return true;
      }
      const chemin = String(
        (typeof window !== "undefined" && window.location && window.location.pathname) || ""
      );
      return chemin === "/admin/order-status-screen" || chemin === "/order-status-screen";
    },
  },
  created() {
    // [test-e2e fix C-007 round-3 2026-08-16] NE PLUS déterminer le theme ici,
    // de façon synchrone — voir beforeMount() ci-dessous pour le pourquoi
    // complet. this.theme reste à sa valeur sûre par défaut (data(): "frontend",
    // JAMAIS "backend") tant que le router n'a pas résolu la vraie route.
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


    // [test-e2e fix C-007 round-1/2/3 2026-08-16] La page publique de suivi
    // (theme "tracking", route.meta.isTracking === true — LE MÊME flag que la
    // branche `theme === 'tracking'` du template et de applyThemeFromRoute()
    // ci-dessous) ne doit déclencher AUCUN bootstrap admin-authentifié : un
    // membre du staff avec un cookie de session admin actif qui ouvre ce lien
    // public (téléphone client, QR borne) ne doit pas faire atterrir des
    // données privilégiées (authcheck, ou même le simple MONTAGE de
    // BackendNavbarComponent/BackendMenuComponent) dans le store Vuex / la
    // mémoire JS de cette page, même si rien ne les affiche.
    //
    // [round-2] Le premier fix vérifiait this.$route.meta directement — faux
    // pour la résolution ASYNCHRONE du ROUTER : app.js appelle app.mount()
    // sans attendre router.isReady(), donc $route.meta n'était pas encore
    // résolu à l'instant précis de ce hook sur un chargement à froid (le SEUL
    // chemin réel : QR borne, lien direct — jamais une navigation interne).
    //
    // [round-3] Le fix round-2 ne gardait QUE le dispatch authcheck derrière
    // isReady() — mais created() appelait TOUJOURS applyThemeFromRoute()
    // SYNCHRONEMENT, AVANT toute résolution. Avec $route.meta non résolu,
    // AUCUNE branche isKiosk/isFrontend/isTable/isTracking ne matchait, donc
    // applyThemeFromRoute() tombait dans son `else` final : theme="backend" —
    // montant BRIÈVEMENT BackendNavbarComponent, dont le created() (fichier
    // séparé) dispatche LUI-MÊME, indépendamment de ce gate,
    // admin/default-access + admin/setting/branch, avant que watch:$route ne
    // corrige theme→"tracking" une fois la vraie route résolue. Prouvé par
    // test-e2e round-2 (Wave C, adversarial) : ces 2 appels visibles dans
    // CHAQUE console.json admin-cookie malgré le fix round-2 (network.json ne
    // les capture pas — mega-audit-snap.js ne journalise QUE les requêtes en
    // échec/lentes/mutation, un GET 200 rapide y est invisible par
    // construction ; console.json capte les violations CSP report-only pour
    // ces mêmes appels, qui elles ont trahi le montage).
    //
    // Fix définitif : la détermination du theme ET le dispatch authcheck
    // attendent TOUS DEUX router.isReady() — theme reste à "frontend" (sûr,
    // aucune donnée privilégiée) jusqu'à résolution réelle de la route.
    this.$router.isReady().then(() => {
      this.applyThemeFromRoute(this.$route);
      if (this.$route?.meta?.isTracking !== true && this.$store.getters.authStatus) {
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
    }).catch();

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
      } else if (route?.meta?.isWall === true) {
        this.theme = "wall";
      } else if (route?.meta?.isTracking === true) {
        this.theme = "tracking";
      } else {
        this.theme = "backend";
      }
    },
  },
};
</script>
