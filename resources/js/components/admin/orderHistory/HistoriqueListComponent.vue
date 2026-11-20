<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <CaisseSecondaryNav current="historique" />
        <div class="db-card">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.historique') }}</h3>
                <div class="db-card-filter">
                    <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                    <FilterComponent @click.prevent="handleSlide('historique-filter')" />
                </div>
            </div>

            <!--
              [S2 V4 2026-07-29] Filtres rapides TOUJOURS visibles.
              Audit navigation : « voir les commandes annulées » et « filtrer par
              date » coûtaient 5 clics chacun (ouvrir l'accordéon Filtrer → choisir
              → Rechercher). Ces 4 chips appliquent le filtre à la volée depuis
              l'écran, soit 1 clic. Elles pilotent les MÊMES `props.search` que le
              formulaire complet (aucune logique de filtrage dupliquée) et restent
              synchronisées avec lui via `activeQuickFilter`.
            -->
            <div class="hist-quick-filters" role="group" :aria-label="$t('menu.historique')">
                <button
                    v-for="chip in quickFilters"
                    :key="chip.id"
                    type="button"
                    class="hist-chip"
                    :class="{ 'hist-chip--on': activeQuickFilter === chip.id }"
                    :aria-pressed="activeQuickFilter === chip.id ? 'true' : 'false'"
                    :data-testid="`historique-chip-${chip.id}`"
                    @click="applyQuickFilter(chip.id)"
                >{{ chip.label }}</button>
            </div>

            <div class="table-filter-div" id="historique-filter">
                <form class="p-4 sm:p-5 mb-5" @submit.prevent="search">
                    <div class="row">
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="order_id" class="db-field-title after:hidden">{{ $t('label.order_id') }}</label>
                            <input id="order_id" v-model="props.search.order_serial_no" type="text"
                                class="db-field-control">
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchOrigin" class="db-field-title after:hidden">{{ $t('label.origin') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchOrigin"
                                v-model="props.origin" :options="originOptions" label-by="name" value-by="id"
                                :closeOnSelect="true" :searchable="false" :clearOnClose="true" placeholder="--"
                                search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchStatus" class="db-field-title after:hidden">{{ $t('label.status') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchStatus"
                                v-model="props.search.status" :options="[
                                    { id: enums.orderStatusEnum.ACCEPT, name: $t('label.accept') },
                                    { id: enums.orderStatusEnum.PREPARING, name: $t('label.preparing') },
                                    { id: enums.orderStatusEnum.PREPARED, name: $t('label.prepared') },
                                    { id: enums.orderStatusEnum.DELIVERED, name: $t('label.delivered') },
                                    { id: enums.orderStatusEnum.CANCELED, name: $t('label.canceled') },
                                    { id: enums.orderStatusEnum.REJECTED, name: $t('label.rejected') },
                                    { id: enums.orderStatusEnum.RETURNED, name: $t('label.returned') },
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchPayment" class="db-field-title after:hidden">{{ $t('label.payment_status') }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchPayment"
                                v-model="props.search.payment_status" :options="[
                                    { id: enums.paymentStatusEnum.PAID, name: $t('label.paid') },
                                    { id: enums.paymentStatusEnum.UNPAID, name: $t('label.unpaid') },
                                    { id: enums.paymentStatusEnum.PENDING_COUNTER, name: $t('label.pending_counter') },
                                    { id: enums.paymentStatusEnum.REFUNDED, name: $t('label.refunded') },
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="dp-input-searchStartDate" class="db-field-title after:hidden">{{ $t('label.date') }}</label>
                            <Datepicker uid="searchStartDate" name="searchStartDate" hideInputIcon autoApply
                                :enableTimePicker="false" utc="false" @update:modelValue="handleDate"
                                v-model="props.form.date" range :preset-ranges="presetRanges"
                                :aria-labels="{ input: $t('label.date') }">
                                <template #yearly="{ label, range, presetDateRange }">
                                    <button type="button" class="dashboard-date-preset w-full px-3 py-2 text-left rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" @click="presetDateRange(range)">{{ label }}</button>
                                </template>
                            </Datepicker>
                        </div>

                        <div class="col-12">
                            <div class="flex flex-wrap gap-3 mt-4">
                                <button class="db-btn py-2 text-white bg-primary">
                                    <i class="lab lab-search-line lab-font-size-16"></i>
                                    <span>{{ $t('button.search') }}</span>
                                </button>
                                <button class="db-btn py-2 text-white bg-gray-600" @click="clear">
                                    <i class="lab lab-cross-line-2 lab-font-size-22"></i>
                                    <span>{{ $t('button.clear') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="db-table-responsive">
                <!-- [AUDIT-SUPERVISEUR 2026-08-25 · C-002] `hist-table` : marges internes
                     resserrées SUR CETTE TABLE SEULEMENT. Dix colonnes à `px-4` coûtaient
                     320 px de marges, et la table débordait de son conteneur — 181 px
                     mesurés à 1280, 95 px à 1366. La colonne ACTION étant `sticky right`,
                     ce débordement lui faisait RECOUVRIR les colonnes DATE et STATUT :
                     mesuré à ZÉRO pixel rendu sur un état, et une date coupée en plein
                     glyphe sur un autre. Une colonne épinglée n'a rien à masquer si la
                     table tient — c'est le débordement qu'on supprime, pas le symptôme. -->
                <table class="db-table stripe hist-table" :dir="direction">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">{{ $t('label.order_id') }}</th>
                            <th class="db-table-head-th">{{ $t('label.origin') }}</th>
                            <th class="db-table-head-th">{{ $t('label.queue_number') }}</th>
                            <th class="db-table-head-th">{{ $t('label.customer') }}</th>
                            <th class="db-table-head-th">{{ $t('label.amount') }}</th>
                            <th class="db-table-head-th">{{ $t('label.payment') }}</th>
                            <th class="db-table-head-th">{{ $t('label.fiscal_number') }}</th>
                            <th class="db-table-head-th">{{ $t('label.date') }}</th>
                            <th class="db-table-head-th hist-statut-col">{{ $t('label.status') }}</th>
                            <th class="db-table-head-th hist-action-col" v-if="permissionChecker('pos-orders')">{{ $t('label.action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="orders.length > 0">
                        <tr class="db-table-body-tr" v-for="order in orders" :key="order.id">
                            <td class="db-table-body-td">{{ order.order_serial_no }}</td>
                            <td class="db-table-body-td">
                                <span class="hist-origin-badge" :class="originBadge(order).cls">
                                    {{ originBadge(order).label }}
                                </span>
                            </td>
                            <td class="db-table-body-td">
                                <span v-if="order.queue_number" class="pos-order-queue-chip">N°{{ order.queue_number }}</span>
                                <span v-else class="text-[#6E7191]">—</span>
                            </td>
                            <td class="db-table-body-td">{{ order.customer_name || '—' }}</td>
                            <td class="db-table-body-td">{{ formatPrice(order.total) }}</td>
                            <td class="db-table-body-td">
                                <!-- [AUDIT-SUPERVISEUR 2026-08-25 · C-002] On passe la COMMANDE,
                                     pas seulement son statut de paiement : une commande annulée
                                     ne peut pas être « à encaisser », et le badge ne pouvait pas
                                     le savoir tant qu'il ignorait `order.status`. -->
                                <span class="hist-pay-badge" :class="paymentBadgeClass(order.payment_status, order)">
                                    {{ paymentLabel(order.payment_status, order) }}
                                </span>
                                <span v-if="order.parent_order_id" class="hist-refund-tag" :title="$t('label.refunded')">
                                    ↩ #{{ order.parent_order_id }}
                                </span>
                            </td>
                            <td class="db-table-body-td">
                                <span v-if="order.fiscal_sequence_no" class="hist-fiscal-chip">{{ order.fiscal_sequence_no }}</span>
                                <span v-else class="text-[#6E7191]">—</span>
                            </td>
                            <td class="db-table-body-td">{{ order.order_datetime }}</td>
                            <td class="db-table-body-td hist-statut-col">
                                <span :class="orderStatusClass(order.status)">
                                    {{ enums.orderStatusEnumArray[order.status] || order.status_name }}
                                </span>
                            </td>
                            <td class="db-table-body-td hist-action-col" v-if="permissionChecker('pos-orders')">
                                <div class="flex justify-start items-center gap-1.5">
                                    <SmIconViewComponent :link="'admin.pos-orders.show'" :id="order.id" />
                                    <!--
                                      [S2 V4 2026-07-29] Réimpression depuis l'HISTORIQUE.
                                      Défaut trouvé en audit navigation : une commande clôturée
                                      n'était réimprimable NULLE PART (le tracker ne montre que
                                      les commandes actives du jour) — le caissier n'avait aucun
                                      moyen de redonner le ticket de la veille. Même mécanique que
                                      PosOrdersTrackerComponent.requestReprint : on hydrate la
                                      commande puis on ouvre le ReceiptComponent existant, qui
                                      porte ses propres boutons d'impression. Le compteur fiscal
                                      de duplicata reste géré par l'endpoint `pos.print`.
                                    -->
                                    <button
                                        type="button"
                                        class="hist-reprint-btn"
                                        :disabled="reprintBusyId === order.id"
                                        :title="$t('pos.reprint_ticket_hint')"
                                        :aria-label="$t('pos.reprint_ticket_hint')"
                                        :data-testid="`historique-reprint-${order.id}`"
                                        @click="requestReprint(order)"
                                    >
                                        <i class="fa-solid fa-print" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tbody class="db-table-body" v-else>
                        <tr class="db-table-body-tr">
                            <td class="db-table-body-td text-center" colspan="10">
                                <div class="p-4">
                                    <div class="max-w-[300px] mx-auto mt-2">
                                        <img class="w-full h-full" :src="ENV.API_URL + '/images/default/not-found.png'" alt="Not Found">
                                    </div>
                                    <span class="d-block mt-3 text-lg">{{ $t('message.no_data_available') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6" v-if="orders.length > 0">
                <PaginationSMBox :pagination="pagination" :method="list" />
                <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                    <PaginationTextComponent :props="{ page: paginationPage }" />
                    <PaginationBox :pagination="pagination" :method="list" />
                </div>
            </div>
        </div>

        <!--
          [S2 V4 2026-07-29] ReceiptComponent monté à la demande pour la
          réimpression (miroir du tracker : même #receiptModal, mêmes boutons).
        -->
        <ReceiptComponent v-if="reprintOrder && reprintOrder.id" :order="reprintOrder" />
    </div>
</template>
<script>
import LoadingComponent from "../components/LoadingComponent";
import CaisseSecondaryNav from "../pos/CaisseSecondaryNav.vue";
import ReceiptComponent from "../pos/ReceiptComponent.vue";
import alertService from "../../../services/alertService";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import appService from "../../../services/appService";
import orderStatusEnum from "../../../enums/modules/orderStatusEnum";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import paymentStatusEnum from "../../../enums/modules/paymentStatusEnum";
import SourceEnum from "../../../enums/modules/sourceEnum";
import TableLimitComponent from "../components/TableLimitComponent";
import SmIconViewComponent from "../components/buttons/SmIconViewComponent";
import FilterComponent from "../components/buttons/collapse/FilterComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import { ref } from 'vue';
import { endOfMonth, endOfYear, startOfMonth, startOfYear, subMonths } from 'date-fns';
import statusEnum from "../../../enums/modules/statusEnum";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import ENV from "../../../config/env";
import { adminPriceMixin } from "../../../helpers/formatPrice";

export default {
    name: "HistoriqueListComponent",
    mixins: [adminPriceMixin],
    components: {
        CaisseSecondaryNav,
        ReceiptComponent,
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        LoadingComponent,
        SmIconViewComponent,
        FilterComponent,
        Datepicker
    },
    setup() {
        const date = ref();
        const // [REPLAN_8 2026-08-24] `slot` sur CHAQUE préréglage — sinon vue-datepicker rend
 // sa propre `<div class="dp__preset_range">`, ni focalisable ni activable au clavier.
 // Le `<template #yearly>` accessible ne s'appliquait qu'à l'unique entrée démo du
 // template vendeur : 4 préréglages sur 5 restaient des div muettes.
 presetRanges = ref([
            { label: 'Aujourd’hui', range: [new Date(), new Date()], slot: 'yearly' },
            { label: 'Ce mois', range: [startOfMonth(new Date()), endOfMonth(new Date())], slot: 'yearly' },
            { label: 'Mois dernier', range: [startOfMonth(subMonths(new Date(), 1)), endOfMonth(subMonths(new Date(), 1))], slot: 'yearly' },
            { label: 'Cette année', range: [startOfYear(new Date()), endOfYear(new Date())], slot: 'yearly' },
        ]);
        return { date, presetRanges };
    },
    data() {
        return {
            loading: { isActive: false },
            // [S2 V4 2026-07-29] Réimpression depuis l'historique (cf. requestReprint).
            reprintOrder: {},
            reprintBusyId: null,
            // [S2 V4 2026-07-29] Filtres rapides (1 clic) — cf. applyQuickFilter.
            activeQuickFilter: null,
            enums: {
                orderStatusEnum: orderStatusEnum,
                orderTypeEnum: orderTypeEnum,
                paymentStatusEnum: paymentStatusEnum,
                orderStatusEnumArray: {
                    [orderStatusEnum.ACCEPT]: this.$t("label.accept"),
                    [orderStatusEnum.PREPARING]: this.$t("label.preparing"),
                    [orderStatusEnum.PREPARED]: this.$t("label.prepared"),
                    [orderStatusEnum.DELIVERED]: this.$t("label.delivered"),
                    [orderStatusEnum.RETURNED]: this.$t("label.returned")
                },
            },
            props: {
                // Origin filter is a UI concept mapped onto backend filters
                // (source_surface for Borne/Caisse/En ligne, order_type for
                // Livraison) on search().
                origin: null,
                form: { date: null },
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    order_by: "desc",
                    order_serial_no: "",
                    user_id: null,
                    status: null,
                    payment_status: null,
                    source_surface: null,
                    order_type: null,
                    from_date: "",
                    to_date: "",
                }
            },
            ENV: ENV
        }
    },
    mounted() {
        this.list();
    },
    computed: {
        // [S2 V4 2026-07-29] Chips de filtrage rapide (libellés i18n FR).
        quickFilters() {
            return [
                { id: 'today', label: this.$t('label.today') },
                { id: 'yesterday', label: this.$t('label.yesterday') },
                { id: 'canceled', label: this.$t('label.canceled') },
                { id: 'refunded', label: this.$t('label.refunded') },
            ];
        },
        orders: function () {
            return this.$store.getters['orderHistory/lists'];
        },
        pagination: function () {
            return this.$store.getters['orderHistory/pagination'];
        },
        paginationPage: function () {
            return this.$store.getters['orderHistory/page'];
        },
        direction: function () {
            return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
        },
        originOptions: function () {
            return [
                { id: 'kiosk', name: this.$t('label.kiosk') },
                { id: 'pos', name: this.$t('label.caisse') },
                { id: 'online', name: this.$t('label.online') },
                { id: 'delivery', name: this.$t('label.delivery') },
            ];
        },
    },
    methods: {
        permissionChecker(e) {
            return appService.permissionChecker(e);
        },
        /**
         * [S2 V4 2026-07-29] Réimpression d'un ticket depuis l'historique.
         * Miroir strict de PosOrdersTrackerComponent.requestReprint : la liste
         * ne porte qu'un payload allégé, on hydrate donc la commande complète
         * avant de monter ReceiptComponent (qui porte ses propres boutons
         * client/cuisine). Le duplicata fiscal est journalisé par l'endpoint
         * `pos.print`, pas ici — on n'est qu'un raccourci d'interface.
         */
        async requestReprint(order) {
            if (!order || !order.id) return;
            if (this.reprintBusyId === order.id) return;
            this.reprintBusyId = order.id;
            try {
                const res = await this.$store.dispatch('posOrder/show', order.id);
                const fullOrder = res?.data?.data;
                if (!fullOrder || !fullOrder.id) {
                    throw new Error('empty');
                }
                this.reprintOrder = fullOrder;
                this.$nextTick(() => {
                    appService.modalShow('#receiptModal');
                });
            } catch (e) {
                alertService.error(this.$t('pos.reprint_error'));
            } finally {
                this.reprintBusyId = null;
            }
        },
        orderStatusClass: function (status) {
            return appService.orderStatusClass(status);
        },
        handleSlide: function (id) {
            return appService.handleSlide(id);
        },
        // Origin resolver — source_surface is the reliable signal; delivery
        // type overrides (a delivery can originate from any surface).
        originBadge: function (order) {
            const surface = String(order.source_surface || '').toLowerCase();
            const type = parseInt(order.order_type);
            if (type === orderTypeEnum.DELIVERY) {
                return { label: this.$t('label.delivery'), cls: 'origin-delivery' };
            }
            if (surface === 'kiosk' || type === orderTypeEnum.KIOSK) {
                return { label: this.$t('label.kiosk'), cls: 'origin-borne' };
            }
            if (surface === 'pos' || type === orderTypeEnum.POS) {
                return { label: this.$t('label.caisse'), cls: 'origin-caisse' };
            }
            if (surface === 'web' || surface === 'app' || surface === 'mobile') {
                return { label: this.$t('label.online'), cls: 'origin-online' };
            }
            // [abuse-e2e P3 heal 2026-05-30] Fallback on the legacy `source` column
            // when source_surface is empty/NULL (older orders predate the surface
            // tag) so they are not all blanket-badged "En ligne": Source POS=Caisse,
            // WEB/APP=En ligne. Unknown/dirty source values keep the En ligne default.
            const src = parseInt(order.source);
            if (src === SourceEnum.POS) {
                return { label: this.$t('label.caisse'), cls: 'origin-caisse' };
            }
            if (src === SourceEnum.WEB || src === SourceEnum.APP) {
                return { label: this.$t('label.online'), cls: 'origin-online' };
            }
            return { label: this.$t('label.online'), cls: 'origin-online' };
        },
        /**
         * [AUDIT-SUPERVISEUR 2026-08-25 · C-002] LE BADGE DE PAIEMENT DOIT REGARDER LA COMMANDE.
         *
         * Mesure : SEPT lignes de l'historique portaient SIMULTANEMENT « À encaisser » et le
         * statut « Annulée ». Une commande annulée ne peut pas etre a encaisser — et l'ecran
         * d'encaissement capture huit secondes plus tot ne contenait AUCUNE de ces sept
         * commandes. C'est donc bien le libelle qui mentait, pas la file.
         *
         * Cause : cette methode ne recevait que `payment_status` et ne consultait jamais
         * `status`. Deux verites sur la meme ligne, calculees separement, qui se contredisent.
         *
         * Aggravant, et c'est ce qui rendait le defaut si couteux : la colonne qui
         * desambiguise — « Annulée » — est precisement celle que C-001 rendait illisible. A
         * l'ecran, le caissier ne lisait QUE « À encaisser », sept fois.
         *
         * Le second parametre est optionnel : les appels qui ne passent que le statut de
         * paiement gardent exactement leur comportement d'avant.
         */
        paymentLabel: function (ps, order) {
            if (order && this.commandeSansObjetDePaiement(order)) {
                return this.$t('label.payment_moot');
            }

            switch (parseInt(ps)) {
                case paymentStatusEnum.PAID: return this.$t('label.paid');
                case paymentStatusEnum.UNPAID: return this.$t('label.unpaid');
                case paymentStatusEnum.PENDING_COUNTER: return this.$t('label.pending_counter');
                case paymentStatusEnum.REFUNDED: return this.$t('label.refunded');
                default: return '—';
            }
        },
        /**
         * Vrai quand la commande ne peut plus donner lieu a un encaissement : annulee ou
         * rejetee. On ne se fie pas a une valeur en dur — l'enumeration est la source.
         */
        commandeSansObjetDePaiement: function (order) {
            const s = parseInt(order && order.status);
            if (!Number.isFinite(s)) {
                return false;
            }

            return s === orderStatusEnum.CANCELED || s === orderStatusEnum.REJECTED;
        },
        paymentBadgeClass: function (ps, order) {
            if (order && this.commandeSansObjetDePaiement(order)) {
                // Gris neutre : ni une dette a recouvrer, ni un encaissement reussi.
                return 'pay-moot';
            }

            switch (parseInt(ps)) {
                case paymentStatusEnum.PAID: return 'pay-paid';
                case paymentStatusEnum.PENDING_COUNTER: return 'pay-pending';
                case paymentStatusEnum.REFUNDED: return 'pay-refunded';
                default: return 'pay-unpaid';
            }
        },
        // Translate the origin filter selection into backend filters.
        applyOriginFilter: function () {
            this.props.search.source_surface = null;
            this.props.search.order_type = null;
            switch (this.props.origin) {
                case 'kiosk': this.props.search.source_surface = 'kiosk'; break;
                case 'pos': this.props.search.source_surface = 'pos'; break;
                case 'online': this.props.search.source_surface = 'web'; break;
                case 'delivery': this.props.search.order_type = orderTypeEnum.DELIVERY; break;
            }
        },
        search: function () {
            // Le formulaire complet reprend la main : les chips ne reflètent plus l'état.
            this.activeQuickFilter = null;
            this.applyOriginFilter();
            this.list();
        },
        /**
         * [S2 V4 2026-07-29] Filtre rapide en 1 clic. Re-cliquer la chip active
         * la désactive (retour à la liste complète). On écrit dans les mêmes
         * `props.search` que le formulaire — pas de second chemin de filtrage.
         */
        applyQuickFilter: function (id) {
            const toggleOff = this.activeQuickFilter === id;
            this.props.origin = null;
            // [S2 auto-RED cycle 2] Remettre AUSSI à zéro la recherche libre :
            // un n° de commande ou un client saisi restait appliqué en silence
            // sous une chip qui suggère un filtre propre.
            this.props.search.order_serial_no = "";
            this.props.search.user_id = null;
            this.props.search.status = null;
            this.props.search.payment_status = null;
            this.props.search.source_surface = null;
            this.props.search.order_type = null;
            this.props.search.from_date = "";
            this.props.search.to_date = "";
            this.props.form.date = null;
            this.props.search.page = 1;

            if (toggleOff) {
                this.activeQuickFilter = null;
                this.list();
                return;
            }

            const today = new Date();
            const isoDay = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000)
                .toISOString().slice(0, 10);

            if (id === 'today') {
                this.props.search.from_date = isoDay(today);
                this.props.search.to_date = isoDay(today);
                this.props.form.date = [today, today];
            } else if (id === 'yesterday') {
                // [S2 auto-RED 2026-07-29] setDate() et non « −86 400 000 ms » : au
                // passage à l'heure d'été la veille dure 23 h, et la soustraction
                // fixe renvoyait l'avant-veille.
                const y = new Date(today);
                y.setDate(y.getDate() - 1);
                this.props.search.from_date = isoDay(y);
                this.props.search.to_date = isoDay(y);
                this.props.form.date = [y, y];
            } else if (id === 'canceled') {
                this.props.search.status = orderStatusEnum.CANCELED;
            } else if (id === 'refunded') {
                this.props.search.payment_status = paymentStatusEnum.REFUNDED;
            }

            this.activeQuickFilter = id;
            this.list();
        },
        handleDate: function (e) {
            if (e) {
                this.props.search.from_date = e[0];
                this.props.search.to_date = e[1];
            } else {
                this.props.form.date = null;
                this.props.search.from_date = null;
                this.props.search.to_date = null;
            }
        },
        clear: function () {
            this.props.origin = null;
            this.props.search.paginate = 1;
            this.props.search.page = 1;
            this.props.search.order_by = "desc";
            this.props.search.order_serial_no = "";
            this.props.search.status = null;
            this.props.search.payment_status = null;
            this.props.search.source_surface = null;
            this.props.search.order_type = null;
            this.props.search.from_date = "";
            this.props.search.to_date = "";
            this.props.form.date = null;
            this.list();
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch('orderHistory/lists', this.props.search).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
    }
}
</script>

<style scoped>
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
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5);
}
:deep(.db-card-title) {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h5);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
    letter-spacing: var(--pos-v5-tracking-tight);
}
:deep(.db-table-head) { background: var(--pos-v5-bg-subtle); }
:deep(.db-table-head-th) {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-eyebrow);
    font-weight: var(--pos-v5-weight-bold);
    letter-spacing: var(--pos-v5-tracking-caps);
    text-transform: uppercase;
    color: var(--pos-v5-ink-soft);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
}
:deep(.db-table-body-tr) { transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard); }
:deep(.db-table-body-tr:hover) { background: var(--pos-v5-brand-red-faint); }
:deep(.db-table-body-td) {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-body);
    color: var(--pos-v5-ink);
    padding: var(--pos-v5-space-3) var(--pos-v5-space-4);
    font-feature-settings: "tnum";
    font-variant-numeric: tabular-nums;
}
.pos-order-queue-chip {
    display: inline-flex;
    min-height: 2rem;
    align-items: center;
    border: 1px solid #fed7aa;
    border-radius: 9999px;
    background: #fff7ed;
    color: #9a3412;
    font-weight: var(--pos-v5-weight-extrabold);
    padding: 0.2rem 0.65rem;
    white-space: nowrap;
}

