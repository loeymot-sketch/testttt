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
      <div ref="ccModalRoot" class="cc-modal" role="dialog" aria-modal="true" :aria-label="$t('label.encaisser_mode_title')">
        <!-- [DISPUTE-R1 ADV-F-P0-1 2026-06-12] Zone scrollable INTERNE.
             Le footer sticky du heal W2-G6 recouvrait 6 touches du pavé
             (taper « 9 » pouvait déclencher « Confirmer & Imprimer ticket »
             — hit-test live 1440×900). Structure colonne flex : tout le
             contenu scrolle ICI, le footer est un sibling FIXE en bas,
             hors du scroller → chevauchement impossible, CTA toujours
             visible (l'intention G6 est préservée sans son effet de bord). -->
        <div class="cc-modal-body" data-testid="pos-counter-collect-body">
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
            <!-- [DISPUTE-R1 B-R1-04+E-ADV-4 2026-06-12] Les N° de file se
                 réutilisent chaque jour (2× « A0011 » en attente simultanée,
                 prouvé DB). Avant de confirmer un encaissement, le caissier
                 doit VOIR qu'il tient une commande d'un autre jour. -->
            <span
              v-if="orderDayBadge"
              class="cc-modal-order-day"
              data-testid="pos-counter-collect-day-badge"
            >⚠ {{ orderDayBadge }}</span>
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

        <!-- Cash sub-section: received amount input + numpad + rendu calc -->
        <div
          v-if="selectedMode === 'CASH'"
          class="cc-cash-section"
          data-testid="pos-counter-collect-cash-block"
        >
          <label for="ccReceivedInput" class="cc-input-label">
            {{ $t('label.received_amount') }}
          </label>
          <input
            id="ccReceivedInput"
            ref="receivedInput"
            type="text"
            class="cc-input cc-tabular"
            v-on:keypress="onlyFloat"
            @input="onReceivedInput"
            @keyup.enter="onConfirmFromKeyboard"
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

        <!-- Card / Mobile / Ticket — single-tap confirm. CARD = Terminal manuel
             (SumUp) with an optional reference the cashier copies from the TPE. -->
        <div
          v-else
          class="cc-mode-info"
          data-testid="pos-counter-collect-noncash-block"
        >
          <p class="cc-mode-info-text">{{ modeHint }}</p>
          <div
            v-if="selectedMode === 'CARD'"
            class="cc-card-ref"
            data-testid="pos-counter-collect-card-ref-block"
          >
            <label class="cc-card-ref-label" for="cc-card-ref-input">
              {{ $t('label.encaisser_terminal_ref') }}
            </label>
            <input
              id="cc-card-ref-input"
              v-model="cardReference"
              type="text"
              inputmode="text"
              maxlength="64"
              class="cc-card-ref-input"
              :placeholder="$t('label.encaisser_terminal_ref_placeholder')"
              data-testid="pos-counter-collect-card-ref-input"
              :aria-label="$t('label.encaisser_terminal_ref')"
            />
          </div>
        </div>
        </div><!-- /.cc-modal-body — fin de la zone scrollable -->

        <!-- Footer: confirm + cancel — SIBLING du body, jamais au-dessus du pavé -->
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
import { trapFocus } from '../../../helpers/posA11y';

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
      // [G-H unified-encaissement] Terminal-manuel (SumUp) reference — the cashier
      // types the SumUp transaction ref after a manual card payment; persisted via
      // the existing confirmCounterPayment `note` (no backend change). Owner spec:
      // terminals not live → manual SumUp card→réf. Optional (CARD mode only).
      cardReference: '',
      // [GOAL-2026-05-29 BUG-CASH-KEYPAD] true while the received field still
      // holds the auto-pre-filled order total untouched. The FIRST numpad/key
      // press then starts a FRESH entry instead of appending onto "8,50"
      // (owner-reported "chiffres bizarres": pre-filled 8,50 + tap 1 → 8,501).
      cashFieldPristine: true,
      // [HEAL F1 / dispute-final-push 2026-06-13] timestamp (ms) où la section
      // CASH s'est ouverte — pour rejeter le keyup d'Entrée pass-through.
      cashSectionOpenedAt: 0,
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
      if (this.selectedMode === 'CASH') {
        // For CASH: require received >= total. Backend
        // (PaymentService::confirmCounterPayment L235-238) enforces
        // this server-side; surface it client-side to avoid the 422
        // round-trip + confusing toast.
        return this.cashReceivedNumber >= this.orderTotal && this.orderTotal > 0;
      }
      // For CARD / MOBILE / TICKET: backend allows null received (L247-249);
      // a single tap on the mode confirms the collection.
      return this.orderTotal > 0;
    },
    modeHint() {
      const map = {
        CARD:   this.$t('label.cc_mode_card_hint'),
        MOBILE: this.$t('label.cc_mode_mobile_hint'),
        TICKET: this.$t('label.cc_mode_ticket_hint'),
      };
      return map[this.selectedMode] || '';
    },
    // [DISPUTE-R1 B-R1-04+E-ADV-4 2026-06-12] Les N° de file (A0011…) se
    // réutilisent chaque business date → avant de confirmer, le caissier doit
    // voir qu'il encaisse une commande d'un AUTRE jour. null = aujourd'hui
    // (cas normal, zéro bruit). « hier » pour J-1, sinon date FR jj/mm/aaaa.
    // (Clé i18n dédiée inexistante → FR hardcodé, noté pour H2.)
    orderDayBadge() {
      const iso = this.order?.created_at;
      if (!iso) return null;
      const d = new Date(iso);
      if (!Number.isFinite(d.getTime())) return null;
      d.setHours(0, 0, 0, 0);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      if (d.getTime() === today.getTime()) return null;
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);
      if (d.getTime() === yesterday.getTime()) return "Commande d'hier";
      try {
        return 'Commande du ' + new Intl.DateTimeFormat('fr-FR', {
          day: '2-digit', month: '2-digit', year: 'numeric',
        }).format(d);
      } catch (_) {
        return 'Commande du ' + String(d.getDate()).padStart(2, '0') + '/'
          + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
      }
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
          this.cardReference = '';
          this.submitting = false;
          // [HEAL F1 / dispute-final-push 2026-06-13] Horodatage d'ouverture de
          // la section CASH : sert à rejeter le keyup d'Entrée qui a OUVERT ce
          // modal (cf. onConfirmFromKeyboard).
          this.cashSectionOpenedAt = (typeof performance !== 'undefined' && performance.now)
            ? performance.now() : Date.now();
          // [GOAL-M-POS-2 2026-05-24] Auto-focus receivedInput on modal
          // open so the cashier can type-then-Enter without a mouse hop.
          // $nextTick defers until the cc-cash-section v-if mounts the
          // input (receivedInput ref does not exist before selectedMode
          // is CASH AND the DOM updates). Mirrors L5.3-F-02 recommendation.
          this.$nextTick(() => {
            if (this.$refs.receivedInput) {
              this.$refs.receivedInput.focus();
              this.$refs.receivedInput.select();
            }
          });
        }
      },
    },
    // [FP-37] Trap focus inside the modal while open (Tab no longer leaks to the page behind
    // the dialog). Uses the existing tested posA11y.trapFocus helper, which was dead code
    // imported by zero production components. `visible` is computed from `order`.
    visible(isVisible) {
      if (isVisible) {
        this.$nextTick(() => {
          this._releaseFocusTrap = trapFocus(this.$refs.ccModalRoot);
        });
      } else if (this._releaseFocusTrap) {
        this._releaseFocusTrap();
        this._releaseFocusTrap = null;
      }
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
    // [FP-37] release the focus trap if the modal is torn down while open.
    if (this._releaseFocusTrap) { this._releaseFocusTrap(); this._releaseFocusTrap = null; }
  },
  methods: {
    setMode(modeId) {
      if (this.submitting) return;
      this.selectedMode = modeId;
      // When switching back to CASH, re-pre-fill the received field if
      // the cashier had emptied it via the numpad C key.
      if (modeId === 'CASH' && (this.cashReceivedRaw === '' || Number(String(this.cashReceivedRaw).replace(',', '.')) <= 0)) {
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
    // [G-H unified-encaissement] Build the persisted note. For the manual
    // Terminal (CARD/SumUp) path, append the cashier-entered reference so the
    // SumUp transaction is traceable on the OrderPayment/audit (terminals are
    // not wired live in V1 → manual card→réf per owner mandate). Trimmed; the
    // ref is optional (empty → the plain mode note, unchanged behavior).
    buildCollectNote() {
      if (this.selectedMode === 'CASH') {
        return 'Encaissement borne au comptoir (SSOT modal)';
      }
      if (this.selectedMode === 'CARD') {
        const ref = String(this.cardReference || '').trim();
        return ref !== ''
          ? `Encaissement Terminal manuel (SumUp) — réf: ${ref}`
          : 'Encaissement Terminal manuel (SumUp) (SSOT modal)';
      }

      return `Encaissement borne ${this.selectedMode} (SSOT modal)`;
    },
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
    // [HEAL F1 / dispute-final-push 2026-06-13] Confirmation déclenchée AU
    // CLAVIER (Entrée) sur l'input montant. L'appui Entrée qui OUVRE le modal
    // (click sur « Encaisser ») envoie son keyUP dans l'input fraîchement
    // autofocusé → cet unique keyup confirmait un encaissement ESPÈCES SANS
    // aucune revue (prouvé ×3 live : mauvais mode + piste NF525 polluée). On
    // ignore une Entrée qui arrive pendant la fenêtre d'ouverture sur un champ
    // resté vierge ; une Entrée DÉLIBÉRÉE (le caissier revoit puis valide, ou
    // saisit un montant) arrive bien plus tard / sur un champ édité. Le bouton
    // Confirmer (souris) appelle onConfirm() directement, inchangé.
    onConfirmFromKeyboard() {
      const now = (typeof performance !== 'undefined' && performance.now)
        ? performance.now() : Date.now();
      if (this.cashFieldPristine && (now - this.cashSectionOpenedAt) < 450) return;
      this.onConfirm();
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
      // CASH path sends explicit received (backend enforces >= total).
      // Non-CASH path sends null (backend allows null for non-cash modes).
      const received = this.selectedMode === 'CASH' ? this.cashReceivedNumber : null;

      this.submitting = true;
      try {
        const idempotencyKey = this.buildIdempotencyKey(orderId, modeInt);
        const resp = await axios.post(
          `admin/pos/counter-collect/${orderId}/confirm`,
          {
            mode: modeInt,
            received,
            note: this.buildCollectNote(),
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
        // [UIUX-W2 F6 2026-06-11] 429 rate-limit : le message backend brut
        // est l'anglais « Too Many Attempts. » ET l'intercepteur axios global
        // (bootstrap.js, bucket 'rl') toaste DÉJÀ un message FR avec le vrai
        // Retry-After (« Trop de requêtes — patientez Xs… »). On supprime
        // donc le second toast local (doublon EN brut) et on libère juste
        // le bouton pour un nouvel essai.
        if (err?.response?.status === 429) {
          this.submitting = false;
          return;
        }
        // [DISPUTE-R1 E-ADV-8 2026-06-12] 401 mid-confirm (token expiré,
        // TTL 480 min) : le message backend brut est l'anglais
        // « Unauthenticated. » et l'intercepteur global app.js déconnecte +
        // redirige (le toast EN courait la course avec la navigation). Le
        // caissier qui a déjà accepté les espèces doit lire SANS AMBIGUÏTÉ
        // que la commande n'est PAS encaissée et qu'elle reste dans la file
        // après reconnexion. (Clé i18n dédiée → backlog H2 ; FR hardcodé
        // assumé, précédent : messages 409/cash_movement_skipped ci-dessus.)
        if (err?.response?.status === 401) {
          alertService.error(
            this.$t('label.encaisser_failed')
            + " — session expirée : la commande n'a PAS été encaissée."
            + ' Reconnectez-vous puis réessayez depuis la file d\'encaissement.'
          );
          this.submitting = false;
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
  /* [DISPUTE-R1 ADV-F-P0-1 2026-06-12] La racine n'est PLUS le scroller
     (l'ancien scroll racine + footer collant opaque = 6 touches du
     pavé recouvertes, « 9 » déclenchait le CTA Confirmer). Colonne flex :
     le scroll vit dans .cc-modal-body, le footer est fixe en bas. */
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
  padding: 0;
  font-family: var(--pos-v5-font-family, 'Rubik', system-ui, sans-serif);
}
/* [DISPUTE-R1 ADV-F-P0-1] Scroller interne — header + hero + modes + pavé.
   min-height: 0 est requis pour qu'un enfant flex puisse rétrécir sous son
   contenu et scroller au lieu de pousser le footer hors écran. */
.cc-modal-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 20px 24px 8px 24px;
}
/* [DISPUTE-R1 ADV-F-P0-1 compaction 2026-06-12] Densité verticale mesurée
   live : contenu 862px vs ~743px disponibles à 1440×900 → le pavé exigeait
   un scroll pour CHAQUE encaissement espèces. Compaction ciblée (~-125px :
   hero, tuiles mode, input, touches 56→48px = plancher tactile 48px) pour
   que le modal tienne SANS scroll à 900px de hauteur. À 768px le scroll
   interne reste possible et SÛR (footer hors du scroller). */
.cc-modal-header { margin-bottom: 8px; }
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
  /* [UIUX-W2 G6 2026-06-11] capitalisation forcée (Title Case) supprimée —
     pas une convention FR ; on garde la casse naturelle de la chaîne. */
}
.cc-modal-close {
  background: transparent;
  border: 0;
  font-size: 18px;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  cursor: pointer;
  color: var(--pos-v5-ink-soft, #5A5A5A);
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
  color: var(--pos-v5-ink-soft, #5A5A5A);
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
/* [DISPUTE-R1 B-R1-04+E-ADV-4] Chip ambre « Commande d'hier / du jj/mm/aaaa »
   — visible AVANT confirmation pour désamorcer la collision de N° de file. */
.cc-modal-order-day {
  font-size: 11px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 999px;
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #f59e0b;
  white-space: nowrap;
}

/* Hero total — mirror PaymentComponent V5 ".pos-v5-payment-total-card" */
.cc-hero-total {
  text-align: center;
  padding: 8px 12px;
  background: var(--pos-v5-surface-2, #faf6f1);
  border: 1px solid var(--pos-v5-border, #eadfd2);
  border-radius: 10px;
  margin-bottom: 10px;
}
.cc-hero-label {
  margin: 0 0 4px 0;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--pos-v5-ink-soft, #5A5A5A);
  font-weight: 600;
}
.cc-hero-value {
  margin: 0;
  font-size: 32px;
  line-height: 1.1;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: var(--pos-v5-brand-red, #cf3a3a);
  /* [UIUX-W2 G6 2026-06-11] l'ancienne police mono décorative rendait
     « 3 , 80 € » (virgule et espace pleine chasse) — police standard du modal
     + tabular-nums : chiffres à chasse fixe (stabilité pendant la saisie)
     sans casser la virgule. */
  font-family: var(--pos-v5-font-family, 'Rubik', system-ui, sans-serif);
}
@media (max-width: 480px) { .cc-hero-value { font-size: 32px; } }

/* Mode picker — same look as Wave W */
.cc-mode-section { margin-bottom: 10px; }
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
  padding: 8px 10px;
  border: 2px solid var(--pos-v5-border, #e0e0e0);
  border-radius: 10px;
  background: var(--pos-v5-surface-2, #fafafa);
  cursor: pointer;
  font-family: inherit;
  transition: transform 80ms ease, border-color 120ms ease, background 120ms ease;
  min-height: 64px;
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
.cc-mode-icon { font-size: 20px; line-height: 1; }
.cc-mode-label {
  font-size: 14px;
  font-weight: 700;
  color: var(--pos-v5-text, #1a1a1a);
}
.cc-mode-sub {
  font-size: 10.5px;
  color: var(--pos-v5-ink-soft, #5A5A5A);
  text-align: center;
}

/* Cash sub-section */
.cc-cash-section { margin-bottom: 10px; }
/* [DISPUTE-R1 ADV-F-P0-1 compaction] Touches du pavé 56→48px DANS CE MODAL
   uniquement (plancher tactile 48px respecté — réf. EAA/McDo §1) ; l'atome
   partagé PosV5Numpad reste intact pour les autres surfaces. */
.cc-cash-section :deep(.pos-v5-numpad button) { min-height: 48px; }
.cc-input-label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--pos-v5-ink-soft, #5A5A5A);
  margin-bottom: 6px;
}
.cc-input {
  width: 100%;
  padding: 8px 14px;
  border: 1.5px solid var(--pos-v5-border, #e0e0e0);
  border-radius: 8px;
  font-size: 20px;
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
  color: var(--pos-v5-ink-soft, #5A5A5A);
  line-height: 1.45;
}

/* Footer */
.cc-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  /* [DISPUTE-R1 ADV-F-P0-1 2026-06-12] L'ancien footer collant opaque
     (heal W2-G6) recouvrait les touches 7/8/9/00/0/«,» du pavé —
     « 9 » tombait sur « Confirmer & Imprimer ticket » (hit-test live).
     Le footer est désormais un enfant flex FIXE hors du scroller
     (.cc-modal-body) : jamais au-dessus du contenu, CTA toujours visible. */
  flex: 0 0 auto;
  background: var(--pos-v5-surface, #fff);
  border-top: 1px solid var(--pos-v5-border, #eee);
  padding: 10px 24px 14px;
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

/* [DISPUTE-R1 ADV-F-P0-1 compaction 2026-06-12] Viewports courts (1366×768 =
   résolution de caisse courante) : valeurs VALIDÉES LIVE par simulation DOM
   (body 664/664, 14/14 touches hit-test OK sans scroll, CTA visible).
   Touches du pavé inchangées (48px = plancher tactile). Le sous-libellé des
   modes est masqué ici uniquement (info disponible ≥820px de hauteur). */
@media (max-height: 820px) {
  .cc-modal { max-height: 96vh; }
  .cc-modal-body { padding: 12px 24px 6px; }
  .cc-modal-header { margin-bottom: 6px; }
  .cc-hero-total { padding: 6px 12px; margin-bottom: 8px; }
  .cc-hero-value { font-size: 26px; }
  .cc-section-title { margin-bottom: 6px; }
  .cc-mode-grid { gap: 8px; }
  .cc-mode-btn { min-height: 52px; padding: 6px 10px; }
  .cc-mode-sub { display: none; }
  .cc-mode-icon { font-size: 18px; }
  .cc-input-label { margin-bottom: 4px; }
  .cc-input { margin-bottom: 8px; }
  .cc-modal-footer { padding: 8px 24px 10px; }
}
</style>
