<template>
  <div class="kiosk-loyalty-screen">

    <!-- Header -->
    <div class="kiosk-loyalty-header">
      <button class="kiosk-back-btn" @click="goBack">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <path d="M19 12H5M12 5l-7 7 7 7" stroke="currentColor" stroke-width="2.5"
            stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="kiosk-loyalty-logo">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none">
          <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77 5.82 21.02l1.18-6.88L2 9.27l6.91-1.01L12 2z"
            fill="#FFD700" stroke="#FFA500" stroke-width="1.5"/>
        </svg>
      </div>
      <h1 class="kiosk-loyalty-title">Programme Fidélité</h1>
    </div>

    <!-- Étape 1: Saisie du code -->
    <div v-if="step === 'input'" class="kiosk-loyalty-step">
      <div class="kiosk-loyalty-card">
        <p class="kiosk-loyalty-subtitle">Entrez votre code fidélité ou votre numéro de téléphone</p>

        <div class="kiosk-loyalty-input-row">
          <input
            ref="codeInput"
            v-model="code"
            type="text"
            class="kiosk-loyalty-input"
            placeholder="Ex: A1B2C3D4 ou 0612345678"
            maxlength="20"
            @keyup.enter="checkLoyalty"
          />
          <button class="kiosk-btn-clear" v-if="code" @click="code = ''">✕</button>
        </div>

        <!-- Clavier numérique tactile -->
        <div class="kiosk-numpad">
          <button
            v-for="key in numpadKeys"
            :key="key"
            class="kiosk-numpad-btn"
            :class="{ wide: key === 'del', zero: key === '0' }"
            @click="handleNumpad(key)"
          >
            <template v-if="key === 'del'">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <path d="M21 4H8l-7 8 7 8h13V4z" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M18 9l-6 6M12 9l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
            </template>
            <template v-else>{{ key }}</template>
          </button>
        </div>

        <div v-if="error" class="kiosk-loyalty-error">{{ error }}</div>

        <button
          class="kiosk-btn-primary full"
          :disabled="!code.trim() || loading"
          @click="checkLoyalty"
        >
          <span v-if="!loading">Vérifier mon code</span>
          <span v-else class="kiosk-spinner-inline"></span>
        </button>

        <button class="kiosk-loyalty-skip" @click="goBack">
          Continuer sans fidélité
        </button>

        <!-- Register new customer -->
        <button class="kiosk-loyalty-register-btn" @click="step = 'register'">
          Pas encore membre ? S'inscrire →
        </button>
      </div>
    </div>

    <!-- Étape 1b: Inscription nouveau client -->
    <div v-if="step === 'register'" class="kiosk-loyalty-step">
      <div class="kiosk-loyalty-card">
        <p class="kiosk-loyalty-subtitle">Créer votre compte fidélité</p>

        <div class="kiosk-register-fields">
          <div class="kiosk-field-group">
            <label class="kiosk-field-label">Nom *</label>
            <input
              v-model="registerName"
              type="text"
              class="kiosk-loyalty-input"
              placeholder="Votre prénom et nom"
              maxlength="60"
            />
          </div>
          <div class="kiosk-field-group">
            <label class="kiosk-field-label">Téléphone *</label>
            <input
              v-model="registerPhone"
              type="tel"
              class="kiosk-loyalty-input"
              placeholder="0600000000"
              maxlength="20"
            />
          </div>
          <div class="kiosk-field-group">
            <label class="kiosk-field-label">Email (optionnel)</label>
            <input
              v-model="registerEmail"
              type="email"
              class="kiosk-loyalty-input"
              placeholder="votre@email.fr"
              maxlength="80"
            />
          </div>
        </div>

        <div v-if="registerError" class="kiosk-loyalty-error">{{ registerError }}</div>

        <button
          class="kiosk-btn-primary full"
          :disabled="!registerName.trim() || !registerPhone.trim() || registerLoading"
          @click="submitRegister"
        >
          <span v-if="!registerLoading">Créer mon compte</span>
          <span v-else class="kiosk-spinner-inline"></span>
        </button>
        <button class="kiosk-loyalty-skip" @click="step = 'input'">← Retour</button>
      </div>
    </div>

    <!-- Étape 2: Solde et choix de rachat -->
    <div v-if="step === 'balance'" class="kiosk-loyalty-step">
      <div class="kiosk-loyalty-card">

        <!-- Profil client -->
        <div class="kiosk-loyalty-profile">
          <div class="kiosk-loyalty-avatar">
            {{ customerInitials }}
          </div>
          <div class="kiosk-loyalty-info">
            <h2>{{ customer.name }}</h2>
            <p class="kiosk-loyalty-member-since">Membre fidélité</p>
          </div>
        </div>

        <!-- Points disponibles -->
        <div class="kiosk-loyalty-points-badge">
          <span class="kiosk-loyalty-points-value">{{ customer.loyalty_point }}</span>
          <span class="kiosk-loyalty-points-label">points disponibles</span>
          <span v-if="discountValue > 0" class="kiosk-loyalty-points-equiv">
            = {{ formatPrice(Math.min(discountValue, total)) }} de réduction sur cette commande
          </span>
        </div>

        <!-- Barre de progression vers le prochain palier -->
        <div class="kiosk-loyalty-progress-wrap" v-if="nextTierPoints > 0">
          <div class="kiosk-loyalty-progress-bar">
            <div
              class="kiosk-loyalty-progress-fill"
              :style="{ width: progressPercent + '%' }"
            ></div>
          </div>
          <p class="kiosk-loyalty-progress-label">
            Plus que {{ nextTierPoints - customer.loyalty_point }} pts pour le prochain palier
          </p>
        </div>

        <!-- Options : utiliser ou pas -->
        <div v-if="canRedeem" class="kiosk-loyalty-options">
          <button
            class="kiosk-loyalty-option"
            :class="{ selected: redeemChoice === 'yes' }"
            @click="redeemChoice = 'yes'"
          >
            <div class="kiosk-loyalty-option-icon green">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                <path d="M20 7l-11 11-5-5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <div class="kiosk-loyalty-option-text">
              <strong>Utiliser mes points</strong>
              <span>-{{ formatPrice(Math.min(discountValue, total)) }} sur cette commande</span>
            </div>
          </button>

          <button
            class="kiosk-loyalty-option"
            :class="{ selected: redeemChoice === 'no' }"
            @click="redeemChoice = 'no'"
          >
            <div class="kiosk-loyalty-option-icon gray">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77 5.82 21.02l1.18-6.88L2 9.27l6.91-1.01L12 2z"
                  stroke="currentColor" stroke-width="2"/>
              </svg>
            </div>
            <div class="kiosk-loyalty-option-text">
              <strong>Garder mes points</strong>
              <span>Continuer à accumuler</span>
            </div>
          </button>
        </div>

        <div v-else class="kiosk-loyalty-not-enough">
          <p>Vous avez {{ customer.loyalty_point }} pts — il vous faut {{ minRedeemPoints }} pts minimum pour une réduction.</p>
          <p class="green">Vous allez gagner des points sur cette commande !</p>
        </div>

        <button
          class="kiosk-btn-primary full"
          @click="applyLoyalty"
          :disabled="canRedeem && !redeemChoice"
        >
          Confirmer
        </button>

        <button class="kiosk-loyalty-skip" @click="goBack">
          Annuler
        </button>
      </div>
    </div>

    <!-- Étape 3: Confirmation appliquée -->
    <div v-if="step === 'confirmed'" class="kiosk-loyalty-step">
      <div class="kiosk-loyalty-card kiosk-loyalty-confirm-card">
        <div class="kiosk-loyalty-confirm-icon">
          <svg width="64" height="64" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" fill="#22c55e"/>
            <path d="M8 12l3 3 5-5" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <h2 v-if="appliedDiscount > 0" class="kiosk-loyalty-confirm-title">
          Réduction appliquée !
        </h2>
        <h2 v-else class="kiosk-loyalty-confirm-title">
          Fidélité enregistrée
        </h2>
        <p v-if="appliedDiscount > 0" class="kiosk-loyalty-confirm-amount">
          -{{ formatPrice(appliedDiscount) }}
        </p>
        <p class="kiosk-loyalty-confirm-sub">
          {{ appliedDiscount > 0 ? 'Réduction déduite de votre total' : 'Vos points seront crédités après livraison' }}
        </p>
        <button class="kiosk-btn-primary full" @click="proceedToPayment">
          Continuer vers le paiement
        </button>
      </div>
    </div>

  </div>
