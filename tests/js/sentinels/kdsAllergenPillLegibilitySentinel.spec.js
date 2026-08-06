import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-AUDIT-F-P1-3-001 (2026-08-06)
 *
 * SÉCURITÉ ALIMENTAIRE — la pastille « ⚠ ALLERGIE » de la carte KDS était le
 * PLUS PETIT texte de la carte (10px / hauteur 20px) alors que le numéro de
 * commande fait 36-52px : le signal le plus critique était illisible pour un
 * cuisinier debout à 1-2 m du passe (mesure audit F, captures round-1/F-ux).
 *
 * INVARIANT : police ≥ 16px ET hauteur ≥ 28px. Toute réduction future casse ce
 * test — un allergène ne redevient jamais du texte fin.
 */
describe('KDS — lisibilité de la pastille allergène (FK-AUDIT-F-P1-3-001)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue'),
        'utf8',
    );

    const pillBlock = source.match(/\.kds-card__allergen-pill\s*\{[^}]*\}/);

    it('le bloc CSS de la pastille existe', () => {
        expect(pillBlock, 'bloc .kds-card__allergen-pill attendu').not.toBeNull();
    });

    it('police ≥ 16px (lisible à 2 m)', () => {
        const px = Number(/font-size:\s*(\d+(?:\.\d+)?)px/.exec(pillBlock[0])?.[1]);
        expect(px, `font-size lue: ${px}px`).toBeGreaterThanOrEqual(16);
    });

    it('hauteur ≥ 28px (cible visuelle, pas un filet de texte)', () => {
        const px = Number(/height:\s*(\d+(?:\.\d+)?)px/.exec(pillBlock[0])?.[1]);
        expect(px, `height lue: ${px}px`).toBeGreaterThanOrEqual(28);
    });

    it('le fond haut-contraste est conservé (#C2410C, 5.18:1 sur blanc)', () => {
        expect(pillBlock[0]).toMatch(/background:\s*#C2410C/i);
        expect(pillBlock[0]).toMatch(/color:\s*#FFFFFF/i);
    });
});
