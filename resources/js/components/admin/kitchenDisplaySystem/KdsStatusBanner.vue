<!--
  [kds/sprint-2 V-3] Single consolidated status banner. Replaces the 5-banner
  stack that consumed ~10% of screen height in the legacy template
  (ConnectionStatusBanner + kds-sync-mode-banner + admin-polling-hint +
   cap-warning + bump-local-only-notice).

  Priority order (only highest-priority message renders):
    app-error  → red 40px banner (persistent applicative error — poll failed,
                 403, status conflict — mirrors legacy kds-error-banner)
    error      → red 40px banner with spinner (network lost > 60s)
    sync-?     → orange "synchro incertaine" (no confirmed data refresh >75s —
                 socket may be UP while the queue worker is down = stale board)
    cap-full   → list at server cap
    cap-warning→ near cap (n >= 200)
    fallback   → polling-mode fallback (WS down, polling every 5s)
    sync-mode  → admin-polling-hint (admin cross-branch view)
    bump-notice→ local-only bump persistence

  [KDS-V2-BLIND-BANNERS 2026-07-22] app-error + sync-? added: both signals
  previously lived ONLY in the legacy v-else template (kds-error-banner +
  kds-sync-stamp) and were invisible in the default V2 layout.

  Owner-acceptable: SYNC · LOCAL tag on the right when any banner is shown.
-->
<template>
  <div v-if="bannerData" :class="['kds-banner', `kds-banner--${bannerData.level}`, { 'kds-banner--reserve-gutter': reserveRightGutter }]" role="status">
    <div class="kds-banner__main">
      <span class="kds-banner__icon" :style="{ background: bannerData.iconBg }">
        <svg
          v-if="bannerData.spinner"
          class="kds-banner__spinner"
          width="14"
          height="14"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="3"
          stroke-linecap="round"
          aria-hidden="true"
        >
          <path d="M21 12a9 9 0 1 1-6.219-8.56" />
        </svg>
        <svg
          v-else
          width="11"
          height="11"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="3"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M12 9v4M12 17h.01" />
        </svg>
      </span>
      <span class="kds-banner__text">{{ bannerData.text }}</span>
    </div>
    <span class="kds-banner__tag keep-latin">{{ bannerData.tag }}</span>
  </div>
</template>

