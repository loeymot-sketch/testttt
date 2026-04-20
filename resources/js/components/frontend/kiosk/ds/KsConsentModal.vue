<template>
  <div
    v-if="modelValue"
    class="ks-consent-backdrop"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    :aria-describedby="descId"
    data-testid="kiosk-consent-modal"
    @click.self="handleBackdrop"
    @keydown.esc="handleDecline"
  >
    <div class="ks-consent-card" tabindex="-1" ref="cardRef">
      <header class="ks-consent-header">
        <h2 :id="titleId" class="ks-consent-title" data-testid="kiosk-consent-title">
          {{ $t('kiosk.consent.title') }}
        </h2>
        <p class="ks-consent-subtitle" :id="descId">
          {{ $t('kiosk.consent.subtitle') }}
        </p>
      </header>

      <p class="ks-consent-description">{{ $t('kiosk.consent.description') }}</p>

      <!-- Loyalty checkbox (opt-in strict, non pré-cochée, §1.6 RGPD) -->
      <label class="ks-consent-check" data-testid="kiosk-consent-loyalty-label">
        <input
          type="checkbox"
          :checked="loyaltyChecked"
          @change="toggleLoyalty"
          :aria-describedby="loyaltyErrorId"
          data-testid="kiosk-consent-loyalty"
        />
        <span>{{ $t('kiosk.consent.checkbox_loyalty') }}</span>
      </label>
      <p
        v-if="showLoyaltyError"
        :id="loyaltyErrorId"
        class="ks-consent-error"
        role="alert"
        data-testid="kiosk-consent-loyalty-error"
      >{{ $t('kiosk.consent.required_loyalty') }}</p>

      <!-- Analytics checkbox (séparée — distinct consent legal) -->
      <label class="ks-consent-check" data-testid="kiosk-consent-analytics-label">
        <input
          type="checkbox"
          :checked="analyticsChecked"
          @change="toggleAnalytics"
          data-testid="kiosk-consent-analytics"
        />
        <span>{{ $t('kiosk.consent.checkbox_analytics') }}</span>
      </label>

      <!-- Mobile transfer checkbox (Phase 8.11 — 3ème type consent) -->
      <label class="ks-consent-check" data-testid="kiosk-consent-mobile-transfer-label">
        <input
          type="checkbox"
          :checked="mobileTransferChecked"
          @change="toggleMobileTransfer"
          data-testid="kiosk-consent-mobile-transfer"
        />
        <span>{{ $t('kiosk.consent.checkbox_mobile_transfer') }}</span>
      </label>

      <!-- Privacy link -->
      <button type="button"
        class="ks-consent-privacy"
        data-testid="kiosk-consent-privacy-link"
        @click="openPrivacy">
        {{ $t('kiosk.consent.privacy_link') }}
      </button>

      <!-- Actions -->
      <div class="ks-consent-actions">
        <button type="button"
          class="ks-consent-btn ks-consent-btn--ghost"
          data-testid="kiosk-consent-decline"
          @click="handleDecline"
          :disabled="submitting">{{ $t('kiosk.consent.cta_decline') }}</button>
        <button type="button"
          class="ks-consent-btn ks-consent-btn--primary"
          data-testid="kiosk-consent-accept"
          :aria-busy="submitting"
          :disabled="submitting"
          @click="handleAccept">{{ submitting ? '…' : $t('kiosk.consent.cta_accept') }}</button>
      </div>

      <!-- Nested privacy subdialog -->
      <div
        v-if="privacyOpen"
        class="ks-consent-privacy-overlay"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('kiosk.consent.privacy_link')"
        data-testid="kiosk-consent-privacy-modal"
        @click.self="closePrivacy"
      >
        <div class="ks-consent-privacy-card">
          <h3 class="ks-consent-privacy-title">{{ $t('kiosk.consent.privacy_link') }}</h3>
          <div class="ks-consent-privacy-body">
            <p>{{ privacyBody }}</p>
          </div>
          <button type="button"
            class="ks-consent-btn ks-consent-btn--primary"
            :aria-label="$t('kiosk.consent.privacy_close')"
            data-testid="kiosk-consent-privacy-close"
            @click="closePrivacy">{{ $t('kiosk.consent.privacy_close') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * KsConsentModal — dialogue RGPD pour opt-in loyalty + analytics.
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 5.4.
 *
 * Règles RGPD (article 7 & 32, brief §1) :
 *  - Checkboxes NON pré-cochées par défaut (pas de dark pattern).
 *  - Séparation stricte loyalty vs analytics — deux consentements distincts.
 *  - Lien vers politique de confidentialité accessible AVANT validation.
 *  - Pas de "×" discret — refus explicite via CTA visible.
 *  - Aucune PII en props/retour — le caller est responsable de fournir
 *    phone/email séparément si nécessaire (ex. KioskApp dédié).
 *  - L'événement `consent_given` est émis via /api/frontend/kiosk-event
 *    à la validation (accept ou decline) — jamais avant.
 *
 * Props :
 *  - modelValue          : boolean v-model open/close.
 *  - privacyNoticeVersion: string (audit trail LoyaltyConsent).
 *  - phone               : string optional — si fourni, on appelle
 *                          /loyalty/opt-in dès accept. Sinon le parent
 *                          gère la persistance (ex. collecte plus tard).
 *  - email               : string optional.
 *  - name                : string optional.
 *
 * Émissions :
 *  - update:modelValue   : ferme le dialog.
 *  - accepted (payload)  : { loyalty, analytics, apiResult? }.
 *  - declined            : aucun argument.
 *  - error (err)         : echec appel opt-in (réseau ou 500).
 */

const UID = () => 'ks-consent-' + Math.random().toString(36).slice(2, 10);

export default {
    name: 'KsConsentModal',
    props: {
        modelValue: { type: Boolean, default: false },
        privacyNoticeVersion: { type: String, default: '2026-04-18' },
        phone: { type: String, default: '' },
        email: { type: String, default: '' },
        name: { type: String, default: '' },
        // Si false, on ne fait aucun POST — pur opt-in client (analytics only).
        persistLoyalty: { type: Boolean, default: true },
    },
    emits: ['update:modelValue', 'accepted', 'declined', 'error'],
    data() {
        const uid = UID();
        return {
            titleId: uid + '-title',
            descId: uid + '-desc',
            loyaltyErrorId: uid + '-loyalty-err',
            loyaltyChecked: false,
            analyticsChecked: false,
            mobileTransferChecked: false,
            privacyOpen: false,
            submitting: false,
            showLoyaltyError: false,
        };
    },
    computed: {
        privacyBody() {
            return this.$te('kiosk.consent.privacy_body')
                ? this.$t('kiosk.consent.privacy_body')
                : [
                    'FoodKing collecte uniquement les données nécessaires à votre programme fidélité : nom, téléphone, email, préférences alimentaires, allergènes déclarés.',
                    'Vos préférences peuvent être supprimées sur demande (contact indiqué en caisse ou via le site officiel).',
                    'Aucune donnée personnelle n\'est partagée avec des partenaires commerciaux.',
                    'Les analyses d\'utilisation, si acceptées, sont anonymes et agrégées — sans lien avec votre identité.',
                ].join(' ');
        },
    },
    watch: {
        modelValue(next) {
            if (next) {
                this.loyaltyChecked = false;
                this.analyticsChecked = false;
                this.mobileTransferChecked = false;
                this.showLoyaltyError = false;
                this.privacyOpen = false;
                this.submitting = false;
                this.$nextTick(() => {
                    try { this.$refs.cardRef?.focus(); } catch (_) {}
                });
                this.reportEvent('modal_open');
            }
        },
    },
    methods: {
        toggleLoyalty(e) {
            this.loyaltyChecked = !!e.target.checked;
            if (this.loyaltyChecked) this.showLoyaltyError = false;
        },
        toggleAnalytics(e) {
            this.analyticsChecked = !!e.target.checked;
        },
        toggleMobileTransfer(e) {
            this.mobileTransferChecked = !!e.target.checked;
        },
        openPrivacy() {
            this.privacyOpen = true;
        },
        closePrivacy() {
            this.privacyOpen = false;
        },
        handleBackdrop() {
            // Pas de fermeture "silencieuse" par backdrop — RGPD exige
            // un choix explicite. Redirige vers decline.
            this.handleDecline();
        },
        async handleAccept() {
            if (this.submitting) return;
            // Au moins un des deux doit être coché — sinon on considère
            // comme un decline implicite (évite "continuer sans rien accepter"
            // en cliquant sur accepter).
            if (!this.loyaltyChecked && !this.analyticsChecked && !this.mobileTransferChecked) {
                this.showLoyaltyError = true;
                return;
            }

            // Synchronise les trois consents dans le store (source of truth).
            try {
                this.$store?.dispatch?.('kioskSettings/setConsentLoyalty', !!this.loyaltyChecked);
                this.$store?.dispatch?.('kioskSettings/setConsentAnalytics', !!this.analyticsChecked);
                this.$store?.dispatch?.('kioskSettings/setConsentMobileTransfer', !!this.mobileTransferChecked);
            } catch (_) {}

            const outcome = {
                loyalty: this.loyaltyChecked,
                analytics: this.analyticsChecked,
                mobileTransfer: this.mobileTransferChecked,
                apiResult: null,
            };

            // Persist loyalty opt-in côté backend si loyalty=true et persistLoyalty=true.
            if (this.loyaltyChecked && this.persistLoyalty) {
                if (!this.phone) {
                    // Mode "consent sans collecte" — on skip simplement le POST.
                    outcome.apiResult = { skipped: 'no_phone' };
                } else {
                    this.submitting = true;
                    try {
                        const axios = window.axios;
                        if (axios?.post) {
                            const res = await axios.post('frontend/loyalty/opt-in', {
                                phone: this.phone,
                                email: this.email || null,
                                name: this.name || null,
                                consent_accepted: true,
                                privacy_notice_version: this.privacyNoticeVersion,
                            });
                            outcome.apiResult = res?.data || { status: true };
                        }
                    } catch (e) {
                        this.$emit('error', e);
                        this.reportEvent('opt_in_error');
                        this.submitting = false;
                        return;
                    }
                    this.submitting = false;
                }
            }

            this.reportEvent('accept', {
                loyalty: this.loyaltyChecked,
                analytics: this.analyticsChecked,
                mobile_transfer: this.mobileTransferChecked,
            });

            // Event analytics (spec : consent_given avec consent_type+granted).
            this.trackAnalyticsConsent('loyalty_scan', this.loyaltyChecked);
            this.trackAnalyticsConsent('heatmap', this.analyticsChecked);
            this.trackAnalyticsConsent('mobile_transfer', this.mobileTransferChecked);

            this.$emit('accepted', outcome);
            this.$emit('update:modelValue', false);
        },
        handleDecline() {
            if (this.submitting) return;
            try {
                this.$store?.dispatch?.('kioskSettings/setConsentLoyalty', false);
                this.$store?.dispatch?.('kioskSettings/setConsentAnalytics', false);
                this.$store?.dispatch?.('kioskSettings/setConsentMobileTransfer', false);
            } catch (_) {}
            this.reportEvent('decline');
            this.trackAnalyticsConsent('loyalty_scan', false);
            this.trackAnalyticsConsent('heatmap', false);
            this.trackAnalyticsConsent('mobile_transfer', false);
            this.$emit('declined');
            this.$emit('update:modelValue', false);
        },
        /**
         * Log observabilité vers /api/frontend/kiosk-event.
         * Non-bloquant, silencieux en cas d'échec.
         */
        reportEvent(subtype, meta = {}) {
            try {
                const axios = window.axios;
                if (!axios?.post) return;
                const details = subtype + (Object.keys(meta).length ? ' | ' + JSON.stringify(meta) : '');
                axios.post('frontend/kiosk-event', {
                    type: 'consent_event',
                    details: details.slice(0, 490),
                }).catch(() => {});
            } catch (_) {}
        },
        /**
         * Émet un event analytics `consent_given` côté observabilité
         * (distinct du `consent_event` d'observabilité purement technique).
         */
        trackAnalyticsConsent(consent_type, granted) {
            try {
                const axios = window.axios;
                if (!axios?.post) return;
                axios.post('frontend/kiosk-event', {
                    type: 'analytics',
                    event_name: 'consent_given',
                    payload: { consent_type, granted: !!granted },
                }).catch(() => {});
            } catch (_) {}
        },
    },
};
</script>

<style scoped>
.ks-consent-backdrop {
  position: fixed;
  inset: 0;
  z-index: 220;
  background: var(--kiosk-overlay-modal, rgba(26,26,26,0.65));
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 24px;
}

.ks-consent-card {
  width: min(560px, 100%);
  background: var(--kiosk-surface);
  color: var(--kiosk-text);
  border-radius: 20px;
  box-shadow: var(--kiosk-shadow-modal);
  padding: 32px 32px 28px;
  display: flex;
  flex-direction: column;
  gap: 18px;
  outline: none;
  max-height: 90vh;
  overflow-y: auto;
}

.ks-consent-header {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ks-consent-title {
  margin: 0;
  font-size: 26px;
  font-weight: 900;
  color: var(--kiosk-text);
}

.ks-consent-subtitle {
  margin: 0;
  font-size: 15px;
  color: var(--kiosk-text-muted);
  font-weight: 600;
}

.ks-consent-description {
  margin: 0;
  font-size: 15px;
  line-height: 1.5;
  color: var(--kiosk-text);
}

.ks-consent-check {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 14px;
  border: 1.5px solid var(--kiosk-border);
  border-radius: 12px;
  background: var(--kiosk-surface-alt);
  cursor: pointer;
  font-size: 15px;
  font-weight: 600;
  color: var(--kiosk-text);
  min-height: var(--kiosk-tap-min, 56px);
  transition: background 0.15s ease, border-color 0.15s ease;
}
.ks-consent-check:hover { background: var(--kiosk-primary-soft); }
.ks-consent-check input[type="checkbox"] {
  margin-top: 3px;
  width: 22px;
  height: 22px;
  accent-color: var(--kiosk-primary);
  flex-shrink: 0;
}

.ks-consent-error {
  margin: -6px 0 0;
  font-size: 13px;
  color: var(--kiosk-error);
  font-weight: 700;
}

.ks-consent-privacy {
  align-self: flex-start;
  padding: 8px 0;
  background: transparent;
  border: none;
  color: var(--kiosk-primary);
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  text-decoration: underline;
}
.ks-consent-privacy:hover { color: var(--kiosk-primary-dark); }
.ks-consent-privacy:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 2px;
}

.ks-consent-actions {
  display: flex;
  gap: 12px;
  margin-top: 8px;
}

.ks-consent-btn {
  flex: 1;
  min-height: var(--kiosk-tap-min, 60px);
  padding: 14px 20px;
  border-radius: 14px;
  font-size: 17px;
  font-weight: 800;
  cursor: pointer;
  transition: background 0.15s ease, transform 0.08s ease;
  border: 1.5px solid transparent;
}

.ks-consent-btn--ghost {
  background: var(--kiosk-surface);
  color: var(--kiosk-text-muted);
  border-color: var(--kiosk-border);
}
.ks-consent-btn--ghost:hover { background: var(--kiosk-surface-alt); }

.ks-consent-btn--primary {
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red, #ffffff);
  box-shadow: var(--kiosk-shadow-cta);
}
.ks-consent-btn--primary:hover { background: var(--kiosk-primary-dark); }
.ks-consent-btn--primary:active { transform: scale(0.98); }
.ks-consent-btn--primary[disabled] { opacity: 0.7; cursor: not-allowed; }

.ks-consent-btn:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 3px;
}

/* Privacy overlay */
.ks-consent-privacy-overlay {
  position: fixed;
  inset: 0;
  z-index: 230;
  background: var(--kiosk-overlay-modal, rgba(26,26,26,0.75));
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 24px;
}

.ks-consent-privacy-card {
  width: min(620px, 100%);
  background: var(--kiosk-surface);
  color: var(--kiosk-text);
  border-radius: 18px;
  padding: 28px 28px 24px;
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-height: 80vh;
  overflow-y: auto;
  box-shadow: var(--kiosk-shadow-modal);
}

.ks-consent-privacy-title {
  margin: 0;
  font-size: 22px;
  font-weight: 900;
}

.ks-consent-privacy-body {
  font-size: 15px;
  line-height: 1.55;
  color: var(--kiosk-text);
}
</style>
