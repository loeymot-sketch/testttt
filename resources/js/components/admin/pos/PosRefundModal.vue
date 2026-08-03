<template>
  <!--
    PosRefundModal — NEW component for NF525 counter-entry refund UI.
    [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26]

    Backend is fully ready since cycle K (commit 2026-05-19):
      - POST /api/admin/pos-order/{order}/refund-with-counter-entry
      - RefundWithCounterEntryService (full-negate mirror)
      - REMBOURSEMENT receipt marker (ReceiptRemboursementMarker.vue)
      - 7 sentinel tests GREEN (RefundCounterEntryUniqueParentTest, etc.)

    UI was the missing piece — without this modal the cashier could not
    issue refunds, only bypass via the dangerous status dropdown
    (Returned status) which violates NF525 (no mirror order created).

    Mission per PROPOSAL_POS_REFUND_UI_2026-05-25.md §3 Option B:
      - Reusable standalone modal (props `order`, emits @close / @refunded)
      - Permission `pos-refund` (granted Admin + Branch Manager ONLY)
      - Idempotency frozen-key during modal lifecycle (race protection)
      - Backend triple-defense already in place (UUID key + UNIQUE +
        409 MIRROR_ALREADY_EXISTS) — frontend complements with disabled
        confirm button during in-flight POST.

    NF525 invariants preserved — see PROPOSAL §6:
      - PaymentComponent.vue NOT touched (frozen-zone)
      - FiscalSequenceService NOT touched (mirror uses standard allocate)
      - AuditLogService NOT touched (chain append from listener)
      - z_reports migrations NOT touched
      - Parent order strictly immutable (backend invariant)

    A11y:
      - role="dialog" + aria-modal="true" + REAL focus trap via the shared
        `focusTrap` mixin (factored from ds/KsModal.vue): focus enters the
        panel on open, Tab/Shift+Tab cycle first<->last, and focus is restored
        to the trigger element on close.
      - Escape closes modal (unless POST in flight)
      - reason field with aria-describedby for char counter
      - Error/success regions with role="alert" / role="status"
  -->
  <transition name="fade">
    <div
      v-if="visible"
      class="refund-modal-overlay"
      data-testid="pos-refund-modal-overlay"
      @click.self="onCancel"
      @keydown.esc.stop="onCancel"
    >
      <div
        ref="panel"
        class="refund-modal"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="'pos-refund-modal-title'"
      >
        <!-- Header -->
        <header class="refund-modal-header">
          <div class="refund-modal-title-row">
            <h3
              id="pos-refund-modal-title"
              class="refund-modal-title"
              data-testid="pos-refund-modal-title"
            >
              <span aria-hidden="true">💸</span>
              {{ $t('pos.refund.modal_title') }}
            </h3>
            <button
              type="button"
              class="refund-modal-close"
              :aria-label="$t('pos.refund.close')"
              :disabled="submitting"
              data-testid="pos-refund-modal-close"
              @click="onCancel"
            >✕</button>
          </div>
          <p class="refund-modal-order-meta">
            <span class="refund-modal-order-no">
              N° {{ order?.order_serial_no || order?.queue_number || order?.id }}
            </span>
            <span class="refund-modal-source">{{ $t('pos.refund.source_badge') }}</span>
          </p>
        </header>

        <!-- Order recap -->
        <section class="refund-recap">
          <dl class="refund-recap-grid">
            <div class="refund-recap-row">
              <dt>{{ $t('pos.refund.field_total') }}</dt>
              <dd class="refund-recap-total" data-testid="pos-refund-modal-total">
                {{ formatPrice(orderTotal) }}
              </dd>
            </div>
            <div class="refund-recap-row" v-if="paymentMethodLabel">
              <dt>{{ $t('pos.refund.field_payment_method') }}</dt>
              <dd data-testid="pos-refund-modal-payment-method">{{ paymentMethodLabel }}</dd>
            </div>
            <div class="refund-recap-row" v-if="order?.order_datetime">
              <dt>{{ $t('pos.refund.field_date') }}</dt>
              <dd>{{ order.order_datetime }}</dd>
            </div>
          </dl>
        </section>

        <!-- NF525 warning banner — non-dismissible mandatory acknowledgement -->
        <div
          class="refund-warning"
          role="status"
          aria-live="polite"
          data-testid="pos-refund-modal-warning"
        >
          <span class="refund-warning-icon" aria-hidden="true">⚠️</span>
          <span class="refund-warning-text">{{ $t('pos.refund.warning') }}</span>
        </div>

        <!-- Reason textarea (required min 5 chars per sentinel) -->
        <div class="refund-field-block">
          <label for="pos-refund-reason" class="refund-field-label">
            {{ $t('pos.refund.reason_label') }}
            <span class="refund-field-required" aria-hidden="true">*</span>
          </label>
          <textarea
            id="pos-refund-reason"
            ref="reasonInput"
            v-model="reason"
            class="refund-field-textarea"
            rows="3"
            maxlength="700"
            :placeholder="$t('pos.refund.reason_placeholder')"
            :aria-describedby="'pos-refund-reason-help pos-refund-reason-counter'"
            :aria-invalid="reasonInvalid ? 'true' : 'false'"
            :disabled="submitting"
            data-testid="pos-refund-modal-reason"
          ></textarea>
          <div class="refund-field-meta">
            <p
              id="pos-refund-reason-help"
              class="refund-field-help"
              :class="{ 'is-error': reasonInvalid }"
              role="status"
              aria-live="polite"
            >
              {{ reasonInvalid ? $t('pos.refund.reason_min_error') : $t('pos.refund.reason_help') }}
            </p>
            <p
              id="pos-refund-reason-counter"
              class="refund-field-counter"
              aria-live="off"
            >
              {{ reason.length }}/700
            </p>
          </div>
        </div>

        <!-- Error band (surfaces 4xx/5xx from backend) -->
        <div
          v-if="errorMessage"
          class="refund-error-band"
          role="alert"
          aria-live="assertive"
          data-testid="pos-refund-modal-error"
        >
          <span aria-hidden="true">❗</span>
          {{ errorMessage }}
        </div>

        <!-- Footer: cancel + confirm danger -->
        <footer class="refund-modal-footer">
          <button
            type="button"
            class="refund-cancel-btn"
            :disabled="submitting"
            data-testid="pos-refund-modal-cancel"
            @click="onCancel"
          >
            {{ $t('pos.refund.cancel_btn') }}
          </button>
          <button
            type="button"
            class="refund-confirm-btn"
            :disabled="!canConfirm || submitting"
            :aria-disabled="!canConfirm || submitting"
            :aria-busy="submitting"
            data-testid="pos-refund-modal-confirm"
            @click="onConfirm"
          >
            <span v-if="submitting" class="refund-spinner" aria-hidden="true"></span>
            <span v-else aria-hidden="true">✓</span>
            {{ submitting ? $t('pos.refund.processing') : $t('pos.refund.confirm_btn') }}
          </button>
        </footer>
      </div>
    </div>
  </transition>
