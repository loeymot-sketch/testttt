import PosComponent from "../../components/admin/pos/PosComponent";
import FloorplanComponent from "../../components/admin/pos/FloorplanComponent";

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
