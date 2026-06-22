// [CV1-OBSERVABILITY-OUTBOX-001] Admin outbox pipeline dashboard (SPA).
// Lives behind /admin/observability/outbox. Backend gate is `role:Admin|Tenant
// Admin`; the SPA mirrors this with `permissionUrl: "dashboard"` (every Admin
// has dashboard access — finer authority enforcement is server-side).
const OutboxOverviewComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/observability/OutboxOverviewComponent");

export default [
    // [iter15-mega-fix A-001 2026-05-10] /admin/observability was a dead URL:
    // Laravel SPA fallback returned 200 HTML, but Vue Router fell through to
    // route.notFound and rendered the generic 404 illustration. The bare URL
    // is what links/external dashboards/tooling are most likely to point at,
    // so register a redirect to the real outbox sub-route. The canonical
    // /admin/observability/outbox entry stays intact below.
    {
        path: "/admin/observability",
        redirect: { name: "admin.observability.outbox" },
    },
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
