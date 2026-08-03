<template>
  <header
    :class="[
      'ks-hero',
      `ks-hero--${variant}`,
      `ks-hero--${density}`,
      { 'ks-hero--has-photo': hasPhoto, 'ks-hero--align-center': alignCenter }
    ]"
    :role="ariaRole"
    :aria-label="ariaLabel || null"
  >
    <!-- Photo héro (gauche en variant strip ; arrière-plan en variant cinematic) -->
    <div v-if="hasPhoto" class="ks-hero__photo" aria-hidden="true">
      <img
        :src="photo"
        :alt="photoAlt"
        :loading="lazyPhoto ? 'lazy' : 'eager'"
        :decoding="lazyPhoto ? 'async' : 'auto'"
      />
      <span v-if="variant === 'cinematic'" class="ks-hero__overlay" aria-hidden="true" />
    </div>

    <!-- Bloc texte central -->
    <div class="ks-hero__content">
      <span v-if="eyebrow || $slots.eyebrow" class="ks-hero__eyebrow">
        <slot name="eyebrow">{{ eyebrow }}</slot>
      </span>

      <h2
        v-if="title || $slots.title"
        :class="[
          'ks-hero__title',
          'kiosk-display-' + titleScale,
        ]"
      >
        <slot name="title">{{ title }}</slot>
      </h2>

      <p v-if="subtitle || $slots.subtitle" class="ks-hero__subtitle">
        <slot name="subtitle">{{ subtitle }}</slot>
      </p>

      <!-- Slot composition live (chips ingrédients pour wizard) -->
      <div v-if="$slots.composition" class="ks-hero__composition">
        <slot name="composition" />
      </div>
    </div>

    <!-- Slot prix / CTA / actions (droite en strip, dessous en cinematic) -->
    <div v-if="$slots.aside || $slots.price" class="ks-hero__aside">
      <slot name="price" />
      <slot name="aside" />
    </div>
  </header>
</template>

<script>
/**
 * KsHero — Bandeau d'identité produit / écran (Bold Appétissant V1.11).
 * -----------------------------------------------------------------------------
 * Plan : plans/PLAN_CV1-KIOSK-VISUAL-REDESIGN-001_2026-05-02.md §V1.11 + §10.1
 *
 * Trois variants :
 *  - strip     (default) : compact, photo gauche + titre + price aside
 *                          → header wizard, header pages produit
 *  - cinematic : pleine largeur, photo background + overlay sombre + content
 *                en bas → idle screen, écrans cérémoniaux
 *  - banner    : sans photo, titre + subtitle, full-width tinted
 *                → écrans erreurs, transitions, sections recap
 *
 * Densités :
 *  - cozy   : compact (header wizard)
 *  - comfy  : standard
 *  - hero   : XXL (idle, confirmation)
 *
 * Slots :
 *  - title       : titre principal (Fraunces, scale via prop titleScale)
 *  - eyebrow     : étiquette au-dessus du titre (small caps Inter)
 *  - subtitle    : sous-titre (Inter L)
 *  - composition : chips/composition live (utilisé en wizard)
 *  - price       : slot dédié prix (KsPriceLine variant total-hero)
 *  - aside       : actions / autres (CTA secondaire, badge…)
 *
 * Discipline :
 *  - Aucune logique de prix / business
 *  - Photo : URL externe (backend ou Unsplash placeholder), ratio préservé
 *  - Si pas de photo : layout adapte automatiquement (banner)
 */
export default {
    name: 'KsHero',
    props: {
        variant: {
            type: String,
            default: 'strip',
            validator: (v) => ['strip', 'cinematic', 'banner'].includes(v),
        },
        density: {
            type: String,
            default: 'comfy',
            validator: (v) => ['cozy', 'comfy', 'hero'].includes(v),
        },
        title: { type: String, default: null },
        eyebrow: { type: String, default: null },
        subtitle: { type: String, default: null },
        photo: { type: String, default: null },
        photoAlt: { type: String, default: '' },
        lazyPhoto: { type: Boolean, default: true },
        titleScale: {
            type: String,
            default: 'l',
            validator: (v) => ['hero', 'xl', 'l', 'm', 's'].includes(v),
        },
        alignCenter: { type: Boolean, default: false },
        ariaLabel: { type: String, default: null },
        ariaRole: {
            type: String,
            default: 'banner',
            validator: (v) => ['banner', 'region', 'header', 'group'].includes(v),
        },
    },
    computed: {
        hasPhoto() {
            return !!this.photo;
        },
    },
};
</script>

<style scoped>
/* =============================================================================
   KsHero — variants strip / cinematic / banner
   ============================================================================= */
.ks-hero {
    position: relative;
    width: 100%;
    color: var(--kiosk-bold-text-primary, var(--kiosk-text));
    background: var(--kiosk-bold-bg, var(--kiosk-bg));
}

.ks-hero__content {
    display: flex;
    flex-direction: column;
    gap: var(--kiosk-space-2);
    min-width: 0;
    flex: 1;
}

.ks-hero__eyebrow {
    font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
    font-size: calc(11px * var(--kiosk-text-scale, 1));
    font-weight: var(--kiosk-font-weight-bold, 700);
    text-transform: uppercase;
    letter-spacing: 0.10em;
    color: var(--kiosk-bold-text-secondary, var(--kiosk-text-muted));
    line-height: 1;
}

