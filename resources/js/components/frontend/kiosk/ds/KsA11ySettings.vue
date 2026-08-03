<template>
  <div
    v-if="modelValue"
    class="ks-a11y-backdrop"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    data-testid="kiosk-a11y-drawer"
    @click.self="close"
    @keydown.esc="close"
  >
    <div class="ks-a11y-drawer" tabindex="-1" ref="drawerRef">
      <div class="ks-a11y-header">
        <h2 :id="titleId" class="ks-a11y-title" data-testid="kiosk-a11y-title">
          {{ $t('kiosk.a11y.title') }}
        </h2>
        <button type="button"
          class="ks-a11y-close"
          :aria-label="$t('kiosk.a11y.close')"
          data-testid="kiosk-a11y-close"
          @click="close">×</button>
      </div>

      <!--
        [ADR-007 / Sprint 3D 2026-05-16] Sélecteur de langue retiré : kiosk
        runtime FR-immutable. Le drawer ne propose plus FR/EN/AR — la borne
        reste FR pour toute la session, le clavier virtuel, la voix Web Speech
        et la lecture i18n. Voir docs/adr/ADR-007-kiosk-fr-lock.md.
        Pour ré-ouvrir un pilote multi-langue post-V1, basculer
        KIOSK_LOCALE_SWITCH_ALLOWED=true (config/kiosk.php) ET réintroduire
        une UI dédiée — pas dans ce drawer a11y.
      -->

      <!-- Contraste AA/AAA -->
      <section class="ks-a11y-section" :aria-labelledby="contrastHeadingId">
        <h3 :id="contrastHeadingId" class="ks-a11y-section-title">{{ $t('kiosk.a11y.contrast') }}</h3>
        <div class="ks-a11y-options" role="radiogroup" :aria-labelledby="contrastHeadingId" data-testid="kiosk-a11y-contrast-group">
          <button type="button"
            class="ks-a11y-option"
            :class="{ 'is-selected': contrast === 'aa' }"
            role="radio"
            :aria-checked="contrast === 'aa'"
            data-testid="kiosk-a11y-contrast-aa"
            @click="selectContrast('aa')">
            <span class="ks-a11y-option-label">{{ $t('kiosk.a11y.contrast_aa') }}</span>
            <span class="ks-a11y-option-hint">{{ $t('kiosk.a11y.contrast_aa_hint') }}</span>
          </button>
          <button type="button"
            class="ks-a11y-option"
            :class="{ 'is-selected': contrast === 'aaa' }"
            role="radio"
            :aria-checked="contrast === 'aaa'"
            data-testid="kiosk-a11y-contrast-aaa"
            @click="selectContrast('aaa')">
            <span class="ks-a11y-option-label">{{ $t('kiosk.a11y.contrast_aaa') }}</span>
            <span class="ks-a11y-option-hint">{{ $t('kiosk.a11y.contrast_aaa_hint') }}</span>
          </button>
        </div>
      </section>

      <!-- PMR toggle -->
      <section class="ks-a11y-section">
        <div class="ks-a11y-toggle-row">
          <div class="ks-a11y-toggle-copy">
            <span class="ks-a11y-section-title" :id="pmrHeadingId">{{ $t('kiosk.a11y.pmr') }}</span>
            <span class="ks-a11y-toggle-hint">{{ $t('kiosk.a11y.pmr_hint') }}</span>
          </div>
          <button type="button"
            class="ks-a11y-switch"
            :class="{ 'is-on': pmr }"
            role="switch"
            :aria-checked="pmr"
            :aria-labelledby="pmrHeadingId"
            data-testid="kiosk-a11y-pmr-toggle"
            @click="togglePmr">
            <span class="ks-a11y-switch-thumb" aria-hidden="true" />
          </button>
        </div>
      </section>

      <!-- Audio toggle -->
      <section class="ks-a11y-section">
        <div class="ks-a11y-toggle-row">
          <div class="ks-a11y-toggle-copy">
            <span class="ks-a11y-section-title" :id="audioHeadingId">{{ $t('kiosk.a11y.audio') }}</span>
            <span class="ks-a11y-toggle-hint">{{ $t('kiosk.a11y.audio_hint') }}</span>
          </div>
          <button type="button"
            class="ks-a11y-switch"
            :class="{ 'is-on': audio }"
            role="switch"
            :aria-checked="audio"
            :aria-labelledby="audioHeadingId"
            data-testid="kiosk-a11y-audio-toggle"
            @click="toggleAudio">
            <span class="ks-a11y-switch-thumb" aria-hidden="true" />
          </button>
        </div>
      </section>

      <!-- Phase 8.9 — Audio description toggle (EAA 2025 : verbose readouts) -->
      <section class="ks-a11y-section">
        <div class="ks-a11y-toggle-row">
          <div class="ks-a11y-toggle-copy">
            <span class="ks-a11y-section-title" :id="audioDescHeadingId">{{ $t('kiosk.a11y.audio_description') }}</span>
            <span class="ks-a11y-toggle-hint">{{ $t('kiosk.a11y.audio_description_hint') }}</span>
          </div>
          <button type="button"
            class="ks-a11y-switch"
            :class="{ 'is-on': audioDescription }"
            role="switch"
            :aria-checked="audioDescription"
            :aria-labelledby="audioDescHeadingId"
            data-testid="kiosk-a11y-audio-description-toggle"
            @click="toggleAudioDescription">
            <span class="ks-a11y-switch-thumb" aria-hidden="true" />
          </button>
        </div>
      </section>

      <!-- Phase 8.9 — Reduced motion (WCAG 2.3.3 pause/stop/hide animations) -->
      <section class="ks-a11y-section">
        <div class="ks-a11y-toggle-row">
          <div class="ks-a11y-toggle-copy">
            <span class="ks-a11y-section-title" :id="reducedMotionHeadingId">{{ $t('kiosk.a11y.reduced_motion') }}</span>
            <span class="ks-a11y-toggle-hint">{{ $t('kiosk.a11y.reduced_motion_hint') }}</span>
          </div>
          <button type="button"
            class="ks-a11y-switch"
            :class="{ 'is-on': reducedMotion }"
            role="switch"
            :aria-checked="reducedMotion"
            :aria-labelledby="reducedMotionHeadingId"
            data-testid="kiosk-a11y-reduced-motion-toggle"
            @click="toggleReducedMotion">
            <span class="ks-a11y-switch-thumb" aria-hidden="true" />
          </button>
        </div>
      </section>

      <!-- CV1-KIOSK-VISUAL-REDESIGN-001 V1.4 — Sélection thème (Bold Appétissant) -->
      <section class="ks-a11y-section" :aria-labelledby="themeHeadingId">
        <div class="ks-a11y-toggle-row" style="display: flex; flex-direction: column; align-items: stretch; gap: 12px;">
          <div class="ks-a11y-toggle-copy">
            <span class="ks-a11y-section-title" :id="themeHeadingId">{{ $t('kiosk.a11y.theme', 'Thème') }}</span>
            <span class="ks-a11y-toggle-hint">{{ $t('kiosk.a11y.theme_hint', 'Choisis l’apparence claire ou sombre, ou laisse le système décider.') }}</span>
          </div>
          <KsThemeToggle
            :model-value="theme"
            :aria-label="$t('kiosk.a11y.theme_aria', 'Sélection du thème')"
            testid="kiosk-a11y-theme-toggle"
            @update:modelValue="selectTheme"
          />
        </div>
      </section>

      <!-- Footer : reset + close -->
      <div class="ks-a11y-footer">
        <button type="button"
          class="ks-a11y-reset"
          data-testid="kiosk-a11y-reset"
          @click="reset">{{ $t('kiosk.a11y.reset') }}</button>
        <button type="button"
          class="ks-a11y-done"
          data-testid="kiosk-a11y-done"
          @click="close">{{ $t('kiosk.a11y.done') }}</button>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * KsA11ySettings — drawer de paramètres d'accessibilité.
 * -----------------------------------------------------------------------------
 * Phase 4.3 — European Accessibility Act (EAA).
 *
 * Ouvert depuis :
 *  - bouton dans le header kiosk (à câbler en Phase 4.4)
 *  - Idle screen (icône discrète coin bas)
 *
 * Props :
 *  - modelValue (bool) : open/close (v-model)
 *
 * Émissions :
 *  - update:modelValue : ferme le drawer (via close)
 *
 * A11y :
 *  - role="dialog", aria-modal, aria-labelledby
 *  - Focus management : focus initial sur le drawer au mount (tabindex=-1)
 *  - Radiogroup pour contraste (role=radio + aria-checked)
 *  - Switches pour PMR/Audio (role=switch + aria-checked)
 *  - Escape ferme la modale
 *
 * [ADR-007 / Sprint 3D 2026-05-16] La sélection de langue (FR/EN/AR) a été
 * retirée du drawer pour restaurer le FR-lock kiosk en V1. Voir
 * docs/adr/ADR-007-kiosk-fr-lock.md pour la justification et la procédure de
 * relaxation post-V1.
 */
