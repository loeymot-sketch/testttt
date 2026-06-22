<template>
  <div class="kiosk-step-frites-style" data-testid="kiosk-step-frites-style">
    <h3 class="kiosk-step-title">{{ stepTitle }}</h3>
    <p class="kiosk-step-subtitle">{{ stepSubtitle }}</p>

    <div class="kiosk-frites-style-grid" role="radiogroup" :aria-label="stepTitle">
      <!-- Card NATURE (default — no extra selected, no price label) -->
      <div
        class="kiosk-frites-style-card kiosk-frites-style-card--nature"
        :class="{ selected: !selectedExtraId }"
        role="radio"
        :aria-checked="!selectedExtraId"
        tabindex="0"
        data-testid="kiosk-frites-style-nature"
        @click="select(null)"
        @keydown.enter.prevent="select(null)"
        @keydown.space.prevent="select(null)"
      >
        <div class="kiosk-frites-style-media kiosk-frites-style-media--nature">
          <span class="kiosk-frites-style-emoji" aria-hidden="true">🍟</span>
        </div>
        <span class="kiosk-frites-style-name">{{ natureLabel }}</span>
      </div>

      <!-- Cards UPGRADE (Cheddar fondu, Cheddar + Oignons) — 20% larger psychologique -->
      <div
        v-for="extra in upgradeExtras"
        :key="extra.id"
        class="kiosk-frites-style-card kiosk-frites-style-card--upgrade"
        :class="[{ selected: selectedExtraId === extra.id }, variantClass(extra)]"
        role="radio"
        :aria-checked="selectedExtraId === extra.id"
        tabindex="0"
        :data-testid="`kiosk-frites-style-upgrade-${extra.id}`"
        @click="select(extra.id)"
        @keydown.enter.prevent="select(extra.id)"
        @keydown.space.prevent="select(extra.id)"
      >
        <div
          class="kiosk-frites-style-media"
          :class="`kiosk-frites-style-media--${variantSlug(extra)}`"
        >
          <span class="kiosk-frites-style-emoji" aria-hidden="true">{{ variantEmoji(extra) }}</span>
          <span class="kiosk-frites-style-emoji-overlay" aria-hidden="true">🍟</span>
        </div>
        <span class="kiosk-frites-style-name">{{ extra.name }}</span>
        <span class="kiosk-frites-style-price">+{{ formatPrice(parseFloat(extra.price) || 0) }}</span>
      </div>
    </div>
  </div>
</template>

<script>
import { kioskPriceMixin } from '../../../../helpers/kioskFormatPrice';

/**
 * KioskStepFritesStyleComponent — Choix progressif du style des frites.
 * -----------------------------------------------------------------------------
 * Owner gate (2026-05-10) : 3 niveaux exclusifs (radio) :
 *  - Nature (default — aucun extra, pas de prix label)
 *  - Cheddar fondu (+1€) — image 20% plus grande pour aspect marketing
 *  - Cheddar + Oignons croustillants (+2€) — même taille que cheddar fondu
 *
 * Source de données : `item.extras` filtrés par `group_label === 'frites_style'`.
 * Migration DB : 2026_05_10_040000_add_frites_style_upgrade_extras (8 rows).
 *
 * Sélection via state : `selections.fritesStyleExtraId = number | null`
 *  - null = Nature (default, aucun supplément ajouté à l'order payload)
 *  - id = extra sélectionné (id 709|711 Cheddar fondu, 710|712 Cheddar+Oignons
 *         selon item parent ; le payload submitted au backend inclut cet id
 *         dans item_extras → PricingService applique le prix à l'order →
 *         synchro POS naturelle via DB partagée).
 */
export default {
    name: 'KioskStepFritesStyle',
    mixins: [kioskPriceMixin],
    props: {
        item: { type: Object, required: true },
        selections: { type: Object, required: true },
    },
    emits: ['update'],
    computed: {
        upgradeExtras() {
            const extras = Array.isArray(this.item?.extras) ? this.item.extras : [];
            return extras
                .filter((e) => e?.group_label === 'frites_style')
                .sort((a, b) => (parseFloat(a.price) || 0) - (parseFloat(b.price) || 0));
        },
        selectedExtraId() {
            const id = this.selections?.fritesStyleExtraId;
            return id == null ? null : Number(id);
        },
        natureLabel() {
            return this.$t('kiosk.wizard.step.frites_style.nature') || 'Frites nature';
        },
        stepTitle() {
            return this.$t('kiosk.wizard.step.frites_style.title') || 'Personnalisez vos frites';
        },
        stepSubtitle() {
            return this.$t('kiosk.wizard.step.frites_style.subtitle')
                || 'Choisissez votre style préféré';
        },
    },
    methods: {
        select(extraId) {
            this.$emit('update', 'fritesStyleExtraId', extraId);
        },
        /**
         * V3.7 (2026-05-10) — round 3 fix C-002 P1.
         * Owner gate (Round 3) : la borne ne doit JAMAIS afficher l'image de
         * l'item parent (salade/tenders) sur les cards `frites_style`. Comme
         * `item_extras` ne possède pas de colonne thumb/image_url et qu'aucun
         * asset dédié n'a été commit, on rend chaque card visuellement distincte
         * via un emoji principal + emoji frites en overlay + une teinte de
         * fond unique. Discriminant : nom de l'extra (case-insensitive).
         */
        variantSlug(extra) {
            const name = String(extra?.name || '').toLowerCase();
            if (name.includes('oignon')) return 'cheddar-oignons';
            if (name.includes('cheddar')) return 'cheddar';
            return 'upgrade';
        },
        variantClass(extra) {
            return `kiosk-frites-style-card--${this.variantSlug(extra)}`;
        },
        variantEmoji(extra) {
            const slug = this.variantSlug(extra);
            if (slug === 'cheddar-oignons') return '🧅';
            if (slug === 'cheddar') return '🧀';
            return '🍟';
        },
    },
};
</script>

