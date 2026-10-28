import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const repoRoot = process.cwd();

function read(file) {
    return fs.readFileSync(path.join(repoRoot, file), 'utf8');
}

describe('delivery geocode failure UI contracts', () => {
    it('shows a blocking frontend checkout banner for GEOCODE_FAILED', () => {
        const source = read('resources/js/components/frontend/checkout/CheckoutComponent.vue');

        expect(source).toContain('deliveryGeocodeError');
        expect(source).toContain('GEOCODE_FAILED');
        expect(source).toContain('Adresse non reconnue');
        expect(source).toContain('focusDeliveryAddressField');
        expect(source).toContain('role="alert"');
    });

    it('blocks POS delivery addresses without resolved Google coordinates', () => {
        const source = read('resources/js/components/admin/pos/PosComponent.vue');

        expect(source).toContain('deliveryGeocodeError');
        expect(source).toContain('Adresse non reconnue');
        expect(source).toContain('focusDeliveryAddressField');
        // [LIVRAISON 2026-10-20] Plus de repli « distance saisie à la main » : une adresse n'est acceptée que
        // si le SERVEUR l'a géocodée et chiffrée (devis) ; sinon la caisse refuse et le dit.
        expect(source).toContain('!this.deliveryInline.confirmed');
        expect(source).toContain("Choisissez l\\'adresse de livraison dans la liste proposée.");
        expect(source).toContain('livraison.quoteAddress(axios');
        expect(source).not.toContain('Frais de livraison appliqués au tarif minimum.');
    });
});
