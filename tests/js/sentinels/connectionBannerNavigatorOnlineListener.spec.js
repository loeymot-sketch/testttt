import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-RED-R1-O1 | @source RED-R1 O1 + R5 §3 (banner offline POS)
 * @reason
 *   ConnectionStatusBanner doit AUSSI écouter navigator.onLine via les events
 *   `online`/`offline` sur window. Sans cela, si le réseau coupe sans que Pusher
 *   ne déconnecte (rare mais possible), l'utilisateur tape commandes en aveugle
 *   pendant ~30s avant que le banner offline n'apparaisse.
 *
 * Sentinel ferme le contrat structurel :
 *   - mounted() enregistre les handlers `offline` ET `online`
 *   - beforeUnmount() les retire (cleanup symétrique, pas de leak)
 *   - le handler `offline` set disconnectedSince = Date.now() (amorce timer banner)
 */
describe('ConnectionStatusBanner — navigator.onLine listeners (RED-R1 O1)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/common/ConnectionStatusBanner.vue'),
        'utf8',
    );

    it('mounted() registers an `offline` listener on window', () => {
        expect(source).toMatch(/window\.addEventListener\(\s*["']offline["']/);
    });

    it('mounted() registers an `online` listener on window', () => {
        expect(source).toMatch(/window\.addEventListener\(\s*["']online["']/);
    });

    it('the offline handler itself sets disconnectedSince = Date.now() (anchored on _onOffline)', () => {
        // Anchor on `_onOffline = () => { ... disconnectedSince = Date.now() ... }` so deleting
        // the handler body is caught even if other unrelated `disconnectedSince = Date.now()`
        // statements remain elsewhere in the file.
        expect(source).toMatch(/_onOffline\s*=\s*\(\)\s*=>\s*\{[^}]*disconnectedSince\s*=\s*Date\.now\(\)/);
    });

    it('beforeUnmount() removes the `offline` listener (no leak)', () => {
        expect(source).toMatch(/window\.removeEventListener\(\s*["']offline["']/);
    });

    it('beforeUnmount() removes the `online` listener (no leak)', () => {
        expect(source).toMatch(/window\.removeEventListener\(\s*["']online["']/);
    });
});
