// [GOAL CAISSE CONTRÔLE 2026-09-02] Un bouton-lien de la caisse doit être un VRAI lien.
//
// DÉFAUT TROUVÉ AU NAVIGATEUR, pas dans le code. En vérifiant que « Suivi commandes » gardait bien
// son `href` après l'interception du clic simple, la sonde a renvoyé `href: null` — et pas
// seulement sur ce bouton : « Encaissement » aussi, qui n'avait rien à voir avec le chantier.
//
// CAUSE : `PosV5Button` écrivait `:href="tag === 'a' ? href : null"`. Sur un `router-link`, ce
// `null` ne restait pas sans effet — il retombait sur l'ancre rendue par `RouterLink` et EFFAÇAIT
// le `href` que le routeur venait d'y calculer.
//
// POURQUOI PERSONNE NE L'AVAIT VU : le clic simple fonctionnait (le gestionnaire de `RouterLink`
// reste posé sur l'élément, `href` ou pas). Ce qui était mort, c'est tout le reste — clic du
// milieu, Ctrl/Cmd-clic, « copier l'adresse du lien », l'annonce « lien » au lecteur d'écran et
// la tabulation clavier, une ancre sans `href` n'étant pas focusable.
//
// Ce banc empêche le retour du défaut, dans les deux sens.

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import PosV5Button from '../../resources/js/components/admin/pos/v5/PosV5Button.vue';

/** Faux `router-link` : rend une ancre avec un href calculé, comme le vrai. */
const FauxRouterLink = {
    name: 'router-link',
    props: { to: { type: [String, Object], default: null } },
    render() {
        const cible = typeof this.to === 'string' ? this.to : `/${this.to?.name ?? ''}`;
        return h('a', { href: cible }, this.$slots.default ? this.$slots.default() : []);
    },
};

const monter = (props) => mount(PosV5Button, {
    props,
    global: { stubs: { 'router-link': FauxRouterLink } },
});

describe('PosV5Button — le href du routeur survit', () => {
    it('as="router-link" : l’ancre GARDE le href calculé par le routeur', () => {
        const w = monter({ as: 'router-link', to: { name: 'admin.pos-orders.tracker' } });
        expect(w.get('a').attributes('href')).toBe('/admin.pos-orders.tracker');
    });

    it('as="router-link" sans href propre : aucun attribut href vide n’est ajouté', () => {
        const w = monter({ as: 'router-link', to: '/admin/encaissement', href: null });
        expect(w.get('a').attributes('href')).toBe('/admin/encaissement');
    });

    it('as="a" : le href de la propriété est bien posé', () => {
        const w = monter({ as: 'a', href: 'https://example.test/facture.pdf' });
        expect(w.get('a').attributes('href')).toBe('https://example.test/facture.pdf');
    });

    it('as="button" : aucun href, c’est un bouton', () => {
        const w = monter({ as: 'button' });
        expect(w.get('button').attributes('href')).toBeUndefined();
    });

    it('un lien reste focusable au clavier — une ancre sans href ne l’est pas', () => {
        const w = monter({ as: 'router-link', to: { name: 'admin.encaissement' } });
        const ancre = w.get('a').element;
        expect(ancre.hasAttribute('href')).toBe(true);
    });
});
