<template>
    <div class="kiosk-theme-admin">
        <header class="kt-header">
            <h1 class="kt-title">{{ $t('kiosk.admin.theme_manager_title') }}</h1>
            <p class="kt-subtitle">{{ $t('kiosk.admin.theme_manager_subtitle') }}</p>
        </header>

        <div v-if="loading" class="kt-loading" data-testid="kiosk-theme-loading">
            <KioskSkeletonLoader type="grid" :count="3" />
        </div>

        <div v-else>
            <!--
                Branch selector visible only for head-office Admins (branch_id=0).
                A scoped Admin can only operate on its own branch (controller-side
                guard mirrored here for UX clarity).
            -->
            <div v-if="isHeadOffice && branches.length > 1" class="kt-branch-selector">
                <label for="kt-branch-select">
                    <span class="kt-branch-label">{{ $t('kiosk.admin.theme_branch_label') }}</span>
                    <select
                        id="kt-branch-select"
                        v-model="selectedBranchId"
                        class="kt-branch-select"
                        data-testid="kiosk-theme-branch-select"
                        @change="loadActiveTheme"
                    >
                        <option v-for="b in branches" :key="b.id" :value="b.id">
                            {{ b.name }}
                        </option>
                    </select>
                </label>
            </div>

            <!-- Theme cards grid (radiogroup) -->
            <div
                class="kt-grid"
                role="radiogroup"
                :aria-label="$t('kiosk.admin.theme_select_aria')"
                data-testid="kiosk-theme-grid"
            >
                <KioskThemePreviewCard
                    v-for="theme in availableThemes"
                    :key="theme.id"
                    :theme="theme"
                    :is-active="activeTheme === theme.id"
                    @select="setActiveTheme(theme.id)"
                />
            </div>

            <!-- Status message (live region) -->
            <div
                v-if="statusMessage"
                class="kt-status"
                :class="`kt-status-${statusType}`"
                role="status"
                aria-live="polite"
                data-testid="kiosk-theme-status"
            >
                {{ statusMessage }}
            </div>

            <p class="kt-preview-note">
                {{ $t('kiosk.admin.theme_preview_note') }}
            </p>
        </div>
    </div>
</template>

<script>
/**
 * KioskThemeManagerPage.vue — [V2-5 Phase 2] Admin UI for the seasonal skinning.
 *
 * - GET  admin/kiosk-theme/{branchId}         → load active slug for the branch
 * - PATCH admin/kiosk-theme/{branchId}        → update active slug (gated `permission:settings`)
 *
 * Branch selector :
 *   - Head-office admin (authBranchId == 0) sees the dropdown.
 *   - Scoped admin sees a single read-only branch (its own).
 *
 * Branches list reuses the global `branch/lists` Vuex action — same pattern
 * as DeliveryPlatformsPage (no duplicated fetch, free caching).
 *
 * Greenfield only : no styling on existing components, no frozen-zone touch.
 * The page is mounted at /admin/kiosk-themes via kioskThemeAdminRoutes.
 */

import axios from 'axios';
import KioskThemePreviewCard from './KioskThemePreviewCard.vue';
import KioskSkeletonLoader from '../../frontend/kiosk/KioskSkeletonLoader.vue';

// Static catalog mirrored on the server-side whitelist
// (`KioskThemeController::SUPPORTED_THEMES` and JS `SUPPORTED_THEMES`).
// Adding a theme here without server-side update would still preview but
// the PATCH would 422 — see docs/KIOSK_THEMES.md procedure.
const AVAILABLE_THEMES = Object.freeze([
    {
        id: 'standard',
        name: 'Standard',
        emoji: '🍔',
        descriptionKey: 'kiosk.admin.theme_standard_description',
    },
    {
        id: 'halloween',
        name: 'Halloween',
        emoji: '🎃',
        descriptionKey: 'kiosk.admin.theme_halloween_description',
    },
    {
        id: 'christmas',
        name: 'Noël',
        emoji: '🎄',
        descriptionKey: 'kiosk.admin.theme_christmas_description',
    },
]);

