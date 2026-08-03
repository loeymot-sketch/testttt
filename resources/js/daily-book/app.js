// [GOAL RUPTURE-CARNET 2026-07-15 / W5] Carnet — app Vue légère standalone.
// Pas de vuex/router : une seule vue mobile-first, fetch + cookie session.
import { createApp } from 'vue';
import DailyBookApp from './DailyBookApp.vue';

createApp(DailyBookApp).mount('#daily-book-app');
