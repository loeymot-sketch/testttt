<template>
    <LoadingComponent :props="loading" />
    <div id="horaires" class="db-card db-tab-div active" data-testid="admin-horaires">
        <div class="db-card-header">
            <h3 class="db-card-title normal-case">Horaires d'ouverture</h3>
        </div>
        <div class="db-card-body">
            <!-- [HORAIRES 2026-11-05] Une seule source : commandes programmées, tournées de livraison, « ouvert / fermé » du
                 site et de l'application. Aucune mise à jour de l'application n'est nécessaire. -->
            <p class="text-sm text-gray-600 mb-4">
                Ces horaires s'appliquent tout de suite au site et à l'application (ouvert / fermé, heure d'ouverture affichée,
                commandes programmées et tournées de livraison). Une fermeture après minuit (par exemple 18:00 → 00:30) compte
                pour le jour où le service a commencé.
            </p>

            <form @submit.prevent="save">
                <fieldset class="p-4 mb-6 border border-[#DBDEE0]">
                    <legend class="py-1.5 px-4 text-base font-semibold border border-[#DBDEE0] text-primary">Semaine</legend>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500">
                                    <th class="py-2 pr-4">Jour</th>
                                    <th class="py-2 pr-4">Ouvert</th>
                                    <th class="py-2 pr-4">Ouverture</th>
                                    <th class="py-2 pr-4">Fermeture</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="iso in jours" :key="iso" class="border-t border-[#EFF0F6]">
                                    <td class="py-2 pr-4 font-semibold capitalize">{{ noms[iso] }}</td>
                                    <td class="py-2 pr-4">
                                        <label class="inline-flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" v-model="form.semaine[iso].ouvert" :id="'horaires-ouvert-' + iso"
                                                   :aria-label="'Ouvert le ' + noms[iso]" />
                                            <span>{{ form.semaine[iso].ouvert ? 'Ouvert' : 'Fermé' }}</span>
                                        </label>
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input type="time" step="300" class="db-field-control" v-model="form.semaine[iso].ouverture"
                                               :disabled="!form.semaine[iso].ouvert" :aria-label="'Ouverture le ' + noms[iso]"
                                               :class="erreur('semaine.' + iso + '.ouverture') ? 'invalid' : ''" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <input type="time" step="300" class="db-field-control" v-model="form.semaine[iso].fermeture"
                                               :disabled="!form.semaine[iso].ouvert" :aria-label="'Fermeture le ' + noms[iso]"
                                               :class="erreur('semaine.' + iso + '.fermeture') ? 'invalid' : ''" />
                                        <small class="db-field-alert" v-if="erreur('semaine.' + iso + '.fermeture')">{{ erreur('semaine.' + iso + '.fermeture') }}</small>
                                        <small class="text-xs text-gray-400 block" v-else-if="form.semaine[iso].ouvert && passeMinuit(iso)">le lendemain</small>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="db-btn mt-3 normal-case text-primary bg-primary/5" @click="copierLundi">
                        <i class="lab lab-time-slots"></i>
                        <span>Appliquer les horaires du lundi à tous les jours</span>
                    </button>
                </fieldset>

                <fieldset class="p-4 mb-6 border border-[#DBDEE0]">
                    <legend class="py-1.5 px-4 text-base font-semibold border border-[#DBDEE0] text-primary">Fermetures exceptionnelles</legend>
                    <p class="text-xs text-gray-500 mb-3">
                        Congés, jour férié, inventaire… Ces jours-là, le site et l'application affichent « Fermé » avec votre message,
                        et aucune commande ne peut être passée ni programmée.
                    </p>
                    <p v-if="!form.fermetures.length" class="text-sm text-gray-400 mb-3">Aucune fermeture prévue.</p>
                    <div v-for="(f, i) in form.fermetures" :key="i" class="form-row items-end mb-2" data-testid="horaires-fermeture">
                        <div class="form-col-12 sm:form-col-3">
                            <label class="db-field-title" :for="'fermeture-du-' + i">Du</label>
                            <input type="date" class="db-field-control" v-model="f.du" :id="'fermeture-du-' + i"
                                   :class="erreur('fermetures.' + i + '.du') ? 'invalid' : ''" />
                        </div>
                        <div class="form-col-12 sm:form-col-3">
                            <label class="db-field-title" :for="'fermeture-au-' + i">Au (inclus)</label>
                            <input type="date" class="db-field-control" v-model="f.au" :id="'fermeture-au-' + i"
                                   :class="erreur('fermetures.' + i + '.au') ? 'invalid' : ''" />
                            <small class="db-field-alert" v-if="erreur('fermetures.' + i + '.au')">{{ erreur('fermetures.' + i + '.au') }}</small>
                        </div>
                        <div class="form-col-12 sm:form-col-5">
                            <label class="db-field-title" :for="'fermeture-message-' + i">Message aux clients</label>
                            <input type="text" maxlength="160" class="db-field-control" v-model="f.message" :id="'fermeture-message-' + i"
                                   placeholder="Ex. : Fermé pour congés, réouverture lundi" />
                        </div>
                        <div class="form-col-12 sm:form-col-1">
                            <button type="button" class="db-btn text-red-600 bg-red-50" @click="form.fermetures.splice(i, 1)"
                                    :aria-label="'Supprimer la fermeture ' + (i + 1)">
                                <i class="lab lab-trash"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="db-btn mt-2 normal-case text-primary bg-primary/5" @click="ajouterFermeture" data-testid="horaires-ajouter-fermeture">
                        <i class="lab lab-add-circle-line"></i>
                        <span>Ajouter une fermeture</span>
                    </button>
                </fieldset>

                <div class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200" v-if="apercu">
                    <p class="text-sm text-blue-700 font-medium">Ce que voient les clients (après enregistrement)</p>
                    <p class="text-xs text-blue-600 mt-1">{{ apercu }}</p>
                </div>

                <!-- [formulaire-admin-echec-muet] L'erreur s'affiche AUSSI à côté du bouton : le haut de la page est loin. -->
                <p v-if="messageErreur" class="db-field-alert mb-3" role="alert">{{ messageErreur }}</p>
                <button type="submit" class="db-btn text-white bg-primary" data-testid="horaires-enregistrer">
                    <i class="lab lab-save"></i>
                    <span>Enregistrer</span>
                </button>
            </form>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import LoadingComponent from "../../components/LoadingComponent";
