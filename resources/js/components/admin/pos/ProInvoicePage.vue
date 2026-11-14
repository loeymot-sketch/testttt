<template>
    <!-- [FACTURE PRO 2026-11-14 · propriétaire] « créer fiche professionnel, le montant, la date et imprimer,
         c'est tout ». Une facture à montant libre pour un client professionnel, HORS ticket de caisse :
         elle ne crée aucune commande et n'entre ni dans le Z ni dans le tiroir. -->
    <div class="row">
        <div class="col-12">
            <BreadcrumbComponent />
        </div>
        <div class="col-12">
            <CaisseSecondaryNav current="facture-pro" />
        </div>
        <div class="col-12 pb-4">
            <div class="fp-wrap" data-testid="pro-invoice-page">
                <p class="fp-notice" role="note">
                    Facture établie <strong>hors ticket de caisse</strong> : elle ne crée pas de commande et n'entre ni dans le Z ni dans le tiroir.
                </p>

                <form class="fp-grid" novalidate @submit.prevent="submit" data-testid="pro-invoice-form">
                    <section class="fp-card">
                        <h2 class="fp-title">Client professionnel</h2>
                        <label class="fp-field">
                            <span>Société (raison sociale) <em>*</em></span>
                            <input v-model="form.client_name" type="text" maxlength="190" autocomplete="organization"
                                   data-testid="fp-client-name" :aria-invalid="!!err('client_name')">
                            <small v-if="err('client_name')" class="fp-err">{{ err('client_name') }}</small>
                        </label>
                        <label class="fp-field">
                            <span>Adresse</span>
                            <textarea v-model="form.client_address" rows="2" maxlength="500" data-testid="fp-client-address"></textarea>
                            <small v-if="err('client_address')" class="fp-err">{{ err('client_address') }}</small>
                        </label>
                        <div class="fp-row">
                            <label class="fp-field">
                                <span>SIRET</span>
                                <input v-model="form.client_siret" type="text" inputmode="numeric" maxlength="20" data-testid="fp-client-siret">
                                <small v-if="err('client_siret')" class="fp-err">{{ err('client_siret') }}</small>
                            </label>
                            <label class="fp-field">
                                <span>N° TVA</span>
                                <input v-model="form.client_vat_number" type="text" maxlength="20" placeholder="FR…" data-testid="fp-client-vat">
                                <small v-if="err('client_vat_number')" class="fp-err">{{ err('client_vat_number') }}</small>
                            </label>
                        </div>
                    </section>

                    <section class="fp-card">
                        <h2 class="fp-title">Prestation</h2>
                        <div class="fp-row">
                            <label class="fp-field">
                                <span>Date de la prestation <em>*</em></span>
                                <input v-model="form.service_date" type="date" :max="today" data-testid="fp-service-date">
                                <small v-if="err('service_date')" class="fp-err">{{ err('service_date') }}</small>
                            </label>
                            <label class="fp-field fp-field--qty">
                                <span>Quantité</span>
                                <input v-model.number="form.quantity" type="number" min="1" max="999" step="1" data-testid="fp-quantity">
                                <small v-if="err('quantity')" class="fp-err">{{ err('quantity') }}</small>
                            </label>
                        </div>
                        <label class="fp-field">
                            <span>Désignation <em>*</em></span>
                            <input v-model="form.label" type="text" maxlength="190" placeholder="2 repas" data-testid="fp-label">
                            <small v-if="err('label')" class="fp-err">{{ err('label') }}</small>
                        </label>
                        <div class="fp-row">
                            <label class="fp-field">
                                <span>Montant TTC (€) <em>*</em></span>
                                <input v-model="form.amount_ttc" type="text" inputmode="decimal" placeholder="18,00"
                                       class="fp-amount" data-testid="fp-amount">
                                <small v-if="err('amount_ttc')" class="fp-err">{{ err('amount_ttc') }}</small>
                            </label>
                            <div class="fp-field">
                                <span id="fp-vat-label">Taux de TVA</span>
                                <div class="fp-seg" role="radiogroup" aria-labelledby="fp-vat-label">
                                    <button v-for="r in vatRates" :key="r.value" type="button" role="radio"
                                            :aria-checked="form.vat_rate === r.value" :class="{ on: form.vat_rate === r.value }"
                                            :data-testid="'fp-vat-' + r.value" @click="form.vat_rate = r.value">{{ r.label }}</button>
                                </div>
                                <small v-if="err('vat_rate')" class="fp-err">{{ err('vat_rate') }}</small>
                            </div>
                        </div>
                        <div class="fp-field">
                            <span id="fp-pay-label">Règlement <em>*</em></span>
                            <div class="fp-pay" role="radiogroup" aria-labelledby="fp-pay-label">
                                <button v-for="m in paymentMethods" :key="m.value" type="button" role="radio"
                                        :aria-checked="form.payment_method === m.value" :class="{ on: form.payment_method === m.value }"
                                        :data-testid="'fp-pay-' + m.value" @click="form.payment_method = m.value">
                                    <span aria-hidden="true">{{ m.icon }}</span> {{ m.label }}
                                </button>
                            </div>
                            <small v-if="err('payment_method')" class="fp-err">{{ err('payment_method') }}</small>
                        </div>
                    </section>

                    <aside class="fp-card fp-preview" aria-live="polite" data-testid="fp-preview">
                        <h2 class="fp-title">Aperçu</h2>
                        <div class="fp-line"><span>Total HT</span><strong data-testid="fp-preview-ht">{{ money(preview.ht) }}</strong></div>
                        <div class="fp-line"><span>TVA {{ vatLabel }}</span><strong data-testid="fp-preview-vat">{{ money(preview.vat) }}</strong></div>
                        <div class="fp-line fp-line--ttc"><span>Total TTC</span><strong data-testid="fp-preview-ttc">{{ money(preview.ttc) }}</strong></div>
                        <p v-if="generalError" class="fp-err fp-err--block" role="alert">{{ generalError }}</p>
                        <button type="submit" class="fp-submit" :disabled="submitting" data-testid="fp-submit">
                            {{ submitting ? 'Création…' : 'Créer et imprimer' }}
                        </button>
                        <p class="fp-hint">Le numéro (FP-AAAA-NNNN) est attribué à la création. Une facture ne se modifie pas : elle s'annule.</p>
                    </aside>
                </form>

                <section class="fp-card fp-list" data-testid="pro-invoice-list">
                    <h2 class="fp-title">Dernières factures</h2>
                    <p v-if="loadingList" class="fp-muted">Chargement…</p>
                    <p v-else-if="listError" class="fp-err" role="alert">{{ listError }}</p>
                    <p v-else-if="invoices.length === 0" class="fp-muted">Aucune facture pro pour l'instant.</p>
                    <div v-else class="fp-table-wrap">
                        <table class="fp-table">
                            <thead>
                            <tr>
                                <th>N°</th><th>Prestation</th><th>Client</th><th>Désignation</th>
                                <th class="r">TTC</th><th>Règlement</th><th class="r">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <template v-for="inv in invoices" :key="inv.id">
                                <tr :class="{ cancelled: inv.cancelled }" :data-testid="'fp-row-' + inv.number">
                                    <td class="fp-num">{{ inv.number }}<span v-if="inv.cancelled" class="fp-badge">Annulée</span></td>
                                    <td>{{ frDate(inv.service_date) }}</td>
                                    <td>{{ inv.client_name }}</td>
                                    <td>{{ inv.quantity > 1 ? inv.quantity + ' × ' : '' }}{{ inv.label }}</td>
                                    <td class="r">{{ money(inv.amount_ttc) }}</td>
                                    <td>{{ inv.payment_label }}</td>
                                    <td class="r fp-actions">
                                        <button type="button" class="fp-btn" :disabled="printingId === inv.id"
                                                :data-testid="'fp-reprint-' + inv.id" @click="print(inv.id)">Réimprimer</button>
                                        <button v-if="!inv.cancelled" type="button" class="fp-btn fp-btn--danger"
                                                :data-testid="'fp-cancel-' + inv.id" @click="startCancel(inv)">Annuler</button>
                                    </td>
                                </tr>
                                <tr v-if="cancelling && cancelling.id === inv.id" class="fp-cancel-row">
                                    <td colspan="7">
                                        <form class="fp-cancel-form" @submit.prevent="confirmCancel">
                                            <label class="fp-field">
                                                <span>Motif de l'annulation de {{ inv.number }} (le numéro est conservé)</span>
                                                <input v-model="cancelReason" type="text" maxlength="255" data-testid="fp-cancel-reason">
                                            </label>
                                            <button type="submit" class="fp-btn fp-btn--danger" :disabled="cancelBusy" data-testid="fp-cancel-confirm">Confirmer l'annulation</button>
                                            <button type="button" class="fp-btn" @click="cancelling = null">Retour</button>
                                            <small v-if="cancelError" class="fp-err">{{ cancelError }}</small>
                                        </form>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>

