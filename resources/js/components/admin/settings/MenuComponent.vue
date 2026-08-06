<template>
    <button @click="openSettingMenu($event)" type="button"
        class="settings-btn w-full md:hidden flex items-center justify-center gap-2 p-2 rounded bg-primary text-white">
        <span class="capitalize">{{ $t('menu.settings_menu') }}</span>
        <i class="icon fa-solid fa-chevron-down text-sm"></i>
    </button>
    <div class="h-0 overflow-hidden md:h-auto md:overflow-auto transition-all duration-300">
        <nav class="db-card p-3">
            <router-link :to="{ name: 'admin.settings.company' }" class="db-tab-btn">
                <i class="lab lab-company text-sm"></i>
                {{ $t("menu.company") }}
            </router-link>
            <router-link :to="{ name: 'admin.settings.site' }" class="db-tab-btn">
                <i class="lab lab-site  text-sm"></i>
                {{ $t("menu.site") }}
            </router-link>
            <router-link :to="{ name: 'admin.settings.branch' }" class="db-tab-btn">
                <i class="lab lab-branches text-sm"></i>
                {{ $t("menu.branches") }}
            </router-link>
            <router-link :to="{ name: 'admin.settings.kioskMachines' }" class="db-tab-btn">
                <i class="lab lab-kiosk text-sm"></i>
                {{ $t("menu.kiosk_machines") }}
            </router-link>
            <!-- [AUDIT-A P1-1/P1-2 + P2 2026-08-06] Pages jusque-là inatteignables :
                 rapports Z NF525, imprimantes, et TPE (page existante mais orpheline). -->
            <router-link :to="{ name: 'admin.settings.zReports' }" class="db-tab-btn">
                <i class="lab lab-license text-sm"></i>
                {{ $t("menu.z_reports") }}
            </router-link>
            <router-link :to="{ name: 'admin.settings.printers' }" class="db-tab-btn">
                <i class="lab lab-printer text-sm"></i>
                {{ $t("menu.printers") }}
            </router-link>
            <router-link :to="{ name: 'admin.settings.paymentTerminals' }" class="db-tab-btn">
                <i class="lab lab-payment text-sm"></i>
                {{ $t("menu.payment_terminals") }}
            </router-link>
            <router-link v-if="!isSettingHidden('mail')" :to="{ name: 'admin.settings.mail' }" class="db-tab-btn">
                <i class="lab lab-mail text-sm"></i>
                {{ $t("menu.mail") }}
            </router-link>
            <router-link :to="{ name: 'admin.settings.orderSetup' }" class="db-tab-btn">
                <i class="lab lab-order-setup text-sm"></i>
                {{ $t("menu.order_setup") }}
            </router-link>
            <router-link :to="{ name: 'admin.settings.kioskSetup' }" class="db-tab-btn">
                <i class="lab lab-kiosk text-sm"></i>
                {{ $t("menu.kiosk_setup") }}
            </router-link>
            <router-link v-if="!isSettingHidden('loyaltySetup')" :to="{ name: 'admin.settings.loyaltySetup' }" class="db-tab-btn">
                <i class="lab lab-loyalty text-sm"></i>
                {{ $t("menu.loyalty_setup") }}
            </router-link>
            <router-link v-if="!isSettingHidden('otp')" :to="{ name: 'admin.settings.otp' }" class="db-tab-btn">
                <i class="lab lab-otp text-sm"></i>
                {{ $t("menu.otp") }}
            </router-link>
            <router-link v-if="!isSettingHidden('notification')" :to="{ name: 'admin.settings.notification' }" class="db-tab-btn">
                <i class="lab lab-notification text-sm"></i>
                {{ $t("menu.notification") }}
            </router-link>
            <router-link v-if="!isSettingHidden('notificationAlert')" :to="{ name: 'admin.settings.notificationAlert' }" class="db-tab-btn">
                <i class="lab lab-license text-sm"></i>
                {{ $t("menu.notification_alert") }}
            </router-link>
            <router-link v-if="!isSettingHidden('socialMedia')" :to="{ name: 'admin.settings.socialMedia' }" class="db-tab-btn">
                <i class="lab lab-social-media text-sm"></i>
                {{ $t("menu.social_media") }}
            </router-link>
            <router-link v-if="!isSettingHidden('cookies')" :to="{ name: 'admin.settings.cookies' }" class="db-tab-btn">
                <i class="lab lab-cookies text-sm"></i>
                {{ $t("menu.cookies") }}
            </router-link>
            <router-link v-if="!isSettingHidden('analytics')" :to="{ name: 'admin.settings.analytic' }" class="db-tab-btn">
                <i class="lab lab-analytics text-sm"></i>
                {{ $t("menu.analytics") }}
            </router-link>
            <router-link v-if="!isSettingHidden('theme')" :to="{ name: 'admin.settings.theme' }" class="db-tab-btn">
                <i class="lab lab-theme text-sm"></i>
                {{ $t("menu.theme") }}
            </router-link>
            <router-link v-if="!isSettingHidden('timeSlots')" :to="{ name: 'admin.settings.timeSlot' }" class="db-tab-btn">
                <i class="lab lab-time-slots text-sm"></i>
                {{ $t("menu.time_slots") }}
            </router-link>
            <router-link v-if="!isSettingHidden('sliders')" :to="{ name: 'admin.settings.slider' }" class="db-tab-btn">
                <i class="lab lab-sliders text-sm"></i>
                {{ $t("menu.sliders") }}
            </router-link>
            <router-link :to="{ name: 'admin.settings.currency' }" class="db-tab-btn">
                <i class="lab lab-currencies text-sm"></i>
                {{ $t("menu.currencies") }}
            </router-link>
            <router-link v-if="!isSettingHidden('itemCategories')" :to="{ name: 'admin.settings.itemCategory' }" class="db-tab-btn">
                <i class="lab lab-item-categories text-sm"></i>
                {{ $t("menu.item_categories") }}
            </router-link>
            <router-link v-if="!isSettingHidden('itemAttributes')" :to="{ name: 'admin.settings.itemAttribute' }" class="db-tab-btn">
                <i class="lab lab-item-attributes text-sm"></i>
                {{ $t("menu.item_attributes") }}
            </router-link>
            <router-link v-if="!isSettingHidden('tax')" :to="{ name: 'admin.settings.tax' }" class="db-tab-btn">
                <i class="lab lab-taxes text-sm"></i>
                {{ $t("menu.taxes") }}
            </router-link>
            <router-link v-if="!isSettingHidden('pages')" :to="{ name: 'admin.settings.page' }" class="db-tab-btn">
                <i class="lab lab-pages text-sm"></i>
                {{ $t("menu.pages") }}
            </router-link>
            <router-link v-if="!isSettingHidden('role')" :to="{ name: 'admin.settings.role' }" class="db-tab-btn">
                <i class="lab lab-role-permissions text-sm"></i>
                {{ $t("menu.role_permissions") }}
            </router-link>
            <router-link v-if="!isSettingHidden('languages')" :to="{ name: 'admin.settings.language' }" class="db-tab-btn">
                <i class="lab lab-languages text-sm"></i>
                {{ $t("menu.languages") }}
            </router-link>
            <router-link v-if="!isSettingHidden('smsGateway')" :to="{ name: 'admin.settings.smsGateway' }" class="db-tab-btn">
                <i class="lab lab-sms text-sm"></i>
                {{ $t("menu.sms_gateway") }}
            </router-link>
            <router-link v-if="!isSettingHidden('paymentGateway')" :to="{ name: 'admin.settings.paymentGateway' }" class="db-tab-btn">
                <i class="lab lab-payment-gateway text-sm"></i>
                {{ $t("menu.payment_gateway") }}
            </router-link>
            <router-link v-if="!isSettingHidden('license')" :to="{ name: 'admin.settings.license' }" class="db-tab-btn">
                <i class="lab lab-license text-sm"></i>
                {{ $t("menu.license") }}
            </router-link>
            <div v-if="wizardPerItemDemoEnabled" class="border-t mt-3 pt-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">
                    {{ $t("menu.advanced_tools") }}
                </p>
                <router-link :to="{ name: 'admin.demo.wizard-launcher' }" class="db-tab-btn">
                    <i class="lab lab-items text-sm"></i>
                    {{ $t("menu.demo_wizard_advanced") }}
                </router-link>
            </div>
        </nav>
    </div>
