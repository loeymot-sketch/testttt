/**
 * kioskBootHealthcheck.js — [P0-5 / KIO-1] Mandatory boot healthcheck.
 * -----------------------------------------------------------------------------
 *
 * Closes the KIO-1 hidden risk : when `kioskHardware.isKioskBridge() === true`
 * but TPE drivers fail init silently, `tpeCharge` could return ok-stub-like
 * → phantom card transactions. This service runs at boot, validates :
 *
 *   1. Bridge present (informational — false in browser/dev/tests).
 *   2. Backend reachable (DB + cache via /api/frontend/kiosk/health).
 *   3. TPE responsive (only if bridge present — via kioskHardware.healthcheck()).
 *   4. Printer responsive (warning only — falls back to PDF receipt).
 *   5. Drawer responsive (warning only — POS supervisor can override).
 *
 * On success, calls `markBootHealthcheckPassed()` so `tpeCharge()` is
 * unlocked. Until that flag is set, `tpeCharge()` short-circuits with
 * `{ok:false, error:'healthcheck_required'}` in real Electron sessions.
 *
 * Permissive design : in non-bridge environments (vitest, dev browser,
 * staging-without-Electron), the gate is bypassed entirely so the dev
 * loop is never blocked.
 *
 * Telemetry : the result is fire-and-forget POSTed to /api/frontend/kiosk-event
 * for operator dashboards. Pre-login boots will silently 401 since that
 * endpoint requires Sanctum — acceptable trade-off for a non-critical log.
 *
 * @see app/Http/Controllers/Frontend/KioskHealthController.php
 * @see resources/js/services/kioskHardware.js
 * @see plans/PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md §KIO-1 / §P0-5
 */
import kioskHardware from './kioskHardware';

const BACKEND_PING_TIMEOUT_MS = 3000;

/**
 * Symbolic check identifiers — mirrored by the overlay UI so labels stay
 * stable across translations and the telemetry payload remains greppable.
 */
export const CHECKS = Object.freeze({
    BRIDGE_PRESENT: 'bridge_present',
    BACKEND_REACHABLE: 'backend_reachable',
    TPE_RESPONSIVE: 'tpe_responsive',
    PRINTER_RESPONSIVE: 'printer_responsive',
    DRAWER_RESPONSIVE: 'drawer_responsive',
});

/**
 * Runs the full boot healthcheck and returns a structured result :
 *
 *   {
 *     ok: boolean,                // true = no critical_failures
 *     checks: { [name]: true | false | 'stub_mode' },
 *     critical_failures: string[], // codes that block payment session
 *     warnings: string[],          // non-blocking degradations
 *     completed_at: string,        // ISO8601
 *   }
 *
 * On success, this function calls `kioskHardware.markBootHealthcheckPassed()`
 * to open the `tpeCharge` gate. The caller (BootHealthcheckOverlay) MUST
 * NOT proceed to the kiosk UI when `ok === false`.
 *
 * @returns {Promise<object>} healthcheck result
 */
export async function performBootHealthcheck() {
    const results = {
        ok: false,
        checks: {},
        critical_failures: [],
        warnings: [],
        completed_at: null,
    };

    // 1. Bridge present (purely informational — false in browser/dev/tests)
    const bridgePresent = !!kioskHardware.isKioskBridge();
    results.checks[CHECKS.BRIDGE_PRESENT] = bridgePresent;

    // 2. Backend reachable (DB + cache ping). Always run — without backend
    //    the kiosk cannot persist orders, so this is critical regardless
    //    of bridge mode.
    results.checks[CHECKS.BACKEND_REACHABLE] = await pingBackend();
    if (results.checks[CHECKS.BACKEND_REACHABLE] !== true) {
        results.critical_failures.push('backend_unreachable');
    }

    // 3+4+5. Hardware checks — only meaningful when bridge present.
    if (bridgePresent) {
        await runHardwareChecks(results);
    } else {
        // Browser/dev mode : no hardware to check. Mark stub so the overlay
        // displays "—" instead of "✓"/"✗", and emit a warning so the
        // telemetry stream shows we knowingly bypassed.
        results.checks[CHECKS.TPE_RESPONSIVE] = 'stub_mode';
        results.checks[CHECKS.PRINTER_RESPONSIVE] = 'stub_mode';
        results.checks[CHECKS.DRAWER_RESPONSIVE] = 'stub_mode';
        results.warnings.push('running_browser_stub_mode');
    }

    results.completed_at = new Date().toISOString();
    results.ok = results.critical_failures.length === 0;

    // Open the tpeCharge gate ONLY on full success. The overlay caller is
    // responsible for blocking UI on `ok === false`, but this hard gate
    // is the actual hardening — any caller bypassing the overlay is
    // still refused at the tpeCharge entrypoint.
    if (results.ok) {
        kioskHardware.markBootHealthcheckPassed();
    } else {
        kioskHardware.resetBootHealthcheckGate();
    }

    // Fire-and-forget telemetry. Errors are swallowed — must NEVER block
    // the boot sequence.
    reportHealthcheckTelemetry(results);

    return results;
}

/**
 * Performs a lightweight ping against /api/frontend/kiosk/health. Returns
 * `true` only when the backend responds 200 + `status: 'ok'`. Any other
 * outcome (timeout, 5xx, unexpected body) returns `false`.
 */
