<template>
    <!--
      [POS-V5-DESIGN-CONVERGENCE 2026-05-02 R2] Tiles produits avec photo hero.
      - Photo aspect-ratio 4/3 si `item.thumb` existe, sinon fallback emoji 🍴
      - Body sous photo : nom (clamp 2 lignes) + prix rouge brand + bouton "+" rond
      - Hover : lift + scale image + bouton "+" devient rouge brand plein
      - Disponibilité : overlay rouge translucide centré "Indisponible"
      - Grille auto-fill responsive (s'adapte selon largeur cart panel/sidebar)
    -->
    <div ref="itemsGrid" class="pos-v5-grid grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 mb-8 md:mb-0">
        <!-- [iter15-mega-fix D-010 round-7 2026-05-10] aria-label must reflect tile state: when disabled (rupture), 'Ajouter' is misleading -->
        <button v-for="item in items" :key="item.id || item"
            type="button"
            :class="['pos-v5-tile', tileClassList(item)]"
            :aria-disabled="isCatalogTileUnavailable(item) ? 'true' : 'false'"
            :disabled="isCatalogTileUnavailable(item)"
            :aria-label="isCatalogTileUnavailable(item)
                ? $t('a11y.unavailable_item', { item: item.name })
                : $t('a11y.add_item', { item: item.name, price: itemOfferPrice(item) })"
            :data-pos-item-id="item.id"
            @keyup.enter.prevent="addItem(item)"
            @keyup.space.prevent="addItem(item)">
            <!-- Photo hero (aspect-ratio 4/3) -->
            <div class="pos-v5-tile__visual">
                <img v-if="item.thumb"
                    :src="item.thumb"
                    :alt="item.name"
                    loading="lazy"
                    class="pos-v5-tile__image" />
                <span v-else class="pos-v5-tile__visual-fallback" aria-hidden="true">🍴</span>
                <span v-if="isCatalogTileUnavailable(item)" class="pos-item-86-badge pos-v5-tile__overlay">{{ $t('pos.item_86_d') }}</span>
            </div>
            <!-- Body -->
            <div class="pos-v5-tile__body">
                <h3 class="pos-v5-tile__name text-sm font-bold capitalize leading-snug line-clamp-2">{{ item.name }}</h3>
                <!-- [CAISSE-INGREDIENTS 2026-06-04] Ingrédients lisibles sur la tuile (sans clic) :
                     le caissier répond "qu'y a-t-il dedans ?" d'un coup d'œil. Texte muté, 2 lignes max. -->
                <p v-if="item.description" class="pos-v5-tile__desc" :title="item.description">{{ item.description }}</p>
                <div class="pos-v5-tile__foot">
                    <h4 class="pos-v5-tile__price">
                        {{ item.offer.length > 0 ? item.offer[0].currency_price : item.currency_price }}
                    </h4>
                    <span aria-hidden="true" class="pos-v5-tile__add">
                        <i class="lab lab-bag-2" style="font-size:13px;"></i>
                    </span>
                </div>
            </div>
        </button>
    </div>

    <!--========INFO PART START=========-->
    <div id="item-info-modal" ref="itemInfoModal" class="modal ff-modal info-modal">
        <div class="modal-dialog" v-if="itemInfo">
            <div class="modal-header flex items-start gap-3">
                <h3 class="modal-title text-base font-medium">{{ itemInfo.name }}</h3>
                <button class="modal-close fa-regular fa-circle-xmark" @click.prevent="infoModalHide"></button>
            </div>
            <div class="modal-body">
                {{ itemInfo.caution }}
            </div>
        </div>
    </div>
    <!--========INFO PART END===========-->

    <!--========VARIATION PART START=========
       [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Refonte chrome modal + header V5.
       L'image item devient ronde 88px (mirror wizard composition chips).
       La logique du wizard reste FROZEN (utilisé pour produits sans wizard).
    -->
    <div id="item-variation-modal" ref="itemVariationModal" class="modal ff-modal pos-v4-item-wizard-modal pos-v5-item-modal" role="dialog" aria-modal="true" aria-labelledby="item-variation-modal-title" tabindex="-1" :data-pos-drinks-catalog="drinksCatalogJson">
        <div
            class="modal-dialog pos-v4-item-wizard-dialog max-w-[820px] w-full flex flex-col max-h-[min(100dvh,100vh)] overflow-hidden rounded-xl bg-white shadow-xl"
            v-if="item">
            <div class="modal-header items-start border-none pb-0 flex-shrink-0 pos-v5-item-modal__head">
                <div class="flex gap-4 items-center">
                    <img class="flex-shrink-0 w-[88px] h-[88px] object-cover rounded-full ring-2 ring-[var(--pos-v5-brand-red-soft)] shadow-md" :src="item.thumb"
                        alt="thumbnail">
                    <div class="flex-auto">
                        <div class="flex items-start gap-2 mb-1">
                            <h3 id="item-variation-modal-title" class="text-base font-bold capitalize text-[var(--pos-v5-ink)]">{{ item.name }}</h3>
                            <button v-if="item.caution" type="button" class="info-btn mt-0.5 flex items-start"
                                data-modal="#item-info-modal" @click.prevent="infoModalShow(item.name, item.caution)">
                                <i class="lab lab-information font-fill-paragraph transition lab-font-size-16"></i>
                            </button>
                        </div>
                        <p class="text-xs mb-2 text-[var(--pos-v5-ink-soft)]">{{ item.description }}</p>
                        <h4 class="text-lg font-extrabold text-[var(--pos-v5-brand-red)] pos-v5-tabular">{{ item.offer.length > 0 ? item.offer[0].currency_price :
                            item.currency_price }}</h4>
                    </div>
                </div>
                <button class="modal-close lab-close-circle-line font-fill-danger lab-font-size-24"
                    @click.prevent="variationModalHide"></button>
            </div>
            <div class="modal-body pos-v4-item-wizard-scroll flex-1 min-h-0 overflow-y-auto overscroll-contain">
                <div class="flex items-center gap-2 mb-4">
                    <h3 class="text-sm leading-6 font-medium first-letter:uppercase text-heading">
                        {{ $t('label.quantity') }}:</h3>
                    <div class="flex items-center indec-group py-1 px-2 rounded-xl bg-[#F7F7FC]">
                        <button @click.prevent="quantityDecrement"
                            class="fa-solid fa-minus text-[10px] w-[18px] h-[18px] leading-4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-minus"></button>
                        <input type="number" v-on:keypress="onlyNumber($event)" v-on:keyup="quantityUp"
                            v-model="temp.quantity"
                            class="text-center w-7 text-xs font-semibold text-heading indec-value">
                        <button @click.prevent="quantityIncrement"
                            class="fa-solid fa-plus text-[10px] w-[18px] h-[18px] leading4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-plus"></button>
                    </div>
                </div>
                <div class="mb-4" v-if="item.itemAttributes.length > 0">
                    <div class="space-y-4">
                        <div v-for="itemAttribute in item.itemAttributes" :key="itemAttribute.id"
                            class="rounded-xl border border-[#EFF0F6] p-3 bg-white">
                            <div class="flex items-start justify-between gap-3 mb-2">
                                <div>
                                    <h3 class="text-sm leading-6 font-medium capitalize text-heading">
                                        {{ itemAttribute.name }}
                                    </h3>
                                    <p class="text-[11px] text-[#6E7191]">
                                        Min {{ getAttributeConfig(itemAttribute).minSelect }} / Max {{ getAttributeConfig(itemAttribute).maxSelect }}
                                    </p>
                                </div>
                                <span v-if="isMultiAttribute(itemAttribute)"
                                    :class="hasAttributeSelectionError(itemAttribute) ? 'bg-red-100 text-red-600' : 'bg-green-100 text-green-700'"
                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold">
                                    {{ getAttributeTotalQuantity(itemAttribute.id) }} / {{ getAttributeConfig(itemAttribute).maxSelect }}
                                </span>
                            </div>

                            <p v-if="hasAttributeSelectionError(itemAttribute)" class="mb-2 text-[11px] text-red-600">
                                {{ itemAttribute.name }}: minimum {{ getAttributeConfig(itemAttribute).minSelect }} requis.
                            </p>

                            <div class="space-y-2">
                                <template v-for="variation in getAttributeVariations(itemAttribute)" :key="variation.id">
                                    <label v-if="!isMultiAttribute(itemAttribute)"
                                        :title="modifierUnavailableReason(variation)"
                                        :aria-disabled="isModifierUnavailable(variation) ? 'true' : 'false'"
                                        :class="getVariationQuantity(variation.id) > 0 ? 'border-primary bg-[#FFE8DD]' : 'border-[#F7F7FC] bg-[#F7F7FC]'"
                                        class="w-full min-h-[60px] cursor-pointer py-2 px-3 gap-3 rounded-lg flex items-center border transition"
                                        :style="isModifierUnavailable(variation) ? 'opacity:.5;cursor:not-allowed;' : ''">
                                        <div class="custom-radio sm flex-shrink-0">
                                            <input :checked="getVariationQuantity(variation.id) > 0"
                                                :disabled="isModifierUnavailable(variation)"
                                                @change="selectLegacyVariation(itemAttribute, variation)"
                                                type="radio"
                                                :id="variation.item_attribute_id + '-' + variation.name"
                                                class="custom-radio-field">
                                            <span class="custom-radio-span"></span>
                                        </div>
                                        <img v-if="variation.thumb" class="w-10 h-10 object-cover rounded flex-shrink-0" :src="variation.thumb" :alt="variation.name">
                                        <div class="flex-1 min-w-0">
                                            <h3 class="block capitalize text-xs text-heading">
                                                {{ textShortener(variation.name, 15) }}</h3>
                                            <h4 v-if="variation.price > 0" class="block text-xs font-medium text-heading">
                                                +{{ variation.currency_price }}
                                            </h4>
                                            <span v-if="isModifierUnavailable(variation)" class="block text-[10px] font-semibold text-danger">
                                                {{ $t('pos.item_86_d') }}
                                            </span>
                                        </div>
                                    </label>

                                    <div v-else class="flex items-center gap-3 rounded-lg border border-[#F7F7FC] bg-[#F7F7FC] py-2 px-3"
                                        :title="modifierUnavailableReason(variation)"
                                        :style="isModifierUnavailable(variation) ? 'opacity:.5;' : ''">
                                        <img v-if="variation.thumb" class="w-10 h-10 object-cover rounded flex-shrink-0" :src="variation.thumb" :alt="variation.name">
                                        <div class="flex-1 min-w-0">
                                            <h3 class="block capitalize text-xs text-heading">
                                                {{ textShortener(variation.name, 18) }}
                                            </h3>
                                            <h4 v-if="variation.price > 0" class="block text-xs font-medium text-heading">
                                                +{{ variation.currency_price }}
                                            </h4>
                                            <span v-if="isModifierUnavailable(variation)" class="block text-[10px] font-semibold text-danger">
                                                {{ $t('pos.item_86_d') }}
                                            </span>
                                        </div>
                                        <div class="flex items-center indec-group py-1 px-2 rounded-xl bg-white">
                                            <button @click.prevent="decrementVariation(itemAttribute, variation)"
                                                :disabled="getVariationQuantity(variation.id) === 0"
                                                class="fa-solid fa-minus text-[10px] w-[18px] h-[18px] leading-4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-minus disabled:opacity-40 disabled:cursor-not-allowed"></button>
                                            <span class="text-center w-7 text-xs font-semibold text-heading">
                                                {{ getVariationQuantity(variation.id) }}
                                            </span>
                                            <button @click.prevent="incrementVariation(itemAttribute, variation)"
                                                :disabled="isAttributeAtMax(itemAttribute) || isModifierUnavailable(variation)"
                                                class="fa-solid fa-plus text-[10px] w-[18px] h-[18px] leading-4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-plus disabled:opacity-40 disabled:cursor-not-allowed"></button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- [WIZARD-VARIATION-BRIDGE 2026-06-24] Le shim FROZEN public/js/pos-wizard.js
                     (overlay « design parfait » de la caisse) transfère les choix viande/sauce/pain
                     vers le modal Vue en écrivant dans des <select> (viande l.4039, sauce l.3740,
                     pain l.3764). Or la v5 rend ces attributs single-select en radios SANS `value`
                     → le bridge value-based no-op → l'ordre soumettait les variations PAR DÉFAUT
                     (1ʳᵉ viande/sauce), faussant le composition_snapshot NF525 + le KDS, et perdant
                     la 2ᵉ viande (Tacos L/Méga/Terminator). Ces <select> cachés write-only sont des
                     CIBLES de bridge : le wrapper .form-group porte le nom d'attribut (filtre viande),
                     l'option.value = id de variation (sauce/pain par id ; viandes par index → la 2ᵉ
                     viande tombe dans le 2ᵉ select). @change → setVariationQuantity = SSOT de sélection.
                     Aucun fichier frozen modifié. -->
                <div aria-hidden="true" class="pos-wizard-variation-bridge"
                     style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;"
                     v-if="item.itemAttributes.length > 0">
                    <div v-for="bridgeAttr in item.itemAttributes" :key="'vbridge-' + bridgeAttr.id"
                         class="form-group" :data-bridge-attr="bridgeAttr.id">
                        <span>{{ bridgeAttr.name }}</span>
                        <select :data-bridge-select="bridgeAttr.id" @change="onWizardBridgeSelect(bridgeAttr, $event)">
                            <option value=""></option>
                            <option v-for="bridgeVar in getAttributeVariations(bridgeAttr)"
                                    :key="'vbo-' + bridgeVar.id" :value="bridgeVar.id">{{ bridgeVar.name }}</option>
                        </select>
                    </div>
                    <!-- Extras (crudités + suppléments) : le shim frozen toggle des
                         `.extra .custom-checkbox-field` (value=extra id). La v5 rend les
                         extras en boutons +/- → sans ces checkboxes le supplément +0,90 ne
                         se transférait pas → le backend re-facturait SANS (sous-facturation
                         NF525). @change → setExtraQuantity = SSOT extras. -->
                    <div v-for="bridgeExtra in item.extras" :key="'ebridge-' + bridgeExtra.id"
                         class="extra" :data-bridge-extra="bridgeExtra.id">
                        <label>{{ bridgeExtra.name }}</label>
                        <input type="checkbox" class="custom-checkbox-field" :value="bridgeExtra.id"
                               :checked="getExtraQuantity(bridgeExtra.id) > 0"
                               @change="onWizardBridgeExtra(bridgeExtra, $event)">
                    </div>
                </div>
                <div class="mb-4" v-if="item.extras.length > 0">
                    <h3 class="text-sm leading-6 font-medium capitalize mb-2 text-heading">{{ $t('label.extras') }}</h3>
                    <div class="space-y-2">
                        <div v-for="extra in item.extras" :key="extra.id"
                            class="flex items-center gap-3 rounded-lg border border-[#F7F7FC] bg-[#F7F7FC] py-2 px-3"
                            :title="modifierUnavailableReason(extra)"
                            :style="isModifierUnavailable(extra) ? 'opacity:.5;' : ''">
                            <img v-if="extra.thumb" class="w-10 h-10 object-cover rounded flex-shrink-0" :src="extra.thumb" :alt="extra.name">
                            <div class="flex-1 min-w-0">
                                <h3 class="block capitalize mb-1 text-xs text-heading">
                                    {{ textShortener(extra.name, 18) }}</h3>
                                <h4 class="block text-xs font-medium text-heading">+{{ extra.currency_price }}</h4>
                                <span v-if="isModifierUnavailable(extra)" class="block text-[10px] font-semibold text-danger">
                                    {{ $t('pos.item_86_d') }}
                                </span>
                            </div>
                            <div class="flex items-center indec-group py-1 px-2 rounded-xl bg-white">
                                <button @click.prevent="decrementExtra(extra)"
                                    :disabled="getExtraQuantity(extra.id) === 0"
                                    class="fa-solid fa-minus text-[10px] w-[18px] h-[18px] leading-4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-minus disabled:opacity-40 disabled:cursor-not-allowed"></button>
                                <span class="text-center w-7 text-xs font-semibold text-heading">
                                    {{ getExtraQuantity(extra.id) }}
                                </span>
                                <button @click.prevent="incrementExtra(extra)"
                                    :disabled="isModifierUnavailable(extra)"
                                    class="fa-solid fa-plus text-[10px] w-[18px] h-[18px] leading-4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-plus"></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-5" v-if="item.addons.length > 0">
                    <h3 class="text-sm leading-6 font-medium capitalize mb-2 text-heading">{{ $t('label.addons') }}</h3>
                    <div class="swiper addon-swiper">
                        <Swiper :speed="280" slidesPerView="auto" :spaceBetween="16">
                            <SwiperSlide v-for="addon in item.addons" :key="addon">
                                <div class="!w-fit !relative">
                                    <div @click.prevent="changeAddon(addon)"
                                        :data-addon-id="addon.id"
                                        :data-addon-name="addon.addon_item_name"
                                        :data-addon-active="addons[addon.id] ? '1' : '0'"
                                        :title="modifierUnavailableReason(addon)"
                                        class="addon cursor-pointer w-fit min-w-[200px] h-[70px] rounded-lg flex border border-[#EFF0F6]"
                                        :style="isAddonUnavailable(addon) ? 'opacity:.5;cursor:not-allowed;' : ''">
                                        <img class="w-[68px] h-full object-cover ltr:rounded-l-lg rtl:rounded-r-lg flex-shrink-0"
                                            :src="addon.thumb" alt="thumbnail">
                                        <div class="ltr:rounded-r-lg rtl:rounded-l-lg w-full py-1 px-2">
                                            <span
                                                class="block text-xs text-ellipsis whitespace-nowrap overflow-hidden w-fit max-w-[100px] capitalize text-heading">
                                                {{ addon.addon_item_name }}
                                            </span>
                                            <p v-if="addon.variation_names.length > 0"
                                                class=" text-left text-[10px] leading-4 capitalize mb-1.5 cursor-pointer">
                                                <span v-for="(variation, index) in addon.variation_names">
                                                    {{ textShortener(variation.name, 8) }}
                                                    <span v-if="index + 1 < addon.variation_names.length">,
                                                        &nbsp;</span>
                                                </span>
                                            </p>
                                            <span
                                                class="block text-xs font-semibold text-heading ltr:text-left rtl:text-right">
                                                {{ addon.total_currency_price }}
                                            </span>
                                            <span v-if="isAddonUnavailable(addon)" class="block text-[10px] font-semibold text-danger">
                                                {{ $t('pos.item_86_d') }}
                                            </span>
                                        </div>
                                    </div>
                                    <div
                                        class="flex flex-col items-end justify-between h-full absolute top-0 ltr:right-0 rtl:left-0 z-10 p-2">
                                        <button type="button" class="info-btn" data-modal="#item-info-modal"
                                            @click.prevent="infoModalShow(addon.addon_item_name, addon.caution)">
                                            <i
                                                class="lab lab-information font-fill-paragraph transition lab-font-size-16"></i>
                                        </button>

                                        <div class="flex items-center indec-group">
                                            <button @click.prevent="addonQuantityDecrement(addon.id)"
                                                class="fa-solid fa-minus text-[8px] w-4 h-4 leading-3 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-minus"></button>
                                            <input v-on:keypress="onlyNumber($event)"
                                                v-on:keyup="addonQuantityUp(addon.id)" v-model="addonQuantity[addon.id]"
                                                :disabled="isAddonUnavailable(addon)"
                                                type="number"
                                                class="text-center w-5 text-xs font-semibold text-heading indec-value">
                                            <button @click.prevent="addonQuantityIncrement(addon.id)"
                                                :disabled="isAddonUnavailable(addon)"
                                                class="fa-solid fa-plus text-[8px] w-4 h-4 leading-3 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-plus"></button>
                                        </div>
                                    </div>
                                </div>
                            </SwiperSlide>
                        </Swiper>
                    </div>
                </div>

                <div class="mb-6">
                    <h3 class="text-xs leading-6 font-medium capitalize mb-2 text-heading">
                        {{ $t('label.special_instructions') }}
                    </h3>
                    <textarea v-model="temp.instruction" :placeholder="$t('message.add_note')"
                        class="h-12 w-full rounded-lg border py-1.5 px-2 placeholder:text-[10px] placeholder:text-[#6E7191] border-[#D9DBE9]"></textarea>
                    <small class="db-field-alert" v-if="instructionError">{{ instructionError }}</small>
                </div>
            </div>
            <div
                class="pos-v4-item-wizard-footer pos-add-to-cart-sticky pos-v5-item-modal__foot flex-shrink-0 border-t border-[var(--pos-v5-border)] bg-white px-4 pb-4 pt-3"
                data-wiz-vue-footer
            >
                    <button type="button" :disabled="!canAddToCart" @click.prevent="addToCart"
                        class="pos-v5-item-add-cta flex items-center justify-center gap-3 rounded-2xl text-base py-3 px-4 font-extrabold w-full text-white disabled:opacity-50 disabled:cursor-not-allowed transition">
                        <i class="icon-bag-2" aria-hidden="true"></i>
                        <span>
                            {{ $t('button.add_to_cart') }} ·
                            <span class="pos-v5-tabular">{{
                                currencyFormat(temp.total_price, setting.site_digit_after_decimal_point,
                                    setting.site_default_currency_symbol, setting.site_currency_position)
                            }}</span>
                        </span>
                    </button>
                    <div v-if="itemUnavailabilityBannerVisible" class="alert alert-warning mt-2" role="status">
                        {{ $t('pos.item_unavailable_during_edit') }}
                    </div>
            </div>
        </div>
    </div>
    <!--========VARIATION PART END===========-->
</template>

<script>
import appService from "../../../services/appService";
import _ from "lodash";
import alertService from "../../../services/alertService";
import { Swiper, SwiperSlide } from 'swiper/vue';
import 'swiper/css';
import {
    normalizeExtraEntries,
    normalizeId,
    normalizeQuantity,
    normalizeVariationEntries,
} from "../../../helpers/posNormalizeIds";

function createEmptyTemp() {
    return {
        name: "",
        image: "",
        item_id: 0,
        quantity: 0,
        discount: 0,
        currency_price: 0,
        convert_price: 0,
        item_variations: [],
        item_extras: [],
        item_variation_total: 0,
        item_extra_total: 0,
        total_price: 0,
        instruction: "",
    };
}

export default {
    name: "itemComponent",
    components: {
        Swiper,
        SwiperSlide,
    },
    props: {
        items: Object,
        drinksCatalog: {
            type: Array,
            default: function () { return []; }
        }
    },
    // [POS-CATEGORY-FIRST 2026-06-23 /goal] Emitted after a NEW cart line is
    // added (simple item or Vanilla-wizard add) so PosComponent can auto-return
    // to the category grid hub. Edits (replaceCartLine) do NOT emit.
    emits: ['item:added'],
    data() {
        return {
            item: null,
            itemInfo: null,
            addons: {},
            addonQuantity: {},
            itemArrays: [],
            /** Index ligne panier en cours d’édition (null = ajout normal) */
            editingCartIndex: null,
            /** True juste après ouverture depuis le panier : garde convert_price enregistré (wizard) jusqu’à une modif */
            usePricedCartBase: false,
            settings: {
                itemsToShow: 4.3,
                wrapAround: false,
                snapAlign: "start"
            },
            addonSettings: {
                itemsToShow: 3,
                wrapAround: false,
                snapAlign: "start"
            },
            temp: createEmptyTemp(),
            instructionError: ""
        }
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        /**
         * Live catalogue flag from ItemAvailabilityChanged / API.
         * Absent legacy items are treated as available (backward compat).
         */
        catalogItemAvailable: function () {
            if (!this.item) return true;
            const v = this.item.is_available;
            if (v === undefined || v === null) return true;
            return v === true || v === 1 || v === '1';
        },
        itemUnavailabilityBannerVisible: function () {
            return Boolean(this.item) && !this.catalogItemAvailable;
        },
        canAddToCart: function () {
            const modal = this.$refs?.itemVariationModal;
            const wizardBridged = parseFloat(modal?.dataset?.wizardTotal || 0) || 0;
            // Single-page pos-wizard submits via dataset + instruction; legacy <select> sync often
            // does not run on POS V5 (no viande dropdowns), so Vue temp.item_variations can lag while
            // the wizard state is authoritative — do not block on hasSelectionErrors in that case.
            if (wizardBridged > 0) {
                return this.catalogItemAvailable;
            }
            return this.temp.total_price > 0 && !this.hasSelectionErrors() && this.catalogItemAvailable;
        },
        /**
         * [POS-WIZARD-DRINKS 2026-05-02] Sérialisation du catalogue boissons pour le shim
         * `public/js/pos-wizard.js` (vanilla JS hors webpack). Le wizard lit cet attribut
         * DOM pour détecter quels addons du produit sont des boissons (cross-reference
         * id catalogue / nom catalogue), assurant la symétrie POS↔borne.
         */
        drinksCatalogJson: function () {
            try {
                return JSON.stringify(Array.isArray(this.drinksCatalog) ? this.drinksCatalog : []);
            } catch (e) {
                return '[]';
            }
        },
    },
    methods: {
        onlyNumber: function (e) {
            return appService.onlyNumber(e);
        },
        textShortener: function (text, number) {
            return appService.textShortener(text, number);
        },
        currencyFormat: function (amount, decimal, currency, position) {
            return appService.currencyFormat(amount, decimal, currency, position);
        },
        resetTempState: function () {
            this.temp = createEmptyTemp();
            this.addons = {};
            this.addonQuantity = {};
        },
        normalizeLoadedItem: function (raw) {
            const item = Object.assign({}, raw || {});
            item.offer = Array.isArray(item.offer) ? item.offer : [];
            item.addons = Array.isArray(item.addons) ? item.addons : [];
            item.extras = Array.isArray(item.extras) ? item.extras : [];
            item.itemAttributes = Array.isArray(item.itemAttributes) ? item.itemAttributes : [];
            item.variations = item.variations && typeof item.variations === 'object' ? item.variations : {};
            return item;
        },
        showItemLoadError: function (error) {
            if (typeof console !== 'undefined' && console.error) {
                console.error('[POS] item/details failed', error);
            }
            alertService.error(this.$t('message.something_went_wrong') || 'Erreur lors du chargement du produit.');
        },
        renderedItemsList: function () {
            if (Array.isArray(this.items)) {
                return this.items;
            }
            if (this.items && typeof this.items === 'object') {
                return Object.values(this.items);
            }
            return [];
        },
        findRenderedItemById: function (itemId) {
            const normalizedItemId = normalizeId(itemId);
            if (normalizedItemId === null) {
                return null;
            }

            return this.renderedItemsList()
                .find((row) => normalizeId(row && row.id) === normalizedItemId) || null;
        },
        handleNativeTileClick: function (event) {
            if (event?.__fkPosTileHandled) {
                return;
            }

            const tile = event?.target?.closest?.('[data-pos-item-id]');
            if (!tile) {
                return;
            }
            if (tile.disabled || tile.getAttribute('aria-disabled') === 'true') {
                return;
            }

            const selectedItem = this.findRenderedItemById(tile.getAttribute('data-pos-item-id'));
            if (!selectedItem || this.isCatalogTileUnavailable(selectedItem)) {
                return;
            }

            event.__fkPosTileHandled = true;
            event.preventDefault();
            this.variationModalShow(selectedItem);
        },
        posItemDetailsPayload: function (id) {
            const payload = { id, surface: 'pos' };
            const branchId = this.$store.getters['auth/authBranchId']
                || this.$store.getters.authBranchId
                || this.$store.state?.auth?.authBranchId
                || null;
            if (branchId) {
                payload.branch_id = branchId;
            }
            return payload;
        },
        getAttributeConfig: function (attribute) {
            const maxSelect = normalizeQuantity(attribute && attribute.max_select, 1);
            const allowRepeat = Boolean(attribute && attribute.allow_repeat);
            const isMulti = maxSelect > 1 || allowRepeat;
            const minSelectRaw = normalizeId(attribute && attribute.min_select);
            const minSelect = minSelectRaw === null ? (isMulti ? 0 : 1) : Math.min(minSelectRaw, maxSelect);

            return {
                minSelect,
                maxSelect,
                allowRepeat,
                isMulti,
            };
        },
        isMultiAttribute: function (attribute) {
            return this.getAttributeConfig(attribute).isMulti;
        },
        getAttributeVariations: function (attribute) {
            if (!this.item || !attribute) return [];
            return Array.isArray(this.item.variations && this.item.variations[attribute.id])
                ? this.item.variations[attribute.id]
                : [];
        },
        findVariationById: function (variationId) {
            if (!this.item || !this.item.variations) return null;
            const normalizedVariationId = normalizeId(variationId);
            if (normalizedVariationId === null) return null;
            return Object.values(this.item.variations)
                .flatMap((rows) => Array.isArray(rows) ? rows : [])
                .find((variation) => normalizeId(variation && variation.id) === normalizedVariationId) || null;
        },
        findExtraById: function (extraId) {
            if (!this.item || !Array.isArray(this.item.extras)) return null;
            const normalizedExtraId = normalizeId(extraId);
            if (normalizedExtraId === null) return null;
            return this.item.extras.find((extra) => normalizeId(extra && extra.id) === normalizedExtraId) || null;
        },
        isModifierUnavailable: function (modifier) {
            if (!modifier) return false;
            const availability = modifier.is_available;
            if (availability === false || availability === 0 || availability === '0') {
                return true;
            }
            const status = Number(modifier.status);
            return status === 0 || status === 2 || status === 10;
        },
        isAddonUnavailable: function (addon) {
            return this.isModifierUnavailable(addon);
        },
        getAddonById: function (id) {
            if (!this.item || !Array.isArray(this.item.addons)) return null;
            const normalizedId = normalizeId(id);
            if (normalizedId === null) return null;
            return this.item.addons.find((addon) => normalizeId(addon && addon.id) === normalizedId) || null;
        },
        modifierUnavailableReason: function (modifier) {
            return modifier && modifier.unavailable_reason ? modifier.unavailable_reason : '';
        },
        pruneUnavailableRestoredSelections: function () {
            this.temp.item_variations = normalizeVariationEntries(this.temp.item_variations)
                .filter((entry) => {
                    const variation = this.findVariationById(entry.id);
                    return variation && !this.isModifierUnavailable(variation);
                });
            this.temp.item_extras = normalizeExtraEntries(this.temp.item_extras)
                .filter((entry) => {
                    const extra = this.findExtraById(entry.id);
                    return extra && !this.isModifierUnavailable(extra);
                });
            Object.keys(this.addons || {}).forEach((addonId) => {
                const addon = this.getAddonById(addonId);
                if (!addon || this.isAddonUnavailable(addon)) {
                    delete this.addons[addonId];
                    this.addonQuantity[addonId] = 1;
                }
            });
        },
        getVariationEntriesByAttribute: function (attributeId) {
            const normalizedAttributeId = normalizeId(attributeId);
            return normalizeVariationEntries(this.temp.item_variations)
                .filter((entry) => entry.item_attribute_id === normalizedAttributeId);
        },
        getVariationQuantity: function (variationId) {
            const normalizedVariationId = normalizeId(variationId);
            if (normalizedVariationId === null) return 0;

            const existing = normalizeVariationEntries(this.temp.item_variations)
                .find((entry) => entry.id === normalizedVariationId);

            return existing ? normalizeQuantity(existing.quantity, 1) : 0;
        },
        getAttributeTotalQuantity: function (attributeId) {
            return this.getVariationEntriesByAttribute(attributeId)
                .reduce((total, entry) => total + normalizeQuantity(entry.quantity, 1), 0);
        },
        isAttributeAtMax: function (attribute) {
            const config = this.getAttributeConfig(attribute);
            return this.getAttributeTotalQuantity(attribute.id) >= config.maxSelect;
        },
        hasAttributeSelectionError: function (attribute) {
            const config = this.getAttributeConfig(attribute);
            return this.getAttributeTotalQuantity(attribute.id) < config.minSelect;
        },
        hasSelectionErrors: function () {
            if (!this.item || !Array.isArray(this.item.itemAttributes)) return false;
            return this.item.itemAttributes.some((attribute) => this.hasAttributeSelectionError(attribute));
        },
        setVariationQuantity: function (attribute, variation, quantity) {
            this.bumpPricingToCatalog();
            const attributeId = normalizeId(attribute && attribute.id);
            const variationId = normalizeId(variation && variation.id);

            if (attributeId === null || variationId === null) return;
            if (this.isModifierUnavailable(variation) && quantity > this.getVariationQuantity(variationId)) return;

            const config = this.getAttributeConfig(attribute);
            const safeQuantity = Math.max(0, Math.floor(Number(quantity) || 0));
            let next = normalizeVariationEntries(this.temp.item_variations)
                .filter((entry) => entry.item_attribute_id !== attributeId || entry.id !== variationId);

            if (!config.isMulti) {
                next = next.filter((entry) => entry.item_attribute_id !== attributeId);
            }

            if (safeQuantity > 0) {
                next.push({
                    id: variationId,
                    item_attribute_id: attributeId,
                    quantity: safeQuantity,
                    variation_name: attribute.name,
                    name: variation.name,
                });
            }

            this.temp.item_variations = next;
            this.totalPriceSetup();
        },
        selectLegacyVariation: function (attribute, variation) {
            this.setVariationQuantity(attribute, variation, 1);
        },
        // [WIZARD-VARIATION-BRIDGE 2026-06-24] @change des <select> cachés pilotés par
        // le shim frozen pos-wizard.js. Mappe la variation choisie (par id) vers
        // temp.item_variations via le SSOT de sélection setVariationQuantity, pour que
        // l'ordre soumette la vraie viande/sauce/pain (et non le défaut). Single-select :
        // setVariationQuantity remplace déjà la variation de l'attribut.
        onWizardBridgeSelect: function (attribute, event) {
            const variationId = normalizeId(event && event.target ? event.target.value : null);
            if (variationId === null) return;
            const variation = (this.getAttributeVariations(attribute) || [])
                .find((entry) => normalizeId(entry && entry.id) === variationId);
            if (variation) {
                this.setVariationQuantity(attribute, variation, 1);
            }
        },
        // [WIZARD-VARIATION-BRIDGE 2026-06-24] @change des checkboxes cachées extras
        // pilotées par le shim frozen pos-wizard.js (cb.click() toggle). Reflète l'état
        // coché dans temp.item_extras via setExtraQuantity (SSOT) -> suppléments facturés.
        onWizardBridgeExtra: function (extra, event) {
            const checked = !!(event && event.target && event.target.checked);
            this.setExtraQuantity(extra, checked ? 1 : 0);
        },
        incrementVariation: function (attribute, variation) {
            if (this.isAttributeAtMax(attribute)) return;
            this.setVariationQuantity(attribute, variation, this.getVariationQuantity(variation.id) + 1);
        },
        decrementVariation: function (attribute, variation) {
            this.setVariationQuantity(attribute, variation, this.getVariationQuantity(variation.id) - 1);
        },
        getExtraQuantity: function (extraId) {
            const normalizedExtraId = normalizeId(extraId);
            if (normalizedExtraId === null) return 0;

            const existing = normalizeExtraEntries(this.temp.item_extras)
                .find((entry) => entry.id === normalizedExtraId);

            return existing ? normalizeQuantity(existing.quantity, 1) : 0;
        },
        setExtraQuantity: function (extra, quantity) {
            this.bumpPricingToCatalog();
            const extraId = normalizeId(extra && extra.id);

            if (extraId === null) return;
            if (this.isModifierUnavailable(extra) && quantity > this.getExtraQuantity(extraId)) return;

            const safeQuantity = Math.max(0, Math.floor(Number(quantity) || 0));
            const next = normalizeExtraEntries(this.temp.item_extras)
                .filter((entry) => entry.id !== extraId);

            if (safeQuantity > 0) {
                next.push({
                    id: extraId,
                    quantity: safeQuantity,
                    name: extra.name,
                });
            }

            this.temp.item_extras = next;
            this.totalPriceSetup();
        },
        incrementExtra: function (extra) {
            this.setExtraQuantity(extra, this.getExtraQuantity(extra.id) + 1);
        },
        decrementExtra: function (extra) {
            this.setExtraQuantity(extra, this.getExtraQuantity(extra.id) - 1);
        },
        initializeDefaultSelections: function () {
            if (!this.item || !Array.isArray(this.item.itemAttributes)) return;

            _.forEach(this.item.itemAttributes, (attribute) => {
                const config = this.getAttributeConfig(attribute);
                const variations = this.getAttributeVariations(attribute);

                const firstAvailable = variations.find((variation) => !this.isModifierUnavailable(variation));

                if (!config.isMulti && firstAvailable) {
                    this.setVariationQuantity(attribute, firstAvailable, 1);
                }
            });
        },
        isCatalogTileUnavailable: function (row) {
            if (!row) return false;
            const v = row.is_available;
            if (v === undefined || v === null) return false;
            return v === false || v === 0 || v === '0';
        },
        tileClassList: function (row) {
            // [POS-V5 R2] Layout délégué à .pos-v5-tile (CSS scoped). Cette méthode
            // garde uniquement les flags d'état pour la compat sentinels.
            return {
                'pos-item-tile': true,
                'is-unavailable': this.isCatalogTileUnavailable(row),
            };
        },
        onProductTileClick: function (selectedItem) {
            if (this.isCatalogTileUnavailable(selectedItem)) return;
            this.variationModalShow(selectedItem);
        },
        itemOfferPrice: function (row) {
            if (!row) return '';
            return row.offer && row.offer.length > 0 ? row.offer[0].currency_price : row.currency_price;
        },
        addItem: function (item) {
            this.onProductTileClick(item);
        },
        /**
         * [T11 POS_AVAILABILITY_LIVE_GUARD] Sync modal item when parent updates list from Echo.
         */
        syncItemAvailabilityFromBroadcast: function (itemId, isAvailable, reason) {
            if (!this.item) return;
            if (parseInt(this.item.id, 10) !== parseInt(itemId, 10)) return;
            this.item = Object.assign({}, this.item, {
                is_available: isAvailable,
                availability_reason: reason != null ? reason : this.item.availability_reason,
            });
        },
        infoModalShow: function (name, caution) {
            this.itemInfo = {
                name: name,
                caution: caution
            };
            const modalTarget = this.$refs.itemInfoModal;
            modalTarget?.classList?.add("active");
            document.body.style.overflowY = "hidden";
        },
        infoModalHide: function () {
            this.itemInfo = null;
            const modalDiv = this.$refs.itemInfoModal;
            modalDiv?.classList?.remove("active");
            document.body.style.overflowY = "auto";
        },
        variationModalShow: function (selectedItem) {
            this.editingCartIndex = null;
            this.usePricedCartBase = false;
            this.resetTempState();

            // [AUDIT 2026-04-17 R2] Surface=pos so the backend only returns
            // extras/variations visible on the cashier channel (NormalItemResource).
            this.$store.dispatch('item/details', this.posItemDetailsPayload(selectedItem.id))
                .then((res) => {

                    const item = this.normalizeLoadedItem(res.data.data);
                    this.item = item;

                    if (this.item.addons.length > 0) {
                        _.forEach(this.item.addons, (addon) => {
                            this.addonQuantity[addon.id] = 1;
                        });
                    }

                    this.temp.name = this.item.name;
                    this.temp.image = this.item.thumb;
                    this.temp.item_id = this.item.id;
                    this.temp.quantity = 1;
                    this.temp.discount = 0;
                    this.temp.convert_price = item.offer.length > 0 ? item.offer[0].convert_price : item.convert_price;
                    this.temp.currency_price = item.offer.length > 0 ? item.offer[0].currency_price : item.currency_price;
                    this.initializeDefaultSelections();
                    this.totalPriceSetup();

                    const modalTarget = this.$refs.itemVariationModal;
                    // Inject item data directly so wizard doesn't depend solely on XHR interceptor
                    if (modalTarget && item) {
                        modalTarget.setAttribute('data-wizard-item-data', JSON.stringify(item));
                    }
                    modalTarget?.classList?.add("active");
                    document.body.style.overflowY = "hidden";
                    this._wizardReturnFocusEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                    setTimeout(() => {
                        if (modalTarget && modalTarget.classList?.contains('active')) {
                            modalTarget.focus({ preventScroll: true });
                        }
                    }, 150);
                }).catch((error) => {
                    this.showItemLoadError(error);
                });
        },
        /**
         * Rouvre le modal article depuis une ligne panier (édition).
         * @param {object} cartLine — entrée `posCart/lists`
         * @param {number} index — index dans le panier
         */
        openEditFromCart: function (cartLine, index) {
            if (!cartLine || cartLine.item_id == null) return;
            this.editingCartIndex = typeof index === 'number' ? index : null;
            // [AUDIT 2026-04-17 R2] Keep the POS channel projection on edit too.
            this.$store.dispatch('item/details', this.posItemDetailsPayload(cartLine.item_id))
                .then((res) => {
                    const item = this.normalizeLoadedItem(res.data.data);
                    this.item = item;
                    this.addons = {};
                    this.addonQuantity = {};
                    this.temp.item_variations = [];
                    this.temp.item_extras = [];
                    this.temp.item_variation_total = 0;
                    this.temp.item_extra_total = 0;
                    this.temp.name = cartLine.name;
                    this.temp.image = cartLine.image;
                    this.temp.item_id = cartLine.item_id;
                    this.temp.quantity = parseInt(cartLine.quantity, 10) > 0 ? parseInt(cartLine.quantity, 10) : 1;
                    this.temp.discount = cartLine.discount || 0;
                    this.temp.convert_price = parseFloat(cartLine.convert_price) || 0;
                    this.temp.currency_price = cartLine.currency_price;
                    this.temp.instruction = cartLine.instruction || '';
                    this.temp.item_variations = normalizeVariationEntries(cartLine.item_variations);
                    this.temp.item_extras = normalizeExtraEntries(cartLine.item_extras);

                    _.forEach(cartLine.pos_line_addons || [], (b) => {
                        const ad = item.addons && item.addons.find((x) => String(x.id) === String(b.parent_addon_id));
                        if (ad) {
                            const q = parseInt(b.quantity, 10) > 0 ? parseInt(b.quantity, 10) : 1;
                            this.addonQuantity[ad.id] = q;
                            this.addons[ad.id] = {
                                name: b.name,
                                image: b.image,
                                item_id: b.item_id,
                                quantity: q,
                                discount: b.discount || 0,
                                currency_price: b.currency_price,
                                convert_price: parseFloat(b.convert_price) || 0,
                                item_variations: _.cloneDeep(b.item_variations),
                                item_extras: _.cloneDeep(b.item_extras),
                                item_variation_total: parseFloat(b.item_variation_total) || 0,
                                item_extra_total: parseFloat(b.item_extra_total) || 0,
                                total_price: parseFloat(b.total_price) || 0,
                                instruction: b.instruction || '',
                            };
                        }
                    });
                    if (item.addons && item.addons.length > 0) {
                        _.forEach(item.addons, (addon) => {
                            if (typeof this.addonQuantity[addon.id] === 'undefined') {
                                this.addonQuantity[addon.id] = 1;
                            }
                        });
                    }
                    this.pruneUnavailableRestoredSelections();
                    this.totalPriceSetup();
                    // Conserver la base déjà enregistrée en panier (pont wizard / ajustements), pas le prix catalogue brut
                    this.temp.convert_price = parseFloat(cartLine.convert_price) || 0;
                    this.temp.currency_price = cartLine.currency_price;
                    var item_addon_total = 0;
                    _.forEach(this.addons, (addon) => {
                        item_addon_total += (parseFloat(addon.total_price) || 0) * (parseInt(addon.quantity) || 1);
                    });
                    this.temp.total_price = parseFloat(
                        (this.temp.convert_price + this.temp.item_variation_total + this.temp.item_extra_total) *
                            this.temp.quantity +
                            item_addon_total
                    );
                    this.usePricedCartBase = true;

                    // [EDIT-RESTORE] Construire selections wizard pour restauration
                    const wizardRestore = this.buildWizardRestorePayload(cartLine, item);
                    const modalTarget = this.$refs.itemVariationModal;
                    if (modalTarget && wizardRestore) {
                        modalTarget.setAttribute('data-wizard-restore-selections', JSON.stringify(wizardRestore));
                    }
                    // Inject item data directly so the wizard doesn't depend on the XHR interceptor
                    // (which misses relative Axios URLs like "admin/item/details/123").
                    if (modalTarget && item) {
                        modalTarget.setAttribute('data-wizard-item-data', JSON.stringify(item));
                    }

                    modalTarget?.classList?.add('active');
                    document.body.style.overflowY = 'hidden';
                    this._wizardReturnFocusEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                    setTimeout(() => {
                        if (modalTarget && modalTarget.classList?.contains('active')) {
                            modalTarget.focus({ preventScroll: true });
                        }
                    }, 150);
                })
                .catch(() => {
                    // [V7 FIX] Show error feedback — silent failure leaves cashier confused
                    this.editingCartIndex = null;
                    this.usePricedCartBase = false;
                    alertService.error(this.$t('message.something_went_wrong') || 'Erreur lors du chargement du produit.');
                });
        },
        variationModalHide: function () {
            this.editingCartIndex = null;
            this.usePricedCartBase = false;
            this.item = null;
            this.resetTempState();

            if (this.$refs.itemVariationModal?.dataset?.wizardTotal) {
                delete this.$refs.itemVariationModal.dataset.wizardTotal;
            }
            this.$refs.itemVariationModal?.removeAttribute?.('data-wizard-pos-line-addons');
            this.$refs.itemVariationModal?.removeAttribute?.('data-wizard-restore-selections');

            const modalDiv = this.$refs.itemVariationModal;
            modalDiv?.classList?.remove("active");
            document.body.style.overflowY = "auto";
            const returnFocusEl = this._wizardReturnFocusEl;
            this._wizardReturnFocusEl = null;
            if (returnFocusEl && typeof returnFocusEl.focus === 'function' && document.contains(returnFocusEl)) {
                this.$nextTick(() => returnFocusEl.focus());
            }
        },
        bumpPricingToCatalog: function () {
            if (!this.usePricedCartBase || !this.item) return;
            this.usePricedCartBase = false;
            this.temp.convert_price = this.item.offer.length > 0 ? this.item.offer[0].convert_price : this.item.convert_price;
            this.temp.currency_price = this.item.offer.length > 0 ? this.item.offer[0].currency_price : this.item.currency_price;
        },
        changeVariation: function (attributeId, variationId, variationName, variationPrice) {
            const attribute = (this.item.itemAttributes || []).find((element) => String(element.id) === String(attributeId));
            const variation = this.getAttributeVariations(attribute || { id: attributeId })
                .find((entry) => String(entry.id) === String(variationId));

            if (!attribute || !variation) return;

            this.selectLegacyVariation(attribute, variation);
        },
        changeVariationAdjust: function (attributeId, variationId) {
            _.forEach(this.getAttributeVariations({ id: attributeId }), (variation) => {
                if (variation.id === variationId) {
                    this.changeVariation(attributeId, variationId, variation.name, variation.convert_price);
                }
            });
        },
        changeExtra: function (e, id, name) {
            this.setExtraQuantity({ id, name }, e.target.checked ? 1 : 0);
        },
        totalPriceSetup: function () {
            let item_variation_total = 0;
            let item_extra_total = 0;
            let item_addon_total = 0;
            _.forEach(normalizeVariationEntries(this.temp.item_variations), (selectedVariation) => {
                const itemVariations = this.getAttributeVariations({ id: selectedVariation.item_attribute_id });
                _.forEach(itemVariations, (itemVariation) => {
                    if (selectedVariation.id === itemVariation.id) {
                        item_variation_total += (parseFloat(itemVariation.convert_price) || 0) * normalizeQuantity(selectedVariation.quantity, 1);
                    }
                });
            });

            _.forEach(normalizeExtraEntries(this.temp.item_extras), (selectedExtra) => {
                _.forEach(this.item.extras, (itemExtra) => {
                    if (selectedExtra.id === itemExtra.id) {
                        item_extra_total += (parseFloat(itemExtra.convert_price) || 0) * normalizeQuantity(selectedExtra.quantity, 1);
                    }
                });
            });

            _.forEach(this.addons, (addon) => {
                item_addon_total += (addon.total_price * addon.quantity);
            });

            this.temp.item_variation_total = item_variation_total;
            this.temp.item_extra_total = item_extra_total;
            var catalogBase =
                parseFloat(this.item.offer.length > 0 ? this.item.offer[0].convert_price : this.item.convert_price) || 0;
            if (!this.usePricedCartBase) {
                this.temp.convert_price = catalogBase;
            }
            var baseUnit = this.usePricedCartBase ? parseFloat(this.temp.convert_price) || 0 : catalogBase;
            this.temp.total_price = parseFloat(
                (baseUnit + this.temp.item_variation_total + this.temp.item_extra_total) * this.temp.quantity +
                    item_addon_total
            );
        },
        quantityUp: function () {
            this.bumpPricingToCatalog();
            if (this.temp.quantity === 0) {
                this.temp.quantity = 1;
            }
            this.totalPriceSetup();
        },
        quantityIncrement: function () {
            this.bumpPricingToCatalog();
            this.temp.quantity++;
            if (this.temp.quantity <= 0) {
                this.temp.quantity = 1;
            }
            this.totalPriceSetup();
        },
        quantityDecrement: function () {
            this.bumpPricingToCatalog();
            this.temp.quantity--;
            if (this.temp.quantity <= 0) {
                this.temp.quantity = 1;
            }
            this.totalPriceSetup();
        },
        addonQuantityUp: function (id) {
            this.bumpPricingToCatalog();
            const addon = this.getAddonById(id);
            if (this.isAddonUnavailable(addon) && typeof this.addons[id] === "undefined") {
                this.addonQuantity[id] = 1;
                return;
            }
            if (typeof this.addonQuantity[id] !== "undefined") {
                if (this.addonQuantity[id] === 0) {
                    this.addonQuantity[id] = 1;
                }
            }
            if (typeof this.addons[id] !== "undefined") {
                this.addons[id].quantity = this.addonQuantity[id];
            }

            this.totalPriceSetup();
        },
        addonQuantityIncrement: function (id) {
            this.bumpPricingToCatalog();
            const addon = this.getAddonById(id);
            if (this.isAddonUnavailable(addon)) {
                return;
            }
            if (typeof this.addonQuantity[id] !== "undefined") {
                this.addonQuantity[id]++;
                if (this.addonQuantity[id] <= 0) {
                    this.addonQuantity[id] = 1;
                }
                if (typeof this.addons[id] !== "undefined") {
                    this.addons[id].quantity = this.addonQuantity[id];
                }
                this.totalPriceSetup();
            }
        },
        addonQuantityDecrement: function (id) {
            this.bumpPricingToCatalog();
            if (typeof this.addonQuantity[id] !== "undefined") {
                this.addonQuantity[id]--;
                if (this.addonQuantity[id] <= 0) {
                    this.addonQuantity[id] = 1;
                }
                if (typeof this.addons[id] !== "undefined") {
                    this.addons[id].quantity = this.addonQuantity[id];
                }
                this.totalPriceSetup();
            }
        },
        changeAddon: function (addon) {
            this.bumpPricingToCatalog();
            if (this.isAddonUnavailable(addon) && typeof this.addons[addon.id] === "undefined") {
                return;
            }
            if (typeof this.addons[addon.id] === "undefined") {
                this.addons[addon.id] = {
                    name: addon.addon_item_name,
                    image: addon.thumb,
                    item_id: addon.item_addon_id,
                    quantity: this.addonQuantity[addon.id],
                    discount: 0,
                    currency_price: addon.offer.length > 0 ? addon.offer[0].currency_price : addon.addon_item_currency_price,
                    convert_price: addon.offer.length > 0 ? addon.offer[0].convert_price : addon.addon_item_convert_price,
                    item_variations: {
                        variations: {},
                        names: {}
                    },
                    item_extras: {
                        extras: [],
                        names: []
                    },
                    item_variation_total: addon.variation_total_convert_price,
                    item_extra_total: 0,
                    total_price: addon.total_convert_price,
                    instruction: "",
                };
                if (typeof addon.variations !== 'undefined' && addon.variations && Object.keys(addon.variations).length !== 0) {
                    _.forEach(addon.variations, (variationId, attributeId) => {
                        this.addons[addon.id].item_variations.variations[attributeId] = variationId;
                    });

                }
                if (addon.variation_names.length > 0) {
                    _.forEach(addon.variation_names, (variation) => {
                        this.addons[addon.id].item_variations.names[variation.attribute_name] = variation.name;
                    });
                }
            } else {
                delete this.addons[addon.id];
            }
            this.totalPriceSetup();
        },
        /** Menus regroupés passés par le wizard si le clic .addon n’a pas rempli `this.addons`. */
        readWizardBundledAddons: function () {
            var el = this.$refs.itemVariationModal;
            if (!el || typeof el.getAttribute !== 'function') return [];
            var raw = el.getAttribute('data-wizard-pos-line-addons');
            if (!raw) return [];
            try {
                var arr = JSON.parse(raw);
                return Array.isArray(arr) ? arr : [];
            } catch (e) {
                return [];
            }
        },
        /**
         * [EDIT-RESTORE] Reconstruit les selections wizard à partir d'une ligne panier
         * pour pré-remplir le wizard lors de l'édition.
         */
        buildWizardRestorePayload: function (cartLine, item) {
            const restore = {
                viandes: {},
                sauces: {},
                sauceOrder: [],
                garnitures: {},
                supplements: {},
                menuChoice: 'none',
                pain: null,
                accompagnement: null,
                sauceSingle: null,
                instruction: cartLine.instruction || '',
                fritesGrande: false,
                fritesCheddar: false,
                sauceFrites: {},
                sauceFritesOrder: [],
                // [X4 FIX] boissonChoice restored from menu_restore
                boissonChoice: null,
                // [X5 FIX] viandeSupplItems restored from menu_restore
                viandeSupplItems: {},
                // [Y1 FIX] Carry cart line quantity so wizard itemQuantity stays in sync
                _cartQuantity: parseInt(cartLine.quantity, 10) > 0 ? parseInt(cartLine.quantity, 10) : 1
            };

            // 1. Variations → pain, viande, sauce
            const variationEntries = normalizeVariationEntries(cartLine.item_variations);
            if (variationEntries.length > 0) {
                variationEntries.forEach((variationEntry) => {
                    const attrName = variationEntry.variation_name || '';
                    const varName = variationEntry.name || '';
                    const attrLower = attrName.toLowerCase();
                    
                    // Pain / Galette — match by exact attrName first, then fallback
                    if (attrLower.includes('pain') || attrLower.includes('galette')) {
                        let painAttr = item.itemAttributes?.find(a => (a.name || '').toLowerCase() === attrLower);
                        if (!painAttr) {
                            painAttr = item.itemAttributes?.find(a => {
                                const n = (a.name || '').toLowerCase();
                                return n.includes('pain') || n.includes('galette');
                            });
                        }
                        if (painAttr && item.variations && item.variations[painAttr.id]) {
                            const painVar = item.variations[painAttr.id].find(v => v.name === varName);
                            if (painVar) restore.pain = painVar.id;
                        }
                    }
                    // Viande (compter les occurrences)
                    // [N2 FIX] Match the attribute by its exact name (attrName) first, not generic find.
                    // This handles "Viande 1" / "Viande 2" as separate attributes correctly.
                    else if (attrLower.includes('viande') || attrLower.includes('meat')) {
                        // 1st pass: find attribute whose name matches attrName exactly (case-insensitive)
                        let viandeAttr = item.itemAttributes?.find(a =>
                            (a.name || '').toLowerCase() === attrLower
                        );
                        // 2nd pass fallback: any attribute containing 'viande' or 'meat'
                        if (!viandeAttr) {
                            viandeAttr = item.itemAttributes?.find(a => {
                                const n = (a.name || '').toLowerCase();
                                return n.includes('viande') || n.includes('meat');
                            });
                        }
                        if (viandeAttr && item.variations && item.variations[viandeAttr.id]) {
                            const viandeVar = item.variations[viandeAttr.id].find(v => v.name === varName);
                            if (viandeVar) {
                                const key = 'v_' + viandeVar.id;
                                restore.viandes[key] = (restore.viandes[key] || 0) + normalizeQuantity(variationEntry.quantity, 1);
                            }
                        }
                    }
                    // Sauce (première = gratuite) — match by exact attrName first, then fallback
                    else if (attrLower.includes('sauce')) {
                        let sauceAttr = item.itemAttributes?.find(a => (a.name || '').toLowerCase() === attrLower);
                        if (!sauceAttr) {
                            sauceAttr = item.itemAttributes?.find(a => (a.name || '').toLowerCase().includes('sauce'));
                        }
                        if (sauceAttr && item.variations && item.variations[sauceAttr.id]) {
                            const sauceVar = item.variations[sauceAttr.id].find(v => v.name === varName);
                            if (sauceVar) {
                                const key = 's_' + sauceVar.id;
                                if (!restore.sauceOrder.includes(key)) {
                                    restore.sauces[key] = true;
                                    restore.sauceOrder.push(key);
                                }
                                // Sauce unique (omelettes, snacking)
                                if (restore.sauceOrder.length === 1) {
                                    restore.sauceSingle = sauceVar.id;
                                }
                            }
                        }
                    }
                    // Accompagnement (assiettes : riz, frites, salade) — match by exact attrName first
                    else if (attrLower.includes('accompagnement') || attrLower.includes('riz') || attrLower.includes('salade')) {
                        let accompAttr = item.itemAttributes?.find(a => (a.name || '').toLowerCase() === attrLower);
                        if (!accompAttr) {
                            accompAttr = item.itemAttributes?.find(a => {
                                const n = (a.name || '').toLowerCase();
                                return n.includes('accompagnement') || n.includes('riz') || n.includes('salade');
                            });
                        }
                        if (accompAttr && item.variations && item.variations[accompAttr.id]) {
                            const accompVar = item.variations[accompAttr.id].find(v => v.name === varName);
                            if (accompVar) restore.accompagnement = accompVar.id;
                        }
                    }
                });
            }

            // 2. Extras → garnitures, suppléments, sauces extras, sauce frites
            const extraEntries = normalizeExtraEntries(cartLine.item_extras);
            if (extraEntries.length > 0) {
                extraEntries.forEach((extraEntry) => {
                    const extraName = extraEntry.name || '';
                    const extra = item.extras?.find(e => e.name === extraName);
                    if (!extra) return;

                    const extraLower = extraName.toLowerCase();
                    const isFree = parseFloat(extra.convert_price) <= 0;

                    // Sauce frites (menu)
                    if (extraLower.includes('sauce') && (extraLower.includes('frites') || extraLower.includes('frite'))) {
                        const key = 'sf_' + extra.id;
                        if (!restore.sauceFritesOrder.includes(key)) {
                            restore.sauceFrites[key] = true;
                            restore.sauceFritesOrder.push(key);
                        }
                    }
                    // Grande portion / Cheddar (menu)
                    else if (extraLower.includes('grande') && extraLower.includes('portion')) {
                        restore.fritesGrande = true;
                    }
                    else if (extraLower.includes('cheddar')) {
                        restore.fritesCheddar = true;
                    }
                    // Garnitures gratuites (tomate, oignon, salade, etc.)
                    else if (isFree || extraLower.includes('tomate') || extraLower.includes('oignon') || extraLower.includes('salade') || extraLower.includes('cornichon')) {
                        restore.garnitures['c_' + extra.id] = true;
                    }
                    // Sauce extra (payante)
                    else if (extraLower.includes('sauce')) {
                        const key = 's_' + extra.id;
                        if (!restore.sauceOrder.includes(key)) {
                            restore.sauces[key] = true;
                            restore.sauceOrder.push(key);
                        }
                    }
                    // Supplément payant
                    else {
                        restore.supplements['p_' + extra.id] = true;
                    }
                });
            }

            // [P5-2 FIX] Restore sauceSingle from instruction text if not already set via variations
            // Instruction format: "Sauce: <name>" on its own line
            if (!restore.sauceSingle && cartLine.instruction) {
                const sauceMatch = cartLine.instruction.match(/(?:^|\n)Sauce\s*:\s*(.+?)(?:\n|$)/i);
                if (sauceMatch) {
                    const sauceName = sauceMatch[1].trim();
                    const sauceExtra = item.extras?.find(e => e.name === sauceName);
                    if (sauceExtra) {
                        restore.sauceSingle = sauceExtra.id;
                        // Also add to sauceOrder if not already present
                        const sKey = 's_' + sauceExtra.id;
                        if (!restore.sauceOrder.includes(sKey)) {
                            restore.sauces[sKey] = true;
                            restore.sauceOrder.push(sKey);
                        }
                    }
                }
            }

            // 3. Addons → menuChoice + menu_restore (sauce frites, options frites)
            if (cartLine.pos_line_addons && cartLine.pos_line_addons.length > 0) {
                const firstAddon = cartLine.pos_line_addons[0];
                const addonId = firstAddon.parent_addon_id;
                restore.menuChoice = 'addon_' + addonId;

                // Restaurer les extras menu depuis menu_restore (stocké par le wizard)
                if (firstAddon.menu_restore) {
                    const mr = firstAddon.menu_restore;
                    if (mr.fritesGrande) restore.fritesGrande = true;
                    if (mr.fritesCheddar) restore.fritesCheddar = true;
                    if (mr.sauceFrites && typeof mr.sauceFrites === 'object') {
                        restore.sauceFrites = Object.assign({}, mr.sauceFrites);
                    }
                    if (Array.isArray(mr.sauceFritesOrder) && mr.sauceFritesOrder.length > 0) {
                        restore.sauceFritesOrder = mr.sauceFritesOrder.slice();
                    }
                    // [X4 FIX] Restore boissonChoice from menu_restore
                    if (mr.boissonChoice != null) {
                        restore.boissonChoice = mr.boissonChoice;
                    }
                    // [X5 FIX] Restore viandeSupplItems from menu_restore
                    if (mr.viandeSupplItems && typeof mr.viandeSupplItems === 'object') {
                        restore.viandeSupplItems = Object.assign({}, mr.viandeSupplItems);
                    }
                }
            }

            return restore;
        },
        /** Une seule ligne panier : principal + `pos_line_addons` (menu, etc.) */
        buildPosCartMainPayload: function () {
            var quantity = parseInt(this.temp.quantity) > 0 ? parseInt(this.temp.quantity) : 1;
            var bridgedWizardTotal = parseFloat(this.$refs.itemVariationModal?.dataset?.wizardTotal || 0) || 0;
            var wizardCartDisplay = this.$refs.itemVariationModal?.dataset?.wizardCartDisplay || '';
            var wizardBundled = this.readWizardBundledAddons();
            var addonTotal = 0;
            var pos_line_addons = [];

            // Wizard bundled addons take priority: they carry menu_extras + menu_restore.
            // Only fall back to Vue's this.addons when the wizard is not active.
            if (wizardBundled.length > 0) {
                wizardBundled.forEach((b) => {
                    addonTotal += (parseFloat(b.total_price) || 0) * (parseInt(b.quantity) || 1);
                });
                wizardBundled.forEach((b) => {
                    pos_line_addons.push(_.cloneDeep(b));
                });
            // [W6 FIX] Use proper typeof check instead of comparing to string "undefined"
            } else if (this.addons && typeof this.addons === 'object' && Object.keys(this.addons).length !== 0) {
                _.forEach(this.addons, (addon, parentKey) => {
                    const catalogAddon = this.getAddonById(parentKey);
                    if (!catalogAddon || this.isAddonUnavailable(catalogAddon)) return;
                    addonTotal += (parseFloat(addon.total_price) || 0) * (parseInt(addon.quantity) || 1);
                });
                _.forEach(this.addons, (addon, parentKey) => {
                    const catalogAddon = this.getAddonById(parentKey);
                    if (!catalogAddon || this.isAddonUnavailable(catalogAddon)) return;
                    pos_line_addons.push({
                        parent_addon_id: parentKey,
                        name: addon.name,
                        image: addon.image,
                        item_id: addon.item_id,
                        quantity: addon.quantity,
                        discount: addon.discount || 0,
                        currency_price: addon.currency_price,
                        convert_price: addon.convert_price,
                        item_variations: _.cloneDeep(addon.item_variations),
                        item_extras: _.cloneDeep(addon.item_extras),
                        item_variation_total: addon.item_variation_total,
                        item_extra_total: addon.item_extra_total,
                        instruction: addon.instruction || '',
                        total_price: addon.total_price,
                    });
                });
            }

            var effectiveLineTotal = bridgedWizardTotal > 0 ? bridgedWizardTotal : (parseFloat(this.temp.total_price) || 0);
            var mainLineTotal = Math.max(0, effectiveLineTotal - addonTotal);
            var mainUnitTotal = quantity > 0 ? (mainLineTotal / quantity) : 0;
            var adjustedBaseConvertPrice = Math.max(
                0,
                mainUnitTotal - (parseFloat(this.temp.item_variation_total) || 0) - (parseFloat(this.temp.item_extra_total) || 0)
            );

            return {
                name: this.temp.name,
                image: this.temp.image,
                item_id: normalizeId(this.temp.item_id) || this.temp.item_id,
                quantity: normalizeQuantity(this.temp.quantity, 1),
                discount: this.temp.discount,
                currency_price: this.temp.currency_price,
                convert_price: adjustedBaseConvertPrice,
                item_variations: normalizeVariationEntries(this.temp.item_variations)
                    .filter((entry) => {
                        const variation = this.findVariationById(entry.id);
                        return variation && !this.isModifierUnavailable(variation);
                    }),
                item_extras: normalizeExtraEntries(this.temp.item_extras)
                    .filter((entry) => {
                        const extra = this.findExtraById(entry.id);
                        return extra && !this.isModifierUnavailable(extra);
                    }),
                item_variation_total: this.temp.item_variation_total,
                item_extra_total: this.temp.item_extra_total,
                instruction: this.temp.instruction,
                pos_line_addons: pos_line_addons,
                cart_display: wizardCartDisplay,
            };
        },
        addToCart: function () {
            if (!this.canAddToCart) return;
            var mainPayload = this.buildPosCartMainPayload();
            var editIdx = this.editingCartIndex;
            var finishSuccess = () => {
                this.editingCartIndex = null;
                this.usePricedCartBase = false;
                this.item = null;
                this.resetTempState();
                if (this.$refs.itemVariationModal?.dataset?.wizardTotal) {
                    delete this.$refs.itemVariationModal.dataset.wizardTotal;
                }
                this.$refs.itemVariationModal?.removeAttribute?.('data-wizard-pos-line-addons');
                this.itemArrays = [];

                alertService.success(this.$t('message.add_to_cart'));
                appService.modalHide('#item-variation-modal');
            };
            var finishError = () => {
                if (this.$refs.itemVariationModal?.dataset?.wizardTotal) {
                    delete this.$refs.itemVariationModal.dataset.wizardTotal;
                }
                this.$refs.itemVariationModal?.removeAttribute?.('data-wizard-pos-line-addons');
                this.itemArrays = [];
            };

            if (editIdx !== null && editIdx >= 0) {
                this.$store.dispatch('posCart/replaceCartLine', { index: editIdx, item: mainPayload })
                    .then(finishSuccess)
                    .catch(finishError);
                return;
            }

            var optimisticTempId = null;
            this.$store.dispatch('posCart/addOptimistic', { item: mainPayload })
                .then((tempId) => {
                    optimisticTempId = tempId;
                    return this.$store.dispatch('posCart/lists', [mainPayload]);
                })
                .then(() => {
                    if (optimisticTempId != null) {
                        this.$store.commit('posCart/__optimisticConfirm', optimisticTempId);
                        this.$store.commit('posCart/subtotal');
                    }
                    finishSuccess();
                    // [POS-CATEGORY-FIRST 2026-06-23 /goal] New line taken →
                    // signal the parent to return to the category grid hub.
                    this.$emit('item:added');
                })
                .catch(() => {
                    if (optimisticTempId != null) {
                        this.$store.commit('posCart/__optimisticRollback', optimisticTempId);
                        this.$store.commit('posCart/subtotal');
                    }
                    finishError();
                });
        },
    },
    watch: {
        'temp.instruction'(val) {
            // [V5 FIX] Raise cap from 190 to 500 to match API ValidJsonOrder rule (500 chars).
            // 190 was too restrictive for complex wizard-generated instructions.
            if (val.length > 500) {
                this.temp.instruction = val.slice(0, 500);
                this.instructionError = this.$t("message.special_instructions_limit");
            }
            if (val.length <= 500) {
                this.instructionError = "";
            }
        }
    },

    mounted() {
        this._posTileClickHandler = (event) => this.handleNativeTileClick(event);
        this.$nextTick(() => {
            document?.addEventListener?.('click', this._posTileClickHandler, true);
        });

        // [WIZARD-SUBMIT] The pos-wizard.js dispatches 'wizard:add-to-cart' on the modal element
        // instead of clicking the (potentially disabled) Vue button.
        // This listener calls addToCart() directly, bypassing the :disabled guard.
        const modal = this.$refs.itemVariationModal;
        if (modal) {
            modal.addEventListener('wizard:add-to-cart', () => {
                const wizardTotal = parseFloat(modal.dataset?.wizardTotal || 0);
                if (wizardTotal > 0) {
                    this.temp.total_price = wizardTotal;
                }
                if (!this.canAddToCart) return;
                this.addToCart();
            });
        }
    },
    beforeUnmount() {
        if (this._posTileClickHandler) {
            document?.removeEventListener?.('click', this._posTileClickHandler, true);
            this._posTileClickHandler = null;
        }
    },

}
</script>

<style scoped>
/* =============================================================================
   ItemComponent — POS V5 Design Convergence (refonte 2026-05-02)
   -----------------------------------------------------------------------------
   Mission : CV1-POS-DESIGN-CONVERGENCE-001
   Doc plan : §3.2
   - Tiles produits stylisées via :deep() override dans PosComponent.vue
     (tokens V5 unifiés). On ne redéfinit pas ici pour ne pas fragmenter.
   - Modal item variation : chrome refondu en warm V5 (cf. .pos-v5-item-modal*).
   ============================================================================= */

/* =============================================================================
   POS V5 R2 — Tiles produits avec photo hero (layout vertical : visual + body)
   ============================================================================= */
.pos-v5-tile.pos-item-tile {
    /* Reset de l'ancien flex-col items-center forcé */
    display: flex;
    flex-direction: column;
    background: var(--pos-v5-bg-panel);
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-lg);
    overflow: hidden;
    cursor: pointer;
    appearance: none;
    text-align: left;
    font-family: var(--pos-v5-font-sans);
    color: inherit;
    padding: 0 !important;
    box-shadow: var(--pos-v5-shadow-sm);
    transition:
        transform var(--pos-v5-duration-base) var(--pos-v5-ease-bounce),
        border-color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
        box-shadow var(--pos-v5-duration-base) var(--pos-v5-ease-standard);
    min-height: 0;
}
.pos-v5-tile.pos-item-tile:hover:not(.is-unavailable):not(:disabled) {
    transform: translateY(-2px);
    border-color: var(--pos-v5-brand-red);
    box-shadow: var(--pos-v5-shadow-lift);
}
.pos-v5-tile.pos-item-tile:active:not(.is-unavailable):not(:disabled) {
    transform: translateY(0);
}
.pos-v5-tile.pos-item-tile:focus-visible {
    outline: var(--pos-v5-focus-width) solid var(--pos-v5-focus-color);
    outline-offset: var(--pos-v5-focus-offset);
}

