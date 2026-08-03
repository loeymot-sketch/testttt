// [Mission 2 P1 2026-05-21] Admin UI routes for Livreur cash sessions.
//
// ListComponent : top-level admin view (no props required).
// ShowComponent : requires `sessionId` prop (passed from route :id param).
// FormComponent : embedded only — used by DeliveryBoyShow tabs (existing
//   pattern per component docblock). No top-level /open route — opening
//   a session goes through the delivery boy detail page.
//
// Backend: routes/api.php:643-672 (DeliveryBoyCashSessionController + service)
// Permissions: delivery-boys_show (read), delivery-boys (mutations).
const DeliveryBoyCashSessionListComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionListComponent");
const DeliveryBoyCashSessionShowComponent = () =>
    import(/* webpackChunkName: "admin-shell" */ "../../components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionShowComponent");

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
        path: "/admin/delivery-boy-cash-sessions/:id",
        component: DeliveryBoyCashSessionShowComponent,
        name: "admin.delivery-boy-cash-sessions.show",
        props: (route) => ({ sessionId: route.params.id }),
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "delivery-boys",
            breadcrumb: "delivery_cash_view",
        },
    },
];
