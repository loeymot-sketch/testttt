<template>
    <LoadingComponent :props="loading" />
    <div class="catalog-studio" data-testid="catalog-studio-page">
        <header class="catalog-studio__header">
            <div>
                <p class="catalog-studio__eyebrow">{{ $t("studio.eyebrow") }}</p>
                <h2>{{ $t("studio.title") }}</h2>
                <p class="catalog-studio__subtitle">{{ $t("studio.subtitle") }}</p>
            </div>
            <div class="catalog-studio__header-actions">
                <button v-if="canCreateCategory" type="button" class="db-btn py-2 bg-rose-700 text-white"
                    data-testid="catalog-studio-add-category"
                    @click="onAddCategoryClick">
                    <i class="lab lab-add-circle-line"></i>
                    <span>{{ $t("button.add_item_category") }}</span>
                </button>
                <button v-if="canCreateItem" type="button" class="db-btn py-2 bg-green-700 text-white"
                    data-testid="catalog-studio-add-product"
                    @click="onAddProductClick">
                    <i class="lab lab-add-circle-line"></i>
                    <span>{{ $t("button.add_item") }}</span>
                </button>
            </div>
        </header>

        <section class="catalog-studio__body">
            <aside class="catalog-studio__sidebar">
                <div class="catalog-studio__sidebar-head">
                    <h3>{{ $t("menu.item_categories") }}</h3>
                    <span class="catalog-studio__counter">{{ categories.length }}</span>
                </div>

                <button type="button" class="catalog-studio__category"
                    :class="{ 'catalog-studio__category--active': selectedCategoryId === null }" @click="selectCategory(null)">
                    <strong>{{ $t("studio.all_categories") }}</strong>
                    <small>{{ $t("studio.products_count", { n: totalProducts }) }}</small>
                </button>

                <div v-if="selectedCategoryId !== null" class="catalog-studio__category-wizard"
                    data-testid="catalog-studio-category-wizard-entry">
                    <div>
                        <strong>{{ selectedCategoryName }}</strong>
                        <small>{{ t("studio.category_wizard_hint", "⚠️ Ce parcours est enregistré mais n'est PAS encore appliqué à la borne ni à la caisse.") }}</small>
                    </div>
                    <button type="button" class="db-btn py-2 bg-rose-700 text-white"
                        data-testid="catalog-studio-category-wizard-button"
                        @click="openCategoryComposerDrawer">
                        <i class="lab lab-settings"></i>
                        <span>{{ t("studio.category_wizard_button", "Wizard de la catégorie") }}</span>
                    </button>
                </div>

                <div v-for="category in categories" :key="category.id" class="catalog-studio__category-row"
                    :data-testid="`catalog-studio-category-row-${category.id}`">
                    <button type="button" class="catalog-studio__category"
                        :class="{ 'catalog-studio__category--active': selectedCategoryId === Number(category.id) }"
                        @click="selectCategory(Number(category.id))">
                        <strong>{{ category.name }}</strong>
                        <small>{{ $t("studio.products_count", { n: category.product_count || productsCountByCategory[category.id] || 0 }) }}</small>
                    </button>
                    <div class="catalog-studio__category-actions" v-if="canEditCategory || canDeleteCategory">
                        <button v-if="canEditCategory" type="button" class="db-table-action view"
                            :title="$t('label.update')" :aria-label="$t('label.update')"
                            :data-testid="`catalog-studio-category-edit-${category.id}`"
                            @click="editCategory(category)">
                            <i class="lab lab-edit-line"></i>
                        </button>
                        <button v-if="canDeleteCategory" type="button" class="db-table-action delete"
                            :title="$t('label.delete')" :aria-label="$t('label.delete')"
                            :data-testid="`catalog-studio-category-delete-${category.id}`"
                            @click="destroyCategory(category)">
                            <i class="lab lab-trash-line-2"></i>
                        </button>
                    </div>
                </div>

                <router-link class="catalog-studio__settings-link" :to="{ name: 'admin.settings.itemCategory.list' }">
                    <i class="lab lab-settings"></i>
                    <span>{{ $t("studio.advanced_settings") }}</span>
                </router-link>

                <form v-if="showCategoryQuickForm" ref="categoryQuickForm" class="catalog-studio__quick-form"
                    @submit.prevent="createCategory">
                    <label class="db-field-title">{{ $t("label.name") }}</label>
                    <input ref="categoryQuickFormNameInput" v-model.trim="categoryQuickForm.name" type="text"
                        class="db-field-control" required />
                    <button type="submit" class="db-btn py-2 bg-rose-700 text-white">{{ $t("button.save") }}</button>
                </form>
            </aside>

            <main class="catalog-studio__main">
                <div class="catalog-studio__toolbar">
                    <input v-model.trim="searchTerm" type="text" class="db-field-control" :placeholder="$t('label.search_by_menu_item')" />
                    <router-link class="catalog-studio__stock-link" :to="{ name: 'admin.stock.rupture' }"
                        data-testid="catalog-studio-stock-link">
                        <i class="lab lab-tick-square"></i>
                        <span>{{ $t("studio.stock_link") }}</span>
                    </router-link>
                </div>

                <div class="catalog-studio__product-grid" data-testid="catalog-studio-products-grid">
                    <form v-if="showProductQuickForm" ref="productQuickForm"
                        class="catalog-studio__quick-form catalog-studio__quick-form--product" @submit.prevent="createProduct">
                        <h4>{{ $t("studio.quick_create_product") }}</h4>
                        <div v-if="!selectedCategoryId" class="catalog-studio__quick-create-row">
                            <label for="quickProductCategory" class="db-field-title">{{ $t("label.category") }} *</label>
                            <select id="quickProductCategory" v-model.number="productQuickForm.categoryId"
                                class="db-field-control" required data-testid="catalog-studio-quick-product-category">
                                <option value="">—</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                    {{ cat.name }}
                                </option>
                            </select>
                        </div>
                        <label class="db-field-title">{{ $t("label.name") }}</label>
                        <input ref="productQuickFormNameInput" v-model.trim="productQuickForm.name" type="text"
                            class="db-field-control" required />
                        <label class="db-field-title">{{ $t("label.price") }}</label>
                        <input v-model.trim="productQuickForm.price" type="text" class="db-field-control" required />
                        <label class="db-field-title">{{ $t("label.description") }}</label>
                        <textarea v-model.trim="productQuickForm.description" class="db-field-control"></textarea>
                        <label class="db-field-title">{{ $t("label.image") }}</label>
                        <input type="file" class="db-field-control" accept="image/png, image/jpeg, image/jpg"
                            data-testid="catalog-studio-product-image-upload" @change="changeQuickProductImage" />
                        <button type="submit" class="db-btn py-2 bg-green-700 text-white">{{ $t("button.save") }}</button>
                    </form>

                    <article v-for="item in filteredProducts" :key="item.id" class="catalog-studio__product">
                        <img class="catalog-studio__product-thumb" :src="item.thumb" :alt="item.name" />
                        <div class="catalog-studio__product-content">
                            <h4>{{ item.name }}</h4>
                            <p>{{ item.category_name }}</p>
                            <div class="catalog-studio__product-meta">
                                <span>{{ item.currency_price }}</span>
                                <span :class="statusClass(item.status)">{{ enums.statusEnumArray[item.status] }}</span>
                            </div>
                        </div>
                        <div class="catalog-studio__product-actions">
                            <!-- [ONB-03 2026-08-28] `wizardPerItemDemoEnabled` ajouté : le
                                 bouton ne regardait que la permission, alors que le routeur
                                 REDIRIGE vers le catalogue quand le drapeau est éteint — et
                                 il l'est par défaut. Un bouton visible qui ne mène nulle
                                 part est pire qu'un bouton absent : le commerçant croit
                                 avoir raté quelque chose. -->
                            <button
                                v-if="canComposeCatalog && wizardPerItemDemoEnabled"
                                type="button"
                                class="db-table-action view"
                                :title="t('studio.product_composer_button', 'Composer / wizard')"
                                :aria-label="t('studio.product_composer_button', 'Composer / wizard')"
                                :data-testid="`catalog-studio-product-wizard-${item.id}`"
                                @click="openComposerDrawer(item)"
                            >
                                <i class="lab lab-settings"></i>
                            </button>
                            <router-link v-if="canViewItem" :to="{ name: 'admin.item.show', params: { id: item.id } }"
                                class="db-table-action view" :title="$t('label.view')"
                                :data-testid="`catalog-studio-product-view-${item.id}`">
                                <i class="lab lab-view"></i>
                            </router-link>
                            <button v-if="canDeleteItem" type="button" class="db-table-action delete"
                                :title="$t('label.delete')" :aria-label="$t('label.delete')"
                                :data-testid="`catalog-studio-product-delete-${item.id}`"
                                @click="destroyItem(item)">
                                <i class="lab lab-trash-line-2"></i>
                            </button>
                        </div>
                    </article>
                </div>

                <div v-if="filteredProducts.length === 0" class="catalog-studio__empty">
                    <p>{{ $t("message.no_data_available") }}</p>
                </div>
            </main>
        </section>

        <div v-if="composerDrawer.open" class="catalog-studio__composer-overlay" data-testid="catalog-studio-composer-overlay"
            @click.self="closeComposerDrawer">
            <aside class="catalog-studio__composer-drawer">
                <header class="catalog-studio__composer-header">
                    <div>
                        <p class="catalog-studio__composer-eyebrow">{{ $t("studio.composer_drawer_eyebrow") }}</p>
                        <h3>{{ composerDrawerTitle }}</h3>
                        <small v-if="composerDrawer.entityType === 'category'" class="catalog-studio__composer-help">
                            {{ t("studio.category_wizard_hint", "⚠️ Ce parcours est enregistré mais n'est PAS encore appliqué à la borne ni à la caisse.") }}
                        </small>
                    </div>
                    <div class="catalog-studio__composer-actions">
                        <router-link class="db-btn py-2 bg-rose-700 text-white"
                            :to="composerDrawerRoute"
                            :data-testid="'catalog-studio-composer-open-full'">
                            <i class="lab lab-file-export"></i>
                            <span>{{ $t("studio.open_full_page") }}</span>
                        </router-link>
                        <button type="button" class="db-btn py-2" data-testid="catalog-studio-composer-close"
                            @click="closeComposerDrawer">
                            <i class="lab lab-close"></i>
                            <span>{{ $t("button.close") }}</span>
                        </button>
                    </div>
                </header>
                <iframe v-if="composerDrawerUrl" class="catalog-studio__composer-frame" :src="composerDrawerUrl"
                    :title="composerDrawerTitle"
                    data-testid="catalog-studio-composer-frame"></iframe>
            </aside>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent.vue";
