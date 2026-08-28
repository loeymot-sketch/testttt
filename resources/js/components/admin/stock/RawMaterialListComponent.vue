<template>
    <section class="space-y-4" data-testid="raw-material-list">
        <header class="rounded border border-neutral-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">FoodKing V1</p>
                    <h1 class="mt-1 text-xl font-semibold text-slate-900">
                        {{ $t('label.raw_materials_title') }}
                    </h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">
                        {{ $t('label.raw_materials_subtitle') }}
                    </p>
                </div>
                <span class="rounded bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">
                    {{ matieres.length }}
                </span>
            </div>
        </header>

        <!-- FORMULAIRE — déclarer ou corriger une matière -->
        <form
            class="rounded border border-neutral-200 bg-white p-5 shadow-sm"
            data-testid="raw-material-form"
            @submit.prevent="enregistrer"
        >
            <h2 class="mb-3 text-sm font-semibold text-slate-900">
                {{ enEdition ? $t('label.raw_material_edit') : $t('label.raw_material_new') }}
            </h2>

            <div class="grid gap-3 sm:grid-cols-4">
                <div>
                    <label for="rm-name" class="db-field-title required">{{ $t('label.name') }}</label>
                    <input id="rm-name" v-model="form.name" type="text" maxlength="190"
                        class="db-field-control" data-testid="raw-material-name" />
                    <small v-if="erreurs.name" class="db-field-alert">{{ erreurs.name[0] }}</small>
                </div>

                <div>
                    <label for="rm-unit" class="db-field-title required">{{ $t('label.unit') }}</label>
                    <!--
                        Les unités viennent du SERVEUR (`unites_acceptees`), pas d'une
                        liste recopiée ici : la conversion des factures d'achat ne sait
                        traiter que celles-là, et une liste écrite en double dériverait
                        au premier ajout — le motif du « jumeau oublié ».
                    -->
                    <select id="rm-unit" v-model="form.unit" class="db-field-control"
                        data-testid="raw-material-unit">
                        <option v-for="u in unitesAcceptees" :key="u" :value="u">{{ u }}</option>
                    </select>
                    <small v-if="erreurs.unit" class="db-field-alert">{{ erreurs.unit[0] }}</small>
                </div>

                <div>
                    <label for="rm-threshold" class="db-field-title">{{ $t('label.threshold_low') }}</label>
                    <input id="rm-threshold" v-model="form.threshold_low" type="number" min="0" step="0.01"
                        class="db-field-control" data-testid="raw-material-threshold" />
                    <p class="mt-1 text-xs text-gray-400">{{ $t('label.threshold_low_help') }}</p>
                    <small v-if="erreurs.threshold_low" class="db-field-alert">{{ erreurs.threshold_low[0] }}</small>
                </div>

                <div class="flex items-end gap-2">
                    <button type="submit" class="db-btn bg-primary py-2 px-4 text-white"
                        data-testid="raw-material-save" :disabled="occupe">
                        {{ $t('label.save') }}
                    </button>
                    <button v-if="enEdition" type="button" class="modal-btn-outline py-2 px-4"
                        data-testid="raw-material-cancel" @click="reinitialiser">
                        {{ $t('button.close') }}
                    </button>
                </div>
            </div>

            <p v-if="messageServeur" class="mt-3 text-sm text-rose-700" data-testid="raw-material-message">
                {{ messageServeur }}
            </p>
        </form>

        <!-- LISTE -->
        <div class="overflow-hidden rounded border border-neutral-200 bg-white shadow-sm">
            <div v-if="chargement" class="p-6 text-sm text-slate-500">{{ $t('label.loading') }}…</div>

            <p v-else-if="matieres.length === 0" class="p-6 text-center text-sm text-slate-500"
                data-testid="raw-material-empty">
                {{ $t('label.raw_materials_empty') }}
            </p>

            <table v-else class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-2">{{ $t('label.name') }}</th>
                        <th class="px-4 py-2">{{ $t('label.unit') }}</th>
                        <th class="px-4 py-2">{{ $t('label.stock') }}</th>
                        <th class="px-4 py-2">{{ $t('label.threshold_low') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in matieres" :key="m.id" class="border-t" :data-testid="'raw-material-' + m.id">
                        <td class="px-4 py-2 font-medium text-slate-900">{{ m.name }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ m.unit }}</td>
                        <td class="px-4 py-2 text-slate-600">
                            {{ m.on_hand === null ? '—' : m.on_hand }}
                        </td>
                        <td class="px-4 py-2">
                            <!--
                                Un seuil absent n'est pas « zéro » : c'est « aucune alerte ».
                                Les confondre est ce qui rendait l'alerte de stock bas muette
                                sur 100 % des lignes (le listener filtre `whereNotNull`).
                            -->
                            <span v-if="m.threshold_low === null" class="text-slate-400"
                                :data-testid="'raw-material-nothreshold-' + m.id">
                                {{ $t('label.threshold_low_none') }}
                            </span>
                            <span v-else>{{ m.threshold_low }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" class="text-rose-700 underline"
                                :data-testid="'raw-material-edit-' + m.id" @click="editer(m)">
                                {{ $t('label.edit') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script>
// [ONB-08 2026-08-28] `axios.defaults.baseURL` vaut déjà « <hôte>/api »
// (`shared/axios-setup.js:75`) : les URL s'écrivent SANS préfixe.
import axios from "axios";

/**
 * [ONB-08 2026-08-28] Déclarer ses matières premières.
 *
 * ═══ CET ÉCRAN N'EXISTAIT PAS ═══
 *
 * Le domaine matière première n'exposait que `movements` (lecture) et `adjust`
 * (correction de quantité). Les seules sources de création étaient un seeder et une
 * commande console : **un nouveau commerçant ne pouvait déclarer aucun ingrédient.**
 * Tout lui arrivait pré-rempli avec celui de Le Cayenne.
 *
 * ⚠️ Le commentaire de `RawMaterialAdjustComponent` (`stockRoutes.js:10-14`) affirme
 * être « la seule porte d'écriture manquante du domaine matière première ». C'était
 * faux : la déclaration en était une autre, et elle manquait depuis plus longtemps.
 *
 * ═══ CE QU'IL FERME AU PASSAGE ═══
 *
 * `threshold_low` n'avait aucun chemin d'écriture. Mesuré : 20/20 matières à NULL,
 * alors que le tableau de rupture et le listener d'alerte filtrent tous deux
 * `whereNotNull('threshold_low')` — donc 100 % des lignes exclues, et l'alerte de
 * stock bas structurellement muette.
 *
 * ═══ CE QU'IL NE FAIT PAS ═══
 *
 * Il ne touche PAS aux quantités. `adjust` reste la seule porte, avec sa traçabilité
 * par mouvement. Déclarer une matière et corriger un stock sont deux gestes
 * distincts, et les confondre ferait perdre la trace de l'un des deux.
 */
export default {
    name: "RawMaterialListComponent",
    data() {
        return {
            matieres: [],
            unitesAcceptees: [],
            chargement: true,
            occupe: false,
            erreurs: {},
            messageServeur: "",
            enEdition: null,
            form: { name: "", unit: "", threshold_low: "" },
        };
    },
    mounted() {
        this.charger();
    },
    methods: {
        async charger() {
            this.chargement = true;

            try {
                const { data } = await axios.get("admin/raw-materials");

                this.matieres = Array.isArray(data.data) ? data.data : [];
                this.unitesAcceptees = Array.isArray(data.unites_acceptees) ? data.unites_acceptees : [];

                if (!this.form.unit && this.unitesAcceptees.length) {
                    this.form.unit = this.unitesAcceptees[0];
                }
            } catch (erreur) {
                console.error("[matieres] chargement", erreur);
            } finally {
                this.chargement = false;
            }
        },

        editer(matiere) {
            this.enEdition = matiere.id;
            this.erreurs = {};
            this.messageServeur = "";
            this.form = {
                name: matiere.name,
                unit: matiere.unit,
                // Un seuil absent revient vide, pas à zéro : les confondre écrirait
                // « 0 » là où le commerçant n'avait rien demandé, et ferait sonner
                // une alerte qu'il n'a pas réglée.
                threshold_low: matiere.threshold_low === null ? "" : matiere.threshold_low,
            };
        },

        reinitialiser() {
            this.enEdition = null;
            this.erreurs = {};
            this.messageServeur = "";
            this.form = {
                name: "",
                unit: this.unitesAcceptees[0] || "",
                threshold_low: "",
            };
        },

        async enregistrer() {
            if (this.occupe) return;

            this.occupe = true;
            this.erreurs = {};
            this.messageServeur = "";

            const charge = {
                name: this.form.name,
                unit: this.form.unit,
                // Vide = « aucune alerte », et c'est `null` qu'il faut envoyer.
                // Envoyer 0 ferait sonner l'alerte au premier gramme manquant.
                threshold_low: this.form.threshold_low === "" ? null : this.form.threshold_low,
                is_active: true,
            };

            try {
                if (this.enEdition) {
                    await axios.put("admin/raw-materials/" + this.enEdition, charge);
                } else {
                    await axios.post("admin/raw-materials", charge);
                }

                this.reinitialiser();
                await this.charger();
            } catch (erreur) {
                const reponse = erreur?.response?.data;

                this.erreurs = reponse?.errors || {};
                // Le serveur refuse parfois pour une raison qui n'est pas un champ :
                // changer l'unité d'une matière qui a du stock, par exemple. Ce
                // message-là doit être lu, pas avalé.
                this.messageServeur = reponse?.message && !reponse?.errors ? reponse.message : "";
            } finally {
                this.occupe = false;
            }
        },
    },
};
</script>
