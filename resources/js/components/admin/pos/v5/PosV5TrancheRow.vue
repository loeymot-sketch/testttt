<template>
  <div
    :class="['pos-v5-tranche-row', invalid ? 'pos-v5-tranche-row--invalid' : '']"
    :data-testid="testid"
    role="group"
    :aria-label="ariaGroupLabel"
  >
    <div class="pos-v5-tranche-row__header">
      <label :for="modeId" class="pos-v5-tranche-row__mode-label">
        <span class="pos-v5-tranche-row__icon" aria-hidden="true">{{ modeIcon }}</span>
        <span>{{ modeLabel }}</span>
      </label>
      <select
        :id="modeId"
        class="pos-v5-tranche-row__mode-select"
        :value="tranche.mode"
        :aria-label="modeAriaLabel"
        @change="onModeChange"
      >
        <option :value="modes.CASH">{{ $t('label.cash') }}</option>
        <option :value="modes.CARD">{{ $t('label.card') }}</option>
        <option :value="modes.MOBILE_BANKING">{{ $t('label.mobile_banking') }}</option>
        <option :value="modes.TICKET_RESTAURANT">{{ $t('label.ticket_restaurant') }}</option>
        <option :value="modes.OTHER">{{ $t('label.other') }}</option>
      </select>
      <button
        type="button"
        class="pos-v5-tranche-row__remove"
        :aria-label="removeAriaLabel"
        :data-testid="removeTestid"
        @click="$emit('remove')"
      >
        <span aria-hidden="true">✕</span>
      </button>
    </div>

    <div class="pos-v5-tranche-row__fields">
      <div class="pos-v5-tranche-row__field">
        <label :for="amountId" class="pos-v5-tranche-row__field-label">
          {{ $t('label.amount') }}
        </label>
        <input
          :id="amountId"
          type="number"
          inputmode="decimal"
          step="0.01"
          min="0"
          class="pos-v5-tranche-row__input pos-v5-tabular"
          :value="tranche.amount"
          :data-testid="amountTestid"
          @input="onAmountInput"
        />
      </div>

      <div v-if="isCash" class="pos-v5-tranche-row__field">
        <label :for="tenderedId" class="pos-v5-tranche-row__field-label">
          {{ $t('label.received_amount') }}
        </label>
        <input
          :id="tenderedId"
          type="number"
          inputmode="decimal"
          step="0.01"
          min="0"
          class="pos-v5-tranche-row__input pos-v5-tabular"
          :value="tranche.tendered"
          :data-testid="tenderedTestid"
          @input="onTenderedInput"
        />
      </div>
    </div>

    <div
      v-if="isCash && changeEur > 0"
      class="pos-v5-tranche-row__change"
      role="status"
      aria-live="polite"
    >
      <span class="pos-v5-tranche-row__change-label" aria-hidden="true">✨</span>
      <span class="pos-v5-tranche-row__change-text">
        {{ $t('label.change_due') }}:
      </span>
      <span class="pos-v5-tranche-row__change-value pos-v5-tabular">
        {{ formatEur(changeEur) }}
      </span>
    </div>

    <p
      v-if="invalid && validationMsg"
      class="pos-v5-tranche-row__error"
      role="alert"
    >
      {{ validationMsg }}
    </p>
  </div>
</template>

<script>
/**
 * PosV5TrancheRow — affichage + édition d'une tranche de paiement.
 *
 * Mission : CV1-POS-SPLIT-PAYMENT-001
 * Doc plan : docs/audit/SPLIT_PAYMENT_IMPLEMENTATION_2026-05-06.md
 *
 * Design : POS V5 tokens. Atom autonome — ne mute PAS le tranche prop ;
 * émet `update` avec un patch que le parent applique.
 *
 * Émissions :
 *   - @update(patch) — { mode? | amount? | tendered? }
 *   - @remove        — bouton ✕ cliqué
 */
import posPaymentMethodEnum from '../../../../enums/modules/posPaymentMethodEnum';
import { computeChangeCents, fromCents, validateTranche, isCashMode } from '../../../../helpers/posSplitPayment';

