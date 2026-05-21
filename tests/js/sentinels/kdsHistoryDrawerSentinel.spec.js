import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-WAVE-X3-KDS-HISTORY-001
 * @source P-OWNER Wave X3 (2026-05-21)
 *
 * Sentinel for the KDS "Historique du jour" read-only V1 drawer.
 * Owner mandate verbatim:
 *   "Sur KDS, je veux un bouton 'historique' ou 'archives'. Chef peut voir
 *    toutes les commandes du jour (prêt + livré). Vérifier le contenu si
 *    erreur. Trié comme grille principale."
 *
 * Read-only V1: revert PREPARED → PREPARING is intentionally NOT exposed
 * here. OrderStateMachine (frozen §7) forbids reverse transitions, and the
 * owner has classified this as "secondaire". Revert is V1.0.2 backlog
 * pending a LOCK plan + owner countersign.
 *
 * Structural invariants this sentinel locks (source-grep pattern, mirrors
 * `kdsV2ArchiveSlotSentinel.spec.js` / `kdsV2MultiBumpSentinel.spec.js`):
 *
 *   Drawer (KdsHistoryDrawer.vue):
 *     1. Component declared with name "KdsHistoryDrawer".
 *     2. Accepts `open: Boolean` prop and emits `close`.
 *     3. Calls the canonical endpoint `admin/kds-order/history-today`
 *        (matches existing KDS axios pattern in
 *        store/modules/kitchenDisplaySystemOrder.js — no leading slash,
 *        no `/api/` prefix).
 *     4. Renders four mutually exclusive states (loading / error / empty /
 *        list) with stable `data-testid` hooks for E2E.
 *     5. Maps status integers 8/10/13 to the new i18n keys
 *        `label.kds_state_prepared/out/delivered`.
 *     6. Does NOT render any revert/annuler/recall control (V1 contract —
 *        deferred V1.0.2). Regression guard: no such word in source.
 *
 *   Host component (KitchenDisplaySystemComponent.vue):
 *     7. Imports + registers KdsHistoryDrawer.
 *     8. Owns `historyDrawerOpen` data flag.
 *     9. Renders a trigger button with stable `data-testid="kds-history-button"`
 *        wired to set `historyDrawerOpen = true`.
 *
 *   i18n keys (resources/js/languages/{fr,en,ar}.json under `label.*`):
 *     10. `kds_history_button`, `kds_history_title`, `kds_history_empty`,
 *         `kds_history_loading`, `kds_history_error`, `kds_history_retry`,
 *         `kds_history_button_aria`, `kds_history_close_aria`,
 *         `kds_state_prepared`, `kds_state_out`, `kds_state_delivered`.
 *
 * Anti-régression: if any of these invariants drifts, the drawer either
 * fails to fetch the correct endpoint (404 — leaked from sandbox-confused
 * V1 path), surfaces stale state, exposes a forbidden revert control, or
 * fails to render in one of the three locales.
 */
