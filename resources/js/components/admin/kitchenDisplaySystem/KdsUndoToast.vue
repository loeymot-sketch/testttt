<!--
  [kds/sprint-2 V-4] 3-second undo toast. Shows after chef taps Prêt on a card.
  Card visually fades but the actual server PATCH is delayed by 3s — clicking
  Annuler within the window cancels the PATCH (kdsInflight pending queue).
  After 3s, the toast slides out and the PATCH fires.

  Design tokens (DESIGN_SPEC_KDS_V2_2026-05-11.md §3.4):
    bg #0F172A (dark slate)
    title 18px / 700 white
    subtext 13px #94A3B8
    undo button white bg, #0F172A text, shadow #CBD5E1
    shrink-bar 3s linear green #16A34A

  [Wave T R1 F3 WT-B-R1-001 2026-05-20] Repositioned bottom-right corner with
  smaller min-width (360px). Previously position:absolute top:110px center
  min-width:620px geometrically covered Card B (Order#70 A0002 header)
  during the 3s undo window — chef could not read the adjacent red-critical
  card's queue number. Now pinned to viewport corner via position:fixed bottom
  right so it never overlaps the 4×2 grid cards. Width reduced to 360px so it
  fits inline "N°X marquée prête · Annuler" without wrapping.
-->
<template>
  <div
    v-if="toast"
    class="kds-toast"
    role="status"
    aria-live="assertive"
    aria-atomic="true"
  >
    <div class="kds-toast__inner">
      <div class="kds-toast__row">
        <div class="kds-toast__check" aria-hidden="true">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12l5 5L20 7" />
          </svg>
        </div>
        <div class="kds-toast__content">
          <div class="kds-toast__title">{{ titleText }}</div>
          <div class="kds-toast__sub">
            <span class="kds-toast__printer" aria-hidden="true">🖨</span>
            <span>{{ $t('label.kds_print_done') }}</span>
          </div>
        </div>
        <button
          type="button"
          class="kds-toast__undo"
          @click.prevent="onUndo"
          :aria-label="$t('label.kds_undo_bump_aria')"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 7v6h6" />
            <path d="M3 13a9 9 0 1 0 3-7" />
          </svg>
          {{ $t('label.kds_undo_bump') }}
        </button>
      </div>
      <div class="kds-toast__progress">
        <div class="kds-toast__progress-bar"></div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
    name: 'KdsUndoToast',
    props: {
        toast: {
            type: Object,
            default: null,
            // expected shape: { id, queueNo, expiresAt }
        },
    },
    emits: ['undo'],
    computed: {
        titleText() {
            if (!this.toast) {
                return '';
            }
            return this.$t('label.kds_toast_bumped', { n: this.toast.queueNo });
        },
    },
    methods: {
        onUndo() {
            if (this.toast) {
                this.$emit('undo', this.toast.id);
            }
        },
    },
};
</script>

<style scoped>
/* [Wave T R1 F3 WT-B-R1-001 2026-05-20] position:fixed bottom-right corner.
   Previously position:absolute top:110px center min-width:620px landed on
   top of Row 1 grid cards inside `.kds-v2` (position:relative). Now anchored
   to the viewport so it never overlaps the 4×2 card grid regardless of
   viewport size. Mobile/RTL handled below via media query / [dir="rtl"]. */
.kds-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    left: auto;
    top: auto;
    min-width: 320px;
    max-width: 420px;
    z-index: 30;
    animation: kds-toast-in 220ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
}
[dir="rtl"] .kds-toast {
    right: auto;
    left: 24px;
}
.kds-toast__inner {
    border-radius: 12px;
    overflow: hidden;
    background: #0F172A;
    color: #FFFFFF;
    box-shadow: 0 20px 60px rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.06);
}
.kds-toast__row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
}
.kds-toast__check {
    width: 36px;
    height: 36px;
    border-radius: 9999px;
    background: #16A34A;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.kds-toast__content {
    flex: 1;
}
.kds-toast__title {
    font-size: 15px;
    font-weight: 700;
    line-height: 1.2;
    color: #FFFFFF;
}
.kds-toast__sub {
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #94A3B8;
}
.kds-toast__undo {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 14px;
    height: 36px;
    border: 0;
    border-radius: 8px;
    background: #FFFFFF;
    color: #0F172A;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 2px 0 0 #CBD5E1;
    transition: transform 100ms ease;
    flex-shrink: 0;
}
.kds-toast__undo:active {
    transform: translateY(1px);
}
.kds-toast__undo:focus-visible {
    outline: 4px solid #EA580C;
    outline-offset: 2px;
}
.kds-toast__progress {
    height: 4px;
    background: rgba(255, 255, 255, 0.1);
}
.kds-toast__progress-bar {
    height: 100%;
    background: #16A34A;
    width: 100%;
    transform-origin: left center;
    animation: kds-toast-shrink 3s linear forwards;
}
[dir="rtl"] .kds-toast__progress-bar {
    transform-origin: right center;
}
/* [Wave T R1 F3 WT-B-R1-001 2026-05-20] Slide up from below — matches the
   new bottom-right anchor (was slide-down from translate(-50%,-120%)
   when toast was top-center). */
@keyframes kds-toast-in {
    from { transform: translateY(120%); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}
@keyframes kds-toast-shrink {
    from { transform: scaleX(1); }
    to   { transform: scaleX(0); }
}

@media (prefers-reduced-motion: reduce) {
    .kds-toast,
    .kds-toast__progress-bar,
    .kds-toast__undo {
        animation: none !important;
        transition: none !important;
    }
}
</style>