</template>

<script>
import appService from "../../../services/appService";
import { V1_HIDDEN_MENU_MODULES } from "../../../config/v1-hidden-modules";

/**
 * Mapping local : clés `settings.*` de V1_HIDDEN_MENU_MODULES → identifiant
 * court utilisé dans ce composant pour l'attribut `v-if` (ex. `loyaltySetup`,
 * pas `loyalty-setup`). La constante centrale garde le format kebab-case ;
 * la conversion vit ici pour ne pas polluer le contrat partagé.
 */
const HIDDEN_KEY_TO_LOCAL_SETTING = Object.freeze({
    'settings.mail': 'mail',
    'settings.loyalty-setup': 'loyaltySetup',
    'settings.notification': 'notification',
    'settings.theme': 'theme',
    'settings.item-categories': 'itemCategories',
    'settings.item-attributes': 'itemAttributes',
    'settings.permission': 'permission',
    'settings.role': 'role',
    'settings.tax': 'tax',
    'settings.charge': 'charge',
    'settings.translation': 'translation',
    'settings.activity-log': 'activityLog',
    'settings.languages': 'languages',
    'settings.otp': 'otp',
    'settings.notification-alert': 'notificationAlert',
    'settings.social-media': 'socialMedia',
    'settings.cookies': 'cookies',
    'settings.analytics': 'analytics',
    'settings.time-slots': 'timeSlots',
    'settings.sliders': 'sliders',
    'settings.pages': 'pages',
    'settings.sms-gateway': 'smsGateway',
    'settings.payment-gateway': 'paymentGateway',
    'settings.license': 'license',
});

const HIDDEN_LOCAL_SETTINGS = new Set(
    V1_HIDDEN_MENU_MODULES
        .map(key => HIDDEN_KEY_TO_LOCAL_SETTING[key])
        .filter(Boolean),
);

export default {
    name: "MenuComponent",
    computed: {
        wizardPerItemDemoEnabled() {
            return typeof window !== 'undefined'
                && window.foodkingConfig?.features?.wizard_per_item_demo === true;
        },
    },
    methods: {
        openSettingMenu: function (event) {
            return appService.openSettingMenu(event);
        },
        isSettingHidden(localKey) {
            return HIDDEN_LOCAL_SETTINGS.has(localKey);
        },
    }
};
</script>