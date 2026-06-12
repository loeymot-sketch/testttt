import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

/**
 * [W-REM T-R2.3 Q-5 2026-06-12] Observability outbox — FR + garde purge.
 *
 * Findings DB5-03/DB5-04 (micro-audit loyalty-validation 2026-06-12) :
 *   - DB5-03 : `toLocaleString()` SANS locale (l.339 generatedAtHuman,
 *     l.396 formatTimestamp) → "Généré à 6/12/2026, 2:56:01 AM" — format
 *     dépendant du navigateur, jamais forcé fr-FR/24h.
 *   - DB5-04 : "Purger Échecs > 24h" POSTait immédiatement sans dialogue de
 *     confirmation (alors que l'envoi push masse a un confirm —
 *     appService.confirmation, heal petits-systèmes).
 */

const REPO_ROOT = path.resolve(__dirname, '../..');
const SRC = fs.readFileSync(
    path.join(
        REPO_ROOT,
        'resources/js/components/admin/observability/OutboxOverviewComponent.vue'
    ),
    'utf-8'
);

describe('outbox observability FR datetime (DB5-03)', () => {
    it('no bare toLocaleString() — every call forces the fr-FR locale', () => {
        const bare = SRC.match(/toLocaleString\(\s*\)/g) ?? [];
        expect(bare).toEqual([]);
        const frCalls = SRC.match(/toLocaleString\(\s*'fr-FR'/g) ?? [];
        expect(frCalls.length).toBeGreaterThanOrEqual(2);
    });
});

describe('outbox drain-failed confirmation gate (DB5-04)', () => {
    it('drainFailed is gated behind appService.confirmation before POSTing', () => {
        const fnStart = SRC.indexOf('async drainFailed()');
        expect(fnStart).toBeGreaterThan(-1);
        const fnBody = SRC.slice(fnStart, fnStart + 1200);
        const confirmIdx = fnBody.indexOf('appService.confirmation');
        const postIdx = fnBody.indexOf("axios.post('/admin/observability/outbox/drain-failed'");
        expect(confirmIdx).toBeGreaterThan(-1);
        expect(postIdx).toBeGreaterThan(-1);
        expect(confirmIdx).toBeLessThan(postIdx);
    });
});