import appService from "../../../services/appService";
import statusEnum from "../../../enums/modules/statusEnum";
import askEnum from "../../../enums/modules/askEnum";
import itemTypeEnum from "../../../enums/modules/itemTypeEnum";
import alertService from "../../../services/alertService";

export default {
    name: "CatalogStudioComponent",
    components: {
        LoadingComponent,
    },
    data() {
        return {
            loading: { isActive: false },
            selectedCategoryId: null,
            searchTerm: "",
            showCategoryQuickForm: false,
            showProductQuickForm: false,
            categoryQuickForm: {
                name: "",
            },
            productQuickForm: {
                name: "",
                price: "",
                description: "",
                image: null,
                categoryId: null,
            },
            composerDrawer: {
                open: false,
                entityId: null,
                entityName: "",
                entityType: "item",
            },
            enums: {
                statusEnum,
                askEnum,
                itemTypeEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive"),
                },
            },
            categoriesSearch: {
                paginate: 0,
                order_column: "sort",
                order_type: "asc",
            },
            itemsSearch: {
                paginate: 0,
                order_column: "id",
                order_type: "desc",
            },
            // [ONB-06/ROUGE 2026-08-27] `status` ajoute : sans lui, les 47 taxes
            // INACTIVES de la base etaient chargees au meme titre que les 6 actives.
            taxesSearch: {
                paginate: 0,
                order_column: "id",
                order_type: "asc",
                status: statusEnum.ACTIVE,
            },
        };
    },
    computed: {
        canViewItem() {
            return appService.permissionChecker("items_show");
        },
        canEditItem() {
            return appService.permissionChecker("items_edit");
        },
        canCreateItem() {
            return appService.permissionChecker("items_create");
        },
        canDeleteItem() {
            return appService.permissionChecker("items_delete");
        },
        canComposeCatalog() {
            return appService.permissionChecker("catalog.compose");
        },
        // [ONB-03 2026-08-28] Le drapeau manquait ICI, et seulement ici. Cinq autres
        // endroits le vérifient déjà — MenuComponent, ItemCreateComponent,
        // ProductComposerSummaryComponent, ItemListComponent et le routeur lui-même
        // (itemRoutes.js:15, qui redirige vers le catalogue quand il est éteint).
        // Le bouton engrenage du Studio, lui, ne regardait que la permission ; or
        // `catalog.compose` est donnée à l'Admin dès l'installation, et le drapeau
        // vaut FALSE par défaut. Sur une installation neuve, cliquer l'engrenage
        // ouvrait donc un panneau qui affichait… le catalogue lui-même, sans un mot.
        wizardPerItemDemoEnabled() {
            return typeof window !== 'undefined'
                && window.foodkingConfig?.features?.wizard_per_item_demo === true;
        },
        canEditCategory() {
            return appService.permissionChecker("settings");
        },
        canDeleteCategory() {
            return appService.permissionChecker("settings");
        },
        canCreateCategory() {
            return appService.permissionChecker("settings");
        },
        categories() {
            return this.$store.getters["itemCategory/lists"] || [];
        },
        products() {
            return this.$store.getters["item/lists"] || [];
        },
        taxes() {
            return this.$store.getters["tax/lists"] || [];
        },
        // [ONB-06/ROUGE 2026-08-27] Ce getter mettait en echec la regle `tax_id
        // required` posee cote backend, et c'etait le chemin le plus rapide du produit.
        //
        // Avant : `this.taxes[0]` — la taxe d'identifiant le plus bas. Sur toute
        // installation issue du socle, l'id 1 est « No-VAT » a 0 %, ACTIF. Donc chaque
        // article cree en creation rapide naissait a 0 % de TVA, sans que le commercant
        // ait rien choisi ni rien vu — et la validation passait, puisque c'est bien une
        // taxe reelle, active, d'identifiant non nul.
        //
        // Desormais : on ne propose par defaut qu'un taux STRICTEMENT POSITIF. S'il n'y
        // en a aucun, on renvoie null et le backend refuse — mieux vaut un refus
        // explicite qu'une vente hors taxe silencieuse. Un article exonere reste
        // possible : il faut alors choisir le taux 0 % a la main, et c'est une decision.
        defaultTaxId() {
            const taxable = this.taxes.find(
                (t) => t && Number(t.tax_rate) > 0
            );
            return taxable ? Number(taxable.id) : null;
        },
        nextItemOrder() {
            // [STUDIO-FIX-P0] ItemRequest.php requires `order` (numeric). Compute next slot
            // by max(order)+1 over loaded items so the new product lands at the end.
            if (!Array.isArray(this.products) || this.products.length === 0) {
                return 1;
            }
            let max = 0;
            for (const it of this.products) {
                const o = Number(it.order || 0);
                if (Number.isFinite(o) && o > max) {
                    max = o;
                }
            }
            return max + 1;
        },
        composerDrawerUrl() {
            if (!this.composerDrawer.entityId || !this.$router?.resolve) {
                return "";
            }
            return this.$router.resolve(this.composerDrawerRoute).href;
        },
        composerDrawerRoute() {
            return {
                name: this.composerDrawer.entityType === "category" ? "admin.categories.composer" : "admin.items.composer",
                params: { id: this.composerDrawer.entityId },
            };
        },
        composerDrawerTitle() {
            if (this.composerDrawer.entityType === "category") {
                return `${this.t("studio.category_wizard_button", "Wizard de la catégorie")} : ${this.composerDrawer.entityName || "#"}`;
            }
            return this.$t("studio.composer_drawer_title", { item: this.composerDrawer.entityName || "#" });
        },
        selectedCategory() {
            if (this.selectedCategoryId === null) {
                return null;
            }
            return this.categories.find((category) => Number(category.id) === this.selectedCategoryId) || null;
        },
        selectedCategoryName() {
            return this.selectedCategory?.name || `#${this.selectedCategoryId}`;
        },
        totalProducts() {
            return this.products.length;
        },
        productsCountByCategory() {
            return this.products.reduce((carry, item) => {
                const key = Number(item.item_category_id || 0);
                carry[key] = (carry[key] || 0) + 1;
                return carry;
            }, {});
        },
        filteredProducts() {
            const q = this.searchTerm.toLowerCase();
            return this.products.filter((item) => {
                if (this.selectedCategoryId !== null && Number(item.item_category_id) !== this.selectedCategoryId) {
                    return false;
                }
                if (!q) {
                    return true;
                }
                return String(item.name || "").toLowerCase().includes(q);
            });
        },
    },
    mounted() {
        const queryCategoryId = this.$route?.query?.item_category_id;
        if (queryCategoryId) {
            const numericId = parseInt(queryCategoryId, 10);
            if (!Number.isNaN(numericId) && numericId > 0) {
                this.selectCategory(numericId);
            }
        }
        this.refreshData();
    },
    methods: {
        t(key, fallback) {
            if (typeof this.$t !== "function") {
                return fallback;
            }
            const translated = this.$t(key);
            return translated === key ? fallback : translated;
        },
        statusClass(status) {
            return appService.statusClass(status);
        },
        refreshData() {
            this.loading.isActive = true;
            Promise.all([
                this.$store.dispatch("itemCategory/lists", this.categoriesSearch),
                this.$store.dispatch("item/lists", this.itemsSearch),
                this.$store.dispatch("tax/lists", this.taxesSearch),
            ]).finally(() => {
                this.loading.isActive = false;
            });
        },
        selectCategory(categoryId) {
            this.selectedCategoryId = categoryId;
        },
        openCategoryModal() {
            this.$router.push({ name: "admin.settings.itemCategory.list" });
        },
        openProductCreate() {
            this.$router.push({
                name: "admin.items.list",
                query: { create: "1", item_category_id: this.selectedCategoryId || "" },
            });
        },
        onAddCategoryClick() {
            this.showCategoryQuickForm = !this.showCategoryQuickForm;

            if (this.showCategoryQuickForm) {
                this.$nextTick(() => {
                    const formEl = this.$refs.categoryQuickForm;
                    if (formEl && typeof formEl.scrollIntoView === "function") {
                        formEl.scrollIntoView({ behavior: "smooth", block: "center" });
                    }

                    const nameInput = this.$refs.categoryQuickFormNameInput;
                    if (nameInput && typeof nameInput.focus === "function") {
                        nameInput.focus();
                    }
                });
            }
        },
        onAddProductClick() {
            this.showProductQuickForm = !this.showProductQuickForm;

            if (this.showProductQuickForm) {
                this.productQuickForm.categoryId = this.selectedCategoryId ?? null;

                this.$nextTick(() => {
                    const formEl = this.$refs.productQuickForm;
                    if (formEl && typeof formEl.scrollIntoView === "function") {
                        formEl.scrollIntoView({ behavior: "smooth", block: "center" });
                    }

                    const firstInput = this.$refs.productQuickFormNameInput;
                    if (firstInput && typeof firstInput.focus === "function") {
                        firstInput.focus();
                    }
                });
            }
        },
        openComposerDrawer(item) {
            this.composerDrawer = {
                open: true,
                entityId: Number(item.id),
                entityName: item.name || "",
                entityType: "item",
            };
        },
        openCategoryComposerDrawer() {
            if (this.selectedCategoryId === null) {
                return;
            }
            this.composerDrawer = {
                open: true,
                entityId: this.selectedCategoryId,
                entityName: this.selectedCategoryName,
                entityType: "category",
            };
        },
        closeComposerDrawer() {
            this.composerDrawer = {
                open: false,
                entityId: null,
                entityName: "",
                entityType: "item",
            };
        },
        changeQuickProductImage(event) {
            const file = event?.target?.files?.[0] || null;
            this.productQuickForm.image = file;
        },
        buildQuickProductPayload() {
            const categoryId = this.productQuickForm.categoryId || this.selectedCategoryId;
            const fd = new FormData();
            fd.append("name", this.productQuickForm.name || "");
            fd.append("price", this.productQuickForm.price || "");
            fd.append("description", this.productQuickForm.description || "");
            fd.append("caution", "");
            fd.append("is_featured", String(askEnum.YES));
            fd.append("is_upsell", String(askEnum.NO));
            fd.append("item_type", String(itemTypeEnum.VEG));
            fd.append("item_category_id", String(categoryId));
            fd.append("tax_id", this.defaultTaxId ? String(this.defaultTaxId) : "");
            fd.append("status", String(statusEnum.ACTIVE));
            fd.append("order", String(this.nextItemOrder));
            if (this.productQuickForm.image) {
                fd.append("image", this.productQuickForm.image);
            }
            return fd;
        },
        editCategory(category) {
            // Édition complète vit dans la page Réglages (champs avancés). On y route
            // avec `?edit=<id>`.
            //
            // [ONB-02 2026-08-28] Le commentaire d'origine affirmait ouvrir la modale
            // « via le state Vuex partagé ». C'était FAUX : l'écran cible ne lisait
            // pas ce paramètre, et le commerçant était éjecté sur une liste paginée
            // sans que rien ne s'ouvre. `ItemCateogryListComponent::ouvrirDepuisLUrl()`
            // le lit désormais.
            this.$router.push({
                name: "admin.settings.itemCategory.list",
                query: { edit: String(category.id) },
            });
        },
        destroyCategory(category) {
            if (!this.canDeleteCategory) {
                return;
            }
            appService.destroyConfirmation().then(() => {
                this.loading.isActive = true;
                this.$store.dispatch("itemCategory/destroy", { id: category.id, search: this.categoriesSearch })
                    .then(() => {
                        if (this.selectedCategoryId === Number(category.id)) {
                            this.selectedCategoryId = null;
                        }
                        alertService.successFlip(null, this.$t("menu.item_categories"));
                        this.refreshData();
                    })
                    .catch((err) => {
                        const msg = err?.response?.data?.message || this.$t("error.something_wrong");
                        alertService.error(msg);
                    })
                    .finally(() => {
                        this.loading.isActive = false;
                    });
            }).catch(() => { /* user cancelled */ });
        },
        destroyItem(item) {
            if (!this.canDeleteItem) {
                return;
            }
            appService.destroyConfirmation().then(() => {
                this.loading.isActive = true;
                this.$store.dispatch("item/destroy", { id: item.id, search: this.itemsSearch })
                    .then(() => {
                        alertService.successFlip(null, this.$t("menu.items"));
                        this.refreshData();
                    })
                    .catch((err) => {
                        const msg = err?.response?.data?.message || this.$t("error.something_wrong");
                        alertService.error(msg);
                    })
                    .finally(() => {
                        this.loading.isActive = false;
                    });
            }).catch(() => { /* user cancelled */ });
        },
        createCategory() {
            if (!this.categoryQuickForm.name) {
                return;
            }
            // [STUDIO-FIX-P0] Reset temp state to force POST and avoid PUT to a stale id
            // when an admin has just edited a different category on another page.
            this.$store.dispatch("itemCategory/reset");
            this.loading.isActive = true;
            this.$store.dispatch("itemCategory/save", {
                form: {
                    name: this.categoryQuickForm.name,
                    status: statusEnum.ACTIVE,
                    description: "",
                },
                search: this.categoriesSearch,
            }).then(() => {
                this.categoryQuickForm.name = "";
                this.showCategoryQuickForm = false;
                // [ONB-02 2026-08-28] Etait `successFlip(null, ...)`, qui annonce une
                // SUPPRESSION. Au tout premier geste du parcours — creer sa premiere
                // categorie — le produit disait au commercant que ce qu'il venait de
                // creer avait ete supprime. `false` = creation (voir le piege de
                // signature documente dans alertService.successFlip).
                alertService.successFlip(false, this.$t("menu.item_categories"));
                this.refreshData();
            }).catch((err) => {
                const msg = err?.response?.data?.message || this.$t("error.something_wrong");
                alertService.error(msg);
            }).finally(() => {
                this.loading.isActive = false;
            });
        },
        createProduct() {
            const categoryId = this.productQuickForm.categoryId || this.selectedCategoryId;
            if (!categoryId) {
                alertService.error(this.$t("studio.select_category_first"));
                return;
            }
            // [STUDIO-FIX-P0] Same reset reason as createCategory.
            this.$store.dispatch("item/reset");
            this.loading.isActive = true;
            this.$store.dispatch("item/save", {
                form: this.buildQuickProductPayload(),
                search: this.itemsSearch,
            }).then(() => {
                this.productQuickForm = { name: "", price: "", description: "", image: null, categoryId: null };
                this.showProductQuickForm = false;
                // [ONB-02 2026-08-28] Etait `null`, qui annonce une SUPPRESSION.
                // Creer son premier produit affichait « Articles : suppression
                // effectuee. » — `false` = creation.
                alertService.successFlip(false, this.$t("menu.items"));
                this.refreshData();
            }).catch((err) => {
                const errors = err?.response?.data?.errors;
                const firstError = errors ? Object.values(errors).flat()[0] : null;
                const msg = firstError || err?.response?.data?.message || this.$t("error.something_wrong");
                alertService.error(msg);
            }).finally(() => {
                this.loading.isActive = false;
            });
        },
    },
};
</script>

