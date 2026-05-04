<template>
    <aside class="db-sidebar"
        :class="$route.path.includes('kitchen-display-system') || $route.path.includes('order-status-screen') ? 'hidden' : ''">
        <div class="db-sidebar-header">
            <a v-if="isPosV4Shell" class="w-24" href="/admin/pos-v4">
                <img :src="setting.theme_logo" alt="logo">
            </a>
            <router-link v-else class="w-24" :to="{ name: 'frontend.home' }">
                <img :src="setting.theme_logo" alt="logo">
            </router-link>
            <button @click.prevent="handleSidebar" class="fa-solid fa-xmark xmark-btn close-db-menu"></button>
        </div>
        <!--        {{ menus }}-->
        <nav class="db-sidebar-nav">
            <ul class="db-sidebar-nav-list" v-if="menusForSidebar.length > 0" v-for="menu in menusForSidebar" :key="menu.id || menu.url">
                <li class="db-sidebar-nav-item" v-if="menu.url === '#'" @click.prevent="sidebarActive($event)">
                    <button type="button" :aria-label="$t('menu.' + menu.language)" class="db-sidebar-nav-title">
                        {{ $t('menu.' + menu.language) }}
                    </button>
                </li>

                <li class="db-sidebar-nav-item" v-else-if="menu.url !== '#' && showSidebarParentNavRow(menu)" @click.prevent="sidebarActive($event)">
                    <a v-if="isPosV4Shell" :href="'/admin/' + menu.url" class="db-sidebar-nav-menu">
                        <i class="text-sm" :class="menu.icon"></i>
                        <span class="text-base flex-auto">{{ $t('menu.' + menu.language) }}</span>
                    </a>
                    <router-link v-else :to="'/admin/' + menu.url" class="db-sidebar-nav-menu">
                        <i class="text-sm" :class="menu.icon"></i>
                        <span class="text-base flex-auto">{{ $t('menu.' + menu.language) }}</span>
                    </router-link>
                </li>

                <li class="db-sidebar-nav-item" v-if="menu.children" v-for="children in menu.children"
                    @click.prevent="sidebarActive($event)">
                    <a v-if="isPosV4Shell" :href="'/admin/' + children.url" class="db-sidebar-nav-menu">
                        <i class="text-sm" :class="children.icon"></i>
                        <span class="text-base flex-auto">{{ $t('menu.' + children.language) }}</span>
                    </a>
                    <router-link v-else :to="'/admin/' + children.url" class="db-sidebar-nav-menu">
                        <i class="text-sm" :class="children.icon"></i>
                        <span class="text-base flex-auto">{{ $t('menu.' + children.language) }}</span>
                    </router-link>
                </li>
            </ul>
        </nav>
    </aside>
</template>

<script>
import { V1_HIDDEN_MENU_MODULES, V1_HIDDEN_BACKEND_MENU_URLS } from "../../../config/v1-hidden-modules";

/**
 * Mapping local : clés de V1_HIDDEN_MENU_MODULES → URL `menu.url` côté seeder.
 * Le seeder utilise du kebab-case (`credit-balance-report`) alors que la
 * constante partagée garde un identifiant logique (`creditBalanceReport`).
 * Les clés `settings.*` sont gérées par admin/settings/MenuComponent.vue,
 * pas ici.
 */
const HIDDEN_KEY_TO_MENU_URL = Object.freeze({
    customers: 'customers',
    coupons: 'coupons',
    offers: 'offers',
    creditBalanceReport: 'credit-balance-report',
    deliveryBoys: 'delivery-boys',
    onlineOrders: 'online-orders',
    tableOrders: 'table-orders',
    waiters: 'waiters',
    diningTables: 'dining-tables',
});

/**
 * [CV1-WC-T-WC-MENU-CATALOG-01] Sous-section "Catalogue" côté Vue.
 *
 * Le seeder `MenuTableSeeder` enregistre `Items` comme entrée de menu plate
 * (`url='items'`, sans `children`). Catégories et Attributs sont enfouis sous
 * `Réglages` (audit A.3 #1+#2). On reconstitue ici un regroupement Catalogue
 * **sans toucher la table `menus` en DB** : dès qu'un parent a une entrée dans
 * `VIRTUAL_CHILDREN_BY_URL`, on **impose** ces sous-items (Studio + attributs)
 * et on ignore les `children` legacy issus de la table `menus` (SSOT côté code).
 *
 * Les `language` mappent les clés `menu.<key>` du JSON i18n (cf. `i18n.js` →
 * `resources/js/languages/{fr,en,ar,bn,de}.json`). Les `icon` reprennent la
 * convention seeder `lab lab-<icon>`.
 */
