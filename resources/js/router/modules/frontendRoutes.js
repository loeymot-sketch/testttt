// [STOREFRONT-DELETE 2026-06-25] Pages vitrine Home/Menu/Offres SUPPRIMÉES
// (V1 LOCAL Le Cayenne = aucune commande en ligne, 0 valeur). Les composants
// HomeComponent/MenuComponent/OffersComponent/OffersItemComponent sont supprimés ;
// leurs routes deviennent de simples redirections vers /login (ça garde valides
// les ~15 références route-name `frontend.home`/`frontend.menu`/`frontend.offers`
// dispersées dans les flux auth/compte, sans réintroduire de page vitrine).
// Combiné à STAFF_ONLY_MODE=true (config/features.php) qui masque tout le chrome
// storefront (navbar/panier/footer) et renvoie les fallbacks vers l'admin.
import PageComponent from "../../components/frontend/page/PageComponent";
import EditProfileComponent from "../../components/frontend/account/editProfile/EditProfileComponent";
import MyOrderComponent from "../../components/frontend/account/myOrder/MyOrderComponent";
import OrderDetailsComponent from "../../components/frontend/account/myOrder/OrderDetailsComponent";
import ChatComponent from "../../components/frontend/account/chat/ChatComponent";
import AddressComponent from "../../components/frontend/account/address/AddressComponent";
import ChangePasswordComponent from "../../components/frontend/account/changePassword/ChangePasswordComponent";
import CheckoutComponent from "../../components/frontend/checkout/CheckoutComponent";
import SearchItemComponent from "../../components/frontend/search/SearchItemComponent";

export default [
    // [STOREFRONT-DELETE 2026-06-25] Pages Home/Menu/Offres supprimées → redirigées
    // vers l'admin (login). Plus aucun composant vitrine rendu.
    { path: "/home", redirect: "/login", name: "frontend.home" },
    { path: "/menu", redirect: "/login", name: "frontend.menu" },
    { path: "/offers", redirect: "/login", name: "frontend.offers" },
    { path: "/offers/:slug", redirect: "/login", name: "frontend.offers.item" },
    {
        path: "/page/:slug",
        component: PageComponent,
        name: "frontend.page",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
    {
        path: "/edit-profile",
        component: EditProfileComponent,
        name: "frontend.editProfile",
        meta: {
            isFrontend: true,
            auth: true,
        },
    },
    {
        path: "/my-orders",
        component: MyOrderComponent,
        name: "frontend.myOrder",
        meta: {
            isFrontend: true,
            auth: true,
        },
    },
    {
        path: "/my-orders/:id",
        component: OrderDetailsComponent,
        name: "frontend.myOrder.details",
        meta: {
            isFrontend: true,
            auth: true,
        },
    },
    {
        path: "/chat",
        component: ChatComponent,
        name: "frontend.chat",
        meta: {
            isFrontend: true,
            auth: true,
        },
    },
    {
        path: "/address",
        component: AddressComponent,
        name: "frontend.address",
        meta: {
            isFrontend: true,
            auth: true,
        },
    },
    {
        path: "/change-password",
        component: ChangePasswordComponent,
        name: "frontend.changePassword",
        meta: {
            isFrontend: true,
            auth: true,
        },
    },
    {
        path: "/checkout",
        component: CheckoutComponent,
        name: "frontend.checkout",
        meta: {
            isFrontend: true,
            auth: true,
        },
    },
    {
        path: "/search",
        component: SearchItemComponent,
        name: "frontend.search",
        meta: {
            isFrontend: true,
            auth: false,
        },
    },
];