/* Photo visual (hero 4/3) */
.pos-v5-tile__visual {
    position: relative;
    aspect-ratio: 4 / 3;
    background: var(--pos-v5-bg-subtle);
    overflow: hidden;
    flex-shrink: 0;
}
.pos-v5-tile__image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform var(--pos-v5-duration-slow) var(--pos-v5-ease-standard);
}
.pos-v5-tile.pos-item-tile:hover:not(.is-unavailable) .pos-v5-tile__image {
    transform: scale(1.06);
}
.pos-v5-tile__visual-fallback {
    width: 100%;
    height: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    color: var(--pos-v5-ink-muted);
}

/* Body (name + price + add) */
.pos-v5-tile__body {
    padding: var(--pos-v5-space-3);
    display: flex;
    flex-direction: column;
    gap: var(--pos-v5-space-2);
    flex: 1;
    min-height: 84px;
}
.pos-v5-tile__name {
    margin: 0;
    color: var(--pos-v5-ink);
    font-size: var(--pos-v5-text-body) !important;
    font-weight: var(--pos-v5-weight-bold) !important;
    line-height: var(--pos-v5-leading-snug) !important;
    /* [CAISSE-INGREDIENTS 2026-06-04] flex:1 retiré : le nom + la description
       s'empilent en haut, le pied (prix) reste collé en bas via margin-top:auto. */
    text-transform: capitalize;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-align: left;
}
/* [CAISSE-INGREDIENTS 2026-06-04] Ingrédients sous le nom, muté, 2 lignes max. */
.pos-v5-tile__desc {
    margin: calc(-1 * var(--pos-v5-space-1)) 0 0 0;
    color: var(--pos-v5-ink-soft);
    font-size: var(--pos-v5-text-caption);
    line-height: var(--pos-v5-leading-snug);
    text-align: left;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.pos-v5-tile__foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--pos-v5-space-2);
    margin-top: auto;
}
.pos-v5-tile__price {
    margin: 0;
    font-family: var(--pos-v5-font-sans);
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
    font-size: var(--pos-v5-text-body-lg) !important;
    font-weight: var(--pos-v5-weight-extrabold) !important;
    color: var(--pos-v5-brand-red) !important;
    line-height: 1;
}
.pos-v5-tile__add {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition:
        background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
        color var(--pos-v5-duration-fast) var(--pos-v5-ease-standard),
        transform var(--pos-v5-duration-fast) var(--pos-v5-ease-bounce);
}
.pos-v5-tile__add i {
    line-height: 1;
}
.pos-v5-tile.pos-item-tile:hover:not(.is-unavailable) .pos-v5-tile__add {
    background: var(--pos-v5-brand-red);
    color: var(--pos-v5-ink-on-red);
    transform: scale(1.08);
}

