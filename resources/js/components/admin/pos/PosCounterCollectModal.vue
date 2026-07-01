<template>
  <!--
    PosCounterCollectModal — sibling SSOT-flavored counter-collect modal
    [Wave X X1 P-OWNER 2026-05-21] Owner mandate verbatim:
    "Quand je clique 'Encaisser' sur commande borne cash-pending, je veux
    que ça ouvre la même popup que pour le POS normal payment. Comme ça
    toutes les ventes (POS direct, borne, livreur) passent par UN SEUL
    portail = SSOT pour comptabilité claire."

    Architectural decision: Option C (sibling). PaymentComponent.vue is
    CLAUDE.md §7 FROZEN (paymentComponentEmitsJsdocList.spec.js sentinel
    locks emits) — we cannot mount it with a `mode=counter_collect` prop.
    Instead, we mirror its V5 visual atoms (hero total card, mode picker
    segmented control, PosV5Numpad, rendu calc) inside this sibling so
    the cashier sees a near-identical surface — same look + same behavior
    minus the multi-tranche split.

    SCOPE CHANGE vs orchestrator brief — multi-tranche split deferred to
    V1.0.2 (see commit body). Backend `PaymentService::confirmCounterPayment`
    accepts a SINGLE mode + single received only, AND short-circuits
    (no-op) the moment `payment_status === PAID`. A naive loop over
    tranches would silently lose tranches 2..N. Adding multi-tranche
    requires a NEW `PaymentService::confirmCounterPaymentSplit(Order,
    array $tranches)` route + extending `SplitPaymentService` to accept
    counter-collect entry (currently OrderService::posOrderStore only).
    That is NF525-adjacent surgery + new LOCK — V1.0.2 backlog. V1 ships
    single-tender at counter with full 4-mode parity + numpad for CASH.

    Idempotency contract:
      X-Idempotency-Key = pos-counter-collect-{orderId}-{modeInt}-{minuteBucket}
    Mirrors Wave W formula (ab0caa985) so double-tap within the same
    minute replays the cached 2xx response from IdempotencyKeyMiddleware
    (Wave K Z7), not a duplicate POST.
  -->
  <transition name="fade">
    <div
      v-if="visible"
      class="cc-modal-overlay"
      data-testid="pos-counter-collect-modal"
      @click.self="onCancel"
    >
      <div ref="panel" class="cc-modal" role="dialog" aria-modal="true" :aria-label="$t('label.encaisser_mode_title')">
        <!-- Header: hero total + queue number (mirror PaymentComponent design language) -->
        <div class="cc-modal-header">
          <div class="cc-modal-title-row">
            <h3 class="cc-modal-title">
              <span aria-hidden="true">💳</span>
              {{ $t('label.encaisser_mode_title') }}
            </h3>
            <button
              type="button"
              class="cc-modal-close"
              :aria-label="$t('button.close')"
              :disabled="submitting"
              data-testid="pos-counter-collect-close"
              @click="onCancel"
            >✕</button>
          </div>
          <p class="cc-modal-order-meta">
            <span class="cc-modal-order-no">
              N° {{ order?.queue_number || order?.order_serial_no || order?.id }}
            </span>
            <span class="cc-modal-source">{{ $t('label.cc_source_kiosk') }}</span>
          </p>
        </div>

        <!-- Hero "À encaisser" — mirror PaymentComponent V5 design (48px monospace tabular) -->
        <div class="cc-hero-total">
          <p class="cc-hero-label">{{ $t('label.total_amount') }}</p>
          <p class="cc-hero-value" data-testid="pos-counter-collect-total">
            {{ formatPrice(orderTotal) }}
          </p>
        </div>

        <!-- Mode picker — 4-mode parity with Wave W (cash | card | mobile | ticket) -->
        <div class="cc-mode-section">
          <p class="cc-section-title">{{ $t('label.select_payment_method') }}</p>
          <nav class="cc-mode-grid" role="tablist" :aria-label="$t('label.select_payment_method')">
            <button
              v-for="m in modes"
              :key="m.id"
              type="button"
              role="tab"
              :class="['cc-mode-btn', `cc-mode-btn--${m.id}`, { 'is-active': selectedMode === m.id }]"
              :aria-selected="selectedMode === m.id ? 'true' : 'false'"
              :disabled="submitting"
              :data-testid="`pos-counter-collect-mode-${m.id}`"
              @click="setMode(m.id)"
            >
              <span class="cc-mode-icon" aria-hidden="true">{{ m.icon }}</span>
              <span class="cc-mode-label">{{ $t(m.labelKey) }}</span>
              <span v-if="m.subKey" class="cc-mode-sub">{{ $t(m.subKey) }}</span>
            </button>
          </nav>
        </div>

        <!-- [MONTANT-CARTE 2026-07-01] Saisie du montant : ESPÈCES (montant reçu + rendu) ET
             CARTE (montant carte tapé manuellement, enregistré pour la compta : X carte / X espèces).
             Le TPE est manuel — la caisse ne fait qu'enregistrer le montant en détail. -->
        <div
          v-if="selectedMode === 'CASH' || selectedMode === 'CARD'"
          class="cc-cash-section"
          data-testid="pos-counter-collect-cash-block"
        >
          <label for="ccReceivedInput" class="cc-input-label">
            {{ amountLabel }}
          </label>
          <!-- [PAVE-NUMERIQUE 2026-07-01] readonly + inputmode="none" : sur écran tactile
               Windows, toucher le champ n'ouvre PLUS le clavier Windows. La saisie se fait
               UNIQUEMENT via le pavé numérique de l'app ci-dessous (PosV5Numpad). Le champ
               reste focusable (Entrée valide) et pré-rempli. -->
          <input
            id="ccReceivedInput"
            ref="receivedInput"
            type="text"
            inputmode="none"
            readonly
            class="cc-input cc-tabular"
            v-on:keypress="onlyFloat"
            @input="onReceivedInput"
            @keyup.enter="onConfirm"
            :value="cashReceivedRaw"
            :disabled="submitting"
            data-testid="pos-counter-collect-received-input"
          />

          <PosV5Numpad
            :aria-label="$t('label.received_amount')"
            :decimal-separator="','"
            @input="numpadInput"
            @back="numpadBack"
            @clear="numpadClear"
          />

          <!-- Rendu calc -->
          <div
            v-if="cashChange > 0"
            class="cc-change-row"
            role="status"
            aria-live="polite"
            data-testid="pos-counter-collect-change"
          >
            <span class="cc-change-label">
              <span aria-hidden="true">✨</span>
              {{ $t('label.change_due') }}
            </span>
            <span class="cc-change-value cc-tabular">{{ formatPrice(cashChange) }}</span>
          </div>
          <p v-if="cashShort" class="cc-cash-short" role="alert">
            {{ $t('label.cc_cash_short') }}
          </p>
        </div>

        <!-- Mobile / Ticket — validation directe (pas de saisie montant) -->
        <div
          v-else
          class="cc-mode-info"
          data-testid="pos-counter-collect-noncash-block"
        >
          <p class="cc-mode-info-text">{{ modeHint }}</p>
        </div>

        <!-- Footer: confirm + cancel -->
        <div class="cc-modal-footer">
          <button
            type="button"
            class="cc-cancel-btn"
            :disabled="submitting"
            data-testid="pos-counter-collect-cancel"
            @click="onCancel"
          >
            {{ $t('label.cancel') }}
          </button>
          <button
            type="button"
            class="cc-confirm-btn"
            :disabled="!canConfirm || submitting"
            :aria-disabled="!canConfirm || submitting"
            :aria-busy="submitting"
            data-testid="pos-counter-collect-confirm"
            @click="onConfirm"
          >
            <span v-if="submitting" class="cc-spinner" aria-hidden="true"></span>
            <span v-else aria-hidden="true">✓</span>
            {{ submitting ? $t('label.processing') : $t('button.confirm_and_print') }}
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
import axios from 'axios';
import PosV5Numpad from './v5/PosV5Numpad.vue';
import posPaymentMethodEnum from '../../../enums/modules/posPaymentMethodEnum';
import appService from '../../../services/appService';
import alertService from '../../../services/alertService';
import focusTrap from '../../../mixins/focusTrap';

