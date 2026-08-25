<template>
    <LoadingComponent :props="loading" />
    <div class="row">
        <div class="col-12">
            <BreadcrumbComponent />
        </div>
        <div class="col-12">
            <CaisseSecondaryNav current="encaissement" />
        </div>
        <div class="col-12">
            <div class="db-card enc-card">
                <div class="db-card-header border-none enc-header">
                    <div>
                        <h3 class="db-card-title">{{ $t('menu.encaissement') }}</h3>
                        <p class="enc-subtitle">{{ $t('label.encaisser_queue_subtitle') }}</p>
                    </div>
                    <div class="enc-header-actions">
                        <span class="enc-count-chip">{{ orders.length }}</span>
                        <button class="db-btn py-2 text-white bg-primary" @click.prevent="fetchPending">
                            <i class="lab lab-refresh-line lab-font-size-16"></i>
                            <span>{{ $t('button.refresh') }}</span>
                        </button>
                    </div>
                </div>

                <div class="enc-body">
                    <!-- [T-4.1 FAUX-VIDE 2026-08-15] Un fetch en échec avec orders=[] affichait le
                         MÊME ✅ vert que "0 commande à encaisser" réel — le caissier ne pouvait pas
                         distinguer une file réellement vide d'une file INVISIBLE par panne réseau.
                         Un poll silencieux qui échoue alors qu'une liste réelle est déjà affichée ne
                         doit PAS l'effacer (orders.length > 0 garde la priorité sur l'erreur). -->
                    <div v-if="fetchError && orders.length === 0" class="enc-empty enc-error" data-test="enc-fetch-error">
                        <div class="enc-empty-icon">⚠️</div>
                        <p class="enc-empty-title">{{ $t('label.encaisser_queue_error') }}</p>
                        <button class="db-btn py-2 text-white bg-primary" @click.prevent="fetchPending">
                            {{ $t('button.refresh') }}
                        </button>
                    </div>

                    <div v-else-if="orders.length === 0" class="enc-empty" data-test="enc-empty-real">
                        <div class="enc-empty-icon">✅</div>
                        <p class="enc-empty-title">{{ $t('label.encaisser_queue_empty') }}</p>
                    </div>

                    <div v-else class="enc-grid">
                        <div v-for="order in orders" :key="order.id" class="enc-ticket">
                            <div class="enc-ticket-top">
                                <span class="enc-origin-badge" :class="originBadge(order).cls">
                                    {{ originBadge(order).label }}
                                </span>
                                <span class="enc-queue" v-if="order.queue_number">N°{{ order.queue_number }}</span>
                            </div>
                            <div class="enc-ticket-customer">{{ customerName(order) }}</div>
                            <!--
                              [C-001 2026-08-25 · supervisor-caisse round-1 wave C]
                              Cette carte rendait `1× Menu (Frites + Boisson)` et RIEN d'autre :
                              ni la sauce, ni les suppléments facturés, ni les composants de la
                              formule, ni l'instruction « Sans oignons ». Or la donnée était
                              DÉJÀ dans la réponse (OrderItemResource:33-36 et :50) — le
                              template la jetait. C'est l'écran où le caissier fait FACE au
                              client au moment de prendre l'argent, et c'était le seul des
                              trois écrans de commande à ne pas savoir dire de quoi le montant
                              est fait. Lecture par le normaliseur canonique partagé avec la
                              fiche ET le ticket : une seule vérité pour lire une composition.
                            -->
                            <ul class="enc-ticket-items" v-if="order.order_items && order.order_items.length">
                                <li v-for="(it, idx) in order.order_items.slice(0, 4)" :key="idx">
                                    <span class="enc-item-line">{{ it.quantity }}× {{ itemName(it) }}</span>
                                    <ul
                                        v-if="compositionLines(it).length"
                                        class="enc-item-composition"
                                        data-testid="enc-item-composition">
                                        <li
                                            v-for="(line, lineIdx) in compositionLines(it)"
                                            :key="lineIdx"
                                            :class="line.cls">
                                            <span v-if="line.label" class="enc-comp-label">{{ line.label }}:</span>
                                            {{ line.value }}
                                        </li>
                                    </ul>
                                </li>
                                <li v-if="order.order_items.length > 4" class="enc-more">
                                    +{{ order.order_items.length - 4 }}…
                                </li>
                            </ul>
                            <div class="enc-ticket-bottom">
                                <span class="enc-amount">{{ formatPrice(orderAmount(order)) }}</span>
                                <button class="enc-collect-btn" @click.prevent="openEncaissement(order)">
                                    {{ $t('label.encaisser') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shared counter-collect modal (cash / card / mobile / ticket).
             It POSTs admin/pos/counter-collect/{id}/confirm itself; on @confirmed
             we refresh the queue so the now-paid order leaves the list. -->
        <PosCounterCollectModal
            :order="encaisseOrder"
            @confirmed="onEncaisseConfirmed"
            @cancel="encaisseOrder = null" />
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import BreadcrumbComponent from "../components/BreadcrumbComponent";
import CaisseSecondaryNav from "../pos/CaisseSecondaryNav.vue";
import PosCounterCollectModal from "../pos/PosCounterCollectModal.vue";
import appService from "../../../services/appService";
import alertService from "../../../services/alertService";
import axios from "axios";
// [ENCAISSEMENT-TICKET 2026-07-01] Impression du ticket client au pont ESC/POS à l'encaissement.
import { printEscPosViaCaisseBridge } from "../../../helpers/posLocalPrinter";
import orderTypeEnum from "../../../enums/modules/orderTypeEnum";
import { adminPriceMixin } from "../../../helpers/formatPrice";
// [C-001 2026-08-25] Normaliseur CANONIQUE de composition — le même que la fiche
// commande et que le ticket client. Il absorbe l'ancienne forme
// (`{variation_name, name}`) ET celle de l'instantané NF525
// (`{attribute_name, variation_name}`, où les rôles sont inversés), et écarte
// les entrées sans nom. Aucun quatrième lecteur de composition n'est introduit.
import {
    normalizeReceiptVariations,
    normalizeReceiptExtras,
    normalizeReceiptAddons,
} from "../../../helpers/posReceiptBuilder";
import { onEvents } from "../../../services/eventContract";

export default {
    name: "EncaissementComponent",
    mixins: [adminPriceMixin],
    components: {
        CaisseSecondaryNav,
        LoadingComponent,
        BreadcrumbComponent,
        PosCounterCollectModal,
    },
    data() {
        return {
            loading: { isActive: false },
            orders: [],
            fetchError: false,
            encaisseOrder: null,
            pollTimer: null,
            enums: { orderTypeEnum },
        };
    },
    mounted() {
        this.fetchPending();
        // Light poll so a cashier on this screen sees newly-arrived Borne
        // orders without a manual refresh. Cleared on unmount.
        this.pollTimer = setInterval(() => this.fetchPending(true), 20000);
        // [F-W5-01 sync heal 2026-06-03] Real-time push so newly-arrived Borne
        // orders + counter-collected ones reflect sub-second; the 20s poll above
        // stays as the WS-down fallback (mirrors KDS/OSS/tracker pattern).
        this.subscribeEcho();
    },
    beforeUnmount() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        this.unsubscribeEcho();
    },
    computed: {
        // [RECEIPT-NO-AUTO 2026-07-24] Flag OPT-IN d'auto-impression du reçu CLIENT
        // (défaut FALSE). Spec owner : à l'encaissement, on n'imprime PLUS le ticket
        // client automatiquement — les boutons manuels de la modale (printTicket)
        // restent. Clé absente → false → pas d'auto.
        autoPrintClientReceipt() {
            return !!(typeof window !== 'undefined' && window.foodkingConfig?.printing?.autoPrintClientReceipt);
        },
    },
    methods: {
        fetchPending(silent = false) {
            if (!silent) this.loading.isActive = true;
            return axios.get('admin/pos/counter-collect/pending').then((res) => {
                this.orders = res.data?.data || [];
                this.fetchError = false;
                this.loading.isActive = false;
            }).catch(() => {
                // [T-4.1 FAUX-VIDE] Ne JAMAIS laisser une panne réseau se déguiser en
                // "file vide ✅" — cf. garde du template ci-dessus.
                this.fetchError = true;
                this.loading.isActive = false;
            });
        },
        // [F-W5-01 sync heal 2026-06-03] Echo subscription mirrors KDS/OSS/tracker:
        // branch staff (branch_id>0) get sub-second updates; admin (branch 0) keeps
        // the 20s poll fallback. Re-fetch on OrderCreated (new Borne arrival),
        // OrderPaidAtCounter (collected → drops off), OrderStatusChanged (cancel/refund).
        // [F-W5-01] Robust branch-id resolution mirrors PreparingAndReadyComponent:
        // the auth store module is NOT namespaced, so the bare `authBranchId` getter is
        // the canonical path; the namespaced + state paths are belt-and-suspenders.
        authBranchId() {
            const candidates = [
                this.$store.getters['auth/authBranchId'],
                this.$store.getters.authBranchId,
                this.$store.state?.auth?.authBranchId,
            ];
            for (const c of candidates) {
                if (c === '' || c === null || typeof c === 'undefined') continue;
                const v = parseInt(c, 10);
                if (Number.isFinite(v)) return v;
            }
            return 0;
        },
        subscribeEcho() {
            if (!window.Echo) return;
            const branchId = this.authBranchId();
            if (branchId <= 0) return;
            this.unsubscribeEcho();
            try {
                this._eventSub = onEvents(branchId, [
                    { broadcastAs: 'OrderCreated', handler: () => this.fetchPending(true) },
                    { broadcastAs: 'OrderPaidAtCounter', handler: () => this.fetchPending(true) },
                    { broadcastAs: 'OrderStatusChanged', handler: () => this.fetchPending(true) },
                ]);
            } catch (e) {
                console.warn('[Encaissement] Echo subscription failed:', e.message);
            }
        },
        unsubscribeEcho() {
            try { this._eventSub?.unsubscribe(); } catch (_) { /* noop */ }
            this._eventSub = null;
        },
        // Origin resolver — source_surface is the reliable signal. Today the
        // pending endpoint returns Borne (kiosk) orders; once delta-(B) routes
        // POS walk-in through PENDING_COUNTER, Caisse rows appear here too.
        originBadge(order) {
            const surface = String(order.source_surface || '').toLowerCase();
            if (surface === 'kiosk') return { label: this.$t('label.kiosk'), cls: 'origin-borne' };
            if (surface === 'pos') return { label: this.$t('label.caisse'), cls: 'origin-caisse' };
            if (surface === 'web' || surface === 'app' || surface === 'mobile') {
                return { label: this.$t('label.online'), cls: 'origin-online' };
            }
            // [AUDIT-F P2-2 2026-08-06] Origines manquantes : une commande TÉLÉPHONE
            // ou LIVRAISON tombait dans le repli « Borne » — le caissier lisait une
            // origine fausse sur la file d'encaissement.
            if (surface === 'phone') return { label: this.$t('label.phone'), cls: 'origin-caisse' };
            if (surface === 'delivery') return { label: this.$t('label.delivery'), cls: 'origin-online' };
            return { label: this.$t('label.kiosk'), cls: 'origin-borne' };
        },
        customerName(order) {
            return order.user?.name || order.customer_name || this.$t('label.guest');
        },
        itemName(it) {
            return it.item_name || it.name || it.orderItem?.name || it.order_item?.name || '';
        },
        // [C-001 2026-08-25] Lecture de composition — délégation stricte au
        // normaliseur canonique (posReceiptBuilder), exactement comme
        // PosOrderShowComponent et ReceiptComponent.
        normalizedVariations(it) {
            return normalizeReceiptVariations(it?.item_variations);
        },
        normalizedExtras(it) {
            return normalizeReceiptExtras(it?.item_extras);
        },
        normalizedAddons(it) {
            return normalizeReceiptAddons(it?.item_addons);
        },
        /**
         * [C-001 2026-08-25] Les lignes de composition d'un article, prêtes à
         * rendre : `[{ label, value, cls }]`. Chaque bloc n'existe QUE s'il
         * porte réellement quelque chose — pas de libellé qui survive à sa
         * valeur (le défaut jumeau C-003/C-004 relevé sur la fiche).
         */
        compositionLines(it) {
            const lines = [];

            const variations = this.normalizedVariations(it);
            if (variations.length) {
                lines.push({
                    label: '',
                    value: variations.map((v) => (v.label ? `${v.label}: ${v.name}` : v.name)).join(', '),
                    cls: 'enc-comp-variation',
                });
            }

            const extras = this.normalizedExtras(it);
            if (extras.length) {
                lines.push({
                    label: this.$t('label.extras'),
                    value: extras.map((e) => (e.quantity > 1 ? `${e.name} ×${e.quantity}` : e.name)).join(', '),
                    cls: 'enc-comp-extra',
                });
            }

            // Suppléments de FORMULE (frites, boisson d'un menu) : facturés par
            // CompositionSnapshotBuilder et imprimés sur le ticket client — donc
            // dus au client qui paie, donc lisibles au comptoir.
            const addons = this.normalizedAddons(it);
            if (addons.length) {
                lines.push({
                    label: this.$t('label.addons'),
                    value: addons.map((a) => (a.quantity > 1 ? `${a.name} ×${a.quantity}` : a.name)).join(', '),
                    cls: 'enc-comp-addon',
                });
            }

            // `instruction` vaut NULL en base — un test `!== ''` laisserait
            // passer une ligne vide (défaut déjà corrigé sur la fiche).
            const instruction = typeof it?.instruction === 'string' ? it.instruction.trim() : '';
            if (instruction !== '') {
                lines.push({
                    label: this.$t('label.instruction'),
                    value: instruction,
                    cls: 'enc-comp-note',
                });
            }

            return lines;
        },
        orderAmount(order) {
            return order.cash_pending_amount ?? order.total ?? order.order_amount ?? 0;
        },
        openEncaissement(order) {
            if (!order || !order.id) return;
            const amount = this.orderAmount(order);
            // PosCounterCollectModal reads order.total — map the amount due onto it.
            this.encaisseOrder = { ...order, total: amount };
        },
        async onEncaisseConfirmed(payload) {
            this.encaisseOrder = null;
            alertService.success(this.$t('label.encaisser_success', { order: '' }));
            // [ENCAISSEMENT-TICKET 2026-07-01][PRINT-INSTANT 2026-07-06] Imprimer le TICKET
            // CLIENT via le pont ESC/POS — lancé AVANT/EN PARALLÈLE du refresh de la liste
            // (fire-and-forget, plus d'await en série). Best-effort — pont 202 immédiat.
            // [RECEIPT-NO-AUTO 2026-07-24] Auto-impression désormais gatée sur le flag
            // OPT-IN (défaut FALSE, spec owner) : par défaut on NE déclenche PLUS le pont,
            // les boutons manuels de la modale (printTicket) restent l'unique voie.
            const orderId = payload?.orderId ?? payload?.order_id ?? null;
            if (orderId && this.autoPrintClientReceipt) {
                this._lastEncaissePrint = axios
                    .get(`admin/pos/orders/${orderId}/escpos`, { params: { ticket: 'client' } })
                    .then((res) => {
                        const b64 = res?.data?.escpos_b64;
                        return b64 ? printEscPosViaCaisseBridge(b64) : null;
                    })
                    .catch(() => null); /* pont indisponible : ignoré (l'encaissement a réussi) */
            }
            this.fetchPending();
        },
    },
};
</script>

<style scoped>
.enc-card {
    border-radius: var(--pos-v5-radius-lg);
    box-shadow: var(--pos-v5-shadow-md);
    border: 1px solid var(--pos-v5-border);
    background: var(--pos-v5-bg-panel);
    overflow: hidden;
}
.enc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(180deg, var(--pos-v5-brand-red-faint), var(--pos-v5-bg-panel) 80%);
    border-bottom: 1px solid var(--pos-v5-border);
    padding: var(--pos-v5-space-4) var(--pos-v5-space-5);
}
.db-card-title {
    font-family: var(--pos-v5-font-sans);
    font-size: var(--pos-v5-text-h5);
    font-weight: var(--pos-v5-weight-extrabold);
    color: var(--pos-v5-ink);
}
.enc-subtitle { color: var(--pos-v5-ink-soft); font-size: 0.85rem; margin-top: 0.15rem; }
.enc-header-actions { display: flex; align-items: center; gap: 0.75rem; }
.enc-count-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.2rem;
    height: 2.2rem;
    border-radius: 9999px;
    background: var(--pos-v5-brand-red);
    color: #fff;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.enc-body { padding: var(--pos-v5-space-5); }
