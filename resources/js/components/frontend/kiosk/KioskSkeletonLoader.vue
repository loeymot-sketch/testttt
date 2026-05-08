<template>
    <div
        class="kiosk-skeleton"
        :class="`kiosk-skeleton--${type}`"
        :aria-busy="true"
        :aria-label="ariaLabel"
        role="status"
        data-testid="kiosk-skeleton"
    >
        <!-- Type: 'card' (item card placeholder) -->
        <template v-if="type === 'card'">
            <div
                v-for="n in count"
                :key="n"
                class="kiosk-skeleton-card"
                data-testid="kiosk-skeleton-card"
            >
                <div class="kiosk-skeleton-shape kiosk-skeleton-shape--circle"></div>
                <div class="kiosk-skeleton-lines">
                    <div class="kiosk-skeleton-shape kiosk-skeleton-shape--line kiosk-skeleton-shape--lg"></div>
                    <div class="kiosk-skeleton-shape kiosk-skeleton-shape--line kiosk-skeleton-shape--md"></div>
                    <div class="kiosk-skeleton-shape kiosk-skeleton-shape--line kiosk-skeleton-shape--sm"></div>
                </div>
                <div class="kiosk-skeleton-shape kiosk-skeleton-shape--btn"></div>
            </div>
        </template>

        <!-- Type: 'list' (vertical list placeholder) -->
        <template v-else-if="type === 'list'">
            <div
                v-for="n in count"
                :key="n"
                class="kiosk-skeleton-row"
                data-testid="kiosk-skeleton-row"
            >
                <div class="kiosk-skeleton-shape kiosk-skeleton-shape--line kiosk-skeleton-shape--lg"></div>
                <div class="kiosk-skeleton-shape kiosk-skeleton-shape--line kiosk-skeleton-shape--md"></div>
            </div>
        </template>

        <!-- Type: 'grid' (grid placeholder for categories/products) -->
        <template v-else-if="type === 'grid'">
            <div class="kiosk-skeleton-grid">
                <div
                    v-for="n in count"
                    :key="n"
                    class="kiosk-skeleton-grid-item"
                    data-testid="kiosk-skeleton-grid-item"
                >
                    <div class="kiosk-skeleton-shape kiosk-skeleton-shape--rect kiosk-skeleton-shape--lg"></div>
                    <div class="kiosk-skeleton-shape kiosk-skeleton-shape--line kiosk-skeleton-shape--md"></div>
                </div>
            </div>
        </template>

        <!-- Type: 'inline' (single shimmering bar) -->
        <template v-else>
            <div
                class="kiosk-skeleton-shape kiosk-skeleton-shape--line kiosk-skeleton-shape--lg"
                data-testid="kiosk-skeleton-inline"
            ></div>
        </template>
    </div>
</template>

<script>
/**
 * KioskSkeletonLoader — Universal placeholder during fetch operations.
 *
 * Greenfield component (Wave Alpha — M-1) used between fetch start and data
 * ready. Improves perceived speed by showing structured shimmer placeholders
 * instead of empty space or single spinners. Respects prefers-reduced-motion.
 *
 * Props :
 *  - type  : 'card' | 'list' | 'grid' | 'inline' (visual variant)
 *  - count : number of placeholder rows/cards/items (1..N, default 3)
 *
 * A11y :
 *  - aria-busy=true (announces async state to AT)
 *  - role="status" (live region)
 *  - aria-label translated via $t (with safe fallback if i18n missing)
 *
 * NOT a frozen-zone component — usable in any non-frozen kiosk surface.
 */
export default {
    name: 'KioskSkeletonLoader',
    props: {
        type: {
            type: String,
            default: 'card',
            validator: (v) => ['card', 'list', 'grid', 'inline'].includes(v),
        },
        count: {
            type: Number,
            default: 3,
            validator: (v) => Number.isFinite(v) && v >= 1,
        },
    },
    computed: {
        ariaLabel() {
            // Defensive: fallback if $t missing or key not registered.
            // NOTE: namespaced as `kiosk.skeleton.aria_label` because the legacy
            // string `kiosk.loading` is already used as a label elsewhere — we
            // avoid collision by using the `skeleton` sub-namespace.
            const key = 'kiosk.skeleton.aria_label';
            try {
                const translated = this.$t ? this.$t(key) : null;
                if (translated && translated !== key) return translated;
            } catch (_) { /* noop */ }
            return 'Chargement du contenu en cours…';
        },
    },
};
</script>

<style scoped>
.kiosk-skeleton {
    width: 100%;
    padding: var(--kiosk-space-4, 16px);
}

/* Shimmer animation (respect prefers-reduced-motion) */
@keyframes kiosk-skeleton-shimmer {
    0% { background-position: -200px 0; }
    100% { background-position: calc(200px + 100%) 0; }
}

.kiosk-skeleton-shape {
    background: linear-gradient(
        90deg,
        var(--kiosk-surface-alt, #F7F3EC) 0%,
        var(--kiosk-border, #EEE6D9) 50%,
        var(--kiosk-surface-alt, #F7F3EC) 100%
    );
    background-size: 200px 100%;
    background-repeat: no-repeat;
    animation: kiosk-skeleton-shimmer 1.5s ease-in-out infinite;
    border-radius: var(--kiosk-radius-sm, 8px);
}

@media (prefers-reduced-motion: reduce) {
    .kiosk-skeleton-shape {
        animation: none;
        background: var(--kiosk-surface-alt, #F7F3EC);
    }
}

.kiosk-skeleton-shape--circle {
    border-radius: 50%;
    width: 96px;
    height: 96px;
    flex-shrink: 0;
}

.kiosk-skeleton-shape--rect {
    width: 100%;
    height: 140px;
    border-radius: var(--kiosk-radius-md, 16px);
}

.kiosk-skeleton-shape--line {
    height: 16px;
    margin-bottom: 8px;
}

.kiosk-skeleton-shape--sm { width: 40%; }
.kiosk-skeleton-shape--md { width: 65%; }
.kiosk-skeleton-shape--lg { width: 100%; }

.kiosk-skeleton-shape--btn {
    width: 80px;
    height: 36px;
    border-radius: var(--kiosk-radius-pill, 999px);
    flex-shrink: 0;
}

/* Card type */
.kiosk-skeleton-card {
    display: flex;
    align-items: center;
    gap: var(--kiosk-space-4, 16px);
    padding: var(--kiosk-space-3, 12px);
    background: var(--kiosk-surface, #fff);
    border: 1px solid var(--kiosk-border, #EEE6D9);
    border-radius: var(--kiosk-radius-md, 16px);
    margin-bottom: var(--kiosk-space-3, 12px);
}

.kiosk-skeleton-lines {
    flex: 1;
    min-width: 0;
}

/* List type */
.kiosk-skeleton-row {
    padding: var(--kiosk-space-3, 12px);
    background: var(--kiosk-surface, #fff);
    border-bottom: 1px solid var(--kiosk-border, #EEE6D9);
}

/* Grid type */
.kiosk-skeleton-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--kiosk-space-4, 16px);
}

.kiosk-skeleton-grid-item {
    background: var(--kiosk-surface, #fff);
    padding: var(--kiosk-space-3, 12px);
    border-radius: var(--kiosk-radius-md, 16px);
    border: 1px solid var(--kiosk-border, #EEE6D9);
}
</style>
