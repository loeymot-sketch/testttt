<template>
    <LoadingComponent :props="loading" />
    <div class="col-12 xl:col-6">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t('label.sales_summary') }}</h3>
                <div id="sales-range" class="cursor-pointer flex items-center gap-3 custom-datepicker">
                    <label for="dp-input-salesSummaryDate" class="sr-only">{{ $t('label.date') }}</label>
                    <Datepicker uid="salesSummaryDate" name="salesSummaryDate" hideInputIcon autoApply :enableTimePicker="false" utc="false"
                        @update:modelValue="salesSummary" v-model="date" range :preset-ranges="presetRanges"
                        :aria-labels="{ input: $t('label.date') }">
                        <template #yearly="{ label, range, presetDateRange }">
                            <button type="button" class="dashboard-date-preset w-full px-3 py-2 text-left rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" @click="presetDateRange(range)">{{ label }}</button>
                        </template>
                    </Datepicker>
                    <i class="lab lab-calendar lab-font-size-24 lab-color-pink" aria-hidden="true"></i>
                </div>
            </div>
            <div class="db-card-body">
                <!--
                    [G5 · T5.1 2026-09-03] `total_sales` et `avg_per_day` partaient à `null`
                    (rendu comme du blanc par Vue) et `options` restait `null`, ce qui masque
                    entièrement la courbe (`v-if="options"`). Le `.catch` ne conservait rien
                    de l'échec.
                    C'est la carte du CHIFFRE D'AFFAIRES. La voir vide, un exploitant conclut
                    à une journée creuse, pas à un tableau de bord aveugle — et c'est une
                    conclusion sur laquelle on prend des décisions commerciales.
                    Aggravant : au changement de période, l'échec laissait la COURBE
                    PRÉCÉDENTE affichée. On croyait lire le mois dernier, on lisait encore le
                    mois courant.
                -->
                <p v-if="fetchError" class="text-sm text-red-600 mb-3" data-testid="sales-summary-error">
                    {{ $t('label.sales_summary_error') }}
                </p>
                <ul class="flex gap-11">
                    <li>
                        <div class="flex items-center gap-2.5">
                            <i class="lab lab-sale-summary lab-font-size-20 lab-font-color-2" aria-hidden="true"></i>
                            <h3 class="font-bold text-[22px] leading-[34px]" data-testid="sales-summary-total">{{ montant(total_sales) }}</h3>
                        </div>
                        <p class="text-xs capitalize">{{ $t("label.total_sales") }}</p>
                    </li>
                    <li>
                        <div class="flex items-center gap-2.5">
                            <i class="lab lab-sale-summary lab-font-size-20 lab-font-color-2" aria-hidden="true"></i>
                            <h3 class="font-bold text-[22px] leading-[34px]" data-testid="sales-summary-avg">{{ montant(avg_per_day) }}</h3>
                        </div>
                        <p class="text-xs capitalize">{{ $t("label.avg_sales_per_day") }}</p>
                    </li>
                </ul>

                <apexchart height="188" v-if="options" :options="options" :series="options.series"></apexchart>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import { endOfMonth, startOfMonth, subMonths } from 'date-fns';

export default {
    name: "SalesSummaryComponent",
    components: { LoadingComponent, Datepicker },
    data() {
        return {
            loading: {
                isActive: false,
            },
            date: null,
            first_date: null,
            last_date: null,
            total_sales: null,
            avg_per_day: null,
            // [G5 · T5.1] Une panne doit être DISCERNABLE d'une période sans vente.
            fetchError: false,
            // [REPLAN_7 2026-08-24] `slot` sur CHAQUE préréglage — sinon vue-datepicker rend
            // sa propre `<div class="dp__preset_range">`, ni focalisable ni activable au
            // clavier. Sans ce marqueur, le `<template #yearly>` accessible juste au-dessus
            // ne s'appliquait qu'à l'unique entrée démo du template vendeur : 4 préréglages
            // sur 5 restaient des div muettes, et la sentinelle source ne pouvait pas le voir.
            presetRanges: [
                { label: 'Aujourd’hui', range: [new Date(), new Date()], slot: 'yearly' },
                { label: 'Ce mois', range: [startOfMonth(new Date()), endOfMonth(new Date())], slot: 'yearly' },
                {
                    label: 'Mois dernier',
                    range: [startOfMonth(subMonths(new Date(), 1)), endOfMonth(subMonths(new Date(), 1))],
                    slot: 'yearly',
                },
            ],
            options: null
        };
    },
    mounted() {
        const date = new Date();
        const startDate = new Date(date.getFullYear(), date.getMonth(), 1);
        const endDate = new Date(date.getFullYear(), date.getMonth() + 1, 0);
        this.date = [startDate, endDate];
        this.salesSummary();
    },
    methods: {
        /**
         * [G5 · T5.1] Sur échec, le montant affiche « — » : ni un chiffre faux, ni du
         * blanc. Une période réellement sans vente renvoie « 0,00 € » et l'affiche.
         */
        montant: function (valeur) {
            return this.fetchError ? '—' : valeur;
        },
        salesSummary: function (e) {
            let date = {
                first_date: '',
                last_date: '',
            };
            if (e) {
                this.first_date = e[0];
                this.last_date = e[1];
                date.first_date = e[0];
                date.last_date = e[1];
            }

            this.loading.isActive = true;
            this.$store.dispatch("dashboard/salesSummary", date).then((res) => {
                this.total_sales = res.data.data.total_sales;
                this.avg_per_day = res.data.data.avg_per_day;
                this.options = {
                    series: [{
                        name: this.$t('label.sales'),
                        data: res.data.data.per_day_sales,
                    }],
                    chart: {
                        type: 'area',
                        height: 250,
                        fontFamily: 'inherit',
                        parentHeightOffset: 0,
                        zoom: { enabled: false },
                        toolbar: { show: false, },
                    },
                    xaxis: {
                        // [2026-09-02] La courbe n'avait AUCUNE date en abscisse : on
                        // voyait des points sans savoir de quel jour ils parlaient. Le
                        // serveur publie désormais les jours à côté des montants.
                        type: 'category',
                        categories: res.data.data.per_day_labels || [],
                        tooltip: { enabled: false },
                        axisBorder: { show: false },
                        labels: {
                            // Une période d'un an rendrait 365 étiquettes illisibles :
                            // ApexCharts en espace automatiquement, on borne juste le format.
                            formatter: (v) => (typeof v === 'string' ? v.slice(5) : v),
                        },
                    },
                    stroke: {
                        width: 3,
                        lineCap: "round",
                        curve: "smooth",
                    },
                    colors: ["#FF4F99"],
                    grid: { show: false },
                    yaxis: { show: false },
                    dataLabels: { enabled: false, },
                };

                this.fetchError = false;
                this.loading.isActive = false;
            }).catch(() => {
                // [G5 · T5.1] Un chiffre d'affaires non lu n'est pas un chiffre d'affaires nul.
                this.fetchError = true;
                this.loading.isActive = false;
            });
        },
    }
}
</script>
