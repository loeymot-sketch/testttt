<template>
    <!--
        CatalogChangeToastComponent — Mission #2 Vague 1 action 1.3.

        Non-blocking toast that surfaces a CatalogChanged or
        ComposerProfileChanged event arriving while the customer has a
        wizard open or a non-empty cart.

        Drives off the useCatalogChangeNotifier composable
        (resources/js/composables/useCatalogChangeNotifier.js); this
        component is the visual surface and the keyboard / screen-reader
        accessible affordance that lets the customer acknowledge the
        update or jump to the affected step.

        Audit  : reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §A.1 #2 + §C #1
        Plan   : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md tasks 1.3 + 2.3
        Status : SKELETON — implementation TODO Codex.

        Sibling: KioskToastComponent.vue (generic toast). This one is
        purpose-built for catalog-change semantics (diff payload + actions).
    -->
    <transition name="kiosk-toast-slide">
        <div
            v-if="visible"
            class="kiosk-catalog-change-toast"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            data-testid="kiosk-catalog-change-toast"
            :data-severity="severity"
        >
            <div class="flex items-start gap-3">
                <span class="mt-0.5" aria-hidden="true">
                    <i :class="iconClass"></i>
                </span>
                <div class="flex-1">
                    <p class="text-sm font-semibold">
                        {{ message || tr('catalog.menu_refreshed_toast', undefined, 'Le menu a été mis à jour') }}
                    </p>
                    <p v-if="hasRemovedSelections" class="mt-1 text-xs">
                        {{ tr('catalog.menu_refreshed_recheck_selection', undefined, 'Vérifiez vos choix.') }}
                    </p>
                    <div v-if="canShowReviewAction" class="mt-2 flex gap-2">
                        <button
                            type="button"
                            class="kiosk-btn kiosk-btn-primary text-xs"
                            data-testid="kiosk-catalog-change-review"
                            @click="onReview"
                        >
                            {{ tr('catalog.review_cart', undefined, 'Vérifier mon panier') }}
                        </button>
                        <button
                            type="button"
                            class="kiosk-btn kiosk-btn-ghost text-xs"
                            data-testid="kiosk-catalog-change-dismiss"
                            @click="dismiss"
                        >
                            {{ $t('label.dismiss') }}
                        </button>
                    </div>
                </div>
                <button
                    v-if="!canShowReviewAction"
                    type="button"
                    class="kiosk-btn kiosk-btn-icon"
                    data-testid="kiosk-catalog-change-close"
                    :aria-label="$t('label.dismiss')"
                    @click="dismiss"
                >
                    <i class="lab lab-close" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </transition>
</template>

<script>
/**
 * CatalogChangeToastComponent — visual surface for catalog mid-session
 * mutation events.
 *
 * Props (driven by useCatalogChangeNotifier output):
 *   visible:           Boolean   — toast visibility flag.
 *   message:           String    — pre-translated message (or falsy → default i18n key).
 *   severity:          'info'|'warning' — info = silent toast, warning = require ack.
 *   removedSelections: Array     — diff entries from useCatalogChangeNotifier.
 *
 * Emits:
 *   review  — user clicked "Vérifier mon panier"; parent navigates to cart.
 *   dismiss — user dismissed toast manually.
 *
 * TODO Codex (plan task 1.3 + 2.3):
 *   1. Bind useCatalogChangeNotifier output to this component via props.
 *      Suggested integration in KioskAppComponent.vue:
 *        const notifier = useCatalogChangeNotifier({ store, ... })
 *        <CatalogChangeToastComponent
 *           :visible="notifier.toastVisible.value"
 *           :message="notifier.toastMessage.value"
 *           :removed-selections="notifier.removedSelectionsByStep.value"
 *           @review="onReviewCart"
 *           @dismiss="notifier.dismiss()"
 *        />
 *   2. i18n keys (fr/en/ar — also bn/de present in repo):
 *        catalog.menu_refreshed_toast
 *        catalog.cart_lines_removed (with {count} placeholder)
 *        catalog.review_cart
 *      Source location: resources/js/languages/{fr,en,ar,bn,de}.json
 *   3. A11y:
 *        - role="status" + aria-live="polite" already in template.
 *        - For severity=warning, escalate to role="alert" + aria-live="assertive".
 *        - Honor data-kiosk-reduced-motion=true to swap the slide transition
 *          with an opacity-only fade (Phase 8.9 EAA compliance).
 *        - Ensure focus management: clicking "Review cart" moves focus to
 *          the cart panel; dismissing returns focus to the originating
 *          step element.
 *   4. Sentinel: tests/js/kioskWizardCatalogChangedHandling.spec.js asserts:
 *        - visible flips when CatalogChanged event arrives.
 *        - removed-selections diff payload is rendered.
 *        - review event payload contains affected step ids.
 *        - dismiss event clears toast.
 */
