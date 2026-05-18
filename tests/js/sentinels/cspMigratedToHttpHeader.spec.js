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

    it('master.blade.php no longer renders an active <meta> CSP (only documented in a Blade comment block)', () => {
        // iter15-mega-fix C-007 2026-05-10 removed the runtime <meta>.
        // A historical reference may survive inside a {{-- ... --}} Blade comment block.
        // We assert any surviving reference is inside that documented removal comment.
        const matches = master.match(/<meta\s+http-equiv="Content-Security-Policy/g) || [];
        expect(matches.length).toBeLessThanOrEqual(1);
        if (matches.length === 1) {
            expect(master).toMatch(/removed meta-CSP[\s\S]{0,400}<meta\s+http-equiv="Content-Security-Policy/);
        }
    });

    it('master.blade.php documents the CSP migration history (audit trail)', () => {
        // Surface a non-binding comment trail so future devs know meta was removed deliberately.
        expect(master).toMatch(/removed meta-CSP|middleware emits HTTP header/);
    });
});
