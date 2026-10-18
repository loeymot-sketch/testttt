<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Codes de l'équipe</h3>
            </div>

            <div class="db-card-body">
                <p v-if="forbidden" class="hy-alert" role="alert" data-testid="tm-forbidden">
                    Ce réglage est réservé au responsable (droit « Paramètres »). Demandez-lui de le faire, ou de vous donner ce droit.
                </p>
                <p v-else-if="loadError" class="hy-alert" role="alert" data-testid="tm-error">{{ loadError }}</p>
                <p v-else-if="!loaded" class="hy-muted" role="status">Chargement…</p>

                <template v-if="loaded">
                    <p class="hy-muted tm-intro">
                        Sur la tablette partagée, chacun choisit son nom (« Qui ? ») avant de valider un relevé. Avec un <b>code personnel de 4 à 6 chiffres</b>,
                        personne ne peut signer au nom d'un collègue : le code est demandé <b>une fois</b> quand on choisit un nom protégé, puis la signature reste valable
                        {{ tokenMinutes }} minutes (le code n'est jamais gardé sur la tablette). Une personne <b>sans code</b> reste sélectionnable librement.
                    </p>

                    <p class="hy-banner" :class="meta.without_pin ? 'is-todo' : 'is-ok'" role="status" data-testid="tm-summary">
                        {{ meta.without_pin ? `${meta.without_pin} employé${meta.without_pin > 1 ? "s" : ""} sans code : n'importe qui peut signer à leur nom.` : "Tout le monde a un code." }}
                    </p>

                    <ul class="hy-list" data-testid="tm-list">
                        <li v-for="u in people" :key="u.id" class="hy-row" :class="{ 'is-alert': u.locked }" :data-testid="`tm-row-${u.id}`">
                            <span class="hy-row-name hy-row-name--wide">{{ u.name }}</span>
                            <span class="hy-tag" :class="{ 'is-late': u.locked || !u.has_pin }" :data-testid="`tm-state-${u.id}`">
                                {{ u.locked ? "Bloqué (trop d'essais)" : u.has_pin ? "Code défini" : "Sans code" }}
                            </span>
                            <span v-if="u.has_pin && u.pin_set_at" class="hy-sub">le {{ when(u.pin_set_at) }}</span>

                            <template v-if="editing === u.id">
                                <input ref="pinInput" v-model="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="new-password"
                                       class="hy-input tm-pin" placeholder="4 à 6 chiffres" :aria-label="`Nouveau code de ${u.name}`" :data-testid="`tm-input-${u.id}`"
                                       @keydown.enter.prevent="save(u)" />
                                <button type="button" class="hy-btn hy-btn--primary hy-btn--small" :disabled="busy || !validPin" :data-testid="`tm-save-${u.id}`" @click="save(u)">Enregistrer</button>
                                <button type="button" class="hy-btn hy-btn--small" :data-testid="`tm-cancel-${u.id}`" @click="cancel">Annuler</button>
                            </template>
                            <template v-else-if="removing === u.id">
                                <span class="hy-sub">Retirer le code de {{ u.name }} ?</span>
                                <button type="button" class="hy-btn hy-btn--danger hy-btn--small" :disabled="busy" :data-testid="`tm-remove-yes-${u.id}`" @click="remove(u)">Oui, retirer</button>
                                <button type="button" class="hy-btn hy-btn--small" :data-testid="`tm-remove-no-${u.id}`" @click="removing = null">Non</button>
                            </template>
                            <template v-else>
                                <button type="button" class="hy-btn hy-btn--small" :data-testid="`tm-set-${u.id}`" @click="startEdit(u)">{{ u.has_pin ? "Changer le code" : "Définir un code" }}</button>
                                <button v-if="u.has_pin" type="button" class="hy-btn hy-btn--small" :data-testid="`tm-remove-${u.id}`" @click="removing = u.id">Retirer</button>
                            </template>
                        </li>
                    </ul>
                    <p v-if="!people.length" class="hy-muted" data-testid="tm-empty">Aucun employé enregistré. Ajoutez-les dans « Employés » : ils apparaîtront ici.</p>

                    <p v-if="message" class="hy-banner" :class="messageOk ? 'is-ok' : 'is-alert'" role="status" data-testid="tm-message">{{ message }}</p>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « Qui ? sans code »] Le responsable donne, change ou retire le code personnel de chaque employé.
 * Le serveur décide (permission « settings », format, personnel du restaurant) ; le code n'est jamais relu ni affiché.
 */
import axios from "axios";
import alertService from "../../../services/alertService";

export default {
    name: "HygieneTeamComponent",
    data() {
        return { loaded: false, loadError: "", forbidden: false, people: [], meta: { without_pin: 0, timezone: "Europe/Paris" }, editing: null, removing: null, pin: "", busy: false, message: "", messageOk: true };
    },
    computed: {
        validPin() { return /^\d{4,6}$/.test(this.pin); },
        tokenMinutes() { return 10; },
    },
    mounted() { this.load(); },
    methods: {
        when(iso) {
            try { return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit", timeZone: this.meta.timezone || "Europe/Paris" }).format(new Date(iso)); } catch (_) { return ""; }
        },
        async load() {
            try {
                const { data } = await axios.get("admin/hygiene/team");
                this.people = data.data || [];
                this.meta = { ...this.meta, ...(data.meta || {}) };
                this.loadError = "";
                this.loaded = true;
            } catch (e) {
                if (e && e.response && e.response.status === 403) this.forbidden = true;
                else this.loadError = "La liste de l'équipe est indisponible. Vérifiez la connexion puis rechargez la page.";
            }
        },
        startEdit(u) {
            this.editing = u.id; this.removing = null; this.pin = ""; this.message = "";
            this.$nextTick(() => { const el = this.$refs.pinInput; const input = Array.isArray(el) ? el[0] : el; if (input) input.focus(); });
        },
        cancel() { this.editing = null; this.pin = ""; },
        errorText(e, fallback) {
            const d = e && e.response && e.response.data;
            if (d && d.errors) { const first = Object.values(d.errors)[0]; if (Array.isArray(first) && first[0]) return first[0]; }
            return (d && d.message) || fallback;
        },
        async save(u) {
            if (this.busy || !this.validPin) return;
            this.busy = true;
            this.message = "";
            try {
                await axios.put(`admin/hygiene/team/${u.id}/pin`, { pin: this.pin });
                this.editing = null; this.pin = "";
                this.messageOk = true;
                this.message = `Code de ${u.name} enregistré. Communiquez-le à la personne : il ne s'affichera plus jamais.`;
                alertService.success("Code enregistré.");
                await this.load();
            } catch (e) {
                this.messageOk = false;
                this.message = this.errorText(e, "Enregistrement impossible : vérifiez la connexion puis réessayez.");
            } finally { this.busy = false; }
        },
        async remove(u) {
            if (this.busy) return;
            this.busy = true;
            this.message = "";
            try {
                await axios.delete(`admin/hygiene/team/${u.id}/pin`);
                this.removing = null;
                this.messageOk = true;
                this.message = `Code de ${u.name} retiré : tout le monde peut de nouveau signer à son nom.`;
                await this.load();
            } catch (e) {
                this.messageOk = false;
                this.message = this.errorText(e, "Retrait impossible : vérifiez la connexion puis réessayez.");
            } finally { this.busy = false; }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
<style scoped>
.tm-intro { margin: 0 0 12px; line-height: 1.5; }
.tm-pin { max-width: 190px; font-size: 20px; letter-spacing: .2em; }
</style>