<style scoped>
.kiosk-step-frites-style {
    padding: 24px 16px 32px;
    text-align: center;
}

.kiosk-step-title {
    font-size: 1.6rem;
    font-weight: 900;
    color: #0F0F0F;
    margin: 0 0 8px;
    letter-spacing: -0.3px;
}

.kiosk-step-subtitle {
    font-size: 1rem;
    color: #5A5A5A;
    margin: 0 0 28px;
}

/* Grid 3 columns équivalentes ; le visuel "20% plus grand" est porté par la
   taille du media (image) et le padding interne, pas par la largeur de la
   colonne (sinon le texte tronque). Centered.
   V3.6 owner gate (2026-05-10) : marketing psychology via image+padding +
   border-color hover, pas via grid stretch. */
.kiosk-frites-style-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    align-items: stretch;
    justify-items: center;
    max-width: 980px;
    margin: 0 auto;
}

.kiosk-frites-style-card {
    background: #FFFFFF;
    border: 2.5px solid #E5E5E5;
    border-radius: 22px;
    padding: 18px 14px 16px;
    cursor: pointer;
    transition: border-color 160ms ease, box-shadow 160ms ease, transform 120ms ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    width: 100%;
    -webkit-tap-highlight-color: transparent;
}

.kiosk-frites-style-card:hover {
    border-color: #F4501E;
    box-shadow: 0 8px 24px rgba(244, 80, 30, 0.12);
}

.kiosk-frites-style-card.selected {
    border-color: #F4501E;
    background: #FFF8F4;
    box-shadow: 0 12px 32px rgba(244, 80, 30, 0.20);
}

.kiosk-frites-style-card:active {
    transform: scale(0.98);
}

.kiosk-frites-style-card:focus-visible {
    outline: 3px solid #2563EB;
    outline-offset: 2px;
}

/* Card NATURE — taille normale (100%) */
.kiosk-frites-style-card--nature .kiosk-frites-style-media {
    width: 110px;
    height: 110px;
}

/* Cards UPGRADE — 20% plus grand (psychologique marketing) */
.kiosk-frites-style-card--upgrade .kiosk-frites-style-media {
    width: 132px;
    height: 132px;
}

.kiosk-frites-style-media {
    border-radius: 16px;
    overflow: hidden;
    background: #FAFAFA;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #EFEFEF;
    flex-shrink: 0;
    position: relative;
}

/* V3.7 (2026-05-10) round 3 fix C-002 — fond distinct par variante pour
   ne plus jamais afficher l'image de l'item parent. */
.kiosk-frites-style-media--nature {
    background: linear-gradient(135deg, #FFF8E5 0%, #FFE9B4 100%);
    border-color: #F4B400;
}

.kiosk-frites-style-media--cheddar {
    background: linear-gradient(135deg, #FFEFC2 0%, #FFC85A 100%);
    border-color: #F2A33C;
}

.kiosk-frites-style-media--cheddar-oignons {
    background: linear-gradient(135deg, #FFE2C2 0%, #F08C3D 100%);
    border-color: #C25A1F;
}

.kiosk-frites-style-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.kiosk-frites-style-emoji {
    font-size: 56px;
    line-height: 1;
}

.kiosk-frites-style-card--upgrade .kiosk-frites-style-emoji {
    font-size: 64px;
}

/* Overlay frites emoji to keep "frites" identity on upgrade cards while the
   primary emoji communicates the topping (cheddar / oignons). */
.kiosk-frites-style-emoji-overlay {
    position: absolute;
    bottom: 6px;
    right: 8px;
    font-size: 28px;
    line-height: 1;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.18));
    pointer-events: none;
}

.kiosk-frites-style-name {
    font-size: 1rem;
    font-weight: 700;
    color: #0F0F0F;
    text-align: center;
    line-height: 1.25;
    min-height: 2.5em;
    display: flex;
    align-items: center;
    justify-content: center;
}

.kiosk-frites-style-card--upgrade .kiosk-frites-style-name {
    font-size: 1.05rem;
}

.kiosk-frites-style-price {
    font-size: 1.1rem;
    font-weight: 900;
    color: #F4501E;
    line-height: 1;
    letter-spacing: -0.2px;
    background: #FFE8DD;
    padding: 6px 14px;
    border-radius: 999px;
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    .kiosk-frites-style-card {
        transition: border-color 80ms ease;
    }
}
</style>
