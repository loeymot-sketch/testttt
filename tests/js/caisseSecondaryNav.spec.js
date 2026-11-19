/**
 * [GOAL UX 2026-07-25] CaisseSecondaryNav : navigation cohérente entre les pages secondaires
 * de la caisse (Encaissement · Suivi · Historique · Écran client · Retour caisse). Le prop
 * `current` met en surbrillance la page active.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
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

  // [ESPACE ROUE 2026-11-19] Trois accès SÉPARÉS depuis la caisse : espace roue, avis & réseaux, hygiène.
  describe('accès séparés roue / avis / hygiène', () => {
    afterEach(() => { vi.unstubAllGlobals(); delete window.axios; });

    it('hygiène est un lien vers l\u2019accueil hygiène', () => {
      const h = mountNav('').find('[data-testid="csn-hygiene"]');
      expect(h.exists()).toBe(true);
      expect(h.attributes('data-to')).toBe('admin.hygiene.home');
    });

    it.each([['csn-roue', 'espace'], ['csn-avis', 'avis']])('%s demande une passe vers « %s »', async (id, ecran) => {
      const onglet = { location: null, opener: {} };
      vi.stubGlobal('open', vi.fn(() => onglet));
      const post = vi.fn(async () => ({ data: { url: 'https://exemple.test/passe' } }));
      vi.stubGlobal('axios', { post });
      const w = mountNav('');
      const lien = w.find(`[data-testid="${id}"]`);
      expect(lien.attributes('href')).toBe('/admin/roue'); // repli sans JavaScript : la porte à code
      await lien.trigger('click');
      await new Promise((r) => setTimeout(r, 0));
      expect(post).toHaveBeenCalledWith('/admin/wheel/screen-pass', { ecran });
      expect(onglet.location).toBe('https://exemple.test/passe');
    });
  });
});
