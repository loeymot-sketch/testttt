import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(
    path.join(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf8'
);

// [W6A-P1-01 2026-07-06] Restaurer une commande parkée renvoie delivery_inline.addressText
// = null (ConvertEmptyStringsToNull sur le "" envoyé au park). Tout accès `.trim()` non
// gardé provoquait un TypeError en BOUCLE à chaque render jusqu'au reload. Garde: `|| ''`.
describe('POS parked-order restore — null addressText guard', () => {
    it('never calls .trim() on a possibly-null addressText', () => {
        const unguarded = source.match(/deliveryInline\.addressText\.trim\(\)/g) || [];
        expect(unguarded).toEqual([]);
    });

    it('guards the manual-distance template condition', () => {
        expect(source).toContain("(deliveryInline.addressText || '').trim().length >= 3");
    });
});
