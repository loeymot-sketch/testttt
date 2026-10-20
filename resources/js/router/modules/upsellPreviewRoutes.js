import UpsellPreviewPage from "../../components/admin/upsellPreview/UpsellPreviewPage.vue";

/**
 * V2-3 Phase A — Admin Upsell Preview route.
 *
 * Outil d'aperçu admin gated par `permissionUrl: "settings"` (cohérent avec
 * `permission:settings` côté controller). Permet de tester les stratégies
 * RuleBased / MlPlaceholder hors prod kiosk sans toucher aux frozen
 * components (KioskUpsellComponent admin-curated reste intact).
 *
 * Voir plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md.
 */
export default [
    {
        path: "/admin/upsell-preview",
        component: UpsellPreviewPage,
        name: "admin.upsellPreview",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "settings",
            breadcrumb: "upsell_preview",
        },
    },
];