.ks-hero__title {
    color: var(--kiosk-bold-text-primary, var(--kiosk-text));
    margin: 0;
}

.ks-hero__subtitle {
    font-family: var(--kiosk-font-body-bold, var(--kiosk-font-latin));
    font-size: calc(18px * var(--kiosk-text-scale, 1));
    font-weight: var(--kiosk-font-weight-medium, 500);
    color: var(--kiosk-bold-text-secondary, var(--kiosk-text-muted));
    line-height: var(--kiosk-line-height-snug, 1.3);
    margin: 0;
}

.ks-hero__composition {
    display: flex;
    flex-wrap: wrap;
    gap: var(--kiosk-space-2);
    margin-top: var(--kiosk-space-2);
}

/* =============================================================================
   Variant strip — header wizard, header product page
   ============================================================================= */
.ks-hero--strip {
    display: flex;
    align-items: center;
    gap: var(--kiosk-space-5);
    padding: var(--kiosk-space-5) var(--kiosk-space-6);
    border-bottom: 1px solid var(--kiosk-bold-border, var(--kiosk-border));
}

.ks-hero--strip .ks-hero__photo {
    flex-shrink: 0;
    width: 96px;
    height: 96px;
    border-radius: var(--kiosk-radius-md);
    overflow: hidden;
    box-shadow: var(--kiosk-shadow-card-bold, var(--kiosk-shadow-card));
}

.ks-hero--strip.ks-hero--cozy {
    padding: var(--kiosk-space-4) var(--kiosk-space-5);
}
.ks-hero--strip.ks-hero--cozy .ks-hero__photo {
    width: 72px;
    height: 72px;
}

.ks-hero--strip .ks-hero__aside {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: var(--kiosk-space-3);
    margin-inline-start: auto;
}

/* =============================================================================
   Variant cinematic — idle, confirmation, écrans premium plein cadre
   ============================================================================= */
.ks-hero--cinematic {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: stretch;
    min-height: 60vh;
    padding: var(--kiosk-space-12) var(--kiosk-space-8) var(--kiosk-space-8);
    color: var(--kiosk-bold-text-inverse, #FFF5E8);
    overflow: hidden;
}

.ks-hero--cinematic .ks-hero__photo {
    position: absolute;
    inset: 0;
    z-index: 0;
}
.ks-hero--cinematic .ks-hero__photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.ks-hero--cinematic .ks-hero__overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        180deg,
        rgba(26, 20, 16, 0.10) 0%,
        rgba(26, 20, 16, 0.30) 40%,
        rgba(26, 20, 16, 0.85) 75%,
        rgba(26, 20, 16, 0.95) 100%
    );
    z-index: 1;
}

[data-kiosk-theme='dark'] .ks-hero--cinematic .ks-hero__overlay {
    background: linear-gradient(
        180deg,
        rgba(0, 0, 0, 0.30) 0%,
        rgba(0, 0, 0, 0.55) 40%,
        rgba(0, 0, 0, 0.92) 75%,
        rgba(0, 0, 0, 1) 100%
    );
}

.ks-hero--cinematic .ks-hero__content,
.ks-hero--cinematic .ks-hero__aside {
    position: relative;
    z-index: 2;
}

.ks-hero--cinematic .ks-hero__title {
    color: var(--kiosk-bold-text-inverse, #FFF5E8);
    text-shadow: 0 4px 24px rgba(0, 0, 0, 0.6);
}

.ks-hero--cinematic .ks-hero__subtitle {
    color: rgba(255, 245, 232, 0.92);
}

.ks-hero--cinematic .ks-hero__aside {
    margin-top: var(--kiosk-space-6);
    display: flex;
    gap: var(--kiosk-space-4);
}

.ks-hero--cinematic.ks-hero--align-center {
    align-items: center;
    text-align: center;
}

/* =============================================================================
   Variant banner — sans photo (erreurs, sections recap)
   ============================================================================= */
.ks-hero--banner {
    display: flex;
    flex-direction: column;
    gap: var(--kiosk-space-3);
    padding: var(--kiosk-space-8) var(--kiosk-space-6);
    background: var(--kiosk-bold-surface-subtle, var(--kiosk-surface-alt));
    border-radius: var(--kiosk-radius-lg);
    text-align: start;
}
.ks-hero--banner.ks-hero--align-center { text-align: center; align-items: center; }

.ks-hero--banner.ks-hero--hero {
    padding: var(--kiosk-space-12) var(--kiosk-space-8);
}

/* =============================================================================
   Densité hero — XXL pour idle / confirmation
   ============================================================================= */
.ks-hero--hero.ks-hero--cinematic {
    min-height: 85vh;
    padding: var(--kiosk-space-16) var(--kiosk-space-8) var(--kiosk-space-12);
}
.ks-hero--hero .ks-hero__eyebrow {
    font-size: calc(13px * var(--kiosk-text-scale, 1));
}

/* =============================================================================
   Reduced motion — neutralise toute transition propre KsHero
   ============================================================================= */
@media (prefers-reduced-motion: reduce) {
    .ks-hero__photo img { transform: none !important; }
}
[data-kiosk-reduced-motion='true'] .ks-hero__photo img { transform: none !important; }
</style>