export default {
    name: 'PosV5TrancheRow',
    props: {
        tranche: { type: Object, required: true },
        index: { type: Number, default: 0 },
    },
    emits: ['update', 'remove'],
    computed: {
        modes() {
            return posPaymentMethodEnum;
        },
        isCash() {
            return isCashMode(this.tranche.mode);
        },
        modeIcon() {
            const m = Number(this.tranche.mode);
            if (m === posPaymentMethodEnum.CASH) return '💵';
            if (m === posPaymentMethodEnum.CARD) return '💳';
            if (m === posPaymentMethodEnum.MOBILE_BANKING) return '📱';
            if (m === posPaymentMethodEnum.TICKET_RESTAURANT) return '🍽️';
            return '💼';
        },
        modeLabel() {
            const m = Number(this.tranche.mode);
            if (m === posPaymentMethodEnum.CASH) return this.$t('label.cash');
            if (m === posPaymentMethodEnum.CARD) return this.$t('label.card');
            if (m === posPaymentMethodEnum.MOBILE_BANKING) return this.$t('label.mobile_banking');
            if (m === posPaymentMethodEnum.TICKET_RESTAURANT) return this.$t('label.ticket_restaurant');
            return this.$t('label.other');
        },
        modeId() { return `tranche-mode-${this.index}`; },
        amountId() { return `tranche-amount-${this.index}`; },
        tenderedId() { return `tranche-tendered-${this.index}`; },
        testid() { return `pos-payment-tranche-row-${this.index}`; },
        removeTestid() { return `pos-payment-tranche-remove-${this.index}`; },
        amountTestid() { return `pos-payment-tranche-amount-${this.index}`; },
        tenderedTestid() { return `pos-payment-tranche-tendered-${this.index}`; },
        ariaGroupLabel() {
            return `${this.$t('label.payment') || 'Paiement'} ${this.index + 1} (${this.modeLabel})`;
        },
        modeAriaLabel() {
            return `${this.$t('label.payment_method')} #${this.index + 1}`;
        },
        removeAriaLabel() {
            return `${this.$t('button.remove') || 'Supprimer'} #${this.index + 1}`;
        },
        validation() {
            return validateTranche(this.tranche);
        },
        invalid() {
            return !this.validation.valid;
        },
        validationMsg() {
            const errs = this.validation.errors || {};
            if (errs.tendered === 'tendered_required') {
                return this.$t('pos.split_tendered_required') || 'Le montant reçu est requis pour la tranche cash.';
            }
            if (errs.tendered === 'tendered_below_amount') {
                return this.$t('pos.split_tendered_below_amount') || 'Le montant reçu est inférieur au montant de la tranche.';
            }
            if (errs.amount === 'amount_required') {
                return this.$t('pos.split_amount_required') || 'Le montant de la tranche est requis.';
            }
            if (errs.mode === 'invalid_mode') {
                return this.$t('pos.split_mode_invalid') || 'Mode de paiement invalide.';
            }
            return '';
        },
        changeCents() {
            return computeChangeCents(this.tranche);
        },
        changeEur() {
            return fromCents(this.changeCents);
        },
    },
    methods: {
        onModeChange(e) {
            const mode = Number(e.target.value);
            const patch = { mode };
            if (!isCashMode(mode)) {
                patch.tendered = null;
            }
            this.$emit('update', patch);
        },
        onAmountInput(e) {
            const v = e.target.value;
            this.$emit('update', { amount: v === '' ? 0 : Number(v) });
        },
        onTenderedInput(e) {
            const v = e.target.value;
            this.$emit('update', { tendered: v === '' ? null : Number(v) });
        },
        formatEur(value) {
            const setting = this.$store?.getters?.['frontendSetting/lists'] || {};
            const sym = setting.site_default_currency_symbol || '€';
            return `${Number(value).toFixed(2)} ${sym}`;
        },
    },
};
</script>

<style scoped>
.pos-v5-tranche-row {
    display: flex;
    flex-direction: column;
    gap: var(--pos-v5-space-2);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    background: var(--pos-v5-bg-panel);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
    box-shadow: var(--pos-v5-shadow-sm);
    transition: border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}

.pos-v5-tranche-row--invalid {
    border-color: var(--pos-v5-danger);
    box-shadow: 0 0 0 2px var(--pos-v5-danger-soft);
}

.pos-v5-tranche-row__header {
    display: flex;
    align-items: center;
    gap: var(--pos-v5-space-2);
    flex-wrap: wrap;
}

.pos-v5-tranche-row__mode-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    text-transform: uppercase;
    letter-spacing: var(--pos-v5-tracking-caps);
    color: var(--pos-v5-ink-soft);
}

.pos-v5-tranche-row__icon {
    font-size: 18px;
}

.pos-v5-tranche-row__mode-select {
    flex: 1 1 auto;
    height: 40px;
    padding: 0 var(--pos-v5-space-3);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-sm);
    background: var(--pos-v5-bg-panel);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    color: var(--pos-v5-ink);
}
.pos-v5-tranche-row__mode-select:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}

.pos-v5-tranche-row__remove {
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: var(--pos-v5-radius-pill);
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink-soft);
    cursor: pointer;
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
                color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.pos-v5-tranche-row__remove:hover {
    background: var(--pos-v5-danger-soft);
    color: var(--pos-v5-danger);
}
.pos-v5-tranche-row__remove:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}

.pos-v5-tranche-row__fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--pos-v5-space-2);
}

.pos-v5-tranche-row__field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.pos-v5-tranche-row__field-label {
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-ink-soft);
}

.pos-v5-tranche-row__input {
    height: 44px;
    padding: 0 var(--pos-v5-space-3);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-sm);
    background: var(--pos-v5-bg-panel);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h6);
    font-weight: var(--pos-v5-weight-bold);
    color: var(--pos-v5-ink);
    text-align: center;
}
.pos-v5-tranche-row__input:focus-visible {
    outline: 0;
    border-color: var(--pos-v5-brand-red);
    box-shadow: 0 0 0 3px var(--pos-v5-brand-red-soft);
}

.pos-v5-tranche-row__change {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px var(--pos-v5-space-3);
    background: var(--pos-v5-success-soft);
    border-radius: var(--pos-v5-radius-sm);
    color: var(--pos-v5-success-dark);
    font-size: var(--pos-v5-text-body);
    font-weight: var(--pos-v5-weight-bold);
}
.pos-v5-tranche-row__change-value {
    margin-left: auto;
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}

.pos-v5-tranche-row__error {
    margin: 0;
    font-size: var(--pos-v5-text-eyebrow);
    color: var(--pos-v5-danger-dark);
}
</style>
