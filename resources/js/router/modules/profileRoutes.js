// [POS-V4 W1-C 2026-04-26] Lazy-load all SFC imports into webpack chunk "admin-shell".
// Pattern identical to posRoutes.js (W1-A) and kioskRoutes.js. Converted by
// tools/refactor/lazy_router_modules.mjs. Goal: reduce app.js first-paint
// (see reports/baseline/POS_V4_PERF_HISTORY.md — cross-cycle SSOT).
const ProfileEditProfileComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/profile/ProfileEditProfileComponent");
const ProfileChangePasswordComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/profile/ProfileChangePasswordComponent");
// [MULTI-DEVICE 2026-08-07] Gestion des terminaux connectés. Placé sous le
// profil, sans `permissionUrl` : comme le changement de mot de passe, couper
// l'accès d'un de SES appareils est un contrôle de sécurité personnel qui ne
// doit dépendre d'aucun droit annexe.
const ProfileDevicesComponent = () => import(/* webpackChunkName: "admin-shell" */ "../../components/admin/profile/ProfileDevicesComponent");
export default [
    {
        path: "/admin/profile/edit-profile",
        component: ProfileEditProfileComponent,
        name: "admin.profile.editProfile",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "",
            breadcrumb: "edit_profile",
        },
    },
    {
        path: "/admin/profile/change-password",
        component: ProfileChangePasswordComponent,
        name: "admin.profile.changePassword",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "",
            breadcrumb: "change_password",
        },
    },
    {
        path: "/admin/profile/devices",
        component: ProfileDevicesComponent,
        name: "admin.profile.devices",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "",
            breadcrumb: "connected_devices",
        },
    }
];
