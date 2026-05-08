<template>
  <section class="kiosk-cash">
    <header class="kiosk-cash__header">
      <div class="kiosk-cash__badge" aria-hidden="true">💶</div>
      <h1 class="kiosk-cash__title" data-testid="kiosk-cash-title">
        {{ $t('kiosk.cash_instruction.title') }}
      </h1>
      <p class="kiosk-cash__subtitle">
        {{ $t('kiosk.cash_instruction.subtitle') }}
      </p>
    </header>

    <div class="kiosk-cash__board" role="group" :aria-label="$t('kiosk.cash_instruction.title')">
      <KsCard elevation="lift" padding="lg" class="kiosk-cash__card">
        <div class="kiosk-cash__row">
          <span class="kiosk-cash__label">{{ $t('kiosk.cash_instruction.order_label') }}</span>
          <strong
            class="kiosk-cash__number"
            data-testid="kiosk-cash-order-number"
            :aria-label="orderNumberAriaLabel"
          >
            <span aria-hidden="true">#&nbsp;{{ formattedOrderNumber }}</span>
          </strong>
        </div>
        <div class="kiosk-cash__divider" aria-hidden="true" />
        <div class="kiosk-cash__row">
          <span class="kiosk-cash__label">{{ $t('kiosk.cash_instruction.amount_label') }}</span>
          <KsPriceLine
            size="lg"
            emphasis
            :price="typeof orderTotal === 'number' ? orderTotal : null"
            :label="''"
            data-testid="kiosk-cash-amount"
          />
        </div>
      </KsCard>

      <p class="kiosk-cash__tip" data-testid="kiosk-cash-tip-receipt">
        <span class="kiosk-cash__tip-icon" aria-hidden="true">💡</span>
        <span>{{ $t('kiosk.cash_instruction.tip_receipt_after_payment') }}</span>
      </p>

      <p class="kiosk-cash__help">{{ $t('kiosk.cash_instruction.help') }}</p>
    </div>

    <footer class="kiosk-cash__footer">
      <p v-if="countdown > 0" class="kiosk-cash__countdown" aria-live="polite">
        <span
          v-if="countdownPaused"
          class="kiosk-cash__countdown-paused"
          aria-hidden="true"
        >⏸</span>
        {{ $t('kiosk.cash_instruction.auto_redirect', { n: countdown }) }}
      </p>
      <KsButton
        variant="primary"
        size="lg"
        full-width
        data-testid="kiosk-cash-cta-understood"
        @click="acknowledge('user')"
      >
        {{ $t('kiosk.cash_instruction.cta_understood') }}
      </KsButton>
    </footer>
  </section>
</template>

<script>
import axios from 'axios';

/**
 * KioskCashInstructionComponent — Kiosk Design V1 Phase 3.1
 *
 * Écran distinct du waiting : affiche explicitement au client de se rendre
 * au comptoir avec son numéro de commande et le montant dû en espèces.
 * Exigence AUDIT_FINAL.md §Issue #1 + master prompt §3.1.
 *
 * Props :
 *  - orderNumber : string | number — affichage central
 *  - orderTotal  : number (EUR)    — affichage via KsPriceLine (format FR)
 *  - autoRedirectSeconds : number  — défaut 45 s, redirige /kiosk/idle
 *
 * Events :
 *  - "acknowledged" (reason: 'user'|'timeout')
 *
 * Observabilité : POST /api/frontend/kiosk/event (type=cash_instruction_shown
 * puis cash_instruction_ack) — conforme KIOSK_ANALYTICS_EVENTS.md Phase 1.9.
 */
