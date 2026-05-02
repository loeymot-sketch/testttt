<template>
    <section class="fk-pos-v4 pos-v4-shell" data-pos-v4-shell>
    <a href="#pos-cart" class="sr-only focus:not-sr-only">{{ $t('a11y.skip_to_cart') }}</a>
    <div id="pos-a11y-live" class="sr-only" aria-live="polite" aria-atomic="true"></div>
    <ConnectionStatusBanner suppress-transient suppress-session-invalid />
    <LoadingComponent :props="loading" />

    <div class="pos-v4-main md:w-[calc(100%-340px)] lg:w-[calc(100%-320px)] xl:w-[calc(100%-377px)]">
        <div class="pos-v4-operator-bar">
            <div class="min-w-0">
                <p class="pos-v4-eyebrow">Caisse FoodKing</p>
                <h1 class="pos-v4-title">Commande rapide</h1>
                <div class="pos-v4-status-row">
                    <span>{{ checkoutProps.form.branch_id ? ($t('label.branch') + ' #' + checkoutProps.form.branch_id) : $t('label.ready') }}</span>
                    <span>{{ totalItems() }} {{ $t('label.items') }}</span>
                    <span v-if="kioskCashOrders.length > 0">{{ kioskCashOrders.length }} borne cash</span>
                </div>
            </div>
            <router-link :to="{ name: 'admin.pos.floorplan' }"
                class="pos-v4-floorplan-link inline-flex items-center rounded-lg border border-[#EFF0F6] bg-white px-4 py-2 text-sm font-medium text-heading hover:bg-[#FFEDF4] transition">
                {{ $t('label.floorplan') }}
            </router-link>
        </div>
        <form @submit.prevent="search"
            class="pos-v4-search flex items-center w-full h-[38px] leading-[38px] mb-4 rounded-lg bg-white border-[#EFF0F6] border-t border-l border-b">
            <input type="text" :value="props.search.name" @input="onSearchInput"
                :placeholder="$t('label.search_by_menu_item')"
                :aria-label="$t('label.search_by_menu_item')"
                class="w-full px-5 rounded-tl-lg rounded-bl-lg placeholder:text-xs placeholder:font-rubik placeholder:text-[#A0A3BD]">
            <button @click="resetName" type="button" v-if="props.search.name"
                :aria-label="$t('button.close')"
                class="text-sm text-red-500 fa-regular fa-circle-xmark mr-4"></button>
            <button type="submit"
                :aria-label="$t('button.search')"
                class="flex-shrink-0 w-[38px] h-full text-center ltr:rounded-tr-lg ltr:rounded-br-lg rtl:rounded-tl-lg rtl:rounded-bl-lg bg-[#B0004D]">
                <i class="lab lab-search-normal text-white" aria-hidden="true"></i>
            </button>
        </form>

        <!-- LANDING: grille catégories + best sellers -->
        <template v-if="isLanding">
            <!-- Grille catégories (grandes cartes) -->
            <!-- [Y6 FIX] Filter out the "All" pseudo-category (id=0 or id='') instead of slice(1)
                 so real categories are never hidden if API order changes. -->
            <div v-if="categories.filter(c => c.id && c.id !== 0).length > 0" class="pos-v4-category-grid grid grid-cols-3 sm:grid-cols-4 gap-3 mb-6">
                <button v-for="(category, index) in categories.filter(c => c.id && c.id !== 0)" :key="category.id"
                    type="button" @click="setCategory(category.id)"
                    class="pos-v4-category-card flex flex-col items-center text-center gap-2 py-4 px-2 rounded-xl border border-[#EFF0F6] bg-white hover:bg-[#FFEDF4] hover:border-primary transition">
                    <img class="h-10 w-10 object-contain drop-shadow-category" :src="category.thumb" alt="category">
                    <h3 class="text-xs font-medium font-rubik leading-tight">{{ category.name }}</h3>
                </button>
            </div>

            <!-- Best Sellers -->
            <div aria-live="polite" aria-relevant="additions" :aria-busy="loadingItems ? 'true' : 'false'">
                <SkeletonGrid v-if="loadingItems" :count="12" />
                <template v-else>
                    <div v-if="bestSellerItems.length > 0" class="mb-4">
                        <div class="pos-v4-section-heading">
                            <h3 class="text-sm font-semibold font-rubik text-heading mb-3">{{ $t('label.best_sellers') }}</h3>
                            <span>{{ $t('label.ready') }}</span>
                        </div>
                        <ItemComponent ref="posItemComponent" :items="bestSellerItems" />
                    </div>
                    <!-- Pas de best sellers trouvés: monter ItemComponent vide pour permettre l'édition depuis le panier -->
                    <ItemComponent v-else ref="posItemComponent" :items="[]" />
                </template>
            </div>
        </template>

        <!-- FILTRÉ: swiper catégories + liste complète -->
        <template v-else>
            <div class="swiper pos-menu-swiper pos-v4-category-strip mb-4" v-if="categories.length > 1">
                <Swiper dir="ltr" :speed="1000" slidesPerView="auto" :spaceBetween="16" class="menu-slides">
                    <!-- [W9 FIX] Stable key using category.id instead of object reference -->
                    <SwiperSlide class="!w-fit" v-for="(category, index) in categories" :key="category.id || index"
                        :class="category.id === props.search.item_category_id || (category.id === 0 && props.search.item_category_id === '') ? 'pos-group' : ''">
                        <button v-if="index === 0" type="button" @click="allCategory"
                            class="pos-v4-category-pill w-28 flex flex-col items-center text-center gap-4 py-4 px-3 rounded-lg border-b-2 border-transparent transition hover:bg-[#FFEDF4] hover:border-primary bg-white">
                            <img class="h-7 drop-shadow-category" :src="category.thumb" alt="category">
                            <h3 class="text-xs leading-[16px] font-medium font-rubik">{{ category.name }}</h3>
                        </button>
                        <button v-else type="button" @click="setCategory(category.id)"
                            class="pos-v4-category-pill w-28 flex flex-col items-center text-center gap-4 py-4 px-3 rounded-lg border-b-2 border-transparent transition hover:bg-[#FFEDF4] hover:border-primary bg-white">
                            <img class="h-7 drop-shadow-category" :src="category.thumb" alt="category">
                            <h3 class="text-xs leading-[16px] font-medium font-rubik">{{ category.name }}</h3>
                        </button>
                    </SwiperSlide>
                </Swiper>
            </div>

            <div aria-live="polite" aria-relevant="additions" :aria-busy="loadingItems ? 'true' : 'false'">
                <SkeletonGrid v-if="loadingItems" :count="12" />
                <template v-else>
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
        </template>
    </div>


    <div id="pos-cart"
        role="region"
        :aria-label="$t('a11y.cart_region')"
        class="db-pos-cartDiv pos-v4-cart-panel fixed top-0 ltr:right-0 rtl:left-0 w-full h-screen rounded-none z-50 md:z-10 md:top-[85px] ltr:md:right-5 rtl:md:left-5 md:w-[322px] lg:w-[305px] xl:w-[360px] md:h-[calc(100dvh-85px)] md:rounded-lg flex flex-col overflow-hidden bg-white">
        <div class="pos-v4-cart-head p-4 flex-shrink-0">
            <div class="md:hidden text-right mb-3">
                <button type="button" class="db-pos-cartCls" @click="closeCanvas('pos-cart')"
                    :aria-label="$t('button.close')">
                    <i class="lab-close-circle-line font-fill-danger lab-font-size-24" aria-hidden="true"></i>
                </button>
            </div>
            <div class="pos-v4-ticket-title">
                <div>
                    <p>Ticket caisse</p>
                    <h2>Commande en cours</h2>
                </div>
                <span>{{ totalItems() }} {{ $t('label.items') }}</span>
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
                    role="button"
                    tabindex="0"
                    :aria-label="$t('button.add_customer')"
                    @keydown.enter.prevent="addCustomers"
                    @keydown.space.prevent="addCustomers"
                    class="w-10 h-10 bg-primary rounded-lg flex items-center justify-center cursor-pointer">
                    <i class="fa-solid fa-circle-plus text-white" aria-hidden="true"></i>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <button
                    type="button"
                    class="h-10 rounded-xl border border-[#EFF0F6] text-sm font-medium text-heading bg-[#F7F7FC] hover:bg-[#FFEDF4] transition"
                    :disabled="parkingInFlight"
                    @click="promptParkOrder"
                >
                    {{ $t('pos.park') }}
                </button>
                <button
                    type="button"
                    class="h-10 rounded-xl border border-[#B0004D] text-sm font-medium text-white bg-[#B0004D] hover:bg-[#8E003E] hover:border-[#8E003E] transition"
                    @click="openParkedOrders"
                >
                    {{ $t('pos.parked_orders') }} ({{ parkedOrdersCount }})
                </button>
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
                            <div
                                v-if="deliveryGeocodeError"
                                class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700"
                                role="alert"
                            >
                                {{ deliveryGeocodeError }}
                            </div>
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

        <table class="pos-v4-cart-table w-full">
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
            <tbody role="list">
                <tr v-for="(cart, index) in carts" role="listitem">
                    <td class="pl-3 py-3 last:pr-3 align-top border-b border-[#EFF0F6]">
                        <div class="flex gap-2 items-start">
                            <img v-if="cart.image" :src="cart.image" class="w-10 h-10 rounded-md object-cover flex-shrink-0" />
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="capitalize text-xs font-rubik text-[#2E2F38]">{{ cart.name }}</h3>
                                    <button type="button" @click.prevent="editCartLine(index)"
                                        class="shrink-0 text-primary hover:opacity-80"
                                        :title="$t('button.edit') || 'Modifier'"
                                        :aria-label="$t('button.edit')">
                                        <i class="fa-regular fa-pen-to-square text-xs" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <!-- Wizard cart_display: clean summary (Viandes, Crudités, Sauce, Suppléments) — no instruction clutter -->
                                <template v-if="cart.cart_display && cart.cart_display.trim()">
                                    <p class="text-[11px] font-rubik text-[#5A5A78] leading-snug whitespace-pre-line mt-0.5">{{ cart.cart_display }}</p>
                                </template>
                                <!-- Fallback for non-wizard products: show raw variations/extras -->
                                <template v-else>
                                    <p v-if="formatCartVariationSummary(cart)" class="capitalize text-xs leading-4 font-rubik">
                                        {{ formatCartVariationSummary(cart) }}
                                    </p>
                                    <ul v-if="formatCartExtraSummary(cart)">
                                        <li class="leading-4">
                                            <span class="capitalize text-xs leading-4 font-rubik text-heading">
                                                {{ $t('label.extras') }}:
                                            </span>
                                            <p class="capitalize text-xs leading-4 font-rubik">
                                                {{ formatCartExtraSummary(cart) }}
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
                            <button type="button" @click.prevent="cartQuantityDecrement(index)"
                                :class="cart.quantity === 1 ? 'fa-trash-can' : 'fa-minus'"
                                :aria-label="cart.quantity === 1 ? $t('a11y.remove_item', { item: cart.name }) : $t('a11y.decrease_qty', { item: cart.name })"
                                class="fa-solid text-[10px] w-[18px] h-[18px] leading-4 text-center rounded-full border transition text-primary border-primary hover:bg-primary hover:text-white indec-minus"></button>
                            <input v-on:keypress="onlyNumber($event)" v-on:keyup="cartQuantityUp(index, $event)"
                                type="number" :value="cart.quantity"
                                class="text-center w-7 text-xs font-semibold text-heading indec-value">
                            <button type="button" @click.prevent="cartQuantityIncrement(index)"
                                :aria-label="$t('a11y.increase_qty', { item: cart.name })"
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
        <div class="pos-v4-cart-footer p-4 flex-shrink-0 bg-white border-t border-[#EFF0F6] shadow-[0_-4px_12px_rgba(0,0,0,0.06)]">
            <div class="flex h-[38px]" v-if="carts.length > 0">
                <div class="dropdown-group">
                    <button
                        type="button"
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
                <button @click.prevent="applyDiscount" type="button"
                    class="flex-shrink-0 w-16 h-full text-sm font-medium font-rubik capitalize ltr:rounded-tr-lg ltr:rounded-br-lg rtl:rounded-tl-lg rtl:rounded-bl-lg text-white bg-[#008BBA]">
                    {{ $t('button.apply') }}
                </button>
            </div>
            <div class="mt-2" v-if="carts.length > 0">
                <label for="pos-discount-reason" class="block mb-1 text-xs font-rubik capitalize text-[#2E2F38]">
                    {{ $t('label.reason') }}
                </label>
                <input id="pos-discount-reason" v-model="discountReason" type="text" maxlength="255"
                    :placeholder="$t('label.reason')"
                    class="w-full h-9 text-sm rounded-lg border px-3 text-heading border-[#EFF0F6]">
            </div>

            <div class="flex flex-col gap-1.5 mb-4 mt-4" role="status" aria-live="polite" aria-atomic="true">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-rubik capitalize leading-6 text-[#2E2F38]">
                        {{ $t("label.sub_total") }}
                    </span>
                    <span class="text-sm font-rubik capitalize leading-6 text-[#2E2F38]">
                        {{
                            currencyFormat(subtotal, setting.site_digit_after_decimal_point,
                                setting.site_default_currency_symbol, setting.site_currency_position)
                        }}
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-rubik capitalize leading-6">{{ $t("label.discount") }}</span>
                    <span class="text-sm font-rubik capitalize leading-6">{{
                        currencyFormat(posDiscount,
                            setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                            setting.site_currency_position)
                    }}</span>
                </div>
                <div class="flex items-center justify-between" v-if="checkoutProps.form.delivery_charge">
                    <span class="text-sm font-rubik capitalize leading-6">{{ $t("label.delivery_charge") }}</span>
                    <span class="text-sm font-rubik capitalize leading-6 font-medium text-[#1AB759]">{{
                        currencyFormat(checkoutProps.form.delivery_charge,
                            setting.site_digit_after_decimal_point, setting.site_default_currency_symbol,
                            setting.site_currency_position)
                    }}</span>
                </div>
                <div class="pos-v4-total-row flex items-center justify-between py-2 px-3 rounded-lg bg-[#F7F7FC] -mx-1 mt-1">
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
                </div>
            </div>
            <div class="flex items-center justify-center gap-6" v-if="carts.length > 0">
                <button type="button" @click.prevent="resetCart"
                    class="pos-v4-action-cancel capitalize text-sm font-medium leading-6 font-rubik w-full text-center rounded-3xl py-2 text-white bg-[#FB4E4E]">
                    {{ $t('button.cancel') }}
                </button>
                <button type="button" @click.prevent="orderSubmit"
                    class="pos-v4-action-pay capitalize text-sm font-medium leading-6 font-rubik w-full text-center rounded-3xl py-2 text-white bg-[#1AB759]">
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
                <button type="button" @click="resetCustomer" class="modal-close fa-regular fa-circle-xmark"
                    :aria-label="$t('button.close')"></button>
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
    <ParkedOrdersComponent
        :open="showParkedOrders"
        @close="showParkedOrders = false"
        @restored="applyParkedSnapshot"
    />
    <PaymentComponent
        :props="checkoutProps"
        @payment-form:patch="patchPaymentForm"
        @payment-form:reset="resetPaymentForm"
    />
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
        class="db-pos-cartBtn pos-v4-mobile-cart fixed md:hidden bottom-0 z-10 left-0 w-full h-14 py-4 text-center flex items-center justify-center shadow-xl-top gap-3 bg-primary">
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
                  <span class="kiosk-cash-order-total">{{ formatKioskPrice(order.total ?? order.order_amount) }}</span>
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
                <button
                  class="kiosk-cash-collect-btn"
                  :disabled="order._collecting || order._canceling"
                  @click="collectKioskCashOrder(order)"
                >
                  {{ order._collecting ? '…' : '✓ Encaisser' }}
                </button>
                <button
                  class="kiosk-cash-cancel-btn"
                  :disabled="order._collecting || order._canceling"
                  @click="cancelKioskCashOrder(order)"
                >
                  {{ order._canceling ? '…' : 'Annuler' }}
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
    </section>
</template>
<script>
import axios from 'axios';
import LoadingComponent from "../components/LoadingComponent.vue";
import 'vue3-carousel/dist/carousel.css';
import ItemComponent from "./ItemComponent.vue";
import SkeletonGrid from "./SkeletonGrid.vue";
import sourceEnum from "../../../enums/modules/sourceEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import isAdvanceOrderEnum from "../../../enums/modules/isAdvanceOrderEnum";
import statusEnum from "../../../enums/modules/statusEnum";
import roleEnum from "../../../enums/modules/roleEnum";
import appService from "../../../services/appService";
import PosSyncService from "../../../services/PosSyncService";
import discountTypeEnum from "../../../enums/modules/discountTypeEnum";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import alertService from "../../../services/alertService";
import PaymentComponent from "./PaymentComponent.vue";
import ParkedOrdersComponent from "./ParkedOrdersComponent.vue";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import { Swiper, SwiperSlide } from 'swiper/vue';
import 'swiper/css';
import CustomerAddressCreateComponent from "../customers/address/CustomerAddressCreateComponent.vue";
import CreateCustomerAddressComponent from "./CreateCustomerAddressComponent.vue";
import labelEnum from "../../../enums/modules/labelEnum";
import {
    rowUnitBundled,
    mainOrderLineTotal,
    bundledOrderQuantityAndTotal,
    parsePositiveInt,
} from "../../../helpers/posCartLineMath";
import {
    normalizeExtraEntries,
    normalizeId,
    normalizeVariationEntries,
} from "../../../helpers/posNormalizeIds";
import ConnectionStatusBanner from "../../common/ConnectionStatusBanner.vue";
import { onEvents } from "../../../services/eventContract";
import { normalizeRealtimeOrderEvent, shouldNotifyPosRealtimeOrder } from "../../../store/modules/posOrder";
import debounce from "lodash/debounce";
import { createBarcodeDetector, createFKeyShortcuts } from "../../../helpers/posBarcode";
import { calculateDeliveryChargeFromDistance } from "../../../helpers/deliveryCharge";

// [Phase-6 / T10–T12] Recherche menu, lecteur code-barres + F-keys, debounce,
// `SkeletonGrid` sur chargement grille — perçu perfo (spinners discrets) ; pas de
// logique prix côté client (SSOT serveur). Voir plan 10 phases, Phase 6.
// [Phase-9 / T18] A11y opérateur : skip link → panier, `#pos-a11y-live`, rôle
// `region` panier — helpers `posA11y` (focus / announce). Pas d’`outline: none` arbitraire.

export default {
    name: "PosComponent",
    components: {
        CreateCustomerAddressComponent,
        CustomerAddressCreateComponent,
        ConnectionStatusBanner,
        LoadingComponent,
        ItemComponent,
        SkeletonGrid,
        ParkedOrdersComponent,
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
            showParkedOrders: false,
            expandedKioskCashOrders: {},
            parkingInFlight: false,
            /** [T12] Item grid skeleton while first POS menu fetch is in flight */
            posItemsFetchPending: false,
            _kioskPollTimer: null,
            _posSyncBranchId: null,
            _eventSub: null,
            _walkInCustomerPromise: null,
            /** [T11] Debounce map itemId → timer id — max one toast / item / second */
            _availabilityToastTimers: null,
            checkoutProps: {
                form: {
                    branch_id: null,
                    subtotal: 0,
                    token: "",
                    customer_id: null,
                    discount: 0,
                    delivery_charge: 0,
                    delivery_distance_km: null,
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
                    status: statusEnum.ACTIVE,
                    surface: "pos",
                    branch_id: null
                },
            },
            categoryProps: {
                paginate: 0,
                order_column: 'sort',
                order_type: 'asc',
                status: statusEnum.ACTIVE,
                surface: "pos"
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
            deliveryGeocodeError: '',

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
            _deliveryAcTimer: null,
            _deliveryAcService: null,
            _deliveryActiveIdx: -1,

        }
    },
    computed: {
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
            // [V10 #1] Strict typeof guard: reject arrays/objects/functions before
            // coercion (String([1]) === '1' would otherwise activate the flag).
            const t = typeof raw;
            if (t !== 'boolean' && t !== 'number' && t !== 'string') return false;
            return String(raw) === '1' || raw === true;
        },
        categories: function () {
            return this.$store.getters["posCategory/lists"];
        },
        items: function () {
            return this.$store.getters["item/lists"];
        },
        loadingItems: function () {
            return this.posItemsFetchPending && this.$store.getters["item/lists"].length === 0;
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
        parkedOrdersCount: function () {
            return Number(this.$store.getters['posParked/count'] || 0);
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
        if (this._debouncedListRefresh && this._debouncedListRefresh.cancel) {
            this._debouncedListRefresh.cancel();
        }
        if (this._stopBarcode) {
            this._stopBarcode();
        }
        if (this._stopFKeys) {
            this._stopFKeys();
        }
        // [V14 C-α / FINDING C-2 P2] Clear pending availability toast debounce timers
        // to avoid late-firing toasts on an unmounted component.
        if (this._availabilityToastTimers && typeof this._availabilityToastTimers === 'object') {
            try {
                Object.keys(this._availabilityToastTimers).forEach((k) => {
                    const t = this._availabilityToastTimers[k];
                    if (t) { clearTimeout(t); }
                    delete this._availabilityToastTimers[k];
                });
            } catch (_e) { /* defensive */ }
        }
        if (this._kioskPollTimer) clearInterval(this._kioskPollTimer);
        PosSyncService.stop();
        this._posSyncBranchId = null;
        this._unsubscribeEcho();
        this._unbindWsService();
    },
    mounted() {
        this._debouncedListRefresh = debounce(() => {
            this.itemList();
        }, 150);
        this._stopBarcode = createBarcodeDetector((code) => this.onBarcodeScanned(code));
        // [V14 C-α / FINDING C-5 P2] Disable F-key shortcuts when the parked
        // orders drawer is open (prevents background category switching while
        // the operator interacts with the drawer).
        this._stopFKeys = createFKeyShortcuts(
            (idx) => this.onFKeyShortcut(idx),
            { shouldIntercept: () => !this.showParkedOrders }
        );
        this.closeSidebar();
        this.$refs.takeAway.click();
        this.itemCategories();
        this.itemList();
        this.loadKioskCashOrders();
        this._subscribeEcho();
        this._startKioskPolling();
        this._bindWsService();
        this._startPosSyncFallback();
        try {
            this.loading.isActive = true;
            this.$store.dispatch("defaultAccess/show").then((res) => {
                this.checkoutProps.form.branch_id = res.data.data.branch_id
                this.props.search.branch_id = res.data.data.branch_id;
                this._startPosSyncFallback();
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
                this.itemList();

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
                    const walkingCustomer = this.findWalkInCustomer(res.data.data);
                    if (walkingCustomer) {
                        this.assignWalkInCustomer(walkingCustomer);
                    } else {
                        this.ensureWalkInCustomer();
                    }
                }
                if (!this.checkoutProps.form.customer_id) this.ensureWalkInCustomer();
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
        authBranchId() {
            const candidates = [
                this.$store.getters['auth/authBranchId'],
                this.$store.getters.authBranchId,
                this.$store.state?.auth?.authBranchId,
            ];

            for (const candidate of candidates) {
                if (candidate === '' || candidate === null || typeof candidate === 'undefined') {
                    continue;
                }

                const value = parseInt(candidate, 10);
                if (Number.isFinite(value)) {
                    return value;
                }
            }

            return 0;
        },

        findWalkInCustomer(customers) {
            const list = Array.isArray(customers) ? customers : [];
            return list.find((user) => String(user.email || '').toLowerCase() === 'walkingcustomer@example.com')
                || list.find((user) => {
                    const haystack = `${user.name || ''} ${user.name_email || ''}`.toLowerCase();
                    return haystack.includes('walking')
                        || haystack.includes('walk-in')
                        || haystack.includes('comptoir')
                        || haystack.includes('client passage');
                })
                || null;
        },

        assignWalkInCustomer(customer) {
            if (!customer || !customer.id) return false;
            this.checkoutProps.form.customer_id = customer.id;
            this.address.form.user_id = customer.id;
            this.gettingUserAddress(customer.id);
            return true;
        },

        async ensureWalkInCustomer() {
            if (this.checkoutProps.form.customer_id) return true;

            const existing = this.findWalkInCustomer(this.customers);
            if (this.assignWalkInCustomer(existing)) return true;

            if (this._walkInCustomerPromise) {
                return this._walkInCustomerPromise;
            }

            const countryCode = this.customerProps.form.country_code || this.country_code || '+33';
            this._walkInCustomerPromise = axios.post('/admin/users', {
                name: 'Client Comptoir',
                email: 'walkingcustomer@example.com',
                phone: null,
                password: '123456',
                password_confirmation: '123456',
                status: statusEnum.ACTIVE,
                country_code: countryCode,
            }).then((res) => {
                const customer = res.data?.data || null;
                this.assignWalkInCustomer(customer);
                return true;
            }).catch(() => {
                return this.$store.dispatch('user/lists', {
                    paginate: 0,
                    order_column: 'id',
                    order_type: 'asc',
                    status: statusEnum.ACTIVE,
                    role_id: 2,
                    name: 'Client Comptoir',
                    vuex: true,
                }).then((res) => {
                    const fallback = this.findWalkInCustomer(res.data?.data || []);
                    return this.assignWalkInCustomer(fallback);
                }).catch(() => false);
            }).finally(() => {
                this._walkInCustomerPromise = null;
            });

            return this._walkInCustomerPromise;
        },

        // ── WebSocket state awareness ────────────────────────────────────
        _startPosSyncFallback() {
            const branchId = parseInt(
                this.props.search.branch_id || this.checkoutProps.form.branch_id || this.authBranchId(),
                10,
            );
            if (!Number.isFinite(branchId) || branchId <= 0) {
                return;
            }
            if (this._posSyncBranchId === branchId) {
                return;
            }
            this._posSyncBranchId = branchId;
            PosSyncService.start({
                branchId,
                store: this.$store,
                axios: window.axios || axios,
                webSocketService: window._wsService,
            });
        },
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
            return window._wsService?.isConnected() ? 60000 : 5000;
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
            const branchId = this.authBranchId();
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
                    { broadcastAs: 'OrderPaidAtCounter', handler: () => this.loadKioskCashOrders() },
                    // [POS-9.1.10] React live to admin 86 (item availability change)
                    // so freshly out-of-stock tiles grey out without an F5.
                    // Audit POS-GA-F-45 — kiosk already subscribes; POS did not.
                    { broadcastAs: 'ItemAvailabilityChanged', handler: (event) => this._onItemAvailabilityChanged(event) },
                    { broadcastAs: 'CatalogChanged', handler: (event) => this._onCatalogChanged(event) },
                ]);
            } catch (e) {
                // Echo auth failed or Soketi not running — polling fallback handles it
            }
        },
        _onCatalogChanged(event) {
            const payload = (event && event.payload) ? event.payload : event || {};
            const eventBranchId = parseInt(
                event?.branchId ?? payload.branch_id ?? payload.branchId ?? 0,
                10,
            );
            const activeBranchId = this.authBranchId();

            if (
                Number.isFinite(eventBranchId)
                && eventBranchId > 0
                && activeBranchId > 0
                && eventBranchId !== activeBranchId
            ) {
                return;
            }

            try { this.itemList(); } catch (e) { /* defensive */ }
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

            // [F-04bis] Distinguish two emission modes (event contract is now uniform —
            // see app/Listeners/PersistItemAvailabilityChangedToOutbox + ItemAvailabilityChanged):
            //   • Global catalogue update — admin edited item status/price/variations.
            //     `is_available` is null/undefined; `branch_id` is null; type is one of
            //     'status' | 'price' | 'full'. MUST NOT prune the cart (the item is still
            //     available everywhere — we just need to refresh the catalogue if the
            //     change was structural).
            //   • Branch-scoped flip — MENU_86 toggle / auto-86 / release-after-cancel.
            //     `is_available` is explicitly true|false; `branch_id` is set.
            //     Apply normal pruning logic.
            const hasAvailabilitySignal =
                payload.is_available === true || payload.is_available === false ||
                payload.is_available === 1 || payload.is_available === 0 ||
                payload.is_available === '1' || payload.is_available === '0';

            if (!hasAvailabilitySignal) {
                // Global catalogue change — refresh items list silently if structural.
                if (payload.type === 'full') {
                    try { this.itemList(); } catch (e) { /* defensive */ }
                }
                return;
            }

            // Locate item in the cached POS list (this.itemsRaw / this.items).
            const list = Array.isArray(this.itemsRaw) ? this.itemsRaw
                       : (Array.isArray(this.items) ? this.items : null);
            if (list) {
                const idx = list.findIndex(i => parseInt(i.id, 10) === itemId);
                if (idx !== -1) {
                    const isAvailable = payload.is_available === true || payload.is_available === 1 || payload.is_available === '1';
                    const prevName = list[idx].name;
                    list[idx] = Object.assign({}, list[idx], {
                        is_available: isAvailable,
                        availability_reason: payload.reason || null,
                    });
                    // [P12_POS_CART_PRUNE / F-VERIFY-01-02] Mirror kiosk parity:
                    // remove cart lines for this item_id when it becomes unavailable.
                    if (!isAvailable) {
                        try { this.$store.dispatch('posCart/pruneUnavailable', itemId); } catch (e) { /* defensive */ }
                        this._maybeToastItemUnavailableLost(itemId, prevName);
                    }
                    try {
                        const child = this.$refs.posItemComponent;
                        if (child && typeof child.syncItemAvailabilityFromBroadcast === 'function') {
                            child.syncItemAvailabilityFromBroadcast(itemId, isAvailable, payload.reason || null);
                        }
                    } catch (e) { /* defensive */ }
                }
            }

            // If the broadcast signals a structural change (price / variation /
            // category move), reload the catalogue in the background.
            if (payload.type === 'full') {
                try { this.itemList(); } catch (e) { /* defensive */ }
            }
        },
        /**
         * [T11] One toast per item per ~1s (rapid duplicate broadcasts).
         */
        _maybeToastItemUnavailableLost(itemId, itemName) {
            if (!this._availabilityToastTimers) {
                this._availabilityToastTimers = Object.create(null);
            }
            const key = String(itemId);
            if (this._availabilityToastTimers[key]) return;
            this._availabilityToastTimers[key] = true;
            setTimeout(() => {
                delete this._availabilityToastTimers[key];
            }, 1000);
            try {
                const label = this.$t
                    ? this.$t('pos.item_no_longer_available', { name: itemName || ('#' + itemId) })
                    : ((itemName || itemId) + ' indisponible');
                alertService.warning(label);
            } catch (e) {
                alertService.warning(String(itemName || itemId));
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
            if (!shouldNotifyPosRealtimeOrder(event)) {
                return;
            }

            const normalized = normalizeRealtimeOrderEvent(event);
            const orderId = normalized.orderId;

            // [K09B] Source filter is centralized in posOrder.js and now prefers
            // the backend `_origin` payload key, falling back to legacy order_type.

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

            // [H.3.4 / H.3.8 F-A15] Browsers suspend the AudioContext
            // until a user gesture has occurred. Resume() is a no-op
            // once the context is already running, and returns a
            // Promise we await via .then(); emitting the beep in the
            // resume callback avoids swallowed plays right after login.
            const emit = () => {
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
            };

            try {
                if (ctx.state === 'suspended' && typeof ctx.resume === 'function') {
                    const p = ctx.resume();
                    if (p && typeof p.then === 'function') {
                        p.then(emit).catch(() => { /* still locked */ });
                        return;
                    }
                }
                emit();
            } catch (e) { /* defensive */ }
        },

        // ── Kiosk cash orders ──────────────────────────────────────────────
        async loadKioskCashOrders() {
            this.kioskCashLoading = true;
            try {
                const res = await axios.get('admin/pos/counter-collect/pending');
                const all = res?.data?.data || [];
                this.kioskCashOrders = all
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

        async collectKioskCashOrder(order) {
            if (order._collecting) return;
            order._collecting = true;
            try {
                await axios.post(`admin/pos/counter-collect/${order.id}/confirm`, {
                    mode: posPaymentMethodEnum.CASH,
                    received: order.total ?? order.order_amount ?? 0,
                    note: 'Encaissement borne au comptoir',
                });
                await this.loadKioskCashOrders();
            } catch (err) {
                const msg = err?.response?.data?.message || 'Erreur lors de l\'encaissement';
                alertService.error(msg);
                order._collecting = false;
            }
        },
        async cancelKioskCashOrder(order) {
            if (order._canceling) return;
            order._canceling = true;
            try {
                await axios.post(`admin/pos/counter-collect/${order.id}/cancel`, {
                    reason: 'Commande borne annulee au comptoir',
                });
                await this.loadKioskCashOrders();
            } catch (err) {
                const msg = err?.response?.data?.message || 'Erreur lors de l\'annulation';
                alertService.error(msg);
                order._canceling = false;
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
        currentParkSnapshot() {
            return {
                lists: this.carts,
                subtotal: this.subtotal,
                discount: this.posDiscount,
                total: (this.subtotal + (Number(this.checkoutProps.form.delivery_charge) || 0)) - this.posDiscount,
                checkout_form: {
                    branch_id: this.checkoutProps.form.branch_id,
                    customer_id: this.checkoutProps.form.customer_id,
                    order_type: this.checkoutProps.form.order_type,
                    dining_table_id: this.checkoutProps.form.dining_table_id,
                    address_id: this.checkoutProps.form.address_id,
                    delivery_charge: this.checkoutProps.form.delivery_charge,
                    delivery_distance_km: this.checkoutProps.form.delivery_distance_km,
                    loyalty_customer_code: this.checkoutProps.form.loyalty_customer_code,
                    pos_payment_method: this.checkoutProps.form.pos_payment_method,
                    pos_payment_note: this.checkoutProps.form.pos_payment_note,
                    source: this.checkoutProps.form.source,
                },
                selected_address: this.selectedAddress,
                delivery_inline: {
                    ...this.deliveryInline,
                    suggestions: [],
                    loading: false,
                    activeIdx: -1,
                },
            };
        },
        patchPaymentForm(patch) {
            this.checkoutProps.form = {
                ...this.checkoutProps.form,
                ...patch,
            };
        },
        resetPaymentForm() {
            this.checkoutProps.form = {
                ...this.checkoutProps.form,
                token: "",
                subtotal: null,
                discount: 0,
                delivery_time: null,
                delivery_charge: null,
                delivery_distance_km: null,
                total: 0,
                order_type: orderTypeEnum.TAKEAWAY,
                is_advance_order: isAdvanceOrderEnum.NO,
                source: sourceEnum.POS,
                address_id: null,
                dining_table_id: null,
                coupon_id: null,
                items: [],
                pos_payment_method: posPaymentMethodEnum.CASH,
                pos_payment_note: null,
                pos_received_amount: null,
                quote_token: null,
                quote_signature: null,
            };
        },
        openParkedOrders() {
            this.showParkedOrders = true;
            this.$store.dispatch('posParked/fetchList').then().catch(() => {});
        },
        async promptParkOrder() {
            if (this.parkingInFlight) {
                return;
            }

            if (!Array.isArray(this.carts) || this.carts.length === 0) {
                alertService.info(this.$t('pos.park_requires_items'));
                return;
            }

            const promptLabel = this.$t('pos.park_label_prompt');
            const label = window.prompt(promptLabel, '');

            if (label === null) {
                return;
            }

            this.parkingInFlight = true;

            try {
                await this.$store.dispatch('posParked/park', {
                    label: label.trim() || null,
                    snapshot: this.currentParkSnapshot(),
                });
                await this.$store.dispatch('posCart/resetCart');
                this.checkoutProps.form.token = "";
                this.selectedAddress = {};
                this.resetDeliveryInline();
                alertService.success(this.$t('pos.park_success'));
            } catch (error) {
                alertService.error(this.$t('pos.park_save_error'));
            } finally {
                this.parkingInFlight = false;
            }
        },
        applyParkedSnapshot(payload) {
            const savedForm = payload?.checkout_form || {};
            const savedOrderType = savedForm.order_type ?? orderTypeEnum.TAKEAWAY;
            const savedCustomerId = savedForm.customer_id ?? null;
            const savedSelectedAddress = savedForm.address_id ? (payload?.selected_address || {}) : {};
            const savedDeliveryInline = payload?.delivery_inline && typeof payload.delivery_inline === 'object'
                ? {
                    ...this.deliveryInline,
                    ...payload.delivery_inline,
                    suggestions: [],
                    loading: false,
                    activeIdx: -1,
                }
                : null;

            this.showParkedOrders = false;
            this.checkoutProps.form.token = "";

            this.$nextTick(() => {
                if (savedOrderType === orderTypeEnum.DELIVERY) {
                    this.deliveryOrder();
                } else if (savedOrderType === orderTypeEnum.DINING_TABLE && this.dineInEnabled) {
                    this.dineInOrder();
                } else {
                    this.takeAwayOrder();
                }

                this.checkoutProps.form.branch_id = savedForm.branch_id ?? this.checkoutProps.form.branch_id;
                this.checkoutProps.form.customer_id = savedCustomerId;
                this.checkoutProps.form.order_type = savedOrderType;
                this.checkoutProps.form.dining_table_id = savedForm.dining_table_id ?? null;
                this.checkoutProps.form.address_id = savedForm.address_id ?? null;
                this.checkoutProps.form.delivery_charge = savedForm.delivery_charge ?? 0;
                this.checkoutProps.form.delivery_distance_km = savedForm.delivery_distance_km ?? null;
                this.checkoutProps.form.loyalty_customer_code = savedForm.loyalty_customer_code ?? null;
                this.checkoutProps.form.pos_payment_method = savedForm.pos_payment_method ?? posPaymentMethodEnum.CASH;
                this.checkoutProps.form.pos_payment_note = savedForm.pos_payment_note ?? '';
                this.address.form.user_id = savedCustomerId;
                this.selectedAddress = savedSelectedAddress;

                if (savedDeliveryInline) {
                    this.deliveryInline = savedDeliveryInline;
                } else {
                    this.resetDeliveryInline();
                }

                if (savedCustomerId) {
                    this.clearAddresses = false;
                    this.gettingUserAddress(savedCustomerId);
                    this._loadCustomerLoyalty(savedCustomerId);
                } else {
                    this.clearAddresses = true;
                }
            });
        },
        resetName: function () {
            if (this._debouncedListRefresh && this._debouncedListRefresh.cancel) {
                this._debouncedListRefresh.cancel();
            }
            this.props.search.name = "";
            this.itemList();
        },
        onSearchInput: function (event) {
            this.props.search.name = event.target.value;
            this._debouncedListRefresh();
        },
        onBarcodeScanned: function (code) {
            this.$store.dispatch("item/lookupByBarcode", code).then((item) => {
                if (item) {
                    this.$refs.posItemComponent?.variationModalShow(item);
                } else {
                    alertService.error(this.$t("pos.barcode_not_found", { code }));
                }
            }).catch(() => {
                alertService.error(this.$t("pos.barcode_not_found", { code }));
            });
        },
        onFKeyShortcut: function (idx) {
            const cat = this.categories?.[idx - 1];
            if (!cat) {
                return;
            }
            if (cat.id === 0 || cat.id === "") {
                this.allCategory();
            } else {
                this.setCategory(cat.id);
            }
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
            this.posItemsFetchPending = true;
            this.props.search.page = page;
            this.$store.dispatch("item/lists", this.props.search).then((res) => {
                this.loading.isActive = false;
                this.posItemsFetchPending = false;
            }).catch((err) => {
                this.loading.isActive = false;
                this.posItemsFetchPending = false;
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
        cartVariationEntries: function (cart) {
            return normalizeVariationEntries(cart && cart.item_variations);
        },
        cartExtraEntries: function (cart) {
            return normalizeExtraEntries(cart && cart.item_extras);
        },
        formatCartVariationSummary: function (cart) {
            const entries = this.cartVariationEntries(cart);
            if (entries.length === 0) return '';

            return entries
                .map((variation) => {
                    const quantity = Math.max(1, parseInt(variation.quantity, 10) || 1);
                    const label = variation.name || variation.variation_name || 'Option';
                    return `${quantity}× ${label}`;
                })
                .join(', ');
        },
        formatCartExtraSummary: function (cart) {
            const entries = this.cartExtraEntries(cart);
            if (entries.length === 0) return '';

            return entries
                .map((extra) => {
                    const quantity = Math.max(1, parseInt(extra.quantity, 10) || 1);
                    return quantity > 1 ? `${quantity}× ${extra.name || 'Extra'}` : (extra.name || 'Extra');
                })
                .join(', ');
        },
        editCartLine: function (index) {
            const line = this.carts[index];
            if (!line || !this.$refs.posItemComponent) return;
            this.$refs.posItemComponent.openEditFromCart(line, index);
        },
        /** Construit un item commande POS (principal ou addon) pour le JSON checkout */
        buildPosCheckoutOrderRow: function (row, quantity, lineTotal) {
            const item_variations = this.cartVariationEntries(row).map((variation) => ({
                id: normalizeId(variation.id) || variation.id,
                item_id: row.item_id,
                item_attribute_id: normalizeId(variation.item_attribute_id),
                variation_name: variation.variation_name,
                name: variation.name,
                quantity: Math.max(1, parseInt(variation.quantity, 10) || 1),
            }));

            const item_extras = this.cartExtraEntries(row).map((extra) => ({
                id: normalizeId(extra.id) || extra.id,
                item_id: row.item_id,
                name: extra.name || undefined,
                quantity: Math.max(1, parseInt(extra.quantity, 10) || 1),
            }));
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
            if (this.checkoutProps.form.order_type !== orderTypeEnum.DELIVERY && !this.checkoutProps.form.customer_id) {
                const walkInReady = await this.ensureWalkInCustomer();
                if (!walkInReady) {
                    this.loading.isActive = false;
                    return alertService.error('Client comptoir indisponible. Rechargez la caisse puis réessayez.');
                }
            }
            this.checkoutProps.form.subtotal = this.subtotal;
            // @pricing-allowed-block start
            // [POS-V4 W0+ DISCOVERY 2026-04-26] Pre-modal display total — backend remains SSOT and recomputes server-side.
            // Identical pattern to ItemComponent.totalPriceSetup (W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md, decision D1).
            // signoff-pending — date_limit: 2026-05-10
            // Sign-off owners: Tech Lead + Backend owner. Tracking: reports/audit/BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md §1.
            // Migration path: replace by backend-computed `quote/preview` endpoint (W2 deliverable per HYPERREVIEW §6.D2).
            this.checkoutProps.form.total = parseFloat(this.subtotal + this.checkoutProps.form.delivery_charge - this.checkoutProps.form.discount).toFixed(this.setting.site_digit_after_decimal_point);
            // @pricing-allowed-block end
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

            // [AUDIT-P50-BUG2 + POS-V4 W0+] Generate idempotency key for POS orders to prevent double-submit duplicates
            // This key is unique per checkout attempt and sent in X-Idempotency-Key header.
            // INVARIANT (branch_id isolation): a null branch_id would suffix the key with "_0_" and risk
            // cross-branch collisions on a shared backend key store. We hard-stop here instead of falling back to 0.
            const _branchId = this.checkoutProps.form.branch_id;
            if (_branchId == null || _branchId === '' || _branchId === 0) {
                this.loading.isActive = false;
                return alertService.error(this.$t("message.branch_required") || "Branche requise pour valider la commande.");
            }
            this.checkoutProps.form.idempotency_key = `${Date.now()}_${Math.random().toString(36).substr(2, 9)}_${_branchId}`;

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
            this.checkoutProps.form.delivery_distance_km = null;
            this.clearDeliveryGeocodeError();

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
            this.checkoutProps.form.delivery_distance_km = null;
            this.clearDeliveryGeocodeError();

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
                    this.checkoutProps.form.delivery_distance_km = null;
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
                this.applyDeliveryChargeFromCoordinates(this.selectedAddress.latitude, this.selectedAddress.longitude)
                    .catch(() => {});
            } else if (this.checkoutProps.form.order_type === orderTypeEnum.DELIVERY) {
                this.checkoutProps.form.delivery_distance_km = null;
                this.checkoutProps.form.delivery_charge = 0;
                this.showDeliveryGeocodeError();
            } else {
                this.selectedAddress = {};
                this.checkoutProps.form.address_id = null;
                this.checkoutProps.form.delivery_distance_km = null;
                this.checkoutProps.form.delivery_charge = 0;
                this.clearDeliveryGeocodeError();
            }
        },

        async applyDeliveryChargeFromCoordinates(latitude, longitude) {
            const lat = parseFloat(latitude);
            const lng = parseFloat(longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                this.checkoutProps.form.delivery_distance_km = null;
                this.checkoutProps.form.delivery_charge = 0;
                this.showDeliveryGeocodeError();
                return false;
            }

            try {
                this.clearDeliveryGeocodeError();
                const branchRes = await this.$store.dispatch("branch/showByLatLong", {
                    branch_id: this.checkoutProps.form.branch_id,
                    latitude: lat,
                    longitude: lng
                });
                const distance = appService.distance(lat, lng, parseFloat(branchRes.data.data.latitude), parseFloat(branchRes.data.data.longitude));
                if (!Number.isFinite(distance) || distance < 0) {
                    this.checkoutProps.form.delivery_distance_km = null;
                    this.checkoutProps.form.delivery_charge = 0;
                    this.showDeliveryGeocodeError();
                    return false;
                }
                this.checkoutProps.form.delivery_distance_km = distance;
                this.checkoutProps.form.delivery_charge = calculateDeliveryChargeFromDistance(this.checkoutProps.form.delivery_distance_km);
                return true;
            } catch (err) {
                this.loading.isActive = false;
                this.selectedAddress = {};
                this.checkoutProps.form.address_id = null;
                this.checkoutProps.form.delivery_distance_km = null;
                this.checkoutProps.form.delivery_charge = 0;
                this.showDeliveryGeocodeError();
                alertService.info(err.response?.data?.message || this.deliveryGeocodeError);
                return false;
            }
        },

        clearDeliveryGeocodeError() {
            this.deliveryGeocodeError = '';
        },

        showDeliveryGeocodeError() {
            this.deliveryGeocodeError = 'Adresse non reconnue. Vérifiez l’adresse avant de valider la livraison.';
            this.focusDeliveryAddressField();
        },

        focusDeliveryAddressField() {
            this.$nextTick(() => {
                const input = this.$refs.deliveryAddressInput;
                if (input && typeof input.focus === 'function') {
                    input.focus();
                }
            });
        },

        // ─── [P4] Inline delivery autocomplete ───────────────────────────────────
        onDeliveryAddressInput() {
            this.clearDeliveryGeocodeError();
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
                        this.deliveryInline.address = '';
                        this.deliveryInline.confirmed = false;
                        this.showDeliveryGeocodeError();
                    }
                });
            } else {
                this.deliveryInline.address = '';
                this.deliveryInline.confirmed = false;
                this.showDeliveryGeocodeError();
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
            this.checkoutProps.form.delivery_distance_km = null;
            this.checkoutProps.form.delivery_charge = 0;
            this.clearDeliveryGeocodeError();
        },

        async ensureDeliveryCustomerAndAddress() {
            // If address_id already set (legacy flow), nothing to do
            if (this.checkoutProps.form.address_id) return true;
            // Inline form must have at minimum an address
            const deliveryAddress = (this.deliveryInline.address || this.deliveryInline.addressText || '').trim();
            if (!deliveryAddress) {
                alertService.error('Veuillez saisir une adresse de livraison.');
                return false;
            }
            if (!this.deliveryInline.latitude || !this.deliveryInline.longitude) {
                this.showDeliveryGeocodeError();
                alertService.error(this.deliveryGeocodeError);
                return false;
            }
            try {
                this.loading.isActive = true;
                // 1. Create or reuse customer
                let customerId = this.checkoutProps.form.customer_id;
                if (!customerId) {
                    const customerRes = await axios.post('/admin/users', {
                        name: this.deliveryInline.name || 'Client livraison',
                        phone: this.deliveryInline.phone || null,
                        email: `delivery_${Date.now()}@pos.local`,
                        password: 'delivery123',
                        password_confirmation: 'delivery123',
                        status: statusEnum.ACTIVE,
                        country_code: this.customerProps.form.country_code || this.country_code || '+33',
                    });
                    customerId = customerRes.data.data.id;
                    this.checkoutProps.form.customer_id = customerId;
                }
                // 2. Save address under that customer
                const addrRes = await axios.post(`/admin/users/address/${customerId}`, {
                    address: deliveryAddress,
                    apartment: '',
                    latitude: this.deliveryInline.latitude || '',
                    longitude: this.deliveryInline.longitude || '',
                    label: 'Livraison',
                });
                this.checkoutProps.form.address_id = addrRes.data.data.id;
                // Update delivery charge if lat/lng available
                this.selectedAddress = {
                    id: addrRes.data.data.id,
                    address: deliveryAddress,
                    latitude: this.deliveryInline.latitude,
                    longitude: this.deliveryInline.longitude,
                };
                if (! await this.applyDeliveryChargeFromCoordinates(this.deliveryInline.latitude, this.deliveryInline.longitude)) {
                    this.loading.isActive = false;
                    return false;
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
                            this.ensureWalkInCustomer();

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
.fk-pos-v4 {
  --pos-v4-ink: #141821;
  --pos-v4-muted: #687083;
  --pos-v4-panel: #ffffff;
  --pos-v4-bg: #f3f5f8;
  --pos-v4-red: #e8001c;
  --pos-v4-blue: #0f7cff;
  --pos-v4-green: #12965d;
  --pos-v4-border: rgba(20, 24, 33, 0.1);
  --pos-v4-shadow: 0 18px 48px rgba(20, 24, 33, 0.12);
  min-height: calc(100dvh - 85px);
  margin: -8px -8px 0 -8px;
  padding: 12px;
  background:
    linear-gradient(180deg, rgba(20, 24, 33, 0.04), rgba(20, 24, 33, 0)),
    var(--pos-v4-bg);
  color: var(--pos-v4-ink);
}

.pos-v4-main {
  padding: 0 10px 22px 0;
}

.pos-v4-operator-bar {
  min-height: 112px;
  margin-bottom: 14px;
  padding: 18px;
  border: 1px solid rgba(255, 255, 255, 0.16);
  border-radius: 18px;
  background:
    linear-gradient(135deg, #111827 0%, #23131a 58%, #e8001c 128%);
  box-shadow: var(--pos-v4-shadow);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.pos-v4-eyebrow {
  margin: 0 0 4px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.68);
}

.pos-v4-title {
  margin: 0;
  color: #ffffff !important;
  font-size: clamp(24px, 2.4vw, 34px);
  line-height: 1.04;
  font-weight: 900;
  letter-spacing: 0;
}

.pos-v4-status-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.pos-v4-status-row span,
.pos-v4-ticket-title > span,
.pos-v4-section-heading > span {
  display: inline-flex;
  align-items: center;
  min-height: 26px;
  padding: 0 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.12);
  color: rgba(255, 255, 255, 0.9);
  font-size: 11px;
  font-weight: 800;
  white-space: nowrap;
}

.pos-v4-floorplan-link {
  min-height: 46px;
  border: 0 !important;
  border-radius: 14px !important;
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
  color: var(--pos-v4-ink) !important;
  font-weight: 900 !important;
  white-space: nowrap;
}

.pos-v4-search {
  height: 48px !important;
  border: 1px solid var(--pos-v4-border) !important;
  border-radius: 16px !important;
  box-shadow: 0 10px 26px rgba(20, 24, 33, 0.08);
  overflow: hidden;
}

.pos-v4-search input {
  height: 100%;
  font-size: 14px;
  color: var(--pos-v4-ink);
}

.pos-v4-search button[type="submit"] {
  width: 52px !important;
  background: var(--pos-v4-red) !important;
}

.pos-v4-category-grid {
  align-items: stretch;
}

.pos-v4-category-card,
.pos-v4-category-pill {
  min-height: 108px;
  border-color: transparent !important;
  box-shadow: 0 10px 28px rgba(20, 24, 33, 0.08);
}

.pos-v4-category-card:hover,
.pos-v4-category-pill:hover,
.pos-v4-category-strip .pos-group .pos-v4-category-pill {
  background: #fff5f6 !important;
  border-color: rgba(232, 0, 28, 0.26) !important;
  box-shadow: 0 16px 34px rgba(232, 0, 28, 0.14);
}

.pos-v4-section-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.pos-v4-section-heading > span {
  background: rgba(18, 150, 93, 0.1);
  color: var(--pos-v4-green);
}

.pos-v4-cart-panel {
  border: 1px solid rgba(20, 24, 33, 0.1);
  box-shadow: -18px 0 46px rgba(20, 24, 33, 0.13);
}

.pos-v4-cart-head {
  background:
    linear-gradient(180deg, rgba(232, 0, 28, 0.05), rgba(255, 255, 255, 0)),
    #fff;
}

.pos-v4-ticket-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 1px solid rgba(20, 24, 33, 0.08);
}

.pos-v4-ticket-title p {
  margin: 0 0 2px;
  color: var(--pos-v4-muted);
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0;
}

.pos-v4-ticket-title h2 {
  margin: 0;
  color: var(--pos-v4-ink);
  font-size: 19px;
  line-height: 1.1;
  font-weight: 900;
}

.pos-v4-ticket-title > span {
  background: rgba(15, 124, 255, 0.1);
  color: var(--pos-v4-blue);
}

.pos-v4-cart-table thead {
  background: #111827 !important;
}

.pos-v4-cart-table thead th {
  color: rgba(255, 255, 255, 0.82) !important;
  font-weight: 800 !important;
}

.pos-v4-cart-table tbody tr:hover {
  background: rgba(15, 124, 255, 0.035);
}

.pos-v4-cart-footer {
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.94), #fff),
    var(--pos-v4-panel) !important;
}

.pos-v4-total-row {
  min-height: 58px;
  border: 1px solid rgba(232, 0, 28, 0.12);
  background: linear-gradient(135deg, #fff5f6, #ffffff) !important;
}

.pos-v4-total-row span:last-child {
  color: var(--pos-v4-red) !important;
  font-size: 22px !important;
  font-weight: 900 !important;
}

.pos-v4-action-cancel,
.pos-v4-action-pay {
  min-height: 44px;
  border-radius: 14px !important;
  font-weight: 900 !important;
  box-shadow: 0 10px 20px rgba(20, 24, 33, 0.1);
}

.pos-v4-action-pay {
  background: var(--pos-v4-green) !important;
}

.pos-v4-mobile-cart {
  height: 64px !important;
  background: linear-gradient(135deg, #111827, var(--pos-v4-red)) !important;
}

:deep(.pos-item-tile) {
  min-height: 112px !important;
  border: 1px solid rgba(20, 24, 33, 0.08) !important;
  border-radius: 18px !important;
  background: #fff !important;
  box-shadow: 0 10px 28px rgba(20, 24, 33, 0.08);
}

:deep(.pos-item-tile:hover) {
  transform: translateY(-1px);
  border-color: rgba(232, 0, 28, 0.25) !important;
  box-shadow: 0 16px 34px rgba(232, 0, 28, 0.13);
}

:deep(.pos-item-tile h3) {
  font-size: 13px !important;
  line-height: 1.2 !important;
}

:deep(.pos-item-tile h4) {
  color: var(--pos-v4-red) !important;
  font-size: 12px !important;
  font-weight: 900 !important;
}

:deep(.pos-item-tile button) {
  width: 30px !important;
  height: 30px !important;
  background: #111827;
  border-color: #111827 !important;
  color: #fff !important;
}

@media (max-width: 767px) {
  .fk-pos-v4 {
    margin: -8px;
    padding: 10px;
    padding-bottom: 76px;
  }

  .pos-v4-main {
    padding-right: 0;
  }

  .pos-v4-operator-bar {
    align-items: flex-start;
    flex-direction: column;
  }

  .pos-v4-floorplan-link {
    width: 100%;
    justify-content: center;
  }
}

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
.kiosk-cash-collect-btn:disabled,
.kiosk-cash-cancel-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.kiosk-cash-cancel-btn {
  border: 0; background: #fee2e2; color: #991b1b; border-radius: 999px;
  padding: 0.38rem 0.82rem; font-size: 0.74rem; font-weight: 800;
}
.kiosk-cash-cancel-btn:hover { background: #fecaca; }

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
