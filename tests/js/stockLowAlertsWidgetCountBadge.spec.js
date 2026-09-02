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
      // [2026-09-02] Un droit RÉALISTE plutôt qu'une liste vide : ce cas mesure le COMPTE,
      // pas la garde de permission (qui a ses propres cas plus bas). Une liste vide fait
      // dépendre le résultat du comportement par défaut de `canFetchAlerts()`, ce qui n'est
      // pas ce qu'on veut prouver ici.
      mocks: { $store: { getters: { authPermission: [{ url: 'items/show', access: true }] } } },
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

/**
 * [2026-09-02] « Aucune alerte de stock bas » se lit « votre stock va bien ». Mesuré sur la
 * base réelle : 55 lignes de stock sur 55 ont `threshold_low` NULL, donc la requête de l'API
 * (`whereNotNull('threshold_low')`) ne peut JAMAIS rien remonter — le panneau était un feu
 * vert permanent, pendant que Coca-Cola 33cl était à 0. L'écran doit dire qu'il ne surveille
 * rien, au lieu de laisser croire que tout va bien.
 */
describe('StockLowAlertsWidget — « rien à signaler » vs « rien n\'est surveillé »', () => {
  afterEach(() => { vi.restoreAllMocks(); });

  function monter() {
    return mount(StockLowAlertsWidget, {
      global: {
        plugins: [i18n],
        mocks: { $store: { getters: { authPermission: [{ url: 'items/show', access: true }] } } },
        stubs: { LoadingComponent: true, 'router-link': true },
      },
    });
  }

  it('avertit quand du stock est suivi mais qu\'aucun seuil n\'est configuré', async () => {
    axios.get.mockResolvedValue({ data: { alerts: [], tracked_rows: 55, thresholds_configured: 0 } });
    const w = monter();
    await flushPromises();
    expect(w.find('[data-testid="stock-low-alerts-no-threshold"]').exists()).toBe(true);
    expect(w.text()).not.toContain(frMessages.label.no_low_alerts);
    w.unmount();
  });

  it('dit bien « aucune alerte » quand des seuils existent et que rien ne les franchit', async () => {
    axios.get.mockResolvedValue({ data: { alerts: [], tracked_rows: 55, thresholds_configured: 12 } });
    const w = monter();
    await flushPromises();
    expect(w.find('[data-testid="stock-low-alerts-no-threshold"]').exists()).toBe(false);
    expect(w.text()).toContain(frMessages.label.no_low_alerts);
    w.unmount();
  });

  it('ne crie pas au loup sur une base sans aucun stock suivi', async () => {
    axios.get.mockResolvedValue({ data: { alerts: [], tracked_rows: 0, thresholds_configured: 0 } });
    const w = monter();
    await flushPromises();
    expect(w.find('[data-testid="stock-low-alerts-no-threshold"]').exists()).toBe(false);
    expect(w.text()).toContain(frMessages.label.no_low_alerts);
    w.unmount();
  });
});
