<template>
    <!--
      [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Modale caisse : enregistrer une sortie de stock
      hors-vente (repas personnel / perte) avec trace + décrément du stock direct. Auto-suffisante
      (charge items + historique, POST). Best-effort — ne casse jamais la caisse.
    -->
    <div v-if="open" class="pso-overlay" @click.self="close" role="dialog" aria-modal="true" aria-label="Sortie de stock hors-vente">
        <div class="pso-modal">
            <header class="pso-head">
                <h2>Sortie de stock <span class="pso-sub">repas personnel · perte</span></h2>
                <button type="button" class="pso-close" @click="close" aria-label="Fermer">✕</button>
            </header>

            <div class="pso-body">
                <!-- Formulaire -->
                <form class="pso-form" @submit.prevent="submit">
                    <label class="pso-field">
                        <span>Produit</span>
                        <input
                            v-model.trim="search"
                            type="search"
                            :placeholder="loadingItems ? 'Chargement…' : 'Rechercher un produit…'"
                            list="pso-items"
                            autocomplete="off"
                            data-testid="pso-item-search"
                        />
                        <datalist id="pso-items">
                            <option v-for="it in filteredItems" :key="it.id" :value="it.name"></option>
                        </datalist>
                    </label>

                    <div class="pso-row">
                        <label class="pso-field pso-qty">
                            <span>Quantité</span>
                            <input v-model.number="form.quantity" type="number" min="1" max="999" data-testid="pso-qty"/>
                        </label>
                        <div class="pso-field pso-type">
                            <span>Motif</span>
                            <div class="pso-toggle">
                                <button
                                    type="button"
                                    :class="['pso-toggle-btn', form.type === 'staff_meal' ? 'is-on pso-staff' : '']"
                                    @click="form.type = 'staff_meal'"
                                    data-testid="pso-type-staff"
                                >👤 Repas personnel</button>
                                <button
                                    type="button"
                                    :class="['pso-toggle-btn', form.type === 'waste' ? 'is-on pso-waste' : '']"
                                    @click="form.type = 'waste'"
                                    data-testid="pso-type-waste"
                                >🗑️ Perte / raté</button>
                            </div>
                        </div>
                    </div>

                    <label class="pso-field">
                        <span>Note (facultatif)</span>
                        <input v-model.trim="form.note" type="text" maxlength="255" placeholder="Ex. brûlé, tombé, pause équipe…"/>
                    </label>

                    <div v-if="error" class="pso-error" role="alert">{{ error }}</div>

                    <button type="submit" class="pso-submit" :disabled="!canSubmit || submitting" data-testid="pso-submit">
                        {{ submitting ? 'Enregistrement…' : 'Enregistrer la sortie' }}
                    </button>
                </form>

                <!-- Historique récent -->
                <div class="pso-recent">
                    <h3>Dernières sorties</h3>
                    <div v-if="recent.length === 0" class="pso-empty">Aucune sortie enregistrée aujourd'hui.</div>
                    <ul v-else class="pso-list">
                        <li v-for="o in recent" :key="o.id" class="pso-list-row">
                            <span :class="['pso-tag', o.type === 'staff_meal' ? 'pso-tag-staff' : 'pso-tag-waste']">
                                {{ o.type === 'staff_meal' ? '👤' : '🗑️' }} {{ o.type_label }}
                            </span>
                            <span class="pso-list-item"><b>{{ o.quantity }}×</b> {{ o.item_name }}</span>
                            <span v-if="o.note" class="pso-list-note">— {{ o.note }}</span>
                            <span class="pso-list-time">{{ o.created_at_human }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'PosStockOutflowModal',
    props: {
        open: { type: Boolean, default: false },
    },
    data() {
        return {
            items: [],
            recent: [],
            loadingItems: false,
            submitting: false,
            error: null,
            search: '',
            form: { quantity: 1, type: 'staff_meal', note: '' },
        };
    },
    computed: {
        filteredItems() {
            const q = (this.search || '').toLowerCase();
            if (!q) return this.items.slice(0, 30);
            return this.items.filter((it) => (it.name || '').toLowerCase().includes(q)).slice(0, 30);
        },
        selectedItem() {
            const q = (this.search || '').toLowerCase().trim();
            return this.items.find((it) => (it.name || '').toLowerCase() === q) || null;
        },
        canSubmit() {
            return !!this.selectedItem && Number(this.form.quantity) >= 1 && ['staff_meal', 'waste'].includes(this.form.type);
        },
    },
    watch: {
        open(v) { if (v) this.load(); },
    },
    methods: {
        close() { this.$emit('close'); },
        async load() {
            this.error = null;
            this.loadingItems = true;
            try {
                const [items, recent] = await Promise.all([
                    axios.get('admin/pos/stock-outflow/items'),
                    axios.get('admin/pos/stock-outflow/recent'),
                ]);
                this.items = (items && items.data && items.data.data) || [];
                this.recent = (recent && recent.data && recent.data.data) || [];
            } catch (e) {
                this.error = 'Impossible de charger les produits.';
            } finally {
                this.loadingItems = false;
            }
        },
        async submit() {
            if (!this.canSubmit || this.submitting) return;
            this.error = null;
            this.submitting = true;
            try {
                const res = await axios.post('admin/pos/stock-outflow', {
                    item_id: this.selectedItem.id,
                    quantity: Number(this.form.quantity),
                    type: this.form.type,
                    note: this.form.note || null,
                }, { headers: { 'X-Idempotency-Key': (this._idemKey || (this._idemKey = 'pso-' + Date.now() + '-' + Math.round(Math.random() * 1e6))) } });
                if (res && res.data && res.data.outflow) {
                    this.recent.unshift(res.data.outflow);
                    this.recent = this.recent.slice(0, 50);
                }
                // [SEC MISSION-12 2026-07-31] Clé idempotente STABLE tant que la sortie n'a pas abouti :
                // un rejeu (réponse réseau perdue) réutilise la même clé → le middleware rejoue au lieu de
                // re-décrémenter. Effacée au succès pour que la sortie SUIVANTE ait une clé fraîche.
                this._idemKey = null;
                // reset (garde le motif choisi pour des saisies en série)
                this.search = '';
                this.form.quantity = 1;
                this.form.note = '';
            } catch (e) {
                const msg = e && e.response && e.response.data && e.response.data.message;
                this.error = msg || 'Enregistrement impossible.';
            } finally {
                this.submitting = false;
            }
        },
    },
};
</script>

<style scoped>
.pso-overlay {
    position: fixed; inset: 0; z-index: 80;
    background: rgba(10, 10, 10, 0.45);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
}
.pso-modal {
    background: #fff; border-radius: 18px; width: 100%; max-width: 560px;
    max-height: 88vh; display: flex; flex-direction: column; overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}
.pso-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid #eee;
}
.pso-head h2 { font-size: 18px; font-weight: 800; margin: 0; color: #1A1A1A; }
.pso-sub { font-size: 12px; font-weight: 600; color: #888; margin-left: 8px; text-transform: uppercase; letter-spacing: 0.04em; }
.pso-close { background: #f3f3f3; border: 0; width: 32px; height: 32px; border-radius: 50%; font-size: 15px; cursor: pointer; }
.pso-body { padding: 18px 22px; overflow-y: auto; }
.pso-form { display: flex; flex-direction: column; gap: 14px; }
.pso-field { display: flex; flex-direction: column; gap: 5px; }
.pso-field > span { font-size: 12px; font-weight: 700; color: #555; letter-spacing: 0.02em; }
.pso-field input {
    padding: 11px 13px; border: 1.5px solid #e2e2e2; border-radius: 11px; font-size: 15px; outline: none;
}
.pso-field input:focus { border-color: #F4501E; }
.pso-row { display: flex; gap: 14px; }
.pso-qty { width: 110px; flex-shrink: 0; }
.pso-type { flex: 1; }
.pso-toggle { display: flex; gap: 8px; }
.pso-toggle-btn {
    flex: 1; padding: 10px 8px; border: 1.5px solid #e2e2e2; border-radius: 11px; background: #fafafa;
    font-size: 13px; font-weight: 700; cursor: pointer; color: #555; white-space: nowrap;
}
.pso-toggle-btn.is-on.pso-staff { border-color: #1FA653; background: rgba(31, 166, 83, 0.10); color: #157a3d; }
.pso-toggle-btn.is-on.pso-waste { border-color: #D72638; background: rgba(215, 38, 56, 0.10); color: #C2410C; }
.pso-error { color: #C2410C; font-size: 13px; font-weight: 700; }
.pso-submit {
    margin-top: 4px; padding: 13px; border: 0; border-radius: 12px; background: #F4501E; color: #fff;
    font-size: 15px; font-weight: 800; cursor: pointer;
}
.pso-submit:disabled { opacity: 0.5; cursor: not-allowed; }
.pso-recent { margin-top: 22px; border-top: 1px solid #eee; padding-top: 16px; }
.pso-recent h3 { font-size: 13px; font-weight: 800; color: #555; margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.04em; }
.pso-empty { font-size: 13px; color: #999; }
.pso-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 7px; }
.pso-list-row { display: flex; align-items: center; gap: 8px; font-size: 13px; flex-wrap: wrap; }
.pso-tag { font-weight: 700; padding: 2px 8px; border-radius: 999px; font-size: 11px; white-space: nowrap; }
.pso-tag-staff { background: rgba(31, 166, 83, 0.12); color: #157a3d; }
.pso-tag-waste { background: rgba(215, 38, 56, 0.12); color: #C2410C; }
.pso-list-item { font-weight: 600; color: #333; }
.pso-list-note { color: #999; font-style: italic; }
.pso-list-time { margin-left: auto; color: #aaa; font-size: 11px; white-space: nowrap; }
</style>
