import { createRouter, createWebHistory } from "vue-router";
import ENV from '../config/env';
import appService from "../services/appService";
import alertService from "../services/alertService";
import i18n, { ensureKioskLocale } from '../i18n';
import DashboardComponent from "../components/admin/dashboard/DashboardComponent";
import NotFoundComponent from "../components/frontend/otherPage/NotFoundComponent";
import ExceptionComponent from "../components/frontend/otherPage/ExceptionComponent";
import store from "../store";
import authRoutes from "./modules/authRoutes";
import settingRoutes from "./modules/settingRoutes";
import adminRoutes from "./modules/adminRoutes";
import offerRoutes from "./modules/offerRoutes";
import itemRoutes from "./modules/itemRoutes";
import stockRoutes from "./modules/stockRoutes";
import ingredientRoutes from "./modules/ingredientRoutes";
import couponRoutes from "./modules/couponRoutes";
import onlineOrderRoutes from "./modules/onlineOrderRoutes";
import pushNotificationRoutes from "./modules/pushNotificationRoutes";
import customerRoutes from "./modules/customerRoutes";
import administratorRoutes from "./modules/administratorRoutes";
import deliveryBoyRoutes from "./modules/deliveryBoyRoutes";
import employeeRoutes from "./modules/employeeRoutes";
import frontendRoutes from "./modules/frontendRoutes";
import salesReportRoutes from "./modules/salesReportRoutes";
import itemsReportRoutes from "./modules/itemsReportRoutes";
import posRoutes from "./modules/posRoutes";
import messageRoutes from "./modules/messageRoutes";
import profileRoutes from "./modules/profileRoutes";
import posOrderRoutes from "./modules/posOrderRoutes";
import transactionRoutes from "./modules/transactionRoutes";
import creditBalanceReportRoutes from "./modules/creditBalanceReportRoutes";
import tableOrderRoutes from "./modules/tableOrderRoutes";
import adminTableOrderRoutes from "./modules/adminTableOrderRoutes";
import diningTableRoutes from "./modules/diningTableRoutes";
import subscriberRoutes from "./modules/subscriberRoutes";
import kitchenDisplaySystemRoutes from "./modules/kitchenDisplaySystemRoutes";
import orderStatusScreenRoutes from "./modules/orderStatusScreenRoutes";
import waiterRoutes from "./modules/waiterRoutes";
import chefRoutes from "./modules/chefRoutes";
import kioskRoutes from "./modules/kioskRoutes";



// [STAFF-ONLY-V1] Helper: lit le flag + état d'auth pour router la racine intelligemment.
const isStaffOnly = () => !!(window.foodkingConfig && window.foodkingConfig.staffOnlyMode);
const isAuthenticated = () => !!store.getters.authStatus;
const authenticatedStaffLanding = () => {
    const url = store.getters.authDefaultPermission?.url || store.getters.authDefaultMenu?.url || null;
    return url ? `/admin/${url}` : { name: "admin.dashboard" };
};

// [STAFF-ONLY-V1] Liste blanche des frontend routes accessibles même en staff-only mode.
// Tout ce qui n'est pas ici est redirigé vers /login quand staffOnlyMode=true.
const STAFF_ONLY_FRONTEND_ALLOWLIST = new Set([
    "auth.login",
    "auth.signup",
    "auth.forgetPassword",
    "auth.resetPassword",
    "auth.guest",
    "route.notFound",
    "route.exception",
]);

// [CV1-WIZARD-COMPOSABLE-001 T-WC-PERM-01] Vérifie si l'utilisateur dispose d'une
// permission donnée (via son URL Spatie, ex: 'items'). Renvoie true quand
// l'entrée est inconnue côté store pour préserver le comportement historique
// (le backend reste l'autorité finale via 403 sur l'API).
const userHasPermission = (permissionUrl) => {
    if (!permissionUrl) return true;
    const permissions = store.getters.authPermission || [];
    const entry = permissions.find((p) => p && p.url === permissionUrl);
    if (!entry) return true;
    return entry.access === true;
};