<style scoped>
.catalog-studio {
    display: grid;
    gap: 14px;
}

.catalog-studio__header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #dc2626;
    background: #fff;
    border-radius: 10px;
    padding: 16px;
}

.catalog-studio__eyebrow {
    margin: 0 0 4px;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.06em;
    color: #dc2626;
    font-weight: 700;
}

.catalog-studio__header h2 {
    margin: 0;
    font-size: 22px;
    font-weight: 800;
}

.catalog-studio__subtitle {
    margin: 6px 0 0;
    color: #475569;
}

.catalog-studio__header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: start;
}

.catalog-studio__body {
    display: grid;
    grid-template-columns: minmax(220px, 280px) 1fr;
    gap: 14px;
    min-height: 520px;
}

.catalog-studio__sidebar,
.catalog-studio__main {
    border: 1px solid #e2e8f0;
    background: #fff;
    border-radius: 10px;
    padding: 12px;
}

.catalog-studio__sidebar {
    display: grid;
    gap: 8px;
    align-content: start;
}

.catalog-studio__sidebar-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.catalog-studio__sidebar-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
}

.catalog-studio__counter {
    background: #e2e8f0;
    color: #0f172a;
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 12px;
    font-weight: 700;
}

.catalog-studio__category-row {
    display: grid;
    /* [ONB-02 2026-08-28 · DEBORDEMENT VU A L'ECRAN] `1fr` vaut `minmax(auto, 1fr)`
       en CSS grid, et ce minimum `auto` est le MIN-CONTENT. Un nom de categorie en
       un seul long mot — « E2E Cat 1786616399744 » sur la base de travail —
       elargissait donc la piste au-dela du conteneur et POUSSAIT la colonne des
       boutons HORS de la carte : le crayon et la corbeille flottaient par-dessus la
       colonne des produits.
       `minmax(0, 1fr)` autorise la piste a retrecir ; `min-width: 0` et la coupure
       de mot font le reste. Trouve en REGARDANT la capture, pas en lisant le code —
       la CSS semblait correcte. */
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 6px;
    align-items: stretch;
}

