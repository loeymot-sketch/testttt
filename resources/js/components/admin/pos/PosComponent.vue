<template>
    <!--
      [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Refonte design POS V5 "warm premium".
      - Conserve la classe `fk-pos-v4` pour rollback éventuel via [data-pos-v4-disabled].
      - Ajoute la classe `pos-v5-shell` qui active la typo Inter, le bg crème warm
        et le système de tokens unifié défini dans foundations/pos-v5-tokens.css.
      - Le wizard kiosk reste FROZEN (KioskWizardComponent + kiosk-wizard.css).
      - Aucune logique métier touchée : pricing, OrderStatus, branch_id, dispatch
        intacts. Cf. plans/PLAN_POS_V5_DESIGN_CONVERGENCE_2026-05-02.md.
    -->
    <section class="pos-v5-shell fk-pos-v4 pos-v4-shell" data-pos-v4-shell data-pos-v5-shell>
    <a href="#pos-cart" class="pos-v5-skip-link sr-only focus:not-sr-only">{{ $t('a11y.skip_to_cart') }}</a>
    <div id="pos-a11y-live" class="sr-only pos-v5-sr-only" aria-live="polite" aria-atomic="true"></div>
    <ConnectionStatusBanner suppress-transient suppress-session-invalid />
    <LoadingComponent :props="loading" />

    <div class="pos-v4-main md:w-[calc(100%-316px)] lg:w-[calc(100%-302px)] xl:w-[calc(100%-376px)]">
        <header class="pos-v5-operator-bar pos-v4-operator-bar" role="banner">
            <div class="pos-v5-operator-bar__brand">
                <div class="pos-v5-operator-bar__crown" aria-hidden="true">👑</div>
                <div class="pos-v5-operator-bar__identity min-w-0 flex-1">
                    <p class="pos-v5-operator-bar__eyebrow pos-v4-eyebrow">Caisse FoodKing</p>
                    <h1 class="pos-v5-operator-bar__title pos-v4-title">Commande rapide</h1>
                    <div class="pos-v5-operator-bar__live pos-v4-status-row">
                        <PosV5StatChip
                            v-if="checkoutProps.form.branch_id"
                            :label="$t('label.branch')"
                            :value="`#${checkoutProps.form.branch_id}`"
                            tone="neutral"
                        />
                        <PosV5StatChip
                            v-else
                            :label="$t('label.ready')"
                            tone="ghost"
                        />
                        <PosV5StatChip
                            :label="$t('label.items')"
                            :value="totalItems()"
                            :tone="totalItems() > 0 ? 'brand' : 'neutral'"
                            :class="{ 'is-bumping': cartBumping }"
                            data-testid="pos-cart-stat-chip"
                        />
                    </div>
                </div>
            </div>
            <nav class="pos-v5-operator-bar__actions pos-v4-operator-actions" aria-label="Actions caisse">
                <PosV5Button
                    v-if="kioskCashOrders.length > 0"
                    variant="kiosk-cash"
                    size="md"
                    data-testid="kiosk-cash-open"
                    :badge="kioskCashOrders.length"
                    :title="$t('pos.kiosk_counter_collect_hint')"
                    @click="showKioskCashPanel = true"
                >
                    <template #icon>🖥️</template>
                    <span class="hidden lg:inline">{{ $t('pos.kiosk_counter_collect_short') }}</span>
                </PosV5Button>
                <!--
                  [POS-V5] Bouton suivi commandes — variant tracker.
                  Tone "ready" = halo vert pulsant dès qu'une commande passe à PREPARED.
                  Aucun popup, aucun son — juste un signal visuel pour le caissier.
                  [POS-V5 R2] Label visible dès lg (1024px) au lieu de xl (1280px) pour
                  réduire le risque d'icônes orphelines sur écrans tablet/laptop courants.
                -->
                <PosV5Button
                    variant="tracker"
                    size="md"
                    as="router-link"
                    :to="{ name: 'admin.pos-orders.tracker' }"
                    :tone="activeOrdersStats.ready > 0 ? 'ready' : 'neutral'"
                    :badge="activeOrdersStats.active > 0 ? activeOrdersStats.active : null"
                    data-testid="pos-tracker-open"
                    :title="$t('pos.tracker.button_hint')"
                >
                    <template #icon>📋</template>
                    <span class="hidden lg:inline">{{ $t('pos.tracker.button_label') }}</span>
                </PosV5Button>
                <PosV5Button
                    variant="ghost"
                    size="md"
                    as="router-link"
                    :to="{ name: 'admin.order-status-screen' }"
                    target="_blank"
                    rel="noopener"
                    :title="$t('pos.tracker.customer_screen_hint')"
                >
                    <template #icon>🖥️</template>
                    <span class="hidden lg:inline">{{ $t('pos.tracker.customer_screen') }}</span>
                </PosV5Button>
                <PosV5Button
                    variant="ghost"
                    size="md"
                    as="router-link"
                    :to="{ name: 'admin.pos.floorplan' }"
                    class="pos-v4-floorplan-link"
                    :title="$t('label.floorplan')"
                >
                    <template #icon>🪑</template>
                    <span class="hidden lg:inline">{{ $t('label.floorplan') }}</span>
                </PosV5Button>
                <!--
                  [POS-V5] No-sale / open drawer — variant ghost neutral (pas de halo).
                  Discoverable mais ne compete jamais avec paiement / tracker.
                  Backend bridge kioskHardware.openDrawer() inchangé.
                -->
                <PosV5Button
                    variant="ghost"
                    size="md"
                    class="pos-v4-no-sale-btn"
                    data-testid="pos-no-sale"
                    :title="$t('pos.no_sale_hint')"
                    :disabled="noSaleBusy"
                    :loading="noSaleBusy"
                    @click="triggerNoSaleOpenDrawer"
                >
                    <template #icon>💵</template>
                    <span class="hidden lg:inline">{{ $t('pos.no_sale') }}</span>
                </PosV5Button>
            </nav>
        </header>
        <!-- [POS-V5] Search V5 — input large unifié, soumission par Enter. -->
        <PosV5SearchInput
            class="pos-v4-search"
            :model-value="props.search.name"
            :placeholder="$t('label.search_by_menu_item')"
            :aria-label="$t('label.search_by_menu_item')"
            :clear-aria-label="$t('button.close')"
            @input="onSearchInput"
            @submit="search"
            @clear="resetName"
        />

        <!--
          [POS-V5] Categories strip warm — pills avec photos rondes 56px
          (mirror direct du stepper visual du wizard kiosk). L'active est marquée
          par un anneau rouge brand 2px + ring soft 4px (mirror exact wizard).
        -->
        <nav
            v-if="categories.length > 1"
            class="pos-v5-category-strip pos-menu-category-scroll pos-v4-category-strip"
            ref="categoryScrollStrip"
            role="tablist"
            aria-label="Catégories"
        >
            <template v-for="(category, index) in categories" :key="category.id || index">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="(index === 0 && currentCategoryId === 0) || (Number(category.id) === currentCategoryId) ? 'true' : 'false'"
                    :class="['pos-v5-category', 'pos-v4-category-pill', {
                        'is-active': (index === 0 && currentCategoryId === 0) || (Number(category.id) === currentCategoryId)
                    }]"
                    @click="index === 0 ? allCategory() : setCategory(category.id)"
                >
                    <span class="pos-v5-category__visual">
                        <img v-if="category.thumb" :src="category.thumb" :alt="category.name || ''" loading="lazy" />
                        <span v-else class="pos-v5-category__visual-fallback" aria-hidden="true">{{ (category.name || '?').charAt(0).toUpperCase() }}</span>
                    </span>
                    <span class="pos-v5-category__label">{{ category.name }}</span>
                </button>
            </template>
        </nav>

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


    <!--
      [POS-V5] Cart panel "ticket vivant" — segments verticaux clairs, header
      avec eyebrow rouge brand + titre Inter Black, customer select + add CTA,
      shortcuts park/parked, loyalty badge optionnel, type de commande en
      segmented control, items list en cards verticales, footer avec totals
      + CTA principal "Encaisser X €" (montant intégré au bouton, Q3 plan).
    -->
    <aside id="pos-cart"
        role="region"
        :aria-label="$t('a11y.cart_region')"
        class="db-pos-cartDiv pos-v4-cart-panel pos-v5-cart fixed top-0 ltr:right-0 rtl:left-0 w-full h-screen rounded-none z-50 md:z-10 md:top-[64px] ltr:md:right-3 rtl:md:left-3 md:w-[340px] lg:w-[360px] xl:w-[400px] md:h-[calc(100dvh-64px)] md:rounded-lg flex flex-col overflow-hidden bg-white">
        <!-- Mobile-only close button -->
        <div class="md:hidden text-right p-3 pb-0">
            <button type="button" class="db-pos-cartCls pos-v5-modal__close" @click="closeCanvas('pos-cart')"
                :aria-label="$t('button.close')">
                <span aria-hidden="true">✕</span>
            </button>
        </div>

        <!-- Cart header -->
        <header class="pos-v5-cart__head pos-v4-cart-head flex-shrink-0">
            <div class="pos-v5-cart__head-titles">
                <p class="pos-v5-cart__eyebrow">Ticket caisse</p>
                <h2 class="pos-v5-cart__title">Commande en cours</h2>
            </div>
            <div class="pos-v5-stack-3 mt-3">
                <PosV5Pill
                    v-if="totalItems() > 0"
                    variant="brand"
                    size="md"
                >
                    {{ totalItems() }} {{ $t('label.items') }}
                </PosV5Pill>

                <!-- Customer selector + add CTA -->
                <div class="flex items-center w-full gap-2">
                    <div class="db-field flex-grow">
                        <vue-select
                            class="db-field-control text-sm rounded-lg appearance-none text-heading border-[#D9DBE9]"
                            id="customer" v-model="checkoutProps.form.customer_id" :options="customers"
                            @update:modelValue="changingUser" label-by="name" value-by="id" :closeOnSelect="true"
                            :searchable="true" :clearOnClose="true" :placeholder="$t('label.select_customer')"
                            :search-placeholder="$t('label.search_customer')" />
                    </div>
                    <PosV5Button
                        variant="primary"
                        size="md"
                        :aria-label="$t('button.add_customer')"
                        data-modal="#addCustomer"
                        @click.prevent="addCustomers"
                    >
                        <template #icon>+</template>
                    </PosV5Button>
                </div>

                <!-- Park / Parked shortcuts -->
                <div class="grid grid-cols-2 gap-2">
                    <PosV5Button
                        variant="secondary"
                        size="md"
                        :disabled="parkingInFlight"
                        :loading="parkingInFlight"
                        @click="promptParkOrder"
                    >
                        <template #icon>⏸</template>
                        {{ $t('pos.park') }}
                    </PosV5Button>
                    <PosV5Button
                        variant="ghost-counter"
                        size="md"
                        :badge="parkedOrdersCount"
                        @click="openParkedOrders"
                    >
                        <template #icon>📦</template>
                        {{ $t('pos.parked_orders') }}
                    </PosV5Button>
                </div>
            </div>
            <!--
              [POS-V5] Cancel last cart line — undo subtil, pas destructif.
              "Oops" en un tap (cf. POS-V4-CASHIER-OPS 2026-05-02 doctrine).
            -->
            <div v-if="carts.length > 0" class="mt-3">
                <PosV5Button
                    variant="ghost"
                    size="sm"
                    block
                    data-testid="pos-cancel-last-line"
                    @click="cancelLastCartLine"
                >
                    <template #icon>↻</template>
                    {{ $t('pos.cancel_last_line') }}
                </PosV5Button>
            </div>

            <!-- [POS-V5] Loyalty badge — warm gold/amber chaleureux. -->
            <div v-if="selectedCustomerLoyalty.code" class="pos-v5-loyalty mt-3" role="status">
                <span class="pos-v5-loyalty__icon" aria-hidden="true">⭐</span>
                <span>
                    <span v-if="selectedCustomerLoyalty.loading">...</span>
                    <template v-else>
                        <span class="pos-v5-loyalty__points">{{ selectedCustomerLoyalty.points ?? 0 }}</span> pts fidélité
                        <span class="opacity-80 ml-1">({{ selectedCustomerLoyalty.code }})</span>
                    </template>
                </span>
            </div>

            <!--
              [POS-V5] Order type — segmented control V5 (mirror du wizard
              "Sur place / À emporter" pattern). Le delivery inline form reste
              identique pour ne pas casser la logique d'autocomplete d'adresse.
            -->
            <fieldset class="mt-3">
                <legend class="pos-v5-cart__eyebrow mb-2">{{ $t('label.select_order_type') }}</legend>

                <div class="pos-v5-segmented" role="radiogroup">
                    <!-- [POS-9.1.6] Dine-In gated by feature flag `pos.dine_in_enabled` (default false). -->
                    <label
                        v-if="dineInEnabled"
                        ref="dineIn"
                        for="dinein"
                        data-dine="#dine"
                        :class="['pos-v5-segmented__item', { 'is-active': checkoutProps.form.order_type === orderTypeEnums.dineIn }]"
                        @click="dineInOrder"
                    >
                        <input ref="dineInInput" type="radio" id="dinein" name="orderType"
                            :value="orderTypeEnums.dineIn" v-model="checkoutProps.form.order_type" />
                        <span aria-hidden="true">🍽️</span>
                        <span>{{ $t('label.dine_in') }}</span>
                    </label>

                    <label
                        ref="takeAway"
                        for="takeway"
                        :class="['pos-v5-segmented__item', { 'is-active': checkoutProps.form.order_type === orderTypeEnums.takeAway }]"
                        @click="takeAwayOrder"
                    >
                        <input ref="takeAwayInput" type="radio" id="takeway" name="orderType"
                            :value="orderTypeEnums.takeAway" v-model="checkoutProps.form.order_type" />
                        <span aria-hidden="true">🥡</span>
                        <span>{{ $t('label.takeaway') }}</span>
                    </label>

                    <label
                        ref="deliveryOrderLabel"
                        for="delivery"
                        data-orderdelivery="#orderdelivery"
                        :class="['pos-v5-segmented__item', { 'is-active': checkoutProps.form.order_type === orderTypeEnums.delivery }]"
                        @click="deliveryOrder"
                    >
                        <input ref="deliveryOrderInput" type="radio" id="delivery" name="orderType"
                            :value="orderTypeEnums.delivery" v-model="checkoutProps.form.order_type" />
                        <span aria-hidden="true">🛵</span>
                        <span>{{ $t('label.delivery') }}</span>
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
            </fieldset>
        </header>

        <!--
          [POS-V5] Cart body — cart lines as vertical cards.
          Avoid role="list" here: axe flags aria-required-children when the cart
          is empty or mid-hydrate (list with no listitem). Region + item names
          keep screen-reader context via headings / buttons.
        -->
        <div class="pos-v5-cart__body flex-1 min-h-0 overflow-y-auto thin-scrolling" role="region" :aria-label="$t('a11y.cart_region')">
            <article
                v-for="(cart, index) in carts"
                :key="`cart-${index}`"
                class="pos-v5-cart-item"
            >
                <button
                    type="button"
                    class="pos-v5-cart-item__visual"
                    @click.prevent="editCartLine(index)"
                    :title="$t('button.edit') || 'Modifier'"
                    :aria-label="$t('button.edit')"
                >
                    <img v-if="cart.image" :src="cart.image" :alt="cart.name" />
                    <span v-else class="pos-v5-cart-item__visual-fallback" aria-hidden="true">🍴</span>
                </button>
                <div class="pos-v5-cart-item__body">
                    <h3 class="pos-v5-cart-item__name">
                        <span>{{ cart.name }}</span>
                        <button
                            type="button"
                            class="pos-v5-cart-item__edit"
                            @click.prevent="editCartLine(index)"
                            :title="$t('button.edit') || 'Modifier'"
                            :aria-label="$t('button.edit')"
                        >
                            <span aria-hidden="true">✎</span>
                        </button>
                    </h3>

                    <!-- Wizard cart_display: clean summary (Viandes, Crudités, Sauce, Suppléments) -->
                    <p
                        v-if="cart.cart_display && cart.cart_display.trim()"
                        class="pos-v5-cart-item__detail"
                    >{{ cart.cart_display }}</p>

                    <!-- Fallback for non-wizard products: variations + extras -->
                    <template v-else>
                        <p v-if="formatCartVariationSummary(cart)" class="pos-v5-cart-item__detail">
                            {{ formatCartVariationSummary(cart) }}
                        </p>
                        <p v-if="formatCartExtraSummary(cart)" class="pos-v5-cart-item__detail">
                            {{ $t('label.extras') }}: {{ formatCartExtraSummary(cart) }}
                        </p>
                    </template>

                    <!-- Menu bundled + extras menu (formules) -->
                    <div v-if="cart.pos_line_addons && cart.pos_line_addons.length > 0" class="pos-v5-cart-item__bundled">
                        <div v-for="(bundled, bi) in cart.pos_line_addons" :key="'b-' + index + '-' + bi" class="pos-v5-cart-item__bundled-line">
                            <span>+ {{ bundled.name }}</span>
                            <span v-if="bundledLineUnitTotal(bundled) > 0" class="pos-v5-tabular">
                                (+{{
                                    currencyFormat(bundledLineUnitTotal(bundled) * (parseInt(bundled.quantity, 10) || 1) * cart.quantity,
                                        setting.site_digit_after_decimal_point,
                                        setting.site_default_currency_symbol, setting.site_currency_position)
                                }})
                            </span>
                            <ul v-if="bundled.menu_extras && bundled.menu_extras.length > 0" class="w-full m-0 p-0 list-none ml-3 mt-0.5">
                                <li
                                    v-for="(extra, ei) in bundled.menu_extras"
                                    :key="'me-' + index + '-' + bi + '-' + ei"
                                    class="text-[10px] leading-snug text-[var(--pos-v5-ink-muted)] flex items-center gap-1"
                                >
                                    <span class="text-[color:var(--pos-v5-success)] font-bold" aria-hidden="true">↳</span>
                                    <span>{{ extra }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="pos-v5-cart-item__price pos-v5-tabular">
                    {{
                        currencyFormat(cart.total, setting.site_digit_after_decimal_point,
                            setting.site_default_currency_symbol, setting.site_currency_position)
                    }}
                </div>
                <div class="pos-v5-cart-item__qty">
                    <PosV5QtyStepper
                        size="sm"
                        :model-value="cart.quantity"
                        :show-trash="cart.quantity === 1"
                        :aria-label="cart.name"
                        :increment-aria-label="$t('a11y.increase_qty', { item: cart.name })"
                        :decrement-aria-label="$t('a11y.decrease_qty', { item: cart.name })"
                        :remove-aria-label="$t('a11y.remove_item', { item: cart.name })"
                        @increment="cartQuantityIncrement(index)"
                        @decrement="cartQuantityDecrement(index)"
                        @remove="cartQuantityDecrement(index)"
                    />
                </div>
            </article>

            <div v-if="carts.length === 0" class="pos-v5-cart__empty">
                <span class="pos-v5-cart__empty-icon" aria-hidden="true">🍽️</span>
                <p>Aucun article. Sélectionnez un produit dans la grille.</p>
            </div>
        </div>
        <!--
          [POS-V5] Cart footer — discount block + totals (PosV5TotalRow) +
          CTA principal "Encaisser X €" (Q3 plan : montant intégré au bouton).
        -->
        <footer class="pos-v5-cart__foot pos-v4-cart-footer flex-shrink-0">
            <!-- Discount block -->
            <div v-if="carts.length > 0" class="flex h-9 mb-2">
                <div class="dropdown-group">
                    <button
                        type="button"
                        class="flex items-center justify-start w-[100px] h-full text-xs font-medium rounded-l-md appearance-none border pl-3 text-[var(--pos-v5-ink)] border-[var(--pos-v5-border)] bg-[var(--pos-v5-bg-subtle)] dropdown-btn"
                    >
                        <span class="flex-1 text-start" v-if="discountType === discountTypeEnum.PERCENTAGE">{{
                            $t("label.percentage") }}</span>
                        <span class="flex-1 text-start" v-else>{{ $t("label.fixed") }}</span>
                        <i class="lab lab-arrow-down-2 lab-font-size-17 mx-1"></i>
                    </button>
                    <ul
                        class="p-2 rounded-lg shadow-xl absolute top-10 ltr:right-0 rtl:left-0 z-10 bg-white transition-all duration-300 origin-top scale-y-0 dropdown-list w-full"
                    >
                        <li
                            class="flex items-center gap-2 py-1 px-2.5 rounded-md cursor-pointer hover:bg-[var(--pos-v5-brand-red-soft)]"
                            v-for="option in [
                                { name: $t('label.percentage'), value: discountTypeEnum.PERCENTAGE },
                                { name: $t('label.fixed'), value: discountTypeEnum.FIXED }
                            ]"
                            :key="option.value"
                            @click="selectDiscount(option.value)"
                        >
                            <span class="text-[var(--pos-v5-ink)] capitalize text-sm">{{ option.name }}</span>
                        </li>
                    </ul>
                </div>
                <input
                    v-on:keypress="floatNumber($event)"
                    v-model="discount"
                    type="text"
                    :placeholder="$t('label.add_discount')"
                    data-testid="pos-discount-input"
                    class="w-full h-full border-t border-b px-3 text-sm border-[var(--pos-v5-border)] focus:outline-none focus:border-[var(--pos-v5-brand-red)]"
                />
                <!--
                  [POS-V4-CASHIER-OPS 2026-05-02] Apply button disabled when a
                  positive discount is set without a 3+ char reason. Backend
                  enforces this (OrderService L2007-2011) — mirror client-side.
                -->
                <button
                    @click.prevent="applyDiscount"
                    type="button"
                    :disabled="!isDiscountApplyable"
                    :aria-disabled="!isDiscountApplyable"
                    data-testid="pos-discount-apply"
                    class="flex-shrink-0 w-16 h-full text-xs font-bold uppercase rounded-r-md text-white bg-[var(--pos-v5-info)] disabled:opacity-50 disabled:cursor-not-allowed transition"
                >
                    {{ $t('button.apply') }}
                </button>
            </div>

            <div v-if="carts.length > 0" class="mb-3">
                <label
                    for="pos-discount-reason"
                    class="flex items-center justify-between mb-1 text-[11px] font-bold uppercase tracking-wider text-[var(--pos-v5-ink-soft)]"
                >
                    <span>
                        {{ $t('label.reason') }}
                        <span
                            v-if="discountReasonRequired"
                            class="ml-1 text-[10px] font-bold text-[var(--pos-v5-danger)] normal-case"
                            data-testid="pos-discount-reason-required-flag"
                        >({{ $t('pos.reason_required_short') }})</span>
                    </span>
                    <span class="text-[10px] font-medium text-[var(--pos-v5-ink-muted)] normal-case">{{ (discountReason || '').length }}/255</span>
                </label>
                <input
                    id="pos-discount-reason"
                    v-model="discountReason"
                    type="text"
                    maxlength="255"
                    :placeholder="$t('pos.reason_required_placeholder')"
                    data-testid="pos-discount-reason"
                    :class="['w-full h-9 text-sm rounded-md border px-3 text-[var(--pos-v5-ink)] transition focus:outline-none focus:border-[var(--pos-v5-brand-red)]', discountReasonInvalid ? 'border-[var(--pos-v5-danger)] bg-[var(--pos-v5-danger-soft)]' : 'border-[var(--pos-v5-border)]']"
                />
                <p
                    v-if="discountReasonInvalid"
                    class="mt-1 text-[11px] font-medium text-[var(--pos-v5-danger)]"
                    role="alert"
                    data-testid="pos-discount-reason-invalid"
                >
                    {{ $t('pos.reason_required_hint') }}
                </p>
            </div>

            <!-- Totals block -->
            <div role="status" aria-live="polite" aria-atomic="true" class="mb-3">
                <PosV5TotalRow :label="$t('label.sub_total')" :value="subtotalDisplay" />
                <PosV5TotalRow
                    v-if="posDiscount"
                    :label="$t('label.discount')"
                    :value="posDiscountDisplay"
                    tone="muted"
                    sign="-"
                />
                <PosV5TotalRow
                    v-if="checkoutProps.form.delivery_charge"
                    :label="$t('label.delivery_charge')"
                    :value="deliveryChargeDisplay"
                    tone="info"
                    sign="+"
                />
                <PosV5TotalRow
                    :label="$t('label.total')"
                    :value="grandTotalDisplay"
                    tone="hero"
                    :class="['pos-v4-total-row', { 'is-flashing': totalFlashing }]"
                    data-testid="pos-grand-total"
                />
            </div>

            <!-- Action CTAs -->
            <div v-if="carts.length > 0" class="flex flex-col gap-2">
                <PosV5Button
                    variant="primary-pay"
                    size="xl"
                    block
                    @click.prevent="orderSubmit"
                    data-testid="pos-v5-pay"
                    class="pos-v4-action-pay"
                >
                    <template #icon>💳</template>
                    {{ $t('button.order') }} · {{ grandTotalDisplay }}
                </PosV5Button>
                <PosV5Button
                    variant="danger-ghost"
                    size="sm"
                    block
                    @click.prevent="resetCart"
                    class="pos-v4-action-cancel"
                >
                    <template #icon>↻</template>
                    {{ $t('button.cancel') }}
                </PosV5Button>
            </div>
        </footer>
    </aside>


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
        @order:confirmed="triggerSuccessFlash"
    />
    <!-- [POS-V5 WAVE 3] Overlay success flash après confirm payment (700ms) -->
    <div v-if="successFlashing" class="pos-v5-success-flash" aria-hidden="true"></div>
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
// [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Primitives unifiées POS V5.
// Doc plan : plans/PLAN_POS_V5_DESIGN_CONVERGENCE_2026-05-02.md §4.
import PosV5Button from "./v5/PosV5Button.vue";
import PosV5Card from "./v5/PosV5Card.vue";
import PosV5Pill from "./v5/PosV5Pill.vue";
import PosV5StatChip from "./v5/PosV5StatChip.vue";
import PosV5TotalRow from "./v5/PosV5TotalRow.vue";
import PosV5QtyStepper from "./v5/PosV5QtyStepper.vue";
import PosV5SearchInput from "./v5/PosV5SearchInput.vue";

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
        PaymentComponent,
        // [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Primitives V5
        PosV5Button,
        PosV5Card,
        PosV5Pill,
        PosV5StatChip,
        PosV5TotalRow,
        PosV5QtyStepper,
        PosV5SearchInput,
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
            // [POS-V5 WAVE 3 2026-05-02] Flags pour animations cart-bump et
            // total-flash. Toggled via watcher sur totalItems()/subtotal et
            // remis à false après ~320-700ms (durée animation CSS).
            cartBumping: false,
            totalFlashing: false,
            successFlashing: false,
            _cartBumpTimer: null,
            _totalFlashTimer: null,
            _successFlashTimer: null,
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
        // [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Total numérique + display
        // formaté pour CTA "Encaisser X €" (Q3 plan §3.1.6).
        grandTotal: function () {
            const sub = Number(this.subtotal) || 0;
            const delivery = Number(this.checkoutProps?.form?.delivery_charge) || 0;
            const discount = Number(this.posDiscount) || 0;
            return Math.max(0, sub + delivery - discount);
        },
        grandTotalDisplay: function () {
            return this.currencyFormat(
                this.grandTotal,
                this.setting.site_digit_after_decimal_point,
                this.setting.site_default_currency_symbol,
                this.setting.site_currency_position
            );
        },
        subtotalDisplay: function () {
            return this.currencyFormat(
                this.subtotal,
                this.setting.site_digit_after_decimal_point,
                this.setting.site_default_currency_symbol,
                this.setting.site_currency_position
            );
        },
        posDiscountDisplay: function () {
            return this.currencyFormat(
                this.posDiscount,
                this.setting.site_digit_after_decimal_point,
                this.setting.site_default_currency_symbol,
                this.setting.site_currency_position
            );
        },
        deliveryChargeDisplay: function () {
            return this.currencyFormat(
                this.checkoutProps?.form?.delivery_charge || 0,
                this.setting.site_digit_after_decimal_point,
                this.setting.site_default_currency_symbol,
                this.setting.site_currency_position
            );
        },
        // [POS-V5-DESIGN-CONVERGENCE 2026-05-02] Wizard "active category" mirror.
        // Permet de surligner la catégorie courante dans la nouvelle category strip V5.
        currentCategoryId: function () {
            const raw = this.props?.search?.item_category_id;
            return raw === '' || raw === null || raw === undefined ? 0 : Number(raw);
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
        // [POS-V5 WAVE 3] Cleanup animations timers
        if (this._cartBumpTimer) { clearTimeout(this._cartBumpTimer); this._cartBumpTimer = null; }
        if (this._totalFlashTimer) { clearTimeout(this._totalFlashTimer); this._totalFlashTimer = null; }
        if (this._successFlashTimer) { clearTimeout(this._successFlashTimer); this._successFlashTimer = null; }
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
            this.itemList();
        } else {
            // [CV1-POS-AVAILABILITY-LIVE-001] Aucun branch_id côté auth (admin global
            // sans DefaultAccess) → ne JAMAIS fetcher un catalogue POS sans branch
            // scope. La projection availability per-branch (item_branch_availability)
            // ne peut s'appliquer qu'avec branch_id côté requête (cf ItemService::
            // applyBranchAvailabilityOverlay early-return $branchId<1). Sinon le store
            // se remplit avec is_available global (col items.is_available toujours
            // true), créant le bug R3-F2 : tile cliquable pour item OOS, rejet 422 au
            // submit. Le defaultAccess/show ci-dessous secourra si branch dispo.
            this.loading.isActive = false;
        }
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
        // [POS-V5 WAVE 3 2026-05-02] Animations triggers ─────────────────────
        triggerCartBump() {
            if (this._cartBumpTimer) clearTimeout(this._cartBumpTimer);
            this.cartBumping = true;
            this._cartBumpTimer = setTimeout(() => {
                this.cartBumping = false;
                this._cartBumpTimer = null;
            }, 360);
        },
        triggerTotalFlash() {
            if (this._totalFlashTimer) clearTimeout(this._totalFlashTimer);
            this.totalFlashing = true;
            this._totalFlashTimer = setTimeout(() => {
                this.totalFlashing = false;
                this._totalFlashTimer = null;
            }, 360);
        },
        triggerSuccessFlash() {
            if (this._successFlashTimer) clearTimeout(this._successFlashTimer);
            this.successFlashing = true;
            this._successFlashTimer = setTimeout(() => {
                this.successFlashing = false;
                this._successFlashTimer = null;
            }, 720);
        },
        // ────────────────────────────────────────────────────────────────────

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

        /**
         * Re-fetch customers if the store list is still empty (race: operator taps Pay
         * before mounted()'s user/lists resolves). Avoids orderSubmit stalling on walk-in.
         */
        async ensureCustomersHydratedForCheckout() {
            const have = Array.isArray(this.customers) ? this.customers.length : 0;
            if (have > 0) return true;
            try {
                await this.$store.dispatch('user/lists', {
                    order_column: 'id',
                    order_type: 'asc',
                    status: statusEnum.ACTIVE,
                    role_id: 2,
                });
                return true;
            } catch (e) {
                return false;
            }
        },

        async ensureWalkInCustomer() {
            if (this.checkoutProps.form.customer_id) return true;

            const existing = this.findWalkInCustomer(this.customers);
            if (this.assignWalkInCustomer(existing)) return true;

            try {
                const res = await axios.get('/admin/pos/walk-in-customer');
                const row = res.data?.data;
                if (row && row.id && this.assignWalkInCustomer(row)) {
                    return true;
                }
            } catch (e) {
                /* fall through — operator may lack route access in exotic configs */
            }

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
                // [V1.5C R2] Refresh catalogue after reconnect — Echo may have skipped pushes during outage.
                try { this.itemList(1, { overlay: false }); } catch (e) { /* defensive */ }
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
                await this.ensureCustomersHydratedForCheckout();
                const walkInReady = await this.ensureWalkInCustomer();
                if (!walkInReady) {
                    this.loading.isActive = false;
                    return alertService.error('Client comptoir indisponible. Rechargez la caisse puis réessayez.');
                }
            }
            this.checkoutProps.form.subtotal = this.subtotal;
            // @pricing-allowed-block start
            // [POS-V4 W0+ DISCOVERY 2026-04-26] Pre-modal display total — backend remains SSOT and recomputes server-side.
            // Must match `grandTotal` / footer CTA: raw `+ form.delivery_charge` can mis-add if charge is a string
            // (e.g. "19.5" + number → wrong total) and `form.discount` can drift from Vuex `posCart/discount`.
            // Identical pattern to ItemComponent.totalPriceSetup (W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md, decision D1).
            // signoff-pending — date_limit: 2026-05-10
            // Sign-off owners: Tech Lead + Backend owner. Tracking: reports/audit/BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md §1.
            // Migration path: replace by backend-computed `quote/preview` endpoint (W2 deliverable per HYPERREVIEW §6.D2).
            this.checkoutProps.form.discount = Number(this.posDiscount) || 0;
            this.checkoutProps.form.delivery_charge = Number(this.checkoutProps.form.delivery_charge) || 0;
            this.checkoutProps.form.total = Number(this.grandTotal).toFixed(this.setting.site_digit_after_decimal_point);
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
            handler(newCarts, oldCarts) {
                // [POS-V5 WAVE 3] Trigger cart-bump animation quand un item est ajouté.
                // - Ne se déclenche QUE si la quantité totale a augmenté (pas sur retrait/édition)
                // - Auto-reset après 320ms (durée de l'animation CSS pos-v5-bump).
                const newCount = Array.isArray(newCarts)
                    ? newCarts.reduce((sum, c) => sum + (parseInt(c.quantity, 10) || 0), 0)
                    : 0;
                const oldCount = Array.isArray(oldCarts)
                    ? oldCarts.reduce((sum, c) => sum + (parseInt(c.quantity, 10) || 0), 0)
                    : 0;
                if (newCount > oldCount && newCount > 0) {
                    this.triggerCartBump();
                    this.triggerTotalFlash();
                }

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
/* =============================================================================
   PosComponent — POS V5 Design Convergence (refonte 2026-05-02)
   -----------------------------------------------------------------------------
   Mission : CV1-POS-DESIGN-CONVERGENCE-001
   Doc plan : plans/PLAN_POS_V5_DESIGN_CONVERGENCE_2026-05-02.md
   -----------------------------------------------------------------------------
   - Le wizard kiosk (`KioskWizardComponent`) reste FROZEN.
   - Les classes `.pos-v5-*` partagées vivent dans `resources/css/pos-v5.css`
     (importé via app.css). Ce fichier scoped ne contient que :
       1. Neutralisation des anciens styles `.fk-pos-v4` / `.pos-v4-*` qui
          combattraient les nouvelles classes V5 (gradient sombre operator bar,
          tickets head, total row, item tile :deep, etc.).
       2. Styles UNIQUES à PosComponent : kiosk-cash-panel inline drawer,
          add-customer modal inline.
   - Rollback : poser `[data-pos-v4-disabled]` sur le shell désactive tout.
   ============================================================================= */

/* === 1. SHELL — laisser pos-v5-shell prendre le relais === */
.fk-pos-v4.pos-v5-shell {
  /* On supprime l'ancien gradient gris/froid hérité de pos-v4 ; le bg crème
     warm vient de .pos-v5-shell (foundations/pos-v5-tokens.css). */
  background: var(--pos-v5-bg-app);
  color: var(--pos-v5-ink);
  min-height: calc(100dvh - 64px);
  margin: -4px -8px 0 -8px;
  padding: 4px 10px 12px 8px;
  box-sizing: border-box;
  max-width: 100vw;
  overflow-x: hidden;
}

/* Rollback kill-switch (héritage doctrine pos-v4.css §9) */
[data-pos-v4-disabled].fk-pos-v4 { all: revert; }

.pos-v4-main {
  padding: 0 8px 16px 0;
  min-height: 0;
}

/* === 2. NEUTRALISATION ancien gradient operator bar ===
   La nouvelle classe .pos-v5-operator-bar (pos-v5.css) gère tout. On laisse
   les anciennes classes legacy (.pos-v4-operator-bar, .pos-v4-eyebrow,
   .pos-v4-title, .pos-v4-status-row) en passe-plat — pas de redéfinition. */
.pos-v4-operator-bar {
  /* Reset : le V5 prend le relais */
  background: transparent;
  color: inherit;
  border: 0;
  min-height: 0;
}
.pos-v4-eyebrow,
.pos-v4-title,
.pos-v4-status-row,
.pos-v4-status-row span {
  /* Reset legacy : les V5 classes prennent le relais */
  color: inherit;
  background: transparent;
}

/* === 3. SEARCH — déjà géré par PosV5SearchInput, neutralisation legacy === */
.pos-v4-search { background: transparent; border: 0; box-shadow: none; height: auto; }
.pos-v4-search input,
.pos-v4-search button[type="submit"] { all: unset; }

/* === 4. CART HEAD — neutralisation legacy === */
.pos-v4-cart-head {
  background: transparent;
}
.pos-v4-cart-table thead {
  background: transparent !important;
}

/* === 5. CART FOOTER — neutralisation legacy === */
.pos-v4-cart-footer {
  background: transparent !important;
  box-shadow: none !important;
}
.pos-v4-total-row {
  /* Le hero V5 prend le relais */
  background: transparent !important;
  border: 0 !important;
  min-height: 0 !important;
}

/* === 6. PRODUCT TILES ===
   [POS-V5 R2 2026-05-02] Les tiles produits (.pos-item-tile / .pos-v5-tile) sont
   désormais entièrement gérées par le styling scoped d'ItemComponent.vue (photo
   hero 4/3, body name+price+add, hover lift+scale image). On supprime les
   :deep() overrides ici pour éviter une bataille de spécificité avec le
   composant enfant — single source of truth = ItemComponent.vue. */

/* === 7. RESPONSIVE === */
@media (max-width: 767px) {
  .fk-pos-v4.pos-v5-shell {
    margin: -8px;
    padding: 10px;
    padding-bottom: 76px;
  }
  .pos-v4-main { padding-right: 0; }
}

/* =============================================================================
   8. KIOSK CASH PANEL drawer (inline dans PosComponent — restyle V5)
   -----------------------------------------------------------------------------
   Refonte avec tokens V5 — palette warm cohérente, typo Inter, ombres warm.
   ============================================================================= */
.kiosk-cash-panel-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--pos-v5-z-modal);
  background: rgba(26, 26, 26, 0.42);
  display: flex;
  align-items: flex-end;
  justify-content: flex-end;
  backdrop-filter: blur(2px);
}
.kiosk-cash-panel {
  background: var(--pos-v5-bg-panel);
  width: 420px;
  max-width: 100vw;
  height: 100vh;
  display: flex;
  flex-direction: column;
  box-shadow: -20px 0 48px rgba(26, 26, 26, 0.20);
  border-top-left-radius: var(--pos-v5-radius-xl);
  border-bottom-left-radius: var(--pos-v5-radius-xl);
  font-family: var(--pos-v5-font-sans);
}
.kiosk-cash-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--pos-v5-space-4) var(--pos-v5-space-5);
  border-bottom: 1px solid var(--pos-v5-border);
  background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 70%);
  font-weight: var(--pos-v5-weight-extrabold);
  font-size: var(--pos-v5-text-h6);
  color: var(--pos-v5-ink);
}
.kiosk-cash-panel-header-actions {
  display: inline-flex;
  align-items: center;
  gap: var(--pos-v5-space-2);
}
.kiosk-cash-panel-history-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 10px;
  border-radius: var(--pos-v5-radius-sm);
  border: 1px solid var(--pos-v5-border);
  background: var(--pos-v5-bg-panel);
  color: var(--pos-v5-ink);
  font-size: var(--pos-v5-text-caption);
  font-weight: var(--pos-v5-weight-bold);
  text-decoration: none;
  transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.kiosk-cash-panel-history-link:hover {
  background: var(--pos-v5-brand-red-soft);
  border-color: var(--pos-v5-brand-red);
  color: var(--pos-v5-brand-red);
}
.kiosk-cash-panel-close {
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: var(--pos-v5-radius-pill);
  background: var(--pos-v5-bg-subtle);
  color: var(--pos-v5-ink-soft);
  cursor: pointer;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.kiosk-cash-panel-close:hover {
  background: var(--pos-v5-danger-soft);
  color: var(--pos-v5-danger);
}
.kiosk-cash-detail-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 10px;
  border-radius: var(--pos-v5-radius-sm);
  border: 1px solid var(--pos-v5-border);
  background: var(--pos-v5-bg-panel);
  color: var(--pos-v5-ink);
  font-size: var(--pos-v5-text-caption);
  font-weight: var(--pos-v5-weight-semibold);
  text-decoration: none;
  white-space: nowrap;
  transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.kiosk-cash-detail-btn:hover {
  background: var(--pos-v5-brand-red-soft);
  border-color: var(--pos-v5-brand-red);
  color: var(--pos-v5-brand-red);
}
.kiosk-cash-panel-body {
  flex: 1;
  overflow-y: auto;
  padding: var(--pos-v5-space-4);
  display: flex;
  flex-direction: column;
  gap: var(--pos-v5-space-3);
  background: var(--pos-v5-bg-app);
}
.kiosk-cash-loading,
.kiosk-cash-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--pos-v5-space-8);
  color: var(--pos-v5-ink-muted);
  font-size: var(--pos-v5-text-body);
  text-align: center;
}
.kiosk-cash-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--pos-v5-bg-subtle);
  border-top-color: var(--pos-v5-brand-red);
  border-radius: 50%;
  animation: kiosk-cash-spin 0.8s linear infinite;
}
@keyframes kiosk-cash-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
  .kiosk-cash-spinner { animation: none; }
}
.kiosk-cash-order-card {
  background: var(--pos-v5-bg-panel);
  border: 1px solid var(--pos-v5-border);
  border-left: 4px solid var(--pos-v5-brand-red);
  border-radius: var(--pos-v5-radius-md);
  padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
  display: flex;
  flex-direction: column;
  gap: 6px;
  box-shadow: var(--pos-v5-shadow-sm);
  transition: box-shadow var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.kiosk-cash-order-card:hover {
  box-shadow: var(--pos-v5-shadow-md);
}
.kiosk-cash-order-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.kiosk-cash-order-num {
  font-weight: var(--pos-v5-weight-extrabold);
  font-size: var(--pos-v5-text-body-lg);
  color: var(--pos-v5-ink);
}
.kiosk-cash-order-total {
  font-weight: var(--pos-v5-weight-extrabold);
  color: var(--pos-v5-brand-red);
  font-size: var(--pos-v5-text-body-lg);
  font-feature-settings: "tnum";
  font-variant-numeric: tabular-nums;
}
.kiosk-cash-order-items {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.kiosk-cash-item-pill {
  background: var(--pos-v5-bg-subtle);
  border-radius: var(--pos-v5-radius-pill);
  padding: 2px 8px;
  font-size: 11px;
  font-weight: var(--pos-v5-weight-semibold);
  color: var(--pos-v5-ink-soft);
}
.kiosk-cash-item-pill.more {
  background: var(--pos-v5-brand-red-soft);
  color: var(--pos-v5-brand-red);
}
/* [GAP-25-2] Bouton Encaisser (kiosk-cash) */
.kiosk-cash-collect-btn {
  padding: 8px 14px;
  border-radius: var(--pos-v5-radius-sm);
  border: 0;
  background: var(--pos-v5-success);
  color: var(--pos-v5-ink-on-dark);
  font-size: var(--pos-v5-text-caption);
  font-weight: var(--pos-v5-weight-extrabold);
  cursor: pointer;
  transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
  white-space: nowrap;
  box-shadow: var(--pos-v5-shadow-success);
}
.kiosk-cash-collect-btn:hover { background: var(--pos-v5-success-dark); }
.kiosk-cash-collect-btn:disabled,
.kiosk-cash-cancel-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.kiosk-cash-cancel-btn {
  border: 0;
  background: var(--pos-v5-danger-soft);
  color: var(--pos-v5-danger-dark);
  border-radius: var(--pos-v5-radius-pill);
  padding: 6px 12px;
  font-size: 11px;
  font-weight: var(--pos-v5-weight-extrabold);
  cursor: pointer;
  transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.kiosk-cash-cancel-btn:hover { background: var(--pos-v5-danger-ghost); }
.kiosk-cash-order-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: var(--pos-v5-text-caption);
  color: var(--pos-v5-ink-muted);
}
.kiosk-cash-order-status {
  color: var(--pos-v5-success-dark);
  font-weight: var(--pos-v5-weight-semibold);
}
.kiosk-cash-panel-footer {
  padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
  border-top: 1px solid var(--pos-v5-border);
  background: var(--pos-v5-bg-panel);
}
.kiosk-cash-refresh-btn {
  width: 100%;
  padding: 10px;
  background: var(--pos-v5-bg-subtle);
  border: 1px solid var(--pos-v5-border);
  border-radius: var(--pos-v5-radius-md);
  font-size: var(--pos-v5-text-body);
  font-weight: var(--pos-v5-weight-semibold);
  cursor: pointer;
  color: var(--pos-v5-ink);
  transition: all var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.kiosk-cash-refresh-btn:hover {
  background: var(--pos-v5-brand-red-soft);
  border-color: var(--pos-v5-brand-red);
  color: var(--pos-v5-brand-red);
}

/* Slide-in panel transitions */
.slide-panel-enter-active,
.slide-panel-leave-active { transition: opacity var(--pos-v5-duration-base); }
.slide-panel-enter-from,
.slide-panel-leave-to { opacity: 0; }
.slide-panel-enter-active .kiosk-cash-panel,
.slide-panel-leave-active .kiosk-cash-panel { transition: transform var(--pos-v5-duration-slow) var(--pos-v5-ease-standard); }
.slide-panel-enter-from .kiosk-cash-panel,
.slide-panel-leave-to .kiosk-cash-panel { transform: translateX(100%); }
</style>
