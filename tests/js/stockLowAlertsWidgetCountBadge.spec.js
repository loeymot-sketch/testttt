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

/**
 * [test-e2e fix A-002/E-001/E-002/E-006 round-1 2026-08-16] canFetchAlerts()
 * comparait p.url === 'items' alors que le VRAI gate backend sur
 * GET admin/stock/low-alerts est `permission:items_show` (url seedée
 * 'items/show', PermissionTableSeeder.php:63 + StockRuptureDashboardController
 * __construct). RED avant fix : le test "refuse sur items/show=false" échouait
 * (le widget fetchait quand même, car il ne regardait que 'items') ; le test
 * "n'est PAS bloqué par items=false" échouait aussi (le widget se fermait à
 * tort sur la mauvaise clé). GREEN après fix : le gate suit exactement le
 * slug backend réel.
 */
function mountWidgetWithPerms(perms) {
  return mount(StockLowAlertsWidget, {
    global: {
      plugins: [i18n],
      mocks: { $store: { getters: { authPermission: perms } } },
      stubs: { LoadingComponent: true, 'router-link': true },
    },
  });
}

describe('[test-e2e fix A-002/E-001/E-002/E-006] StockLowAlertsWidget — gate items_show (pas items)', () => {
  afterEach(() => { vi.restoreAllMocks(); });

  it('ne fetch PAS quand la permission items/show est refusée (access:false)', async () => {
    axios.get.mockResolvedValue({ data: { alerts: [{ stockable_id: 1 }] } });
    const wrapper = mountWidgetWithPerms([{ url: 'items/show', access: false }]);
    await flushPromises();

    expect(axios.get).not.toHaveBeenCalled();
    expect(wrapper.find('[data-testid="stock-low-alerts-error"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('fetch quand items/show est accordé, MÊME si l\'entrée "items" (mauvais slug historique) est refusée', async () => {
    axios.get.mockResolvedValue({ data: { alerts: [] } });
    const wrapper = mountWidgetWithPerms([
      { url: 'items', access: false },
      { url: 'items/show', access: true },
    ]);
    await flushPromises();

    expect(axios.get).toHaveBeenCalledWith('admin/stock/low-alerts');
    wrapper.unmount();
  });

  it('ne fetch PAS quand items/show est refusé, MÊME si l\'entrée "items" (mauvais slug) est accordée', async () => {
    axios.get.mockResolvedValue({ data: { alerts: [] } });
    const wrapper = mountWidgetWithPerms([
      { url: 'items', access: true },
      { url: 'items/show', access: false },
    ]);
    await flushPromises();

    expect(axios.get).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});
