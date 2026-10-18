<template>
    <div class="col-12">
        <HygieneHubBar />
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title hy-title">Alertes d'hygiène par e-mail</h3>
            </div>

            <div class="db-card-body">
                <p v-if="forbidden" class="hy-alert" role="alert" data-testid="al-forbidden">
                    Ce réglage est réservé au responsable (droit « Paramètres »). Demandez-lui de le faire, ou de vous donner ce droit.
                </p>
                <p v-else-if="loadError" class="hy-alert" role="alert" data-testid="al-error">{{ loadError }}</p>
                <p v-else-if="!loaded" class="hy-muted" role="status">Chargement…</p>

                <template v-if="loaded">
                    <p class="hy-muted al-intro">
                        Les rappels « À traiter maintenant » de l'accueil (relevé en retard, nettoyage, huile à changer, DLC dépassées, rien tracé) peuvent
                        aussi partir <b>par e-mail</b>, même si personne n'ouvre l'application. Un seul e-mail récapitulatif par personne, relancé
                        au plus {{ form.max_sends }} fois, jamais en dehors des heures choisies. <b>Rien n'est envoyé tant que vous ne l'avez pas activé.</b>
                    </p>

                    <p v-if="!meta.mail_ready" class="hy-banner is-alert" role="alert" data-testid="al-mail-off">
                        Aucune messagerie n'est configurée sur ce serveur : les e-mails ne partiraient pas. Vous pouvez préparer les réglages, mais l'activation
                        sera refusée tant que le responsable technique n'a pas réglé l'envoi d'e-mails.
                    </p>

                    <ul class="hy-toggles al-block">
                        <li>
                            <button type="button" class="hy-toggle" :aria-pressed="form.enabled ? 'true' : 'false'" data-testid="al-enabled" @click="form.enabled = !form.enabled; touch()">
                                <span class="hy-box" aria-hidden="true">{{ form.enabled ? "✓" : "" }}</span>
                                <span><b>Envoyer les alertes par e-mail</b> <small class="hy-muted">{{ form.enabled ? "activé" : "désactivé" }}</small></span>
                            </button>
                        </li>
                    </ul>

                    <section class="al-block" aria-labelledby="al-who">
                        <h4 id="al-who" class="al-h">Qui est prévenu ?</h4>
                        <ul v-if="staff.length" class="hy-toggles" data-testid="al-staff">
                            <li v-for="p in staff" :key="p.id">
                                <button type="button" class="hy-toggle" :aria-pressed="has(p.email) ? 'true' : 'false'" :data-testid="`al-staff-${p.id}`" @click="toggleEmail(p.email)">
                                    <span class="hy-box" aria-hidden="true">{{ has(p.email) ? "✓" : "" }}</span>
                                    <span>{{ p.name }} <small class="hy-muted">{{ p.email }}</small></span>
                                </button>
                            </li>
                        </ul>
                        <p v-else class="hy-muted" data-testid="al-staff-empty">Aucun employé avec une adresse e-mail. Ajoutez une adresse ci-dessous.</p>

                        <ul v-if="extraRecipients.length" class="hy-list al-extra">
                            <li v-for="e in extraRecipients" :key="e" class="hy-row">
                                <span class="hy-row-name hy-row-name--wide">{{ e }}</span>
                                <button type="button" class="hy-btn hy-btn--small" :aria-label="`Retirer ${e}`" @click="toggleEmail(e)">Retirer</button>
                            </li>
                        </ul>
                        <div class="al-add">
                            <label class="hy-field al-grow">
                                <span class="hy-label">Autre adresse e-mail</span>
                                <input v-model.trim="newEmail" type="email" inputmode="email" autocomplete="off" class="hy-input" placeholder="responsable@exemple.fr"
                                       data-testid="al-new-email" @keydown.enter.prevent="addEmail" />
                            </label>
                            <button type="button" class="hy-btn" data-testid="al-add" :disabled="!newEmail" @click="addEmail">Ajouter</button>
                        </div>
                        <p v-if="emailError" class="hy-alert" role="alert" data-testid="al-email-error">{{ emailError }}</p>
                    </section>

                    <section class="al-block" aria-labelledby="al-what">
                        <h4 id="al-what" class="al-h">Pour quoi ?</h4>
                        <ul class="hy-toggles" data-testid="al-domains">
                            <li v-for="d in meta.domains" :key="d.key">
                                <button type="button" class="hy-toggle" :aria-pressed="form.domains.includes(d.key) ? 'true' : 'false'" :data-testid="`al-domain-${d.key}`" @click="toggleDomain(d.key)">
                                    <span class="hy-box" aria-hidden="true">{{ form.domains.includes(d.key) ? "✓" : "" }}</span>
                                    <span>{{ d.label }}</span>
                                </button>
                            </li>
                        </ul>
                        <p class="hy-muted">Seuls les rappels urgents (« alerte ») partent par e-mail. Les rappels « à faire sans urgence » restent sur l'écran.</p>
                    </section>

                    <section class="al-block" aria-labelledby="al-when">
                        <h4 id="al-when" class="al-h">Quand ?</h4>
                        <div class="al-row">
                            <label class="hy-field"><span class="hy-label">Pas avant</span>
                                <input v-model="form.send_from" type="time" class="hy-input" data-testid="al-from" @input="touch" /></label>
                            <label class="hy-field"><span class="hy-label">Pas après</span>
                                <input v-model="form.send_until" type="time" class="hy-input" data-testid="al-until" @input="touch" /></label>
                            <label class="hy-field"><span class="hy-label">Relancer toutes les</span>
                                <select v-model.number="form.repeat_minutes" class="hy-select" data-testid="al-repeat" @change="touch">
                                    <option v-for="m in [30, 60, 90, 120, 180]" :key="m" :value="m">{{ m }} minutes</option>
                                </select></label>
                            <label class="hy-field"><span class="hy-label">Nombre d'envois maximum par rappel et par jour</span>
                                <select v-model.number="form.max_sends" class="hy-select" data-testid="al-max" @change="touch">
                                    <option v-for="n in [1, 2, 3, 4, 5]" :key="n" :value="n">{{ n }}</option>
                                </select></label>
                        </div>
                    </section>

                    <p v-if="message" class="hy-banner" :class="messageOk ? 'is-ok' : 'is-alert'" role="status" data-testid="al-message">{{ message }}</p>

                    <div class="hy-bar">
                        <span class="hy-muted">{{ dirty ? "Modifications non enregistrées" : "Réglages enregistrés" }}</span>
                        <span class="al-actions">
                            <button type="button" class="hy-btn" :disabled="busy || dirty || !saved.recipients.length" data-testid="al-test" :title="dirty ? 'Enregistrez d\'abord' : ''" @click="sendTest">
                                {{ busy === "test" ? "Envoi…" : "Envoyer un e-mail d'essai" }}
                            </button>
                            <button type="button" class="hy-btn hy-btn--primary" :disabled="busy || !dirty" data-testid="al-save" @click="save">
                                {{ busy === "save" ? "Enregistrement…" : "Enregistrer" }}
                            </button>
                        </span>
                    </div>

                    <section class="al-block" aria-labelledby="al-log">
                        <h4 id="al-log" class="al-h">Derniers envois</h4>
                        <p v-if="!recent.length" class="hy-muted" data-testid="al-log-empty">Rien n'a encore été envoyé.</p>
                        <ul v-else class="hy-list" data-testid="al-log">
                            <li v-for="r in recent" :key="r.id" class="hy-row" :class="{ 'is-alert': r.status === 'failed' }" data-testid="al-log-row">
                                <span class="hy-row-when">{{ when(r.at) }}</span>
                                <span class="hy-tag" :class="{ 'is-late': r.status === 'failed' }">{{ statusLabel(r.status) }}</span>
                                <span class="hy-row-name">{{ r.recipient }}</span>
                                <span class="hy-sub">{{ r.text }}<template v-if="r.error"> — {{ r.error }}</template></span>
                            </li>
                        </ul>
                    </section>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0 « alertes hors écran »] Réglage des alertes e-mail : qui est prévenu, pour quoi, quand.
 * Le serveur décide (permission « settings », validation, messagerie réelle) ; cet écran dit la vérité sur ce qui part et laisse
 * un journal. Rien n'est actif par défaut.
 */
