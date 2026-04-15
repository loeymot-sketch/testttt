<template>
    <div class="grid grid-cols-3 gap-2.5 mb-8 md:mb-0">
        <div v-for="item in items" :key="item"
            class="flex flex-col items-center justify-between gap-2 p-3 rounded-xl border border-[#EFF0F6] bg-white hover:bg-[#FFEDF4] hover:border-primary hover:shadow-sm transition cursor-pointer select-none"
            style="min-height: 90px;"
            @click.prevent="variationModalShow(item)" data-modal="#item-variation-modal">
            <div class="flex-1 flex items-center justify-center w-full">
                <h3 class="text-xs font-semibold font-rubik capitalize text-center leading-tight text-[#2E2F38] line-clamp-3">{{ item.name }}</h3>
            </div>
            <div class="flex items-center justify-between w-full mt-1">
                <h4 class="text-[11px] font-rubik font-medium text-primary">
                    {{ item.offer.length > 0 ? item.offer[0].currency_price : item.currency_price }}
                </h4>
                <button @click.stop.prevent="variationModalShow(item)" data-modal="#item-variation-modal"
                    class="flex items-center justify-center w-6 h-6 rounded-full border border-primary text-primary hover:bg-primary hover:text-white transition">
                    <i class="lab lab-bag-2 font-fill-primary lab-font-size-10 group-hover:text-white" style="font-size:11px;"></i>
                </button>
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
                <div class="mb-4" v-if="item.itemAttributes.length > 1">
                    <div class="row">
                        <div v-for="itemAttribute in item.itemAttributes" class="col-12 sm:col-6">
                            <label class="text-sm leading-6 block font-medium capitalize mb-1.5 text-heading">
                                {{ itemAttribute.name }}
                            </label>
                            <div class="relative">
                                <i
                                    class="lab lab-arrow-down text-sm absolute top-1/2 right-2.5 -translate-y-1/2 lab-font-size-16"></i>
                                <select
                                    @change.prevent="changeVariationAdjust(itemAttribute.id, temp.item_variations.variations[itemAttribute.id])"
                                    v-model="temp.item_variations.variations[itemAttribute.id]"
                                    class="text-xs capitalize rounded-lg h-10 w-full py-1.5 px-2.5 appearance-none transition border border-[#EFF0F6] text-heading hover:border-primary/30">
                                    <option :value="variation.id" v-for="variation in item.variations[itemAttribute.id]"
                                        :key="variation">{{ variation.name }} +{{ variation.currency_price }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mb-4" v-else-if="item.itemAttributes.length > 0">
                    <h3 class="text-sm leading-6 font-medium capitalize mb-2 text-heading">
                        {{ item.itemAttributes[0].name }}
                    </h3>
                    <div class="swiper size-swiper">
                        <div class="size-tabs">
                            <Swiper :speed="1000" slidesPerView="auto" :spaceBetween="16">
                                <SwiperSlide class="!w-fit"
                                    v-for="variation in item.variations[item.itemAttributes[0].id]" :key="variation">
                                    <label
                                        :class="temp.item_variations.variations[variation.item_attribute_id] === variation.id ? 'active' : ''"
                                        :for="variation.item_attribute_id + '-' + variation.name"
                                        class="variation-margin-right w-full min-h-[60px] cursor-pointer py-2 px-3 gap-3 rounded-lg flex items-center border transition border-[#F7F7FC] bg-[#F7F7FC]">
                                        <div class="custom-radio sm flex-shrink-0">
                                            <input :value="variation.id"
                                                @click="changeVariation(variation.item_attribute_id, variation.id, variation.name, variation.convert_price)"
                                                v-model="temp.item_variations.variations[variation.item_attribute_id]"
                                                type="radio" :id="variation.item_attribute_id + '-' + variation.name"
                                                class="custom-radio-field">
                                            <span class="custom-radio-span"></span>
                                        </div>
                                        <img v-if="variation.thumb" class="w-10 h-10 object-cover rounded flex-shrink-0" :src="variation.thumb" :alt="variation.name">
                                        <div class="flex-1 min-w-0">
                                            <h3 class="block capitalize text-xs text-heading">
                                                {{ textShortener(variation.name, 15) }}</h3>
                                            <h4 v-if="variation.price > 0"
                                                class="block text-xs font-medium text-heading">
                                                +{{ variation.currency_price }}
                                            </h4>
                                        </div>
                                    </label>
                                </SwiperSlide>
                            </Swiper>
                        </div>
                    </div>
                </div>
                <div class="mb-4" v-if="item.extras.length > 0">
                    <h3 class="text-sm leading-6 font-medium capitalize mb-2 text-heading">{{ $t('label.extras') }}</h3>
                    <div class="extra-swiper">
                        <Swiper :speed="1000" slidesPerView="auto" :spaceBetween="16">
                            <SwiperSlide v-for="extra in item.extras" :key="extra" class="!w-fit !relative">
                                <label :for="extra.id + extra.name"
                                    class="extra w-full min-h-[60px] cursor-pointer py-2 px-3 gap-3 rounded-lg flex items-center border transition border-[#F7F7FC] bg-[#F7F7FC]">
                                    <div class="custom-checkbox w-3 h-3 flex-shrink-0">
                                        <input :id="extra.id + extra.name"
                                            @change.prevent="changeExtra($event, extra.id, extra.name)"
                                            :value="extra.id" type="checkbox" class="custom-checkbox-field">
                                        <i
                                            class="fa-solid fa-check custom-checkbox-icon leading-[9px] text-[9px] rounded-[3px]"></i>
                                    </div>
                                    <img v-if="extra.thumb" class="w-10 h-10 object-cover rounded flex-shrink-0" :src="extra.thumb" :alt="extra.name">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="block capitalize mb-1 text-xs text-heading">
                                            {{ textShortener(extra.name, 15) }}</h3>
                                        <h4 class="block text-xs font-medium text-heading">+{{
                                            extra.currency_price
                                            }}</h4>
                                    </div>
                                </label>
                            </SwiperSlide>
                        </Swiper>
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
                                        class="addon cursor-pointer w-fit min-w-[200px] h-[70px] rounded-lg flex border border-[#EFF0F6]">
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
                                                type="number"
                                                class="text-center w-5 text-xs font-semibold text-heading indec-value">
                                            <button @click.prevent="addonQuantityIncrement(addon.id)"
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
                    <button type="button" :disabled="temp.total_price <= 0" @click.prevent="addToCart"
                        class="flex items-center justify-center gap-3 rounded-3xl text-base py-3 px-3 font-medium w-full text-white bg-primary">
                        <i class="icon-bag-2"></i>
                        <span>
                            {{ $t('button.add_to_cart') }} -
                            {{
                                currencyFormat(temp.total_price, setting.site_digit_after_decimal_point,
                                    setting.site_default_currency_symbol, setting.site_currency_position)
                            }}
                        </span>
                    </button>
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
            temp: {
                name: "",
                image: "",
                item_id: 0,
                quantity: 0,
                discount: 0,
                currency_price: 0,
                convert_price: 0,
                item_variations: {
                    variations: {},
                    names: {}
                },
                item_extras: {
                    extras: [],
                    names: []
                },
                item_variation_total: 0,
                item_extra_total: 0,
                total_price: 0,
                instruction: "",
            },
            instructionError: ""
        }
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
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

            this.$store.dispatch('item/details', selectedItem.id)
                .then((res) => {

                    const item = res.data.data;
                    this.item = res.data.data;

                    if (this.item.itemAttributes.length > 0) {
                        _.forEach(this.item.itemAttributes, (element) => {
                            if (typeof this.item.variations[element.id][0] !== "undefined") {
                                this.temp.item_variations.variations[this.item.variations[element.id][0].item_attribute_id] = this.item.variations[element.id][0].id;
                                this.temp.item_variations.names[element.name] = this.item.variations[element.id][0].name;
                                this.temp.item_variation_total += this.item.variations[element.id][0].convert_price;
                            }
                        });
                    }

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
                    this.temp.total_price = (item.offer.length > 0 ? item.offer[0].convert_price : item.convert_price) + this.temp.item_variation_total;

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
            this.$store.dispatch('item/details', cartLine.item_id)
                .then((res) => {
                    const item = res.data.data;
                    this.item = item;
                    this.addons = {};
                    this.addonQuantity = {};
                    this.temp.item_variations = { variations: {}, names: {} };
                    this.temp.item_extras = { extras: [], names: [] };
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
                    this.temp.item_variations = _.cloneDeep(cartLine.item_variations);
                    this.temp.item_extras = _.cloneDeep(cartLine.item_extras);

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

            this.temp.name = "";
            this.temp.image = "";
            this.temp.item_id = 0;
            this.temp.quantity = 0;
            this.temp.discount = 0;
            this.temp.currency_price = 0;
            this.temp.convert_price = 0;
            this.temp.item_variations = {
                variations: {},
                names: {}
            };
            this.temp.item_extras = {
                extras: [],
                names: []
            };
            this.temp.item_variation_total = 0;
            this.temp.item_extra_total = 0;
            this.temp.total_price = 0;
            this.temp.instruction = "";
            this.addons = {};
            this.addonQuantity = {}; // [BUG-A6 FIX] Reset addon quantities

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
            this.bumpPricingToCatalog();
            this.temp.item_variations.variations[attributeId] = variationId;
            // [W7 FIX] Coerce both sides to string for comparison — attributeId from DOM events
            // is a string, while element.id from API response is a number. Strict === fails.
            // [V2 FIX] Also store names_by_id[attrId] = {attrName, varName} so buildPosCheckoutOrderRow
            // can join by attrId instead of fragile index-zip.
            if (!this.temp.item_variations.names_by_id) {
                this.temp.item_variations.names_by_id = {};
            }
            _.forEach(this.item.itemAttributes, (element) => {
                if (String(element.id) === String(attributeId)) {
                    this.temp.item_variations.names[element.name] = variationName;
                    this.temp.item_variations.names_by_id[String(attributeId)] = {
                        attrName: element.name,
                        varName: variationName,
                    };
                }
            });
            this.totalPriceSetup();
        },
        changeVariationAdjust: function (attributeId, variationId) {
            _.forEach(this.item.variations[attributeId], (variation) => {
                if (variation.id === variationId) {
                    this.changeVariation(attributeId, variationId, variation.name, variation.convert_price);
                }
            });
        },
        changeExtra: function (e, id, name) {
            this.bumpPricingToCatalog();
            if (e.target.checked) {
                this.temp.item_extras.extras.push(id);
                this.temp.item_extras.names.push(name);
            } else {
                for (let i = 0; i < this.temp.item_extras.extras.length; i++) {
                    if (this.temp.item_extras.extras[i] === id) {
                        this.temp.item_extras.extras.splice(i, 1);
                    }
                }
                for (let i = 0; i < this.temp.item_extras.names.length; i++) {
                    if (this.temp.item_extras.names[i] === name) {
                        this.temp.item_extras.names.splice(i, 1);
                    }
                }
            }
            this.totalPriceSetup();
        },
        totalPriceSetup: function () {
            let item_variation_total = 0;
            let item_extra_total = 0;
            let item_addon_total = 0;
            _.forEach(this.temp.item_variations.variations, (variationId, attributeId) => {
                _.forEach(this.item.variations[attributeId], (itemVariation) => {
                    if (variationId === itemVariation.id) {
                        item_variation_total += itemVariation.convert_price;
                    }
                });
            });

            _.forEach(this.temp.item_extras.extras, (extraId) => {
                _.forEach(this.item.extras, (itemExtra) => {
                    if (extraId === itemExtra.id) {
                        item_extra_total += itemExtra.convert_price;
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
            if (cartLine.item_variations && cartLine.item_variations.names) {
                Object.entries(cartLine.item_variations.names).forEach(([attrName, varName]) => {
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
                                restore.viandes[key] = (restore.viandes[key] || 0) + 1;
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
            if (cartLine.item_extras && cartLine.item_extras.names) {
                cartLine.item_extras.names.forEach((extraName) => {
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
                _.forEach(this.addons, (addon) => {
                    addonTotal += (parseFloat(addon.total_price) || 0) * (parseInt(addon.quantity) || 1);
                });
                _.forEach(this.addons, (addon, parentKey) => {
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
                item_id: this.temp.item_id,
                quantity: this.temp.quantity,
                discount: this.temp.discount,
                currency_price: this.temp.currency_price,
                convert_price: adjustedBaseConvertPrice,
                item_variations: this.temp.item_variations,
                item_extras: this.temp.item_extras,
                item_variation_total: this.temp.item_variation_total,
                item_extra_total: this.temp.item_extra_total,
                instruction: this.temp.instruction,
                pos_line_addons: pos_line_addons,
                cart_display: wizardCartDisplay,
            };
        },
        addToCart: function () {
            var mainPayload = this.buildPosCartMainPayload();
            var editIdx = this.editingCartIndex;
            var dispatchPromise =
                editIdx !== null && editIdx >= 0
                    ? this.$store.dispatch('posCart/replaceCartLine', { index: editIdx, item: mainPayload })
                    : this.$store.dispatch('posCart/lists', [mainPayload]);

            dispatchPromise
                .then(() => {
                    this.editingCartIndex = null;
                    this.usePricedCartBase = false;
                    this.item = null;
                    this.temp.name = "";
                    this.temp.image = "";
                    this.temp.item_id = 0;
                    this.temp.quantity = 0;
                    this.temp.discount = 0;
                    this.temp.currency_price = 0;
                    this.temp.convert_price = 0;
                    this.temp.item_variations = {
                        variations: {},
                        names: {}
                    };
                    this.temp.item_extras = {
                        extras: [],
                        names: []
                    };
                    this.temp.item_variation_total = 0;
                    this.temp.item_extra_total = 0;
                    this.temp.total_price = 0;
                    this.temp.instruction = "";
                    this.addons = {};
                    this.addonQuantity = {};
                    if (this.$refs.itemVariationModal?.dataset?.wizardTotal) {
                        delete this.$refs.itemVariationModal.dataset.wizardTotal;
                    }
                    this.$refs.itemVariationModal?.removeAttribute?.('data-wizard-pos-line-addons');
                    this.itemArrays = [];

                    alertService.success(this.$t('message.add_to_cart'));
                    appService.modalHide('#item-variation-modal');
                })
                .catch(() => {
                    if (this.$refs.itemVariationModal?.dataset?.wizardTotal) {
                        delete this.$refs.itemVariationModal.dataset.wizardTotal;
                    }
                    this.$refs.itemVariationModal?.removeAttribute?.('data-wizard-pos-line-addons');
                    this.itemArrays = [];
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
                this.addToCart();
            });
        }
    },

}
</script>

<style scoped>
.pos-add-to-cart-sticky {
    position: sticky;
    bottom: 0;
    z-index: 5;
    background: #fff;
    padding-top: 8px;
    padding-bottom: 2px;
}
</style>