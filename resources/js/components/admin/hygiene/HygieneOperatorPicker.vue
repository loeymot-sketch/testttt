<template>
    <div class="hy-operator" data-testid="hy-operator">
        <button type="button" class="hy-chip hy-chip--btn" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="listbox" data-testid="hy-operator-btn" @click="toggle">
            Qui ? <strong>{{ currentLabel }}</strong> ▾
        </button>
        <div v-if="open" class="hy-operator-pop" role="listbox" aria-label="Qui fait ce relevé ?" data-testid="hy-operator-list">
            <button v-for="p in people" :key="p.id" type="button" role="option" :aria-selected="p.id === chosenId ? 'true' : 'false'"
                    :class="{ 'is-on': p.id === chosenId }" :data-testid="`hy-operator-${p.id}`" @click="choose(p)">
                {{ p.name }}<template v-if="p.is_me"> (moi)</template>
            </button>
            <!-- jamais une liste vide muette : on dit pourquoi, ce qui se passe en attendant, et où l'ajouter -->
            <div v-if="!people.length" class="hy-operator-empty" data-testid="hy-operator-empty">
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

const STORE_KEY = "hygiene.operator";

export default {
    name: "HygieneOperatorPicker",
    props: { modelValue: { type: [Number, null], default: null } },
    emits: ["update:modelValue"],
    data() {
        return { people: [], me: null, open: false, chosenId: null };
    },
    computed: {
        currentLabel() {
            const p = this.people.find((x) => x.id === this.chosenId);
            // liste vide (aucun employé enregistré) : le geste est attribué au compte connecté — le dire plutôt qu'afficher « … »
            return p ? p.short : (this.chosenId !== null && this.chosenId === this.me ? "moi" : "…");
        },
    },
    async created() {
        try {
            const { data } = await axios.get("admin/hygiene/operators");
            this.people = data.data || [];
            this.me = data.meta ? data.meta.me : null;
        } catch (_) {
            this.people = [];
        }
        let saved = null;
        // le choix est mémorisé PAR utilisateur connecté : quand Bob se connecte, il n'hérite pas du choix d'Alice
        try { saved = Number(window.localStorage.getItem(this.storeKey())) || null; } catch (_) { /* stockage indisponible : on retombe sur « moi » */ }
        const known = (id) => this.people.some((p) => p.id === id);
        this.chosenId = known(this.modelValue) ? this.modelValue : (known(saved) ? saved : this.me);
        this.$emit("update:modelValue", this.chosenId === this.me ? null : this.chosenId);
    },
    methods: {
        storeKey() { return `${STORE_KEY}.${this.me}`; },
        toggle() { this.open = !this.open; },
        choose(p) {
            this.chosenId = p.id;
            this.open = false;
            try { window.localStorage.setItem(this.storeKey(), String(p.id)); } catch (_) { /* confort seulement */ }
            this.$emit("update:modelValue", p.id === this.me ? null : p.id);
        },
    },
};
</script>

<style src="./hygiene.css"></style>
