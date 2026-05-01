<template>
    <div class="grid grid-cols-3 gap-2.5 mb-8 md:mb-0">
        <div v-for="item in items" :key="item.id || item"
            :class="tileClassList(item)"
            :aria-disabled="isCatalogTileUnavailable(item) ? 'true' : 'false'"
            role="button"
            :tabindex="isCatalogTileUnavailable(item) ? -1 : 0"
            :aria-label="$t('a11y.add_item', { item: item.name, price: itemOfferPrice(item) })"
            style="min-height: 90px;"
            @click.prevent="onProductTileClick(item)"
            @keyup.enter.prevent="addItem(item)"
            @keyup.space.prevent="addItem(item)"
            data-modal="#item-variation-modal">
            <span v-if="isCatalogTileUnavailable(item)" class="pos-item-86-badge">{{ $t('pos.item_86_d') }}</span>
            <div class="flex-1 flex items-center justify-center w-full">
                <h3 class="text-xs font-semibold font-rubik capitalize text-center leading-tight text-[#2E2F38] line-clamp-3">{{ item.name }}</h3>
            </div>
            <div class="flex items-center justify-between w-full mt-1">
                <h4 class="text-[11px] font-rubik font-medium text-primary">
                    {{ item.offer.length > 0 ? item.offer[0].currency_price : item.currency_price }}
                </h4>
                <span aria-hidden="true"
                    class="flex items-center justify-center w-6 h-6 rounded-full border border-primary text-primary hover:bg-primary hover:text-white transition">
                    <i class="lab lab-bag-2 font-fill-primary lab-font-size-10" style="font-size:11px;"></i>
                </span>
            </div>
        </div>
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

    <!--========VARIATION PART START=========-->
    <div id="item-variation-modal" ref="itemVariationModal" class="modal ff-modal">
        <div class="modal-dialog max-w-[820px]" v-if="item">
            <div class="modal-header items-start border-none pb-0">
                <div class="flex gap-4">
                    <img class="flex-shrink-0 w-[72px] h-[72px] object-cover rounded-lg" :src="item.thumb"
                        alt="thumbnail">
                    <div class="flex-auto">
                        <div class="flex items-start gap-2 mb-1">
                            <h3 class="text-sm font-semibold capitalize">{{ item.name }}</h3>
                            <button v-if="item.caution" type="button" class="info-btn mt-0.5 flex items-start"
                                data-modal="#item-info-modal" @click.prevent="infoModalShow(item.name, item.caution)">
                                <i class="lab lab-information font-fill-paragraph transition lab-font-size-16"></i>
                            </button>
                        </div>
                        <p class="text-xs mb-2">{{ item.description }}</p>
                        <h4 class="text-sm font-semibold">{{ item.offer.length > 0 ? item.offer[0].currency_price :
                            item.currency_price }}</h4>
                    </div>
                </div>
                <button class="modal-close lab-close-circle-line font-fill-danger lab-font-size-24"
                    @click.prevent="variationModalHide"></button>
            </div>
            <div class="modal-body">
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
                                        :class="getVariationQuantity(variation.id) > 0 ? 'border-primary bg-[#FFEDF4]' : 'border-[#F7F7FC] bg-[#F7F7FC]'"
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
                        <Swiper :speed="1000" slidesPerView="auto" :spaceBetween="16">
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
                <div class="pos-add-to-cart-sticky">
                    <button type="button" :disabled="!canAddToCart" @click.prevent="addToCart"
                        class="flex items-center justify-center gap-3 rounded-3xl text-base py-3 px-3 font-medium w-full text-white bg-primary disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="icon-bag-2"></i>
                        <span>
                            {{ $t('button.add_to_cart') }} -
                            {{
                                currencyFormat(temp.total_price, setting.site_digit_after_decimal_point,
                                    setting.site_default_currency_symbol, setting.site_currency_position)
                            }}
                        </span>
                    </button>
                    <div v-if="itemUnavailabilityBannerVisible" class="alert alert-warning mt-2" role="status">
                        {{ $t('pos.item_unavailable_during_edit') }}
                    </div>
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
        items: Object
    },
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
            return this.temp.total_price > 0 && !this.hasSelectionErrors() && this.catalogItemAvailable;
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
            return {
                'pos-item-tile': true,
                'is-unavailable': this.isCatalogTileUnavailable(row),
                'relative flex flex-col items-center justify-between gap-2 p-3 rounded-xl border border-[#EFF0F6] bg-white hover:bg-[#FFEDF4] hover:border-primary hover:shadow-sm transition cursor-pointer select-none': !this.isCatalogTileUnavailable(row),
                'relative flex flex-col items-center justify-between gap-2 p-3 rounded-xl border border-[#EFF0F6] bg-white transition select-none': this.isCatalogTileUnavailable(row),
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
            this.$store.dispatch('item/details', { id: selectedItem.id, surface: 'pos' })
                .then((res) => {

                    const item = res.data.data;
                    this.item = res.data.data;

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
                }).catch({});
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
            this.$store.dispatch('item/details', { id: cartLine.item_id, surface: 'pos' })
                .then((res) => {
                    const item = res.data.data;
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
        // [WIZARD-SUBMIT] The pos-wizard.js dispatches 'wizard:add-to-cart' on the modal element
        // instead of clicking the (potentially disabled) Vue button.
        // This listener calls addToCart() directly, bypassing the :disabled guard.
        const modal = this.$refs.itemVariationModal;
        if (modal) {
            modal.addEventListener('wizard:add-to-cart', () => {
                // Ensure total_price is set from wizard total if Vue hasn't computed it yet
                const wizardTotal = parseFloat(modal.dataset?.wizardTotal || 0);
                if (wizardTotal > 0 && this.temp.total_price <= 0) {
                    this.temp.total_price = wizardTotal;
                }
                if (!this.canAddToCart) return;
                this.addToCart();
            });
        }
    },

}
</script>

<style scoped>
.pos-item-tile.is-unavailable {
    opacity: 0.45;
    pointer-events: none;
    filter: grayscale(0.7);
}
.pos-item-86-badge {
    position: absolute;
    top: 6px;
    right: 6px;
    z-index: 2;
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
    padding: 3px 6px;
    border-radius: 6px;
    background: rgba(46, 47, 56, 0.85);
    color: #fff;
}
.pos-add-to-cart-sticky {
    position: sticky;
    bottom: 0;
    z-index: 5;
    background: #fff;
    padding-top: 8px;
    padding-bottom: 2px;
}
</style>