describe('KDS Wave X3 — Historique du jour drawer (FK-WAVE-X3-KDS-HISTORY-001)', () => {
    const drawerPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue',
    );
    const hostPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue',
    );

    const drawerSource = readFileSync(drawerPath, 'utf8');
    const hostSource = readFileSync(hostPath, 'utf8');

    const frJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'),
    );
    const enJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf8'),
    );
    const arJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/ar.json'), 'utf8'),
    );

    describe('Drawer component', () => {
        it('declares name "KdsHistoryDrawer"', () => {
            expect(drawerSource).toMatch(/name:\s*['"]KdsHistoryDrawer['"]/);
        });

        it('accepts open: Boolean prop and emits close', () => {
            expect(drawerSource).toMatch(/props:\s*\{[\s\S]{0,300}?open:\s*\{[\s\S]{0,200}?type:\s*Boolean/);
            expect(drawerSource).toMatch(/emits:\s*\[\s*['"]close['"]\s*\]/);
        });

        it('fetches the canonical endpoint admin/kds-order/history-today (no /api/ prefix)', () => {
            // Matches existing pattern from store/modules/kitchenDisplaySystemOrder.js:
            //   let url = 'admin/kds-order';
            // axios baseURL handles the /api/ prefix project-wide.
            expect(drawerSource).toMatch(/axios\.get\(\s*['"]admin\/kds-order\/history-today['"]/);
            // Guard against the sandbox-era wrong URL.
            expect(drawerSource).not.toMatch(/admin\/kds\/history-today/);
        });

        it('renders four mutually exclusive states with stable testids', () => {
            expect(drawerSource).toMatch(/data-testid=["']kds-history-loading["']/);
            expect(drawerSource).toMatch(/data-testid=["']kds-history-error["']/);
            expect(drawerSource).toMatch(/data-testid=["']kds-history-empty["']/);
            expect(drawerSource).toMatch(/data-testid=["']kds-history-list["']/);
            expect(drawerSource).toMatch(/data-testid=["']kds-history-drawer["']/);
            expect(drawerSource).toMatch(/data-testid=["']kds-history-close["']/);
        });

        it('maps status integers 8/10/13 to i18n keys label.kds_state_*', () => {
            expect(drawerSource).toMatch(/STATUS_PREPARED\s*=\s*8\b/);
            expect(drawerSource).toMatch(/STATUS_OUT_FOR_DELIVERY\s*=\s*10\b/);
            expect(drawerSource).toMatch(/STATUS_DELIVERED\s*=\s*13\b/);
            expect(drawerSource).toMatch(/\$t\(['"]label\.kds_state_prepared['"]\)/);
            expect(drawerSource).toMatch(/\$t\(['"]label\.kds_state_out['"]\)/);
            expect(drawerSource).toMatch(/\$t\(['"]label\.kds_state_delivered['"]\)/);
        });

        it('does NOT render any revert / annuler / recall control in V1', () => {
            // Revert is V1.0.2 backlog (OrderStateMachine §7 LOCK). Comments
            // in the source may mention the word "revert" in the rationale —
            // strip HTML/template content before checking, since the guard
            // is about USER-FACING controls, not code comments.
            // Take only template + script logic identifiers (exclude comments).
            const noBlockComments = drawerSource.replace(/\/\*[\s\S]*?\*\//g, '');
            const noLineComments = noBlockComments.replace(/(?<!:)\/\/[^\n]*/g, '');
            const noHtmlComments = noLineComments.replace(/<!--[\s\S]*?-->/g, '');
            // Look only at JSX-like attribute values and visible text — match
            // an interactive control or visible button label.
            const lower = noHtmlComments.toLowerCase();
            expect(lower).not.toMatch(/<button[^>]*>[^<]*revert/);
            expect(lower).not.toMatch(/<button[^>]*>[^<]*annuler/);
            expect(lower).not.toMatch(/<button[^>]*>[^<]*recall/);
            expect(lower).not.toMatch(/<button[^>]*>[^<]*rétablir/);
            expect(lower).not.toMatch(/@click=["'][^"']*revert/);
        });
    });

    describe('Host component (KitchenDisplaySystemComponent.vue)', () => {
        it('imports and registers KdsHistoryDrawer', () => {
            expect(hostSource).toMatch(/import\s+KdsHistoryDrawer\s+from\s+["']\.\/KdsHistoryDrawer\.vue["']/);
            expect(hostSource).toMatch(/components:\s*\{[\s\S]*?KdsHistoryDrawer[\s\S]*?\}/);
        });

        it('owns historyDrawerOpen data flag (default false)', () => {
            expect(hostSource).toMatch(/historyDrawerOpen:\s*false/);
        });

        it('renders trigger button with stable testid wired to open the drawer', () => {
            expect(hostSource).toMatch(/data-testid=["']kds-history-button["']/);
            expect(hostSource).toMatch(/@click=["']historyDrawerOpen\s*=\s*true["']/);
            // Mount the drawer at root so it works in BOTH V2 and legacy render paths.
            expect(hostSource).toMatch(/<KdsHistoryDrawer[\s\S]{0,200}?:open=["']historyDrawerOpen["']/);
            expect(hostSource).toMatch(/<KdsHistoryDrawer[\s\S]{0,300}?@close=["']historyDrawerOpen\s*=\s*false["']/);
        });
    });

    describe('i18n keys (Vue JSON locales)', () => {
        const keys = [
            'kds_history_button',
            'kds_history_button_aria',
            'kds_history_close_aria',
            'kds_history_title',
            'kds_history_empty',
            'kds_history_loading',
            'kds_history_error',
            'kds_history_retry',
            'kds_state_prepared',
            'kds_state_out',
            'kds_state_delivered',
        ];

        it('all keys exist under label.* in fr.json', () => {
            for (const k of keys) {
                expect(frJson.label, `fr.json missing key label.${k}`).toHaveProperty(k);
                expect(typeof frJson.label[k], `fr.json label.${k} must be a non-empty string`).toBe('string');
                expect(frJson.label[k].length, `fr.json label.${k} must not be empty`).toBeGreaterThan(0);
            }
        });

        it('all keys exist under label.* in en.json', () => {
            for (const k of keys) {
                expect(enJson.label, `en.json missing key label.${k}`).toHaveProperty(k);
                expect(typeof enJson.label[k]).toBe('string');
                expect(enJson.label[k].length).toBeGreaterThan(0);
            }
        });

        it('all keys exist under label.* in ar.json', () => {
            for (const k of keys) {
                expect(arJson.label, `ar.json missing key label.${k}`).toHaveProperty(k);
                expect(typeof arJson.label[k]).toBe('string');
                expect(arJson.label[k].length).toBeGreaterThan(0);
            }
        });
    });
});
