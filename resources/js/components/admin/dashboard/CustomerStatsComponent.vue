<!--
    ⚠️ COMPOSANT DORMANT — vérifié le 2026-09-03.

    Ce composant n'est monté NULLE PART. Il n'apparaît dans aucun autre fichier de
    `resources/js` que celui-ci, et le dépôt ne charge aucun composant automatiquement
    (ni `require.context`, ni `import.meta.glob`). Aucun utilisateur ne le voit.

    Sa route API (`customer-states / total-customers`) existe pourtant, elle est gardée, testée et maintenue : le
    coût backend est payé pour un écran que personne n'ouvre.

    Trois issues, et c'est une décision PRODUIT, pas technique :
      (a) le monter sur le tableau de bord — il faut alors lui écrire ses bancs, comme les
          six autres widgets en ont reçu le 2026-09-03 (succès / vide / 403 / 500 / délai) ;
      (b) le déplacer vers une page de rapports clients ;
      (c) le supprimer, ET trancher séparément le sort de sa route.

    Ne rien décider n'est pas neutre : la route continue d'être maintenue.
    Signalé une première fois dans `plans/GOAL_ONB07_..._2026-08-26.md:143` sans être tranché.
-->
<template>
  <LoadingComponent :props="loading" />
  <div class="col-12 xl:col-6">
    <div class="db-card">
      <div class="db-card-header">
        <h3 class="db-card-title">{{ $t('label.customer_stats') }}</h3>
        <div id="customer-range" class="cursor-pointer flex items-center gap-3 custom-datepicker">
          <label for="dp-input-customerStatsDate" class="sr-only">{{ $t('label.date') }}</label>
          <Datepicker uid="customerStatsDate" name="customerStatsDate" hideInputIcon autoApply :enableTimePicker="false" utc="false" @update:modelValue="customerStates"
            v-model="date" range :preset-ranges="presetRanges" :aria-labels="{ input: $t('label.date') }">
            <template #yearly="{ label, range, presetDateRange }">
              <button type="button" class="dashboard-date-preset w-full px-3 py-2 text-left rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" @click="presetDateRange(range)">{{ label }}</button>
            </template>
          </Datepicker>
          <i class="lab lab-calendar lab-font-size-24 lab-color-pink"></i>
        </div>
      </div>
      <div class="db-card-body">
        <apexchart height="270" v-if="options" :options="options" :series="options.series"></apexchart>
      </div>
    </div>
  </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import { ref, onMounted } from 'vue';
import { endOfMonth, endOfYear, startOfMonth, startOfYear, subMonths } from 'date-fns';

export default {
  name: "CustomerStatsComponent",
  components: { LoadingComponent, Datepicker },
  data() {
    return {
      loading: {
        isActive: false,
      },

      date: null,
      first_date: null,
      last_date: null,
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
    ],
      options: null
    };
  },
  mounted() {
    const date = new Date();
    const startDate = new Date(date.getFullYear(), date.getMonth(), 1);
    const endDate = new Date(date.getFullYear(), date.getMonth() + 1, 0);
    this.date = [startDate, endDate];
    this.customerStates();

  },
  methods: {
    customerStates: function (e) {
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

      this.$store.dispatch("dashboard/customerStates", date).then((res) => {
        this.options = {
          series: [{
            name: this.$t('menu.customers'),
            data: res.data.data.total_customers,
          }],
          chart: {
            type: 'bar',
            height: 276,
            parentHeightOffset: 0,
            zoom: { enabled: false },
            toolbar: { show: false },
          },
          plotOptions: {
            bar: {
              horizontal: false,
              columnWidth: '40%',
              endingShape: 'rounded'
            },
          },
          stroke: {
            show: true,
            width: 2,
            colors: ['#567DFF']
          },
          xaxis: {
            categories: res.data.data.times,
          },
          fill: {
            opacity: 1
          },
          tooltip: {
            style: {
              fontSize: '14px',
              fontFamily: 'inherit',
            }
          },
          colors: ['#567DFF'],
          grid: { show: false, },
          yaxis: { show: false },
          dataLabels: { enabled: false },
        };

        this.loading.isActive = false;
      }).catch((err) => {
        this.loading.isActive = false;
      });
    },
  },
}
</script>
