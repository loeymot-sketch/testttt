import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * [DISPUTE-R1 E-ADV-8 2026-06-12] — P2 : échec 401 mid-confirm d'encaissement
 * sans message d'échec explicite.
 *
 * Constat adversarial (vague E) : `POST counter-collect/{id}/confirm` → 401
 * (token expiré, TTL 480 min en prod) → le seul feedback était le message
 * backend BRUT « Unauthenticated. » (anglais, TTL toast 2 s, en course avec
 * la redirection logout de app.js). Le caissier qui a déjà accepté les
 * espèces du client ne sait PAS que l'encaissement n'a pas été enregistré.
 *
 * Contrat verrouillé ici :
 *   - branche 401 dédiée dans le catch d'onConfirm ;
 *   - message FR explicite « non encaissée » (jamais le brut EN) ;
 *   - état réessayable (submitting redevient false).
 */

const flushPromises = () => new Promise((r) => setTimeout(r, 0));

vi.mock('axios', () => ({
  default: {
    post: vi.fn(),
    get: vi.fn(),
  },
}));

vi.mock('../../resources/js/services/alertService', () => ({
  default: {
    error: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
    default: vi.fn(),
  },
}));

describe('PosCounterCollectModal — feedback FR explicite sur 401 mid-confirm (E-ADV-8)', () => {
  let wrapper;
  let axios;
  let alertService;

  beforeEach(async () => {
    vi.resetModules();
    vi.clearAllMocks();
    axios = (await import('axios')).default;
    alertService = (await import('../../resources/js/services/alertService')).default;
    const { mount } = await import('@vue/test-utils');
    const { default: PosCounterCollectModal } = await import(
      '../../resources/js/components/admin/pos/PosCounterCollectModal.vue'
    );
    wrapper = mount(PosCounterCollectModal, {
      props: { order: { id: 4537, total: 9.5, queue_number: 'A0011' } },
      global: { mocks: { $t: (key) => key } },
    });
    await wrapper.vm.$nextTick();
  });

  it('toaste un message FR explicite « non encaissée » au lieu du brut EN « Unauthenticated. »', async () => {
    axios.post.mockRejectedValueOnce({
      response: { status: 401, data: { message: 'Unauthenticated.' } },
    });

    await wrapper.vm.onConfirm();
    await flushPromises();

    expect(alertService.error).toHaveBeenCalledTimes(1);
    const msg = String(alertService.error.mock.calls[0][0]);
    // Jamais le message backend brut anglais.
    expect(msg).not.toContain('Unauthenticated');
    // Le caissier doit lire SANS AMBIGUÏTÉ que la commande n'est pas encaissée
    // et qu'il peut réessayer après reconnexion.
    expect(msg).toMatch(/PAS été encaissée/i);
    expect(msg).toMatch(/[Rr]econnectez/);
  });

  it("reste réessayable après le 401 (submitting libéré, modal pas auto-fermé en succès fantôme)", async () => {
    axios.post.mockRejectedValueOnce({
      response: { status: 401, data: { message: 'Unauthenticated.' } },
    });

    await wrapper.vm.onConfirm();
    await flushPromises();

    expect(wrapper.vm.submitting).toBe(false);
    // Aucun toast succès fantôme, aucun emit `confirmed`.
    expect(alertService.success).not.toHaveBeenCalled();
    expect(wrapper.emitted('confirmed')).toBeFalsy();
  });

  it('les autres erreurs (≠401/409/429) gardent le fallback message backend → label.encaisser_failed', async () => {
    axios.post.mockRejectedValueOnce({
      response: { status: 500, data: {} },
    });

    await wrapper.vm.onConfirm();
    await flushPromises();

    expect(alertService.error).toHaveBeenCalledTimes(1);
    expect(String(alertService.error.mock.calls[0][0])).toContain('label.encaisser_failed');
    expect(wrapper.vm.submitting).toBe(false);
  });
});
