// [CV1-OBSERVABILITY-OUTBOX-001] Admin outbox pipeline dashboard (SPA).
// Lives behind /admin/observability/outbox. Backend gate is `role:Admin|Tenant
// Admin`; the SPA mirrors this with `permissionUrl: "dashboard"` (every Admin
// has dashboard access — finer authority enforcement is server-side).
const OutboxOverviewComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/observability/OutboxOverviewComponent");

export default [
    {
        path: "/admin/observability/outbox",
        name: "admin.observability.outbox",
        component: OutboxOverviewComponent,
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "dashboard",
            breadcrumb: "observability_outbox",
        },
    },
];
