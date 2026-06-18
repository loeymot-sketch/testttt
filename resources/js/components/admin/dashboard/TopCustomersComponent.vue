<template>
    <!-- [micro-ux 2026-06-18] scoped non-fullscreen loader (was full-screen overlay). -->
    <LoadingContentComponent :props="loading" />
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
                <!-- [micro-ux 2026-06-18] explicit empty-state instead of a blank card. -->
                <p v-if="!loading.isActive && top_customers.length === 0" class="text-sm text-gray-500 text-center py-6">
                    {{ $t('label.no_data_available') }}
                </p>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingContentComponent from "../components/LoadingContentComponent";
export default {
    name: "TopCustomersComponent",
    components: { LoadingContentComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },

            // [micro-ux 2026-06-18] init to [] (was {}) so .length works for the empty-state.
            top_customers: [],
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