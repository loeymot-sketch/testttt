/**
 * [GOAL UX 2026-07-25] CaisseSecondaryNav : navigation cohérente entre les pages secondaires
 * de la caisse (Encaissement · Suivi · Historique · Écran client · Retour caisse). Le prop
 * `current` met en surbrillance la page active.
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CaisseSecondaryNav from '../../resources/js/components/admin/pos/CaisseSecondaryNav.vue';

const stubs = {
  // router-link stub qui rend le `to.name` en attribut pour l'assertion
  'router-link': {
    props: ['to'],
    template: '<a :data-to="to && to.name"><slot /></a>',
  },
};
const mountNav = (current = '') =>
  mount(CaisseSecondaryNav, {
    props: { current },
    global: { stubs, mocks: { $t: (k) => k } },
  });

describe('CaisseSecondaryNav', () => {
  it('rend les 5 liens de la suite caisse vers les bonnes routes', () => {
    const w = mountNav('encaissement');
    const routes = w.findAll('a').map((a) => a.attributes('data-to'));
    expect(routes).toContain('admin.encaissement');
    expect(routes).toContain('admin.pos-orders.tracker');
    expect(routes).toContain('admin.historique.list');
    expect(routes).toContain('admin.order-status-screen');
    expect(routes).toContain('admin.pos'); // retour caisse
  });

  it('met en surbrillance la page courante (current)', () => {
    expect(mountNav('encaissement').find('[data-testid="csn-encaissement"]').classes()).toContain('active');
    expect(mountNav('suivi').find('[data-testid="csn-suivi"]').classes()).toContain('active');
    expect(mountNav('historique').find('[data-testid="csn-historique"]').classes()).toContain('active');
  });

  it('sans current : aucun lien actif', () => {
    expect(mountNav('').findAll('.csn-link.active')).toHaveLength(0);
  });

  it('le bouton Retour caisse est présent et distinct', () => {
    const back = mountNav('encaissement').find('[data-testid="csn-back-caisse"]');
    expect(back.exists()).toBe(true);
    expect(back.classes()).toContain('csn-back');
  });
});
