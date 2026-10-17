<template>
    <div class="hc-overlay" data-testid="dlc-health" @keydown.esc="$emit('close')" @keydown.tab="trapTab">
        <div ref="panel" class="hc-panel" role="dialog" aria-modal="true" aria-label="Contrôle du catalogue">
            <header class="hc-head">
                <h4>Contrôle du catalogue</h4>
                <button type="button" class="hc-close" aria-label="Fermer" data-testid="dlc-health-close" @click="$emit('close')">✕</button>
            </header>

            <div class="hc-body">
                <p v-if="!total" class="hc-ok" role="status" data-testid="dlc-health-clean">
                    ✓ Catalogue contrôlé : durées validées, allergènes renseignés, fiches rangées. Rien à signaler.
                </p>

                <!-- ── Durées de départ à valider ───────────────────────────────────────── -->
                <section v-if="startingGroups.length" class="hc-sec" data-testid="dlc-health-starting">
                    <h5>Durées à valider <span class="hc-n">{{ starting.length }}</span></h5>
                    <p class="hc-help">
                        Ces durées sont des <b>valeurs de départ prudentes</b> (GBPH restauration, arrêté du 21/12/2009), pas celles de votre plan de
                        maîtrise sanitaire. Lisez-les, corrigez celles qui diffèrent (« Modifier »), puis validez : votre nom et l'heure sont gardés sur chaque fiche.
                    </p>
                    <label class="hc-confirm">
                        <input v-model="confirmed" type="checkbox" data-testid="dlc-health-confirm" />
                        <span>Je confirme que les durées que je valide sont celles de mon plan de maîtrise sanitaire.</span>
                    </label>
                    <details v-for="g in startingGroups" :key="g.name" class="hc-group" :data-testid="`dlc-health-group-${g.slug}`">
                        <summary>
                            <span class="hc-group-name">{{ g.name }}</span>
                            <span class="hc-n">{{ g.items.length }}</span>
                        </summary>
                        <ul class="hc-rows">
                            <li v-for="p in g.items" :key="p.id" class="hc-row">
                                <span class="hc-row-name">{{ p.name }}</span>
                                <span class="hc-row-life">{{ lifeText(p) }}</span>
                                <button type="button" class="hc-btn hc-btn--small" :data-testid="`dlc-health-edit-${p.id}`" @click="$emit('edit', p)">Modifier</button>
                            </li>
                        </ul>
                        <button type="button" class="hc-btn hc-btn--primary" :disabled="!confirmed || busy" :data-testid="`dlc-health-validate-${g.slug}`"
                                @click="validate(g.items.map((p) => p.id))">
                            Valider les {{ g.items.length }} durées de « {{ g.name }} »
                        </button>
                    </details>
                    <button v-if="startingGroups.length > 1" type="button" class="hc-btn hc-btn--primary hc-all" :disabled="!confirmed || busy" data-testid="dlc-health-validate-all"
                            @click="validate(starting.map((p) => p.id))">
                        Tout valider ({{ starting.length }})
                    </button>
                </section>

                <!-- ── Allergènes à renseigner ──────────────────────────────────────────── -->
                <section v-if="allergenRows.length" class="hc-sec" data-testid="dlc-health-allergens">
                    <h5>Allergènes à renseigner <span class="hc-n">{{ allergenRows.length }}</span></h5>
                    <p class="hc-help">
                        Ces fiches n'indiquent ni allergène ni « aucun des 14 » : l'étiquette n'affirme rien. Seul vous savez ce qu'il y a dans votre recette —
                        ouvrez la fiche et cochez les allergènes, ou « Aucun ».
                    </p>
                    <div class="hc-chips">
                        <button v-for="p in allergenRows" :key="p.id" type="button" class="hc-chip" :data-testid="`dlc-health-allergen-${p.id}`" @click="$emit('edit', p)">{{ p.name }}</button>
                    </div>
                </section>

                <!-- ── Fiches sans famille ──────────────────────────────────────────────── -->
                <section v-if="noCategory.length" class="hc-sec" data-testid="dlc-health-category">
                    <h5>Fiches sans famille <span class="hc-n">{{ noCategory.length }}</span></h5>
                    <p class="hc-help">Sans famille, la fiche échappe aux onglets et à « Tout sélectionner » par famille. La famille proposée vient du catalogue ; vous pouvez la changer.</p>
                    <ul class="hc-rows">
                        <li v-for="r in noCategory" :key="r.id" class="hc-row">
                            <input v-model="r.checked" type="checkbox" class="hc-check" :aria-label="`Ranger ${r.name}`" :data-testid="`dlc-health-cat-check-${r.id}`" />
                            <span class="hc-row-name">{{ r.name }}</span>
                            <input v-model="r.category" type="text" maxlength="60" class="db-field-control hc-cat" list="hc-categories" placeholder="Famille…"
                                   :aria-label="`Famille de ${r.name}`" :data-testid="`dlc-health-cat-${r.id}`" />
                        </li>
                    </ul>
                    <datalist id="hc-categories"><option v-for="c in categories" :key="c" :value="c" /></datalist>
                    <button type="button" class="hc-btn hc-btn--primary" :disabled="!classifiable.length || busy" data-testid="dlc-health-classify" @click="classify">
                        Ranger {{ classifiable.length }} fiche{{ classifiable.length > 1 ? "s" : "" }}
                    </button>
                </section>

                <!-- ── Fiches voisines ──────────────────────────────────────────────────── -->
                <section v-if="neighbours.length" class="hc-sec" data-testid="dlc-health-neighbours">
                    <h5>Fiches à harmoniser <span class="hc-n">{{ neighbours.length }}</span></h5>
                    <p class="hc-help">Le même produit ne doit pas se conserver différemment d'une fiche à l'autre. Ouvrez celle à corriger (ou supprimez le doublon).</p>
                    <ul class="hc-rows">
                        <li v-for="(n, i) in neighbours" :key="i" class="hc-row hc-row--pair" :data-testid="`dlc-health-pair-${i}`">
                            <span class="hc-row-name">
                                <b v-if="n.kind === 'duplicate'">Même produit écrit deux fois</b>
                                <b v-else>Conservation différente</b>
                                <small>{{ n.names[0] }} ({{ n.storages[0] }}) · {{ n.names[1] }} ({{ n.storages[1] }})</small>
                            </span>
                            <button v-for="(id, k) in n.ids" :key="id" type="button" class="hc-btn hc-btn--small" :data-testid="`dlc-health-pair-${i}-${k}`" @click="editById(id)">
                                Ouvrir « {{ n.names[k] }} »
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <footer class="hc-foot">
                <span class="hc-spacer"></span>
                <button type="button" class="hc-btn" data-testid="dlc-health-done" @click="$emit('close')">Fermer</button>
            </footer>
        </div>
    </div>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P0.3/P0.4/P0.6] « Contrôle du catalogue » : tout ce qui rend un catalogue peu fiable,
 * au même endroit — durées de départ à valider, allergènes à renseigner, fiches sans famille, fiches voisines. Le serveur calcule
 * (App\Support\Labels\CatalogueHealth) ; cet écran ne devine rien : il propose, le responsable confirme.
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import { DLC_ACTIONS, shelfLifeText } from "../../../helpers/dlcLabelBuilder";

