<template>
    <!-- [micro-ux 2026-06-18] scoped non-fullscreen loader (was full-screen overlay). -->
    <LoadingContentComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header">
                <div class="db-card-title">{{ $t('label.most_popular_items') }}</div>
            </div>
            <div class="db-card-body">
                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-[18px]">
                    <li class="w-full flex rounded-xl border border-[#D9DBE9]" v-if="popular_items.length > 0"
                        v-for="popular_item in popular_items" :key="popular_item">
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
                <!-- [micro-ux 2026-06-18] explicit empty-state instead of a blank card. -->
                <p v-if="!loading.isActive && popular_items.length === 0" class="text-sm text-gray-500 text-center py-6">
                    {{ $t('label.no_data_available') }}
                </p>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingContentComponent from "../components/LoadingContentComponent";
export default {
    name: "MostPopularItemsComponent",
    components: { LoadingContentComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },
            // [micro-ux 2026-06-18] init to [] (was {}) so .length works for the empty-state.
            popular_items: [],
        };
    },
    mounted() {
        this.popularItems();
    },
    methods: {
        popularItems: function () {
            this.loading.isActive = true;
            this.$store.dispatch('dashboard/mostPopularItems').then(res => {
                this.popular_items = res.data.data;
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
    },
}
</script>
