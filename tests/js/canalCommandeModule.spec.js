// [GOAL CAISSE CONTRÔLE 2026-09-02] Le canal d'une commande, extrait du tableau de suivi.
//
// Ce banc verrouille une heuristique qui a DÉJÀ coûté deux régressions :
//   · `source_surface='web'` classé « caisse » jusqu'au 2026-07-20 (les commandes du site client
//     étaient invisibles dans l'onglet 🌐) ;
//   · téléphone / plateforme / livraison tous fondus dans « Caisse » jusqu'au 2026-08-24, alors
//     qu'une commande téléphone signifie « le client n'est PAS là » — l'inverse d'une vente au
//     comptoir.
// L'extraction dans un module partagé ne doit pas les rejouer.

import { describe, it, expect } from 'vitest';
import { canalDe, iconeCanal, classeCanal, CANAUX } from '../../resources/js/support/canalCommande';

describe('canalDe — d’après source_surface', () => {
    it.each([
        ['kiosk', 'kiosk'],
        ['pos', 'pos'],
        ['online', 'online'],
        ['web', 'online'],
        ['phone', 'phone'],
        ['delivery', 'delivery'],
        ['uber_eats', 'platform'],
        ['uber', 'platform'],
        ['ubereats', 'platform'],
        ['deliveroo', 'platform'],
        ['just_eat', 'platform'],
        ['justeat', 'platform'],
        ['platform', 'platform'],
    ])('« %s » → %s', (surface, attendu) => {
        expect(canalDe({ source_surface: surface })).toBe(attendu);
    });

    it('est insensible à la casse', () => {
        expect(canalDe({ source_surface: 'KIOSK' })).toBe('kiosk');
        expect(canalDe({ source_surface: 'Uber_Eats' })).toBe('platform');
    });

    it('lit `_origin` quand `source_surface` est absent', () => {
        expect(canalDe({ _origin: 'phone' })).toBe('phone');
    });
});

describe('canalDe — repli sur order_type quand la surface manque', () => {
    it('types borne 17 / 18 → kiosk', () => {
        expect(canalDe({ order_type: 17 })).toBe('kiosk');
        expect(canalDe({ order_type: 18 })).toBe('kiosk');
    });

    it('types caisse 15 / 20 → pos', () => {
        expect(canalDe({ order_type: 15 })).toBe('pos');
        expect(canalDe({ order_type: 20 })).toBe('pos');
    });

    it('type inconnu, surface inconnue, ou commande absente → pos', () => {
        expect(canalDe({ order_type: 5 })).toBe('pos');
        expect(canalDe({ source_surface: 'martien' })).toBe('pos');
        expect(canalDe({})).toBe('pos');
        expect(canalDe(null)).toBe('pos');
    });

    it('la surface prime TOUJOURS sur le type — une borne TAKEAWAY reste une borne', () => {
        expect(canalDe({ source_surface: 'kiosk', order_type: 10 })).toBe('kiosk');
        expect(canalDe({ source_surface: 'phone', order_type: 15 })).toBe('phone');
    });
});

describe('iconeCanal — une forme distincte par canal (WCAG 1.4.1)', () => {
    it('donne six pictogrammes tous différents', () => {
        const icones = CANAUX.map(iconeCanal);
        expect(new Set(icones).size).toBe(6);
    });

    it.each([
        ['kiosk', '🖥️'],
        ['online', '🌐'],
        ['phone', '📞'],
        ['platform', '🛵'],
        ['delivery', '🚗'],
        ['pos', '🛒'],
    ])('%s → %s', (canal, icone) => {
        expect(iconeCanal(canal)).toBe(icone);
    });
});

describe('classeCanal — pointe vers les règles partagées de pos-v5.css', () => {
    it('rend la classe de base et la variante', () => {
        expect(classeCanal('kiosk')).toBe('pos-canal pos-canal--kiosk');
    });

    it('retombe sur `pos` pour un canal inconnu plutôt que de fabriquer une classe morte', () => {
        expect(classeCanal('martien')).toBe('pos-canal pos-canal--pos');
    });
});
