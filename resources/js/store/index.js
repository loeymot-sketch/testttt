import { createStore } from "vuex";

import createPersistedState from "vuex-persistedstate";
import { auth } from "./modules/auth";
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
            paths: [
                "auth",
                "globalState",
                "frontendCart",
                "frontendSignup",
                "GuestSignup",
                "posCart",
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
                // Aucune PII ici — uniquement les toggles a11y et la langue choisie.
                "kioskSettings.locale",
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
