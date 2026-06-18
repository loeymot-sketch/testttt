import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// [prod-finale 2026-06-17 P1 food-safety] The KDS items-board ("Préparations") column hand-rolls its
// per-item rendering (item_name / variations / extras / instruction) instead of going through the
// kdsCustomization renderItem helper — so it silently dropped the allergen warning even though the backend
// (KitchenDisplaySystemOrderService line-split by allergen-hash + KDSOrderItemsResource.allergens_snapshot)
// is purpose-built to make that column allergen-aware. This sentinel locks the inline allergen render on the
// items-board <li> so a future template refactor cannot re-open the food-safety masking gap. Source-level
// (the component is 2800+ lines / heavy to mount); it asserts the exact `orderItems` <li> block renders the
// structured allergens_snapshot, not merely that the string appears somewhere else (cards/modal).
describe('KDS items-board allergen warning (prod-finale P1 food-safety)', () => {
    const src = readFileSync(
        resolve(__dirname, '../../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
        'utf8',
    );

    // Isolate the items-board <li> loop block: from the `v-for="(orderItem, oIdx) in orderItems"` opening
    // to the quantity badge that closes each row. The allergen render MUST live inside this block.
    const start = src.indexOf('in orderItems"');
    const end = src.indexOf('rounded-full bg-black', start); // the quantity badge unique to the items-board row

    it('the items-board <li> loop exists and is bounded by the quantity badge', () => {
        expect(start).toBeGreaterThan(-1);
        expect(end).toBeGreaterThan(start);
    });

    it('renders the per-item allergens_snapshot inside the items-board row (not just on cards/modal)', () => {
        const block = src.slice(start, end);
        expect(block).toContain('orderItem.allergens_snapshot');
        expect(block).toContain('sortedAllergens(orderItem.allergens_snapshot)');
        // guarded so empty snapshots render nothing (no empty badge)
        expect(block).toMatch(/Array\.isArray\(orderItem\.allergens_snapshot\)\s*&&\s*orderItem\.allergens_snapshot\.length\s*>\s*0/);
        // an accessible warning label, mirroring the card badge
        expect(block).toContain('label.kds_allergens_badge');
    });
});
