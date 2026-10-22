<template>
    <div class="hy-operator" data-testid="hy-operator" @keydown.esc="closeAll">
        <button type="button" class="hy-chip hy-chip--btn" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="listbox" data-testid="hy-operator-btn" @click="toggle">
            Qui ? <strong>{{ currentLabel }}</strong> ▾
        </button>
        <p v-if="expiredNote" class="hy-operator-note" role="status" data-testid="hy-operator-expired">{{ expiredNote }}</p>
        <div v-if="open && pinTarget" class="hy-operator-pop hy-pin" :class="`is-${side}`" role="dialog" :aria-label="`Code de ${pinTarget.short}`" data-testid="hy-pin">
            <p class="hy-pin-title"><strong>Code de {{ pinTarget.short }}</strong></p>
            <input ref="pinInput" v-model="pinValue" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                   class="hy-input hy-pin-input" placeholder="••••" :aria-label="`Code de ${pinTarget.short}`" data-testid="hy-pin-input" @keydown.enter.prevent="submitPin" />
            <p v-if="pinError" class="hy-alert" role="alert" data-testid="hy-pin-error">{{ pinError }}</p>
            <div class="hy-pin-actions">
                <button type="button" class="hy-btn hy-btn--small" data-testid="hy-pin-cancel" @click="cancelPin">Annuler</button>
                <button type="button" class="hy-btn hy-btn--primary hy-btn--small" :disabled="pinBusy || !/^\d{4,6}$/.test(pinValue)" data-testid="hy-pin-ok" @click="submitPin">
                    {{ pinBusy ? "Vérification…" : "Valider" }}
                </button>
            </div>
        </div>
        <div v-else-if="open" class="hy-operator-pop" :class="`is-${side}`" role="listbox" aria-label="Qui fait ce relevé ?" data-testid="hy-operator-list">
            <button v-for="p in people" :key="p.id" type="button" role="option" :aria-selected="p.id === chosenId ? 'true' : 'false'"
                    :class="{ 'is-on': p.id === chosenId }" :data-testid="`hy-operator-${p.id}`" @click="choose(p)">
                {{ p.name }}<template v-if="p.is_me"> (moi)</template><template v-if="p.has_pin && !p.is_me"> <span aria-label="protégé par un code" role="img">🔒</span></template>
            </button>
            <!-- jamais une liste vide muette : on dit pourquoi, ce qui se passe en attendant, et où l'ajouter -->
            <div v-if="!people.length && loadFailed" class="hy-operator-empty" data-testid="hy-operator-failed">
                <p class="hy-muted">Liste indisponible : vérifiez la connexion, puis rouvrez ce menu.</p>
            </div>
            <div v-else-if="!people.length" class="hy-operator-empty" data-testid="hy-operator-empty">
                <p class="hy-muted">Aucun employé enregistré. En attendant, chaque geste est attribué au compte connecté.</p>
                <router-link to="/admin/employees" class="hy-operator-add" data-testid="hy-operator-add">＋ Ajouter un employé</router-link>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17] « Qui fait ce geste ? » : tablette partagée, on désigne la personne. Par défaut : l'utilisateur
 * connecté. Le choix est mémorisé sur la tablette (confort), mais c'est le SERVEUR qui vérifie que la personne fait partie du
 * personnel. Émet `update:modelValue` : l'identifiant choisi, ou null pour « moi » (le serveur prend alors l'utilisateur connecté).
 */
import axios from "axios";
import { loadedBody } from "../../../helpers/hygieneCommon";

const STORE_KEY = "hygiene.operator";
/** Combien de temps la tablette se souvient de la personne choisie (hors code : un nom protégé n'est jamais mémorisé). */
const REMEMBER_MS = 30 * 60 * 1000;

