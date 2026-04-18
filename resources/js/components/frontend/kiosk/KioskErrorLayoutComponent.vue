<template>
  <section
    class="kiosk-err"
    :class="[`kiosk-err--${variant}`]"
    role="alert"
    aria-live="assertive"
  >
    <div class="kiosk-err__content">
      <div class="kiosk-err__icon" aria-hidden="true">
        <slot name="icon">{{ icon }}</slot>
      </div>
      <h1 class="kiosk-err__title" :data-testid="`kiosk-error-${variant}-title`">
        <slot name="title">{{ title }}</slot>
      </h1>
      <p v-if="subtitle || $slots.subtitle" class="kiosk-err__subtitle">
        <slot name="subtitle">{{ subtitle }}</slot>
      </p>
      <p v-if="hint || $slots.hint" class="kiosk-err__hint">
        <slot name="hint">{{ hint }}</slot>
      </p>

      <div class="kiosk-err__actions">
        <slot name="actions" />
      </div>

      <div v-if="$slots.footer" class="kiosk-err__footer">
        <slot name="footer" />
      </div>
    </div>
  </section>
</template>

<script>
/**
 * KioskErrorLayoutComponent — Kiosk Design V1 Phase 3 (layout partagé).
 *
 * Structure visuelle commune aux 4 écrans d'erreur plein-écran :
 *  - Network lost
 *  - Menu unavailable
 *  - Product removed
 *  - Payment refused
 *
 * Props : icon, title, subtitle, hint, variant (pour pouvoir varier les
 * accents de couleur : 'network'|'menu'|'product'|'payment'|'generic').
 *
 * Slots : icon, title, subtitle, hint, actions (liste de KsButton),
 *         footer (optionnel, ex. tips / diagnostic).
 *
 * Aucune logique métier — présentation pure, l'émetteur gère retry/navigate.
 */
export default {
    name: 'KioskErrorLayoutComponent',
    props: {
        icon: { type: String, default: '⚠' },
        title: { type: String, default: '' },
        subtitle: { type: String, default: '' },
        hint: { type: String, default: '' },
        variant: {
            type: String,
            default: 'generic',
            validator: (v) => ['network', 'menu', 'product', 'payment', 'generic'].includes(v),
        },
    },
};
</script>

<style scoped>
.kiosk-err {
    position: relative;
    width: 100%;
    min-height: 100vh;
    background: var(--kiosk-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--kiosk-space-12) var(--kiosk-space-8);
}

.kiosk-err__content {
    max-width: 820px;
    width: 100%;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--kiosk-space-5);
}

.kiosk-err__icon {
    width: 168px;
    height: 168px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 96px;
    background: var(--kiosk-primary-soft);
    color: var(--kiosk-primary);
    margin-bottom: var(--kiosk-space-3);
    box-shadow: var(--kiosk-shadow-card);
}

.kiosk-err--network .kiosk-err__icon { background: #E6ECFB; color: var(--kiosk-info); }
.kiosk-err--menu .kiosk-err__icon    { background: #FDF1DC; color: var(--kiosk-warning); }
.kiosk-err--product .kiosk-err__icon { background: var(--kiosk-primary-soft); color: var(--kiosk-primary); }
.kiosk-err--payment .kiosk-err__icon { background: #FCE6E8; color: var(--kiosk-error); }

.kiosk-err__title {
    margin: 0;
    font-size: calc(var(--kiosk-font-size-display) * var(--kiosk-text-scale));
    font-weight: var(--kiosk-font-weight-black);
    color: var(--kiosk-text);
    line-height: var(--kiosk-line-height-tight);
}

.kiosk-err__subtitle {
    margin: 0;
    font-size: calc(var(--kiosk-font-size-title) * var(--kiosk-text-scale));
    font-weight: var(--kiosk-font-weight-medium);
    color: var(--kiosk-text);
    line-height: var(--kiosk-line-height-snug);
}

.kiosk-err__hint {
    margin: 0;
    max-width: 640px;
    font-size: calc(var(--kiosk-font-size-body) * var(--kiosk-text-scale));
    color: var(--kiosk-text-muted);
    line-height: var(--kiosk-line-height-normal);
}

.kiosk-err__actions {
    margin-top: var(--kiosk-space-8);
    display: flex;
    flex-direction: column;
    gap: var(--kiosk-space-4);
    width: 100%;
    max-width: 480px;
}

.kiosk-err__footer {
    margin-top: var(--kiosk-space-6);
    font-size: calc(var(--kiosk-font-size-caption) * var(--kiosk-text-scale));
    color: var(--kiosk-text-muted);
}
</style>
