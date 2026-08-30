// [POS-V4 W1-A 2026-04-26] Lazy-load POS shell — extracts PosComponent and its
// statically-imported children (ItemComponent, PaymentComponent, ParkedOrdersComponent,
// SkeletonGrid, CreateCustomerAddressComponent, ConnectionStatusBanner, LoadingComponent)
// from the main `app.js` bundle into a dedicated `pos-shell` async chunk.
// Pattern identical to kioskRoutes.js (kiosk-shell / kiosk-wizard / kiosk-admin chunks).
// Goal: meet POS first-paint KPI (< 220 KB gzipped JS) declared in
// reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT (current app.js = 965 KB gzipped).
// FloorplanComponent shares the same chunk because it is a sibling POS surface
// (admin → POS → table layout) reached only after the POS shell is already loaded.
const PosComponent       = () => import(/* webpackChunkName: "pos-shell" */ "../../components/admin/pos/PosComponent");
const FloorplanComponent = () => import(/* webpackChunkName: "pos-shell" */ "../../components/admin/pos/FloorplanComponent");

export default [
    {
        path: "/admin/pos",
        component: PosComponent,
        name: "admin.pos",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos",
        },
    },
    {
        path: "/admin/pos/voice-assistant",
        component: PosComponent,
        name: "admin.pos.voice-assistant",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos",
            voiceAssistant: true,
            breadcrumb: "Assistant téléphone",
        },
    },
    {
        path: "/admin/pos/floorplan",
        component: FloorplanComponent,
        name: "admin.pos.floorplan",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos",
            breadcrumb: "floorplan",
        },
    },
];
