<template>
    <span
        class="dp-health-badge"
        :class="badgeClass"
        :title="tooltipText"
        role="status"
        :aria-label="ariaLabel"
    >
        <span class="dp-health-dot" aria-hidden="true"></span>
        <span class="dp-health-label">{{ stateLabel }}</span>
    </span>
</template>

<script>
/**
 * [PARALLEL-TRACK-1.4 / Phase 4]
 *
 * Pure presentational badge that turns a `last_webhook_received_at`
 * ISO-8601 string into one of three states:
 *
 *   - ok       (< 1h)  green
 *   - warning  (< 24h) orange
 *   - critical (>= 24h or null) red
 *
 * No external state, no axios, no store — keep it cheap to render
 * inside the table rows.
 */
export default {
    name: "DeliveryPlatformHealthBadge",
    props: {
        lastReceivedAt: {
            type: [String, null],
            default: null,
        },
    },
    computed: {
        ageHours() {
            if (!this.lastReceivedAt) return Infinity;
            const ts = Date.parse(this.lastReceivedAt);
            if (Number.isNaN(ts)) return Infinity;
            return (Date.now() - ts) / (1000 * 60 * 60);
        },
        state() {
            if (this.ageHours === Infinity) return "critical";
            if (this.ageHours < 1) return "ok";
            if (this.ageHours < 24) return "warning";
            return "critical";
        },
        badgeClass() {
            return `dp-health-${this.state}`;
        },
        stateLabel() {
            switch (this.state) {
                case "ok":
                    return this.$t("delivery_platforms.health_ok");
                case "warning":
                    return this.$t("delivery_platforms.health_warning");
                default:
                    return this.$t("delivery_platforms.health_critical");
            }
        },
        tooltipText() {
            if (!this.lastReceivedAt) {
                return this.$t("delivery_platforms.never_received");
            }
            // Localised date in the operator's browser timezone.
            try {
                const d = new Date(this.lastReceivedAt);
                return d.toLocaleString();
            } catch (e) {
                return this.lastReceivedAt;
            }
        },
        ariaLabel() {
            return `${this.stateLabel} — ${this.tooltipText}`;
        },
    },
};
</script>

<style scoped>
.dp-health-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.2;
}
.dp-health-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
}
.dp-health-ok {
    background: #d1fae5;
    color: #047857;
}
.dp-health-warning {
    background: #fef3c7;
    color: #b45309;
}
.dp-health-critical {
    background: #fee2e2;
    color: #b91c1c;
}
</style>
