import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-BYPASS-P5-MARKER
 * @source docs/audit/BYPASS_MAPPING_2026-05-08.md
 *
 * Sentinel garantit que le marqueur "MODE TEST — IMPRESSION BYPASSÉE" est :
 *   - dans la zone .hidden-print (NE S'IMPRIME PAS sur le ticket fiscal NF525)
 *   - guarded par v-if="bypassPrintingActive" (n'apparaît que si flag actif)
 *   - exposé via data-testid="bypass-printing-marker" (probe Playwright)
 *
 * Anti-régression : si le marqueur sort de hidden-print → casse compliance NF525.
 * Si v-if disparaît → le marqueur s'affiche en prod (visuel cassé).
 */
describe('Bypass printing marker — hidden-print + guarded (BYPASS-P3)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/pos/ReceiptComponent.vue'),
        'utf8',
    );

    it('marker is inside hidden-print zone (not on actual fiscal receipt)', () => {
        // Strict pattern: <div ... v-if="bypassPrintingActive" ... hidden-print ...>
        expect(source).toMatch(/<div\s+v-if="bypassPrintingActive"[\s\S]*?hidden-print/);
    });

    it('marker exposes data-testid="bypass-printing-marker"', () => {
        expect(source).toContain('data-testid="bypass-printing-marker"');
    });

    it('computed property bypassPrintingActive reads window.foodkingConfig.bypassMode.printing', () => {
        expect(source).toMatch(/bypassPrintingActive[\s\S]*?window\.foodkingConfig\?\.bypassMode\?\.printing/);
    });

    it('computed property bypassPrintingMarker provides default fallback text', () => {
        expect(source).toMatch(/bypassPrintingMarker[\s\S]*?window\.foodkingConfig\?\.bypassMode\?\.printingScreenMarker/);
    });

    it('does NOT inject the marker outside hidden-print zone (compliance NF525)', () => {
        // Negative assertion: no v-if="bypassPrintingActive" appears AFTER the closing
        // </script> tag pattern OR within an unguarded paper-receipt block class
        const inPaperReceipt = /class="[^"]*print-receipt-(?:client|kitchen)[^"]*"[\s\S]{0,300}v-if="bypassPrintingActive"/;
        expect(source).not.toMatch(inPaperReceipt);
    });
});
