import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID  Heal-5 — KDS recall (compensating action, PROPOSAL Path B)
 * @source proposals/PROPOSAL_KDS_ARCHIVE_UNDO_2026-05-25.md
 * @mandate owner verbatim 2026-05-26: « écran de cuisine, je peux pas y accéder
 *   aux archives parce que je peux par exemple avoir fait valider une commande
 *   par erreur avec rapidité, je vais revenir pour la corriger »
 *
 * Structural sentinel — any change that drops the recall button, breaks the
 * 60s TTL guard, or loses the RAPPELÉ badge wiring fails this test.
 *
 * What we check:
 *   1. KdsHistoryDrawer surfaces the "↶ Annuler bump" button via
 *      `label.kds_recall_button` and gates it through `canRecall(order)`.
 *   2. `canRecall` enforces (a) order.status === PREPARED (8),
 *      (b) `updated_at` within `RECALL_TTL_SECONDS * 1000` ms, and
 *      (c) the order is not already in `recalledMap`.
 *   3. POST URL is `admin/kds-order/recall/${order.id}` (matches the
 *      backend route registered in routes/api.php).
 *   4. KdsOrderCard renders the RAPPELÉ badge under `v-if="recallActive"`
 *      and references `label.kds_recall_badge`.
 *   5. KdsV2Grid accepts a `recallActiveIds` prop and re-injects PREPARED
 *      orders whose id matches into `activeOrders`.
 *   6. eventContract.js declares `KDS_ORDER_RECALLED` + the
 *      `KdsOrderRecalled` broadcastAs mapping so the websocket fan-out
 *      type-checks at runtime.
 */
describe('Heal-5 — KDS recall compensating action (Path B)', () => {
    const drawerSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue'),
        'utf8',
    );
    const cardSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue'),
        'utf8',
    );
    const gridSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue'),
        'utf8',
    );
    const orchestratorSource = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
        'utf8',
    );
    const contractSource = readFileSync(
        resolve(process.cwd(), 'resources/js/services/eventContract.js'),
        'utf8',
    );

    // ============================================================
    // 1. Drawer surfaces the recall button under the canRecall gate
    // ============================================================
    it('KdsHistoryDrawer renders the recall button gated by canRecall(order)', () => {
        expect(drawerSource).toMatch(/v-if="canRecall\(order\)"/);
        expect(drawerSource).toMatch(/label\.kds_recall_button(?!_)/);
        // Click handler dispatches to the local recall() method.
        expect(drawerSource).toMatch(/@click="recall\(order\)"/);
    });

    // ============================================================
    // 2. canRecall enforces the three gates
    // ============================================================
    it('canRecall enforces status === PREPARED + 60s window + N=1 cap', () => {
        // (a) Status check — must reference STATUS_PREPARED (constant 8 in this file).
        expect(drawerSource).toMatch(/STATUS_PREPARED\s*=\s*8/);
        expect(drawerSource).toMatch(/order\.status\s*!==\s*STATUS_PREPARED/);

        // (b) 60s window — explicit RECALL_TTL_SECONDS constant + math against `now`.
        expect(drawerSource).toMatch(/RECALL_TTL_SECONDS\s*:\s*60/);
        expect(drawerSource).toMatch(/this\.RECALL_TTL_SECONDS\s*\*\s*1000/);

        // (c) Cap N=1 — recalledMap lookup hides the button after success.
        expect(drawerSource).toMatch(/wasRecentlyRecalled\(order\)/);
        expect(drawerSource).toMatch(/this\.recalledMap/);
    });

    // ============================================================
    // 3. POST URL matches the backend route
    // ============================================================
    it('KdsHistoryDrawer POSTs to admin/kds-order/recall/${order.id}', () => {
        expect(drawerSource).toMatch(/axios\.post\(`admin\/kds-order\/recall\/\$\{order\.id\}`\)/);
    });

    // ============================================================
    // 4. Card renders RAPPELÉ badge under recallActive prop
    // ============================================================
    it('KdsOrderCard renders the RAPPELÉ badge under v-if="recallActive"', () => {
        expect(cardSource).toMatch(/v-if="recallActive"/);
        expect(cardSource).toMatch(/label\.kds_recall_badge(?!_aria)/);
        expect(cardSource).toMatch(/data-testid="`kds-card-recall-badge-\$\{order\.id\}`"/);
        // Prop declared with default false so legacy callers don't break.
        expect(cardSource).toMatch(/recallActive\s*:\s*\{\s*type:\s*Boolean,\s*default:\s*false/);
    });

    // ============================================================
    // 5. KdsV2Grid re-injects PREPARED orders that match recallActiveIds
    // ============================================================
    it('KdsV2Grid accepts recallActiveIds and re-injects PREPARED orders into activeOrders', () => {
        expect(gridSource).toMatch(/recallActiveIds\s*:\s*\{\s*type:\s*Array/);
        // The activeOrders computed must allow PREPARED through if its id is in
        // the recall set.
        expect(gridSource).toMatch(/recallIds\.has\(o\?\.id\)/);
        // Card receives the per-order flag via :recall-active.
        expect(gridSource).toMatch(/:recall-active="isRecallActive\(o\)"/);
    });

    // ============================================================
    // 6. eventContract.js declares the broadcast mapping
    // ============================================================
    it('eventContract.js declares KDS_ORDER_RECALLED + KdsOrderRecalled broadcastAs', () => {
        expect(contractSource).toMatch(/KDS_ORDER_RECALLED:\s*'kds\.order_recalled'/);
        expect(contractSource).toMatch(/KdsOrderRecalled:\s*EVENT_TYPES\.KDS_ORDER_RECALLED/);
    });

    // ============================================================
    // 7. Orchestrator wires the @recalled emit + Echo handler
    // ============================================================
    it('KitchenDisplaySystemComponent binds @recalled on the drawer + KdsOrderRecalled Echo handler', () => {
        expect(orchestratorSource).toMatch(/@recalled="onKdsOrderRecalled"/);
        expect(orchestratorSource).toMatch(/broadcastAs:\s*'KdsOrderRecalled'/);
        expect(orchestratorSource).toMatch(/onKdsOrderRecalled\(payload\)/);
        // The recallActiveIds computed feeds KdsV2Grid via :recall-active-ids.
        expect(orchestratorSource).toMatch(/:recall-active-ids="recallActiveIds"/);
        expect(orchestratorSource).toMatch(/recallActiveIds\(\)/);
    });

    // ============================================================
    // 8. Comment trail traces back to the PROPOSAL for auditability
    // ============================================================
    it('comment trail references PROPOSAL KDS Archive Undo + Path B', () => {
        const proposalTag = 'PROPOSAL KDS Archive Undo';
        expect(drawerSource).toContain(proposalTag);
        expect(cardSource).toContain(proposalTag);
        expect(gridSource).toContain(proposalTag);
        expect(orchestratorSource).toContain(proposalTag);
    });
});
