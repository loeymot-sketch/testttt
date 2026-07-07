/**
 * [owner 2026-07-08 #4] Ouverture tiroir-caisse au paiement espèces.
 *
 * Bug : sur la caisse en Chrome (pas d'Electron window.borne), openDrawer()
 * empruntait le no-op du STUB → le tiroir ne s'ouvrait jamais. Fix : quand ce
 * n'est PAS un vrai bridge Electron, envoyer l'impulsion tiroir (ESC p 0 25 250)
 * au pont d'impression caisse (:9100/raw), même canal que les tickets.
 */
import { describe, it, expect, afterEach, vi } from 'vitest';
import * as posLocalPrinter from '../../resources/js/helpers/posLocalPrinter';

vi.mock('../../resources/js/helpers/posLocalPrinter', () => ({
    printEscPosViaCaisseBridge: vi.fn(async () => ({ ok: true })),
    isCaisseBridgeAvailable: vi.fn(async () => true),
}));

function b64ToBytes(b64) {
    const bin = atob(String(b64 || ''));
    return Array.from(bin, (c) => c.charCodeAt(0));
}

describe('openDrawer — repli pont caisse (#4)', () => {
    afterEach(() => {
        delete window.borne;
        vi.clearAllMocks();
    });

    it('caisse Chrome (pas d\'Electron) : envoie l\'impulsion tiroir au pont caisse', async () => {
        delete window.borne;
        posLocalPrinter.printEscPosViaCaisseBridge.mockResolvedValue({ ok: true });
        const hw = await import('../../resources/js/services/kioskHardware.js');

        const r = await hw.openDrawer();

        expect(r.ok).toBe(true);
        expect(r.via).toBe('caisse-bridge');
        expect(posLocalPrinter.printEscPosViaCaisseBridge).toHaveBeenCalledTimes(1);
        // Octets = ESC p m=0 t1=25 t2=250 (aligné EscPosCommandBuilder.php).
        const b64 = posLocalPrinter.printEscPosViaCaisseBridge.mock.calls[0][0];
        expect(b64ToBytes(b64)).toEqual([0x1B, 0x70, 0x00, 0x19, 0xFA]);
    });

    it('vrai bridge Electron : utilise openDrawer natif, PAS le pont', async () => {
        const nativeOpen = vi.fn(async () => ({ ok: true, native: true }));
        window.borne = {
            isElectron: true,
            openDrawer: nativeOpen,
            healthcheck: async () => ({ ok: true, components: {} }),
        };
        const hw = await import('../../resources/js/services/kioskHardware.js');

        const r = await hw.openDrawer();

        expect(nativeOpen).toHaveBeenCalledTimes(1);
        expect(r.ok).toBe(true);
        expect(posLocalPrinter.printEscPosViaCaisseBridge).not.toHaveBeenCalled();
    });

    it('pont indisponible : dégrade proprement (ok:false), sans throw', async () => {
        delete window.borne;
        posLocalPrinter.printEscPosViaCaisseBridge.mockResolvedValue({ ok: false, error: 'bridge_unavailable' });
        const hw = await import('../../resources/js/services/kioskHardware.js');

        const r = await hw.openDrawer();

        expect(r.ok).toBe(false);
    });
});