</template>

<script>
import axios from 'axios';
import alertService from '../../../services/alertService';
import focusTrap from '../../../mixins/focusTrap';

/**
 * PosRefundModal — HEAL-4 / PROPOSAL-02 NF525 counter-entry refund modal.
 *
 * Props:
 *   - order: Object (required, the order to refund — must contain id +
 *            total/order_amount + order_serial_no, ideally also
 *            order_datetime + pos_payment_method)
 *
 * Emits:
 *   - close: no payload, parent clears the trigger ref
 *   - refunded: { mirrorOrder, parentOrderId, mirrorFiscalSequenceNo }
 *     parent should refresh the order to show the new refunded state +
 *     mirror card.
 *
 * Backend route (ready since cycle K):
 *   POST /api/admin/pos-order/{order}/refund-with-counter-entry
 *   Body: { reason: string min:5 max:700 }
 *   Headers: X-Idempotency-Key (mandatory per IdempotencyKeyMiddleware)
 *   2xx -> 201 with mirror in `data`
 *   409 -> MIRROR_ALREADY_EXISTS (UNIQUE parent_order_id)
 *   422 -> validation/invalid argument (parent not refundable)
 *   500 -> backend error
 *
 * Idempotency key formula (frozen at modal mount):
 *   pos-refund-{orderId}-{mountTimestamp}
 * Same modal session = same key = backend 2xx replay cache hits on
 * double-tap. Different modal openings = different timestamps = backend
 * UNIQUE constraint catches the second one with 409 (defense-in-depth).
 */