/* Origin badge — V1 Le Cayenne origin colors */
.hist-origin-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 9999px;
    padding: 0.15rem 0.6rem;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid transparent;
}
.origin-borne { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
.origin-caisse { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.origin-online { background: #f5f3ff; color: #5b21b6; border-color: #ddd6fe; }
.origin-delivery { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }

/* Payment badge */
.hist-pay-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 9999px;
    padding: 0.12rem 0.55rem;
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}
.pay-paid { background: #ecfdf5; color: #065f46; }
.pay-pending { background: #fffbeb; color: #92400e; }
.pay-unpaid { background: #fef2f2; color: #991b1b; }
.pay-refunded { background: #f1f5f9; color: #475569; }
/* [AUDIT-SUPERVISEUR 2026-08-25 · C-002] Commande annulée ou rejetée : le paiement est
   sans objet. Gris barré d'un ton plus froid que « Remboursé » — ni une dette à
   recouvrer (rouge), ni un encaissement réussi (vert), ni un remboursement effectué. */
.pay-moot { background: #f3f4f6; color: #6b7280; }
.hist-refund-tag { margin-left: 0.35rem; font-size: 0.72rem; color: #475569; font-weight: 600; }
.hist-fiscal-chip {
    display: inline-flex;
    align-items: center;
    border-radius: 0.4rem;
    padding: 0.1rem 0.45rem;
    background: var(--pos-v5-bg-subtle);
    color: var(--pos-v5-ink);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

/* [S2 V4 2026-07-29] Chips de filtrage rapide — toujours visibles, 1 clic. */
.hist-quick-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0 1rem 0.85rem;
}
.hist-chip {
    border-radius: 999px;
    padding: 0.3rem 0.85rem;
    font-size: 0.8rem;
    font-weight: 600;
    background: var(--pos-v5-bg-subtle, #f4f4f6);
    color: var(--pos-v5-ink, #14142b);
    border: 1px solid transparent;
    transition: all 0.15s ease;
}
/* [S2 auto-RED cycle 2] `:hover` (0-2-0) battait `--on` (0-1-0) : la chip ACTIVE
   repassait en fond pêche tout en gardant `color:#fff` → texte blanc sur #FFE8DD,
   contraste 1,18:1. Sur l'écran TACTILE de la caisse le `:hover` reste collé après
   le tap, donc le seul repère « quel filtre est actif » devenait illisible. */
.hist-chip:hover:not(.hist-chip--on) { background: #ffe8dd; }
.hist-chip--on,
.hist-chip--on:hover {
    background: var(--pos-v5-brand-red, #f4501e);
    color: #fff;
}

/* [S2 auto-RED cycle 2 2026-07-29] Colonne ACTION collée à droite.
   Le tableau (10 colonnes) déborde de ~190 px à 1280×800 — la résolution réelle
   de l'écran de caisse — donc le bouton « réimprimer » livré par cette vague
   naissait à x=1407 : invisible sans scroll horizontal, fonctionnalité non
   découvrable. Sticky la garde toujours atteignable, sans masquer de donnée.

   [auto-RED cycle 3] `background` codé en dur = mauvaise idée : la cellule
   ne suivait plus ni la zébrure ni le liseré de survol (rectangle blanc et
   couture au milieu du tableau). `inherit` recopie la couleur RÉELLE de la
   ligne, quelle qu'elle soit — zébrure, survol, futur surlignage. Et l'ombre
   ne se justifie que lorsqu'il y a réellement quelque chose à masquer,
   c'est-à-dire quand le tableau déborde (≤1439px, même seuil que pos-v5.css).

   [C-002 2026-08-25 · supervisor-caisse round-1 wave C] `inherit` ÉTAIT LA
   RACINE DU DÉFAUT, pas la solution. Il recopie le fond du `<tr>` parent — or
   la zébrure du design system (`resources/css/app.css:464`) ne peint QUE les
   rangs IMPAIRS. Conséquences mesurées au pixel sur la colonne DATE (x=1170) :
     • rang IMPAIR → #f9fafb opaque : la date est REPEINTE et disparaît
       (mesures (249,250,251) à y=385/499/613) ;
     • rang PAIR   → le `<tr>` n'a AUCUN fond à hériter, la cellule est donc
       TRANSPARENTE : la date s'imprime À TRAVERS les boutons
       (mesures (26,26,26) à y=442/556/670, « 02:18, 08 » barré par l'icône) ;
     • `<thead>`   → aucun fond non plus : DATE et ACTION s'impriment l'un sur
       l'autre et le mot rendu est « DACTIEON ».
   Une cellule collante qui recouvre du contenu doit être OPAQUE dans TOUS ses
   états. On déclare donc explicitement les quatre — en-tête, rang pair, rang
   impair, survol — chacun avec la couleur EXACTE de sa ligne, ce qui donne à
   la fois l'opacité (rien ne transparaît) et l'absence de couture (aucun
   rectangle d'une autre couleur au milieu du tableau). Verrouillé par
   `tests/js/historiqueActionColumnOpaque.spec.js`, qui évalue la cascade
   réelle via `getComputedStyle`. */
/* [AUDIT-SUPERVISEUR 2026-08-25 · C-002] LA VRAIE CAUSE : la table débordait.
   Dix colonnes à `px-4` (16 px de chaque côté) = 320 px de marges internes. Mesuré :
   181 px de débordement à 1280, 95 px à 1366. Une colonne `sticky right` sur une table
   qui déborde se pose FORCÉMENT sur ce qui est à sa gauche : c'est son rôle. Rendre la
   cellule opaque, comme au round précédent, supprime la bavure mais pas le recouvrement.
   On resserre donc les marges SUR CETTE TABLE UNIQUEMENT — jamais sur `.db-table-head-th`
   global, qui habille toutes les tables du produit. */
.hist-table .db-table-head-th,
.hist-table .db-table-body-td {
    /* 8 px laissait encore 21 px de débordement à 1280 — mesuré, pas estimé.
       6 px les absorbe (4 px gagnés par cellule × 10 colonnes = 40 px) et la
       table tient sur les deux gabarits du parc. */
    padding-left: 6px;
    padding-right: 6px;
}

/*
 * [AUDIT-SUPERVISEUR 2026-08-25 · C-001] UNE COLONNE COLLANTE NE DOIT JAMAIS POUVOIR
 * RECOUVRIR DU CONTENU.
 *
 * Ce que j'avais fait au round precedent : resserrer les marges de CETTE table a 6 px, en
 * ecrivant que « la table tient sur les deux gabarits du parc ». C'etait vrai des donnees
 * que j'avais sous les yeux, et faux en general. Le superviseur l'a mesure au pixel sur une
 * capture ou l'en-tete se lit « S· ACTION » — « Statut » coupe apres le S — et ou chaque
 * badge de statut est reduit a un croissant de 2 a 3 px avant d'etre recouvert.
 *
 * Le declencheur est la LONGUEUR DES NUMEROS DE COMMANDE : 17 caracteres
 * (« AUDC-RICHE-7GFAQZ ») decalent les colonnes d'environ 55 px vers la droite. J'ai
 * remesure sur les donnees d'aujourd'hui — aucun numero de plus de 12 caracteres, donc
 * 0 recouvrement et 0 debordement. Le defaut ne se reproduit pas ; il n'a pas disparu pour
 * autant, il attend juste le bon jeu de donnees. Un correctif qui depend de la longueur des
 * chaines en base n'est pas un correctif.
 *
 * On epingle donc la colonne STATUT elle aussi, calee juste a gauche d'ACTION. Les deux
 * restent visibles quoi qu'il arrive : ni l'une ni l'autre ne peut passer sous sa voisine,
 * et le statut d'une commande — l'information qui dit si elle est annulee — cesse de
 * dependre du nombre de caracteres de son numero.
 */
.hist-table {
    /* Largeurs MESURÉES à 1280 px : ACTION 86 px, STATUT 68 px. Des variables, pas des
       nombres recopiés, pour que le calage et la réserve ne puissent pas se désynchroniser. */
    --hist-action-w: 86px;
    --hist-statut-w: 68px;

    /*
     * LA TABLE DOIT POUVOIR PRENDRE SA LARGEUR NATURELLE.
     *
     * Sans ceci, la réserve posée plus bas se retourne contre elle-même : la table est
     * contrainte à la largeur de son conteneur, donc une marge droite ne l'élargit pas —
     * elle VOLE de la place au texte de la cellule. Mesuré à l'écran : la date est passée
     * de « 18:34, 25-08-2026 » complète à « 25-0 » coupée. J'avais aggravé le défaut en
     * croyant le réparer.
     *
     * `max-content` laisse chaque colonne prendre ce qu'il lui faut ; le conteneur
     * `.db-table-responsive` défile alors horizontalement, et les deux colonnes épinglées
     * restent visibles pendant ce défilement — ce pour quoi elles sont épinglées.
     */
    min-width: max-content;
}

/*
 * LA RÉSERVE, ET POURQUOI ÉPINGLER NE SUFFISAIT PAS.
 *
 * Premier réflexe : épingler STATUT à côté d'ACTION pour qu'elle cesse d'être recouverte.
 * Fait — et vérifié à l'écran : le statut est redevenu lisible sur chaque ligne. Mais la
 * capture suivante montrait la DATE coupée à « 25-0 ». Je n'avais pas réparé le défaut, je
 * l'avais DÉPLACÉ d'une colonne vers la gauche. C'est la nature même d'un élément collant :
 * il se pose sur ce qui passe dessous, et il y aura toujours une colonne « dessous ».
 *
 * La seule réponse qui tienne est de RÉSERVER la place du groupe collant à la fin de la
 * ligne. La dernière colonne non collante porte une marge droite égale à la largeur des
 * deux colonnes épinglées : elles se posent alors sur du vide, jamais sur du texte.
 *
 * Le prix assumé : la table devient plus large et peut demander un défilement horizontal
 * là où elle n'en demandait pas. C'est le bon échange — du contenu caché sans le dire est
 * pire qu'une barre de défilement, surtout sur un écran dont l'objet est de montrer ce
 * qu'un client a commandé.
 */
.hist-table .db-table-head-th:nth-last-child(3),
.hist-table .db-table-body-td:nth-last-child(3) {
    padding-right: calc(var(--hist-action-w) + var(--hist-statut-w) + 6px);
}

.hist-statut-col {
    position: sticky;
    right: var(--hist-action-w);
    z-index: 1; /* sous ACTION, au-dessus du reste */
    background-color: rgb(255, 255, 255);
}

.hist-action-col {
    position: sticky;
    right: 0;
    z-index: 2;
    /* Rangs PAIRS + repli : fond du tableau. Jamais `inherit`, jamais absent. */
    background-color: rgb(255, 255, 255);
}
/* En-tête. [AUDIT-SUPERVISEUR 2026-08-25 · C-018] Le blanc posé au round précédent
   créait une COUTURE : le superviseur a relevé au pixel (1120,340) = (247,243,236)
   pour le thead contre (1155,340) = (255,255,255) pour la cellule collante. Opaque,
   oui — mais de la mauvaise couleur, donc un rectangle blanc au bout d'un bandeau
   beige. Le commentaire d'alors affirmait que `.db-table-head` ne pose aucun fond ;
   la ligne 568 du même fichier le contredisait — `:deep(.db-table-head)` lui pose bien
   `var(--pos-v5-bg-subtle)`. On reprend LA MÊME VARIABLE plutôt qu'un RGB figé : une
   couleur recopiée à la main se désynchronise au premier changement de thème, et on
   aurait recréé la couture ailleurs. */
.db-table-head .hist-action-col,
.db-table-head .hist-statut-col { background: var(--pos-v5-bg-subtle, #f4f4f6); }
/* Rangs IMPAIRS : recopie EXACTE de la zébrure `app.css:464` (#f9fafb). */
.db-table.stripe .db-table-body-tr:nth-child(odd) .hist-action-col,
.db-table.stripe .db-table-body-tr:nth-child(odd) .hist-statut-col {
    background-color: rgb(249, 250, 251);
}
/* Survol : recopie de `:deep(.db-table-body-tr:hover)` ci-dessus
   (--pos-v5-brand-red-faint = #FFF4EE) sur les deux parités. */
.db-table-body-tr:hover .hist-action-col,
.db-table-body-tr:hover .hist-statut-col,
.db-table.stripe .db-table-body-tr:nth-child(odd):hover .hist-action-col,
.db-table.stripe .db-table-body-tr:nth-child(odd):hover .hist-statut-col {
    background-color: var(--pos-v5-brand-red-faint, #FFF4EE);
}
@media (max-width: 1439px) {
    /* L'ombre marque le bord du GROUPE collant, donc sur STATUT (le plus a gauche des
       deux) et non sur ACTION : sinon elle tomberait ENTRE les deux colonnes epinglees. */
    .hist-statut-col { box-shadow: -6px 0 8px -6px rgba(0, 0, 0, 0.18); }
}

/* [S2 V4 2026-07-29] Bouton réimpression — même gabarit que l'icône « voir »
   du design system admin (SmIconViewComponent) pour rester aligné dans la
   colonne ACTION. */
.hist-reprint-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 0.4rem;
    background: var(--pos-v5-bg-subtle, #f4f4f6);
    color: var(--pos-v5-ink, #14142b);
    transition: background 0.15s ease;
}
.hist-reprint-btn:hover:not(:disabled) { background: #ffe8dd; color: var(--pos-v5-brand-red, #f4501e); }
.hist-reprint-btn:disabled { opacity: 0.5; cursor: not-allowed; }

:deep(.db-btn.bg-primary) {
    background: var(--pos-v5-brand-red) !important;
    border-radius: var(--pos-v5-radius-md);
    font-weight: var(--pos-v5-weight-bold);
}
:deep(.db-btn.bg-primary:hover) { background: var(--pos-v5-brand-red-dark) !important; }
:deep(.db-btn.bg-gray-600) {
    background: var(--pos-v5-bg-subtle) !important;
    color: var(--pos-v5-ink) !important;
    border-radius: var(--pos-v5-radius-md);
    font-weight: var(--pos-v5-weight-semibold);
}
:deep(.db-btn.bg-gray-600:hover) { background: var(--pos-v5-border-strong) !important; }
</style>
