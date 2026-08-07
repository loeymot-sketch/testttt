// [FLYER PROMO UBER 2026-08-07] Ticket promotionnel nominatif.
//
// `permissionUrl: "pos-orders"` — on réutilise une permission EXISTANTE plutôt
// que d'en créer une nouvelle : une permission inédite n'est portée par aucun
// rôle en base tant qu'un seeder ne l'a pas distribuée, l'écran serait donc
// inaccessible à tout le monde, propriétaire compris. Même raisonnement que
// le contrôleur (`permission:pos|pos-orders`).
const PromoFlyerComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/promo/PromoFlyerComponent");
const PromoFlyerSettingsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/promo/PromoFlyerSettingsComponent");

export default [
    {
        path: "/admin/promo-flyer",
        component: PromoFlyerComponent,
        name: "admin.promoFlyer",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "promo_flyer",
        },
    },
    {
        path: "/admin/promo-flyer/settings",
        component: PromoFlyerSettingsComponent,
        name: "admin.promoFlyer.settings",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "pos-orders",
            breadcrumb: "promo_flyer_settings",
        },
    },
];
