<template>
  <section class="kiosk-cash">
    <!-- V3.3 (2026-05-10) — Wrapper __main centre vertical (32" portrait ergo) -->
    <div class="kiosk-cash__main">
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
            <strong class="kiosk-cash__number" data-testid="kiosk-cash-order-number">
              #{{ orderNumber || '—' }}
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

        <p class="kiosk-cash__help">{{ $t('kiosk.cash_instruction.help') }}</p>
      </div>
    </div>

    <footer class="kiosk-cash__footer">
      <p v-if="countdown > 0" class="kiosk-cash__countdown" aria-live="polite">
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
import kioskHardware from '../../../services/kioskHardware';
import { printReceipt as escPosPrint, buildReceiptData, reportPrinterFailure, isLocalBridgeAvailable } from '../../../helpers/kioskPrinter';

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
        };
    },
    mounted() {
        this.logEvent('cash_instruction_shown');
        this.startCountdown();
        // [BORNE-LOCAL-BRIDGE 2026-06-28] Auto-impression du ticket client pour le
        // mode PAIEMENT À LA CAISSE (Plan B Le Cayenne) : ce flux finit ici (PAS sur
        // /confirmation), donc l'auto-print de la confirmation ne s'y déclenche jamais.
        // On imprime via le pont local (POST silencieux 127.0.0.1:9100 → SK1-31) si
        // disponible. Le e2e LIVE a révélé ce trou (commande caisse → cash-instruction).
        this.$nextTick(async () => {
            try {
                if (kioskHardware.isKioskBridge() || await isLocalBridgeAvailable()) {
                    this.autoPrintCounterTicket();
                }
            } catch (_) { /* détection pont non bloquante */ }
        });
    },
    beforeUnmount() {
        this.stopCountdown();
    },
    methods: {
        startCountdown() {
            if (this.autoRedirectSeconds <= 0) return;
            this.countdown = this.autoRedirectSeconds;
            this.timer = setInterval(() => {
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
        // [BORNE-LOCAL-BRIDGE 2026-06-28] Construit le ticket client (numéro + total +
        // items best-effort depuis le panier encore présent) et l'imprime via le pont.
        // Une seule fois par commande (garde anti-double).
        autoPrintCounterTicket() {
            if (this._printedFor && this._printedFor === String(this.orderNumber)) return;
            this._printedFor = String(this.orderNumber);
            let cartItems = [];
            try { cartItems = this.$store?.state?.kioskCart?.items || []; } catch (_) {}
            let restaurantName = 'Le Cayenne';
            try { restaurantName = this.$store?.state?.frontendSetting?.company_name || restaurantName; } catch (_) {}
            const amount = typeof this.orderTotal === 'number' ? this.orderTotal : 0;
            const receipt = buildReceiptData({
                restaurantName,
                queueNumber: String(this.orderNumber || ''),
                cartItems,
                subtotal: amount,
                discount: 0,
                total: amount,
                // Libellés ticket en FR littéral (borne FR-only ADR-007) : le bridge
                // ASCII-fold, et $t n'est pas garanti résolu au montage → on évite la
                // clé i18n brute imprimée. Le e2e LIVE a révélé ce défaut.
                paymentMethod: 'A regler en caisse',
                thankYou: 'Merci de votre visite !',
            });
            try {
                escPosPrint(receipt).catch((e) => reportPrinterFailure(this.orderNumber, e?.message || 'cash-print'));
            } catch (e) {
                reportPrinterFailure(this.orderNumber, e?.message || 'cash-print-throw');
            }
        },
    },
};
</script>

<style scoped>
/* FoodKing brand V3.3 (2026-05-10) — Center vertically for 32" portrait kiosk
   ergonomics. Owner gate: contenu confort visuel mid-screen, pas top-heavy.
   Footer anchored bottom via grid template. */
.kiosk-cash {
    position: relative;
    width: 100%;
    min-height: 100vh;
    background: var(--kiosk-page-bg, var(--kiosk-bg));
    display: grid;
    grid-template-rows: 1fr auto;
    padding: var(--kiosk-space-8) var(--kiosk-space-8) var(--kiosk-space-6);
    box-sizing: border-box;
}

.kiosk-cash__main {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--kiosk-space-6);
    padding: var(--kiosk-space-8) 0;
}

.kiosk-cash__header {
    text-align: center;
    margin-bottom: var(--kiosk-space-4);
}

.kiosk-cash__badge {
    width: 140px;
    height: 140px;
    margin: 0 auto var(--kiosk-space-6);
    border-radius: 50%;
    background: var(--kiosk-primary);
    color: var(--kiosk-text-on-red);
    box-shadow: var(--kiosk-shadow-cta);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 80px;
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
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--kiosk-space-6);
    width: 100%;
}

.kiosk-cash__card {
    max-width: 720px;
    width: 100%;
    border-radius: 30px;
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
}
</style>
