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
            <ul class="db-sidebar-nav-list" v-if="visibleMenus.length > 0" v-for="menu in visibleMenus" :key="menu">
                <li class="db-sidebar-nav-item" v-if="menu.url === '#'" @click.prevent="sidebarActive($event)">
                    <button type="button" :aria-label="$t('menu.' + menu.language)" class="db-sidebar-nav-title">
                        {{ $t('menu.' + menu.language) }}
                    </button>
                </li>

                <li class="db-sidebar-nav-item" v-else @click.prevent="sidebarActive($event)">
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
import { V1_HIDDEN_MENU_MODULES } from "../../../config/v1-hidden-modules";

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
});

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
