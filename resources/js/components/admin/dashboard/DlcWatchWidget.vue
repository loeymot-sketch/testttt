<template>
    <div v-if="allowed" class="col-12 xl:col-6" data-testid="dlc-watch-widget">
        <div class="db-card">
            <div class="db-card-header flex items-center justify-between flex-wrap gap-2">
                <h3 class="db-card-title mb-0 flex items-center gap-2">
                    <i class="fa-solid fa-tags text-primary" aria-hidden="true"></i>
                    {{ $t('label.dlc_watch_title') }}
                    <span v-if="loaded && counts.expired" class="db-table-badge text-red-900 bg-red-100 rounded-full px-2 py-0.5 text-xs font-semibold"
                          data-testid="dlc-watch-widget-expired-badge">{{ counts.expired }}</span>
                </h3>
                <router-link to="/admin/labels/dlc" class="text-sm font-medium text-orange-700" data-testid="dlc-watch-widget-open">
                    {{ $t('label.dlc_watch_open') }}
                </router-link>
            </div>
            <div class="db-card-body">
                <!-- Une panne ne ressemble jamais à « rien à surveiller » (même règle que les alertes stock). -->
                <p v-if="fetchError" class="text-sm text-red-600" data-testid="dlc-watch-widget-error">{{ $t('label.dlc_watch_error') }}</p>
                <div v-else-if="!loaded" class="py-6" />
                <template v-else>
                    <div class="flex gap-2 flex-wrap mb-3">
                        <span class="rounded-lg px-3 py-1.5 text-sm font-semibold" :class="counts.expired ? 'bg-red-100 text-red-900' : 'bg-gray-100 text-gray-600'"
                              data-testid="dlc-watch-widget-count-expired">{{ $t('label.dlc_watch_expired') }} : {{ counts.expired }}</span>
                        <span class="rounded-lg px-3 py-1.5 text-sm font-semibold" :class="counts.today ? 'bg-amber-100 text-amber-900' : 'bg-gray-100 text-gray-600'"
                              data-testid="dlc-watch-widget-count-today">{{ $t('label.dlc_watch_today') }} : {{ counts.today }}</span>
                        <span class="rounded-lg px-3 py-1.5 text-sm font-semibold bg-gray-100 text-gray-700"
                              data-testid="dlc-watch-widget-count-soon">{{ $t('label.dlc_watch_soon') }} : {{ counts.soon }}</span>
                        <span v-if="counts.anomaly" class="rounded-lg px-3 py-1.5 text-sm font-semibold bg-orange-100 text-orange-900"
                              data-testid="dlc-watch-widget-count-anomaly">{{ $t('label.dlc_watch_anomaly') }} : {{ counts.anomaly }}</span>
                        <span v-if="counts.unconfirmed" class="rounded-lg px-3 py-1.5 text-sm font-semibold bg-blue-100 text-blue-900"
                              data-testid="dlc-watch-widget-count-unconfirmed">{{ $t('label.dlc_watch_unconfirmed') }} : {{ counts.unconfirmed }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mb-2" data-testid="dlc-watch-widget-unit">Chiffres en étiquettes (un lot peut en regrouper plusieurs).</p>
                    <p v-if="expiredHidden > 0" class="text-xs text-red-700 mb-2" data-testid="dlc-watch-widget-hidden">+ {{ expiredHidden }} lignes échues plus anciennes non affichées : ouvrez l'écran DLC pour les jeter.</p>
                    <p v-if="!items.length" class="text-sm text-gray-600" data-testid="dlc-watch-widget-none">{{ $t('label.dlc_watch_none') }}</p>
                    <ul v-else class="text-sm">
                        <li v-for="w in items.slice(0, 5)" :key="w.key" class="flex justify-between gap-3 py-1.5 border-b border-gray-50"
                            :data-testid="`dlc-watch-widget-item-${w.id}`">
                            <span class="font-semibold text-gray-800">{{ w.name }}{{ w.lot ? " · " : "" }}<span v-if="w.lot" class="font-normal text-gray-500 whitespace-nowrap">N° {{ w.lot }}</span></span>
                            <span :class="w.level === 'expired' ? 'text-red-700 font-bold' : w.level === 'today' ? 'text-amber-800 font-semibold' : 'text-gray-600'">{{ w.text }}</span>
                        </li>
                    </ul>
                    <router-link v-if="items.length > 5" to="/admin/labels/dlc" class="block mt-2 text-sm font-medium text-orange-700"
                                 data-testid="dlc-watch-widget-more">+ {{ items.length - 5 }} — {{ $t('label.dlc_watch_open') }}</router-link>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16 · owner « je ne trouve pas les fonctionnalités … en tableau de bord »] Les DLC à surveiller sur le
 * tableau de bord : lots expirés (à jeter), qui expirent aujourd'hui, sous 72 h — la même liste que l'écran Étiquettes DLC
 * (`GET admin/labels/watch`, journal entier), avec un lien direct. Même porte que l'écran (`pos-orders`) : sans elle, le
 * widget ne s'affiche pas (jamais un appel promis au 403).
 */
import axios from 'axios';
import { watchList, watchCounts } from '../../../helpers/dlcLabelBuilder';

export default {
    name: 'DlcWatchWidget',
    data() {
        return { rows: [], expiredHidden: 0, loaded: false, fetchError: false, serverOffsetMs: 0, nowMs: Date.now(), timer: null };
    },
    computed: {
        allowed() {
            const raw = this.$store && this.$store.getters ? this.$store.getters.authPermission : null;
            const perms = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.data) ? raw.data : []);
            const entry = perms.find((p) => p && p.url === 'pos-orders');
            return Boolean(entry && entry.access === true);
        },
        all() { return watchList(this.rows, this.nowMs + this.serverOffsetMs, 300).items; },
        items() { return this.all; },
        // UNITÉ = l'étiquette physique (comme l'accueil Hygiène, le rappel et le dossier) : un lot de 3 copies compte 3, pas 1 (tour 9 : « 11 » à l'accueil, « 2 » ici)
        // [VG-2026-10-21 M7-003] UNE définition, partagée (dlcLabelBuilder.watchCounts = accueil Hygiène) : la somme des copies d'un LOT
        // entier pour un drapeau porté par une seule ligne disait « 2 non confirmées » quand l'accueil en disait 1
        counts() { return watchCounts(this.all, this.nowMs + this.serverOffsetMs); },
    },
    mounted() {
        if (!this.allowed) return;
        this.load();
        // tableau de bord laissé ouvert : l'heure avance et la liste se relit toutes les 3 minutes
        this.timer = setInterval(() => { this.nowMs = Date.now(); this.load(); }, 180000);
    },
    beforeUnmount() {
        clearInterval(this.timer);
    },
    methods: {
        async load() {
            try {
                const { data } = await axios.get('admin/labels/watch');
                this.rows = data.data || [];
                this.expiredHidden = Number(data.meta && data.meta.expired_hidden) || 0;
                const server = data.meta && Date.parse(data.meta.server_now);
                if (Number.isFinite(server)) this.serverOffsetMs = server - Date.now();
                this.nowMs = Date.now();
                this.fetchError = false;
            } catch (_e) {
                this.rows = [];
                this.fetchError = true;
            } finally {
                this.loaded = true;
            }
        },
    },
};
</script>
