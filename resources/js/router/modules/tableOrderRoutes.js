// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const TableMenuComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/table/tableMenu/TableMenuComponent");
const SearchItemComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/table/search/SearchItemComponent.vue");
const PageComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/table/page/PageComponent.vue");
const CheckoutComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/table/checkout/CheckoutComponent.vue");
const OrderDetailsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/table/order/OrderDetailsComponent.vue");
export default [
    {
        path: "/menu/:slug",
        component: TableMenuComponent,
        name: "table.menu.table",
        meta: {
            isTable: true,
            auth: false,
        },
    },
    {
        path: "/search/:slug",
        component: SearchItemComponent,
        name: "table.search",
        meta: {
            isTable: true,
            auth: false,
        },
    },
    {
        path: "/page/:slug/:pageSlug",
        component: PageComponent,
        name: "table.page",
        meta: {
            isTable: true,
            auth: false,
        },
    },
    {
        path: "/checkout/:slug",
        component: CheckoutComponent,
        name: "table.checkout",
        meta: {
            isTable: true,
            auth: false,
        },
    },
    {
        path: "/table-order/:slug/:id",
        component: OrderDetailsComponent,
        name: "table.tableOrder.details",
        meta: {
            isTable: true,
            auth: false,
        },
    },
];
