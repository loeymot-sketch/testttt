import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID  WAVE-X-X2 + Q10 | @source plan reports/test-e2e/wave-x-2026-05-21/PLAN.md
 *                          + owner Q10 decisions-v1.html 2026-05-21
 * @reason
 *   Wave X X2 adds 2 compact notification panels at the top of the POS main
 *   page so the cashier validates "Prêt à livrer" + "À encaisser borne"
 *   actions without navigating to /admin/pos-orders-tracker.
 *
 *   Q10 (2026-05-21) FIX: panels were hidden when both lists were empty,
 *   making a calm period indistinguishable from a WebSocket / polling
 *   outage. Owner mandate ⇒ ALWAYS render the 2 panels. Empty state shows
 *   a subtle copy + "Mis à jour il y a Xs" timestamp as a visible health
 *   signal. The root v-if is removed; individual panel bodies switch
 *   between filled list and empty copy.
 *
 *   Contract:
 *     - readyOrders[] data, hydrated from `orderStatusScreenOrder/lists`,
 *       filtered to status=PREPARED AND order_type ∈ {KIOSK, TAKEAWAY, POS}
 *     - kioskCashOrders[] (existing) feeds the cash panel
 *     - Max 4 rows per panel (slice(0, 4))
 *     - "Voir plus" overflow link visible when count > 4
 *     - 1-click markDelivered(order) via posOrder/changeStatus action
 *     - 1-click openCounterCollect(order) opens PosCounterCollectModal
 *     - Polling tick + Echo `OrderStatusChanged` both refresh readyOrders
 *     - Empty-state testids: pos-shortcuts-ready-empty + pos-shortcuts-cash-empty
 *     - Last-refresh testids: pos-shortcuts-ready-refresh + pos-shortcuts-cash-refresh
 *     - Stamps lastReadyRefresh + lastCashRefresh on successful loads
 *   This sentinel locks the structural surface so future refactors keep
 *   the X2 + Q10 promise stable.
 */