<script>
import axios from "axios";
import BreadcrumbComponent from "../components/BreadcrumbComponent";
import CaisseSecondaryNav from "./CaisseSecondaryNav.vue";

const BASE = "admin/pos/pro-invoices";

/** Même calcul que le serveur (ProInvoiceService::computeAmounts), en centimes entiers. */
export function computeAmounts(amountTtc, vatRate) {
    const ttc = Number(String(amountTtc ?? "").replace(",", ".").trim());
    if (!Number.isFinite(ttc) || ttc <= 0) {
        return { ttc: 0, ht: 0, vat: 0 };
    }
    const ttcCents = Math.round(ttc * 100);
    const rateBp = Math.round(Number(vatRate) * 100);
    const den = 10000 + rateBp;
    const htCents = Math.floor((2 * ttcCents * 10000 + den) / (2 * den));
    return { ttc: ttcCents / 100, ht: htCents / 100, vat: (ttcCents - htCents) / 100 };
}

const todayIso = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};

const emptyForm = () => ({
    client_name: "",
    client_address: "",
    client_siret: "",
    client_vat_number: "",
    service_date: todayIso(),
    label: "",
    quantity: 1,
    amount_ttc: "",
    vat_rate: "10",
    payment_method: "",
});

export default {
    name: "ProInvoicePage",
    components: { BreadcrumbComponent, CaisseSecondaryNav },
    data() {
        return {
            form: emptyForm(),
            errors: {},
            generalError: "",
            submitting: false,
            invoices: [],
            loadingList: false,
            listError: "",
            printingId: null,
            cancelling: null,
            cancelReason: "",
            cancelBusy: false,
            cancelError: "",
            today: todayIso(),
            vatRates: [
                { value: "5.5", label: "5,5 %" },
                { value: "10", label: "10 %" },
                { value: "20", label: "20 %" },
            ],
            paymentMethods: [
                { value: "especes", label: "Espèces", icon: "💶" },
                { value: "carte", label: "Carte", icon: "💳" },
                { value: "titre_restaurant", label: "Titre-restaurant", icon: "🎫" },
            ],
        };
    },
    computed: {
        preview() {
            return computeAmounts(this.form.amount_ttc, this.form.vat_rate);
        },
        vatLabel() {
            return String(this.form.vat_rate).replace(".", ",") + " %";
        },
    },
    mounted() {
        this.loadList();
    },
    methods: {
        err(field) {
            const e = this.errors[field];
            return Array.isArray(e) ? e[0] : e || "";
        },
        money(v) {
            return Number(v || 0).toLocaleString("fr-FR", { style: "currency", currency: "EUR" });
        },
        frDate(iso) {
            if (!iso) return "";
            const [y, m, d] = String(iso).slice(0, 10).split("-");
            return `${d}/${m}/${y}`;
        },
        idemKey(prefix) {
            return `${prefix}-${Date.now()}-${Math.round(Math.random() * 1e9)}`;
        },
        async loadList() {
            this.loadingList = true;
            this.listError = "";
            try {
                const { data } = await axios.get(BASE, { params: { per_page: 20 } });
                this.invoices = (data && data.data) || [];
            } catch (e) {
                this.listError = (e && e.response && e.response.data && e.response.data.message)
                    || "Impossible de charger les factures.";
            } finally {
                this.loadingList = false;
            }
        },
        async submit() {
            if (this.submitting) return;
            this.errors = {};
            this.generalError = "";
            if (!this.form.payment_method) {
                this.errors = { payment_method: ["Choisissez le mode de règlement."] };
                return;
            }
            this.submitting = true;
            try {
                const { data } = await axios.post(BASE, { ...this.form }, {
                    headers: { "X-Idempotency-Key": this.idemKey("fp") },
                });
                const created = data && data.data;
                this.form = emptyForm();
                await this.loadList();
                if (created && created.id) {
                    await this.print(created.id);
                }
            } catch (e) {
                const res = e && e.response;
                if (res && res.status === 422 && res.data && res.data.errors) {
                    this.errors = res.data.errors;
                } else {
                    this.generalError = (res && res.data && res.data.message) || "La facture n'a pas pu être créée.";
                }
            } finally {
                this.submitting = false;
            }
        },
        /**
         * Impression : le document A4 est rendu par le serveur (une seule source pour les mentions
         * légales), chargé dans un cadre invisible puis imprimé. Pas de fenêtre surgissante à bloquer.
         */
        async print(id) {
            this.printingId = id;
            try {
                const { data: html } = await axios.get(`${BASE}/${id}/document`, { responseType: "text", transformResponse: [(d) => d] });
                const frame = document.createElement("iframe");
                frame.setAttribute("title", "Impression de la facture");
                frame.setAttribute("aria-hidden", "true");
                frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;";
                frame.setAttribute("data-testid", "fp-print-frame");
                frame.onload = () => {
                    try {
                        frame.contentWindow.focus();
                        frame.contentWindow.print();
                    } catch (e) {
                        this.generalError = "L'impression n'a pas pu démarrer. Réessayez avec « Réimprimer ».";
                    } finally {
                        setTimeout(() => frame.remove(), 60000);
                    }
                };
                frame.srcdoc = String(html || "");
                document.body.appendChild(frame);
            } catch (e) {
                this.generalError = "Le document n'a pas pu être imprimé. Réessayez avec « Réimprimer ».";
            } finally {
                this.printingId = null;
            }
        },
        startCancel(inv) {
            this.cancelling = inv;
            this.cancelReason = "";
            this.cancelError = "";
        },
        async confirmCancel() {
            if (!this.cancelling || this.cancelBusy) return;
            this.cancelBusy = true;
            this.cancelError = "";
            try {
                await axios.post(`${BASE}/${this.cancelling.id}/cancel`, { cancel_reason: this.cancelReason }, {
                    headers: { "X-Idempotency-Key": this.idemKey("fpc") },
                });
                this.cancelling = null;
                await this.loadList();
            } catch (e) {
                const res = e && e.response;
                this.cancelError = (res && res.data && res.data.errors && res.data.errors.cancel_reason && res.data.errors.cancel_reason[0])
                    || (res && res.data && res.data.message)
                    || "L'annulation a échoué.";
            } finally {
                this.cancelBusy = false;
            }
        },
    },
};
</script>

