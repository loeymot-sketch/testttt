import { createStore } from "vuex";

import createPersistedState from "vuex-persistedstate";
import { auth, sanitizePendingPhone } from "./modules/auth";
import { company } from "./modules/company";
import { itemCategory } from "./modules/itemCategory";
import { itemAttribute } from "./modules/itemAttribute";
import { slider } from "./modules/slider";
import { branch } from "./modules/branch";
import { offer } from "./modules/offer";
import { item } from "./modules/item";
import { itemVariation } from "./modules/itemVariation";
import { onlineOrder } from "./modules/onlineOrder";
import { tax } from "./modules/tax";
import { currency } from "./modules/currency";
import { mail } from "./modules/mail";
import { menuSection } from "./modules/menuSection";
import { page } from "./modules/page";
import { notification } from "./modules/notification";
import { pushNotification } from "./modules/pushNotification";
import { menuTemplate } from "./modules/menuTemplate";
import { coupon } from "./modules/coupon";
import { customer } from "./modules/customer";
import { waiter } from "./modules/waiter";
import { chef } from "./modules/chef";
import { otp } from "./modules/otp";
import { administrator } from "./modules/administrator";
import { deliveryBoy } from "./modules/deliveryBoy";
import { deliveryBoyAddress } from "./modules/deliveryBoyAddress";
import { defaultAccess } from "./modules/defaultAccess";
import { administratorAddress } from "./modules/administratorAddress";
import { customerAddress } from "./modules/customerAddress";
import { waiterAddress } from "./modules/waiterAddress";
import { chefAddress } from "./modules/chefAddress";
import { socialMedia } from "./modules/socialMedia";
import { license } from "./modules/license";
import { analytic } from "./modules/analytic";
import { analyticSection } from "./modules/analyticSection";
import { role } from "./modules/role";
import { permission } from "./modules/permission";
import { cookies } from './modules/cookies';
import { theme } from './modules/theme';
import { timeSlot } from './modules/timeSlot';
import { employee } from './modules/employee';
import { employeeAddress } from './modules/employeeAddress';
import { itemExtra } from './modules/itemExtra';
import { itemAddon } from './modules/itemAddon';
import { itemAvailability } from './modules/itemAvailability';
import { ingredients } from './modules/ingredients';
import { language } from './modules/language';
import { frontendBranch } from "./modules/frontend/frontendBranch";
import { frontendLanguage } from "./modules/frontend/frontendLanguage";
import { frontendSetting } from "./modules/frontend/frontendSetting";
import { frontendPage } from "./modules/frontend/frontendPage";
import { globalState } from "./modules/frontend/globalState";
import { frontendSlider } from "./modules/frontend/frontendSlider";
import { frontendItemCategory } from "./modules/frontend/frontendItemCategory";
import { timezone } from './modules/timezone';
import { site } from './modules/site';
import { dashboard } from './modules/dashboard';
import { orderSetup } from './modules/orderSetup';
import { kioskSetup } from './modules/kioskSetup';
import { loyaltySetup } from './modules/loyaltySetup';
import { offerItem } from './modules/offerItem';
import { paymentGateway } from './modules/paymentGateway';
import { paymentTerminal } from './modules/paymentTerminal';
import { smsGateway } from './modules/smsGateway';
import { salesReport } from './modules/salesReport';
import { frontendCart } from "./modules/frontend/frontendCart";
import { itemsReport } from './modules/itemsReport';
import { frontendEditProfile } from './modules/frontend/frontendEditProfile';
import { frontendCountryCode } from './modules/frontend/frontendCountryCode';
import { frontendAddress } from './modules/frontend/frontendAddress';
import { message } from './modules/message';
import { diningTable } from "./modules/diningTable";
import { frontendTimeSlot } from "./modules/frontend/frontendTimeSlot";
import { frontendItem } from "./modules/frontend/frontendItem";
import { frontendOffer } from './modules/frontend/frontendOffer';
import { frontendCoupon } from "./modules/frontend/frontendCoupon";
import { countryCode } from './modules/countryCode';
import { frontendOrder } from "./modules/frontend/frontendOrder";
import { frontendSignup } from "./modules/frontend/frontendSignup";
import { GuestSignup } from "./modules/frontend/GuestSignup";
import { backendGlobalState } from "./modules/backendGlobalState";
import { myOrderDetails } from './modules/myOrderDetails';
import { posCart } from './modules/posCart';
import { posFloorplan } from './modules/posFloorplan';
import { posParked } from './modules/posParked';
import { posCustomer } from './modules/posCustomer';
import { posOrder } from './modules/posOrder';
import { orderHistory } from './modules/orderHistory';
import { cashDrawer } from './modules/cashDrawer';
import { transaction } from './modules/transaction';
import { notificationAlert } from './modules/notificationAlert';
import { creditBalanceReport } from './modules/creditBalanceReport';
import { deliveryBoyOrder } from './modules/deliveryBoyOrder';
import { user } from './modules/user';
import { frontendMessage } from "./modules/frontend/frontendMessage";
import { posCategory } from './modules/posCategory';
import { tableItemCategory } from "./modules/table/tableItemCategory";
import { tableCart } from "./modules/table/tableCart";
import { tableDiningTable } from "./modules/table/tableDiningTable";
import { tableDiningOrder } from "./modules/table/tableDiningOrder";
import { tableOrder } from './modules/tableOrder';
import { subscriber } from './modules/subscriber';
import { kitchenDisplaySystemOrder } from './modules/kitchenDisplaySystemOrder';
import { kds } from './modules/kds';
// [CV1-KDS-INFLIGHT-OOS-MARKER-001] Tracks items just marked unavailable (86)
// so the KDS surface can warn the kitchen about in-flight tickets that still
// contain those items. Lazy TTL 10min purge; not persisted (runtime only).
import { kdsInflight } from './modules/kdsInflight';
import { orderStatusScreenOrder } from './modules/orderStatusScreenOrder';
import { kioskMachine } from './modules/kioskMachine';
import { kioskCart } from './modules/kioskCart';
import { kioskMenu } from './modules/kioskMenu';
import { kioskSettings } from './modules/kioskSettings';
import kioskFilter from './modules/kioskFilter';
// [CV1-WC-T-WC-MENU-CATALOG-01] Composer module registration. Module exists since
// item composer profile feature mais n'était pas câblé dans le store — actions
// `show / save / publish / unpublish` désormais accessibles via `composer/...`.
import { composer } from './modules/composer';
// [PHASE-6.4] Plugin analytics : s'abonne aux mutations Vuex pertinentes
//             et relaie vers kioskAnalytics.track() (consent-gated, anonyme).
import kioskAnalyticsPlugin from './plugins/kioskAnalyticsPlugin';



