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
                    <a v-if="isPosV4Shell" :href="'/admin/' + menu.url + (menu.query || '')" class="db-sidebar-nav-menu">
                        <i class="text-sm" :class="menu.icon"></i>
                        <span class="text-base flex-auto">{{ $t('menu.' + menu.language) }}</span>
                    </a>
                    <router-link v-else :to="'/admin/' + menu.url + (menu.query || '')" class="db-sidebar-nav-menu">
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
    // [CATALOG-HUB 2026-07-21] Single unified entry → tab wrapper (Catalogue +
    // Stock). Label kept as `stock_rupture` ("Produits & Stock"). The
    // admin.stock.rupture route stays alive for deep-links.
    // [hub-sidebar-lands-on-catalogue-tab 2026-07-22] `query` deep-links the entry
    // to the hub's existing Stock tab (its historical destination) — Catalogue keeps
    // its own `items` entry. `url` stays bare `catalog-hub` for the permission gate
    // (MENU_URL_TO_PERMISSION_URL) + dedup; only the rendered link appends the query.
    Object.freeze({ url: 'catalog-hub', query: '?tab=stock', language: 'stock_rupture', icon: 'lab lab-stock' }),
    // [PHASE 3d-UI 2026-07-24] Vue conso & stock unifiée (matières + boissons +
    // « à acheter »). URL `stock/unified` → permissionUrlForSidebarPath('stock/…')
    // renvoie 'items' (même gate lecture que le backend items_show et les écrans
    // stock frères). Écran ADDITIF lecture seule, hors NF525.
    Object.freeze({ url: 'stock/unified', language: 'stock_unified', icon: 'lab lab-stock' }),
    Object.freeze({
        url: 'items',
        language: 'items',
        icon: 'lab lab-items',
        children: Object.freeze([
            Object.freeze({ url: 'items/studio', language: 'catalog', icon: 'lab lab-list' }),
        ]),
    }),
    Object.freeze({ url: 'ingredients', language: 'ingredients', icon: 'lab lab-item-attributes' }),
    // [ARCH_STOCK_INTELLIGENT_BOM P3c] Scan facture → entrée en stock (gated items_create).
    Object.freeze({ url: 'purchasing/scan', language: 'purchasing_scan', icon: 'fa-solid fa-receipt' }),
    Object.freeze({ url: 'pos-orders', language: 'pos_orders', icon: 'lab lab-pos-orders' }),
    // [GOAL-CAISSE-UNIFIED 2026-05-30] Unified history + collection surfaces.
    Object.freeze({ url: 'historique', language: 'historique', icon: 'lab lab-pos-orders' }),
    Object.freeze({ url: 'encaissement', language: 'encaissement', icon: 'lab lab-pos-orders' }),
    Object.freeze({ url: 'cash-overview', language: 'cash_overview', icon: 'lab lab-pos-orders' }),
    Object.freeze({ url: 'delivery-boy-cash-sessions', language: 'delivery_cash_sessions', icon: 'lab lab-pos-orders' }),
]);

/** menu.url → clé `permission.url` Spatie (souvent identique ; exceptions ici). */
const MENU_URL_TO_PERMISSION_URL = Object.freeze({
    ingredients: 'ingredients_manage',
    // [ARCH_STOCK_INTELLIGENT_BOM P3c] Scan facture gated comme le scan stock.
    'purchasing/scan': 'items_create',
    // [CATALOG-HUB 2026-07-21] Hub is gated by the same `items` permission as
    // both screens it wraps.
    'catalog-hub': 'items',
    // [GOAL-CAISSE-UNIFIED 2026-05-30] Unified history + collection reuse the
    // pos-orders permission (admin + branch managers already hold it).
    historique: 'pos-orders',
    encaissement: 'pos-orders',
});