export default {
    name: 'KioskCashInstructionComponent',
    props: {
        orderNumber: { type: [String, Number], default: '' },
        orderTotal: { type: Number, default: null },
        autoRedirectSeconds: { type: Number, default: 45 },
    },
    emits: ['acknowledged'],
    data() {
        return {
            countdown: this.autoRedirectSeconds,
            timer: null,
            countdownPaused: false,
            pauseTimeout: null,
        };
    },
    computed: {
        /**
         * Numéro de commande espacé pour lisibilité comptoir staff (QW-4).
         * "1234" → "1 2 3 4" — rendu visuel uniquement, le SR garde
         * la valeur compacte via aria-label (sinon il vocalise « un, deux,
         * trois, quatre » comme tokens séparés au lieu de « mille deux
         * cent trente-quatre »).
         */
        formattedOrderNumber() {
            const raw = String(this.orderNumber ?? '');
            if (!raw) return '—';
            return raw.split('').join(' ');
        },
        orderNumberAriaLabel() {
            const raw = String(this.orderNumber ?? '');
            // Defense-in-depth : on retire tout caractère non-alphanumérique
            // (et `-`, `_`) avant de l'injecter dans aria-label. Les attributs
            // sont déjà quotés par Vue mais on garde une marge sur tout
            // contenu props imprévisible.
            const safe = raw.replace(/[^A-Za-z0-9_-]/g, '');
            if (!safe) return this.$t('kiosk.cash_instruction.order_label');
            return `${this.$t('kiosk.cash_instruction.order_label')} ${safe}`;
        },
    },
    mounted() {
        this.logEvent('cash_instruction_shown');
        this.startCountdown();
        // M-2 : pause countdown si l'utilisateur interagit (UX accessibilité
        // personnes âgées). Le timer reprend après 3 s d'inactivité.
        this._activityHandler = () => this.pauseCountdown();
        if (typeof window !== 'undefined') {
            window.addEventListener('mousemove', this._activityHandler, { passive: true });
            window.addEventListener('touchstart', this._activityHandler, { passive: true });
            window.addEventListener('scroll', this._activityHandler, { passive: true });
        }
    },
    beforeUnmount() {
        this.stopCountdown();
        if (this._activityHandler && typeof window !== 'undefined') {
            window.removeEventListener('mousemove', this._activityHandler);
            window.removeEventListener('touchstart', this._activityHandler);
            window.removeEventListener('scroll', this._activityHandler);
            this._activityHandler = null;
        }
        if (this.pauseTimeout) {
            clearTimeout(this.pauseTimeout);
            this.pauseTimeout = null;
        }
    },
    methods: {
        startCountdown() {
            if (this.autoRedirectSeconds <= 0) return;
            this.countdown = this.autoRedirectSeconds;
            this.timer = setInterval(() => {
                if (this.countdownPaused) return;
                this.countdown -= 1;
                if (this.countdown <= 0) {
                    this.stopCountdown();
                    this.acknowledge('timeout');
                }
            }, 1000);
        },
        stopCountdown() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
        },
        /**
         * M-2 : Met en pause le décompte 3 s tant que l'utilisateur
         * interagit. Sécurise les personnes qui lisent lentement avant
         * d'aller en caisse — pas de redirection-surprise.
         */
        pauseCountdown() {
            this.countdownPaused = true;
            if (this.pauseTimeout) clearTimeout(this.pauseTimeout);
            this.pauseTimeout = setTimeout(() => {
                this.countdownPaused = false;
                this.pauseTimeout = null;
            }, 3000);
        },
        acknowledge(reason = 'user') {
            this.stopCountdown();
            this.logEvent('cash_instruction_ack', { reason });
            this.$emit('acknowledged', reason);
            // Soft fallback navigation si pas d'écoute parent.
            this.$router?.push({ name: 'kiosk.idle' }).catch(() => {});
        },
        logEvent(type, meta = {}) {
            const payload = { type, ...meta };
            if (this.orderNumber) payload.order_ref = String(this.orderNumber);
            // Route alias slash (Phase 1.9) ; fallback tiret non nécessaire.
            axios.post('/frontend/kiosk/event', payload).catch(() => {
                /* observabilité best-effort — ne bloque jamais l'UX */
            });
        },
    },
};
</script>

