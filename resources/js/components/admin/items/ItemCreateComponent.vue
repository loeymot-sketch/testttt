<template>
    <LoadingComponent :props="loading" />
    <SmSidebarModalCreateComponent :props="addButton" @click="addReset" />

    <div id="sidebar" class="drawer">
        <div class="drawer-header">
            <h3 class="drawer-title">{{ $t("menu.items") }}</h3>
            <button class="fa-solid fa-xmark close-btn" @click="reset"></button>
        </div>
        <div class="drawer-body">
            <form @submit.prevent="save">
                <div class="form-row">
                    <div class="form-col-12 sm:form-col-6">
                        <label for="name" class="db-field-title required">{{ $t("label.name") }}</label>
                        <input v-model="props.form.name" v-bind:class="errors.name ? 'invalid' : ''" type="text"
                            id="name" class="db-field-control" data-testid="admin-item-form-name">
                        <small class="db-field-alert" v-if="errors.name">{{ errors.name[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label for="price" class="db-field-title required">{{ $t("label.price") }}</label>
                        <input v-model="props.form.price" v-bind:class="errors.price ? 'invalid' : ''" type="text"
                            id="price" class="db-field-control" data-testid="admin-item-form-price">
                        <small class="db-field-alert" v-if="errors.price">{{ errors.price[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label for="item_category_id" class="db-field-title required">{{ $t("label.category") }}</label>
                        <vue-select class="db-field-control f-b-custom-select" id="item_category_id"
                            v-bind:class="errors.item_category_id ? 'invalid' : ''"
                            v-model="props.form.item_category_id" :options="itemCategories" label-by="name"
                            value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true" placeholder="--"
                            search-placeholder="--" data-testid="admin-item-form-category" />
                        <small class="db-field-alert" v-if="errors.item_category_id">{{
                            errors.item_category_id[0]
                            }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <!-- [ONB-02 T-2.1.3 2026-08-27] Trois défauts corrigés ici, tous
                             constatés à l'écran et invisibles autrement :
                             1. le champ n'était pas marqué obligatoire alors que le backend
                                l'exige désormais — le commerçant remplissait tout puis se
                                prenait un 422 sans avoir été prévenu ;
                             2. le libellé « Taxe (incluant) » ne veut rien dire en français ;
                             3. label-by="code" affichait « VAT-10% » au lieu du nom du taux :
                                les noms lisibles du socle n'arrivaient jamais à l'écran. -->
                        <label for="tax_id" class="db-field-title required">{{ $t("label.tax") }}</label>
                        <vue-select class="db-field-control f-b-custom-select" id="tax_id"
                            v-bind:class="errors.tax_id ? 'invalid' : ''" v-model="props.form.tax_id"
                            :options="taxesLibellees"
                            label-by="libelle" value-by="id" :closeOnSelect="true" :searchable="true" :clearOnClose="true"
                            placeholder="--" search-placeholder="--" />
                        <small class="text-slate-500 block mt-1" v-if="!errors.tax_id">{{
                            $t("message.tax_required_hint")
                        }}</small>
                        <small class="db-field-alert" v-if="errors.tax_id">{{ errors.tax_id[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title">{{ $t("label.image") }}</label>
                        <input @change="changeImage" v-bind:class="errors.image ? 'invalid' : ''" id="image" type="file"
                            class="db-field-control" ref="imageProperty" accept="image/png, image/jpeg, image/jpg" data-testid="admin-item-form-image">
                        <small class="db-field-alert" v-if="errors.image">{{ errors.image[0] }}</small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title" for="veg">{{ $t("label.item_type") }}</label>
                        <div class="db-field-radio-group">
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input type="radio" v-model="props.form.item_type" id="veg"
                                        :value="enums.itemTypeEnum.VEG" class="custom-radio-field">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="veg" class="db-field-label">{{ $t('label.veg') }}</label>
                            </div>
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input type="radio" class="custom-radio-field" v-model="props.form.item_type"
                                        id="nonVeg" :value="enums.itemTypeEnum.NON_VEG">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="nonVeg" class="db-field-label">{{ $t('label.non_veg') }}</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title" for="yes">{{ $t("label.is_featured") }}</label>
                        <div class="db-field-radio-group">
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input type="radio" v-model="props.form.is_featured" id="yes"
                                        :value="enums.askEnum.YES" class="custom-radio-field">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="yes" class="db-field-label">{{ $t('label.yes') }}</label>
                            </div>
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input type="radio" class="custom-radio-field" v-model="props.form.is_featured"
                                        id="no" :value="enums.askEnum.NO">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="no" class="db-field-label">{{ $t('label.no') }}</label>
                            </div>
                        </div>
                    </div>

                    <!-- [GAP-27-1] is_upsell — Splash-style upsell suggestion on kiosk checkout -->
                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title" for="upsell_yes">{{ $t("label.is_upsell") }}</label>
                        <div class="db-field-radio-group">
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input type="radio" v-model="props.form.is_upsell" id="upsell_yes"
                                        :value="enums.askEnum.YES" class="custom-radio-field">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="upsell_yes" class="db-field-label">{{ $t('label.yes') }}</label>
                            </div>
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input type="radio" class="custom-radio-field" v-model="props.form.is_upsell"
                                        id="upsell_no" :value="enums.askEnum.NO">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="upsell_no" class="db-field-label">{{ $t('label.no') }}</label>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">{{ $t('label.is_upsell_hint') }}</p>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label class="db-field-title">{{ $t("label.status") }}</label>
                        <div class="db-field-radio-group">
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input type="radio" v-model="props.form.status" id="active"
                                        :value="enums.statusEnum.ACTIVE" class="custom-radio-field">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="active" class="db-field-label">{{ $t('label.active') }}</label>
                            </div>
                            <div class="db-field-radio">
                                <div class="custom-radio">
                                    <input type="radio" class="custom-radio-field" v-model="props.form.status"
                                        id="inactive" :value="enums.statusEnum.INACTIVE">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label for="inactive" class="db-field-label">{{ $t('label.inactive') }}</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-col-12">
                        <label for="caution" class="db-field-title">{{ $t("label.caution") }}</label>
                        <textarea v-model="props.form.caution" v-bind:class="errors.caution ? 'invalid' : ''"
                            id="caution" rows="2" class="db-field-control"></textarea>
                        <small class="db-field-alert" v-if="errors.caution">{{
                            errors.caution[0]
                            }}</small>
                    </div>

                    <!-- [v1-0-1-h5 Z5-P1-01 2026-05-17] Channels checkbox group — surface segregation UI.
                         Server validation (ItemRequest::rules) accepts in:kiosk,pos,web only.
                         Empty array on save = no channels[] keys appended = server keeps NULL
                         = visible everywhere (Item::displaysOn legacy semantics). -->
                    <div class="form-col-12" data-testid="admin-item-form-channels">
                        <label class="db-field-title" for="item-channels-kiosk">{{ $t("label.channels") }}</label>
                        <div class="db-field-radio-group">
                            <div class="db-field-radio" v-for="channel in ['kiosk', 'pos', 'web']" :key="channel">
                                <div class="custom-radio">
                                    <input type="checkbox"
                                        :id="'item-channels-' + channel"
                                        :value="channel"
                                        v-model="props.form.channels"
                                        class="custom-radio-field"
                                        :data-testid="'admin-item-form-channel-' + channel">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label :for="'item-channels-' + channel" class="db-field-label">
                                    {{ $t('label.channel_' + channel) }}
                                </label>
                            </div>
                        </div>
                        <small class="db-field-alert" v-if="errors.channels">{{ errors.channels[0] }}</small>
                        <p class="text-xs text-gray-400 mt-1">{{ $t('label.channels_help') }}</p>
                    </div>

                    <!--
                        [ONB 2026-08-28] Les allergènes, enfin saisissables.
                        Toute la chaîne existait — colonne, validation, observateur,
                        pivot, affichage caisse et cuisine, et jusqu'au FILTRE
                        ALLERGÈNES DE LA BORNE — sauf l'écran par lequel un humain
                        entre la vérité. Les correspondances actuelles viennent d'un
                        seed qui les qualifie lui-même de « guessed mappings ».
                    -->
                    <div class="form-col-12" data-testid="admin-item-form-allergens">
                        <label class="db-field-title">{{ $t("label.allergens_title") }}</label>
                        <div class="db-field-radio-group">
                            <div class="db-field-radio" v-for="allergene in allergenes" :key="allergene.code">
                                <div class="custom-radio">
                                    <input type="checkbox"
                                        :id="'item-allergen-' + allergene.code"
                                        :value="allergene.code"
                                        v-model="props.form.allergen_flags"
                                        class="custom-radio-field"
                                        :data-testid="'admin-item-form-allergen-' + allergene.code">
                                    <span class="custom-radio-span"></span>
                                </div>
                                <label :for="'item-allergen-' + allergene.code" class="db-field-label">
                                    <span v-if="allergene.icon" aria-hidden="true">{{ allergene.icon }}</span>
                                    {{ $t(allergene.cle) }}
                                </label>
                            </div>
                        </div>
                        <small class="db-field-alert" v-if="errors.allergen_flags">{{ errors.allergen_flags[0] }}</small>
                        <p class="text-xs text-gray-400 mt-1">{{ $t('label.allergens_help') }}</p>
                    </div>

                    <!--
                        [ONB 2026-08-28] Le poste de cuisine : même histoire.
                        `ItemRequest` le valide contre les quatre valeurs de l'ENUM
                        MySQL, le KDS le lit pour router la préparation — et aucun
                        écran ne l'écrivait, donc tout tombait sur le poste par défaut.
                    -->
                    <div class="form-col-12 sm:form-col-6" data-testid="admin-item-form-kds-station">
                        <label for="kds_station" class="db-field-title">{{ $t("label.kds_station") }}</label>
                        <select id="kds_station" v-model="props.form.kds_station" class="db-field-control">
                            <option v-for="(libelle, valeur) in postesDeCuisine" :key="valeur" :value="valeur">
                                {{ libelle }}
                            </option>
                        </select>
                        <small class="db-field-alert" v-if="errors.kds_station">{{ errors.kds_station[0] }}</small>
                        <p class="text-xs text-gray-400 mt-1">{{ $t('label.kds_station_help') }}</p>
                    </div>

                    <div class="form-col-12">
                        <label for="description" class="db-field-title">{{ $t("label.description") }}</label>
                        <textarea v-model="props.form.description" v-bind:class="errors.description ? 'invalid' : ''"
                            id="description" class="db-field-control"></textarea>
                        <small class="db-field-alert" v-if="errors.description">{{
                            errors.description[0]
                            }}</small>
                    </div>

                    <div class="col-12">
                        <div class="flex flex-wrap gap-3 mt-4">
                            <button type="submit" class="db-btn py-2 text-white bg-primary" data-testid="admin-item-form-save">
                                <i class="lab lab-save"></i>
                                <span>{{ $t("label.save") }}</span>
                            </button>
                            <button type="button" class="modal-btn-outline modal-close" :aria-label="$t('button.close')" @click="reset">
                                <i class="lab lab-close"></i>
                                <span>{{ $t("button.close") }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- [T-WC-AFTER-CREATE-01] Post-save CTA — guidage admin après création (Voir produit / Configurer wizard / Continuer) -->
    <div
        v-if="showPostSaveCta"
        class="item-create-cta-overlay"
        data-testid="item-create-post-save-cta"
        role="dialog"
        aria-modal="true"
        aria-labelledby="item-create-cta-title"
    >
        <div class="item-create-cta-card">
            <h3 id="item-create-cta-title" class="item-create-cta-title">
                {{ $t('message.item_created_success') }}
            </h3>
            <p class="item-create-cta-text">
                {{ $t('message.item_created_next_step') }}
            </p>
            <div class="flex flex-wrap gap-2 mt-4">
                <button
                    v-if="wizardPerItemDemoEnabled"
                    type="button"
                    class="db-btn py-2 text-white bg-primary"
                    data-testid="cta-configure-wizard"
                    @click="goToWizard"
                >
                    {{ $t('label.configure_wizard') }}
                </button>
                <button
                    type="button"
                    class="db-btn py-2"
                    data-testid="cta-view-product"
                    @click="goToProductDetail"
                >
                    {{ $t('label.view_product') }}
                </button>
                <button
                    type="button"
                    class="modal-btn-outline"
                    data-testid="cta-continue"
                    @click="dismissCta"
                >
                    {{ $t('label.continue') }}
                </button>
            </div>
        </div>
    </div>
</template>
<script>
import SmSidebarModalCreateComponent from "../components/buttons/SmSidebarModalCreateComponent.vue";
import LoadingComponent from "../components/LoadingComponent.vue";
import itemTypeEnum from "../../../enums/modules/itemTypeEnum";
import askEnum from "../../../enums/modules/askEnum";
import statusEnum from "../../../enums/modules/statusEnum";
import { libelleTaxe } from "../../../services/libelleTaxe";
import alertService from "../../../services/alertService";
import appService from "../../../services/appService";
// [ONB 2026-08-28] `axios.defaults.baseURL` vaut deja « <hote>/api »
// (`shared/axios-setup.js:75`) : les URL s'ecrivent SANS prefixe.
import axios from "axios";

export default {
    name: "ItemCreateComponent",
    components: { SmSidebarModalCreateComponent, LoadingComponent },
    props: ['props'],
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
                },
                itemTypeEnumArray: {
                    [itemTypeEnum.VEG]: this.$t("label.veg"),
                    [itemTypeEnum.NON_VEG]: this.$t("label.non_veg")
                },
                askEnumArray: {
                    [askEnum.YES]: this.$t("label.yes"),
                    [askEnum.NO]: this.$t("label.no")
                }
            },
            /** Le referentiel des 14 allergenes, charge au montage. */
            allergenes: [],
            /**
             * Les quatre valeurs de l'ENUM MySQL `items.kds_station`, ecrites ici
             * comme dans `ItemRequest` — l'enum PHP est revendique par ONB-10.
             */
            postesDeCuisine: {
                none: this.$t("label.kds_station_none"),
                bar: this.$t("label.kds_station_bar"),
                cuisine_chaude: this.$t("label.kds_station_hot"),
                cuisine_froide: this.$t("label.kds_station_cold"),
            },
            image: "",
            errors: {},
            showPostSaveCta: false,
            savedItemId: null,
        }
    },
    computed: {
        addButton: function () {
            return { title: this.$t('button.add_item') };
        },
        itemCategories: function () {
            return this.$store.getters['itemCategory/lists'];
        },
        taxes: function () {
            return this.$store.getters['tax/lists'];
        },
        // [ONB-10 2026-08-27] Deux taxes ACTIVES s'appellent toutes deux « VAT »,
        // pour 5 % et 10 % ; deux autres « GST ». Le libellé porte donc le taux —
        // dérivé de `tax_rate`, la seule valeur que PricingService facture, pour
        // qu'il ne puisse jamais contredire ce qui sera facturé.
        //
        // Le filtre de statut est refait ICI, en plus du `status` passé au chargement
        // (voir `mounted`). Raison constatée à l'écran : `ItemListComponent` remplit
        // le MÊME emplacement du magasin (`tax/lists`) SANS filtre, et écrase donc
        // celui-ci selon l'ordre de chargement. Le formulaire proposait encore
        // « TVA 67% » (taux réel 0 %) et « TVA 97% » (taux réel 20 %). Un filtre qui
        // dépend de l'ordre de chargement n'est pas un filtre.
        taxesLibellees: function () {
            const toutes = this.taxes || [];
            const actives = toutes.filter((t) => Number(t?.status) === statusEnum.ACTIVE);

            // [ONB-02 2026-08-28 · REGRESSION CORRIGEE] Ne filtrer QUE les actives
            // vidait le champ obligatoire pour tout produit rattaché à une taxe
            // inactive — 64 sur la base de travail. Le commerçant venu renommer un
            // article trouvait « Taxe * » vide, et le geste naturel pour le remplir
            // CHANGEAIT SON TAUX DE TVA. C'était pire que le défaut d'origine.
            //
            // On garde donc la taxe COURANTE dans la liste même si elle est inactive,
            // signalée comme telle : le commerçant voit ce qui est réellement appliqué
            // et choisit de le changer, au lieu d'y être poussé sans le savoir. Les
            // NOUVELLES sélections restent bornées aux taxes actives.
            //
            // Trouvé par un agent adverse lancé sur mon propre travail.
            const courante = Number(this.props?.form?.tax_id);
            const dejaLa = actives.some((t) => Number(t.id) === courante);
            const heritee = (! dejaLa && Number.isFinite(courante) && courante > 0)
                ? toutes.find((t) => Number(t.id) === courante)
                : null;

            const liste = heritee ? [heritee, ...actives] : actives;

            return liste.map((taxe) => ({
                ...taxe,
                libelle: (heritee && Number(taxe.id) === courante)
                    ? `${libelleTaxe(taxe)} — inactive`
                    : libelleTaxe(taxe),
            }));
        },
        wizardPerItemDemoEnabled() {
            return typeof window !== 'undefined'
                && window.foodkingConfig?.features?.wizard_per_item_demo === true;
        }
    },
    mounted() {
        this.loading.isActive = true;
        this.$store.dispatch('itemCategory/lists', {
            order_column: 'sort',
            order_type: 'asc',
            status: statusEnum.ACTIVE
        });
        // [ONB-06/ROUGE 2026-08-27] `status` manquait ici alors que la ligne du dessus
        // (itemCategory/lists) l'a. Mesure sur la base de travail : 6 taxes actives et
        // 47 INACTIVES — les 47 etaient proposees au commercant, y compris d'anciens
        // taux et des residus d'audit. Choisir une taxe inactive passait la validation
        // (`exists:taxes,id` ne regarde pas le statut) et facturait a son taux.
        this.$store.dispatch('tax/lists', {
            order_column: 'id',
            order_type: 'asc',
            status: statusEnum.ACTIVE
        });

        // [ONB 2026-08-28] Le referentiel legal des allergenes. En cas d'echec on
        // laisse la liste vide plutot que de bloquer le formulaire : ne pas pouvoir
        // declarer un allergene est genant, ne pas pouvoir creer un produit l'est
        // davantage. L'erreur est tracee, pas avalee.
        axios.get("admin/item/allergens")
            .then((reponse) => {
                this.allergenes = Array.isArray(reponse?.data?.data) ? reponse.data.data : [];
            })
            .catch((erreur) => {
                this.allergenes = [];
                console.error("[items] référentiel allergènes indisponible", erreur);
            });

        this.loading.isActive = false;
    },
    methods: {
        changeImage: function (e) {
            this.image = e.target.files[0];
        },
        reset: function () {
            appService.sideDrawerHide();
            this.$store.dispatch('item/reset').then().catch();
            this.errors = {};
            this.$props.props.form = {
                name: "",
                price: "",
                description: "",
                caution: "",
                is_featured: askEnum.YES,
                is_upsell: askEnum.NO,
                item_type: itemTypeEnum.VEG,
                item_category_id: null,
                tax_id: null,
                status: statusEnum.ACTIVE,
                // [v1-0-1-h5 Z5-P1-01 2026-05-17] Reset channels too.
                channels: [],
                // [ONB 2026-08-28] Sans cette remise a zero, enchainer deux
                // creations reporterait les allergenes du produit precedent sur
                // le suivant — une declaration FAUSSE, plus couteuse qu'une absente.
                allergen_flags: [],
                kds_station: "none",
                            // Creation : aucun rang encore.
                order: 1,
            };
            if (this.image) {
                this.image = "";
                this.$refs.imageProperty.value = null;
            }
        },
        addReset: function () {
            this.$store.dispatch('item/reset').then().catch();
            this.errors = {};
            this.$props.props.form = {
                name: "",
                price: "",
                description: "",
                caution: "",
                is_featured: askEnum.YES,
                is_upsell: askEnum.NO,
                item_type: itemTypeEnum.VEG,
                item_category_id: null,
                tax_id: null,
                status: statusEnum.ACTIVE,
                // [v1-0-1-h5 Z5-P1-01 2026-05-17] Reset channels too.
                channels: [],
                // [ONB 2026-08-28] Sans cette remise a zero, enchainer deux
                // creations reporterait les allergenes du produit precedent sur
                // le suivant — une declaration FAUSSE, plus couteuse qu'une absente.
                allergen_flags: [],
                kds_station: "none",
            };
            if (this.image) {
                this.image = "";
                this.$refs.imageProperty.value = null;
            }
        },
        save: function () {
            try {
                const fd = new FormData();
                fd.append('name', this.props.form.name);
                fd.append('price', this.props.form.price);
                fd.append('item_category_id', this.props.form.item_category_id == null ? '' : this.props.form.item_category_id);
                fd.append('tax_id', this.props.form.tax_id == null ? '' : this.props.form.tax_id);
                fd.append('item_type', this.props.form.item_type);
                fd.append('is_featured', this.props.form.is_featured);
                fd.append('is_upsell', this.props.form.is_upsell ?? askEnum.NO);
                fd.append('description', this.props.form.description);
                fd.append('caution', this.props.form.caution);
                // [ONB-02 2026-08-28] Etait `fd.append('order', 1)` — une CONSTANTE,
                // en creation COMME en modification. Corriger une faute de frappe
                // dans le nom d'un produit defaisait donc l'ordre de la carte, que
                // la borne utilise pour trier. Le commercant ne voyait rien.
                //
                // On renvoie le rang REEL, hydrate depuis la ressource. `?? 1` ne
                // sert qu'a la creation, ou aucun rang n'existe encore.
                fd.append('order', this.props.form.order ?? 1);
                fd.append('status', this.props.form.status);
                // [v1-0-1-h5 Z5-P1-01 2026-05-17] Append channels[] entries.
                // Empty array → skip → server keeps existing value (legacy
                // NULL = visible everywhere). To explicitly clear on edit
                // (i.e. hide on all surfaces), an admin currently needs API
                // / tinker; UI "clear-to-empty-array" is V1.0.2 backlog.
                const channels = Array.isArray(this.props.form.channels) ? this.props.form.channels : [];
                channels.forEach((c) => {
                    if (['kiosk', 'pos', 'web'].indexOf(c) !== -1) {
                        fd.append('channels[]', c);
                    }
                });

                /*
                 * [ONB 2026-08-28] Les allergènes, avec leur témoin.
                 *
                 * Décocher la DERNIÈRE case n'ajoute aucune entrée `allergen_flags[]`
                 * — indiscernable, côté serveur, d'un formulaire qui ignore le champ.
                 * Le témoin `allergen_flags_defini` lève l'ambiguïté : il affirme
                 * « cet écran a affiché le champ, et voici son état complet ». Sans
                 * lui, un commerçant ne pourrait jamais RETIRER un allergène déclaré
                 * par erreur — et une déclaration fausse est pire qu'une absente.
                 */
                fd.append('allergen_flags_defini', '1');
                const allergenes = Array.isArray(this.props.form.allergen_flags)
                    ? this.props.form.allergen_flags
                    : [];
                allergenes.forEach((code) => fd.append('allergen_flags[]', code));

                fd.append('kds_station', this.props.form.kds_station || 'none');
                if (this.image) {
                    fd.append('image', this.image);
                }
                const tempId = this.$store.getters['item/temp'].temp_id;
                this.loading.isActive = true;
                this.$store.dispatch('item/save', {
                    form: fd,
                    search: this.props.search
                }).then((res) => {
                    appService.sideDrawerHide();
                    this.loading.isActive = false;
                    alertService.successFlip((tempId === null ? 0 : 1), this.$t('menu.items'));
                    this.props.form = {
                        name: "",
                        price: "",
                        description: "",
                        caution: "",
                        is_featured: askEnum.YES,
                        is_upsell: askEnum.NO,
                        item_type: itemTypeEnum.VEG,
                        item_category_id: null,
                        tax_id: null,
                        status: statusEnum.ACTIVE,
                        // [v1-0-1-h5 Z5-P1-01 2026-05-17] Reset channels too.
                        channels: [],
                        allergen_flags: [],
                        kds_station: "none",
                                            // Creation : aucun rang encore.
                        order: 1,
                    };
                    this.image = "";
                    this.errors = {};
                    if (this.$refs.imageProperty) {
                        this.$refs.imageProperty.value = null;
                    }
                    // [T-WC-AFTER-CREATE-01] Capture id only on CREATE (tempId === null) and surface CTA modal.
                    if (tempId === null) {
                        const newId = res?.data?.data?.id ?? res?.data?.id ?? null;
                        if (newId) {
                            this.savedItemId = newId;
                            this.showPostSaveCta = true;
                        }
                    }
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = {};
                    if (err.response && err.response.data && err.response.data.errors) {
                        this.errors = err.response.data.errors;
                    } else {
                        alertService.error(err.response.data.message);
                    }
                })
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err)
            }
        },
        // [T-WC-AFTER-CREATE-01] Post-save CTA handlers — wizard / product detail / dismiss.
        goToWizard: function () {
            if (!this.wizardPerItemDemoEnabled) {
                return;
            }

            const id = this.savedItemId;
            this.dismissCta();
            if (id) {
                this.$router.push({ name: 'admin.items.composer', params: { id } });
            }
        },
        goToProductDetail: function () {
            const id = this.savedItemId;
            this.dismissCta();
            if (id) {
                this.$router.push({ name: 'admin.item.show', params: { id } });
            }
        },
        dismissCta: function () {
            this.showPostSaveCta = false;
            this.savedItemId = null;
        }
    }
}
</script>

<style scoped>
/* [T-WC-AFTER-CREATE-01] Self-contained CTA modal — no global CSS dependency. */
.item-create-cta-overlay {
    position: fixed;
    inset: 0;
    background-color: rgba(15, 23, 42, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1050;
    padding: 1rem;
}

.item-create-cta-card {
    background-color: #ffffff;
    border-radius: 0.75rem;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.18);
    padding: 1.5rem;
    max-width: 28rem;
    width: 100%;
}

.item-create-cta-title {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0 0 0.5rem 0;
}

.item-create-cta-text {
    font-size: 0.95rem;
    color: #475569;
    margin: 0;
}
</style>
