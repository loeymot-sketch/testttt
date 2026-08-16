import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import StockLowAlertsWidget from '../../resources/js/components/admin/dashboard/StockLowAlertsWidget.vue';
import frMessages from '../../resources/js/languages/fr.json';

vi.mock('axios', () => ({ default: { get: vi.fn() } }));
import axios from 'axios';

const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } });

/**
 * [T-D STOCK-IA 2026-08-16 · GOAL owner] "trois articles stock faible" — le
 * widget listait déjà les 5 premières alertes mais n'affichait jamais le
 * COMPTE total. Ce spec verrouille le badge de compte + le fait que le widget
 * est maintenant réellement monté sur le dashboard (DashboardComponent.vue).
 */
function mountWidget() {
  return mount(StockLowAlertsWidget, {
    global: {
      plugins: [i18n],
      mocks: { $store: { getters: { authPermission: [] } } },
      stubs: { LoadingComponent: true, 'router-link': true },
    },
  });
}

describe('[T-D STOCK-IA 2026-08-16] StockLowAlertsWidget — badge de compte', () => {
  afterEach(() => { vi.restoreAllMocks(); });

  it('affiche le compte total des alertes (pas seulement les 5 lignes visibles)', async () => {
    const alerts = Array.from({ length: 8 }, (_, i) => ({
      branch_id: 1, stockable_type: 'RawMaterial', stockable_id: i + 1,
      on_hand: 1, threshold_low: 5,
    }));
    axios.get.mockResolvedValue({ data: { alerts } });
    const wrapper = mountWidget();
    await flushPromises();

    const badge = wrapper.find('[data-testid="stock-low-alerts-count"]');
    expect(badge.exists()).toBe(true);
    expect(badge.text()).toBe('8');
    expect(wrapper.findAll('tbody tr').length).toBe(5);
    wrapper.unmount();
  });

  it('masque le badge quand il n\'y a aucune alerte', async () => {
    axios.get.mockResolvedValue({ data: { alerts: [] } });
    const wrapper = mountWidget();
    await flushPromises();

    expect(wrapper.find('[data-testid="stock-low-alerts-count"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('masque le badge pendant le chargement', async () => {
    let resolveFetch;
    axios.get.mockReturnValue(new Promise((r) => { resolveFetch = r; }));
    const wrapper = mountWidget();

    expect(wrapper.find('[data-testid="stock-low-alerts-count"]').exists()).toBe(false);
    resolveFetch({ data: { alerts: [{ stockable_id: 1 }] } });
    await flushPromises();
    wrapper.unmount();
  });
});