/* Unavailable overlay (pos-item-86-badge devient un overlay full surface) */
.pos-v5-tile__overlay,
.pos-item-86-badge {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(194, 30, 47, 0.86);
    color: var(--pos-v5-ink-on-red);
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h6);
    font-weight: var(--pos-v5-weight-extrabold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    text-align: center;
    padding: var(--pos-v5-space-2);
    backdrop-filter: blur(2px);
    /* Reset des anciens placement / radius */
    top: auto;
    right: auto;
    border-radius: 0;
    box-shadow: none;
    line-height: 1.1;
}
/* When pos-item-86-badge is OUTSIDE the tile__visual (legacy compat — no longer used) */
.pos-item-tile > .pos-item-86-badge:not(.pos-v5-tile__overlay) {
    inset: auto;
    top: 8px;
    right: 8px;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 10px;
    background: rgba(194, 30, 47, 0.92);
    backdrop-filter: none;
    box-shadow: 0 2px 6px rgba(26, 26, 26, 0.18);
}

.pos-item-tile.is-unavailable {
    opacity: 0.55;
    pointer-events: none;
    filter: grayscale(0.4);
}
.pos-add-to-cart-sticky {
    position: sticky;
    bottom: 0;
    z-index: 5;
    background: #fff;
    padding-top: 8px;
    padding-bottom: 2px;
}
/* Wizard shell: footer is a flex column — not sticky inside scroll */
.pos-v4-item-wizard-footer.pos-add-to-cart-sticky {
    position: relative;
    padding-top: 0;
    padding-bottom: 0;
}

