/**
 * [DASH-UIUX-2026-06-08 P2] alertService.successFlip used to append a hardcoded English
 * suffix (" Updated/Created/Deleted Successfully.") to an already-translated FR label, so every
 * CRUD toast across the 102 admin components read half-English ("Entreprise Updated
 * Successfully."). It must now append a French confirmation and never leak English.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const successMock = vi.fn();
vi.mock('vue-toastification', () => ({
    useToast: () => ({ success: successMock, error: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}));

import alertService from '../../resources/js/services/alertService';

describe('alertService.successFlip — French CRUD confirmation', () => {
    beforeEach(() => successMock.mockClear());

    it('update (status truthy) appends French, not English', () => {
        alertService.successFlip(1, 'Entreprise');
        const msg = successMock.mock.calls[0][0];
        expect(msg).toContain('mise à jour réussie');
        expect(msg).toContain('Entreprise');
    });

    it('create (status falsy) appends French', () => {
        alertService.successFlip(0, 'Produit');
        expect(successMock.mock.calls[0][0]).toContain('création réussie');
    });

    it('delete (status null) appends French', () => {
        alertService.successFlip(null, 'Coupon');
        expect(successMock.mock.calls[0][0]).toContain('suppression réussie');
    });

    it('NEVER leaks an English "Successfully" suffix for any status', () => {
        [1, 0, null].forEach((s) => alertService.successFlip(s, 'X'));
        successMock.mock.calls.forEach(([msg]) => {
            expect(msg, `"${msg}" must not contain English`).not.toMatch(/Successfully/i);
        });
    });
});
