import { describe, expect, it } from 'vitest';
import {
    makeMeatAttribute,
    makeMeatItem,
    posTryIncrement,
    kioskTryIncrement,
    hasAttributeSelectionError,
    parityDisplayTotal,
    filterPosVariationChoices,
    kioskMeatCatalogIds,
} from './__fixtures__/variationParityFixtures';

describe('POS ↔ Kiosk variation parity (T03)', () => {
    it('case 1: min=1 max=1 accepts exactly one variation slot; totals match', () => {
        const attr = makeMeatAttribute(1, 1, false);
        const v1 = { id: 101, name: 'A', price: 0.5 };
        const v2 = { id: 102, name: 'B', price: 0.25 };
        const item = makeMeatItem(attr, [v1, v2]);
        const kioskSlots = 1;

        let posV = [];
        let kioskV = [];
        posV = posTryIncrement(attr, item.variations[attr.id][0], posV);
        kioskV = kioskTryIncrement(attr, item.variations[attr.id][0], kioskV, kioskSlots);
        expect(posV).toEqual(kioskV);
        expect(hasAttributeSelectionError(attr, posV)).toBe(false);

        const posV2 = posTryIncrement(attr, item.variations[attr.id][1], posV);
        const kioskV2 = kioskTryIncrement(attr, item.variations[attr.id][1], kioskV, kioskSlots);
        expect(posV2).toEqual(posV);
        expect(kioskV2).toEqual(kioskV);

        expect(parityDisplayTotal(item, posV)).toBe(parityDisplayTotal(item, kioskV));
    });

    it('case 2: min=2 max=2 accepts two slots (2× same OK); totals match', () => {
        const attr = makeMeatAttribute(2, 2, true);
        const v1 = { id: 201, name: 'Steak', price: 1 };
        const item = makeMeatItem(attr, [v1, { id: 202, name: 'Poulet', price: 0 }]);
        const kioskSlots = 2;

        let posV = [];
        let kioskV = [];
        posV = posTryIncrement(attr, item.variations[attr.id][0], posV);
        kioskV = kioskTryIncrement(attr, item.variations[attr.id][0], kioskV, kioskSlots);
        posV = posTryIncrement(attr, item.variations[attr.id][0], posV);
        kioskV = kioskTryIncrement(attr, item.variations[attr.id][0], kioskV, kioskSlots);

        expect(posV).toEqual(kioskV);
        expect(hasAttributeSelectionError(attr, posV)).toBe(false);
        expect(parityDisplayTotal(item, posV)).toBe(parityDisplayTotal(item, kioskV));
    });

    it('case 3: min=3 max=3 accepts 2+1 mix; totals match', () => {
        const attr = makeMeatAttribute(3, 3, true);
        const defs = [
            { id: 301, name: 'A', price: 1 },
            { id: 302, name: 'B', price: 2 },
        ];
        const item = makeMeatItem(attr, defs);
        const kioskSlots = 3;

        let posV = [];
        let kioskV = [];
        const meatA = item.variations[attr.id][0];
        const meatB = item.variations[attr.id][1];
        posV = posTryIncrement(attr, meatA, posV);
        kioskV = kioskTryIncrement(attr, meatA, kioskV, kioskSlots);
        posV = posTryIncrement(attr, meatA, posV);
        kioskV = kioskTryIncrement(attr, meatA, kioskV, kioskSlots);
        posV = posTryIncrement(attr, meatB, posV);
        kioskV = kioskTryIncrement(attr, meatB, kioskV, kioskSlots);

        expect(posV).toEqual(kioskV);
        expect(hasAttributeSelectionError(attr, posV)).toBe(false);
        expect(parityDisplayTotal(item, posV)).toBe(parityDisplayTotal(item, kioskV));
    });

    it('case 4: max=3 refuses a 4th increment on both paths', () => {
        const attr = makeMeatAttribute(1, 3, true);
        const item = makeMeatItem(attr, [{ id: 401, name: 'X', price: 0 }]);
        const kioskSlots = 3;
        const meat = item.variations[attr.id][0];

        let posV = [];
        let kioskV = [];
        for (let i = 0; i < 3; i += 1) {
            posV = posTryIncrement(attr, meat, posV);
            kioskV = kioskTryIncrement(attr, meat, kioskV, kioskSlots);
        }
        const posBlocked = posTryIncrement(attr, meat, posV);
        const kioskBlocked = kioskTryIncrement(attr, meat, kioskV, kioskSlots);
        expect(posBlocked).toEqual(posV);
        expect(kioskBlocked).toEqual(kioskV);
    });

    it('case 5: min=2 — total slots below 2 yields selection error (checkout blocked)', () => {
        const attr = makeMeatAttribute(2, 4, true);
        const item = makeMeatItem(attr, [
            { id: 501, name: 'A', price: 0 },
            { id: 502, name: 'B', price: 0 },
        ]);
        const meatA = item.variations[attr.id][0];
        const meatB = item.variations[attr.id][1];

        let posV = [];
        posV = posTryIncrement(attr, meatA, posV);
        posV = posTryIncrement(attr, meatB, posV);
        expect(hasAttributeSelectionError(attr, posV)).toBe(false);

        let kioskV = [];
        kioskV = kioskTryIncrement(attr, meatA, kioskV, 4);
        kioskV = kioskTryIncrement(attr, meatB, kioskV, 4);
        expect(kioskV).toEqual(posV);

        const underMin = [{ id: 502, item_attribute_id: attr.id, quantity: 1, name: 'B', variation_name: attr.name }];
        expect(hasAttributeSelectionError(attr, underMin)).toBe(true);
    });

    it('case 6: eighty-sixed variation (inactive status) not offered in POS or kiosk meat catalog', () => {
        const attr = makeMeatAttribute(1, 3, true);
        const item = makeMeatItem(attr, [
            { id: 601, name: 'OK', price: 0, status: 5 },
            { id: 602, name: 'EightySixed', price: 0, status: 10 },
        ]);

        const list = item.variations[attr.id];
        const posIds = filterPosVariationChoices(list).map((v) => v.id);
        const kioskIds = kioskMeatCatalogIds(item);

        expect(posIds).not.toContain(602);
        expect(kioskIds).not.toContain(602);
        expect(posIds.includes(601) && kioskIds.includes(601)).toBe(true);
    });
});