<script>
export default {
    name: 'KdsStatusBanner',
    props: {
        // explicit signals — orchestrator passes them in
        offlineSince: { type: Number, default: null },     // ms timestamp or null
        listAtCap: { type: Boolean, default: false },
        nearCap: { type: Number, default: 0 },              // n orders, 0 = hide
        fallbackMode: { type: Boolean, default: false },    // WS down, polling
        adminPollingHint: { type: Boolean, default: false },// cross-branch view
        bumpLocalOnlyNotice: { type: Boolean, default: false },
        // [FIX-4 C-002 2026-06-02] reserve a right gutter so the banner tag
        // (e.g. "LOCAL") does not sit under the overflow chip in KdsV2Grid.
        reserveRightGutter: { type: Boolean, default: false },
        // [#10] Reactive clock injected by the parent's 1s ticker so the offline
        // elapsed counter actually ticks (Date.now() alone is not a reactive dep).
        now: { type: Number, default: 0 },
        // [KDS-V2-BLIND-BANNERS 2026-07-22] Data-freshness + app-error signals,
        // fed by the orchestrator's EXISTING legacy computeds (syncBadgeUncertain
        // / kdsErrorBanner) so V2 is no longer blind to stale data and errors.
        syncUncertain: { type: Boolean, default: false },
        errorMessage: { type: String, default: '' },
    },
    methods: {
        // [KDS-V2-BLIND-BANNERS] i18n-safe fallback: vue-i18n returns the KEY
        // itself on a missing translation, so `$t(k) || fallback` would leak a
        // raw key on the kitchen screen. Fall back to the FR literal until the
        // lang files gain the key (lang files are NOT edited by this change).
        tOr(key, fallback) {
            const v = typeof this.$t === 'function' ? this.$t(key) : null;
            return (v && v !== key) ? v : fallback;
        },
    },
    computed: {
        bannerData() {
            // [#10] Use the reactive `now` prop when provided so the elapsed
            // counter re-computes each tick; fall back to Date.now() if a caller
            // hasn't wired the ticker (preserves prior behavior, just non-live).
            const nowMs = (typeof this.now === 'number' && this.now > 0) ? this.now : Date.now();
            // Priority: app-error > offline > sync-uncertain > cap-full >
            //           cap-warning > fallback > sync > bump-notice
            // [KDS-V2-BLIND-BANNERS] app-error outranks everything (explicit,
            // actionable failure). The offline tier deliberately stays ABOVE
            // sync-uncertain: when the connection is lost >60s the uncertainty
            // is implied, and the red elapsed counter is strictly more
            // informative than the orange "maybe stale" banner — demoting it
            // would regress an existing tier.
            if (this.errorMessage) {
                return {
                    level: 'error',
                    text: this.errorMessage,
                    iconBg: '#FFFFFF',
                    tag: 'ERREUR',
                };
            }
            if (this.offlineSince && nowMs - this.offlineSince > 60_000) {
                const ms = nowMs - this.offlineSince;
                const m = Math.floor(ms / 60_000);
                const s = Math.floor((ms % 60_000) / 1000);
                return {
                    level: 'error',
                    text: this.$t('label.kds_connection_lost_long', { m, s }),
                    iconBg: '#FFFFFF',
                    tag: 'OFFLINE',
                    spinner: true,
                };
            }
            if (this.syncUncertain) {
                return {
                    level: 'warning',
                    text: this.tOr(
                        'label.kds_sync_uncertain_banner',
                        'Synchro incertaine — données peut-être datées'
                    ),
                    iconBg: '#F59E0B',
                    tag: 'SYNC · ?',
                };
            }
            if (this.listAtCap) {
                return {
                    level: 'warning',
                    text: this.$t('label.kds_order_list_full_warning', { n: this.nearCap }),
                    iconBg: '#F59E0B',
                    tag: 'CAP · MAX',
                };
            }
            if (this.nearCap >= 200) {
                return {
                    level: 'warning',
                    text: this.$t('label.kds_order_cap_warning', { n: this.nearCap }),
                    iconBg: '#F59E0B',
                    tag: 'CAP · WARN',
                };
            }
            if (this.fallbackMode) {
                return {
                    level: 'warning',
                    text: this.$t('label.kds_fallback_banner'),
                    iconBg: '#F59E0B',
                    tag: 'SYNC · LOCAL',
                };
            }
            if (this.adminPollingHint) {
                return {
                    level: 'info',
                    text: this.$t('label.kds_admin_polling_hint'),
                    iconBg: '#3B82F6',
                    tag: 'SYNC · ADMIN',
                };
            }
            if (this.bumpLocalOnlyNotice) {
                return {
                    level: 'neutral',
                    text: this.$t('label.kds_bump_local_only_notice'),
                    iconBg: '#9CA3AF',
                    tag: 'LOCAL',
                };
            }
            return null;
        },
    },
};
</script>

<style scoped>
.kds-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    height: 32px;
    border-bottom: 1px solid;
    font-family: 'Inter', system-ui, sans-serif;
}
.kds-banner--error {
    height: 40px;
    background: #DC2626;
    color: #FFFFFF;
    border-color: #B91C1C;
}
.kds-banner--warning {
    background: #FFFBEB;
    color: #92400E;
    border-color: #FDE68A;
}
.kds-banner--info {
    background: #EFF6FF;
    color: #1E40AF;
    border-color: #BFDBFE;
}
.kds-banner--neutral {
    background: #F9FAFB;
    color: #4B5563;
    border-color: #E5E7EB;
}
.kds-banner__main {
    display: flex;
    align-items: center;
    gap: 8px;
}
.kds-banner__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    width: 18px;
    height: 18px;
    border-radius: 9999px;
}
.kds-banner--error .kds-banner__icon {
    background: rgba(255, 255, 255, 0.18);
}
.kds-banner__spinner {
    animation: kds-banner-spin 1s linear infinite;
}
@keyframes kds-banner-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
.kds-banner__text {
    font-size: 13px;
    font-weight: 600;
}
.kds-banner--error .kds-banner__text {
    font-size: 14px;
    font-weight: 700;
}
.kds-banner__tag {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.15em;
    opacity: 0.85;
}
/* [FIX-4 C-002 2026-06-02] When the KdsV2Grid overflow chip is active it sits
   absolute top-right and would overlap this banner's tag (e.g. "LOCAL").
   Reserve a right gutter so the tag clears the chip.
   NOTE: 152px is an ESTIMATE (chip ~120px + right:16px + gap) and is
   i18n-dependent — MUST be visually confirmed in the fix-wave capture. */
.kds-banner--reserve-gutter {
    padding-right: 152px;
}

@media (prefers-reduced-motion: reduce) {
    .kds-banner__spinner {
        animation: none !important;
    }
}
</style>
