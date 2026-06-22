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

    // [prod-finale 2026-06-17 P2 food-safety] The printed paper kitchen ticket is an OFFLINE chef surface
    // with no on-screen badge/modal fallback — it MUST carry the structured allergen snapshot. Lock the
    // print path so a refactor can't drop it (cycle-5 finding).
    it('the printed kitchen ticket renders the structured allergens_snapshot', () => {
        const pStart = src.indexOf('printKitchenTicket(');
        expect(pStart).toBeGreaterThan(-1);
        const pEnd = src.indexOf("window.open(", pStart); // ticket HTML is assembled before the print window opens
        expect(pEnd).toBeGreaterThan(pStart);
        const printBlock = src.slice(pStart, pEnd);
        expect(printBlock).toContain('item.allergens_snapshot');
        expect(printBlock).toContain('sortedAllergens(item.allergens_snapshot)');
        expect(printBlock).toMatch(/Array\.isArray\(item\.allergens_snapshot\)\s*&&\s*item\.allergens_snapshot\.length/);
    });

    // All 4 KDS card lanes (dine-in/online/takeaway/kiosk) + the items-board must each render the per-item
    // allergen chip → 5 guarded allergens_snapshot template renders total (cycle-5 lane-parity heal).
    it('every KDS card lane + the items-board renders a guarded per-item allergen chip (>=5 renders)', () => {
        const renders = (src.match(/Array\.isArray\(\w+\.allergens_snapshot\)\s*&&\s*\w+\.allergens_snapshot\.length\s*>\s*0/g) || []);
        expect(renders.length).toBeGreaterThanOrEqual(5);
    });
});
