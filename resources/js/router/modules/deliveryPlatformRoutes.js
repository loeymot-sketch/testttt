import DeliveryPlatformsPage from "../../components/admin/deliveryPlatforms/DeliveryPlatformsPage.vue";

/**
 * [PARALLEL-TRACK-1.4 / Phase 4]
 *
 * Admin route for the Delivery Platforms management page.
 * Re-uses the same `permissionUrl: settings` gate as the rest of
 * the setup-style admin pages (KioskMachine, OrderSetup, …) so the
 * existing menu permission seeders gate it without changes.
 */
export default [
    {
        path: "/admin/delivery-platforms",
        component: DeliveryPlatformsPage,
        name: "admin.deliveryPlatforms",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "settings",
            breadcrumb: "delivery_platforms",
        },
    },
];
