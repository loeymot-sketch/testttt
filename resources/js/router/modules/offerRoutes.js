// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const OfferComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/offers/OfferComponent");
const OfferListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/offers/OfferListComponent");
const OfferShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/offers/OfferShowComponent");
export default [
    {
        path: '/admin/offers',
        component: OfferComponent,
        name: 'admin.offers',
        redirect: {name: 'admin.offers.list'},
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'offers',
            breadcrumb: 'offers'
        },
        children: [
            {
                path: '',
                component: OfferListComponent,
                name: 'admin.offers.list',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'offers',
                    breadcrumb: ''
                },
            },
            {
                path: "show/:id",
                component: OfferShowComponent,
                name: "admin.offer.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "offers",
                    breadcrumb: "view",
                },
            },
        ]
    }
]
