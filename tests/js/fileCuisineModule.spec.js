// [GOAL CAISSE CONTRÔLE 2026-09-02] La file de la cuisine, vue depuis la caisse.
//
// POURQUOI CE MODULE EXISTE
// -------------------------
// Le propriétaire demande, mot pour mot : « son numéro, elle est numéro combien par rapport à la
// cuisine, parce qu'il y a combien d'attente ». Aucune surface ne répondait : le tableau de suivi
// range TOUTE commande à encaisser dans la voie « À encaisser » quel que soit son statut cuisine
// (`PosOrdersTrackerComponent.vue:1384`), d'où la capture d'audit « EN PRÉPARATION 1 » pendant que
// QUATRE commandes cuisaient.
//
// Le rang ne peut donc PAS être recopié du bucket du suivi. Il doit être le miroir strict de la
// règle serveur qui décide ce que le chef voit sur son écran :
//   `App\Domain\Kds\KitchenReleaseRule::itemBoardStatuses()`  → ACCEPT + PREPARING
//   `App\Domain\Kds\KitchenReleaseRule::isReleasedForBoard()` → PAID | PENDING_COUNTER | (POS+CASH)
//   `applyBoardReleaseFilter()` exclut REFUNDED.
//
// Ce banc verrouille CE miroir. Si la règle serveur bouge, il doit rougir : c'est sa seule raison
// d'être. Les valeurs numériques sont écrites en clair (4/7/8, 5/15/20, 15/25, 1) exprès — ce sont
// celles des enums PHP, et un test qui importerait les mêmes constantes que le code testé ne
// prouverait rien sur leur exactitude.

import { describe, it, expect } from 'vitest';
import {
    libereePourLeTableau,
    estEnCuisine,
    fileCuisine,
    rangCuisine,
    attenteCuisine,
} from '../../resources/js/support/fileCuisine';

const cmd = (over = {}) => ({
    id: 1,
    status: 7,               // PREPARING
    payment_status: 5,       // PAID
    order_type: 25,          // KIOSK
    pos_payment_method: null,
    created_at: '2026-09-02T08:00:00+02:00',
    ...over,
});

describe('libereePourLeTableau — miroir de KitchenReleaseRule::isReleasedForBoard', () => {
    it('admet PAID', () => {
        expect(libereePourLeTableau(cmd({ payment_status: 5 }))).toBe(true);
    });

    it('admet PENDING_COUNTER — la borne Plan B cuit PENDANT que le client paie au comptoir', () => {
        // C'est la découverte qui change la règle : commentaire de KitchenReleaseRule.php:87-91,
        // « the kitchen starts preparing while the customer pays at the till ».
        expect(libereePourLeTableau(cmd({ payment_status: 15 }))).toBe(true);
    });

    it('admet une commande de caisse réglée en espèces (POS + CASH), même non encore encaissée', () => {
        expect(libereePourLeTableau(cmd({ payment_status: 10, order_type: 15, pos_payment_method: 1 }))).toBe(true);
    });

    it('refuse UNPAID hors du cas POS+CASH', () => {
        expect(libereePourLeTableau(cmd({ payment_status: 10, order_type: 25, pos_payment_method: null }))).toBe(false);
        expect(libereePourLeTableau(cmd({ payment_status: 10, order_type: 15, pos_payment_method: 2 }))).toBe(false);
    });

    it('lit des valeurs transmises en chaîne — l’API les renvoie parfois ainsi', () => {
        expect(libereePourLeTableau(cmd({ payment_status: '15' }))).toBe(true);
        expect(libereePourLeTableau(cmd({ payment_status: '10', order_type: '15', pos_payment_method: '1' }))).toBe(true);
    });

    it('ne casse pas sur une entrée absente', () => {
        expect(libereePourLeTableau(null)).toBe(false);
        expect(libereePourLeTableau(undefined)).toBe(false);
    });
});

describe('estEnCuisine — ACCEPT + PREPARING, libérée, non remboursée', () => {
    it('retient ACCEPT (la commande est déjà sur l’écran du chef)', () => {
        // itemBoardStatuses() = ACCEPT + PREPARING. Exclure ACCEPT sous-estimerait l’attente,
        // ce qui est exactement la plainte du propriétaire.
        expect(estEnCuisine(cmd({ status: 4 }))).toBe(true);
    });

    it('retient PREPARING', () => {
        expect(estEnCuisine(cmd({ status: 7 }))).toBe(true);
    });

    it('exclut PREPARED — elle est prête, elle n’est plus devant vous', () => {
        expect(estEnCuisine(cmd({ status: 8 }))).toBe(false);
    });

    it('exclut PENDING (1) : pas encore acceptée, la cuisine ne l’a pas', () => {
        expect(estEnCuisine(cmd({ status: 1 }))).toBe(false);
    });

    it('exclut les statuts terminaux', () => {
        [13, 16, 19, 22].forEach((s) => expect(estEnCuisine(cmd({ status: s }))).toBe(false));
    });

    it('exclut une commande REMBOURSÉE qui garde status=PREPARING', () => {
        // Cas réel documenté (tracker:1350-1358) : remboursement passerelle, statut inchangé.
        expect(estEnCuisine(cmd({ status: 7, payment_status: 20 }))).toBe(false);
    });

    it('exclut une commande non libérée (web UNPAID en attente d’acceptation)', () => {
        expect(estEnCuisine(cmd({ status: 4, payment_status: 10, order_type: 10 }))).toBe(false);
    });

    it('accepte `order_status` comme alias de `status` (les deux existent dans les charges utiles)', () => {
        expect(estEnCuisine({ order_status: 7, payment_status: 5, created_at: 'x' })).toBe(true);
    });
});

