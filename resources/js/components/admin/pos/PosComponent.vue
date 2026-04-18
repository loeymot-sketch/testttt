<template>
    <ConnectionStatusBanner />
    <LoadingComponent :props="loading" />

    <div class="md:w-[calc(100%-340px)] lg:w-[calc(100%-320px)] xl:w-[calc(100%-377px)]">
        <form @submit.prevent="search"
            class="flex items-center w-full h-[38px] leading-[38px] mb-4 rounded-lg bg-white border-[#EFF0F6] border-t border-l border-b">
            <input type="text" v-model="props.search.name" :placeholder="$t('label.search_by_menu_item')"
                class="w-full px-5 rounded-tl-lg rounded-bl-lg placeholder:text-xs placeholder:font-rubik placeholder:text-[#A0A3BD]">
            <button @click="resetName" type="button" v-if="props.search.name"
                class="text-sm text-red-500 fa-regular fa-circle-xmark mr-4"></button>
            <button type="submit"
                class="flex-shrink-0 w-[38px] h-full text-center ltr:rounded-tr-lg ltr:rounded-br-lg rtl:rounded-tl-lg rtl:rounded-bl-lg bg-primary">
                <i class="lab lab-search-normal text-white"></i>
            </button>
        </form>

        <!-- LANDING: grille catégories + best sellers -->
        <template v-if="isLanding">
            <!-- Grille catégories (grandes cartes) -->
            <!-- [Y6 FIX] Filter out the "All" pseudo-category (id=0 or id='') instead of slice(1)
                 so real categories are never hidden if API order changes. -->
            <div v-if="categories.filter(c => c.id && c.id !== 0).length > 0" class="grid grid-cols-3 sm:grid-cols-4 gap-3 mb-6">
                <router-link v-for="(category, index) in categories.filter(c => c.id && c.id !== 0)" :key="category.id"
                    to="#" @click.prevent="setCategory(category.id)"
                    class="flex flex-col items-center text-center gap-2 py-4 px-2 rounded-xl border border-[#EFF0F6] bg-white hover:bg-[#FFEDF4] hover:border-primary transition">
                    <img class="h-10 w-10 object-contain drop-shadow-category" :src="category.thumb" alt="category">
                    <h3 class="text-xs font-medium font-rubik leading-tight">{{ category.name }}</h3>
                </router-link>
            </div>

            <!-- Best Sellers -->
            <div v-if="bestSellerItems.length > 0" class="mb-4">
                <h3 class="text-sm font-semibold font-rubik text-heading mb-3">⭐ Best Sellers</h3>
                <ItemComponent ref="posItemComponent" :items="bestSellerItems" />
            </div>
            <!-- Pas de best sellers trouvés: monter ItemComponent vide pour permettre l'édition depuis le panier -->
            <ItemComponent v-else ref="posItemComponent" :items="[]" />
        </template>

        <!-- FILTRÉ: swiper catégories + liste complète -->
        <template v-else>
            <div class="swiper pos-menu-swiper mb-4" v-if="categories.length > 1">
                <Swiper dir="ltr" :speed="1000" slidesPerView="auto" :spaceBetween="16" class="menu-slides">
                    <!-- [W9 FIX] Stable key using category.id instead of object reference -->
                    <SwiperSlide class="!w-fit" v-for="(category, index) in categories" :key="category.id || index"
                        :class="category.id === props.search.item_category_id || (category.id === 0 && props.search.item_category_id === '') ? 'pos-group' : ''">
                        <router-link v-if="index === 0" to="#" @click.prevent="allCategory"
                            class="w-28 flex flex-col items-center text-center gap-4 py-4 px-3 rounded-lg border-b-2 border-transparent transition hover:bg-[#FFEDF4] hover:border-primary bg-white">
                            <img class="h-7 drop-shadow-category" :src="category.thumb" alt="category">
                            <h3 class="text-xs leading-[16px] font-medium font-rubik">{{ category.name }}</h3>
                        </router-link>
                        <router-link v-else to="#" @click.prevent="setCategory(category.id)"
                            class="w-28 flex flex-col items-center text-center gap-4 py-4 px-3 rounded-lg border-b-2 border-transparent transition hover:bg-[#FFEDF4] hover:border-primary bg-white">
                            <img class="h-7 drop-shadow-category" :src="category.thumb" alt="category">
                            <h3 class="text-xs leading-[16px] font-medium font-rubik">{{ category.name }}</h3>
                        </router-link>
                    </SwiperSlide>
                </Swiper>
            </div>

            <ItemComponent ref="posItemComponent" :items="items" />

            <div class="my-12" v-if="items.length === 0 && !props.search.name">
                <div class="max-w-[350px] mx-auto">
                    <img class="w-full mb-8" :src="setting.image_order_not_found" alt="image_order_not_found">
                </div>
                <span class="w-full mb-4 text-center text-black">{{ $t('message.no_data_available') }}</span>
            </div>
            <div class="my-12" v-else-if="items.length === 0 && props.search.name">
                <div class="max-w-[250px] mx-auto">
                    <img class="w-full mb-8" :src="setting.item_not_found" alt="item_not_found">
                </div>
                <span class="w-full mb-4 text-center text-black">{{ $t('message.no_items_found') }}</span>
            </div>
        </template>
    </div>


    <div id="pos-cart"
        class="db-pos-cartDiv fixed top-0 ltr:right-0 rtl:left-0 w-full h-screen rounded-none z-50 md:z-10 md:top-[85px] ltr:md:right-5 rtl:md:left-5 md:w-[322px] lg:w-[305px] xl:w-[360px] md:h-[calc(100dvh-85px)] md:rounded-lg flex flex-col overflow-hidden bg-white">
        <div class="p-4 flex-shrink-0">
            <div class="md:hidden text-right mb-3">
                <button class="db-pos-cartCls" @click="closeCanvas('pos-cart')">
                    <i class="lab-close-circle-line font-fill-danger lab-font-size-24"></i>
                </button>
            </div>
            <div class="flex items-center w-full gap-4 mb-3">
                <div class="db-field flex-grow">
                    <vue-select
                        class="db-field-control text-sm rounded-lg appearance-none text-heading border-[#D9DBE9]"
                        id="customer" v-model="checkoutProps.form.customer_id" :options="customers"
                        @update:modelValue="changingUser" label-by="name" value-by="id" :closeOnSelect="true"
                        :searchable="true" :clearOnClose="true" :placeholder="$t('label.select_customer')"
                        :search-placeholder="$t('label.search_customer')" />
                </div>
                <div data-modal="#addCustomer" @click.prevent="addCustomers"
                    class="w-10 h-10 bg-primary rounded-lg flex items-center justify-center cursor-pointer">
                    <i class="fa-solid fa-circle-plus text-white"></i>
                </div>
            </div>

            <!-- Loyalty badge — shown when selected customer has a loyalty account -->
            <div v-if="selectedCustomerLoyalty.code" class="flex items-center gap-2 mb-3 px-3 py-2 rounded-lg bg-amber-50 border border-amber-200">
                <i class="fa-solid fa-star text-amber-500 text-sm"></i>
                <span class="text-xs font-medium text-amber-700">
                    <span v-if="selectedCustomerLoyalty.loading">...</span>
                    <template v-else>
                        <span class="font-bold">{{ selectedCustomerLoyalty.points ?? 0 }}</span> pts fidélité
                        <span class="text-amber-500 ml-1">({{ selectedCustomerLoyalty.code }})</span>
                    </template>
                </span>
            </div>

            <div class="p-3 pt-2 rounded-lg border border-[#D9DBE9]">
                <h4 class="text-sm font-medium mb-3">{{ $t('label.select_order_type') }}</h4>

                <div class="db-field-radio-group gap-1 active-group">

                    <!-- [POS-9.1.6] Dine-In gated by feature flag `pos.dine_in_enabled` (default false).
                         Enable via /api/admin/settings.pos.dine_in_enabled = 1 once floor-plan + table
                         selector UX is validated. Logic kept live so flipping the flag is zero-code. -->
                    <label v-if="dineInEnabled" @click="dineInOrder" ref="dineIn" for="dinein" data-dine="#dine"
                        class="!w-fit db-field-radio px-2.5 py-2 rounded-lg border border-[#F7F7FC] bg-[#F7F7FC]">
                        <div class="custom-radio sm">
                            <input ref="dineInInput" type="radio" id="dinein" name="orderType"
                                :value="orderTypeEnums.dineIn" v-model="checkoutProps.form.order_type"
                                class="custom-radio-field" />
                            <span class="custom-radio-span"></span>
                        </div>
                        <h3 class="db-field-label text-sm text-heading">
                            {{ $t('label.dine_in') }}
                        </h3>
                    </label>
                    <label ref="takeAway" @click="takeAwayOrder" for="takeway"
                        class="!w-fit db-field-radio px-2.5 py-2 rounded-lg border border-[#F7F7FC] bg-[#F7F7FC] active">
                        <div class="custom-radio sm">
                            <input ref="takeAwayInput" type="radio" id="takeway" name="orderType"
                                :value="orderTypeEnums.takeAway" v-model="checkoutProps.form.order_type"
                                class="custom-radio-field" />
                            <span class="custom-radio-span"></span>
                        </div>
                        <h3 class="db-field-label text-sm text-heading">
                            {{ $t('label.takeaway') }}
                        </h3>
                    </label>


                    <label ref="deliveryOrderLabel" @click="deliveryOrder" for="delivery"
                        data-orderdelivery="#orderdelivery" type="button"
                        class="!w-fit db-field-radio px-2.5 py-2 rounded-lg border border-[#F7F7FC] bg-[#F7F7FC]">
                        <div class="custom-radio sm">
                            <input ref="deliveryOrderInput" type="radio" id="delivery" name="orderType"
                                :value="orderTypeEnums.delivery" v-model="checkoutProps.form.order_type"
                                class="custom-radio-field" />
                            <span class="custom-radio-span"></span>
                        </div>
                        <h3 class="db-field-label text-sm text-heading">
                            {{ $t('label.delivery') }}
                        </h3>
                    </label>
                </div>
                <!-- [P4] Inline delivery form — no separate modal, no map tab -->
                <div ref="deliveryOrderDiv" id="orderdelivery" class="h-auto hidden transition">
                    <div class="mt-3 flex flex-col gap-2">
                        <!-- Row 1: Nom + Téléphone -->
                        <div class="flex gap-2">
                            <div class="flex-1">
                                <input
                                    type="text"
                                    v-model="deliveryInline.name"
                                    placeholder="Nom du client"
                                    class="w-full h-10 text-sm rounded-lg border px-3 text-heading border-[#D9DBE9] focus:border-primary focus:outline-none"
                                />
                            </div>
                            <div class="flex-1">
                                <input
                                    type="tel"
                                    v-model="deliveryInline.phone"
                                    placeholder="Téléphone"
                                    class="w-full h-10 text-sm rounded-lg border px-3 text-heading border-[#D9DBE9] focus:border-primary focus:outline-none"
                                />
                            </div>
                        </div>
                        <!-- Row 2: Adresse autocomplete -->
                        <div class="relative">
                            <div class="flex items-center gap-2">
                                <div class="relative flex-1">
                                    <i class="fa-solid fa-location-dot absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                    <input
                                        ref="deliveryAddressInput"
                                        type="text"
                                        v-model="deliveryInline.addressText"
                                        @input="onDeliveryAddressInput"
                                        @keydown.down.prevent="deliveryNavDown"
                                        @keydown.up.prevent="deliveryNavUp"
                                        @keydown.enter.prevent="deliveryNavSelect"
                                        @keydown.esc="deliveryInline.suggestions = []"
                                        placeholder="Adresse de livraison..."
                                        autocomplete="off"
                                        class="w-full h-10 text-sm rounded-lg border pl-8 pr-3 text-heading border-[#D9DBE9] focus:border-primary focus:outline-none"
                                        :class="deliveryInline.confirmed ? 'border-green-400 bg-green-50' : ''"
                                    />
                                    <i v-if="deliveryInline.confirmed" class="fa-solid fa-circle-check absolute right-3 top-1/2 -translate-y-1/2 text-green-500 text-sm"></i>
                                    <i v-else-if="deliveryInline.loading" class="fa-solid fa-spinner fa-spin absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                </div>
                                <button
                                    v-if="deliveryInline.confirmed"
                                    @click.prevent="resetDeliveryInline"
                                    type="button"
                                    class="h-10 w-10 flex items-center justify-center rounded-lg border border-red-200 text-red-400 hover:bg-red-50 transition flex-shrink-0"
                                    title="Effacer"
                                >
                                    <i class="fa-solid fa-xmark text-sm"></i>
                                </button>
                            </div>
                            <!-- Suggestions dropdown -->
                            <ul
                                v-if="deliveryInline.suggestions.length > 0"
                                class="absolute z-50 w-full mt-1 bg-white border border-[#D9DBE9] rounded-lg shadow-lg max-h-48 overflow-y-auto"
                            >
                                <li
                                    v-for="(s, idx) in deliveryInline.suggestions"
                                    :key="s.place_id"
                                    @mousedown.prevent="selectDeliverySuggestion(s)"
                                    class="flex items-start gap-2 px-3 py-2 cursor-pointer hover:bg-[#FFEDF4] text-sm text-heading transition"
                                    :class="idx === deliveryInline.activeIdx ? 'bg-[#FFEDF4]' : ''"
                                >
                                    <i class="fa-solid fa-location-dot text-primary mt-0.5 flex-shrink-0 text-xs"></i>
                                    <span class="leading-tight">{{ s.description }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <!-- [POS-9.1.6] Table selector gated by the same `pos.dine_in_enabled` flag -->
                <div v-if="dineInEnabled" ref="dineInDiv" id="dine" class="h-auto hidden transition">
                    <div class="mt-3">
                        <div class="db-field flex-grow">
                            <vue-select
                                class="db-field-control text-sm rounded-lg appearance-none text-heading border-[#D9DBE9]"
                                id="diningtables" :options="diningtables" v-model="checkoutProps.form.dining_table_id"
                                value-by="id" label-by="name" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" :placeholder="$t('label.select_table')"
                                :search-placeholder="$t('label.search_table')" />
                        </div>
                    </div>
                </div>

            </div>


        </div>
        <div class="flex-1 min-h-0 overflow-y-auto thin-scrolling border-t border-[#EFF0F6]">

        <table class="w-full">
            <thead class="bg-[#FFEDF4]">
                <tr class="h-9">
                    <th class="capitalize text-xs font-normal font-rubik text-left px-3 text-heading pl-3">
                        {{ $t('label.item') }}
                    </th>
                    <th class="capitalize text-xs font-normal font-rubik text-left px-3 text-heading">
                        {{ $t('label.qty') }}
                    </th>
                    <th class="capitalize text-xs font-normal font-rubik text-left px-3 text-heading">
                        {{ $t('label.price') }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(cart, index) in carts">
                    <td class="pl-3 py-3 last:pr-3 align-top border-b border-[#EFF0F6]">
                        <div class="flex gap-2 items-start">
                            <img v-if="cart.image" :src="cart.image" class="w-10 h-10 rounded-md object-cover flex-shrink-0" />
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="capitalize text-xs font-rubik text-[#2E2F38]">{{ cart.name }}</h3>
                                    <button type="button" @click.prevent="editCartLine(index)"
                                        class="shrink-0 text-primary hover:opacity-80"
                                        :title="$t('button.edit') || 'Modifier'">
                                        <i class="fa-regular fa-pen-to-square text-xs"></i>
                                    </button>
                                </div>
                                <!-- Wizard cart_display: clean summary (Viandes, Crudités, Sauce, Suppléments) — no instruction clutter -->
                                <template v-if="cart.cart_display && cart.cart_display.trim()">
                                    <p class="text-[11px] font-rubik text-[#5A5A78] leading-snug whitespace-pre-line mt-0.5">{{ cart.cart_display }}</p>
                                </template>
                                <!-- Fallback for non-wizard products: show raw variations/extras -->
                                <template v-else>
                                <p v-if="Object.keys(cart.item_variations.variations).length !== 0">
                            <span v-for="(variation, variationName, index) in cart.item_variations.names">
                                <span class="capitalize text-xs leading-4 font-rubik text-heading">{{
                                    variationName
                                    }}:
                                    &nbsp;</span>
                                <span class="capitalize text-xs leading-4 font-rubik">{{ variation }}
                                    <span v-if="index + 1 < Object.keys(cart.item_variations.names).length">,
                                        &nbsp;</span>
                                </span>
                            </span>
                        </p>
                        <ul v-if="cart.item_extras.extras.length > 0">
                            <li class="leading-4">
                                <span class="capitalize text-xs leading-4 font-rubik text-heading">
                                    {{ $t('label.extras') }}:
                                </span>
                                <p class="capitalize text-xs leading-4 font-rubik">
                                    <span v-for="(extra, index) in cart.item_extras.names">
                                        {{ extra }}
                                        <span v-if="index + 1 < cart.item_extras.extras.length">, &nbsp;</span>
                                    </span>
                                </p>
                            </li>
                        </ul>
                                </template>

                        <!-- Menu bundled + extras menu directement sous chaque ligne -->
                        <div v-if="cart.pos_line_addons && cart.pos_line_addons.length > 0" class="mt-1.5 space-y-0.5">
                            <div v-for="(bundled, bi) in cart.pos_line_addons" :key="'b-' + index + '-' + bi">
                                <div class="text-[11px] font-semibold font-rubik text-[#1AB759] leading-snug flex items-center gap-1 flex-wrap">
                                    <span>+ {{ bundled.name }}</span>
                                    <span v-if="bundledLineUnitTotal(bundled) > 0" class="font-rubik text-[#1AB759]">
                                        (+{{
                                            currencyFormat(bundledLineUnitTotal(bundled) * (parseInt(bundled.quantity, 10) || 1) * cart.quantity,
                                                setting.site_digit_after_decimal_point,
                                                setting.site_default_currency_symbol, setting.site_currency_position)
                                        }})
                                    </span>
                                </div>
                                <!-- Extras menu directement sous cette ligne (sauce frites, grande portion, cheddar) -->
                                <ul v-if="bundled.menu_extras && bundled.menu_extras.length > 0" class="ml-3 mt-0.5 space-y-0.5">
                                    <li v-for="(extra, ei) in bundled.menu_extras" :key="'me-' + index + '-' + bi + '-' + ei"
                                        class="text-[10px] font-rubik text-[#8E8EA9] leading-snug flex items-center gap-1">
                                        <span class="text-[#1AB759] font-bold">↳</span>
                                        <span>{{ extra }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                            </div>
                        </div>
                    </td>
                    <td class="pl-3 py-3 last:pr-3 align-top border-b border-[#EFF0F6]">
                        <div class="flex items-center indec-group">
                            <button @click.prevent="cartQuantityDecrement(index)"
                                :class="cart.quantity === 1 ? 'fa-trash-can' : 'fa-minus'"
                                class="fa-solid text-[10px] w-[18px] h-[18px] leading-4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-minus"></button>
                            <input v-on:keypress="onlyNumber($event)" v-on:keyup="cartQuantityUp(index, $event)"
                                type="number" :value="cart.quantity"
                                class="text-center w-7 text-xs font-semibold text-heading indec-value">
                            <button @click.prevent="cartQuantityIncrement(index)"
                                class="fa-solid fa-plus text-[10px] w-[18px] h-[18px] leading4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-plus"></button>
                        </div>
                    </td>
                    <td class="pl-3 py-3 last:pr-3 align-top border-b border-[#EFF0F6] text-xs font-rubik text-heading">
                        {{
                            currencyFormat(cart.total, setting.site_digit_after_decimal_point,
                                setting.site_default_currency_symbol, setting.site_currency_position)
                        }}
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
        <div class="p-4 flex-shrink-0 bg-white border-t border-[#EFF0F6] shadow-[0_-4px_12px_rgba(0,0,0,0.06)]">
            <div class="flex h-[38px]" v-if="carts.length > 0">
                <div class="dropdown-group">
                    <button
                        class="flex items-center justify-start w-[120px] h-full text-sm font-rubik rounded-tl rounded-bl appearance-none border pl-3 text-heading border-[#EFF0F6] dropdown-btn">
                        <span class="flex-1 text-start" v-if="discountType === discountTypeEnum.PERCENTAGE">{{
                            $t("label.percentage") }}</span>
                        <span class="flex-1 text-start" v-else>{{ $t("label.fixed") }}</span>
                        <i class="lab lab-arrow-down-2 lab-font-size-17 mx-1"></i>
                    </button>
                    <ul
                        class="p-2 rounded-lg shadow-xl absolute top-10 ltr:right-0 rtl:left-0 z-10 bg-white transition-all duration-300 origin-top scale-y-0 dropdown-list w-full">
                        <li class="flex items-center gap-2 py-1 px-2.5 rounded-md cursor-pointer hover:bg-gray-100"
                            v-for="option in [
                                { name: $t('label.percentage'), value: discountTypeEnum.PERCENTAGE },
                                { name: $t('label.fixed'), value: discountTypeEnum.FIXED }
                            ]" :key="option" @click="selectDiscount(option.value)">
                            <span class="text-heading capitalize text-sm">{{ option.name }}</span>

                        </li>
                    </ul>
                </div>
                <input v-on:keypress="floatNumber($event)" v-model="discount" type="text"
                    :placeholder="$t('label.add_discount')"
                    class="w-full h-full border-t border-b px-3 border-[#EFF0F6]">
                <button @click.prevent="applyDiscount" type="submit"
                    class="flex-shrink-0 w-16 h-full text-sm font-medium font-rubik capitalize ltr:rounded-tr-lg ltr:rounded-br-lg rtl:rounded-tl-lg rtl:rounded-bl-lg text-white bg-[#008BBA]">
                    {{ $t('button.apply') }}
                </button>
            </div>

            <ul class="flex flex-col gap-1.5 mb-4 mt-4">
                <li class="flex items-center justify-between">
                    <span class="text-sm font-rubik capitalize leading-6 text-[#2E2F38]">
                        {{ $t("label.sub_total") }}
                    </span>
                    <span class="text-sm font-rubik capitalize leading-6 text-[#2E2F38]">
                        {{
                            currencyFormat(subtotal, setting.site_digit_after_decimal_point,
                                setting.site_default_currency_symbol, setting.site_currency_position)
                        }}
                    </span>
                </li>
                <li class="flex items-center justify-between">
                    <span class="text-sm font-rubik capitalize leading-6">{{ $t("label.discount") }}</span>
                    <span class="text-sm font-rubik capitalize leading-6">{{
                        currencyFormat(posDiscount,
                            setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                            setting.site_currency_position)
                    }}</span>
                </li>
                <li class="flex items-center justify-between" v-if="checkoutProps.form.delivery_charge">
                    <span class="text-sm font-rubik capitalize leading-6">{{ $t("label.delivery_charge") }}</span>
                    <span class="text-sm font-rubik capitalize leading-6 font-medium text-[#1AB759]">{{
                        currencyFormat(checkoutProps.form.delivery_charge,
                            setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                            setting.site_currency_position)
                    }}</span>
                </li>
                <li class="flex items-center justify-between py-2 px-3 rounded-lg bg-[#F7F7FC] -mx-1 mt-1">
                    <span class="text-sm font-semibold font-rubik capitalize leading-6 text-[#2E2F38]">
                        {{ $t("label.total") }}
                        <!-- [AUDIT-P2] Tax is recalculated server-side from catalog tax_id.
                             Display total here is pre-tax (subtotal + delivery - discount).
                             Final order total may differ slightly if products carry a tax rate. -->
                        <span class="text-xs font-normal text-[#A0A3BD] ml-1">(HT)</span>
                    </span>
                    <span class="text-base font-bold font-rubik leading-6 text-primary">
                        {{
                            currencyFormat((subtotal + checkoutProps.form.delivery_charge) - posDiscount,
                                setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                                setting.site_currency_position)
                        }}
                    </span>
                </li>
            </ul>
            <div class="flex items-center justify-center gap-6" v-if="carts.length > 0">
                <button @click.prevent="resetCart"
                    class="capitalize text-sm font-medium leading-6 font-rubik w-full text-center rounded-3xl py-2 text-white bg-[#FB4E4E]">
                    {{ $t('button.cancel') }}
                </button>
                <button @click.prevent="orderSubmit"
                    class="capitalize text-sm font-medium leading-6 font-rubik w-full text-center rounded-3xl py-2 text-white bg-[#1AB759]">
                    {{ $t('button.order') }}
                </button>
            </div>
        </div>
    </div>


    <!--====================================
        ADD CUSTOMER MODAL PART START
=====================================-->
    <div id="addCustomer" class="modal">
        <div class="modal-dialog">
            <div class="modal-header pb-3 border-b border-[#D9DBE9]">
                <h3 class="capitalize font-medium">{{ $t('button.add_customer') }}</h3>
                <button @click="resetCustomer" class="modal-close fa-regular fa-circle-xmark"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="saveCustomer">
                    <div class="row mb-3">
                        <div class="col-12 sm:col-6">
                            <label class="db-field-title required">{{ $t("label.name") }}</label>
                            <input type="text" v-model="customerProps.form.name"
                                v-bind:class="errors.name ? 'invalid' : ''" id="name"
                                class="w-full h-12 text-sm rounded-lg border px-4 text-heading border-[#D9DBE9]" />
                            <small class="db-field-alert" v-if="errors.name">{{
                                errors.name[0]
                                }}</small>
                        </div>
                        <div class="col-12 sm:col-6">
                            <label for="phone" class="db-field-title">{{ $t("label.phone") }}</label>
                            <div :class="errors.phone ? 'invalid' : ''"
                                class="w-full h-12 rounded-lg border px-4 flex items-center border-[#D9DBE9]">
                                <div class="w-fit flex-shrink-0 dropdown-group">
                                    <button type="button" class="flex items-center gap-1 dropdown-btn">
                                        {{ flag }}
                                        <span class="whitespace-nowrap flex-shrink-0 text-xs">
                                            {{
                                                customerProps.form.country_code
                                            }}
                                        </span>
                                        <input type="hidden" v-model="customerProps.form.country_code
                                            " />
                                    </button>
                                </div>
                                <input v-model="customerProps.form.phone" v-on:keypress="phoneNumber($event)"
                                    v-bind:class="errors.phone ? 'invalid' : ''" type="text" id="phone"
                                    class="pl-2 text-sm w-full h-full" />
                            </div>
                            <small class="db-field-alert" v-if="errors.phone">
                                {{ errors.phone[0] }}
                            </small>
                        </div>
                        <div class="col-12 sm:col-6">
                            <label class="db-field-title required">{{ $t("label.email") }}</label>
                            <input type="email" id="email" v-model="customerProps.form.email"
                                v-bind:class="errors.email ? 'invalid' : ''"
                                class="w-full h-12 text-sm rounded-lg border px-4 text-heading border-[#D9DBE9]" />
                            <small class="db-field-alert" v-if="errors.email">{{
                                errors.email[0]
                                }}</small>
                        </div>
                        <div class="col-12 sm:col-6">
                            <label for="password" class="db-field-title required">{{ $t("label.password") }}</label>
                            <!-- [W11 FIX] type="password" to prevent shoulder-surfing on shared POS terminals -->
                            <input v-model="customerProps.form.password" v-bind:class="errors.password ? 'invalid' : ''"
                                type="password" id="password"
                                class="w-full h-12 text-sm rounded-lg border px-4 text-heading border-[#D9DBE9]"
                                autocomplete="new-password" />
                            <small class="db-field-alert" v-if="errors.password">{{ errors.password[0] }}</small>
                        </div>
                        <input type="hidden" v-model="customerProps.form.password_confirmation" />
                    </div>
                    <button type="submit"
                        class="rounded-3xl text-base py-3 px-3 font-medium w-full text-white bg-primary">
                        {{ $t('button.add_customer') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
    <!--====================================
          ADD CUSTOMER MODAL PART END
    =====================================-->

    <!--====================================
      PAYMENT MODAL PART START
  =====================================-->
    <PaymentComponent :props="checkoutProps" />
    <!--====================================
          PAYMENT MODAL PART END
      =====================================-->


    <!--====================================
      ADDRESS MODAL PART START
  =====================================-->
    <CreateCustomerAddressComponent :props="address" />
    <!--====================================
          ADDRESS MODAL PART END
      =====================================-->


    <button @click="openCanvas('pos-cart')" type="button"
        class="db-pos-cartBtn fixed md:hidden bottom-0 z-10 left-0 w-full h-14 py-4 text-center flex items-center justify-center shadow-xl-top gap-3 bg-primary">
        <i class="lab lab-bag-2 lab-font-size-13 text-white"></i>
        <span class="text-base font-medium font-rubik text-white">
            {{ totalItems() }} {{ $t('label.items') }} - {{
                // [BUG-A3 FIX] Include delivery_charge in mobile total (match cart panel)
                currencyFormat((subtotal + checkoutProps.form.delivery_charge) - posDiscount,
                    setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                    setting.site_currency_position)
            }}
        </span>
    </button>

    <!-- ═══ Borne Cash — notification flottante ═══ -->
    <!-- Badge pulsant si des commandes kiosk cash sont en attente de paiement -->
    <transition name="slide-up-pos">
      <button
        v-if="kioskCashOrders.length > 0"
        class="kiosk-cash-fab"
        @click="showKioskCashPanel = true"
        title="Commandes borne à encaisser"
      >
        <span class="kiosk-cash-fab-icon">🖥️</span>
        <span class="kiosk-cash-fab-badge">{{ kioskCashOrders.length }}</span>
      </button>
    </transition>

    <!-- Panel commandes borne cash -->
    <transition name="slide-panel">
      <div v-if="showKioskCashPanel" class="kiosk-cash-panel-overlay" @click.self="showKioskCashPanel = false">
        <div class="kiosk-cash-panel">
          <div class="kiosk-cash-panel-header">
            <h3>🖥️ Commandes borne — à encaisser</h3>
            <button class="kiosk-cash-panel-close" @click="showKioskCashPanel = false">✕</button>
          </div>
          <div class="kiosk-cash-panel-body">
            <div v-if="kioskCashLoading" class="kiosk-cash-loading">
              <div class="kiosk-cash-spinner"></div>
            </div>
            <div v-else-if="kioskCashOrders.length === 0" class="kiosk-cash-empty">
              Aucune commande borne en attente.
            </div>
            <div
              v-for="order in kioskCashOrders"
              :key="order.id"
              class="kiosk-cash-order-card"
            >
              <div class="kiosk-cash-order-head">
                <span class="kiosk-cash-order-num">N° {{ order.queue_number || order.order_serial_no }}</span>
                <div class="kiosk-cash-order-head-actions">
                  <button
                    type="button"
                    class="kiosk-cash-expand-btn"
                    :aria-expanded="isKioskCashOrderExpanded(order.id) ? 'true' : 'false'"
                    :data-testid="`kiosk-cash-expand-${order.id}`"
                    @click="toggleKioskCashOrderDetails(order.id)"
                  >
                    <i class="fa-solid fa-chevron-down" :class="{ 'kiosk-cash-expand-btn-rotated': isKioskCashOrderExpanded(order.id) }"></i>
                  </button>
                  <span class="kiosk-cash-order-total">{{ formatKioskPrice(order.order_amount) }}</span>
                </div>
              </div>
              <div class="kiosk-cash-order-items">
                <span v-for="(item, i) in (order.order_items || []).slice(0,3)" :key="i" class="kiosk-cash-item-pill">
                  {{ item.quantity }}× {{ item.item_name || item.name }}
                </span>
                <span v-if="(order.order_items || []).length > 3" class="kiosk-cash-item-pill more">
                  +{{ order.order_items.length - 3 }} autres
                </span>
              </div>
              <div
                v-if="isKioskCashOrderExpanded(order.id)"
                class="kiosk-cash-order-details"
                :data-testid="`kiosk-cash-details-${order.id}`"
              >
                <div
                  v-for="(item, itemIdx) in (order.order_items || [])"
                  :key="item.id || `${order.id}-${itemIdx}`"
                  class="kiosk-cash-order-detail-item"
                >
                  <div class="kiosk-cash-order-detail-title">{{ item.quantity }}× {{ item.item_name || item.name }}</div>
                  <div v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0" class="kiosk-cash-order-detail-line">
                    <strong>Variations:</strong>
                    <span>{{ item.item_variations.map(variation => `${variation.variation_name || 'Option'}: ${variation.name}`).join(', ') }}</span>
                  </div>
                  <div v-if="Array.isArray(item.item_extras) && item.item_extras.length > 0" class="kiosk-cash-order-detail-line">
                    <strong>Extras:</strong>
                    <span>{{ item.item_extras.map(extra => extra.name).join(', ') }}</span>
                  </div>
                  <div v-if="item.instruction" class="kiosk-cash-order-detail-line">
                    <strong>Instructions:</strong>
                    <span>{{ item.instruction }}</span>
                  </div>
                  <div v-if="Array.isArray(item.allergens_snapshot) && item.allergens_snapshot.length > 0" class="kiosk-cash-order-detail-line">
                    <strong>Allergenes:</strong>
                    <span>{{ item.allergens_snapshot.join(', ') }}</span>
                  </div>
                </div>
              </div>
              <div class="kiosk-cash-order-foot">
                <span class="kiosk-cash-order-time">{{ formatKioskTime(order.created_at) }}</span>
                <!-- [GAP-25-2] Bouton "Encaisser" — marque la commande comme DELIVERED (13) -->
                <button
                  class="kiosk-cash-collect-btn"
                  :disabled="order._collecting"
                  @click="collectKioskCashOrder(order)"
                >
                  {{ order._collecting ? '…' : '✓ Encaisser' }}
                </button>
              </div>
            </div>
          </div>
          <div class="kiosk-cash-panel-footer">
            <button class="kiosk-cash-refresh-btn" @click="loadKioskCashOrders">↻ Actualiser</button>
          </div>
        </div>
      </div>
    </transition>
</template>
<script>
import axios from 'axios';
import LoadingComponent from "../components/LoadingComponent.vue";
import 'vue3-carousel/dist/carousel.css';
import ItemComponent from "./ItemComponent.vue";
import sourceEnum from "../../../enums/modules/sourceEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import isAdvanceOrderEnum from "../../../enums/modules/isAdvanceOrderEnum";
import statusEnum from "../../../enums/modules/statusEnum";
import roleEnum from "../../../enums/modules/roleEnum";
import appService from "../../../services/appService";
import discountTypeEnum from "../../../enums/modules/discountTypeEnum";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import alertService from "../../../services/alertService";
import PaymentComponent from "./PaymentComponent.vue";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import { Swiper, SwiperSlide } from 'swiper/vue';
import 'swiper/css';
import focustrap from "bootstrap/js/src/util/focustrap";
import CustomerAddressCreateComponent from "../customers/address/CustomerAddressCreateComponent.vue";
import CreateCustomerAddressComponent from "./CreateCustomerAddressComponent.vue";
import labelEnum from "../../../enums/modules/labelEnum";
import {
    rowUnitBundled,
    mainOrderLineTotal,
    bundledOrderQuantityAndTotal,
    parsePositiveInt,
} from "../../../helpers/posCartLineMath";
import ConnectionStatusBanner from "../../common/ConnectionStatusBanner.vue";
import { onEvents } from "../../../services/eventContract";

export default {
    name: "PosComponent",
    components: {
        CreateCustomerAddressComponent,
        CustomerAddressCreateComponent,
        ConnectionStatusBanner,
        LoadingComponent,
        ItemComponent,
        Swiper,
        SwiperSlide,
        PaymentComponent
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            company: {},
            order: {},
            discount: null,
            // [POS-9.1.1] mandatory motif for any POS discount
            discountReason: '',
            // Kiosk cash orders notification
            kioskCashOrders: [],
            kioskCashLoading: false,
            showKioskCashPanel: false,
            expandedKioskCashOrders: {},
            _kioskPollTimer: null,
            _eventSub: null,
            checkoutProps: {
                form: {
                    branch_id: null,
                    subtotal: 0,
                    token: "",
                    customer_id: null,
                    discount: 0,
                    delivery_charge: 0,
                    delivery_time: null,
                    total: 0,
                    order_type: orderTypeEnum.TAKEAWAY,
                    is_advance_order: isAdvanceOrderEnum.NO,
                    pos_payment_method: posPaymentMethodEnum.CASH,
                    pos_payment_note: '',
                    source: sourceEnum.POS,
                    address_id: null,
                    coupon_id: null,
                    items: [],
                    dining_table_id: null,
                    pos_received_amount: null,
                    loyalty_customer_code: null,
                    // [POS-9.1.1] motif mandatory when discount > 0
                    discount_reason: null,
                }
            },
            selectedCustomerLoyalty: {
                points: null,
                code: null,
                loading: false,
            },
            props: {
                search: {
                    paginate: 0,
                    order_column: "id",
                    order_type: "asc",
                    name: "",
                    item_category_id: "",
                    status: statusEnum.ACTIVE
                },
            },
            categoryProps: {
                paginate: 0,
                order_column: 'sort',
                order_type: 'asc',
                status: statusEnum.ACTIVE
            },

            statusEnum: statusEnum,
            discountTypeEnum: discountTypeEnum,
            discountType: discountTypeEnum.PERCENTAGE,
            posPaymentMethodEnum: posPaymentMethodEnum,
            customerProps: {
                form: {
                    name: "",
                    email: "",
                    phone: "",
                    password: "123456",
                    password_confirmation: "123456",
                    country_code: "",
                    status: statusEnum.ACTIVE,
                },
                search: {
                    paginate: 0,
                    order_column: "id",
                    order_type: "asc",
                    status: statusEnum.ACTIVE
                },
            },
            errors: {},

            flag: "",
            address: {
                form: {
                    address: "",
                    apartment: "",
                    latitude: "",
                    longitude: "",
                    label: "",
                    user_id: "",
                },
                search: {
                    order_column: "id",
                    order_type: "desc",
                },
                status: false,
                switchLabel: "",
                isMap: false,
                vuex: false
            },
            selectedAddress: {},
            orderTypeEnums: {
                dineIn: orderTypeEnum.DINING_TABLE,
                takeAway: orderTypeEnum.TAKEAWAY,
                delivery: orderTypeEnum.DELIVERY,
            },
            location: {
                lat: null,
                lng: null
            },
            clearAddresses: false,

            // [P4] Inline delivery form — no separate modal, no map
            deliveryInline: {
                name: '',
                phone: '',
                addressText: '',
                address: '',
                latitude: '',
                longitude: '',
                suggestions: [],
                confirmed: false,
                loading: false,
                activeIdx: -1,
            },

        }
    },
    computed: {
        focustrap() {
            return focustrap
        },
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        /**
         * [POS-9.1.6] POS dine-in feature flag.
         * Reads `pos_dine_in_enabled` from the frontend settings store;
         * defaults to FALSE so a regressed/empty backend stays safe.
         */
        dineInEnabled: function () {
            const s = this.setting || {};
            const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
            return String(raw) === '1' || raw === true;
        },
        categories: function () {
            return this.$store.getters["posCategory/lists"];
        },
        items: function () {
            return this.$store.getters["item/lists"];
        },
        isLanding: function () {
            return this.props.search.item_category_id === '' && !this.props.search.name;
        },
        bestSellerItems: function () {
            // [V8 FIX] Use is_featured flag from API as the primary source for best sellers.
            // Falls back to hard-coded name list only when no featured items are configured,
            // so the admin can manage best sellers from the back-office without code changes.
            const allItems = this.$store.getters["item/lists"];
            const featured = allItems.filter(function (item) { return item.is_featured == 1 || item.is_featured === true; });
            if (featured.length > 0) return featured;
            const names = ['cayenne', 'terminator', 'double cheese', 'tacos l', 'tacos m'];
            return allItems.filter(function (item) {
                const n = (item.name || '').toLowerCase();
                return names.some(function (bs) { return n.includes(bs); });
            });
        },
        customers: function () {
            return this.$store.getters['user/lists'];
        },
        carts: function () {
            return this.$store.getters['posCart/lists'];
        },
        subtotal: function () {
            return this.$store.getters['posCart/subtotal'];
        },
        posDiscount: function () {
            return this.$store.getters['posCart/discount'];
        },
        direction: function () {
            return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
        },
        diningtables: function () {
            return this.$store.getters["diningTable/lists"];
        },
        filteredCustomerAddresses: function () {
            if (this.clearAddresses) {
                return [];
            }
            return this.customerAddresses;
        },
        customerAddresses: function () {
            return this.$store.getters["user/addressLists"];
        },
    },
    beforeUnmount() {
        if (this._kioskPollTimer) clearInterval(this._kioskPollTimer);
        this._unsubscribeEcho();
        this._unbindWsService();
    },
    mounted() {
        this.closeSidebar();
        this.$refs.takeAway.click();
        this.itemCategories();
        this.itemList();
        this.loadKioskCashOrders();
        this._subscribeEcho();
        this._startKioskPolling();
        this._bindWsService();
        try {
            this.loading.isActive = true;
            this.$store.dispatch("defaultAccess/show").then((res) => {
                this.checkoutProps.form.branch_id = res.data.data.branch_id
                // [POS-9.1.9] Bind the POS cart to the active cashier (branch + user).
                // Without this, all carts share `pos_cart_v2` and a cashier B
                // logging in after cashier A inherits A's lines (POS-GA-F-41).
                try {
                    const authInfo = this.$store.getters['auth/authInfo'] || {};
                    this.$store.dispatch('posCart/setScope', {
                        branchId: res.data.data.branch_id,
                        userId: authInfo.id || null,
                    });
                } catch (e) { /* defensive: never block POS bootstrap */ }
                this.$store.dispatch("frontendBranch/show", this.checkoutProps.form.branch_id).then(res => {
                    this.location = {
                        lat: res.data.data.latitude,
                        lng: res.data.data.longitude
                    };
                }).catch();

            }).catch((err) => {
                this.loading.isActive = false;
            });

            this.loading.isActive = true;
            this.$store.dispatch('user/lists', {
                order_column: 'id',
                order_type: 'asc',
                status: statusEnum.ACTIVE,
                role_id: 2,
            }).then((res) => {
                if (res.data.data && res.data.data.length > 0) {
                    // [W4 FIX] Find walking customer by email first, then by name keyword.
                    // Do NOT fall back to res.data.data[0] — that would assign a real customer's
                    // account to an anonymous POS order, leaking order history.
                    var walkingCustomer = res.data.data.find(u => u.email === 'walkingcustomer@example.com')
                        || res.data.data.find(u => u.name && u.name.toLowerCase().includes('walking'));
                    if (walkingCustomer) {
                        this.checkoutProps.form.customer_id = walkingCustomer.id;
                        this.address.form.user_id = walkingCustomer.id;
                        this.gettingUserAddress(this.checkoutProps.form.customer_id);
                    }
                    // If no walking customer found, leave customer_id null — cashier must select manually
                }
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });

            this.loading.isActive = true;
            this.$store
                .dispatch("diningTable/lists", {
                    order_column: 'id',
                    order_type: 'desc',
                    status: statusEnum.ACTIVE,
                })
                .then((res) => {
                    this.loading.isActive = false;
                })
                .catch((err) => {
                    this.loading.isActive = false;
                });

            this.$store
                .dispatch("company/lists")
                .then((companyRes) => {
                    // [BUG-A1 FIX] Populate company data from response
                    this.company.name = companyRes.data.data.company_name;
                    this.company.email = companyRes.data.data.company_email;
                    this.company.phone = companyRes.data.data.company_phone;
                    this.company.address = companyRes.data.data.company_address;
                    this.company.country_code = companyRes.data.data.company_country_code;

                    this.$store
                        .dispatch(
                            "countryCode/show",
                            companyRes.data.data.company_country_code
                        )
                        .then((res) => {
                            if (this.customerProps.form.country_code === "") {
                                this.customerProps.form.country_code =
                                    res.data.data.calling_code;
                                this.country_code = res.data.data.calling_code;
                            }
                            this.flag = res.data.data.flag_emoji;
                        })
                        .catch();
                })
                .catch();

        } catch (err) {
            this.loading.isActive = false;
        }

        // Vérifier si un panier a été restauré depuis localStorage
        const restoredFromStorage = this.$store.getters['posCart/restoredFromStorage'];
        if (restoredFromStorage && this.$store.getters['posCart/lists'].length > 0) {
            // Afficher une notification — utiliser le système d'alerte existant
            // alertService est un import de module, pas une propriété d'instance
            alertService.info(
                this.$t('message.cart_restored') || 'Panier restauré de la session précédente. Vérifiez les articles.'
            );
            this.$store.dispatch('posCart/acknowledgeRestore');
        }

    },
    methods: {
        // ── WebSocket state awareness ────────────────────────────────────
        _bindWsService() {
            const ws = window._wsService;
            if (!ws) return;
            this._onWsConnected = () => {
                this.loadKioskCashOrders();
                this._restartKioskPolling();
            };
            this._onWsDisconnected = () => {
                this._restartKioskPolling();
            };
            ws.on('connected', this._onWsConnected);
            ws.on('disconnected', this._onWsDisconnected);
        },
        _unbindWsService() {
            const ws = window._wsService;
            if (!ws) return;
            if (this._onWsConnected) ws.off('connected', this._onWsConnected);
            if (this._onWsDisconnected) ws.off('disconnected', this._onWsDisconnected);
        },
        _kioskPollingInterval() {
            return window._wsService?.isConnected() ? 60000 : 10000;
        },
        _startKioskPolling() {
            this._kioskPollTimer = setInterval(() => this.loadKioskCashOrders(), this._kioskPollingInterval());
        },
        _restartKioskPolling() {
            if (this._kioskPollTimer) clearInterval(this._kioskPollTimer);
            this._startKioskPolling();
        },
        // ── Echo real-time subscription for kiosk cash orders ─────────────
        _subscribeEcho() {
            if (!window.Echo) return;
            const branchId = parseInt(this.$store.getters['auth/authBranchId'] || 0);
            if (branchId <= 0) return;
            try {
                this._eventSub = onEvents(branchId, [
                    {
                        broadcastAs: 'OrderCreated',
                        handler: (event) => {
                            // [POS-9.1.11] Audible + visual notification for new POS orders.
                            // Audit POS-GA-F-55 — cashier had zero feedback on new
                            // kiosk-cash / online orders, only a silent list refresh.
                            this._notifyNewOrder(event);
                            this.loadKioskCashOrders();
                        },
                    },
                    { broadcastAs: 'OrderStatusChanged', handler: () => this.loadKioskCashOrders() },
                    // [POS-9.1.10] React live to admin 86 (item availability change)
                    // so freshly out-of-stock tiles grey out without an F5.
                    // Audit POS-GA-F-45 — kiosk already subscribes; POS did not.
                    { broadcastAs: 'ItemAvailabilityChanged', handler: (event) => this._onItemAvailabilityChanged(event) },
                ]);
            } catch (e) {
                // Echo auth failed or Soketi not running — polling fallback handles it
            }
        },
        /**
         * [POS-9.1.10] Apply an ItemAvailabilityChanged broadcast to the POS
         * item list in-place (no full refetch unless type === 'full'). The
         * payload shape is the contract emitted by AvailabilityService /
         * Stock86 listener: { item_id, is_available, type, reason, price }.
         */
        _onItemAvailabilityChanged(event) {
            const payload = (event && event.payload) ? event.payload : event || {};
            const itemId = parseInt(payload.item_id || payload.itemId || 0, 10);
            if (!itemId) return;

            // Locate item in the cached POS list (this.itemsRaw / this.items).
            const list = Array.isArray(this.itemsRaw) ? this.itemsRaw
                       : (Array.isArray(this.items) ? this.items : null);
            if (list) {
                const idx = list.findIndex(i => parseInt(i.id, 10) === itemId);
                if (idx !== -1) {
                    const isAvailable = payload.is_available === true || payload.is_available === 1 || payload.is_available === '1';
                    list[idx] = Object.assign({}, list[idx], {
                        is_available: isAvailable,
                        availability_reason: payload.reason || null,
                    });
                }
            }

            // If the broadcast signals a structural change (price / variation /
            // category move), reload the catalogue in the background.
            if (payload.type === 'full') {
                try { this.itemList(); } catch (e) { /* defensive */ }
            }
        },
        _unsubscribeEcho() {
            this._eventSub?.unsubscribe();
            this._eventSub = null;
        },
        /**
         * [POS-9.1.11] Audible + visual cue when a new order is broadcast on
         * the POS branch channel. Audit POS-GA-F-55.
         *  - Toast (alertService.info) so the cashier sees the order ID;
         *  - Short beep via Web Audio API (no asset to ship); silently
         *    skipped if AudioContext is unavailable or denied by autoplay.
         *  - Honors the `pos_new_order_sound_enabled` frontend setting (defaults true).
         */
        _notifyNewOrder(event) {
            const payload = (event && event.payload) ? event.payload : event || {};
            const orderId = payload.order_id || payload.id || null;
            try {
                const label = orderId
                    ? (this.$t && this.$t('message.new_pos_order_with_id', { id: orderId })) || ('Nouvelle commande #' + orderId)
                    : (this.$t && this.$t('message.new_pos_order')) || 'Nouvelle commande';
                alertService.info(label);
            } catch (e) { /* defensive */ }

            // Sound — opt-out via setting; default ON.
            try {
                const s = this.setting || {};
                const soundFlag = s.pos_new_order_sound_enabled;
                const soundOn = soundFlag === undefined || soundFlag === null
                    ? true
                    : (String(soundFlag) === '1' || soundFlag === true);
                if (!soundOn) return;
                this._playNewOrderBeep();
            } catch (e) { /* defensive */ }
        },
        _playNewOrderBeep() {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!this._audioCtx) {
                try { this._audioCtx = new Ctx(); } catch (e) { return; }
            }
            const ctx = this._audioCtx;
            try {
                const osc  = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = 880; // A5 — short ding
                gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
                osc.connect(gain).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.4);
            } catch (e) { /* autoplay or context blocked */ }
        },

        // ── Kiosk cash orders ──────────────────────────────────────────────
        async loadKioskCashOrders() {
            this.kioskCashLoading = true;
            try {
                // [GAP-25-1] Fetch BOTH order_type=25 (KIOSK/sur place) AND order_type=10 (TAKEAWAY/à emporter)
                // since kiosk now allows customers to choose "à emporter" (Phase 22).
                const [resKiosk, resTakeaway] = await Promise.all([
                    axios.get('admin/kds-order', { params: { order_type: 25, payment_method: 1, paginate: 50 } }).catch(() => null),
                    axios.get('admin/kds-order', { params: { order_type: 10, payment_method: 1, paginate: 50 } }).catch(() => null),
                ]);
                const all = [
                    ...(resKiosk?.data?.data || []),
                    ...(resTakeaway?.data?.data || []),
                ];
                // Client-side filter by status (ACCEPT=4, PREPARING=7, PREPARED=8)
                this.kioskCashOrders = all
                    .filter(o => [4, 7, 8].includes(parseInt(o.order_status ?? o.status, 10)))
                    .sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
            } catch (_) {
                this.kioskCashOrders = [];
            } finally {
                this.kioskCashLoading = false;
            }
        },
        toggleKioskCashOrderDetails(orderId) {
            this.expandedKioskCashOrders = {
                ...this.expandedKioskCashOrders,
                [orderId]: !this.expandedKioskCashOrders[orderId],
            };
        },
        isKioskCashOrderExpanded(orderId) {
            return !!this.expandedKioskCashOrders[orderId];
        },

        // [GAP-25-2] Mark a kiosk cash order as DELIVERED (collected + paid by cashier)
        async collectKioskCashOrder(order) {
            if (order._collecting) return;
            order._collecting = true;
            try {
                await axios.post(`admin/kds-order/change-status/${order.id}`, { status: 13 }); // 13 = DELIVERED
                await this.loadKioskCashOrders();
            } catch (err) {
                const msg = err?.response?.data?.message || 'Erreur lors de l\'encaissement';
                alertService.error(msg);
                order._collecting = false;
            }
        },
        formatKioskPrice(amount) {
            return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount || 0);
        },
        formatKioskTime(iso) {
            if (!iso) return '';
            try {
                return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            } catch (_) { return ''; }
        },
        // ──────────────────────────────────────────────────────────────────
        onlyNumber: function (e) {
            return appService.onlyNumber(e);
        },
        floatNumber: function (e) {
            return appService.floatNumber(e);
        },
        currencyFormat: function (amount, decimal, currency, position) {
            return appService.currencyFormat(amount, decimal, currency, position);
        },
        openCanvas: function (id) {
            return appService.openCanvas(id);
        },
        closeCanvas: function (id) {
            return appService.closeCanvas(id);
        },
        resetName: function () {
            this.props.search.name = "";
            this.itemList();
        },
        selectDiscount(value) {
            this.discountType = value;
        },
        search: function () {
            this.itemList();
        },
        allCategory: function () {
            this.props.search.name = "";
            this.props.search.item_category_id = "";
            this.itemList();
        },
        closeSidebar: function () {
            this.$store.dispatch("globalState/set", { topSidebar: false });
            document?.querySelector(".db-sidebar")?.classList?.add("active");
            document?.querySelector(".db-main")?.classList?.add("expand");
        },
        itemCategories: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch("posCategory/lists", this.categoryProps).then((res) => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
        itemList: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch("item/lists", this.props.search).then((res) => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
        setCategory: function (id) {
            this.props.search.item_category_id = id;
            this.itemList();
        },
        cartQuantityUp: function (id, e) {
            // [V4 FIX] e.target.value is always a string from DOM input; parseInt before storing
            // to avoid string quantity being stored in Vuex (e.g. "3" instead of 3).
            var qty = parseInt(e.target.value, 10);
            if (!isNaN(qty) && qty > 0) {
                this.$store.dispatch('posCart/quantity', { id: id, status: qty }).then().catch();
            }
        },
        cartQuantityIncrement: function (id) {
            this.$store.dispatch('posCart/quantity', { id: id, status: "increment" }).then().catch();
        },
        cartQuantityDecrement: function (id) {
            this.$store.dispatch('posCart/quantity', { id: id, status: "decrement" }).then().catch();
        },
        deleteCartItem: function (id) {
            this.$store.dispatch('posCart/deleteCartItem', { id: id, status: "decrement" }).then().catch();
        },
        applyDiscount: function () {
            // [POS-9.1.1] Require motif for any non-zero discount; surface server permission gate.
            const hasDiscount = this.discount && parseFloat(this.discount) > 0;
            if (hasDiscount) {
                const reason = (this.discountReason || '').trim();
                if (reason.length < 3) {
                    return alertService.error(this.$t('message.discount_reason_required') || 'A reason is required for any POS discount (min 3 characters).');
                }
                this.checkoutProps.form.discount_reason = reason;
            } else {
                this.checkoutProps.form.discount_reason = null;
            }

            if (this.discountType == discountTypeEnum.FIXED) {
                if (this.subtotal < this.discount) {
                    return alertService.error(this.$t('message.discount_fixed_error_message'));
                } else {
                    this.checkoutProps.form.discount = parseFloat(+this.discount).toFixed(this.setting.site_digit_after_decimal_point);
                    this.$store.dispatch('posCart/discount', this.checkoutProps.form.discount).then().catch();
                }

            } else {
                if (this.discount > 100) {
                    return alertService.error(this.$t('message.discount_error_message'));
                } else {

                    this.checkoutProps.form.discount = parseFloat((this.subtotal * this.discount) / 100).toFixed(this.setting.site_digit_after_decimal_point);
                    this.$store.dispatch('posCart/discount', this.checkoutProps.form.discount).then().catch();

                }
            }
        },
        resetCart: function () {
            this.$store.dispatch('posCart/resetCart').then(res => {
                this.checkoutProps.form.token = "";
                this.resetDeliveryInline();
                alertService.success(this.$t('message.cart_reset') || 'Panier vidé.');
            }).catch();
        },
        /** Délègue au helper (même formule que store / checkout) */
        bundledLineUnitTotal: function (bundled) {
            return rowUnitBundled(bundled);
        },
        editCartLine: function (index) {
            const line = this.carts[index];
            if (!line || !this.$refs.posItemComponent) return;
            this.$refs.posItemComponent.openEditFromCart(line, index);
        },
        /** Construit un item commande POS (principal ou addon) pour le JSON checkout */
        buildPosCheckoutOrderRow: function (row, quantity, lineTotal) {
            let item_variations = [];
            // [V2 FIX] Join variations by attrId key using names_by_id map (set in changeVariation).
            // Falls back to index-zip if names_by_id is absent (legacy cart lines or addon rows).
            const variationEntries = Object.entries(row.item_variations.variations || {});
            const namesByIdMap = row.item_variations.names_by_id || null;
            const nameEntries = Object.entries(row.item_variations.names || {});
            variationEntries.forEach(([attrId, varId], i) => {
                let variation_name, name;
                if (namesByIdMap && namesByIdMap[String(attrId)]) {
                    variation_name = namesByIdMap[String(attrId)].attrName;
                    name = namesByIdMap[String(attrId)].varName;
                } else {
                    const nameEntry = nameEntries[i];
                    variation_name = nameEntry ? nameEntry[0] : undefined;
                    name = nameEntry ? nameEntry[1] : undefined;
                }
                item_variations.push({
                    id: varId,
                    item_id: row.item_id,
                    item_attribute_id: attrId,
                    variation_name,
                    name,
                });
            });
            let item_extras = [];
            const extraIds = row.item_extras.extras || [];
            const extraNames = row.item_extras.names || [];
            extraIds.forEach((extraId, i) => {
                item_extras.push({
                    id: extraId,
                    item_id: row.item_id,
                    name: extraNames[i] || undefined,
                });
            });
            return {
                item_id: row.item_id,
                item_price: row.convert_price,
                branch_id: this.checkoutProps.form.branch_id,
                instruction: row.instruction || '',
                quantity: quantity,
                discount: row.discount || 0,
                total_price: lineTotal,
                item_variation_total: row.item_variation_total,
                item_extra_total: row.item_extra_total,
                item_variations: item_variations,
                item_extras: item_extras,
            };
        },
        orderSubmit: async function () {
            // [P5-3] Guard: prevent opening payment modal with empty cart
            if (!this.carts || this.carts.length === 0) {
                return alertService.error(this.$t("message.cart_is_empty") || "Le panier est vide.");
            }
            this.loading.isActive = true;
            this.checkoutProps.form.subtotal = this.subtotal;
            this.checkoutProps.form.total = parseFloat(this.subtotal + this.checkoutProps.form.delivery_charge - this.checkoutProps.form.discount).toFixed(this.setting.site_digit_after_decimal_point);
            this.checkoutProps.form.items = [];
            _.forEach(this.carts, (item) => {
                const mainQty = parsePositiveInt(item.quantity, 1);
                const mainLineTotal = mainOrderLineTotal(item, mainQty);
                this.checkoutProps.form.items.push(this.buildPosCheckoutOrderRow(item, mainQty, mainLineTotal));

                const addons = Array.isArray(item.pos_line_addons) ? item.pos_line_addons : [];
                _.forEach(addons, (b) => {
                    // [C2 FIX] Skip bundled addons with no resolvable item_id to avoid backend 422
                    if (b.item_id == null) {
                        console.warn('[POS] Bundled addon skipped — item_id is null/undefined:', b);
                        return;
                    }
                    const { orderQty, lineTotal } = bundledOrderQuantityAndTotal(b, mainQty);
                    this.checkoutProps.form.items.push(this.buildPosCheckoutOrderRow(b, orderQty, lineTotal));
                });
            });
            this.checkoutProps.form.items = JSON.stringify(this.checkoutProps.form.items);

            // Auto-generate order token (like a fast-food: sequential number for on-site, customer name for delivery)
            if (!this.checkoutProps.form.token) {
                const isDelivery = this.checkoutProps.form.order_type === orderTypeEnum.DELIVERY;
                if (isDelivery && this.deliveryInline.name && this.deliveryInline.name.trim()) {
                    // Use customer first name for delivery orders (easy to call out)
                    this.checkoutProps.form.token = this.deliveryInline.name.trim().split(' ')[0];
                } else {
                    // Sequential daily counter: N°1, N°2 … resets at midnight
                    const today = new Date().toISOString().slice(0, 10); // "YYYY-MM-DD"
                    const seqKey = 'pos_order_seq_' + today;
                    const seq = (parseInt(localStorage.getItem(seqKey) || '0') + 1);
                    localStorage.setItem(seqKey, String(seq));
                    this.checkoutProps.form.token = String(seq);
                }
            }
            if (this.checkoutProps.form.order_type === orderTypeEnum.DINING_TABLE && !this.checkoutProps.form.dining_table_id) {
                this.loading.isActive = false;
                return alertService.error(this.$t("message.table_field_required"));
            }
            if (this.checkoutProps.form.order_type === orderTypeEnum.DELIVERY && !this.checkoutProps.form.address_id) {
                // [P4] Try to create customer+address from inline form before rejecting
                const ok = await this.ensureDeliveryCustomerAndAddress();
                if (!ok) {
                    this.loading.isActive = false;
                    return;
                }
            }

            // [AUDIT-P50-BUG2] Generate idempotency key for POS orders to prevent double-submit duplicates
            // This key is unique per checkout attempt and sent in X-Idempotency-Key header
            this.checkoutProps.form.idempotency_key = `${Date.now()}_${Math.random().toString(36).substr(2, 9)}_${this.checkoutProps.form.branch_id || 0}`;

            this.loading.isActive = false;
            appService.modalShow('#orderpayment');
        },
        totalItems: function () {
            if (this.carts.length > 0) {
                let totalItem = 0;
                this.carts.forEach(cart => {
                    totalItem += cart.quantity;
                });
                return totalItem;
            }
            return 0; // [BUG-A5 FIX] Return 0 when cart is empty
        },
        phoneNumber(e) {
            return appService.phoneNumber(e);
        },
        addCustomers: function () {
            appService.modalShow("#addCustomer");
        },
        resetCustomer: function () {
            appService.modalHide("#addCustomer");
            this.$store.dispatch("user/reset").then().catch();
            this.errors = {};
            this.customerProps.form = {
                name: "",
                email: "",
                phone: "",
                password: "123456",
                password_confirmation: "123456",
                status: statusEnum.ACTIVE,
                country_code: this.country_code,
            };
        },
        saveCustomer: function () {
            try {
                this.loading.isActive = true;
                this.$store
                    .dispatch("user/save", this.customerProps)
                    .then((res) => {
                        appService.modalHide("#addCustomer");
                        alertService.successFlip(0, this.$t("menu.customers"));
                        this.$store
                            .dispatch("user/lists", {
                                order_column: "id",
                                order_type: "asc",
                                status: statusEnum.ACTIVE,
                                role_id: 2,
                                vuex: true
                            })
                            .then((customerResponse) => {
                                this.loading.isActive = false;
                                this.checkoutProps.form.customer_id = res.data.data.id;
                                this.address.form.user_id = res.data.data.id;
                                this.selectedAddress = {};
                                this.gettingUserAddress(this.checkoutProps.form.customer_id);
                            })
                            .catch((err) => {
                                this.loading.isActive = false;
                            });

                        this.customerProps.form = {
                            name: "",
                            email: "",
                            phone: "",
                            password: "123456",
                            password_confirmation: "123456",
                            status: statusEnum.ACTIVE,
                            country_code: this.country_code,
                        };
                        this.errors = {};
                    })
                    .catch((err) => {
                        this.loading.isActive = false;
                        this.errors = err.response.data.errors;
                    });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
        dineInOrder: function () {
            this.checkoutProps.form.address_id = null;
            this.selectedAddress = {};
            this.checkoutProps.form.delivery_charge = 0;

            this.$refs.dineIn.classList.add('active');
            this.$refs.dineInDiv.classList.add('block');
            this.$refs.dineInDiv.classList.remove('hidden');
            this.$refs.takeAway.classList.remove('active');
            this.$refs.deliveryOrderLabel.classList.remove('active');
            this.$refs.deliveryOrderDiv.classList.add('hidden');
            this.$refs.deliveryOrderDiv.classList.remove('block');
        },
        takeAwayOrder: function () {
            this.checkoutProps.form.dining_table_id = null;
            this.checkoutProps.form.address_id = null;
            this.selectedAddress = {};
            this.checkoutProps.form.delivery_charge = 0;

            this.$refs.takeAway?.classList.add('active');
            this.$refs.dineIn?.classList.remove('active');
            this.$refs.dineInDiv?.classList.add('hidden');
            this.$refs.dineInDiv?.classList.remove('block');
            this.$refs.deliveryOrderLabel?.classList.remove('active');
            this.$refs.deliveryOrderDiv?.classList.add('hidden');
            this.$refs.deliveryOrderDiv?.classList.remove('block');
        },
        deliveryOrder: function () {
            this.checkoutProps.form.dining_table_id = null;

            this.$refs.deliveryOrderLabel?.classList.add('active');
            this.$refs.deliveryOrderDiv?.classList.add('block');
            this.$refs.deliveryOrderDiv?.classList.remove('hidden');
            this.$refs.dineIn?.classList.remove('active');
            this.$refs.dineInDiv?.classList.add('hidden');
            this.$refs.dineInDiv?.classList.remove('block');
            this.$refs.takeAway?.classList.remove('active');
        },

        _loadCustomerLoyalty: function (customerId) {
            const customer = this.customers.find(c => c.id === customerId);
            if (!customer) {
                this.selectedCustomerLoyalty = { points: null, code: null, loading: false };
                this.checkoutProps.form.loyalty_customer_code = null;
                return;
            }
            // If the customer list already includes loyalty_code (from UserResource), use it directly.
            if (customer.loyalty_code) {
                this.selectedCustomerLoyalty = {
                    points: customer.loyalty_points ?? null,
                    code: customer.loyalty_code,
                    loading: false,
                };
                this.checkoutProps.form.loyalty_customer_code = customer.loyalty_code;
                return;
            }
            // Fallback: fetch from loyalty API (for customers without loyalty_code in list payload)
            this.selectedCustomerLoyalty = { points: null, code: null, loading: true };
            this.checkoutProps.form.loyalty_customer_code = null;
            axios.get('frontend/loyalty/balance', { params: { code: customer.phone || '' } })
                .then(res => {
                    if (res.data?.status && res.data?.data?.loyalty_code) {
                        this.selectedCustomerLoyalty = {
                            points: res.data.data.points ?? 0,
                            code: res.data.data.loyalty_code,
                            loading: false,
                        };
                        this.checkoutProps.form.loyalty_customer_code = res.data.data.loyalty_code;
                    } else {
                        this.selectedCustomerLoyalty = { points: null, code: null, loading: false };
                    }
                })
                .catch(() => {
                    this.selectedCustomerLoyalty = { points: null, code: null, loading: false };
                });
        },

        gettingUserAddress: function (userId) {
            this.$store
                .dispatch("user/addressLists", {
                    id: userId,
                    order_column: "id",
                    order_type: "desc",
                })
                .then()
                .catch();
        },
        openAddressModal: function () {
            this.address.isMap = true;
            appService.modalShow('#addressModal');
        },
        editAddressModal: function (address) {
            appService.modalShow("#addressModal");
            this.loading.isActive = true;
            this.$store
                .dispatch("user/editAddress", address.id)
                .then((res) => {
                    this.loading.isActive = false;
                    this.address.isMap = true;
                    this.address.form = {
                        address: address.address,
                        apartment: address.apartment,
                        latitude: address.latitude,
                        longitude: address.longitude,
                        label: address.label,
                        user_id: address.user_id,
                    };
                    this.checkoutProps.form.address_id = null;
                    this.checkoutProps.form.delivery_charge = 0;
                    this.selectedAddress = {};
                    if (this.address.form.label === this.$t("label.home")) {
                        this.address.status = false;
                        this.address.switchLabel = labelEnum.HOME;
                    } else if (this.address.form.label === this.$t("label.work")) {
                        this.address.status = false;
                        this.address.switchLabel = labelEnum.WORK;
                    } else {
                        this.address.status = true;
                        this.address.switchLabel = labelEnum.OTHER;
                    }
                })
                .catch((err) => {
                    alertService.error(err.response.data.message);
                });
        },
        changingUser: function () {
            if (this.checkoutProps.form.customer_id !== null) {
                this.clearAddresses = false;
                this.gettingUserAddress(this.checkoutProps.form.customer_id);
                this._loadCustomerLoyalty(this.checkoutProps.form.customer_id);
            } else {
                this.clearAddresses = true;
                this.selectedCustomerLoyalty = { points: null, code: null, loading: false };
                this.checkoutProps.form.loyalty_customer_code = null;
            }
            this.address.form.user_id = this.checkoutProps.form.customer_id;
            this.selectedAddress = {};
            this.checkoutProps.form.delivery_charge = null;
        },
        updateSelectedAddress: function () {
            const address = this.customerAddresses.find((item) => item.id === this.checkoutProps.form.address_id);
            this.selectedAddress = address || {};
            this.deliveryChargeCalculation();
            if (this.checkoutProps.form.address_id === null) {
                this.checkoutProps.form.delivery_charge = null;
            }
        },
        deliveryChargeCalculation: function () {
            if (this.checkoutProps.form.order_type === orderTypeEnum.DELIVERY && (typeof this.selectedAddress.latitude !== 'undefined' && this.selectedAddress.latitude !== '')) {
                this.$store.dispatch("branch/showByLatLong", {
                    branch_id: this.checkoutProps.form.branch_id,
                    latitude: this.selectedAddress.latitude,
                    longitude: this.selectedAddress.longitude
                }).then((branchRes) => {
                    const distance = appService.distance(parseFloat(this.selectedAddress.latitude), parseFloat(this.selectedAddress.longitude), parseFloat(branchRes.data.data.latitude), parseFloat(branchRes.data.data.longitude));

                    if (distance > this.setting.order_setup_free_delivery_kilometer) {
                        let extraDistance = distance - parseFloat(this.setting.order_setup_free_delivery_kilometer);
                        this.checkoutProps.form.delivery_charge = (extraDistance * parseFloat(this.setting.order_setup_charge_per_kilo) + parseFloat(this.setting.order_setup_basic_delivery_charge));
                    } else {
                        this.checkoutProps.form.delivery_charge = parseFloat(this.setting.order_setup_basic_delivery_charge);
                    }
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.selectedAddress = {};
                    this.checkoutProps.form.address_id = null;
                    this.checkoutProps.form.delivery_charge = 0;
                    alertService.info(err.response.data.message);

                });
            } else {
                this.selectedAddress = {};
                this.checkoutProps.form.address_id = null;
                this.checkoutProps.form.delivery_charge = 0;
            }
        },

        // ─── [P4] Inline delivery autocomplete ───────────────────────────────────
        _deliveryAcTimer: null,
        _deliveryAcService: null,
        _deliveryActiveIdx: -1,

        onDeliveryAddressInput() {
            this.deliveryInline.confirmed = false;
            this.deliveryInline.latitude = '';
            this.deliveryInline.longitude = '';
            this.checkoutProps.form.address_id = null;
            clearTimeout(this._deliveryAcTimer);
            const q = this.deliveryInline.addressText.trim();
            if (q.length < 3) { this.deliveryInline.suggestions = []; return; }
            this._deliveryAcTimer = setTimeout(() => this._fetchDeliverySuggestions(q), 300);
        },

        _getDeliveryAcService() {
            if (this._deliveryAcService) return this._deliveryAcService;
            if (window.google && window.google.maps && window.google.maps.places) {
                this._deliveryAcService = new window.google.maps.places.AutocompleteService();
                return this._deliveryAcService;
            }
            return null;
        },

        _fetchDeliverySuggestions(query) {
            const svc = this._getDeliveryAcService();
            if (!svc) {
                // Fallback: no Google Maps loaded — skip suggestions
                this.deliveryInline.suggestions = [];
                return;
            }
            this.deliveryInline.loading = true;
            const req = { input: query, types: ['geocode'] };
            // Bias toward branch location if known
            if (this.location && this.location.lat && this.location.lng) {
                req.location = new window.google.maps.LatLng(this.location.lat, this.location.lng);
                req.radius = 50000;
            }
            svc.getPlacePredictions(req, (predictions, status) => {
                this.deliveryInline.loading = false;
                if (status === window.google.maps.places.PlacesServiceStatus.OK && predictions) {
                    this.deliveryInline.suggestions = predictions.slice(0, 6);
                } else {
                    this.deliveryInline.suggestions = [];
                }
            });
        },

        selectDeliverySuggestion(suggestion) {
            this.deliveryInline.suggestions = [];
            this.deliveryInline.addressText = suggestion.description;
            this.deliveryInline.loading = true;
            // Geocode to get lat/lng
            if (window.google && window.google.maps) {
                const geocoder = new window.google.maps.Geocoder();
                geocoder.geocode({ placeId: suggestion.place_id }, (results, status) => {
                    this.deliveryInline.loading = false;
                    if (status === 'OK' && results && results[0]) {
                        const loc = results[0].geometry.location;
                        this.deliveryInline.latitude = loc.lat();
                        this.deliveryInline.longitude = loc.lng();
                        this.deliveryInline.address = suggestion.description;
                        this.deliveryInline.confirmed = true;
                    } else {
                        this.deliveryInline.address = suggestion.description;
                        this.deliveryInline.confirmed = true;
                    }
                });
            } else {
                this.deliveryInline.address = suggestion.description;
                this.deliveryInline.confirmed = true;
                this.deliveryInline.loading = false;
            }
        },

        deliveryNavDown() {
            if (this.deliveryInline.suggestions.length === 0) return;
            this.deliveryInline.activeIdx = Math.min(
                (this.deliveryInline.activeIdx || -1) + 1,
                this.deliveryInline.suggestions.length - 1
            );
        },
        deliveryNavUp() {
            this.deliveryInline.activeIdx = Math.max((this.deliveryInline.activeIdx || 0) - 1, 0);
        },
        deliveryNavSelect() {
            const idx = this.deliveryInline.activeIdx || 0;
            if (this.deliveryInline.suggestions[idx]) {
                this.selectDeliverySuggestion(this.deliveryInline.suggestions[idx]);
            }
        },

        resetDeliveryInline() {
            this.deliveryInline.name = '';
            this.deliveryInline.phone = '';
            this.deliveryInline.addressText = '';
            this.deliveryInline.address = '';
            this.deliveryInline.latitude = '';
            this.deliveryInline.longitude = '';
            this.deliveryInline.suggestions = [];
            this.deliveryInline.confirmed = false;
            this.deliveryInline.loading = false;
            this.deliveryInline.activeIdx = -1;
            this.checkoutProps.form.address_id = null;
        },

        async ensureDeliveryCustomerAndAddress() {
            // If address_id already set (legacy flow), nothing to do
            if (this.checkoutProps.form.address_id) return true;
            // Inline form must have at minimum an address
            if (!this.deliveryInline.address) {
                alertService.error('Veuillez saisir une adresse de livraison.');
                return false;
            }
            try {
                this.loading.isActive = true;
                // 1. Create or reuse customer
                let customerId = this.checkoutProps.form.customer_id;
                if (this.deliveryInline.name) {
                    const customerRes = await axios.post('/admin/users', {
                        name: this.deliveryInline.name,
                        phone: this.deliveryInline.phone || null,
                        email: `delivery_${Date.now()}@pos.local`,
                        password: 'delivery123',
                        password_confirmation: 'delivery123',
                        status: 1,
                    });
                    customerId = customerRes.data.data.id;
                    this.checkoutProps.form.customer_id = customerId;
                }
                // 2. Save address under that customer
                const addrRes = await axios.post(`/admin/users/address/${customerId}`, {
                    address: this.deliveryInline.address,
                    apartment: '',
                    latitude: this.deliveryInline.latitude || '',
                    longitude: this.deliveryInline.longitude || '',
                    label: 'Livraison',
                });
                this.checkoutProps.form.address_id = addrRes.data.data.id;
                // Update delivery charge if lat/lng available
                if (this.deliveryInline.latitude && this.deliveryInline.longitude) {
                    this.selectedAddress = {
                        id: addrRes.data.data.id,
                        address: this.deliveryInline.address,
                        latitude: this.deliveryInline.latitude,
                        longitude: this.deliveryInline.longitude,
                    };
                    this.deliveryChargeCalculation();
                }
                this.loading.isActive = false;
                return true;
            } catch (err) {
                this.loading.isActive = false;
                const msg = err.response?.data?.message || 'Erreur lors de la sauvegarde de l\'adresse.';
                alertService.error(msg);
                return false;
            }
        },
        // ─────────────────────────────────────────────────────────────────────────
    },
    watch: {
        "customerProps.form.password"(newValue) {
            this.customerProps.form.password_confirmation = newValue;
        },
        carts: {
            handler(newCarts) {
                if (!newCarts || newCarts.length === 0) {
                    this.discount = null;
                    this.discountType = discountTypeEnum.PERCENTAGE;
                    this.discountReason = '';
                    this.checkoutProps.form.discount_reason = null;
                    this.$nextTick(() => {
                        if (this.$refs.takeAway) {
                            this.$refs.takeAway.click();
                            if (this.customers.length > 0) {
                                // [BUG-M2 FIX] Use same walking customer resolution logic — never rely on array index
                                var wc = this.customers.find(u => u.email === 'walkingcustomer@example.com')
                                    || this.customers.find(u => u.name && u.name.toLowerCase().includes('walking'))
                                    || this.customers[0];
                                this.checkoutProps.form.customer_id = wc.id;
                                this.address.form.user_id = wc.id;
                                this.gettingUserAddress(this.checkoutProps.form.customer_id);
                            }

                        }
                    });
                }
            },
            deep: true,
            immediate: true
        }
    },
}
</script>

<style scoped>
/* ── Kiosk cash FAB button ── */
.kiosk-cash-fab {
  position: fixed;
  bottom: 88px;
  right: 20px;
  z-index: 1000;
  background: #e8001c;
  border: none;
  border-radius: 50px;
  padding: 0.6rem 1rem 0.6rem 0.85rem;
  display: flex; align-items: center; gap: 0.4rem;
  cursor: pointer;
  box-shadow: 0 4px 16px rgba(232,0,28,0.4);
  animation: kiosk-fab-pulse 2s ease-in-out infinite;
}
@keyframes kiosk-fab-pulse {
  0%, 100% { box-shadow: 0 4px 16px rgba(232,0,28,0.4); }
  50% { box-shadow: 0 4px 28px rgba(232,0,28,0.7); }
}
.kiosk-cash-fab-icon { font-size: 1.2rem; }
.kiosk-cash-fab-badge {
  background: #fff;
  color: #e8001c;
  border-radius: 50%;
  width: 22px; height: 22px;
  font-size: 0.78rem; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
}
/* ── Panel overlay ── */
.kiosk-cash-panel-overlay {
  position: fixed; inset: 0; z-index: 2000;
  background: rgba(0,0,0,0.5);
  display: flex; align-items: flex-end; justify-content: flex-end;
}
.kiosk-cash-panel {
  background: #fff;
  width: 380px; max-width: 100vw;
  height: 100vh;
  display: flex; flex-direction: column;
  box-shadow: -4px 0 24px rgba(0,0,0,0.15);
}
.kiosk-cash-panel-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f0f0f0;
  font-weight: 700; font-size: 0.95rem;
}
.kiosk-cash-panel-close {
  background: none; border: none; font-size: 1.1rem;
  cursor: pointer; color: #888; padding: 0.25rem;
}
.kiosk-cash-panel-body {
  flex: 1; overflow-y: auto;
  padding: 1rem;
  display: flex; flex-direction: column; gap: 0.75rem;
}
.kiosk-cash-loading, .kiosk-cash-empty {
  display: flex; align-items: center; justify-content: center;
  padding: 2rem; color: #888; font-size: 0.9rem;
}
.kiosk-cash-spinner {
  width: 32px; height: 32px;
  border: 3px solid #f0f0f0;
  border-top-color: #e8001c;
  border-radius: 50%;
  animation: kiosk-spin 0.8s linear infinite;
}
@keyframes kiosk-spin { to { transform: rotate(360deg); } }
.kiosk-cash-order-card {
  background: #fafafa;
  border: 1px solid #f0f0f0;
  border-left: 4px solid #e8001c;
  border-radius: 10px;
  padding: 0.75rem 1rem;
  display: flex; flex-direction: column; gap: 0.4rem;
}
.kiosk-cash-order-head {
  display: flex; align-items: center; justify-content: space-between;
}
.kiosk-cash-order-num { font-weight: 800; font-size: 1rem; }
.kiosk-cash-order-total { font-weight: 700; color: #e8001c; font-size: 1rem; }
.kiosk-cash-order-items { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.kiosk-cash-item-pill {
  background: #f0f0f0; border-radius: 20px;
  padding: 0.18rem 0.55rem; font-size: 0.78rem; color: #444;
}
.kiosk-cash-item-pill.more { background: #ffe4e4; color: #e8001c; }
/* [GAP-25-2] Bouton Encaisser */
.kiosk-cash-collect-btn {
  padding: 6px 14px;
  border-radius: 8px;
  border: none;
  background: #16a34a;
  color: white;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s, opacity 0.15s;
  white-space: nowrap;
}
.kiosk-cash-collect-btn:hover { background: #15803d; }
.kiosk-cash-collect-btn:disabled { opacity: 0.55; cursor: not-allowed; }

.kiosk-cash-order-foot {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 0.78rem; color: #999;
}
.kiosk-cash-order-status { color: #16a34a; font-weight: 600; }
.kiosk-cash-panel-footer {
  padding: 0.75rem 1rem;
  border-top: 1px solid #f0f0f0;
}
.kiosk-cash-refresh-btn {
  width: 100%; padding: 0.6rem;
  background: #f5f5f5; border: none; border-radius: 8px;
  font-size: 0.9rem; font-weight: 600; cursor: pointer; color: #444;
}
.kiosk-cash-refresh-btn:hover { background: #ebebeb; }
/* Transitions */
.slide-up-pos-enter-active, .slide-up-pos-leave-active { transition: transform 0.3s ease, opacity 0.3s; }
.slide-up-pos-enter-from, .slide-up-pos-leave-to { transform: translateY(20px); opacity: 0; }
.slide-panel-enter-active, .slide-panel-leave-active { transition: opacity 0.25s; }
.slide-panel-enter-from, .slide-panel-leave-to { opacity: 0; }
.slide-panel-enter-active .kiosk-cash-panel,
.slide-panel-leave-active .kiosk-cash-panel { transition: transform 0.3s ease; }
.slide-panel-enter-from .kiosk-cash-panel,
.slide-panel-leave-to .kiosk-cash-panel { transform: translateX(100%); }
</style>