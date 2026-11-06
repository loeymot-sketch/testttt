import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

import axios from 'axios';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';

describe('kioskCart.refreshKioskToken — borne longue durée', () => {
    beforeEach(() => {
        axios.post.mockReset();
    });

    it('renouvelle le token borne avant expiration sans réenvoyer les identifiants machine', async () => {
        axios.post.mockResolvedValue({ data: { token: 'FRESH-KIOSK-TOKEN' } });
        const state = { kioskToken: 'OLD-KIOSK-TOKEN', kioskMachineId: 37 };
        const commit = vi.fn();

        await expect(kioskCart.actions.refreshKioskToken({ state, commit })).resolves.toBe(true);

        expect(axios.post).toHaveBeenCalledWith('refresh-token', { token: 'OLD-KIOSK-TOKEN' });
        expect(commit).toHaveBeenCalledWith('SET_KIOSK_TOKEN', {
            token: 'FRESH-KIOSK-TOKEN',
            machineId: 37,
        });
    });

    it('ne fait aucun appel sans session borne et conserve le chemin 401 comme filet de sécurité', async () => {
        const commit = vi.fn();
        await expect(kioskCart.actions.refreshKioskToken({ state: { kioskToken: null }, commit })).resolves.toBe(false);
        expect(axios.post).not.toHaveBeenCalled();
        expect(commit).not.toHaveBeenCalled();
    });

    it('ne remplace pas une session tournée pendant le refresh', async () => {
        const state = { kioskToken: 'OLD', kioskMachineId: 37 };
        const commit = vi.fn();
        axios.post.mockImplementation(() => {
            state.kioskToken = 'NEWER-SESSION';
            return Promise.resolve({ data: { token: 'STALE-REFRESH' } });
        });

        await expect(kioskCart.actions.refreshKioskToken({ state, commit })).resolves.toBe(false);
        expect(commit).not.toHaveBeenCalled();
    });
});