export default new createStore({
    state: {},
    mutations: {},
    actions: {},
    modules: {
        auth,
        company,
        itemCategory,
        itemAttribute,
        slider,
        branch,
        offer,
        item,
        itemVariation,
        tax,
        currency,
        mail,
        pushNotification,
        notification,
        page,
        onlineOrder,
        menuSection,
        menuTemplate,
        coupon,
        customer,
        waiter,
        chef,
        customerAddress,
        waiterAddress,
        chefAddress,
        otp,
        administrator,
        deliveryBoy,
        deliveryBoyAddress,
        defaultAccess,
        administratorAddress,
        socialMedia,
        license,
        analytic,
        analyticSection,
        role,
        permission,
        cookies,
        theme,
        timeSlot,
        employee,
        employeeAddress,
        itemExtra,
        itemAddon,
        itemAvailability,
        ingredients,
        language,
        globalState,
        frontendBranch,
        frontendLanguage,
        frontendSetting,
        frontendPage,
        frontendSlider,
        frontendItemCategory,
        frontendCart,
        timezone,
        site,
        dashboard,
        orderSetup,
        kioskSetup,
        loyaltySetup,
        offerItem,
        paymentGateway,
        paymentTerminal,
        smsGateway,
        salesReport,
        itemsReport,
        frontendEditProfile,
        frontendCountryCode,
        frontendAddress,
        message,
        frontendTimeSlot,
        frontendItem,
        frontendOffer,
        frontendCoupon,
        countryCode,
        frontendOrder,
        frontendSignup,
        GuestSignup,
        backendGlobalState,
        myOrderDetails,
        posCart,
        posFloorplan,
        posParked,
        posCustomer,
        posOrder,
        orderHistory,
        cashDrawer,
        transaction,
        notificationAlert,
        creditBalanceReport,
        deliveryBoyOrder,
        user,
        frontendMessage,
        posCategory,
        diningTable,
        tableItemCategory,
        tableCart,
        tableDiningTable,
        tableDiningOrder,
        tableOrder,
        subscriber,
        kitchenDisplaySystemOrder,
        kds,
        kdsInflight,
        orderStatusScreenOrder,
        kioskMachine,
        kioskCart,
        kioskMenu,
        kioskSettings,
        kioskFilter,
        composer,
    },
    plugins: [
        createPersistedState({
            // [UR4-002 V1.0.2 Wave A1] Override default getState to sanitize
            // the `auth.authInfo.phone` field on rehydrate. vuex-persistedstate
            // bypasses mutations on boot (calls `store.replaceState(savedState)`
            // directly), so the auth mutation-level sanitize alone cannot scrub
            // pre-existing polluted localStorage from sessions that ran before
            // backend PhoneDisplay::safe (commit afc094091) was deployed. Without
            // this override, legacy `PENDING_CREATE_<hex>` sentinels survive
            // page reloads forever for already-onboarded users.
            //
            // We preserve the default JSON.parse + try/catch contract from
            // vuex-persistedstate/src/index.ts so unparseable storage falls back
            // to `undefined` (= fresh store, no rehydrate) instead of crashing.
            getState: (key, storage) => {
                const value = storage.getItem(key);
                try {
                    const parsed = typeof value === "string"
                        ? JSON.parse(value)
                        : (typeof value === "object" ? value : undefined);
                    if (parsed && parsed.auth && parsed.auth.authInfo) {
                        parsed.auth.authInfo = sanitizePendingPhone(parsed.auth.authInfo);
                    }
                    // [W5-PERF B1 2026-07-06] posCart n'est plus persisté ici
                    // (voir paths + filter ci-dessous) — on purge aussi un
                    // éventuel snapshot legacy de la clé `vuex` pour qu'un
                    // panier NON scopé caissier ne soit jamais réhydraté au
                    // boot (le module posCart réhydrate depuis SA clé scopée
                    // `pos_cart_v3:b<branch>:u<user>` via posCart/setScope).
                    if (parsed && parsed.posCart) {
                        delete parsed.posCart;
                    }
                    return parsed;
                } catch (err) {
                    return undefined;
                }
            },
            // [W5-PERF B1 2026-07-06] vuex-persistedstate sérialise TOUT l'état
            // persisté (~19 Ko JSON) à CHAQUE mutation — mesuré ~9-12 écritures
            // localStorage ≈ 135-228 Ko par AJOUT PANIER caisse, car chaque
            // action posCart enchaîne lists/subtotal/discount. posCart étant
            // retiré des paths (le module gère SA propre persistence scopée +
            // TTL 2 h, posCart.js [POS-9.1.9]), ses mutations ne changent plus
            // RIEN au snapshot persisté → on les filtre pour supprimer ces
            // écritures redondantes. Toute autre mutation persiste comme avant.
            filter: (mutation) => !String(mutation && mutation.type || '').startsWith('posCart/'),
            paths: [
                "auth",
                "globalState",
                "frontendCart",
                "frontendSignup",
                "GuestSignup",
                // [W5-PERF B1 2026-07-06] "posCart" RETIRÉ : le module posCart
                // persiste déjà lui-même chaque mutation sous sa clé scopée
                // caissier `pos_cart_v3:b<branch>:u<user>` (TTL 2 h) et se
                // réhydrate via posCart/setScope au mount du POS
                // (PosComponent.applyPosBranchScope). Le persister AUSSI dans
                // la clé `vuex` doublait chaque écriture ET réhydratait un
                // panier non scopé au boot (fuite inter-caissier théorique).
                "tableCart",
                // Kiosk: persist enough to survive a page refresh on the waiting screen
                "kioskCart.branchId",
                "kioskCart.orderRef",
                "kioskCart.queueNumber",
                "kioskCart.idempotencyKey",
                "kioskCart.items",
                "kioskCart.loyaltyDiscount",
                "kioskCart.loyaltyCustomer",
                // [AUDIT-P1] Persist orderType: "Sur place" (25) vs "À emporter" (10) chosen by
                // customer must survive a page refresh (e.g. Electron reload on the payment screen).
                // Without this, a reload between cart and payment resets to default 25 (sur place).
                "kioskCart.orderType",
                // Kiosk machine auth — persist token so machine stays logged in across refreshes
                "kioskCart.kioskToken",
                "kioskCart.kioskMachineId",
                // Phase 4 — Accessibility & locale preferences (European Accessibility Act).
                // Stockés sur l'appareil (localStorage) pour survivre aux reloads Electron.
                // Aucune PII ici — uniquement les toggles a11y.
                //
                // [ADR-007 / Sprint 3D 2026-05-16] `kioskSettings.locale` est volontairement
                // EXCLU de la persistance : le kiosk runtime est FR-immutable en V1.
                // Persister la locale permettrait à un store en `ar`/`en` (legacy iter15
                // antérieur au lock) de forcer une locale non-FR au boot via
                // `applyKioskA11yFromStore`. Le store retombe sur le default 'fr' à
                // chaque reload, ce qui restaure le FR-lock même sur les bornes qui
                // auraient un localStorage hérité. Voir docs/adr/ADR-007-kiosk-fr-lock.md.
                "kioskSettings.contrast",
                "kioskSettings.pmr",
                "kioskSettings.audio",
                "kioskSettings.keyboardEnabled",
                "kioskSettings.idleMs",
                "kioskSettings.confirmMs",
                "kioskSettings.receiptMs",
                "kioskSettings.consentAnalytics",
                "kioskSettings.consentLoyalty",
            ],
        }),
        kioskAnalyticsPlugin,
    ],
});