import HygieneHubBar from "./HygieneHubBar";
import axios from "axios";
import alertService from "../../../services/alertService";

const DEFAULT_FORM = () => ({ enabled: false, recipients: [], domains: [], send_from: "09:00", send_until: "23:30", repeat_minutes: 60, max_sends: 3 });

export default {
    name: "HygieneAlertsComponent",
    components: { HygieneHubBar },
    data() {
        return {
            loaded: false, loadError: "", forbidden: false, busy: "",
            form: DEFAULT_FORM(), saved: DEFAULT_FORM(), staff: [], recent: [], meta: { mail_ready: true, domains: [], timezone: "Europe/Paris" },
            newEmail: "", emailError: "", message: "", messageOk: true, dirtyFlag: false,
        };
    },
    computed: {
        dirty() { return this.dirtyFlag; },
        staffEmails() { return this.staff.map((p) => p.email.toLowerCase()); },
        extraRecipients() { return this.form.recipients.filter((e) => !this.staffEmails.includes(e)); },
    },
    mounted() { this.load(); },
    methods: {
        touch() { this.dirtyFlag = true; this.message = ""; },
        has(email) { return this.form.recipients.includes(String(email).toLowerCase()); },
        toggleEmail(email) {
            const e = String(email).toLowerCase();
            this.emailError = "";
            if (!this.has(e) && this.form.recipients.length >= 10) { this.emailError = "Dix destinataires au plus : retirez-en un avant d'en ajouter."; return; }
            this.form.recipients = this.has(e) ? this.form.recipients.filter((x) => x !== e) : [...this.form.recipients, e];
            this.touch();
        },
        toggleDomain(key) {
            this.form.domains = this.form.domains.includes(key) ? this.form.domains.filter((x) => x !== key) : [...this.form.domains, key];
            this.touch();
        },
        addEmail() {
            const e = this.newEmail.toLowerCase();
            this.emailError = "";
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) { this.emailError = "Cette adresse e-mail n'est pas valide."; return; }
            if (this.form.recipients.length >= 10) { this.emailError = "Dix destinataires au plus."; return; }
            if (this.has(e)) { this.emailError = "Cette adresse est déjà dans la liste."; return; }
            this.form.recipients = [...this.form.recipients, e];
            this.touch();
            this.newEmail = "";
        },
        when(iso) {
            try { return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit", timeZone: this.meta.timezone || "Europe/Paris" }).format(new Date(iso)); } catch (_) { return ""; }
        },
        statusLabel(s) { return { sent: "Envoyé", failed: "Échec", test: "Essai" }[s] || s; },
        adopt(settings) {
            const s = { ...DEFAULT_FORM(), ...settings };
            this.saved = JSON.parse(JSON.stringify(s));
            this.form = JSON.parse(JSON.stringify(s));
            this.dirtyFlag = false;
        },
        async load() {
            try {
                const { data } = await axios.get("admin/hygiene/alerts");
                this.meta = { ...this.meta, ...(data.meta || {}) };
                this.staff = (data.data.staff || []).map((p) => ({ ...p, email: String(p.email) }));
                this.recent = data.data.recent || [];
                this.adopt(data.data.settings);
                this.loadError = "";
                this.loaded = true;
            } catch (e) {
                if (e && e.response && e.response.status === 403) this.forbidden = true;
                else this.loadError = "Les réglages d'alertes sont indisponibles. Vérifiez la connexion puis rechargez la page.";
            }
        },
        errorText(e, fallback) {
            const d = e && e.response && e.response.data;
            if (d && d.errors) {
                const first = Object.values(d.errors)[0];
                if (Array.isArray(first) && first[0]) return first[0];
            }
            return (d && d.message) || fallback;
        },
        async save() {
            if (this.busy) return;
            this.busy = "save";
            this.message = "";
            try {
                const { data } = await axios.put("admin/hygiene/alerts", { ...this.form });
                this.adopt(data.data.settings);
                this.messageOk = true;
                this.message = this.saved.enabled ? "Enregistré : les alertes sont actives." : "Enregistré : les alertes sont désactivées.";
                alertService.success("Alertes enregistrées.");
            } catch (e) {
                this.messageOk = false;
                this.message = this.errorText(e, "Enregistrement impossible : vérifiez la connexion puis réessayez.");
            } finally {
                this.busy = "";
            }
        },
        async sendTest() {
            if (this.busy || this.dirty) return;
            this.busy = "test";
            this.message = "";
            try {
                const { data } = await axios.post("admin/hygiene/alerts/test");
                this.messageOk = true;
                this.message = `E-mail d'essai envoyé à ${data.data.sent} adresse${data.data.sent > 1 ? "s" : ""}. Vérifiez qu'il est bien arrivé (et pas dans les courriers indésirables).`;
            } catch (e) {
                this.messageOk = false;
                this.message = this.errorText(e, "L'e-mail d'essai n'a pas pu partir : la messagerie du serveur a refusé l'envoi. Le détail est dans « Derniers envois » ci-dessous.");
            } finally {
                this.busy = "";
                try { const { data } = await axios.get("admin/hygiene/alerts"); this.recent = data.data.recent || []; } catch (_) { /* le journal se rafraîchira au prochain chargement */ }
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
<style scoped>
.al-intro { margin: 0 0 12px; line-height: 1.5; }
.al-block { margin-top: 18px; }
.al-h { font-size: 17px; font-weight: 800; margin: 0 0 8px; color: #111827; }
.al-add { display: flex; gap: 10px; align-items: flex-end; margin-top: 10px; flex-wrap: wrap; }
.al-grow { flex: 1 1 260px; }
.al-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
.al-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.al-extra { margin-top: 8px; }
</style>
