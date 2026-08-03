import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID N-HEAL-03 | @source Wave M M-POS-4 G-001 + G-002 P3 (2026-05-24)
 * @reason
 *   PosComponent.vue holds two long-lived handles that were NOT cleared in
 *   beforeUnmount() before this heal:
 *     - `_deliveryAcTimer` : setTimeout debounce for the Google Maps inline
 *       delivery-address autocomplete (300ms). On route transition mid-debounce
 *       it would fire on an unmounted component (potential `_destroyed` guard
 *       hit) and leak the timer slot across long cashier shifts.
 *     - `_audioCtx`        : Web AudioContext lazily allocated by
 *       `_playNewOrderBeep`. Without `.close()` the browser keeps the audio
 *       graph alive; over a 5h+ shift with hundreds of beeps this is a real
 *       leak (verified by Chrome devtools Memory profile — Wave M finding).
 *
 *   This sentinel locks the cleanup contract symmetrically with the other 10
 *   pre-existing handles cleared in beforeUnmount() (echo, wsService, posSync,
 *   barcode, F-keys, debounce, availability toast timers, kiosk poll,
 *   shortcuts ticker, cart/total/success flash timers, offline flush timer).
 */
describe('PosComponent — beforeUnmount cleanup (N-HEAL-03 M-POS-4 G-001+G-002)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
        'utf8',
    );

    // Slice out the beforeUnmount() block so the assertions below anchor on
    // teardown code — not on the mounted()/methods bodies that also reference
    // `_deliveryAcTimer` (the debounce site itself does `clearTimeout` between
    // keystrokes, but that's NOT cleanup) and `_audioCtx` (allocation site).
    const beforeUnmountBlock = (() => {
        const start = source.indexOf('beforeUnmount() {');
        expect(start, 'beforeUnmount() block must exist').toBeGreaterThan(-1);
        // Walk braces from the opening `{` of the function body until balanced.
        const bodyStart = source.indexOf('{', start);
        let depth = 0;
        let i = bodyStart;
        for (; i < source.length; i += 1) {
            const ch = source[i];
            if (ch === '{') depth += 1;
            else if (ch === '}') {
                depth -= 1;
                if (depth === 0) break;
            }
        }
        return source.slice(start, i + 1);
    })();

    it('clears the Google Maps autocomplete debounce timer (_deliveryAcTimer)', () => {
        expect(beforeUnmountBlock).toMatch(/clearTimeout\(\s*this\._deliveryAcTimer\s*\)/);
    });

    it('nulls _deliveryAcTimer after clearing (release GC)', () => {
        expect(beforeUnmountBlock).toMatch(/this\._deliveryAcTimer\s*=\s*null/);
    });

    it('closes the Web AudioContext (_audioCtx.close)', () => {
        expect(beforeUnmountBlock).toMatch(/this\._audioCtx\.close\(\)/);
    });

    it('guards _audioCtx.close() to keep heal idempotent across browsers without close()', () => {
        expect(beforeUnmountBlock).toMatch(/typeof\s+this\._audioCtx\.close\s*===\s*['"]function['"]/);
    });

    it('nulls _audioCtx after closing (release GC + prevent double-close)', () => {
        expect(beforeUnmountBlock).toMatch(/this\._audioCtx\s*=\s*null/);
    });

    it('swallows the close() promise rejection (audioCtx may already be closing)', () => {
        // Pattern: `.catch(() => {})` immediately after close().
        expect(beforeUnmountBlock).toMatch(/this\._audioCtx\.close\(\)\.catch\(\s*\(\)\s*=>\s*\{\s*\}\s*\)/);
    });
});
