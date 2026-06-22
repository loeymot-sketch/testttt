import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-RED-R4-KD5 | @source docs/audit/RED_TEAM_R4_KDS_RECEPTION_2026-05-07.md
 * @fix-mission RED-R4 BLUE response (commit après e309083b7) | @reason
 *   Le watcher `orders` du KDS doit comparer les IDs (Set diff), pas la longueur.
 *   Heure de pointe : 1 commande PREPARED sort + 1 nouvelle ACCEPT entre → length stable
 *   → l'ancien `newVal.length > oldVal.length` ratait le chime → commande oubliée.
 *
 * Sentinel ferme le watcher en ID-based. Toute régression vers length-based
 * fera échouer ce test.
 */
describe('KDS new-order chime — watcher ID-based diff (KD5)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
        'utf8',
    );

    it('contains an oldIds Set diff (not a length comparison) in the orders watcher', () => {
        // Strict pattern: oldIds Set construction
        expect(source).toMatch(/const\s+oldIds\s*=\s*new\s+Set\(/);
        // Strict pattern: filter on !oldIds.has(...)
        expect(source).toMatch(/!oldIds\.has\(/);
        // Strict pattern: new orders length > 0 triggers the chime
        expect(source).toMatch(/newOrders\.length\s*>\s*0/);
    });

    it('does NOT contain the legacy length-based watcher', () => {
        // Negative assertion: the obsolete pattern must not reappear
        expect(source).not.toMatch(/newVal\.length\s*>\s*oldVal\.length/);
    });
});
