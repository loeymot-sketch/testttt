<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <!-- [v1-0-1-h5 Z5-P1-03 2026-05-17] hardcoded FR strings (eyebrow, subtitle, sync chip, 4 metric labels, 4 action labels, 2 aria-labels) replaced with $t() — 5-language JSON parity added -->
        <section class="catalog-control-plane">
            <div class="catalog-control-plane__summary">
                <span class="catalog-control-plane__eyebrow">{{ $t('label.catalog_pilot') }}</span>
                <div class="catalog-control-plane__title-row">
                    <h2>{{ $t('label.catalog_subtitle') }}</h2>
                    <span class="catalog-control-plane__sync">
                        <i class="lab lab-refresh"></i>
                        {{ $t('label.catalog_pos_kiosk') }}
                    </span>
                </div>
            </div>

            <!-- [iter15-mega-fix A-010 round-7 2026-05-10] :title attribute exposes full label even when CSS clips, and aria-label keeps SR action context -->
            <div class="catalog-control-plane__metrics" :aria-label="$t('label.catalog_summary_aria')">
                <button type="button" class="catalog-control-plane__metric" :title="`${itemsCount} ${$t('label.catalog_metric_products')}`" :aria-label="`${$t('label.filter')} ${itemsCount} ${$t('label.catalog_metric_products')}`" @click.prevent="clear">
                    <span>{{ itemsCount }}</span>
                    <small>{{ $t('label.catalog_metric_products') }}</small>
                </button>
                <router-link class="catalog-control-plane__metric" :title="`${categoriesCount} ${$t('label.catalog_metric_categories')}`" :aria-label="`${$t('label.view')} ${categoriesCount} ${$t('label.catalog_metric_categories')}`" :to="{ name: 'admin.settings.itemCategory.list' }">
                    <span>{{ categoriesCount }}</span>
                    <small>{{ $t('label.catalog_metric_categories') }}</small>
                </router-link>
                <button type="button" class="catalog-control-plane__metric" :title="`${activeItemsCount} ${$t('label.catalog_metric_active')}`" :aria-label="`${$t('label.filter')} ${activeItemsCount} ${$t('label.catalog_metric_active')}`" @click.prevent="filterActiveItems">
                    <span>{{ activeItemsCount }}</span>
                    <small>{{ $t('label.catalog_metric_active') }}</small>
                </button>
                <button type="button" class="catalog-control-plane__metric catalog-control-plane__metric--alert" :title="`${unavailableItemsCount} ${$t('label.catalog_metric_unavailable')}`" :aria-label="`${$t('label.filter')} ${unavailableItemsCount} ${$t('label.catalog_metric_unavailable')}`" @click.prevent="focusAvailability">
                    <span>{{ unavailableItemsCount }}</span>
                    <small>{{ $t('label.catalog_metric_unavailable') }}</small>
                </button>
            </div>

            <div class="catalog-control-plane__actions" :aria-label="$t('label.catalog_actions_aria')">
                <router-link class="catalog-control-plane__action" :to="{ name: 'admin.items.list' }">
                    <i class="lab lab-list"></i>
                    {{ $t('label.catalog_action_products') }}
                </router-link>
                <router-link class="catalog-control-plane__action" :to="{ name: 'admin.settings.itemCategory.list' }">
                    <i class="lab lab-category"></i>
                    {{ $t('label.catalog_action_categories') }}
                </router-link>
                <router-link class="catalog-control-plane__action" :to="{ name: 'admin.offers.list' }">
                    <i class="lab lab-discount"></i>
                    {{ $t('label.catalog_action_offers') }}
                </router-link>
                <button type="button" class="catalog-control-plane__action" @click.prevent="focusAvailability">
                    <i class="lab lab-toggle-on"></i>
                    {{ $t('label.catalog_action_availability') }}
                </button>
            </div>
        </section>

        <div class="db-card" data-testid="admin-items-list">
            <div class="db-card-header border-none">
                <h3 class="db-card-title">{{ $t('menu.items') }}</h3>
                <div class="db-card-filter">
                    <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                    <FilterComponent @click.prevent="handleSlide('item-filter')" />
                    <div class="dropdown-group">
                        <ExportComponent />
                        <div
                            class="dropdown-list db-card-filter-dropdown-list transition-all duration-300 scale-y-0 origin-top">
                            <PrintComponent :props="printObj" />
                            <ExcelComponent :method="xls" />
                        </div>
                    </div>
                    <div v-if="permissionChecker('items_create')" class="dropdown-group">
                        <ImportComponent />
                        <div
                            class="dropdown-list db-card-filter-dropdown-list transition-all duration-300 scale-y-0 origin-top">
                            <SampleFileComponent @click="downloadSample" />
                            <UploadFileComponent :dataModal="'itemUpload'" @click="uploadModal('#itemUpload')" />
                        </div>
                    </div>
                    <ItemUploadComponent v-on:list="list" />
                    <div data-testid="admin-item-create-open">
                        <ItemCreateComponent :props="props" v-if="permissionChecker('items_create')" />
                    </div>
                </div>
            </div>

            <div class="table-filter-div" id="item-filter">
                <form class="p-4 sm:p-5 mb-5" @submit.prevent="search">
                    <div class="row">
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="name" class="db-field-title after:hidden">{{
                                $t("label.name")
                            }}</label>
                            <input id="name" v-model="props.search.name" type="text" class="db-field-control" />
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="price" class="db-field-title after:hidden">{{
                                $t("label.price")
                            }}</label>
                            <input id="price" v-on:keypress="numberOnly($event)" v-model="props.search.price"
                                type="text" class="db-field-control" />
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="item_category_id" class="db-field-title">{{
                                $t("label.category")
                            }}</label>

                            <vue-select class="db-field-control f-b-custom-select" id="item_category_id"
                                v-model="props.search.item_category_id" :options="itemCategories" label-by="name"
                                value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                                placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="tax_id" class="db-field-title">{{
                                $t("label.tax")
                            }}</label>

                            <vue-select class="db-field-control f-b-custom-select" id="tax_id"
                                v-model="props.search.tax_id" :options="taxes" label-by="name" value-by="id"
                                :closeOnSelect="true" :searchable="true" :clearOnClose="true" placeholder="--"
                                search-placeholder="--" />
                        </div>
                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchItemType" class="db-field-title after:hidden">{{
                                $t("label.item_type")
                            }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchItemType"
                                v-model="props.search.item_type" :options="[
                                    { id: enums.itemTypeEnum.VEG, name: $t('label.veg') },
                                    { id: enums.itemTypeEnum.NON_VEG, name: $t('label.non_veg') },
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchIsFeatured" class="db-field-title after:hidden">{{
                                $t("label.is_featured")
                            }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchIsFeatured"
                                v-model="props.search.is_featured" :options="[
                                    { id: enums.askEnum.YES, name: $t('label.yes') },
                                    { id: enums.askEnum.NO, name: $t('label.no') },
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>


                        <div class="col-12 sm:col-6 md:col-4 xl:col-3">
                            <label for="searchStatus" class="db-field-title after:hidden">{{
                                $t("label.status")
                            }}</label>
                            <vue-select class="db-field-control f-b-custom-select" id="searchStatus"
                                v-model="props.search.status" :options="[
                                    { id: enums.statusEnum.ACTIVE, name: $t('label.active') },
                                    { id: enums.statusEnum.INACTIVE, name: $t('label.inactive') },
                                ]" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                                :clearOnClose="true" placeholder="--" search-placeholder="--" />
                        </div>

                        <div class="col-12">
                            <div class="flex flex-wrap gap-3 mt-4">
                                <button class="db-btn py-2 text-white bg-primary">
                                    <i class="lab lab-search-line lab-font-size-16"></i>
                                    <span>{{ $t("button.search") }}</span>
                                </button>
                                <button class="db-btn py-2 text-white bg-gray-600" @click="clear">
                                    <i class="lab lab-cross-line-2 lab-font-size-22"></i>
                                    <span>{{ $t("button.clear") }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="db-table-responsive" ref="availabilityTable">
                <table class="db-table stripe" id="print" :dir="direction">
                    <thead class="db-table-head">
                        <tr class="db-table-head-tr">
                            <th class="db-table-head-th">
                                {{ $t('label.image') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.name') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.category') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.price') }}
                            </th>
                            <th class="db-table-head-th">
                                {{ $t('label.status') }}
                            </th>
                            <th class="db-table-head-th" v-if="permissionChecker('items_edit')">
                                {{ $t('label.availability') }}
                            </th>
                            <th class="db-table-head-th hidden-print"
                                v-if="permissionChecker('items_show') || permissionChecker('items_edit') || permissionChecker('items_create') || permissionChecker('items_delete')">
                                {{ $t('label.action') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="db-table-body" v-if="items.length > 0">
                        <tr class="db-table-body-tr" v-for="item in items" :key="item" :data-testid="`admin-item-row-${item.id}`">
                            <td class="db-table-body-td">
                                <div class="w-[54px] h-[42px] rounded-md overflow-hidden border border-slate-200 bg-slate-50">
                                    <img
                                        class="w-full h-full object-cover"
                                        :src="item.thumb"
                                        :alt="item.name"
                                    >
                                </div>
                            </td>
                            <td class="db-table-body-td">
                                {{ textShortener(item.name, 40) }}
                            </td>
                            <td class="db-table-body-td">{{ item.category_name }}</td>
                            <td class="db-table-body-td">{{ item.flat_price }}</td>
                            <td class="db-table-body-td">
                                <span :class="statusClass(item.status)">
                                    {{ enums.statusEnumArray[item.status] }}
                                </span>
                            </td>
                            <td class="db-table-body-td" v-if="permissionChecker('items_edit')">
                                <div :data-testid="`admin-availability-toggle-${item.id}`">
                                    <AvailabilityToggleComponent :item-id="item.id" :branch-id="null" :is-available="item.is_available ?? true" :unavailable-reason="item.availability_reason || item.unavailable_reason || null" @availability-changed="list" />
                                </div>
                                <span class="sr-only" :data-testid="`admin-availability-status-${item.id}`">{{ item.is_available ?? true }}</span>
                            </td>
                            <td class="db-table-body-td hidden-print"
                                v-if="permissionChecker('items_show') || permissionChecker('items_edit') || permissionChecker('items_create') || permissionChecker('items_delete')">
                                <div class="flex justify-start items-center sm:items-start sm:justify-start gap-1.5">
                                    <span :data-testid="`admin-item-view-${item.id}`">
                                        <SmIconViewComponent :link="'admin.item.show'" :id="item.id"
                                            v-if="permissionChecker('items_show')" />
                                    </span>
                                    <span :data-testid="`admin-item-edit-${item.id}`">
                                        <SmIconSidebarModalEditComponent @click="edit(item)"
                                            v-if="permissionChecker('items_edit')" />
                                    </span>
                                    <span :data-testid="`admin-item-configure-wizard-${item.id}`">
                                        <router-link
                                            v-if="permissionChecker('items_edit') && wizardPerItemDemoEnabled"
                                            :to="{ name: 'admin.items.composer', params: { id: item.id } }"
                                            class="db-table-action view"
                                            :title="$t('label.configure_wizard')"
                                            :aria-label="$t('label.configure_wizard')"
                                            data-testid="item-list-configure-wizard-button"
                                        >
                                            <i class="lab lab-cog"></i>
                                            <span class="db-tooltip">{{ $t('label.configure_wizard') }}</span>
                                        </router-link>
                                    </span>
                                    <span :data-testid="`admin-item-duplicate-${item.id}`">
                                        <button
                                            type="button"
                                            class="db-table-action view"
                                            :title="$t('label.duplicate')"
                                            :aria-label="$t('label.duplicate')"
                                            @click.prevent="duplicate(item)"
                                            v-if="permissionChecker('items_create')"
                                        >
                                            <i class="lab lab-copy"></i>
                                            <span class="db-tooltip">{{ $t('label.duplicate') }}</span>
                                        </button>
                                    </span>
                                    <span :data-testid="`admin-item-delete-${item.id}`">
                                        <SmIconDeleteComponent @click="destroy(item.id)"
                                            v-if="permissionChecker('items_delete')" />
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    <tbody class="db-table-body" v-else>
                        <tr class="db-table-body-tr">
                            <td class="db-table-body-td text-center" colspan="8">
                                <div class="p-4">
                                    <div class="max-w-[300px] mx-auto mt-2">
                                        <img class="w-full h-full" :src="ENV.API_URL + '/images/default/not-found.png'"
                                            alt="Not Found">
                                    </div>
                                    <span class="d-block mt-3 text-lg">{{ $t('message.no_data_available') }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6"
                v-if="items.length > 0">
                <PaginationSMBox :pagination="pagination" :method="list" />
                <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                    <PaginationTextComponent :props="{ page: paginationPage }" />
                    <PaginationBox :pagination="pagination" :method="list" />
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import axios from 'axios';
import LoadingComponent from "../components/LoadingComponent";
import ItemCreateComponent from "./ItemCreateComponent";
import alertService from "../../../services/alertService";
import statusEnum from "../../../enums/modules/statusEnum";
import askEnum from "../../../enums/modules/askEnum";
import itemTypeEnum from "../../../enums/modules/itemTypeEnum";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import appService from "../../../services/appService";
import TableLimitComponent from "../components/TableLimitComponent";
import SmIconSidebarModalEditComponent from "../components/buttons/SmIconSidebarModalEditComponent";
import SmIconDeleteComponent from "../components/buttons/SmIconDeleteComponent";
import SmIconViewComponent from "../components/buttons/SmIconViewComponent";
import FilterComponent from "../components/buttons/collapse/FilterComponent";
import ExportComponent from "../components/buttons/export/ExportComponent";
import PrintComponent from "../components/buttons/export/PrintComponent";
import ExcelComponent from "../components/buttons/export/ExcelComponent";
import displayModeEnum from "../../../enums/modules/displayModeEnum";
import SampleFileComponent from "../components/buttons/import/SampleFileComponent.vue";
import UploadFileComponent from "../components/buttons/import/UploadFileComponent.vue";
import ImportComponent from "../components/buttons/import/ImportComponent.vue";
import ItemUploadComponent from './ItemUploadComponent.vue';
import AvailabilityToggleComponent from './AvailabilityToggleComponent.vue';
import ENV from '../../../config/env';

export default {
    name: "ItemListComponent",
    components: {
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        ItemCreateComponent,
        LoadingComponent,
        SmIconSidebarModalEditComponent,
        SmIconDeleteComponent,
        SmIconViewComponent,
        FilterComponent,
        ExportComponent,
        PrintComponent,
        ExcelComponent,
        SampleFileComponent,
        UploadFileComponent,
        ImportComponent,
        ItemUploadComponent,
        AvailabilityToggleComponent
    },
    data() {
        return {
            loading: {
                isActive: false
            },
            enums: {
                statusEnum: statusEnum,
                itemTypeEnum: itemTypeEnum,
                askEnum: askEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive")
                }
            },
            printLoading: true,
            printObj: {
                id: "print",
                popTitle: this.$t("menu.items"),
            },
            taxProps: {
                search: {
                    paginate: 0,
                    order_column: 'id',
                    order_type: 'asc'
                }
            },
            categoryProps: {
                search: {
                    paginate: 0,
                    order_column: 'id',
                    order_type: 'asc'
                }
            },
            props: {
                form: {
                    name: "",
                    price: "",
                    description: "",
                    caution: "",
                    is_featured: askEnum.YES,
                    // [GAP-27-1] Default is_upsell = NO (5) for new items
                    is_upsell: askEnum.NO,
                    item_type: itemTypeEnum.VEG,
                    item_category_id: null,
                    tax_id: null,
                    status: statusEnum.ACTIVE,
                    // [v1-0-1-h5 Z5-P1-01 2026-05-17] Default = empty array = "all surfaces"
                    // (Item::displaysOn treats NULL as universal; the FormData
                    // builder in ItemCreateComponent.save() skips the channels[]
                    // append when the array is empty, so the server keeps the
                    // legacy NULL = visible-everywhere semantics.)
                    channels: [],
                },
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: 'id',
                    order_type: 'desc',
                    name: "",
                    price: "",
                    item_category_id: null,
                    status: null,
                    tax_id: null,
                    item_type: null,
                    is_featured: null
                }
            },
            ENV: ENV
        }
    },
    mounted() {
        this.list();
        this.loading.isActive = true;
        this.props.search.page = 1;
        const routeCategoryId = Number(this.$route?.query?.item_category_id || 0);
        if (Number.isInteger(routeCategoryId) && routeCategoryId > 0) {
            this.props.form.item_category_id = routeCategoryId;
            this.props.search.item_category_id = routeCategoryId;
        }
        this.$store.dispatch('itemCategory/lists', this.categoryProps.search).then(res => {
            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
        });
        this.$store.dispatch('tax/lists', this.taxProps.search).then(res => {
            this.loading.isActive = false;
        }).catch((err) => {
            this.loading.isActive = false;
        });
        // [T-WC-CREATE-URL-01] /admin/items/create deep-link → opens create drawer.
        // The drawer DOM lives inside ItemCreateComponent (v-if=items_create), so we
        // wait one tick to ensure it is mounted before activating the [data-drawer] target.
        if (this.$route?.query?.create === '1' && this.permissionChecker('items_create')) {
            this.$nextTick(() => appService.sideDrawerShow());
        }
    },
    computed: {
        items: function () {
            return this.$store.getters['item/lists'];
        },
        pagination: function () {
            return this.$store.getters['item/pagination'];
        },
        paginationPage: function () {
            return this.$store.getters['item/page'];
        },
        itemCategories: function () {
            return this.$store.getters["itemCategory/lists"];
        },
        taxes: function () {
            return this.$store.getters['tax/lists'];
        },
        wizardPerItemDemoEnabled() {
            return typeof window !== 'undefined'
                && window.foodkingConfig?.features?.wizard_per_item_demo === true;
        },
        direction: function () {
            return this.$store.getters['frontendLanguage/show'].display_mode === displayModeEnum.RTL ? 'rtl' : 'ltr';
        },
        itemsCount: function () {
            // [Wave P-5 2026-05-20] Prefer the paginated total (full catalog size)
            // over `items.length` (currently visible page = 10). Owner viewed
            // "10 produits" on a 46-item Le Cayenne menu — defect Wave O O7.
            const pageTotal = this.paginationPage && Number(this.paginationPage.total);
            if (Number.isFinite(pageTotal) && pageTotal > 0) {
                return pageTotal;
            }
            const paginationTotal = this.pagination && Number(this.pagination.total);
            if (Number.isFinite(paginationTotal) && paginationTotal > 0) {
                return paginationTotal;
            }
            return Array.isArray(this.items) ? this.items.length : 0;
        },
        categoriesCount: function () {
            return Array.isArray(this.itemCategories) ? this.itemCategories.length : 0;
        },
        activeItemsCount: function () {
            if (!Array.isArray(this.items)) {
                return 0;
            }
            return this.items.filter((item) => Number(item.status) === Number(statusEnum.ACTIVE)).length;
        },
        unavailableItemsCount: function () {
            if (!Array.isArray(this.items)) {
                return 0;
            }
            return this.items.filter((item) => {
                return item.is_available === false || item.is_available === 0 || item.is_available === '0'
                    || item.availability_reason || item.unavailable_reason;
            }).length;
        },

    },
    methods: {
        permissionChecker(e) {
            return appService.permissionChecker(e);
        },
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        textShortener: function (text, number = 30) {
            return appService.textShortener(text, number);
        },
        numberOnly: function (e) {
            return appService.floatNumber(e);
        },
        handleSlide: function (id) {
            return appService.handleSlide(id);
        },
        search: function () {
            this.list();
        },
        clear: function () {
            this.props.search.paginate = 1;
            this.props.search.page = 1;
            this.props.search.name = "";
            this.props.search.price = "";
            this.props.search.item_category_id = null;
            this.props.search.status = null;
            this.props.search.tax_id = null;
            this.props.search.item_type = null;
            this.props.search.is_featured = null;
            this.list();
        },
        filterActiveItems: function () {
            this.props.search.status = statusEnum.ACTIVE;
            this.list();
        },
        focusAvailability: function () {
            this.$nextTick(() => {
                this.$refs.availabilityTable?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
            });
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch('item/lists', this.props.search).then(res => {
                this.loading.isActive = false;
            }).catch((err) => {
                this.loading.isActive = false;
            });
        },
        edit: function (item) {
            appService.sideDrawerShow();
            this.loading.isActive = true;
            this.$store.dispatch('item/edit', item.id);
            this.loading.isActive = false;
            this.props.errors = {};
            this.props.form = {
                name: item.name,
                price: item.flat_price,
                description: item.description,
                caution: item.caution,
                is_featured: item.is_featured,
                // [GAP-27-1] Load is_upsell from API so admin can toggle it (askEnum.NO=10 default)
                is_upsell: item.is_upsell ?? askEnum.NO,
                item_type: item.item_type,
                tax_id: item.tax_id,
                item_category_id: item.item_category_id,
                status: item.status,
                // [v1-0-1-h5 Z5-P1-01 2026-05-17] Hydrate channels from API.
                // NULL (legacy "all surfaces") → empty array; array stays.
                // Unknown channels filtered out as defence against schema drift.
                channels: Array.isArray(item.channels)
                    ? item.channels.filter((c) => ['kiosk', 'pos', 'web'].indexOf(c) !== -1)
                    : [],
            };
        },
        destroy: function (id) {
            appService.destroyConfirmation().then((res) => {
                try {
                    this.loading.isActive = true;
                    this.$store.dispatch('item/destroy', { id: id, search: this.props.search }).then((res) => {
                        this.loading.isActive = false;
                        alertService.successFlip(null, this.$t('menu.items'));
                    }).catch((err) => {
                        this.loading.isActive = false;
                        alertService.error(err.response.data.message);
                    })
                } catch (err) {
                    this.loading.isActive = false;
                    alertService.error(err.response.data.message);
                }
            }).catch((err) => {
                this.loading.isActive = false;
            })
        },
        duplicate: function (item) {
            const confirmed = window.confirm(`${this.$t('label.duplicate')} ${item.name}?`);
            if (!confirmed) {
                return;
            }

            this.loading.isActive = true;
            axios.post(`/admin/item/${item.id}/duplicate`).then(() => {
                this.loading.isActive = false;
                alertService.successInfo(null, this.$t('message.item_duplicated'));
                this.list(this.paginationPage?.current_page || this.props.search.page || 1);
            }).catch((err) => {
                this.loading.isActive = false;
                alertService.error(err.response?.data?.message || err.message);
            });
        },
        xls: function () {
            this.loading.isActive = true;
            this.$store.dispatch("item/export", this.props.search).then((res) => {
                this.loading.isActive = false;
                const blob = new Blob([res.data], {
                    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                });
                const link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = this.$t("menu.items");
                link.click();
                URL.revokeObjectURL(link.href);
            }).catch((err) => {
                this.loading.isActive = false;
                alertService.error(err.response.data.message);
            });
        },
        uploadModal: function (id) {
            appService.modalShow(id);
        },
        downloadSample: function () {
            this.loading.isActive = true;
            this.$store.dispatch("item/downloadSample").then((res) => {
                this.loading.isActive = false;
                const url = window.URL.createObjectURL(
                    new Blob([res.data])
                );
                const link = document.createElement("a");
                link.href = url;
                link.download =
                    "" + "Items Import Sample." + 'xlsx';
                link.click();
                URL.revokeObjectURL(link.href);
            }).catch((err) => {
                this.loading.isActive = false;
            });
        }
    }
}
</script>

<style scoped>
@media print {
    .hidden-print {
        display: none !important;
    }
}

.catalog-control-plane {
    display: grid;
    grid-template-columns: minmax(240px, 1.2fr) minmax(280px, 1fr) minmax(260px, .9fr);
    gap: 12px;
    align-items: stretch;
    margin-bottom: 16px;
    padding: 16px;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #e11d48;
    border-radius: 8px;
    background: #ffffff;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
}

.catalog-control-plane__summary {
    min-width: 0;
}

.catalog-control-plane__eyebrow {
    display: inline-flex;
    align-items: center;
    margin-bottom: 6px;
    color: #e11d48;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
}

.catalog-control-plane__title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

.catalog-control-plane__title-row h2 {
    margin: 0;
    color: #0f172a;
    font-size: 20px;
    font-weight: 800;
    line-height: 1.2;
}

.catalog-control-plane__sync {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border-radius: 999px;
    background: #ecfdf5;
    color: #047857;
    font-size: 12px;
    font-weight: 800;
}

.catalog-control-plane__metrics,
.catalog-control-plane__actions {
    display: grid;
    gap: 8px;
}

.catalog-control-plane__metrics {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.catalog-control-plane__actions {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.catalog-control-plane__metric,
.catalog-control-plane__action {
    min-height: 54px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    color: #0f172a;
    transition: border-color .16s ease, background .16s ease, transform .16s ease;
}

.catalog-control-plane__metric {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 8px 10px;
    text-align: left;
}

.catalog-control-plane__metric span {
    color: #111827;
    font-size: 20px;
    font-weight: 900;
    line-height: 1;
}

.catalog-control-plane__metric small {
    margin-top: 5px;
    color: #64748b;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}

.catalog-control-plane__metric--alert span {
    color: #dc2626;
}

.catalog-control-plane__action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px;
    font-size: 13px;
    font-weight: 800;
}

.catalog-control-plane__metric:hover,
.catalog-control-plane__action:hover {
    border-color: #e11d48;
    background: #fff1f2;
    transform: translateY(-1px);
}

@media (max-width: 1180px) {
    .catalog-control-plane {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .catalog-control-plane {
        padding: 12px;
    }

    .catalog-control-plane__metrics,
    .catalog-control-plane__actions {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
