<template>
    <LoadingComponent :props="loading" />
    <div class="flex items-center justify-between mb-4">
        <h4 class="font-semibold text-[22px] leading-[34px] mb-3 capitalize">{{ $t('menu.order_statistics') }}</h4>
        <div class="relative cursor-pointer custom-datepicker">
            <label for="dp-input-orderStatisticsDate" class="sr-only">{{ $t('label.date') }}</label>
            <Datepicker uid="orderStatisticsDate" name="orderStatisticsDate" hideInputIcon autoApply :enableTimePicker="false" utc="false" @update:modelValue="handleDate"
                v-model="date" range :preset-ranges="presetRanges" :aria-labels="{ input: $t('label.date') }">
                <template #yearly="{ label, range, presetDateRange }">
                    <button type="button" class="dashboard-date-preset w-full px-3 py-2 text-left rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" @click="presetDateRange(range)">{{ label }}</button>
                </template>
            </Datepicker>
        </div>
    </div>
    <!--
        [G5 · T5.1 2026-09-03] Les dix compteurs partent à `null`, que Vue rend comme une
        chaîne VIDE. Le `.catch` ne faisait que retomber le voile de chargement sans rien
        conserver de l'échec : sur 403 ou 500, l'écran montrait dix tuiles avec leur
        libellé et, dessous, du blanc.
        Ce n'est pas seulement indiscernable d'une journée sans commande — c'est pire. Une
        vraie journée à zéro renvoie `0` et affiche « 0 ». Le blanc n'appartient à aucune
        journée réelle : c'est un état que le produit ne savait pas nommer, et que
        l'exploitant lisait comme un zéro.
    -->
    <p v-if="fetchError" class="text-sm text-red-600 mb-3" data-testid="order-statistics-error">
        {{ $t('label.order_statistics_error') }}
    </p>
    <div class="row mb-3">
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#FFE6F0]">
                    <i class="lab lab-total-orders lab-font-size-24 text-primary" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">
                        {{ $t('label.total_orders') }}
                    </h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-total_order">{{ chiffre(total_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#FFF6E6]">
                    <i class="lab lab-pending lab-font-size-24 lab-color-yellow" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">{{ $t('label.pending') }}</h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-pending_order">{{ chiffre(pending_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#E7FFF0]">
                    <i class="lab lab-delivered lab-font-size-24 lab-color-green" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">{{ $t('label.accept') }}</h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-accept_order">{{ chiffre(accept_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#e5ebff]">
                    <i class="lab lab-processing lab-font-size-24 text-[#567DFF]" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">{{ $t('label.preparing') }}
                    </h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-preparing_order">{{ chiffre(preparing_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#F5EAFF]">
                    <i class="lab lab-prepared lab-font-size-24 text-[#A953FF]" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">{{ $t('label.prepared') }}</h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-prepared_order">{{ chiffre(prepared_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#E9F9FF]">
                    <i class="lab lab-out-for-delivery lab-font-size-24 lab-color-blue" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">
                        {{ $t('label.out_for_delivery') }}
                    </h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-out_for_delivery_order">{{ chiffre(out_for_delivery_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#EBE7FF]">
                    <i class="lab lab-delivered lab-font-size-24 lab-color-delivered" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">{{ $t('label.delivered') }}</h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-delivered_order">{{ chiffre(delivered_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#FFEAEA]">
                    <i class="lab lab-cancel-n-reject lab-font-size-24 lab-color-red" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">{{ $t('label.canceled') }}</h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-canceled_order">{{ chiffre(canceled_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#E9EEFF]">
                    <i class="lab lab-returned lab-font-size-24 lab-color-blue-2" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">{{ $t('label.returned') }}</h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-returned_order">{{ chiffre(returned_order) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-12 sm:col-6 md:col-4 lg:col-6 xl:col-3">
            <div class="flex items-center gap-4 p-4 rounded-lg shadow-xs bg-white">
                <div class="w-12 h-12 rounded-full flex items-center justify-center bg-[#FFEAEA]">
                    <i class="lab lab-cancel-n-reject lab-font-size-24 lab-color-red" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="font-normal text-sm leading-6 capitalize text-paragraph">{{ $t('label.rejected') }}</h3>
                    <h4 class="font-bold text-lg leading-[34px]" data-testid="order-stat-rejected_order">{{ chiffre(rejected_order) }}</h4>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import { ref } from 'vue';
import { endOfMonth, endOfYear, startOfMonth, startOfYear, subMonths } from 'date-fns';
export default {
    name: "OrderStatisticsComponent",
    components: { LoadingComponent, Datepicker },
    data() {
        return {
            loading: {
                isActive: false,
            },

            date: null,
            first_date: null,
            last_date: null,
            // [G5 · T5.1] Une panne doit être DISCERNABLE d'une journée sans commande.
            fetchError: false,
            total_order: null,
            pending_order: null,
            accept_order: null,
            preparing_order: null,
            prepared_order: null,
            out_for_delivery_order: null,
            delivered_order: null,
            canceled_order: null,
            returned_order: null,
            rejected_order: null,
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
                { label: 'Cette année', range: [startOfYear(new Date()), endOfYear(new Date())], slot: 'yearly' },
            ]
        };
    },
    mounted() {
        const startDate = new Date();
        const endDate = new Date();
        this.date = [startDate, endDate];
        this.orderStatistic();
    },
    methods: {
        /**
         * [G5 · T5.1] Sur échec, le compteur affiche « — » : ni un chiffre faux, ni du
         * blanc. Le blanc est le pire des trois, parce qu'il se lit « 0 ».
         */
        chiffre: function (valeur) {
            return this.fetchError ? '—' : valeur;
        },
        handleDate: function (e) {
            if (e) {
                this.first_date = e[0];
                this.last_date = e[1];

                this.loading.isActive = true;
                this.$store.dispatch("dashboard/orderStatistics", {
                    first_date: this.first_date,
                    last_date: this.last_date,
                }).then((res) => {
                    this.total_order = res.data.data.total_order;
                    this.pending_order = res.data.data.pending_order;
                    this.accept_order = res.data.data.accept_order;
                    this.preparing_order = res.data.data.preparing_order;
                    this.prepared_order = res.data.data.prepared_order;
                    this.out_for_delivery_order = res.data.data.out_for_delivery_order;
                    this.delivered_order = res.data.data.delivered_order;
                    this.canceled_order = res.data.data.canceled_order;
                    this.returned_order = res.data.data.returned_order;
                    this.rejected_order = res.data.data.rejected_order;
                    this.fetchError = false;
                    this.loading.isActive = false;
                }).catch(() => {
                    // [G5 · T5.1] L'échec est conservé, pas seulement absorbé.
                    this.fetchError = true;
                    this.loading.isActive = false;
                });
            } else {
                this.date = null;
                this.first_date = null;
                this.last_date = null;
                this.orderStatistic();
            }
        },
        orderStatistic: function () {
            this.loading.isActive = true;
            this.$store.dispatch("dashboard/orderStatistics").then((res) => {
                this.total_order = res.data.data.total_order;
                this.pending_order = res.data.data.pending_order;
                this.accept_order = res.data.data.accept_order;
                this.preparing_order = res.data.data.preparing_order;
                this.prepared_order = res.data.data.prepared_order;
                this.out_for_delivery_order = res.data.data.out_for_delivery_order;
                this.delivered_order = res.data.data.delivered_order;
                this.canceled_order = res.data.data.canceled_order;
                this.returned_order = res.data.data.returned_order;
                this.rejected_order = res.data.data.rejected_order;
                this.fetchError = false;
                this.loading.isActive = false;
            }).catch(() => {
                // [G5 · T5.1] L'échec est conservé, pas seulement absorbé.
                this.fetchError = true;
                this.loading.isActive = false;
            });
        }
    }
}
</script>
