<!--
  [kds/sprint-2 V-2] Single line renderer consumed by KdsOrderCard.

  Receives a typed line object emitted by `renderItem()` and renders it.
  No per-category branching — the parent's iteration is generic.

  Line types handled:
    header        — qty + name + ⚠ if hasAllergen
    variation     — "Pain : Baguette traditionnelle"
    variation-flat— "Avec : 2 Merguez, 1 Brochette"
    supplement    — "Cheddar" bold white on black (inverted)
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
        <span class="kds-line__qty" :class="{ 'kds-line__qty--multi': isMulti }">{{ line.qty }}<span class="kds-line__qty-x">×</span></span>
        <span v-if="line.hasAllergen" class="kds-line__allergen-icon" :aria-label="$t('label.kds_line_allergen_icon_aria')">⚠</span>
        <span v-if="line.hasSupplement" class="kds-line__hash" aria-hidden="true">#</span>
        <span class="kds-line__name">{{ line.label }}</span>
      </div>
    </template>

    <!-- symbolic-main — qty + "G | SANDWICH | P | STO | SAM" (kitchen shorthand) -->
    <template v-else-if="line.type === 'symbolic-main'">
      <div class="kds-line__symbolic">
        <span class="kds-line__qty" :class="{ 'kds-line__qty--multi': isMulti }">{{ line.qty }}<span class="kds-line__qty-x">×</span></span>
        <span v-if="line.hasAllergen" class="kds-line__allergen-icon" :aria-label="$t('label.kds_line_allergen_icon_aria')">⚠</span>
        <span v-if="line.hasSupplement" class="kds-line__hash" aria-hidden="true">#</span>
        <span class="kds-line__symbolic-text">{{ line.label }}</span>
      </div>
    </template>

    <!-- symbolic-menu — "MENU" / "F" badge -->
    <template v-else-if="line.type === 'symbolic-menu'">
      <div class="kds-line__symbolic-menu">{{ line.label }}</div>
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

    <!-- supplement: "Cheddar" bold WHITE on a BLACK box (inverted reading, never yellow) -->
    <template v-else-if="line.type === 'supplement'">
      <div class="kds-line__supplement">{{ supplementText }}</div>
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
        <span class="kds-line__instruction-text">{{ line.label }}</span>
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
    // [GOAL REMARQUES 2026-10-03 · R-054] « 2 × » doit sauter aux yeux : fond noir dès qu'il y en a plus d'un.
    isMulti() {
      return Number(this.line.qty) > 1;
    },
    // [GOAL #7 2026-10-02] Le gras blanc sur noir porte à lui seul le signal « supplément » :
    // l'étoile emoji (jaune) n'est plus affichée — jamais de jaune sur la fiche cuisine.
    supplementText() {
      return String(this.line.label || '').replace(/^\s*⭐\s*/u, '');
    },
    groupLabel() {
      const key = `label.kds_group_${this.line.group || 'other'}`;
      // Fallback to raw group key if i18n misses (defensive — i18n parity
      // CI script enforces presence, but a freshly added category should
      // not blank the line).
      const translated = this.$t(key);
      return translated === key ? this.line.group : translated;
    },
    allergenLabel() {
      return this.$t('label.kds_allergen_warning_prefix');
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
/* [GOAL REMARQUES 2026-10-03 · R-054] Propriétaire : « fois deux […] avec une arrière-plan en noir ».
   Quantité > 1 en blanc sur noir ; le « × » reste lisible (opacité neutralisée). */
.kds-line__qty--multi {
  background: #000000;
  color: #FFFFFF;
  border-radius: 4px;
  padding: 2px 6px;
}
.kds-line__qty--multi .kds-line__qty-x {
  opacity: 1;
}
.kds-line__allergen-icon {
  color: #7C2D12;
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
  /* [T-6.1 SAUCE-TRONQUEE 2026-08-15 · GOAL_CONFORT_MAX] 2 lignes coupait net
     (webkit-line-clamp ajoute une ellipse, mais le contenu au-delà — souvent la
     dernière sauce choisie sur un nom long — reste invisible et illisible pour le
     cuisinier). 3 lignes donne 50% de marge de plus avant toute coupe. */
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* SYMBOLIC MAIN — qty + pipe-delimited shorthand. [KDS-COMPACT 2026-07-05] Owner : texte
   plus PETIT (18px) → 1 seule ligne par produit (code 3 lettres) au lieu de 2-3. */
.kds-line--symbolic-main {
  padding: 0.2rem 0 0.1rem;
}
.kds-line__symbolic {
  display: flex;
  align-items: baseline;
  gap: 8px;
}
.kds-line__hash {
  /* [GOAL #7 2026-10-02] « # » gras en tête d'une ligne produit qui porte un supplément. */
  color: #000000;
  font-size: 20px;
  font-weight: 900;
  line-height: 1.2;
}
.kds-line__symbolic-text {
  flex: 1;
  color: #111827;
  font-size: 18px;
  font-weight: 800;
  line-height: 1.2;
  letter-spacing: 0.3px;
  font-variant-numeric: tabular-nums;
  /* [KDS-UI-MULTI 2026-08-07 owner] `break-word` coupait EN PLEIN MOT sur les cartes
     étroites : mesuré à 8 colonnes, « MENU » s'affichait « MEN / U » et « Coca-Cola »
     devenait « Coca / - / Cola ». Le cuisinier lit des symboles courts ; les couper les rend
     méconnaissables. On ne casse plus qu'entre les mots — un mot trop long débordera plutôt
     que de devenir illisible, et c'est le moindre mal. */
  overflow-wrap: break-word;
  word-break: normal;
}

/* SYMBOLIC MENU — "MENU" / "F" badge */
.kds-line__symbolic-menu {
  display: inline-block;
  margin-top: 5px;
  margin-inline-start: 56px;
  padding: 2px 10px;
  border-radius: 6px;
  background: #111827;
  color: #FFFFFF;
  font-size: 16px;
  font-weight: 800;
  letter-spacing: 1px;
}

/* VARIATION — "Pain : Baguette" */
.kds-line__variation,
.kds-line__variation--flat {
  color: #374151; /* ≥ 7:1 sur tous les fonds de carte (#4B5563 tombait à 6,2 sur fond d'alerte) */
  font-size: 16px;
  line-height: 1.3;
  padding-inline-start: 56px;
  margin-top: 4px;
  display: flex;
  flex-wrap: wrap;
}
.kds-line__group {
  font-weight: 600;
  color: #1F2937;
  text-transform: capitalize;
}
.kds-line__sep {
  color: #374151;
}
.kds-line__value {
  color: #374151;
}

/* SUPPLEMENT — EN LIGNE (côte à côte). [KDS-INLINE-SUPP 2026-07-05] Les suppléments
   s'affichent l'un À CÔTÉ de l'autre (pas chacun sur sa ligne).
   [GOAL #7 2026-10-02] LECTURE INVERSÉE : gras BLANC sur cadre NOIR (21:1), JAMAIS jaune —
   le jaune (#CA8A04) tombait à 2,7:1 sur le fond jaune de la fiche. */
.kds-line--supplement {
  display: inline-block;
  vertical-align: top;
}
.kds-line--supplement:first-of-type {
  padding-inline-start: 44px; /* aligne le groupe suppléments sous le produit */
}
/* [GOAL REMARQUES 2026-10-03 · R-071] Propriétaire, 03/10 : « agrandir les suppléments ». En 15 px ils
   restaient PLUS PETITS que le produit (18 px symbolique, 22 px en-tête) : on passe à 22 px, au moins
   la taille du nom. `nowrap` faisait déborder un nom long hors d'une fiche étroite (8 colonnes) : on
   revient à la ligne ENTRE les mots seulement (jamais dans un mot — régression C4-001), borné à la fiche. */
.kds-line__supplement {
  display: inline-block;
  background: #000000;
  color: #FFFFFF;
  padding: 3px 10px;
  border-radius: 5px;
  font-size: 22px;
  font-style: normal;
  font-weight: 900;
  margin-inline-end: 8px;
  margin-bottom: 4px;
  line-height: 1.25;
  max-width: 100%;
  white-space: normal;
  word-break: normal;
  overflow-wrap: normal;
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
  color: #374151;
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
/* [W6-ADV B-3 2026-07-06] Note multi-lignes (« BOISSON: Hawaï 33cl\n[bien cuit svp] ») :
   respecter les sauts de ligne comme le ticket (lignes ** séparées) — sans pre-line,
   2 notes distinctes se lisaient d'un seul bloc à l'écran. */
.kds-line__instruction-text {
  white-space: pre-line;
}
.kds-instruction--note {
  color: #374151;
}
.kds-instruction--exclusion {
  color: #7C2D12;
  font-weight: 600;
}
.kds-instruction--allergen {
  color: #7C2D12;
  font-weight: 700;
}

/* ALLERGEN BLOCK — "⚠ Allergènes : gluten · lait" */
.kds-line__allergen-block {
  display: flex;
  align-items: baseline;
  gap: 6px;
  flex-wrap: wrap;
  color: #7C2D12;
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