export default {
    name: "HygieneOperatorPicker",
    props: { modelValue: { type: [Number, null], default: null }, token: { type: [String, null], default: null } },
    emits: ["update:modelValue", "update:token", "update:me"],
    data() {
        return { people: [], me: null, open: false, chosenId: null, loadFailed: false, pinTarget: null, pinValue: "", pinError: "", pinBusy: false, expiredNote: "", expiryTimer: null, side: "left" };
    },
    computed: {
        currentLabel() {
            const p = this.people.find((x) => x.id === this.chosenId);
            // liste vide (aucun employé enregistré) : le geste est attribué au compte connecté — le dire plutôt qu'afficher « … »
            return p ? p.short : (this.chosenId !== null && this.chosenId === this.me ? "moi" : "…");
        },
    },
    async created() {
        await this.loadPeople();
    },
    beforeUnmount() { this.clearExpiry(); },
    methods: {
        /** Charge la liste ; rappelée à l'ouverture du menu tant qu'elle a échoué (le message promet « rouvrez ce menu »). */
        async loadPeople() {
            try {
                const data = loadedBody(await axios.get("admin/hygiene/operators")); // [VG-2026-10-21 M8-103] une réponse sans la forme attendue = liste en échec, pas « personne »
                this.people = data.data || [];
                this.me = data.meta ? data.meta.me : null;
                this.loadFailed = false;
            } catch (_) {
                this.people = [];
                this.loadFailed = true;
                return;
            }
            let saved = null;
            // le choix est mémorisé PAR utilisateur connecté : quand Bob se connecte, il n'hérite pas du choix d'Alice
            // …et il PÉRIME (30 min) : celui qui a choisi son nom ce matin ne signe pas, par oubli, les étiquettes de l'après-midi
            try {
                const raw = JSON.parse(window.localStorage.getItem(this.storeKey()) || "null");
                if (raw && typeof raw === "object" && Number.isFinite(raw.at) && Date.now() - raw.at < REMEMBER_MS) saved = Number(raw.id) || null;
            } catch (_) { /* stockage indisponible ou ancien format : on retombe sur « moi » */ }
            // une personne protégée par un code n'est JAMAIS rétablie toute seule : le code n'est pas gardé sur la tablette (et le jeton est en mémoire)
            const known = (id) => this.people.some((p) => p.id === id && !p.has_pin);
            this.chosenId = known(this.modelValue) ? this.modelValue : (known(saved) ? saved : this.me);
            this.$emit("update:modelValue", this.chosenId === this.me ? null : this.chosenId);
            this.$emit("update:token", null);
            this.$emit("update:me", this.me); // les écrans qui gardent une saisie hors ligne écrivent la personne en toutes lettres
        },
        /** Ferme le menu ET efface tout code à moitié tapé : un collègue qui touche la pastille ensuite ne retrouve jamais le code d'un autre. */
        closeAll() { this.open = false; this.cancelPin(); },
        storeKey() { return `${STORE_KEY}.${this.me}`; },
        toggle() {
            if (this.open) { this.closeAll(); return; }
            this.open = true;
            this.placePopover();
            if (this.loadFailed) this.loadPeople();
        },
        /** Ouvre la fenêtre du côté où il y a de la place : le bouton peut être au bord gauche (températures) ou droit (huile), jamais coupée. */
        placePopover() {
            try {
                const r = this.$el.getBoundingClientRect();
                this.side = r.left + 340 > window.innerWidth - 16 && r.right - 340 > 0 ? "right" : "left";
            } catch (_) { this.side = "left"; }
        },
        clearExpiry() { if (this.expiryTimer) { clearTimeout(this.expiryTimer); this.expiryTimer = null; } },
        choose(p) {
            this.expiredNote = "";
            // une personne avec un code : on le demande (sauf SOI-MÊME : la connexion est déjà la preuve)
            if (p.has_pin && p.id !== this.me) {
                this.pinTarget = p; this.pinValue = ""; this.pinError = "";
                this.$nextTick(() => { if (this.$refs.pinInput) this.$refs.pinInput.focus(); });
                return;
            }
            this.applyChoice(p, null, null);
        },
        applyChoice(p, token, expiresAt) {
            this.clearExpiry();
            this.chosenId = p.id;
            this.open = false;
            this.pinTarget = null; this.pinValue = ""; this.pinError = "";
            // le choix d'une personne SANS code est mémorisé (confort) ; celui d'une personne AVEC code ne l'est jamais
            if (!token) { try { window.localStorage.setItem(this.storeKey(), JSON.stringify({ id: p.id, at: Date.now() })); } catch (_) { /* confort seulement */ } }
            this.$emit("update:modelValue", p.id === this.me ? null : p.id);
            this.$emit("update:token", token || null);
            if (token && expiresAt) {
                const ms = Math.max(1000, Date.parse(expiresAt) - Date.now());
                // le code expire : la personne reste affichée mais sans jeton, donc la prochaine écriture est REFUSÉE avec un message clair
                // (retomber sur « moi » en silence ferait signer du compte partagé sans que personne le voie)
                this.expiryTimer = setTimeout(() => {
                    this.$emit("update:token", null);
                    this.expiredNote = `Le code de ${p.short} a expiré : touchez « Qui ? » et saisissez-le de nouveau pour signer.`;
                }, ms);
            }
        },
        cancelPin() { this.pinTarget = null; this.pinValue = ""; this.pinError = ""; },
        async submitPin() {
            if (this.pinBusy || !this.pinTarget || !/^\d{4,6}$/.test(this.pinValue)) return;
            const target = this.pinTarget; // figée : si on change de personne pendant l'attente, la réponse de l'une ne s'applique pas à l'autre
            this.pinBusy = true;
            this.pinError = "";
            try {
                const { data } = await axios.post("admin/hygiene/operators/verify", { user_id: target.id, pin: this.pinValue });
                if (this.pinTarget !== target) return;
                const d = data.data || {};
                this.applyChoice(target, d.token || null, d.expires_at || null);
            } catch (e) {
                if (this.pinTarget !== target) return;
                const r = e && e.response;
                this.pinValue = "";
                if (!r) this.pinError = "Vérification impossible : pas de réseau. Réessayez quand la connexion revient.";
                else this.pinError = (r.data && r.data.errors && r.data.errors.pin && r.data.errors.pin[0]) || (r.data && r.data.message) || "Code non vérifié : réessayez.";
            } finally {
                this.pinBusy = false;
            }
        },
    },
};
</script>

<style src="./hygiene.css"></style>
