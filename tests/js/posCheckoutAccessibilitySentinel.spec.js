import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';
import { parse } from '@vue/compiler-sfc';

const file = 'resources/js/components/admin/pos/PosComponent.vue';
const source = fs.readFileSync(path.resolve(file), 'utf8');

describe('POS — champs opérationnels accessibles', () => {
    it('conserve un template Vue valide', () => {
        expect(parse(source, { filename: file }).errors).toEqual([]);
    });

    it.each([
        ['pos-customer-name', 'name'],
        ['pos-customer-phone', 'tel'],
        ['pos-delivery-name', 'name'],
        ['pos-delivery-phone', 'tel'],
        ['pos-delivery-address', 'street-address'],
    ])('%s possède une étiquette et autocomplete=%s', (id, autocomplete) => {
        expect(source).toContain(`for="${id}"`);
        const start = source.indexOf(`id="${id}"`);
        const inputTag = source.slice(start, source.indexOf('/>', start));
        expect(inputTag).toContain(`autocomplete="${autocomplete}"`);
    });

    it('nomme les contrôles iconiques et expose les erreurs du formulaire client', () => {
        expect(source).toContain('aria-label="Effacer l’adresse de livraison"');
        expect(source).toContain('aria-label="Choisir l’indicatif téléphonique"');
        expect(source).toContain(':aria-invalid="errors.name ? \'true\' : \'false\'"');
        expect(source).toContain(':aria-invalid="errors.email ? \'true\' : \'false\'"');
    });

    it('rend le focus clavier visible sur les champs prioritaires', () => {
        const focusRings = source.match(/focus-visible:ring-2/g) || [];
        expect(focusRings.length).toBeGreaterThanOrEqual(10);
        expect(source).toContain('id="pos-discount-input"');
        expect(source).toContain('for="pos-discount-input"');
    });
});