const UID = () => 'ks-a11y-' + Math.random().toString(36).slice(2, 10);

// [ADR-007 / Sprint 3D 2026-05-16] LOCALE_OPTIONS retiré : kiosk runtime
// FR-immutable. Voir docs/adr/ADR-007-kiosk-fr-lock.md.

import KsThemeToggle from './KsThemeToggle.vue';

export default {
    name: 'KsA11ySettings',
    components: { KsThemeToggle },
    props: {
        modelValue: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    data() {
        const uid = UID();
        return {
            titleId: uid + '-title',
            // [ADR-007] langHeadingId conservé pour rétro-compat si un consumer
            // externe le référençait — mais aucun radiogroup langue n'est plus
            // rendu dans le drawer.
            contrastHeadingId: uid + '-contrast',
            pmrHeadingId: uid + '-pmr',
            audioHeadingId: uid + '-audio',
            audioDescHeadingId: uid + '-audio-desc',
            reducedMotionHeadingId: uid + '-reduced-motion',
            themeHeadingId: uid + '-theme',
        };
    },
    computed: {
        contrast() { return this.$store.state.kioskSettings?.contrast || 'aa'; },
        theme() { return this.$store.state.kioskSettings?.theme || 'auto'; },
        pmr() { return !!this.$store.state.kioskSettings?.pmr; },
        audio() { return !!this.$store.state.kioskSettings?.audio; },
        audioDescription() { return !!this.$store.state.kioskSettings?.audioDescription; },
        reducedMotion() { return !!this.$store.state.kioskSettings?.reducedMotion; },
    },
    watch: {
        modelValue(next) {
            if (next) {
                this.$nextTick(() => {
                    try { this.$refs.drawerRef?.focus(); } catch (_) {}
                });
                this.$store.dispatch('kioskSettings/markSettingsOpened');
                this.reportEvent('open');
            }
        },
    },
    methods: {
        close() {
            this.$emit('update:modelValue', false);
        },
        // [ADR-007 / Sprint 3D 2026-05-16] selectLocale retiré : kiosk runtime
        // FR-immutable. La méthode et son dispatch kioskSettings setLocale
        // n'existent plus dans le drawer pour empêcher toute remise en place
        // accidentelle par copier-coller. Voir docs/adr/ADR-007-kiosk-fr-lock.md.
        selectContrast(mode) {
            this.$store.dispatch('kioskSettings/setContrast', mode);
            this.reportEvent('contrast_change', { value: mode });
        },
        togglePmr() {
            const next = !this.pmr;
            this.$store.dispatch('kioskSettings/setPmr', next);
            this.reportEvent('pmr_toggle', { value: next });
        },
        toggleAudio() {
            const next = !this.audio;
            this.$store.dispatch('kioskSettings/setAudio', next);
            this.reportEvent('audio_toggle', { value: next });
        },
        toggleAudioDescription() {
            const next = !this.audioDescription;
            this.$store.dispatch('kioskSettings/setAudioDescription', next);
            this.reportEvent('audio_description_toggle', { value: next });
        },
        toggleReducedMotion() {
            const next = !this.reducedMotion;
            this.$store.dispatch('kioskSettings/setReducedMotion', next);
            this.reportEvent('reduced_motion_toggle', { value: next });
        },
        selectTheme(value) {
            // CV1-KIOSK-VISUAL-REDESIGN-001 V1.4 — propagation par useKioskTheme
            // (composable monté à la racine kiosk) qui écoute le store et écrit
            // data-kiosk-theme sur <html>.
            this.$store.dispatch('kioskSettings/setTheme', value);
            this.reportEvent('theme_change', { value });
        },
        reset() {
            this.$store.dispatch('kioskSettings/reset');
            this.reportEvent('reset');
        },
        /**
         * Log non-bloquant vers /api/frontend/kiosk/event. Aucune PII : on
         * envoie juste le type d'action et la nouvelle valeur du toggle.
         */
        reportEvent(subtype, meta = {}) {
            try {
                const axios = window.axios;
                if (!axios?.post) return;
                axios.post('frontend/kiosk-event', {
                    type: 'a11y_settings',
                    details: subtype + (meta.value !== undefined ? ':' + meta.value : ''),
                }).catch(() => {});
            } catch (_) {}
        },
    },
};
</script>

<style scoped>
.ks-a11y-backdrop {
  position: fixed;
  inset: 0;
  z-index: 200;
  background: var(--kiosk-overlay-modal, rgba(26,26,26,0.55));
  display: flex;
  align-items: stretch;
  justify-content: end;
}

.ks-a11y-drawer {
  width: min(520px, 100%);
  background: var(--kiosk-surface);
  color: var(--kiosk-text);
  display: flex;
  flex-direction: column;
  box-shadow: var(--kiosk-shadow-modal);
  padding: 28px 28px 32px;
  overflow-y: auto;
  outline: none;
  gap: 22px;
}

.ks-a11y-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 12px;
  border-bottom: 1.5px solid var(--kiosk-border);
}