.enc-empty { text-align: center; padding: 3rem 1rem; color: var(--pos-v5-ink-soft); }
.enc-empty-icon { font-size: 2.5rem; }
.enc-empty-title { margin-top: 0.75rem; font-size: 1.05rem; font-weight: 600; }

.enc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 1rem;
}
.enc-ticket {
    border: 1px solid var(--pos-v5-border);
    border-radius: var(--pos-v5-radius-md);
    background: #fff;
    padding: 0.9rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    box-shadow: var(--pos-v5-shadow-sm);
}
.enc-ticket-top { display: flex; align-items: center; justify-content: space-between; }
.enc-origin-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 9999px;
    padding: 0.12rem 0.55rem;
    font-size: 0.74rem;
    font-weight: 700;
    border: 1px solid transparent;
}
.origin-borne { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
.origin-caisse { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.origin-online { background: #f5f3ff; color: #5b21b6; border-color: #ddd6fe; }
.enc-queue {
    font-weight: 800;
    color: #9a3412;
    font-variant-numeric: tabular-nums;
}
.enc-ticket-customer { font-weight: 600; color: var(--pos-v5-ink); }
.enc-ticket-items { list-style: none; padding: 0; margin: 0; font-size: 0.82rem; color: var(--pos-v5-ink-soft); }
.enc-ticket-items li { line-height: 1.4; }
.enc-more { font-style: italic; }

/* [C-001 2026-08-25] Composition d'une ligne, lisible d'un coup d'œil avec le
   client en face : l'article reste l'élément fort, la composition s'indente en
   retrait sous lui (liseré gauche = « ceci appartient à la ligne au-dessus »).
   `word-break` parce qu'une carte fait ~230px et qu'un nom de formule long ne
   doit pas déborder ni tronquer une valeur due au client. */
.enc-item-line { font-weight: 600; color: var(--pos-v5-ink); }
.enc-item-composition {
    list-style: none;
    margin: 0.1rem 0 0.35rem 0.35rem;
    padding: 0 0 0 0.5rem;
    border-left: 2px solid var(--pos-v5-border);
    font-size: 0.76rem;
    line-height: 1.35;
    color: var(--pos-v5-ink-soft);
    overflow-wrap: anywhere;
}
.enc-comp-label { font-weight: 600; }
/* L'instruction du client est la ligne qu'un caissier ne doit PAS rater. */
.enc-comp-note { color: #9a3412; font-weight: 600; }
.enc-ticket-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    /* [C-001 2026-08-25] `margin-top: auto` : les cartes n'ont plus la même
       hauteur de contenu depuis qu'elles portent la composition. Sans ça, le
       montant et le bouton « Encaisser » flottaient à des hauteurs différentes
       d'une carte à l'autre — or c'est la ligne que le caissier vise. On les
       pousse au bas de la carte (`.enc-ticket` est déjà un flex colonne). */
    margin-top: auto;
    padding-top: 0.6rem;
    border-top: 1px dashed var(--pos-v5-border);
}
.enc-amount { font-weight: 800; font-size: 1.1rem; color: var(--pos-v5-ink); font-variant-numeric: tabular-nums; }
.enc-collect-btn {
    background: var(--pos-v5-brand-red);
    color: #fff;
    border: none;
    border-radius: var(--pos-v5-radius-md);
    padding: 0.5rem 1.1rem;
    font-weight: 800;
    cursor: pointer;
    transition: background var(--pos-v5-duration-fast) var(--pos-v5-ease-standard);
}
.enc-collect-btn:hover { background: var(--pos-v5-brand-red-dark); }
:deep(.db-btn.bg-primary) {
    background: var(--pos-v5-brand-red) !important;
    border-radius: var(--pos-v5-radius-md);
    font-weight: var(--pos-v5-weight-bold);
}
:deep(.db-btn.bg-primary:hover) { background: var(--pos-v5-brand-red-dark) !important; }
</style>
