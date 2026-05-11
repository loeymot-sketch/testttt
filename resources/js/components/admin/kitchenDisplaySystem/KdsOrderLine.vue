<!--
  [kds/sprint-2 V-2] Single line renderer consumed by KdsOrderCard.

  Receives a typed line object emitted by `renderItem()` and renders it.
  No per-category branching — the parent's iteration is generic.

  Line types handled:
    header        — qty + name + ⚠ if hasAllergen
    variation     — "Pain : Baguette traditionnelle"
    variation-flat— "Avec : 2 Merguez, 1 Brochette"
    supplement    — "+ Cheddar" in yellow italic
    addon         — generic indented "▸ Label"
    menu_child    — "▸ Frites Moyennes" (menu_full/menu_frites/menu_boisson)
    instruction   — italic note, classified note/exclusion/allergen
    allergen      — "⚠ Allergènes : gluten · lait" orange-bold-italic
-->
<template>
  <div class="kds-line" :class="`kds-line--${line.type}`">
    <!-- header — qty + name + allergen icon -->
    <template v-if="line.type === 'header'">
      <div class="kds-line__header">
        <span class="kds-line__qty">{{ line.qty }}<span class="kds-line__qty-x">×</span></span>
        <span v-if="line.hasAllergen" class="kds-line__allergen-icon" aria-label="allergen">⚠</span>
        <span class="kds-line__name">{{ line.label }}</span>
      </div>
    </template>

    <!-- grouped variation: "Pain : Baguette" -->
    <template v-else-if="line.type === 'variation'">
      <div class="kds-line__variation">
        <span class="kds-line__group">{{ groupLabel }}</span>
        <span class="kds-line__sep"> : </span>
        <span class="kds-line__value">{{ line.label }}</span>
      </div>
    </template>

    <!-- flat assiette: "Avec : a, b, c" -->
    <template v-else-if="line.type === 'variation-flat'">
      <div class="kds-line__variation kds-line__variation--flat">
        <span class="kds-line__group">{{ $t('label.kds_group_avec') }}</span>
        <span class="kds-line__sep"> : </span>
        <span class="kds-line__value">{{ line.label }}</span>
      </div>
    </template>

    <!-- supplement: "+ Cheddar" in yellow italic -->
    <template v-else-if="line.type === 'supplement'">
      <div class="kds-line__supplement">{{ line.label }}</div>
    </template>

    <!-- menu_child: "▸ Frites Moyennes" (formule child) -->
    <template v-else-if="line.type === 'menu_child'">
      <div class="kds-line__menu-child">
        <span class="kds-line__menu-arrow">▸</span>
        <span>{{ line.label }}</span>
      </div>
    </template>

    <!-- generic addon -->
    <template v-else-if="line.type === 'addon'">
      <div class="kds-line__addon">
        <span class="kds-line__menu-arrow">▸</span>
        <span>{{ line.label }}</span>
      </div>
    </template>

    <!-- free-text instruction -->
    <template v-else-if="line.type === 'instruction'">
      <div :class="['kds-line__instruction', line.visualClass]">
        <span class="kds-line__instruction-prefix">·</span>
        <span>{{ line.label }}</span>
      </div>
    </template>

    <!-- allergen codes block -->
    <template v-else-if="line.type === 'allergen'">
      <div class="kds-line__allergen-block" role="alert">
        <span class="kds-line__allergen-icon">⚠</span>
        <span class="kds-line__allergen-label">{{ allergenLabel }}</span>
        <span class="kds-line__allergen-codes">{{ joinedCodes }}</span>
      </div>
    </template>
  </div>
</template>

