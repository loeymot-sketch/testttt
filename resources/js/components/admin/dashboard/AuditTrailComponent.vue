<template>
    <div class="col-12 sm:col-12 xl:col-12 mb-6">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <h4 class="font-semibold text-lg text-gray-800 mb-2 flex items-center gap-2">
                <i class="lab lab-history text-primary"></i>
                Audit Trail NF525 (Journal Inviolable)
            </h4>
            <!--
                [2026-09-02 · Codex P1-I] Cette phrase affirmait : « Le préfixe de hash
                atteste l'intégrité de la chaîne ». C'est faux, et c'est la pire sorte de
                faux — il rassure exactement là où il ne faut pas. Huit caractères d'une
                empreinte ne disent RIEN sur la ligne précédente, ni sur une ligne retirée,
                ni sur la reproductibilité de la signature. Seul un reparcours complet de la
                chaîne l'atteste.
            -->
            <p class="text-xs text-gray-500 mb-4">
                Source : <code class="bg-gray-100 px-1 rounded">audit_logs</code> (écriture seule, chaîne signée HMAC SHA-256).
                La colonne « Empreinte » montre le <strong>préfixe du hash de chaînage</strong> : elle prouve que la ligne
                appartient à la chaîne, <strong>pas</strong> que la chaîne est intacte.
                L'intégrité n'est attestée que par un reparcours complet — <code class="bg-gray-100 px-1 rounded">php artisan fiscal:verify-chain</code>.
            </p>

            <div class="overflow-x-auto max-h-80 overflow-y-auto">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 rounded-tl-lg">Utilisateur</th>
                            <th scope="col" class="px-4 py-3">Action</th>
                            <th scope="col" class="px-4 py-3">Ressource</th>
                            <th scope="col" class="px-4 py-3">Branche</th>
                            <th scope="col" class="px-4 py-3" title="Préfixe 8 caractères du hash de chaînage SHA-256 — n'atteste pas l'intégrité de la chaîne">Empreinte</th>
                            <th scope="col" class="px-4 py-3 rounded-tr-lg">Quand</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in auditLogs" :key="log.id" class="bg-white border-b hover:bg-gray-50">
                            <td class="px-4 py-4 font-medium text-gray-900">{{ log.user_name }}</td>
                            <td class="px-4 py-4">
                                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded">{{ translateAction(log.action) }}</span>
                            </td>
                            <td class="px-4 py-4">{{ log.resource || '-' }}</td>
                            <td class="px-4 py-4" :data-testid="`audit-trail-branche-${log.id}`">
                                {{ log.branch_id === 0 ? 'Toutes' : (log.branch_id ?? '—') }}
                            </td>
                            <td class="px-4 py-4">
                                <code v-if="log.hash_prefix"
                                      class="font-mono text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded"
                                      :title="'Préfixe du hash de chaînage — n’atteste pas l’intégrité de la chaîne'">
                                    {{ log.hash_prefix }}
                                </code>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                            <!-- « il y a 3 heures » ne se recoupe avec rien : sans date exacte,
                                 impossible de rapprocher une ligne d'audit d'un ticket ou d'un Z. -->
                            <td class="px-4 py-4 text-gray-600">
                                <span :title="log.created_at || ''">{{ log.time }}</span>
                                <span v-if="log.created_at" class="block text-xs text-gray-400 font-mono">
                                    {{ dateExacte(log.created_at) }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="auditLogs.length === 0">
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">Aucun événement audité récent.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "AuditTrailComponent",
    data() {
        return {
            auditLogs: [],
            timer: null
        }
    },
    mounted() {
        this.fetchData();
        // Auto-refresh every 30 seconds
        this.timer = setInterval(this.fetchData, 30000);
    },
    beforeUnmount() {
        clearInterval(this.timer);
    },
    methods: {
        fetchData() {
            this.$store.dispatch('dashboard/auditTrail').then(res => {
                this.auditLogs = res.data.data;
            });
        },
        // [I18N-DASH-P1-01 heal 2026-05-30] Translate NF525 audit action codes
        // (e.g. 'user.login', 'cash.movement.recorded') to French/EN labels.
        // Canonical event names stay in DB for HMAC chain integrity; rendering
        // only is translated. Falls back to raw action if translation missing.
        // Affiche la date à la seconde, en heure locale, sans dépendre d'une bibliothèque.
        dateExacte(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return String(iso);
            const p = (n) => String(n).padStart(2, '0');

            return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} `
                + `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
        },
        translateAction(action) {
            if (!action) return '-';
            const key = 'label.audit_event_' + action.replace(/\./g, '_');
            const translated = this.$t(key);
            return translated !== key ? translated : action;
        }
    }
}
</script>
