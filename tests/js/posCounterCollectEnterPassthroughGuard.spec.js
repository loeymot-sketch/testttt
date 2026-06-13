import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * [HEAL F1 / dispute-final-push 2026-06-13] — P1 fiscal/a11y.
 *
 * L'appui Entrée qui OUVRE le modal d'encaissement (click clavier sur le bouton
 * « Encaisser ») envoyait son keyUP dans l'input montant fraîchement autofocusé
 * → @keyup.enter="onConfirm" confirmait un encaissement ESPÈCES SANS aucune
 * revue (prouvé ×3 live : commandes 4513/4515/4518 encaissées par un seul
 * appui, mauvais mode de paiement + piste NF525 polluée).
 *
 * Contrat verrouillé : @keyup.enter passe désormais par onConfirmFromKeyboard,
 * qui IGNORE une Entrée arrivant <450 ms après l'ouverture sur un champ resté
 * vierge (la fuite arrive en ~1 frame). Une Entrée délibérée — champ édité OU
 * après un délai de revue — confirme normalement. Le bouton souris est inchangé.
 */

vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));
vi.mock('../../resources/js/services/alertService', () => ({
  default: { error: vi.fn(), success: vi.fn(), warning: vi.fn(), info: vi.fn(), default: vi.fn() },
}));

describe('PosCounterCollectModal — garde Entrée pass-through (F1)', () => {
  let wrapper;
  let axios;

  beforeEach(async () => {
    vi.resetModules();
    vi.clearAllMocks();
    axios = (await import('axios')).default;
    axios.post.mockResolvedValue({ data: { success: true, data: {} } });
    const { mount } = await import('@vue/test-utils');
    const { default: PosCounterCollectModal } = await import(
      '../../resources/js/components/admin/pos/PosCounterCollectModal.vue'
    );
    wrapper = mount(PosCounterCollectModal, {
      props: { order: { id: 4518, total: 3.0, queue_number: 'A0007' } },
      global: { mocks: { $t: (key) => key } },
    });
    await wrapper.vm.$nextTick();
  });

  it("ignore le keyup d'Entrée pass-through (champ vierge, juste ouvert) — AUCUN POST", async () => {
    // Régime de la fuite : section CASH ouverte à l'instant, champ jamais édité.
    expect(wrapper.vm.cashFieldPristine).toBe(true);
    wrapper.vm.cashSectionOpenedAt = (typeof performance !== 'undefined' && performance.now)
      ? performance.now() : Date.now();

    wrapper.vm.onConfirmFromKeyboard();
    await wrapper.vm.$nextTick();

    expect(axios.post).not.toHaveBeenCalled();
    expect(wrapper.emitted('confirmed')).toBeFalsy();
  });

  it('confirme si le caissier a édité le montant (champ non vierge) — POST émis', () => {
    wrapper.vm.cashFieldPristine = false; // saisie réelle
    const spy = vi.spyOn(wrapper.vm, 'onConfirm');

    wrapper.vm.onConfirmFromKeyboard();

    expect(spy).toHaveBeenCalledTimes(1);
  });

  it("confirme une Entrée délibérée après un délai de revue (>450 ms) — POST émis", () => {
    // Champ vierge mais l'ouverture remonte à 1 s : décision one-tap assumée.
    wrapper.vm.cashSectionOpenedAt = ((typeof performance !== 'undefined' && performance.now)
      ? performance.now() : Date.now()) - 1000;
    const spy = vi.spyOn(wrapper.vm, 'onConfirm');

    wrapper.vm.onConfirmFromKeyboard();

    expect(spy).toHaveBeenCalledTimes(1);
  });
});