<style scoped>
.kiosk-cash {
    position: relative;
    width: 100%;
    min-height: 100vh;
    background: var(--kiosk-bg);
    display: flex;
    flex-direction: column;
    padding: var(--kiosk-space-12) var(--kiosk-space-8) var(--kiosk-space-10);
}

.kiosk-cash__header {
    text-align: center;
    margin-bottom: var(--kiosk-space-10);
}

.kiosk-cash__badge {
    width: 120px;
    height: 120px;
    margin: 0 auto var(--kiosk-space-5);
    border-radius: 50%;
    background: var(--kiosk-primary-soft);
    color: var(--kiosk-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 72px;
}

.kiosk-cash__title {
    margin: 0 0 var(--kiosk-space-3);
    font-size: calc(var(--kiosk-font-size-hero) * var(--kiosk-text-scale));
    font-weight: var(--kiosk-font-weight-black);
    color: var(--kiosk-text);
    line-height: var(--kiosk-line-height-tight);
}

.kiosk-cash__subtitle {
    margin: 0 auto;
    max-width: 760px;
    font-size: calc(var(--kiosk-font-size-subtitle) * var(--kiosk-text-scale));
    color: var(--kiosk-text-muted);
    line-height: var(--kiosk-line-height-snug);
}

.kiosk-cash__board {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--kiosk-space-6);
    padding: var(--kiosk-space-6) 0;
}

.kiosk-cash__card {
    max-width: 720px;
    width: 100%;
}

.kiosk-cash__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--kiosk-space-6);
    padding: var(--kiosk-space-4) 0;
}

.kiosk-cash__label {
    font-size: calc(var(--kiosk-font-size-subtitle) * var(--kiosk-text-scale));
    font-weight: var(--kiosk-font-weight-medium);
    color: var(--kiosk-text-muted);
}

.kiosk-cash__number {
    font-size: calc(96px * var(--kiosk-text-scale));
    font-weight: var(--kiosk-font-weight-black);
    color: var(--kiosk-primary);
    letter-spacing: var(--kiosk-letter-spacing-tight);
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.kiosk-cash__divider {
    height: 1px;
    background: var(--kiosk-border);
    margin: var(--kiosk-space-3) 0;
}

.kiosk-cash__help {
    font-size: calc(var(--kiosk-font-size-body) * var(--kiosk-text-scale));
    color: var(--kiosk-text-muted);
    max-width: 540px;
    text-align: center;
    line-height: var(--kiosk-line-height-snug);
}

.kiosk-cash__footer {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--kiosk-space-4);
    padding-top: var(--kiosk-space-6);
    border-top: 1px solid var(--kiosk-border);
}

.kiosk-cash__countdown {
    font-size: calc(var(--kiosk-font-size-caption) * var(--kiosk-text-scale));
    color: var(--kiosk-text-muted);
    font-weight: var(--kiosk-font-weight-medium);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.kiosk-cash__countdown-paused {
    color: var(--kiosk-primary);
    font-size: 1.1em;
    line-height: 1;
}

/* QW-4 : tip impression ticket — bandeau discret sous la card. */
.kiosk-cash__tip {
    margin: var(--kiosk-space-4) auto var(--kiosk-space-3);
    max-width: 600px;
    padding: var(--kiosk-space-3) var(--kiosk-space-4);
    background: var(--kiosk-primary-soft);
    border-left: 4px solid var(--kiosk-primary);
    border-radius: var(--kiosk-radius-3);
    color: var(--kiosk-text-muted);
    font-size: calc(var(--kiosk-font-size-body) * var(--kiosk-text-scale));
    line-height: var(--kiosk-line-height-snug);
    text-align: left;
    display: flex;
    align-items: center;
    gap: 12px;
}

.kiosk-cash__tip-icon {
    font-size: 1.2em;
    line-height: 1;
}
</style>