export default {
  name: 'PosRefundModal',
  mixins: [focusTrap],
  props: {
    order: {
      type: Object,
      default: null,
    },
  },
  emits: ['close', 'refunded'],
  data() {
    return {
      reason: '',
      submitting: false,
      errorMessage: '',
      // Frozen idempotency key — minted on modal open, reused across
      // double-clicks within the same session. Cleared on close().
      idempotencyKey: null,
    };
  },
  computed: {
    visible() {
      return this.order !== null && this.order !== undefined && !!this.order.id;
    },
    orderTotal() {
      if (!this.order) return 0;
      return Number(this.order.total ?? this.order.order_amount ?? this.order.total_amount_price ?? 0);
    },
    reasonTrimmedLength() {
      return String(this.reason || '').trim().length;
    },
    reasonInvalid() {
      // Min 5 chars per sentinel `posRefundModalSentinel.spec.js`.
      // Backend enforces min:3 (current contract) BUT the proposal
      // recommends a STRICTER UI gate at 5 to force a meaningful reason.
      // Empty AND under-min both forbid confirm; show error only when
      // user has started typing.
      return this.reasonTrimmedLength > 0 && this.reasonTrimmedLength < 5;
    },
    canConfirm() {
      return this.reasonTrimmedLength >= 5 && !!this.order?.id;
    },
    paymentMethodLabel() {
      // Order may carry either pos_payment_method (int enum) or a
      // pre-translated label. Try both shapes; fall back silently.
      const pm = this.order?.pos_payment_method;
      if (pm === null || pm === undefined) return '';
      const map = {
        1: this.$t('label.cash'),
        2: this.$t('label.card'),
        3: this.$t('label.mobile_banking'),
        4: this.$t('label.other'),
      };
      return map[pm] || (typeof pm === 'string' ? pm : '');
    },
  },
  watch: {
    order: {
      immediate: true,
      handler(newOrder) {
        if (newOrder && newOrder.id) {
          // Mint a fresh idempotency key each time a new order is set.
          // Frozen across double-clicks in the same modal session.
          this.idempotencyKey = `pos-refund-${newOrder.id}-${Date.now()}`;
          this.reason = '';
          this.errorMessage = '';
          this.submitting = false;
          // [A11y round4] Install the WCAG focus trap on the dialog panel and
          // land initial focus on the reason field (preserves prior UX). The
          // mixin confines Tab and restores focus to the trigger on close.
          this.$nextTick(() => {
            this.activateFocusTrap(this.$refs.panel, {
              initialFocus: this.$refs.reasonInput,
            });
          });
        } else {
          // Order cleared (parent closed the modal) → release trap + restore focus.
          this.deactivateFocusTrap();
        }
      },
    },
  },
  methods: {
    formatPrice(amount) {
      try {
        return new Intl.NumberFormat('fr-FR', {
          style: 'currency',
          currency: 'EUR',
        }).format(amount || 0);
      } catch (_) {
        return `${(amount || 0).toFixed(2)} €`;
      }
    },
    onCancel() {
      if (this.submitting) return;
      this.errorMessage = '';
      this.$emit('close');
    },
    async onConfirm() {
      if (!this.canConfirm || this.submitting || !this.order?.id) return;
      this.errorMessage = '';
      this.submitting = true;

      const orderId = this.order.id;
      const reasonTrimmed = String(this.reason || '').trim();
      const key = this.idempotencyKey || `pos-refund-${orderId}-${Date.now()}`;

      try {
        const response = await axios.post(
          `admin/pos-order/${orderId}/refund-with-counter-entry`,
          { reason: reasonTrimmed },
          { headers: { 'X-Idempotency-Key': key } }
        );

        const data = response?.data?.data || null;
        const meta = response?.data?.meta || {};
        alertService.success(this.$t('pos.refund.success'));

        this.$emit('refunded', {
          mirrorOrder: data,
          parentOrderId: meta.parent_order_id ?? orderId,
          mirrorFiscalSequenceNo: meta.mirror_fiscal_sequence_no ?? null,
        });
        this.submitting = false;
        this.$emit('close');
      } catch (err) {
        const status = err?.response?.status;
        const code = err?.response?.data?.code;
        const backendMessage = err?.response?.data?.message;

        if (status === 409 && code === 'MIRROR_ALREADY_EXISTS') {
          // Defense-in-depth: backend UNIQUE caught the duplicate.
          // Treat as info (the refund effectively exists already) and
          // ask the parent to refresh by emitting refunded with empty
          // mirror so the order detail re-fetches.
          alertService.info(this.$t('pos.refund.already_refunded'));
          this.$emit('refunded', {
            mirrorOrder: null,
            parentOrderId: orderId,
            mirrorFiscalSequenceNo: null,
            alreadyRefunded: true,
          });
          this.submitting = false;
          this.$emit('close');
          return;
        }

        if (status === 403) {
          this.errorMessage = this.$t('pos.refund.error_permission');
        } else if (status === 422) {
          this.errorMessage = backendMessage || this.$t('pos.refund.error_invalid');
        } else if (status >= 500) {
          this.errorMessage = this.$t('pos.refund.error_server');
        } else {
          this.errorMessage = backendMessage || this.$t('pos.refund.error_generic');
        }
        alertService.error(this.errorMessage);
        this.submitting = false;
      }
    },
  },
};
</script>

