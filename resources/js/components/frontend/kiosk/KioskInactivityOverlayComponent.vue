<template>
  <transition name="kiosk-fade">
    <div
      v-if="visible"
      class="kiosk-inactivity-overlay"
      role="alertdialog"
      aria-modal="true"
      :aria-labelledby="titleId"
      :aria-describedby="descriptionId"
      data-testid="kiosk-inactivity-overlay"
      @click.self="onStay"
      @keydown.esc.prevent="onStay"
      tabindex="-1"
      ref="overlayRoot"
    >
      <div class="kiosk-inactivity-dialog">
        <div class="kiosk-inactivity-icon" aria-hidden="true">
          <svg viewBox="0 0 64 64" width="80" height="80" focusable="false">
            <circle cx="32" cy="32" r="28" fill="none" stroke="currentColor" stroke-width="4"/>
            <path d="M32 16 v18 l12 6" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>

        <h2 :id="titleId" class="kiosk-inactivity-title">
          {{ $t('kiosk.inactivity.title') || 'Toujours là ?' }}
        </h2>
        <p :id="descriptionId" class="kiosk-inactivity-desc">
          {{ $t('kiosk.inactivity.desc') || 'Votre commande sera effacée dans' }}
          <strong class="kiosk-inactivity-countdown" data-testid="kiosk-inactivity-countdown" aria-live="polite">
            {{ secondsLeft }} {{ secondsLeft === 1 ? ($t('kiosk.inactivity.second') || 'seconde') : ($t('kiosk.inactivity.seconds') || 'secondes') }}
          </strong>
        </p>

        <div class="kiosk-inactivity-actions">
          <KsButton
            variant="primary"
            size="lg"
            :label="$t('kiosk.inactivity.stay') || 'Je suis là'"
            data-testid="kiosk-inactivity-stay"
            @click="onStay"
            ref="stayBtn"
          />
          <KsButton
            variant="secondary"
            size="lg"
            :label="$t('kiosk.inactivity.leave') || 'Abandonner'"
            data-testid="kiosk-inactivity-leave"
            @click="onLeave"
          />
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
/**
 * KioskInactivityOverlayComponent — Écran 13 du DESIGN_BRIEF_KIOSK_2026.
 *
 * Rôle : modal bloquant affiché lorsque le timer d'inactivité
 * (kioskSettings.confirmMs) se déclenche. Donne 2 choix :
 *   - "Je suis là"   → réinitialise le timer parent, ferme le modal
 *   - "Abandonner"   → vide panier + retour écran idle
 *
 * Si l'utilisateur ne répond pas, le parent déclenche le leave automatique
 * quand le countdown arrive à 0.
 *
 * Props :
 *   - visible          (Boolean) : affichage contrôlé par le parent
 *   - countdownMs      (Number)  : durée totale du countdown (défaut 15s)
 *
 * Emit :
 *   - 'stay'  : utilisateur choisit de rester
 *   - 'leave' : utilisateur choisit d'abandonner OU timer écoulé
 *
 * A11y :
 *   - role=alertdialog + aria-modal=true (WAI-ARIA 1.2)
 *   - Focus trap simple (on focus le bouton "Je suis là" au mount)
 *   - Countdown annoncé via aria-live=polite (pas assertive — non critique)
 *   - Clic sur backdrop OU Esc = Stay (pas Leave — invariant §12
 *     DATA_CONTRACT : un geste accidentel ne doit pas détruire un panier)
 *
 * Analytics :
 *   - `idle_warning` émis quand visible=true devient true (watch)
 *   - `idle_reset` émis sur stay
 *   - `idle_dismissed` émis sur leave (timer ou bouton)
 */
import KsButton from './ds/KsButton.vue';
import kioskAnalytics from '../../../helpers/kioskAnalytics';

let uidCounter = 0;

