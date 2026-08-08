// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const SettingsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/SettingsComponent");
const CompanyComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Company/CompanyComponent");
const SiteComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Site/SiteComponent");
const ItemCategoryListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/ItemCategory/ItemCateogryListComponent");
const ItemCategoryComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/ItemCategory/ItemCategoryComponent");
const ItemAttributeComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/ItemAttribute/ItemAttributeComponent");
const ItemAttributeListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/ItemAttribute/ItemAttributeListComponent");
const SliderComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Slider/SliderComponent");
const SliderListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Slider/SliderListComponent");
const SliderShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Slider/SliderShowComponent");
const BranchComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Branch/BranchComponent");
const BranchListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Branch/BranchListComponent");
const BranchShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Branch/BranchShowComponent");
const TaxComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Tax/TaxComponent");
const TaxListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Tax/TaxListComponent");
const CurrencyComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Currency/CurrencyComponent");
const CurrencyListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Currency/CurrencyListComponent");
const MailComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Mail/MailComponent");
const NotificationComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Notification/NotificationComponent");
const PageComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Page/PageComponent");
const PageListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Page/PageListComponent");
const PageShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Page/PageShowComponent");
const OtpComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Otp/OtpComponent");
const SocialMediaComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/SocialMedia/SocialMediaComponent");
const LicenseComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/License/LicenseComponent");
const AnalyticComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/analytics/AnalyticComponent");
const AnalyticListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/analytics/AnalyticListComponent");
const AnalyticShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/analytics/AnalyticShowComponent");
const RoleComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Role/RoleComponent");
const RoleListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Role/RoleListComponent");
const RoleShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Role/RoleShowComponent");
const CookiesComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Cookies/CookiesComponent");
const ThemeComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Theme/ThemeComponent");
const TimeSlotListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/TimeSlot/TimeSlotListComponent");
const LanguageComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Language/LanguageComponent");
const LanguageListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Language/LanguageListComponent");
const LanguageShowComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Language/LanguageShowComponent");
const OrderSetupComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/OrderSetup/OrderSetupComponent");
const KioskSetupComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/KioskSetup/KioskSetupComponent");
const LoyaltySetupComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/LoyaltySetup/LoyaltySetupComponent");
const PaymentGatewayComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/PaymentGateway/PaymentGatewayComponent");
const PaymentTerminalsComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/PaymentTerminals/PaymentTerminalsComponent");
// [AUDIT-A P1-1/P1-2 2026-08-06] Rapports Z NF525 + gestion imprimantes — APIs
// complètes qui n'avaient AUCUNE page (PDF légaux inatteignables sans curl).
const ZReportListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Fiscal/ZReportListComponent");
const PrintersComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/Printers/PrintersComponent");
const SmsGatewayComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/SmsGateway/SmsGatewayComponent");
const NotificationAlertComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/NotificationAlert/NotificationAlertComponent");
const KioskMachineComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/KioskMachine/KioskMachineComponent.vue");
const KioskMachineListComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/settings/KioskMachine/KioskMachineListComponent.vue");
export default [
    {
        path: "/admin/settings",
        component: SettingsComponent,
        name: "admin.settings",
        redirect: { name: "admin.settings.company" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "settings",
            breadcrumb: "settings",
        },
        children: [
            {
                path: "company",
                component: CompanyComponent,
                name: "admin.settings.company",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "company",
                },
            },
            {
                path: "site",
                component: SiteComponent,
                name: "admin.settings.site",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "site",
                },
            },
            {
                path: "branches",
                component: BranchComponent,
                name: "admin.settings.branch",
                redirect: { name: "admin.settings.branch.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "branches",
                },
                children: [
                    {
                        path: "list",
                        component: BranchListComponent,
                        name: "admin.settings.branch.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                    {
                        path: "show/:id",
                        component: BranchShowComponent,
                        name: "admin.settings.branch.show",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "view",
                        },
                    },
                ],
            },
            {
                path: "mail",
                component: MailComponent,
                name: "admin.settings.mail",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "mail",
                },
            },
            {
                path: "order-setup",
                component: OrderSetupComponent,
                name: "admin.settings.orderSetup",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "order_setup",
                },
            },
            {
                path: "kiosk-setup",
                component: KioskSetupComponent,
                name: "admin.settings.kioskSetup",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "kiosk_setup",
                },
            },
            {
                path: "loyalty-setup",
                component: LoyaltySetupComponent,
                name: "admin.settings.loyaltySetup",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "loyalty_setup",
                },
            },
            {
                path: "otp",
                component: OtpComponent,
                name: "admin.settings.otp",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "otp",
                },
            },
            {
                path: "notification",
                component: NotificationComponent,
                name: "admin.settings.notification",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "notification",
                },
            },
            {
                path: "social-media",
                component: SocialMediaComponent,
                name: "admin.settings.socialMedia",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "social_media",
                },
            },
            {
                path: "cookies",
                component: CookiesComponent,
                name: "admin.settings.cookies",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "cookies",
                },
            },
            {
                path: "analytics",
                component: AnalyticComponent,
                name: "admin.settings.analytic",
                redirect: { name: "admin.settings.analytic.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "analytics",
                },
                children: [
                    {

                        path: "list",
                        component: AnalyticListComponent,
                        name: "admin.settings.analytic.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                    {
                        path: "show/:id",
                        component: AnalyticShowComponent,
                        name: "admin.settings.analytic.show",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "view",
                        },
                    },
                ]
            },
            {
                path: "theme",
                component: ThemeComponent,
                name: "admin.settings.theme",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "theme",
                },
            },
            {
                path: "time-slots",
                component: TimeSlotListComponent,
                name: "admin.settings.timeSlot",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "time_slots",
                }
            },
            {
                path: "sliders",
                component: SliderComponent,
                name: "admin.settings.slider",
                redirect: { name: "admin.settings.slider.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "sliders",
                },
                children: [
                    {
                        path: "list",
                        component: SliderListComponent,
                        name: "admin.settings.slider.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                    {
                        path: "show/:id",
                        component: SliderShowComponent,
                        name: "admin.settings.slider.show",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "view",
                        },
                    },
                ],
            },
            {
                path: "currencies",
                component: CurrencyComponent,
                name: "admin.settings.currency",
                redirect: { name: "admin.settings.currency.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "currencies",
                },
                children: [
                    {
                        path: "list",
                        component: CurrencyListComponent,
                        name: "admin.settings.currency.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                ],
            },
            {
                path: "item-categories",
                component: ItemCategoryComponent,
                name: "admin.settings.itemCategory",
                redirect: { name: "admin.settings.itemCategory.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "item_categories",
                },
                children: [
                    {
                        path: "list",
                        component: ItemCategoryListComponent,
                        name: "admin.settings.itemCategory.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                    {
                        path: "show/:id",
                        name: "admin.settings.itemCategory.show",
                        redirect: (to) => ({
                            name: "admin.items.studio",
                            query: { item_category_id: to.params.id },
                        }),
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "view",
                        },
                    },
                ],
            },
            {
                path: "item-attributes",
                component: ItemAttributeComponent,
                name: "admin.settings.itemAttribute",
                redirect: { name: "admin.settings.itemAttribute.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "item_attributes",
                },
                children: [
                    {
                        path: "list",
                        component: ItemAttributeListComponent,
                        name: "admin.settings.itemAttribute.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                ],
            },
            {
                path: "taxes",
                component: TaxComponent,
                name: "admin.settings.tax",
                redirect: { name: "admin.settings.tax.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "taxes",
                },
                children: [
                    {
                        path: "list",
                        component: TaxListComponent,
                        name: "admin.settings.tax.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                ],
            },
            {
                path: "pages",
                component: PageComponent,
                name: "admin.settings.page",
                redirect: { name: "admin.settings.page.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "pages",
                },
                children: [
                    {
                        path: "list",
                        component: PageListComponent,
                        name: "admin.settings.page.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                    {
                        path: "show/:id",
                        component: PageShowComponent,
                        name: "admin.settings.page.show",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "view",
                        },
                    },
                ],
            },
            {
                path: "role",
                component: RoleComponent,
                name: "admin.settings.role",
                redirect: { name: "admin.settings.role.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "role_permissions",
                },
                children: [
                    {
                        path: "list",
                        component: RoleListComponent,
                        name: "admin.settings.role.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                    {
                        path: "show/:id",
                        component: RoleShowComponent,
                        name: "admin.settings.role.show",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "view",
                        },
                    },
                ],
            },
            {
                path: "languages",
                component: LanguageComponent,
                name: "admin.settings.language",
                redirect: { name: "admin.settings.language.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "languages",
                },
                children: [
                    {
                        path: "list",
                        component: LanguageListComponent,
                        name: "admin.settings.language.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                    {
                        path: "show/:id",
                        component: LanguageShowComponent,
                        name: "admin.settings.language.show",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "view",
                        },
                    },
                ],
            },
            {
                path: "sms-gateway",
                component: SmsGatewayComponent,
                name: "admin.settings.smsGateway",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "sms_gateway",
                },
            },
            {
                path: "payment-gateway",
                component: PaymentGatewayComponent,
                name: "admin.settings.paymentGateway",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "payment_gateway",
                },
            },
            {
                // [Wave F F-2 / Sprint 1C] Per-TPE fee tracking
                path: "payment-terminals",
                component: PaymentTerminalsComponent,
                name: "admin.settings.paymentTerminals",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "payment_terminals",
                },
            },
            {
                // [AUDIT-A P1-1 2026-08-06] Rapports Z NF525 (liste + PDF + rapport X)
                path: "z-reports",
                component: ZReportListComponent,
                name: "admin.settings.zReports",
                meta: {
                    isFrontend: false,
                    auth: true,
                    // [P0 ACCÈS 2026-08-08] Le SPA exigeait « settings », le back-end exige
                    // `pos-manage-fiscal` (ZReportController::…abort_unless). Contradiction
                    // MESURÉE : le Gérant a `pos/manage-fiscal` mais PAS `settings` — le back-end
                    // l'autorisait, le routeur le rejetait avec un toast. Seul l'Admin atteignait
                    // les rapports Z. On aligne l'écran sur la vérité back-end : un droit fiscal
                    // n'a rien à faire derrière le droit « réglages ».
                    permissionUrl: "pos/manage-fiscal",
                    breadcrumb: "z_reports",
                },
            },
            {
                // [AUDIT-A P1-2 2026-08-06] Gestion des imprimantes (CRUD + test)
                path: "printers",
                component: PrintersComponent,
                name: "admin.settings.printers",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "printers",
                },
            },
            {
                path: "license",
                component: LicenseComponent,
                name: "admin.settings.license",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "license",
                }
            },
            {
                path: "notification-alert",
                component: NotificationAlertComponent,
                name: "admin.settings.notificationAlert",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "notification_alert",
                }
            },
            {
                path: "kiosk-machines",
                component: KioskMachineComponent,
                name: "admin.settings.kioskMachines",
                redirect: { name: "admin.settings.kioskMachines.list" },
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "settings",
                    breadcrumb: "kiosk_machines",
                },
                children: [
                    {
                        path: "list",
                        component: KioskMachineListComponent,
                        name: "admin.settings.kioskMachines.list",
                        meta: {
                            isFrontend: false,
                            auth: true,
                            permissionUrl: "settings",
                            breadcrumb: "",
                        },
                    },
                ],
            },
        ],
    },
];