function permissionUrlForSidebarPath(menuUrl) {
    if (!menuUrl || menuUrl === '#') {
        return null;
    }
    if (MENU_URL_TO_PERMISSION_URL[menuUrl]) {
        return MENU_URL_TO_PERMISSION_URL[menuUrl];
    }
    if (menuUrl.startsWith('settings/')) {
        return 'settings';
    }
    if (menuUrl.startsWith('stock/')) {
        return 'items';
    }
    if (menuUrl.startsWith('items/')) {
        return 'items';
    }
    if (menuUrl === 'pos-orders-tracker') {
        return 'pos-orders';
    }
    return menuUrl;
}

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
         *
         * [V1-NAV-2026-05] Ne plus renvoyer uniquement V1_PRIMARY : cela masquait tout le menu
         * rôle (POS sous « Pos & Orders », KDS, réglages, etc.). On garde l’accès rapide
         * dashboard + POS en tête, la bande V1, puis le menu enrichi filtré + dédoublonné.
         */
        menusForSidebar() {
            return this.buildMergedSidebarMenus();
        },
        normalizedPermissions() {
            const p = this.$store.getters.authPermission;
            if (Array.isArray(p)) {
                return p;
            }
            if (p && Array.isArray(p.data)) {
                return p.data;
            }
            return [];
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
        userHasPermissionUrl(permissionUrl) {
            if (!permissionUrl) {
                return true;
            }
            const permissions = this.normalizedPermissions;
            if (!permissions.length) {
                return true;
            }
            const entry = permissions.find((x) => x && x.url === permissionUrl);
            if (!entry) {
                return true;
            }
            return entry.access === true;
        },
        menuPathAllowed(menuUrl) {
            return this.userHasPermissionUrl(permissionUrlForSidebarPath(menuUrl));
        },
        applyVirtualChildrenToMenu(menu) {
            if (menu.url && VIRTUAL_CHILDREN_BY_URL[menu.url]) {
                return { ...menu, children: VIRTUAL_CHILDREN_BY_URL[menu.url] };
            }
            return menu;
        },
        filterMenuChildrenByPermission(menu) {
            const m = this.applyVirtualChildrenToMenu(menu);
            if (!m.children || !m.children.length) {
                return m;
            }
            const children = m.children.filter((c) => c && c.url && this.menuPathAllowed(c.url));
            return { ...m, children };
        },
        menuBlockIsRenderable(menu) {
            if (!menu) {
                return false;
            }
            if (menu.url === '#') {
                const kids = menu.children || [];
                return kids.some((c) => c && c.url && this.menuPathAllowed(c.url));
            }
            if (!this.menuPathAllowed(menu.url)) {
                return false;
            }
            const withKids = this.filterMenuChildrenByPermission(menu);
            if (withKids.children && withKids.children.length === 0 && menu.children && menu.children.length) {
                return false;
            }
            return true;
        },
        buildMergedSidebarMenus() {
            const out = [];
            const seen = new Set();

            const pushBlock = (menu) => {
                if (!menu || !this.menuBlockIsRenderable(menu)) {
                    return;
                }
                const filtered = this.filterMenuChildrenByPermission(menu);
                if (filtered.url && filtered.url !== '#') {
                    seen.add(filtered.url);
                }
                (filtered.children || []).forEach((c) => {
                    if (c && c.url) {
                        seen.add(c.url);
                    }
                });
                out.push(filtered);
            };

            pushBlock({ url: 'dashboard', language: 'dashboard', icon: 'lab lab-dashboard' });
            pushBlock({ url: 'pos', language: 'pos', icon: 'lab lab-pos-bold' });

            V1_PRIMARY_SIDEBAR_MENUS.forEach((m) => {
                pushBlock({ ...m });
            });

            this.enrichedVisibleMenus.forEach((menu) => {
                if (menu.url === '#') {
                    const kids = (menu.children || []).filter((c) => {
                        if (!c || !c.url || this.hiddenMenuUrls.has(c.url)) {
                            return false;
                        }
                        if (seen.has(c.url) || !this.menuPathAllowed(c.url)) {
                            return false;
                        }
                        seen.add(c.url);
                        return true;
                    });
                    if (kids.length) {
                        out.push({ ...menu, children: kids });
                    }
                    return;
                }
                if (seen.has(menu.url)) {
                    return;
                }
                pushBlock({ ...menu });
            });

            return out;
        },
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