// [CV1-WIZARD-COMPOSABLE-001 T-WC-PERM-01] Notifie l'utilisateur quand une route
// avec meta.permissionUrl lui est refusée (meta.access === false), et redirige
// vers la fiche produit si l'ID est connu, sinon vers le dashboard. Remplace
// l'ancien redirect aveugle vers `route.exception` qui n'expliquait rien.
const handlePermissionDenied = (to, next) => {
    const permissionUrl = (to.meta && to.meta.permissionUrl) || null;
    const message = permissionUrl
        ? i18n.global.t('message.permission_required', { permission: permissionUrl })
        : i18n.global.t('message.not_authorize');
    try {
        alertService.error(message);
    } catch (_e) {
        // Toast indisponible (ex: bootstrap très précoce avant montage de l'app).
    }
    if (
        to.params &&
        to.params.id &&
        to.name !== 'admin.item.show' &&
        userHasPermission('items')
    ) {
        return next({ name: 'admin.item.show', params: { id: to.params.id } });
    }
    return next({ name: 'admin.dashboard' });
};

const baseRoutes = [
    {
        path: "/",
        name: "root",
        redirect: () => {
            if (isStaffOnly()) {
                return isAuthenticated() ? authenticatedStaffLanding() : { name: "auth.login" };
            }
            return { name: "frontend.home" };
        },
    },
    {
        path: "/kds",
        redirect: { name: "admin.kitchen-display-system" },
    },
    {
        path: "/delivery",
        redirect: { name: "admin.delivery-boys" },
    },
    {
        path: "/order-status",
        redirect: { name: "admin.order-status-screen" },
    },
    {
        path: "/:pathMatch(.*)*",
        name: "route.notFound",
        component: NotFoundComponent,
        meta: {
            isFrontend: true,
        },
    },
    {
        path: "/exception",
        name: "route.exception",
        component: ExceptionComponent,
    },
    {
        path: "/admin/dashboard",
        component: DashboardComponent,
        name: "admin.dashboard",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "dashboard",
            breadcrumb: "dashboard",
        },
    },
];

export const routes = baseRoutes.concat(
    frontendRoutes,
    authRoutes,
    settingRoutes,
    adminRoutes,
    offerRoutes,
    itemRoutes,
    stockRoutes,
    ingredientRoutes,
    pushNotificationRoutes,
    couponRoutes,
    onlineOrderRoutes,
    customerRoutes,
    waiterRoutes,
    chefRoutes,
    deliveryBoyRoutes,
    administratorRoutes,
    employeeRoutes,
    salesReportRoutes,
    itemsReportRoutes,
    messageRoutes,
    profileRoutes,
    posRoutes,
    posOrderRoutes,
    transactionRoutes,
    creditBalanceReportRoutes,
    tableOrderRoutes,
    adminTableOrderRoutes,
    diningTableRoutes,
    subscriberRoutes,
    kitchenDisplaySystemRoutes,
    orderStatusScreenRoutes,
    kioskRoutes
);

const permission = store.getters.authPermission;
appService.recursiveRouter(routes, permission);

const API_URL = ENV.API_URL;
const router = createRouter({
    linkActiveClass: "active",
    mode: 'history',
    history: createWebHistory(),
    routes,
    scrollBehavior() {
        return { left: 0, top: 0 }
    }
});

router.beforeEach((to, from, next) => {
    const isKioskRoute =
        (to.path || '').startsWith('/kiosk') ||
        to.matched.some((record) => record.meta && record.meta.isKiosk);
    if (isKioskRoute) {
        ensureKioskLocale();
    }

    // [STAFF-ONLY-V1] Bloque toutes les routes "vitrine client" (Home/Menu/Offers/etc.)
    // et redirige vers /login quand STAFF_ONLY_MODE=true. Kiosk reste public autonome.
    if (isStaffOnly() && !isKioskRoute && to.meta && to.meta.isFrontend === true) {
        if (!STAFF_ONLY_FRONTEND_ALLOWLIST.has(to.name)) {
            return next({ name: isAuthenticated() ? "admin.dashboard" : "auth.login" });
        }
    }

    if (to.meta.auth === true) {
        if (!store.getters.authStatus) {
            next({ name: "auth.login" });
        } else {
            if (to.meta.isFrontend === false) {
                if (to.meta.access === false) {
                    // [CV1-WIZARD-COMPOSABLE-001 T-WC-PERM-01] Toast clair + redirect
                    // contextuel au lieu du redirect aveugle vers route.exception.
                    return handlePermissionDenied(to, next);
                } else {
                    next();
                }
            } else {
                next();
            }
        }
    } else if (to.name === "auth.login" && store.getters.authStatus) {
        // [STAFF-ONLY-V1] En staff-only, respecter la landing du rôle (POS => /admin/pos).
        next(isStaffOnly() ? authenticatedStaffLanding() : { name: "frontend.home" });
    } else {
        next();
    }
});
export default router;
