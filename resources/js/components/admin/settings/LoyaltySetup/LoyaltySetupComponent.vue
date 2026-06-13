<template>
    <LoadingComponent :props="loading" />
    <div id="loyalty_setup" class="db-card db-tab-div active">
        <div class="db-card-header">
            <h3 class="db-card-title">{{ $t('menu.loyalty_setup') }}</h3>
        </div>
        <div class="db-card-body">
            <form @submit.prevent="save">
                <fieldset class="p-4 mb-6 border border-[#DBDEE0]">
                    <legend class="py-1.5 px-4 text-base font-semibold capitalize border border-[#DBDEE0] text-primary">
                        {{ $t('label.loyalty_points_config') }}
                    </legend>
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-4">
                            <label for="loyalty_points_per_euro" class="db-field-title required">
                                {{ $t('label.loyalty_points_per_euro') }}
                            </label>
                            <input
                                v-model.number="form.loyalty_points_per_euro"
                                v-bind:class="errors.loyalty_points_per_euro ? 'invalid' : ''"
                                type="number"
                                id="loyalty_points_per_euro"
                                class="db-field-control"
                                min="0"
                                max="1000"
                            />
                            <small class="db-field-alert" v-if="errors.loyalty_points_per_euro">{{ errors.loyalty_points_per_euro[0] }}</small>
                            <p class="text-xs text-gray-400 mt-1">{{ $t('label.loyalty_points_per_euro_hint') }}</p>
                        </div>

                        <div class="form-col-12 sm:form-col-4">
                            <label for="loyalty_points_for_1_euro_discount" class="db-field-title required">
                                {{ $t('label.loyalty_points_for_1_euro_discount') }}
                            </label>
                            <input
                                v-model.number="form.loyalty_points_for_1_euro_discount"
                                v-bind:class="errors.loyalty_points_for_1_euro_discount ? 'invalid' : ''"
                                type="number"
                                id="loyalty_points_for_1_euro_discount"
                                class="db-field-control"
                                min="1"
                                max="10000"
                            />
                            <small class="db-field-alert" v-if="errors.loyalty_points_for_1_euro_discount">{{ errors.loyalty_points_for_1_euro_discount[0] }}</small>
                            <p class="text-xs text-gray-400 mt-1">{{ $t('label.loyalty_points_for_1_euro_discount_hint') }}</p>
                        </div>

                        <div class="form-col-12 sm:form-col-4">
                            <label for="loyalty_min_redeem_points" class="db-field-title required">
                                {{ $t('label.loyalty_min_redeem_points') }}
                            </label>
                            <input
                                v-model.number="form.loyalty_min_redeem_points"
                                v-bind:class="errors.loyalty_min_redeem_points ? 'invalid' : ''"
                                type="number"
                                id="loyalty_min_redeem_points"
                                class="db-field-control"
                                min="0"
                                max="10000"
                            />
                            <small class="db-field-alert" v-if="errors.loyalty_min_redeem_points">{{ errors.loyalty_min_redeem_points[0] }}</small>
                            <p class="text-xs text-gray-400 mt-1">{{ $t('label.loyalty_min_redeem_points_hint') }}</p>
                        </div>
                    </div>

                    <!-- Live preview -->
                    <div v-if="form.loyalty_points_per_euro > 0 && form.loyalty_points_for_1_euro_discount > 0" class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                        <p class="text-sm text-blue-700 font-medium">{{ $t('label.loyalty_preview') }}</p>
                        <p class="text-xs text-blue-600 mt-1">
                            {{ appService.currencyFormat(10) }} d'achat = {{ form.loyalty_points_per_euro * 10 }} pts
                            → {{ discountPreview }} de réduction
                        </p>
                    </div>
                </fieldset>

                <button type="submit" class="db-btn text-white bg-primary">
                    <i class="lab lab-save"></i>
                    <span>{{ $t('button.save') }}</span>
                </button>
            </form>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";
import appService from "../../../../services/appService";

export default {
    name: "LoyaltySetupComponent",
    components: { LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            // [DB4-F3 GOAL 2026-06-12] defaults alignés sur le canon L1/D11
            // (1 pt/€ · 100 pts = 1 € · min 100) — l'ancien 10/100/50 était le
            // barème 10× éradiqué : affiché ET enregistrable si le fetch échouait.
            form: {
                loyalty_points_per_euro:            1,
                loyalty_points_for_1_euro_discount: 100,
                loyalty_min_redeem_points:          100,
            },
            errors: {},
            // [CENTRAL-C1-P3-01] exposé au template pour le rendu FR EUR de
            // l'aperçu live (appService.currencyFormat → "0,10 €").
            appService: appService,
        };
    },
    computed: {
        // [CENTRAL-C1-P3-01] Réduction obtenue pour 10 € d'achat, rendue en FR
        // EUR canonique ("0,10 €") au lieu de l'ancien toFixed(2)+€ collé en-US.
        discountPreview() {
            const perEuro = Number(this.form.loyalty_points_per_euro) || 0;
            const ptsFor1Euro = Number(this.form.loyalty_points_for_1_euro_discount) || 0;
            if (perEuro <= 0 || ptsFor1Euro <= 0) {
                return appService.currencyFormat(0);
            }
            return appService.currencyFormat((perEuro * 10) / ptsFor1Euro);
        },
    },
    mounted() {
        try {
            this.loading.isActive = true;
            this.$store.dispatch('loyaltySetup/lists').then(res => {
                const d = res.data.data;
                this.form = {
                    loyalty_points_per_euro:            d.loyalty_points_per_euro            ?? 1,
                    loyalty_points_for_1_euro_discount: d.loyalty_points_for_1_euro_discount ?? 100,
                    loyalty_min_redeem_points:          d.loyalty_min_redeem_points          ?? 100,
                };
                this.loading.isActive = false;
            }).catch((err) => {
                // [DB4-F3] échec de chargement plus jamais avalé en silence —
                // l'admin verrait sinon des defaults trompeurs enregistrables.
                this.loading.isActive = false;
                alertService.error(err?.response?.data?.message ?? this.$t('message.something_wrong'));
            });
        } catch {
            this.loading.isActive = false;
        }
    },
    methods: {
        save() {
            try {
                this.loading.isActive = true;
                this.$store.dispatch('loyaltySetup/save', this.form).then((res) => {
                    this.loading.isActive = false;
                    alertService.successFlip(res.config.method === 'put' ?? 0, this.$t('menu.loyalty_setup'));
                    this.errors = {};
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = err.response?.data?.errors ?? {};
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        }
    }
};
</script>
