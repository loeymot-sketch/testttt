<template>
  <div class="ot-page" data-testid="order-tracking-page">
    <div class="ot-shell">
      <div class="ot-logo">Le Cayenne</div>

      <!-- [test-e2e fix C-006 round-1 2026-08-16] Sondage 8s sans notification a11y : un
           utilisateur lecteur d'écran n'était jamais informé des changements de statut/étape/
           position/temps d'attente. aria-live="polite" + role="status" sur CHACUNE des 5
           variantes de .ot-state : (a) quand le contenu texte change à l'intérieur de la MÊME
           branche déjà montée (ex: statusLabel qui avance pendant "en cours"), le live region
           annonce nativement le nouveau texte ; (b) quand v-if bascule vers une AUTRE branche
           (ex: en-cours → prête), le nouveau noeud role="status" inséré après le chargement
           initial est lui aussi annoncé par les lecteurs d'écran modernes (même mécanisme
           qu'un toast). Choisi plutôt qu'une région cachée dupliquée : la structure existante
           est déjà 1 seul wrapper actif à la fois via v-if/v-else-if, donc taguer chaque
           branche couvre 100% des transitions sans dupliquer statusLabel/meta ailleurs. -->

      <!-- Chargement initial -->
      <div v-if="loading" class="ot-state" data-testid="ot-loading" aria-live="polite" role="status">
        <div class="ot-spinner" />
        <p class="ot-hint">Recherche de votre commande…</p>
      </div>

      <!-- Lien inconnu / expiré -->
      <div v-else-if="!found" class="ot-state" data-testid="ot-not-found" aria-live="polite" role="status">
        <div class="ot-icon">🔍</div>
        <h1 class="ot-title">Commande introuvable</h1>
        <p class="ot-hint">
          Ce lien de suivi n'est plus valide. Vérifiez l'adresse ou
          rapprochez-vous du comptoir avec votre numéro de commande.
        </p>
      </div>

      <!-- Commande annulée -->
      <div v-else-if="isCancelled" class="ot-state" data-testid="ot-cancelled" aria-live="polite" role="status">
        <div class="ot-icon">✕</div>
        <h1 class="ot-title">Commande annulée</h1>
        <p class="ot-hint">{{ statusLabel }}. Pour toute question, adressez-vous au comptoir.</p>
      </div>

      <!-- Prête / livrée -->
      <div v-else-if="ready" class="ot-state ot-ready" data-testid="ot-ready" aria-live="polite" role="status">
        <div class="ot-ready-check">✓</div>
        <h1 class="ot-title">Votre commande est prête !</h1>
        <div v-if="queueNumber" class="ot-queue-number">{{ queueNumber }}</div>
        <p class="ot-hint">{{ readyHint }}</p>
      </div>

      <!-- En cours -->
      <div v-else class="ot-state" data-testid="ot-in-progress" aria-live="polite" role="status">
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
      // [test-e2e fix C-002 round-1 2026-08-16] Hook d'introspection pour les tests :
      // expose le résultat du garde-fou JSON explicite (voir _poll) pour prouver que
      // found:false découle d'une DÉCISION délibérée et pas d'un accident
      // (data.found === undefined pour une string HTML). Non lié au template, sans
      // impact UX.
      _lastPollLooksLikeJson: null,
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
        // [test-e2e fix C-002 round-1 2026-08-16] Un token malformé ne 404 pas au niveau
        // route : le catch-all SPA (routes/web.php:237) sert le shell HTML en 200 dès que
        // la contrainte regex [A-Za-z0-9]{48} rejette le segment et que la route
        // order/track/{trackingToken} ne matche pas. Avant ce fix, data.found était
        // `undefined` par ACCIDENT pour une réponse HTML (string, donc falsy) — on valide
        // maintenant EXPLICITEMENT que la réponse ressemble à du JSON réel de l'API de
        // suivi (objet non-null + Content-Type contenant "json" quand l'entête est
        // présente) avant de faire confiance à data.found. Si la réponse ne ressemble pas
        // à du JSON, on traite ça comme found:false DÉLIBÉRÉMENT — même UX "introuvable",
        // plus par hasard.
        const contentType = String(res?.headers?.['content-type'] || '').toLowerCase();
        const looksLikeJson =
          res?.data !== null &&
          typeof res?.data === 'object' &&
          (contentType === '' || contentType.includes('json'));
        const data = looksLikeJson ? res.data : {};
        this._lastPollLooksLikeJson = looksLikeJson;
        this.loading = false;
        this.found = looksLikeJson && !!data.found;
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
  /* [test-e2e fix C-003 round-1 2026-08-16] #F4501E sur fond blanc = ~3.49:1 (échoue AA
     4.5:1 texte normal 15px). #AA2E08 = même teinte/saturation assombrie (HSL L 0.54→0.35)
     → ~6.77:1, marge confortable au-dessus de 4.5:1. */
  color: #AA2E08;
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
/* [test-e2e fix C-005 round-1 2026-08-16] #2ecc71 sur blanc = 2.10:1, échoue même le
   seuil AA 3:1 texte large-gras (.ot-title = 22px/800). Aucune teinte plus sombre n'était
   déjà utilisée ailleurs dans ce composant (.ot-step.done réutilise le MÊME #2ecc71) —
   #1A7541 = même teinte/saturation assombrie (HSL L 0.49→0.28) → ~5.73:1, passe même le
   seuil texte normal 4.5:1 par prudence. */
.ot-ready .ot-title { color: #1A7541; }

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
  /* [test-e2e fix C-004 round-1 2026-08-16] #8a8a8a sur #faf5f2 = 3.19:1 (échoue AA
     4.5:1). Réutilise EXACTEMENT la couleur déjà conforme de .ot-hint (#6b6b6b) → ~4.93:1
     sur ce même fond, cohérent avec le reste du composant. */
  color: #6b6b6b;
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
