<template>
  <div class="ot-page" data-testid="order-tracking-page">
    <div class="ot-shell">
      <div class="ot-logo">Le Cayenne</div>

      <!-- Chargement initial -->
      <div v-if="loading" class="ot-state" data-testid="ot-loading">
        <div class="ot-spinner" />
        <p class="ot-hint">Recherche de votre commande…</p>
      </div>

      <!-- Lien inconnu / expiré -->
      <div v-else-if="!found" class="ot-state" data-testid="ot-not-found">
        <div class="ot-icon">🔍</div>
        <h1 class="ot-title">Commande introuvable</h1>
        <p class="ot-hint">
          Ce lien de suivi n'est plus valide. Vérifiez l'adresse ou
          rapprochez-vous du comptoir avec votre numéro de commande.
        </p>
      </div>

      <!-- Commande annulée -->
      <div v-else-if="isCancelled" class="ot-state" data-testid="ot-cancelled">
        <div class="ot-icon">✕</div>
        <h1 class="ot-title">Commande annulée</h1>
        <p class="ot-hint">{{ statusLabel }}. Pour toute question, adressez-vous au comptoir.</p>
      </div>

      <!-- Prête / livrée -->
      <div v-else-if="ready" class="ot-state ot-ready" data-testid="ot-ready">
        <div class="ot-ready-check">✓</div>
        <h1 class="ot-title">Votre commande est prête !</h1>
        <div v-if="queueNumber" class="ot-queue-number">{{ queueNumber }}</div>
        <p class="ot-hint">{{ readyHint }}</p>
      </div>

      <!-- En cours -->
      <div v-else class="ot-state" data-testid="ot-in-progress">
        <h1 class="ot-title">Votre commande est en cours</h1>
        <div v-if="queueNumber" class="ot-queue-number">{{ queueNumber }}</div>

        <!-- Étapes -->
        <div class="ot-steps" role="list">
          <div
            v-for="s in steps"
            :key="s.n"
            class="ot-step"
            :class="{ done: s.n < step, active: s.n === step }"
            role="listitem"
          >
            <span class="ot-step-dot">{{ s.n < step ? '✓' : s.n }}</span>
            <span class="ot-step-label">{{ s.label }}</span>
          </div>
        </div>

        <p class="ot-status-label">{{ statusLabel }}</p>

        <!-- Bientôt prête -->
        <div v-if="almostReady" class="ot-almost-ready" data-testid="ot-almost-ready">
          🔥 Presque prête — vous êtes parmi les prochaines commandes servies !
        </div>

        <!-- Position + temps -->
        <div v-else class="ot-meta">
          <div v-if="positionAhead !== null" class="ot-meta-item">
            <span class="ot-meta-value">{{ positionAhead }}</span>
            <span class="ot-meta-label">commande{{ positionAhead > 1 ? 's' : '' }} avant vous</span>
          </div>
          <div v-if="waitLow !== null && waitHigh !== null" class="ot-meta-item">
            <span class="ot-meta-value">{{ waitLow }}-{{ waitHigh }} min</span>
            <span class="ot-meta-label">temps d'attente estimé</span>
          </div>
        </div>
      </div>

      <div v-if="networkLost" class="ot-network-banner" data-testid="ot-network-banner">
        📡 Connexion instable — dernière position connue affichée.
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

// [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Page publique consommée depuis le
// téléphone du client (lien/QR remis à la borne) — sondage léger, pas de temps
// réel serveur en prod (cf. doctrine sondage 5-60s des écrans publics). Throttle
// backend = 30/min ; 8s laisse une marge large.
const POLL_INTERVAL_MS = 8000;
const STEP_DEFS = [
  { n: 1, label: 'Reçue' },
  { n: 2, label: 'Acceptée' },
  { n: 3, label: 'En préparation' },
  { n: 4, label: 'Prête' },
];