export default {
    name: 'KioskThemeManagerPage',
    components: { KioskThemePreviewCard, KioskSkeletonLoader },
    data() {
        return {
            loading: true,
            selectedBranchId: null,
            activeTheme: 'standard',
            statusMessage: null,
            statusType: 'info',
            statusTimer: null,
        };
    },
    computed: {
        /**
         * Head-office admin = authBranchId 0 / null. Anything else is scoped
         * to a single branch. Mirror of the controller-side check.
         */
        isHeadOffice() {
            const id = this.$store?.getters?.authBranchId;
            return id === 0 || id === null || id === undefined;
        },
        branches() {
            return this.$store?.getters?.['branch/lists'] || [];
        },
        availableThemes() {
            // Resolve i18n descriptions at render time so locale switch is reactive.
            return AVAILABLE_THEMES.map((t) => ({
                id: t.id,
                name: t.name,
                emoji: t.emoji,
                description: this.$t(t.descriptionKey),
            }));
        },
    },
    async mounted() {
        await this.bootstrap();
    },
    beforeUnmount() {
        if (this.statusTimer) clearTimeout(this.statusTimer);
    },
    methods: {
        async bootstrap() {
            this.loading = true;
            try {
                await this.loadBranches();

                // Default selection : current user's branch (or first available).
                const userBranchId = this.$store?.getters?.authBranchId;
                if (userBranchId && userBranchId !== 0) {
                    this.selectedBranchId = userBranchId;
                } else {
                    this.selectedBranchId = this.branches[0]?.id || null;
                }

                if (this.selectedBranchId) {
                    await this.loadActiveTheme();
                }
            } catch (err) {
                this.showStatus(this.$t('kiosk.admin.theme_load_error'), 'error');
            } finally {
                this.loading = false;
            }
        },
        async loadBranches() {
            try {
                if (!this.branches.length) {
                    // Reuse the canonical branch list action (cached in Vuex).
                    await this.$store.dispatch('branch/lists', { paginate: 0 });
                }
            } catch (_) {
                // Non-fatal : the page still works for a scoped Admin even
                // without the branches list — they only see their own branch.
            }
        },
        async loadActiveTheme() {
            if (!this.selectedBranchId) return;
            try {
                const res = await axios.get(
                    `admin/kiosk-theme/${this.selectedBranchId}`
                );
                const slug = res?.data?.active_theme;
                this.activeTheme = typeof slug === 'string' && slug.length
                    ? slug
                    : 'standard';
            } catch (_) {
                this.activeTheme = 'standard';
            }
        },
        async setActiveTheme(themeId) {
            if (!this.selectedBranchId) {
                this.showStatus(this.$t('kiosk.admin.theme_save_error'), 'error');
                return;
            }
            try {
                await axios.patch(
                    `admin/kiosk-theme/${this.selectedBranchId}`,
                    { theme: themeId }
                );
                this.activeTheme = themeId;
                this.showStatus(this.$t('kiosk.admin.theme_saved'), 'success');
            } catch (err) {
                this.showStatus(this.$t('kiosk.admin.theme_save_error'), 'error');
            }
        },
        showStatus(message, type) {
            this.statusMessage = message;
            this.statusType = type || 'info';
            if (this.statusTimer) clearTimeout(this.statusTimer);
            // Auto-clear after 3.5s for non-error states ; keep errors visible
            // until next user action.
            if (type !== 'error') {
                this.statusTimer = setTimeout(() => {
                    this.statusMessage = null;
                }, 3500);
            }
        },
    },
};
</script>

<style scoped>
.kiosk-theme-admin {
    padding: 32px;
    max-width: 1200px;
    margin: 0 auto;
}

.kt-header {
    margin-bottom: 24px;
}

.kt-title {
    margin: 0 0 8px;
    font-size: 28px;
    font-weight: 800;
    color: #1A1A1A;
}

.kt-subtitle {
    color: #5A5A5A;
    margin: 0;
    font-size: 15px;
}

.kt-loading {
    margin: 32px 0;
}

.kt-branch-selector {
    margin-bottom: 24px;
}

.kt-branch-selector label {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 700;
    color: #1A1A1A;
}

.kt-branch-label {
    white-space: nowrap;
}

.kt-branch-select {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #EEE6D9;
    background: #fff;
    font-size: 14px;
    min-width: 220px;
    cursor: pointer;
}

.kt-branch-select:focus-visible {
    outline: 2px solid #E8001C;
    outline-offset: 2px;
}

.kt-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    margin-bottom: 24px;
}

.kt-status {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-weight: 600;
}

.kt-status-success {
    background: #D5F5E2;
    color: #066B2C;
}

.kt-status-error {
    background: #FEE2E2;
    color: #B91C1C;
}

.kt-status-info {
    background: #DBEAFE;
    color: #1E40AF;
}

.kt-preview-note {
    color: #5A5A5A;
    font-size: 14px;
    font-style: italic;
    margin: 16px 0 0;
}
</style>
