<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <div class="db-card p-4">
            <div class="flex flex-wrap gap-y-5 items-end justify-between">
                <div>
                    <div class="flex flex-wrap items-start gap-y-2 gap-x-6 mb-5">
                        <p class="text-2xl font-medium">
                            {{ $t('label.order_id') }}:
                            <span class="text-heading">
                                #{{ order.order_serial_no }}
                            </span>
                        </p>
                        <div class="flex items-center gap-2 mt-1.5">
                            <span
                                :class="'text-xs capitalize h-5 leading-5 px-2 rounded-3xl text-[#FB4E4E] bg-[#FFDADA]' + statusClass(order.payment_status)">
                                {{ paymentStatusEnumArray[order.payment_status] }}
                            </span>
                            <span :class="'text-xs capitalize px-2 rounded-3xl ' + orderStatusClass(order.status)">
                                {{ orderStatusEnumArray[order.status] }}
                            </span>
                        </div>
                    </div>
                    <ul class="flex flex-col gap-2">
                        <li class="flex items-center gap-2">
                            <i class="lab lab-calendar-line lab-font-size-16"></i>
                            <span class="text-xs">{{ order.order_datetime }}</span>
                        </li>
                        <li class="text-xs">
                            {{ $t('label.payment_type') }}:

                            <span class="text-heading">
                                {{ posPaymentMethodEnumArray[order.pos_payment_method] }}

                                <span
                                    v-if="order.pos_payment_method !== enums.posPaymentMethodEnum.CASH && order.pos_payment_note">
                                    ({{ order.pos_payment_note }})</span>
                            </span>


                        </li>
                        <li class="text-xs">
                            {{ $t('label.order_type') }}:
                            <span class="text-heading">
                                {{ orderTypeEnumArray[order.order_type] }}
                            </span>
                        </li>
                        <!--
                          [S2 V6 2026-07-29] « Heure de livraison » s'affichait sur une
                          commande À EMPORTER et sur une commande BORNE, qui ne sont jamais
                          livrées : c'est le créneau de RETRAIT. Le libellé suit désormais le
                          type de commande, et la ligne disparaît si aucun créneau n'est posé.
                        -->
                        <li class="text-xs" v-if="order.delivery_date || order.delivery_time">{{
                            isDeliveryOrder ? $t('label.delivery_time') : $t('label.pickup_time')
                        }}:
                            <span class="text-heading">
                                {{ order.delivery_date }} {{ order.delivery_time }}
                            </span>
                        </li>
                        <!-- [WT-D-R1-07 2026-05-20] Internal token reference
                             (kiosk/online flows). Relabelled FR "Référence
                             interne" to avoid collision with `order_serial_no`
                             (the real "N° commande" displayed at top). Hidden
                             entirely if the token visually duplicates the
                             customer first word (defensive guard against
                             noisy fixture-injected tokens that bleed into
                             the customer name field). -->
                        <li class="text-xs" v-if="displayedToken">
                            {{ $t('label.order_token') }}:
                            <span class="text-heading text-[10px] opacity-75 font-mono">
                                {{ displayedToken }}
                            </span>
                        </li>
                        <li class="text-xs" v-if="order.table_name">
                            {{ $t("label.table_name") }}:
                            <span class="text-heading">
                                {{ order.table_name }}
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-wrap gap-3"
                    v-if="order.status !== enums.orderStatusEnum.REJECTED && order.status !== enums.orderStatusEnum.CANCELED">
                    <!-- [WT-D-R1-09 / WT-D-R1-02 2026-05-20] Delivery-boy
                         assignment dropdown — WCAG AA combobox+listbox
                         pattern overlaid on existing dropdown-group hover
                         behaviour. data-testid hooks added for E2E.
                         A live-region chip is rendered below the button when
                         a driver is assigned so the cashier sees confirmation
                         without consulting the toast. -->
                    <div class="dropdown-group" v-if="order.order_type === enums.orderTypeEnum.DELIVERY"
                        role="combobox" aria-haspopup="listbox" aria-expanded="false"
                        :aria-label="$t('label.select_delivery_boy')"
                        :class="{ 'driver-assigned-flash': driverFlashHighlight }"
                        data-testid="pos-driver-assign-group">
                        <button type="button"
                            class="min-w-[162px] flex items-center justify-start text-sm capitalize appearance-none pl-2 h-[38px] rounded border border-primary bg-white text-primary dropdown-btn"
                            :aria-label="order?.delivery_boy?.id
                                ? $t('label.driver_assigned') + ': ' + order.delivery_boy.name
                                : $t('label.select_delivery_boy')"
                            data-testid="pos-driver-assign-btn">
                            <span class="flex-1 text-start">{{ order?.delivery_boy?.id ? order?.delivery_boy?.name :
                                $t('label.select_delivery_boy') }}
                            </span>
                            <i class="lab lab-arrow-down-2 lab-font-size-17 mx-1" aria-hidden="true"></i>
                        </button>
                        <ul role="listbox"
                            :aria-label="$t('label.select_delivery_boy')"
                            class="p-2 rounded-lg shadow-xl absolute top-10 ltr:right-0 rtl:left-0 z-10 bg-white transition-all duration-300 origin-top scale-y-0 dropdown-list w-full">
                            <li role="option"
                                :aria-selected="order?.delivery_boy?.id === deliveryBoy.id ? 'true' : 'false'"
                                tabindex="0"
                                class="active flex items-center gap-2 py-1 px-2.5 rounded-md cursor-pointer hover:bg-gray-100 focus:outline focus:outline-2 focus:outline-primary"
                                v-for="deliveryBoy in deliveryBoys" :key="deliveryBoy.id"
                                @click="selectDeliveryBoy(deliveryBoy.id, deliveryBoy.name)"
                                @keydown.enter.prevent="selectDeliveryBoy(deliveryBoy.id, deliveryBoy.name)"
                                @keydown.space.prevent="selectDeliveryBoy(deliveryBoy.id, deliveryBoy.name)"
                                :data-testid="'pos-driver-option-' + deliveryBoy.id">
                                <span class="text-heading capitalize text-sm"
                                    :class="order?.delivery_boy?.id === deliveryBoy.id ? 'text-primary' : ''">{{
                                        deliveryBoy.name
                                    }}</span>
                            </li>
                        </ul>
                        <!-- Live-region success chip — visible UI confirmation
                             that assignment landed (WT-D-R1-02 fix). -->
                        <p v-if="order?.delivery_boy?.id"
                            class="driver-assigned-chip mt-1.5 inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-[#E6F8EE] text-[#1AB759] font-semibold"
                            role="status" aria-live="polite"
                            data-testid="pos-driver-assigned-chip">
                            <i class="lab lab-tick-square lab-font-size-12" aria-hidden="true"></i>
                            {{ $t('label.driver_assigned') }}: {{ order.delivery_boy.name }}
                        </p>
                    </div>

                    <div class="dropdown-group">
                        <button
                            class="min-w-[97px] flex items-center gap-4 justify-start text-sm capitalize appearance-none pl-2 h-[38px] rounded border border-primary bg-white text-primary dropdown-btn">
                            <span class="flex-1 text-start">{{ paymentStatusEnumArray[order.payment_status]
                                }}</span>
                            <i class="lab lab-arrow-down-2 lab-font-size-17 mx-1"></i>
                        </button>
                        <ul
                            class="p-2 rounded-lg shadow-xl absolute top-10 ltr:right-0 rtl:left-0 z-10 bg-white transition-all duration-300 origin-top scale-y-0 dropdown-list w-full">
                            <li class="active flex items-center gap-2 py-1 px-2.5 rounded-md cursor-pointer hover:bg-gray-100"
                                v-for="paymentStatus in paymentStatusObject" :key="paymentStatus.value"
                                @click="changePaymentStatus(paymentStatus.value)">
                                <span class="text-heading capitalize text-sm"
                                    :class="order.payment_status === paymentStatus.value ? 'text-primary' : ''">{{
                                        paymentStatus.name
                                    }}</span>

                            </li>
                        </ul>
                    </div>

                    <div class="dropdown-group">
                        <button
                            class="min-w-[150px] flex items-center justify-start text-sm capitalize appearance-none pl-2 h-[38px] rounded border border-primary bg-white text-primary dropdown-btn">
                            <span class="flex-1 text-start">{{ orderStatusEnumArray[order.status] }}</span>
                            <i class="lab lab-arrow-down-2 lab-font-size-17 mx-1"></i>
                        </button>
                        <ul
                            class="p-2 rounded-lg shadow-xl absolute top-10 ltr:right-0 rtl:left-0 z-10 bg-white transition-all duration-300 origin-top scale-y-0 dropdown-list w-full">
                            <li class="active flex items-center gap-2 py-1 px-2.5 rounded-md cursor-pointer hover:bg-gray-100"
                                v-for="status in orderStatusObject" :key="status.value"
                                @click="orderStatus(status.value)">
                                <span class="text-heading capitalize text-sm"
                                    :class="order.status === status.value ? 'text-primary' : ''">{{ status.name
                                    }}</span>

                            </li>
                        </ul>
                    </div>

                    <button type="button" v-print="printObj"
                        class="flex items-center justify-center gap-2 px-4 h-[38px] rounded shadow-db-card bg-primary">
                        <i class="lab lab-printer-line lab-font-size-16 text-white"></i>
                        <span class="text-sm capitalize text-white">{{ $t('button.print_invoice') }}</span>
                    </button>
                    <!--
                      [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26]
                      POS Refund UI button — NF525 counter-entry refund CTA.
                      Visible ONLY when:
                        - order is PAID (payment_status === PAID)
                        - order is NOT already a refund mirror (parent_order_id null/0)
                        - current user holds `pos-refund` permission (Admin or Branch Manager)
                      Opens PosRefundModal which POSTs to backend route
                      /api/admin/pos-order/{id}/refund-with-counter-entry — service
                      creates an immutable mirror order with full-negate totals +
                      fresh fiscal_sequence_no, preserving the NF525 append-only chain.
                      Backend triple-defense: UUID idempotency + UNIQUE(parent_order_id)
                      + 409 MIRROR_ALREADY_EXISTS handler (PosOrderController:82-96).
                    -->
                    <button
                        v-if="canShowRefund"
                        type="button"
                        class="flex items-center justify-center gap-2 px-4 h-[38px] rounded shadow-db-card border-2 border-[#cf3a3a] bg-white text-[#cf3a3a] hover:bg-[#cf3a3a] hover:text-white transition"
                        data-testid="pos-order-refund-open"
                        @click="openRefundModal"
                    >
                        <span aria-hidden="true">💸</span>
                        <span class="text-sm capitalize">{{ $t('pos.refund.btn_open') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 sm:col-6">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">{{ $t('label.order_details') }}</h3>
            </div>
            <div class="db-card-body">
                <div class="pl-3">
                    <div class="mb-3 pb-3 border-b last:mb-0 last:pb-0 last:border-b-0 border-gray-2"
                        v-if="orderItems.length > 0" v-for="item in orderItems" :key="item">
                        <div class="flex items-center gap-3 relative">
                            <h3
                                class="absolute top-5 -left-3 text-sm w-[26px] h-[26px] leading-[26px] text-center rounded-full text-white bg-heading">
                                {{ item.quantity }}</h3>
                            <img class="w-16 h-16 rounded-lg flex-shrink-0" :src="item.item_image" alt="thumbnail">
                            <div class="w-full">
                                <a href="#"
                                    class="text-sm font-medium capitalize transition text-heading hover:underline">
                                    {{ item.item_name }}
                                </a>
                                <p v-if="item.item_variations.length !== 0" class="capitalize text-xs mb-1.5">
                                    <!--
                                      [S2 V6 2026-07-29] Passage par le normaliseur canonique
                                      (posReceiptBuilder), déjà utilisé par le ticket. La lecture
                                      brute était FAUSSE sur les commandes récentes : dans
                                      l'instantané NF525, `variation_name` porte le CHOIX et
                                      `attribute_name` l'intitulé — l'inverse de l'ancienne forme.
                                      D'où l'affichage « Mayonnaise: » suivi de rien.
                                    -->
                                    <span v-for="(variation, index) in normalizedVariations(item)" :key="index">
                                        {{ variation.label }}: {{ variation.name }}<span
                                            v-if="index + 1 < normalizedVariations(item).length">,&nbsp;</span>
                                    </span>
                                </p>
                                <!-- [WT-D-R1-F4 2026-05-20] Canonical FR EUR via shared `formatPrice()` — `item.total_price` shipped raw by OrderItemResource. -->
                                <h3 class="text-xs font-semibold">{{ formatPrice(item.total_price) }}</h3>
                            </div>
                        </div>
                        <!--
                          [S2 V6 2026-07-29] Gardes sur le CONTENU réel : `instruction` vaut
                          NULL en base (pas ''), donc l'ancien test `!== ''` était toujours vrai
                          et affichait une ligne « Instruction: » vide sur chaque article ; et
                          les extras de l'instantané n'exposent pas `name` mais `extra_name`,
                          d'où le « Extras: , » orphelin. Le normaliseur écarte déjà les
                          entrées sans nom.
                        -->
                        <ul v-if="normalizedExtras(item).length > 0 || unnamedExtrasCount(item) > 0 || normalizedAddons(item).length > 0 || hasInstruction(item)"
                            class="flex flex-col gap-1.5 mt-2">
                            <!--
                              [S2 V6 2026-07-29] Filet d'honnêteté : le normaliseur écarte les
                              entrées sans nom (anciennes lignes ne portant que des ids). On ne
                              doit pas pour autant faire DISPARAÎTRE l'existence d'un supplément
                              facturé — on l'annonce alors sans le nommer. Aucune ligne de ce
                              type dans la base actuelle (38 lignes sans instantané, 0 avec
                              composition) : c'est une garantie par construction, pas un
                              correctif de symptôme.
                            -->
                            <li class="flex gap-1" v-if="normalizedExtras(item).length > 0">
                                <h3 class="capitalize text-xs w-fit whitespace-nowrap">{{ $t('label.extras') }}:</h3>
                                <p class="text-xs">
                                    <span v-for="(extra, index) in normalizedExtras(item)" :key="index">
                                        {{ extra.name }}<span v-if="extra.quantity > 1"> ×{{ extra.quantity }}</span><span
                                            v-if="index + 1 < normalizedExtras(item).length">,&nbsp;</span>
                                    </span>
                                </p>
                            </li>
                            <li class="flex gap-1" v-else-if="unnamedExtrasCount(item) > 0">
                                <h3 class="capitalize text-xs w-fit whitespace-nowrap">{{ $t('label.extras') }}:</h3>
                                <p class="text-xs text-[#6E7191]">{{ $t('label.unnamed_extras', { count: unnamedExtrasCount(item) }) }}</p>
                            </li>
                            <!--
                              [GOAL-CAISSE-VISION 2026-08-24] Les SUPPLÉMENTS DE FORMULE (addons).
                              Ils étaient facturés (`CompositionSnapshotBuilder.php:166-177`) ET
                              imprimés sur le ticket client (`ReceiptComponent.vue:162-170`), mais
                              cette fiche — celle que le caissier ouvre quand un client conteste —
                              n'en portait AUCUNE trace : `grep -c addon` y valait 0. Un client
                              demandant « pourquoi 3 € de plus ? » recevait une fiche muette.
                              Même normaliseur que le ticket, donc même vérité.
                            -->
                            <li class="flex gap-1" v-if="normalizedAddons(item).length > 0">
                                <h3 class="capitalize text-xs w-fit whitespace-nowrap">{{ $t('label.addons') }}:</h3>
                                <p class="text-xs" data-testid="pos-order-show-addons">
                                    <span v-for="(addon, index) in normalizedAddons(item)" :key="index">
                                        {{ addon.name }}<span v-if="addon.quantity > 1"> ×{{ addon.quantity }}</span><span
                                            v-if="index + 1 < normalizedAddons(item).length">,&nbsp;</span>
                                    </span>
                                </p>
                            </li>
                            <li class="flex gap-1" v-if="hasInstruction(item)">
                                <h3 class="capitalize text-xs w-fit whitespace-nowrap">{{
                                    $t('label.instruction')
                                }}:</h3>
                                <p class="text-xs">{{ item.instruction }}</p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 sm:col-6">
        <div class="row">
            <div class="col-12">
                <div class="db-card p-1">
                    <ul class="flex flex-col gap-2 p-3 border-b border-dashed border-[#EFF0F6]">
                        <!-- [WT-D-R1-F4 2026-05-20] Canonical FR EUR
                             rendering via shared `formatPrice()` helper.
                             `OrderDetailsResource` already ships raw numeric
                             `subtotal` / `discount` / `delivery_charge` /
                             `total` — we feed them through `formatPrice()` so
                             every admin surface renders "19,00 €" instead of
                             "19.00€" (glued, no space). -->
                        <li class="flex items-center justify-between text-heading">
                            <span class="text-sm leading-6 capitalize">{{ $t('label.subtotal') }}</span>
                            <span class="text-sm leading-6 capitalize">{{ formatPrice(order.subtotal) }}</span>
                        </li>
                        <li class="flex items-center justify-between text-heading">
                            <span class="text-sm leading-6 capitalize">{{ $t('label.discount') }}</span>
                            <span class="text-sm leading-6 capitalize">{{ formatPrice(order.discount) }}</span>
                        </li>
                        <li v-if="order.order_type === enums.orderTypeEnum.DELIVERY"
                            class="flex items-center justify-between text-heading">
                            <span class="text-sm leading-6 capitalize">{{ $t('label.delivery_charge') }}</span>
                            <span class="text-sm leading-6 capitalize font-semibold text-[#1AB759]">
                                {{ formatPrice(order.delivery_charge) }}
                            </span>
                        </li>
                    </ul>
                    <div class="flex items-center justify-between p-3">
                        <h4 class="text-sm leading-6 font-bold capitalize">{{ $t('label.total') }}</h4>
                        <h5 class="text-sm leading-6 font-bold capitalize">
                            {{ formatPrice(order.total) }}
                        </h5>
                    </div>
                    <!-- [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier
                         loyalty redeem CTA. Visible only when the order is
                         not yet paid AND not in a terminal state. Pressing
                         it opens a Vue overlay (no frozen-zone touch).
                         Permission gate `pos.redeem-loyalty` is enforced
                         server-side by the FormRequest. -->
                    <div
                        v-if="order.id && (canShowLoyaltyRedeem || canShowLoyaltyIdentify)"
                        class="flex items-center justify-end gap-2 p-3 pt-0"
                    >
                        <!--
                          [FIDÉLITÉ COMPTOIR 2026-08-14 · propriétaire] « il a passé une commande
                          hier, il n'y avait pas de points ajoutés, je veux qu'ils soient ajoutés » —
                          il n'existait AUCUN moyen de rattacher un client à une commande déjà
                          servie depuis l'historique. `attachCustomer` (backend) le permettait déjà
                          sur une commande DELIVERED — seule cette entrée manquait. Visible plus
                          largement que la remise : identifier/rattacher ne dépense rien, donc reste
                          possible après paiement (seule une vente morte — annulée/rejetée/rendue —
                          le referme, mêmes gardes que le serveur).
                        -->
                        <button
                            v-if="canShowLoyaltyIdentify"
                            type="button"
                            class="text-xs px-3 py-1.5 rounded border border-primary text-primary hover:bg-primary hover:text-white transition"
                            data-testid="pos-loyalty-identify-open"
                            @click="loyaltyIdentifyOpen = true"
                        >
                            Fidélité client
                        </button>
                        <button
                            v-if="canShowLoyaltyRedeem"
                            type="button"
                            class="text-xs px-3 py-1.5 rounded border border-primary text-primary hover:bg-primary hover:text-white transition"
                            data-testid="pos-loyalty-redeem-open"
                            @click="loyaltyRedeemOpen = true"
                        >
                            {{ $t('pos.loyalty.redeem.title') }}
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="db-card">
                    <div class="db-card-header">
                        <!--
                          [S2 V6 2026-07-29] Le titre était « Informations de livraison » sur
                          TOUTES les commandes — y compris un « À emporter » et une commande
                          borne, qui ne sont jamais livrés. Le bloc ne contient d'ailleurs
                          l'adresse que pour les livraisons (v-if plus bas) : ailleurs c'est
                          la fiche CLIENT.
                        -->
                        <h3 class="db-card-title">{{ isDeliveryOrder ? $t('label.delivery_information') : $t('label.customer_information') }}</h3>
                    </div>
                    <div class="db-card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <!--
                              [S2 V6 2026-07-29] `orderUser.image` est vide pour un client de
                              passage → l'image cassée affichait son texte alternatif « avat »
                              en plein milieu de la fiche. On ne rend l'avatar que s'il existe.
                            -->
                            <img v-if="orderUser.image" class="w-8 rounded-full" :src="orderUser.image" :alt="$t('label.customer')">
                            <span v-else class="w-8 h-8 rounded-full bg-[#EFF0F6] flex items-center justify-center" aria-hidden="true">
                                <i class="lab lab-profile-circle lab-font-size-16"></i>
                            </span>
                            <h4 class="font-semibold text-sm capitalize text-[#374151]">
                                {{ isWalkIn ? 'Passager' : textShortener(orderUser.name, 20) }}
                            </h4>
                        </div>
                        <ul class="flex flex-col gap-3 py-4 border-[#EFF0F6]"
                            :class="order.order_type === enums.orderTypeEnum.DELIVERY ? 'mb-4 border-y' : 'border-t'">
                            <!-- [S2 V6 2026-07-29] Ligne e-mail vide (icône seule) si le client n'en a pas. -->
                            <li class="flex items-center gap-2.5" v-if="!isWalkIn && orderUser.email">
                                <i class="lab lab-mail lab-font-size-14"></i>
                                <span class="text-xs">{{ orderUser.email }}</span>
                            </li>
                            <li class="flex items-center gap-2.5" v-if="!isWalkIn && orderUser.phone">
                                <i class="lab lab-call-calling-linear lab-font-size-14"></i>
                                <span dir="ltr" class="text-xs">{{ (orderUser.country_code || '') + orderUser.phone
                                    }}</span>
                            </li>
                        </ul>
                        <div v-if="order.order_type === enums.orderTypeEnum.DELIVERY && orderAddress" class="flex items-start gap-3">
                            <i class="lab lab-location lab-font-size-20 leading-6 font-fill-black"></i>
                            <span class="text-sm w-full max-w-[200px] leading-6 text-[#374151]">
                                {{ orderAddress.apartment ? orderAddress.apartment + ', ' : '' }} {{
                                    orderAddress.address }}
                            </span>
                            <PosOrderMapComponent :orderAddress="orderAddress" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <PosOrderReceiptComponent :order="order" />

    <!-- [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] Cashier loyalty redeem
         modal — separate Vue overlay outside the FROZEN pos-wizard.js.
         Opens via the CTA above ; emits 'applied' to refresh order totals. -->
    <PosLoyaltyRedeemModal
        :open="loyaltyRedeemOpen"
        :order-id="order.id"
        @close="loyaltyRedeemOpen = false"
        @applied="onLoyaltyRedeemApplied"
    />

    <!-- [FIDÉLITÉ COMPTOIR 2026-08-14] Identifier / inscrire / rattacher un client depuis une
         commande déjà passée (historique) — le même écran que la page caisse principale. -->
    <PosLoyaltyIdentifyModal
        :open="loyaltyIdentifyOpen"
        :order-id="order.id"
        @close="loyaltyIdentifyOpen = false"
        @attached="onLoyaltyIdentifyAttached"
        @use-points="onLoyaltyIdentifyUsePoints"
    />

    <!-- [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] NF525 Refund modal.
         Reusable standalone modal — opens via the "Rembourser" CTA above.
         On `refunded`, refreshes the order so REMBOURSEMENT marker + new
         payment_status appear without page reload. Modal is fed the
         `refundTarget` (cleared on close); v-if visibility is driven by
         the modal's internal computed (order != null). -->
    <PosRefundModal
        :order="refundTarget"
        @close="onRefundClose"
        @refunded="onRefundCompleted"
    />
</template>
<script>
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import appService from "../../../services/appService";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import TableLimitComponent from "../components/TableLimitComponent";
import paymentStatusEnum from "../../../enums/modules/paymentStatusEnum";
import print from "vue3-print-nb";
import PosOrderReceiptComponent from "./PosOrderReceiptComponent";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import statusEnum from "../../../enums/modules/statusEnum";
// [S2 V6 2026-07-29] Normaliseurs canoniques legacy↔instantané NF525, partagés
// avec le ticket — une seule vérité pour lire une composition (DISCIPLINE §9).
import { normalizeReceiptVariations, normalizeReceiptExtras, normalizeReceiptAddons } from "../../../helpers/posReceiptBuilder";
import PosOrderMapComponent from "./PosOrderMapComponent";
// [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier loyalty redeem modal
// (Option B per plans/LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md). Mounted
// here because PosOrderShowComponent is the canonical detail view ; the
// modal itself is server-permission-gated so it's safe to render the CTA
// unconditionally — backend will 403 unauthorized cashiers.
import PosLoyaltyRedeemModal from "../pos/PosLoyaltyRedeemModal.vue";
import PosLoyaltyIdentifyModal from "../pos/PosLoyaltyIdentifyModal.vue";
// [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] NF525 refund modal.
// Owner-mandate V1 ship gate per PROPOSAL_POS_REFUND_UI_2026-05-25 §3
// Option B (reusable standalone modal). Visibility CTA gated by
// permission `pos-refund` (Admin + Branch Manager ONLY). Backend route
// is also permission-gated (defense-in-depth, fail-fast 403).
import PosRefundModal from "../pos/PosRefundModal.vue";
// [WT-D-R1-F4 2026-05-20] Shared admin FR EUR price formatter — canonicalises
// "19,00 €" across PosOrderShow / PosOrderList / tracker.
import { adminPriceMixin } from "../../../helpers/formatPrice";

export default {
    name: "PosOrderShowComponent",
    mixins: [adminPriceMixin],
    components: {
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        LoadingComponent,
        PosOrderReceiptComponent,
        PosOrderMapComponent,
        PosLoyaltyRedeemModal,
        PosLoyaltyIdentifyModal,
        // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26]
        PosRefundModal,
    },
    directives: {
        print
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            printLoading: true,
            printObj: {
                id: "print",
                popTitle: this.$t("menu.order_receipt"),
            },
            enums: {
                orderStatusEnum: orderStatusEnum,
                paymentStatusEnum: paymentStatusEnum,
                posPaymentMethodEnum: posPaymentMethodEnum,
                orderTypeEnum: orderTypeEnum,
            },
            payment_status: null,
            order_status: null,
            delivery_boy: null,
            // [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] Loyalty redeem modal flag.
            loyaltyRedeemOpen: false,
            // [FIDÉLITÉ COMPTOIR 2026-08-14] Identify/attach modal flag — historique.
            loyaltyIdentifyOpen: false,
            // [WT-D-R1-02 2026-05-20] Brief CSS flash highlight (2s) after
            // successful driver assignment so the cashier visually perceives
            // the state change beyond the toast.
            driverFlashHighlight: false,
            // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] Refund modal target.
            // Non-null = modal visible. Cleared on close/cancel/refunded.
            // The order ref is the live `this.order` snapshot at click time.
            refundTarget: null,
        }
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch('deliveryBoy/lists', {
            order_column: 'id',
            order_type: 'asc',
            status: statusEnum.ACTIVE
        });
        this.$store.dispatch('posOrder/show', this.$route.params.id).then(res => {
            this.payment_status = res.data.data.payment_status;
            this.order_status = res.data.data.status;
            this.delivery_boy = res.data.data.delivery_boy ? res.data.data.delivery_boy.id : 0;
            this.loading.isActive = false;
        }).catch((error) => {
            this.loading.isActive = false;
        });
    },
    computed: {
        order: function () {
            return this.$store.getters['posOrder/show'];
        },
        orderItems: function () {
            return this.$store.getters['posOrder/orderItems'];
        },
        orderUser: function () {
            return this.$store.getters['posOrder/orderUser'];
        },
        // Walk-in client (no real profile) is the DB-seeded placeholder user
        // keyed on this email. Detect it to display "Passager" without the
        // fake seeded contact (email/phone). Precedent: PosComponent.vue:2588.
        isWalkIn: function () {
            return String(this.orderUser && this.orderUser.email || '').toLowerCase() === 'walkingcustomer@example.com';
        },
        // [S2 V6 2026-07-29] Seule une commande LIVRAISON a une adresse, un
        // livreur et une heure de livraison. Emporter / caisse / borne / table
        // affichaient pourtant le vocabulaire de la livraison.
        isDeliveryOrder: function () {
            return parseInt(this.order?.order_type, 10) === orderTypeEnum.DELIVERY;
        },
        orderAddress: function () {
            return this.$store.getters['posOrder/orderAddress'];
        },
        deliveryBoys: function () {
            return this.$store.getters["deliveryBoy/lists"];
        },
        // [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] CTA visibility :
        // only allowed when the order is not yet paid and not in a terminal
        // state. Server-side `pos.redeem-loyalty` permission gate is the
        // authoritative defense — this computed only hides the visual entry
        // point for orders where redeem would always 409.
        canShowLoyaltyRedeem: function () {
            const o = this.order || {};
            if (!o.id) return false;
            if (o.payment_status === paymentStatusEnum.PAID) return false;
            const terminal = [
                orderStatusEnum.DELIVERED,
                orderStatusEnum.CANCELED,
                orderStatusEnum.REJECTED,
                orderStatusEnum.RETURNED,
            ];
            if (terminal.includes(o.status)) return false;
            return true;
        },
        // [FIDÉLITÉ COMPTOIR 2026-08-14] Identifier/rattacher un client — PAS la même règle que la
        // remise. Mirroir exact de la garde serveur `PosLoyaltyAttachService::attach()` : seule une
        // vente MORTE (annulée / rejetée / rendue) referme la porte. DELIVERED et PAID restent
        // ouverts à dessein — c'est précisément le cas d'une vente cash déjà servie hier, que le
        // caissier doit pouvoir retrouver dans l'historique et rattacher.
        canShowLoyaltyIdentify: function () {
            const o = this.order || {};
            if (!o.id) return false;
            const morte = [
                orderStatusEnum.CANCELED,
                orderStatusEnum.REJECTED,
                orderStatusEnum.RETURNED,
            ];
            return !morte.includes(o.status);
        },
        // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] Refund CTA visibility.
        // Backend is authoritative (PosOrderController:54-57 abort_unless can()
        // and RefundWithCounterEntryService own guards) — this computed only
        // hides the visual entry point for cases that would obviously fail:
        //   1. No order loaded yet
        //   2. Order not paid (nothing to refund)
        //   3. Order IS a refund mirror itself (parent_order_id set) — would
        //      DB-UNIQUE-block via 409 MIRROR_ALREADY_EXISTS anyway
        //   4. Current user does NOT hold `pos-refund` permission
        //      (Admin + Branch Manager ONLY by default per RolePermissionTableSeeder)
        // Server permission is the source of truth — frontend hide just spares
        // the cashier a confusing 403 toast.
        canShowRefund: function () {
            const o = this.order || {};
            if (!o.id) return false;
            if (o.payment_status !== paymentStatusEnum.PAID) return false;
            // parent_order_id non-null/0 = this is itself a refund mirror.
            // Cannot refund a refund. Backend UNIQUE constraint also blocks.
            if (o.parent_order_id && Number(o.parent_order_id) > 0) return false;
            // Permission gate — Admin + Branch Manager only by default.
            // appService.permissionChecker reads the same authPermission
            // store array used elsewhere (PosOrderListComponent etc).
            if (!appService.permissionChecker('pos-refund')) return false;
            return true;
        },
        orderStatusObject: function () {
            // [HEAL-4 / PROPOSAL-02 / B2-P3-CF-02 — V101-02 2026-05-26]
            // RETURNED was REMOVED from the selectable status dropdown.
            // Pre-heal a cashier could flip an order to "Returned" via this
            // dropdown to refund cosmetically — WITHOUT creating the NF525
            // counter-entry mirror order. This violates the Loi de Finance
            // France append-only requirement (a refund MUST emit a mirror
            // order in the current Z window so the audit chain reflects the
            // negative line).
            //
            // The legitimate refund path is now the new PosRefundModal CTA
            // ("💸 Rembourser") gated by permission `pos-refund`, which
            // POSTs to /api/admin/pos-order/{id}/refund-with-counter-entry
            // and properly creates the mirror via RefundWithCounterEntryService.
            //
            // We keep RETURNED in `orderStatusEnumArray` (display map) so
            // existing/historical refunded orders still render "Retourné"
            // correctly in the UI. Only the SELECTOR is restricted.
            const list = [
                { name: this.$t("label.accept"), value: orderStatusEnum.ACCEPT },
                { name: this.$t("label.preparing"), value: orderStatusEnum.PREPARING },
                { name: this.$t("label.prepared"), value: orderStatusEnum.PREPARED },
                ...(this.order.order_type !== orderTypeEnum.TAKEAWAY && this.order.order_type !== orderTypeEnum.DINING_TABLE
                    ? [{ name: this.$t("label.out_for_delivery"), value: orderStatusEnum.OUT_FOR_DELIVERY }]
                    : []),
                { name: this.$t("label.delivered"), value: orderStatusEnum.DELIVERED },
                // REMOVED: { name: this.$t("label.returned"), value: orderStatusEnum.RETURNED }
                // Use the Rembourser modal instead — NF525 mirror order required.
            ];

            return list;
        },
        // [S2 V6 2026-07-29] La carte d'affichage était INCOMPLÈTE : PENDING,
        // CANCELED et REJECTED en étaient absents, alors que le SÉLECTEUR
        // ci-dessus n'est volontairement pas exhaustif (l'annulation et le
        // remboursement passent par leurs modales dédiées). Résultat : une
        // commande annulée affichait un badge de statut VIDE — le caissier ne
        // pouvait pas savoir en regardant la fiche qu'elle était annulée.
        // Sélecteur ≠ carte d'affichage : celle-ci doit couvrir TOUT l'enum.
        orderStatusEnumArray: function () {
            return {
                [orderStatusEnum.PENDING]: this.$t("label.pending"),
                [orderStatusEnum.ACCEPT]: this.$t("label.accept"),
                [orderStatusEnum.PREPARING]: this.$t("label.preparing"),
                [orderStatusEnum.PREPARED]: this.$t("label.prepared"),
                [orderStatusEnum.OUT_FOR_DELIVERY]: this.$t("label.out_for_delivery"),
                [orderStatusEnum.DELIVERED]: this.$t("label.delivered"),
                [orderStatusEnum.CANCELED]: this.$t("label.canceled"),
                [orderStatusEnum.REJECTED]: this.$t("label.rejected"),
                [orderStatusEnum.RETURNED]: this.$t("label.returned")
            }
        },
        paymentStatusObject: function () {
            return [
                { name: this.$t("label.paid"), value: paymentStatusEnum.PAID },
                { name: this.$t("label.unpaid"), value: paymentStatusEnum.UNPAID },
            ];
        },
        // [S2 V6 2026-07-29] Idem côté paiement : PENDING_COUNTER (commande à
        // encaisser au comptoir) et REFUNDED (remboursée) manquaient → pastille
        // vide sur toutes les commandes Plan B et sur tous les remboursements.
        paymentStatusEnumArray: function () {
            return {
                [paymentStatusEnum.PAID]: this.$t("label.paid"),
                [paymentStatusEnum.UNPAID]: this.$t("label.unpaid"),
                [paymentStatusEnum.PENDING_COUNTER]: this.$t("label.pending_counter"),
                [paymentStatusEnum.REFUNDED]: this.$t("label.refunded")
            }
        },
        posPaymentMethodEnumArray: function () {
            return {
                [posPaymentMethodEnum.CASH]: this.$t("label.cash"),
                [posPaymentMethodEnum.CARD]: this.$t("label.card"),
                [posPaymentMethodEnum.MOBILE_BANKING]: this.$t("label.mobile_banking"),
                [posPaymentMethodEnum.OTHER]: this.$t("label.other"),
            }
        },
        orderTypeEnumArray: function () {
            return {
                [orderTypeEnum.DELIVERY]: this.$t("label.delivery"),
                [orderTypeEnum.TAKEAWAY]: this.$t("label.takeaway"),
                [orderTypeEnum.DINING_TABLE]: this.$t("label.dining_table")
            }
        },
        // [WT-D-R1-07 2026-05-20] Token is an internal kiosk/online reference,
        // NOT the order number. Suppress it from the visible detail summary
        // when it visibly duplicates the customer name first word (fixture-
        // injected noise) so the cashier sees only "N° {order_serial_no}" up
        // top — no more "N° commande: #Wave" confusion.
        displayedToken: function () {
            const raw = (this.order && this.order.token) ? String(this.order.token).trim() : '';
            if (!raw) return '';
            const customer = (this.orderUser && this.orderUser.name) ? String(this.orderUser.name).trim() : '';
            if (customer) {
                const firstWord = customer.split(/\s+/)[0];
                if (firstWord && raw.toLowerCase().startsWith(firstWord.toLowerCase())) {
                    return '';
                }
            }
            return raw;
        },
    },
    methods: {
        /**
         * [S2 V6 2026-07-29] Composition d'une ligne, lue par le normaliseur
         * canonique partagé avec le ticket : il absorbe l'ancienne forme
         * (`{variation_name, name}`) ET celle de l'instantané NF525
         * (`{attribute_name, variation_name}`, où les rôles sont inversés), et
         * écarte les entrées sans nom. La lecture brute affichait
         * « Mayonnaise: » sans valeur et « Extras: , ».
         */
        normalizedVariations(item) {
            return normalizeReceiptVariations(item?.item_variations);
        },
        normalizedExtras(item) {
            return normalizeReceiptExtras(item?.item_extras);
        },
        /**
         * [GOAL-CAISSE-VISION 2026-08-24] Suppléments de formule (menu : frites,
         * boisson…). Même normaliseur que le ticket client — c'est la condition
         * pour que la fiche et le papier racontent la MÊME commande. `item_addons`
         * est expédié par `OrderItemResource:37` et n'existe que dans l'instantané.
         */
        normalizedAddons(item) {
            return normalizeReceiptAddons(item?.item_addons);
        },
        /**
         * Nombre de suppléments présents dans la donnée mais que le normaliseur
         * n'a pas pu nommer (anciennes lignes réduites à des ids). On les
         * annonce sans les nommer plutôt que de les faire disparaître.
         */
        unnamedExtrasCount(item) {
            const raw = item?.item_extras;
            const list = Array.isArray(raw) ? raw : (raw && typeof raw === 'object' ? Object.values(raw) : []);
            return Math.max(0, list.length - normalizeReceiptExtras(raw).length);
        },
        /** `instruction` vaut NULL en base — `!== ''` laissait passer une ligne vide. */
        hasInstruction(item) {
            return typeof item?.instruction === 'string' && item.instruction.trim() !== '';
        },
        // [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] On successful redemption,
        // refresh the order so subtotal/discount/total + loyalty_customer_code
        // reflect the new state. Then close the modal.
        onLoyaltyRedeemApplied: function () {
            this.loyaltyRedeemOpen = false;
            this.loading.isActive = true;
            this.$store
                .dispatch('posOrder/show', this.$route.params.id)
                .then((res) => {
                    this.payment_status = res.data.data.payment_status;
                    this.order_status = res.data.data.status;
                    this.loading.isActive = false;
                })
                .catch(() => {
                    this.loading.isActive = false;
                });
        },
        // [FIDÉLITÉ COMPTOIR 2026-08-14] Un client vient d'être rattaché (ou créé) sur cette
        // commande de l'historique — la modale affiche déjà son propre message de succès, rien de
        // plus à rafraîchir ici (le rattachement ne change ni le total ni le statut de la commande).
        onLoyaltyIdentifyAttached: function () {
            // Volontairement vide : cf. commentaire ci-dessus.
        },
        // Passe le relais à la fenêtre de remise existante, cohérent avec PosComponent.vue —
        // seule la remise reste gardée pré-paiement (server-side ORDER_ALREADY_FINALIZED sinon).
        onLoyaltyIdentifyUsePoints: function () {
            this.loyaltyIdentifyOpen = false;
            this.loyaltyRedeemOpen = true;
        },
        // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] Refund modal handlers.
        openRefundModal: function () {
            // Snapshot the current order into refundTarget — the modal owns
            // its own form state; we just feed it the order ref.
            this.refundTarget = this.order || null;
        },
        onRefundClose: function () {
            this.refundTarget = null;
        },
        onRefundCompleted: function (_payload) {
            // Refresh the order so the new status (RETURNED) / payment / mirror
            // reference materialize without page reload.
            //
            // [WI-REFUND-PREZ 2026-06-04] The backend routes by Z-status behind
            // ONE endpoint, so `_payload` may describe EITHER mode:
            //   - mode='counter_entry' (post-Z) → mirrorOrder + mirrorFiscalSequenceNo
            //     populated, REMBOURSEMENT mirror appears in the history list.
            //   - mode='pre_z' (same-day, not-yet-Z-closed) → mirrorOrder=null,
            //     mirrorFiscalSequenceNo=null. The parent itself flips to
            //     RETURNED (Retourné) with cashBack + audit — NO mirror exists.
            // This handler is tolerant of BOTH: we just re-fetch the parent and
            // read its fresh status/payment_status; a null mirror is expected and
            // never crashes. The success toast ("Commande remboursée") is raised
            // by PosRefundModal before it emits.
            this.refundTarget = null;
            this.loading.isActive = true;
            this.$store
                .dispatch('posOrder/show', this.$route.params.id)
                .then((res) => {
                    this.payment_status = res.data.data.payment_status;
                    this.order_status = res.data.data.status;
                    this.loading.isActive = false;
                })
                .catch(() => {
                    this.loading.isActive = false;
                });
        },
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        orderStatusClass: function (status) {
            return appService.orderStatusClass(status);
        },
        textShortener: function (text, number = 30) {
            return appService.textShortener(text, number);
        },
        orderStatus: function (status) {
            try {
                this.loading.isActive = true;
                this.$store.dispatch("posOrder/changeStatus", {
                    id: this.$route.params.id,
                    status: status,
                }).then((res) => {
                    this.loading.isActive = false;
                    alertService.successFlip(
                        1,
                        this.$t("label.status")
                    );
                }).catch((err) => {
                    this.loading.isActive = false;
                    alertService.error(err.response.data.message);
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err.response.data.message);
            }
        },
        changePaymentStatus: function (status) {
            try {
                this.loading.isActive = true;
                this.$store.dispatch("posOrder/changePaymentStatus", {
                    id: this.$route.params.id,
                    payment_status: status,
                }).then((res) => {
                    this.loading.isActive = false;
                    alertService.successFlip(
                        1,
                        this.$t("label.payment_status")
                    );
                }).catch((err) => {
                    this.loading.isActive = false;
                    alertService.error(err.response.data.message);
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err.response.data.message);
            }
        },
        // [WT-D-R1-02 2026-05-20] Driver assignment now : (1) shows a
        // human-readable toast naming the driver + order, (2) refreshes the
        // order so the chip+button render the new state, (3) triggers a 2s
        // CSS flash highlight on the dropdown wrapper for visual feedback.
        selectDeliveryBoy: function (id, name) {
            try {
                this.loading.isActive = true;
                const orderSerial = this.order?.order_serial_no || '';
                this.$store.dispatch("posOrder/selectDeliveryBoy", {
                    id: this.$route.params.id,
                    delivery_boy_id: id,
                }).then(() => {
                    // Refresh so order.delivery_boy populates -> chip renders.
                    return this.$store.dispatch('posOrder/show', this.$route.params.id);
                }).then((res) => {
                    this.payment_status = res.data.data.payment_status;
                    this.order_status = res.data.data.status;
                    this.delivery_boy = res.data.data.delivery_boy ? res.data.data.delivery_boy.id : 0;
                    this.loading.isActive = false;

                    // 2s flash highlight on the assignment group.
                    this.driverFlashHighlight = true;
                    setTimeout(() => { this.driverFlashHighlight = false; }, 2000);

                    const driverName = name
                        || (res.data.data.delivery_boy ? res.data.data.delivery_boy.name : '');
                    const msg = this.$t('message.delivery_boy_assigned_to_order', {
                        name: driverName,
                        order: orderSerial,
                    });
                    alertService.success(msg);
                }).catch((err) => {
                    this.loading.isActive = false;
                    alertService.error(err?.response?.data?.message || this.$t('label.error'));
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err?.response?.data?.message || this.$t('label.error'));
            }
        },
    },
}
</script>

<style scoped>
/* =============================================================================
   PosOrderShowComponent — POS V5 Design Convergence (touches 2026-05-02 R2)
   -----------------------------------------------------------------------------
   Cycle    : CV1-POS-DESIGN-CONVERGENCE-001 R2
   Doc plan : §3.9
   Approche : pass design léger — db-card admin standard conservé pour cohérence
   backoffice ; on adopte tokens V5 sur surfaces visibles (radius/shadow, badges,
   dropdowns brand). Aucun template touché.
   ============================================================================= */
:deep(.db-card) {
    border-radius: var(--pos-v5-radius-lg);
    box-shadow: var(--pos-v5-shadow-md);
    border: 1px solid var(--pos-v5-border);
    background: var(--pos-v5-bg-panel);
    overflow: hidden;
}

:deep(.db-card-header) {
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 80%);
    border-bottom: 1px solid var(--pos-v5-border);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-5);
}
:deep(.db-card-title) {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h6);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
}

/* Dropdowns brand (delivery boy / payment status / order status) */
:deep(.dropdown-btn) {
    border-radius: var(--pos-v5-radius-md) !important;
    border-color: var(--pos-v5-brand-red) !important;
    color: var(--pos-v5-brand-red) !important;
    font-family: var(--pos-v5-font-sans);
    font-weight: var(--pos-v5-weight-bold);
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
:deep(.dropdown-btn:hover) {
    background: var(--pos-v5-brand-red-soft);
}

/* Print invoice button */
:deep(button[v-print]),
:deep(.shadow-db-card.bg-primary) {
    background: var(--pos-v5-brand-red) !important;
    border-radius: var(--pos-v5-radius-md);
    box-shadow: var(--pos-v5-shadow-cta-soft);
    font-family: var(--pos-v5-font-sans);
    font-weight: var(--pos-v5-weight-bold);
}

/* Status badges adopt V5 palette */
:deep(.bg-\[\#FFDADA\]) {
    background: var(--pos-v5-danger-soft) !important;
    color: var(--pos-v5-danger-dark) !important;
}

/* [WT-D-R1-02 2026-05-20] Driver-assignment visual feedback. */
.driver-assigned-flash {
    animation: pos-driver-flash 2s ease-out 1;
    border-radius: var(--pos-v5-radius-md, 8px);
}
@keyframes pos-driver-flash {
    0%   { box-shadow: 0 0 0 0 rgba(26, 183, 89, 0.55); }
    35%  { box-shadow: 0 0 0 6px rgba(26, 183, 89, 0.20); }
    100% { box-shadow: 0 0 0 0 rgba(26, 183, 89, 0); }
}
.driver-assigned-chip {
    line-height: 1;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
@media (prefers-reduced-motion: reduce) {
    .driver-assigned-flash { animation: none; }
}
</style>