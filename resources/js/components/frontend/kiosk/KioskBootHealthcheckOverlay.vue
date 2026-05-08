<template>
    <div v-if="!isReady" class="kiosk-boot-healthcheck-overlay" role="alertdialog"
        aria-modal="true" aria-labelledby="kbh-title">
        <div class="kiosk-boot-healthcheck__panel">
            <h2 id="kbh-title" class="kiosk-boot-healthcheck__title">
                {{ failed ? 'Borne non opérationnelle' : 'Démarrage de la borne…' }}
            </h2>

            <ul class="kiosk-boot-healthcheck__list" aria-live="polite">
                <li v-for="check in checks" :key="check.key"
                    class="kiosk-boot-healthcheck__item"
                    :class="{
                        'kiosk-boot-healthcheck__item--ok': check.status === true,
                        'kiosk-boot-healthcheck__item--fail': check.status === false,
                        'kiosk-boot-healthcheck__item--stub': check.status === null,
                        'kiosk-boot-healthcheck__item--pending': check.status === undefined,
                    }">
                    <span class="kiosk-boot-healthcheck__label">{{ check.label }}</span>
                    <span class="kiosk-boot-healthcheck__status" aria-hidden="true">
                        {{ statusGlyph(check.status) }}
                    </span>
                    <span class="visually-hidden">{{ statusText(check.status) }}</span>
                </li>
            </ul>

            <div v-if="failed" class="kiosk-boot-healthcheck__error" role="alert">
                <p class="kiosk-boot-healthcheck__error-message">
                    Causes critiques détectées : {{ criticalFailures.join(', ') }}.
                </p>
                <p v-if="warnings.length" class="kiosk-boot-healthcheck__warnings">
                    Avertissements : {{ warnings.join(', ') }}.
                </p>
                <div class="kiosk-boot-healthcheck__actions">
                    <button type="button"
                        class="kiosk-boot-healthcheck__btn kiosk-boot-healthcheck__btn--primary"
                        :disabled="isRunning"
                        @click="retry">
                        {{ isRunning ? 'Vérification…' : 'Réessayer' }}
                    </button>
                    <button type="button"
                        class="kiosk-boot-healthcheck__btn kiosk-boot-healthcheck__btn--secondary"
                        @click="$emit('contact-staff', { reason: 'boot_healthcheck_failed', failures: criticalFailures })">
                        Appeler le staff
                    </button>
                </div>
            </div>

            <p v-else-if="isRunning" class="kiosk-boot-healthcheck__hint">
                Vérification du matériel en cours, merci de patienter…
            </p>
        </div>
    </div>
</template>

<script>
/**
 * KioskBootHealthcheckOverlay — [P0-5 / KIO-1] Boot gate UI.
 * -----------------------------------------------------------------------------
 *
 * Displayed at kiosk boot BEFORE any payment session can be started.
 * Calls performBootHealthcheck() which runs five sub-checks (bridge,
 * backend, TPE, printer, drawer) and opens the tpeCharge() gate via
 * markBootHealthcheckPassed() on success.
 *
 * If the healthcheck fails :
 *   - `failed = true` and the user sees the critical_failures list.
 *   - The "Réessayer" button reruns performBootHealthcheck().
 *   - The "Appeler le staff" button emits `contact-staff` for the parent
 *     view to surface a staff alert (kiosk admin badge / SMS / etc.).
 *
 * Emits :
 *   - `ready`         { results }  — when all critical checks pass.
 *   - `contact-staff` { reason, failures } — operator escalation.
 *
 * Note : this overlay is the UX layer only. The actual hardening lives in
 * `kioskHardware.requireHealthCheck()` which gates `tpeCharge()` directly.
 * Even if a misbehaving caller bypasses this overlay, `tpeCharge()` will
 * still refuse to run until the gate is opened.
 *
 * Frozen-zones note : KioskAppComponent.vue is agent F-016 territory and
 * MUST NOT be edited by this work. Integration is left to the kiosk
 * entry layer (router or direct mount) — see the boot integration note
 * at the bottom of kioskBootHealthcheck.js.
 */
import { performBootHealthcheck, CHECKS } from '../../../services/kioskBootHealthcheck';
import kioskHardware from '../../../services/kioskHardware';

