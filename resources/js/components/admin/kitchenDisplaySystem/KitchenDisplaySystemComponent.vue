<template>
  <LoadingComponent :props="loading" />
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
          </div>
          <ul class="h-full thin-scrolling overflow-auto pb-12">
            <!-- [N7 FIX] Stable key using item_id + instruction hash instead of object reference -->
            <li v-for="(orderItem, oIdx) in orderItems" :key="orderItem.item_id + '-' + oIdx"
              class="px-3 py-2 flex items-start justify-between gap-2 border-b border-[#EFF0F6] last:border-none">
              <div>
                <h5 class="text-sm font-medium mb-1">{{ orderItem.item_name }}</h5>
                <p v-if="orderItem.item_variations.length > 0"
                  class="text-xs font-normal font-client capitalize text-[#6E7191]">
                  <span v-for="(variation, index) in orderItem.item_variations" class="text-heading">
                    {{ variation.variation_name }}: {{ variation.name }}<span
                      v-if="index + 1 < orderItem.item_variations.length">,&nbsp;</span>
                  </span>
                </p>
                <span class="flex gap-1" v-if="orderItem.item_extras.length > 0">
                  <h3 class="capitalize text-xs w-fit whitespace-nowrap">{{ $t('label.extras') }}:
                  </h3>
                  <p v-if="orderItem.item_extras.length > 0"
                    class="text-xs font-normal font-client capitalize text-[#6E7191]">
                    <span v-for="(extra, index) in orderItem.item_extras" class="text-heading">
                      {{ extra.name }}<span v-if="index + 1 < orderItem.item_extras.length">,&nbsp;</span>
                    </span>
                  </p>
                </span>
                <div v-if="orderItem.instruction" class="kds-instruction mt-1 text-xs text-heading" style="white-space: pre-line;">{{ orderItem.instruction }}</div>
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
        <div class="db-card px-3 py-2.5 mb-4">
          <div class="swiper kitchen-swiper !flex flex-col gap-y-2 xl:flex-row items-start justify-between">
            <Swiper dir="ltr" :speed="1000" slidesPerView="auto" :spaceBetween="12" :loop="false"
              class="md:grid sm:grid-cols-2 lg:grid-cols-4  gap-y-2 md:w-fit lg:!w-full w-full">
              <SwiperSlide class="!w-fit">
                <button type="button" v-on:click="list()"
                  class="db-btn text-[#1F1F39] w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white hover:text-primary border border-[#D9DBE9] hover:bg-primary/5"
                  :class="!props.search.status ? '!bg-primary/5 text-primary' : ''">
                  <span class="capitalize whitespace-nowrap text-sm font-medium">{{ $t("label.all_orders") }}</span>
                </button>
              </SwiperSlide>
              <SwiperSlide class="!w-fit">
                <button type="button" v-on:click="list(enums.orderStatusEnum.ACCEPT)"
                  :class="props.search.status === enums.orderStatusEnum.ACCEPT ? '!bg-primary/5 text-primary' : ''"
                  class="db-btn text-[#1F1F39] w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white hover:text-primary border border-[#D9DBE9] hover:bg-primary/5">
                  <span class="capitalize whitespace-nowrap text-sm font-medium">{{ $t("label.confirmed") }}</span>
                </button>
              </SwiperSlide>
              <SwiperSlide class="!w-fit">
                <button type="button" v-on:click="list(enums.orderStatusEnum.PREPARING)"
                  :class="props.search.status === enums.orderStatusEnum.PREPARING ? '!bg-primary/5 text-primary' : ''"
                  class="db-btn text-[#1F1F39] w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white hover:text-primary border border-[#D9DBE9] hover:bg-primary/5">
                  <span class="capitalize whitespace-nowrap text-sm font-medium">{{ $t("label.preparing") }}</span>
                </button>
              </SwiperSlide>
              <SwiperSlide class="!w-fit">
                <button type="button" v-on:click="list(enums.orderStatusEnum.PREPARED)"
                  :class="props.search.status === enums.orderStatusEnum.PREPARED ? '!bg-primary/5 text-primary' : ''"
                  class="db-btn text-[#1F1F39] w-fit flex items-center justify-center gap-3 h-11 px-6 rounded-lg transition bg-white hover:text-primary border border-[#D9DBE9] hover:bg-primary/5">
                  <span class="capitalize whitespace-nowrap text-sm font-medium">{{ $t("label.done") }}</span>
                </button>
              </SwiperSlide>
            </Swiper>

            <form @submit.prevent="search"
              class="header-search-group group flex items-center justify-center border border-solid gap-2 px-3 xl:!max-w-[305px] w-full h-11 rounded-lg transition border-[#D9DBE9] focus-within:bg-white focus-within:border-primary">
              <i class="lab lab-search-normal lab-font-size-16"></i>
              <input type="text" v-model="props.search.order_serial_no" placeholder="Search Order"
                class="header-search-field w-full h-full text-xs appearance-none placeholder:font-normal placeholder:text-paragraph text-heading" />
              <button type="button" @click.prevent="searchReset"
                class="modal-close lab lab-close-circle-line transition invisible group-focus-within:visible"></button>
            </form>
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-4 gap-4" @click="closeFilterSlide($event)">
          <div class="db-card rounded-[10px] h-fit">
            <div class="p-3 pb-2" :class="dineinOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''">
              <h3 class="text-lg font-semibold">{{ $t("label.dinein_orders") }}</h3>
            </div>
            <div v-if="dineinOrders.length > 0" class="p-3" v-for="dineinOrder in dineinOrders" :key="dineinOrder">
              <div class="w-full rounded-lg border border-[#EFF0F6]">
                <div class="py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#F0F8FF]">
                  <div class="flex items-center gap-1 text-[#0084FF]">
                    <i class="lab lab-processing lab-font-size-16 text-[#0084FF]"></i>
                    <span class="text-sm font-normal">#{{ dineinOrder.order_serial_no }}</span>
                  </div>

                  <span class="py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize text-white "
                    :class="dineinOrder.status === enums.orderStatusEnum.PREPARED ? 'bg-[#2AC769]' : (dineinOrder.status === enums.orderStatusEnum.ACCEPT ? 'bg-primary' : 'bg-[#F6A609]')">{{
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
                  <button type="button" @click="openFilterSlide($event)"
                    class="filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full">
                    <span>{{ dineinOrder.order_datetime }}</span>
                    <div
                      class="flex items-center justify-center w-6 h-6 rounded-full bg-[#FFEDF4] text-base font-semibold transition-all duration-500 group-hover:text-primary">
                      <i class="icon text-primary fa-solid fa-chevron-down"></i>
                    </div>
                  </button>
                  <div style="height: 0px" class="overflow-hidden transition-all duration-500">
                    <div v-for="(item, iIdx) in dineinOrder.order_items" :key="item.id || iIdx"
                      class="flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none">
                      <h4 class="text-sm font-medium">{{ item.quantity }}x</h4>
                      <div>
                        <h5 class="text-sm font-medium mb-1">{{ item.item_name }}</h5>
                        <!-- [Y2 FIX] Guard item_variations -->
                        <p v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0"
                          class="text-xs font-normal font-client capitalize text-[#6E7191]">
                          <span v-for="(variation, index) in item.item_variations" :key="index" class="text-heading">
                            {{ variation.variation_name }}: {{ variation.name }}<span
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
                        <!-- [P3-1 FIX] Show instruction on dine-in cards -->
                        <div v-if="item.instruction && item.instruction !== ''" class="kds-instruction mt-1 text-xs text-heading" style="white-space: pre-line;">{{ item.instruction }}</div>
                      </div>
                    </div>
                    <button v-if="dineinOrder.status === enums.orderStatusEnum.ACCEPT" type="button"
                      @click="orderStatus(dineinOrder.id, enums.orderStatusEnum.PREPARING)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-primary text-white">
                      {{ $t("label.start_preparing") }}
                    </button>
                    <button v-if="dineinOrder.status === enums.orderStatusEnum.PREPARING" type="button"
                      @click="orderStatus(dineinOrder.id, enums.orderStatusEnum.PREPARED)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white">
                      {{ $t("label.mark_done") }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="db-card rounded-[10px] h-fit">
            <div class="p-3 pb-2" :class="onlineOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''">
              <h3 class="text-lg font-semibold">{{ $t("label.online_orders") }}</h3>
            </div>
            <div v-if="onlineOrders.length > 0" class="p-3" v-for="onlineOrder in onlineOrders" :key="onlineOrder">
              <div class="w-full rounded-lg border border-[#EFF0F6]">
                <div class="py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#FFF6EE]">
                  <div class="flex items-center gap-1 text-[#FF8C1A]">
                    <i class="lab lab-processing lab-font-size-16 text-[#FF8C1A]"></i>
                    <span class="text-sm font-normal">#{{ onlineOrder.order_serial_no }}</span>
                  </div>
                  <span class="py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize text-white "
                    :class="onlineOrder.status === enums.orderStatusEnum.PREPARED ? 'bg-[#2AC769]' : (onlineOrder.status === enums.orderStatusEnum.ACCEPT ? 'bg-primary' : 'bg-[#F6A609]')">{{
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
                  <button type="button" @click="openFilterSlide($event)"
                    class="filter group text-xs font-[300] flex justify-between items-center w-full"
                    :class="onlineOrder.is_advance_order === enums.askEnum.YES ? 'text-[#008BBA]' : 'text-[#6E7191]'">
                    <span>{{ onlineOrder.order_datetime }}</span>
                    <div
                      class="flex items-center justify-center w-6 h-6 rounded-full bg-[#FFEDF4] text-base font-semibold transition-all duration-500 group-hover:text-primary">
                      <i class="icon text-primary fa-solid fa-chevron-down"></i>
                    </div>
                  </button>
                  <div style="height: 0px" class="overflow-hidden transition-all duration-500">
                    <div v-for="(item, iIdx) in onlineOrder.order_items" :key="item.id || iIdx"
                      class="flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none">
                      <h4 class="text-sm font-medium">{{ item.quantity }}x</h4>
                      <div>
                        <h5 class="text-sm font-medium mb-1">{{ item.item_name }}</h5>
                        <!-- [Y2 FIX] Guard item_variations -->
                        <p v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0"
                          class="text-xs font-normal font-client capitalize text-[#6E7191]">
                          <span v-for="(variation, index) in item.item_variations" :key="index" class="text-heading">
                            {{ variation.variation_name }}: {{ variation.name }}<span
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
                        <div v-if="item.instruction && item.instruction !== ''" class="kds-instruction mt-1 text-xs text-heading" style="white-space: pre-line;">{{ item.instruction }}</div>
                      </div>
                    </div>
                    <button v-if="onlineOrder.status === enums.orderStatusEnum.ACCEPT" type="button"
                      @click="orderStatus(onlineOrder.id, enums.orderStatusEnum.PREPARING)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-primary text-white">
                      {{ $t("label.start_preparing") }}
                    </button>
                    <button v-if="onlineOrder.status === enums.orderStatusEnum.PREPARING" type="button"
                      @click="orderStatus(onlineOrder.id, enums.orderStatusEnum.PREPARED)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-[#1AB759] text-white">
                      {{ $t("label.mark_done") }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="db-card rounded-[10px] h-fit">
            <div class="p-3 pb-2" :class="takeawayOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''">
              <h3 class="text-lg font-semibold">{{ $t("label.takeaway") }}</h3>
            </div>
            <div v-if="takeawayOrders.length > 0" class="p-3" v-for="takeawayOrder in takeawayOrders"
              :key="takeawayOrder">
              <div class="w-full rounded-lg border border-[#EFF0F6]">
                <div class="py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#FAF4FF]">
                  <div class="flex items-center gap-1 text-[#9837FF]">
                    <i class="lab lab-processing lab-font-size-16 text-[#9837FF]"></i>
                    <span class="text-sm font-normal">#{{ takeawayOrder.order_serial_no }}</span>
                  </div>
                  <span class="py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize text-white "
                    :class="takeawayOrder.status === enums.orderStatusEnum.PREPARED ? 'bg-[#2AC769]' : (takeawayOrder.status === enums.orderStatusEnum.ACCEPT ? 'bg-primary' : 'bg-[#F6A609]')">{{
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
                  <button type="button" @click="openFilterSlide($event)"
                    class="filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full">
                    <span>{{ takeawayOrder.order_datetime }}</span>
                    <div
                      class="flex items-center justify-center w-6 h-6 rounded-full bg-[#FFEDF4] text-base font-semibold transition-all duration-500 group-hover:text-primary">
                      <i class="icon text-primary fa-solid fa-chevron-down"></i>
                    </div>
                  </button>
                  <div style="height: 0px" class="overflow-hidden transition-all duration-500">
                    <div v-for="(item, iIdx) in takeawayOrder.order_items" :key="item.id || iIdx"
                      class="flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none">
                      <h4 class="text-sm font-medium">{{ item.quantity }}x</h4>
                      <div>
                        <h5 class="text-sm font-medium mb-1">{{ item.item_name }}</h5>
                        <!-- [Y2 FIX] Guard item_variations -->
                        <p v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0"
                          class="text-xs font-normal font-client capitalize text-[#6E7191]">
                          <span v-for="(variation, index) in item.item_variations" :key="index" class="text-heading">
                            {{ variation.variation_name }}: {{ variation.name }}<span
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
                        <!-- [P3-1 FIX] Show instruction on takeaway cards -->
                        <div v-if="item.instruction && item.instruction !== ''" class="kds-instruction mt-1 text-xs text-heading" style="white-space: pre-line;">{{ item.instruction }}</div>
                      </div>
                    </div>
                    <button v-if="takeawayOrder.status === enums.orderStatusEnum.ACCEPT" type="button"
                      @click="orderStatus(takeawayOrder.id, enums.orderStatusEnum.PREPARING)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-primary text-white">
                      {{ $t("label.start_preparing") }}
                    </button>
                    <button v-if="takeawayOrder.status === enums.orderStatusEnum.PREPARING" type="button"
                      @click="orderStatus(takeawayOrder.id, enums.orderStatusEnum.PREPARED)"
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
            <div class="p-3 pb-2 flex items-center gap-2" :class="kioskOrders.length > 0 ? 'border-b border-[#D9DBE9] mb-2' : ''">
              <h3 class="text-lg font-semibold">🖥️ Borne</h3>
              <span v-if="kioskOrders.length > 0"
                class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-[#e53935] text-white text-[10px] font-bold">
                {{ kioskOrders.length }}
              </span>
            </div>
            <div v-if="kioskOrders.length === 0" class="p-3 text-sm text-[#6E7191]">
              Aucune commande borne en cours.
            </div>
            <div v-if="kioskOrders.length > 0" class="p-3" v-for="kioskOrder in kioskOrders" :key="kioskOrder.id">
              <div class="w-full rounded-lg border border-[#EFF0F6]">
                <div class="py-2.5 px-3 w-full rounded-t-lg flex items-center justify-between bg-[#FFF0EE]">
                  <div class="flex items-center gap-2 text-[#e53935]">
                    <i class="lab lab-processing lab-font-size-16 text-[#e53935]"></i>
                    <span class="text-sm font-normal">#{{ kioskOrder.order_serial_no }}</span>
                    <span v-if="kioskOrder.queue_number"
                      class="bg-[#e53935] text-white text-xs font-bold px-2 py-0.5 rounded-full">
                      N°{{ kioskOrder.queue_number }}
                    </span>
                  </div>
                  <span class="py-0.5 px-2 rounded-[4px] text-[10px] font-client leading-4 capitalize text-white"
                    :class="kioskOrder.status === enums.orderStatusEnum.PREPARED ? 'bg-[#2AC769]' : (kioskOrder.status === enums.orderStatusEnum.ACCEPT ? 'bg-primary' : 'bg-[#F6A609]')">
                    {{ kioskOrder.status === enums.orderStatusEnum.PREPARED ? $t('label.done') :
                      (kioskOrder.status === enums.orderStatusEnum.ACCEPT ? $t('label.confirmed') : kioskOrder.status_name) }}
                  </span>
                </div>
                <div class="w-full pt-2 pb-3 px-3">
                  <button type="button" @click="openFilterSlide($event)"
                    class="filter group text-[#6E7191] text-xs font-[300] flex justify-between items-center w-full">
                    <span>{{ kioskOrder.order_datetime }}</span>
                    <div class="flex items-center justify-center w-6 h-6 rounded-full bg-[#FFEDF4] text-base font-semibold transition-all duration-500 group-hover:text-primary">
                      <i class="icon text-primary fa-solid fa-chevron-down"></i>
                    </div>
                  </button>
                  <div style="height: 0px" class="overflow-hidden transition-all duration-500">
                    <div v-for="(item, iIdx) in kioskOrder.order_items" :key="item.id || iIdx"
                      class="flex items-start gap-2 py-3 border-b border-dashed border-[#EFF0F6] last:border-none">
                      <h4 class="text-sm font-medium">{{ item.quantity }}x</h4>
                      <div>
                        <h5 class="text-sm font-medium mb-1">{{ item.item_name }}</h5>
                        <!-- [Y2 FIX] Guard item_variations -->
                        <p v-if="Array.isArray(item.item_variations) && item.item_variations.length > 0"
                          class="text-xs font-normal font-client capitalize text-[#6E7191]">
                          <span v-for="(variation, index) in item.item_variations" :key="index" class="text-heading">
                            {{ variation.variation_name }}: {{ variation.name }}<span
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
                        <div v-if="item.instruction && item.instruction !== ''" class="kds-instruction mt-1 text-xs text-heading" style="white-space: pre-line;">{{ item.instruction }}</div>
                      </div>
                    </div>
                    <button v-if="kioskOrder.status === enums.orderStatusEnum.ACCEPT" type="button"
                      @click="orderStatus(kioskOrder.id, enums.orderStatusEnum.PREPARING)"
                      class="rounded-lg w-full h-9 flex justify-center items-center text-sm font-medium bg-primary text-white">
                      {{ $t('label.start_preparing') }}
                    </button>
                    <button v-if="kioskOrder.status === enums.orderStatusEnum.PREPARING" type="button"
                      @click="orderStatus(kioskOrder.id, enums.orderStatusEnum.PREPARED)"
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
  </div>
</template>
<script>
import LoadingComponent from "../components/LoadingComponent";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import statusEnum from "../../../enums/modules/statusEnum";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import askEnum from "../../../enums/modules/askEnum";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
import { Swiper, SwiperSlide } from "swiper/vue";


export default {
  name: "KitchenDisplaySystemComponent",
  components: {
    LoadingComponent,
    Swiper,
    SwiperSlide
  },
  data() {
    return {
      loading: {
        isActive: false,
      },
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
        askEnum: askEnum,
      },
      autoRefreshInterval: null,
    };
  },
  computed: {
    orders: function () {
      return this.$store.getters["kitchenDisplaySystemOrder/lists"];
    },
    orderItems: function () {
      return this.$store.getters["kitchenDisplaySystemOrder/orderItems"];
    },
  },
  mounted() {
    this.closeSidebar();
    this.refreshOrderList();
    this.startAutoRefresh();
    window.addEventListener('realtime-order-update', this.refreshOrderList);
    // [P4-1] Subscribe to branch Echo channel for sub-second push updates
    this.subscribeEcho();
  },
  methods: {
    startAutoRefresh() {
      if (this.$route.path.includes('kitchen-display-system')) {
        this.autoRefreshInterval = setInterval(() => {
          this.refreshOrderList();
        }, 30000); // 30s fallback polling — Echo push is primary
      }
    },
    // [P4-1] Subscribe to branch Echo channel for real-time order updates
    // Admin users (branch_id=0) rely on 30s polling; branch staff get sub-second push
    subscribeEcho() {
      if (!window.Echo) return;
      const branchId = parseInt(this.$store.getters['auth/authBranchId'] || 0);
      if (branchId <= 0) return; // Admin: polling fallback is sufficient
      try {
        this._echoChannel = window.Echo.private(`branch.${branchId}`)
          .listen('.OrderStatusChanged', () => { this.refreshOrderList(); })
          .listen('.OrderCreated', () => { this.refreshOrderList(); });
      } catch (e) {
        // Echo not available or auth failed — polling fallback handles it
      }
    },
    unsubscribeEcho() {
      if (!window.Echo || !this._echoChannel) return;
      const branchId = parseInt(this.$store.getters['auth/authBranchId'] || 0);
      if (branchId <= 0) return;
      try { window.Echo.leave(`branch.${branchId}`); } catch (e) {}
      this._echoChannel = null;
    },
    refreshOrderList() {
      this.items();
      this.list(this.props.search.status);
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
          this.dineinOrders = res.data.data.filter(
            (item) => item.order_type === orderTypeEnum.DINING_TABLE
          );
          this.onlineOrders = res.data.data.filter(
            (item) => item.order_type === orderTypeEnum.DELIVERY
          );
          this.takeawayOrders = res.data.data.filter(
            (item) => item.order_type === orderTypeEnum.TAKEAWAY
          );
          this.kioskOrders = res.data.data.filter(
            (item) => item.order_type === orderTypeEnum.KIOSK
          );

          this.loading.isActive = false;
        })
        .catch((err) => {
          this.loading.isActive = false;
        });
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
    orderStatus: function (id, status) {
      try {
        this.loading.isActive = true;
        this.$store.dispatch("kitchenDisplaySystemOrder/changeStatus", {
          id: id,
          status: status,
        }).then((res) => {
          this.loading.isActive = false;
          alertService.successFlip(
            1,
            this.$t("label.status")
          );
          this.list();
          this.items();
          // Propager le changement de statut à tous les composants qui écoutent (OSS, autres KDS)
          window.dispatchEvent(new CustomEvent('realtime-order-update', {
            detail: { type: 'status-change', order_id: id, status: status }
          }));
        }).catch((err) => {
          this.loading.isActive = false;
          alertService.error(err.response.data.message);
        });
      } catch (err) {
        this.loading.isActive = false;
        alertService.error(err.response.data.message);
      }
    },
    toggleFilter(index) {
      if (this.expandedFilter === index) {
        this.expandedFilter = null;
      } else {
        this.expandedFilter = index;
      }
    },

  },
  beforeUnmount() {
    this.stopAutoRefresh();
    this.openSidebar();
    window.removeEventListener('realtime-order-update', this.refreshOrderList);
    // [P4-1] Unsubscribe Echo channel on unmount
    this.unsubscribeEcho();
  },
};
</script>

<style scoped>
.kds-instruction {
  line-height: 1.5;
}
</style>