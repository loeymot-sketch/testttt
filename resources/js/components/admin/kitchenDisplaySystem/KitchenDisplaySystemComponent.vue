<template>
  <!--
    [Wave X3 2026-05-21] KDS Historique du jour — read-only V1 day-history.
    Trigger button renders above both V2 and legacy layouts so chef can open
    the history drawer regardless of which grid mode is active. Wave U
    "Récemment servies" strip in KdsV2Grid remains independent (short-term
    recents in active grid vs. full-day history viewer in drawer).
  -->
  <div class="kds-history-trigger-row">
    <!-- [GOAL RUPTURE-CARNET 2026-07-15 / W3] Bouton « Rupture » cuisine — rendu
         au-dessus des DEUX layouts (V2 + legacy), comme Historique, pour que le
         Chef y accède quel que soit le mode de grille actif. -->
    <button
      type="button"
      class="kds-history-trigger kds-rupture-trigger"
      data-testid="kds-availability-panel-open"
      :aria-label="$t('availability.panel_title')"
      @click="showAvailabilityPanel = true"
    >
      <span aria-hidden="true">🚫</span>
      <span class="kds-history-trigger__label">{{ $t('availability.button_label') }}</span>
    </button>
    <button
      type="button"
      class="kds-history-trigger"
      :aria-label="$t('label.kds_history_button_aria')"
      data-testid="kds-history-button"
      @click="historyDrawerOpen = true"
    >
      <span aria-hidden="true">📚</span>
      <span class="kds-history-trigger__label">{{ $t('label.kds_history_button') }}</span>
    </button>
  </div>
  <KdsHistoryDrawer
    :open="historyDrawerOpen"
    :dir="direction"
    @close="historyDrawerOpen = false"
    @recalled="onKdsOrderRecalled"
  />
  <!-- [GOAL RUPTURE-CARNET 2026-07-15 / W3] Panel rupture (86) cuisine —
       propagation borne/caisse/web via ItemAvailabilityChanged + outbox. -->
  <AvailabilityTogglePanel
    :visible="showAvailabilityPanel"
    @close="showAvailabilityPanel = false"
  />
  <!--
    [kds/sprint-2 V-5] Feature-flagged V2 layout. When useV2Layout is true
    (URL ?v2=1, localStorage 'kds.v2_enabled', or future settings flag), the
    new single-FIFO 4×2 grid renders. Otherwise the legacy 4-column layout
    (Dine-in / Online / Takeaway / Kiosk) stays — instant rollback by URL
    param removal.
  -->
  <KdsV2Grid
    v-if="useV2Layout"
    :orders="orders"
    :dir="direction"
    :offline-since="v2OfflineSince"
    :list-at-cap="kdsOrderListAtCap"
    :fallback-mode="!wsConnected && !kdsSuppressFallbackBanner"
    :admin-polling-hint="kdsIsCentralAdmin"
    :bump-local-only-notice="!kdsHideBumpInfo"
    :auto-transition-enabled="v2AutoTransitionEnabled"
    :recall-active-ids="recallActiveIds"
    @change-status="onV2ChangeStatus"
    @auto-promote="onV2AutoPromote"
    @reprint="reprintKitchenTicket"
  />
  <template v-else>
  <!--
    [iter15-mega-fix B-003/D-002 2026-05-10] Banner consolidation: suppress the
    global transient "Reconnexion en cours…" banner here because the local
    kds-sync-mode-banner already conveys fallback polling state to staff.
    Prevents two banners shouting the same fact (iter15 mega-audit Wave B/D).
    Terminal session_invalid still surfaces.
  -->
  <ConnectionStatusBanner suppress-transient />
  <LoadingComponent :props="loading" />
  <!--
    [iter15-mega-fix C-008 run-3 2026-05-10] Hide the KDS "Mode secours actif"
    fallback banner in local dev where Pusher/Soketi is not running — it was
    permanently visible across every KDS view (states 01/07/09 in iter15 mega
    Wave B + Wave C) and pure noise. Production keeps it: kitchen needs to
    know they are in fallback polling. Gate strictly on appEnv === 'local'
    (NOT 'testing') so any CI suite running with APP_ENV=testing still sees
    the banner. Existing dependent specs (audit-kds-cycle1 D1-05,
    red-team-r4 R4-12, 04-kds-status) all use OR-fallback locators so they
    keep passing in CI Playwright (which runs APP_ENV=local) via the sync
    stamp / aria-live / grid alternatives.
  -->
  <div v-if="!wsConnected && !kdsSuppressFallbackBanner" class="ws-reconnect-banner" data-testid="kds-sync-mode-banner">
    {{ $t('label.kds_fallback_banner') }}
  </div>
  <!--
    [test-e2e round-2 cluster-1 C-001 2026-05-10] Persistent error banner.
    Replaces (and augments) the previous ephemeral toast in
    _refreshWithCurrentFilter / list() error paths. Visible until the next
    successful /api/admin/kds-order poll resets it, or the operator dismisses
    it manually with the ✕ button. data-testid lets E2E specs assert
    visibility deterministically (no race with toast fade-out).
  -->
  <div
    v-if="kdsErrorBanner.visible"
    class="kds-hint-banner kds-hint-banner--danger kds-hint-banner--action"
    role="alert"
    aria-live="assertive"
    data-testid="kds-error-banner"
  >
    <span>{{ kdsErrorBanner.message }}</span>
    <button
      type="button"
      class="kds-hint-link"
      :aria-label="$t('label.kds_dismiss_hint')"
      @click="dismissKdsErrorBanner"
    >✕</button>
  </div>
  <div
    v-if="kdsIsCentralAdmin"
    class="kds-hint-banner kds-hint-banner--info"
    role="status"
  >
    {{ $t("label.kds_admin_polling_hint") }}
  </div>
  <div
    v-if="kdsOrderApproachingCap"
    class="kds-hint-banner kds-hint-banner--warning"
    role="alert"
  >
    {{ $t("label.kds_order_cap_warning", { n: orders.length }) }}
  </div>
  <div
    v-if="kdsOrderListAtCap"
    class="kds-hint-banner kds-hint-banner--danger kds-hint-banner--action"
    role="alert"
  >
    <span>{{ $t("label.kds_order_list_full_warning", { n: orders.length }) }}</span>
    <button type="button" class="kds-hint-link" @click="kdsOverflowSeeMore">{{ $t('label.kds_see_more') }}</button>
  </div>
  <div
    v-if="!kdsHideBumpInfo"
    class="kds-hint-banner kds-hint-banner--neutral flex flex-wrap items-center justify-between gap-2 text-left"
    role="note"
  >
    <span class="min-w-0 flex-1">{{ $t("label.kds_bump_local_only_notice") }}</span>
    <button
      type="button"
      class="shrink-0 text-xs font-medium underline text-[#4b5563]"
      @click="dismissKdsBumpNotice"
    >
      {{ $t("label.kds_dismiss_hint") }}
    </button>
  </div>
  <div class="row md:mt-4 lg:mt-0">
    <div class="lg:hidden flex items-center w-full px-4">
      <button
        class="kitchen-board db-tab-btn active text-base text-black font-semibold h-[38px] bg-white flex items-center justify-center rounded-l-lg px-7"
        data-tab="#item-order">{{ $t('label.items_board') }}</button>
      <button
        class="kitchen-board db-tab-btn text-base text-black font-semibold h-[38px] bg-white flex items-center justify-center ro rounded-r-lg px-7"
        data-tab="#today-order">{{ $t('label.todays_order') }}</button>
    </div>
    <div id="item-order" class="col-12 lg:col-3 db-tab-div active lg:block hidden">
      <div class="db-card rounded-[10px] w-full">
        <div class="h-screen md:h-[calc(100vh-127px)] overflow-hidden">
          <div class="p-3 pb-2 border-b border-[#D9DBE9]">
            <h3 class="text-lg font-semibold">{{ $t('label.items_board') }}</h3>
            <p class="text-[11px] text-[#4B5563] leading-snug mt-1">{{ $t("label.kds_items_board_scope") }}</p>
          </div>
          <ul class="h-full thin-scrolling overflow-auto pb-12">
            <!-- [N7 FIX] Stable key using item_id + instruction hash instead of object reference -->
            <li v-for="(orderItem, oIdx) in orderItems" :key="orderItem.item_id + '-' + oIdx"
              class="px-3 py-2 flex items-start justify-between gap-2 border-b border-[#EFF0F6] last:border-none">
              <div>
                <h5 class="text-sm font-medium mb-1">{{ orderItem.item_name }}</h5>
                <!-- [AUDIT-P1] Array.isArray guard: legacy kiosk orders stored JSON objects,
                     not arrays. Without this guard, .length on an object is undefined → Vue warning. -->
                <p v-if="Array.isArray(orderItem.item_variations) && orderItem.item_variations.length > 0"
                  class="text-xs font-normal font-client capitalize text-[#6E7191]">
                  <span v-for="(variation, index) in orderItem.item_variations" :key="index" class="text-heading">
                    {{ variationLine(variation) }}<span
                      v-if="index + 1 < orderItem.item_variations.length">,&nbsp;</span>
                  </span>
                </p>
                <span class="flex gap-1" v-if="Array.isArray(orderItem.item_extras) && orderItem.item_extras.length > 0">
                  <h3 class="capitalize text-xs w-fit whitespace-nowrap">{{ $t('label.extras') }}:
                  </h3>
                  <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                    <span v-for="(extra, index) in orderItem.item_extras" :key="index" class="text-heading">
                      {{ extra.name }}<span v-if="index + 1 < orderItem.item_extras.length">,&nbsp;</span>
                    </span>
                  </p>
                </span>
                <span class="flex gap-1" v-if="Array.isArray(orderItem.item_addons) && orderItem.item_addons.length > 0">
                  <h3 class="capitalize text-xs w-fit whitespace-nowrap">{{ $t('label.addons') }}:
                  </h3>
                  <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                    <span v-for="(addon, index) in orderItem.item_addons" :key="index" class="text-heading">
                      {{ kdsAddonDisplayName(addon) }}<span v-if="Number(addon.quantity || 1) > 1"> ×{{ Number(addon.quantity || 1) }}</span><span v-if="index + 1 < orderItem.item_addons.length">,&nbsp;</span>
                    </span>
                  </p>
                </span>
                <div
                  v-if="kdsCleanInstruction(orderItem)"
                  :class="[kdsInstructionClass(kdsCleanInstruction(orderItem)), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']"
                  style="white-space: pre-line"
                >{{ kdsCleanInstruction(orderItem) }}</div>
              </div>
              <div
                class="text-sm font-medium w-6 h-6 rounded-full bg-black text-white flex items-center justify-center">{{
                  orderItem.quantity }}
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>
    <div id="today-order" class="col-12 lg:col-9 db-tab-div lg:block hidden">
      <div class="ordersTab">
        <div class="db-card px-3 py-3 mb-4 flex flex-col xl:flex-row flex-wrap gap-4 xl:items-center xl:justify-between">
          <div class="flex flex-wrap items-center gap-3">
            <label for="kds-station-filter" class="text-sm font-medium text-heading shrink-0">{{ $t('label.kds_station_filter') }}</label>
            <select id="kds-station-filter" v-model="stationFilter" @change="persistKdsUiPrefs"
              :aria-label="$t('label.kds_station_filter')"
              class="h-10 rounded-lg border border-[#D9DBE9] px-3 text-xs text-heading min-w-[10rem] bg-white">
              <option value="all">{{ $t('label.kds_all_stations') }}</option>
              <option value="bar">{{ $t('label.kds_bar') }}</option>
              <option value="cuisine_chaude">{{ $t('label.kds_cuisine_chaude') }}</option>
              <option value="cuisine_froide">{{ $t('label.kds_cuisine_froide') }}</option>
            </select>
            <label class="flex items-center gap-2 text-xs font-medium text-heading cursor-pointer">
              <input type="checkbox" v-model="groupByTable" @change="persistKdsUiPrefs" class="rounded border-[#D9DBE9]" />
              {{ $t('label.kds_group_by_table') }}
            </label>
          </div>
          <div class="flex flex-wrap items-center gap-4">
            <span class="text-sm font-medium text-heading">{{ $t('label.kds_sound') }}</span>
            <label class="flex items-center gap-2 text-xs text-heading cursor-pointer">
              <input type="checkbox" v-model="soundEnabled" @change="persistKdsUiPrefs" class="rounded border-[#D9DBE9]" />
              <span class="sr-only">{{ $t('label.kds_sound') }}</span>
            </label>
            <label class="flex items-center gap-2 text-xs text-heading">
              <span class="whitespace-nowrap">{{ $t('label.kds_volume') }}</span>
              <input type="range" min="0" max="100" v-model.number="soundVolume" @input="persistKdsUiPrefs"
                :aria-label="$t('label.kds_volume')"
                class="w-28 accent-primary" />
            </label>
            <label class="flex items-center gap-2 text-xs font-medium text-heading cursor-pointer"
              :title="$t('label.kds_auto_print_hint')">
              <input type="checkbox" v-model="autoPrintKitchen" @change="persistKdsUiPrefs" class="rounded border-[#D9DBE9]" />
              <span class="whitespace-nowrap">🖨️ {{ $t('label.kds_auto_print') }} {{ autoPrintKitchen ? 'ON' : 'OFF' }}</span>
            </label>
            <span v-if="kitchenPrintFailedCount > 0" role="alert"
              class="flex items-center gap-1 text-xs font-semibold text-red-600 whitespace-nowrap">
              ⚠️ {{ $t('label.kds_print_failed', { count: kitchenPrintFailedCount }) }}
            </span>
          </div>
          <audio ref="kdsNewOrderAudio" preload="auto" class="hidden" src="/sounds/kds-new-order.mp3" />
        </div>
        <div class="db-card px-3 py-2.5 mb-4">
          <div class="swiper kitchen-swiper !flex flex-col gap-y-2 xl:flex-row items-start justify-between">
            <Swiper :dir="direction" :speed="1000" slidesPerView="auto" :spaceBetween="12" :loop="false"
              class="md:grid sm:grid-cols-2 lg:grid-cols-4  gap-y-2 md:w-fit lg:!w-full w-full">
              <SwiperSlide class="!w-fit">
                <button type="button" v-on:click="list()"
                  class="db-btn text-heading w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white border border-[#D9DBE9] hover:text-[#4C1A96] hover:border-[#4C1A96] hover:bg-[#F8F1FF]"
                  :class="!props.search.status ? '!bg-[#4C1A96] !text-white !border-[#4C1A96]' : ''">
                  <span class="whitespace-nowrap text-sm font-medium">{{ $t("label.all_orders") }}</span>
                </button>
              </SwiperSlide>
              <SwiperSlide class="!w-fit">
                <button type="button" v-on:click="list(enums.orderStatusEnum.ACCEPT)"
                  :class="props.search.status === enums.orderStatusEnum.ACCEPT ? '!bg-[#4C1A96] !text-white !border-[#4C1A96]' : ''"
                  class="db-btn text-heading w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white border border-[#D9DBE9] hover:text-[#4C1A96] hover:border-[#4C1A96] hover:bg-[#F8F1FF]">
                  <span class="whitespace-nowrap text-sm font-medium">{{ $t("label.confirmed") }}</span>
                </button>
              </SwiperSlide>
              <SwiperSlide class="!w-fit">
                <button type="button" v-on:click="list(enums.orderStatusEnum.PREPARING)"
                  :class="props.search.status === enums.orderStatusEnum.PREPARING ? '!bg-[#4C1A96] !text-white !border-[#4C1A96]' : ''"
                  class="db-btn text-heading w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white border border-[#D9DBE9] hover:text-[#4C1A96] hover:border-[#4C1A96] hover:bg-[#F8F1FF]">
                  <span class="whitespace-nowrap text-sm font-medium">{{ $t("label.preparing") }}</span>
                </button>
              </SwiperSlide>
              <SwiperSlide class="!w-fit">
                <button type="button" v-on:click="list(enums.orderStatusEnum.PREPARED)"
                  :class="props.search.status === enums.orderStatusEnum.PREPARED ? '!bg-[#4C1A96] !text-white !border-[#4C1A96]' : ''"
                  class="db-btn text-heading w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white border border-[#D9DBE9] hover:text-[#4C1A96] hover:border-[#4C1A96] hover:bg-[#F8F1FF]">
                  <span class="whitespace-nowrap text-sm font-medium">{{ $t("label.done") }}</span>
                </button>
              </SwiperSlide>
            </Swiper>

            <form @submit.prevent="search"
              class="header-search-group group flex items-center justify-center border border-solid gap-2 px-3 xl:!max-w-[305px] w-full h-11 rounded-lg transition border-[#D9DBE9] focus-within:bg-white focus-within:border-primary">
              <i class="lab lab-search-normal lab-font-size-16"></i>
              <input type="text" v-model="props.search.order_serial_no" :placeholder="$t('label.kds_search_orders')"
                :aria-label="$t('button.search')"
                class="header-search-field w-full h-full text-xs appearance-none placeholder:font-normal placeholder:text-paragraph text-heading" />
              <button type="button" @click.prevent="searchReset"
                :aria-label="$t('button.close')"
                class="modal-close lab lab-close-circle-line transition invisible group-focus-within:visible"></button>
            </form>
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-4 gap-4" @click="closeFilterSlide($event)">
          <div class="db-card rounded-[10px] h-fit">
            <div class="p-3 pb-2" :class="filteredDineinOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''">
              <h3 class="text-lg font-semibold">{{ $t("label.dinein_orders") }}</h3>
            </div>
            <div v-if="filteredDineinOrders.length === 0" class="p-3 text-sm text-[#6E7191]">
              Aucune commande sur place en cours.
            </div>
            <div v-else class="p-3 space-y-3">
              <template v-for="(dineinOrder, dIdx) in sortedFilteredDinein" :key="dineinOrder.id">
                <div v-if="kdsDineinTableHeaderVisible(dineinOrder, dIdx)" class="mb-1">
                  <button type="button" @click="toggleTableGroup(dineinTableKey(dineinOrder))"
                    class="w-full flex items-center justify-between gap-2 px-2 py-2 rounded-lg bg-[#F7F7FC] text-left text-sm font-semibold text-heading">
                    <span>{{ dineinTableKey(dineinOrder) }}</span>
                    <i class="fa-solid" :class="isTableGroupOpen(dineinTableKey(dineinOrder)) ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                  </button>
                </div>
                <div v-show="!groupByTable || isTableGroupOpen(dineinTableKey(dineinOrder))">
              <div class="w-full rounded-lg border transition-colors border-[#EFF0F6] relative" :class="[kdsWaitClass(dineinOrder), flashTableChangeIds[dineinOrder.id] ? 'kds-table-flash' : '']"
                role="article"
                :aria-labelledby="'order-' + dineinOrder.id + '-title'"
                data-kds-order-card="dinein">
                <!-- [CV1-KDS-INFLIGHT-OOS-MARKER-001] OOS warning badge -->
                <span
                  v-if="kdsHasOosWarning(dineinOrder)"
                  class="kds-oos-warning-badge"
                  data-testid="kds-oos-warning-badge"
                  :title="$t('label.kds_oos_warning_tooltip')"
                  :aria-label="$t('label.kds_oos_warning_aria')"
                  role="img"
                >&#9888; OOS</span>
                <button
                  v-if="orderHasAllergens(dineinOrder)"
                  type="button"
                  class="kds-allergens-badge"
                  @click.prevent.stop="openAllergensModal(dineinOrder)"
                  :aria-label="$t('label.kds_allergens_badge_aria')"
                >&#9888; {{ $t('label.kds_allergens_badge') }}</button>
                <div class="py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#F0F8FF]">
                  <div class="flex items-center gap-1 text-[#0084FF]">
                    <i class="lab lab-processing lab-font-size-16 text-[#0084FF]"></i>
                    <span :id="'order-' + dineinOrder.id + '-title'" class="text-sm font-normal">#{{ dineinOrder.order_serial_no }}</span>
                    <span v-if="dineinOrder.queue_number" class="kds-source-pill kds-source-pill--queue">
                      N°{{ dineinOrder.queue_number }}
                    </span>
                  </div>

                  <span class="py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize"
                    :class="orderStatusBadgeClasses(dineinOrder.status)">{{
                      dineinOrder.status === enums.orderStatusEnum.PREPARED ? $t("label.done") : (dineinOrder.status ===
                        enums.orderStatusEnum.ACCEPT ? $t("label.confirmed") : dineinOrder.status_name)
                    }}</span>
                </div>
                <div class="w-full pt-2 pb-3 px-3">
                  <p class="text-sm font-normal leading-6 font-client capitalize text-[#6E7191]">
                    {{ $t("label.table_no") }}: <span class="text-heading font-medium">{{ dineinOrder.table_name
                      }}</span>
                  </p>
                  <p class="text-sm font-normal leading-6 font-client capitalize text-[#6E7191]">
                    {{ $t("label.token_no") }}: <span class="text-heading font-medium">{{ dineinOrder.token ?
                      dineinOrder.token : $t("label.online")
                      }}</span>
                  </p>
                  <!-- [Sprint H4 Z3-NEW-002/003 2026-05-17] Legacy delivery
                       block mirrored from onlineOrder lane. Helper gates on
                       order_type === DELIVERY so this only renders for
                       misclassified delivery orders that landed in the
                       dine-in lane — defensive UX so chef + courier never
                       miss a delivery on the ?v2=0 rollback path. -->
                  <div
                    v-if="kdsLegacyShouldShowDelivery(dineinOrder)"
                    class="mt-1 mb-2 p-2 rounded-lg bg-[#F0FDFA] border-l-[3px] border-[#0F766E]"
                    data-testid="kds-legacy-delivery"
                  >
                    <p v-if="kdsLegacyDeliveryAddress(dineinOrder)" class="text-xs leading-snug text-[#0F766E] font-semibold flex items-start gap-1">
                      <span aria-hidden="true">&#128205;</span>
                      <span class="break-words">{{ kdsLegacyDeliveryAddress(dineinOrder) }}</span>
                    </p>
                    <p v-if="dineinOrder.customer && dineinOrder.customer.name" class="text-xs leading-snug text-[#115E59] flex items-center gap-1">
                      <span aria-hidden="true">&#128100;</span>
                      <span>{{ dineinOrder.customer.name }}</span>
                    </p>
                    <!-- [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT -->
                    <a
                      v-if="dineinOrder.customer && safePhone(dineinOrder.customer.phone)"
                      :href="`tel:${safePhone(dineinOrder.customer.phone)}`"
                      class="text-xs leading-snug text-[#0F766E] font-bold flex items-center gap-1 hover:underline keep-latin"
                    >
                      <span aria-hidden="true">&#128241;</span>
                      <span>{{ safePhone(dineinOrder.customer.phone) }}</span>
                    </a>
                  </div>
                  <!-- [iter15-mega-fix B-002 2026-05-10] Chevron now exposes
                       aria-expanded so assistive tech can announce the
                       collapse state. The state-transition CTAs (Démarrer /
                       Prêt) have been hoisted OUT of this accordion below so
                       a chef in gloves can reach them in one tap without
                       expanding line items first. -->
                  <button type="button" @click="openFilterSlide($event)"
                    class="filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full"
                    :aria-expanded="false"
                    :aria-label="$t('label.kds_toggle_items') || 'Afficher les articles'">
                    <span>{{ kdsDisplayDateTime(dineinOrder.order_datetime) }}</span>
                    <div
                      class="flex items-center justify-center w-6 h-6 rounded-full bg-[#FFE8DD] text-base font-semibold transition-all duration-500 group-hover:text-[#F4501E]">
                      <i class="icon text-[#F4501E] fa-solid fa-chevron-down"></i>
                    </div>
                  </button>
                  <div style="height: 0px" class="overflow-hidden transition-all duration-500">
                    <div v-for="(item, iIdx) in dineinOrder.order_items" :key="item.id || iIdx"
                      class="flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none">
                      <h4 class="text-sm font-medium shrink-0">{{ item.quantity }}x</h4>
                      <div class="flex-1 min-w-0">
                        <h5 class="text-sm font-medium mb-1">{{ item.item_name }}</h5>
                        <!-- [Y2 FIX] Guard item_variations -->
                        <p v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0"
                          class="text-xs font-normal font-client capitalize text-[#6E7191]">
                          <span v-for="(variation, index) in item.item_variations" :key="index" class="text-heading">
                            {{ variationLine(variation) }}<span
                              v-if="index + 1 < item.item_variations.length">,&nbsp;</span>
                          </span>
                        </p>
                        <!-- [N4 FIX] Was <li> without <ul> — replaced with <div> -->
                        <!-- [Y2 FIX] Guard item_extras -->
                        <div class="flex gap-1" v-if="Array.isArray(item.item_extras) && item.item_extras.length > 0">
                          <span class="capitalize text-xs w-fit whitespace-nowrap font-medium">{{ $t('label.extras') }}:</span>
                          <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                            <span v-for="(extra, index) in item.item_extras" :key="index" class="text-heading">
                              {{ extra.name }}<span v-if="index + 1 < item.item_extras.length">,&nbsp;</span>
                            </span>
                          </p>
                        </div>
                        <div class="flex gap-1" v-if="Array.isArray(item.item_addons) && item.item_addons.length > 0">
                          <span class="capitalize text-xs w-fit whitespace-nowrap font-medium">{{ $t('label.addons') }}:</span>
                          <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                            <span v-for="(addon, index) in item.item_addons" :key="index" class="text-heading">
                              {{ kdsAddonDisplayName(addon) }}<span v-if="Number(addon.quantity || 1) > 1"> ×{{ Number(addon.quantity || 1) }}</span><span v-if="index + 1 < item.item_addons.length">,&nbsp;</span>
                            </span>
                          </p>
                        </div>
                        <!-- [P3-1 FIX] Show instruction on dine-in cards -->
                        <div
                          v-if="kdsCleanInstruction(item)"
                          :class="[kdsInstructionClass(kdsCleanInstruction(item)), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']"
                          style="white-space: pre-line"
                        >{{ kdsCleanInstruction(item) }}</div>
                      </div>
                      <div class="flex flex-col items-end gap-1 shrink-0 pt-0.5">
                        <!-- [iter15-mega-fix B-007 round-7 2026-05-10] Icon-only Prêt button needs aria-label for touch tablets / SR -->
                        <button v-if="!kdsIsBumped(dineinOrder.id, item.id)" type="button"
                          class="w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#F4501E] hover:bg-[#F4501E]/5"
                          :title="$t('button.kds_bump')"
                          :aria-label="`${$t('button.kds_bump')} — ${item.item_name}`"
                          @click.prevent.stop="kdsBump(dineinOrder, item)">
                          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </button>
                        <button v-else-if="kdsCanRecall(dineinOrder.id, item.id)" type="button"
                          class="text-[11px] font-semibold text-[#F4501E] underline decoration-[#F4501E]/50"
                          @click.prevent.stop="kdsRecall(dineinOrder, item)">
                          {{ $t('button.kds_recall') }}
                        </button>
                        <!-- [KDS-REPRINT 2026-07-13] Réimpression manuelle du ticket cuisine (ignore toggle auto + dé-dup). -->
                        <button type="button"
                          class="w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#6B7280] hover:bg-[#6B7280]/5"
                          :title="$t('label.kds_reprint')"
                          :aria-label="`${$t('label.kds_reprint')} — N°${dineinOrder.queue_number || dineinOrder.id}`"
                          data-testid="kds-legacy-reprint"
                          @click.prevent.stop="reprintKitchenTicket(dineinOrder)">
                          <i class="fa-solid fa-print" aria-hidden="true"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                  <!-- [iter15-mega-fix B-002 2026-05-10] State-transition CTAs
                       moved OUT of the accordion. Always rendered so the chef
                       reaches them in 1 tap on a busy kitchen tablet. -->
                  <!-- [iter15-mega-fix C-041 round-8 2026-05-10] Tailwind
                       theme.colors.primary resolves to rgb(255 0 107) (=
                       #FF006B, hot pink/magenta) and clashes with the owner's
                       Cayenne brand palette. Replace with arbitrary hex
                       `bg-[#F4501E]` (Cayenne orange-red, matches mobile app
                       and `--kiosk-bold-primary` token) on the KDS surface
                       only, scoped to this iter15-mega Wave C round-8 fix —
                       global Tailwind override is out of scope for this
                       round. -->
                  <div class="kds-card-cta mt-2" data-testid="kds-card-cta">
                    <button v-if="dineinOrder.status === enums.orderStatusEnum.ACCEPT" type="button"
                      @click="orderStatus(dineinOrder, enums.orderStatusEnum.PREPARING)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#F4501E] text-white">
                      {{ $t("label.start_preparing") }}
                    </button>
                    <button v-if="dineinOrder.status === enums.orderStatusEnum.PREPARING" type="button"
                      @click="orderStatus(dineinOrder, enums.orderStatusEnum.PREPARED)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white">
                      {{ $t("label.mark_done") }}
                    </button>
                  </div>
                </div>
              </div>
                </div>
              </template>
            </div>
          </div>
          <div class="db-card rounded-[10px] h-fit">
            <div class="p-3 pb-2" :class="filteredOnlineOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''">
              <h3 class="text-lg font-semibold">{{ $t("label.online_orders") }}</h3>
            </div>
            <div v-if="filteredOnlineOrders.length === 0" class="p-3 text-sm text-[#6E7191]">
              Aucune commande en ligne en cours.
            </div>
            <div v-if="filteredOnlineOrders.length > 0" class="p-3" v-for="onlineOrder in filteredOnlineOrders" :key="onlineOrder.id">
              <div class="w-full rounded-lg border transition-colors border-[#EFF0F6] relative" :class="kdsWaitClass(onlineOrder)"
                role="article"
                :aria-labelledby="'order-' + onlineOrder.id + '-title'"
                data-kds-order-card="online">
                <!-- [CV1-KDS-INFLIGHT-OOS-MARKER-001] OOS warning badge -->
                <span
                  v-if="kdsHasOosWarning(onlineOrder)"
                  class="kds-oos-warning-badge"
                  data-testid="kds-oos-warning-badge"
                  :title="$t('label.kds_oos_warning_tooltip')"
                  :aria-label="$t('label.kds_oos_warning_aria')"
                  role="img"
                >&#9888; OOS</span>
                <button
                  v-if="orderHasAllergens(onlineOrder)"
                  type="button"
                  class="kds-allergens-badge"
                  @click.prevent.stop="openAllergensModal(onlineOrder)"
                  :aria-label="$t('label.kds_allergens_badge_aria')"
                >&#9888; {{ $t('label.kds_allergens_badge') }}</button>
                <div class="py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#FFF6EE]">
                  <div class="flex items-center gap-1 text-[#FF8C1A]">
                    <i class="lab lab-processing lab-font-size-16 text-[#FF8C1A]"></i>
                    <span :id="'order-' + onlineOrder.id + '-title'" class="text-sm font-normal">#{{ onlineOrder.order_serial_no }}</span>
                    <span v-if="onlineOrder.queue_number" class="kds-source-pill kds-source-pill--queue">
                      N°{{ onlineOrder.queue_number }}
                    </span>
                  </div>
                  <span class="py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize"
                    :class="orderStatusBadgeClasses(onlineOrder.status)">{{
                      onlineOrder.status === enums.orderStatusEnum.PREPARED ? $t("label.done") : (onlineOrder.status ===
                        enums.orderStatusEnum.ACCEPT ? $t("label.confirmed") : onlineOrder.status_name)
                    }}</span>
                </div>
                <div class="w-full pt-2 pb-3 px-3">
                  <p class="text-sm font-normal leading-6 font-client capitalize text-[#6E7191]">
                    {{ $t("label.schedule") }}: <span class="text-heading font-medium"
                      :class="onlineOrder.is_advance_order === enums.askEnum.YES ? '!text-[#008BBA]' : ''">{{
                        onlineOrder.delivery_time }}</span>
                  </p>
                  <p class="text-sm font-normal leading-6 font-client capitalize text-[#6E7191]"
                    v-if="onlineOrder.token">
                    {{ $t("label.token_no") }}: <span class="text-heading font-medium">{{ onlineOrder.token }}</span>
                  </p>
                  <!-- [Sprint 2A DEL-3 2026-05-16] Legacy delivery block —
                       online lane = DELIVERY orders only (filter at line
                       1690 below). Even if the operator falls back to
                       ?v2=0, the livreur must still see address + phone +
                       name. Backend KDSOrderDetailsResource now provides
                       order_address + customer on every payload. -->
                  <div
                    v-if="kdsLegacyShouldShowDelivery(onlineOrder)"
                    class="mt-1 mb-2 p-2 rounded-lg bg-[#F0FDFA] border-l-[3px] border-[#0F766E]"
                    data-testid="kds-legacy-delivery"
                  >
                    <p v-if="kdsLegacyDeliveryAddress(onlineOrder)" class="text-xs leading-snug text-[#0F766E] font-semibold flex items-start gap-1">
                      <span aria-hidden="true">&#128205;</span>
                      <span class="break-words">{{ kdsLegacyDeliveryAddress(onlineOrder) }}</span>
                    </p>
                    <p v-if="onlineOrder.customer && onlineOrder.customer.name" class="text-xs leading-snug text-[#115E59] flex items-center gap-1">
                      <span aria-hidden="true">&#128100;</span>
                      <span>{{ onlineOrder.customer.name }}</span>
                    </p>
                    <!-- [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT -->
                    <a
                      v-if="onlineOrder.customer && safePhone(onlineOrder.customer.phone)"
                      :href="`tel:${safePhone(onlineOrder.customer.phone)}`"
                      class="text-xs leading-snug text-[#0F766E] font-bold flex items-center gap-1 hover:underline keep-latin"
                    >
                      <span aria-hidden="true">&#128241;</span>
                      <span>{{ safePhone(onlineOrder.customer.phone) }}</span>
                    </a>
                  </div>
                  <!-- [iter15-mega-fix B-002 2026-05-10] aria-expanded for SR. -->
                  <button type="button" @click="openFilterSlide($event)"
                    class="filter group text-xs font-[300] flex justify-between items-center w-full"
                    :class="onlineOrder.is_advance_order === enums.askEnum.YES ? 'text-[#008BBA]' : 'text-[#6E7191]'"
                    :aria-expanded="false"
                    :aria-label="$t('label.kds_toggle_items') || 'Afficher les articles'">
                    <span>{{ kdsDisplayDateTime(onlineOrder.order_datetime) }}</span>
                    <div
                      class="flex items-center justify-center w-6 h-6 rounded-full bg-[#FFE8DD] text-base font-semibold transition-all duration-500 group-hover:text-[#F4501E]">
                      <i class="icon text-[#F4501E] fa-solid fa-chevron-down"></i>
                    </div>
                  </button>
                  <div style="height: 0px" class="overflow-hidden transition-all duration-500">
                    <div v-for="(item, iIdx) in onlineOrder.order_items" :key="item.id || iIdx"
                      class="flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none">
                      <h4 class="text-sm font-medium shrink-0">{{ item.quantity }}x</h4>
                      <div class="flex-1 min-w-0">
                        <h5 class="text-sm font-medium mb-1">{{ item.item_name }}</h5>
                        <!-- [Y2 FIX] Guard item_variations -->
                        <p v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0"
                          class="text-xs font-normal font-client capitalize text-[#6E7191]">
                          <span v-for="(variation, index) in item.item_variations" :key="index" class="text-heading">
                            {{ variationLine(variation) }}<span
                              v-if="index + 1 < item.item_variations.length">,&nbsp;</span>
                          </span>
                        </p>
                        <!-- [N4 FIX] Was <li> without <ul> — replaced with <div> -->
                        <!-- [Y2 FIX] Guard item_extras -->
                        <div class="flex gap-1" v-if="Array.isArray(item.item_extras) && item.item_extras.length > 0">
                          <span class="capitalize text-xs w-fit whitespace-nowrap font-medium">{{ $t('label.extras') }}:</span>
                          <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                            <span v-for="(extra, index) in item.item_extras" :key="index" class="text-heading">
                              {{ extra.name }}<span v-if="index + 1 < item.item_extras.length">,&nbsp;</span>
                            </span>
                          </p>
                        </div>
                        <div class="flex gap-1" v-if="Array.isArray(item.item_addons) && item.item_addons.length > 0">
                          <span class="capitalize text-xs w-fit whitespace-nowrap font-medium">{{ $t('label.addons') }}:</span>
                          <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                            <span v-for="(addon, index) in item.item_addons" :key="index" class="text-heading">
                              {{ kdsAddonDisplayName(addon) }}<span v-if="Number(addon.quantity || 1) > 1"> ×{{ Number(addon.quantity || 1) }}</span><span v-if="index + 1 < item.item_addons.length">,&nbsp;</span>
                            </span>
                          </p>
                        </div>
                        <div
                          v-if="kdsCleanInstruction(item)"
                          :class="[kdsInstructionClass(kdsCleanInstruction(item)), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']"
                          style="white-space: pre-line"
                        >{{ kdsCleanInstruction(item) }}</div>
                      </div>
                      <div class="flex flex-col items-end gap-1 shrink-0 pt-0.5">
                        <!-- [iter15-mega-fix B-007 round-7 2026-05-10] Icon-only Prêt button needs aria-label for touch tablets / SR -->
                        <button v-if="!kdsIsBumped(onlineOrder.id, item.id)" type="button"
                          class="w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#F4501E] hover:bg-[#F4501E]/5"
                          :title="$t('button.kds_bump')"
                          :aria-label="`${$t('button.kds_bump')} — ${item.item_name}`"
                          @click.prevent.stop="kdsBump(onlineOrder, item)">
                          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </button>
                        <button v-else-if="kdsCanRecall(onlineOrder.id, item.id)" type="button"
                          class="text-[11px] font-semibold text-[#F4501E] underline decoration-[#F4501E]/50"
                          @click.prevent.stop="kdsRecall(onlineOrder, item)">
                          {{ $t('button.kds_recall') }}
                        </button>
                        <!-- [KDS-REPRINT 2026-07-13] Réimpression manuelle du ticket cuisine (ignore toggle auto + dé-dup). -->
                        <button type="button"
                          class="w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#6B7280] hover:bg-[#6B7280]/5"
                          :title="$t('label.kds_reprint')"
                          :aria-label="`${$t('label.kds_reprint')} — N°${onlineOrder.queue_number || onlineOrder.id}`"
                          data-testid="kds-legacy-reprint"
                          @click.prevent.stop="reprintKitchenTicket(onlineOrder)">
                          <i class="fa-solid fa-print" aria-hidden="true"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                  <!-- [iter15-mega-fix B-002 2026-05-10] CTAs hoisted out of accordion. -->
                  <div class="kds-card-cta mt-2" data-testid="kds-card-cta">
                    <button v-if="onlineOrder.status === enums.orderStatusEnum.ACCEPT" type="button"
                      @click="orderStatus(onlineOrder, enums.orderStatusEnum.PREPARING)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#F4501E] text-white">
                      {{ $t("label.start_preparing") }}
                    </button>
                    <button v-if="onlineOrder.status === enums.orderStatusEnum.PREPARING" type="button"
                      @click="orderStatus(onlineOrder, enums.orderStatusEnum.PREPARED)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white">
                      {{ $t("label.mark_done") }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="db-card rounded-[10px] h-fit">
            <div class="p-3 pb-2" :class="filteredTakeawayOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''">
              <h3 class="text-lg font-semibold">{{ $t("label.takeaway") }}</h3>
            </div>
            <div v-if="filteredTakeawayOrders.length === 0" class="p-3 text-sm text-[#6E7191]">
              Aucune commande à emporter en cours.
            </div>
            <div v-if="filteredTakeawayOrders.length > 0" class="p-3" v-for="takeawayOrder in filteredTakeawayOrders"
              :key="takeawayOrder.id">
              <div class="w-full rounded-lg border transition-colors border-[#EFF0F6] relative" :class="kdsWaitClass(takeawayOrder)"
                role="article"
                :aria-labelledby="'order-' + takeawayOrder.id + '-title'"
                data-kds-order-card="takeaway">
                <!-- [CV1-KDS-INFLIGHT-OOS-MARKER-001] OOS warning badge -->
                <span
                  v-if="kdsHasOosWarning(takeawayOrder)"
                  class="kds-oos-warning-badge"
                  data-testid="kds-oos-warning-badge"
                  :title="$t('label.kds_oos_warning_tooltip')"
                  :aria-label="$t('label.kds_oos_warning_aria')"
                  role="img"
                >&#9888; OOS</span>
                <button
                  v-if="orderHasAllergens(takeawayOrder)"
                  type="button"
                  class="kds-allergens-badge"
                  @click.prevent.stop="openAllergensModal(takeawayOrder)"
                  :aria-label="$t('label.kds_allergens_badge_aria')"
                >&#9888; {{ $t('label.kds_allergens_badge') }}</button>
                <div class="py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#F7F0FF]">
                  <div class="kds-card-header-meta text-[#2D1263]">
                    <i class="lab lab-processing lab-font-size-16 text-[#2D1263]"></i>
                    <span :id="'order-' + takeawayOrder.id + '-title'" class="text-sm font-normal">#{{ takeawayOrder.order_serial_no }}</span>
                    <span v-if="takeawayOrder.queue_number"
                      class="kds-source-pill kds-source-pill--queue">
                      N°{{ takeawayOrder.queue_number }}
                    </span>
                    <span v-if="isPaymentPendingCounter(takeawayOrder)" class="kds-counter-payment-badge">
                      PAIEMENT COMPTOIR - NON REGLE
                    </span>
                  </div>
                  <span class="py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize"
                    :class="orderStatusBadgeClasses(takeawayOrder.status)">{{
                      takeawayOrder.status === enums.orderStatusEnum.PREPARED ? $t("label.done") :
                        (takeawayOrder.status ===
                          enums.orderStatusEnum.ACCEPT ? $t("label.confirmed") : takeawayOrder.status_name)
                    }}</span>
                </div>
                <div class="w-full pt-2 pb-3 px-3">
                  <p class="text-sm font-normal leading-6 font-client capitalize text-[#6E7191]">
                    {{ $t("label.token_no") }}: <span class="text-heading font-medium">{{ takeawayOrder.token ?
                      takeawayOrder.token : $t("label.online") }}</span>
                  </p>
                  <p v-if="takeawayOrder.queue_number" class="text-sm font-normal leading-6 font-client text-[#6E7191]">
                    N° file: <span class="text-heading font-medium">{{ takeawayOrder.queue_number }}</span>
                  </p>
                  <!-- [Sprint H4 Z3-NEW-002/003 2026-05-17] Legacy delivery
                       block mirrored from onlineOrder lane. Helper gates on
                       order_type === DELIVERY so this only renders for
                       misclassified delivery orders that landed in the
                       takeaway lane — defensive UX for ?v2=0 rollback. -->
                  <div
                    v-if="kdsLegacyShouldShowDelivery(takeawayOrder)"
                    class="mt-1 mb-2 p-2 rounded-lg bg-[#F0FDFA] border-l-[3px] border-[#0F766E]"
                    data-testid="kds-legacy-delivery"
                  >
                    <p v-if="kdsLegacyDeliveryAddress(takeawayOrder)" class="text-xs leading-snug text-[#0F766E] font-semibold flex items-start gap-1">
                      <span aria-hidden="true">&#128205;</span>
                      <span class="break-words">{{ kdsLegacyDeliveryAddress(takeawayOrder) }}</span>
                    </p>
                    <p v-if="takeawayOrder.customer && takeawayOrder.customer.name" class="text-xs leading-snug text-[#115E59] flex items-center gap-1">
                      <span aria-hidden="true">&#128100;</span>
                      <span>{{ takeawayOrder.customer.name }}</span>
                    </p>
                    <!-- [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT -->
                    <a
                      v-if="takeawayOrder.customer && safePhone(takeawayOrder.customer.phone)"
                      :href="`tel:${safePhone(takeawayOrder.customer.phone)}`"
                      class="text-xs leading-snug text-[#0F766E] font-bold flex items-center gap-1 hover:underline keep-latin"
                    >
                      <span aria-hidden="true">&#128241;</span>
                      <span>{{ safePhone(takeawayOrder.customer.phone) }}</span>
                    </a>
                  </div>
                  <!-- [iter15-mega-fix B-002 2026-05-10] aria-expanded for SR. -->
                  <button type="button" @click="openFilterSlide($event)"
                    class="filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full"
                    :aria-expanded="false"
                    :aria-label="$t('label.kds_toggle_items') || 'Afficher les articles'">
                    <span>{{ kdsDisplayDateTime(takeawayOrder.order_datetime) }}</span>
                    <div
                      class="flex items-center justify-center w-6 h-6 rounded-full bg-[#FFE8DD] text-base font-semibold transition-all duration-500 group-hover:text-[#F4501E]">
                      <i class="icon text-[#F4501E] fa-solid fa-chevron-down"></i>
                    </div>
                  </button>
                  <div style="height: 0px" class="overflow-hidden transition-all duration-500">
                    <div v-for="(item, iIdx) in takeawayOrder.order_items" :key="item.id || iIdx"
                      class="flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none">
                      <h4 class="text-sm font-medium shrink-0">{{ item.quantity }}x</h4>
                      <div class="flex-1 min-w-0">
                        <h5 class="text-sm font-medium mb-1">{{ item.item_name }}</h5>
                        <!-- [Y2 FIX] Guard item_variations -->
                        <p v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0"
                          class="text-xs font-normal font-client capitalize text-[#6E7191]">
                          <span v-for="(variation, index) in item.item_variations" :key="index" class="text-heading">
                            {{ variationLine(variation) }}<span
                              v-if="index + 1 < item.item_variations.length">,&nbsp;</span>
                          </span>
                        </p>
                        <!-- [N4 FIX] Was <li> without <ul> — replaced with <div> -->
                        <!-- [Y2 FIX] Guard item_extras -->
                        <div class="flex gap-1" v-if="Array.isArray(item.item_extras) && item.item_extras.length > 0">
                          <span class="capitalize text-xs w-fit whitespace-nowrap font-medium">{{ $t('label.extras') }}:</span>
                          <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                            <span v-for="(extra, index) in item.item_extras" :key="index" class="text-heading">
                              {{ extra.name }}<span v-if="index + 1 < item.item_extras.length">,&nbsp;</span>
                            </span>
                          </p>
                        </div>
                        <div class="flex gap-1" v-if="Array.isArray(item.item_addons) && item.item_addons.length > 0">
                          <span class="capitalize text-xs w-fit whitespace-nowrap font-medium">{{ $t('label.addons') }}:</span>
                          <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                            <span v-for="(addon, index) in item.item_addons" :key="index" class="text-heading">
                              {{ kdsAddonDisplayName(addon) }}<span v-if="Number(addon.quantity || 1) > 1"> ×{{ Number(addon.quantity || 1) }}</span><span v-if="index + 1 < item.item_addons.length">,&nbsp;</span>
                            </span>
                          </p>
                        </div>
                        <!-- [P3-1 FIX] Show instruction on takeaway cards -->
                        <div
                          v-if="kdsCleanInstruction(item)"
                          :class="[kdsInstructionClass(kdsCleanInstruction(item)), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']"
                          style="white-space: pre-line"
                        >{{ kdsCleanInstruction(item) }}</div>
                      </div>
                      <div class="flex flex-col items-end gap-1 shrink-0 pt-0.5">
                        <!-- [iter15-mega-fix B-007 round-7 2026-05-10] Icon-only Prêt button needs aria-label for touch tablets / SR -->
                        <button v-if="!kdsIsBumped(takeawayOrder.id, item.id)" type="button"
                          class="w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#F4501E] hover:bg-[#F4501E]/5"
                          :title="$t('button.kds_bump')"
                          :aria-label="`${$t('button.kds_bump')} — ${item.item_name}`"
                          @click.prevent.stop="kdsBump(takeawayOrder, item)">
                          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </button>
                        <button v-else-if="kdsCanRecall(takeawayOrder.id, item.id)" type="button"
                          class="text-[11px] font-semibold text-[#F4501E] underline decoration-[#F4501E]/50"
                          @click.prevent.stop="kdsRecall(takeawayOrder, item)">
                          {{ $t('button.kds_recall') }}
                        </button>
                        <!-- [KDS-REPRINT 2026-07-13] Réimpression manuelle du ticket cuisine (ignore toggle auto + dé-dup). -->
                        <button type="button"
                          class="w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#6B7280] hover:bg-[#6B7280]/5"
                          :title="$t('label.kds_reprint')"
                          :aria-label="`${$t('label.kds_reprint')} — N°${takeawayOrder.queue_number || takeawayOrder.id}`"
                          data-testid="kds-legacy-reprint"
                          @click.prevent.stop="reprintKitchenTicket(takeawayOrder)">
                          <i class="fa-solid fa-print" aria-hidden="true"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                  <!-- [iter15-mega-fix B-002 2026-05-10] CTAs hoisted out of accordion. -->
                  <div class="kds-card-cta mt-2" data-testid="kds-card-cta">
                    <button v-if="takeawayOrder.status === enums.orderStatusEnum.ACCEPT" type="button"
                      @click="orderStatus(takeawayOrder, enums.orderStatusEnum.PREPARING)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#F4501E] text-white">
                      {{ $t("label.start_preparing") }}
                    </button>
                    <button v-if="takeawayOrder.status === enums.orderStatusEnum.PREPARING" type="button"
                      @click="orderStatus(takeawayOrder, enums.orderStatusEnum.PREPARED)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white">
                      {{ $t("label.mark_done") }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Borne (Kiosk) orders column -->
          <div class="db-card rounded-[10px] h-fit">
            <div class="p-3 pb-2 flex items-center gap-2" :class="filteredKioskOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''">
              <h3 class="text-lg font-semibold">🖥️ Borne</h3>
              <span v-if="filteredKioskOrders.length > 0"
                class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-[#991B1B] text-white text-[11px] font-bold">
                {{ filteredKioskOrders.length }}
              </span>
            </div>
            <div v-if="filteredKioskOrders.length === 0" class="p-3 text-sm text-[#6E7191]">
              Aucune commande borne en cours.
            </div>
            <div v-if="filteredKioskOrders.length > 0" class="p-3" v-for="kioskOrder in filteredKioskOrders" :key="kioskOrder.id">
              <div class="w-full rounded-lg border transition-colors border-[#EFF0F6] relative" :class="kdsWaitClass(kioskOrder)"
                role="article"
                :aria-labelledby="'order-' + kioskOrder.id + '-title'"
                data-kds-order-card="kiosk">
                <!-- [CV1-KDS-INFLIGHT-OOS-MARKER-001] OOS warning badge -->
                <span
                  v-if="kdsHasOosWarning(kioskOrder)"
                  class="kds-oos-warning-badge"
                  data-testid="kds-oos-warning-badge"
                  :title="$t('label.kds_oos_warning_tooltip')"
                  :aria-label="$t('label.kds_oos_warning_aria')"
                  role="img"
                >&#9888; OOS</span>
                <button
                  v-if="orderHasAllergens(kioskOrder)"
                  type="button"
                  class="kds-allergens-badge"
                  @click.prevent.stop="openAllergensModal(kioskOrder)"
                  :aria-label="$t('label.kds_allergens_badge_aria')"
                >&#9888; {{ $t('label.kds_allergens_badge') }}</button>
                <div class="py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#FFF0EE]">
                  <div class="kds-card-header-meta text-[#991B1B]">
                    <i class="lab lab-processing lab-font-size-16 text-[#991B1B]"></i>
                    <span :id="'order-' + kioskOrder.id + '-title'" class="text-sm font-normal">#{{ kioskOrder.order_serial_no }}</span>
                    <span v-if="kioskOrder.queue_number"
                      class="kds-source-pill kds-source-pill--queue">
                      N°{{ kioskOrder.queue_number }}
                    </span>
                    <span v-if="isPaymentPendingCounter(kioskOrder)" class="kds-counter-payment-badge">
                      PAIEMENT COMPTOIR - NON REGLE
                    </span>
                  </div>
                  <span class="py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize"
                    :class="orderStatusBadgeClasses(kioskOrder.status)">
                    {{ kioskOrder.status === enums.orderStatusEnum.PREPARED ? $t('label.done') :
                      (kioskOrder.status === enums.orderStatusEnum.ACCEPT ? $t('label.confirmed') : kioskOrder.status_name) }}
                  </span>
                </div>
                <div class="w-full pt-2 pb-3 px-3">
                  <!-- [Sprint H4 Z3-NEW-002/003 2026-05-17] Legacy delivery
                       block mirrored from onlineOrder lane. Helper gates on
                       order_type === DELIVERY so this only renders for
                       misclassified delivery orders that landed in the
                       kiosk lane — defensive UX for ?v2=0 rollback. -->
                  <div
                    v-if="kdsLegacyShouldShowDelivery(kioskOrder)"
                    class="mt-1 mb-2 p-2 rounded-lg bg-[#F0FDFA] border-l-[3px] border-[#0F766E]"
                    data-testid="kds-legacy-delivery"
                  >
                    <p v-if="kdsLegacyDeliveryAddress(kioskOrder)" class="text-xs leading-snug text-[#0F766E] font-semibold flex items-start gap-1">
                      <span aria-hidden="true">&#128205;</span>
                      <span class="break-words">{{ kdsLegacyDeliveryAddress(kioskOrder) }}</span>
                    </p>
                    <p v-if="kioskOrder.customer && kioskOrder.customer.name" class="text-xs leading-snug text-[#115E59] flex items-center gap-1">
                      <span aria-hidden="true">&#128100;</span>
                      <span>{{ kioskOrder.customer.name }}</span>
                    </p>
                    <!-- [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT -->
                    <a
                      v-if="kioskOrder.customer && safePhone(kioskOrder.customer.phone)"
                      :href="`tel:${safePhone(kioskOrder.customer.phone)}`"
                      class="text-xs leading-snug text-[#0F766E] font-bold flex items-center gap-1 hover:underline keep-latin"
                    >
                      <span aria-hidden="true">&#128241;</span>
                      <span>{{ safePhone(kioskOrder.customer.phone) }}</span>
                    </a>
                  </div>
                  <!-- [iter15-mega-fix B-002 2026-05-10] aria-expanded for SR. -->
                  <button type="button" @click="openFilterSlide($event)"
                    class="filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full"
                    :aria-expanded="false"
                    :aria-label="$t('label.kds_toggle_items') || 'Afficher les articles'">
                    <span v-if="kioskOrder.queue_number" class="text-xs font-medium text-heading">N° file: {{ kioskOrder.queue_number }}</span>
                    <span>{{ kdsDisplayDateTime(kioskOrder.order_datetime) }}</span>
                    <div class="flex items-center justify-center w-6 h-6 rounded-full bg-[#FFE8DD] text-base font-semibold transition-all duration-500 group-hover:text-[#F4501E]">
                      <i class="icon text-[#F4501E] fa-solid fa-chevron-down"></i>
                    </div>
                  </button>
                  <div style="height: 0px" class="overflow-hidden transition-all duration-500">
                    <div v-for="(item, iIdx) in kioskOrder.order_items" :key="item.id || iIdx"
                      class="flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none">
                      <h4 class="text-sm font-medium shrink-0">{{ item.quantity }}x</h4>
                      <div class="flex-1 min-w-0">
                        <h5 class="text-sm font-medium mb-1">{{ item.item_name }}</h5>
                        <!-- [Y2 FIX] Guard item_variations -->
                        <p v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0"
                          class="text-xs font-normal font-client capitalize text-[#6E7191]">
                          <span v-for="(variation, index) in item.item_variations" :key="index" class="text-heading">
                            {{ variationLine(variation) }}<span
                              v-if="index + 1 < item.item_variations.length">,&nbsp;</span>
                          </span>
                        </p>
                        <!-- [Y2 FIX] Guard item_extras -->
                        <div class="flex gap-1" v-if="Array.isArray(item.item_extras) && item.item_extras.length > 0">
                          <span class="capitalize text-xs w-fit whitespace-nowrap font-medium">{{ $t('label.extras') }}:</span>
                          <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                            <span v-for="(extra, index) in item.item_extras" :key="index" class="text-heading">
                              {{ extra.name }}<span v-if="index + 1 < item.item_extras.length">,&nbsp;</span>
                            </span>
                          </p>
                        </div>
                        <div class="flex gap-1" v-if="Array.isArray(item.item_addons) && item.item_addons.length > 0">
                          <span class="capitalize text-xs w-fit whitespace-nowrap font-medium">{{ $t('label.addons') }}:</span>
                          <p class="text-xs font-normal font-client capitalize text-[#6E7191]">
                            <span v-for="(addon, index) in item.item_addons" :key="index" class="text-heading">
                              {{ kdsAddonDisplayName(addon) }}<span v-if="Number(addon.quantity || 1) > 1"> ×{{ Number(addon.quantity || 1) }}</span><span v-if="index + 1 < item.item_addons.length">,&nbsp;</span>
                            </span>
                          </p>
                        </div>
                        <div v-if="Array.isArray(item.allergens_snapshot) && item.allergens_snapshot.length > 0" class="mt-2 flex flex-wrap gap-1">
                          <span
                            v-for="(allergen, allergenIdx) in item.allergens_snapshot"
                            :key="`${item.id || iIdx}-allergen-${allergenIdx}`"
                            class="rounded-full bg-[#FFF3E8] px-2 py-0.5 text-[11px] font-medium uppercase tracking-[0.02em] text-[#C25D1B]"
                          >
                            {{ allergen }}
                          </span>
                        </div>
                        <div
                          v-if="kdsCleanInstruction(item)"
                          :class="[kdsInstructionClass(kdsCleanInstruction(item)), 'kds-instruction', 'mt-1', 'text-xs', 'text-heading']"
                          style="white-space: pre-line"
                        >{{ kdsCleanInstruction(item) }}</div>
                      </div>
                      <div class="flex flex-col items-end gap-1 shrink-0 pt-0.5">
                        <!-- [iter15-mega-fix B-007 round-7 2026-05-10] Icon-only Prêt button needs aria-label for touch tablets / SR -->
                        <button v-if="!kdsIsBumped(kioskOrder.id, item.id)" type="button"
                          class="w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#F4501E] hover:bg-[#F4501E]/5"
                          :title="$t('button.kds_bump')"
                          :aria-label="`${$t('button.kds_bump')} — ${item.item_name}`"
                          @click.prevent.stop="kdsBump(kioskOrder, item)">
                          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </button>
                        <button v-else-if="kdsCanRecall(kioskOrder.id, item.id)" type="button"
                          class="text-[11px] font-semibold text-[#F4501E] underline decoration-[#F4501E]/50"
                          @click.prevent.stop="kdsRecall(kioskOrder, item)">
                          {{ $t('button.kds_recall') }}
                        </button>
                        <!-- [KDS-REPRINT 2026-07-13] Réimpression manuelle du ticket cuisine (ignore toggle auto + dé-dup). -->
                        <button type="button"
                          class="w-8 h-8 rounded-lg border border-[#D9DBE9] flex items-center justify-center text-[#6B7280] hover:bg-[#6B7280]/5"
                          :title="$t('label.kds_reprint')"
                          :aria-label="`${$t('label.kds_reprint')} — N°${kioskOrder.queue_number || kioskOrder.id}`"
                          data-testid="kds-legacy-reprint"
                          @click.prevent.stop="reprintKitchenTicket(kioskOrder)">
                          <i class="fa-solid fa-print" aria-hidden="true"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                  <!-- [iter15-mega-fix B-002 2026-05-10] CTAs hoisted out of accordion. -->
                  <div class="kds-card-cta mt-2" data-testid="kds-card-cta">
                    <button v-if="kioskOrder.status === enums.orderStatusEnum.ACCEPT" type="button"
                      @click="orderStatus(kioskOrder, enums.orderStatusEnum.PREPARING)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#F4501E] text-white">
                      {{ $t('label.start_preparing') }}
                    </button>
                    <button v-if="kioskOrder.status === enums.orderStatusEnum.PREPARING" type="button"
                      @click="orderStatus(kioskOrder, enums.orderStatusEnum.PREPARED)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white">
                      {{ $t('label.mark_done') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
    <!-- [CV1-KDS-A11Y-RICH-001] Off-screen aria-live region for transition
         announcements (ACCEPT → PREPARING → PREPARED). Polite politeness so
         it does not interrupt the cuisinier's current focus, and visually
         hidden so it never reflows the kitchen layout. -->
    <div
      class="sr-only kds-aria-live"
      data-testid="kds-aria-live"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >{{ kdsAriaLiveMessage }}</div>
    <!-- [F-03 / Lot 1.C] Adaptive sync badge: reassures the kitchen that the
         board is up to date even when WebSocket is degraded. Color shifts to
         orange after 30s without sync. -->
    <div class="kds-sync-footer">
      <span
        class="kds-sync-stamp"
        :class="{ 'kds-sync-stamp--stale': syncBadgeIsStale }"
        :title="syncBadgeText"
      >
        {{ syncBadgeText }}
      </span>
    </div>
    <div
      v-if="allergensModal.open"
      ref="allergensModalRoot"
      class="kds-allergens-modal-overlay"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('label.kds_allergens_modal_title', { order_id: allergensModal.order && allergensModal.order.id ? allergensModal.order.id : '' })"
      tabindex="-1"
      @click.self="closeAllergensModal"
      @keydown.esc="closeAllergensModal"
      @keydown="onAllergensModalKeydown"
    >
      <div class="kds-allergens-modal-content">
        <header class="kds-allergens-modal-header">
          <h2>{{ $t('label.kds_allergens_modal_title', { order_id: allergensModal.order && allergensModal.order.id ? allergensModal.order.id : '' }) }}</h2>
          <button
            ref="allergensModalCloseButton"
            type="button"
            class="kds-allergens-modal-close" :aria-label="$t('button.close')"
            @click="closeAllergensModal"
          >{{ $t('button.kds_allergens_modal_close') }}</button>
        </header>
        <p class="kds-allergens-modal-intro">{{ $t('label.kds_allergens_modal_intro') }}</p>
        <ul class="kds-allergens-modal-list">
          <li
            v-for="(orderItem, allergenIndex) in allergensModalItems"
            :key="(orderItem.id || orderItem.item_id || allergenIndex) + '-' + allergenIndex"
            class="kds-allergens-modal-list-item"
          >
            <strong>{{ orderItem.item_name || orderItem.name || (orderItem.item && orderItem.item.name) || '-' }}</strong>
            <span>{{ sortedAllergens(orderItem.allergens_snapshot).length
              ? sortedAllergens(orderItem.allergens_snapshot).join(' \u00B7 ')
              : $t('label.kds_allergens_modal_none') }}</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
  </template>
</template>
<script>
import LoadingComponent from "../components/LoadingComponent.vue";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import statusEnum from "../../../enums/modules/statusEnum";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import paymentStatusEnum from "../../../enums/modules/paymentStatusEnum";
import askEnum from "../../../enums/modules/askEnum";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
import { onEvents } from "../../../services/eventContract";
import kdsSyncService from "../../../services/KdsSyncService";
import { Swiper, SwiperSlide } from "swiper/vue";
import ConnectionStatusBanner from "../../common/ConnectionStatusBanner.vue";
import { kdsStatusPayload } from "../../../store/modules/kds";
import {
  filterOrdersByStation,
  getKdsEscalationClass,
  kdsStationFilterStorageKey,
  parseOrderCreatedMs,
  shouldPlayKdsNewOrderSound,
} from "../../../helpers/kdsDisplay";
import { kdsInstructionVisualClass } from "../../../helpers/kdsLineSemantics";
import { orderHasAllergens as kdsOrderHasAllergens, sortedAllergens as kdsSortedAllergens } from "../../../helpers/kdsAllergens";
import { ORDER_STATUS } from "../../../helpers/kdsState";
// [POS-WIZARD-COMPO-AUDIT 2026-06-23 P2-B] Shape-agnostic GROUP:VALUE render so
// snapshot-shaped composition lines (attribute_name=GROUP / variation_name=VALUE)
// no longer invert on the KDS order-cards + kitchen ticket. Legacy items-board
// shape stays correct through the same helper.
import { kdsVariationLine, sanitizeKdsInstruction } from "../../../helpers/kdsCustomization";
import { freshnessBaseMs as kdsFreshnessBaseMs, isSyncStale as kdsIsSyncStale, isSyncUncertain as kdsIsSyncUncertain, humanizeSyncAgo as kdsHumanizeSyncAgo } from "../../../helpers/kdsFreshness";
// [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT — mirrors App\Support\PhoneDisplay::safe
import { safePhone } from "../../../helpers/phoneDisplay";
// [KITCHEN-BRIDGE 2026-07-09] Auto-impression SILENCIEUSE du ticket cuisine via le
// pont local (miroir du pont caisse). Dé-dup persistée localStorage (jamais 2× le
// même ticket au refresh/reconnexion). Best-effort : pont éteint = jamais bloquant.
import { printEscPosViaKitchenBridge, hasKitchenPrinted, markKitchenPrinted, seedKitchenPrinted, getKitchenFailed, setKitchenFailed } from "../../../helpers/kitchenLocalPrinter";
// [KITCHEN-PRINT-RESILIENCE 2026-07-13] Cadence du retry auto des tickets cuisine
// en échec (pont/imprimante injoignable). Quand le pont revient, les tickets manqués
// SORTENT tout seuls sans intervention.
const KITCHEN_PRINT_RETRY_MS = 20000;
// [kds/sprint-2 V-5] V2 layout components — feature-flagged single FIFO 4×2 grid.
import KdsV2Grid from "./KdsV2Grid.vue";
// [Wave X3 2026-05-21] KDS Historique du jour — read-only day-history drawer.
import KdsHistoryDrawer from "./KdsHistoryDrawer.vue";
// [GOAL RUPTURE-CARNET 2026-07-15 / W3] Panel rupture (86) partagé caisse+cuisine.
import AvailabilityTogglePanel from "../shared/AvailabilityTogglePanel.vue";

// [Phase-7 / T13–T14] Fil cuisine : stations, filtre, bump / statut, timers
// d’attente (kdsDisplay), son — ne pas mélanger avec de la logique de caisse
// OrderService ici (GATE plan). Polling 5s si WS down.

export default {
  name: "KitchenDisplaySystemComponent",
  components: {
    ConnectionStatusBanner,
    LoadingComponent,
    Swiper,
    SwiperSlide,
    KdsV2Grid,
    KdsHistoryDrawer,
    // [GOAL RUPTURE-CARNET 2026-07-15 / W3] Rupture produits (86) cuisine.
    AvailabilityTogglePanel,
  },
  data() {
    return {
      loading: {
        isActive: false,
      },
      // [GOAL RUPTURE-CARNET 2026-07-15 / W3] Panel rupture produits (86).
      showAvailabilityPanel: false,
      props: {
        search: {
          paginate: 0,
          order_column: "id",
          order_by: "desc",
          order_serial_no: "",
          status: "",
        },
      },
      dineinOrders: [],
      onlineOrders: [],
      takeawayOrders: [],
      kioskOrders: [],
      enums: {
        statusEnum: statusEnum,
        orderTypeEnum: orderTypeEnum,
        orderStatusEnum: orderStatusEnum,
        paymentStatusEnum: paymentStatusEnum,
        askEnum: askEnum,
      },
      autoRefreshInterval: null,
      wsConnected: !!(window._wsService?.isConnected()),
      _eventSub: null,
      // [F-02] Order ids that just had their dining-table changed; cards in this
      // set get a 2s CSS flash so the kitchen notices the table moved (gate G-2:
      // in_place_with_css_flash — never re-print, never play a sound).
      flashTableChangeIds: {},
      _tableFlashTimers: {},
      stationFilter: "all",
      groupByTable: false,
      soundEnabled: true,
      soundVolume: 80,
      // [KITCHEN-BRIDGE 2026-07-09] Toggle impression cuisine auto (défaut ON, owner).
      autoPrintKitchen: true,
      // Garde de seed du backlog (une seule fois par montage) — évite la ré-impression
      // massive des commandes déjà présentes au 1er chargement / reconnexion.
      _kitchenBacklogSeeded: false,
      // [KITCHEN-PRINT-RESILIENCE 2026-07-13] Ids (String) des commandes dont le ticket
      // cuisine a ÉCHOUÉ (pont/imprimante injoignable) → réactif : alimente le badge
      // d'échec + la boucle de retry. Un id sort d'ici dès qu'il est imprimé (succès
      // direct ou retry) ou que sa commande disparaît du board.
      _kitchenFailedPrint: [],
      // Garde in-flight (mémoire, non persistée) : bloque le double-envoi d'un même
      // ticket pendant l'await (burst WS ou chevauchement watch/retry).
      _kitchenInFlight: new Set(),
      // Interval du retry auto des tickets en échec (nettoyé en beforeUnmount).
      _kitchenRetryInterval: null,
      /** forces border timer class recompute (orange → red) */
      waitTick: 0,
      expandedTableGroups: {},
      _kdsWaitInterval: null,
      _kdsOrdersHydrated: false,
      // [iter15-mega-fix C-017 2026-05-10] Axios response interceptor id for
      // KDS change-status self-heal (see mounted/beforeUnmount).
      _kdsStatusInterceptorId: null,
      kdsHideBumpInfo: false,
      // [F-03 / Lot 1.C] Adaptive polling fallback metadata: keep listener
      // unsubscribers and the per-second tick used to update the "Synchronized
      // Xs ago" badge. The KdsSyncService itself manages cadence based on
      // wsService state — we only consume its `sync` events here.
      kdsSyncUnsubscribers: [],
      syncNowTick: Date.now(),
      _kdsSyncStampTimer: null,
      // [CLUSTER-4 P2 2026-07-11] Timestamp (epoch-ms) of the last SUCCESSFUL
      // board hydrate — Echo broadcast, fallback poll, or list(). Stamped in
      // _applyOrderBuckets. Distinct from wsConnected (socket state, which lies
      // about data freshness when the queue worker dies but soketi stays up).
      // null until the first successful refresh. Combined with the sync-service
      // poll clock (kdsSyncService.lastSyncAt) to drive the freshness badge.
      lastDataFreshAt: null,
      // [Lot 2.I / G-4] Non-blocking allergens modal state. Opened from the
      // ⚠ Allergens badge on each order-card. Purely informational — does NOT
      // gate kdsBump / kdsRecall / orderStatus / printKitchenTicket.
      allergensModal: {
        open: false,
        order: null,
      },
      // [Audit 2.I F-02] element to return focus to when modal closes (badge / background).
      allergenModalReturnFocus: null,
      // [Lot 2.C / F-07] Throttle new-order chime when many orders land at once.
      _kdsLastNewOrderSoundAt: 0,
      kdsOverflowDetected: false,
      // [Wave X3 2026-05-21] KDS Historique du jour drawer — open state.
      historyDrawerOpen: false,
      // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
      // Local cache of orders recalled by this branch in the last 60s. Populated
      // by the @recalled emit from KdsHistoryDrawer (the chef who clicked) AND
      // by the `KdsOrderRecalled` Echo handler (other stations of the same branch).
      // KdsV2Grid reads `recallActiveIds` (a computed array of currently-active
      // ids) and applies the RAPPELÉ badge accordingly. Entries auto-expire 60s
      // after `recalledAt` via the computed (no setInterval needed — the global
      // `now` ticker in KdsV2Grid drives reactivity downstream).
      kdsRecalledMap: {},
      // Companion ticker for the 60s recall TTL — independent of the grid's
      // own ticker so this component can compute `recallActiveIds` without
      // reading into the child. Cleared in beforeUnmount.
      _kdsRecallNow: Date.now(),
      _kdsRecallTickerId: null,
      // [CV1-KDS-A11Y-RICH-001] Polite aria-live message that announces
      // ACCEPT → PREPARING → PREPARED transitions to assistive technology.
      // Updated by `kdsAnnounceTransition`; rendered in the dedicated
      // `<div role="status" aria-live="polite">` outside the cards.
      kdsAriaLiveMessage: '',
      // [test-e2e round-2 cluster-1 C-001 2026-05-10] Persistent error banner
      // shown when /api/admin/kds-order returns 5xx (or any failure that
      // would otherwise have raised an ephemeral toast). The previous
      // alertService.error() Vue-Toastification toast faded within ~500ms
      // bounce-leave animation; a kitchen operator at 1m+ glance never
      // saw it. The banner stays visible until the next successful poll
      // OR until the operator dismisses it manually.
      kdsErrorBanner: {
        visible: false,
        message: '',
        lastRetryAt: null,
      },
      // [kds/sprint-2 V-5] Feature-flag scaffolding for the V2 single-FIFO grid.
      // Owner GO confirmed 2026-05-11. Activation:
      //   - URL `?v2=1` (per-session preview)
      //   - localStorage `kds.v2_enabled` = '1' (per-device opt-in)
      // [Wave Q-2 2026-05-20] Auto-transition forced OFF. Owner override of the
      // RESEARCH §4.3 single-chef heuristic: when two paid tickets land, the
      // first auto-promoted to PREPARING and the second stayed in ACCEPT,
      // creating an inconsistent suivi page (one in PRÉPARATION, one in
      // CONFIRMÉE). Owner expects ALL tickets to follow CONFIRMÉE → EN
      // PRÉPARATION → PRÊT under explicit chef action. The chef now taps Prêt
      // twice on a fresh ticket (advance, then ready) — single-step ladder
      // matches the server `OrderStateMachine::allows` rule.
      v2OfflineSince: null,
      v2AutoTransitionEnabled: false,
    };
  },
  computed: {
    direction() {
      return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
    },
    // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
    // Currently-active recall ids (RAPPELÉ badge window still open). Derived
    // from `kdsRecalledMap` + the 60s TTL using `_kdsRecallNow` so the badge
    // auto-disappears at expiry without an explicit cleanup pass. Passed down
    // to `KdsV2Grid` via `recall-active-ids` and to the legacy 4-col layout
    // via the same prop.
    recallActiveIds() {
      const ttlMs = 60 * 1000;
      const now = this._kdsRecallNow;
      const ids = [];
      const map = this.kdsRecalledMap || {};
      for (const key of Object.keys(map)) {
        const at = map[key];
        if (typeof at === 'number' && (now - at) < ttlMs) {
          ids.push(parseInt(key, 10));
        }
      }
      return ids;
    },
    // [kds/sprint-2 V-5 / Sprint 3C 2026-05-16 / Sprint H4 Z3-NEW-006 2026-05-17]
    // V2 layout = PRODUCTION DEFAULT since 2026-05-16. The original gated rollout
    // (default false, ?v2=1 opt-in) left the V2 P0 fixes (consolidated banner,
    // fixed-height card, allergen pill, age stripe, single-FIFO grid) inert in
    // production — kitchen still saw the legacy 4-column UI with the
    // accordion-closed-by-default bug + 5 stacked banners eating ~10% screen height.
    //
    // Order of precedence (Z3-NEW-006 added the org config layer so operators
    // can rollback the whole org via KDS_V2_DEFAULT_ENABLED=false in .env
    // instead of per-tab localStorage flipping each device):
    //   1. URL param ?v2=0           — emergency rollback to legacy (per-tab)
    //   2. URL param ?v2=1           — explicit V2 (per-tab)
    //   3. localStorage kds.v2_enabled === '0' — per-device legacy opt-out
    //   4. localStorage kds.v2_enabled === '1' — per-device V2 force-on (legacy)
    //   5. window.FK_KDS_V2_DEFAULT_ENABLED — org config (Blade-injected from
    //      config('kds.v2_default_enabled') / KDS_V2_DEFAULT_ENABLED env)
    //   6. Default true              — V2 (Wave Z 5C rollout default)
    //
    // The legacy 4-column path remains intact in the v-else branch so
    // operators in the kitchen can revert in one URL keystroke if a
    // regression surfaces during the rollout window.
    useV2Layout() {
      try {
        if (typeof window === 'undefined') {
          // SSR or unit tests without window: default to V2 unchanged.
          return true;
        }
        const params = new URLSearchParams(window.location.search || '');
        const urlV2 = params.get('v2');
        if (urlV2 === '0') {
          return false;
        }
        if (urlV2 === '1') {
          return true;
        }
        const stored = window.localStorage?.getItem('kds.v2_enabled');
        if (stored === '0') {
          return false;
        }
        if (stored === '1') {
          return true;
        }
        // [Z3-NEW-006] Org-wide config layer between localStorage and hardcoded
        // fallback. Lets ops flip KDS_V2_DEFAULT_ENABLED=false in .env to
        // rollback the whole fleet without touching each device.
        if (typeof window.FK_KDS_V2_DEFAULT_ENABLED === 'boolean') {
          return window.FK_KDS_V2_DEFAULT_ENABLED;
        }
        return true;
      } catch (_e) {
        // Fail-safe: if storage is denied, still render the modern UI.
        return true;
      }
    },
    // [PR-02 core-bulletproof 2026-06-04] Sync degradation must NEVER be silent
    // (owner mandate "silencieux = grave"). The real Le Cayenne box runs
    // APP_ENV=local, so the previous "hide whenever appEnv==='local'" gate left
    // the kitchen with NO visual cue when soketi dropped to ~30-60s polling.
    //
    // FAIL-SAFE-TO-VISIBLE opt-out design (NOT opt-in): suppress the fallback
    // banner ONLY when (a) we are in local dev AND (b) an explicit opt-out flag
    // window.FK_KDS_SHOW_FALLBACK_BANNER === false is set. The box never sets
    // the flag → it stays undefined → `=== false` is false → NOT suppressed →
    // banner VISIBLE. A dev who wants silence (soketi intentionally off in dev)
    // adds KDS_SHOW_FALLBACK_BANNER=false to .env. The dangerous state (silent
    // degradation) is opt-out, never opt-in.
    //
    // Renamed from kdsHideFallbackBannerInLocalDev → kdsSuppressFallbackBanner:
    // the old name implied "hide in local dev" which is no longer the contract.
    //
    // The `env === 'local'` check stays FIRST so && short-circuits before
    // touching window.FK_* (SSR-safe). The catch falls back to `false`
    // (fail-safe-to-visible). 'testing' is intentionally excluded (env !==
    // 'local') so any CI suite using APP_ENV=testing always renders the banner.
    //
    // Config contract documented in config/kds.php ('show_fallback_banner',
    // default true). NOTE: wiring the config through master.blade.php into
    // window.FK_KDS_SHOW_FALLBACK_BANNER is deferred to avoid colliding with a
    // parallel session editing master.blade.php — until then the box default
    // (flag undefined → VISIBLE) already satisfies the mandate.
    //
    // Existing Playwright specs referencing data-testid="kds-sync-mode-banner"
    // are unaffected: abuse-C-kds:369 toHaveCount(0) runs in V2 (legacy testid
    // never mounts) AND with WS connected (v-if !wsConnected is false anyway);
    // all other refs are OR-locators / soft .count() records.
    kdsSuppressFallbackBanner() {
      try {
        const env = (typeof window !== 'undefined' && window.foodkingConfig?.appEnv) || '';
        return env === 'local' && window.FK_KDS_SHOW_FALLBACK_BANNER === false;
      } catch (_e) {
        return false;
      }
    },
    // [Wave T R1 F3 WT-B-R1-007 2026-05-20] Gate the "Compte central" polling
    // hint on (a) admin/central role (branch_id<=0) AND (b) multi-branch
    // install (branchCount>1). On single-branch installs like Le Cayenne the
    // banner copy "Compte central (multi-succursales) : le flux en direct
    // n'est pas abonné" is misleading — there is no multi-branch context AND
    // the wording "n'est pas abonné" reads as a real-time sync failure to a
    // chef. We hide the banner entirely when only one branch exists; the
    // central admin still sees the standard sync stamp + fallback banner if
    // the WS is actually down. branchCount is exposed by master.blade.php
    // (cached 5min) so this is a zero-network check.
    kdsIsCentralAdmin() {
      const isCentralRole = this.authBranchId() <= 0;
      if (!isCentralRole) {
        return false;
      }
      try {
        const count = parseInt(
          (typeof window !== 'undefined' && window.foodkingConfig?.branchCount) || 0,
          10
        );
        // Multi-branch install only — single-branch (or unknown count) hides.
        return Number.isFinite(count) && count > 1;
      } catch (_e) {
        // Fail-safe: if config probe throws, do NOT show the misleading banner.
        return false;
      }
    },
    /** 45–49: backend plafond 50 — avertir avant d’atteindre la limite d’affichage */
    kdsOrderApproachingCap() {
      const n = Array.isArray(this.orders) ? this.orders.length : 0;
      return n >= 45 && n < 50;
    },
    /** Backend probed >50 active rows and returned the capped first page. */
    kdsOrderListAtCap() {
      return this.kdsOverflowDetected === true;
    },
    // [F-03 / Lot 1.C · CLUSTER-4 P2 2026-07-11] Unified data-freshness clock.
    // The badge is fed by the FRESHEST of two successful-refresh clocks:
    //   - this.lastDataFreshAt : board hydrated (Echo broadcast / poll / list)
    //   - kdsSyncService.lastSyncAt : sync-service poll returned 200
    // wsConnected (socket state) is deliberately NOT used: it stays "connected"
    // when the queue worker dies, so it lies about data freshness. Touching
    // this.syncNowTick makes this computed recompute every 1s AND re-read the
    // non-reactive kdsSyncService.lastSyncAt.
    kdsFreshnessBaseMs() {
      void this.syncNowTick; // 1s tick → recompute + re-read external clock
      return kdsFreshnessBaseMs({
        lastDataFreshAt: this.lastDataFreshAt,
        lastSyncAt: kdsSyncService.lastSyncAt,
      });
    },
    humanizedSyncAgo() {
      return kdsHumanizeSyncAgo(this.kdsFreshnessBaseMs, this.syncNowTick);
    },
    // [CLUSTER-4 P2] Hard-stall tier: no confirmed refresh for >75s (two missed
    // 60s poll cycles). The board may be materially behind → say so explicitly
    // instead of implying the data is live.
    syncBadgeUncertain() {
      return kdsIsSyncUncertain(this.kdsFreshnessBaseMs, this.syncNowTick);
    },
    syncBadgeText() {
      if (this.syncBadgeUncertain) return this.$t("label.kds_sync_uncertain");
      if (!this.humanizedSyncAgo) return this.$t("label.kds_sync_never");
      return this.$t("label.kds_sync_stamp", { ago: this.humanizedSyncAgo });
    },
    syncBadgeIsStale() {
      return kdsIsSyncStale(this.kdsFreshnessBaseMs, this.syncNowTick);
    },
    orders: function () {
      return this.$store.getters["kitchenDisplaySystemOrder/lists"];
    },
    // [KITCHEN-PRINT-RESILIENCE 2026-07-13] Nombre de tickets cuisine en échec —
    // pilote le badge d'avertissement en en-tête KDS.
    kitchenPrintFailedCount() {
      return Array.isArray(this._kitchenFailedPrint) ? this._kitchenFailedPrint.length : 0;
    },
    orderItems: function () {
      return this.$store.getters["kitchenDisplaySystemOrder/orderItems"];
    },
    filteredDineinOrders() {
      return filterOrdersByStation(this.dineinOrders, this.stationFilter);
    },
    filteredOnlineOrders() {
      return filterOrdersByStation(this.onlineOrders, this.stationFilter);
    },
    filteredTakeawayOrders() {
      return filterOrdersByStation(this.takeawayOrders, this.stationFilter);
    },
    filteredKioskOrders() {
      return filterOrdersByStation(this.kioskOrders, this.stationFilter);
    },
    sortedFilteredDinein() {
      const key = (o) => (o.table_name && String(o.table_name).trim()) || "—";
      const rows = [...this.filteredDineinOrders];
      if (this.groupByTable) {
        rows.sort((a, b) => key(a).localeCompare(key(b), undefined, { sensitivity: "base" }));
      }
      return rows;
    },
    // [Lot 2.I / G-4] Items shown in the allergens modal. Backwards-compatible
    // with both the new orderItems shape and the legacy order_items shape that
    // some surfaces still emit.
    allergensModalItems() {
      const order = this.allergensModal.order;
      if (!order) return [];
      return order.orderItems || order.order_items || [];
    },
  },
  watch: {
    orders(newVal, oldVal) {
      if (!this._kdsOrdersHydrated || oldVal === undefined) {
        return;
      }
      // [RED-R4 BLUE / KD5] ID-based diff (was length-based). Heure de pointe :
      // 1 commande PREPARED sort du board pendant que 1 nouvelle ACCEPT entre →
      // length stable → ancien check ratait le chime → commande oubliée.
      const oldIds = new Set((oldVal || []).map((o) => o && o.id));
      const newOrders = (newVal || []).filter((o) => o && !oldIds.has(o.id));
      if (newOrders.length > 0) {
        this.playKdsNewOrderSound();
        // [KITCHEN-BRIDGE 2026-07-09] Auto-impression cuisine, exactement 1× / commande.
        this.autoPrintNewKitchenTickets(newOrders);
      }
    },
  },
  created() {
    try {
      this.kdsHideBumpInfo = localStorage.getItem("kds.hide_bump_info") === "1";
    } catch (e) {
      this.kdsHideBumpInfo = false;
    }
    // [Lot 2.F / F-10] Per-user storage key; migrate from legacy kds.station_filter once.
    const uid = this.kdsAuthUserId();
    const sKey = kdsStationFilterStorageKey(uid);
    let sf = null;
    try {
      sf = localStorage.getItem(sKey);
    } catch (e) {
      sf = null;
    }
    if (sf == null && uid > 0) {
      try {
        const leg = localStorage.getItem("kds.station_filter");
        if (leg === "all" || leg === "bar" || leg === "cuisine_chaude" || leg === "cuisine_froide") {
          sf = leg;
          localStorage.setItem(sKey, leg);
        }
      } catch (e) {
        /* ignore */
      }
    }
    if (sf == null) {
      try {
        sf = localStorage.getItem("kds.station_filter");
      } catch (e) {
        sf = null;
      }
    }
    if (sf === "all" || sf === "bar" || sf === "cuisine_chaude" || sf === "cuisine_froide") {
      this.stationFilter = sf;
    }
    const gb = localStorage.getItem("kds.group_by_table");
    this.groupByTable = gb === "1" || gb === "true";
    const se = localStorage.getItem("kds.sound_enabled");
    this.soundEnabled = se !== "0" && se !== "false";
    const sv = parseInt(localStorage.getItem("kds.sound_volume") || "80", 10);
    this.soundVolume = Number.isFinite(sv) ? Math.min(100, Math.max(0, sv)) : 80;
    // [KITCHEN-BRIDGE 2026-07-09] Toggle impression cuisine auto — défaut ACTIVÉ.
    try {
      const ap = localStorage.getItem("kds.autoPrint");
      this.autoPrintKitchen = ap !== "0" && ap !== "false";
    } catch (e) {
      this.autoPrintKitchen = true;
    }
  },
  mounted() {
    this.closeSidebar();
    // [KDS-A-01 2026-07-06] Le layout admin partagé `.db-main` est display:block →
    // le `flex:1` de .kds-v2 (KdsV2Grid) était inerte et la grille ne prenait que la
    // hauteur de son contenu au lieu du viewport (owner : 3 cartes PLEIN ÉCRAN en
    // hauteur). Fix route-scoped, même pattern que `.expand` (OSS/POS) : on pose la
    // classe `db-main--kds` (flex-column, cf. resources/css/app.css) uniquement quand
    // le layout V2 est actif, et on la retire en beforeUnmount — zéro impact sur les
    // autres pages admin.
    if (this.useV2Layout) {
      document?.querySelector('.db-main')?.classList?.add('db-main--kds');
    }
    this.refreshOrderList();
    this.startAutoRefresh();
    window.addEventListener('realtime-order-update', this.refreshOrderList);
    this.subscribeEcho();
    this._bindWsService();
    // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
    // 1s ticker that drives `recallActiveIds` expiry. Independent of
    // KdsV2Grid's own ticker so this orchestrator owns the SSOT for the
    // RAPPELÉ-badge lifecycle.
    this._kdsRecallTickerId = window.setInterval(() => {
      this._kdsRecallNow = Date.now();
    }, 1000);
    // [iter15-mega-fix C-017 2026-05-10] Self-heal the KDS surface when a
    // status transition POST succeeds. Production case: Pusher dev WS dies,
    // chef clicks "prêt", backend persists (202), but no broadcast → board
    // lies. Test case (iter15-mega-kiosk-roundtrip Wave C state 09): test
    // bypasses the Vue `orderStatus` method entirely, calling
    // `window.axios.post('admin/kds-order/change-status/...')` directly,
    // so the in-method `_debouncedRefresh()` never runs. A response
    // interceptor catches BOTH paths : it fires on every successful KDS
    // change-status POST regardless of caller (component method, raw axios,
    // future tooling). On 2xx we trigger an immediate refresh — bypassing
    // the 300ms debounce because this is a one-shot reaction, not a burst.
    try {
      const ax = (typeof window !== 'undefined' && window.axios) ? window.axios : null;
      if (ax && ax.interceptors && ax.interceptors.response) {
        this._kdsStatusInterceptorId = ax.interceptors.response.use(
          (response) => {
            try {
              const cfg = response && response.config;
              if (cfg && /^post$/i.test(cfg.method || '')
                  && typeof cfg.url === 'string'
                  && cfg.url.indexOf('admin/kds-order/change-status/') !== -1
                  && response.status >= 200 && response.status < 300) {
                // Direct refresh (no debounce) so the 09-kds-prete window
                // (~1500ms in the spec) catches the transition. Echo+method
                // paths still funnel through `_debouncedRefresh` elsewhere.
                this._refreshWithCurrentFilter();
                this.items();
              }
            } catch (_e) { /* defensive: never break the response chain */ }
            return response;
          },
          (error) => Promise.reject(error)
        );
      }
    } catch (_e) { /* defensive: interceptor is best-effort */ }
    this._kdsWaitInterval = setInterval(() => {
      this.waitTick += 1;
    }, 30000);
    // [F-03 / Lot 1.C] Adaptive polling fallback. Pauses automatically when
    // wsService.state === 'CONNECTED'; accelerates when degraded; backoffs on
    // 5xx. Safe to mount unconditionally — the service no-ops while WS is up.
    this.kdsSyncUnsubscribers.push(
      kdsSyncService.on('sync', ({ gatedIds = [], orders = [], deleted_ids = [] }) => {
        const hasFreshOrders = orders.some((o) => !gatedIds.includes(o.id));
        const hasDeletes = (deleted_ids || []).length > 0;
        this.syncNowTick = Date.now();
        if (hasFreshOrders || hasDeletes) {
          this._debouncedRefresh && this._debouncedRefresh();
        }
      })
    );
    this.kdsSyncUnsubscribers.push(
      kdsSyncService.on('error', (info) => {
        this.syncNowTick = Date.now();
        // [HARDWARE-HARDENING 2026-07-03] Un 401/403 sur le poll sync NE se répare pas tout seul
        // (permission web-guard manquante côté serveur — cf. AdminWebGuardPermissionsSyncSeeder) et
        // laissait le tableau BLANC en SILENCE → le poste cuisine paraissait « planté » (mène à un
        // faux diagnostic « bug WebSocket »). On rend l'échec BRUYANT : bannière claire + cause réelle.
        const st = info && info.status;
        if (st === 401 || st === 403) {
          this.kdsErrorBanner = { visible: true, message: this.$t('message.kds_sync_forbidden'), lastRetryAt: null };
        }
      })
    );
    try {
      kdsSyncService.start(this.authBranchId());
    } catch (e) { /* defensive: never break KDS mount because of poller */ }
    this._kdsSyncStampTimer = setInterval(() => { this.syncNowTick = Date.now(); }, 1000);
    // [KITCHEN-PRINT-RESILIENCE 2026-07-13] Retry auto des tickets cuisine en échec :
    // quand le pont/imprimante revient, les tickets manqués ressortent seuls.
    this._kitchenRetryInterval = setInterval(() => {
      try { this.retryFailedKitchenTickets(); } catch (e) { /* best-effort */ }
    }, KITCHEN_PRINT_RETRY_MS);
  },
  methods: {
    // [UR1-002 V1.0.2 Wave B1] phoneDisplay SSOT proxy for template access.
    safePhone(phone) {
      return safePhone(phone);
    },
    // [POS-WIZARD-COMPO-AUDIT 2026-06-23 P2-B] Shape-agnostic "GROUP: VALUE"
    // variation render for the order-cards (snapshot-shaped) AND the items-board
    // (legacy-shaped). Replaces the inline `{{variation_name}}: {{name}}` that
    // inverted snapshot lines into "Poulet mariné: ".
    variationLine(v) {
      return kdsVariationLine(v);
    },
    // [2026-05-18 PR-C T2 reframe heal] JS-side filter for OrderStatusChanged.
    // Mirrors `KitchenReleaseRule::visibleStatuses` (ACCEPT / PREPARING /
    // PREPARED). A status change affects the KDS board when EITHER the
    // previous OR the next status is in that set:
    //   - Entry transitions (PENDING→ACCEPT, ACCEPT→PREPARING, etc.)
    //   - Exit transitions  (PREPARED→DELIVERED, ACCEPT→CANCELED)
    //   - Same-board mutations (ACCEPT→PREPARING bump)
    // Pure transitions outside the KDS board (e.g. DELIVERED→REFUNDED,
    // CANCELED→RETURNED) no longer trigger an unnecessary debounced refresh.
    _statusChangeAffectsKds(parsed) {
      const KDS_VISIBLE = [4, 7, 8]; // ACCEPT, PREPARING, PREPARED (mirror KitchenReleaseRule)
      const payload = parsed && parsed.payload ? parsed.payload : (parsed || {});
      const oldStatus = Number(payload.old_status);
      const newStatus = Number(payload.new_status);
      // Missing payload (legacy / unparsed event) → fall back to refresh
      // rather than risk swallowing a real transition.
      if (!Number.isFinite(oldStatus) && !Number.isFinite(newStatus)) {
        return true;
      }
      return KDS_VISIBLE.includes(oldStatus) || KDS_VISIBLE.includes(newStatus);
    },
    // [Sprint 2A DEL-3 2026-05-16] Legacy template delivery-block helpers.
    // Mirror the V2 KdsOrderCard `isDeliveryOrder` + `deliveryAddressLine`
    // computed logic for the rollback path (?v2=0 / kds.v2_enabled='0').
    // Source-truth precedence identical to V2 to avoid drift between the
    // two UIs the kitchen could switch between in a single shift.
    kdsLegacyShouldShowDelivery(order) {
      if (!order) {
        return false;
      }
      const surface = String(order.source_surface || '').toLowerCase();
      if (surface === 'delivery') {
        return true;
      }
      // OrderType::DELIVERY === 5 (app/Enums/OrderType.php).
      return Number(order.order_type) === 5;
    },
    kdsLegacyDeliveryAddress(order) {
      const a = order && order.order_address;
      if (!a) {
        return '';
      }
      const parts = [];
      if (a.apartment) {
        parts.push(a.apartment);
      }
      if (a.address) {
        parts.push(a.address);
      }
      return parts.join(' — ');
    },
    // [kds/sprint-2 V-5] V2 grid event handlers — both delegate to the same
    // store action `kitchenDisplaySystemOrder/changeStatus` used by the
    // legacy 4-column layout. KDSOrderStateMachine on the server stays the
    // single source of truth (apply() lockForUpdate + idempotent early-return).
    onV2ChangeStatus(event) {
      // event: { orderId, status }
      const order = (this.orders || []).find((o) => o.id === event.orderId);
      if (!order) {
        return;
      }
      const payload = kdsStatusPayload(order, event.status);
      this.$store.dispatch('kitchenDisplaySystemOrder/changeStatus', { ...payload })
        .then(() => {
          this.kdsAnnounceTransition(order, event.status);
          this._debouncedRefresh();
          window.dispatchEvent(new CustomEvent('realtime-order-update', {
            detail: { type: 'status-change', order_id: payload.id, status: event.status }
          }));
        })
        .catch((err) => {
          const httpStatus = err?.response?.status;
          // [#11] 409 conflict → silent refresh (unchanged behavior). 422
          // invalid-transition → ALSO refresh so the stale card/CTA self-corrects
          // instead of waiting for the next passive poll (2-60s), then surface
          // the banner. Other errors → banner only.
          if (httpStatus === 409) {
            this._debouncedRefresh();
            return;
          }
          if (httpStatus === 422) {
            this._debouncedRefresh();
          }
          if (this.kdsErrorBanner) {
            this.kdsErrorBanner.visible = true;
            // [I18N-FIX 2026-06-27] La clé existe sous `message.` (pas `label.`,
            // absente partout) → alignée sur l'usage correct en toast l.~2311.
            this.kdsErrorBanner.message = this.$t('message.kds_status_conflict');
          }
        });
    },
    onV2AutoPromote(orderId) {
      // Auto-transition ACCEPT → PREPARING — same path as a manual chef bump.
      const order = (this.orders || []).find((o) => o.id === orderId);
      if (!order) {
        return;
      }
      this.onV2ChangeStatus({ orderId, status: ORDER_STATUS.PREPARING });
    },
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
    kdsInstructionClass(text) {
      return kdsInstructionVisualClass(text);
    },
    // [POS-OUTPUT-AUDIT 2026-06-24 P1-KDS] Strip the compo duplicate the
    // structured render already shows (frozen pos-wizard.js echoes the full
    // composition into instruction); keep unique extras (↳ Sauce frites, +Menu,
    // free notes). Empty result → the v-if hides the line. Display-only.
    kdsCleanInstruction(item) {
      return sanitizeKdsInstruction(item?.instruction, item?.item_name);
    },
    isPaymentPendingCounter(order) {
      return order?.payment_pending_counter === true
        || parseInt(order?.payment_status, 10) === paymentStatusEnum.PENDING_COUNTER;
    },
    // [Lot 2.I / G-4] Thin wrappers over pure helpers in kdsAllergens.js so
    // they remain unit-testable in isolation (see tests/js/kdsAllergens.spec.js).
    orderHasAllergens(order) {
      return kdsOrderHasAllergens(order);
    },
    sortedAllergens(snapshot) {
      return kdsSortedAllergens(snapshot);
    },
    openAllergensModal(order) {
      this.allergenModalReturnFocus =
        typeof document !== "undefined" && document.activeElement
          ? document.activeElement
          : null;
      this.allergensModal = { open: true, order };
      this.$nextTick(() => {
        const el = this.$refs.allergensModalCloseButton;
        if (el && typeof el.focus === "function") {
          try { el.focus(); } catch (_) { /* defensive: focus is best-effort */ }
        }
      });
    },
    closeAllergensModal() {
      const returnTo = this.allergenModalReturnFocus;
      this.allergensModal = { open: false, order: null };
      this.allergenModalReturnFocus = null;
      this.$nextTick(() => {
        if (returnTo && typeof returnTo.focus === "function") {
          try { returnTo.focus(); } catch (_) { /* best-effort */ }
        }
      });
    },
    // [Audit 2.I F-01] Keep Tab / Shift+Tab inside the dialog (simple 2-node cycle).
    onAllergensModalKeydown(e) {
      if (e.key !== "Tab") {
        return;
      }
      const root = this.$refs.allergensModalRoot;
      const content = root && root.querySelector && root.querySelector(".kds-allergens-modal-content");
      if (!content) {
        return;
      }
      const focusables = Array.from(
        content.querySelectorAll(
          'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
      ).filter((n) => n.offsetParent !== null || n === document.activeElement);
      // One focusable (e.g. only Close): keep Tab from moving focus out of the dialog.
      if (focusables.length < 2) {
        e.preventDefault();
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      } else if (document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    },
    dismissKdsBumpNotice() {
      try {
        localStorage.setItem("kds.hide_bump_info", "1");
      } catch (e) {
        /* ignore */
      }
      this.kdsHideBumpInfo = true;
    },
    kdsWaitClass(order) {
      void this.waitTick;
      const ms = parseOrderCreatedMs(order);
      return getKdsEscalationClass(ms, Date.now());
    },
    kdsDisplayDateTime(value) {
      const raw = String(value || "").trim();
      const match = raw.match(/^(\d{1,2}):(\d{2})\s*(AM|PM),?\s*(.*)$/i);
      if (!match) {
        return raw.replace(/\s*\b(AM|PM)\b/gi, "").trim();
      }
      let hour = parseInt(match[1], 10);
      const minute = match[2];
      const marker = match[3].toUpperCase();
      const suffix = match[4] ? `, ${match[4]}` : "";
      if (marker === "PM" && hour < 12) hour += 12;
      if (marker === "AM" && hour === 12) hour = 0;
      return `${String(hour).padStart(2, "0")}:${minute}${suffix}`;
    },
    orderStatusBadgeClasses(status) {
      return status === this.enums.orderStatusEnum.PREPARED
        ? "bg-[#166534] text-white"
        : status === this.enums.orderStatusEnum.ACCEPT
          ? "bg-[#4C1A96] text-white"
          : "bg-[#92400E] text-white";
    },
    kdsAuthUserId() {
      const u = this.$store.getters["auth/authInfo"] || {};
      const id = u.id != null ? parseInt(u.id, 10) : 0;
      return Number.isFinite(id) && id > 0 ? id : 0;
    },
    persistKdsUiPrefs() {
      try {
        localStorage.setItem(kdsStationFilterStorageKey(this.kdsAuthUserId()), this.stationFilter);
      } catch (e) {
        /* private mode / quota */
      }
      localStorage.setItem("kds.group_by_table", this.groupByTable ? "1" : "0");
      localStorage.setItem("kds.sound_enabled", this.soundEnabled ? "1" : "0");
      localStorage.setItem("kds.sound_volume", String(this.soundVolume));
      localStorage.setItem("kds.autoPrint", this.autoPrintKitchen ? "1" : "0");
    },
    playKdsNewOrderSound() {
      if (!this.soundEnabled) {
        return;
      }
      // [Lot 2.C / F-07] Throttle: burst of new orders (WS) must not machine-gun the chime.
      const now = Date.now();
      if (!shouldPlayKdsNewOrderSound(this._kdsLastNewOrderSoundAt, now)) {
        return;
      }
      this._kdsLastNewOrderSoundAt = now;
      const el = this.$refs.kdsNewOrderAudio;
      if (!el) {
        return;
      }
      el.volume = Math.min(1, Math.max(0, this.soundVolume / 100));
      el.currentTime = 0;
      el.play().catch(() => {});
    },
    // [KITCHEN-BRIDGE 2026-07-09] Seed la garde de dé-dup avec les ids des commandes
    // DÉJÀ présentes (backlog) au 1er chargement / à la reconnexion, SANS imprimer.
    // Garantit qu'un reload/refresh ne relance pas une impression massive du backlog.
    _seedKitchenPrintedBacklogOnce() {
      if (this._kitchenBacklogSeeded) return;
      this._kitchenBacklogSeeded = true;
      try {
        // [SUPERVISOR-HEAL 2026-07-13] Restaure la liste d'échec PERSISTÉE (survit au reload
        // pendant une panne pont) AVANT le seed, et EXCLUT ces ids du marquage « imprimé » :
        // un ticket en échec ne doit JAMAIS être seedé comme imprimé (sinon perdu à vie au
        // reload) — il reste dans _kitchenFailedPrint → réessayé par le retry auto.
        this._kitchenFailedPrint = getKitchenFailed();
        const failed = new Set(this._kitchenFailedPrint.map((id) => String(id)));
        const ids = (this.orders || [])
          .map((o) => o && o.id)
          .filter((id) => id != null && !failed.has(String(id)));
        seedKitchenPrinted(ids);
      } catch (e) {
        /* best-effort — jamais bloquant pour le KDS */
      }
    },
    // Imprime le ticket cuisine des NOUVELLES commandes, exactement une fois chacune.
    // Respecte le toggle autoPrintKitchen ; la dé-dup persiste en localStorage.
    autoPrintNewKitchenTickets(orders) {
      if (!this.autoPrintKitchen) return;
      (orders || []).forEach((o) => {
        if (!o || o.id == null) return;
        if (hasKitchenPrinted(o.id)) return;
        const key = String(o.id);
        // [KITCHEN-PRINT-RESILIENCE 2026-07-13] Garde in-flight (remplace l'ancien
        // mark préventif) : bloque le double-envoi si le watch re-tire (burst WS)
        // avant la fin du GET, SANS marquer imprimé prématurément (sinon un échec
        // pont = ticket perdu à vie, jamais réessayé).
        if (this._kitchenInFlight && this._kitchenInFlight.has(key)) return;
        if (this._kitchenInFlight) this._kitchenInFlight.add(key);
        Promise.resolve(this.autoPrintKitchenTicket(o))
          .then((r) => {
            if (r && r.ok) {
              // Imprimé : marque (dé-dup persistée) + sort de la liste d'échec.
              markKitchenPrinted(o.id);
              this._removeKitchenFailed(o.id);
            } else {
              // Échec (pont/imprimante) : NE PAS marquer → le retry le ressortira.
              this._addKitchenFailed(o.id);
            }
          })
          .catch(() => { this._addKitchenFailed(o.id); })
          .finally(() => { if (this._kitchenInFlight) this._kitchenInFlight.delete(key); });
      });
    },
    // Récupère les octets ESC/POS cuisine (rendu serveur SSOT, width-safe/symbolique)
    // puis les POSTe au pont cuisine local. Jamais throw. RETOURNE un résultat
    // DISCRIMINÉ pour que l'appelant sache marquer imprimé / réessayer :
    //   - {ok:true}                        → imprimé
    //   - {ok:false, retriable:true}       → GET escpos KO (302/401/429/réseau) OU
    //     pont injoignable : ticket NON sorti, à réessayer
    //   - {ok:false, retriable:false}      → contexte invalide / rien à imprimer
    async autoPrintKitchenTicket(order) {
      const ax = (typeof window !== "undefined" && window.axios) ? window.axios : null;
      if (!ax || !order || order.id == null) return { ok: false, retriable: false };
      let data;
      try {
        const resp = await ax.get(`admin/pos/orders/${order.id}/escpos`, { params: { ticket: "kitchen" } });
        data = resp && resp.data;
      } catch (e) {
        // GET escpos KO : 302 login / 401 session expirée / 429 throttle / réseau /
        // 5xx → retriable (le pont ou la session peut revenir).
        try {
          if (typeof window !== "undefined" && window.console) {
            window.console.debug("[kds] escpos cuisine KO (retriable):", e && e.message);
          }
        } catch (_) { /* noop */ }
        return { ok: false, retriable: true, reason: "escpos-fetch" };
      }
      const b64 = data && data.escpos_b64;
      if (!b64) return { ok: false, retriable: false, reason: "empty" };
      const r = await printEscPosViaKitchenBridge(b64, { orderRef: order.id });
      // r = {ok:true} | {ok:false,retriable:true} | null(b64 vide, déjà filtré).
      return (r && r.ok) ? r : (r || { ok: false, retriable: true, reason: "bridge" });
    },
    // [KITCHEN-PRINT-RESILIENCE 2026-07-13] Ajoute/retire un id (String) de la liste
    // réactive des tickets cuisine en échec (pilote badge + retry).
    _addKitchenFailed(orderId) {
      if (orderId == null || !Array.isArray(this._kitchenFailedPrint)) return;
      const key = String(orderId);
      if (!this._kitchenFailedPrint.includes(key)) {
        this._kitchenFailedPrint.push(key);
        try { setKitchenFailed(this._kitchenFailedPrint); } catch (_) { /* persist best-effort */ }
      }
    },
    _removeKitchenFailed(orderId) {
      if (orderId == null || !Array.isArray(this._kitchenFailedPrint)) return;
      const key = String(orderId);
      const i = this._kitchenFailedPrint.indexOf(key);
      if (i !== -1) {
        this._kitchenFailedPrint.splice(i, 1);
        try { setKitchenFailed(this._kitchenFailedPrint); } catch (_) { /* persist best-effort */ }
      }
    },
    // Retry auto : pour chaque ticket en échec ENCORE présent dans les commandes
    // actives, re-tente l'impression. Succès → marque + retire. Purge aussi les ids
    // de commandes disparues (bumped/annulées). Quand le pont revient, les tickets
    // manqués sortent seuls. Non-bloquant, best-effort.
    retryFailedKitchenTickets() {
      if (!Array.isArray(this._kitchenFailedPrint) || this._kitchenFailedPrint.length === 0) return;
      const activeById = new Map();
      (this.orders || []).forEach((o) => { if (o && o.id != null) activeById.set(String(o.id), o); });
      // Purge les ids dont la commande a quitté le board.
      this._kitchenFailedPrint = this._kitchenFailedPrint.filter((key) => activeById.has(key));
      try { setKitchenFailed(this._kitchenFailedPrint); } catch (_) { /* persist best-effort */ }
      this._kitchenFailedPrint.slice().forEach((key) => {
        if (this._kitchenInFlight && this._kitchenInFlight.has(key)) return;
        const order = activeById.get(key);
        if (!order) return;
        if (this._kitchenInFlight) this._kitchenInFlight.add(key);
        Promise.resolve(this.autoPrintKitchenTicket(order))
          .then((r) => {
            if (r && r.ok) {
              markKitchenPrinted(order.id);
              this._removeKitchenFailed(order.id);
            }
          })
          .catch(() => { /* reste en échec, re-tenté au prochain tick */ })
          .finally(() => { if (this._kitchenInFlight) this._kitchenInFlight.delete(key); });
      });
    },
    // [KDS-REPRINT 2026-07-13] Réimpression MANUELLE du ticket cuisine, déclenchée
    // par le bouton 🖨️ (carte V2 + lanes legacy). Contrairement à l'auto-print
    // (autoPrintNewKitchenTickets) :
    //   - PAS de dé-dup : on appelle autoPrintKitchenTicket() DIRECTEMENT, donc le
    //     ticket ressort MÊME si l'id est déjà marqué imprimé (hasKitchenPrinted).
    //   - PAS gaté par le toggle « Impression auto » (autoPrintKitchen) : c'est une
    //     action explicite du cuisinier (ticket perdu/illisible).
    // autoPrintKitchenTicket est best-effort (try/catch interne, jamais throw).
    async reprintKitchenTicket(order) {
      if (!order || order.id == null) return;
      // [SUPERVISOR-HEAL round2 2026-07-13] Garde in-flight (comme les 2 chemins auto) :
      // empêche le retry auto (tick 20 s) OU un 2e clic de RÉ-imprimer le MÊME ticket en
      // parallèle pendant l'await de cette réimpression → 0 doublon (course fermée).
      const key = String(order.id);
      if (this._kitchenInFlight && this._kitchenInFlight.has(key)) return;
      if (this._kitchenInFlight) this._kitchenInFlight.add(key);
      try {
        const r = await this.autoPrintKitchenTicket(order);
        try {
          if (r && r.ok) {
            // Réimpression sortie : marque imprimé + purge l'éventuelle entrée en échec →
            // le retry auto ne ressort PAS un doublon 20 s plus tard, badge à jour.
            markKitchenPrinted(order.id);
            this._removeKitchenFailed(order.id);
            alertService.info(this.$t("message.kds_reprint_sent", { queue: order.queue_number || order.id }));
          } else {
            // Pont/imprimante injoignable : toast ERREUR explicite (le cuisinier voit
            // que le ticket n'est PAS sorti). Ne marque rien.
            alertService.error(this.$t("message.kds_reprint_failed"));
          }
        } catch (_) { /* retour visuel best-effort, jamais bloquant */ }
      } finally {
        if (this._kitchenInFlight) this._kitchenInFlight.delete(key);
      }
    },
    isTableGroupOpen(key) {
      return this.expandedTableGroups[key] !== false;
    },
    toggleTableGroup(key) {
      const cur = this.isTableGroupOpen(key);
      this.expandedTableGroups = { ...this.expandedTableGroups, [key]: !cur };
    },
    dineinTableKey(order) {
      return (order.table_name && String(order.table_name).trim()) || "—";
    },
    kdsDineinTableHeaderVisible(order, idx) {
      if (!this.groupByTable) {
        return false;
      }
      const list = this.sortedFilteredDinein;
      const key = (o) => (o.table_name && String(o.table_name).trim()) || "—";
      if (idx === 0) {
        return true;
      }
      return key(order) !== key(list[idx - 1]);
    },
    kdsIsBumped(orderId, itemId) {
      return this.$store.getters["kds/bumpTimestamp"](orderId, itemId) != null;
    },
    kdsCanRecall(orderId, itemId) {
      const ts = this.$store.getters["kds/bumpTimestamp"](orderId, itemId);
      if (ts == null) {
        return false;
      }
      return Date.now() - ts < 60000;
    },
    kdsBump(order, item) {
      this.$store.dispatch("kds/bumpItem", { orderId: order.id, itemId: item.id });
      this.$nextTick(() => {
        if (this.$store.getters["kds/isReadyOrder"](order)) {
          if (order.status !== this.enums.orderStatusEnum.PREPARED) {
            const nextStatus = order.status === this.enums.orderStatusEnum.ACCEPT
              ? this.enums.orderStatusEnum.PREPARING
              : this.enums.orderStatusEnum.PREPARED;
            this.orderStatus(order, nextStatus);
          }
        }
      });
    },
    async kdsRecall(order, item) {
      const r = await this.$store.dispatch("kds/recallItem", {
        orderId: order.id,
        itemId: item.id,
      });
      if (r && r.ok === false && r.reason === "grace_expired") {
        alertService.error(this.$t("message.kds_recall_grace_expired"));
      }
    },
    _bindWsService() {
      const ws = window._wsService;
      if (!ws) return;
      this._onWsConnected = () => {
        this.wsConnected = true;
        this.refreshOrderList();
        this._restartPolling();
      };
      this._onWsDisconnected = () => {
        this.wsConnected = false;
        this._restartPolling();
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
    _pollingInterval() {
      // [HEAL B.3 2026-05-19] KDS polling cadence is intentionally hardcoded
      // per-surface (not config-driven). 5000ms when WS down protects the
      // kitchen-staleness budget (orders must surface within ~5s of payment).
      // 60000ms when WS up because Echo handles the live push and polling
      // becomes a passive sanity-check. NOT a config-read miss — see
      // config/broadcasting.php for the per-surface SoT note (RED-Z3 §B-6).
      return this.wsConnected ? 60000 : 5000;
    },
    _restartPolling() {
      this.stopAutoRefresh();
      this.startAutoRefresh();
    },
    startAutoRefresh() {
      if (this.$route.path.includes('kitchen-display-system')) {
        this.autoRefreshInterval = setInterval(() => {
          this.refreshOrderList();
        }, this._pollingInterval());
      }
    },
    // [P4-1] Subscribe to branch Echo channel for real-time order updates.
    // Admin users (branch_id=0) rely on polling; branch staff get sub-second push.
    subscribeEcho() {
      if (!window.Echo) return;
      const branchId = this.authBranchId();
      if (branchId <= 0) return; // Admin: polling fallback is sufficient
      // [AUDIT-P51-BUG2] Always unsubscribe first to prevent duplicate listeners on re-mount
      this.unsubscribeEcho();
      try {
        this._eventSub = onEvents(branchId, [
          // [2026-05-18 PR-C T2 reframe heal] Filter OrderStatusChanged JS-side:
          // refresh only when from OR to is in the KDS-visible status set
          // (ACCEPT=4 / PREPARING=7 / PREPARED=8 — mirror KitchenReleaseRule).
          // Before this guard, every status flip (DELIVERED→REFUNDED, CANCELED,
          // RETURNED, etc.) triggered a debounced full refresh even though
          // none of those affect the KDS board → wasted backend calls + flicker.
          { broadcastAs: 'OrderStatusChanged', handler: (parsed) => {
              if (this._statusChangeAffectsKds(parsed)) {
                this._debouncedRefresh();
              }
          } },
          { broadcastAs: 'OrderCreated', handler: () => { this._debouncedRefresh(); } },
          { broadcastAs: 'OrderPaidAtCounter', handler: () => { this._debouncedRefresh(); } },
          // [SYNC-001 + CV1-KDS-INFLIGHT-OOS-MARKER-001] KDS now also receives
          // ItemAvailabilityChanged so the station can flag in-flight tickets
          // that include a freshly 86'd item (rupture stock cuisine).
          // _onItemAvailabilityChanged dispatches into kdsInflight Vuex module
          // (lazy-purged 10min TTL), then triggers a debounced refresh.
          { broadcastAs: 'ItemAvailabilityChanged', handler: (parsed) => { this._onItemAvailabilityChanged(parsed); } },
          // [F-02] Floor-plan transfer / occupy → update the table label in place
          // and flash the card briefly. Refresh re-fetches table_name from the
          // backend; flash provides the visual cue (gate G-2 decision).
          {
            broadcastAs: 'OrderTableChanged',
            handler: (payload) => { this._handleTableChanged(payload); },
          },
          // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B]
          // Chef on station A recalls an order → all other stations of the
          // same branch get the event here and re-inject the card with the
          // RAPPELÉ badge for 60s without polling. The handler delegates to
          // onKdsOrderRecalled which sets the local `recallActiveMap` entry;
          // KdsV2Grid reads it via the `recall-active-ids` prop.
          {
            broadcastAs: 'KdsOrderRecalled',
            handler: (parsed) => {
              const orderId = parsed?.payload?.order_id || parsed?.payload?.orderId;
              if (orderId) {
                // [#9] Anchor the RAPPELÉ window to the broadcast recall instant,
                // not message-receipt time, so the 60s window stays aligned across
                // stations under WS latency. Fall back to now only if absent/bad.
                const recalledRaw = parsed?.payload?.recalled_at || parsed?.payload?.recalledAt;
                const recalledMs = recalledRaw ? Date.parse(recalledRaw) : NaN;
                this.onKdsOrderRecalled({
                  orderId: parseInt(orderId, 10),
                  queueNumber: parsed?.payload?.queue_number || null,
                  recalledAt: Number.isFinite(recalledMs) ? recalledMs : Date.now(),
                  payload: parsed?.payload || null,
                });
              }
            },
          },
        ]);
        // [P13_LOG_HYGIENE] console.log(`[KDS] Echo subscribed to branch.${branchId}`);
      } catch (e) {
        // Echo not available or auth failed — polling fallback handles it
        console.warn('[KDS] Echo subscription failed:', e.message);
      }
    },
    unsubscribeEcho() {
      const branchId = this.authBranchId();
      if (branchId <= 0) return;
      try {
        this._eventSub?.unsubscribe();
        // [P13_LOG_HYGIENE] console.log(`[KDS] Echo unsubscribed from branch.${branchId}`);
      } catch (e) {
        console.warn('[KDS] Echo unsubscribe error:', e.message);
      }
      this._eventSub = null;
    },
    refreshOrderList() {
      this.items();
      // [FIX-54-5] Preserve current filter — use _refreshWithCurrentFilter instead of list()
      // to avoid falsy value (0) being treated as "no filter" and resetting the status
      this._refreshWithCurrentFilter();
    },
    _isVisibleInCurrentBoard(item) {
      const selectedStatus = Number(this.props.search.status || 0);
      if (selectedStatus > 0) {
        return true;
      }
      // [iter15-mega-fix C-022 run-3 2026-05-10] Previously this filter
      // excluded PREPARED orders from the default "Toutes Les Commandes"
      // board. That contradicted the customer-facing reality: the
      // order-status-screen still announces "Prêt: A0024" so the customer
      // comes back to the counter, but the chef no longer saw the card on
      // the default KDS surface and had no way to confirm the hand-over.
      // The PREPARED visual marker already exists — `orderStatusBadgeClasses`
      // paints PREPARED cards green (#166534 / "Prête"), and the
      // ACCEPT/PREPARING action CTAs are status-conditional so PREPARED
      // cards naturally show no action button. Showing the card on the
      // default board is therefore non-disruptive and restores
      // kitchen ↔ customer-screen parity. The dedicated "Prêtes" filter
      // button (selectedStatus > 0 branch above) keeps its narrow view.
      return true;
    },
    _applyOrderBuckets(rows) {
      const visibleRows = (Array.isArray(rows) ? rows : []).filter((item) => this._isVisibleInCurrentBoard(item));
      // [test-e2e fix E-003 round-3] V1 dine-in disabled — kiosk orders are TAKEAWAY
      // (OrderRequest:200 enforces order_type=TAKEAWAY for ALL kiosk orders since
      // dine-in is gated off via feature flag pos.dine_in_enabled=false). Therefore
      // bucket by source_surface ('kiosk') first, falling back to order_type for
      // historical rows that pre-date the source_surface column. Without this fix
      // the "🖥️ Borne" KDS column is permanently empty in V1.
      const isKioskSource = (item) => {
        const surface = typeof item.source_surface === 'string' ? item.source_surface.toLowerCase() : '';
        if (surface === 'kiosk') return true;
        // Legacy fallback: orders created before source_surface column existed and
        // marked as KIOSK type still bucket as kiosk.
        if (!surface && item.order_type === orderTypeEnum.KIOSK) return true;
        return false;
      };
      this.kioskOrders = visibleRows.filter(isKioskSource);
      // Non-kiosk rows fan out across the POS / online / dine-in lanes by order_type.
      const nonKioskRows = visibleRows.filter((item) => !isKioskSource(item));
      this.dineinOrders = nonKioskRows.filter((item) => item.order_type === orderTypeEnum.DINING_TABLE);
      this.onlineOrders = nonKioskRows.filter((item) => item.order_type === orderTypeEnum.DELIVERY);
      // POS (caisse) orders follow the same kitchen lane as takeaway.
      this.takeawayOrders = nonKioskRows.filter((item) =>
        item.order_type === orderTypeEnum.TAKEAWAY || item.order_type === orderTypeEnum.POS
      );
      // [CLUSTER-4 P2 2026-07-11] A successful board hydrate (Echo broadcast,
      // fallback poll, or list()) is the ground-truth "data is fresh" signal.
      // Stamp it here — the single chokepoint every success path funnels
      // through — so the freshness badge tracks REAL refreshes, not just the
      // socket state or the sync-service poll clock.
      this.lastDataFreshAt = Date.now();
    },
    _refreshWithCurrentFilter() {
      // [FIX-54-5] Re-fetch orders without modifying props.search.status
      // This ensures the current filter (e.g., status=7 PREPARING) is preserved
      // even when the value is 0 (ACCEPT) which is falsy in JavaScript
      this.loading.isActive = true;
      this.$store
        .dispatch("kitchenDisplaySystemOrder/lists", this.props.search)
        .then((res) => {
          this._applyOrderBuckets(res?.data?.data || []);
          this.kdsOverflowDetected = res?.data?.meta?.overflow === true;
          this.loading.isActive = false;
	          this._kdsOrdersHydrated = true;
          this._seedKitchenPrintedBacklogOnce();
          // [test-e2e round-2 C-001] Successful poll → dismiss the persistent
          // error banner (kitchen connection restored).
          this._clearKdsErrorBanner();
	        })
        .catch((err) => {
          this.loading.isActive = false;
          // [test-e2e round-2 C-001] Persistent banner instead of ephemeral
          // toast — kitchen operator at 1m+ glance must NOT miss this.
          this._raiseKdsErrorBanner(err);
        });
    },
    openFilterSlide(event) {
      return appService.openFilterSlide(event);
    },
    closeFilterSlide(event) {
      return appService.closeFilterSlide(event);
    },

    stopAutoRefresh() {
      if (this.autoRefreshInterval) {
        clearInterval(this.autoRefreshInterval);
        this.autoRefreshInterval = null;
      }
    },
    list: function (status = "") {
      if (status) {
        this.props.search.status = status;
      } else {
        this.props.search.status = "";
      }
      this.loading.isActive = true;
      this.$store
        .dispatch("kitchenDisplaySystemOrder/lists", this.props.search)
        .then((res) => {
          this._applyOrderBuckets(res?.data?.data || []);
          this.kdsOverflowDetected = res?.data?.meta?.overflow === true;

          this.loading.isActive = false;
          this._kdsOrdersHydrated = true;
          this._seedKitchenPrintedBacklogOnce();
          // [test-e2e round-2 C-001] Auto-dismiss the persistent banner
          // on next successful /api/admin/kds-order response.
          this._clearKdsErrorBanner();
        })
        .catch((err) => {
          this.loading.isActive = false;
          // [test-e2e round-2 C-001] Persistent banner instead of ephemeral
          // toast — see _raiseKdsErrorBanner / kdsErrorBanner data prop.
          this._raiseKdsErrorBanner(err);
        });
    },
    _raiseKdsErrorBanner(err) {
      // Build a copy that flags the kind of failure (network/5xx vs 4xx).
      const status = Number(err?.response?.status || 0);
      const fallback = this.$t('error.kds_connection_lost');
      // For server-side messages (e.g. 422 validation), prefer the message
      // payload — but for 5xx / network drops, the standardized banner copy
      // is far clearer to a kitchen operator than a stack trace.
      let message;
      if (!status || status >= 500 || status === 408 || status === 425 || status === 429) {
        message = fallback;
      } else {
        message = err?.response?.data?.message || fallback;
      }
      this.kdsErrorBanner = {
        visible: true,
        message,
        lastRetryAt: Date.now(),
      };
    },
    _clearKdsErrorBanner() {
      if (this.kdsErrorBanner.visible) {
        this.kdsErrorBanner = { visible: false, message: '', lastRetryAt: null };
      }
    },
    dismissKdsErrorBanner() {
      // Manual ✕ dismiss. Next successful poll will keep it hidden anyway,
      // and the next failure will re-raise it.
      this._clearKdsErrorBanner();
    },
    items: function () {
      this.loading.isActive = true;
      this.$store
        .dispatch("kitchenDisplaySystemOrder/orderItems")
        .then((res) => {
          this.loading.isActive = false;
        })
        .catch((err) => {
          this.loading.isActive = false;
          alertService.error(err?.response?.data?.message || this.$t('message.something_wrong'));
        });
    },
    openSidebar: function () {
      document?.querySelector(".db-main")?.classList?.remove("expand");
      const activeMenu = document.querySelector('.db-sidebar-nav-item.active');
      if (activeMenu) {
        activeMenu.classList.remove('active');
      }
      document?.querySelector('.router-link-exact-active')?.parentElement?.classList?.add('active');
    },
    closeSidebar: function () {
      document?.querySelector(".db-main")?.classList?.add("expand");
    },
    search: function () {
      if (typeof this.props.search.order_serial_no !== "undefined" && this.props.search.order_serial_no !== "") {
        this.list();
      } else {
        this.list();
      }
    },
    searchReset: function () {
      this.props.search.order_serial_no = "";
      this.list();
    },
    kdsOverflowSeeMore() {
      this.props.search.order_serial_no = "";
      this.props.search.status = "";
      this.list();
    },
    // [AUDIT-P47-BUG4] Escape HTML to prevent XSS when printing kitchen tickets.
    // Order data comes from DB but could be poisoned if an admin account was compromised.
    escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    },
    kdsAddonDisplayName(addon) {
      if (!addon || typeof addon !== 'object') {
        return '';
      }
      return addon.addon_name || addon.addon_item_name || addon.name || addon.item_name || 'Addon';
    },
    // [AUDIT-P2] Print a kitchen ticket for a given order using a hidden iframe.
    // Opens a minimal print window with order ref, items, variations, extras, addons, and instructions.
    // No external library needed — uses native window.print() on an isolated document.
    // [AUDIT-P47-BUG4] All dynamic values escaped to prevent stored XSS.
    printKitchenTicket(/* order */) {
      // [TICKET-UNIFIED 2026-06-30] Owner : l'écran cuisine (board) N'IMPRIME PAS.
      // Le ticket cuisine sort UNIQUEMENT de la caisse (ReceiptComponent -> pont SAGA),
      // au format symbolique unifié. Méthode neutralisée (boutons retirés).
    },
    orderStatus: function (order, status) {
      try {
        this.loading.isActive = true;
        const payload = kdsStatusPayload(order, status);
        this.$store.dispatch("kitchenDisplaySystemOrder/changeStatus", {
          ...payload,
        }).then((res) => {
          this.loading.isActive = false;
          alertService.successFlip(
            1,
            this.$t("label.status")
          );
          // [CV1-KDS-A11Y-RICH-001] Announce the transition to screen readers
          // via the aria-live region (does NOT steal focus, polite politeness).
          this.kdsAnnounceTransition(order, status);
          // [AUDIT-P49-BUG7] Debounce refresh: list() triggers items update via store,
          // and Echo broadcast also triggers refresh. Use debounce to prevent triple API calls.
          this._debouncedRefresh();
          // Propager le changement de statut à tous les composants qui écoutent (OSS, autres KDS)
          window.dispatchEvent(new CustomEvent('realtime-order-update', {
            detail: { type: 'status-change', order_id: payload.id, status: status }
          }));
        }).catch((err) => {
          this.loading.isActive = false;
          if (err?.response?.status === 409) {
            alertService.error(this.$t("message.kds_status_conflict"));
            this._debouncedRefresh();
            return;
          }
          // [AUDIT-P47-BUG7] Null-safe guard — err.response is undefined on network timeout
          const msg = err?.response?.data?.message || err?.message || 'Erreur réseau';
          alertService.error(msg);
        });
      } catch (err) {
        this.loading.isActive = false;
        // [AUDIT-P47-BUG7] Null-safe guard — err.response is undefined on network timeout
        const msg = err?.response?.data?.message || err?.message || 'Erreur réseau';
        alertService.error(msg);
      }
    },
    /**
     * [CV1-KDS-INFLIGHT-OOS-MARKER-001] Handle ItemAvailabilityChanged events.
     *
     * Per RED-R3 F3 + RED-R4 KD11 :
     *   - Branch-scoped 86 (is_available === false): mark the item in
     *     `kdsInflight.recentlyDeavailable` so any in-flight ticket card
     *     that still contains this item gets a red OOS warning badge.
     *   - Item became available again (is_available === true): clear the
     *     flag so the badge disappears.
     *   - Other event shapes (global price/structural change): just refresh.
     *
     * The OOS marker is independent from the existing chime watcher (KD5).
     * Lazy TTL purge inside the Vuex getter ensures stale flags don't
     * outlive the rupture window (10 min). No timer leaks across remounts.
     */
    _onItemAvailabilityChanged(parsed) {
      try {
        const payload = (parsed && parsed.payload) ? parsed.payload : {};
        const itemId = payload.item_id ?? payload.itemId ?? null;
        const isAvailable = payload.is_available ?? payload.isAvailable ?? null;
        const branchId = payload.branch_id ?? payload.branchId ?? null;
        const reason = payload.reason ?? null;
        if (itemId !== null && itemId !== undefined) {
          if (isAvailable === false) {
            this.$store.dispatch('kdsInflight/markDeavailable', {
              itemId,
              branchId,
              reason,
              kdsBranchId: this.authBranchId(),
            });
            // [iter15-mega-fix D-003 2026-05-10] Surface a visible warning toast
            // on the KDS surface in addition to the inflight badge — kitchen
            // staff with hands full + noise need an unmissable cue when an
            // item used by an in-preparation ticket goes 86. The aria-live
            // region (kds-aria-live) carries the same message for screen readers.
            try {
              const itemName = payload.name || payload.item_name || ('#' + itemId);
              // [HEAL-2 V102-03 2026-05-26] i18n FR/EN/AR via pos.stock_rupture_alert
              // (was hardcoded FR concat with raw reason enum — caused mixed-locale
              // labels in EN/AR). reason suffix dropped per owner mission spec
              // "Stock épuisé : {item_name}".
              let label;
              try {
                label = this.$t('pos.stock_rupture_alert', { name: itemName });
              } catch (_t) {
                label = `Stock épuisé : ${itemName}`;
              }
              this.kdsAriaLiveMessage = label;
              alertService.warning(label);
            } catch (_e) { /* defensive */ }
          } else if (isAvailable === true) {
            this.$store.dispatch('kdsInflight/clearItem', itemId);
          }
        }
      } catch (e) {
        // Defensive: never break KDS refresh because of a malformed payload.
        console.warn('[KDS] _onItemAvailabilityChanged failed:', e?.message);
      }
      this._debouncedRefresh();
    },
    /**
     * [CV1-KDS-INFLIGHT-OOS-MARKER-001] Card-level helper used by templates :
     * returns true if the given order is in_preparation (or accepted) AND
     * still references at least one item flagged in `kdsInflight`.
     * Cards in PREPARED state are intentionally NOT marked — the warning
     * is only useful while the cuisinier is still working on the ticket.
     */
    kdsHasOosWarning(order) {
      if (!order) return false;
      const status = Number(order.status);
      const isInflight = status === orderStatusEnum.ACCEPT || status === orderStatusEnum.PREPARING;
      if (!isInflight) return false;
      const getter = this.$store.getters['kdsInflight/orderHasRecentlyDeavailableItem'];
      return typeof getter === 'function' ? getter(order) : false;
    },
    /**
     * [CV1-KDS-A11Y-RICH-001] Update the polite aria-live region with a
     * one-line transition announcement. Screen readers pick it up without
     * stealing focus. Used from `orderStatus` after a successful change.
     * Falls back silently if the order has no serial number.
     */
    kdsAnnounceTransition(order, status) {
      if (!order) return;
      const serial = order.order_serial_no ?? order.id ?? '';
      if (serial === '' || serial === null || serial === undefined) return;
      let label = '';
      if (status === orderStatusEnum.PREPARING) {
        label = this.$t ? this.$t('label.kds_aria_live_preparing', { id: serial }) : `Order #${serial} preparing`;
      } else if (status === orderStatusEnum.PREPARED) {
        label = this.$t ? this.$t('label.kds_aria_live_ready', { id: serial }) : `Order #${serial} ready`;
      } else if (status === orderStatusEnum.ACCEPT) {
        label = this.$t ? this.$t('label.kds_aria_live_accepted', { id: serial }) : `Order #${serial} accepted`;
      } else {
        label = `Order #${serial}`;
      }
      // Vue reactivity : briefly clear before setting so identical successive
      // transitions still trigger an aria-live announcement.
      this.kdsAriaLiveMessage = '';
      this.$nextTick(() => {
        this.kdsAriaLiveMessage = label;
      });
    },
    /**
     * [F-02] Handle OrderTableChanged events.
     *
     * Per orchestrator gate G-2 (in_place_with_css_flash):
     *   - Refresh the list so the new table_name appears (SSOT = backend).
     *   - Mark the order id as "freshly moved" for ~2s so the card flashes.
     *   - NEVER replay a sound and NEVER trigger a re-print — this is a silent,
     *     visual-only signal that respects the kitchen rush flow.
     *
     * Idempotent: receiving the same payload twice resets the 2s timer.
     */
    _handleTableChanged(payload) {
      const orderId = parseInt(payload?.order_id || 0);
      if (orderId <= 0) {
        this._debouncedRefresh();
        return;
      }
      this.flashTableChangeIds = { ...this.flashTableChangeIds, [orderId]: true };
      if (this._tableFlashTimers[orderId]) {
        clearTimeout(this._tableFlashTimers[orderId]);
      }
      this._tableFlashTimers[orderId] = setTimeout(() => {
        const next = { ...this.flashTableChangeIds };
        delete next[orderId];
        this.flashTableChangeIds = next;
        delete this._tableFlashTimers[orderId];
      }, 2000);
      this._debouncedRefresh();
    },
    // [AUDIT-P49-BUG7] Debounced refresh: prevents simultaneous list()+items()+Echo refresh.
    _debouncedRefresh() {
      if (this._refreshTimeout) {
        clearTimeout(this._refreshTimeout);
      }
      this._refreshTimeout = setTimeout(() => {
        this._refreshWithCurrentFilter();
        this.items();
      }, 300); // 300ms debounce — sufficient to absorb Echo broadcast + manual call
    },
    toggleFilter(index) {
      if (this.expandedFilter === index) {
        this.expandedFilter = null;
      } else {
        this.expandedFilter = index;
      }
    },

    // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
    // Handler shared by:
    //   (a) `KdsHistoryDrawer` `@recalled` emit — fires synchronously after the
    //       chef clicks "↶ Annuler bump" on the originating station (instant
    //       local feedback);
    //   (b) `KdsOrderRecalled` Echo broadcast handler — fires on OTHER stations
    //       of the same branch (cross-poste sync, see subscribeEcho).
    // We store the timestamp in `kdsRecalledMap` so the computed
    // `recallActiveIds` exposes the order to KdsV2Grid for the 60s window.
    // Also bumps an aria-live message for screen-reader users.
    onKdsOrderRecalled(payload) {
      if (!payload || !payload.orderId) {
        return;
      }
      const id = parseInt(payload.orderId, 10);
      const at = typeof payload.recalledAt === 'number' ? payload.recalledAt : Date.now();
      this.kdsRecalledMap = { ...this.kdsRecalledMap, [id]: at };
      this.kdsAriaLiveMessage = this.$t('label.kds_recall_badge_aria', {
        queue: payload.queueNumber || id,
      });
      // Trigger a debounced list refresh so the card actually re-appears on
      // the board (the order's `updated_at` may have aged out of the active
      // grid even though it's still PREPARED). The recall row in the DB
      // doesn't change `orders.updated_at`, so without a refresh the FIFO
      // sort would not re-insert it.
      this._debouncedRefresh();
    },

  },
  beforeUnmount() {
    this.stopAutoRefresh();
    // [#2] Clear the debounced-refresh timer — without this, a refresh queued
    // <300ms before navigation fires _refreshWithCurrentFilter()+items() on the
    // torn-down component (orphan dispatch + reactive writes on a dead instance).
    if (this._refreshTimeout) {
      clearTimeout(this._refreshTimeout);
      this._refreshTimeout = null;
    }
    // [Heal-5] Stop the recall TTL ticker.
    if (this._kdsRecallTickerId) {
      window.clearInterval(this._kdsRecallTickerId);
      this._kdsRecallTickerId = null;
    }
    if (this._kdsWaitInterval) {
      clearInterval(this._kdsWaitInterval);
      this._kdsWaitInterval = null;
    }
    // [iter15-mega-fix C-017 2026-05-10] Eject the status-transition response
    // interceptor — leaks would re-fire `_refreshWithCurrentFilter()` on a
    // ghost component after the user navigates away from the KDS page.
    try {
      if (this._kdsStatusInterceptorId !== null
          && this._kdsStatusInterceptorId !== undefined
          && typeof window !== 'undefined' && window.axios
          && window.axios.interceptors && window.axios.interceptors.response) {
        window.axios.interceptors.response.eject(this._kdsStatusInterceptorId);
      }
    } catch (_e) { /* defensive */ }
    this._kdsStatusInterceptorId = null;
    // [KDS-A-01 2026-07-06] Retire l'override flex-column route-scoped (cf. mounted).
    document?.querySelector('.db-main')?.classList?.remove('db-main--kds');
    this.openSidebar();
    window.removeEventListener('realtime-order-update', this.refreshOrderList);
    this.unsubscribeEcho();
    this._unbindWsService();
    Object.values(this._tableFlashTimers || {}).forEach((t) => { try { clearTimeout(t); } catch (e) {} });
    this._tableFlashTimers = {};
    // [F-03 / Lot 1.C] Tear down adaptive polling cleanly.
    try { kdsSyncService.stop(); } catch (e) { /* ignore */ }
    (this.kdsSyncUnsubscribers || []).forEach((u) => { try { u && u(); } catch (e) {} });
    this.kdsSyncUnsubscribers = [];
    if (this._kdsSyncStampTimer) {
      clearInterval(this._kdsSyncStampTimer);
      this._kdsSyncStampTimer = null;
    }
    // [KITCHEN-PRINT-RESILIENCE 2026-07-13] Stoppe le retry des tickets cuisine.
    if (this._kitchenRetryInterval) {
      clearInterval(this._kitchenRetryInterval);
      this._kitchenRetryInterval = null;
    }
  },
};
</script>

<style scoped>
/* [Wave X3 2026-05-21] KDS Historique du jour trigger.
   [KDS-TOPBAR 2026-07-05] Owner : ne PAS gâcher l'espace des commandes avec un gros bouton
   dans une bande épaisse → bande FINE (hauteur mini) + bouton compact aligné à droite, tout
   en haut. La grille récupère la hauteur gaspillée. */
.kds-history-trigger-row {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 2px 12px;
  width: 100%;
  min-height: 0;
  flex-shrink: 0;
}
.kds-history-trigger {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #111111;
  color: #ffffff;
  border: none;
  border-radius: 7px;
  padding: 4px 12px;
  font-size: 0.78rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 120ms ease, outline 120ms ease;
}
.kds-history-trigger:hover {
  background: #d40000;
}
.kds-history-trigger:focus-visible {
  outline: 2px solid #ffd400;
  outline-offset: 2px;
}
.kds-history-trigger__label {
  font-variant-numeric: tabular-nums;
}

.kds-instruction {
  line-height: 1.5;
  border-left: 3px solid #e0e0e0;
  padding-left: 0.4rem;
}
.kds-instruction--note {
  color: #4e4b66;
  border-left-color: #a0a3bd;
  background: rgba(160, 163, 189, 0.08);
  border-radius: 0 4px 4px 0;
  padding: 0.2rem 0.35rem 0.2rem 0.4rem;
}
.kds-instruction--exclusion {
  color: #7c2d12;
  border-left-color: #f97316;
  background: #fff7ed;
  font-weight: 600;
  border-radius: 0 4px 4px 0;
  padding: 0.2rem 0.35rem 0.2rem 0.4rem;
  box-shadow: 0 0 0 1px rgba(249, 115, 22, 0.2);
}
.kds-instruction--allergen {
  color: #7f1d1d;
  border-left-color: #dc2626;
  background: #fef2f2;
  font-weight: 700;
  border-radius: 0 4px 4px 0;
  padding: 0.2rem 0.35rem 0.2rem 0.4rem;
  box-shadow: 0 0 0 1px rgba(220, 38, 38, 0.35);
}
.kds-hint-banner {
  text-align: center;
  padding: 8px 12px;
  font-size: 0.8rem;
  line-height: 1.35;
  font-weight: 500;
}
.kds-hint-banner--info {
  background: #e0f2fe;
  color: #0c4a6e;
  border-bottom: 1px solid #bae6fd;
}
.kds-hint-banner--warning {
  background: #fffbeb;
  color: #78350f;
  border-bottom: 1px solid #fde68a;
}
.kds-hint-banner--neutral {
  background: #f4f4f5;
  color: #3f3f46;
  border-bottom: 1px solid #e4e4e7;
}
.kds-hint-banner--danger {
  background: #fee2e2;
  color: #7f1d1d;
  border-bottom: 1px solid #fecaca;
  font-weight: 600;
}
/* [test-e2e fix C-009 round-3 2026-05-10] Persistent KDS error banner sticky
   to viewport top so a kitchen operator scrolled into the order list still
   sees the 503-degraded signal after toast fade. Scoped to --danger to avoid
   affecting bump-info neutral banner (which is allowed to scroll naturally).
   The KDS view's scroll container is `<main class="db-main">` with
   `h-screen overflow-auto` and top-padding accounting for the fixed navbar,
   so `position: sticky; top: 0` pins at the scrollport's padding edge —
   right below the fixed `.db-header` (z-30). z-index: 50 keeps it above
   internal grid panels. */
.kds-hint-banner--danger {
  position: sticky;
  top: 0;
  z-index: 50;
}
.kds-hint-banner--action {
  align-items: center;
  display: flex;
  gap: 0.75rem;
  justify-content: center;
}
.kds-hint-link {
  background: transparent;
  border: 0;
  color: inherit;
  cursor: pointer;
  font-size: 0.78rem;
  font-weight: 800;
  text-decoration: underline;
}
/* [F-03 / Lot 1.C] Sync stamp footer — discrete, never blocks the grid. */
.kds-sync-footer {
  display: flex;
  justify-content: flex-end;
  padding: 4px 12px 6px 12px;
  background: transparent;
  pointer-events: none;
}
.kds-sync-stamp {
  font-size: 0.72rem;
  color: #374151;
  font-weight: 500;
  letter-spacing: 0.01em;
}
.kds-sync-stamp--stale {
  color: #92400e;
  font-weight: 600;
}
.ws-reconnect-banner {
  background: #ecfeff;
  color: #155e75;
  border-bottom: 1px solid #a5f3fc;
  text-align: center;
  padding: 6px 12px;
  font-size: 0.85rem;
  font-weight: 600;
}
.kds-card-header-meta {
  align-items: center;
  display: flex;
  flex: 1 1 auto;
  flex-wrap: wrap;
  gap: 0.35rem;
  min-width: 0;
}
.kds-card-header-meta > span:first-of-type {
  min-width: 0;
  overflow-wrap: anywhere;
}
.kds-source-pill {
  align-items: center;
  border-radius: 9999px;
  display: inline-flex;
  flex: 0 0 auto;
  font-size: 0.68rem;
  font-weight: 900;
  letter-spacing: 0.01em;
  line-height: 1.1;
  min-height: 1.4rem;
  padding: 0.22rem 0.55rem;
  white-space: nowrap;
}
.kds-source-pill--kiosk {
  background: #4C1A96;
  color: #FFFFFF;
}
.kds-source-pill--queue {
  background: #991B1B;
  color: #FFFFFF;
}
.kds-wait-green {
  border-color: rgba(34, 197, 94, 0.55) !important;
}
.kds-wait-orange {
  border-color: rgba(245, 158, 11, 0.7) !important;
}
.kds-wait-red {
  border-color: rgba(239, 68, 68, 0.85) !important;
}
.kds-wait-red.animate-pulse {
  animation: none !important;
}
.kds-counter-payment-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 9999px;
  background: #B91C1C;
  color: #FFFFFF;
  font-size: 0.58rem;
  font-weight: 900;
  letter-spacing: 0.02em;
  line-height: 1;
  padding: 0.28rem 0.45rem;
  text-transform: uppercase;
}

/* [F-02] Visual cue for in-place table reassignment.
 * Per orchestrator gate G-2 (in_place_with_css_flash):
 *   - 2 second cyan pulse — non-blocking, no sound, no re-print.
 *   - High enough contrast to be noticed mid-rush, low enough not to alarm.
 * The class is removed automatically by _handleTableChanged() after 2 s.
 */
@keyframes kds-table-flash-pulse {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(0, 132, 255, 0.0);
    background-color: transparent;
  }
  20% {
    box-shadow: 0 0 0 6px rgba(0, 132, 255, 0.45);
    background-color: rgba(0, 132, 255, 0.08);
  }
  60% {
    box-shadow: 0 0 0 3px rgba(0, 132, 255, 0.25);
    background-color: rgba(0, 132, 255, 0.04);
  }
}
.kds-table-flash {
  animation: kds-table-flash-pulse 2s ease-out 1;
  border-color: rgba(0, 132, 255, 0.7) !important;
}
/* [Lot 2.I / G-4] Non-blocking allergens badge + modal. Visual emphasis only;
   does NOT gate any kitchen action. Designed for visibility at ~2m distance. */
.kds-allergens-badge {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  z-index: 5;
  border: 0;
  border-radius: 9999px;
  background: #B91C1C;
  color: #FFFFFF;
  cursor: pointer;
  font-size: 0.75rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  line-height: 1;
  padding: 0.5rem 0.75rem;
  text-transform: uppercase;
}
.kds-allergens-badge:hover,
.kds-allergens-badge:focus-visible {
  background: #991B1B;
  outline: 3px solid #FCA5A5;
  outline-offset: 2px;
}
.kds-allergens-modal-overlay {
  align-items: center;
  background: rgba(17, 24, 39, 0.72);
  bottom: 0;
  display: flex;
  justify-content: center;
  left: 0;
  padding: 1rem;
  position: fixed;
  right: 0;
  top: 0;
  z-index: 9999;
}
.kds-allergens-modal-content {
  background: #FFFFFF;
  border-radius: 1rem;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
  color: #111827;
  max-height: min(80vh, 42rem);
  max-width: 42rem;
  overflow-y: auto;
  padding: 1.5rem;
  width: 100%;
}
.kds-allergens-modal-header {
  align-items: flex-start;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
}
.kds-allergens-modal-header h2 {
  font-size: 1.25rem;
  font-weight: 800;
  margin: 0;
}
.kds-allergens-modal-close {
  border: 1px solid #D1D5DB;
  border-radius: 0.5rem;
  background: #F9FAFB;
  color: #111827;
  cursor: pointer;
  font-weight: 700;
  padding: 0.5rem 0.75rem;
}
.kds-allergens-modal-close:hover,
.kds-allergens-modal-close:focus-visible {
  background: #F3F4F6;
  outline: 3px solid #93C5FD;
  outline-offset: 2px;
}
.kds-allergens-modal-intro {
  color: #374151;
  margin: 1rem 0;
}
.kds-allergens-modal-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  list-style: none;
  margin: 0;
  padding: 0;
}
.kds-allergens-modal-list-item {
  border: 1px solid #FCA5A5;
  border-radius: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.75rem;
}
.kds-allergens-modal-list-item span {
  color: #B91C1C;
  font-weight: 800;
}
/* [CV1-KDS-A11Y-RICH-001] WCAG 2.4.7 / 4.1.3 — visually hidden but available
   to assistive technology. Used by the polite aria-live region. */
.sr-only {
  position: absolute !important;
  width: 1px !important;
  height: 1px !important;
  padding: 0 !important;
  margin: -1px !important;
  overflow: hidden !important;
  clip: rect(0, 0, 0, 0) !important;
  white-space: nowrap !important;
  border: 0 !important;
}
/* [CV1-KDS-INFLIGHT-OOS-MARKER-001] In-flight OOS warning badge. Sits next
   to (not on top of) the allergens badge so both can coexist on tickets
   that have allergens AND a freshly 86'd item. */
.kds-oos-warning-badge {
  position: absolute;
  top: 0.5rem;
  right: 5.5rem;
  z-index: 5;
  border: 0;
  border-radius: 9999px;
  background: #DC2626;
  color: #FFFFFF;
  cursor: help;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  line-height: 1;
  padding: 0.5rem 0.65rem;
  text-transform: uppercase;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}
@media (prefers-reduced-motion: reduce) {
  .kds-table-flash,
  .kds-wait-red.animate-pulse,
  .animate-pulse,
  .animate-spin,
  .animate-bounce,
  .animate-ping {
    animation: none !important;
  }
  .kds-table-flash {
    border-color: rgba(0, 132, 255, 0.7) !important;
  }
  .swiper-wrapper,
  .swiper-slide,
  .swiper-container,
  [class*="swiper-"] {
    transition-duration: 0.001ms !important;
  }
}
</style>