export default {
  name: 'OrderTrackingPageComponent',
  props: {
    trackingToken: { type: String, required: true },
  },
  data() {
    return {
      loading: true,
      found: false,
      status: null,
      statusLabel: '',
      step: 1,
      queueNumber: null,
      positionAhead: null,
      almostReady: false,
      ready: false,
      waitLow: null,
      waitHigh: null,
      steps: STEP_DEFS,
      pollTimer: null,
      pollFailCount: 0,
      networkLost: false,
      _pollInFlight: false,
    };
  },
  computed: {
    isCancelled() {
      // step=0 est le codage partagé avec le backend pour Annulée/Refusée (OrderTrackingService::stepAndLabel).
      return this.step === 0;
    },
    readyHint() {
      return this.status === 13 // DELIVERED (générique "remise/terminée")
        ? 'Bon appétit !'
        : 'Vous pouvez venir la récupérer au comptoir.';
    },
  },
  mounted() {
    this._poll();
    this.pollTimer = setInterval(() => this._poll(), POLL_INTERVAL_MS);
  },
  beforeUnmount() {
    if (this.pollTimer) clearInterval(this.pollTimer);
  },
  methods: {
    async _poll() {
      if (this.ready || this.isCancelled) {
        if (this.pollTimer) clearInterval(this.pollTimer);
        return;
      }
      if (this._pollInFlight) return;
      this._pollInFlight = true;
      try {
        const res = await axios.get(`frontend/order/track/${this.trackingToken}`);
        const data = res?.data || {};
        this.loading = false;
        this.found = !!data.found;
        if (this.found) {
          this.status = data.status;
          this.statusLabel = data.status_label || '';
          this.step = data.step ?? 1;
          this.queueNumber = data.queue_number || null;
          this.positionAhead = data.position_ahead ?? null;
          this.almostReady = !!data.almost_ready;
          this.ready = !!data.ready;
          this.waitLow = data.wait_low ?? null;
          this.waitHigh = data.wait_high ?? null;
        }
        this.pollFailCount = 0;
        this.networkLost = false;
      } catch (_e) {
        this.loading = false;
        this.pollFailCount += 1;
        if (this.pollFailCount >= 3) this.networkLost = true;
      } finally {
        this._pollInFlight = false;
      }
    },
  },
};
</script>

<style scoped>
.ot-page {
  min-height: 100vh;
  background: #1A1A1A;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
  box-sizing: border-box;
}

.ot-shell {
  width: 100%;
  max-width: 420px;
  background: #ffffff;
  border-radius: 24px;
  padding: 32px 24px 40px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
}

.ot-logo {
  text-align: center;
  font-weight: 900;
  font-size: 20px;
  letter-spacing: 1px;
  color: #F4501E;
  margin-bottom: 24px;
}

.ot-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 12px;
}

.ot-icon { font-size: 48px; }

.ot-title {
  font-size: 22px;
  font-weight: 800;
  color: #1A1A1A;
  margin: 4px 0 0;
}

.ot-hint {
  font-size: 15px;
  color: #6b6b6b;
  margin: 0;
  line-height: 1.5;
}

.ot-status-label {
  font-size: 15px;
  font-weight: 700;
  color: #F4501E;
  margin: 8px 0 0;
}

.ot-queue-number {
  font-size: 48px;
  font-weight: 900;
  color: #F4501E;
  letter-spacing: -1px;
  margin: 4px 0;
}

.ot-spinner {
  width: 40px;
  height: 40px;
  border: 3px solid #f0d9d2;
  border-top-color: #F4501E;
  border-radius: 50%;
  animation: ot-spin 0.9s linear infinite;
}
@keyframes ot-spin { to { transform: rotate(360deg); } }

.ot-ready-check {
  width: 88px;
  height: 88px;
  border-radius: 50%;
  background: #2ecc71;
  color: #fff;
  font-size: 44px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 0 40px rgba(46, 204, 113, 0.4);
}
.ot-ready .ot-title { color: #2ecc71; }

.ot-steps {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 16px 0 4px;
}

.ot-step {
  display: flex;
  align-items: center;
  gap: 12px;
  opacity: 0.4;
}
.ot-step.done, .ot-step.active { opacity: 1; }

.ot-step-dot {
  width: 28px;
  height: 28px;
  min-width: 28px;
  border-radius: 50%;
  background: #eee;
  color: #888;
  font-size: 13px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}
.ot-step.done .ot-step-dot { background: #2ecc71; color: #fff; }
.ot-step.active .ot-step-dot {
  background: #F4501E;
  color: #fff;
  box-shadow: 0 0 0 4px rgba(244, 80, 30, 0.15);
}

.ot-step-label {
  font-size: 15px;
  font-weight: 600;
  color: #1A1A1A;
  text-align: left;
}

.ot-almost-ready {
  background: #fff4e5;
  border: 1px solid #FFB800;
  border-radius: 14px;
  padding: 12px 16px;
  font-size: 14px;
  font-weight: 700;
  color: #8a5a00;
  text-align: center;
  margin-top: 8px;
}

.ot-meta {
  display: flex;
  gap: 12px;
  width: 100%;
  margin-top: 8px;
}

.ot-meta-item {
  flex: 1;
  background: #faf5f2;
  border-radius: 14px;
  padding: 12px 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.ot-meta-value {
  font-size: 20px;
  font-weight: 900;
  color: #1A1A1A;
}

.ot-meta-label {
  font-size: 11px;
  color: #8a8a8a;
  text-align: center;
  line-height: 1.3;
}

.ot-network-banner {
  margin-top: 20px;
  background: #fdecea;
  color: #d32f2f;
  border-radius: 12px;
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 600;
  text-align: center;
}
</style>