<style scoped>
.fp-wrap { display: flex; flex-direction: column; gap: 16px; }
.fp-notice { margin: 0; padding: 10px 14px; border-radius: 12px; background: #FFF6E0; border: 1.5px solid #FFB800; color: #1A1A1A; font-size: 13px; }
.fp-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 300px); gap: 16px; align-items: start; }
.fp-card { background: #FFFFFF; border: 1.5px solid #E5E5EF; border-radius: 16px; padding: 16px 18px; min-width: 0; }
.fp-title { margin: 0 0 12px; font-size: 15px; font-weight: 800; color: #1A1A1A; }
.fp-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; min-width: 0; font-size: 13px; font-weight: 700; color: #1B1B3A; }
.fp-field em { color: #F4501E; font-style: normal; }
.fp-field input, .fp-field textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #E5E5EF; border-radius: 10px; font-size: 15px; font-weight: 500; color: #1A1A1A; background: #FFFFFF; }
.fp-field input:focus, .fp-field textarea:focus { outline: none; border-color: #F4501E; box-shadow: 0 0 0 3px rgba(244, 80, 30, .15); }
.fp-field input[aria-invalid="true"] { border-color: #B42318; }
.fp-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
.fp-amount { font-size: 20px !important; font-weight: 800 !important; }
.fp-seg, .fp-pay { display: flex; gap: 8px; flex-wrap: wrap; }
.fp-seg button, .fp-pay button { flex: 1 1 auto; min-height: 44px; padding: 8px 12px; border-radius: 12px; border: 1.5px solid #E5E5EF; background: #FFFFFF; color: #1B1B3A; font-weight: 800; font-size: 14px; cursor: pointer; }
.fp-pay button { min-height: 56px; font-size: 15px; }
.fp-seg button.on, .fp-pay button.on { background: #F4501E; border-color: #F4501E; color: #FFFFFF; box-shadow: 0 4px 12px rgba(244, 80, 30, .22); }
.fp-preview { position: sticky; top: 12px; }
.fp-line { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; color: #1B1B3A; }
.fp-line--ttc { border-top: 1.5px solid #1A1A1A; margin-top: 6px; padding-top: 10px; font-size: 19px; }
.fp-submit { width: 100%; margin-top: 14px; min-height: 56px; border: 0; border-radius: 14px; background: #F4501E; color: #FFFFFF; font-size: 17px; font-weight: 800; cursor: pointer; box-shadow: 0 6px 16px rgba(244, 80, 30, .28); }
.fp-submit:disabled { opacity: .6; cursor: progress; }
.fp-hint, .fp-muted { margin: 10px 0 0; font-size: 12px; color: #6B6B85; }
.fp-err { color: #B42318; font-weight: 600; font-size: 12.5px; }
.fp-err--block { margin: 10px 0 0; }
.fp-table-wrap { overflow-x: auto; }
.fp-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.fp-table th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: #6B6B85; padding: 8px; border-bottom: 1.5px solid #E5E5EF; white-space: nowrap; }
.fp-table td { padding: 9px 8px; border-bottom: 1px solid #F0F0F5; color: #1A1A1A; vertical-align: middle; }
.fp-table .r { text-align: right; white-space: nowrap; }
.fp-table tr.cancelled td { color: #8A8AA0; }
.fp-table tr.cancelled td:not(.fp-actions) { text-decoration: line-through; }
.fp-num { font-weight: 800; white-space: nowrap; }
.fp-badge { margin-left: 6px; padding: 2px 8px; border-radius: 999px; background: #FDECEA; color: #B42318; font-size: 11px; font-weight: 800; text-decoration: none; display: inline-block; }
.fp-actions { display: flex; gap: 6px; justify-content: flex-end; }
.fp-btn { padding: 7px 12px; border-radius: 999px; border: 1.5px solid #E5E5EF; background: #FFFFFF; color: #1B1B3A; font-weight: 700; font-size: 12.5px; cursor: pointer; white-space: nowrap; }
.fp-btn:hover { border-color: #F4501E; color: #F4501E; }
.fp-btn--danger { color: #B42318; }
.fp-btn--danger:hover { border-color: #B42318; color: #B42318; }
.fp-cancel-row td { background: #FFF8F6; }
.fp-cancel-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.fp-cancel-form .fp-field { flex: 1 1 320px; margin: 0; }
@media (max-width: 1200px) {
    .fp-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
    .fp-preview { grid-column: 1 / -1; position: static; }
}
@media (max-width: 760px) {
    .fp-grid, .fp-row { grid-template-columns: minmax(0, 1fr); }
}
</style>
