<template>
  <div class="kiosk-loyalty-screen">

    <!-- Header -->
    <div class="kiosk-loyalty-header">
      <button type="button" class="kiosk-back-btn" @click="goBack">
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
      <h1 class="kiosk-loyalty-title">{{ $t('kiosk.loyalty_screen.title') }}</h1>
    </div>

    <!-- Étape 1: Saisie du code -->
    <div v-if="step === 'input'" class="kiosk-loyalty-step">
      <div class="kiosk-loyalty-card">
        <p class="kiosk-loyalty-subtitle">{{ $t('kiosk.loyalty_screen.input_sub') }}</p>

        <div class="kiosk-loyalty-input-row">
          <input
            ref="codeInput"
            v-model="code"
            type="text"
            class="kiosk-loyalty-input"
            :placeholder="$t('kiosk.loyalty_screen.code_placeholder')"
            maxlength="20"
            @keyup.enter="checkLoyalty"
          />
          <button type="button" class="kiosk-btn-clear" v-if="code" @click="code = ''">✕</button>
        </div>

        <!-- Clavier numérique tactile -->
        <div class="kiosk-numpad">
          <button type="button"
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

        <button type="button"
          class="kiosk-btn-primary full"
          :disabled="!code.trim() || loading"
          @click="checkLoyalty"
        >
          <span v-if="!loading">{{ $t('kiosk.loyalty_screen.verify_btn') }}</span>
          <span v-else class="kiosk-spinner-inline"></span>
        </button>

        <button type="button" class="kiosk-loyalty-skip" @click="goBack">
          {{ $t('kiosk.loyalty_screen.skip') }}
        </button>

        <!-- Register new customer -->
        <button type="button" class="kiosk-loyalty-register-btn" @click="step = 'register'">
          {{ $t('kiosk.loyalty_screen.register_cta') }}
        </button>
      </div>
    </div>

    <!-- Étape 1b: Inscription nouveau client -->
    <div v-if="step === 'register'" class="kiosk-loyalty-step">
      <div class="kiosk-loyalty-card">
        <p class="kiosk-loyalty-subtitle">{{ $t('kiosk.loyalty_screen.register_title') }}</p>

        <div class="kiosk-register-fields">
          <div class="kiosk-field-group">
            <label class="kiosk-field-label">{{ $t('kiosk.loyalty_screen.label_name') }}</label>
            <input
              v-model="registerName"
              type="text"
              class="kiosk-loyalty-input"
              :class="{ 'kiosk-loyalty-input--active': vkeybActiveField === 'registerName' }"
              :placeholder="$t('kiosk.loyalty_screen.placeholder_name')"
              maxlength="60"
              readonly
              data-testid="kiosk-loyalty-register-name"
              @focus="onFocusRegisterField('registerName')"
              @click="onFocusRegisterField('registerName')"
            />
          </div>
          <div class="kiosk-field-group">
            <label class="kiosk-field-label">{{ $t('kiosk.loyalty_screen.label_phone') }}</label>
            <input
              v-model="registerPhone"
              type="tel"
              class="kiosk-loyalty-input"
              :class="{ 'kiosk-loyalty-input--active': vkeybActiveField === 'registerPhone' }"
              :placeholder="$t('kiosk.loyalty_screen.placeholder_phone')"
              maxlength="20"
              readonly
              data-testid="kiosk-loyalty-register-phone"
              @focus="onFocusRegisterField('registerPhone')"
              @click="onFocusRegisterField('registerPhone')"
            />
          </div>
          <div class="kiosk-field-group">
            <label class="kiosk-field-label">{{ $t('kiosk.loyalty_screen.label_email') }}</label>
            <input
              v-model="registerEmail"
              type="email"
              class="kiosk-loyalty-input"
              :class="{ 'kiosk-loyalty-input--active': vkeybActiveField === 'registerEmail' }"
              :placeholder="$t('kiosk.loyalty_screen.placeholder_email')"
              maxlength="80"
              readonly
              data-testid="kiosk-loyalty-register-email"
              @focus="onFocusRegisterField('registerEmail')"
              @click="onFocusRegisterField('registerEmail')"
            />
          </div>
        </div>

        <div v-if="registerError" class="kiosk-loyalty-error">{{ registerError }}</div>

        <button type="button"
          class="kiosk-btn-primary full"
          :disabled="!registerName.trim() || !registerPhone.trim() || registerLoading"
          @click="submitRegister"
        >
          <span v-if="!registerLoading">{{ $t('kiosk.loyalty_screen.register_submit') }}</span>
          <span v-else class="kiosk-spinner-inline"></span>
        </button>
        <button type="button" class="kiosk-loyalty-skip" @click="step = 'input'">← {{ $t('kiosk.loyalty_screen.back') }}</button>
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
            <p class="kiosk-loyalty-member-since">{{ $t('kiosk.loyalty_screen.member_badge') }}</p>
          </div>
        </div>

        <!-- Points disponibles — le solde brut reste toujours affiché (consultation
             lecture seule). [C39 heal 2026-07-06] L'équivalence « = X € de réduction sur
             cette commande » est une PROMESSE de remise : gatée par kioskPromoEnabled,
             sinon elle annonce une réduction que la borne n'applique jamais (flag OFF). -->
        <div class="kiosk-loyalty-points-badge">
          <span class="kiosk-loyalty-points-value">{{ customer.loyalty_point }}</span>
          <span class="kiosk-loyalty-points-label">{{ $t('kiosk.loyalty_screen.points_label') }}</span>
          <span v-if="discountValue > 0 && discountsEnabled && kioskPromoEnabled" class="kiosk-loyalty-points-equiv" data-testid="kiosk-loyalty-points-equiv">
            {{ $t('kiosk.loyalty_screen.points_equiv', { amount: formatPrice(Math.min(discountValue, total)) }) }}
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
            {{ $t('kiosk.loyalty_screen.tier_progress', { n: nextTierPoints - customer.loyalty_point }) }}
          </p>
        </div>

        <!-- Options : utiliser ou pas — [FIX P2 audit 2026-06-28] uniquement si les
             remises sont activées (sinon /loyalty/redeem renvoie 422 → cul-de-sac).
             En V1 earn-only (remises OFF), on n'affiche PAS l'option « utiliser ».
             [C39 heal 2026-07-06] + gate borne dédié kioskPromoEnabled : la borne
             n'envoie jamais le discount au payload (loyalty_code seul), donc afficher
             « utiliser mes points » = promesse « -X € » jamais tenue (client débité
             plein tarif). Même couple de flags que le bloc promo du panier. -->
        <div v-if="canRedeem && discountsEnabled && kioskPromoEnabled" class="kiosk-loyalty-options" data-testid="kiosk-loyalty-redeem-options">
          <button type="button"
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
              <strong>{{ $t('kiosk.loyalty_screen.redeem_use') }}</strong>
              <span>{{ $t('kiosk.loyalty_screen.redeem_use_sub', { amount: formatPrice(Math.min(discountValue, total)) }) }}</span>
            </div>
          </button>

          <button type="button"
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
              <strong>{{ $t('kiosk.loyalty_screen.redeem_keep') }}</strong>
              <span>{{ $t('kiosk.loyalty_screen.redeem_keep_sub') }}</span>
            </div>
          </button>
        </div>

        <div v-else-if="!canRedeem" class="kiosk-loyalty-not-enough">
          <p>{{ $t('kiosk.loyalty_screen.not_enough', { current: customer.loyalty_point, min: minRedeemPoints }) }}</p>
          <p class="green">{{ $t('kiosk.loyalty_screen.not_enough_green') }}</p>
        </div>

        <!-- Earn-only (remises désactivées en V1) : on montre le SOLDE, pas « pas assez ». -->
        <div v-else class="kiosk-loyalty-not-enough">
          <p>Vous avez {{ customer.loyalty_point }} points fidélité 🎉</p>
          <p class="green">{{ $t('kiosk.loyalty_screen.not_enough_green') }}</p>
        </div>

        <button type="button"
          class="kiosk-btn-primary full"
          @click="applyLoyalty"
          :disabled="canRedeem && discountsEnabled && kioskPromoEnabled && !redeemChoice"
        >
          {{ $t('kiosk.loyalty_screen.confirm') }}
        </button>

        <button type="button" class="kiosk-loyalty-skip" @click="goBack">
          {{ $t('kiosk.loyalty_screen.cancel') }}
        </button>
      </div>
    </div>

    <!-- [PHASE-6.3] RGPD consent modal — s'ouvre avant register (qui persiste des PII).
         Kiosk Phase 9.1.10 — KsConsentModal émet `accepted` / `declined` (past
         tense, cf. `emits: ['update:modelValue', 'accepted', 'declined', 'error']`).
         Avant ce fix, le parent écoutait `@accept` / `@decline` (infinitif), donc
         le handler n'était JAMAIS appelé : la modale semblait "accepter" mais
         `submitRegister` n'était jamais exécuté côté loyalty (RGPD broken). -->
    <KsConsentModal
      :model-value="showConsentModal"
      @accepted="onConsentAccept"
      @declined="onConsentDecline"
      @update:model-value="(v) => { if (!v) showConsentModal = false; }"
    />

    <!-- Kiosk Phase 9.1.7 — clavier virtuel monté hors du flex,
         fixed bottom. Uniquement visible quand un champ register est actif.
         Le numpad du code fidélité n'est pas impacté (UX dédié). -->
    <KsVirtualKeyboard
      :model-value="vkeybValue"
      :visible="vkeybActiveField !== null"
      :layout="vkeybLayout"
      :allow-space="vkeybAllowSpace"
      :max-length="vkeybMaxLength"
      :show-preview="true"
      @update:model-value="onVkeybInput"
      @submit="onVkeybSubmit"
      @close="onVkeybClose"
    />

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
          {{ $t('kiosk.loyalty_screen.confirm_discount_title') }}
        </h2>
        <h2 v-else class="kiosk-loyalty-confirm-title">
          {{ $t('kiosk.loyalty_screen.confirm_saved_title') }}
        </h2>
        <p v-if="appliedDiscount > 0" class="kiosk-loyalty-confirm-amount">
          -{{ formatPrice(appliedDiscount) }}
        </p>
        <p class="kiosk-loyalty-confirm-sub">
          {{ appliedDiscount > 0 ? $t('kiosk.loyalty_screen.confirm_discount_sub') : $t('kiosk.loyalty_screen.confirm_saved_sub') }}
        </p>
        <button type="button" class="kiosk-btn-primary full" @click="proceedToPayment">
          {{ $t('kiosk.loyalty_screen.continue_payment') }}
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
// [PHASE-6.3] RGPD — modale de consentement loyalty + analytics injectée
//             juste avant l'appel API `frontend/loyalty/register` qui persiste les PII.
import KsConsentModal from './ds/KsConsentModal.vue';
// Kiosk Phase 9.1.7 — clavier virtuel maison pour les inputs loyalty register.
// Les bornes Windows kiosk désactivent TabTip → sans ce composant, les champs
// name/phone/email sont inutilisables. Le numpad pour le code reste inchangé
// (UX dédié aux codes fidélité numériques courts).
import KsVirtualKeyboard from './ds/KsVirtualKeyboard.vue';
// [PHASE-6.4] Instrumentation analytics (gated par consent).
import kioskAnalytics from '../../../helpers/kioskAnalytics';


