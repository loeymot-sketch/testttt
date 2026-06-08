<template>
    <div class="backdrop"></div>
    <header class="db-header">
        <a v-if="isPosV4Shell" class="w-32 flex-shrink-0" href="/admin/pos-v4" @click="closeFullScreen">
            <img class="w-full" :src="setting.theme_logo" alt="logo">
        </a>
        <!-- [FP-ARMY-P3] Admin chrome: the brand logo linked to frontend.home, but the router guard
             bounces staff straight back to the admin landing (index.js:272 isStaffOnly) → the click
             was a dead no-op. Point it at the admin dashboard (the staff "home") so the universal
             click-logo-to-go-home affordance actually works. -->
        <router-link v-else class="w-32 flex-shrink-0" :to="{ name: 'admin.dashboard' }" @click="closeFullScreen">
            <img class="w-full" :src="setting.theme_logo" alt="logo">
        </router-link>
        <div class="flex items-center justify-end w-full gap-4">
            <div class="sub-header flex items-center gap-4 transition justify-between xh:top-10 xh:fixed xh:left-0 xh:w-full xh:p-4 xh:border-y xh:border-[#EFF0F6] xh:bg-white">
                <button v-if="$route.path.includes('order-status-screen')" type="button" @click="fullScreen"
                    aria-label="Plein écran"
                    class="hidden db-header-toggle lg:flex items-center justify-center w-9 h-9 px-3 rounded-lg bg-[#E0FFED]">
                    <i class="lab lab-maximize lab-font-size-24 text-[#1AB759]" aria-hidden="true"></i>
                </button>

                <div v-if="authBranch === 0" class="relative dropdown-group">
                    <button class="flex items-center text-left gap-2 dropdown-btn">
                        <i class="lab lab-shop lab-font-size-24 font-fill-primary"></i>
                        <h3 class="capitalize text-xs font-medium text-heading">
                            <span class="block font-normal mb-0.5">{{ $t('label.branch') }}</span>
                            <b class="font-semibold whitespace-nowrap">{{ branch.name }}</b>
                        </h3>
                        <i class="lab lab-arrow-down text-xs ml-1.5 lab-font-size-14"></i>
                    </button>
                    <ul v-if="branches.length > 0"
                        class="p-2 w-fit rounded-lg shadow-xl absolute top-14 left-0 z-10 border border-gray-200 bg-white dropdown-list transition-all duration-300 scale-y-0 origin-top">
                        <li v-for="branch in branches"
                            class="flex items-center gap-2 w-full px-2.5 rounded-md transition hover:bg-gray-100">
                            <input @click="changeBranch(branch.id)" v-model="defaultBranch" type="radio"
                                :id="'branch_id_' + branch.id" :value="branch.id" name="branch"
                                class="w-3 cursor-pointer mb-[1px] accent-primary">
                            <label :for="'branch_id_' + branch.id"
                                class="capitalize leading-8 text-sm min-w-[150px] cursor-pointer text-heading">
                                {{ branch.name }}
                            </label>
                        </li>
                    </ul>
                </div>
                <div class="flex items-center gap-4">
                    <div class="relative dropdown-group"
                        v-if="$route.path.includes('kitchen-display-system') || $route.path.includes('order-status-screen')">
                        <router-link :to="{ path: '/admin/' + kdsHeaderMenu.url }" @click="closeFullScreen"
                            class="flex items-center gap-2 h-9 px-3 rounded-lg bg-[#FFE8DD]">
                            <i class="lab-font-size-17 text-primary" :class="kdsHeaderMenu.icon"></i>
                            <span
                                class=" md:block hidden whitespace-nowrap text-xs font-medium capitalize text-[#111827]">{{
                                    kdsHeaderMenu.label }}</span>
                        </router-link>
                    </div>
                    <div class="flex items-center justify-between md:justify-center gap-4">
                        <div v-if="setting.site_language_switch === enums.activityEnum.ENABLE"
                            class="dropdown-group relative">
                            <button class="dropdown-btn flex items-center gap-2 h-9 px-3 rounded-lg bg-[#FFE8DD]">
                                <img :src="language.image" alt="flag" class="w-4 h-4 rounded-full">
                                <span
                                    class="hidden md:block whitespace-nowrap text-xs font-medium capitalize text-heading">
                                    {{ language.name }}
                                </span>
                            </button>
                            <ul v-if="languages.length > 0"
                                class="p-2 min-w-[180px] rounded-lg shadow-xl absolute top-14 ltr:right-0 rtl:left-0 z-10 border border-gray-200 bg-white transition-all duration-300 origin-top scale-y-0 dropdown-list">
                                <li @click="changeLanguage(language.id, language.code)" v-for="language in languages"
                                    class="flex items-center gap-2 py-1.5 px-2.5 rounded-md cursor-pointer hover:bg-gray-100">
                                    <img :src="language.image" alt="flag" class="w-4 h-4 rounded-full">
                                    <span class="text-heading capitalize text-sm">{{ language.name }}</span>
                                </li>
                            </ul>
                        </div>

                        <a
                            v-if="isPosV4Shell && pos.permission && !$route.path.includes('kitchen-display-system') && !$route.path.includes('order-status-screen')"
                            class="w-9 h-9 rounded-lg flex items-center justify-center bg-[#FFEBD8]"
                            :aria-label="$t('menu.pos')"
                            :href="'/admin/' + pos.url">
                            <i class="lab lab-pos-bold lab-font-size-16 font-fill-pos" aria-hidden="true"></i>
                        </a>
                        <router-link
                            v-else-if="pos.permission && !$route.path.includes('kitchen-display-system') && !$route.path.includes('order-status-screen')"
                            class="w-9 h-9 rounded-lg flex items-center justify-center bg-[#FFEBD8]"
                            :aria-label="$t('menu.pos')"
                            :to="{ path: '/admin/' + pos.url }">
                            <i class="lab lab-pos-bold lab-font-size-16 font-fill-pos" aria-hidden="true"></i>
                        </router-link>
                    </div>
                </div>
            </div>
            <button @click.prevent="handleSidebar"
                v-if="!$route.path.includes('kitchen-display-system') && !$route.path.includes('order-status-screen')"
                class="fa-solid db-header-nav w-9 h-9 rounded-lg text-primary bg-primary/5"
                :aria-label="sidebar ? $t('button.close') : $t('button.menu')"
                :class="sidebar ? 'fa-align-left' : 'fa-bars'"></button>

            <!-- [UR3-A1 V1.0.2 Wave D1] Profile dropdown — ARIA + keyboard nav additive over dropdown.js -->
            <div class="dropdown-group">
                <button
                    class="dropdown-btn flex items-center gap-2"
                    ref="profileTrigger"
                    :id="profileMenuTriggerId"
                    :aria-expanded="profileMenuOpen ? 'true' : 'false'"
                    aria-haspopup="menu"
                    :aria-controls="profileMenuId"
                    @keydown.escape="closeProfileMenu"
                    @keydown.down.prevent="openProfileMenuAndFocusFirst">
                    <img class="flex-shrink-0 w-9 h-9 object-cover rounded-lg" :src="authInfo.image" alt="avatar">
                        <!-- [iter15-mega-fix A-009/A-012 round-7 2026-05-10] No JS chop ".."; CSS ellipsis + :title for full name on hover/SR -->
                        <h3 class="whitespace-nowrap text-sm capitalize text-left leading-[17px]">{{ $t('label.hello') }} <b
                            :title="authInfo.name"
                            class="block font-semibold text-[#111827] overflow-hidden text-ellipsis whitespace-nowrap max-w-[160px]">{{ authInfo.name }}</b></h3>
                    <i class="lab lab-arrow-down text-xs ml-1.5 lab-font-size-14" aria-hidden="true"></i>
                </button>
                <div
                    :id="profileMenuId"
                    role="menu"
                    :aria-labelledby="profileMenuTriggerId"
                    ref="profileMenu"
                    @keydown.escape="closeProfileMenu"
                    class="dropdown-list fixed sm:absolute top-[75px] sm:top-12 ltr:right-0 rtl:left-0 z-[60] rounded-xl w-full h-[calc(100dvh_-_75px)] overflow-y-auto sm:h-auto sm:w-[360px] p-4 shadow-paper bg-white transition-all duration-300 scale-y-0 origin-top">
                    <div class="w-fit mx-auto text-center mb-5">
                        <figure
                            class="relative z-10 w-[98px] h-[98px] border-2 border-dashed rounded-full inline-flex items-center justify-center border-white bg-gradient-to-t from-[#FF7A00] to-[#FF016C] before:absolute before:top-1/2 before:left-1/2 before:-translate-x-1/2 before:-translate-y-1/2 before:w-24 before:h-24 before:rounded-full before:-z-10 before:bg-white">
                            <img class="w-[90px] h-[90px] rounded-full shadow-avatar" :src="authInfo.image"
                                alt="avatar">
                        </figure>

                        <label for="imageProperty"
                            class="block w-11 h-11 mx-auto -mt-7 mb-3 relative z-10 rounded-full border-2 cursor-pointer bg-heading border-white">
                            <input @change="saveImage" accept="image/png, image/jpeg, image/jpg" ref="imageProperty"
                                type="file" id="imageProperty"
                                :aria-label="$t('button.edit_profile')"
                                class="w-full h-full rounded-full opacity-0 cursor-pointer">
                            <i
                                class="lab lab-edit-2 absolute top-1/2 left-1/2 -translate-y-1/2 -translate-x-1/2 -z-10 lab-font-size-24 lab-font-color-1"></i>
                        </label>

                        <!-- [iter15-mega-fix A-009/A-012 round-7 2026-05-10] Render full name + title fallback; CSS handles overflow -->
                        <h3 :title="authInfo.name" class="font-medium text-sm leading-6 capitalize mb-0.5 overflow-hidden text-ellipsis whitespace-nowrap max-w-[260px] mx-auto">{{ authInfo.name }}
                        </h3>
                        <p class="text-xs mb-0.5">{{ authInfo.email }}</p>
                        <!-- [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT — mirrors App\Support\PhoneDisplay::safe -->
                        <p dir="ltr" class="text-xs">{{ safePhone(authInfo.phone) ? (authInfo.country_code || '') + safePhone(authInfo.phone) : '' }}</p>
                        <h3 class="font-medium text-sm leading-6 capitalize mb-0.5">{{ authInfo.currency_balance }}</h3>
                    </div>
                    <!-- [UR3-A1 V1.0.2 Wave D1] role="none" makes <nav> transparent to AT so role="menu"
                         on the outer container correctly owns the role="menuitem" children per ARIA spec
                         (fixes axe aria-required-children + aria-required-parent). -->
                    <nav role="none">
                        <a v-if="isPosV4Shell" href="/admin/profile/edit-profile"
                            role="menuitem" tabindex="-1"
                            class="paper-link transition w-full flex items-center gap-3.5 py-3 border-b last:border-none border-[#EFF0F6]">
                            <i class="lab lab-edit lab-font-size-17" aria-hidden="true"></i>
                            <span class="text-sm leading-6 capitalize">{{ $t('button.edit_profile') }}</span>
                        </a>
                        <router-link v-else :to="{ name: 'admin.profile.editProfile' }"
                            role="menuitem" tabindex="-1"
                            class="paper-link transition w-full flex items-center gap-3.5 py-3 border-b last:border-none border-[#EFF0F6]">
                            <i class="lab lab-edit lab-font-size-17" aria-hidden="true"></i>
                            <span class="text-sm leading-6 capitalize">{{ $t('button.edit_profile') }}</span>
                        </router-link>

                        <a v-if="isPosV4Shell" href="/admin/profile/change-password"
                            role="menuitem" tabindex="-1"
                            class="paper-link transition w-full flex items-center gap-3.5 py-3 border-b last:border-none border-[#EFF0F6]">
                            <i class="lab lab-key lab-font-size-17" aria-hidden="true"></i>
                            <span class="text-sm leading-6 capitalize">{{ $t('button.change_password') }}</span>
                        </a>
                        <router-link v-else :to="{ name: 'admin.profile.changePassword' }"
                            role="menuitem" tabindex="-1"
                            class="paper-link transition w-full flex items-center gap-3.5 py-3 border-b last:border-none border-[#EFF0F6]">
                            <i class="lab lab-key lab-font-size-17" aria-hidden="true"></i>
                            <span class="text-sm leading-6 capitalize">{{ $t('button.change_password') }}</span>
                        </router-link>

                        <button @click="logout()"
                            role="menuitem" tabindex="-1"
                            class="paper-link transition w-full flex items-center gap-3.5 py-3 border-b last:border-none border-[#EFF0F6]">
                            <i class="lab lab-logout lab-font-size-17" aria-hidden="true"></i>
                            <span class="text-sm leading-6 capitalize">{{ $t('button.logout') }}</span>
                        </button>
                    </nav>
                </div>
            </div>
        </div>
    </header>


    <div id="order" v-if="orderNotificationStatus" ref="orderNotificationModal" class="modal active ff-modal">
        <div class="modal-dialog max-w-[360px] p-6 text-center relative">
            <button @click.prevent="closeOrderNotificationModal" class="modal-close absolute top-4 right-4">
                <i class="fa-regular fa-circle-xmark"></i>
            </button>
            <h3 class="text-[18px] font-semibold leading-8 mb-6">
                {{ orderNotificationMessage }}
                <span class="block">{{ $t('message.please_check_your_order_list') }}</span>
            </h3>
            <router-link @click.prevent="closeOrderNotificationModal" :to="{ path: '/admin/' + getUrl() }"
                class="db-btn h-[38px] shadow-[0px_6px_10px_rgba(255,_0,_107,_0.24)] bg-primary text-white">
                {{ $t('button.let_me_check') }}
            </router-link>
        </div>
    </div>
</template>

<script>

import activityEnum from "../../../enums/modules/activityEnum";
import statusEnum from "../../../enums/modules/statusEnum";
import _ from "lodash";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
import { initializeApp } from "firebase/app";
import { getMessaging, getToken, onMessage } from "firebase/messaging";
import axios from "axios";
// [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT — mirrors App\Support\PhoneDisplay::safe
import { safePhone } from "../../../helpers/phoneDisplay";

export default {
    name: "BackendNavbarComponent",
    data() {
        return {
            loading: {
                isActive: false,
            },
            enums: {
                activityEnum: activityEnum
            },
            defaultBranch: null,
            sidebarOpen: true,
            pos: {
                permission: false,
                url: '',
            },
            branchProps: {
                paginate: 0,
                order_column: "id",
                order_type: "asc",
                status: statusEnum.ACTIVE
            },
            orderNotificationStatus: false,
            orderNotificationMessage: "",
            orderNotification: {
                tablePermission: false,
                tableUrl: "",
                permission: false,
                url: "",
                orderType: null
            },
            // [UR3-A1 V1.0.2 Wave D1] Profile dropdown ARIA + keyboard state.
            // Vue 3 has no `_uid` — generate unique IDs once for aria-controls/aria-labelledby.
            // `profileMenuOpen` is mirrored from the dropdown.js `.active` class via MutationObserver
            // (dropdown.js remains the SSOT for open/close to avoid double-toggling).
            profileMenuId: 'profile-menu-' + Math.random().toString(36).slice(2, 10),
            profileMenuTriggerId: 'profile-menu-trigger-' + Math.random().toString(36).slice(2, 10),
            profileMenuOpen: false,
            profileMenuObserver: null,
        }
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        authInfo: function () {
            return this.$store.getters.authInfo;
        },
        authBranch: function () {
            return this.$store.getters.authBranchId;
        },
        branches: function () {
            return this.$store.getters['backendGlobalState/branches'];
        },
        branch: function () {
            return this.$store.getters['backendGlobalState/branchShow'];
        },
        languages: function () {
            return this.$store.getters['frontendLanguage/lists'];
        },
        language: function () {
            return this.$store.getters['frontendLanguage/show'];
        },
        permissions: function () {
            return this.$store.getters.authPermission;
        },
        defaultPermission: function () {
            return this.$store.getters.authDefaultPermission;
        },
        defaultMenu: function () {
            return this.$store.getters.authDefaultMenu;
        },
        kdsHeaderMenu: function () {
            const menu = this.defaultMenu || {};
            if (menu.url && menu.language) {
                return {
                    url: menu.url,
                    icon: menu.icon || 'lab lab-dashboard',
                    label: this.$t('menu.' + menu.language),
                };
            }
            if (this.$route.path.includes('order-status-screen')) {
                return { url: 'order-status-screen', icon: 'lab lab-monitor', label: 'Suivi client' };
            }
            return { url: 'kitchen-display-system', icon: 'lab lab-kitchen', label: 'Écran cuisine' };
        },
        isPosV4Shell() {
            return typeof window !== 'undefined' && window.location.pathname.startsWith('/admin/pos-v4');
        },
        sidebar() {
            return this.$store.getters['globalState/lists'].topSidebar;
        },
    },
    mounted() {
        // [UR3-A1 V1.0.2 Wave D1] Observe `.active` class on profile menu DOM node
        // (toggled by public/themes/default/js/dropdown.js) to mirror state into
        // `profileMenuOpen` for reactive `aria-expanded` binding.
        this.$nextTick(() => {
            if (this.$refs.profileMenu && typeof MutationObserver !== 'undefined') {
                this.profileMenuObserver = new MutationObserver(() => {
                    const isOpen = this.$refs.profileMenu &&
                        this.$refs.profileMenu.classList.contains('active');
                    if (this.profileMenuOpen !== isOpen) {
                        this.profileMenuOpen = isOpen;
                    }
                });
                this.profileMenuObserver.observe(this.$refs.profileMenu, {
                    attributes: true,
                    attributeFilter: ['class'],
                });
            }
        });
        appService.responsiveLoad();
        this.$store.dispatch("globalState/set", { topSidebar: this.sidebarOpen });
        this.$store.dispatch("defaultAccess/show").then(res => {
            this.defaultBranch = res.data.data.branch_id;
            this.$store.dispatch('backendGlobalState/branchShow', res.data.data.branch_id).then().catch();
        }).catch();
        this.$store.dispatch('backendGlobalState/branches', this.branchProps).then().catch();

        this.orderPermissionCheck();
        this.posPermissionCheck();

        window.setTimeout(() => {
            if (this.$store.getters.authStatus && this.setting.notification_fcm_api_key && this.setting.notification_fcm_auth_domain && this.setting.notification_fcm_project_id && this.setting.notification_fcm_storage_bucket && this.setting.notification_fcm_messaging_sender_id && this.setting.notification_fcm_app_id && this.setting.notification_fcm_measurement_id) {
                initializeApp({
                    apiKey: this.setting.notification_fcm_api_key,
                    authDomain: this.setting.notification_fcm_auth_domain,
                    projectId: this.setting.notification_fcm_project_id,
                    storageBucket: this.setting.notification_fcm_storage_bucket,
                    messagingSenderId: this.setting.notification_fcm_messaging_sender_id,
                    appId: this.setting.notification_fcm_app_id,
                    measurementId: this.setting.notification_fcm_measurement_id
                });
                const messaging = getMessaging();

                Notification.requestPermission().then((permission) => {
                    if (permission === 'granted') {
                        getToken(messaging, { vapidKey: this.setting.notification_fcm_public_vapid_key }).then((currentToken) => {
                            if (currentToken) {
                                axios.post('/frontend/device-token/web', { token: currentToken }).then().catch((error) => {
                                    if (error.response.data.message === 'Unauthenticated.') {
                                        this.$store.dispatch('loginDataReset');
                                    }
                                });
                            }
                        }).catch();
                    }
                });

                onMessage(messaging, (payload) => {
                    const notificationTitle = payload.notification.title;
                    const notificationOptions = {
                        body: payload.notification.body,
                        icon: '/images/default/firebase-logo.png'
                    };
                    new Notification(notificationTitle, notificationOptions);

                    if (payload.data.topicName === 'new-order-found' && this.orderNotification.permission) {
                        this.orderNotificationStatus = true;
                        this.orderNotificationMessage = payload.notification.body;
                        const audio = new Audio(this.setting.notification_audio);
                        audio.play();
                    }

                    if (payload.data.topicName === 'new-table-order-found' && this.orderNotification.tablePermission) {
                        this.orderNotification.orderType = 'table';
                        this.orderNotificationStatus = true;
                        this.orderNotificationMessage = payload.notification.body;
                        const audio = new Audio(this.setting.notification_audio);
                        audio.play();
                    }
                    
                    // AUDIT FIX: Dispatch global event to update UI instantly without polling
                    window.dispatchEvent(new CustomEvent('realtime-order-update', { detail: payload }));
                });
            }
        }, 5000);
    },
    beforeUnmount() {
        // [UR3-A1 V1.0.2 Wave D1] Tear down profile-menu class observer to prevent leaks.
        if (this.profileMenuObserver) {
            this.profileMenuObserver.disconnect();
            this.profileMenuObserver = null;
        }
    },
    methods: {
        // [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT proxy for template access.
        safePhone(phone) {
            return safePhone(phone);
        },
        // [UR3-A1 V1.0.2 Wave D1] Profile dropdown keyboard handlers.
        // Note: open/close SSOT is dropdown.js — these helpers manually replicate
        // its `.active`/`.rotated` toggle so keyboard parity holds without racing.
        closeProfileMenu() {
            if (this.$refs.profileMenu) {
                this.$refs.profileMenu.classList.remove('active');
            }
            if (this.$refs.profileTrigger) {
                this.$refs.profileTrigger.classList.remove('rotated');
                this.$nextTick(() => {
                    if (this.$refs.profileTrigger) this.$refs.profileTrigger.focus();
                });
            }
        },
        openProfileMenuAndFocusFirst() {
            // If menu is closed, trigger the document-level click handler in
            // dropdown.js by clicking the trigger (which will also close any other
            // open dropdown). If already open, just move focus to first menuitem.
            if (!this.profileMenuOpen && this.$refs.profileTrigger) {
                this.$refs.profileTrigger.click();
            }
            this.$nextTick(() => {
                const menu = this.$refs.profileMenu;
                if (!menu) return;
                const items = menu.querySelectorAll('[role="menuitem"]');
                if (items && items.length > 0) {
                    items[0].focus();
                }
            });
        },
        textShortener: function (text, number = 30) {
            return appService.textShortener(text, number);
        },
        logout: function () {
            this.$store.dispatch("logout").then(res => {
                this.$router.push({ name: "frontend.home" });
            }).catch();
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
        changeBranch: function (id) {
            this.$store.dispatch("defaultAccess/saveOrUpdate", { branch_id: id }).then(res => {
                this.$store.dispatch('backendGlobalState/branchShow', id).then(res => {
                    location.reload();
                }).catch();
            });
        },
        changeLanguage: function (id, code) {
            this.defaultLanguage = id;
            this.$store.dispatch("globalState/set", { language_id: id, language_code: code }).then(res => {
                this.$store.dispatch('frontendLanguage/show', id).then(res => {
                    this.$i18n.locale = res.data.data.code;
                }).catch();
            }).catch();
        },
        posPermissionCheck: function () {
            const permissions = this.normalizedAuthPermissionList();
            if (permissions.length > 0) {
                _.forEach(permissions, (permission) => {
                    if (permission.name === 'pos') {
                        if (permission.access === true) {
                            this.pos.permission = true;
                            this.pos.url = permission.url;
                        }
                    }
                });
            }
        },
        normalizedAuthPermissionList() {
            const permissions = this.$store.getters.authPermission;
            if (Array.isArray(permissions)) {
                return permissions;
            }
            if (permissions && Array.isArray(permissions.data)) {
                return permissions.data;
            }
            return [];
        },
        saveImage: function () {
            if (this.$refs.imageProperty.files[0]) {
                try {
                    this.loading.isActive = true;
                    const formData = new FormData();
                    formData.append("image", this.$refs.imageProperty.files[0]);
                    this.$store.dispatch("frontendEditProfile/changeImage", { form: formData }).then((res) => {
                        this.$store.dispatch('updateAuthInfo', res.data.data).then(res => {
                            this.loading.isActive = false;
                            alertService.success(this.$t("message.photo_update"));
                            this.$refs.imageProperty.value = null;
                        }).catch((err) => {
                            this.loading.isActive = false;
                            alertService.error(err);
                        });
                    }).catch((err) => {
                        this.loading.isActive = false;
                        this.imageErrors = err.response.data.errors;
                        alertService.error(err.response.data.message);
                    });
                } catch (err) {
                    this.loading.isActive = false;
                    alertService.error(err.response.data.message);
                }
            }
        },
        orderPermissionCheck: function () {
            const permissions = this.normalizedAuthPermissionList();
            if (permissions.length > 0) {
                _.forEach(permissions, (permission) => {
                    if (permission.name === 'online-orders') {
                        if (permission.access === true) {
                            this.orderNotification.permission = true;
                            this.orderNotification.url = permission.url;
                        }
                    }

                    if (permission.name === 'table-orders') {
                        if (permission.access === true) {
                            this.orderNotification.tablePermission = true;
                            this.orderNotification.tableUrl = permission.url;
                        }
                    }
                });
            }
        },
        getUrl: function () {
            return this.orderNotification.orderType === 'table' ? this.orderNotification.tableUrl : this.orderNotification.url
        },
        closeOrderNotificationModal: function () {
            const modalTarget = this.$refs.orderNotificationModal;
            modalTarget?.classList?.remove("active");
            document.body.style.overflowY = "auto";
            this.loading.isActive = false;
            this.orderNotificationStatus = false;
            this.orderNotification.orderType = null;
        },

        closeFullScreen: function () {
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                const elementDbCustomerMain = document?.querySelector(".db-main-customer");
                const headerDiv = document?.querySelector(".db-header");
                elementDbCustomerMain?.classList.remove("db-main-customer", "customer-display");
                elementDbCustomerMain?.classList.add("db-main");
                elementDbCustomerMain?.classList.remove("hiddenHeader");
                headerDiv?.classList.remove('active', 'hidden');
                document?.exitFullscreen();
            };
            // [GOAL-2026-05-29 BTN-P2] removed dangling handleMouseMove ref (never
            // defined — refactor leftover) that threw ReferenceError on the OSS wall.
        },

        fullScreen: function (event) {

            if (this.$route.path.includes('order-status-screen')) {
                const elementDbMain = document?.querySelector(".db-main");
                const elementDbCustomerMain = document?.querySelector(".db-main-customer");
                const headerDiv = document.querySelector(".db-header");

                if (elementDbMain) {
                    elementDbMain.classList.remove("db-main");
                    elementDbMain.classList.add("db-main-customer", "customer-display");
                    elementDbMain.classList.add("hiddenHeader");
                    headerDiv.classList.add("active", "hidden")

                } else {
                    elementDbCustomerMain.classList.remove("db-main-customer", "customer-display");
                    elementDbCustomerMain.classList.add("db-main");
                    elementDbCustomerMain.classList.remove("hiddenHeader");
                    headerDiv.classList.remove("active", "hidden");
                }
            }

            this.toggleFullscreen();
        },
        toggleFullscreen: function () {
            let elem = document.documentElement;
            if (!document.fullscreenElement) {
                if (elem.requestFullscreen) {
                    elem.requestFullscreen();
                } else if (elem.webkitRequestFullscreen) {
                    elem.webkitRequestFullscreen();
                } else if (elem.mozRequestFullScreen) {
                    elem.mozRequestFullScreen();
                } else if (elem.msRequestFullscreen) {
                    elem.msRequestFullscreen();
                }
                // [GOAL-2026-05-29 BTN-P2] removed dangling handleMouseMove addEventListener
                // (never defined) — it threw ReferenceError right after requestFullscreen(),
                // breaking the OSS fullscreen cursor-reveal. Fullscreen toggle now works clean.
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.mozCancelFullScreen) {
                    document.mozCancelFullScreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
                // [GOAL-2026-05-29 BTN-P2] removed dangling handleMouseMove ref (never defined).
            }
        }
    }
}
</script>
