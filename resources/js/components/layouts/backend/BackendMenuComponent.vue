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
                    <!-- `menu.external` : l'entrée pointe une page HORS de l'application Vue
                         (la roue est une page Blade autonome). Un `router-link` y chercherait une
                         route inexistante et rendrait un lien mort. On ouvre dans un nouvel onglet
                         pour ne pas faire perdre au gérant l'écran d'où il vient. -->
                    <a v-if="isPosV4Shell || menu.external"
                       :href="'/admin/' + menu.url + (menu.query || '')"
                       :target="menu.external ? '_blank' : null"
                       :rel="menu.external ? 'noopener' : null"
                       class="db-sidebar-nav-menu">
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
import { hasPermissionAccess } from "../../../shared/permission-match";

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
        // [2026-09-02] Pages de wizard : les listes de choix (avec prix) réutilisables par catégorie.
        Object.freeze({ url: 'wizard-pages',                   language: 'wizard_pages', icon: 'lab lab-document-text' }),
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
    // [GOAL_CAYENNE_FINITION_2026-08-13 / §6 Vague 5] Ajustement inventaire manuel
    // (casse / vol / pesée fausse). URL `stock/…` → permissionUrlForSidebarPath
    // renvoie 'items' (même gate lecture ; l'écriture elle-même est gated
    // items_create côté backend et l'écran bascule en lecture seule sinon).
    Object.freeze({ url: 'stock/raw-material-adjust', language: 'raw_material_adjust', icon: 'fa-solid fa-scale-balanced' }),
    Object.freeze({
        url: 'items',
        language: 'items',
        icon: 'lab lab-items',
        children: Object.freeze([
            Object.freeze({ url: 'items/studio', language: 'catalog', icon: 'lab lab-list' }),
            Object.freeze({ url: 'wizard-pages', language: 'wizard_pages', icon: 'lab lab-document-text' }),
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
    // [FLYER PROMO 2026-08-07] Ticket promo nominatif pour les commandes des
    // plateformes de livraison. Entrée déclarée EN CODE, sans seed : la
    // permission réutilisée (`pos-orders`) existe déjà sur les rôles concernés,
    // alors qu'une permission neuve ne serait portée par personne tant qu'un
    // seeder ne l'aurait pas distribuée — l'écran serait inaccessible.
    Object.freeze({
        url: 'promo-flyer',
        language: 'promo_flyer',
        icon: 'lab lab-pos-orders',
        children: Object.freeze([
            Object.freeze({ url: 'promo-flyer/settings', language: 'promo_flyer_settings', icon: 'lab lab-list' }),
        ]),
    }),
    // [UBER-PHOTO 2026-08-10] Photographier un ticket Uber depuis la tablette et l'envoyer en
    // cuisine. Même gate réutilisée (`pos-orders`) et même raison qu'au-dessus : une permission
    // neuve ne serait portée par aucun rôle, l'écran serait inaccessible à tout le monde.
    Object.freeze({ url: 'uber-photo', language: 'uber_photo', icon: 'fa-solid fa-camera' }),
    /*
     * [ROUE 2026-08-13 · propriétaire : « accès admin caisse »] Les écrans de la roue existaient
     * et fonctionnaient, mais aucun lien n'y menait depuis le back-office.
     *
     * `external: true` N'EST PAS UN DÉTAIL. Toutes les autres entrées de cette liste sont des
     * routes de l'application Vue, rendues par un `router-link`. `/admin/roue` est une page Blade
     * AUTONOME, hors du routeur : un `router-link` y chercherait une route qui n'existe pas et
     * produirait un lien MORT — une entrée de menu qui ne mène nulle part est pire que pas
     * d'entrée du tout. Le drapeau bascule le rendu sur une vraie ancre (voir le gabarit).
     *
     * Gate `pos-orders` réutilisée, exactement pour la raison écrite au-dessus pour le ticket
     * promo et la photo Uber : une permission neuve ne serait portée par aucun rôle tant qu'un
     * seeder ne l'aurait pas distribuée, et l'écran serait inaccessible à tout le monde.
     */
    Object.freeze({ url: 'roue', language: 'roue', icon: 'lab lab-pos-orders', external: true }),
]);

/** menu.url → clé `permission.url` Spatie (souvent identique ; exceptions ici). */
const MENU_URL_TO_PERMISSION_URL = Object.freeze({
    ingredients: 'ingredients_manage',
    // [ARCH_STOCK_INTELLIGENT_BOM P3c] Scan facture gated comme le scan stock.
    'purchasing/scan': 'items_create',
    // [CATALOG-HUB 2026-07-21] Hub is gated by the same `items` permission as
    // both screens it wraps.
    'catalog-hub': 'items',
    // [2026-09-02] Même porte que l'API composeur : un écran ouvert qui répondrait 403 est un piège.
    'wizard-pages': 'catalog.compose',
    // [GOAL-CAISSE-UNIFIED 2026-05-30] Unified history + collection reuse the
    // pos-orders permission (admin + branch managers already hold it).
    historique: 'pos-orders',
    encaissement: 'pos-orders',
    // [FLYER PROMO 2026-08-07] Même gate que la caisse — voir l'entrée de menu.
    'promo-flyer': 'pos-orders',
    // [ROUE 2026-08-13] Même gate que la caisse — voir l'entrée de menu.
    roue: 'pos-orders',
    'promo-flyer/settings': 'pos-orders',
    // [UBER-PHOTO 2026-08-10] Même gate que la caisse — voir l'entrée de menu.
    'uber-photo': 'pos-orders',
    // [P1 LIENS MORTS 2026-08-08] Ces deux entrées n'étaient PAS mappées : faute de
    // correspondance, la barre latérale retombait sur le nom de l'url lui-même, pour lequel il
    // n'existe aucune permission — donc défaut permissif, donc lien AFFICHÉ. Mais la route, elle,
    // exige un droit que le caissier n'a pas : clic -> toast « permission requise » -> retour au
    // tableau de bord. Deux liens morts dans la navigation QUOTIDIENNE des 9 comptes caisse.
    // On mappe chacun sur le droit que sa route exige réellement, pour que le menu dise la vérité :
    // ce qui est affiché est atteignable.
    'cash-overview': 'cash-sessions-report',
    'delivery-boy-cash-sessions': 'delivery-boys',
    // Cockpit global : API Admin-only. Sans mapping, fail-open sidebar.
    'observability/system': 'settings',
    'observability/outbox': 'settings',
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
    if (menuUrl.startsWith('observability/')) {
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
        // [GOAL-OPS-SWAP W1 2026-08-12] Même résolveur que la garde de route
        // (shared/permission-match.js). Avant : cette barre latérale proposait
        // « Ingrédients » à l'opérateur caisse et au chef, qui recevaient un 403
        // sur /api/admin/ingredients — parce que la permission `ingredients_manage`
        // a `url = NULL` en base et que la recherche ne portait que sur `url`.
        userHasPermissionUrl(permissionUrl) {
            return hasPermissionAccess(this.normalizedPermissions, permissionUrl);
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
