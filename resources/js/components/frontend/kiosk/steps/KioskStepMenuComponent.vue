<template>
  <div class="kiosk-step-menu">
    <h3 class="kiosk-step-title">
      {{ isStandaloneDrinkStep ? $t('kiosk.wizard.menu.boisson_section_title') : $t('kiosk.wizard.menu.title') }}
    </h3>

    <div class="kiosk-menu-info">
      <span v-if="menuInfoBadge && !isStandaloneDrinkStep" class="kiosk-info-badge">{{ menuInfoBadge }}</span>
      <span v-if="menuPrice > 0" class="kiosk-menu-price">
        +{{ formatPrice(menuPrice) }}
      </span>
    </div>

    <div
      v-if="needsExplicitMenuChoice && !isStandaloneDrinkStep"
      class="kiosk-validation-hint kiosk-menu-validation-hint"
      role="status"
    >
      {{ $t('kiosk.wizard.menu.hint_need_choice') }}
    </div>

    <div v-if="!isStandaloneDrinkStep" class="kiosk-menu-options" role="radiogroup" :aria-label="$t('kiosk.wizard.menu.title')">
      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'full' }"
        role="radio"
        tabindex="0"
        :aria-checked="localChoice === 'full'"
        :aria-label="$t('kiosk.wizard.menu.full_name')"
        @click="selectChoice('full')"
        @keydown.enter.prevent="selectChoice('full')"
        @keydown.space.prevent="selectChoice('full')"
      >
        <span class="kiosk-menu-emoji">🍟🥤</span>
        <span class="kiosk-menu-name">{{ $t('kiosk.wizard.menu.full_name') }}</span>
        <span class="kiosk-menu-desc">{{ $t('kiosk.wizard.menu.full_desc') }}</span>
        <span v-if="localChoice === 'full'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>

      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'frites' }"
        role="radio"
        tabindex="0"
        :aria-checked="localChoice === 'frites'"
        :aria-label="$t('kiosk.wizard.menu.frites_name')"
        @click="selectChoice('frites')"
        @keydown.enter.prevent="selectChoice('frites')"
        @keydown.space.prevent="selectChoice('frites')"
      >
        <span class="kiosk-menu-emoji">🍟</span>
        <span class="kiosk-menu-name">{{ $t('kiosk.wizard.menu.frites_name') }}</span>
        <span class="kiosk-menu-desc">{{ $t('kiosk.wizard.menu.frites_desc') }}</span>
        <span v-if="localChoice === 'frites'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>

      <div
        v-if="showBoissonOnlyMenuCard"
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'boisson' }"
        role="radio"
        tabindex="0"
        :aria-checked="localChoice === 'boisson'"
        :aria-label="$t('kiosk.wizard.menu.boisson_name')"
        @click="selectChoice('boisson')"
        @keydown.enter.prevent="selectChoice('boisson')"
        @keydown.space.prevent="selectChoice('boisson')"
      >
        <span class="kiosk-menu-emoji">🥤</span>
        <span class="kiosk-menu-name">{{ $t('kiosk.wizard.menu.boisson_name') }}</span>
        <span class="kiosk-menu-desc">{{ $t('kiosk.wizard.menu.boisson_desc') }}</span>
        <span v-if="localChoice === 'boisson'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>

      <div
        class="kiosk-menu-card"
        :class="{ selected: localChoice === 'none' }"
        role="radio"
        tabindex="0"
        :aria-checked="localChoice === 'none'"
        :aria-label="$t('kiosk.wizard.menu.none_name')"
        @click="selectChoice('none')"
        @keydown.enter.prevent="selectChoice('none')"
        @keydown.space.prevent="selectChoice('none')"
      >
        <span class="kiosk-menu-emoji">🚫</span>
        <span class="kiosk-menu-name">{{ $t('kiosk.wizard.menu.none_name') }}</span>
        <span class="kiosk-menu-desc">{{ $t('kiosk.wizard.menu.none_desc') }}</span>
        <span v-if="localChoice === 'none'" class="kiosk-menu-action active">✓</span>
        <span v-else class="kiosk-menu-action">+</span>
      </div>
    </div>

    <!-- [Round 5 — 2026-05-10] Inline frites_style upgrade section REMOVED.
         Owner feedback: la duplication entre la page Menu et la page dédiée
         « Quel style de frites ? » nuisait à la fluidité. Le choix Nature /
         Cheddar fondu / Cheddar + Oignons est désormais EXCLUSIVEMENT porté
         par KioskStepFritesStyleComponent (étape dédiée). -->

    <div v-if="(showBoissonChoice || isStandaloneDrinkStep) && boissonList.length > 0" class="kiosk-boisson-section">
      <h4 v-if="!isStandaloneDrinkStep" class="kiosk-subtitle">{{ $t('kiosk.wizard.menu.boisson_section_title') }}</h4>
      <p v-if="localChoice === 'full'" class="kiosk-boisson-included">{{ $t('kiosk.wizard.menu.boisson_one_included') }}</p>
      <div
        v-if="needsExplicitBoissonSelection"
        class="kiosk-validation-hint kiosk-boisson-validation-hint"
        role="status"
      >
        {{ $t('kiosk.wizard.menu.boisson_hint') }}
      </div>
      <div class="kiosk-boisson-grid" role="radiogroup" :aria-label="$t('kiosk.wizard.menu.boisson_section_title')">
        <div
          v-for="boisson in boissonList"
          :key="boisson.id ?? boisson.name"
          class="kiosk-boisson-card"
          :class="{ selected: localBoisson === (boisson.id ?? boisson.name) }"
          role="radio"
          tabindex="0"
          :aria-checked="localBoisson === (boisson.id ?? boisson.name)"
          :aria-label="boisson.name"
          @click="selectBoisson(boisson)"
          @keydown.enter.prevent="selectBoisson(boisson)"
          @keydown.space.prevent="selectBoisson(boisson)"
        >
          <div class="kiosk-boisson-visual">
            <img
              v-if="boisson.displayThumb && !brokenBoissonThumbs[boissonThumbKey(boisson)]"
              :src="boisson.displayThumb"
              :alt="boisson.name"
              class="kiosk-boisson-img"
              loading="lazy"
              @error="onBoissonThumbError(boisson)"
            />
            <span v-else class="kiosk-boisson-emoji">{{ boisson.emoji }}</span>
          </div>
          <span class="kiosk-boisson-name">{{ boisson.name }}</span>
          <span
            v-if="isStandaloneDrinkStep && standaloneDrinkPrice > 0"
            class="kiosk-boisson-price"
          >+{{ formatPrice(standaloneDrinkPrice) }}</span>
          <span v-if="localBoisson === (boisson.id ?? boisson.name)" class="kiosk-menu-action active">✓</span>
          <span v-else class="kiosk-menu-action">+</span>
        </div>
      </div>
    </div>
    <div v-else-if="showBoissonChoice || isStandaloneDrinkStep" class="kiosk-boisson-section">
      <p class="kiosk-boisson-placeholder">{{ $t('kiosk.wizard.menu.boisson_counter') }}</p>
    </div>

    <!-- Sauces frites — catalogue variations (comme étape sauce), en bas -->
    <div v-if="showFritesSauce" class="kiosk-boisson-section kiosk-frites-sauce-section">
      <h4 class="kiosk-subtitle">{{ $t('kiosk.wizard.menu.frites_sauce_title') }}</h4>
      <div
        v-if="needsExplicitFritesSauceSelection"
        class="kiosk-validation-hint kiosk-frites-sauce-validation-hint"
        role="status"
      >
        {{ $t('kiosk.wizard.menu.frites_sauce_hint') }}
      </div>
      <div class="kiosk-boisson-grid" role="group" :aria-label="$t('kiosk.wizard.menu.frites_sauce_title')">
        <div
          v-for="sauce in fritesSauceRows"
          :key="sauce.key"
          class="kiosk-boisson-card"
          :class="{ selected: isFritesSauceSelected(sauce.key) }"
          role="checkbox"
          tabindex="0"
          :aria-checked="isFritesSauceSelected(sauce.key)"
          :aria-label="sauce.name"
          @click="toggleFritesSauce(sauce)"
          @keydown.enter.prevent="toggleFritesSauce(sauce)"
          @keydown.space.prevent="toggleFritesSauce(sauce)"
        >
          <div class="kiosk-boisson-visual">
            <img
              v-if="sauce.thumb && !brokenFritesSauceThumbs[sauce.key]"
              :src="sauce.thumb"
              :alt="sauce.name"
              class="kiosk-boisson-img"
              loading="lazy"
              @error="onFritesSauceThumbError(sauce.key)"
            />
            <span v-else class="kiosk-boisson-emoji">{{ sauce.emoji }}</span>
          </div>
          <span class="kiosk-boisson-name">{{ sauce.name }}</span>
          <span class="kiosk-frites-sauce-price">{{ fritesSaucePriceLabel(sauce.key) }}</span>
          <span v-if="getFritesSauceOrder(sauce.key) > 0" class="kiosk-sauce-order">{{ getFritesSauceOrder(sauce.key) }}</span>
          <span v-else class="kiosk-menu-action">+</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { kioskResolveImageSrc } from '../../../../helpers/kioskMedia';
