/**
 * V2-3 Phase A — Admin Upsell Preview route.
 *
 * Outil d'aperçu admin gated par `permissionUrl: "settings"` (cohérent avec
 * `permission:settings` côté controller). Permet de tester les stratégies
 * RuleBased / MlPlaceholder hors prod kiosk sans toucher aux frozen
 * components (KioskUpsellComponent admin-curated reste intact).
 *
 * [Wave-B B2 2026-05-08] Lazy-loaded via webpack chunk pour ne pas bloat
 * le main bundle (admin tool pas sur kiosk hot path).
 *
 * Voir plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md.
 */
const UpsellPreviewPage = () =>
    import(/* webpackChunkName: "admin-upsell-preview" */ "../../components/admin/upsellPreview/UpsellPreviewPage.vue");

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