</template>

<script>
import { mapActions, mapGetters } from 'vuex';
import { kioskPriceMixin } from '../../../helpers/kioskFormatPrice';
import { shouldSkipKioskUpsellScreen } from '../../../helpers/kioskUpsellFlow';
import axios from 'axios';


export default {
  name: 'KioskLoyaltyComponent',
  mixins: [kioskPriceMixin],

  inject: {
    showToast: { default: () => () => {} },
  },

  data() {
    return {
      step: 'input',
      code: '',
      loading: false,
      error: null,
      customer: null,
      discountValue: 0,
      minRedeemPoints: 100,
      redeemChoice: null,
      appliedDiscount: 0,
      numpadKeys: ['1','2','3','4','5','6','7','8','9','del','0'],
      // Register new customer
      registerName:    '',
      registerPhone:   '',
      registerEmail:   '',
      registerLoading: false,
      registerError:   null,
    };
  },

  computed: {
    ...mapGetters('kioskCart', ['total', 'upsellShown', 'items']),
    ...mapGetters('kioskMenu', ['categories']),
    shouldSkipKioskUpsell() {
      return shouldSkipKioskUpsellScreen(this.items, this.categories);
    },

    customerInitials() {
      if (!this.customer?.name) return '?';
      return this.customer.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
    },

    canRedeem() {
      return this.customer && this.customer.loyalty_point >= this.minRedeemPoints;
    },

    nextTierPoints() {
      if (!this.customer) return 0;
      const pts = this.customer.loyalty_point;
      const tiers = [100, 250, 500, 1000, 2000];
      return tiers.find(t => t > pts) || 0;
    },

    progressPercent() {
      if (!this.nextTierPoints || !this.customer) return 100;
      const prev = [0, 100, 250, 500, 1000];
      const tierIdx = [100, 250, 500, 1000, 2000].findIndex(t => t > this.customer.loyalty_point);
      const start = prev[tierIdx] || 0;
      const range = this.nextTierPoints - start;
      return Math.min(100, Math.round(((this.customer.loyalty_point - start) / range) * 100));
    },
  },

  mounted() {
    this.loadConfig();
    this.$nextTick(() => this.$refs.codeInput?.focus());
  },

  methods: {
    ...mapActions('kioskCart', ['setLoyalty', 'markUpsellShown']),

    async loadConfig() {
      try {
        const res = await axios.get('frontend/loyalty/config');
        const cfg = res.data?.data || res.data || {};
        this.minRedeemPoints = cfg.min_redeem_points || 100;
      } catch (_) {}
    },

    handleNumpad(key) {
      if (key === 'del') {
        this.code = this.code.slice(0, -1);
      } else if (this.code.length < 20) {
        this.code += key;
      }
    },

    async checkLoyalty() {
      if (!this.code.trim()) return;
      this.loading = true;
      this.error = null;
      try {
        const res = await axios.post('frontend/loyalty/check', { code: this.code.trim() });
        const data = res.data?.data || res.data || {};
        // Normalize field names: API returns `points`, UI uses `loyalty_point`
        this.customer = {
          ...data,
          loyalty_point: parseInt(data.loyalty_point ?? data.points ?? 0, 10),
        };
        this.discountValue = parseFloat(data.discount_value || 0);
        this.step = 'balance';
      } catch (err) {
        const msg = err.response?.data?.message || err.response?.data?.errors?.code?.[0];
        this.error = msg || 'Code ou numéro introuvable. Vérifiez et réessayez.';
      } finally {
        this.loading = false;
      }
    },

    async applyLoyalty() {
      if (this.canRedeem && this.redeemChoice === 'yes') {
        this.appliedDiscount = Math.min(this.discountValue, this.total);
        await this.setLoyalty({ customer: this.customer, discount: this.appliedDiscount });
        this.showToast(`Réduction de ${this.formatPrice(this.appliedDiscount)} appliquée !`, 'success', 3000);
      } else {
        await this.setLoyalty({ customer: this.customer, discount: 0 });
        this.appliedDiscount = 0;
        this.showToast('Fidélité enregistrée — points crédités après commande', 'info', 3000);
      }
      this.step = 'confirmed';
    },

    async submitRegister() {
      if (!this.registerName.trim() || !this.registerPhone.trim()) return;
      this.registerLoading = true;
      this.registerError = null;
      try {
        const res = await axios.post('frontend/loyalty/register', {
          name:  this.registerName.trim(),
          phone: this.registerPhone.trim(),
          email: this.registerEmail.trim() || undefined,
        });
        const data = res.data?.data || {};
        // Registration succeeded — immediately show balance screen with new account
        this.customer = {
          name:          data.name || this.registerName,
          loyalty_point: parseInt(data.points ?? 0, 10),
          loyalty_code:  data.loyalty_code || '',
        };
        this.discountValue = 0; // New member: 0 points, no discount yet
        this.code = data.loyalty_code || '';
        this.showToast(`Bienvenue ${this.customer.name} ! Compte fidélité créé.`, 'success', 3500);
        this.step = 'balance';
      } catch (err) {
        const msg = err.response?.data?.message || 'Inscription impossible. Réessayez.';
        this.registerError = msg;
      } finally {
        this.registerLoading = false;
      }
    },

    proceedToPayment() {
      // Same routing as KioskCartComponent::proceedToUpsell (category skip + upsell once per session).
      if (this.upsellShown) {
        this.$router.push({ name: 'kiosk.payment' });
        return;
      }
      this.markUpsellShown();
      if (this.shouldSkipKioskUpsell) {
        this.$router.push({ name: 'kiosk.payment' });
        return;
      }
      this.$router.push({ name: 'kiosk.upsell' });
    },

    goBack() {
      this.$router.push({ name: 'kiosk.cart' });
    },

    // formatPrice() provided by kioskPriceMixin
  },
};
</script>

