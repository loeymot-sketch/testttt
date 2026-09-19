<template>
    <LoadingComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header">
                <div class="db-card-title">{{ $t('label.featured_items') }}</div>
            </div>
            <div class="db-card-body">
                <!--
                    [G5 · T5.1 2026-09-03] Deux défauts se cumulaient ici :
                      1. `featured_items` partait à `{}` — un OBJET — donc `.length` valait
                         `undefined` et la carte se rendait vide avant toute réponse.
                      2. Le `.catch` ne faisait que couper le voile de chargement : 403, 500
                         et « aucun article mis en avant » produisaient LE MÊME pixel.
                    Une carte vide qui peut vouloir dire deux choses opposées ne dit rien.
                -->
                <p v-if="fetchError" class="text-sm text-red-600" data-testid="featured-items-error">
                    {{ $t('label.featured_items_error') }}
                </p>
                <p v-else-if="loaded && featured_items.length === 0" class="text-sm text-gray-500"
                   data-testid="featured-items-empty">
                    {{ $t('label.featured_items_empty') }}
                </p>
                <ul v-else class="grid grid-cols-2 sm:grid-cols-4 gap-[18px]">
                    <!-- Clé = l'identifiant du produit, jamais l'objet lui-même. -->
                    <li class="w-full rounded-xl border border-[#D9DBE9]"
                        v-for="featured_item in featured_items"
                        :key="featured_item.id"
                        :data-testid="`featured-item-${featured_item.id}`">
                        <img class="w-full rounded-t-[11px]" :src="featured_item.thumb" alt="product">
                        <h4 class="text-xs p-2 font-medium capitalize">{{ featured_item.name }}</h4>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
export default {
    name: "FeaturedItemsComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },

            // [G5 · T5.1] Tableau, pas objet : `.length` doit être lisible dès le montage.
            featured_items: [],
            loaded: false,
            fetchError: false,
        };
    },
    mounted() {
        this.featuredItems();
    },
    methods: {
        featuredItems: function () {
            this.loading.isActive = true;
            return this.$store.dispatch('dashboard/featuredItems').then(res => {
                this.featured_items = res.data.data || [];
                this.fetchError = false;
                this.loaded = true;
                this.loading.isActive = false;
            }).catch(() => {
                // [G5 · T5.1] L'échec est CONSERVÉ, pas seulement absorbé : sans ce
                // drapeau, une 403 se lisait « aucun article mis en avant ».
                this.featured_items = [];
                this.fetchError = true;
                this.loaded = true;
                this.loading.isActive = false;
            });
        },
    },
}
</script>
