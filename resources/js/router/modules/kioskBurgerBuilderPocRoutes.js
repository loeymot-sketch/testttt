// V2-2 Phase A — POC route exposing the standalone burger-builder demo.
// Greenfield, public, requires no auth, lives outside the auth-gated kiosk
// shell so a reviewer can inspect the drag-and-drop UX without spinning up
// a kiosk session. Phase B (wizard integration) stays gated.
const KioskBurgerBuilderPoc = () => import(
    /* webpackChunkName: "kiosk-builder-poc" */ '../../components/frontend/kiosk/builder/KioskBurgerBuilderPoc.vue'
);

export default [
    {
        path: '/kiosk/burger-builder-poc',
        name: 'kiosk.builder.poc',
        component: KioskBurgerBuilderPoc,
        // `auth` is intentionally omitted: the global `router.beforeEach`
        // gate in resources/js/router/index.js triggers only when
        // `meta.auth === true`. `isKiosk: true` ensures `ensureKioskLocale()`
        // runs so $t() resolves the fr.json keys consistently.
        meta: { isKiosk: true, requiresAuth: false },
    },
];