export default {
    name: 'KioskBootHealthcheckOverlay',
    emits: ['ready', 'contact-staff'],
    data() {
        return {
            isReady: false,
            isRunning: false,
            failed: false,
            results: null,
            checks: [],
            criticalFailures: [],
            warnings: [],
        };
    },
    async mounted() {
        await this.runHealthcheck();
    },
    methods: {
        async runHealthcheck() {
            if (this.isRunning) return;
            this.isRunning = true;
            this.failed = false;
            this.checks = this.buildPendingChecks();
            try {
                // Reset cached gate state before retrying — the router
                // singleton cache must drop its stale promise on retry,
                // otherwise the second click of "Réessayer" would never
                // actually re-run the healthcheck.
                kioskHardware.resetBootHealthcheckGate();
                const results = await performBootHealthcheck();
                this.results = results;
                this.checks = this.buildChecksFromResults(results);
                this.criticalFailures = results.critical_failures || [];
                this.warnings = results.warnings || [];

                if (results.ok) {
                    this.isReady = true;
                    this.$emit('ready', results);
                    // When mounted via the /kiosk/boot-failure route, the
                    // parent is the router itself — auto-navigate back
                    // to the kiosk root so the user re-enters the normal
                    // flow without a second click.
                    if (this.$route && this.$route.name === 'kiosk.boot-failure') {
                        this.$router.replace({ path: '/kiosk' });
                    }
                } else {
                    this.failed = true;
                }
            } catch (e) {
                // performBootHealthcheck never throws by contract, but
                // this guard ensures the overlay never traps the user
                // in an "infinite spinner" state.
                this.failed = true;
                this.criticalFailures = ['boot_healthcheck_runtime_error'];
                this.checks = this.buildPendingChecks().map((c) => ({ ...c, status: false }));
            } finally {
                this.isRunning = false;
            }
        },
        retry() {
            return this.runHealthcheck();
        },
        buildPendingChecks() {
            return [
                { key: CHECKS.BRIDGE_PRESENT, label: 'Bridge Electron', status: undefined },
                { key: CHECKS.BACKEND_REACHABLE, label: 'Serveur backend', status: undefined },
                { key: CHECKS.TPE_RESPONSIVE, label: 'TPE (paiement)', status: undefined },
                { key: CHECKS.PRINTER_RESPONSIVE, label: 'Imprimante', status: undefined },
                { key: CHECKS.DRAWER_RESPONSIVE, label: 'Tiroir caisse', status: undefined },
            ];
        },
        buildChecksFromResults(results) {
            const c = (results && results.checks) || {};
            const norm = (v) => (v === 'stub_mode' ? null : v);
            return [
                { key: CHECKS.BRIDGE_PRESENT, label: 'Bridge Electron', status: norm(c[CHECKS.BRIDGE_PRESENT]) },
                { key: CHECKS.BACKEND_REACHABLE, label: 'Serveur backend', status: norm(c[CHECKS.BACKEND_REACHABLE]) },
                { key: CHECKS.TPE_RESPONSIVE, label: 'TPE (paiement)', status: norm(c[CHECKS.TPE_RESPONSIVE]) },
                { key: CHECKS.PRINTER_RESPONSIVE, label: 'Imprimante', status: norm(c[CHECKS.PRINTER_RESPONSIVE]) },
                { key: CHECKS.DRAWER_RESPONSIVE, label: 'Tiroir caisse', status: norm(c[CHECKS.DRAWER_RESPONSIVE]) },
            ];
        },
        statusGlyph(status) {
            if (status === true) return 'OK';
            if (status === false) return 'KO';
            if (status === null) return '—';
            return '…';
        },
        statusText(status) {
            if (status === true) return 'opérationnel';
            if (status === false) return 'en échec';
            if (status === null) return 'mode dégradé / stub';
            return 'en cours';
        },
    },
};
</script>

<style scoped>
.kiosk-boot-healthcheck-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.95);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    color: #f8fafc;
    font-family: var(--kiosk-font-family, system-ui, -apple-system, sans-serif);
}
.kiosk-boot-healthcheck__panel {
    background: #1e293b;
    border-radius: 16px;
    padding: 2.5rem 3rem;
    max-width: 640px;
    width: 90%;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.4);
}
.kiosk-boot-healthcheck__title {
    margin: 0 0 1.5rem 0;
    font-size: 1.75rem;
    font-weight: 700;
    text-align: center;
}
.kiosk-boot-healthcheck__list {
    list-style: none;
    padding: 0;
    margin: 0 0 1.5rem 0;
}
.kiosk-boot-healthcheck__item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    margin-bottom: 0.5rem;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    font-size: 1.125rem;
}
.kiosk-boot-healthcheck__item--ok { border-left: 4px solid #22c55e; }
.kiosk-boot-healthcheck__item--fail { border-left: 4px solid #ef4444; }
.kiosk-boot-healthcheck__item--stub { border-left: 4px solid #94a3b8; opacity: 0.7; }
.kiosk-boot-healthcheck__item--pending { border-left: 4px solid #eab308; }
.kiosk-boot-healthcheck__status {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    min-width: 2.5rem;
    text-align: right;
}
.kiosk-boot-healthcheck__error {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.4);
    border-radius: 8px;
    padding: 1rem 1.25rem;
}
.kiosk-boot-healthcheck__error-message {
    margin: 0 0 0.5rem 0;
    font-weight: 600;
}
.kiosk-boot-healthcheck__warnings {
    margin: 0 0 1rem 0;
    color: #fde68a;
    font-size: 0.95rem;
}
.kiosk-boot-healthcheck__actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 0.75rem;
}
.kiosk-boot-healthcheck__btn {
    flex: 1;
    padding: 0.875rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    min-height: var(--kiosk-touch-min, 48px);
    transition: opacity 0.15s ease;
}
.kiosk-boot-healthcheck__btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.kiosk-boot-healthcheck__btn--primary {
    background: #3b82f6;
    color: #fff;
}
.kiosk-boot-healthcheck__btn--secondary {
    background: rgba(255, 255, 255, 0.15);
    color: #f8fafc;
}
.kiosk-boot-healthcheck__hint {
    text-align: center;
    color: #cbd5e1;
    font-size: 0.95rem;
    margin: 0;
}
.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
