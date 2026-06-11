import { describe, it, expect, vi } from 'vitest';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';

/**
 * [UIUX-W2 F6 2026-06-11] Toasts / feedback caisse.
 *
 *  - `label.error` était ABSENT des fichiers de langue → les catch de
 *    PosOrderShowComponent (assignation livreur) toastaient la clé brute
 *    « label.error ». Clé ajoutée FR (« Une erreur est survenue ») + parité EN.
 *  - EncaissementComponent.onEncaisseConfirmed doublonnait le toast succès
 *    avec un numéro de commande VIDE (« Commande N° encaissée ») alors que
 *    PosCounterCollectModal toaste déjà le succès AVEC le numéro → doublon
 *    supprimé, le refresh de la liste est conservé.
 */

describe('label.error i18n key (F6 UIUX-W2)', () => {
    it('exists in FR with a real FR message', () => {
        expect(fr.label.error).toBe('Une erreur est survenue');
    });
    it('exists in EN (parity)', () => {
        expect(en.label.error).toBeTruthy();
    });
});

describe('EncaissementComponent.onEncaisseConfirmed (F6 UIUX-W2)', () => {
    it('no longer fires the duplicate empty-number success toast, still refreshes', async () => {
        vi.resetModules();
        const alertService = (await import('../../resources/js/services/alertService')).default;
        const successSpy = vi.spyOn(alertService, 'success').mockImplementation(() => {});
        const { default: EncaissementComponent } = await import(
            '../../resources/js/components/admin/encaissement/EncaissementComponent.vue'
        );
        const ctx = {
            encaisseOrder: { id: 7 },
            fetchPending: vi.fn(),
            $t: (k) => k,
        };
        EncaissementComponent.methods.onEncaisseConfirmed.call(ctx);
        expect(ctx.encaisseOrder).toBeNull();
        expect(ctx.fetchPending).toHaveBeenCalled();
        // Le modal (PosCounterCollectModal) toaste déjà le succès avec le
        // numéro — plus de second toast au numéro vide ici.
        expect(successSpy).not.toHaveBeenCalled();
        successSpy.mockRestore();
    });
});
