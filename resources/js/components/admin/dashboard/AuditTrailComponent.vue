<template>
    <div class="col-12 sm:col-12 xl:col-12 mb-6">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <h4 class="font-semibold text-lg text-gray-800 mb-2 flex items-center gap-2">
                <i class="lab lab-history text-primary"></i>
                Audit Trail NF525 (Journal Inviolable)
            </h4>
            <p class="text-xs text-gray-500 mb-4">
                Source : <code class="bg-gray-100 px-1 rounded">audit_logs</code> (INSERT-only, HMAC SHA-256 chain-signed). Le préfixe de hash atteste l'intégrité de la chaîne.
            </p>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 rounded-tl-lg">Utilisateur</th>
                            <th scope="col" class="px-4 py-3">Action</th>
                            <th scope="col" class="px-4 py-3">Ressource</th>
                            <th scope="col" class="px-4 py-3" title="Préfixe 8 caractères du SHA-256 chain hash">Hash</th>
                            <th scope="col" class="px-4 py-3 rounded-tr-lg">Temps</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in auditLogs" :key="log.id" class="bg-white border-b hover:bg-gray-50">
                            <td class="px-4 py-4 font-medium text-gray-900">{{ log.user_name }}</td>
                            <td class="px-4 py-4">
                                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded">{{ log.action }}</span>
                            </td>
                            <td class="px-4 py-4">{{ log.resource || '-' }}</td>
                            <td class="px-4 py-4">
                                <code v-if="log.hash_prefix"
                                      class="font-mono text-xs bg-emerald-50 text-emerald-800 px-2 py-0.5 rounded"
                                      :title="'Chain HMAC SHA-256 prefix — full hash hidden for security'">
                                    {{ log.hash_prefix }}
                                </code>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                            <td class="px-4 py-4 text-gray-600">{{ log.time }}</td>
                        </tr>
                        <tr v-if="auditLogs.length === 0">
                            <td colspan="5" class="px-6 py-8 text-center text-gray-500">Aucun événement audité récent.</td>
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
        }
    }
}
</script>