export default {
    name: 'KioskInactivityOverlayComponent',
    components: { KsButton },
    props: {
        visible:      { type: Boolean, default: false },
        countdownMs:  { type: Number,  default: 15000 },
    },
    emits: ['stay', 'leave'],
    data() {
        const uid = ++uidCounter;
        return {
            uid,
            secondsLeft: Math.max(1, Math.ceil(this.countdownMs / 1000)),
            tickTimer: null,
            expireTimer: null,
        };
    },
    computed: {
        titleId()       { return `kiosk-inactivity-title-${this.uid}`; },
        descriptionId() { return `kiosk-inactivity-desc-${this.uid}`;  },
    },
    watch: {
        visible: {
            immediate: true,
            handler(v) {
                if (v) this.start(); else this.stop();
            },
        },
    },
    beforeUnmount() {
        this.stop();
    },
    methods: {
        start() {
            this.stop();
            this.secondsLeft = Math.max(1, Math.ceil(this.countdownMs / 1000));
            try {
                // Kiosk Phase 9.1.9 — l'event canonique whitelisté dans
                // kioskAnalytics.ALLOWED_EVENTS est `idle_warning_shown` (pas
                // `idle_warning`). L'ancien nom était silently droppé par le
                // filtre, donc l'analytique était aveugle sur les overlays.
                kioskAnalytics.track('idle_warning_shown', {
                    countdown_ms: this.countdownMs,
                });
            } catch (_) {}
            // Focus l'action principale (a11y).
            this.$nextTick(() => {
                try { this.$refs.stayBtn?.$el?.focus?.(); } catch (_) {}
            });
            this.tickTimer = setInterval(() => {
                if (this.secondsLeft > 1) {
                    this.secondsLeft -= 1;
                } else {
                    this.secondsLeft = 0;
                }
            }, 1000);
            this.expireTimer = setTimeout(() => this.onTimerExpired(), this.countdownMs);
        },
        stop() {
            if (this.tickTimer) { clearInterval(this.tickTimer); this.tickTimer = null; }
            if (this.expireTimer) { clearTimeout(this.expireTimer); this.expireTimer = null; }
        },
        onStay() {
            this.stop();
            try { kioskAnalytics.track('idle_reset', { trigger: 'user' }); } catch (_) {}
            this.$emit('stay');
        },
        onLeave() {
            this.stop();
            try { kioskAnalytics.track('idle_dismissed', { trigger: 'user' }); } catch (_) {}
            this.$emit('leave');
        },
        onTimerExpired() {
            try { kioskAnalytics.track('idle_dismissed', { trigger: 'timeout' }); } catch (_) {}
            this.$emit('leave');
        },
    },
};
</script>

<style scoped>
.kiosk-inactivity-overlay {
    position: fixed;
    inset: 0;
    z-index: 1050;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.55);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
}

.kiosk-inactivity-dialog {
    width: min(640px, 92vw);
    padding: 48px 40px;
    background: var(--kiosk-surface, #FFFFFF);
    border-radius: var(--kiosk-radius-3, 20px);
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.35);
    text-align: center;
    color: var(--kiosk-text, #1A1A1A);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
}

.kiosk-inactivity-icon {
    color: var(--kiosk-primary, #F4501E);
}

.kiosk-inactivity-title {
    font-family: var(--kiosk-font-display, inherit);
    font-size: calc(34px * var(--kiosk-text-scale, 1));
    font-weight: 900;
    margin: 0;
    letter-spacing: -0.01em;
}

.kiosk-inactivity-desc {
    font-size: calc(20px * var(--kiosk-text-scale, 1));
    margin: 0;
    color: var(--kiosk-text-muted, #5A5A5A);
    line-height: 1.4;
}

.kiosk-inactivity-countdown {
    color: var(--kiosk-primary, #F4501E);
    font-weight: 900;
    font-variant-numeric: tabular-nums;
}

.kiosk-inactivity-actions {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    justify-content: center;
    margin-top: 8px;
}

/* Respecte reduced-motion. */
.kiosk-fade-enter-active,
.kiosk-fade-leave-active { transition: opacity 160ms ease; }
.kiosk-fade-enter-from,
.kiosk-fade-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
    .kiosk-fade-enter-active,
    .kiosk-fade-leave-active { transition: none; }
}
:global([data-kiosk-reduced-motion="true"]) .kiosk-fade-enter-active,
:global([data-kiosk-reduced-motion="true"]) .kiosk-fade-leave-active { transition: none; }

/* PMR : cible tactile élargie. */
:global([data-kiosk-pmr="true"]) .kiosk-inactivity-dialog {
    padding: 56px 48px;
}
</style>
