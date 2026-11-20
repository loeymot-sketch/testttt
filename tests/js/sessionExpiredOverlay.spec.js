import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

/**
 * [SESSION-EXPIRED-OVERLAY 2026-09-23] Audit findings #10/#11 : un burst de
 * 401 (jeton expiré/révoqué) laisse chaque widget afficher son propre "0"/
 * "aucune donnée"/"chargement infini" pendant la fenêtre transitoire avant la
 * redirection réelle vers /login (constat prod 23/09, /admin/pos-v4, 12+ 401
 * en ~2s). Ce module injecte un overlay DOM PUR (sans dépendance Vue/Vuex,
 * potentiellement incohérents au moment même où l'auth casse) dès le premier
 * 401, avant la redirection elle-même.
 */
describe('showSessionExpiredOverlay', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    // Le module garde un flag "déjà affiché" au niveau du module — reset du
    // registre de modules pour obtenir une instance fraîche à chaque test.
    vi.resetModules();
  });

  it('injecte un overlay plein écran visible dans le DOM, avec role=alert', async () => {
    const { showSessionExpiredOverlay } = await import('../../resources/js/shared/session-expired-overlay.js');
    showSessionExpiredOverlay();

    const overlay = document.querySelector('[data-testid="session-expired-overlay"]');
    expect(overlay).not.toBeNull();
    expect(overlay.getAttribute('role')).toBe('alert');
    expect(overlay.textContent).toContain('Session expirée');
  });

  it('accepte un message personnalisé', async () => {
    const { showSessionExpiredOverlay } = await import('../../resources/js/shared/session-expired-overlay.js');
    showSessionExpiredOverlay('Reconnexion en cours…');

    const overlay = document.querySelector('[data-testid="session-expired-overlay"]');
    expect(overlay.textContent).toContain('Reconnexion en cours…');
  });

  it('ne double jamais l\'overlay — un 2e appel (burst de plusieurs 401) est un no-op', async () => {
    const { showSessionExpiredOverlay } = await import('../../resources/js/shared/session-expired-overlay.js');
    showSessionExpiredOverlay();
    showSessionExpiredOverlay();
    showSessionExpiredOverlay();

    const overlays = document.querySelectorAll('[data-testid="session-expired-overlay"]');
    expect(overlays.length).toBe(1);
  });

  // [GARDE DE CONNEXION 2026-10-19] L'écran sombre était posé et JAMAIS enlevé : la navigation vers la connexion
  // échouait en silence et l'écran cuisine restait figé sur « reconnexion… » (reproduit au navigateur).
  describe('récupération automatique', () => {
    beforeEach(() => { vi.useFakeTimers(); window.history.pushState({}, '', '/admin/kitchen-display-system'); });
    afterEach(() => { vi.useRealTimers(); window.history.pushState({}, '', '/'); });

    it('porte un bouton « Se reconnecter » qui mène à la connexion', async () => {
      const aller = vi.fn();
      const { showSessionExpiredOverlay } = await import('../../resources/js/shared/session-expired-overlay.js');
      showSessionExpiredOverlay(undefined, { aller });
      document.querySelector('[data-testid="session-expired-reconnect"]').click();
      expect(aller).toHaveBeenCalledTimes(1);
    });

    it('si la navigation reste sans effet, redirige franchement au bout de 4 s', async () => {
      const aller = vi.fn();
      const { showSessionExpiredOverlay } = await import('../../resources/js/shared/session-expired-overlay.js');
      showSessionExpiredOverlay(undefined, { aller });
      vi.advanceTimersByTime(3900);
      expect(aller).not.toHaveBeenCalled();
      vi.advanceTimersByTime(200);
      expect(aller).toHaveBeenCalledTimes(1);
    });

    it('s\'efface seul quand la page de connexion est atteinte (plus jamais figé par-dessus)', async () => {
      const aller = vi.fn();
      const { showSessionExpiredOverlay } = await import('../../resources/js/shared/session-expired-overlay.js');
      showSessionExpiredOverlay(undefined, { aller });
      window.history.pushState({}, '', '/login');
      vi.advanceTimersByTime(400);
      expect(document.querySelector('[data-testid="session-expired-overlay"]')).toBeNull();
      vi.advanceTimersByTime(10000);
      expect(aller).not.toHaveBeenCalled();
    });
  });
});
