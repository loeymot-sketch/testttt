<template>
    <!--
        ComposerProfileWarningBadge — Mission #2 Vague 1 action 1.1 + 1.5.

        Non-blocking visual badge that surfaces a CatalogWarningService entry
        on the admin item detail page.

        Plan   : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md tasks 1.1, 1.4, 1.5
        Audit  : reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md
    -->
    <div
        v-if="warnings.length > 0"
        class="space-y-2"
        data-testid="composer-profile-warning-badge"
        role="region"
        :aria-label="$t('warning.catalog.region_label')"
    >
        <article
            v-for="warning in warnings"
            :key="warning.code"
            class="flex items-start gap-3 rounded border p-3 border-solid"
            :style="severitySurfaceStyle(warning.severity)"
            :data-testid="`warning-badge-${warning.code}`"
            :data-severity="warning.severity"
            :role="warning.severity === 'blocker' ? 'alert' : undefined"
            :aria-live="warning.severity === 'blocker' ? 'assertive' : 'polite'"
        >
            <span class="mt-0.5" aria-hidden="true">
                <i :class="iconClass(warning.severity)"></i>
            </span>
            <div class="flex-1 text-sm">
                <p class="font-semibold">
                    {{ catalogMessage(warning.code, 'title') }}
                </p>
                <p class="mt-1 text-xs">
                    {{ catalogMessage(warning.code, 'message') }}
                </p>
                <div v-if="hasAction(warning.code)" class="mt-2">
                    <button
                        type="button"
                        class="db-btn db-btn-link text-xs underline"
                        :data-testid="`warning-action-${warning.code}`"
                        @click="onAction(warning)"
                    >
                        {{ catalogMessage(warning.code, 'action') }}
                    </button>
                </div>
            </div>
            <button
                v-if="dismissible"
                type="button"
                class="text-slate-400 hover:text-slate-600"
                :data-testid="`warning-dismiss-${warning.code}`"
                :aria-label="$t('button.close')"
                @click="dismiss(warning)"
            >
                <i class="lab lab-close" aria-hidden="true"></i>
            </button>
        </article>
    </div>
</template>

<script>
const SEVERITY_ICON_CLASS = {
    info: 'lab lab-information',
    warning: 'lab lab-warning',
    blocker: 'lab lab-error',
};

const ACTIONABLE_CODES = new Set([
    'composer_unpublished',
    'composer_missing_for_complex_kind',
    'channels_null',
    'missing_photo',
    'branch_availability_unset',
    'high_daily_consumed',
]);

const COMPOSER_CODES = ['composer_unpublished', 'composer_missing_for_complex_kind'];

const SEVERITY_SURFACE_STYLE = {
    info: {
        borderColor: '#2196F3',
        backgroundColor: '#E3F2FD',
        color: '#0D47A1',
    },
    warning: {
        borderColor: '#FF9800',
        backgroundColor: '#FFF8E1',
        color: '#E65100',
    },
    blocker: {
        borderColor: '#E53935',
        backgroundColor: '#FFEBEE',
        color: '#B71C1C',
    },
};

export default {
    name: 'ComposerProfileWarningBadge',
    props: {
        warnings: { type: Array, default: () => [] },
        dismissible: { type: Boolean, default: true },
    },
    methods: {
        catalogMessage(code, segment) {
            return this.$t(`warning.catalog.${code}.${segment}`);
        },
        severitySurfaceStyle(severity) {
            return SEVERITY_SURFACE_STYLE[severity] || SEVERITY_SURFACE_STYLE.info;
        },
        iconClass(severity) {
            return SEVERITY_ICON_CLASS[severity] || SEVERITY_ICON_CLASS.info;
        },
        hasAction(code) {
            return ACTIONABLE_CODES.has(code);
        },
        onAction(warning) {
            if (
                COMPOSER_CODES.includes(warning.code)
                && typeof this.$router !== 'undefined'
                && typeof this.$router.push === 'function'
            ) {
                const rawId = this.$route.params.id ?? this.$route.params.itemId ?? null;
                if (rawId !== null && rawId !== '') {
                    this.$router
                        .push(`/admin/items/show/${rawId}/composer`)
                        .catch(() => {});
                    return;
                }
            }
            this.$emit('action', warning);
        },
        dismiss(warning) {
            this.$emit('dismiss', warning);
        },
    },
};
</script>