.ks-a11y-title {
  font-size: 26px;
  font-weight: 900;
  margin: 0;
  color: var(--kiosk-text);
}

.ks-a11y-close {
  width: 44px;
  height: 44px;
  min-width: 44px;
  border-radius: 12px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  color: var(--kiosk-text);
  font-size: 24px;
  cursor: pointer;
}
.ks-a11y-close:hover { background: var(--kiosk-surface-alt); }
.ks-a11y-close:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 2px;
}

.ks-a11y-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ks-a11y-section-title {
  font-size: 15px;
  font-weight: 800;
  color: var(--kiosk-text);
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.ks-a11y-options {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
}

.ks-a11y-option {
  min-height: var(--kiosk-tap-min, 56px);
  padding: 12px 16px;
  border-radius: 14px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  color: var(--kiosk-text);
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s ease;
  text-align: start;
}

.ks-a11y-option:hover { background: var(--kiosk-surface-alt); }

.ks-a11y-option.is-selected {
  border-color: var(--kiosk-primary);
  background: var(--kiosk-primary-soft);
  color: var(--kiosk-primary-dark);
}

.ks-a11y-option:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 2px;
}

.ks-a11y-option-flag { font-size: 22px; }
.ks-a11y-option-label { flex: 1; }

.ks-a11y-option-hint {
  font-size: 12px;
  color: var(--kiosk-text-muted);
  font-weight: 500;
  margin-inline-start: 8px;
}