<style scoped>
/* =============================================================================
   PosRefundModal — visual language consistent with PosCounterCollectModal.
   Danger-tinted to emphasize the NF525 irrevocability of refunds.
   ============================================================================= */
.refund-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.60);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10002;
  padding: 16px;
}
.refund-modal {
  position: relative;
  background: var(--pos-v5-surface, #fff);
  border-radius: 12px;
  width: 100%;
  max-width: 540px;
  max-height: 92vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.40);
  padding: 20px 24px 24px 24px;
  font-family: var(--pos-v5-font-family, 'Rubik', system-ui, sans-serif);
  border-top: 4px solid var(--pos-v5-brand-red, #cf3a3a);
}

/* Header */
.refund-modal-header { margin-bottom: 14px; }
.refund-modal-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.refund-modal-title {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: var(--pos-v5-text, #1a1a1a);
  display: flex;
  align-items: center;
  gap: 8px;
}
.refund-modal-close {
  background: transparent;
  border: 0;
  font-size: 18px;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  cursor: pointer;
  color: var(--pos-v5-muted, #555);
}
.refund-modal-close:hover:not(:disabled) {
  background: var(--pos-v5-surface-2, #f3f3f3);
  color: var(--pos-v5-text, #1a1a1a);
}
.refund-modal-order-meta {
  margin: 6px 0 0 0;
  display: flex;
  align-items: baseline;
  gap: 10px;
  font-size: 13px;
}
.refund-modal-order-no {
  font-weight: 700;
  color: var(--pos-v5-muted, #555);
}
.refund-modal-source {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--pos-v5-brand-red-soft, #ffeaea);
  color: var(--pos-v5-brand-red, #cf3a3a);
  font-weight: 600;
}

/* Order recap */
.refund-recap {
  background: var(--pos-v5-surface-2, #faf6f1);
  border: 1px solid var(--pos-v5-border, #eadfd2);
  border-radius: 10px;
  padding: 12px 14px;
  margin-bottom: 14px;
}
.refund-recap-grid { margin: 0; display: flex; flex-direction: column; gap: 6px; }
.refund-recap-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-size: 13px;
  margin: 0;
}
.refund-recap-row dt {
  color: var(--pos-v5-muted, #777);
  font-weight: 500;
}
.refund-recap-row dd {
  margin: 0;
  font-weight: 700;
  color: var(--pos-v5-text, #1a1a1a);
}
.refund-recap-total {
  font-size: 18px !important;
  color: var(--pos-v5-brand-red, #cf3a3a) !important;
  font-variant-numeric: tabular-nums;
  font-family: 'Rubik Mono One', 'JetBrains Mono', ui-monospace, monospace;
}

/* Warning band */
.refund-warning {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  background: #fff8e6;
  border: 1px solid #f3c969;
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 14px;
}
.refund-warning-icon {
  font-size: 18px;
  line-height: 1.2;
}
.refund-warning-text {
  font-size: 13px;
  color: #6b4f00;
  line-height: 1.45;
}

/* Reason field */
.refund-field-block { margin-bottom: 14px; }
.refund-field-label {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: var(--pos-v5-text, #1a1a1a);
  margin-bottom: 6px;
}
.refund-field-required {
  color: var(--pos-v5-brand-red, #cf3a3a);
  margin-left: 2px;
}
.refund-field-textarea {
  width: 100%;
  border: 1px solid var(--pos-v5-border, #d9cebd);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  font-family: inherit;
  line-height: 1.5;
  color: var(--pos-v5-text, #1a1a1a);
  background: #fff;
  resize: vertical;
  min-height: 80px;
}
.refund-field-textarea:focus {
  outline: 2px solid var(--pos-v5-brand-red, #cf3a3a);
  outline-offset: 0;
  border-color: var(--pos-v5-brand-red, #cf3a3a);
}
.refund-field-textarea[aria-invalid="true"] {
  border-color: var(--pos-v5-danger, #cf3a3a);
}
.refund-field-meta {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-top: 4px;
  gap: 12px;
}
.refund-field-help {
  margin: 0;
  font-size: 11px;
  color: var(--pos-v5-muted, #777);
}
.refund-field-help.is-error {
  color: var(--pos-v5-danger, #cf3a3a);
  font-weight: 600;
}
.refund-field-counter {
  margin: 0;
  font-size: 11px;
  color: var(--pos-v5-muted, #999);
  font-variant-numeric: tabular-nums;
}

/* Error band */
.refund-error-band {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  background: #fdecec;
  border: 1px solid #f5a8a8;
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 14px;
  font-size: 13px;
  color: #8a1f1f;
}

/* Footer */
.refund-modal-footer {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 6px;
}
.refund-cancel-btn,
.refund-confirm-btn {
  padding: 10px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  border: 1px solid transparent;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background 0.12s ease;
}
.refund-cancel-btn {
  background: #f3f3f3;
  color: #333;
  border-color: #ddd;
}
.refund-cancel-btn:hover:not(:disabled) {
  background: #e8e8e8;
}
.refund-cancel-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.refund-confirm-btn {
  background: var(--pos-v5-brand-red, #cf3a3a);
  color: #fff;
  border-color: var(--pos-v5-brand-red, #cf3a3a);
}
.refund-confirm-btn:hover:not(:disabled) {
  background: #b32f2f;
  border-color: #b32f2f;
}
.refund-confirm-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.refund-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid #fff;
  border-top-color: transparent;
  border-radius: 50%;
  animation: refund-spin 0.7s linear infinite;
}
@keyframes refund-spin {
  to { transform: rotate(360deg); }
}

/* Modal enter/leave */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.18s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
