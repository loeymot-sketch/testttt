import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PosV5Button from '../../resources/js/components/admin/pos/v5/PosV5Button.vue';

/**
 * [UIUX-W2 F5 2026-06-11] Bouton « Écran client » inerte.
 *
 * Root-cause : le template bindait `:href="tag === 'a' ? href : null"`.
 * Quand `as="router-link"`, ce `href: null` tombe en fallthrough attr sur
 * le <a> rendu par router-link et ÉCRASE le href que router-link calcule
 * depuis `to` → ancre sans href, clic no-op (pas de navigation native ni
 * de focus clavier).
 *
 * Fix : ne binder `href` QUE quand tag === 'a' (v-bind conditionnel) ;
 * pour router-link, aucun attr `href` ne doit transiter en fallthrough.
 */

// Stub router-link qui expose ses fallthrough attrs sur le DOM rendu —
// reproduit le mécanisme d'écrasement de Vue (attrs fusionnés sur le <a>).
const RouterLinkStub = {
    name: 'router-link',
    props: ['to'],
    // $attrs contient les fallthrough du parent (dont href:null avant fix).
    template: '<a class="rl-stub" href="/computed-by-router" v-bind="$attrs"><slot /></a>',
    inheritAttrs: false,
};

const mountBtn = (props) => mount(PosV5Button, {
    props,
    slots: { default: 'Écran client' },
    global: { components: { 'router-link': RouterLinkStub } },
});

describe('PosV5Button href binding (F5 UIUX-W2)', () => {
    it('does NOT leak href:null onto router-link (keeps router-computed href)', () => {
        const wrapper = mountBtn({ as: 'router-link', to: '/admin/customer-screen' });
        const a = wrapper.find('a.rl-stub');
        expect(a.exists()).toBe(true);
        // Avant fix : href:null en fallthrough supprime l'attribut → null.
        expect(a.attributes('href')).toBe('/computed-by-router');
    });

    it('still binds href on a plain <a> tag', () => {
        const wrapper = mountBtn({ as: 'a', href: '/customer-screen' });
        const a = wrapper.find('a');
        expect(a.attributes('href')).toBe('/customer-screen');
    });

    it('renders a real <button> with type when as=button (no href)', () => {
        const wrapper = mountBtn({ as: 'button' });
        const btn = wrapper.find('button');
        expect(btn.exists()).toBe(true);
        expect(btn.attributes('href')).toBeUndefined();
        expect(btn.attributes('type')).toBe('button');
    });
});
