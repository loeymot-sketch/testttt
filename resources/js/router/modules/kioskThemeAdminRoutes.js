/**
 * [V2-5 Phase 2] Admin route for the Kiosk Theme manager (seasonal skinning).
 *
 * Re-uses the same `permissionUrl: settings` gate as the rest of the
 * setup-style admin pages (KioskMachine, OrderSetup, DeliveryPlatforms…)
 * so the existing menu permission seeders gate it without changes.
 *
 * Path mounted : /admin/kiosk-themes
 * Component    : ./components/admin/kioskTheme/KioskThemeManagerPage.vue
 */
import KioskThemeManagerPage from "../../components/admin/kioskTheme/KioskThemeManagerPage.vue";

export default [
    {
        path: "/admin/kiosk-themes",
        component: KioskThemeManagerPage,
        name: "admin.kioskThemes",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "settings",
            breadcrumb: "kiosk_themes",
        },
    },
];
