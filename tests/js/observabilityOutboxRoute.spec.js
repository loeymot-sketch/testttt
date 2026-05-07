import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

// Mock axios — the component calls axios.get(...) on mount; we don't want
// real HTTP nor failing imports to break the smoke test.
vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: {} }),
        post: vi.fn().mockResolvedValue({ data: {} }),
    },
}));

/**
 * [CV1-OBSERVABILITY-OUTBOX-001] Sentinel — guards the SPA wiring of the
 * outbox dashboard so a careless rebase that drops the route or renames the
 * component will surface as a Vitest red, NOT as a 404 in production.
 *
 *   1. router/modules/observabilityRoutes.js declares /admin/observability/outbox
 *   2. router/index.js registers observabilityRoutes in the routes.concat(...)
 *   3. components/admin/observability/OutboxOverviewComponent.vue exists
 *   4. component renders the 5 sections (data-testids) the controller feeds
 *   5. polling is configured (setInterval + visibilitychange)
 */
describe('[CV1-OBSERVABILITY-OUTBOX-001] Outbox dashboard SPA wiring', () => {
    const cwd = process.cwd();

    it('observabilityRoutes module declares /admin/observability/outbox', () => {
        const file = resolve(cwd, 'resources/js/router/modules/observabilityRoutes.js');
        const content = readFileSync(file, 'utf-8');
        expect(content).toContain('/admin/observability/outbox');
        expect(content).toContain('admin.observability.outbox');
        expect(content).toContain('OutboxOverviewComponent');
    });

    it('observabilityRoutes is registered in main router', () => {
        const file = resolve(cwd, 'resources/js/router/index.js');
        const content = readFileSync(file, 'utf-8');
        expect(content).toMatch(/observabilityRoutes/);
        expect(content).toMatch(/import\s+observabilityRoutes\s+from/);
    });

    it('OutboxOverviewComponent.vue exists with 5 data-testid sections', () => {
        const file = resolve(
            cwd,
            'resources/js/components/admin/observability/OutboxOverviewComponent.vue'
        );
        const content = readFileSync(file, 'utf-8');

        // Five sections required by the mission:
        expect(content).toContain('data-testid="outbox-pending"');
        expect(content).toContain('data-testid="outbox-dispatched"');
        expect(content).toContain('data-testid="outbox-queue-high"');
        expect(content).toContain('data-testid="outbox-failed-jobs"');
        expect(content).toContain('data-testid="outbox-health"');
    });

    it('OutboxOverviewComponent configures polling + visibility refresh', () => {
        const file = resolve(
            cwd,
            'resources/js/components/admin/observability/OutboxOverviewComponent.vue'
        );
        const content = readFileSync(file, 'utf-8');
        // Polling primitives — setInterval w/ pollIntervalMs prop, visibilitychange listener.
        expect(content).toContain('pollIntervalMs');
        expect(content).toContain('setInterval');
        expect(content).toContain('visibilitychange');
        // beforeUnmount must clean the timer to avoid leaked intervals on route change.
        expect(content).toContain('beforeUnmount');
        expect(content).toMatch(/clearInterval/);
    });

    it('OutboxOverviewComponent wires retry/drain admin actions', () => {
        const file = resolve(
            cwd,
            'resources/js/components/admin/observability/OutboxOverviewComponent.vue'
        );
        const content = readFileSync(file, 'utf-8');
        expect(content).toContain('/api/admin/observability/outbox/retry-failed');
        expect(content).toContain('/api/admin/observability/outbox/drain-failed');
        expect(content).toContain('data-testid="outbox-retry-failed"');
        expect(content).toContain('data-testid="outbox-drain-failed"');
    });

    /**
     * Build-stand-in: actually compile + mount the .vue file with
     * @vue/test-utils. If the template has a parse error, a missing
     * import, or an invalid <script> block, this test fails. This is the
     * cheapest substitute for `npm run dev -- --build` since the harness
     * blocks build commands.
     */
    it('OutboxOverviewComponent compiles + mounts cleanly (build smoke)', async () => {
        // The component uses the global `axios` injected by the production
        // app shell (window.axios). Stub it with a resolved promise so the
        // mounted() hook completes without an unhandled rejection.
        const fakeAxios = {
            get: vi.fn().mockResolvedValue({ data: {} }),
            post: vi.fn().mockResolvedValue({ data: {} }),
        };
        // eslint-disable-next-line no-undef
        globalThis.axios = fakeAxios;

        const { mount, flushPromises } = await import('@vue/test-utils');
        const mod = await import(
            '../../resources/js/components/admin/observability/OutboxOverviewComponent.vue'
        );
        const Component = mod.default || mod;

        const wrapper = mount(Component, {
            global: {
                mocks: { $t: (k) => k },
                stubs: { i: true },
            },
        });
        await flushPromises();

        // Five sections must render in the DOM.
        expect(wrapper.find('[data-testid="outbox-overview-dashboard"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="outbox-pending"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="outbox-dispatched"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="outbox-queue-high"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="outbox-failed-jobs"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="outbox-health"]').exists()).toBe(true);
        // mounted() must have called the read endpoint at least once.
        expect(fakeAxios.get).toHaveBeenCalledWith('/api/admin/observability/outbox');
        wrapper.unmount();
        // eslint-disable-next-line no-undef
        delete globalThis.axios;
    });
});