import {
  kioskDrinkAddonRowsFromItem,
  kioskIsDrinkAddonName,
  kioskIsGenericDrinkOptionName,
} from '../../../../helpers/kioskDrinkAddons';
import { kioskPriceMixin } from '../../../../helpers/kioskFormatPrice';
import { getKioskExtraSauceUnitPrice, getKioskMenuAddonPrice } from '../../../../helpers/kioskPricing';
import { kioskSauceVariationRowsForItem } from '../../../../helpers/kioskSauceCatalog';
// [Round 5 — 2026-05-10] kioskIsBundledFritesMenuUpgradeExtra import removed
// after suppression of the inline frites_style duplication section. The dedicated
// KioskStepFritesStyleComponent is now the sole UI source for the upgrade.

export default {
  name: 'KioskStepMenu',
  mixins: [kioskPriceMixin],
  props: {
    step: Object,
    item: Object,
    selections: Object,
    /** Affiche l'option « Boisson seule » quand le wizard parent déclare la formule disponible. */
    showBoissonOnlyMenuCard: { type: Boolean, default: true },
  },
  emits: ['update'],
  data() {
    return {
      localChoice: this.selections.menuChoice || null,
      localBoisson: this.selections.boissonChoice || null,
      localFritesSauceOrder: this.normalizeFritesSauceOrder(this.selections),
      brokenBoissonThumbs: {},
      brokenFritesSauceThumbs: {},
    };
  },
  computed: {
    needsExplicitMenuChoice() {
      return this.localChoice === null || this.localChoice === undefined;
    },
    needsExplicitBoissonSelection() {
      if (!this.showBoissonChoice || this.boissonList.length === 0) return false;
      const b = this.localBoisson;
      return b === null || b === undefined || b === '';
    },
    needsExplicitFritesSauceSelection() {
      if (!this.showFritesSauce) return false;
      return this.localFritesSauceOrder.length === 0;
    },
    menuInfoBadge() {
      if (this.localChoice === 'none') return null;
      if (this.localChoice === 'full') return this.$t('kiosk.wizard.menu.badge_frites_boisson');
      if (this.localChoice === 'frites') return this.$t('kiosk.wizard.menu.badge_frites');
      if (this.localChoice === 'boisson') return this.$t('kiosk.wizard.menu.badge_boisson');
      return null;
    },
    menuPrice() {
      return getKioskMenuAddonPrice(this.item, this.localChoice);
    },
    // [P2-g / F3 borne 2026-07-18] Step boisson AUTONOME (ex. bol « Boisson Seule » +2,00 €).
    // Le mapping FROZEN `ADDON_ROLE_TO_TYPE['drink']='menu'` route ce step vers KioskStepMenu,
    // mais un bol (has_menu=false) n'a PAS de formule /menu/i : la boisson est un addon payant
    // autonome, pas une formule « Menu Complet ». On résout l'addon-ancre de facturation à
    // partir des choix du step composer × item.addons pour (a) rendre un sélecteur de boisson
    // propre et (b) émettre son addonId (sinon addonId:null → boisson jamais facturée).
    drinkStepBillingAddon() {
      const cs = this.step?.composer_step;
      if (!cs || cs.source_type !== 'addon') return null;
      const role = String(cs.addon_role || '').toLowerCase();
      if (role !== 'drink') return null;
      // Les formules (has_menu / addon /menu/i) restent gérées par le flow formule.
      if (this.item?.has_menu) return null;
      const addons = Array.isArray(this.item?.addons) ? this.item.addons : [];
      if (addons.some((a) => /menu/i.test(String(a?.addon_item_name || a?.name || '')))) return null;
      const choices = Array.isArray(cs.choices) ? cs.choices : [];
      for (const ch of choices) {
        const match = addons.find((a) => Number(a?.id) === Number(ch?.id));
        if (match) return match;
      }
      // Repli : tout addon-boisson générique porté par l'item (ex. « Boisson Seule »).
      return addons.find((a) => kioskIsGenericDrinkOptionName(String(a?.addon_item_name || a?.name || ''))) || null;
    },
    isStandaloneDrinkStep() {
      return this.drinkStepBillingAddon != null;
    },
    standaloneDrinkPrice() {
      const a = this.drinkStepBillingAddon;
      if (!a) return 0;
      return parseFloat(
        a.addon_item_convert_price ?? a.convert_price ?? a.price ?? a.addon_item_price ?? 0
      ) || 0;
    },
    showBoissonChoice() {
      return this.localChoice === 'full' || this.localChoice === 'boisson';
    },
    showFritesSauce() {
      if (this.localChoice !== 'full' && this.localChoice !== 'frites') return false;
      // [AUDIT 2026-04-17 C5] Politique catégorie : sauce déjà incluse via
      // l'étape principale Sauce — on ne redemande pas pour les frites.
      if (this.sauceIncludedFromCategory) return false;
      return true;
    },
    // [Round 5 — 2026-05-10] menuFritesUpgradeRows / showMenuFritesUpgrade /
    // selectedBundledUpgradeId computeds removed alongside the inline section.
    // The dedicated KioskStepFritesStyleComponent now reads item.extras with
    // group_label='frites_style' directly and writes to selections.fritesStyleExtraId.

    /**
     * Backward-compat alias: anciens tests / code externe utilisent `fritesSauceList`.
     */
    fritesSauceList() {
      return this.fritesSauceRows;
    },
    // [AUDIT 2026-04-17 C1] Plus de fallback hardcodé. La liste des sauces
    // frites provient EXCLUSIVEMENT du catalogue (mêmes variations que
    // l'étape Sauce). Si vide, l'option « Sans sauce » reste exposée
    // pour permettre au client d'avancer.
    fritesSauceRows() {
      const fromCatalog = kioskSauceVariationRowsForItem(this.item);
      if (fromCatalog.length === 0) {
        return [
          {
            key: 'sans',
            name: this.$t('kiosk.wizard.frites_sauce.sans'),
            emoji: '🚫',
            thumb: null,
          },
        ];
      }
      return [
        ...fromCatalog.map((r) => ({
          key: r.rowKey,
          name: r.name,
          emoji: r.emoji,
          thumb: r.thumb || null,
        })),
        {
          key: 'sans',
          name: this.$t('kiosk.wizard.frites_sauce.sans'),
          emoji: '🚫',
          thumb: null,
        },
      ];
    },
    // [AUDIT 2026-04-17 C5] Si la catégorie applique sauce_included_menu,
    // la sauce principale couvre aussi les frites — on masque la section
    // « sauce frites » pour éviter le doublon visuel.
    sauceIncludedFromCategory() {
      return Boolean(this.item?.sauce_included_menu);
    },
    boissonList() {
      const boissonAddons = kioskDrinkAddonRowsFromItem(this.item);
      if (boissonAddons.length > 0) {
        return boissonAddons.map((b) => this.mapBoissonAddonRow(b));
      }

      // [P2-g / F3 borne 2026-07-18] Step boisson autonome (bol) : les boissons viennent du
      // catalogue global (Coca, Fanta…) mais DOIVENT porter l'addonId de facturation
      // (« Boisson Seule » 2,00 €) pour que selectBoisson émette un addonId non-null → le
      // wizard frozen pousse l'addon → boisson facturée (avant : addonId:null → offerte).
      const rows = this.globalBoissonCatalogRows;
      const anchor = this.drinkStepBillingAddon;
      if (anchor && anchor.id != null) {
        const addonId = Number(anchor.id);
        if (Number.isFinite(addonId)) {
          return rows.map((r) => ({ ...r, addonId }));
        }
      }

      return rows;
    },
    globalBoissonCatalogRows() {
      const items = this.$store?.getters?.['kioskMenu/allItems']
        || this.$store?.state?.kioskMenu?.items
        || [];
      if (!Array.isArray(items) || items.length === 0) return [];

      const categories = this.$store?.getters?.['kioskMenu/categories']
        || this.$store?.state?.kioskMenu?.categories
        || [];
      const drinkCategoryIds = new Set((Array.isArray(categories) ? categories : [])
        .filter((cat) => this.isDrinkCategory(cat))
        .map((cat) => String(cat.id)));

      const seen = new Set();
      return items
        .filter((row) => this.isDrinkCatalogItem(row, drinkCategoryIds))
        .map((row) => {
          const id = row.id ?? row.item_id ?? row.addon_item_id ?? row.name;
          const key = String(id ?? row.name ?? '');
          if (!key || seen.has(key)) return null;
          seen.add(key);
          const name = row.name || row.item_name || this.$t('kiosk.wizard.menu.drink_fallback_name');
          return {
            id,
            name,
            emoji: this.getEmojiForBoisson(name),
            displayThumb: kioskResolveImageSrc(row),
            _item: row,
          };
        })
        .filter(Boolean);
    },
    fritesExtraUnitLabel() {
      return this.formatPrice(getKioskExtraSauceUnitPrice(this.item));
    },
  },
  watch: {
    selections: {
      deep: true,
      handler(sel) {
        if (Array.isArray(sel.fritesSauceOrder)) {
          this.localFritesSauceOrder = [...sel.fritesSauceOrder];
        }
      },
    },
  },
  mounted() {
    // [AUDIT 2026-04-17 C5] Politique catégorie default_menu_kiosk :
    // si la catégorie impose la formule par défaut ET que le client n'a
    // encore rien choisi ET que l'item a réellement un menu disponible,
    // pré-sélectionner « full » pour réduire la friction.
    if (
      this.localChoice === null &&
      this.item?.has_menu &&
      this.item?.default_menu_kiosk &&
      this.boissonList.length > 0
    ) {
      this.selectChoice('full');
    }

    // [P2-g / F3 borne 2026-07-18] Step boisson autonome : `canAdvance` (wizard) exige un
    // menuChoice non-null. On respecte min_select : si la boisson est optionnelle (min 0) et
    // qu'aucun choix n'existe encore, on pré-pose 'none' (skippable). Si min_select ≥ 1, on
    // laisse null → le client doit choisir une boisson pour continuer.
    if (this.isStandaloneDrinkStep && (this.localChoice === null || this.localChoice === undefined)) {
      const minSelect = parseInt(this.step?.composer_step?.min_select ?? 0, 10) || 0;
      if (minSelect <= 0) {
        this.localChoice = 'none';
        this.$emit('update', 'menuChoice', 'none');
      }
    }
  },
  methods: {
    // [Round 5 — 2026-05-10] bundledUpgradeExtraIds / applyBundledUpgradeSelection
    // / clearBundledUpgrades methods removed. The dedicated KioskStepFritesStyle
    // step now owns the frites_style upgrade selection (writes to
    // selections.fritesStyleExtraId, not selections.supplements[id]).
    normalizeFritesSauceOrder(sel) {
      if (Array.isArray(sel.fritesSauceOrder) && sel.fritesSauceOrder.length) {
        return [...sel.fritesSauceOrder];
      }
      if (sel.fritesSauce) return [sel.fritesSauce];
      return [];
    },
    boissonThumbKey(boisson) {
      return String(boisson.id ?? boisson.name ?? '');
    },
    onBoissonThumbError(boisson) {
      const k = this.boissonThumbKey(boisson);
      this.brokenBoissonThumbs = { ...this.brokenBoissonThumbs, [k]: true };
    },
    onFritesSauceThumbError(key) {
      this.brokenFritesSauceThumbs = { ...this.brokenFritesSauceThumbs, [key]: true };
    },
    mapBoissonAddonRow(b) {
      const rawAddonItemId = b.item_addon_id ?? b.addon_item_id;
      let rowId = null;
      const addonId = typeof b.id === 'number' ? b.id : null;
      if (rawAddonItemId != null && rawAddonItemId !== '') {
        const n = Number(rawAddonItemId);
        if (!Number.isNaN(n) && Number.isFinite(n)) rowId = n;
      }
      if (rowId == null && typeof b.id === 'number') rowId = b.id;
      const name = b.addon_item_name || b.name || this.$t('kiosk.wizard.menu.drink_fallback_name');
      return {
        id: rowId,
        name,
        emoji: this.getEmojiForBoisson(name),
        displayThumb: kioskResolveImageSrc(b),
        addonId,
        _addon: b,
      };
    },
    isDrinkCategory(cat) {
      const haystack = `${cat?.name || ''} ${cat?.slug || ''}`.toLowerCase();
      return /\b(boisson|boissons|drink|drinks|soda|sodas|beverage|beverages)\b/i.test(haystack);
    },
    isDrinkCatalogItem(row, drinkCategoryIds) {
      if (!row || row.id === this.item?.id) return false;
      if (row.is_available === false) return false;
      const status = Number(row.status);
      if (status === 0 || status === 2 || status === 10) return false;
      const catId = String(row.item_category_id ?? row.category_id ?? '');
      const inDrinkCategory = catId !== '' && drinkCategoryIds.has(catId);
      const name = row.name || row.item_name || '';
      if (kioskIsGenericDrinkOptionName(name)) return false;
      return inDrinkCategory || kioskIsDrinkAddonName(name);
    },
    getEmojiForBoisson(name) {
      const lower = (name || '').toLowerCase();
      if (lower.includes('coca') || lower.includes('cola')) return '🥤';
      if (lower.includes('fanta') || lower.includes('orange')) return '🍊';
      if (lower.includes('sprite') || lower.includes('citron') || lower.includes('lemon')) return '🍋';
      if (lower.includes('eau') || lower.includes('water')) return '💧';
      if (lower.includes('thé') || lower.includes('tea') || lower.includes('ice tea')) return '🧊';
      if (lower.includes('jus') || lower.includes('juice')) return '🧃';
      return '🥤';
    },
    selectBoisson(boisson) {
      const key = boisson.id ?? boisson.name;

      // [P2-g / F3 borne 2026-07-18] Mode step boisson autonome (bol) : la boisson est
      // optionnelle (toggle) et doit poser menuChoice='boisson' pour que le wizard frozen
      // pousse l'addon de facturation (« Boisson Seule » 2,00 €). Re-taper la boisson
      // sélectionnée la retire (retour à 'none').
      if (this.isStandaloneDrinkStep) {
        if (this.localBoisson === key) {
          this.localBoisson = null;
          this.localChoice = 'none';
          this.$emit('update', 'boissonChoice', null);
          this.$emit('update', 'menuChoice', 'none');
          return;
        }
        this.localBoisson = key;
        this.localChoice = 'boisson';
        this.$emit('update', 'menuChoice', 'boisson');
        this.$emit('update', 'boissonChoice', this.localBoisson, {
          boissonName: boisson.name,
          boissonId: typeof boisson.id === 'number' ? boisson.id : null,
          addonId: typeof boisson.addonId === 'number' ? boisson.addonId : null,
        });
        return;
      }

      this.localBoisson = key;
      this.$emit('update', 'boissonChoice', this.localBoisson, {
        boissonName: boisson.name,
        boissonId: typeof boisson.id === 'number' ? boisson.id : null,
        addonId: typeof boisson.addonId === 'number' ? boisson.addonId : null,
      });
    },
    isFritesSauceSelected(key) {
      return this.localFritesSauceOrder.includes(key);
    },
    getFritesSauceOrder(key) {
      const i = this.localFritesSauceOrder.indexOf(key);
      return i >= 0 ? i + 1 : 0;
    },
    fritesSaucePriceLabel(key) {
      const ord = this.getFritesSauceOrder(key);
      if (ord <= 0) return ' ';
      return ord > 1 ? this.fritesExtraUnitLabel : this.$t('kiosk.wizard.summary.free');
    },
    emitFritesSauceOrder() {
      const order = [...this.localFritesSauceOrder];
      this.$emit('update', 'fritesSauceOrder', order);
      this.$emit('update', 'fritesSauce', order[0] ?? null);
    },
    toggleFritesSauce(sauce) {
      let order = [...this.localFritesSauceOrder];
      const key = sauce.key;
      if (key === 'sans') {
        order = ['sans'];
      } else {
        order = order.filter((k) => k !== 'sans');
        const idx = order.indexOf(key);
        if (idx >= 0) order.splice(idx, 1);
        else order.push(key);
      }
      this.localFritesSauceOrder = order;
      this.emitFritesSauceOrder();
    },
    selectChoice(choice) {
      this.localChoice = choice;
      if (choice === 'none' || choice === 'boisson') {
        this.localFritesSauceOrder = [];
        this.$emit('update', 'fritesSauceOrder', []);
        this.$emit('update', 'fritesSauce', null);
      }
      // [Round 5 — 2026-05-10] clearBundledUpgrades() call removed alongside the
      // inline upgrade picker. Wizard parent already clears
      // selections.fritesStyleExtraId on menuChoice='none'|'boisson' (see
      // KioskWizardComponent.vue ~line 1289).
      if (choice === 'none' || choice === 'frites') {
        this.localBoisson = null;
        this.$emit('update', 'boissonChoice', null);
      }
      this.$emit('update', 'menuChoice', choice);
    },
  },
};
</script>

