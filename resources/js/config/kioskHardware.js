/**
 * kioskHardware.js (config) — Phase 8.10.
 * -----------------------------------------------------------------------------
 * Constantes de configuration du bridge hardware Electron (`window.borne.*`).
 * Séparé du service `services/kioskHardware.js` pour permettre aux composants
 * Vue / tests de consommer uniquement les timings & identifiants sans tirer
 * le wrapper complet.
 *
 * Source d'autorité : docs/design/KIOSK_HARDWARE_CALLS.md §6 + AUDIT_FINAL.md.
 */

export const KIOSK_HARDWARE = Object.freeze({
    HEALTHCHECK_INTERVAL_MS: 90_000,
    HEALTHCHECK_JITTER_MS: 5_000,
    TPE_TIMEOUT_MS: 120_000,
    TPE_REFUSED_RETRY_MAX: 2,
    PRINTER_RETRY_MS: 2_000,
    PRINTER_RETRY_MAX: 3,
    CASH_DRAWER_RETRY_MS: 3_000,
    SCANNER_DEBOUNCE_MS: 250,
    BRIDGE_READY_TIMEOUT_MS: 15_000,
    STAGES: Object.freeze({
        IDLE: 'idle',
        READY: 'ready',
        BUSY: 'busy',
        DEGRADED: 'degraded',
        OFFLINE: 'offline',
    }),
    SCAN_METHODS: Object.freeze(['qr', 'nfc']),
});

export const MINI_RECAP = Object.freeze({
    PREVIEW_DEBOUNCE_MS: 400,
    MIN_CART_FOR_PREVIEW: 1,
});

export default KIOSK_HARDWARE;
