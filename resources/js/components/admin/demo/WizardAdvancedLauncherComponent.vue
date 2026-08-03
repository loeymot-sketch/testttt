<template>
    <section class="db-card p-5" data-testid="wizard-advanced-launcher">
        <div class="flex flex-col gap-2 mb-5">
            <span class="text-xs font-semibold uppercase tracking-wide text-amber-700">
                Demo V2
            </span>
            <h1 class="text-2xl font-bold text-heading">
                {{ $t('demo_wizard_advanced.title') }}
            </h1>
        </div>

        <div
            class="rounded border border-amber-300 bg-amber-50 text-amber-900 p-4 mb-5"
            role="alert"
        >
            {{ $t('demo_wizard_advanced.warning') }}
        </div>

        <form class="grid gap-4 md:grid-cols-[1fr_auto]" @submit.prevent="openSelectedItem">
            <div>
                <label class="db-field-title" for="wizard-demo-item-search">
                    {{ $t('demo_wizard_advanced.product_label') }}
                </label>
                <input
                    id="wizard-demo-item-search"
                    v-model="search"
                    class="db-field-control"
                    list="wizard-demo-items"
                    :placeholder="$t('demo_wizard_advanced.product_placeholder')"
                    @input="matchSelectedItem"
                >
                <datalist id="wizard-demo-items">
                    <option
                        v-for="item in items"
                        :key="item.id"
                        :value="itemLabel(item)"
                    />
                </datalist>
            </div>
            <button
                type="submit"
                class="db-btn h-11 self-end text-white bg-primary"
                :disabled="!selectedItemId || loading"
                :aria-busy="loading ? 'true' : 'false'"
            >
                {{ $t('demo_wizard_advanced.open_button') }}
            </button>
        </form>

        <p v-if="loadError" class="text-sm text-danger mt-3" role="alert" aria-live="polite">
            {{ loadError }}
        </p>

        <router-link class="inline-flex items-center gap-2 text-primary mt-6" :to="{ name: 'admin.items.studio' }">
            <i class="fa-solid fa-arrow-left text-xs"></i>
            {{ $t('demo_wizard_advanced.back_to_studio') }}
        </router-link>
    </section>
</template>

<script>
import axios from 'axios';

export default {
    name: 'WizardAdvancedLauncherComponent',
    data() {
        return {
            loading: false,
            search: '',
            selectedItemId: null,
            items: [],
            loadError: '',
        };
    },
    mounted() {
        this.loadItems();
    },
    methods: {
        async loadItems() {
            this.loading = true;
            this.loadError = '';
            try {
                const response = await axios.get('admin/item', {
                    params: { per_page: 100, vuex: false },
                });
                this.items = Array.isArray(response.data?.data) ? response.data.data : [];
            } catch (error) {
                this.loadError = this.$t('demo_wizard_advanced.load_error');
            } finally {
                this.loading = false;
            }
        },
        itemLabel(item) {
            return item?.name ? `${item.name} (#${item.id})` : `#${item?.id}`;
        },
        matchSelectedItem() {
            const match = this.items.find(item => this.itemLabel(item) === this.search);
            this.selectedItemId = match?.id || null;
        },
        openSelectedItem() {
            if (!this.selectedItemId) {
                return;
            }

            this.$router.push({
                name: 'admin.items.composer',
                params: { id: this.selectedItemId },
            });
        },
    },
};
</script>
