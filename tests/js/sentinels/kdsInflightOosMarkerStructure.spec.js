import { describe, expect, it } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-CV1-KDS-INFLIGHT-OOS-MARKER-001
 * @source RED-R3 F3 + RED-R4 KD11
 *
 * Sentinel guarantees that the KDS surface keeps :
 *   - a Vuex `kdsInflight` module with a `recentlyDeavailable` state slice
 *   - a lazy 10-min TTL purge inside the live getter (no setInterval)
 *   - a real `_onItemAvailabilityChanged(parsed)` method (NOT inline arrow)
 *   - a per-card `kdsHasOosWarning(order)` helper guarded by status
 *   - the OOS warning badge rendered on every order card column (4 cards)
 *
 * Anti-régression : if any of these structural invariants vanishes, a
 * cuisinier could prepare a plate that has just been 86'd without seeing
 * any visual warning. This sentinel is meant to break loudly when that
 * regresses.
 */
describe('KDS in-flight OOS marker — structure (CV1-KDS-INFLIGHT-OOS-MARKER-001)', () => {
    const repoRoot = process.cwd();
    const componentPath = resolve(
        repoRoot,
        'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue',
    );
    const storeModulePath = resolve(repoRoot, 'resources/js/store/modules/kdsInflight.js');
    const storeIndexPath = resolve(repoRoot, 'resources/js/store/index.js');

    const componentSource = readFileSync(componentPath, 'utf8');
    const moduleSource = existsSync(storeModulePath) ? readFileSync(storeModulePath, 'utf8') : '';
    const storeIndexSource = readFileSync(storeIndexPath, 'utf8');

    it('Vuex module file exists at resources/js/store/modules/kdsInflight.js', () => {
        expect(existsSync(storeModulePath)).toBe(true);
    });

    it('Vuex module declares a namespaced kdsInflight store with recentlyDeavailable state', () => {
        expect(moduleSource).toMatch(/export\s+const\s+kdsInflight\s*=\s*\{/);
        expect(moduleSource).toMatch(/namespaced:\s*true/);
        expect(moduleSource).toMatch(/recentlyDeavailable\s*:/);
    });

    it('Vuex module exposes markDeavailable / clearItem / purgeExpired actions', () => {
        expect(moduleSource).toMatch(/markDeavailable\s*\(/);
        expect(moduleSource).toMatch(/clearItem\s*\(/);
        expect(moduleSource).toMatch(/purgeExpired\s*\(/);
    });

    it('Vuex module enforces 10-minute TTL (lazy purge inside getter)', () => {
        // 10 * 60 * 1000 = 600000ms — anti-régression on the literal
        expect(moduleSource).toMatch(/10\s*\*\s*60\s*\*\s*1000/);
        // Live getter must compare now() - ts < TTL_MS, NOT a setInterval
        expect(moduleSource).toMatch(/liveRecentlyDeavailable/);
        expect(moduleSource).toMatch(/now\s*-\s*entry\.ts\s*<\s*TTL_MS/);
        // Negative: no setInterval / setTimeout-based purge (would leak)
        expect(moduleSource).not.toMatch(/setInterval\s*\(/);
    });

    it('Vuex module is registered in resources/js/store/index.js', () => {
        expect(storeIndexSource).toMatch(/import\s*\{\s*kdsInflight\s*\}\s*from\s*'\.\/modules\/kdsInflight'/);
        expect(storeIndexSource).toMatch(/kdsInflight\s*,/);
    });

    it('KDS component declares a real _onItemAvailabilityChanged method (no inline arrow)', () => {
        // Strict pattern: a method, not an inline arrow on the listener bind site.
        expect(componentSource).toMatch(/_onItemAvailabilityChanged\s*\([^)]*\)\s*\{/);
        // The Echo listener binding must call this method (not refresh-only)
        expect(componentSource).toMatch(/broadcastAs:\s*'ItemAvailabilityChanged'[\s\S]*?_onItemAvailabilityChanged\s*\(/);
    });

    it('_onItemAvailabilityChanged dispatches the kdsInflight markDeavailable / clearItem actions', () => {
        expect(componentSource).toMatch(/dispatch\(\s*['"]kdsInflight\/markDeavailable['"]/);
        expect(componentSource).toMatch(/dispatch\(\s*['"]kdsInflight\/clearItem['"]/);
    });

    it('kdsHasOosWarning helper is guarded by ACCEPT / PREPARING status (in-flight only)', () => {
        expect(componentSource).toMatch(/kdsHasOosWarning\s*\([^)]*\)\s*\{/);
        // Must consult the kdsInflight getter (not a local map)
        expect(componentSource).toMatch(/kdsInflight\/orderHasRecentlyDeavailableItem/);
        // Must scope the check to in-flight statuses
        expect(componentSource).toMatch(/orderStatusEnum\.ACCEPT/);
        expect(componentSource).toMatch(/orderStatusEnum\.PREPARING/);
    });

    it('OOS warning badge renders on each order card column (data-testid + 4 occurrences)', () => {
        const occurrences = componentSource.match(/data-testid="kds-oos-warning-badge"/g) || [];
        // 4 cards : dine-in, online, takeaway, kiosk
        expect(occurrences.length).toBeGreaterThanOrEqual(4);
        // Badge must be guarded by kdsHasOosWarning (no unconditional render)
        expect(componentSource).toMatch(/v-if="kdsHasOosWarning\(\s*dineinOrder\s*\)"/);
        expect(componentSource).toMatch(/v-if="kdsHasOosWarning\(\s*onlineOrder\s*\)"/);
        expect(componentSource).toMatch(/v-if="kdsHasOosWarning\(\s*takeawayOrder\s*\)"/);
        expect(componentSource).toMatch(/v-if="kdsHasOosWarning\(\s*kioskOrder\s*\)"/);
    });

    it('OOS badge ships an aria-label and a title (tooltip + screen-reader)', () => {
        expect(componentSource).toMatch(/kds_oos_warning_tooltip/);
        expect(componentSource).toMatch(/kds_oos_warning_aria/);
    });
});
