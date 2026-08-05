import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-WAVE-U-KDS-ARCHIVE-001
 * @source P-OWNER Wave U (2026-05-21)
 *
 * Sentinel for the KDS Prêt-archive partition. Owner-reported bug:
 * "Quand je clique sur prêt, la commande ne va pas dans les commandes
 *  archives. Elle devient grise mais c'est pas archivé et le temps continue."
 *
 * Root cause: KdsSyncService feeds [ACCEPT, PREPARING, PREPARED] to the
 * KDS active feed. The pre-Wave-U KdsV2Grid.visibleOrders did not partition
 * by state → PREPARED orders lingered in the 4×2 FIFO grid styled with
 * `.kds-card--ready { opacity: 0.7 }` (greyed) while the elapsed timer kept
 * ticking (chef saw a ghost ticket that could not be dismissed).
 *
 * Heal: partition into:
 *  - activeOrders (status 4|7) → 4×2 grid, FIFO, keyboard shortcuts
 *  - recentlyServed (status 8, slice(0,4), sorted by updated_at desc)
 *    → compact bottom strip with "il y a Xm" since served
 *
 * Structural invariants this sentinel locks:
 *   1. KdsV2Grid exposes `activeOrders` and `recentlyServed` computeds.
 *   2. The grid template renders `activeOrders.slice(0, 8)` (not visibleOrders).
 *   3. The served strip is rendered with `v-if="recentlyServed.length > 0"`.
 *   4. `onKey` reads from the RENDERED slice `visibleActiveOrders[idx]` (so
 *      [A]–[H] never targets an order hidden from the grid — neither a
 *      PREPARED archive ticket nor, post-KDS-3CARDS, an overflow order 4+).
 *   5. The watcher triggering auto-promote watches `activeOrders` (not the
 *      undifferentiated visibleOrders).
 *   6. i18n keys `kds_recently_served`, `kds_served_just_now`,
 *      `kds_served_ago` exist in fr.json / en.json / ar.json.
 *
 * Anti-régression: if any of these invariants drifts, the grid would
 * either regress to showing PREPARED ghosts (V0 bug) or break the
 * keyboard shortcut alignment with on-card [A]–[H] badges.
 */
