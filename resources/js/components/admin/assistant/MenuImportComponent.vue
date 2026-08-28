<template>
    <div class="db-card db-tab-div active">
        <div class="db-card-header">
            <h3 class="db-card-title">{{ $t("label.menu_import_title") }}</h3>
            <p class="db-card-subtitle">{{ $t("label.menu_import_intro") }}</p>
        </div>

        <div class="db-card-body">
            <!-- ÉTAPE 1 — la photo -->
            <div v-if="etape === 'photo'" class="form-row">
                <div class="form-col-12">
                    <label class="db-field-title" for="photo_carte">
                        {{ $t("label.menu_import_photo") }}
                    </label>
                    <input
                        id="photo_carte"
                        ref="fichier"
                        type="file"
                        class="db-field-control"
                        :accept="formatsAcceptes"
                        @change="choisirPhoto"
                    />
                    <small class="db-field-hint">{{ $t("label.menu_import_formats") }}</small>
                    <p v-if="erreurPhoto" class="db-field-alert">{{ erreurPhoto }}</p>
                </div>

                <div class="form-col-12">
                    <button
                        type="button"
                        class="db-btn text-white bg-primary"
                        :disabled="!photo || occupe"
                        @click="lire"
                    >
                        {{ occupe ? $t("label.menu_import_reading") : $t("label.menu_import_read") }}
                    </button>
                </div>
            </div>

            <!-- ÉTAPE 2 — la relecture. C'est ICI que se joue tout le dispositif :
                 rien n'est écrit tant que le commerçant n'a pas relu. -->
            <div v-if="etape === 'relecture'">
                <div class="db-alert-info mb-4">
                    <p>{{ $t("label.menu_import_review_hint", { n: lignes.length }) }}</p>
                    <!-- Dire d'où vient ce qui est affiché. Sans ça, un commerçant
                         croirait avoir importé sa carte en regardant une démonstration. -->
                    <p v-if="source === 'bouchon'" class="db-field-alert">
                        {{ $t("label.menu_import_demo_notice") }}
                    </p>
                    <p v-if="tronquee" class="db-field-alert">
                        {{ $t("label.menu_import_truncated") }}
                    </p>
                </div>

                <div class="table-responsive overflow-x-auto">
                    <table class="db-table">
                        <thead class="db-table-head">
                            <tr>
                                <th class="db-table-head-th">{{ $t("label.name") }}</th>
                                <th class="db-table-head-th">{{ $t("label.category") }}</th>
                                <th class="db-table-head-th">{{ $t("label.price") }}</th>
                                <th class="db-table-head-th">{{ $t("label.status") }}</th>
                            </tr>
                        </thead>
                        <tbody class="db-table-body">
                            <tr
                                v-for="(ligne, i) in lignes"
                                :key="i"
                                class="db-table-body-tr"
                                :class="{ 'bg-yellow-50': aVerifier(ligne) }"
                            >
                                <td class="db-table-body-td">
                                    <input v-model="ligne.nom" type="text" class="db-field-control" />
                                </td>
                                <td class="db-table-body-td">
                                    <input v-model="ligne.categorie" type="text" class="db-field-control" />
                                </td>
                                <td class="db-table-body-td">
                                    <input
                                        v-model.number="ligne.prix"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="db-field-control"
                                        :class="{ 'border-red-500': ligne.prix === null || ligne.prix === '' }"
                                    />
                                </td>
                                <td class="db-table-body-td">
                                    <span v-if="ligne.prix === null || ligne.prix === ''" class="text-red-600">
                                        {{ $t("label.menu_import_price_missing") }}
                                    </span>
                                    <span v-else-if="aVerifier(ligne)" class="text-yellow-700">
                                        {{ $t("label.menu_import_check") }}
                                    </span>
                                    <span v-else class="text-green-600">{{ $t("label.menu_import_ok") }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="form-row mt-4">
                    <!-- La TVA est un choix du COMMERÇANT, jamais de la lecture d'image.
                         Sans elle le serveur refuse — et c'est voulu : un produit sans
                         taxe serait facturé à 0 % en silence. -->
                    <div class="form-col-6">
                        <label class="db-field-title" for="taxe_import">
                            {{ $t("label.tax") }} <span class="text-red-600">*</span>
                        </label>
                        <select id="taxe_import" v-model.number="taxeId" class="db-field-control">
                            <option :value="null">{{ $t("label.select") }}</option>
                            <option v-for="taxe in taxes" :key="taxe.id" :value="taxe.id">
                                {{ taxe.name }} — {{ taxe.tax_rate }} %
                            </option>
                        </select>
                        <small class="db-field-hint">{{ $t("label.menu_import_tax_hint") }}</small>
                    </div>

                    <div class="form-col-6">
                        <label class="db-field-title" for="type_import">
                            {{ $t("label.item_type") }} <span class="text-red-600">*</span>
                        </label>
                        <select id="type_import" v-model.number="typeArticle" class="db-field-control">
                            <option :value="null">{{ $t("label.select") }}</option>
                            <option v-for="t in typesArticle" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="form-row mt-4">
                    <div class="form-col-12">
                        <button type="button" class="db-btn bg-gray-200" @click="recommencer">
                            {{ $t("button.cancel") }}
                        </button>
                        <button
                            type="button"
                            class="db-btn text-white bg-primary ml-2"
                            :disabled="!pretAAppliquer || occupe"
                            @click="appliquer"
                        >
                            {{ $t("label.menu_import_apply", { n: lignes.length }) }}
                        </button>
                        <p v-if="!pretAAppliquer" class="db-field-hint mt-2">
                            {{ $t("label.menu_import_blocked") }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ÉTAPE 3 — le compte rendu -->
            <div v-if="etape === 'rapport'">
                <p class="db-card-title">{{ resume }}</p>

                <div v-if="rapport.refus && rapport.refus.length" class="mt-4">
                    <h4 class="db-field-title">{{ $t("label.menu_import_refused") }}</h4>
                    <ul class="list-disc pl-6">
                        <li v-for="(r, i) in rapport.refus" :key="i">
                            <strong>{{ r.ligne }}</strong> — {{ r.raison }}
                        </li>
                    </ul>
                </div>

                <button type="button" class="db-btn text-white bg-primary mt-4" @click="recommencer">
                    {{ $t("label.menu_import_again") }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import alertService from "../../../services/alertService";

/**
 * [ONB-04 2026-08-28] Importer sa carte depuis une photo.
 *
 * Trois étapes, et la deuxième est la raison d'être de l'écran : **rien n'est
 * écrit tant que le commerçant n'a pas relu**. La lecture d'image PROPOSE, il
 * CORRIGE, et seulement ensuite le catalogue est créé — par les mêmes services
 * que la saisie à la main, donc avec les mêmes règles.
 *
 * Deux choix d'affichage qui ne sont pas cosmétiques :
 *
 * · Une ligne dont le prix n'a pas pu être lu est montrée EN ROUGE et bloque
 *   l'application, au lieu d'être écartée en silence. Cacher une ligne douteuse
 *   serait pire que la montrer : le commerçant croirait sa carte complète.
 *
 * · Quand la lecture vient du bouchon (aucune clé d'IA configurée — gate
 *   propriétaire G-IA en attente), l'écran le DIT. Sans cette mention, un
 *   commerçant croirait avoir importé sa carte en regardant une démonstration.
 */
export default {
    name: "MenuImportComponent",

    data() {
        return {
            etape: "photo",
            photo: null,
            erreurPhoto: "",
            occupe: false,
            lignes: [],
            source: "",
            tronquee: false,
            seuilConfiance: 0.75,
            taxeId: null,
            typeArticle: null,
            taxes: [],
            rapport: {},
            resume: "",
        };
    },

    computed: {
        formatsAcceptes() {
            return ".jpg,.jpeg,.png,.webp,.heic,.pdf";
        },

        typesArticle() {
            // On reprend les cles EXISTANTES du projet plutot que d'en inventer :
            // `label.item_type_veg` n'existait dans aucune langue et mon ecran aurait
            // affiche la cle brute. Attrape avant livraison par la mesure des cles
            // citees-mais-absentes.
            return [
                { id: 1, name: this.$t("label.veg") },
                { id: 2, name: this.$t("label.non_veg") },
            ];
        },

        /**
         * On ne laisse pas partir une charge qu'on sait invalide : le serveur la
         * refuserait de toute façon, mais un blocage ici explique POURQUOI, à côté
         * du champ concerné, plutôt qu'après un aller-retour.
         */
        pretAAppliquer() {
            if (!this.taxeId || !this.typeArticle) return false;
            if (!this.lignes.length) return false;

            return this.lignes.every(
                (l) =>
                    String(l.nom || "").trim() !== "" &&
                    String(l.categorie || "").trim() !== "" &&
                    l.prix !== null &&
                    l.prix !== "" &&
                    Number(l.prix) >= 0,
            );
        },
    },

    mounted() {
        this.chargerTaxes();
    },

    methods: {
        aVerifier(ligne) {
            return Number(ligne.confiance) < this.seuilConfiance;
        },

        chargerTaxes() {
            axios
                .get("/api/admin/taxes", { params: { paginate: 0 } })
                .then((r) => {
                    this.taxes = r?.data?.data || [];
                })
                .catch(() => {
                    this.taxes = [];
                });
        },

        choisirPhoto(evenement) {
            this.erreurPhoto = "";
            this.photo = evenement.target.files?.[0] || null;
        },

        lire() {
            if (!this.photo) return;

            const charge = new FormData();
            charge.append("photo", this.photo);

            this.occupe = true;
            this.erreurPhoto = "";

            axios
                .post("/api/admin/assistant/menu/lecture", charge, {
                    headers: { "Content-Type": "multipart/form-data" },
                })
                .then((r) => {
                    const p = r?.data?.proposition || {};
                    this.lignes = (p.articles || []).map((a) => ({ ...a }));
                    this.source = r?.data?.source || "";
                    this.tronquee = Boolean(p.tronquee);
                    this.seuilConfiance = Number(r?.data?.seuil_confiance ?? 0.75);
                    this.etape = "relecture";
                })
                .catch((e) => {
                    // Le message du serveur d'abord : il dit quoi corriger.
                    this.erreurPhoto =
                        e?.response?.data?.errors?.photo?.[0] ||
                        e?.response?.data?.message ||
                        this.$t("label.menu_import_failed");
                })
                .finally(() => {
                    this.occupe = false;
                });
        },

        appliquer() {
            this.occupe = true;

            axios
                .post("/api/admin/assistant/menu/application", {
                    tax_id: this.taxeId,
                    item_type: this.typeArticle,
                    articles: this.lignes.map((l) => ({
                        nom: l.nom,
                        categorie: l.categorie,
                        prix: l.prix,
                        description: l.description || null,
                    })),
                })
                .then((r) => {
                    this.rapport = r?.data?.rapport || {};
                    this.resume = r?.data?.resume || "";
                    this.etape = "rapport";
                })
                .catch((e) => {
                    alertService.error(
                        e?.response?.data?.message ||
                            Object.values(e?.response?.data?.errors || {})[0]?.[0] ||
                            this.$t("label.menu_import_failed"),
                    );
                })
                .finally(() => {
                    this.occupe = false;
                });
        },

        recommencer() {
            this.etape = "photo";
            this.photo = null;
            this.lignes = [];
            this.rapport = {};
            this.resume = "";
            this.erreurPhoto = "";

            if (this.$refs.fichier) {
                this.$refs.fichier.value = "";
            }
        },
    },
};
</script>
