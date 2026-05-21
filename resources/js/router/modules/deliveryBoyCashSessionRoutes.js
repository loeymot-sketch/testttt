// [Mission 2 P1 2026-05-21] Admin UI routes for Livreur cash sessions.
// Components shipped earlier (DeliveryBoyCashSessionListComponent,
// DeliveryBoyCashSessionShowComponent, DeliveryBoyCashSessionFormComponent)
// but never wired into the router — owner needs them visible in admin.
//
// Backend: routes/api.php:643-672 (DeliveryBoyCashSessionController + service)
// Permissions: delivery-boys_show (read), delivery-boys (mutations).
const DeliveryBoyCashSessionListComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionListComponent");
const DeliveryBoyCashSessionShowComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionShowComponent");
const DeliveryBoyCashSessionFormComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionFormComponent");

export default [
    {
        path: "/admin/delivery-boy-cash-sessions",
        component: DeliveryBoyCashSessionListComponent,
        name: "admin.delivery-boy-cash-sessions.list",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "delivery-boys",
            breadcrumb: "delivery_cash_sessions",
        },
    },
    {
        path: "/admin/delivery-boy-cash-sessions/open",
        component: DeliveryBoyCashSessionFormComponent,
        name: "admin.delivery-boy-cash-sessions.open",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "delivery-boys",
            breadcrumb: "delivery_cash_open",
        },
    },
    {
        path: "/admin/delivery-boy-cash-sessions/:id",
        component: DeliveryBoyCashSessionShowComponent,
        name: "admin.delivery-boy-cash-sessions.show",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "delivery-boys",
            breadcrumb: "delivery_cash_view",
        },
    },
];
