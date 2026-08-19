/**
 * [FIDÉLITÉ 2026-08-19] L'HISTORIQUE DES POINTS SUR LA FICHE CLIENT ADMIN.
 *
 * ── CE QUE CE FICHIER PROTÈGE ────────────────────────────────────────────────────────────────
 * La fiche client affichait le SOLDE depuis le 2026-08-14, avec un commentaire disant qu'elle
 * servait à répondre à « pourquoi j'ai ce solde ? » — c'est-à-dire qu'elle affichait la question
 * et pas la réponse. Un solde sans son histoire est indéfendable : le client conteste, le
 * responsable ouvre la fiche, et n'a rien à lui montrer. Le comptoir avait déjà cet historique.
 *
 * Le panneau réutilise l'endpoint du comptoir (`admin/pos-loyalty/history`) au lieu d'en créer
 * un second : deux sources pour un même solde, ce sont deux versions devant un client qui
 * conteste.
 *
 * ── POURQUOI TESTER LE MAPPING, ET PAS LE PIXEL ──────────────────────────────────────────────
 * Ce qui casse ici n'est pas la mise en page, c'est le CONTRAT : nom du paramètre, chemin de la
 * réponse, noms des champs. Ces quatre points ont été relevés dans le vrai contrôleur puis
 * vérifiés en direct contre le serveur (`data.entries[]` avec `when`/`label`/`points`/`balance`,
 * paramètres `loyalty_code` + `limit`). Ce test fige exactement cela — un renommage côté serveur
 * doit faire rougir quelque chose, pas vider un tableau en silence.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn() } }));
import axios from 'axios';
import CustomerShowComponent from '../../resources/js/components/admin/customers/CustomerShowComponent.vue';

/** Contexte minimal : on éprouve la méthode, pas le montage complet de l'écran. */
function contexte() {
    return {
        loyaltyHistory: { loading: false, error: '', rows: [] },
    };
}

const REPONSE_REELLE = {
    data: {
        status: true,
        data: {
            customer: { loyalty_code: 'ANNUL001', balance: 597 },
            entries: [
                {
                    id: 157,
                    when: '19/08/2026 13:29',
                    type: 'earn',
                    label: 'Gagné sur une commande',
                    points: 95,
                    signed: '+95',
                    balance: 597,
                    surface: 'pos',
                    order_id: 6603,
                },
                {
                    id: 150,
                    when: '19/08/2026 09:41',
                    type: 'redeem',
                    label: 'Réduction utilisée',
                    points: -1500,
                    signed: '-1500',
                    balance: 500,
                    surface: 'pos',
                    order_id: 6600,
                },
            ],
        },
    },
};

describe('Fiche client admin — historique des points', () => {
    beforeEach(() => vi.clearAllMocks());

    it('interroge le MÊME endpoint que le comptoir, avec les bons paramètres', async () => {
        axios.get.mockResolvedValue(REPONSE_REELLE);
        const ctx = contexte();

        await CustomerShowComponent.methods.chargerHistoriqueFidelite.call(ctx, 'ANNUL001');

        expect(axios.get).toHaveBeenCalledWith('admin/pos-loyalty/history', {
            params: { loyalty_code: 'ANNUL001', limit: 15 },
        });
    });

    it('traduit la réponse RÉELLE du serveur en lignes lisibles', async () => {
        axios.get.mockResolvedValue(REPONSE_REELLE);
        const ctx = contexte();

        await CustomerShowComponent.methods.chargerHistoriqueFidelite.call(ctx, 'ANNUL001');

        expect(ctx.loyaltyHistory.rows).toEqual([
            { date: '19/08/2026 13:29', libelle: 'Gagné sur une commande', points: 95, solde: 597 },
            { date: '19/08/2026 09:41', libelle: 'Réduction utilisée', points: -1500, solde: 500 },
        ]);
        expect(ctx.loyaltyHistory.loading).toBe(false);
        expect(ctx.loyaltyHistory.error).toBe('');
    });

    it('sans code fidélité, n’appelle rien', async () => {
        const ctx = contexte();
        await CustomerShowComponent.methods.chargerHistoriqueFidelite.call(ctx, null);
        expect(axios.get).not.toHaveBeenCalled();
    });

    /**
     * UN ÉCHEC NE DOIT PAS SE DÉGUISER EN « AUCUN MOUVEMENT ».
     *
     * Un tableau vide affiché après une erreur réseau ou un refus de permission ferait croire au
     * responsable que ce client n'a jamais rien fait. C'est un mensonge, et il tomberait
     * exactement au moment où quelqu'un conteste.
     */
    it('un échec dit qu’il a échoué, il ne montre pas un historique vide', async () => {
        axios.get.mockRejectedValue(new Error('403'));
        const ctx = contexte();

        await CustomerShowComponent.methods.chargerHistoriqueFidelite.call(ctx, 'ANNUL001');

        expect(ctx.loyaltyHistory.rows).toEqual([]);
        expect(ctx.loyaltyHistory.error).not.toBe('');
        expect(ctx.loyaltyHistory.loading).toBe(false);
    });
});