import alertService from "../../../../services/alertService";

const NOMS = { 1: "lundi", 2: "mardi", 3: "mercredi", 4: "jeudi", 5: "vendredi", 6: "samedi", 7: "dimanche" };

export default {
    name: "HorairesComponent",
    components: { LoadingComponent },
    data() {
        const semaine = {};
        Object.keys(NOMS).forEach((iso) => { semaine[iso] = { ouvert: true, ouverture: "18:00", fermeture: "00:30" }; });
        return {
            loading: { isActive: false },
            jours: [1, 2, 3, 4, 5, 6, 7],
            noms: NOMS,
            form: { semaine, fermetures: [] },
            errors: {},
            messageErreur: "",
            apercu: "",
        };
    },
    mounted() {
        this.charger();
    },
    methods: {
        charger() {
            this.loading.isActive = true;
            axios.get("admin/setting/horaires").then((res) => {
                this.remplir(res.data.data);
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
                this.messageErreur = "Impossible de lire les horaires. Rechargez la page.";
            });
        },
        remplir(d) {
            const semaine = {};
            (d.semaine || []).forEach((j) => {
                semaine[j.iso] = { ouvert: !!j.ouvert, ouverture: j.ouverture, fermeture: j.fermeture };
            });
            Object.keys(NOMS).forEach((iso) => { if (!semaine[iso]) semaine[iso] = { ouvert: true, ouverture: "18:00", fermeture: "00:30" }; });
            this.form = {
                semaine,
                fermetures: (d.fermetures || []).map((f) => ({ du: f.du, au: f.au, message: f.message || "" })),
            };
            const fr = (iso) => { const [a, m, j] = String(iso).split("-"); return j + "/" + m + "/" + a; };
            const prochaines = (d.fermetures_a_venir || []).map((f) => (f.du === f.au ? "fermé le " + fr(f.du) : "fermé du " + fr(f.du) + " au " + fr(f.au)) + (f.message ? " (« " + f.message + " »)" : ""));
            const maintenant = d.ouvert ? "ouvert en ce moment"
                : (d.prochaine_ouverture ? "fermé en ce moment — réouverture " + d.prochaine_ouverture.jour + " à " + d.prochaine_ouverture.heure : "fermé en ce moment");
            this.apercu = d.resume ? [d.resume, maintenant].concat(prochaines).join(" · ") : "";
        },
        passeMinuit(iso) {
            const j = this.form.semaine[iso];
            return j.fermeture && j.ouverture && j.fermeture <= j.ouverture;
        },
        copierLundi() {
            const l = this.form.semaine[1];
            this.jours.forEach((iso) => { this.form.semaine[iso] = { ouvert: l.ouvert, ouverture: l.ouverture, fermeture: l.fermeture }; });
        },
        ajouterFermeture() {
            const aujourdhui = new Date().toISOString().slice(0, 10);
            this.form.fermetures.push({ du: aujourdhui, au: aujourdhui, message: "" });
        },
        erreur(cle) {
            const e = this.errors && this.errors[cle];
            return e && e.length ? e[0] : "";
        },
        save() {
            this.loading.isActive = true;
            this.messageErreur = "";
            const fermetures = this.form.fermetures.filter((f) => f.du).map((f) => ({ du: f.du, au: f.au || f.du, message: f.message || "" }));
            axios.put("/admin/setting/horaires", { semaine: this.form.semaine, fermetures }).then((res) => {
                this.loading.isActive = false;
                this.errors = {};
                this.remplir(res.data.data);
                alertService.successFlip(1, "Horaires");
            }).catch((err) => {
                this.loading.isActive = false;
                this.errors = (err.response && err.response.data && err.response.data.errors) || {};
                this.messageErreur = (err.response && err.response.data && err.response.data.message)
                    || "Les horaires n'ont pas pu être enregistrés.";
            });
        },
    },
};
</script>
