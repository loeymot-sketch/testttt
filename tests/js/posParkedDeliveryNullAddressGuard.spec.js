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

    // [LIVRAISON 2026-10-20] Le bloc « saisie manuelle des km » est supprimé (le serveur ignore toute distance
    // venue de l'écran). La garde null subsiste là où l'adresse est lue : la frappe d'adresse.
    it('guards the address-input handler against a null addressText', () => {
        expect(source).toContain("const q = (this.deliveryInline.addressText || '').trim();");
    });

    it('no longer offers a manual kilometre field (distance is server-side only)', () => {
        expect(source).not.toContain('pos-delivery-manual-km');
    });
});
