<template>
    <div class="col-12">
        <BreadcrumbComponent />
    </div>

    <LoadingComponent :props="loading" />

    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t("label.promo_flyer") }}</h3>
            </div>

            <div class="db-card-body">
                <!-- Avertissement honnête : imprimer un code que le site refusera
                     serait pire que ne rien imprimer. -->
                <div v-if="loaded && !couponRedemptionEnabled"
                    class="mb-4 rounded border border-amber-400 bg-amber-50 p-3 text-sm text-amber-900">
                    <strong>{{ $t("label.flyer_codes_disabled_title") }}</strong>
                    <div>{{ $t("label.flyer_codes_disabled_body") }}</div>
                </div>

                <form @submit.prevent="createFlyer">
                    <div class="form-row">
                        <div class="form-col-12 sm:form-col-4">
                            <label for="customer_name" class="db-field-title required">
                                {{ $t("label.customer_first_name") }}
                            </label>
                            <input
                                v-model="form.customer_name"
                                id="customer_name"
                                type="text"
                                maxlength="60"
                                autocomplete="off"
                                autocapitalize="words"
                                class="db-field-control text-lg"
                                :placeholder="$t('label.customer_first_name_placeholder')"
                                :class="errors.customer_name ? 'invalid' : ''"
                            />
                            <small class="db-field-alert" v-if="errors.customer_name">
                                {{ errors.customer_name[0] }}
                            </small>
                            <small class="text-slate-500">{{ $t("label.flyer_name_hint") }}</small>
                        </div>

                        <div class="form-col-12 sm:form-col-4">
                            <label class="db-field-title">{{ $t("label.civility") }}</label>
                            <div class="flex gap-2">
                                <button
                                    v-for="c in civilities"
                                    :key="c.value"
                                    type="button"
                                    class="db-btn py-2 flex-1"
                                    :class="form.civility === c.value ? 'text-white bg-primary' : ''"
                                    @click="form.civility = c.value"
                                >{{ c.label }}</button>
                            </div>
                        </div>

                        <div class="form-col-12 sm:form-col-4 flex items-end">
                            <button
                                type="submit"
                                class="db-btn py-3 w-full text-white bg-primary text-lg"
                                :disabled="busy || !form.customer_name.trim()"
                            >
                                {{ busy ? $t("label.sending") : $t("button.print_flyer") }}
                            </button>
                        </div>
                    </div>
                </form>

                <div v-if="lastFlyer"
                    class="mt-5 rounded border border-emerald-400 bg-emerald-50 p-4">
                    <div class="text-sm text-emerald-900">{{ $t("label.flyer_sent_to_pos") }}</div>
                    <div class="mt-1 text-2xl font-bold tracking-wider text-emerald-950">
                        {{ lastFlyer.code }}
                    </div>
                    <div class="text-sm text-emerald-900">
                        {{ lastFlyer.customer_name }} · −{{ lastFlyer.discount }}%
                        <span v-if="lastFlyer.valid_until"> · {{ $t("label.valid_until") }} {{ lastFlyer.valid_until }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t("label.flyer_history") }}</h3>
                <button type="button" class="db-btn py-2 text-white bg-primary" @click="fetchHistory">
                    <i class="lab lab-reset"></i>
                    <span>{{ $t("button.refresh") }}</span>
                </button>
            </div>
            <div class="db-card-body">
                <div v-if="flyers.length === 0" class="py-4 text-center text-slate-500">
                    {{ $t("label.flyer_history_empty") }}
                </div>
                <div v-else class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ $t("label.customer") }}</th>
                                <th>{{ $t("label.code") }}</th>
                                <th>{{ $t("label.status") }}</th>
                                <th>{{ $t("label.date") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="flyer in flyers" :key="flyer.id">
                                <td>{{ flyer.customer_name }}</td>
                                <td class="font-mono font-bold">{{ flyer.code }}</td>
                                <td>
                                    <span :class="statusClass(flyer.status)">{{ statusLabel(flyer) }}</span>
                                    <div v-if="flyer.last_error" class="text-xs text-rose-700">
                                        {{ flyer.last_error }}
                                    </div>
                                </td>
                                <td>{{ formatDate(flyer.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [FLYER PROMO UBER 2026-08-07] Écran de saisie du ticket promotionnel.
 *
 * Conçu pour être utilisé DEBOUT, SUR UN TÉLÉPHONE, entre deux commandes :
 * un seul champ, un gros bouton, et le code affiché en grand juste après pour
 * pouvoir le dicter si le ticket ne sort pas.
 *
 * L'impression elle-même n'a pas lieu ici : le serveur ne peut pas joindre
 * l'imprimante du restaurant. L'ordre est déposé en file et c'est l'écran de la
 * caisse qui l'imprime (voir PromoFlyerPrintListener).
 */
import BreadcrumbComponent from "../components/BreadcrumbComponent";
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";

export default {
    name: "PromoFlyerComponent",
    components: { BreadcrumbComponent, LoadingComponent },
    data() {
        return {
            loading: { isActive: false },
            busy: false,
            loaded: false,
            couponRedemptionEnabled: true,
            form: { customer_name: "", civility: "" },
            civilities: [
                { value: "", label: "—" },
                { value: "Mme", label: "Mme" },
                { value: "M.", label: "M." },
            ],
            errors: {},
            lastFlyer: null,
            flyers: [],
        };
    },
    mounted() {
        this.fetchSettings();
        this.fetchHistory();
    },
    methods: {
        fetchSettings() {
            this.$store.dispatch("promoFlyerSettings")
                .then((res) => {
                    this.couponRedemptionEnabled = res.coupon_redemption_enabled !== false;
                    this.loaded = true;
                })
                .catch(() => { this.loaded = true; });
        },

        fetchHistory() {
            this.loading.isActive = true;
            this.$store.dispatch("promoFlyerList")
                .then((res) => { this.flyers = res.flyers || []; })
                .catch((err) => alertService.error(err?.response?.data?.message))
                .finally(() => { this.loading.isActive = false; });
        },

        createFlyer() {
            const name = (this.form.customer_name || "").trim();
            if (name === "" || this.busy) return;

            this.busy = true;
            this.errors = {};

            this.$store.dispatch("promoFlyerCreate", { customer_name: name, civility: this.form.civility })
                .then((res) => {
                    this.lastFlyer = res.flyer;
                    this.form.customer_name = "";
                    this.form.civility = "";
                    alertService.success(res.message);
                    this.fetchHistory();
                })
                .catch((err) => {
                    this.errors = err?.response?.data?.errors || {};
                    alertService.error(err?.response?.data?.message);
                })
                .finally(() => { this.busy = false; });
        },

        statusLabel(flyer) {
            if (flyer.status === "printed") return this.$t("label.flyer_printed");
            if (flyer.status === "failed") return this.$t("label.flyer_failed");
            return this.$t("label.flyer_pending");
        },

        statusClass(status) {
            if (status === "printed") return "text-emerald-700 font-medium";
            if (status === "failed") return "text-rose-700 font-medium";
            return "text-amber-700 font-medium";
        },

        formatDate(value) {
            if (!value) return "—";
            try {
                return new Date(value).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" });
            } catch (_) {
                return value;
            }
        },
    },
};
</script>
