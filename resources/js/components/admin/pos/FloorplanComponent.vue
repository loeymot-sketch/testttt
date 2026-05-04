<template>
    <!-- [POS-V5] Floorplan refondu en namespace warm cohérent avec le POS shell. -->
    <section class="pos-v5-shell pos-v5-floorplan">
        <header class="pos-v5-floorplan__head">
            <div>
                <p class="pos-v5-floorplan__eyebrow">Caisse FoodKing · Plan de salle</p>
                <h2 class="pos-v5-floorplan__title">{{ $t('label.floorplan') }}</h2>
                <p class="pos-v5-floorplan__meta">
                    <span class="pos-v5-tabular">{{ tables.length }}</span> tables
                </p>
            </div>
            <div class="pos-v5-floorplan__actions">
                <button
                    type="button"
                    class="pos-v5-floorplan-btn pos-v5-floorplan-btn--primary"
                    :disabled="loading"
                    @click="fetchState"
                >
                    {{ loading ? '...' : $t('button.search') }}
                </button>
                <router-link
                    :to="{ name: 'admin.pos' }"
                    class="pos-v5-floorplan-btn pos-v5-floorplan-btn--ghost"
                >
                    ← {{ $t('label.back') }}
                </router-link>
            </div>
        </header>

        <div v-if="errorMessage" class="pos-v5-floorplan__error" role="alert">
            {{ errorMessage }}
        </div>

        <div class="pos-v5-floorplan-grid floorplan-grid">
            <button
                v-for="table in tables"
                :key="table.id"
                type="button"
                class="pos-v5-floorplan-table"
                :class="cardClass(table)"
                @click="handleTableClick(table)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="pos-v5-floorplan-table__name">{{ table.name }}</h3>
                        <p class="pos-v5-floorplan-table__seats">{{ table.size || 0 }} seats</p>
                    </div>
                    <span class="pos-v5-floorplan-table__status">
                        {{ statusLabel(table.occupancy_status) }}
                    </span>
                </div>

                <div class="mt-4 text-sm">
                    <template v-if="isOccupied(table)">
                        <p class="font-bold">Order #<span class="pos-v5-tabular">{{ table.occupied_order_id }}</span></p>
                        <p class="text-xs opacity-80">{{ elapsedLabel(table.occupied_at) }}</p>
                    </template>
                    <template v-else>
                        <p class="text-sm opacity-80">{{ $t('label.free') }}</p>
                    </template>
                </div>

                <div v-if="isOccupied(table)" class="mt-4 flex flex-wrap gap-2" @click.stop>
                    <button
                        type="button"
                        class="pos-v5-floorplan-table-btn"
                        @click="openOrder(table)"
                    >
                        Open order
                    </button>
                    <button
                        type="button"
                        class="pos-v5-floorplan-table-btn"
                        @click="releaseTable(table)"
                    >
                        {{ $t('button.release_table') }}
                    </button>
                    <button
                        type="button"
                        class="pos-v5-floorplan-table-btn"
                        @click="transferTable(table)"
                    >
                        {{ $t('button.transfer_table') }}
                    </button>
                </div>
            </button>
        </div>
    </section>
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
                // [POS-V5] tones — adopt warm V5 palette pour cohérence
                'is-free': status === 'free',
                'is-occupied': status === 'occupied',
                'is-reserved': status === 'reserved',
                'is-cleaning': status === 'cleaning',
                // legacy classes Tailwind conservées pour compat
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
/* =============================================================================
   FloorplanComponent — POS V5 Design Convergence (refonte 2026-05-02)
   Mission : CV1-POS-DESIGN-CONVERGENCE-001 / Doc plan §3.7
   ============================================================================= */
.pos-v5-floorplan {
    background: var(--pos-v5-bg-app);
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5);
    border-radius: var(--pos-v5-radius-lg);
    border: 1px solid var(--pos-v5-border);
    border-left: 4px solid var(--pos-v5-brand-red);
    box-shadow: var(--pos-v5-shadow-md);
    font-family: var(--pos-v5-font-sans);
}

.pos-v5-floorplan__head {
    display: flex;
    flex-direction: column;
    gap: var(--pos-v5-space-3);
    margin-bottom: var(--pos-v5-space-4);
}
@media (min-width: 768px) {
    .pos-v5-floorplan__head {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }
}

