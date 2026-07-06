/**
 * [W5-PERF D3 2026-07-06] Contrat : les événements Echo du POS ne déclenchent
 * plus 3 GET complets CHACUN — ils passent par UN refresh de panneaux coalescé.
 *
 * Mesuré AVANT (verdicts.md D3) : `_subscribeEcho` → OrderCreated /
 * OrderStatusChanged / OrderPaidAtCounter appelaient chacun immédiatement
 * loadKioskCashOrders + loadActiveOrdersStats + loadReadyOrders (counter-collect
 * 174-369 ms + oss-order 58-110 ms ×2). En rush (rafale de N événements) : N×3
 * requêtes dont N-1 refreshs identiques.
 *
 * Contrat verrouillé :
 *   1. les 3 handlers Echo appellent UNIQUEMENT this._schedulePanelsRefresh() ;
 *   2. _schedulePanelsRefresh = debounce trailing 300-500 ms regroupant les
 *      3 loads + garde _destroyed ;
 *   3. la notification caissier (_notifyNewOrder) reste IMMÉDIATE sur
 *      OrderCreated (pas coalescée) ;
 *   4. le debounce est annulé au beforeUnmount ;
 *   5. le polling de secours (_startKioskPolling) garde ses appels directs
 *      (cadence déjà bornée 5-60 s, c'est le fallback quand Echo est mort).
 *   + comportement réel : une rafale → 1 seul refresh groupé (fake timers).
 */
import { describe, expect, it, vi, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import debounce from 'lodash/debounce';

const source = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf8',
);

function extractSubscribeEcho() {
    const match = source.match(/_subscribeEcho\(\) \{([\s\S]*?)\n {8}\},/);
    expect(match, '_subscribeEcho introuvable').toBeTruthy();
    return match[1];
}

function extractHandler(broadcastAs) {
    const block = extractSubscribeEcho();
    const match = block.match(
        new RegExp(`broadcastAs:\\s*'${broadcastAs}',\\s*handler:\\s*\\((?:event)?\\)\\s*=>\\s*\\{([\\s\\S]*?)\\n {24}\\},`),
    );
    expect(match, `handler ${broadcastAs} introuvable`).toBeTruthy();
    return match[1];
}

describe('POS Echo — refresh de panneaux coalescé (contrat source)', () => {
    it.each(['OrderCreated', 'OrderStatusChanged', 'OrderPaidAtCounter'])(
        'handler %s : _schedulePanelsRefresh() au lieu des 3 loads directs',
        (eventName) => {
            const body = extractHandler(eventName);
            expect(body).toMatch(/this\._schedulePanelsRefresh\(\)/);
            expect(body).not.toMatch(/this\.loadKioskCashOrders\(\)/);
            expect(body).not.toMatch(/this\.loadActiveOrdersStats\(\)/);
            expect(body).not.toMatch(/this\.loadReadyOrders\(\)/);
        },
    );

    it('la notification nouveau-ticket reste immédiate sur OrderCreated', () => {
        expect(extractHandler('OrderCreated')).toMatch(/this\._notifyNewOrder\(event\)/);
    });

    it('_schedulePanelsRefresh = debounce trailing 300-500 ms des 3 loads + garde _destroyed', () => {
        const match = source.match(
            /this\._schedulePanelsRefresh = debounce\(\(\) => \{([\s\S]*?)\}, (\d+)\);/,
        );
        expect(match, 'création de _schedulePanelsRefresh introuvable').toBeTruthy();
        const [, body, waitMs] = match;
        expect(Number(waitMs)).toBeGreaterThanOrEqual(300);
        expect(Number(waitMs)).toBeLessThanOrEqual(500);
        expect(body).toMatch(/this\._destroyed/);
        expect(body).toMatch(/this\.loadKioskCashOrders\(\)/);
        expect(body).toMatch(/this\.loadActiveOrdersStats\(\)/);
        expect(body).toMatch(/this\.loadReadyOrders\(\)/);
        // trailing pur : pas d'option leading (sinon 2 refreshs par rafale)
        expect(source).not.toMatch(/_schedulePanelsRefresh = debounce\([\s\S]{0,400}?leading:\s*true/);
    });

    it('beforeUnmount annule le refresh coalescé en vol', () => {
        expect(source).toMatch(
            /_schedulePanelsRefresh && this\._schedulePanelsRefresh\.cancel[\s\S]{0,80}?this\._schedulePanelsRefresh\.cancel\(\)/,
        );
    });

    it('le polling de secours garde ses appels directs (fallback Echo mort)', () => {
        const match = source.match(/_startKioskPolling\(\) \{([\s\S]*?)\n {8}\},/);
        expect(match, '_startKioskPolling introuvable').toBeTruthy();
        expect(match[1]).toMatch(/this\.loadKioskCashOrders\(\)/);
        expect(match[1]).toMatch(/this\.loadActiveOrdersStats\(\)/);
        expect(match[1]).toMatch(/this\.loadReadyOrders\(\)/);
    });
});

describe('POS Echo — comportement de rafale (même debounce lodash que le composant)', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('une rafale de 10 événements en 200 ms ⇒ UN seul refresh groupé', () => {
        vi.useFakeTimers();
        const loads = vi.fn();
        // Réplique exacte de la construction du composant (trailing 400 ms).
        const schedule = debounce(() => loads(), 400);
        for (let i = 0; i < 10; i++) {
            schedule();
            vi.advanceTimersByTime(20);
        }
        expect(loads).toHaveBeenCalledTimes(0); // rien pendant la rafale
        vi.advanceTimersByTime(400);
        expect(loads).toHaveBeenCalledTimes(1); // un refresh après la rafale
    });

    it('deux rafales espacées ⇒ deux refreshs (les événements ne sont pas perdus)', () => {
        vi.useFakeTimers();
        const loads = vi.fn();
        const schedule = debounce(() => loads(), 400);
        schedule();
        vi.advanceTimersByTime(500);
        schedule();
        vi.advanceTimersByTime(500);
        expect(loads).toHaveBeenCalledTimes(2);
    });

    it('cancel() en vol ⇒ aucun refresh après unmount', () => {
        vi.useFakeTimers();
        const loads = vi.fn();
        const schedule = debounce(() => loads(), 400);
        schedule();
        schedule.cancel();
        vi.advanceTimersByTime(1000);
        expect(loads).toHaveBeenCalledTimes(0);
    });
});