<style scoped>
.kiosk-loyalty-screen {
  min-height: 100vh;
  background: linear-gradient(160deg, #0f0f1a 0%, #1a1a2e 60%, #16213e 100%);
  display: flex;
  flex-direction: column;
  color: #fff;
  padding-bottom: 2rem;
}

.kiosk-loyalty-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.5rem 2rem 1rem;
  border-bottom: 1px solid rgba(255,255,255,0.08);
}

.kiosk-back-btn {
  background: rgba(255,255,255,0.08);
  border: none;
  border-radius: 12px;
  width: 52px;
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: #fff;
  transition: background 0.2s;
}
.kiosk-back-btn:hover { background: rgba(255,255,255,0.14); }

.kiosk-loyalty-title {
  font-size: 1.6rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.kiosk-loyalty-step {
  flex: 1;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 2rem 1.5rem;
}

.kiosk-loyalty-card {
  width: 100%;
  max-width: 540px;
  background: rgba(255,255,255,0.04);
  border-radius: 24px;
  padding: 2rem;
  border: 1px solid rgba(255,255,255,0.08);
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.kiosk-loyalty-subtitle {
  font-size: 1.1rem;
  color: rgba(255,255,255,0.6);
  text-align: center;
}

.kiosk-loyalty-input-row {
  position: relative;
}

.kiosk-loyalty-input {
  width: 100%;
  background: rgba(255,255,255,0.08);
  border: 2px solid rgba(255,255,255,0.15);
  border-radius: 14px;
  padding: 1rem 3rem 1rem 1.25rem;
  font-size: 1.5rem;
  color: #fff;
  text-align: center;
  letter-spacing: 0.1em;
  outline: none;
  transition: border-color 0.2s;
  box-sizing: border-box;
}
.kiosk-loyalty-input:focus {
  border-color: #FFD700;
}
.kiosk-loyalty-input::placeholder { color: rgba(255,255,255,0.3); }

.kiosk-btn-clear {
  position: absolute;
  right: 1rem;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: rgba(255,255,255,0.5);
  font-size: 1.2rem;
  cursor: pointer;
  padding: 0.25rem;
}

/* Numpad */
.kiosk-numpad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.75rem;
}

.kiosk-numpad-btn {
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 14px;
  height: 64px;
  font-size: 1.5rem;
  font-weight: 600;
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, transform 0.1s;
  user-select: none;
}
.kiosk-numpad-btn:active {
  background: rgba(255,215,0,0.2);
  transform: scale(0.95);
}
.kiosk-numpad-btn.wide {
  background: rgba(255,100,100,0.15);
}
.kiosk-numpad-btn.wide:active {
  background: rgba(255,100,100,0.3);
}

.kiosk-loyalty-error {
  background: rgba(220,38,38,0.15);
  border: 1px solid rgba(220,38,38,0.4);
  border-radius: 10px;
  padding: 0.75rem 1rem;
  color: #fca5a5;
  text-align: center;
  font-size: 0.95rem;
}

.kiosk-btn-primary {
  background: linear-gradient(135deg, #FFD700, #FFA500);
  color: #000;
  border: none;
  border-radius: 16px;
  padding: 1rem 2rem;
  font-size: 1.1rem;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  transition: opacity 0.2s, transform 0.1s;
}
.kiosk-btn-primary:disabled {
  opacity: 0.4;
  cursor: default;
}
.kiosk-btn-primary.full { width: 100%; }
.kiosk-btn-primary:not(:disabled):active { transform: scale(0.97); }

.kiosk-loyalty-skip {
  background: none;
  border: none;
  color: rgba(255,255,255,0.4);
  font-size: 0.95rem;
  text-decoration: underline;
  cursor: pointer;
  text-align: center;
  padding: 0.5rem;
}

.kiosk-loyalty-register-btn {
  background: none;
  border: 1px solid rgba(255,215,0,0.25);
  border-radius: 12px;
  color: rgba(255,215,0,0.6);
  font-size: 0.9rem;
  padding: 0.6rem 1rem;
  cursor: pointer;
  text-align: center;
  transition: border-color 0.2s, color 0.2s;
}
.kiosk-loyalty-register-btn:hover { border-color: rgba(255,215,0,0.5); color: #FFD700; }

.kiosk-register-fields {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.kiosk-field-group {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.kiosk-field-label {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(255,255,255,0.45);
}

.kiosk-spinner-inline {
  display: inline-block;
  width: 20px;
  height: 20px;
  border: 3px solid rgba(0,0,0,0.3);
  border-top-color: #000;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Profil client */
.kiosk-loyalty-profile {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.kiosk-loyalty-avatar {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: linear-gradient(135deg, #FFD700, #FFA500);
  color: #000;
  font-size: 1.4rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.kiosk-loyalty-info h2 {
  font-size: 1.3rem;
  font-weight: 700;
  margin: 0;
}
.kiosk-loyalty-member-since {
  color: rgba(255,255,255,0.5);
  font-size: 0.85rem;
  margin: 0.15rem 0 0;
}

/* Points badge */
.kiosk-loyalty-points-badge {
  background: linear-gradient(135deg, rgba(255,215,0,0.15), rgba(255,165,0,0.1));
  border: 1px solid rgba(255,215,0,0.3);
  border-radius: 16px;
  padding: 1.25rem;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.kiosk-loyalty-points-value {
  font-size: 3rem;
  font-weight: 900;
  color: #FFD700;
  line-height: 1;
}
.kiosk-loyalty-points-label {
  color: rgba(255,255,255,0.6);
  font-size: 0.9rem;
}
.kiosk-loyalty-points-equiv {
  font-size: 1rem;
  font-weight: 600;
  color: #4ade80;
  margin-top: 0.25rem;
}

/* Progress bar */
.kiosk-loyalty-progress-wrap {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.kiosk-loyalty-progress-bar {
  height: 8px;
  background: rgba(255,255,255,0.1);
  border-radius: 4px;
  overflow: hidden;
}
.kiosk-loyalty-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #FFD700, #FFA500);
  border-radius: 4px;
  transition: width 0.6s ease;
}
.kiosk-loyalty-progress-label {
  font-size: 0.8rem;
  color: rgba(255,255,255,0.4);
  text-align: center;
  margin: 0;
}

/* Options */
.kiosk-loyalty-options {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.kiosk-loyalty-option {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.25rem;
  background: rgba(255,255,255,0.05);
  border: 2px solid rgba(255,255,255,0.1);
  border-radius: 16px;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.2s, background 0.2s;
  color: #fff;
  width: 100%;
}
.kiosk-loyalty-option.selected {
  border-color: #FFD700;
  background: rgba(255,215,0,0.1);
}
.kiosk-loyalty-option-icon {
  width: 52px;
  height: 52px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.kiosk-loyalty-option-icon.green { background: rgba(74,222,128,0.15); color: #4ade80; }
.kiosk-loyalty-option-icon.gray  { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.5); }
.kiosk-loyalty-option-text {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}
.kiosk-loyalty-option-text strong { font-size: 1rem; font-weight: 700; }
.kiosk-loyalty-option-text span   { font-size: 0.85rem; color: rgba(255,255,255,0.5); }
.kiosk-loyalty-option.selected .kiosk-loyalty-option-text span { color: rgba(255,215,0,0.7); }

.kiosk-loyalty-not-enough {
  background: rgba(255,255,255,0.04);
  border-radius: 12px;
  padding: 1rem;
  font-size: 0.95rem;
  color: rgba(255,255,255,0.6);
  text-align: center;
  line-height: 1.6;
}
.green { color: #4ade80; }

/* Confirmation step */
.kiosk-loyalty-confirm-card {
  align-items: center;
  text-align: center;
  padding: 3rem 2rem;
}
.kiosk-loyalty-confirm-icon {
  margin-bottom: 1rem;
}
.kiosk-loyalty-confirm-title {
  font-size: 1.8rem;
  font-weight: 800;
  margin: 0;
}
.kiosk-loyalty-confirm-amount {
  font-size: 3rem;
  font-weight: 900;
  color: #4ade80;
  margin: 0.5rem 0;
}
.kiosk-loyalty-confirm-sub {
  color: rgba(255,255,255,0.5);
  font-size: 1rem;
  margin-bottom: 1rem;
}
</style>