.catalog-studio__category-actions {
    display: flex;
    gap: 4px;
    align-items: center;
}

.catalog-studio__category {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    color: #0f172a;
    text-align: left;
    padding: 10px;
    display: grid;
    gap: 2px;
    width: 100%;
    min-width: 0;
}

.catalog-studio__category strong,
.catalog-studio__category small {
    /* Un nom sans espace ne doit pas elargir sa carte : on le coupe. */
    min-width: 0;
    overflow-wrap: anywhere;
}

.catalog-studio__category strong {
    font-size: 13px;
    color: #0f172a;
}

.catalog-studio__category small {
    color: #475569;
}

.catalog-studio__category--active {
    border-color: #dc2626;
    background: #fff1f2;
    color: #7f1d1d;
}

.catalog-studio__category--active strong {
    color: #450a0a;
}

.catalog-studio__category--active small {
    color: #991b1b;
}

.catalog-studio__category-wizard strong {
    color: #7f1d1d;
}

.catalog-studio__category-wizard {
    border: 1px solid #fecaca;
    border-radius: 8px;
    background: #fff7f7;
    padding: 10px;
    display: grid;
    gap: 8px;
}

.catalog-studio__category-wizard strong,
.catalog-studio__category-wizard small {
    display: block;
}

.catalog-studio__category-wizard small {
    color: #7f1d1d;
    font-size: 12px;
}

