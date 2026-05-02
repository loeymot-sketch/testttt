/**
 * useCatalogChangeNotifier.js — Mission #2 Vague 1 action 1.3.
 *
 * Composable Vue 3 that wraps the kiosk wizard / cart in a UX-aware
 * listener for `CatalogChanged` and `ComposerProfileChanged` events.
 *
 * When a catalog mutation arrives WHILE the user is mid-wizard or has a
 * non-empty cart, this composable:
 *
 *   1. Diffs the incoming projection vs the snapshot the user is editing.
 *   2. Emits a non-blocking toast: "Le menu a été mis à jour, votre
 *      sélection a été vérifiée" (i18n key catalog.menu_refreshed_toast).
 *   3. If a chosen variation/extra has been removed from the new
 *      projection, the composable highlights the affected step in the
 *      wizard, focuses it, and announces it via the a11y helper.
 *   4. Calls store.dispatch('kioskCart/pruneUnavailable') to remove
 *      stale lines safely.
 *   5. Tracks the event with kioskAnalytics so we can measure how often
 *      this race condition happens in production.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §A.1 #2 + §C #1
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md tasks 1.3 + 2.3
 *
 * STUB — Skeleton only. Implementation TODO under plan task 1.3.
 */

import { getCurrentInstance, onBeforeUnmount, onMounted, ref } from 'vue';
import { announce } from '../helpers/a11y/announcer.js';

const TOAST_TTL_MS = 5_000;
const ITEM_REMOVED_STEP_ID = 'cart';

function normalizeId(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const numeric = Number(value);
    return Number.isFinite(numeric) ? String(numeric) : String(value);
}

function idsMatch(a, b) {
    const left = normalizeId(a);
    const right = normalizeId(b);
    return left !== null && right !== null && left === right;
}

function toArray(value) {
    if (Array.isArray(value)) {
        return value;
    }
    if (value && typeof value === 'object') {
        return Object.values(value).flatMap((entry) => toArray(entry));
    }
    return [];
}

function itemOptionSet(options, candidateKeys = ['id']) {
    return new Set(toArray(options)
        .map((option) => {
            for (const key of candidateKeys) {
                const normalized = normalizeId(option?.[key]);
                if (normalized !== null) {
                    return normalized;
                }
            }
            return null;
        })
        .filter((id) => id !== null));
}

function projectionItems(newProjection) {
    const byId = new Map();
    const add = (item) => {
        const id = normalizeId(item?.id ?? item?.item_id);
        if (id !== null) {
            byId.set(id, item);
        }
    };

    toArray(newProjection?.items).forEach(add);
    toArray(newProjection?.categories).forEach((category) => {
        toArray(category?.items).forEach(add);
    });

    return byId;
}

function cartLineId(line, index) {
    return line?.cart_line_id
        ?? line?.line_id
        ?? line?.uuid
        ?? line?.id
        ?? `cart-line-${index}`;
}

function optionLabel(option, fallback = '') {
    return option?.label
        ?? option?.name
        ?? option?.variation_name
        ?? option?.addon_item_name
        ?? fallback;
}

function selectedVariations(line) {
    return toArray(line?.item_variations ?? line?.variations).map((variation) => ({
        id: variation?.id ?? variation?.variation_id ?? variation?.option_id,
        step_id: variation?.step_id ?? variation?.variation_name ?? 'variation',
        label: optionLabel(variation, 'Variation'),
    }));
}

function selectedExtras(line) {
    return toArray(line?.item_extras ?? line?.extras).map((extra) => ({
        id: extra?.id ?? extra?.extra_id ?? extra?.option_id,
        step_id: extra?.step_id ?? 'extras',
        label: optionLabel(extra, 'Extra'),
    }));
}

function selectedAddons(line) {
    return toArray(line?.item_addons ?? line?.addons).map((addon) => ({
        id: addon?.id ?? addon?.addon_id ?? addon?.addon_item_id ?? addon?.item_id ?? addon?.option_id,
        step_id: addon?.step_id ?? addon?.group_label ?? 'addons',
        label: optionLabel(addon, 'Addon'),
    }));
}

function groupByStep(removedSelections) {
    return removedSelections.reduce((acc, selection) => {
        const stepId = selection.step_id || 'unknown';
        acc[stepId] = acc[stepId] || [];
        acc[stepId].push(selection);
        return acc;
    }, {});
}

function flattenGroupedSelections(grouped) {
    if (Array.isArray(grouped)) {
        return grouped;
    }
    if (!grouped || typeof grouped !== 'object') {
        return [];
    }
    return Object.values(grouped).flat();
}