describe('KDS Wave U — Prêt archive partition (FK-WAVE-U-KDS-ARCHIVE-001)', () => {
    const gridPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue',
    );
    const cardPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue',
    );
    const gridSource = readFileSync(gridPath, 'utf8');
    const cardSource = readFileSync(cardPath, 'utf8');
    const frJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'),
    );
    const enJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf8'),
    );
    const arJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/ar.json'), 'utf8'),
    );

    it('grid exposes activeOrders computed filtering to status ACCEPT|PREPARING', () => {
        expect(gridSource).toMatch(/activeOrders\s*\(\s*\)\s*\{/);
        // Must reference both ORDER_STATUS.ACCEPT and ORDER_STATUS.PREPARING
        expect(gridSource).toMatch(/ORDER_STATUS\.ACCEPT/);
        expect(gridSource).toMatch(/ORDER_STATUS\.PREPARING/);
    });

    it('grid exposes recentlyServed computed filtering to status PREPARED (top 4 by updated_at desc)', () => {
        expect(gridSource).toMatch(/recentlyServed\s*\(\s*\)\s*\{/);
        expect(gridSource).toMatch(/ORDER_STATUS\.PREPARED/);
        // slice(0, 4) caps the strip at 4 pills
        expect(gridSource).toMatch(/\.slice\(\s*0\s*,\s*4\s*\)/);
        // updated_at is the chronology source (NOT created_at)
        expect(gridSource).toMatch(/updated_at/);
    });

    it('grid template renders activeOrders (ACTIVE only) — never visibleOrders directly', () => {
        // [KDS-6CARDS GOAL-8AXES 2026-08-05] La grille rend visibleActiveOrders =
        // activeOrders (TOUTES, flux horizontal, 6 par écran). L'invariant clé de CE
        // sentinel reste inchangé : la source est activeOrders (ACCEPT|PREPARING),
        // JAMAIS visibleOrders directement (qui inclurait les PREPARED archivés).
        expect(gridSource).toMatch(/v-for="\(o,\s*idx\)\s+in\s+visibleActiveOrders"/);
        // visibleActiveOrders DOIT dériver de activeOrders (plafond perf KDS_RENDER_MAX),
        // pas de visibleOrders.
        expect(gridSource).toMatch(/visibleActiveOrders\s*\(\s*\)\s*\{[\s\S]*?this\.activeOrders\.slice\(\s*0\s*,\s*KDS_RENDER_MAX\s*\)/);
        // Le v-for des cartes ne doit pas itérer visibleOrders (les PREPARED vont dans la bande servie).
        expect(gridSource).not.toMatch(/v-for="\(o,\s*idx\)\s+in\s+visibleOrders/);
    });

    it('grid template renders the recentlyServed strip with v-if guard', () => {
        expect(gridSource).toMatch(/v-if="recentlyServed\.length\s*>\s*0"/);
        expect(gridSource).toMatch(/kds-v2__served/);
        // Each pill must show queue_number and the relative servedAgoLabel
        expect(gridSource).toMatch(/servedAgoLabel\s*\(\s*o\s*\)/);
    });

    it('onKey shortcut handler reads shortcutOrders[idx], bounded to guaranteed-visible cards (P2-k)', () => {
        // Defense against drift where [A]–[H] could silently target an order
        // hidden from view. [KDS-6CARDS 2026-08-05] la grille rend TOUT en flux
        // horizontal, mais seules les KDS_VISIBLE_CARDS premières sont garanties
        // à l'écran SANS scroll → les raccourcis restent bornés à
        // `shortcutOrders = activeOrders.slice(0, KDS_VISIBLE_CARDS)`.
        expect(gridSource).toMatch(/this\.shortcutOrders\[idx\]/);
        expect(gridSource).toMatch(/idx\s*<\s*this\.shortcutOrders\.length/);
        // And it must NOT bind against the full activeOrders queue.
        expect(gridSource).not.toMatch(/idx\s*<\s*this\.activeOrders\.length/);
    });

    it('auto-promote watcher watches activeOrders (not visibleOrders)', () => {
        // The watcher block must trigger off activeOrders so the candidate
        // picker never sees PREPARED orders that left the grid.
        const watchBlock = gridSource.match(/watch:\s*\{[\s\S]*?\n\s{4}\}/);
        expect(watchBlock).not.toBeNull();
        expect(watchBlock[0]).toMatch(/activeOrders\s*:/);
    });

    it('servedAgoLabel method exists with FR/EN-style relative output', () => {
        expect(gridSource).toMatch(/servedAgoLabel\s*\(\s*o\s*\)\s*\{/);
        expect(gridSource).toMatch(/kds_served_just_now/);
        expect(gridSource).toMatch(/kds_served_ago/);
    });

    it('i18n keys exist in fr/en/ar (under label namespace)', () => {
        for (const key of ['kds_recently_served', 'kds_served_just_now', 'kds_served_ago']) {
            expect(frJson.label[key], `fr.label.${key}`).toBeTruthy();
            expect(enJson.label[key], `en.label.${key}`).toBeTruthy();
            expect(arJson.label[key], `ar.label.${key}`).toBeTruthy();
        }
    });

    it('card body scrollbar widened to 8px and thumb darkened (Bug 2 heal)', () => {
        // Bug 2: ::-webkit-scrollbar width 4px → 8px, thumb #D1D5DB → #6B7280.
        // Reading the styled block ensures the change is shipped, not just
        // suggested in comments.
        const styleBlock = cardSource.match(/<style\s+scoped>[\s\S]*?<\/style>/);
        expect(styleBlock).not.toBeNull();
        const css = styleBlock[0];
        // ::-webkit-scrollbar width 8px present
        expect(css).toMatch(/\.kds-card__body::-webkit-scrollbar\s*\{[^}]*width:\s*8px/);
        // Thumb color darker than the previous #D1D5DB
        expect(css).toMatch(/\.kds-card__body::-webkit-scrollbar-thumb\s*\{[^}]*background:\s*#6B7280/);
        // Firefox: scrollbar-width: thin with a non-transparent color
        expect(css).toMatch(/scrollbar-width:\s*thin/);
        expect(css).toMatch(/scrollbar-color:\s*#6B7280/);
        // Body fade does not block scroll wheel
        expect(css).toMatch(/\.kds-card__body-fade\s*\{[^}]*pointer-events:\s*none/);
    });
});
