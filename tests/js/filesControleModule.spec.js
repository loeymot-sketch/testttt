// [GOAL CAISSE CONTRÔLE 2026-09-02] Les quatre files de la caisse.
//
// Ce banc existe parce que le défaut d'origine était un DÉSACCORD entre deux compteurs affichés
// à 40 px l'un de l'autre : le badge de la caisse annonçait « 3 » pendant que le tableau de suivi
// annonçait « 7 actives », les deux disant « les commandes en cours ». Trois surfaces lisent
// désormais ces prédicats (le tiroir, le badge de son bouton, le compteur du ticket) : ils sont
// écrits UNE fois, et vérifiés ici.

import { describe, it, expect } from 'vitest';
import {
    estTerminale,
    fileEncaisser,
    filePretes,
    fileLivrees,
    filesDeControle,
} from '../../resources/js/support/filesControle';

const cmd = (over = {}) => ({
    id: 1,
    status: 7,
    payment_status: 5,
    order_type: 25,
    is_cash_pending: false,
    created_at: '2026-09-02T08:00:00+02:00',
    ...over,
});

describe('estTerminale', () => {
    it.each([[13, 'livrée'], [16, 'annulée'], [19, 'rejetée'], [22, 'retournée']])(
        'statut %i (%s) est terminal',
        (s) => expect(estTerminale(cmd({ status: s }))).toBe(true),
    );

    it.each([[1], [4], [7], [8]])('statut %i ne l’est pas', (s) => {
        expect(estTerminale(cmd({ status: s }))).toBe(false);
    });
});

describe('fileEncaisser — l’argent qui n’est pas dans le tiroir', () => {
    it('retient une commande à encaisser quel que soit son statut cuisine', () => {
        [4, 7, 8].forEach((s) => {
            expect(fileEncaisser([cmd({ status: s, is_cash_pending: true })])).toHaveLength(1);
        });
    });

    it('exclut une commande annulée qui garde payment_status=PENDING_COUNTER', () => {
        // Cas réel : trente lignes constatées en base. Le serveur REFUSE de les encaisser ;
        // les afficher promettrait une action impossible.
        const annulee = cmd({ status: 16, payment_status: 15, is_cash_pending: true });
        expect(fileEncaisser([annulee])).toEqual([]);
    });

    it('exclut ce qui n’est pas à encaisser', () => {
        expect(fileEncaisser([cmd({ is_cash_pending: false })])).toEqual([]);
    });

    it('n’accepte que le booléen vrai — pas une valeur simplement « truthy »', () => {
        expect(fileEncaisser([cmd({ is_cash_pending: 1 })])).toEqual([]);
        expect(fileEncaisser([cmd({ is_cash_pending: 'oui' })])).toEqual([]);
    });

    it('trie plus ancienne d’abord', () => {
        const a = cmd({ id: 'A', is_cash_pending: true, created_at: '2026-09-02T08:10:00+02:00' });
        const b = cmd({ id: 'B', is_cash_pending: true, created_at: '2026-09-02T08:00:00+02:00' });
        expect(fileEncaisser([a, b]).map((o) => o.id)).toEqual(['B', 'A']);
    });
});

describe('filePretes — tous les canaux', () => {
    it('retient une commande COMPTOIR prête — c’est le défaut d’origine', () => {
        // Le panneau « Prêt à livrer » était nourri par un flux filtré BORNE + À EMPORTER :
        // une commande comptoir prête n'apparaissait NULLE PART sur la caisse.
        const comptoir = cmd({ status: 8, order_type: 15, source_surface: 'pos' });
        expect(filePretes([comptoir])).toHaveLength(1);
    });

    it.each([[25, 'borne'], [10, 'à emporter'], [15, 'caisse'], [5, 'livraison'], [20, 'table']])(
        'retient le type %i (%s)',
        (t) => expect(filePretes([cmd({ status: 8, order_type: t })])).toHaveLength(1),
    );

    it('exclut un remboursement passerelle resté au statut prêt', () => {
        expect(filePretes([cmd({ status: 8, payment_status: 20 })])).toEqual([]);
    });

    it('exclut tout autre statut', () => {
        [1, 4, 7, 13, 16].forEach((s) => expect(filePretes([cmd({ status: s })])).toEqual([]));
    });
});