export function useCatalogChangeNotifier({
    store,
    eventContract,
    branchId,
    i18n,
    analytics = null,
    hasOpenWizard = null,
    autoSubscribe = true,
} = {}) {
    const toastVisible = ref(false);
    const toastMessage = ref('');
    const toastSeverity = ref('info');
    const removedSelectionsByStep = ref({});

    let unsubscribe = null;
    let toastTimer = null;

    function t(key, params = undefined, fallback = key) {
        const translated = i18n?.t?.(key, params);
        return translated && translated !== key ? translated : fallback;
    }

    function resolveBranchId() {
        const value = typeof branchId === 'function' ? branchId() : branchId?.value ?? branchId;
        return normalizeId(value);
    }

    function normalizeEventBranchId(event) {
        return normalizeId(
            event?.branch_id
            ?? event?.branchId
            ?? event?.payload?.branch_id
            ?? event?.payload?.branchId
            ?? null
        );
    }

    function snapshotLines() {
        const snapshot = store?.getters?.['kioskCart/snapshot'];
        if (Array.isArray(snapshot)) {
            return snapshot;
        }
        const items = store?.getters?.['kioskCart/items'] ?? store?.state?.kioskCart?.items ?? [];
        return Array.isArray(items) ? items.map((line) => ({ ...line })) : [];
    }

    function currentProjection(currentBranchId) {
        const getter = store?.getters?.['kioskMenu/forBranch'];
        if (typeof getter === 'function') {
            return getter(currentBranchId);
        }
        return {
            branch_id: currentBranchId,
            categories: store?.getters?.['kioskMenu/categories'] ?? store?.state?.kioskMenu?.categories ?? [],
            items: store?.getters?.['kioskMenu/allItems'] ?? store?.state?.kioskMenu?.items ?? [],
        };
    }

    function hasActiveSession(lines) {
        if (Array.isArray(lines) && lines.length > 0) {
            return true;
        }
        return typeof hasOpenWizard === 'function' ? !!hasOpenWizard() : false;
    }

    /**
     * Compute the diff between the snapshot the user is editing and the
     * new catalog projection.
     */
    function diffSnapshot(snapshotCart, newProjection) {
        const lines = Array.isArray(snapshotCart) ? snapshotCart : [];
        const itemsById = projectionItems(newProjection);
        const removedItems = [];
        const removedSelections = [];

        lines.forEach((line, index) => {
            const itemId = normalizeId(line?.item_id ?? line?.id);
            const item = itemId !== null ? itemsById.get(itemId) : null;
            const lineId = cartLineId(line, index);

            if (!item) {
                removedItems.push({
                    cart_line_id: lineId,
                    item_id: line?.item_id ?? line?.id ?? null,
                    name: line?.name ?? line?.item_name ?? '',
                });
                return;
            }

            const variationIds = itemOptionSet(item?.variations, ['id', 'variation_id', 'option_id']);
            selectedVariations(line).forEach((selection) => {
                const optionId = normalizeId(selection.id);
                if (optionId !== null && !variationIds.has(optionId)) {
                    removedSelections.push({
                        cart_line_id: lineId,
                        step_id: selection.step_id,
                        option_id: selection.id,
                        label: selection.label,
                    });
                }
            });

            const extraIds = itemOptionSet(item?.extras, ['id', 'extra_id', 'option_id']);
            selectedExtras(line).forEach((selection) => {
                const optionId = normalizeId(selection.id);
                if (optionId !== null && !extraIds.has(optionId)) {
                    removedSelections.push({
                        cart_line_id: lineId,
                        step_id: selection.step_id,
                        option_id: selection.id,
                        label: selection.label,
                    });
                }
            });

            const addonIds = itemOptionSet(item?.addons, ['id', 'addon_id', 'addon_item_id', 'item_id', 'option_id']);
            selectedAddons(line).forEach((selection) => {
                const optionId = normalizeId(selection.id);
                if (optionId !== null && !addonIds.has(optionId)) {
                    removedSelections.push({
                        cart_line_id: lineId,
                        step_id: selection.step_id,
                        option_id: selection.id,
                        label: selection.label,
                    });
                }
            });
        });

        return {
            removedItems,
            removedSelections,
        };
    }

    /**
     * Handle an incoming CatalogChanged or ComposerProfileChanged event.
     */
    async function onCatalogChanged(event) {
        const currentBranchId = resolveBranchId();
        const eventBranchId = normalizeEventBranchId(event);

        if (
            eventBranchId !== null
            && currentBranchId !== null
            && !idsMatch(eventBranchId, currentBranchId)
        ) {
            return { ignored: true, reason: 'branch_mismatch' };
        }

        const beforeRefreshSnapshot = snapshotLines();
        if (!hasActiveSession(beforeRefreshSnapshot)) {
            return { ignored: true, reason: 'inactive_session' };
        }

        await store?.dispatch?.('kioskMenu/fetchMenu', {
            branchId: currentBranchId,
            force: true,
        });

        const newProjection = currentProjection(currentBranchId);
        const diff = diffSnapshot(beforeRefreshSnapshot, newProjection);
        const removedItemIds = diff.removedItems.map((item) => item.item_id).filter((id) => id !== null && id !== undefined);

        if (diff.removedItems.length > 0) {
            await store?.dispatch?.('kioskCart/pruneUnavailableLines', removedItemIds);
            pruneRemovedItemLines(beforeRefreshSnapshot, removedItemIds);
        }

        if (diff.removedSelections.length > 0) {
            removedSelectionsByStep.value = groupByStep(diff.removedSelections);
            emitWizardInvalidation(removedSelectionsByStep.value);
        } else {
            removedSelectionsByStep.value = {};
        }

        const removedCount = diff.removedItems.length;
        if (removedCount > 0) {
            toastMessage.value = t(
                'catalog.cart_lines_removed',
                { count: removedCount },
                `Certaines lignes ont été retirées de votre panier (${removedCount})`
            );
            toastSeverity.value = 'warning';
        } else if (diff.removedSelections.length > 0) {
            toastMessage.value = t(
                'catalog.menu_refreshed_recheck_selection',
                undefined,
                'Vérifiez vos choix.'
            );
            toastSeverity.value = 'warning';
        } else {
            toastMessage.value = t(
                'catalog.menu_refreshed_toast',
                undefined,
                'Le menu a été mis à jour'
            );
            toastSeverity.value = 'info';
        }

        showToast();
        announce(toastMessage.value, 'polite');
        trackCatalogChange(diff, currentBranchId);

        return { refreshed: true, diff };
    }

    function pruneRemovedItemLines(snapshotCart, removedItemIds) {
        if (!Array.isArray(snapshotCart) || removedItemIds.length === 0 || typeof store?.commit !== 'function') {
            return;
        }
        const removed = new Set(removedItemIds.map((id) => normalizeId(id)));
        const filtered = snapshotCart.filter((line) => !removed.has(normalizeId(line?.item_id ?? line?.id)));
        if (filtered.length !== snapshotCart.length) {
            store.commit('kioskCart/SET_CART_LINES', filtered);
        }
    }

    function emitWizardInvalidation(groupedSelections) {
        if (typeof window === 'undefined' || typeof window.dispatchEvent !== 'function') {
            return;
        }
        const stepIds = Object.keys(groupedSelections);
        if (stepIds.length === 0) {
            return;
        }
        window.dispatchEvent(new CustomEvent('wizard:invalidate-step', {
            detail: {
                step_ids: stepIds,
                steps: stepIds,
                removedSelectionsByStep: groupedSelections,
                removedSelections: flattenGroupedSelections(groupedSelections),
            },
        }));
    }

    function trackCatalogChange(diff, currentBranchId) {
        try {
            analytics?.track?.('catalog_change_mid_session', {
                removed_items: diff.removedItems.length,
                removed_selections: diff.removedSelections.length,
                branch_id: currentBranchId,
            });
        } catch (_) {}
    }

    function showToast() {
        if (toastTimer !== null) {
            clearTimeout(toastTimer);
        }
        toastVisible.value = true;
        toastTimer = setTimeout(() => {
            toastVisible.value = false;
            toastTimer = null;
        }, TOAST_TTL_MS);
    }

    function dismiss() {
        if (toastTimer !== null) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
        toastVisible.value = false;
        removedSelectionsByStep.value = {};
    }

    function start() {
        if (unsubscribe || !eventContract || typeof eventContract.onEvents !== 'function') {
            return;
        }
        const currentBranchId = resolveBranchId();
        if (currentBranchId === null) {
            return;
        }
        const subscription = eventContract.onEvents(currentBranchId, [
            { broadcastAs: 'CatalogChanged', handler: onCatalogChanged },
            { broadcastAs: 'ComposerProfileChanged', handler: onCatalogChanged },
        ]);
        unsubscribe = subscription?.unsubscribe ?? null;
    }

    function stop() {
        if (unsubscribe) {
            unsubscribe();
            unsubscribe = null;
        }
        if (toastTimer !== null) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
    }

    if (autoSubscribe && getCurrentInstance()) {
        onMounted(start);
        onBeforeUnmount(stop);
    }

    return {
        toastVisible,
        toastMessage,
        toastSeverity,
        removedSelectionsByStep,
        dismiss,
        start,
        stop,
        // Exposed for tests:
        __diffSnapshot: diffSnapshot,
        __onCatalogChanged: onCatalogChanged,
    };
}
