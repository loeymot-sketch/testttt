import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-RED-R2-P2 | @source RED-R2 BLUE-R2 plan P2 (kiosk PUSHER doctrine)
 * @reason
 *   Le kiosk public NE DOIT PAS supprimer le banner session-invalid.
 *   Si la session devient terminale (WS_STATE.SESSION_INVALID) et que le banner
 *   est masqué, l'utilisateur fait des actions sur une session zombie sans le savoir.
 *   Le banner session-invalid affiche un bouton reload — comportement attendu pour
 *   un kiosk client public.
 *
 *   En revanche, le POS (operator interne) GARDE suppress-session-invalid car le
 *   reload est piloté autrement (UX différente).
 *
 * Sentinel anti-régression :
 *   - KioskAppComponent : monte ConnectionStatusBanner SANS suppress-session-invalid
 *   - PosComponent : monte ConnectionStatusBanner AVEC suppress-session-invalid
 */
describe('Kiosk banner — no suppress-session-invalid (RED-R2 P2)', () => {
    const kioskSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskAppComponent.vue'),
        'utf8',
    );
    const posSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
        'utf8',
    );

    it('KioskAppComponent mounts ConnectionStatusBanner WITHOUT suppress-session-invalid', () => {
        // Match the ConnectionStatusBanner tag and its attributes up to the self-close.
        const tagMatch = kioskSource.match(/<ConnectionStatusBanner\b[^>]*\/>/);
        expect(tagMatch).not.toBeNull();
        expect(tagMatch[0]).not.toMatch(/suppress-session-invalid/);
    });

    it('KioskAppComponent still mounts ConnectionStatusBanner with suppress-transient', () => {
        const tagMatch = kioskSource.match(/<ConnectionStatusBanner\b[^>]*\/>/);
        expect(tagMatch).not.toBeNull();
        expect(tagMatch[0]).toMatch(/suppress-transient/);
    });

    it('PosComponent KEEPS suppress-session-invalid on its ConnectionStatusBanner (POS UX différente)', () => {
        const tagMatch = posSource.match(/<ConnectionStatusBanner\b[^>]*\/>/);
        expect(tagMatch).not.toBeNull();
        expect(tagMatch[0]).toMatch(/suppress-session-invalid/);
    });
});
