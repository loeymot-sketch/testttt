<template>
    <LoadingComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header">
                <div class="db-card-title">{{ $t('label.most_popular_items') }}</div>
            </div>
            <div class="db-card-body">
                <!--
                    [G5 · T5.1 2026-09-03] Même patron défectueux que FeaturedItemsComponent —
                    et c'est ce qui le rend grave : ce n'est pas un accident isolé, c'est une
                    copie. `popular_items` partait à `{}` (donc `.length` = `undefined`) et le
                    `.catch` ne conservait rien de l'échec. Le classement des ventes est
                    précisément la carte qu'on regarde pour décider quoi mettre en avant : la
                    voir vide un jour de panne coûte une mauvaise décision.
                -->
                <p v-if="fetchError" class="text-sm text-red-600" data-testid="popular-items-error">
                    {{ $t('label.most_popular_items_error') }}
                </p>
                <p v-else-if="loaded && popular_items.length === 0" class="text-sm text-gray-500"
                   data-testid="popular-items-empty">
                    {{ $t('label.most_popular_items_empty') }}
                </p>
                <ul v-else class="grid grid-cols-1 sm:grid-cols-2 gap-[18px]">
                    <li class="w-full flex rounded-xl border border-[#D9DBE9]"
                        v-for="popular_item in popular_items"
                        :key="popular_item.id"
                        :data-testid="`popular-item-${popular_item.id}`">
                        <img class="flex w-20 h-20 object-cover rounded-l-[11px]" :src="popular_item.thumb" alt="product">
                        <div class="py-2 px-3 flex flex-col justify-between overflow-hidden">
                            <h4 class="text-sm overflow-hidden whitespace-nowrap text-ellipsis font-medium capitalize">
                                {{ popular_item.name }}</h4>
                            <h5 class="text-xs font-medium capitalize text-sky-800">
                                {{ popular_item.category_name }}
                            </h5>
                            <h6 class="text-sm font-bold">{{ popular_item.currency_price }}</h6>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
export default {
    name: "MostPopularItemsComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },
            // [G5 · T5.1] Tableau, pas objet.
            popular_items: [],
            loaded: false,
            fetchError: false,
        };
    },
    mounted() {
        this.popularItems();
    },
    methods: {
        popularItems: function () {
            this.loading.isActive = true;
            return this.$store.dispatch('dashboard/mostPopularItems').then(res => {
                this.popular_items = res.data.data || [];
                this.fetchError = false;
                this.loaded = true;
                this.loading.isActive = false;
            }).catch(() => {
                // [G5 · T5.1] Un classement qu'on n'a pas pu lire n'est pas un classement vide.
                this.popular_items = [];
                this.fetchError = true;
                this.loaded = true;
                this.loading.isActive = false;
            });
        },
    },
}
</script>
