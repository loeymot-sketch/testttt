<template>
    <div class="db-card p-4 md:p-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-heading">{{ $t('label.floorplan') }}</h2>
                <p class="text-sm text-[#6E7191]">
                    {{ tables.length }} tables
                </p>
            </div>
        <div class="flex items-center gap-2">
            <button
                type="button"
                class="db-btn py-2 px-4 text-sm text-white bg-[#B0004D] rounded-lg hover:bg-[#8E003E] hover:text-white"
                :disabled="loading"
                @click="fetchState"
            >
                {{ loading ? '...' : $t('button.search') }}
            </button>
                <router-link
                    :to="{ name: 'admin.pos' }"
                    class="db-btn py-2 px-4 text-sm rounded-lg border border-[#D9DBE9] text-heading"
                >
                    {{ $t('label.back') }}
                </router-link>
            </div>
        </div>

        <div v-if="errorMessage" class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ errorMessage }}
        </div>

        <div class="grid gap-3 floorplan-grid">
            <button
                v-for="table in tables"
                :key="table.id"
                type="button"
                class="text-left rounded-2xl border p-4 transition shadow-sm"
                :class="cardClass(table)"
                @click="handleTableClick(table)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold">{{ table.name }}</h3>
                        <p class="text-xs opacity-80">{{ table.size || 0 }} seats</p>
                    </div>
                    <span class="text-[11px] uppercase tracking-wide">
                        {{ statusLabel(table.occupancy_status) }}
                    </span>
                </div>

                <div class="mt-4 text-sm">
                    <template v-if="isOccupied(table)">
                        <p class="font-medium">Order #{{ table.occupied_order_id }}</p>
                        <p class="text-xs opacity-80">{{ elapsedLabel(table.occupied_at) }}</p>
                    </template>
                    <template v-else>
                        <p class="text-sm opacity-80">{{ $t('label.free') }}</p>
                    </template>
                </div>

                <div v-if="isOccupied(table)" class="mt-4 flex flex-wrap gap-2" @click.stop>
                    <button
                        type="button"
                        class="rounded-lg bg-white/80 px-3 py-1.5 text-xs font-medium text-heading"
                        @click="openOrder(table)"
                    >
                        Open order
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-white/80 px-3 py-1.5 text-xs font-medium text-heading"
                        @click="releaseTable(table)"
                    >
                        {{ $t('button.release_table') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-white/80 px-3 py-1.5 text-xs font-medium text-heading"
                        @click="transferTable(table)"
                    >
                        {{ $t('button.transfer_table') }}
                    </button>
                </div>
            </button>
        </div>
    </div>
</template>

<script>
import alertService from "../../../services/alertService";

// [Phase-8 / T19] Plan de salle : `posFloorplan` (state/assign/transfer), UI
// libre / occupé, garde-fous double-clic — **non-régression** assign 409, transfer
// cross-branch 422/404 (voir `FloorplanControllerTest`, GATE C-β / branch_id).

export default {
    name: "FloorplanComponent",
    data() {
        return {
            loading: false,
            errorMessage: "",
            _pollTimer: null,
            // [V14 C-β / FINDING C-β-T19-7 P2] Per-action in-flight guards
            // to prevent double-click race conditions (assign / release /
            // transfer firing twice → 409 conflict on the second call).
            inFlight: {
                assign: {},
                release: {},
                transfer: {},
            },
        };
    },
    computed: {
        tables() {
            return this.$store.getters["posFloorplan/tables"];
        },
    },
    mounted() {
        this.fetchState();
        this._pollTimer = setInterval(() => this.fetchState({ silent: true }), 15000);
    },
    beforeUnmount() {
        if (this._pollTimer) {
            clearInterval(this._pollTimer);
        }
    },
    methods: {
        async fetchState(options = {}) {
            const silent = options.silent === true;
            if (!silent) {
                this.loading = true;
            }

            this.errorMessage = "";

            try {
                await this.$store.dispatch("posFloorplan/fetchState");
            } catch (error) {
                this.errorMessage = error?.response?.data?.message || "Unable to load floorplan.";
            } finally {
                if (!silent) {
                    this.loading = false;
                }
            }
        },
        cardClass(table) {
            const status = table?.occupancy_status || 'free';

            return {
                'border-green-200 bg-green-50 text-green-900': status === 'free',
                'border-red-200 bg-red-50 text-red-900': status === 'occupied',
                'border-orange-200 bg-orange-50 text-orange-900': status === 'reserved',
                'border-slate-200 bg-slate-100 text-slate-900': status === 'cleaning',
            };
        },
        statusLabel(status) {
            const normalized = status || 'free';

            return this.$t(`label.${normalized}`);
        },
        isOccupied(table) {
            return (table?.occupancy_status || 'free') === 'occupied';
        },
        elapsedLabel(value) {
            if (!value) {
                return "";
            }

            const startedAt = new Date(value).getTime();
            if (Number.isNaN(startedAt)) {
                return "";
            }

            const diffMinutes = Math.max(0, Math.floor((Date.now() - startedAt) / 60000));

            if (diffMinutes < 1) {
                return "Started just now";
            }

            if (diffMinutes < 60) {
                return `${diffMinutes} min`;
            }

            const hours = Math.floor(diffMinutes / 60);
            const minutes = diffMinutes % 60;

            return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`;
        },
        currentOrderId() {
            const routeOrderId = Number(this.$route?.query?.order_id || 0);

            return routeOrderId > 0 ? routeOrderId : null;
        },
        async handleTableClick(table) {
            if (this.isOccupied(table)) {
                this.openOrder(table);
                return;
            }
            if (this.inFlight.assign[table.id]) { return; }

            const defaultOrderId = this.currentOrderId();
            const raw = window.prompt("Order ID", defaultOrderId ? String(defaultOrderId) : "");

            if (raw === null) {
                return;
            }

            const orderId = Number(raw);
            if (!Number.isInteger(orderId) || orderId <= 0) {
                alertService.error("A valid order id is required.");
                return;
            }

            this.inFlight.assign[table.id] = true;
            try {
                await this.$store.dispatch("posFloorplan/assign", {
                    tableId: table.id,
                    orderId,
                });
                alertService.success(`Table ${table.name} assigned.`);
            } catch (error) {
                alertService.error(error?.response?.data?.message || "Unable to assign table.");
            } finally {
                delete this.inFlight.assign[table.id];
            }
        },
        async releaseTable(table) {
            if (this.inFlight.release[table.id]) { return; }
            this.inFlight.release[table.id] = true;
            try {
                await this.$store.dispatch("posFloorplan/release", table.id);
                alertService.success(`Table ${table.name} released.`);
            } catch (error) {
                alertService.error(error?.response?.data?.message || "Unable to release table.");
            } finally {
                delete this.inFlight.release[table.id];
            }
        },
        async transferTable(table) {
            if (this.inFlight.transfer[table.id]) { return; }
            const raw = window.prompt(this.$t('message.confirm_transfer'), "");
            if (raw === null) {
                return;
            }

            const targetId = Number(raw);
            if (!Number.isInteger(targetId) || targetId <= 0) {
                alertService.error("A valid target table id is required.");
                return;
            }

            this.inFlight.transfer[table.id] = true;
            try {
                await this.$store.dispatch("posFloorplan/transfer", {
                    sourceId: table.id,
                    targetId,
                });
                alertService.success(`Table ${table.name} transferred.`);
            } catch (error) {
                alertService.error(error?.response?.data?.message || "Unable to transfer table.");
            } finally {
                delete this.inFlight.transfer[table.id];
            }
        },
        openOrder(table) {
            const orderId = Number(table?.occupied_order_id || 0);
            if (!orderId) {
                return;
            }

            this.$router.push({
                name: "admin.pos-orders.show",
                params: { id: orderId },
            });
        },
    },
};
</script>

<style scoped>
.floorplan-grid {
    grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
}
</style>
