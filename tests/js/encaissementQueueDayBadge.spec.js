import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [DISPUTE-R1 B-R1-04 + E-ADV-4 2026-06-12] — part UI du P0/P1 « file
 * d'encaissement : N° de file dupliqués inter-jours affichés SANS date ».
 *
 * Constat adversarial (prouvé DB + live) : les queue_number (A0011…) se
 * réutilisent chaque business date ; 48+ commandes PENDING_COUNTER du 10/06
 * jamais purgées coexistaient avec celles du jour — 2× « A0011 » dans la
 * même liste, montants différents. La file servait les plus ANCIENNES en
 * premier : le run GStack a lui-même encaissé les mauvaises commandes.
 *
 * Contrat UI verrouillé ici (la purge backend = décision owner, hors scope) :
 *   1. tri : jour le plus récent D'ABORD (zombies en bas), FIFO intra-jour ;
 *   2. badge date (« hier » / jj/mm) sur toute carte d'un autre jour ;
 *   3. AUCUN badge sur les commandes du jour (zéro bruit) ;
 *   4. le modal de confirmation d'encaissement porte la date de la commande
 *      quand elle n'est pas d'aujourd'hui (« Commande d'hier » / « du … »).
 */

const NOW = new Date('2026-06-12T10:00:00');

const orders = [
  // Zombie du 10/06 — même N° de file que la commande du jour.
  { id: 4330, queue_number: 'A0011', created_at: '2026-06-10T09:00:00', total: 1.0, order_items: [], source_surface: 'kiosk' },
  // Commande d'HIER.
  { id: 4400, queue_number: 'A0002', created_at: '2026-06-11T18:00:00', total: 4.5, order_items: [], source_surface: 'kiosk' },
  // Commandes du JOUR (FIFO attendu : 4537 avant 4544).
  { id: 4537, queue_number: 'A0011', created_at: '2026-06-12T08:30:00', total: 9.5, order_items: [], source_surface: 'kiosk' },
  { id: 4544, queue_number: 'A0013', created_at: '2026-06-12T09:15:00', total: 23.0, order_items: [], source_surface: 'kiosk' },
];

describe('EncaissementComponent — badges date + tri jour-récent-d’abord (B-R1-04/E-ADV-4)', () => {
  let wrapper;

  beforeEach(async () => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
    vi.resetModules();

    globalThis.axios = {
      get: vi.fn().mockResolvedValue({ data: { data: orders } }),
    };
    delete globalThis.window.Echo;

    const { mount } = await import('@vue/test-utils');
    const { default: EncaissementComponent } = await import(
      '../../resources/js/components/admin/encaissement/EncaissementComponent.vue'
    );

    wrapper = mount(EncaissementComponent, {
      global: {
        mocks: {
          $t: (key) => key,
          $store: { getters: {}, state: {}, dispatch: vi.fn() },
        },
        stubs: {
          LoadingComponent: true,
          BreadcrumbComponent: true,
          PosCounterCollectModal: true,
        },
      },
    });
    // Laisse fetchPending résoudre sous fake timers.
    await vi.advanceTimersByTimeAsync(0);
    await wrapper.vm.$nextTick();
  });

  afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    delete globalThis.axios;
  });

  it('trie la file : commandes du jour D’ABORD (FIFO intra-jour), zombies des jours passés en bas', () => {
    const ids = wrapper.vm.sortedOrders.map((o) => o.id);
    expect(ids).toEqual([4537, 4544, 4400, 4330]);
  });

  it('badge « hier » sur la commande de J-1, badge jj/mm sur celle du 10/06', () => {
    expect(wrapper.find('[data-testid="enc-day-badge-4400"]').text()).toBe('hier');
    expect(wrapper.find('[data-testid="enc-day-badge-4330"]').text()).toBe('10/06');
  });

  it('AUCUN badge date sur les commandes du jour (zéro bruit)', () => {
    expect(wrapper.find('[data-testid="enc-day-badge-4537"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="enc-day-badge-4544"]').exists()).toBe(false);
  });
});

describe('PosCounterCollectModal — date de la commande dans le modal de confirmation (B-R1-04/E-ADV-4)', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
    vi.resetModules();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  const mountModal = async (order) => {
    const { mount } = await import('@vue/test-utils');
    const { default: PosCounterCollectModal } = await import(
      '../../resources/js/components/admin/pos/PosCounterCollectModal.vue'
    );
    return mount(PosCounterCollectModal, {
      props: { order },
      global: { mocks: { $t: (key) => key } },
    });
  };

  it('affiche « Commande d’hier » pour une commande de J-1', async () => {
    const w = await mountModal({ id: 4400, total: 4.5, queue_number: 'A0002', created_at: '2026-06-11T18:00:00' });
    await w.vm.$nextTick();
    const badge = w.find('[data-testid="pos-counter-collect-day-badge"]');
    expect(badge.exists()).toBe(true);
    expect(badge.text()).toContain("Commande d'hier");
    w.unmount();
  });

  it('affiche « Commande du 10/06/2026 » pour une commande plus ancienne', async () => {
    const w = await mountModal({ id: 4330, total: 1.0, queue_number: 'A0011', created_at: '2026-06-10T09:00:00' });
    await w.vm.$nextTick();
    const badge = w.find('[data-testid="pos-counter-collect-day-badge"]');
    expect(badge.exists()).toBe(true);
    expect(badge.text()).toContain('Commande du 10/06/2026');
    w.unmount();
  });

  it('AUCUNE date pour une commande du jour (cas normal)', async () => {
    const w = await mountModal({ id: 4537, total: 9.5, queue_number: 'A0011', created_at: '2026-06-12T08:30:00' });
    await w.vm.$nextTick();
    expect(w.find('[data-testid="pos-counter-collect-day-badge"]').exists()).toBe(false);
    w.unmount();
  });
});

describe('PosComponent (panneau file caisse) — mêmes garanties, vérifiées au source', () => {
  const src = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf8',
  );

  it('le tri de loadKioskCashOrders est jour-récent-d’abord + FIFO intra-jour', () => {
    // Périmètre : la fonction loadKioskCashOrders uniquement (readyOrders garde
    // légitimement son tri ancienneté-seule — commandes PRÊTES à remettre).
    const fnStart = src.indexOf('async loadKioskCashOrders()');
    const fnEnd = src.indexOf('toggleKioskCashOrderDetails', fnStart);
    const fn = src.slice(fnStart, fnEnd);
    expect(fn).toMatch(/kioskCashDayStart\(a\.created_at\)/);
    expect(fn).toMatch(/if\s*\(da\s*!==\s*db\)\s*return\s*db\s*-\s*da/);
    // L'ancien tri ancienneté-seule (qui servait les zombies en premier) est mort ICI.
    expect(fn).not.toMatch(/\.sort\(\(a,\s*b\)\s*=>\s*new Date\(a\.created_at\)\s*-\s*new Date\(b\.created_at\)\)\s*;/);
  });

  it('les cartes de la file portent le badge date kioskCashDayBadge', () => {
    expect(src).toMatch(/kioskCashDayBadge\(order\.created_at\)/);
    expect(src).toMatch(/kiosk-cash-day-badge/);
  });
});
