/**
 * [FINITION 2026-11-04] Caisse — la ligne ajoutée est amenée à l'écran, et le ticket dit combien de
 * lignes attendent en dessous.
 *
 * Mesuré avant (banc tests/Playwright/finition-caisse-ligne-ajoutee-visible-2026-11-04.spec.js) : à
 * 1366×768 et 1024×768, le 3ᵉ article ajouté arrivait 70 px sous le bas visible du ticket, sans défilement,
 * et « 4 Articles » s'affichait au-dessus de 2 lignes seulement, sans aucun signe des 2 autres.
 *
 * Ce banc teste la logique sur un faux corps de ticket : quelle ligne est visée, quand on défile, et le
 * compte des lignes cachées — sans dépendre de la mise en page réelle (couverte par le banc Playwright).
 */
import { describe, it, expect, vi } from 'vitest';

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

const HAUTEUR_LIGNE = 80;

/** Faux corps de ticket : N lignes de 80 px, fenêtre visible de `visible` px. */
function corps(nLignes, visible, scrollTop = 0) {
    const lignes = Array.from({ length: nLignes }, (_, i) => ({ offsetTop: i * HAUTEUR_LIGNE, offsetHeight: HAUTEUR_LIGNE, offsetParent: null }));
    const el = {
        scrollTop, clientHeight: visible, offsetTop: 0,
        querySelectorAll: () => lignes,
        scrollTo: vi.fn(function ({ top }) { this.scrollTop = top; }),
    };
    lignes.forEach((l) => { l.offsetParent = el; });
    return el;
}

function vm(carts, el, quantitesAvant) {
    const v = {
        carts,
        _ticketQuantites: quantitesAvant,
        $refs: { ticketCorps: el },
        $nextTick: (fn) => fn(),
        lignesCacheesBas: 0,
    };
    v.mesurerLignesCachees = PosComponent.methods.mesurerLignesCachees.bind(v);
    v.montrerLigneTicket = vi.fn(PosComponent.methods.montrerLigneTicket.bind(v));
    return v;
}

describe('Caisse — ticket : la ligne ajoutée reste visible', () => {
    it('empreinte scalaire « lignes:quantité » (aucun observateur profond)', () => {
        const e = PosComponent.computed.ticketEmpreinte.call({ carts: [{ quantity: 2 }, { quantity: 1 }], cartTotalQuantity: 3 });
        expect(e).toBe('2:3');
        expect(PosComponent.watch.ticketEmpreinte.deep).toBeUndefined();
    });

    it('NOUVELLE ligne en fin de liste hors de vue → le ticket défile jusqu’à elle', () => {
        const el = corps(3, 170);
        const v = vm([{ quantity: 1 }, { quantity: 1 }, { quantity: 1 }], el, [1, 1]);
        PosComponent.watch.ticketEmpreinte.call(v, '3:3', '2:2');
        expect(v.montrerLigneTicket).toHaveBeenCalledWith(2);
        // Ligne 3 : 160→240 px ; fenêtre 170 px → défilement à 240 − 170 + 4 = 74.
        expect(el.scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 74 }));
        expect(v.lignesCacheesBas).toBe(0);
    });

    it('quantité augmentée sur une ligne EXISTANTE hors de vue → défile jusqu’à cette ligne', () => {
        const el = corps(4, 170);
        const v = vm([{ quantity: 1 }, { quantity: 1 }, { quantity: 1 }, { quantity: 2 }], el, [1, 1, 1, 1]);
        PosComponent.watch.ticketEmpreinte.call(v, '4:5', '4:4');
        expect(v.montrerLigneTicket).toHaveBeenCalledWith(3);
    });

    it('ligne déjà visible → aucun défilement', () => {
        const el = corps(2, 170);
        const v = vm([{ quantity: 2 }, { quantity: 1 }], el, [1, 1]);
        PosComponent.watch.ticketEmpreinte.call(v, '2:3', '2:2');
        expect(v.montrerLigneTicket).toHaveBeenCalledWith(0);
        expect(el.scrollTo).not.toHaveBeenCalled();
    });

    it('RETRAIT → aucun défilement, mais le compte des lignes cachées est refait', () => {
        const el = corps(4, 170);
        const v = vm([{ quantity: 1 }, { quantity: 1 }, { quantity: 1 }, { quantity: 1 }], el, [1, 1, 1, 1, 1]);
        PosComponent.watch.ticketEmpreinte.call(v, '4:4', '5:5');
        expect(v.montrerLigneTicket).not.toHaveBeenCalled();
        // Fenêtre 0→170 : lignes 3 (160→240, 10 px visibles < 1/3) et 4 (240→320) cachées.
        expect(v.lignesCacheesBas).toBe(2);
    });

    it('compte des lignes cachées : une ligne visible au tiers ne compte pas, tout en bas → 0', () => {
        const el = corps(4, 190);
        const v = vm([], el, []);
        v.mesurerLignesCachees();
        // Ligne 3 : 160→240, 30 px visibles ≥ 80/3 → visible ; ligne 4 cachée.
        expect(v.lignesCacheesBas).toBe(1);
        el.scrollTop = 320 - 190;
        v.mesurerLignesCachees();
        expect(v.lignesCacheesBas).toBe(0);
    });

    it('mouvement réduit demandé → défilement instantané', () => {
        const ancien = window.matchMedia;
        window.matchMedia = () => ({ matches: true });
        try {
            const el = corps(3, 170);
            const v = vm([], el, []);
            v.montrerLigneTicket(2);
            expect(el.scrollTo).toHaveBeenCalledWith({ top: 74, behavior: 'auto' });
        } finally { window.matchMedia = ancien; }
    });
});
