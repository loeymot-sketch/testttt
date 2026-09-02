import { describe, it, expect } from 'vitest';

// [AUDIT-SUPERVISEUR 2026-08-25 · E-009]
//
// LE DÉFAUT — `collapseBundledAddonItems()` est le chemin emprunté par la CARTE DE
// PRODUCTION (`KdsOrderCard.vue:315`). Quand une ligne de formule est repliée dans
// son parent, le legs ne transmettait que les consignes d'instruction. Ses EXTRAS
// étaient jetés : un Cheddar payé disparaissait de l'écran de cuisine.
//
// POURQUOI C'EST GRAVE AU-DELÀ DU DÉFAUT LUI-MÊME — ce trou ANNULAIT, sur le seul
// chemin qui compte, le correctif posé la veille dans `kdsSymbolic`. On avait bouché
// la fuite en amont et le repli la rouvrait en aval. Le superviseur l'a mesuré vivant
// sur 21 commandes réelles.
//
// CE QUE CE SPEC EXIGE — une PRÉSENCE, jamais l'absence d'un symptôme. C'est la leçon
// de la journée : le test qui a laissé passer le défaut d'origine cherchait le motif
// « Extras: , , , » sur un gabarit qui n'émet aucun libellé « Extras: ».

import { collapseBundledAddonItems } from '../../resources/js/helpers/kdsBundledAddons.js';

/** Un parent qui revendique une formule, tel que le KDS le reçoit. */
const parentMenu = (over = {}) => ({
    item_name: 'Menu Tacos',
    quantity: 1,
    // Le parent REVENDIQUE ses lignes de formule par des lignes commençant par « + »
    // (`claimedAddonNames`), exactement comme le wizard les compose.
    instruction: '+ Frites',
    ...over,
});

/** La ligne enfant que le repli absorbe. */
const ligneRepliee = (over = {}) => ({
    item_name: 'Frites',
    quantity: 1,
    ...over,
});

/** Lit les extras comme le fait le rendu : instantané d'abord, ancienne colonne ensuite. */
const extrasVus = (ligne) => {
    const snap = ligne && ligne.composition_snapshot;
    if (snap && Array.isArray(snap.extras) && snap.extras.length > 0) return snap.extras;
    return (ligne && Array.isArray(ligne.item_extras)) ? ligne.item_extras : [];
};
const nomsVus = (ligne) => extrasVus(ligne).map((e) => e.extra_name || e.name || '').filter(Boolean);

describe('repli des formules — un supplément facturé ne disparaît jamais', () => {
    it('lègue au parent les extras de la ligne repliée (forme instantané NF525)', () => {
        const items = [
            parentMenu(),
            ligneRepliee({
                composition_snapshot: {
                    schema_version: 1,
                    extras: [{ extra_id: 1, extra_name: 'Cheddar', quantity: 2 }],
                },
            }),
        ];

        const rendu = collapseBundledAddonItems(items);

        // La ligne enfant est bien repliée…
        expect(rendu).toHaveLength(1);
        // …mais son cheddar a survécu, sur le parent.
        expect(nomsVus(rendu[0])).toContain('Cheddar');
    });

    it('lègue aussi les extras de l\'ANCIENNE forme (item_extras)', () => {
        const items = [
            parentMenu(),
            ligneRepliee({ item_extras: [{ id: 9, name: 'Oignons frits', quantity: 1 }] }),
        ];

        const rendu = collapseBundledAddonItems(items);

        expect(rendu).toHaveLength(1);
        expect(nomsVus(rendu[0])).toContain('Oignons frits');
    });

    it('écrit dans la source que le rendu LIRA — pas dans l\'autre', () => {
        // Le parent porte déjà un instantané : c'est lui que `readExtras` choisira.
        // Écrire l'héritage dans `item_extras` le rendrait invisible.
        const items = [
            parentMenu({
                composition_snapshot: { schema_version: 1, extras: [{ extra_id: 5, extra_name: 'Salade', quantity: 1 }] },
            }),
            ligneRepliee({
                composition_snapshot: { schema_version: 1, extras: [{ extra_id: 1, extra_name: 'Cheddar', quantity: 2 }] },
            }),
        ];

        const rendu = collapseBundledAddonItems(items);

        const noms = nomsVus(rendu[0]);
        expect(noms).toContain('Salade');
        expect(noms).toContain('Cheddar');
    });

    it('ne duplique pas un extra que le parent porte déjà', () => {
        const items = [
            parentMenu({ item_extras: [{ id: 1, name: 'Cheddar', quantity: 1 }] }),
            ligneRepliee({ item_extras: [{ id: 1, name: 'Cheddar', quantity: 1 }] }),
        ];

        const rendu = collapseBundledAddonItems(items);

        expect(nomsVus(rendu[0]).filter((n) => n === 'Cheddar')).toHaveLength(1);
    });

    it('ne mute JAMAIS l\'objet source — la ligne comptable reste intacte', () => {
        const enfant = ligneRepliee({
            composition_snapshot: { schema_version: 1, extras: [{ extra_id: 1, extra_name: 'Cheddar', quantity: 2 }] },
        });
        const parent = parentMenu();
        const avant = JSON.stringify([parent, enfant]);

        collapseBundledAddonItems([parent, enfant]);

        expect(JSON.stringify([parent, enfant])).toBe(avant);
    });

    it('n\'invente aucun extra quand la ligne repliée n\'en porte aucun', () => {
        const items = [parentMenu(), ligneRepliee()];

        const rendu = collapseBundledAddonItems(items);

        expect(rendu).toHaveLength(1);
        expect(nomsVus(rendu[0])).toEqual([]);
    });

    it('le comportement d\'origine tient : les consignes d\'instruction sont toujours léguées', () => {
        const items = [
            parentMenu({ instruction: '+ Frites' }),
            ligneRepliee({ instruction: 'Sauce frites: Andalouse' }),
        ];

        const rendu = collapseBundledAddonItems(items);

        expect(rendu).toHaveLength(1);
        expect(String(rendu[0].instruction)).toMatch(/Andalouse/i);
    });
});
