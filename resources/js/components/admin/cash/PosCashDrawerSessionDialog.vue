<template>
    <transition name="cash-session-dialog">
        <div
            v-if="open"
            class="cash-session-overlay"
            role="dialog"
            aria-modal="true"
            :aria-label="$t('label.cash_session_dialog_title')"
            data-testid="cash-session-overlay"
            @click.self="onBackdrop"
        >
            <section class="cash-session-dialog" :data-mode="mode">
                <header class="cash-session-dialog__head">
                    <div class="cash-session-dialog__title-wrap">
                        <p class="cash-session-dialog__eyebrow">{{ $t('label.cash_session_header_btn') }}</p>
                        <h2 class="cash-session-dialog__title" data-testid="cash-session-title">
                            {{ headerTitle }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="cash-session-dialog__close"
                        :aria-label="$t('button.close')"
                        data-testid="cash-session-close"
                        @click="emitClose"
                    >
                        ✕
                    </button>
                </header>

                <!-- Error band -->
                <div
                    v-if="errorMessage"
                    class="cash-session-dialog__error"
                    role="alert"
                    data-testid="cash-session-error"
                >
                    {{ errorMessage }}
                </div>

                <!-- ============================================================
                  MODE 1 — NO SESSION : prompt to open
                  ============================================================ -->
                <div
                    v-if="mode === 'open'"
                    class="cash-session-dialog__body"
                    data-testid="cash-session-open-form"
                >
                    <p class="cash-session-dialog__intro">
                        {{ $t('label.cash_session_no_session') }}
                    </p>

                    <label class="cash-session-dialog__field-label" for="cash-session-opening">
                        {{ $t('label.cash_session_opening_amount') }}
                    </label>
                    <div class="cash-session-dialog__amount-display" data-testid="cash-session-opening-display">
                        {{ formatMoney(openingAmount) }}
                    </div>

                    <div class="cash-session-dialog__increments" role="group" aria-label="Incréments rapides">
                        <button
                            v-for="inc in increments"
                            :key="`open-inc-${inc}`"
                            type="button"
                            class="cash-session-dialog__chip"
                            :data-testid="`cash-session-open-inc-${inc}`"
                            @click="addToOpening(inc)"
                        >
                            +{{ inc }} €
                        </button>
                        <button
                            type="button"
                            class="cash-session-dialog__chip cash-session-dialog__chip--reset"
                            data-testid="cash-session-open-reset"
                            @click="openingAmount = 0"
                        >
                            {{ $t('button.clear') || 'Effacer' }}
                        </button>
                    </div>

                    <input
                        id="cash-session-opening"
                        ref="openingInput"
                        v-model.number="openingAmount"
                        type="number"
                        step="0.01"
                        min="0"
                        class="cash-session-dialog__input"
                        data-testid="cash-session-opening-input"
                        :aria-label="$t('label.cash_session_opening_amount')"
                    />

                    <div class="cash-session-dialog__actions">
                        <button
                            type="button"
                            class="cash-session-dialog__btn cash-session-dialog__btn--ghost"
                            @click="emitClose"
                        >
                            {{ $t('button.cancel') }}
                        </button>
                        <button
                            type="button"
                            class="cash-session-dialog__btn cash-session-dialog__btn--primary"
                            data-testid="cash-session-open-submit"
                            :disabled="loading || !canOpen"
                            @click="submitOpen"
                        >
                            {{ loading ? '...' : $t('label.cash_session_open') }}
                        </button>
                    </div>
                </div>

                <!-- ============================================================
                  MODE 2 — ACTIVE SESSION view (default when session is open)
                  ============================================================ -->
                <div
                    v-else-if="mode === 'active'"
                    class="cash-session-dialog__body"
                    data-testid="cash-session-active-view"
                >
                    <dl class="cash-session-dialog__stats">
                        <div class="cash-session-dialog__stat">
                            <dt>{{ $t('label.cash_session_opening_amount') }}</dt>
                            <dd data-testid="cash-session-stat-opening">{{ formatMoney(sessionOpeningAmount) }}</dd>
                        </div>
                        <div class="cash-session-dialog__stat">
                            <dt>{{ $t('label.cash_session_opened_at') }}</dt>
                            <dd>{{ formattedOpenedAt }}</dd>
                        </div>
                        <div class="cash-session-dialog__stat">
                            <dt>{{ $t('label.cash_session_movements_count') }}</dt>
                            <dd data-testid="cash-session-stat-mvt-count">{{ movementsCount }}</dd>
                        </div>
                        <div class="cash-session-dialog__stat cash-session-dialog__stat--accent">
                            <dt>{{ $t('label.cash_session_expected_amount') }}</dt>
                            <dd data-testid="cash-session-stat-expected">{{ formatMoney(expectedTotal) }}</dd>
                        </div>
                    </dl>

                    <div class="cash-session-dialog__actions cash-session-dialog__actions--column">
                        <button
                            type="button"
                            class="cash-session-dialog__btn cash-session-dialog__btn--secondary"
                            data-testid="cash-session-view-movements"
                            @click="openMovements"
                        >
                            {{ $t('label.cash_session_view_movements') }}
                        </button>
                        <button
                            type="button"
                            class="cash-session-dialog__btn cash-session-dialog__btn--danger"
                            data-testid="cash-session-go-close"
                            @click="mode = 'close'"
                        >
                            {{ $t('label.cash_session_close') }}
                        </button>
                    </div>
                </div>

                <!-- ============================================================
                  MODE 3 — CLOSE form (closing amount + variance reason)
                  ============================================================ -->
                <div
                    v-else-if="mode === 'close'"
                    class="cash-session-dialog__body"
                    data-testid="cash-session-close-form"
                >
                    <label class="cash-session-dialog__field-label" for="cash-session-closing">
                        {{ $t('label.cash_session_closing_amount') }}
                    </label>
                    <div class="cash-session-dialog__amount-display" data-testid="cash-session-closing-display">
                        {{ formatMoney(closingAmount) }}
                    </div>

                    <div class="cash-session-dialog__increments" role="group" aria-label="Incréments rapides">
                        <button
                            v-for="inc in increments"
                            :key="`close-inc-${inc}`"
                            type="button"
                            class="cash-session-dialog__chip"
                            :data-testid="`cash-session-close-inc-${inc}`"
                            @click="addToClosing(inc)"
                        >
                            +{{ inc }} €
                        </button>
                        <button
                            type="button"
                            class="cash-session-dialog__chip cash-session-dialog__chip--reset"
                            @click="closingAmount = 0"
                        >
                            {{ $t('button.clear') || 'Effacer' }}
                        </button>
                    </div>

                    <input
                        id="cash-session-closing"
                        v-model.number="closingAmount"
                        type="number"
                        step="0.01"
                        min="0"
                        class="cash-session-dialog__input"
                        data-testid="cash-session-closing-input"
                        :aria-label="$t('label.cash_session_closing_amount')"
                    />

                    <dl class="cash-session-dialog__variance-block">
                        <div class="cash-session-dialog__stat">
                            <dt>{{ $t('label.cash_session_expected_amount') }}</dt>
                            <dd data-testid="cash-session-close-expected">{{ formatMoney(expectedTotal) }}</dd>
                        </div>
                        <div
                            class="cash-session-dialog__stat cash-session-dialog__stat--variance"
                            :class="{
                                'cash-session-dialog__stat--variance-positive': liveVariance > 0,
                                'cash-session-dialog__stat--variance-negative': liveVariance < 0,
                            }"
                        >
                            <dt>{{ $t('label.cash_session_variance') }}</dt>
                            <dd data-testid="cash-session-close-variance">{{ formatVariance(liveVariance) }}</dd>
                        </div>
                    </dl>

                    <div v-if="varianceRequiresReason" class="cash-session-dialog__variance-reason">
                        <label class="cash-session-dialog__field-label" for="cash-session-reason">
                            {{ $t('label.cash_session_variance_reason') }} <span aria-hidden="true">*</span>
                        </label>
                        <textarea
                            id="cash-session-reason"
                            v-model="varianceReason"
                            rows="2"
                            class="cash-session-dialog__textarea"
                            data-testid="cash-session-reason-input"
                            :aria-label="$t('label.cash_session_variance_reason')"
                        />
                        <p
                            v-if="varianceReasonMissing"
                            class="cash-session-dialog__hint cash-session-dialog__hint--error"
                            data-testid="cash-session-reason-required"
                        >
                            {{ $t('label.cash_session_required_reason') }}
                        </p>
                    </div>

                    <div class="cash-session-dialog__actions">
                        <button
                            type="button"
                            class="cash-session-dialog__btn cash-session-dialog__btn--ghost"
                            @click="mode = 'active'"
                        >
                            {{ $t('label.cash_session_back') }}
                        </button>
                        <button
                            type="button"
                            class="cash-session-dialog__btn cash-session-dialog__btn--danger"
                            data-testid="cash-session-close-submit"
                            :disabled="loading || varianceReasonMissing"
                            @click="submitClose"
                        >
                            {{ loading ? '...' : $t('label.cash_session_confirm_close') }}
                        </button>
                    </div>
                </div>

                <!-- ============================================================
                  MODE 4 — MOVEMENTS list
                  ============================================================ -->
                <div
                    v-else-if="mode === 'movements'"
                    class="cash-session-dialog__body cash-session-dialog__body--list"
                    data-testid="cash-session-movements-view"
                >
                    <table
                        v-if="movements.length > 0"
                        class="cash-session-dialog__table"
                    >
                        <thead>
                            <tr>
                                <th scope="col">{{ $t('label.date') || 'Date' }}</th>
                                <th scope="col">{{ $t('label.type') || 'Type' }}</th>
                                <th scope="col" class="cash-session-dialog__th--right">{{ $t('label.amount') }}</th>
                                <th scope="col" class="cash-session-dialog__th--right">{{ $t('label.cash_session_variance') || 'Sens' }}</th>
                                <th scope="col">{{ $t('label.notes') || 'Notes' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="movement in movements"
                                :key="movement.id"
                                data-testid="cash-session-movement-row"
                            >
                                <td>{{ formatTimestamp(movement.created_at) }}</td>
                                <td>{{ movement.type }}</td>
                                <td class="cash-session-dialog__td--right">{{ formatMoney(movement.amount) }}</td>
                                <td class="cash-session-dialog__td--right">{{ movement.direction === 'in' ? '↑ IN' : '↓ OUT' }}</td>
                                <td>{{ movement.notes || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="cash-session-dialog__empty" data-testid="cash-session-movements-empty">
                        {{ $t('label.cash_session_no_movements') }}
                    </p>

                    <div class="cash-session-dialog__actions">
                        <button
                            type="button"
                            class="cash-session-dialog__btn cash-session-dialog__btn--ghost"
                            @click="mode = 'active'"
                        >
                            {{ $t('label.cash_session_back') }}
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </transition>
</template>

<script>
/**
 * PosCashDrawerSessionDialog.vue — Sprint 1A.
 *
 * UI complète pour la gestion de la session caisse :
 *   - aucun OPEN → modal "Ouvrir la caisse" (numpad-like quick increments + input)
 *   - OPEN existante → vue active (stats + bouton clôturer / mouvements)
 *   - clôture → numpad closing + variance display + reason si écart
 *   - mouvements → tableau cash_movements
 *
 * SSOT : `cashDrawer` Vuex module (resources/js/store/modules/cashDrawer.js).
 *
 * Le composant écoute `props.open` pour s'afficher. Il émet `close` quand le
 * caissier ferme manuellement, et `session-opened` / `session-closed` après
 * action réussie (utile au parent pour rafraîchir le state ou tracker).
 *
 * Variance reason: saisie ici puis transmise par `CashDrawerService.closeSession()`
 * au POST /reconcile (pas /close — c'est `reconcileSession()` qui l'exige, cf.
 * garde I6). [CAISSE 2026-08-14] Corrigé : ce corps de requête était vide
 * jusqu'ici, ce qui bloquait 422 toute clôture avec un écart réel (voir
 * CashDrawerService.js pour le détail du défaut).
 */
import CashDrawerService from '../../../services/CashDrawerService';

export default {
    name: 'PosCashDrawerSessionDialog',
    props: {
        open: { type: Boolean, default: false },
        // Override mode externally (e.g. PosComponent auto-open). Defaults
        // to 'auto' which picks 'open' or 'active' based on store session.
        initialMode: {
            type: String,
            default: 'auto',
            validator: (v) => ['auto', 'open', 'active', 'close', 'movements'].includes(v),
        },
        // [Wave O / O-1 2026-05-20] Branch context for the open/current call.
        // Required when the caller is a global Admin (auth branch_id=0) so the
        // backend knows which filiale's drawer to operate. Branch-bound staff
        // can omit this — backend will use auth.branch_id.
        branchId: {
            type: [Number, String, null],
            default: null,
        },
    },
    emits: ['close', 'session-opened', 'session-closed'],
    data() {
        return {
            mode: 'auto',
            openingAmount: 50,
            closingAmount: 0,
            varianceReason: '',
            varianceReasonTouched: false,
            increments: [5, 10, 20, 50],
            errorMessage: '',
            // [B-001 abuse-e2e] guards the one-shot movement hydration in resolveMode()
            // against the mounted + 3-watcher storm (load at most once per session id).
            movementsLoadedForSession: null,
        };
    },
    computed: {
        session() {
            return this.$store.getters['cashDrawer/session'];
        },
        loading() {
            return this.$store.getters['cashDrawer/loading'];
        },
        movements() {
            return this.$store.getters['cashDrawer/movements'] || [];
        },
        movementsCount() {
            return this.movements.length;
        },
        sessionOpeningAmount() {
            return this.session ? Number(this.session.opening_amount) || 0 : 0;
        },
        expectedTotal() {
            // Live recompute mirror of backend reconcile math.
            // For UI freshness we recompute from current movements snapshot.
            return CashDrawerService.computeExpected(this.sessionOpeningAmount, this.movements);
        },
        liveVariance() {
            const diff = Number(this.closingAmount) - this.expectedTotal;
            return Math.round(diff * 100) / 100;
        },
        // [T-4.3 SEUIL-INCOHERENT 2026-08-15] Même seuil que le serveur
        // (`CashDrawerService::closeSession()` — `abs($variance) > $threshold`,
        // config/cash.php:31, 2,00€ par défaut) : exiger un motif dès 0,005€
        // bloquait le caissier pour de simples centimes d'arrondi que le
        // serveur aurait acceptés sans discussion.
        varianceThresholdEur() {
            const configured = window.foodkingConfig && window.foodkingConfig.cash && window.foodkingConfig.cash.varianceThresholdEur;
            const n = Number(configured);
            return Number.isFinite(n) && n >= 0 ? n : 2.00;
        },
        varianceRequiresReason() {
            return this.mode === 'close' && Math.abs(this.liveVariance) > this.varianceThresholdEur;
        },
        varianceReasonMissing() {
            if (!this.varianceRequiresReason) return false;
            const trimmed = (this.varianceReason || '').trim();
            return trimmed.length < 3;
        },
        canOpen() {
            return Number(this.openingAmount) >= 0 && Number.isFinite(Number(this.openingAmount));
        },
        formattedOpenedAt() {
            if (!this.session || !this.session.opened_at) return '—';
            try {
                const d = new Date(this.session.opened_at);
                if (Number.isNaN(d.getTime())) return this.session.opened_at;
                return d.toLocaleString('fr-FR', {
                    day: '2-digit',
                    month: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                });
            } catch (_e) {
                return this.session.opened_at;
            }
        },
        headerTitle() {
            switch (this.mode) {
                case 'open':
                    return this.$t('label.cash_session_open');
                case 'close':
                    return this.$t('label.cash_session_close');
                case 'movements':
                    return this.$t('label.cash_session_movements');
                case 'active':
                default:
                    return this.$t('label.cash_session_active');
            }
        },
    },
    watch: {
        open(newVal) {
            if (newVal) {
                this.errorMessage = '';
                this.resolveMode();
            }
        },
        session(newSession) {
            // External session change (e.g. PosComponent loaded session) — re-resolve mode.
            if (this.open) this.resolveMode();
        },
        initialMode(newVal) {
            if (this.open) this.resolveMode();
        },
    },
    mounted() {
        if (this.open) this.resolveMode();
    },
    methods: {
        resolveMode() {
            if (this.initialMode && this.initialMode !== 'auto') {
                this.mode = this.initialMode;
            } else {
                this.mode = this.session && this.session.status === 'open' ? 'active' : 'open';
            }
            // [B-001 abuse-e2e] Hydrate movements for any OPEN session so expectedTotal
            // (shown in BOTH the active summary and the close/reconcile views) reflects
            // real cash IN, not just the opening float. loadCurrentSession does NOT fetch
            // movements — without this the cashier reconciles the drawer against
            // opening-only (e.g. 50,00 € instead of 402,00 €). Backend reconcileSession
            // stays authoritative; this only corrects the operator-facing number.
            // Guarded to load at most once per session id (resolveMode fires in mounted
            // + open/session/initialMode watchers).
            if (this.session && this.session.status === 'open' && this.session.id !== this.movementsLoadedForSession) {
                this.movementsLoadedForSession = this.session.id;
                this.$store.dispatch('cashDrawer/loadMovements');
            }
        },
        emitClose() {
            this.errorMessage = '';
            this.$emit('close');
        },
        onBackdrop() {
            this.emitClose();
        },
        addToOpening(amount) {
            const cur = Number(this.openingAmount) || 0;
            this.openingAmount = Math.round((cur + Number(amount)) * 100) / 100;
        },
        addToClosing(amount) {
            const cur = Number(this.closingAmount) || 0;
            this.closingAmount = Math.round((cur + Number(amount)) * 100) / 100;
        },
        async submitOpen() {
            if (!this.canOpen) return;
            this.errorMessage = '';
            try {
                // [Wave O / O-1 2026-05-20] Forward branchId so global Admin
                // can operate the dropdown-selected filiale's drawer. Backend
                // resolves staff branch_id from auth context when this is null.
                const session = await this.$store.dispatch('cashDrawer/openSession', {
                    openingAmount: this.openingAmount,
                    branchId: this.branchId,
                });
                this.$emit('session-opened', session);
                this.mode = 'active';
            } catch (err) {
                this.errorMessage = this._extractError(err);
            }
        },
        async openMovements() {
            this.errorMessage = '';
            try {
                await this.$store.dispatch('cashDrawer/loadMovements');
                this.mode = 'movements';
            } catch (err) {
                this.errorMessage = this._extractError(err);
            }
        },
        async submitClose() {
            if (this.varianceReasonMissing) {
                this.varianceReasonTouched = true;
                return;
            }
            this.errorMessage = '';
            try {
                const result = await this.$store.dispatch('cashDrawer/closeSession', {
                    closingAmount: this.closingAmount,
                    varianceReason: this.varianceReason || null,
                });
                this.$emit('session-closed', result);
                // After close+reconcile, the session is gone; reset local fields
                // and close the dialog so the parent shows the "no session" state.
                this.closingAmount = 0;
                this.varianceReason = '';
                this.emitClose();
            } catch (err) {
                this.errorMessage = this._extractError(err);
            }
        },
        formatMoney(value) {
            const n = Number(value) || 0;
            try {
                return n.toLocaleString('fr-FR', {
                    style: 'currency',
                    currency: 'EUR',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            } catch (_e) {
                return `${n.toFixed(2)} €`;
            }
        },
        formatVariance(value) {
            const n = Number(value) || 0;
            const formatted = this.formatMoney(Math.abs(n));
            if (n > 0) return `+${formatted}`;
            if (n < 0) return `-${formatted}`;
            return formatted;
        },
        formatTimestamp(iso) {
            if (!iso) return '—';
            try {
                const d = new Date(iso);
                if (Number.isNaN(d.getTime())) return iso;
                return d.toLocaleString('fr-FR', {
                    day: '2-digit',
                    month: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                });
            } catch (_e) {
                return iso;
            }
        },
        _extractError(err) {
            // [T-4.3 ERREUR-ANGLAISE 2026-08-15 · GOAL_CONFORT_MAX] `CashDrawerService::
            // reconcileSession()` (backend) lève `CashVarianceRequiresApprovalException`
            // avec un message CODÉ EN DUR EN ANGLAIS ("Cash variance X€ exceeds threshold
            // Y€ — ..."). Lire `err.response.data.message` en premier faisait fuiter cet
            // anglais brut sur un écran FR (ADR-007). Le `code` stable existe justement
            // pour éviter ça — même discipline que CashSessionReportListComponent (T-2.1).
            const data = err && err.response && err.response.data;
            if (data && data.code === 'CASH_VARIANCE_REASON_REQUIRED') {
                return this.$t('message.cash_variance_reason_required', { threshold: this.formatMoney(data.threshold) });
            }
            if (data && data.code === 'CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED') {
                return this.$t('message.cash_variance_manager_required', { threshold: this.formatMoney(data.threshold) });
            }
            const fromResponse = data && data.message;
            if (fromResponse) return fromResponse;
            if (err && err.message) return err.message;
            return this.$t('label.cash_session_failure');
        },
    },
};
</script>

<style scoped>
.cash-session-overlay {
    position: fixed;
    inset: 0;
    z-index: 1100;
    background: rgba(26, 26, 26, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.cash-session-dialog {
    width: min(520px, 100%);
    max-height: 90vh;
    overflow-y: auto;
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #eee6d9;
    box-shadow: 0 24px 48px -16px rgba(26, 26, 26, 0.45);
    display: flex;
    flex-direction: column;
}

.cash-session-dialog__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 20px 24px 12px;
    border-bottom: 1px solid #f1ebe0;
}

.cash-session-dialog__eyebrow {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #8a8278;
    margin: 0;
}

.cash-session-dialog__title {
    margin: 4px 0 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1a1a1a;
    line-height: 1.2;
}

.cash-session-dialog__close {
    background: transparent;
    border: 0;
    font-size: 1.5rem;
    line-height: 1;
    color: #5a5a5a;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 8px;
}

.cash-session-dialog__close:hover {
    background: #f7f3ec;
    color: #1a1a1a;
}

.cash-session-dialog__error {
    background: #fef2f2;
    border: 1px solid rgba(194, 30, 47, 0.35);
    color: #9c1727;
    padding: 10px 16px;
    margin: 12px 24px 0;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
}

.cash-session-dialog__body {
    padding: 20px 24px 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.cash-session-dialog__body--list {
    gap: 12px;
}

.cash-session-dialog__intro {
    margin: 0;
    color: #5a5a5a;
    font-size: 0.95rem;
}

.cash-session-dialog__field-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: #1a1a1a;
}

.cash-session-dialog__amount-display {
    font-size: 2rem;
    font-weight: 800;
    color: #1a1a1a;
    background: #fff4ee;
    border: 1px solid #f4501e;
    border-radius: 12px;
    padding: 12px 16px;
    font-variant-numeric: tabular-nums;
    text-align: right;
}

.cash-session-dialog__increments {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
}

.cash-session-dialog__chip {
    background: #f7f3ec;
    border: 1px solid #eee6d9;
    border-radius: 10px;
    padding: 10px 8px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #1a1a1a;
    cursor: pointer;
    transition: background 120ms ease, border-color 120ms ease;
    min-height: 44px;
}

.cash-session-dialog__chip:hover {
    background: #fff4ee;
    border-color: #f4501e;
    color: #f4501e;
}

.cash-session-dialog__chip--reset {
    background: #ffffff;
    color: #5a5a5a;
}

.cash-session-dialog__input,
.cash-session-dialog__textarea {
    border: 1px solid #d9c9b8;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 1rem;
    color: #1a1a1a;
    width: 100%;
}

.cash-session-dialog__input:focus,
.cash-session-dialog__textarea:focus {
    outline: none;
    border-color: #f4501e;
    box-shadow: 0 0 0 3px rgba(244, 80, 30, 0.18);
}

.cash-session-dialog__stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin: 0;
}

.cash-session-dialog__stat {
    background: #f7f3ec;
    border-radius: 10px;
    padding: 10px 12px;
}

.cash-session-dialog__stat dt {
    margin: 0;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: #8a8278;
    letter-spacing: 0.03em;
}

.cash-session-dialog__stat dd {
    margin: 4px 0 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #1a1a1a;
    font-variant-numeric: tabular-nums;
}

.cash-session-dialog__stat--accent {
    background: #fff4ee;
    border: 1px solid #f4501e;
}

.cash-session-dialog__stat--accent dd {
    color: #f4501e;
}

.cash-session-dialog__variance-block {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin: 8px 0 0;
}

.cash-session-dialog__stat--variance-positive {
    background: #ecfdf5;
    border: 1px solid rgba(27, 138, 58, 0.35);
}

.cash-session-dialog__stat--variance-positive dd {
    color: #126b2a;
}

.cash-session-dialog__stat--variance-negative {
    background: #fef2f2;
    border: 1px solid rgba(194, 30, 47, 0.35);
}

.cash-session-dialog__stat--variance-negative dd {
    color: #9c1727;
}

.cash-session-dialog__variance-reason {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.cash-session-dialog__hint {
    margin: 0;
    font-size: 0.8rem;
    color: #5a5a5a;
}

.cash-session-dialog__hint--error {
    color: #9c1727;
}

.cash-session-dialog__actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.cash-session-dialog__actions--column {
    flex-direction: column;
}

.cash-session-dialog__btn {
    min-height: 44px;
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid transparent;
    transition: background 120ms ease, border-color 120ms ease, transform 120ms ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.cash-session-dialog__btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.cash-session-dialog__btn--primary {
    background: #f4501e;
    color: #ffffff;
    border-color: #f4501e;
}

.cash-session-dialog__btn--primary:hover:not(:disabled) {
    background: #dc4517;
    border-color: #dc4517;
}

.cash-session-dialog__btn--secondary {
    background: #ffffff;
    color: #1a1a1a;
    border-color: #d9c9b8;
}

.cash-session-dialog__btn--secondary:hover:not(:disabled) {
    background: #f7f3ec;
}

.cash-session-dialog__btn--ghost {
    background: transparent;
    color: #5a5a5a;
    border-color: #eee6d9;
}

.cash-session-dialog__btn--ghost:hover:not(:disabled) {
    color: #1a1a1a;
    border-color: #d9c9b8;
}

.cash-session-dialog__btn--danger {
    background: #c21e2f;
    color: #ffffff;
    border-color: #c21e2f;
}

.cash-session-dialog__btn--danger:hover:not(:disabled) {
    background: #9c1727;
    border-color: #9c1727;
}

.cash-session-dialog__table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.cash-session-dialog__table th,
.cash-session-dialog__table td {
    text-align: left;
    padding: 8px 10px;
    border-bottom: 1px solid #f1ebe0;
}

.cash-session-dialog__th--right,
.cash-session-dialog__td--right {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.cash-session-dialog__empty {
    text-align: center;
    color: #8a8278;
    padding: 20px 0;
    margin: 0;
}

/* Transition */
.cash-session-dialog-enter-active,
.cash-session-dialog-leave-active {
    transition: opacity 180ms ease;
}

.cash-session-dialog-enter-from,
.cash-session-dialog-leave-to {
    opacity: 0;
}

@media (max-width: 480px) {
    .cash-session-dialog__increments {
        grid-template-columns: repeat(3, 1fr);
    }
    .cash-session-dialog__stats,
    .cash-session-dialog__variance-block {
        grid-template-columns: 1fr;
    }
    .cash-session-dialog__actions {
        flex-direction: column-reverse;
    }
}
</style>