describe('fileCuisine — l’ordre dans lequel la cuisine les prépare', () => {
    const A = cmd({ id: 'A', created_at: '2026-09-02T08:00:00+02:00' });
    const B = cmd({ id: 'B', created_at: '2026-09-02T08:05:00+02:00' });
    const C = cmd({ id: 'C', created_at: '2026-09-02T08:10:00+02:00', status: 4 });
    const PRETE = cmd({ id: 'P', created_at: '2026-09-02T07:50:00+02:00', status: 8 });

    it('trie du plus ancien au plus récent, indépendamment de l’ordre reçu', () => {
        expect(fileCuisine([C, A, B]).map((o) => o.id)).toEqual(['A', 'B', 'C']);
    });

    it('écarte ce qui n’est pas en cuisine', () => {
        expect(fileCuisine([A, PRETE, B]).map((o) => o.id)).toEqual(['A', 'B']);
    });

    it('rend un tableau vide sur une entrée vide ou absente', () => {
        expect(fileCuisine([])).toEqual([]);
        expect(fileCuisine(null)).toEqual([]);
    });

    it('ne modifie pas le tableau reçu', () => {
        const src = [C, A, B];
        fileCuisine(src);
        expect(src.map((o) => o.id)).toEqual(['C', 'A', 'B']);
    });
});

describe('rangCuisine — « 2ᵉ sur 4 », jamais une durée prédite', () => {
    const A = cmd({ id: 'A', created_at: '2026-09-02T08:00:00+02:00' });
    const B = cmd({ id: 'B', created_at: '2026-09-02T08:05:00+02:00' });
    const C = cmd({ id: 'C', created_at: '2026-09-02T08:10:00+02:00' });
    const D = cmd({ id: 'D', created_at: '2026-09-02T08:15:00+02:00', status: 4 });

    it('donne le rang ET la profondeur totale de la file', () => {
        expect(rangCuisine(B, [A, B, C, D])).toEqual({ rang: 2, total: 4 });
        expect(rangCuisine(A, [A, B, C, D])).toEqual({ rang: 1, total: 4 });
        expect(rangCuisine(D, [A, B, C, D])).toEqual({ rang: 4, total: 4 });
    });

    it('compte les ACCEPT dans la profondeur — sinon la 4ᵉ se croirait 3ᵉ', () => {
        expect(rangCuisine(C, [A, B, C, D]).total).toBe(4);
    });

    it('rend null pour une commande qui n’est pas en cuisine', () => {
        expect(rangCuisine(cmd({ id: 'P', status: 8 }), [A, B])).toBeNull();
        expect(rangCuisine(null, [A, B])).toBeNull();
    });

    it('une commande à encaisser qui cuit a un rang : elle est dans les DEUX files', () => {
        const borne = cmd({ id: 'K', payment_status: 15, created_at: '2026-09-02T08:02:00+02:00' });
        expect(rangCuisine(borne, [A, borne, C])).toEqual({ rang: 2, total: 3 });
    });

    it('départage deux commandes de même horodatage par identifiant, de façon stable', () => {
        const X = cmd({ id: 10, created_at: '2026-09-02T08:00:00+02:00' });
        const Y = cmd({ id: 11, created_at: '2026-09-02T08:00:00+02:00' });
        expect(rangCuisine(X, [Y, X])).toEqual({ rang: 1, total: 2 });
        expect(rangCuisine(Y, [Y, X])).toEqual({ rang: 2, total: 2 });
    });
});

describe('attenteCuisine — les trois faits du ticket, aucune prédiction', () => {
    const maintenant = Date.parse('2026-09-02T08:14:00+02:00');
    const A = cmd({ id: 'A', created_at: '2026-09-02T08:00:00+02:00' });
    const B = cmd({ id: 'B', created_at: '2026-09-02T08:05:00+02:00' });

    it('rend le nombre en cuisine, l’âge de la plus ancienne, et le rang du prochain', () => {
        expect(attenteCuisine([A, B], maintenant)).toEqual({
            total: 2,
            plusAncienneMinutes: 14,
            prochainRang: 3,
        });
    });

    it('cuisine libre : zéro, zéro, et le prochain sera premier', () => {
        expect(attenteCuisine([], maintenant)).toEqual({
            total: 0,
            plusAncienneMinutes: 0,
            prochainRang: 1,
        });
    });

    it('n’annonce JAMAIS une durée d’attente estimée — aucune clé de prévision', () => {
        // Rejet C1 de la revue adverse : l’âge du plus ancien n’est pas l’attente du prochain,
        // et ce dépôt n’a aucun modèle de débit cuisine pour en fabriquer une.
        const r = attenteCuisine([A, B], maintenant);
        expect(Object.keys(r).sort()).toEqual(['plusAncienneMinutes', 'prochainRang', 'total']);
    });

    it('ne rend jamais un âge négatif si l’horloge du poste est en retard', () => {
        const futur = cmd({ created_at: '2026-09-02T09:00:00+02:00' });
        expect(attenteCuisine([futur], maintenant).plusAncienneMinutes).toBe(0);
    });
});
