<!--
    ⚠️ COMPOSANT DORMANT — vérifié le 2026-09-03.

    Ce composant n'est monté NULLE PART. Il n'apparaît dans aucun autre fichier de
    `resources/js` que celui-ci, et le dépôt ne charge aucun composant automatiquement
    (ni `require.context`, ni `import.meta.glob`). Aucun utilisateur ne le voit.

    Sa route API (`top-customers`) existe pourtant, elle est gardée, testée et maintenue : le
    coût backend est payé pour un écran que personne n'ouvre.

    Trois issues, et c'est une décision PRODUIT, pas technique :
      (a) le monter sur le tableau de bord — il faut alors lui écrire ses bancs, comme les
          six autres widgets en ont reçu le 2026-09-03 (succès / vide / 403 / 500 / délai) ;
      (b) le déplacer vers une page de rapports clients ;
      (c) le supprimer, ET trancher séparément le sort de sa route.

    Ne rien décider n'est pas neutre : la route continue d'être maintenue.
    Signalé une première fois dans `plans/GOAL_ONB07_..._2026-08-26.md:143` sans être tranché.
-->
<template>
    <LoadingComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t('label.top_customers') }}</h3>
            </div>
            <div class="db-card-body">
                <ul class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <li class="w-full rounded-xl pt-3 border border-[#D9DBE9]" v-if="top_customers.length > 0"
                        v-for="top_customer in top_customers" :key="top_customer">
                        <img class="w-12 h-12 mx-auto rounded-full mb-2" :src="top_customer.image" alt="avatar">
                        <h4
                            class="text-sm px-3 text-center font-medium capitalize mb-4 whitespace-nowrap overflow-hidden text-ellipsis">
                            {{ top_customer.name }}</h4>
                        <p
                            class="text-xs w-full tracking-wide text-center py-1 rounded-t rounded-b-[11px] text-white bg-[#008BBA]">
                            {{ top_customer.order }} {{ $t('label.orders') }}</p>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
export default {
    name: "TopCustomersComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },

            top_customers: {},
        };
    },
    mounted() {
        this.topCustomers();
    },
    methods: {
        topCustomers: function () {
            this.loading.isActive = true;
            this.$store.dispatch('dashboard/topCustomers').then(res => {
                this.top_customers = res.data.data;
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
    },
}
</script>