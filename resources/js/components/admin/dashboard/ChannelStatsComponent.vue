<template>
    <div class="col-12 sm:col-12 xl:col-6 mb-6">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 h-full">
            <h4 class="font-semibold text-lg text-gray-800 mb-6">Répartition par Canal (Aujourd'hui)</h4>

            <!--
                [G5 · T5.1 2026-09-03] `fetchData()` n'avait AUCUN `.catch`. Une 403, une
                500 ou une coupure réseau laissait `stats` à `[]` — soit exactement l'écran
                d'une journée sans commande — et laissait filer un rejet de promesse non
                traité. L'exploitant lisait « aucune commande sur aucun canal » un jour de
                panne, sans rien pour le contredire.
            -->
            <p v-if="fetchError" class="text-sm text-red-600" data-testid="channel-stats-error">
                Impossible de charger la répartition par canal — ce panneau n'a rien pu lire.
            </p>
            <p v-else-if="loaded && stats.length === 0" class="text-sm text-gray-500" data-testid="channel-stats-empty">
                Aucune commande enregistrée aujourd'hui.
            </p>
            <div v-else class="space-y-6">
                <!--
                    Clé = le nom du canal, pas l'index : le serveur peut réordonner les
                    canaux d'un rafraîchissement à l'autre, et une clé positionnelle fait
                    alors recycler le nœud d'un canal pour un autre.
                -->
                <div v-for="stat in stats" :key="stat.name">
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-medium text-gray-700">{{ stat.name }}</span>
                        <span class="text-sm font-semibold text-gray-500">{{ stat.value }}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <!--
                            [G5 · T5.2] Sans `role="progressbar"`, un lecteur d'écran ne lit
                            qu'un pourcentage nu, sans savoir de quelle échelle il parle.
                        -->
                        <div class="h-2.5 rounded-full"
                             role="progressbar"
                             :aria-valuenow="Number(stat.value)"
                             aria-valuemin="0"
                             aria-valuemax="100"
                             :aria-label="stat.name"
                             :data-testid="`channel-stats-bar-${stat.name}`"
                             :style="{ width: stat.value + '%' }"
                             :class="getColor(stat.name)">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "ChannelStatsComponent",
    data() {
        return {
            stats: [],
            // [G5 · T5.1] Une panne doit être DISCERNABLE d'une journée sans commande.
            loaded: false,
            fetchError: false,
        }
    },
    mounted() {
        this.fetchData();
    },
    methods: {
        fetchData() {
            return this.$store.dispatch('dashboard/channelStatistics').then(res => {
                this.stats = res.data.data || [];
                this.fetchError = false;
                this.loaded = true;
            }).catch(() => {
                // Ne JAMAIS retomber sur `stats = []` en silence : ce serait affirmer
                // « zéro commande » alors qu'on n'a rien lu du tout.
                this.stats = [];
                this.fetchError = true;
                this.loaded = true;
            });
        },
        getColor(name) {
            if(name === 'Web') return 'bg-blue-500';
            if(name === 'Kiosk/App') return 'bg-orange-500';
            if(name === 'POS') return 'bg-purple-500';
            return 'bg-gray-500';
        }
    }
}
</script>