.ks-a11y-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-radius: 14px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-surface-alt);
}

.ks-a11y-toggle-copy {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
}

.ks-a11y-toggle-hint {
  font-size: 13px;
  font-weight: 500;
  color: var(--kiosk-text-muted);
  letter-spacing: 0;
  text-transform: none;
}

.ks-a11y-switch {
  width: 64px;
  height: 36px;
  border-radius: 20px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  position: relative;
  cursor: pointer;
  transition: all 0.2s ease;
}

.ks-a11y-switch-thumb {
  position: absolute;
  top: 3px;
  inset-inline-start: 3px;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--kiosk-text-muted);
  transition: all 0.2s ease;
}

.ks-a11y-switch.is-on {
  background: var(--kiosk-primary);
  border-color: var(--kiosk-primary);
}
.ks-a11y-switch.is-on .ks-a11y-switch-thumb {
  inset-inline-start: auto;
  inset-inline-end: 3px;
  background: var(--kiosk-text-on-red);
}

.ks-a11y-switch:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 3px;
}

.ks-a11y-footer {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: auto;
  padding-top: 16px;
  border-top: 1.5px solid var(--kiosk-border);
}

.ks-a11y-reset {
  flex: 1;
  min-height: var(--kiosk-tap-min, 56px);
  padding: 14px 18px;
  border-radius: 14px;
  border: 1.5px solid var(--kiosk-border);
  background: var(--kiosk-surface);
  color: var(--kiosk-text-muted);
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
}

.ks-a11y-done {
  flex: 2;
  min-height: var(--kiosk-tap-min, 56px);
  padding: 14px 18px;
  border-radius: 14px;
  border: none;
  background: var(--kiosk-primary);
  color: var(--kiosk-text-on-red);
  font-size: 18px;
  font-weight: 800;
  cursor: pointer;
  box-shadow: var(--kiosk-shadow-cta);
}
.ks-a11y-done:hover { background: var(--kiosk-primary-dark); }
.ks-a11y-done:focus-visible {
  outline: var(--kiosk-focus-width, 3px) solid var(--kiosk-focus-ring, var(--kiosk-primary));
  outline-offset: 3px;
}

/* RTL support : la drawer s'ouvre depuis la gauche en AR */
:global([dir='rtl']) .ks-a11y-backdrop { justify-content: flex-start; }
</style>
