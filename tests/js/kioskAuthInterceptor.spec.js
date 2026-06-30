import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
  installKioskAuthInterceptor,
  _resetKioskAuthInterceptorForTests,
} from '../../resources/js/helpers/kioskAuthInterceptor.js';

/**
 * [OWNER 2026-06-30] Le toast jaune « Session rafraîchie automatiquement »
 * réapparaissait sur la borne en pleine commande. Il vient de l'event
 * `kiosk-auth-retried` (récupération SILENCIEUSE d'un 401 — succès). Pour le
 * client c'est du bruit : on le supprime ENTIÈREMENT. `kiosk-auth-failed`
 * (déconnexion réelle) reste débouncé (1 message par rafale).
 */
describe('kioskAuthInterceptor — suppression du toast jaune de récupération', () => {
  beforeEach(() => {
    _resetKioskAuthInterceptorForTests();
    installKioskAuthInterceptor(); // idempotent (guard) → installé une seule fois
  });

  it('kiosk-auth-retried : ENTIÈREMENT supprimé → le listener bubble (KioskAppComponent) ne le reçoit JAMAIS', () => {
    const bubbleListener = vi.fn();
    window.addEventListener('kiosk-auth-retried', bubbleListener); // simule le toast.show() frozen
    window.dispatchEvent(new CustomEvent('kiosk-auth-retried', { detail: {} }));
    window.dispatchEvent(new CustomEvent('kiosk-auth-retried', { detail: {} }));
    window.dispatchEvent(new CustomEvent('kiosk-auth-retried', { detail: {} }));
    expect(bubbleListener).not.toHaveBeenCalled(); // 0 toast « Session rafraîchie »
    window.removeEventListener('kiosk-auth-retried', bubbleListener);
  });

  it('kiosk-auth-failed : reste débouncé → 1 message « Reconnexion » par rafale (déconnexion légitime)', () => {
    const bubbleListener = vi.fn();
    window.addEventListener('kiosk-auth-failed', bubbleListener);
    // rafale de 4 events < DEBOUNCE_MS → un seul passe (le 1er)
    for (let i = 0; i < 4; i++) {
      window.dispatchEvent(new CustomEvent('kiosk-auth-failed', { detail: { url: '/api' } }));
    }
    expect(bubbleListener).toHaveBeenCalledTimes(1);
    window.removeEventListener('kiosk-auth-failed', bubbleListener);
  });
});