const SEVERITY_ICON_CLASS = {
    info: 'lab lab-information',
    warning: 'lab lab-warning',
};

export default {
    name: 'CatalogChangeToastComponent',
    props: {
        visible: { type: Boolean, default: false },
        message: { type: String, default: '' },
        severity: {
            type: String,
            default: 'info',
            validator: (v) => ['info', 'warning'].includes(v),
        },
        removedSelections: { type: [Array, Object], default: () => [] },
    },
    emits: ['review', 'dismiss'],
    data() {
        return {
            dismissTimer: null,
        };
    },
    computed: {
        iconClass() {
            return SEVERITY_ICON_CLASS[this.severity] || SEVERITY_ICON_CLASS.info;
        },
        flattenedRemovedSelections() {
            if (Array.isArray(this.removedSelections)) {
                return this.removedSelections;
            }
            if (this.removedSelections && typeof this.removedSelections === 'object') {
                return Object.values(this.removedSelections).flat();
            }
            return [];
        },
        hasRemovedSelections() {
            return this.flattenedRemovedSelections.length > 0;
        },
        removedCount() {
            return this.flattenedRemovedSelections.length;
        },
        canShowReviewAction() {
            return this.severity === 'warning' || this.hasRemovedSelections;
        },
    },
    watch: {
        visible: {
            immediate: true,
            handler(isVisible) {
                this.clearDismissTimer();
                if (!isVisible) {
                    return;
                }
                this.dismissTimer = setTimeout(() => {
                    this.$emit('dismiss');
                }, 5000);
            },
        },
    },
    beforeUnmount() {
        this.clearDismissTimer();
    },
    methods: {
        onReview() {
            this.$emit('review', { removedSelections: this.flattenedRemovedSelections });
        },
        dismiss() {
            this.clearDismissTimer();
            this.$emit('dismiss');
        },
        clearDismissTimer() {
            if (this.dismissTimer !== null) {
                clearTimeout(this.dismissTimer);
                this.dismissTimer = null;
            }
        },
        tr(key, params = undefined, fallback = key) {
            const translated = this.$t?.(key, params);
            return translated && translated !== key ? translated : fallback;
        },
    },
};
</script>

<style scoped>
.kiosk-catalog-change-toast {
    position: fixed;
    top: 1rem;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    max-width: 32rem;
    padding: 1rem;
    border-radius: 0.5rem;
    background: var(--kiosk-surface-elevated, #ffffff);
    color: var(--kiosk-text-primary, #1f2937);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    border: 1px solid var(--kiosk-border-default, #e2e8f0);
}

.kiosk-catalog-change-toast[data-severity='warning'] {
    border-color: var(--kiosk-status-warning, #f59e0b);
}

.kiosk-toast-slide-enter-active,
.kiosk-toast-slide-leave-active {
    transition: transform 200ms ease-out, opacity 200ms ease-out;
}
.kiosk-toast-slide-enter-from,
.kiosk-toast-slide-leave-to {
    opacity: 0;
    transform: translate(-50%, -1rem);
}

/* WCAG 2.3.3 — Phase 8.9 reduced motion. */
[data-kiosk-reduced-motion='true'] .kiosk-toast-slide-enter-active,
[data-kiosk-reduced-motion='true'] .kiosk-toast-slide-leave-active {
    transition: opacity 120ms ease-out;
    transform: translateX(-50%);
}
</style>
