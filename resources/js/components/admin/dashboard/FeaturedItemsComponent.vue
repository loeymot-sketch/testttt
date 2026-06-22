<template>
    <!-- [micro-ux 2026-06-18] scoped non-fullscreen loader (was full-screen overlay). -->
    <LoadingContentComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header">
                <div class="db-card-title">{{ $t('label.featured_items') }}</div>
            </div>
            <div class="db-card-body">
                <ul class="grid grid-cols-2 sm:grid-cols-4 gap-[18px]">
                    <li class="w-full rounded-xl border border-[#D9DBE9]" v-if="featured_items.length > 0"
                        v-for="featured_item in featured_items" :key="featured_item">
                        <img class="w-full rounded-t-[11px]" :src="featured_item.thumb" alt="product">
                        <!-- [micro-ux 2026-06-18] truncate long names like sibling widgets. -->
                        <h4 class="text-xs p-2 font-medium capitalize overflow-hidden whitespace-nowrap text-ellipsis">{{ featured_item.name }}</h4>
                    </li>
                </ul>
                <!-- [micro-ux 2026-06-18] explicit empty-state instead of a blank card. -->
                <p v-if="!loading.isActive && featured_items.length === 0" class="text-sm text-gray-500 text-center py-6">
                    {{ $t('label.no_data_available') }}
                </p>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingContentComponent from "../components/LoadingContentComponent";
export default {
    name: "FeaturedItemsComponent",
    components: { LoadingContentComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },

            // [micro-ux 2026-06-18] init to [] (was {}) so .length works for the empty-state.
            featured_items: [],
        };
    },
    mounted() {
        this.featuredItems();
    },
    methods: {
        featuredItems: function () {
            this.loading.isActive = true;
            this.$store.dispatch('dashboard/featuredItems').then(res => {
                this.featured_items = res.data.data;
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
    },
}
</script>
