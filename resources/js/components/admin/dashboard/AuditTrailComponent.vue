<template>
    <div class="col-12 sm:col-12 xl:col-12 mb-6">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <h4 class="font-semibold text-lg text-gray-800 mb-4 flex items-center gap-2">
                <i class="lab lab-history text-primary"></i>
                {{ $t('dashboard.audit.title') }}
            </h4>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 rounded-tl-lg">{{ $t('dashboard.audit.col_user') }}</th>
                            <th scope="col" class="px-6 py-3">{{ $t('dashboard.audit.col_action') }}</th>
                            <th scope="col" class="px-6 py-3">{{ $t('dashboard.audit.col_resource') }}</th>
                            <th scope="col" class="px-6 py-3 rounded-tr-lg">{{ $t('dashboard.audit.col_time') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in auditLogs" :key="log.id" class="bg-white border-b hover:bg-gray-50">
                            <td class="px-6 py-4 font-medium text-gray-900">{{ log.user_name }}</td>
                            <td class="px-6 py-4">
                                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded">{{ log.action }}</span>
                            </td>
                            <td class="px-6 py-4">{{ log.resource || '-' }}</td>
                            <td class="px-6 py-4 text-gray-400">{{ log.time }}</td>
                        </tr>
                        <tr v-if="auditLogs.length === 0">
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500">{{ $t('dashboard.audit.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script>
const BASE_DELAY = 30000;
const MAX_DELAY = 240000;
const FAILURE_THRESHOLD = 3;

export default {
    name: "AuditTrailComponent",
    data() {
        return {
            auditLogs: [],
            pollTimeout: null,
            pollFailures: 0,
            pollDelay: BASE_DELAY,
        }
    },
    mounted() {
        this.pollLoop();
    },
    beforeUnmount() {
        clearTimeout(this.pollTimeout);
    },
    methods: {
        async fetchData() {
            const res = await this.$store.dispatch('dashboard/auditTrail');
            this.auditLogs = res.data.data;
        },
        async pollLoop() {
            try {
                await this.fetchData();
                this.pollFailures = 0;
                this.pollDelay = BASE_DELAY;
            } catch (e) {
                this.pollFailures++;
                if (this.pollFailures >= FAILURE_THRESHOLD) {
                    console.warn('[AuditTrail] consecutive polling failures, backing off');
                    this.pollDelay = Math.min(this.pollDelay * 2, MAX_DELAY);
                }
            }
            this.pollTimeout = setTimeout(() => this.pollLoop(), this.pollDelay);
        }
    }
}
</script>