function slug(v) {
    return String(v || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "sans-famille";
}

export default {
    name: "DlcCatalogueHealth",
    props: {
        health: { type: Object, required: true },
        products: { type: Array, required: true },
        categories: { type: Array, default: () => [] },
    },
    emits: ["close", "edit", "changed"],
    data() {
        return {
            confirmed: false,
            busy: false,
            noCategory: (this.health.no_category || []).map((r) => ({ ...r, category: r.suggested || "", checked: Boolean(r.suggested) })),
            returnFocus: null,
        };
    },
    computed: {
        byId() { return new Map(this.products.map((p) => [p.id, p])); },
        starting() { return (this.health.starting || []).map((id) => this.byId.get(id)).filter(Boolean); },
        startingGroups() {
            const groups = new Map();
            this.starting.forEach((p) => {
                const name = p.category || "Sans famille";
                if (!groups.has(name)) groups.set(name, []);
                groups.get(name).push(p);
            });
            return [...groups.entries()].sort((a, b) => a[0].localeCompare(b[0], "fr")).map(([name, items]) => ({ name, slug: slug(name), items }));
        },
        allergenRows() { return (this.health.allergens || []).map((id) => this.byId.get(id)).filter(Boolean); },
        neighbours() { return this.health.neighbours || []; },
        classifiable() { return this.noCategory.filter((r) => r.checked && String(r.category || "").trim()); },
        total() {
            return this.starting.length + this.allergenRows.length + this.noCategory.length + this.neighbours.length;
        },
    },
    mounted() {
        this.returnFocus = document.activeElement;
        this.$nextTick(() => { const b = this.$el.querySelector("button"); if (b) b.focus(); });
    },
    beforeUnmount() {
        if (this.returnFocus && this.returnFocus.focus && document.contains(this.returnFocus)) this.returnFocus.focus();
    },
    methods: {
        lifeText(p) {
            const events = (p.actions || []).map((a) => `${(DLC_ACTIONS[a.key] || DLC_ACTIONS.opened).label} ${shelfLifeText(a.value, a.unit)}`).join(" · ");
            return `${events}${p.storage ? ` · ${p.storage}` : ""}`;
        },
        editById(id) {
            const p = this.byId.get(id);
            if (p) this.$emit("edit", p);
        },
        async validate(ids) {
            if (this.busy || !this.confirmed || !ids.length) return;
            this.busy = true;
            try {
                const { data } = await axios.post("admin/labels/products/validate-durations", { ids });
                const n = Number(data && data.data && data.data.validated) || 0;
                alertService.success(`${n} fiche${n > 1 ? "s" : ""} validée${n > 1 ? "s" : ""}.`);
                this.$emit("changed");
            } catch (e) {
                alertService.error("Validation impossible : vérifiez la connexion puis réessayez.");
            } finally {
                this.busy = false;
            }
        },
        async classify() {
            if (this.busy || !this.classifiable.length) return;
            this.busy = true;
            try {
                const assignments = this.classifiable.map((r) => ({ id: r.id, category: String(r.category).trim() }));
                const { data } = await axios.post("admin/labels/products/classify", { assignments });
                const n = Number(data && data.data && data.data.classified) || 0;
                alertService.success(`${n} fiche${n > 1 ? "s" : ""} rangée${n > 1 ? "s" : ""}.`);
                this.noCategory = this.noCategory.filter((r) => !assignments.some((a) => a.id === r.id));
                this.$emit("changed");
            } catch (e) {
                alertService.error("Classement impossible : vérifiez la connexion puis réessayez.");
            } finally {
                this.busy = false;
            }
        },
        trapTab(e) {
            const items = [...this.$el.querySelectorAll("button:not([disabled]), input:not([disabled]), summary, select")];
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },
    },
};
</script>

<style scoped>
.hc-overlay { position: fixed; inset: 0; z-index: 1050; background: rgba(15, 23, 42, .55); display: flex; align-items: center; justify-content: center; padding: 12px; }
.hc-panel { background: #fff; border-radius: 16px; width: min(860px, 100%); max-height: calc(100vh - 24px); box-shadow: 0 20px 60px rgba(0, 0, 0, .3); display: flex; flex-direction: column; }
.hc-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid #e2e8f0; flex: 0 0 auto; }
.hc-head h4 { margin: 0; font-size: 20px; font-weight: 800; color: #111827; }
.hc-close { width: 48px; height: 48px; border-radius: 12px; border: 1px solid #cbd5e1; background: #fff; font-size: 20px; cursor: pointer; }
.hc-body { padding: 14px 20px; overflow-y: auto; flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; gap: 16px; }
.hc-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 10px; padding: 12px 14px; margin: 0; font-weight: 700; }
.hc-sec { border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; }
.hc-sec h5 { margin: 0 0 6px; font-size: 17px; font-weight: 800; color: #111827; display: flex; align-items: center; gap: 8px; }
.hc-n { display: inline-block; min-width: 26px; padding: 1px 8px; border-radius: 999px; background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; font-size: 14px; font-weight: 800; text-align: center; }
.hc-help { color: #475569; font-size: 14px; margin: 0 0 10px; line-height: 1.45; }
.hc-confirm { color: #1f2937; display: flex; gap: 12px; align-items: flex-start; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; padding: 10px 12px; margin: 0 0 10px; font-size: 15px; line-height: 1.4; cursor: pointer; min-height: 48px; }
.hc-confirm input, .hc-check { width: 26px; height: 26px; flex: 0 0 auto; }
.hc-group { border: 1px solid #e2e8f0; border-radius: 10px; margin: 0 0 8px; padding: 0 10px; }
.hc-group[open] { padding-bottom: 10px; }
.hc-group summary { display: flex; align-items: center; gap: 8px; min-height: 52px; cursor: pointer; font-weight: 700; font-size: 16px; color: #111827; }
.hc-group-name { flex: 1 1 auto; }
.hc-rows { list-style: none; margin: 0 0 10px; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.hc-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-height: 48px; padding: 2px 4px; border-bottom: 1px solid #f1f5f9; }
.hc-row-name { flex: 1 1 200px; min-width: 0; font-weight: 700; font-size: 15px; display: flex; flex-direction: column; color: #111827; }
.hc-row-name small { font-weight: 500; color: #475569; font-size: 13px; }
.hc-row-life { flex: 2 1 220px; min-width: 0; color: #475569; font-size: 14px; }
.hc-cat { flex: 1 1 200px; max-width: 280px; min-height: 48px; }
.hc-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.hc-chip { min-height: 48px; padding: 0 14px; border-radius: 999px; border: 1px solid #fdba74; background: #fff7ed; color: #7c2d12; font-size: 15px; font-weight: 700; cursor: pointer; }
.hc-foot { display: flex; gap: 10px; align-items: center; padding: 12px 20px; border-top: 1px solid #e2e8f0; flex: 0 0 auto; }
.hc-spacer { flex: 1 1 auto; }
.hc-btn { min-height: 52px; padding: 0 18px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; color: #1f2937; font-size: 16px; font-weight: 700; cursor: pointer; }
.hc-btn--small { min-height: 48px; padding: 0 12px; font-size: 14px; }
.hc-btn.hc-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.hc-btn.hc-btn--primary:disabled { background: #e2e8f0; border-color: #cbd5e1; color: #475569; cursor: not-allowed; }
.hc-all { margin-top: 4px; }
</style>
