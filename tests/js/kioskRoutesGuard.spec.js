import { describe, it, expect, vi, beforeEach } from 'vitest';

// [BORNE-BLANK 2026-06-27] Régression prouvée sur la borne cloud : le guard
// requireKioskAuth chaînait next()/proceed sur la promesse de
// store.dispatch('kioskFilter/init') (puis 'kioskCart/kioskLogin'). Si cette
// promesse ne déclenchait jamais son .then (observé live : 0 réseau, 0 erreur,
// #app = <router-view> vide), next() n'était JAMAIS rappelé → écran BLANC, le
// composant kiosk ne montait pas. Ce test fige le contrat : le guard doit
// TOUJOURS rappeler next() de façon synchrone, même si les dispatches stallent.

const { mockStore, dispatch } = vi.hoisted(() => {
  const d = vi.fn(() => new Promise(() => {})); // ne se résout JAMAIS (le stall observé)
  return {
    dispatch: d,
    mockStore: {
      getters: { 'kioskFilter/hydrated': false },
      dispatch: d,
      state: { kioskCart: { kioskToken: null } },
    },
  };
});

vi.mock('../../resources/js/store/index.js', () => ({ default: mockStore }));

import kioskRoutes from '../../resources/js/router/modules/kioskRoutes';

const guard = kioskRoutes.find((r) => r.path === '/kiosk').beforeEnter;

describe('requireKioskAuth — la borne ne reste jamais blanche', () => {
  beforeEach(() => {
    dispatch.mockClear();
    mockStore.getters['kioskFilter/hydrated'] = false;
    mockStore.state.kioskCart.kioskToken = null;
    window.foodkingConfig = { kioskAutoLogin: { username: 'kiosk-m', password: 'pw' } };
  });

  it('appelle next() SYNCHRONEMENT même si init/login ne se résolvent jamais', () => {
    const next = vi.fn();
    guard({ name: 'kiosk.idle' }, {}, next);
    // Avant le fix : 0 appel (next chaîné sur une promesse jamais résolue) = écran blanc.
    expect(next).toHaveBeenCalledTimes(1);
    expect(next).toHaveBeenCalledWith(); // proceed (pas de redirection login)
  });

  it('pré-chauffe le login machine en arrière-plan quand des creds sont présents', () => {
    const next = vi.fn();
    guard({ name: 'kiosk.idle' }, {}, next);
    expect(dispatch).toHaveBeenCalledWith('kioskCart/kioskLogin', { username: 'kiosk-m', password: 'pw' });
    expect(next).toHaveBeenCalledWith();
  });

  it('redirige vers kiosk.login sans creds ni token', () => {
    window.foodkingConfig = { kioskAutoLogin: null };
    const next = vi.fn();
    guard({ name: 'kiosk.idle' }, {}, next);
    expect(next).toHaveBeenCalledWith({ name: 'kiosk.login' });
  });

  it('next() direct si un token kiosk existe déjà (pas de relogin)', () => {
    mockStore.state.kioskCart.kioskToken = 'tok-abc';
    const next = vi.fn();
    guard({ name: 'kiosk.idle' }, {}, next);
    expect(next).toHaveBeenCalledWith();
    expect(dispatch).not.toHaveBeenCalledWith('kioskCart/kioskLogin', expect.anything());
  });

  it('laisse passer la route login elle-même sans dispatch', () => {
    const next = vi.fn();
    guard({ name: 'kiosk.login' }, {}, next);
    expect(next).toHaveBeenCalledWith();
    expect(dispatch).not.toHaveBeenCalled();
  });
});