describe('PosComponent shortcuts — Wave X X2 main-page notifications', () => {
    const posPath = resolve(
        process.cwd(),
        'resources/js/components/admin/pos/PosComponent.vue',
    );
    const source = readFileSync(posPath, 'utf8');

    it('declares the readyOrders data array', () => {
        expect(source).toMatch(/readyOrders:\s*\[\]/);
    });

    it('mounts the 2 shortcut panels above the products grid (data-testid wrapper)', () => {
        expect(source).toMatch(/data-testid="pos-shortcuts"/);
        expect(source).toMatch(/data-testid="pos-shortcuts-ready"/);
        expect(source).toMatch(/data-testid="pos-shortcuts-cash"/);
    });

    it('Q10: always renders both panels (root v-if removed for visible health signal)', () => {
        // Q10 mandate — the panels MUST render even when both lists are
        // empty so the cashier sees the empty-state copy + last-refresh
        // timestamp (calm period vs polling outage signal). The legacy
        // root v-if `readyOrders.length>0 || kioskCashOrders.length>0` is
        // forbidden. Empty-state copy + refresh testids must be wired.
        expect(source).not.toMatch(/v-if="readyOrders\.length\s*>\s*0\s*\|\|\s*kioskCashOrders\.length\s*>\s*0"/);
        expect(source).toMatch(/data-testid="pos-shortcuts-ready-empty"/);
        expect(source).toMatch(/data-testid="pos-shortcuts-cash-empty"/);
        expect(source).toMatch(/data-testid="pos-shortcuts-ready-refresh"/);
        expect(source).toMatch(/data-testid="pos-shortcuts-cash-refresh"/);
    });

    it('Q10: lastRefresh state is stamped on successful loads', () => {
        // Both loaders must set their refresh timestamp inside the try{}
        // success path so the empty-state label can render
        // "Mis à jour il y a Xs". A failure path must NOT stamp (we want
        // a stale timestamp to surface the outage).
        expect(source).toMatch(/lastReadyRefresh:\s*null/);
        expect(source).toMatch(/lastCashRefresh:\s*null/);
        expect(source).toMatch(/this\.lastReadyRefresh\s*=\s*Date\.now\(\)/);
        expect(source).toMatch(/this\.lastCashRefresh\s*=\s*Date\.now\(\)/);
    });

    it('Q10: empty-state copy + 3 refresh-label i18n keys are referenced', () => {
        expect(source).toMatch(/pos_shortcut_ready_empty/);
        expect(source).toMatch(/pos_shortcut_cash_empty/);
        expect(source).toMatch(/pos_shortcut_last_refresh_now/);
        expect(source).toMatch(/pos_shortcut_last_refresh_sec/);
        expect(source).toMatch(/pos_shortcut_last_refresh_min/);
    });

    it('slices each panel to a maximum of 4 rows', () => {
        // Both panels must use `.slice(0, 4)` to enforce the compact footprint.
        const sliceMatches = source.match(/\.slice\(0,\s*4\)/g) || [];
        expect(sliceMatches.length).toBeGreaterThanOrEqual(2);
    });

    it('shows "Voir plus" overflow link when count > 4', () => {
        // Both panels must surface the overflow CTA when their list exceeds 4.
        expect(source).toMatch(/readyOrders\.length\s*>\s*4/);
        expect(source).toMatch(/kioskCashOrders\.length\s*>\s*4/);
        expect(source).toMatch(/pos_shortcut_view_more/);
    });

    it('exposes the markDelivered + openCounterCollect methods', () => {
        expect(source).toMatch(/markDelivered\s*\(/);
        expect(source).toMatch(/openCounterCollect\s*\(/);
    });

    it('markDelivered dispatches posOrder/changeStatus with DELIVERED status', () => {
        expect(source).toMatch(/posOrder\/changeStatus/);
        expect(source).toMatch(/status:\s*orderStatusEnum\.DELIVERED/);
    });

    it('loadReadyOrders filters PREPARED AND order_type ∈ {KIOSK, TAKEAWAY, POS}', () => {
        expect(source).toMatch(/loadReadyOrders/);
        expect(source).toMatch(/orderStatusEnum\.PREPARED/);
        expect(source).toMatch(/orderTypeEnum\.KIOSK/);
        expect(source).toMatch(/orderTypeEnum\.TAKEAWAY/);
        // DELIVERY orders MUST be excluded — driver flow is a separate UI.
        // We assert allowedTypes is a Set including KIOSK + TAKEAWAY (+ POS)
        // but not DELIVERY in the same expression.
        expect(source).toMatch(/allowedTypes\s*=\s*new Set/);
    });

    it('readyOrders is sorted oldest-first (cashier clears the longest-waiting first)', () => {
        expect(source).toMatch(/new Date\(a\.created_at\)\s*-\s*new Date\(b\.created_at\)/);
    });

    it('polling tick refreshes loadReadyOrders alongside the existing kiosk/badge loaders', () => {
        // _startKioskPolling must call loadReadyOrders inside the interval.
        const pollMatch = source.match(/_startKioskPolling\s*\(\)\s*\{[\s\S]+?\}\s*,/);
        expect(pollMatch).not.toBeNull();
        expect(pollMatch[0]).toMatch(/this\.loadReadyOrders\(\)/);
    });

    it('Echo OrderStatusChanged handler triggers loadReadyOrders (via refresh coalescé W5)', () => {
        // [W5-PERF D3 2026-07-06] Baseline update — la PROMESSE X2 est inchangée
        // (OrderStatusChanged ⇒ readyOrders rafraîchi) mais le mécanisme est
        // désormais coalescé : le handler appelle _schedulePanelsRefresh()
        // (debounce trailing 400 ms) dont le corps regroupe les 3 loads —
        // fin des 3 GET complets PAR événement en rafale (verdicts.md D3).
        // Le contrat est verrouillé en 2 maillons : handler → scheduler, et
        // scheduler → loadReadyOrders. Voir tests/js/posEchoDebounce.spec.js
        // pour le contrat complet (3 handlers + comportement de rafale).
        const idx = source.indexOf("broadcastAs: 'OrderStatusChanged'");
        expect(idx).toBeGreaterThan(-1);
        const slice = source.slice(idx, idx + 1500);
        expect(slice).toMatch(/this\._schedulePanelsRefresh\(\)/);

        const scheduler = source.match(
            /this\._schedulePanelsRefresh = debounce\(\(\) => \{([\s\S]*?)\}, \d+\);/,
        );
        expect(scheduler).not.toBeNull();
        expect(scheduler[1]).toMatch(/this\.loadReadyOrders\(\)/);
    });

    it('mounts the SSOT PosCounterCollectModal sibling component', () => {
        expect(source).toMatch(/import\s+PosCounterCollectModal/);
        expect(source).toMatch(/<PosCounterCollectModal/);
    });

    it('SSOT modal is wired via counterCollectOrder state + confirmed/cancel handlers', () => {
        expect(source).toMatch(/counterCollectOrder:\s*null/);
        expect(source).toMatch(/onCounterCollectConfirmed/);
        expect(source).toMatch(/onCounterCollectCancel/);
    });

    it('Wave W mode-picker template + methods are fully removed', () => {
        // Defensive: prove that the legacy in-component encaisserModal state
        // + methods are gone (they moved into PosCounterCollectModal.vue).
        expect(source).not.toMatch(/encaisserModal:\s*\{/);
        expect(source).not.toMatch(/openEncaisserModal\s*\(/);
        expect(source).not.toMatch(/confirmEncaisser\s*\(/);
        expect(source).not.toMatch(/closeEncaisserModal\s*\(/);
        // The buildKioskCashIdempotencyKey helper was inlined into
        // cancelKioskCashOrder; it must NOT survive as a method.
        expect(source).not.toMatch(/buildKioskCashIdempotencyKey\s*\(/);
    });

    it('shortcut Encaisser CTA routes through openCounterCollect (single SSOT entry)', () => {
        // The kiosk-cash slide-in panel "Encaisser" button + the X2 shortcut
        // "Encaisser" CTA both call openCounterCollect — single funnel.
        const occurrences = source.match(/@click="openCounterCollect\(/g) || [];
        expect(occurrences.length).toBeGreaterThanOrEqual(2);
    });
});
