import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-RED-R2-§1-P2 | @source RED-R2 §1 P2 (CSP <meta> → HTTP header)
 * @reason
 *   La CSP délivrée via <meta http-equiv> est ignorée par les navigateurs
 *   modernes pour plusieurs directives critiques (frame-ancestors, sandbox,
 *   report-uri…). On migre vers un header HTTP injecté par le middleware
 *   ContentSecurityPolicyHeader. Le <meta> reste en place comme fallback de
 *   transition (rollback safety) — voir docs/runbooks/CSP_HEADER_MIGRATION.md.
 *
 * Sentinel ferme :
 *   - le middleware FQCN est bien référencé dans Kernel.php (groupe web)
 *   - le <meta> kiosk porte un commentaire « FALLBACK ONLY » (l'auteur a
 *     conscience que le <meta> n'est plus la source autoritative)
 */
describe('CSP — migration <meta> → HTTP header (RED-R2 §1 P2)', () => {
    const kernel = readFileSync(resolve(process.cwd(), 'app/Http/Kernel.php'), 'utf8');
    const master = readFileSync(resolve(process.cwd(), 'resources/views/master.blade.php'), 'utf8');

    it('Kernel.php registers ContentSecurityPolicyHeader middleware in the web group', () => {
        expect(kernel).toMatch(/App\\Http\\Middleware\\ContentSecurityPolicyHeader::class/);
    });

    it('master.blade.php still embeds the <meta> CSP (rollback fallback retained)', () => {
        expect(master).toMatch(/<meta\s+http-equiv="Content-Security-Policy-Report-Only"/);
    });

    it('master.blade.php marks the <meta> CSP as fallback-only (transition guard)', () => {
        expect(master).toMatch(/FALLBACK ONLY/);
    });
});