<style scoped>
.kiosk-step-menu {
  padding: 6px 18px 24px;
  background: transparent;
  min-height: 100%;
}

.kiosk-step-title {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 12px;
  color: var(--kiosk-text, #333);
}

.kiosk-validation-hint.kiosk-menu-validation-hint {
  text-align: center;
  margin: 0 12px 12px;
  font-size: 13px;
  font-weight: 600;
  color: var(--kiosk-primary, #f4501e);
  padding: 10px 14px;
  background: var(--kiosk-primary-light, rgba(244, 80, 30, 0.06));
  border-radius: 12px;
}

.kiosk-validation-hint.kiosk-boisson-validation-hint,
.kiosk-validation-hint.kiosk-frites-sauce-validation-hint {
  text-align: center;
  margin: 0 0 12px;
  font-size: 13px;
  font-weight: 600;
  color: var(--kiosk-primary, #f4501e);
  padding: 10px 14px;
  background: var(--kiosk-primary-light, rgba(244, 80, 30, 0.06));
  border-radius: 12px;
}

.kiosk-menu-info {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-bottom: 14px;
}

.kiosk-info-badge {
  background: var(--kiosk-primary-soft, rgba(244, 80, 30, 0.06));
  border: 1px solid var(--kiosk-border, rgba(244, 80, 30, 0.2));
  color: var(--kiosk-primary, #f4501e);
  padding: 6px 16px;
  border-radius: 50px;
  font-size: 12px;
  font-weight: 700;
}

.kiosk-menu-price {
  font-size: 16px;
  font-weight: 800;
  color: var(--kiosk-primary, #f4501e);
  background: var(--kiosk-primary-soft, rgba(244, 80, 30, 0.06));
  padding: 4px 12px;
  border-radius: 50px;
}

.kiosk-menu-options {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 22px 18px;
  margin-bottom: 20px;
  max-width: 820px;
  margin-left: auto;
  margin-right: auto;
}

.kiosk-boisson-included {
  text-align: center;
  font-size: 12px;
  font-weight: 600;
  color: var(--kiosk-success, #2e7d32);
  margin: -6px 0 12px;
}

.kiosk-menu-card {
  min-height: 196px;
  border-radius: 20px;
  border: 1px solid var(--kiosk-border, #efefef);
  background: var(--kiosk-surface, #fff);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 14px 12px 16px;
  cursor: pointer;
  touch-action: manipulation;
  transition:
    transform 0.18s ease,
    border-color 0.18s ease,
    background-color 0.18s ease,
    box-shadow 0.18s ease;
  position: relative;
}

.kiosk-menu-card:active {
  transform: scale(0.96);
}

/* [AUDIT 2026-04-17 C6] Keyboard focus ring — cards are role=radio / radiogroup. */
.kiosk-menu-card:focus-visible,
.kiosk-boisson-card:focus-visible {
  outline: 3px solid rgba(244, 80, 30, 0.55);
  outline-offset: 3px;
}

.kiosk-menu-card.selected {
  border-color: var(--kiosk-primary, #f4501e);
  background: var(--kiosk-primary-light, rgba(244, 80, 30, 0.02));
  box-shadow: 0 0 0 2px var(--kiosk-primary-light, rgba(244, 80, 30, 0.08)), var(--kiosk-shadow-card, none);
}

.kiosk-menu-emoji {
  width: 118px;
  height: 118px;
  border-radius: 50%;
  background: var(--kiosk-product-media-bg, #f7f7f8);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 48px;
  margin-bottom: 12px;
  transition: transform 0.2s;
}

.kiosk-menu-card.selected .kiosk-menu-emoji {
  transform: scale(1.1);
}

.kiosk-menu-name {
  font-size: 12px;
  font-weight: 700;
  color: var(--kiosk-text, #444);
  text-align: center;
  text-transform: uppercase;
}

.kiosk-menu-desc {
  font-size: 11px;
  color: var(--kiosk-text-muted, #999);
  text-align: center;
  margin-top: 3px;
}

.kiosk-menu-card.selected .kiosk-menu-name {
  color: var(--kiosk-primary, #f4501e);
}

.kiosk-boisson-section {
  border-top: 1px solid var(--kiosk-border, #e0e0e0);
  padding-top: 16px;
  animation: fadeInUp 0.3s ease;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.kiosk-subtitle {
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  margin: 0 0 14px;
  color: var(--kiosk-text, #333);
}

.kiosk-boisson-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px 18px;
  max-width: 900px;
  margin: 0 auto;
}

.kiosk-boisson-card {
  min-height: 170px;
  border-radius: 20px;
  border: 1px solid var(--kiosk-border, #efefef);
  background: var(--kiosk-surface, #fff);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 12px 10px 14px;
  cursor: pointer;
  touch-action: manipulation;
  transition:
    transform 0.18s ease,
    border-color 0.18s ease,
    background-color 0.18s ease,
    box-shadow 0.18s ease;
  position: relative;
}

.kiosk-boisson-card:active {
  transform: scale(0.95);
}

.kiosk-boisson-card.selected {
  border-color: var(--kiosk-primary, #f4501e);
  background: var(--kiosk-primary-light, rgba(244, 80, 30, 0.02));
  box-shadow: 0 0 0 2px var(--kiosk-primary-light, rgba(244, 80, 30, 0.08)), var(--kiosk-shadow-card, none);
}

.kiosk-boisson-visual {
  width: 102px;
  height: 102px;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.kiosk-boisson-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.kiosk-boisson-emoji {
  width: 102px;
  height: 102px;
  border-radius: 50%;
  background: var(--kiosk-product-media-bg, #f7f7f8);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40px;
}

.kiosk-boisson-name {
  font-size: 12px;
  font-weight: 700;
  color: var(--kiosk-text, #444);
  text-align: center;
  line-height: 1.2;
  text-transform: uppercase;
}

.kiosk-boisson-card.selected .kiosk-boisson-name {
  color: var(--kiosk-primary, #f4501e);
}

.kiosk-boisson-price {
  margin-top: 4px;
  font-size: 12px;
  font-weight: 800;
  color: var(--kiosk-primary, #f4501e);
}

.kiosk-boisson-placeholder {
  text-align: center;
  color: var(--kiosk-text-muted, #999);
  font-size: 14px;
  padding: 16px 0;
}

.kiosk-frites-sauce-section {
  border-top: 1px solid var(--kiosk-border, #e0e0e0);
  padding-top: 20px;
  margin-top: 8px;
  animation: fadeInUp 0.3s ease;
}

.kiosk-frites-sauce-price {
  font-size: 11px;
  font-weight: 700;
  color: var(--kiosk-text, #333);
  margin-top: 4px;
  min-height: 14px;
}

.kiosk-frites-sauce-section .kiosk-sauce-order {
  position: absolute;
  top: 12px;
  right: 20px;
  width: 28px;
  height: 28px;
  background: var(--kiosk-primary, #d7263d);
  color: var(--kiosk-text-on-red, white);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 800;
}

.kiosk-menu-action {
  position: absolute;
  top: 12px;
  right: 20px;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--kiosk-primary, #d7263d);
  color: var(--kiosk-text-on-red, white);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  line-height: 1;
  box-shadow: 0 3px 10px rgba(215, 38, 61, 0.2);
  outline: 2px solid rgba(255, 255, 255, 0.85);
}

.kiosk-menu-action.active {
  font-size: 13px;
  font-weight: 800;
}
</style>