/**
 * PosCounterCollectModal — Wave X X1 sibling counter-collect SSOT-flavored modal.
 *
 * Props:
 *   - order: Order object (must contain id + total/order_amount + queue_number)
 *
 * Emits:
 *   - confirmed: payload { orderId, mode, modeInt, received } after 2xx persist
 *   - cancel:    no payload, parent should clear the trigger ref
 *
 * Backend route:
 *   POST /admin/pos/counter-collect/{order}/confirm
 *   body: { mode: int, received: number|null, note: string|null }
 *   headers: X-Idempotency-Key (mandatory per Wave K Z7 IdempotencyKeyMiddleware)
 *
 * Visual parity contract with PaymentComponent.vue (V5):
 *   - Hero total card (mb-4) with 48px monospace tabular value
 *   - Section-title "Sélectionnez le mode de paiement"
 *   - 2×2 mode picker (4 modes) — Wave W visual preserved
 *   - PosV5Numpad shared atom for CASH received input
 *   - Confirm button — primary brand red, disabled until canConfirm
 *
 * Multi-tranche split is NOT supported here — see template header comment
 * for the V1.0.2 deferral rationale.
 */
export default {
  name: 'PosCounterCollectModal',
  components: { PosV5Numpad },
  mixins: [focusTrap],
  props: {
    order: {
      type: Object,
      default: null,
    },
  },
  emits: ['confirmed', 'cancel'],
  data() {
    return {
      selectedMode: 'CASH',
      cashReceivedRaw: '',
      // [GOAL-2026-05-29 BUG-CASH-KEYPAD] true while the received field still
      // holds the auto-pre-filled order total untouched. The FIRST numpad/key
      // press then starts a FRESH entry instead of appending onto "8,50"
      // (owner-reported "chiffres bizarres": pre-filled 8,50 + tap 1 → 8,501).
      cashFieldPristine: true,
      submitting: false,
      // Static mode list — kept inside data to ease i18n key reference;
      // intentionally NOT a computed because keys never change.
      modes: [
        { id: 'CASH',   icon: '💶', labelKey: 'label.encaisser_mode_cash',   subKey: 'label.encaisser_mode_cash_sub'   },
        { id: 'CARD',   icon: '💳', labelKey: 'label.encaisser_mode_card',   subKey: 'label.encaisser_mode_card_sub'   },
        { id: 'MOBILE', icon: '📱', labelKey: 'label.encaisser_mode_mobile', subKey: null },
        { id: 'TICKET', icon: '🎟️', labelKey: 'label.encaisser_mode_ticket', subKey: null },
      ],
    };
  },
  computed: {
    visible() {
      return this.order !== null && this.order !== undefined;
    },
    orderTotal() {
      if (!this.order) return 0;
      return Number(this.order.total ?? this.order.order_amount ?? 0);
    },
    cashReceivedNumber() {
      // [GOAL-D2 2026-05-23] Accept BOTH `.` and `,` as decimal separator
      // so the FR pre-fill ("8,50") parses correctly AND user-typed
      // values keep working in either locale flavour. Mirrors locale
      // tolerance pattern used by FR POS Vanilla wizard.
      const raw = String(this.cashReceivedRaw || '').replace(',', '.');
      const v = parseFloat(raw);
      return Number.isFinite(v) ? v : 0;
    },
    cashChange() {
      if (this.selectedMode !== 'CASH') return 0;
      const diff = this.cashReceivedNumber - this.orderTotal;
      return diff > 0 ? Math.round(diff * 100) / 100 : 0;
    },
    cashShort() {
      if (this.selectedMode !== 'CASH') return false;
      if (this.cashReceivedRaw === '' || this.cashReceivedRaw === null) return false;
      return this.cashReceivedNumber < this.orderTotal && this.cashReceivedNumber > 0;
    },
    canConfirm() {
      if (!this.order) return false;
      // [MONTANT-CARTE 2026-07-01] CASH ET CARTE exigent un montant saisi >= total.
      // CASH : montant reçu (rendu calculé). CARTE : montant tapé manuellement (TPE manuel),
      // prérempli = total, enregistré pour la compta (X carte / X espèces).
      if (this.selectedMode === 'CASH' || this.selectedMode === 'CARD') {
        return this.cashReceivedNumber >= this.orderTotal && this.orderTotal > 0;
      }
      // MOBILE / TICKET : validation directe (backend accepte received null).
      return this.orderTotal > 0;
    },
    amountLabel() {
      if (this.selectedMode === 'CARD') {
        const t = this.$t('label.card_amount');
        return (t && t !== 'label.card_amount') ? t : 'Montant carte';
      }
      return this.$t('label.received_amount');
    },
    modeHint() {
      const map = {
        CARD:   this.$t('label.cc_mode_card_hint'),
        MOBILE: this.$t('label.cc_mode_mobile_hint'),
        TICKET: this.$t('label.cc_mode_ticket_hint'),
      };
      return map[this.selectedMode] || '';
    },
  },
  watch: {
    // Pre-fill the received input with the order total the moment the
    // modal mounts on a fresh order so a one-tap "Confirmer" suffices for
    // the canonical exact-change case (cashier's most common scenario).
    order: {
      immediate: true,
      handler(newOrder) {
        if (newOrder && newOrder.id) {
          // [GOAL-D2 2026-05-23] Pre-fill INPUT with FR decimal separator
          // so the cashier sees "8,50" instead of "8.50". Parser at
          // cashReceivedNumber accepts both `,` and `.`.
          this.cashReceivedRaw = String(this.orderTotal.toFixed(2)).replace('.', ',');
          this.cashFieldPristine = true;
          this.selectedMode = 'CASH';
          this.submitting = false;
          // [GOAL-M-POS-2 2026-05-24] Auto-focus receivedInput on modal
          // open so the cashier can type-then-Enter without a mouse hop.
          // $nextTick defers until the cc-cash-section v-if mounts the
          // input (receivedInput ref does not exist before selectedMode
          // is CASH AND the DOM updates). Mirrors L5.3-F-02 recommendation.
          this.$nextTick(() => {
            // [A11y round4] WCAG focus trap on the dialog panel; initial focus
            // stays on the received field (cashier types-then-Enter). The mixin
            // confines Tab and restores focus to the trigger on close.
            this.activateFocusTrap(this.$refs.panel, {
              initialFocus: this.$refs.receivedInput,
            });
            if (this.$refs.receivedInput) {
              this.$refs.receivedInput.focus();
              this.$refs.receivedInput.select();
            }
          });
        } else {
          // Order cleared (parent closed the modal) → release trap + restore focus.
          this.deactivateFocusTrap();
        }
      },
    },
  },
  // [GOAL-M-POS-2 2026-05-24] Escape-to-close keyboard contract.
  // Mirrors KdsHistoryDrawer.vue:189-204 pattern: document-level
  // keydown listener installed in mounted(), removed in beforeUnmount().
  // The component itself is always in the DOM (parent always renders
  // <PosCounterCollectModal>); only the inner overlay toggles via
  // v-if="visible". The visibility + submitting guards inside _onEsc
  // ensure the handler is a no-op when no order is being collected.
  mounted() {
    document.addEventListener('keydown', this._onEsc);
  },
  beforeUnmount() {
    document.removeEventListener('keydown', this._onEsc);
  },
  methods: {
    setMode(modeId) {
      if (this.submitting) return;
      this.selectedMode = modeId;
      // [MONTANT-CARTE 2026-07-01] Préremplir le montant (= total) pour CASH ET CARTE quand le
      // champ est vide (le caissier a vidé via C, ou bascule de mode). CARTE = montant tapé pour la compta.
      if ((modeId === 'CASH' || modeId === 'CARD') && (this.cashReceivedRaw === '' || Number(String(this.cashReceivedRaw).replace(',', '.')) <= 0)) {
        // [GOAL-D2 2026-05-23] FR decimal pre-fill (see watcher comment).
        this.cashReceivedRaw = String(this.orderTotal.toFixed(2)).replace('.', ',');
        this.cashFieldPristine = true;
      }
    },
    onReceivedInput(e) {
      // Physical-keyboard edit → the field is now user-owned (not pristine).
      this.cashFieldPristine = false;
      this.cashReceivedRaw = e.target.value;
    },
    numpadInput(val) {
      if (this.submitting) return;
      // [GOAL-2026-05-29 BUG-CASH-KEYPAD] First tap after the auto-pre-fill
      // starts a FRESH amount (the pre-fill is a one-tap-confirm convenience).
      // Appending onto the pre-filled "8,50" produced "8,501" — the
      // owner-reported "chiffres bizarres". Physical typing already replaces
      // via the input's auto-select; the custom numpad must mirror that.
      let base = this.cashFieldPristine ? '' : String(this.cashReceivedRaw || '');
      this.cashFieldPristine = false;

      // One decimal separator only — ignore a 2nd ','/'.' (was "8,50," → NaN).
      if (val === ',' || val === '.') {
        if (base.includes(',') || base.includes('.')) return;
        if (base === '') base = '0'; // leading separator → "0,"
      }
      this.cashReceivedRaw = base + val;
    },
    numpadBack() {
      if (this.submitting) return;
      // Backspace on the pristine pre-fill: begin editing the existing value.
      this.cashFieldPristine = false;
      this.cashReceivedRaw = String(this.cashReceivedRaw || '').slice(0, -1);
    },
    numpadClear() {
      if (this.submitting) return;
      this.cashFieldPristine = false;
      this.cashReceivedRaw = '';
    },
    onlyFloat(e) {
      return appService.floatNumber(e);
    },
    formatPrice(amount) {
      try {
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount || 0);
      } catch (_) {
        return `${(amount || 0).toFixed(2)} €`;
      }
    },
    // Idempotency key formula — mirrors Wave W
    // (PosComponent.buildKioskCashIdempotencyKey commit ab0caa985).
    // Minute-bucket so a double-tap within the same minute replays the
    // cached 2xx response from IdempotencyKeyMiddleware (Wave K Z7),
    // avoiding two distinct POSTs racing into PaymentService::
    // confirmCounterPayment's DB::transaction + lockForUpdate.
    // Different orders and different modes produce distinct keys.
    buildIdempotencyKey(orderId, modeInt) {
      const minuteBucket = Math.floor(Date.now() / 60000);
      return `pos-counter-collect-${orderId}-${modeInt}-${minuteBucket}`;
    },
    onCancel() {
      if (this.submitting) return;
      this.$emit('cancel');
    },
    // [GOAL-M-POS-2 2026-05-24] Document-level Escape handler. Guarded by
    // visibility (modal is permanently mounted by parent) + submitting
    // flag (don't fire mid-POST or while a 200/409 is in flight).
    // Stored as an arrow-function-style property so the listener
    // reference matches across add/remove (avoids the lost-reference
    // bug with method-bound handlers).
    _onEsc(e) {
      if (!this.visible || this.submitting) return;
      if (e.key === 'Escape') {
        this.onCancel();
      }
    },
    async onConfirm() {
      if (!this.order || this.submitting || !this.canConfirm) return;
      const modeMap = {
        CASH:   posPaymentMethodEnum.CASH,
        CARD:   posPaymentMethodEnum.CARD,
        MOBILE: posPaymentMethodEnum.MOBILE_BANKING,
        TICKET: posPaymentMethodEnum.TICKET_RESTAURANT,
      };
      const modeInt = modeMap[this.selectedMode];
      if (!modeInt) return;
      const orderId = this.order.id;
      const total = this.orderTotal;
      // [MONTANT-CARTE 2026-07-01] CASH ET CARTE envoient le montant saisi (CARTE = montant tapé
      // manuellement, enregistré pour la compta). MOBILE/TICKET envoient null (validation directe).
      const received = (this.selectedMode === 'CASH' || this.selectedMode === 'CARD')
        ? this.cashReceivedNumber
        : null;

      this.submitting = true;
      try {
        const idempotencyKey = this.buildIdempotencyKey(orderId, modeInt);
        const resp = await axios.post(
          `admin/pos/counter-collect/${orderId}/confirm`,
          {
            mode: modeInt,
            received,
            note: this.selectedMode === 'CASH'
              ? 'Encaissement borne au comptoir (SSOT modal)'
              : `Encaissement borne ${this.selectedMode} (SSOT modal)`,
          },
          {
            headers: { 'X-Idempotency-Key': idempotencyKey },
          }
        );

        // Toast feedback per mode — mirror Wave W simulation copy so the
        // cashier perceives parity with the old picker.
        const orderLabel = this.order.queue_number || this.order.order_serial_no || orderId;

        // [TRAP-3 2026-06-04] Cash-trail gap surfacing. When a CASH collection
        // succeeds with NO open drawer session, the backend flags
        // `cash_movement_skipped` on the response: the order is PAID but NO
        // cash_movement row was recorded, so end-of-day reconciliation will
        // under-count. The sale is NEVER blocked — we replace the plain success
        // toast with an explicit WARNING so the cashier knows to open a session
        // / reconcile manually, instead of the gap silently reaching the Z-close.
        const cashMovementSkipped = resp?.data?.data?.cash_movement_skipped === true
          || resp?.data?.cash_movement_skipped === true;
        if (cashMovementSkipped) {
          const skipMsg = resp?.data?.data?.cash_movement_skipped_message
            || resp?.data?.cash_movement_skipped_message
            || 'Aucune session caisse ouverte — mouvement non enregistré';
          alertService.warning(
            `Commande ${orderLabel} encaissée. ${skipMsg} (à régulariser au fond de caisse).`
          );
          this.$emit('confirmed', {
            orderId,
            mode: this.selectedMode,
            modeInt,
            received,
            total,
            cashMovementSkipped: true,
          });
          return;
        }

        if (this.selectedMode === 'CASH') {
          alertService.success(
            this.$t('label.cash_drawer_opened_simulation', { order: orderLabel })
          );
        } else if (this.selectedMode === 'CARD') {
          alertService.success(
            this.$t('label.tpe_validated_simulation', { order: orderLabel })
          );
        } else {
          alertService.success(
            this.$t('label.encaisser_success', { order: orderLabel })
          );
        }

        this.$emit('confirmed', {
          orderId,
          mode: this.selectedMode,
          modeInt,
          received,
          total,
        });
      } catch (err) {
        // [GOAL-K2-HEAL-01 2026-05-24] Phase K.4 H9 P1 + J-CASCADE H9 —
        // Race protection. When two cashiers click "Encaisser" on the
        // same Q10 row simultaneously, the loser receives a 409 Conflict
        // carrying `error_code: payment_already_collected` from
        // PaymentService::confirmCounterPayment (formerly a silent
        // short-circuit that toasted `cash_drawer_opened_simulation` as
        // success → drawer-open + till-count drift risk).
        //
        // We branch on error_code (stable identifier) rather than the
        // translated message string, surface a clear FR error toast, and
        // emit `cancel` so the parent Q10 panel refreshes and removes the
        // already-paid row from view. Phantom drawer-open simulation
        // never fires.
        if (
          err?.response?.status === 409
          && err?.response?.data?.error_code === 'payment_already_collected'
        ) {
          const fallbackMsg = 'Cette commande a déjà été encaissée par un autre caissier.';
          alertService.error(err?.response?.data?.message || fallbackMsg);
          this.submitting = false;
          this.$emit('cancel');
          return;
        }
        const msg = err?.response?.data?.message || this.$t('label.encaisser_failed');
        alertService.error(msg);
        this.submitting = false;
      }
    },
  },
};
</script>