export default {
  name: 'KioskLoyaltyComponent',
  mixins: [kioskPriceMixin],
  components: { KsConsentModal, KsVirtualKeyboard },

  inject: {
    showToast: { default: () => () => {} },
  },

  // [MEGA 2.E / F-09] Hard cap on loyalty check latency (QR/keyboard path uses same API).
  LOYALTY_HTTP_TIMEOUT_MS: 25000,

  data() {
    return {
      step: 'input',
      code: '',
      loading: false,
      error: null,
      customer: null,
      discountValue: 0,
      minRedeemPoints: 100,
      rewardTiers: [100, 250, 500, 1000, 2000],
      redeemChoice: null,
      appliedDiscount: 0,
      numpadKeys: ['1','2','3','4','5','6','7','8','9','del','0'],
      // Register new customer
      registerName:    '',
      registerPhone:   '',
      registerEmail:   '',
      registerLoading: false,
      registerError:   null,
      // [PHASE-6.3] RGPD consent state
      showConsentModal: false,
      _pendingRegister: null,
      // Kiosk Phase 9.1.7 — état clavier virtuel.
      // `vkeybActiveField` = clé du champ actuellement édité ('registerName'
      // | 'registerPhone' | 'registerEmail'). null → clavier masqué.
      vkeybActiveField: null,
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
    // [FIX P2 audit 2026-06-28] Même flag que le panier (window.foodkingConfig.
    // discountsEnabled = config pos.manual_discount_enabled, défaut false). Si OFF,
    // le backend rejette toute remise (422) → on masque l'option « utiliser mes points ».
    discountsEnabled() {
      return typeof window !== 'undefined'
        && window.foodkingConfig
        && window.foodkingConfig.discountsEnabled === true;
    },
    /**
     * [C39 heal 2026-07-06] Gate DÉDIÉ borne (mirror EXACT de
     * KioskCartComponent.kioskPromoEnabled). Le W2 audit (2026-06-26) avait gaté
     * le bloc PROMO + le bouton fidélité DU PANIER derrière ce flag, mais PAS
     * l'écran de redeem fidélité atteignable via « Mon compte »
     * (KioskCategoriesComponent → kiosk.loyalty). Résultat : `discountsEnabled`
     * seul reste TRUE en prod (il pilote la remise manuelle POS + le web), donc
     * l'option « utiliser mes points » s'affichait, posait `loyaltyDiscount` dans
     * le store → panier « -X € » MAIS le payload borne n'envoie jamais ce discount
     * (buildKioskQuotePayload → loyalty_code seul, le serveur early-return) → le
     * client était débité PLEIN TARIF. On aligne donc le redeem fidélité sur le
     * MÊME couple de flags que la promo : `discountsEnabled && kioskPromoEnabled`.
     * Défaut FALSE (config kiosk.promo_enabled) → caché = aucune promesse non tenue.
     * La consultation du solde de points reste (lecture seule) ; seul le redeem
     * qui affiche une remise non appliquée est gaté.
     */
    kioskPromoEnabled() {
      return (typeof window !== 'undefined' && window.foodkingConfig)
        ? window.foodkingConfig.kioskPromoEnabled === true
        : false;
    },

    nextTierPoints() {
      if (!this.customer) return 0;
      const pts = this.customer.loyalty_point;
      return this.rewardTiers.find(t => t > pts) || 0;
    },

    progressPercent() {
      if (!this.nextTierPoints || !this.customer) return 100;
      const prev = [0, ...this.rewardTiers];
      const tierIdx = this.rewardTiers.findIndex(t => t > this.customer.loyalty_point);
      const start = prev[tierIdx] || 0;
      const range = this.nextTierPoints - start;
      return Math.min(100, Math.round(((this.customer.loyalty_point - start) / range) * 100));
    },

    // Kiosk Phase 9.1.7 — layout clavier virtuel basé sur la locale borne
    // (kioskSettings.locale). Toujours fallback 'fr'.
    vkeybLayout() {
      const loc = this.$store.state.kioskSettings?.locale;
      if (loc === 'en' || loc === 'ar' || loc === 'fr') return loc;
      return 'fr';
    },
    // Kiosk Phase 9.1.7 — valeur pilotée par le champ actif. Renvoie '' si
    // aucun champ n'est édité (le clavier est alors masqué via `visible`).
    vkeybValue() {
      const f = this.vkeybActiveField;
      if (f === 'registerName') return this.registerName;
      if (f === 'registerPhone') return this.registerPhone;
      if (f === 'registerEmail') return this.registerEmail;
      return '';
    },
    // Kiosk Phase 9.1.7 — interdit l'espace pour email (format RFC) et pour
    // phone (format E.164 strict, l'utilisateur peut taper +, chiffres,
    // tirets via clavier mais JAMAIS d'espace).
    vkeybAllowSpace() {
      return this.vkeybActiveField === 'registerName';
    },
    // Kiosk Phase 9.1.7 — maxLength alignée sur les attributs `maxlength`
    // posés sur les <input> pour rester cohérent si un utilisateur mixe
    // clavier virtuel + numpad matériel (hyp: admin).
    vkeybMaxLength() {
      if (this.vkeybActiveField === 'registerName') return 60;
      if (this.vkeybActiveField === 'registerEmail') return 80;
      if (this.vkeybActiveField === 'registerPhone') return 20;
      return 200;
    },
  },

  mounted() {
    this.loadConfig();
    this.$nextTick(() => this.$refs.codeInput?.focus());
  },

  methods: {
    ...mapActions('kioskCart', ['setLoyalty', 'markUpsellShown']),

    async loadConfig() {
      const ms = this.$options.LOYALTY_HTTP_TIMEOUT_MS;
      try {
        const res = await axios.get('frontend/loyalty/config', { timeout: ms });
        const cfg = res.data?.data || res.data || {};
        this.minRedeemPoints = cfg.min_redeem_points || 100;
        if (Array.isArray(cfg.tiers) && cfg.tiers.length > 0) {
          this.rewardTiers = cfg.tiers
            .map((tier) => parseInt(tier, 10))
            .filter((tier) => Number.isFinite(tier) && tier > 0)
            .sort((a, b) => a - b);
        }
      } catch (_) {}
    },

    handleNumpad(key) {
      if (key === 'del') {
        this.code = this.code.slice(0, -1);
      } else if (this.code.length < 20) {
        this.code += key;
      }
    },

    // Kiosk Phase 9.1.7 — handlers du clavier virtuel.
    // onFocusRegisterField(field) : appelé sur focus/click d'un <input>
    // pour ouvrir le clavier sur le bon champ.
    onFocusRegisterField(field) {
      if (['registerName', 'registerPhone', 'registerEmail'].includes(field)) {
        this.vkeybActiveField = field;
      }
    },
    onVkeybInput(next) {
      const f = this.vkeybActiveField;
      if (!f) return;
      this[f] = next;
    },
    onVkeybSubmit() {
      // La touche ✓ valide le formulaire si tout est rempli ; sinon elle
      // passe juste au champ suivant. Cela reproduit le comportement
      // attendu d'un clavier matériel avec "Enter".
      if (this.vkeybActiveField === 'registerName' && this.registerPhone === '') {
        this.vkeybActiveField = 'registerPhone';
        return;
      }
      if (this.vkeybActiveField === 'registerPhone' && this.registerEmail === '') {
        this.vkeybActiveField = 'registerEmail';
        return;
      }
      // Sur le dernier champ → tenter la soumission.
      this.vkeybActiveField = null;
      if (this.registerName.trim() && this.registerPhone.trim() && !this.registerLoading) {
        this.submitRegister();
      }
    },
    onVkeybClose() {
      this.vkeybActiveField = null;
    },

    async checkLoyalty() {
      if (!this.code.trim()) return;
      this.loading = true;
      this.error = null;
      const ms = this.$options.LOYALTY_HTTP_TIMEOUT_MS;
      try {
        const res = await axios.post('frontend/loyalty/check', { code: this.code.trim() }, { timeout: ms });
        const data = res.data?.data || res.data || {};
        // Normalize field names: API returns `points`, UI uses `loyalty_point`
        this.customer = {
          ...data,
          loyalty_point: parseInt(data.loyalty_point ?? data.points ?? 0, 10),
        };
        this.discountValue = parseFloat(data.discount_value || 0);
        this.step = 'balance';
      } catch (err) {
        if (err.code === 'ECONNABORTED' || err.message?.includes('timeout')) {
          this.error = this.$t('kiosk.loyalty_screen.request_timeout');
        } else {
          const msg = err.response?.data?.message || err.response?.data?.errors?.code?.[0];
          this.error = msg || this.$t('kiosk.loyalty_screen.error_not_found');
        }
      } finally {
        this.loading = false;
      }
    },

    async applyLoyalty() {
      // [FIX P2 audit] redeem seulement si activé (sinon le backend 422 rejette la commande).
      // [C39 heal 2026-07-06] + gate borne dédié kioskPromoEnabled : sans lui, la remise
      // était posée dans le store mais jamais envoyée au payload → client débité plein tarif.
      if (this.canRedeem && this.discountsEnabled && this.kioskPromoEnabled && this.redeemChoice === 'yes') {
        this.appliedDiscount = Math.min(this.discountValue, this.total);
        await this.setLoyalty({ customer: this.customer, discount: this.appliedDiscount });
        this.showToast(
          this.$t('kiosk.loyalty_screen.toast_discount', { amount: this.formatPrice(this.appliedDiscount) }),
          'success',
          3000
        );
      } else {
        await this.setLoyalty({ customer: this.customer, discount: 0 });
        this.appliedDiscount = 0;
        this.showToast(this.$t('kiosk.loyalty_screen.toast_saved'), 'info', 3000);
      }
      this.step = 'confirmed';
    },

    /**
     * [PHASE-6.3] submitRegister avec gate RGPD.
     *
     * Flow :
     *   1. L'utilisateur remplit nom/téléphone/email puis clique "Je m'inscris".
     *   2. Si le consent loyalty n'est pas déjà stocké (kioskSettings.consentLoyalty),
     *      on ouvre la modale KsConsentModal — la requête `/loyalty/register` N'EST
     *      PAS émise tant que l'utilisateur n'a pas explicitement accepté.
     *   3. Sur `@accept` : on pose les consents dans le store ET on exécute le POST.
     *   4. Sur `@decline` : on ferme la modale, on nettoie le payload en attente.
     *      L'utilisateur peut corriger/retenter.
     *
     * Rationale : conforme RGPD opt-in strict (§1.6 master prompt). Les PII
     * (name/phone/email) ne doivent JAMAIS quitter la borne sans consent explicite.
     */
    async submitRegister() {
      if (!this.registerName.trim() || !this.registerPhone.trim()) return;

      const consentGiven = !!this.$store.state.kioskSettings?.consentLoyalty;
      if (!consentGiven) {
        // Prépare le payload et ouvre la modale — l'exécution effective est reprise
        // dans `onConsentAccept` si l'utilisateur valide.
        this._pendingRegister = {
          name:  this.registerName.trim(),
          phone: this.registerPhone.trim(),
          email: this.registerEmail.trim() || undefined,
        };
        this.showConsentModal = true;
        return;
      }

      // Consent déjà donné : exécuter directement.
      await this._doSubmitRegister({
        name:  this.registerName.trim(),
        phone: this.registerPhone.trim(),
        email: this.registerEmail.trim() || undefined,
      });
    },

    /**
     * [PHASE-6.3] Exécute l'appel `/loyalty/register` avec les PII saisies,
     * après que le consent RGPD a été validé. Peut être appelée directement
     * (consent pré-existant) ou via `onConsentAccept`.
     */
    async _doSubmitRegister(payload) {
      this.registerLoading = true;
      this.registerError = null;
      try {
        const res = await axios.post('frontend/loyalty/register', payload);
        const data = res.data?.data || {};
        this.customer = {
          name:          data.name || payload.name,
          loyalty_point: parseInt(data.points ?? 0, 10),
          loyalty_code:  data.loyalty_code || '',
        };
        this.discountValue = 0;
        this.code = data.loyalty_code || '';
        this.showToast(this.$t('kiosk.loyalty_screen.toast_welcome', { name: this.customer.name }), 'success', 3500);
        this.step = 'balance';
        // [PHASE-6.4] Analytics : registration réussie (anonyme — pas de phone/email ici).
        try { kioskAnalytics.track('loyalty_scanned', { registration: true }); } catch (_) {}
      } catch (err) {
        const msg = err.response?.data?.message || this.$t('kiosk.loyalty_screen.register_error_generic');
        this.registerError = msg;
      } finally {
        this.registerLoading = false;
      }
    },

    /**
     * [PHASE-6.3] Callback du consent modal : si l'utilisateur accepte, on
     * exécute le register en attente. Le modal gère déjà la persistance store
     * des consents + le POST `/loyalty/opt-in` interne.
     */
    async onConsentAccept() {
      this.showConsentModal = false;
      const payload = this._pendingRegister;
      this._pendingRegister = null;
      if (!payload) return;
      await this._doSubmitRegister(payload);
    },

    onConsentDecline() {
      this.showConsentModal = false;
      this._pendingRegister = null;
      // Pas d'erreur utilisateur — le decline est un choix légitime (RGPD).
      // L'utilisateur peut soit saisir un code existant, soit quitter l'écran.
      this.registerError = this.$t('kiosk.loyalty_screen.consent_required');
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
/* FoodKing brand V3.3 (2026-05-10) — Loyalty page warmth + ergonomie 32".
   Owner gate : "trop blanc-sur-blanc bancaire, faut chaleur restaurant".
   Subtle warm gradient + yellow loyalty icon prominent + card centered. */
.kiosk-loyalty-screen {
  min-height: 100vh;
  background:
    radial-gradient(ellipse at top, #FFF7ED 0%, #FFFFFF 45%),
    radial-gradient(ellipse at bottom, #FFF7E0 0%, #FFFFFF 50%),
    #FFFFFF;
  display: flex;
  flex-direction: column;
  color: #0F0F0F;
  padding-bottom: 2rem;
}

.kiosk-loyalty-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem 2rem;
  border-bottom: none;
}

.kiosk-back-btn {
  background: #F7F7F7;
  border: 1px solid #E5E5E5;
  border-radius: 12px;
  width: 52px;
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: #0F0F0F;
  transition: background 0.2s;
}
.kiosk-back-btn:hover { background: #FFE8DD; color: #F4501E; }

.kiosk-loyalty-title {
  font-size: 1.6rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #0F0F0F;
}

.kiosk-loyalty-step {
  flex: 1;
  display: flex;
  align-items: center; /* V3.3: vertical center pour ergo 32" portrait */
  justify-content: center;
  padding: 1rem 1.5rem;
}

/* Card warmth V3.3 — yellow ribbon top + Cayenne shadow + plus accueillant */
.kiosk-loyalty-card {
  position: relative;
  width: 100%;
  max-width: 600px;
  background: #FFFFFF;
  border-radius: 28px;
  padding: 2.5rem 2rem 2rem;
  border: 1.5px solid #FFE8DD;
  box-shadow:
    0 12px 40px rgba(244, 80, 30, 0.12),
    0 4px 12px rgba(15, 15, 15, 0.04);
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

/* Decorative yellow accent banner top of card (warm restaurant feel) */
.kiosk-loyalty-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 24px;
  right: 24px;
  height: 6px;
  background: linear-gradient(90deg, #F5C518 0%, #F4501E 100%);
  border-radius: 0 0 6px 6px;
}

.kiosk-loyalty-subtitle {
  font-size: 1.1rem;
  color: #5A5A5A;
  text-align: center;
}

.kiosk-loyalty-input-row {
  position: relative;
}

.kiosk-loyalty-input {
  width: 100%;
  background: #FFFFFF;
  border: 2px solid #E5E5E5;
  border-radius: 14px;
  padding: 1rem 3rem 1rem 1.25rem;
  font-size: 1.5rem;
  color: #0F0F0F;
  text-align: center;
  letter-spacing: 0.1em;
  outline: none;
  transition: border-color 0.2s;
  box-sizing: border-box;
}
.kiosk-loyalty-input:focus,
.kiosk-loyalty-input--active {
  border-color: #F4501E;
}
.kiosk-loyalty-input::placeholder { color: #8A8A8A; }

.kiosk-btn-clear {
  position: absolute;
  right: 1rem;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: #8A8A8A;
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
  background: #FFFFFF;
  border: 1.5px solid #E5E5E5;
  border-radius: 14px;
  height: 64px;
  font-size: 1.5rem;
  font-weight: 700;
  color: #0F0F0F;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, transform 0.1s, border-color 0.15s;
  user-select: none;
}
.kiosk-numpad-btn:hover {
  background: #FAFAFA;
  border-color: #F4501E;
}
.kiosk-numpad-btn:active {
  background: #FFE8DD;
  border-color: #F4501E;
  color: #F4501E;
  transform: scale(0.95);
}
.kiosk-numpad-btn.wide {
  background: #FAFAFA;
  color: #5A5A5A;
}
.kiosk-numpad-btn.wide:active {
  background: #FFE8DD;
  color: #F4501E;
}

.kiosk-loyalty-error {
  background: rgba(220,38,38,0.08);
  border: 1px solid rgba(220,38,38,0.4);
  border-radius: 10px;
  padding: 0.75rem 1rem;
  color: #C21E2F;
  text-align: center;
  font-size: 0.95rem;
}

.kiosk-btn-primary {
  background: #F4501E;
  color: #FFFFFF;
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

/* V3.4 (2026-05-10) — Owner gate: bouton skip + register invisibles
   (couleurs trop pâles sur fond blanc). Renforcement contraste accessibilité. */
.kiosk-loyalty-skip {
  background: none;
  border: none;
  color: #5A5A5A;
  font-size: 1rem;
  font-weight: 600;
  text-decoration: underline;
  cursor: pointer;
  text-align: center;
  padding: 0.75rem;
}
.kiosk-loyalty-skip:hover { color: #F4501E; }

.kiosk-loyalty-register-btn {
  background: linear-gradient(135deg, #F5C518 0%, #E0B214 100%);
  border: none;
  border-radius: 14px;
  color: #0F0F0F;
  font-size: 1rem;
  font-weight: 800;
  padding: 0.85rem 1.25rem;
  cursor: pointer;
  text-align: center;
  letter-spacing: 0.2px;
  box-shadow: 0 4px 12px rgba(245, 197, 24, 0.32);
  transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
}
.kiosk-loyalty-register-btn:hover {
  transform: translateY(-1px);
  filter: brightness(1.05);
  box-shadow: 0 6px 16px rgba(245, 197, 24, 0.42);
}
.kiosk-loyalty-register-btn:active { transform: translateY(0); }

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
  color: #8A8A8A;
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
  color: #8A8A8A;
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
  color: #5A5A5A;
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
  background: #F7F7F7;
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
  color: #8A8A8A;
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
  background: #FAFAFA;
  border: 1.5px solid #E5E5E5;
  border-radius: 16px;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.2s, background 0.2s;
  color: #0F0F0F;
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
.kiosk-loyalty-option-icon.gray  { background: #F7F7F7; color: #8A8A8A; }
.kiosk-loyalty-option-text {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}
.kiosk-loyalty-option-text strong { font-size: 1rem; font-weight: 700; }
.kiosk-loyalty-option-text span   { font-size: 0.85rem; color: #8A8A8A; }
.kiosk-loyalty-option.selected .kiosk-loyalty-option-text span { color: rgba(255,215,0,0.7); }

.kiosk-loyalty-not-enough {
  background: #FAFAFA;
  border-radius: 12px;
  padding: 1rem;
  font-size: 0.95rem;
  color: #5A5A5A;
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
  color: #8A8A8A;
  font-size: 1rem;
  margin-bottom: 1rem;
}
</style>