.catalog-studio__settings-link {
    margin-top: 8px;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #0f172a;
}

.catalog-studio__quick-form {
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 10px;
    display: grid;
    gap: 8px;
}

.catalog-studio__quick-form--product {
    background: #f8fafc;
    border-style: solid;
}

.catalog-studio__quick-form--product h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 800;
}

.catalog-studio__quick-create-row {
    display: grid;
    gap: 6px;
}

.catalog-studio__main {
    display: grid;
    gap: 12px;
    align-content: start;
}

.catalog-studio__toolbar {
    display: flex;
    gap: 8px;
}

.catalog-studio__toolbar input {
    flex: 1;
}

.catalog-studio__stock-link {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #0f172a;
    white-space: nowrap;
}

.catalog-studio__product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px;
}

.catalog-studio__product {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
    display: grid;
}

.catalog-studio__product-thumb {
    width: 100%;
    height: 120px;
    object-fit: cover;
    background: #f1f5f9;
}

.catalog-studio__product-content {
    padding: 10px;
    display: grid;
    gap: 4px;
}

.catalog-studio__product-content h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 800;
}

.catalog-studio__product-content p {
    margin: 0;
    color: #64748b;
    font-size: 12px;
}

.catalog-studio__product-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
}

.catalog-studio__stock-inline {
    margin-top: 6px;
    border-top: 1px dashed #cbd5e1;
    padding-top: 8px;
    display: grid;
    gap: 6px;
}