<style scoped>
/* =============================================================================
   POS Counter-Collect Modal — visual parity with PaymentComponent V5
   ----------------------------------------------------------------------------- */
.cc-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10001;
  padding: 16px;
}
.cc-modal {
  position: relative;
  background: var(--pos-v5-surface, #fff);
  border-radius: 12px;
  width: 100%;
  max-width: 520px;
  max-height: 92vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
  padding: 20px 24px 24px 24px;
  font-family: var(--pos-v5-font-family, 'Rubik', system-ui, sans-serif);
}
.cc-modal-header { margin-bottom: 14px; }
.cc-modal-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.cc-modal-title {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: var(--pos-v5-text, #1a1a1a);
  text-transform: capitalize;
}
.cc-modal-close {
  background: transparent;
  border: 0;
  font-size: 18px;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  cursor: pointer;
  color: var(--pos-v5-muted, #555);
}
.cc-modal-close:hover:not(:disabled) {
  background: var(--pos-v5-surface-2, #f3f3f3);
  color: var(--pos-v5-text, #1a1a1a);
}
.cc-modal-order-meta {
  margin: 6px 0 0 0;
  display: flex;
  align-items: baseline;
  gap: 10px;
  font-size: 13px;
}
.cc-modal-order-no {
  font-weight: 700;
  color: var(--pos-v5-muted, #555);
}
.cc-modal-source {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--pos-v5-brand-red-soft, #ffeaea);
  color: var(--pos-v5-brand-red, #cf3a3a);
  font-weight: 600;
}

/* Hero total — mirror PaymentComponent V5 ".pos-v5-payment-total-card" */
.cc-hero-total {
  text-align: center;
  padding: 14px 16px;
  background: var(--pos-v5-surface-2, #faf6f1);
  border: 1px solid var(--pos-v5-border, #eadfd2);
  border-radius: 10px;
  margin-bottom: 16px;
}
.cc-hero-label {
  margin: 0 0 4px 0;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--pos-v5-muted, #777);
  font-weight: 600;
}
.cc-hero-value {
  margin: 0;
  font-size: 40px;
  line-height: 1.1;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: var(--pos-v5-brand-red, #cf3a3a);
  font-family: 'Rubik Mono One', 'JetBrains Mono', ui-monospace, monospace;
}
@media (max-width: 480px) { .cc-hero-value { font-size: 32px; } }

/* Mode picker — same look as Wave W */
.cc-mode-section { margin-bottom: 16px; }
.cc-section-title {
  margin: 0 0 8px 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--pos-v5-text, #1a1a1a);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.cc-mode-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
}
.cc-mode-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 14px 10px;
  border: 2px solid var(--pos-v5-border, #e0e0e0);
  border-radius: 10px;
  background: var(--pos-v5-surface-2, #fafafa);
  cursor: pointer;
  font-family: inherit;
  transition: transform 80ms ease, border-color 120ms ease, background 120ms ease;
  min-height: 84px;
}
/* [test-e2e fix A-002 round-1 2026-05-21] Separate :hover (subtle hint)
   from .is-active (brand-red filled state) so a cashier never sees TWO
   buttons highlighted simultaneously (hover residue + selected mode). */
.cc-mode-btn:hover:not(:disabled):not(.is-active) {
  border-color: var(--pos-v5-border-strong, #d4d4d4);
  background: var(--pos-v5-surface, #fff);
}
.cc-mode-btn.is-active {
  border-color: var(--pos-v5-brand-red, #cf3a3a);
  background: var(--pos-v5-brand-red-soft, #ffeaea);
  box-shadow: inset 0 0 0 1px var(--pos-v5-brand-red, #cf3a3a);
}
.cc-mode-btn:active:not(:disabled) { transform: translateY(0); }
.cc-mode-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.cc-mode-icon { font-size: 26px; line-height: 1; }
.cc-mode-label {
  font-size: 14px;
  font-weight: 700;
  color: var(--pos-v5-text, #1a1a1a);
}
.cc-mode-sub {
  font-size: 10.5px;
  color: var(--pos-v5-muted, #777);
  text-align: center;
}

/* Cash sub-section */
.cc-cash-section { margin-bottom: 16px; }
.cc-input-label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--pos-v5-muted, #555);
  margin-bottom: 6px;
}
.cc-input {
  width: 100%;
  padding: 12px 14px;
  border: 1.5px solid var(--pos-v5-border, #e0e0e0);
  border-radius: 8px;
  font-size: 24px;
  font-weight: 700;
  text-align: right;
  background: var(--pos-v5-surface, #fff);
  margin-bottom: 10px;
  color: var(--pos-v5-text, #1a1a1a);
}
.cc-input:focus {
  outline: none;
  border-color: var(--pos-v5-brand-red, #cf3a3a);
  box-shadow: 0 0 0 3px var(--pos-v5-brand-red-soft, #ffeaea);
}
.cc-tabular { font-variant-numeric: tabular-nums; }
.cc-change-row {
  margin-top: 12px;
  padding: 10px 14px;
  background: var(--pos-v5-success-soft, #e8f7ed);
  border: 1px solid var(--pos-v5-success, #2c8c4a);
  border-radius: 8px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  color: var(--pos-v5-success-dark, #1f6437);
  font-weight: 700;
}
.cc-change-label { font-size: 13px; }
.cc-change-value { font-size: 18px; }
.cc-cash-short {
  margin: 8px 0 0 0;
  font-size: 12px;
  color: var(--pos-v5-brand-red, #cf3a3a);
  font-weight: 600;
}

/* Non-cash info */
.cc-mode-info {
  margin-bottom: 16px;
  padding: 12px 14px;
  background: var(--pos-v5-surface-2, #faf6f1);
  border: 1px dashed var(--pos-v5-border, #d9cfc0);
  border-radius: 8px;
}
.cc-mode-info-text {
  margin: 0;
  font-size: 13px;
  color: var(--pos-v5-muted, #555);
  line-height: 1.45;
}

/* Footer */
.cc-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 4px;
}
.cc-cancel-btn,
.cc-confirm-btn {
  padding: 12px 20px;
  border-radius: 8px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.cc-cancel-btn {
  background: transparent;
  border: 1px solid var(--pos-v5-border, #ccc);
  color: var(--pos-v5-text, #1a1a1a);
}
.cc-cancel-btn:hover:not(:disabled) { background: var(--pos-v5-surface-2, #f3f3f3); }
.cc-cancel-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.cc-confirm-btn {
  background: var(--pos-v5-brand-red, #cf3a3a);
  border: 2px solid var(--pos-v5-brand-red, #cf3a3a);
  color: #fff;
  box-shadow: 0 4px 12px rgba(207, 58, 58, 0.25);
}
.cc-confirm-btn:hover:not(:disabled) {
  background: var(--pos-v5-brand-red-dark, #b32f2f);
  border-color: var(--pos-v5-brand-red-dark, #b32f2f);
}
.cc-confirm-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
  box-shadow: none;
}

.cc-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.4);
  border-top-color: #fff;
  border-radius: 50%;
  animation: cc-spin 700ms linear infinite;
  display: inline-block;
}
@keyframes cc-spin {
  to { transform: rotate(360deg); }
}

.fade-enter-active,
.fade-leave-active { transition: opacity 160ms ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
