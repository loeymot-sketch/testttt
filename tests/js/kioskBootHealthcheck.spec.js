/**
 * [P0-5 / KIO-1] Boot healthcheck service tests.
 *
 * Verifies :
 *  - Bridge absent → stub_mode warnings, no critical (dev/test path).
 *  - Bridge present + TPE OK → ok=true, all critical checks pass, gate opens.
 *  - Bridge present + TPE unresponsive → critical_failure, gate stays closed.
 *  - Backend unreachable → critical_failure, gate stays closed.
 *  - Printer-only failure → warning, NOT critical (PDF fallback).
 *  - Bridge absent does NOT block tpeCharge (stub-mode permissiveness).
 *  - tpeCharge gate fails when bridge present + healthcheck not run.
 *  - tpeCharge gate passes after markBootHealthcheckPassed().
 *
 * Module state is reset between tests via vi.resetModules() + dynamic
 * import — same pattern as kioskHardwareService.spec.js.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

function freshImportPair() {
    vi.resetModules();
    return Promise.all([
        import('../../resources/js/services/kioskBootHealthcheck.js'),
        import('../../resources/js/services/kioskHardware.js'),
    ]);
}

function stubAxios({ getResp, postSpy } = {}) {
    window.axios = {
        get: vi.fn(async () => getResp ?? { data: { status: 'ok' } }),
        post: vi.fn(async (...args) => {
            postSpy?.(...args);
            return { data: { ok: true } };
        }),
    };
}

describe('kioskBootHealthcheck — performBootHealthcheck()', () => {
    afterEach(() => {
        delete window.borne;
        delete window.axios;
        vi.restoreAllMocks();
    });

    it('bridge_absent_returns_stub_mode_warnings (dev/browser path)', async () => {
        delete window.borne;
        stubAxios();
        const [boot] = await freshImportPair();
        const r = await boot.performBootHealthcheck();
        expect(r.ok).toBe(true);
        expect(r.checks.bridge_present).toBe(false);
        expect(r.checks.backend_reachable).toBe(true);
        expect(r.checks.tpe_responsive).toBe('stub_mode');
        expect(r.checks.printer_responsive).toBe('stub_mode');
        expect(r.checks.drawer_responsive).toBe('stub_mode');
        expect(r.warnings).toContain('running_browser_stub_mode');
        expect(r.critical_failures).toEqual([]);
    });

    it('bridge_present_tpe_ok_returns_ok_true_and_opens_gate', async () => {
        window.borne = {
            isElectron: true,
            healthcheck: async () => ({
                ok: true,
                components: { tpe: true, printer: true, drawer: true },
            }),
        };
        stubAxios();
        const [boot, hw] = await freshImportPair();
        const r = await boot.performBootHealthcheck();
        expect(r.ok).toBe(true);
        expect(r.critical_failures).toEqual([]);
        expect(r.checks.tpe_responsive).toBe(true);
        // Gate opened — tpeCharge should not short-circuit on healthcheck.
        expect(hw.isBootHealthcheckPassed()).toBe(true);
    });

    it('bridge_present_tpe_unresponsive_returns_critical_failure_and_keeps_gate_closed', async () => {
        window.borne = {
            isElectron: true,
            healthcheck: async () => ({
                ok: true,
                components: { tpe: 'error', printer: true },
            }),
        };
        stubAxios();
        const [boot, hw] = await freshImportPair();
        const r = await boot.performBootHealthcheck();
        expect(r.ok).toBe(false);
        expect(r.critical_failures).toContain('tpe_unresponsive');
        expect(r.checks.tpe_responsive).toBe(false);
        // Gate stays closed.
        expect(hw.isBootHealthcheckPassed()).toBe(false);
    });

    it('backend_unreachable_returns_critical_failure', async () => {
        window.borne = {
            isElectron: true,
            healthcheck: async () => ({ ok: true, components: { tpe: true, printer: true } }),
        };
        // Simulate axios rejecting the ping.
        window.axios = {
            get: vi.fn(async () => { throw new Error('ECONNREFUSED'); }),
            post: vi.fn(async () => ({ data: { ok: true } })),
        };
        const [boot, hw] = await freshImportPair();
        const r = await boot.performBootHealthcheck();
        expect(r.ok).toBe(false);
        expect(r.critical_failures).toContain('backend_unreachable');
        expect(r.checks.backend_reachable).toBe(false);
        expect(hw.isBootHealthcheckPassed()).toBe(false);
    });

    it('printer_failure_returns_warning_not_critical (PDF fallback)', async () => {
        window.borne = {
            isElectron: true,
            healthcheck: async () => ({
                ok: true,
                components: { tpe: true, printer: 'paper_out' },
            }),
        };
        stubAxios();
        const [boot, hw] = await freshImportPair();
        const r = await boot.performBootHealthcheck();
        expect(r.ok).toBe(true);
        expect(r.critical_failures).toEqual([]);
        expect(r.warnings).toContain('printer_unresponsive_falling_back_to_pdf');
        expect(r.checks.printer_responsive).toBe(false);
        // Gate opened — printer KO is non-blocking.
        expect(hw.isBootHealthcheckPassed()).toBe(true);
    });

    it('healthcheck_throwing_marks_critical_failure', async () => {
        window.borne = {
            isElectron: true,
            healthcheck: async () => { throw new Error('ipc_dead'); },
        };
        stubAxios();
        const [boot, hw] = await freshImportPair();
        const r = await boot.performBootHealthcheck();
        // healthcheck() in kioskHardware swallows throws and returns
        // {ok:false}; the boot service then escalates to critical.
        expect(r.ok).toBe(false);
        expect(r.critical_failures.some((f) =>
            f === 'hardware_healthcheck_unresponsive' || f === 'hardware_healthcheck_threw'
        )).toBe(true);
        expect(hw.isBootHealthcheckPassed()).toBe(false);
    });

    it('telemetry is fire-and-forget (errors swallowed)', async () => {
        delete window.borne;
        const postSpy = vi.fn();
        window.axios = {
            get: vi.fn(async () => ({ data: { status: 'ok' } })),
            post: vi.fn(async () => { throw new Error('boom'); }),
        };
        const [boot] = await freshImportPair();
        // Must not throw despite POST failing.
        await expect(boot.performBootHealthcheck()).resolves.toMatchObject({ ok: true });
    });
});

describe('kioskBootHealthcheck — tpeCharge gate (KIO-1 hardening)', () => {
    afterEach(() => {
        delete window.borne;
        delete window.axios;
        vi.restoreAllMocks();
    });

    it('bridge_absent_does_not_block_tpeCharge (regression guard)', async () => {
        // Critical regression we must NEVER introduce : in non-bridge envs
        // (browser/dev/tests), tpeCharge MUST pass through to the stub.
        delete window.borne;
        const [, hw] = await freshImportPair();
        // No call to performBootHealthcheck → gate never explicitly opened.
        const r = await hw.tpeCharge(1500, 'CB');
        expect(r.ok).toBe(true);
        // Stub returns a tx_ref starting with 'stub-'.
        expect(typeof r.tx_ref === 'string' && r.tx_ref.startsWith('stub-')).toBe(true);
    });

    it('bridge_present_tpeCharge_blocked_until_boot_healthcheck_passed', async () => {
        // Real Electron bridge but no boot check → tpeCharge MUST refuse.
        window.borne = {
            isElectron: true,
            tpeCharge: vi.fn(async () => ({ ok: true, tx_ref: 'real-tx-001' })),
        };
        const [, hw] = await freshImportPair();
        const r = await hw.tpeCharge(2500, 'CB');
        expect(r.ok).toBe(false);
        expect(r.error).toBe('healthcheck_required');
        // The bridge tpeCharge MUST NOT have been called — that's the
        // whole point of KIO-1 hardening.
        expect(window.borne.tpeCharge).not.toHaveBeenCalled();
    });

    it('bridge_present_tpeCharge_unblocked_after_markBootHealthcheckPassed', async () => {
        window.borne = {
            isElectron: true,
            tpeCharge: vi.fn(async () => ({ ok: true, tx_ref: 'real-tx-002' })),
        };
        const [, hw] = await freshImportPair();
        // Simulate the boot healthcheck having passed.
        hw.markBootHealthcheckPassed();
        const r = await hw.tpeCharge(2500, 'CB');
        expect(r.ok).toBe(true);
        expect(r.tx_ref).toBe('real-tx-002');
        expect(window.borne.tpeCharge).toHaveBeenCalledOnce();
    });

    it('resetBootHealthcheckGate_re_blocks_tpeCharge', async () => {
        // Retry semantics : after a successful boot, if operator triggers
        // a re-boot, the gate must close again.
        window.borne = {
            isElectron: true,
            tpeCharge: vi.fn(async () => ({ ok: true, tx_ref: 'real-tx-003' })),
        };
        const [, hw] = await freshImportPair();
        hw.markBootHealthcheckPassed();
        expect((await hw.tpeCharge(100, 'CB')).ok).toBe(true);
        hw.resetBootHealthcheckGate();
        const r = await hw.tpeCharge(100, 'CB');
        expect(r.ok).toBe(false);
        expect(r.error).toBe('healthcheck_required');
    });

    it('failed_boot_healthcheck_does_not_open_gate_then_retry_can_open_it', async () => {
        window.borne = {
            isElectron: true,
            healthcheck: vi.fn()
                .mockImplementationOnce(async () => ({ ok: true, components: { tpe: 'error', printer: true } }))
                .mockImplementationOnce(async () => ({ ok: true, components: { tpe: true, printer: true } })),
            tpeCharge: vi.fn(async () => ({ ok: true, tx_ref: 'tx-after-retry' })),
        };
        stubAxios();
        const [boot, hw] = await freshImportPair();

        const first = await boot.performBootHealthcheck();
        expect(first.ok).toBe(false);
        expect(hw.isBootHealthcheckPassed()).toBe(false);
        // tpeCharge is blocked.
        expect((await hw.tpeCharge(500, 'CB')).ok).toBe(false);

        // Retry succeeds.
        const second = await boot.performBootHealthcheck();
        expect(second.ok).toBe(true);
        expect(hw.isBootHealthcheckPassed()).toBe(true);
        expect((await hw.tpeCharge(500, 'CB')).ok).toBe(true);
    });
});
