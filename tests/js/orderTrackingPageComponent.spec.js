import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import OrderTrackingPageComponent from '../../resources/js/components/frontend/tracking/OrderTrackingPageComponent.vue';

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...args) => axiosGet(...args) } }));

function mountTracking(token = 'abc123') {
  return mount(OrderTrackingPageComponent, { props: { trackingToken: token } });
}

describe('[T-C SUIVI-CLIENT 2026-08-16] OrderTrackingPageComponent — page publique client', () => {
  afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers(); axiosGet.mockReset(); });

  it('interroge le bon endpoint public avec le tracking_token de la prop', async () => {
    axiosGet.mockResolvedValue({ data: { found: false } });
    const wrapper = mountTracking('THE-TOKEN-48');
    await flushPromises();
    expect(axiosGet).toHaveBeenCalledWith('frontend/order/track/THE-TOKEN-48');
    wrapper.unmount();
  });

  it('affiche un état "introuvable" propre quand found=false (jamais une erreur brute)', async () => {
    axiosGet.mockResolvedValue({ data: { found: false } });
    const wrapper = mountTracking();
    await flushPromises();
    expect(wrapper.find('[data-testid="ot-not-found"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="ot-loading"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('affiche les étapes + position + fourchette pendant la préparation', async () => {
    axiosGet.mockResolvedValue({
      data: {
        found: true, status: 7, status_label: 'En préparation', step: 3,
        queue_number: 'A0042', position_ahead: 4, almost_ready: false, ready: false,
        wait_low: 20, wait_high: 25, server_time: '2026-08-16T12:00:00+02:00',
      },
    });
    const wrapper = mountTracking();
    await flushPromises();

    expect(wrapper.find('[data-testid="ot-in-progress"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('A0042');
    expect(wrapper.text()).toContain('4');
    expect(wrapper.text()).toContain('20-25 min');
    expect(wrapper.find('[data-testid="ot-almost-ready"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('remplace position/fourchette par le bandeau "presque prête" quand almost_ready=true', async () => {
    axiosGet.mockResolvedValue({
      data: {
        found: true, status: 7, status_label: 'En préparation', step: 3,
        queue_number: 'A0043', position_ahead: 2, almost_ready: true, ready: false,
        wait_low: 15, wait_high: 20, server_time: '2026-08-16T12:00:00+02:00',
      },
    });
    const wrapper = mountTracking();
    await flushPromises();

    expect(wrapper.find('[data-testid="ot-almost-ready"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('affiche l\'écran "prête" et arrête le sondage', async () => {
    vi.useFakeTimers();
    axiosGet.mockResolvedValue({
      data: {
        found: true, status: 8, status_label: 'Prête', step: 4,
        queue_number: 'A0044', position_ahead: null, almost_ready: false, ready: true,
        wait_low: null, wait_high: null, server_time: '2026-08-16T12:00:00+02:00',
      },
    });
    const wrapper = mountTracking();
    await flushPromises();
    expect(wrapper.find('[data-testid="ot-ready"]').exists()).toBe(true);

    axiosGet.mockClear();
    vi.advanceTimersByTime(20000);
    await flushPromises();
    expect(axiosGet).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('affiche l\'écran "annulée" distinctement (step=0 partagé Annulée/Refusée)', async () => {
    axiosGet.mockResolvedValue({
      data: {
        found: true, status: 16, status_label: 'Annulée', step: 0,
        queue_number: 'A0045', position_ahead: null, almost_ready: false, ready: false,
        wait_low: null, wait_high: null, server_time: '2026-08-16T12:00:00+02:00',
      },
    });
    const wrapper = mountTracking();
    await flushPromises();
    expect(wrapper.find('[data-testid="ot-cancelled"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('[C-002] traite une réponse HTML (catch-all SPA sur token malformé) comme found:false DÉLIBÉRÉMENT, pas par accident', async () => {
    // Reproduit routes/web.php:237 : token malformé -> route/track/{trackingToken} ne
    // matche pas (regex [A-Za-z0-9]{48}) -> le catch-all SPA sert le shell HTML en 200.
    const html = '<!doctype html><html><head><title>Le Cayenne</title></head><body id="app"></body></html>';
    axiosGet.mockResolvedValue({ data: html, headers: { 'content-type': 'text/html; charset=UTF-8' } });
    const wrapper = mountTracking('ABCDEFGHIJ');
    await flushPromises();

    // Preuve que le garde-fou EXPLICITE a tranché (et pas juste data.found undefined par
    // hasard) : le champ d'introspection doit avoir été mis à `false` en connaissance de
    // cause, avant même de regarder data.found.
    expect(wrapper.vm._lastPollLooksLikeJson).toBe(false);
    expect(wrapper.vm.found).toBe(false);
    expect(wrapper.find('[data-testid="ot-not-found"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="ot-loading"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('[C-002] ne régresse pas le cas nominal JSON (found=true) même sans header Content-Type explicite', async () => {
    axiosGet.mockResolvedValue({
      data: {
        found: true, status: 7, status_label: 'En préparation', step: 3,
        queue_number: 'A0050', position_ahead: 1, almost_ready: false, ready: false,
        wait_low: 10, wait_high: 15, server_time: '2026-08-16T12:00:00+02:00',
      },
      // Pas de `headers` du tout (comme axios en environnement de test réel sans mock
      // complet) — le garde-fou C-002 ne doit pas devenir plus strict que nécessaire.
    });
    const wrapper = mountTracking();
    await flushPromises();

    expect(wrapper.vm._lastPollLooksLikeJson).toBe(true);
    expect(wrapper.find('[data-testid="ot-in-progress"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('affiche un bandeau réseau après 3 échecs consécutifs de sondage', async () => {
    vi.useFakeTimers();
    axiosGet.mockRejectedValue(new Error('network'));
    const wrapper = mountTracking();
    await flushPromises();
    expect(wrapper.find('[data-testid="ot-network-banner"]').exists()).toBe(false);

    for (let i = 0; i < 2; i++) {
      vi.advanceTimersByTime(8000);
      await flushPromises();
    }
    expect(wrapper.find('[data-testid="ot-network-banner"]').exists()).toBe(true);
    wrapper.unmount();
  });
});
