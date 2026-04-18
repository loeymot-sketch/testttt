<template>
  <div
    v-if="visibleAllergens.length > 0"
    :class="[
      'ks-allergen-badge',
      { 'ks-allergen-badge--warning': hasCollision }
    ]"
    :role="hasCollision ? 'alert' : 'group'"
    :aria-label="ariaLabel"
    :aria-live="hasCollision ? 'assertive' : 'off'"
  >
    <span v-if="hasCollision" class="ks-allergen-badge__alert-icon" aria-hidden="true">⚠</span>
    <ul class="ks-allergen-badge__list">
      <li
        v-for="code in visibleAllergens"
        :key="code"
        :class="[
          'ks-allergen-badge__item',
          { 'ks-allergen-badge__item--collision': collisionSet.has(code) }
        ]"
        :title="localize(code)"
      >
        <span class="ks-allergen-badge__icon" aria-hidden="true">{{ iconFor(code) }}</span>
        <span class="ks-allergen-badge__code">{{ localize(code) }}</span>
      </li>
    </ul>
  </div>
</template>

<script>
/**
 * KsAllergenBadge — Liste des allergènes d'un item, avec alerte si
 * intersection avec les allergènes déclarés par le client (DATA_CONTRACT §9.4).
 *
 * Props :
 *  - allergens         : string[] codes de l'item (`gluten`, `milk`, ...)
 *  - customerAllergens : string[] codes déclarés par le client (scan loyalty)
 *  - compact           : true → n'affiche que les codes en intersection
 *
 * Règles :
 *  - Si `customerAllergens ∩ allergens` ≠ ∅ → role=alert + cadre rouge.
 *  - Si `allergens` vide → composant invisible (v-if).
 *  - Jamais de PII dans les attributs ARIA. Les codes sont publics (UE 1169/2011).
 *
 * Le mapping code→icône utilise les emojis UE standards. Le label est
 * localisé via `$t('allergens.<code>')` si disponible.
 */
const ICONS = {
    gluten: '🌾', crustaceans: '🦐', eggs: '🥚', fish: '🐟',
    peanuts: '🥜', soy: '🌱', milk: '🥛', tree_nuts: '🌰',
    celery: '🥬', mustard: '🫘', sesame: '🌻', sulphites: '🍷',
    lupin: '🌼', molluscs: '🐚',
};

export default {
    name: 'KsAllergenBadge',
    props: {
        allergens: { type: Array, default: () => [] },
        customerAllergens: { type: Array, default: () => [] },
        compact: { type: Boolean, default: false },
    },
    computed: {
        collisionSet() {
            return new Set(this.customerAllergens || []);
        },
        hasCollision() {
            return (this.allergens || []).some((a) => this.collisionSet.has(a));
        },
        visibleAllergens() {
            if (!Array.isArray(this.allergens)) return [];
            if (this.compact) {
                return this.allergens.filter((a) => this.collisionSet.has(a));
            }
            return this.allergens;
        },
        ariaLabel() {
            if (this.hasCollision) {
                return this.$t
                    ? this.$t('kiosk.allergens.warning_aria')
                    : 'Warning: this product contains allergens you declared';
            }
            return this.$t
                ? this.$t('kiosk.allergens.list_aria')
                : 'Allergens';
        },
    },
    methods: {
        iconFor(code) {
            return ICONS[code] || '•';
        },
        localize(code) {
            if (!code) return '';
            if (this.$t) {
                const key = `allergens.${code}`;
                const translated = this.$t(key);
                if (translated && translated !== key) return translated;
            }
            return code;
        },
    },
};
</script>

<style scoped>
.ks-allergen-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--kiosk-space-2, 8px);
    padding: 4px 10px;
    border-radius: var(--kiosk-radius-2, 10px);
    background: var(--kiosk-surface-alt, #F7F3EC);
    color: var(--kiosk-text-muted, #5A5A5A);
    font-size: calc(12px * var(--kiosk-text-scale, 1));
    line-height: 1.2;
}

.ks-allergen-badge--warning {
    background: #FCE6E8;
    color: var(--kiosk-error, #C21E2F);
    border: 1px solid var(--kiosk-error, #C21E2F);
    animation: ks-allergen-pulse 1.4s ease-in-out infinite;
}

@keyframes ks-allergen-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(194, 30, 47, 0.25); }
    50% { box-shadow: 0 0 0 6px rgba(194, 30, 47, 0); }
}

.ks-allergen-badge__alert-icon {
    font-weight: 900;
    font-size: 1.1em;
}

.ks-allergen-badge__list {
    list-style: none;
    display: inline-flex;
    flex-wrap: wrap;
    gap: var(--kiosk-space-2, 8px);
    margin: 0;
    padding: 0;
}

.ks-allergen-badge__item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.ks-allergen-badge__item--collision {
    font-weight: 700;
}

.ks-allergen-badge__icon {
    font-size: 1em;
}

.ks-allergen-badge__code {
    text-transform: capitalize;
    letter-spacing: 0.01em;
}

/* Respecte prefers-reduced-motion (WCAG 2.3.3) et le toggle explicite. */
@media (prefers-reduced-motion: reduce) {
    .ks-allergen-badge--warning { animation: none; }
}
:global([data-kiosk-reduced-motion="true"]) .ks-allergen-badge--warning {
    animation: none;
}
</style>