describe('fileLivrees — plus récente d’abord', () => {
    it('inverse l’ordre chronologique', () => {
        const a = cmd({ id: 'A', status: 13, created_at: '2026-09-02T08:00:00+02:00' });
        const b = cmd({ id: 'B', status: 13, created_at: '2026-09-02T08:20:00+02:00' });
        expect(fileLivrees([a, b]).map((o) => o.id)).toEqual(['B', 'A']);
    });

    it('ne retient que les livrées', () => {
        expect(fileLivrees([cmd({ status: 8 }), cmd({ status: 13 })])).toHaveLength(1);
    });
});

describe('filesDeControle — le service semé en audit, de bout en bout', () => {
    // Reproduction du semis de `tests/e2e/helpers/seed-caisse-controle.js`.
    const service = [
        cmd({ id: 'K1', status: 7, payment_status: 15, is_cash_pending: true, created_at: '2026-09-02T08:00:00+02:00' }),
        cmd({ id: 'K2', status: 7, payment_status: 15, is_cash_pending: true, created_at: '2026-09-02T08:05:00+02:00' }),
        cmd({ id: 'P1', status: 7, payment_status: 5, order_type: 15, created_at: '2026-09-02T08:08:00+02:00' }),
        cmd({ id: 'T1', status: 4, payment_status: 15, is_cash_pending: true, created_at: '2026-09-02T08:11:00+02:00' }),
        cmd({ id: 'R1', status: 8, created_at: '2026-09-02T07:58:00+02:00' }),
        cmd({ id: 'R2', status: 8, order_type: 15, source_surface: 'pos', created_at: '2026-09-02T08:03:00+02:00' }),
        cmd({ id: 'W1', status: 1, payment_status: 10, order_type: 10, created_at: '2026-09-02T08:12:00+02:00' }),
        cmd({ id: 'D1', status: 13, created_at: '2026-09-02T07:40:00+02:00' }),
        cmd({ id: 'D2', status: 13, created_at: '2026-09-02T07:50:00+02:00' }),
    ];

    it('classe les neuf commandes dans les bonnes files', () => {
        const f = filesDeControle(service);
        expect(f.encaisser.map((o) => o.id)).toEqual(['K1', 'K2', 'T1']);
        expect(f.cuisine.map((o) => o.id)).toEqual(['K1', 'K2', 'P1', 'T1']);
        expect(f.pretes.map((o) => o.id)).toEqual(['R1', 'R2']);
        expect(f.livrees.map((o) => o.id)).toEqual(['D2', 'D1']);
    });

    it('les compteurs NE S’ADDITIONNENT PAS : trois commandes sont dans deux files', () => {
        // K1, K2 et T1 sont à encaisser ET en cuisine — c'est la règle serveur
        // (`isReleasedForBoard` admet PENDING_COUNTER), pas un doublon d'affichage.
        // C'est pour cette raison que le tiroir n'affiche JAMAIS de total agrégé.
        const f = filesDeControle(service);
        const somme = f.encaisser.length + f.cuisine.length + f.pretes.length + f.livrees.length;
        expect(somme).toBe(11);
        expect(new Set([...f.encaisser, ...f.cuisine, ...f.pretes, ...f.livrees]).size).toBe(8);
    });

    it('la commande web en attente d’acceptation n’est dans AUCUNE file', () => {
        // W1 : PENDING + UNPAID. La cuisine ne l'a pas, il n'y a rien à encaisser au comptoir
        // tant qu'elle n'est pas acceptée, elle n'est ni prête ni livrée. Son panneau dédié
        // (« Commandes web à traiter ») reste sa seule maison.
        const f = filesDeControle(service);
        const partout = [...f.encaisser, ...f.cuisine, ...f.pretes, ...f.livrees];
        expect(partout.find((o) => o.id === 'W1')).toBeUndefined();
    });

    it('ne casse pas sur une entrée absente', () => {
        const f = filesDeControle(null);
        expect(f).toEqual({ encaisser: [], cuisine: [], pretes: [], livrees: [] });
    });
});
