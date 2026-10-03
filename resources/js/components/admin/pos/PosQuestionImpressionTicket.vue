<!--
  [GOAL REMARQUES 2026-10-03 · R-048] « Imprimer le ticket client ? » après un encaissement fait depuis la
  page Encaissement ou le Suivi des commandes — la même question que la caisse (PosComponent) et la vente
  directe (ReceiptComponent). Jamais d'impression automatique : « je veux vraiment garder le choix ».
-->
<template>
  <div
    class="pos-question-impression"
    role="dialog"
    aria-modal="false"
    :aria-label="$t('pos.print_decision_title')"
    data-testid="question-impression"
  >
    <p class="pos-question-impression__titre">{{ $t('pos.print_decision_title') }}</p>
    <div class="pos-question-impression__actions">
      <button
        type="button"
        class="pos-question-impression__oui"
        data-testid="question-impression-oui"
        :disabled="enCours"
        @click="imprimer"
      >
        <span aria-hidden="true">🧾</span>
        {{ $t('pos.print_decision_yes') }}
      </button>
      <button
        type="button"
        class="pos-question-impression__non"
        data-testid="question-impression-non"
        :disabled="enCours"
        @click="$emit('fermer')"
      >
        {{ $t('pos.print_decision_no') }}
      </button>
    </div>
  </div>
</template>

<script>
import alertService from '../../../services/alertService';
import { imprimerTicketClient } from '../../../helpers/posImprimerTicketClient';

export default {
  name: 'PosQuestionImpressionTicket',
  props: {
    orderId: { type: [Number, String], required: true },
  },
  emits: ['fermer'],
  data() {
    return { enCours: false };
  },
  methods: {
    async imprimer() {
      if (this.enCours) return;
      this.enCours = true;
      const r = await imprimerTicketClient(this.orderId);
      this.enCours = false;
      // Un échec d'impression ne se déguise jamais en succès ; l'encaissement, lui, a réussi.
      if (r.ok) alertService.success(this.$t('pos.print_ticket_client') + ' ✓');
      else alertService.warning(this.$t('pos.print_ticket_client') + ' — impression impossible (pont ?)');
      this.$emit('fermer');
    },
  },
};
</script>

<style scoped>
.pos-question-impression {
  position: fixed;
  inset-inline-end: 1.25rem;
  bottom: 1.25rem;
  z-index: 60;
  background: #FFFFFF;
  border: 2px solid #111827;
  border-radius: 0.75rem;
  padding: 0.9rem 1rem;
  box-shadow: 0 12px 30px rgba(17, 24, 39, 0.18);
  max-width: min(92vw, 360px);
}
.pos-question-impression__titre { margin: 0 0 0.6rem; font-weight: 800; color: #111827; }
.pos-question-impression__actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.pos-question-impression__oui,
.pos-question-impression__non {
  min-height: 44px;
  padding: 0 1rem;
  border-radius: 0.5rem;
  font-weight: 700;
  cursor: pointer;
}
.pos-question-impression__oui { background: #111827; color: #FFFFFF; border: 2px solid #111827; }
.pos-question-impression__non { background: #FFFFFF; color: #111827; border: 2px solid #111827; }
.pos-question-impression__oui:disabled,
.pos-question-impression__non:disabled { opacity: 0.55; cursor: not-allowed; }
</style>
