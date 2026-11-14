<template>
    <!-- [LIVRAISONS-PAGE 2026-10-23] « Livraisons » : les commandes à livrer, regroupées en départs de 30 min, avec pour chaque arrêt la
         bascule nos livreurs ⇄ Uber. C'est la planche de la caisse (PosDeliveryBoard) posée en page, pour qu'elle s'ouvre depuis la barre
         latérale sans passer par la caisse. -->
    <div class="row">
        <div class="col-12">
            <BreadcrumbComponent />
        </div>
        <div class="col-12">
            <CaisseSecondaryNav current="livraisons" />
        </div>
        <div class="col-12 pb-4">
            <PosDeliveryBoard :page="true" />
        </div>
    </div>
</template>

<script>
import BreadcrumbComponent from "../components/BreadcrumbComponent";
import CaisseSecondaryNav from "./CaisseSecondaryNav.vue";
import PosDeliveryBoard from "./PosDeliveryBoard.vue";

export default {
    name: "PosDeliveryPage",
    components: { BreadcrumbComponent, CaisseSecondaryNav, PosDeliveryBoard },
};
</script>