.pos-v5-floorplan__eyebrow {
    margin: 0;
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-brand-red);
    line-height: 1;
}
.pos-v5-floorplan__title {
    margin: 4px 0 6px;
    font-size: var(--pos-v5-text-h4);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
}
.pos-v5-floorplan__meta {
    margin: 0;
    font-size: var(--pos-v5-text-caption);
    color: var(--pos-v5-ink-soft);
}

.pos-v5-floorplan__actions {
    display: inline-flex;
    align-items: center;
    gap: var(--pos-v5-space-2);
}
.pos-v5-floorplan-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: var(--pos-v5-space-2) var(--pos-v5-space-4);
    border-radius: var(--pos-v5-radius-md);
    border: 1px solid transparent;
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    font-weight: var(--pos-v5-weight-bold);
    cursor: pointer;
    text-decoration: none;
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
    min-height: 40px;
}
.pos-v5-floorplan-btn--primary {
    background: var(--pos-v5-brand-red);
    color: var(--pos-v5-ink-on-red);
    box-shadow: var(--pos-v5-shadow-cta-soft);
}
.pos-v5-floorplan-btn--primary:hover:not(:disabled) {
    background: var(--pos-v5-brand-red-dark);
}
.pos-v5-floorplan-btn--ghost {
    background: var(--pos-v5-bg-panel);
    color: var(--pos-v5-ink);
    border-color: var(--pos-v5-border);
}
.pos-v5-floorplan-btn--ghost:hover {
    background: var(--pos-v5-bg-subtle);
    border-color: var(--pos-v5-border-strong);
}

.pos-v5-floorplan__error {
    margin-bottom: var(--pos-v5-space-4);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    border: 1px solid var(--pos-v5-danger);
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger-dark);
    border-radius: var(--pos-v5-radius-md);
    font-size: var(--pos-v5-text-body);
    font-weight: var(--pos-v5-weight-semibold);
}

.pos-v5-floorplan-grid,
.floorplan-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: var(--pos-v5-space-3);
}

.pos-v5-floorplan-table {
    text-align: left;
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-lg);
    padding: var(--pos-v5-space-4);
    background: var(--pos-v5-bg-panel);
    color: var(--pos-v5-ink);
    cursor: pointer;
    transition: all var(--pos-v5-duration-base) var(--pos-v5-ease-bounce);
    box-shadow: var(--pos-v5-shadow-sm);
    aspect-ratio: 1 / 1.1;
    display: flex;
    flex-direction: column;
}
.pos-v5-floorplan-table:hover {
    transform: translateY(-2px);
    box-shadow: var(--pos-v5-shadow-lift);
}

/* Tones par status (override Tailwind legacy via spécificité) */
.pos-v5-floorplan-table.is-free {
    border-color: var(--pos-v5-success);
    background: var(--pos-v5-success-soft);
    color: var(--pos-v5-success-dark);
}
.pos-v5-floorplan-table.is-occupied {
    border-color: var(--pos-v5-brand-red);
    background: var(--pos-v5-brand-red-soft);
    color: var(--pos-v5-brand-red);
}
.pos-v5-floorplan-table.is-reserved {
    border-color: var(--pos-v5-warning);
    background: var(--pos-v5-warning-soft);
    color: var(--pos-v5-warning-dark);
}
.pos-v5-floorplan-table.is-cleaning {
    border-color: var(--pos-v5-border-strong);
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
}

.pos-v5-floorplan-table__name {
    margin: 0;
    font-size: var(--pos-v5-text-h6);
    font-weight: var(--pos-v5-weight-extrabold);
    line-height: var(--pos-v5-leading-tight);
}
.pos-v5-floorplan-table__seats {
    margin: 2px 0 0;
    font-size: var(--pos-v5-text-caption);
    opacity: 0.8;
}
.pos-v5-floorplan-table__status {
    font-size: 10px;
    font-weight: var(--pos-v5-weight-extrabold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    padding: 3px 8px;
    border-radius: var(--pos-v5-radius-pill);
    background: rgba(255, 255, 255, 0.7);
}

.pos-v5-floorplan-table-btn {
    background: rgba(255, 255, 255, 0.86);
    color: inherit;
    padding: 6px 10px;
    border-radius: var(--pos-v5-radius-sm);
    border: 0;
    font-family: var(--pos-v5-font-sans);
    font-size: 11px;
    font-weight: var(--pos-v5-weight-bold);
    cursor: pointer;
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-floorplan-table-btn:hover {
    background: rgba(255, 255, 255, 1);
}

@media (prefers-reduced-motion: reduce) {
    .pos-v5-floorplan-table { transition: none; }
    .pos-v5-floorplan-table:hover { transform: none; }
}
</style>