.catalog-studio__stock-title {
    margin: 0;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 700;
    color: #334155;
}

.catalog-studio__stock-hint {
    color: #64748b;
    font-size: 11px;
}

.catalog-studio__product-actions {
    border-top: 1px solid #e2e8f0;
    padding: 8px;
    display: flex;
    gap: 8px;
}

.catalog-studio__empty {
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    padding: 28px;
    text-align: center;
    color: #64748b;
}

.catalog-studio__composer-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    z-index: 1200;
    display: flex;
    justify-content: flex-end;
}

.catalog-studio__composer-drawer {
    width: min(96vw, 1500px);
    height: 100vh;
    background: #f8fafc;
    border-left: 1px solid #cbd5e1;
    display: grid;
    grid-template-rows: auto 1fr;
}

.catalog-studio__composer-header {
    border-bottom: 1px solid #cbd5e1;
    background: #fff;
    padding: 12px 14px;
    display: flex;
    justify-content: space-between;
    gap: 12px;
}

.catalog-studio__composer-eyebrow {
    margin: 0 0 4px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    font-weight: 700;
}

.catalog-studio__composer-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
}

.catalog-studio__composer-help {
    display: block;
    margin-top: 4px;
    color: #7f1d1d;
}

.catalog-studio__composer-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.catalog-studio__composer-frame {
    width: 100%;
    height: 100%;
    border: 0;
    background: #fff;
}

@media (max-width: 1024px) {
    .catalog-studio__body {
        grid-template-columns: 1fr;
    }
}
</style>
