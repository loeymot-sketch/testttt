<template>
    <section class="fk-pos-v4 pos-v4-shell" data-pos-v4-shell>
    <a href="#pos-cart" class="sr-only focus:not-sr-only">{{ $t('a11y.skip_to_cart') }}</a>
    <div id="pos-a11y-live" class="sr-only" aria-live="polite" aria-atomic="true"></div>
    <ConnectionStatusBanner suppress-transient suppress-session-invalid />
    <LoadingComponent :props="loading" />

    <div class="pos-v4-main md:w-[calc(100%-316px)] lg:w-[calc(100%-302px)] xl:w-[calc(100%-346px)]">
        <div class="pos-v4-operator-bar">
            <div class="min-w-0 flex-1">
                <p class="pos-v4-eyebrow">Caisse FoodKing</p>
                <h1 class="pos-v4-title">Commande rapide</h1>
                <div class="pos-v4-status-row">
                    <span>{{ checkoutProps.form.branch_id ? ($t('label.branch') + ' #' + checkoutProps.form.branch_id) : $t('label.ready') }}</span>
                    <span>{{ totalItems() }} {{ $t('label.items') }}</span>
                </div>
            </div>
            <div class="pos-v4-operator-actions flex flex-shrink-0 flex-wrap items-center justify-end gap-2 sm:gap-3">
                <button
                    v-if="kioskCashOrders.length > 0"
                    type="button"
                    class="kiosk-cash-bar-btn"
                    data-testid="kiosk-cash-open"
                    :title="$t('pos.kiosk_counter_collect_hint')"
                    @click="showKioskCashPanel = true"
                >
                    <span class="kiosk-cash-bar-btn-icon" aria-hidden="true">🖥️</span>
                    <span class="kiosk-cash-bar-btn-text">
                        <span class="kiosk-cash-bar-btn-label">{{ $t('pos.kiosk_counter_collect_short') }}</span>
                        <span class="kiosk-cash-bar-btn-sub">{{ $t('pos.kiosk_counter_collect_sub') }}</span>
                    </span>
                    <span class="kiosk-cash-bar-btn-badge">{{ kioskCashOrders.length }}</span>
                </button>
                <!--
                  [POS-V4-ORDERS-TRACKER 2026-05-02] Bouton suivi commandes.
                  - Toujours visible ; muet si aucune commande active (badge 0).
                  - Vert + halo subtil dès qu'une commande passe à PREPARED — pas de
                    popup, pas de toast, juste un signal visuel pour le caissier.
                  - Le clic ouvre l'écran kanban dédié sans casser le panier en cours
                    (router-link garde le state Vuex).
                -->
                <router-link
                    :to="{ name: 'admin.pos-orders.tracker' }"
                    :class="['pos-tracker-bar-btn', activeOrdersStats.ready > 0 ? 'is-ready' : '']"
                    data-testid="pos-tracker-open"
                    :title="$t('pos.tracker.button_hint')"
                >
                    <span class="pos-tracker-bar-btn-icon" aria-hidden="true">📋</span>
                    <span class="pos-tracker-bar-btn-text">
                        <span class="pos-tracker-bar-btn-label">{{ $t('pos.tracker.button_label') }}</span>
                        <span class="pos-tracker-bar-btn-sub" v-if="activeOrdersStats.ready > 0">
                            {{ activeOrdersStats.ready }} {{ $t('pos.tracker.ready_short') }}
                        </span>
                        <span class="pos-tracker-bar-btn-sub" v-else>{{ $t('pos.tracker.button_sub') }}</span>
                    </span>
                    <span
                        v-if="activeOrdersStats.active > 0"
                        class="pos-tracker-bar-btn-badge"
                    >{{ activeOrdersStats.active }}</span>
                </router-link>
                <router-link
                    :to="{ name: 'admin.order-status-screen' }"
                    target="_blank"
                    rel="noopener"
                    class="pos-tracker-bar-customer inline-flex items-center gap-2 rounded-lg border border-[#EFF0F6] bg-white px-3 py-2 text-sm font-medium text-heading hover:bg-[#FFEDF4] hover:border-primary transition"
                    :title="$t('pos.tracker.customer_screen_hint')"
                >
                    <i class="fa-solid fa-display" aria-hidden="true"></i>
                    <span class="hidden xl:inline">{{ $t('pos.tracker.customer_screen') }}</span>
                </router-link>
            <router-link :to="{ name: 'admin.pos.floorplan' }"
                class="pos-v4-floorplan-link inline-flex items-center rounded-lg border border-[#EFF0F6] bg-white px-4 py-2 text-sm font-medium text-heading hover:bg-[#FFEDF4] transition">
                {{ $t('label.floorplan') }}
            </router-link>
            <!--
              [POS-V4-CASHIER-OPS 2026-05-02] No-sale / open drawer.
              - Discoverable in the operator bar but visually neutral (no badge, no glow)
                so it never competes with payment / tracker actions.
              - Calls the existing kioskHardware.openDrawer() bridge, which is a safe
                no-op in dev (returns ok:true) — production hardware opens the till.
              - Logs the event server-side via the bridge for audit trail.
            -->
            <button
                type="button"
                class="pos-v4-no-sale-btn inline-flex items-center gap-2 rounded-lg border border-[#EFF0F6] bg-white px-3 py-2 text-sm font-medium text-heading hover:bg-[#FFEDF4] hover:border-primary transition"
                data-testid="pos-no-sale"
                :title="$t('pos.no_sale_hint')"
                :disabled="noSaleBusy"
                :aria-busy="noSaleBusy"
                @click="triggerNoSaleOpenDrawer"
            >
                <i class="fa-solid fa-cash-register" aria-hidden="true"></i>
                <span class="hidden xl:inline">{{ $t('pos.no_sale') }}</span>
            </button>
            </div>
        </div>
        <form @submit.prevent="search"
            class="pos-v4-search flex items-center w-full h-[38px] leading-[38px] mb-2 rounded-lg bg-white border-[#EFF0F6] border-t border-l border-b">
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

        <!-- Vue unifiée : bande de catégories (Toutes + …) toujours visible + grille produits -->
        <!-- Native horizontal scroll (no Swiper) — avoids long animated slides between categories -->
        <!--
          [POS-V4-DENSITY 2026-05-02] Compact pills (was w-28 / py-4 / gap-4 /
          h-7 thumb). Saves ~28px of vertical space before the products grid.
          The user explicitly asked for tighter category buttons + more room
          for products — operator bar + tracker buttons stay untouched.
        -->
        <div
            v-if="categories.length > 1"
            class="pos-menu-category-scroll pos-v4-category-strip mb-2 flex flex-nowrap gap-2 overflow-x-auto pb-1"
            ref="categoryScrollStrip"
            role="tablist"
            :aria-label="$t('label.categories') || 'Categories'"
        >
            <template v-for="(category, index) in categories" :key="category.id || index">
                <div
                    class="flex-shrink-0 w-24"
                    :class="category.id === props.search.item_category_id || (category.id === 0 && props.search.item_category_id === '') ? 'pos-group' : ''"
                >
                    <button v-if="index === 0" type="button" @click="allCategory"
                        class="pos-v4-category-pill w-24 flex flex-col items-center text-center gap-1.5 py-2 px-2 rounded-lg border-b-2 border-transparent transition hover:bg-[#FFEDF4] hover:border-primary bg-white">
                        <img class="h-6 drop-shadow-category" :src="category.thumb" alt="">
                        <h3 class="text-[11px] leading-[14px] font-medium font-rubik">{{ category.name }}</h3>
                    </button>
                    <button v-else type="button" @click="setCategory(category.id)"
                        class="pos-v4-category-pill w-24 flex flex-col items-center text-center gap-1.5 py-2 px-2 rounded-lg border-b-2 border-transparent transition hover:bg-[#FFEDF4] hover:border-primary bg-white">
                        <img class="h-6 drop-shadow-category" :src="category.thumb" alt="">
                        <h3 class="text-[11px] leading-[14px] font-medium font-rubik">{{ category.name }}</h3>
                    </button>
                </div>
            </template>
        </div>

        <div aria-live="polite" aria-relevant="additions"
            :aria-busy="loadingItems ? 'true' : 'false'"
            class="pos-menu-products-region">
            <SkeletonGrid v-if="loadingItems" :count="12" />
            <template v-else>
                <ItemComponent ref="posItemComponent" :items="items" :drinks-catalog="drinksCatalog" />

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
    </div>


    <div id="pos-cart"
        role="region"
        :aria-label="$t('a11y.cart_region')"
        class="db-pos-cartDiv pos-v4-cart-panel fixed top-0 ltr:right-0 rtl:left-0 w-full h-screen rounded-none z-50 md:z-10 md:top-[64px] ltr:md:right-3 rtl:md:left-3 md:w-[300px] lg:w-[290px] xl:w-[330px] md:h-[calc(100dvh-64px)] md:rounded-lg flex flex-col overflow-hidden bg-white">
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
            <!--
              [POS-V4-CASHIER-OPS 2026-05-02] Cancel last cart line.
              - Visible only when at least one line exists.
              - Subtle styling: not a red destructive button — cashier should feel
                this is a one-tap "oops" undo, not a heavyweight cancel.
              - Confirms via a single OK alert (no native confirm()) to avoid a
                double-click drag in fast-food rush hour.
            -->
            <div v-if="carts.length > 0" class="mb-3">
                <button
                    type="button"
                    class="w-full h-9 rounded-lg border border-dashed border-[#D9DBE9] text-xs font-medium text-[#6E7191] bg-white hover:bg-[#FFEDF4] hover:border-primary hover:text-primary transition flex items-center justify-center gap-2"
                    data-testid="pos-cancel-last-line"
                    @click="cancelLastCartLine"
                >
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                    {{ $t('pos.cancel_last_line') }}
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
                    data-testid="pos-discount-input"
                    class="w-full h-full border-t border-b px-3 border-[#EFF0F6]">
                <!--
                  [POS-V4-CASHIER-OPS 2026-05-02] Apply button is disabled when a
                  positive discount is set without a 3+ char reason. Backend
                  enforces this (OrderService L2007-2011) — we mirror it client-side
                  to keep UX immediate and prevent a wasted server round-trip in the
                  fast-food rush hour. Empty discount stays applyable to clear it.
                -->
                <button @click.prevent="applyDiscount" type="button"
                    :disabled="!isDiscountApplyable"
                    :aria-disabled="!isDiscountApplyable"
                    data-testid="pos-discount-apply"
                    class="flex-shrink-0 w-16 h-full text-sm font-medium font-rubik capitalize ltr:rounded-tr-lg ltr:rounded-br-lg rtl:rounded-tl-lg rtl:rounded-bl-lg text-white bg-[#008BBA] disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ $t('button.apply') }}
                </button>
            </div>
            <div class="mt-2" v-if="carts.length > 0">
                <label for="pos-discount-reason" class="flex items-center justify-between mb-1 text-xs font-rubik capitalize text-[#2E2F38]">
                    <span>
                        {{ $t('label.reason') }}
                        <span
                            v-if="discountReasonRequired"
                            class="ml-1 text-[10px] font-medium text-[#FB4E4E] normal-case"
                            data-testid="pos-discount-reason-required-flag"
                        >({{ $t('pos.reason_required_short') }})</span>
                    </span>
                    <span class="text-[10px] font-medium text-[#8E8EA9] normal-case">{{ (discountReason || '').length }}/255</span>
                </label>
                <input id="pos-discount-reason" v-model="discountReason" type="text" maxlength="255"
                    :placeholder="$t('pos.reason_required_placeholder')"
                    data-testid="pos-discount-reason"
                    :class="['w-full h-9 text-sm rounded-lg border px-3 text-heading transition', discountReasonInvalid ? 'border-[#FB4E4E] bg-[#FFF5F5]' : 'border-[#EFF0F6]']">
                <p
                    v-if="discountReasonInvalid"
                    class="mt-1 text-[11px] font-medium text-[#FB4E4E]"
                    role="alert"
                    data-testid="pos-discount-reason-invalid"
                >
                    {{ $t('pos.reason_required_hint') }}
                </p>
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
                        <!--
                          [POS-V4-DENSITY 2026-05-02] The "(HT)" suffix was technically
                          honest under the legacy assumption that catalog prices are
                          stored ex-tax — but that very assumption is now contested
                          (cf. GATE_POS_V4_VAT_HT_TTC_2026-05-02). Until the human
                          gate decides whether catalog prices represent HT or TTC,
                          we drop the suffix here: the receipt remains the fiscal
                          authority and shows the proper TVA breakdown explicitly.
                          This is purely a UI clarification — no pricing math changed.
                        -->
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

    <!-- Panel commandes borne cash (ouvert depuis la barre du haut) -->
    <transition name="slide-panel">
      <div v-if="showKioskCashPanel" class="kiosk-cash-panel-overlay" @click.self="showKioskCashPanel = false">
        <div class="kiosk-cash-panel">
          <div class="kiosk-cash-panel-header">
            <h3>🖥️ Commandes borne — à encaisser</h3>
            <div class="kiosk-cash-panel-header-actions">
                <!--
                  [POS-V4-ORDERS-ACCESS 2026-05-02] Accès direct depuis la caisse vers
                  la liste filtrée historique (status / date / N° / client) sans passer
                  par le menu admin latéral.
                -->
                <router-link
                    :to="{ name: 'admin.pos-orders.list' }"
                    class="kiosk-cash-panel-history-link"
                    :title="$t('pos.orders.history_hint')"
                    data-testid="kiosk-cash-panel-history"
                >
                    <i class="fa-solid fa-list-ul" aria-hidden="true"></i>
                    <span>{{ $t('pos.orders.history') }}</span>
                </router-link>
                <button class="kiosk-cash-panel-close" @click="showKioskCashPanel = false">✕</button>
            </div>
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
                <!--
                  [POS-V4-ORDERS-ACCESS 2026-05-02] Lien direct vers le détail de la
                  commande (lignes, statut, ticket fiscal) — accessible sans quitter
                  l'écran caisse pour vérification ou réimpression.
                -->
                <router-link
                  :to="{ name: 'admin.pos-orders.show', params: { id: order.id } }"
                  class="kiosk-cash-detail-btn"
                  :title="$t('pos.orders.view_detail')"
                  :data-testid="`kiosk-cash-detail-${order.id}`"
                >
                  <i class="fa-solid fa-eye" aria-hidden="true"></i>
                  {{ $t('pos.orders.detail_short') }}
                </router-link>
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
// [POS-V4-CASHIER-OPS 2026-05-02] No-sale / drawer open passes through the
// hardware bridge wrapper. Returns {ok:true} in dev (no real till) and logs
// hardware_event server-side in production for audit trail.
import { openDrawer as kioskHardwareOpenDrawer } from "../../../services/kioskHardware";
import PaymentComponent from "./PaymentComponent.vue";
import ParkedOrdersComponent from "./ParkedOrdersComponent.vue";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
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
            // [POS-V4-ORDERS-TRACKER 2026-05-02] Stats discrètes pour le bouton "Suivi
            // commandes" : `active` = ACCEPT+PREPARING+PREPARED (badge), `ready` =
            // PREPARED uniquement (déclencheur du halo vert). Pas de popup, pas de son
            // ici — l'écran tracker dédié et l'OSS client gèrent les notifications fortes.
            activeOrdersStats: { active: 0, ready: 0 },
            // [POS-V4-CASHIER-OPS 2026-05-02] Guard against double-tap on the
            // no-sale button while the hardware bridge resolves (real till can
            // take ~200-500ms to physically open).
            noSaleBusy: false,
            parkingInFlight: false,
            /** [T12] Item grid skeleton while first POS menu fetch is in flight */
            posItemsFetchPending: false,
            _itemListFetchDepth: 0,
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
        // [POS-V4-CASHIER-OPS 2026-05-02] Discount-with-reason UX guards.
        // Backend rule: any positive POS discount requires a reason ≥3 chars
        // (assertPosManualDiscountAllowed). Mirrored client-side so:
        //  - the apply button is greyed out the instant either constraint is
        //    violated (no surprise alert after click);
        //  - the reason field shows a red border + inline hint as soon as the
        //    cashier types a discount value but skips the reason.
        // Empty discount stays applyable so the cashier can clear an existing
        // discount without re-typing a reason.
        discountAmountValue: function () {
            const raw = this.discount;
            if (raw === '' || raw == null) return 0;
            const n = parseFloat(raw);
            return Number.isFinite(n) ? n : 0;
        },
        discountReasonRequired: function () {
            return this.discountAmountValue > 0;
        },
        discountReasonInvalid: function () {
            if (!this.discountReasonRequired) return false;
            return String(this.discountReason || '').trim().length < 3;
        },
        isDiscountApplyable: function () {
            if (this.discountAmountValue <= 0) return true;
            return !this.discountReasonInvalid;
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
        /**
         * [POS-WIZARD-DRINKS 2026-05-02] Catalogue boissons — symétrie POS↔borne.
         *
         * Source : `posCategory/lists` (déjà branch-scoped) + `item/lists` (déjà branch-scoped
         * par RouteServiceProvider via auth user). Détection catégorie identique à
         * `KioskStepMenuComponent.isDrinkCategory` (regex sur name+slug). Le wizard JS shim
         * reçoit ce catalogue via attribut DOM (`data-pos-drinks-catalog`) sur la modal racine
         * et l'utilise comme priorité 1 pour reconnaître les addons boisson, plus permettre
         * une cross-reference par item_id ou nom — au-delà de la regex keywords legacy.
         *
         * Invariants respectés :
         * - Backend pricing SSOT : aucun prix calculé ici, juste id/name/thumb pour affichage.
         * - branch_id : items et catégories déjà filtrés par le backend selon l'utilisateur.
         * - Pas de mutation, lecture-only.
         */
        drinksCatalog: function () {
            const allCats = this.$store.getters["posCategory/lists"] || [];
            const drinkCatRegex = /\b(boisson|boissons|drink|drinks|soda|sodas|beverage|beverages)\b/i;
            const drinkCategoryIds = new Set(
                allCats
                    .filter(function (c) {
                        const haystack = String(c.name || '') + ' ' + String(c.slug || '');
                        return drinkCatRegex.test(haystack);
                    })
                    .map(function (c) { return String(c.id); })
            );
            if (drinkCategoryIds.size === 0) return [];
            const allItems = this.$store.getters["item/lists"] || [];
            const seen = new Set();
            const out = [];
            for (let i = 0; i < allItems.length; i++) {
                const it = allItems[i];
                if (!it) continue;
                if (it.is_available === false) continue;
                const status = Number(it.status);
                if (status === 0 || status === 2 || status === 10) continue;
                const catId = String(it.item_category_id != null ? it.item_category_id : (it.category_id != null ? it.category_id : ''));
                if (catId === '' || !drinkCategoryIds.has(catId)) continue;
                const idRaw = it.id != null ? it.id : (it.item_id != null ? it.item_id : null);
                if (idRaw == null) continue;
                const idKey = String(idRaw);
                if (seen.has(idKey)) continue;
                seen.add(idKey);
                out.push({
                    id: typeof idRaw === 'number' ? idRaw : (Number(idRaw) || idRaw),
                    name: String(it.name || it.item_name || ''),
                    thumb: it.thumb || it.image || '',
                    category_id: it.item_category_id != null ? it.item_category_id : (it.category_id != null ? it.category_id : null),
                    is_available: it.is_available !== false,
                });
            }
            return out;
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
            this.itemList(1, { overlay: false });
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
        const bootstrapBranchId = this.authBranchId();
        if (bootstrapBranchId) {
            this.applyPosBranchScope(bootstrapBranchId);
        }
        this.itemList();
        this.loadKioskCashOrders();
        this.loadActiveOrdersStats();
        this._subscribeEcho();
        this._startKioskPolling();
        this._bindWsService();
        this._startPosSyncFallback();
        try {
            this.loading.isActive = true;
            this.$store.dispatch("defaultAccess/show").then((res) => {
                const previousBranchId = this.props.search.branch_id;
                const branchId = this.resolveDefaultAccessBranchId(res);
                if (branchId) {
                    this.applyPosBranchScope(branchId);
                    this.loadBranchLocation(branchId);
                    this._startPosSyncFallback();
                    if (previousBranchId !== branchId) {
                        this.itemList();
                    } else {
                        this.loading.isActive = false;
                    }
                } else {
                    this.loading.isActive = false;
                }

            }).catch((err) => {
                const previousBranchId = this.props.search.branch_id;
                const fallbackBranchId = this.authBranchId();
                if (fallbackBranchId) {
                    this.applyPosBranchScope(fallbackBranchId);
                    this._startPosSyncFallback();
                    if (previousBranchId !== fallbackBranchId) {
                        this.itemList();
                    }
                } else {
                    this.loading.isActive = false;
                }
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
            const authInfo = this.$store.getters['auth/authInfo'] || {};
            const candidates = [
                this.$store.getters['auth/authBranchId'],
                authInfo.branch_id,
                this.$store.getters.authBranchId,
                this.$store.state?.auth?.authBranchId,
                this.$store.state?.auth?.authInfo?.branch_id,
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

        resolveDefaultAccessBranchId(response) {
            const raw = response?.data?.data?.branch_id;
            if (raw !== '' && raw !== null && typeof raw !== 'undefined') {
                const value = parseInt(raw, 10);
                if (Number.isFinite(value) && value > 0) {
                    return value;
                }
            }

            return this.authBranchId();
        },

        applyPosBranchScope(branchId) {
            const value = parseInt(branchId, 10);
            if (!Number.isFinite(value) || value <= 0) {
                return null;
            }

            this.checkoutProps.form.branch_id = value;
            this.props.search.branch_id = value;

            try {
                const authInfo = this.$store.getters['auth/authInfo'] || {};
                this.$store.dispatch('posCart/setScope', {
                    branchId: value,
                    userId: authInfo.id || null,
                });
            } catch (e) { /* defensive: never block POS bootstrap */ }

            return value;
        },

        loadBranchLocation(branchId) {
            const value = parseInt(branchId, 10);
            if (!Number.isFinite(value) || value <= 0) {
                return;
            }

            this.$store.dispatch("frontendBranch/show", value).then(res => {
                this.location = {
                    lat: res.data.data.latitude,
                    lng: res.data.data.longitude
                };
            }).catch(() => {});
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
            this._kioskPollTimer = setInterval(() => {
                this.loadKioskCashOrders();
                // [POS-V4-ORDERS-TRACKER 2026-05-02] Polling unifié pour le badge tracker.
                this.loadActiveOrdersStats();
            }, this._kioskPollingInterval());
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
                            // [POS-V4-ORDERS-TRACKER 2026-05-02] sync badge tracker
                            this.loadActiveOrdersStats();
                        },
                    },
                    {
                        broadcastAs: 'OrderStatusChanged',
                        handler: () => {
                            this.loadKioskCashOrders();
                            this.loadActiveOrdersStats();
                        },
                    },
                    {
                        broadcastAs: 'OrderPaidAtCounter',
                        handler: () => {
                            this.loadKioskCashOrders();
                            this.loadActiveOrdersStats();
                        },
                    },
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

            try { this.itemList(1, { overlay: false }); } catch (e) { /* defensive */ }
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
                    try { this.itemList(1, { overlay: false }); } catch (e) { /* defensive */ }
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
                try { this.itemList(1, { overlay: false }); } catch (e) { /* defensive */ }
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

        // ── Suivi commandes (badge tracker caisse) ────────────────────────
        // [POS-V4-ORDERS-TRACKER 2026-05-02]
        // Lecture-only. Source : `admin/oss-order` (OSS endpoint déjà branch-scoped
        // côté backend). On compte ACCEPT (4) + PREPARING (7) + PREPARED (8) pour le
        // badge total, et PREPARED seul pour le halo vert. En cas d'erreur on retombe
        // silencieusement à 0/0 — le tracker plein écran reste accessible quand même.
        async loadActiveOrdersStats() {
            try {
                const res = await this.$store.dispatch('orderStatusScreenOrder/lists');
                const list = (res?.data?.data) || this.$store.getters['orderStatusScreenOrder/lists'] || [];
                let active = 0;
                let ready = 0;
                for (let i = 0; i < list.length; i++) {
                    const s = parseInt(list[i].status ?? list[i].order_status ?? 0, 10);
                    if (s === orderStatusEnum.ACCEPT || s === orderStatusEnum.PREPARING) active += 1;
                    else if (s === orderStatusEnum.PREPARED) { active += 1; ready += 1; }
                }
                this.activeOrdersStats = { active, ready };
            } catch (e) {
                // Silencieux — pas de toast (le caissier n'a pas besoin de bruit ici).
                this.activeOrdersStats = { active: 0, ready: 0 };
            }
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
            this.itemList(1, { overlay: false });
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
            this.itemList(1, { overlay: false });
        },
        allCategory: function () {
            this.props.search.name = "";
            this.props.search.item_category_id = "";
            this.itemList(1, { overlay: false });
        },
        closeSidebar: function () {
            this.$store.dispatch("globalState/set", { topSidebar: false });
            document?.querySelector(".db-sidebar")?.classList?.add("active");
            document?.querySelector(".db-main")?.classList?.add("expand");
        },
        itemCategories: function (page = 1) {
            // No fullscreen overlay — runs in parallel with itemList on mount; overlay was confusing with menu fetch.
            this.props.search.page = page;
            this.$store.dispatch("posCategory/lists", this.categoryProps).then(() => {}).catch(() => {});
        },
        /**
         * Load POS menu items. Use `{ overlay: false }` for category/search/filter changes so the
         * fullscreen spinner is not shown; the previous grid stays visible until the new list arrives.
         */
        itemList: function (page = 1, opts) {
            const options = opts != null && typeof opts === 'object' ? opts : {};
            const showOverlay = options.overlay !== false;

            if (showOverlay) {
                this.loading.isActive = true;
            }

            this._itemListFetchDepth = (this._itemListFetchDepth || 0) + 1;
            this.posItemsFetchPending = true;

            this.props.search.page = page;

            const finish = () => {
                this._itemListFetchDepth = Math.max(0, (this._itemListFetchDepth || 1) - 1);
                if (this._itemListFetchDepth === 0) {
                    this.posItemsFetchPending = false;
                }
                if (showOverlay) {
                    this.loading.isActive = false;
                }
            };

            this.$store.dispatch("item/lists", this.props.search).then(() => {
                finish();
            }).catch(() => {
                finish();
            });
        },
        setCategory: function (id) {
            this.props.search.name = "";
            this.props.search.item_category_id = id;
            this.itemList(1, { overlay: false });
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
        // [POS-V4-CASHIER-OPS 2026-05-02] Cancel the most recently added cart line.
        // Reuses the existing deleteCartItem mutation; no new store contract needed.
        cancelLastCartLine: function () {
            const lines = this.$store.getters['posCart/lists'] || [];
            if (lines.length === 0) {
                return;
            }
            const lastIndex = lines.length - 1;
            const lastLine = lines[lastIndex];
            this.$store.dispatch('posCart/deleteCartItem', { id: lastIndex, status: 'decrement' })
                .then(() => {
                    const label = (lastLine && lastLine.name) ? lastLine.name : '';
                    alertService.info(label
                        ? this.$t('pos.cancel_last_line_done_named', { name: label })
                        : this.$t('pos.cancel_last_line_done'));
                })
                .catch(() => {
                    alertService.error(this.$t('pos.cancel_last_line_error'));
                });
        },
        // [POS-V4-CASHIER-OPS 2026-05-02] No-sale / open drawer.
        // No order is created. Backend audit trail comes from the hardware
        // bridge (reportHardwareEvent) — we don't double-log here. We also
        // surface a tiny success/info toast so the cashier sees feedback even
        // when the dev stub returns immediately.
        triggerNoSaleOpenDrawer: async function () {
            if (this.noSaleBusy) {
                return;
            }
            this.noSaleBusy = true;
            try {
                const result = await Promise.resolve(kioskHardwareOpenDrawer());
                if (result && result.ok === false) {
                    alertService.error(this.$t('pos.no_sale_error'));
                } else {
                    alertService.info(this.$t('pos.no_sale_done'));
                }
            } catch (e) {
                alertService.error(this.$t('pos.no_sale_error'));
            } finally {
                this.noSaleBusy = false;
            }
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
  /* [POS-V4-DENSITY 2026-05-02] Tightened to match the slimmer admin header
     (.db-header now py-2 instead of p-4 -> ~64px). The previous 85px offset
     left a ~21px dead band above the operator bar that ate vertical space
     for nothing — cashier wants product grid taller, not whitespace. */
  min-height: calc(100dvh - 64px);
  margin: -4px -8px 0 -8px;
  padding: 4px 10px 12px 8px;
  box-sizing: border-box;
  max-width: 100vw;
  overflow-x: hidden;
  background:
    linear-gradient(180deg, rgba(20, 24, 33, 0.04), rgba(20, 24, 33, 0)),
    var(--pos-v4-bg);
  color: var(--pos-v4-ink);
}

.pos-v4-main {
  padding: 0 8px 16px 0;
  min-height: 0;
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

/* [POS-V4-CASHIER-OPS 2026-05-02] no-sale button — neutral, low-noise */
.pos-v4-no-sale-btn {
  min-height: 46px;
  font-weight: 700;
  white-space: nowrap;
}
.pos-v4-no-sale-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.pos-v4-no-sale-btn i {
  color: var(--pos-v4-red, #FB4E4E);
}

/* [POS-V4-DENSITY 2026-05-02] Search bar slimmed from 48px back to its
   declared 38px (the 48px override was a leftover from an earlier visual
   pass and ate ~10px of product-grid real estate for no functional gain).
   Margin-bottom reduced from mb-4 (16px) to mb-2 (8px) below via inline
   class — cf. template. */
.pos-v4-search {
  height: 38px !important;
  border: 1px solid var(--pos-v4-border) !important;
  border-radius: 12px !important;
  box-shadow: 0 6px 14px rgba(20, 24, 33, 0.06);
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

/* [POS-V4-DENSITY 2026-05-02] Pill height tightened from 108px to 76px;
   shadow softened so the strip reads as a compact navigation row, not a
   second hero band above the products. */
.pos-v4-category-card,
.pos-v4-category-pill {
  min-height: 76px;
  border-color: transparent !important;
  box-shadow: 0 4px 12px rgba(20, 24, 33, 0.06);
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

/* Category strip: native overflow scroll (replaced Swiper — no 1s slide animation) */
.pos-menu-category-scroll {
  -webkit-overflow-scrolling: touch;
  scrollbar-width: thin;
  scroll-behavior: auto;
}

.pos-menu-category-scroll::-webkit-scrollbar {
  height: 6px;
}

.pos-menu-category-scroll::-webkit-scrollbar-thumb {
  border-radius: 6px;
  background: rgba(20, 24, 33, 0.22);
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

  .pos-v4-operator-actions {
    width: 100%;
    flex-direction: column;
    align-items: stretch;
  }

  .kiosk-cash-bar-btn {
    width: 100%;
    max-width: none;
    justify-content: flex-start;
  }

  .pos-v4-floorplan-link {
    width: 100%;
    justify-content: center;
  }
}

/* ── Borne cash : bouton dans la barre opérateur (remplace l’ancien FAB bas-droite) ── */
.kiosk-cash-bar-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.5rem 0.85rem 0.5rem 0.65rem;
  border-radius: 14px;
  border: 2px solid rgba(255, 255, 255, 0.95);
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  cursor: pointer;
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.2);
  max-width: min(100%, 320px);
  text-align: left;
  animation: kiosk-bar-pulse 2.2s ease-in-out infinite;
}
.kiosk-cash-bar-btn:hover {
  background: rgba(255, 255, 255, 0.26);
}
@keyframes kiosk-bar-pulse {
  0%, 100% { box-shadow: 0 4px 18px rgba(0, 0, 0, 0.2); }
  50% { box-shadow: 0 4px 22px rgba(255, 255, 255, 0.35); }
}
.kiosk-cash-bar-btn-icon {
  font-size: 1.35rem;
  flex-shrink: 0;
  line-height: 1;
}
.kiosk-cash-bar-btn-text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 0.1rem;
  min-width: 0;
}
.kiosk-cash-bar-btn-label {
  font-weight: 900;
  font-size: clamp(0.8rem, 1.1vw, 0.95rem);
  letter-spacing: 0.02em;
  line-height: 1.15;
}
.kiosk-cash-bar-btn-sub {
  font-size: 0.68rem;
  font-weight: 700;
  opacity: 0.9;
  line-height: 1.2;
}
.kiosk-cash-bar-btn-badge {
  flex-shrink: 0;
  min-width: 1.85rem;
  height: 1.85rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: #fff;
  color: #e8001c;
  font-weight: 900;
  font-size: 1rem;
  display: flex;
  align-items: center;
  justify-content: center;
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
.kiosk-cash-panel-header-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.6rem;
}
.kiosk-cash-panel-history-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.7rem;
  border-radius: 8px;
  border: 1px solid #EFF0F6;
  background: #ffffff;
  color: #1F1F39;
  font-size: 0.78rem;
  font-weight: 700;
  text-decoration: none;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.kiosk-cash-panel-history-link:hover {
  background: #FFEDF4;
  border-color: #B0004D;
  color: #B0004D;
}
.kiosk-cash-panel-close {
  background: none; border: none; font-size: 1.1rem;
  cursor: pointer; color: #888; padding: 0.25rem;
}
.kiosk-cash-detail-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 5px 10px;
  border-radius: 8px;
  border: 1px solid #EFF0F6;
  background: #ffffff;
  color: #1F1F39;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.kiosk-cash-detail-btn:hover {
  background: #FFEDF4;
  border-color: #B0004D;
  color: #B0004D;
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
.slide-panel-enter-active, .slide-panel-leave-active { transition: opacity 0.25s; }
.slide-panel-enter-from, .slide-panel-leave-to { opacity: 0; }
.slide-panel-enter-active .kiosk-cash-panel,
.slide-panel-leave-active .kiosk-cash-panel { transition: transform 0.3s ease; }
.slide-panel-enter-from .kiosk-cash-panel,
.slide-panel-leave-to .kiosk-cash-panel { transform: translateX(100%); }

/* ── [POS-V4-ORDERS-TRACKER 2026-05-02] Bouton suivi commandes ────────────
   Discret par défaut (bord neutre), tourne vert avec halo respirant dès
   qu'une commande passe à PREPARED. Aucun popup, aucun son — juste un
   signal visuel pour que le caissier sache, sans être interrompu pendant
   une prise de commande en cours. */
.pos-tracker-bar-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.4rem 0.8rem 0.4rem 0.6rem;
  border-radius: 12px;
  border: 1px solid #EFF0F6;
  background: #ffffff;
  color: #1F1F39;
  cursor: pointer;
  text-align: left;
  transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
  position: relative;
}

.pos-tracker-bar-btn:hover {
  background: #FFEDF4;
  border-color: #B0004D;
}

.pos-tracker-bar-btn-icon {
  font-size: 1.15rem;
  line-height: 1;
  flex-shrink: 0;
}

.pos-tracker-bar-btn-text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.05rem;
  min-width: 0;
}

.pos-tracker-bar-btn-label {
  font-size: 0.82rem;
  font-weight: 700;
  line-height: 1.15;
}

.pos-tracker-bar-btn-sub {
  font-size: 0.65rem;
  font-weight: 600;
  opacity: 0.7;
  line-height: 1.2;
}

.pos-tracker-bar-btn-badge {
  flex-shrink: 0;
  min-width: 1.6rem;
  height: 1.6rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  background: #F1F5F9;
  color: #1F1F39;
  font-weight: 800;
  font-size: 0.78rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.pos-tracker-bar-btn.is-ready {
  background: #DCFCE7;
  border-color: #1AB759;
  color: #14532D;
  animation: pos-tracker-bar-glow 2.6s ease-in-out infinite;
}

.pos-tracker-bar-btn.is-ready:hover {
  background: #BBF7D0;
  border-color: #15A151;
}

.pos-tracker-bar-btn.is-ready .pos-tracker-bar-btn-badge {
  background: #1AB759;
  color: #ffffff;
}

@keyframes pos-tracker-bar-glow {
  0%, 100% { box-shadow: 0 0 0 0 rgba(26, 183, 89, 0); }
  50%      { box-shadow: 0 0 0 6px rgba(26, 183, 89, 0.18); }
}

@media (prefers-reduced-motion: reduce) {
  .pos-tracker-bar-btn.is-ready { animation: none; }
}

@media (max-width: 767px) {
  .pos-tracker-bar-btn {
    width: 100%;
    justify-content: flex-start;
  }
  .pos-tracker-bar-btn-text { flex: 1; }
}
</style>