/* =============================================================================
   POS V5 Item Modal (variation modal léger — produits sans wizard kiosk)
   -----------------------------------------------------------------------------
   Le wizard kiosk reste FROZEN. Ce modal sert pour les produits simples
   (boisson avec taille, dessert avec variantes, etc.) — refonte chrome warm V5.
   ============================================================================= */
.pos-v5-item-modal :deep(.modal-dialog) {
    border-radius: var(--pos-v5-radius-xl) !important;
    box-shadow: var(--pos-v5-shadow-modal) !important;
    background: var(--pos-v5-bg-panel) !important;
    border: 1px solid var(--pos-v5-border) !important;
    font-family: var(--pos-v5-font-sans);
}

.pos-v5-item-modal__head {
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 80%);
    border-bottom: 1px solid var(--pos-v5-border) !important;
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5) var(--pos-v5-space-3) !important;
}

.pos-v5-item-modal__foot {
    background: var(--pos-v5-bg-panel) !important;
    box-shadow: var(--pos-v5-shadow-sticky-top);
}

/* CTA "Ajouter au panier — X €" : pleine largeur, brand gradient, ombre soft */
.pos-v5-item-add-cta {
    background: linear-gradient(135deg, var(--pos-v5-brand-red), var(--pos-v5-brand-red-dark)) !important;
    border: 0;
    box-shadow: var(--pos-v5-shadow-cta);
    min-height: var(--pos-v5-tap-large);
    letter-spacing: var(--pos-v5-tracking-tight);
    transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-bounce);
}
.pos-v5-item-add-cta:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 12px 28px rgba(244, 80, 30, 0.32);
}

/* Attribute / variation cards within the modal body */
.pos-v5-item-modal :deep(.modal-body) > * {
    font-family: var(--pos-v5-font-sans);
}

/* Respecte reduced-motion */
@media (prefers-reduced-motion: reduce) {
    .pos-v5-item-add-cta { transition: none !important; }
    .pos-v5-item-add-cta:hover:not(:disabled) { transform: none; }
}
</style>