const VIRTUAL_CHILDREN_BY_URL = Object.freeze({
    items: Object.freeze([
        Object.freeze({ url: 'items/studio',                   language: 'catalog',     icon: 'lab lab-list' }),
        Object.freeze({ url: 'settings/item-attributes/list',  language: 'item_attributes',  icon: 'lab lab-item-attributes' }),
    ]),
});

const V1_PRIMARY_SIDEBAR_MENUS = Object.freeze([
    Object.freeze({ url: 'stock/rupture', language: 'stock_rupture', icon: 'lab lab-stock' }),
    Object.freeze({
        url: 'items',
        language: 'items',
        icon: 'lab lab-items',
        children: Object.freeze([
            Object.freeze({ url: 'items/studio', language: 'catalog', icon: 'lab lab-list' }),
        ]),
    }),
    Object.freeze({ url: 'ingredients', language: 'ingredients', icon: 'lab lab-item-attributes' }),
    Object.freeze({ url: 'pos-orders', language: 'pos_orders', icon: 'lab lab-pos-orders' }),
]);

export default {
    name: "BackendMenuComponent",
    data: function () {
        return {
            activeParentId: 1,
            activeChildId: 0,
            sidebarOpen: false,
        }
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        menus: function () {
            return this.$store.getters.authMenu;
        },
        hiddenBackendMenuUrls() {
            return V1_HIDDEN_BACKEND_MENU_URLS;
        },
        hiddenLegacyBackendMenuUrlSet() {
            return new Set(V1_HIDDEN_BACKEND_MENU_URLS);
        },
        hiddenMenuUrls() {
            return new Set(
                V1_HIDDEN_MENU_MODULES
                    .map(key => HIDDEN_KEY_TO_MENU_URL[key])
                    .filter(Boolean),
            );
        },
        visibleMenus() {
            const hidden = this.hiddenMenuUrls;
            return (this.menus || [])
                .map(menu => {
                    if (!menu.children) return menu;
                    return {
                        ...menu,
                        children: menu.children.filter(child => !hidden.has(child.url)),
                    };
                })
                .filter(menu => {
                    if (hidden.has(menu.url)) return false;
                    if (menu.url === '#' && Array.isArray(menu.children) && menu.children.length === 0) {
                        return false;
                    }
                    return true;
                });
        },
        /**
         * [CV1-WC-T-WC-MENU-CATALOG-01] Enrichit les menus visibles avec les
         * sous-items virtuels (Catalogue) sans toucher la DB. Si l'URL a des
         * virtual children définis, ils remplacent toujours les children BDD legacy.
         */
        enrichedVisibleMenus() {
            return this.visibleMenus.map((menu) => {
                if (menu.url && VIRTUAL_CHILDREN_BY_URL[menu.url]) {
                    return { ...menu, children: VIRTUAL_CHILDREN_BY_URL[menu.url] };
                }
                return menu;
            });
        },
        /**
         * Retire tout le bloc menu si URL legacy masquée sans enfants (sinon lien mort).
         * Les entrées comme `items` avec enfants virtuels restent avec le parent row caché.
         */
        menusForSidebar() {
            return V1_PRIMARY_SIDEBAR_MENUS;
        },
        sidebar() {
            return this.$store.getters['globalState/lists'].topSidebar;
        },
        isPosV4Shell() {
            return typeof window !== 'undefined' && window.location.pathname.startsWith('/admin/pos-v4');
        },
    },
    mounted() {
        this.defaultSidebarActive();

    },
    methods: {
        sidebarActive: function (e) {
            const activeMenu = document.querySelector('.db-sidebar-nav-item.active');
            if (activeMenu) {
                activeMenu.classList.remove('active');
            }
            e?.currentTarget?.classList?.add('active');
        },
        defaultSidebarActive: function () {
            if (document?.querySelector(".db-sidebar-nav-menu")?.classList?.contains("active")) {
                document?.querySelector('.db-sidebar-nav-menu')?.parentElement?.classList?.add('active');
            } else {
                document?.querySelector('.router-link-exact-active')?.parentElement?.classList?.add('active');
            }
        },
        showSidebarParentNavRow(menu) {
            if (!menu || menu.url === '#') return false;
            return !this.hiddenLegacyBackendMenuUrlSet.has(menu.url);
        },
        handleSidebar: function () {
            this.sidebarOpen = !this.sidebar;
            this.$store.dispatch("globalState/set", { topSidebar: this.sidebarOpen });

            if (document?.querySelector(".db-sidebar")?.classList?.contains("active")) {
                document?.querySelector(".db-main")?.classList?.remove("expand");
                document?.querySelector(".db-sidebar")?.classList?.remove("active");
            } else {
                document?.querySelector(".db-sidebar")?.classList?.add("active");
                document?.querySelector(".db-main")?.classList?.add("expand");
            }
        },
    }
}
</script>
