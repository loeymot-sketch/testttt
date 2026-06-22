// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const CouponComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/coupons/CouponComponent");
const CouponListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/coupons/CouponListComponent");
const CouponShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/coupons/CouponShowComponent");
export default [
    {
        path: "/admin/coupons",
        component: CouponComponent,
        name: "admin.coupons",
        redirect: { name: "admin.coupons.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "coupons",
            breadcrumb: "coupons",
        },
        children: [
            {
                path: "",
                component: CouponListComponent,
                name: "admin.coupons.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "coupons",
                    breadcrumb: "",
                },
            },
            {
                path: "show/:id",
                component: CouponShowComponent,
                name: "admin.coupon.show",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "coupons",
                    breadcrumb: "view",
                },
            },
        ],
    },
];