<script>
export default {
  name: 'KdsOrderLine',
  props: {
    line: {
      type: Object,
      required: true,
    },
  },
  computed: {
    groupLabel() {
      const key = `label.kds_group_${this.line.group || 'other'}`;
      // Fallback to raw group key if i18n misses (defensive — i18n parity
      // CI script enforces presence, but a freshly added category should
      // not blank the line).
      const translated = this.$t(key);
      return translated === key ? this.line.group : translated;
    },
    allergenLabel() {
      return this.$t('label.kds_allergen_warning_prefix') || 'Allergènes :';
    },
    joinedCodes() {
      const codes = Array.isArray(this.line.codes) ? this.line.codes : [];
      // Translate each code via existing allergens.* keys; fallback to raw code.
      return codes
        .map((c) => {
          const k = `allergens.${c}`;
          const t = this.$t(k);
          return t === k ? c : t;
        })
        .join(' · ');
    },
  },
};
</script>

<style scoped>
.kds-line {
  font-family: 'Inter', system-ui, sans-serif;
}

/* HEADER — qty + allergen icon + name */
.kds-line--header {
  padding: 0.625rem 0;
}
.kds-line__header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}
.kds-line__qty {
  display: inline-block;
  min-width: 42px;
  text-align: end;
  color: #111827;
  font-size: 26px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}
.kds-line__qty-x {
  font-size: 18px;
  opacity: 0.55;
  margin-inline-start: 2px;
}
.kds-line__allergen-icon {
  color: #F97316;
  font-size: 20px;
  line-height: 1;
  margin-top: -2px;
}
.kds-line__name {
  flex: 1;
  color: #111827;
  font-size: 22px;
  font-weight: 500;
  line-height: 1.15;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* VARIATION — "Pain : Baguette" */
.kds-line__variation,
.kds-line__variation--flat {
  color: #4B5563;
  font-size: 16px;
  line-height: 1.3;
  padding-inline-start: 56px;
  margin-top: 4px;
  display: flex;
  flex-wrap: wrap;
}
.kds-line__group {
  font-weight: 600;
  color: #374151;
  text-transform: capitalize;
}
.kds-line__sep {
  color: #6B7280;
}
.kds-line__value {
  color: #4B5563;
}

/* SUPPLEMENT — yellow italic */
.kds-line__supplement {
  color: #CA8A04;
  font-size: 16px;
  font-style: italic;
  font-weight: 600;
  padding-inline-start: 56px;
  margin-top: 4px;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* MENU CHILD — formule member */
.kds-line__menu-child,
.kds-line__addon {
  display: flex;
  align-items: baseline;
  gap: 6px;
  color: #1F2937;
  font-size: 16px;
  font-weight: 500;
  padding-inline-start: 56px;
  margin-top: 4px;
  line-height: 1.3;
}
.kds-line__menu-arrow {
  color: #6B7280;
  font-weight: 700;
  flex-shrink: 0;
}

/* INSTRUCTION — italic note with class-based emphasis */
.kds-line__instruction {
  display: flex;
  align-items: baseline;
  gap: 6px;
  font-size: 16px;
  font-style: italic;
  padding-inline-start: 56px;
  margin-top: 4px;
  line-height: 1.3;
}
.kds-line__instruction-prefix {
  opacity: 0.55;
  font-style: normal;
}
.kds-instruction--note {
  color: #4B5563;
}
.kds-instruction--exclusion {
  color: #92400E;
  font-weight: 600;
}
.kds-instruction--allergen {
  color: #C2410C;
  font-weight: 700;
}

/* ALLERGEN BLOCK — "⚠ Allergènes : gluten · lait" */
.kds-line__allergen-block {
  display: flex;
  align-items: baseline;
  gap: 6px;
  flex-wrap: wrap;
  color: #C2410C;
  font-size: 16px;
  font-style: italic;
  font-weight: 700;
  padding-inline-start: 40px;
  margin-top: 6px;
  margin-bottom: 4px;
  line-height: 1.3;
  background: rgba(249, 115, 22, 0.06);
  border-inline-start: 4px solid #F97316;
  border-radius: 6px;
  padding-top: 4px;
  padding-bottom: 4px;
  padding-inline-end: 10px;
}
.kds-line__allergen-label {
  font-weight: 800;
}
.kds-line__allergen-codes {
  font-weight: 600;
}

[dir="rtl"] .kds-line__qty {
  text-align: end;
}
</style>