async function pingBackend() {
    try {
        // Prefer window.axios (configured with apiKey + baseURL) — that's
        // how the rest of the kiosk talks to the backend. Falls back to
        // fetch() with a hard timeout if axios is missing (e.g. tests).
        if (typeof window !== 'undefined' && window.axios?.get) {
            const r = await window.axios.get('frontend/kiosk/health', {
                timeout: BACKEND_PING_TIMEOUT_MS,
            });
            return r?.data?.status === 'ok';
        }
        if (typeof fetch === 'function') {
            const ctrl = typeof AbortController === 'function' ? new AbortController() : null;
            const t = ctrl ? setTimeout(() => ctrl.abort(), BACKEND_PING_TIMEOUT_MS) : null;
            try {
                const r = await fetch('/api/frontend/kiosk/health', {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    signal: ctrl?.signal,
                });
                if (t) clearTimeout(t);
                if (!r.ok) return false;
                const body = await r.json().catch(() => null);
                return body?.status === 'ok';
            } finally {
                if (t) clearTimeout(t);
            }
        }
        return false;
    } catch (_) {
        return false;
    }
}

/**
 * Runs the hardware checks (TPE/printer/drawer) when bridge is present.
 * Mutates `results` in place — appends to `critical_failures` and
 * `warnings` according to the existing kioskHardware.healthcheck()
 * contract (state ∈ {ok, degraded, critical}, components map).
 *
 * Contract mapping :
 *   - state === 'critical'  → tpe or printer KO → tpe_unresponsive critical
 *     unless only printer KO (then printer_unresponsive_falling_back_to_pdf
 *     warning, NOT critical — kiosk can fall back to PDF receipt).
 *   - state === 'degraded'  → nfc/camera KO → warning only.
 *   - state === 'ok'        → all green.
 *   - thrown / unavailable  → critical (we cannot determine state).
 */
async function runHardwareChecks(results) {
    let hc;
    try {
        hc = await kioskHardware.healthcheck();
    } catch (_) {
        results.checks[CHECKS.TPE_RESPONSIVE] = false;
        results.checks[CHECKS.PRINTER_RESPONSIVE] = false;
        results.checks[CHECKS.DRAWER_RESPONSIVE] = false;
        results.critical_failures.push('hardware_healthcheck_threw');
        return;
    }

    if (!hc || hc.ok !== true) {
        results.checks[CHECKS.TPE_RESPONSIVE] = false;
        results.checks[CHECKS.PRINTER_RESPONSIVE] = false;
        results.checks[CHECKS.DRAWER_RESPONSIVE] = false;
        results.critical_failures.push('hardware_healthcheck_unresponsive');
        return;
    }

    const components = (hc && hc.components) || {};
    const tpeOk = !isComponentKo(components, ['tpe', 'tpe_status']);
    const printerOk = !isComponentKo(components, ['printer', 'printer_status']);
    // Drawer is not part of the existing healthcheck contract — best-effort
    // read of optional `drawer` key. Default to true (no warning) if absent.
    const drawerOk = !isComponentKo(components, ['drawer', 'drawer_status']);

    results.checks[CHECKS.TPE_RESPONSIVE] = tpeOk;
    results.checks[CHECKS.PRINTER_RESPONSIVE] = printerOk;
    results.checks[CHECKS.DRAWER_RESPONSIVE] = drawerOk;

    // TPE is the ONLY truly-critical hardware for closing the KIO-1 gap.
    // Printer KO is a warning — kiosk gracefully falls back to PDF receipt.
    // Drawer KO is a warning — operator can override at POS layer.
    if (!tpeOk) {
        results.critical_failures.push('tpe_unresponsive');
    }
    if (!printerOk) {
        results.warnings.push('printer_unresponsive_falling_back_to_pdf');
    }
    if (!drawerOk && Object.prototype.hasOwnProperty.call(components, 'drawer')) {
        // Only warn if the bridge actually reports drawer state — absent
        // key means "not equipped" not "broken".
        results.warnings.push('drawer_unresponsive');
    }
}

/**
 * A component is KO if its value is explicitly false, 'error', or 'paper_out'.
 * Anything else (true, 'ready', undefined for a key, …) is treated as OK.
 * Mirrors `isKo` semantics from kioskHardware.healthcheck() so the two
 * services stay in lockstep.
 */
function isComponentKo(components, keys) {
    for (const k of keys) {
        const v = components[k];
        if (v === false || v === 'error' || v === 'paper_out') return true;
    }
    return false;
}

/**
 * Fire-and-forget healthcheck telemetry. Best-effort — the boot sequence
 * MUST NEVER be blocked by a network failure here. Pre-login boots will
 * silently 401 because /api/frontend/kiosk-event requires Sanctum, but
 * that's by design — telemetry kicks in after the first successful login.
 */
function reportHealthcheckTelemetry(results) {
    try {
        const payload = {
            type: 'hardware_event',
            event_name: 'boot_healthcheck',
            details: 'ok=' + (results.ok ? '1' : '0')
                + ' | critical=' + (results.critical_failures.join(',') || 'none')
                + ' | warnings=' + (results.warnings.join(',') || 'none'),
            payload: {
                ok: results.ok,
                checks: results.checks,
                critical_failures: results.critical_failures,
                warnings: results.warnings,
                completed_at: results.completed_at,
            },
        };
        if (typeof window !== 'undefined' && window.axios?.post) {
            window.axios.post('frontend/kiosk-event', payload).catch(() => {});
            return;
        }
        if (typeof fetch === 'function') {
            fetch('/api/frontend/kiosk-event', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive: true,
            }).catch(() => {});
        }
    } catch (_) {
        // no-op
    }
}

export default {
    performBootHealthcheck,
    CHECKS,
};
