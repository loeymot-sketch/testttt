import { describe, it, expect } from 'vitest';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';
import KitchenDisplaySystemComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';

/**
 * [VG-2026-10-21 M6-001 · message faux] Pendant que GET /api/admin/kds-order répondait 500
 * (livraison « ASAP »), le cuisinier lisait « Connexion cuisine indisponible » : FAUX, le réseau
 * allait bien, c'est le SERVEUR qui échouait. Il attendait en vain que « la connexion revienne ».
 *
 * On appelle la VRAIE méthode `_raiseKdsErrorBanner` du composant (pas une copie), avec un
 * `this` minimal et les vrais fichiers de langue.
 */
function traduire(messages) {
    return (cle) => {
        const valeur = cle.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), messages);
        return typeof valeur === 'string' ? valeur : cle;
    };
}

function lever(err) {
    const ctx = {
        $t: traduire(fr),
        kdsErrorBanner: { visible: false, message: '', lastRetryAt: null },
    };
    KitchenDisplaySystemComponent.methods._raiseKdsErrorBanner.call(ctx, err);
    return ctx.kdsErrorBanner;
}

describe('KDS — erreur serveur ≠ connexion perdue (M6-001)', () => {
    it('500 → « Erreur du serveur — les commandes affichées peuvent être incomplètes »', () => {
        const b = lever({ response: { status: 500, data: { message: 'Server Error' } } });
        expect(b.visible).toBe(true);
        expect(b.message).toContain('Erreur du serveur');
        expect(b.message).toContain('les commandes affichées peuvent être incomplètes');
        expect(b.message).not.toContain('Connexion cuisine indisponible');
    });

    it('503 / 502 → même message serveur (jamais « connexion »)', () => {
        for (const status of [502, 503, 504]) {
            const b = lever({ response: { status } });
            expect(b.message, `status ${status}`).toContain('Erreur du serveur');
        }
    });

    it('pas de réponse (réseau coupé) → « Connexion cuisine indisponible » conservé', () => {
        const b = lever({ message: 'Network Error' });
        expect(b.message).toBe(fr.error.kds_connection_lost);
    });

    it('422 → message métier du serveur conservé (non-régression)', () => {
        const b = lever({ response: { status: 422, data: { message: 'Statut invalide' } } });
        expect(b.message).toBe('Statut invalide');
    });

    it('la clé existe en français ET en anglais', () => {
        expect(typeof fr.error.kds_server_error).toBe('string');
        expect(typeof en.error.kds_server_error).toBe('string');
    });
});
