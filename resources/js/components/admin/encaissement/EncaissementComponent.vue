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

                <!--
                  [GOAL CAISSE/CUISINE #3 2026-10-02] File « en attente d'encaissement » : par DÉFAUT la
                  journée de service courante seulement (nouveau jour = liste vide). Les commandes d'hier et
                  d'avant, jamais encaissées, vivent dans « Jours précédents » — d'où on peut les purger
                  (une par une ou toutes), après confirmation. Une purge ANNULE (jamais de suppression d'une
                  commande payée / fiscalisée) et laisse une trace d'audit.
                -->
                <div class="enc-scope-bar" role="tablist" :aria-label="$t('label.enc_scope_aria')">
                    <button
                        type="button" role="tab" class="enc-scope-tab"
                        :class="{ 'is-active': scope === 'today' }"
                        :aria-selected="scope === 'today' ? 'true' : 'false'"
                        data-testid="enc-scope-today"
                        @click="setScope('today')"
                    >{{ $t('label.enc_scope_today') }}</button>
                    <button
                        type="button" role="tab" class="enc-scope-tab"
                        :class="{ 'is-active': scope === 'previous' }"
                        :aria-selected="scope === 'previous' ? 'true' : 'false'"
                        data-testid="enc-scope-previous"
                        @click="setScope('previous')"
                    >
                        {{ $t('label.enc_scope_previous') }}
                        <span v-if="previousCount > 0" class="enc-scope-badge" data-testid="enc-previous-count">{{ previousCount }}</span>
                    </button>
                    <button
                        v-if="scope === 'previous' && orders.length > 0"
                        type="button"
                        class="enc-purge-all-btn"
                        data-testid="enc-purge-all"
                        @click="askPurge(null)"
                    >{{ $t('label.enc_purge_all', { n: orders.length }) }}</button>
                </div>
                <p v-if="scope === 'previous'" class="enc-scope-hint" data-testid="enc-previous-hint">{{ $t('label.enc_previous_hint') }}</p>

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
                        <p class="enc-empty-title">{{ scope === 'previous' ? $t('label.enc_previous_empty') : $t('label.encaisser_queue_empty') }}</p>
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
                                <!--
                                  [AUDIT-SUPERVISEUR 2026-08-26 · D-007] TROIS BOUTONS
                                  IDENTIQUES POUR TROIS MONTANTS DIFFÉRENTS.
                                  Au lecteur d'écran comme au clavier, la file d'encaissement
                                  annonçait « Encaisser, bouton » trois fois de suite — pour
                                  11,10 €, 8,30 € et 14,60 €. C'est le geste qui PREND l'argent
                                  du client : son nom ne doit pas être ambigu.
                                  Le même dépôt fait déjà l'inverse ailleurs (le bouton de
                                  réimpression de l'historique porte title ET aria-label).
                                  Un data-testid aussi : sans lui, aucun test ne peut viser
                                  UNE commande en particulier dans cette file.
                                -->
                                <button
                                    class="enc-collect-btn"
                                    :aria-label="`${$t('label.encaisser')} ${order.order_serial_no || order.id} — ${formatPrice(orderAmount(order))}`"
                                    :data-testid="`enc-collect-${order.id}`"
                                    @click.prevent="openEncaissement(order)"
                                >
                                    {{ $t('label.encaisser') }}
                                </button>
                                <button
                                    v-if="scope === 'previous'"
                                    type="button"
                                    class="enc-purge-btn"
                                    :aria-label="`${$t('label.enc_purge_one')} ${order.order_serial_no || order.id}`"
                                    :data-testid="`enc-purge-${order.id}`"
                                    @click.prevent="askPurge(order)"
                                >{{ $t('label.enc_purge_one') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- [GOAL #3 2026-10-02] Confirmation OBLIGATOIRE avant toute purge. -->
        <div v-if="purgeTarget" class="enc-confirm-overlay" data-testid="enc-purge-confirm" @click.self="cancelPurge">
            <div class="enc-confirm" role="alertdialog" aria-modal="true" :aria-label="$t('label.enc_purge_title')">
                <h4 class="enc-confirm-title">{{ $t('label.enc_purge_title') }}</h4>
                <p class="enc-confirm-body" data-testid="enc-purge-summary">{{ purgeSummary }}</p>
                <p class="enc-confirm-note">{{ $t('label.enc_purge_note') }}</p>
                <label class="enc-confirm-label" for="encPurgeReason">{{ $t('label.enc_purge_reason') }}</label>
                <input id="encPurgeReason" v-model="purgeReason" class="enc-confirm-input" type="text" maxlength="255" data-testid="enc-purge-reason" />
                <div class="enc-confirm-actions">
                    <button type="button" class="enc-confirm-cancel" data-testid="enc-purge-cancel" :disabled="purging" @click="cancelPurge">{{ $t('button.cancel') }}</button>
                    <button type="button" class="enc-confirm-ok" data-testid="enc-purge-ok" :disabled="purging || purgeReason.trim().length < 3" @click="confirmPurge">{{ $t('label.enc_purge_confirm') }}</button>
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
            // [GOAL #3 2026-10-02] 'today' (défaut) | 'previous' ; compteur du badge fourni par l'API.
            scope: 'today',
            previousCount: 0,
            purgeTarget: null, // { order: Order|null } — null order = toutes les anciennes
            purgeReason: '',
            purging: false,
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
        purgeSummary() {
            const t = this.purgeTarget;
            if (!t) return '';
            if (t.order) {
                return this.$t('label.enc_purge_summary_one', {
                    order: t.order.order_serial_no || t.order.id,
                    amount: this.formatPrice(this.orderAmount(t.order)),
                });
            }
            const total = this.orders.reduce((sum, o) => sum + (parseFloat(this.orderAmount(o)) || 0), 0);
            return this.$t('label.enc_purge_summary_all', { n: this.orders.length, amount: this.formatPrice(total) });
        },
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
            return axios.get('admin/pos/counter-collect/pending', { params: { scope: this.scope || 'today' } }).then((res) => {
                this.orders = res.data?.data || [];
                this.previousCount = Number(res.data?.meta?.previous_count || 0);
                this.fetchError = false;
                this.loading.isActive = false;
            }).catch(() => {
                // [T-4.1 FAUX-VIDE] Ne JAMAIS laisser une panne réseau se déguiser en
                // "file vide ✅" — cf. garde du template ci-dessus.
                this.fetchError = true;
                this.loading.isActive = false;
            });
        },
        setScope(scope) {
            if (scope !== 'today' && scope !== 'previous') return;
            this.scope = scope;
            this.orders = [];
            return this.fetchPending();
        },
        // [GOAL #3 2026-10-02] Demande de purge : `order` = une commande, null = toutes les anciennes.
        askPurge(order) {
            this.purgeReason = this.$t('label.enc_purge_reason_default');
            this.purgeTarget = { order: order || null };
        },
        cancelPurge() {
            if (this.purging) return;
            this.purgeTarget = null;
        },
        async confirmPurge() {
            if (!this.purgeTarget || this.purging || this.purgeReason.trim().length < 3) return;
            const one = this.purgeTarget.order;
            const body = {
                confirm: true,
                reason: this.purgeReason.trim(),
                ...(one ? { ids: [one.id] } : { all: true }),
            };
            const minute = Math.floor(Date.now() / 60000);
            this.purging = true;
            try {
                const res = await axios.post('admin/pos/counter-collect/purge-previous', body, {
                    headers: { 'X-Idempotency-Key': `pos-purge-previous-${one ? one.id : 'all'}-${minute}` },
                });
                const purged = Number(res.data?.purged || 0);
                const skipped = Array.isArray(res.data?.skipped) ? res.data.skipped.length : 0;
                if (purged > 0) alertService.success(this.$t('label.enc_purge_done', { n: purged }));
                if (skipped > 0) alertService.warning(this.$t('label.enc_purge_skipped', { n: skipped }));
                this.purgeTarget = null;
                await this.fetchPending();
            } catch (err) {
                alertService.error(err?.response?.data?.message || this.$t('label.enc_purge_error'));
            } finally {
                this.purging = false;
            }
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
            return normalizeReceiptExtras(it?.item_extras, it?.instruction);
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
/* [GOAL #3 2026-10-02] Filtre « Aujourd'hui | Jours précédents » + purge. Texte ≥ 7:1, cibles ≥ 44 px. */
.enc-scope-bar { display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem var(--pos-v5-space-5) 0; flex-wrap: wrap; }
.enc-scope-tab {
    min-height: 44px; padding: 0 1.1rem; border-radius: 9999px; border: 2px solid #111827;
    background: #fff; color: #111827; font-weight: 700; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer;
}
.enc-scope-tab.is-active { background: #111827; color: #fff; }
.enc-scope-badge {
    display: inline-flex; min-width: 1.6rem; height: 1.6rem; padding: 0 0.4rem; align-items: center; justify-content: center;
    border-radius: 9999px; background: #B91C1C; color: #fff; font-size: 0.8rem; font-weight: 800; font-variant-numeric: tabular-nums;
}
.enc-purge-all-btn {
    margin-inline-start: auto; min-height: 44px; padding: 0 1.1rem; border-radius: 0.5rem; border: 2px solid #7F1D1D;
    background: #7F1D1D; color: #fff; font-weight: 700; cursor: pointer;
}
.enc-scope-hint { padding: 0.5rem var(--pos-v5-space-5) 0; color: #374151; font-size: 0.9rem; }
.enc-purge-btn {
    min-height: 44px; padding: 0 0.9rem; border-radius: 0.5rem; border: 2px solid #7F1D1D; background: #fff; color: #7F1D1D; font-weight: 700; cursor: pointer;
}
.enc-ticket-bottom { flex-wrap: wrap; gap: 0.5rem; }
.enc-confirm-overlay { position: fixed; inset: 0; background: rgba(17, 24, 39, 0.65); display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 1rem; }
.enc-confirm { background: #fff; color: #111827; border-radius: 0.75rem; padding: 1.25rem 1.5rem; max-width: 30rem; width: 100%; box-shadow: 0 20px 50px rgba(0,0,0,.35); }
.enc-confirm-title { font-size: 1.15rem; font-weight: 800; margin-bottom: 0.5rem; }
.enc-confirm-body { font-weight: 700; margin-bottom: 0.4rem; }
.enc-confirm-note { color: #374151; font-size: 0.9rem; margin-bottom: 0.75rem; }
.enc-confirm-label { display: block; font-weight: 600; font-size: 0.9rem; margin-bottom: 0.25rem; }
.enc-confirm-input { width: 100%; min-height: 44px; border: 2px solid #111827; border-radius: 0.5rem; padding: 0 0.75rem; }
.enc-confirm-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; }
.enc-confirm-cancel { min-height: 44px; padding: 0 1.1rem; border-radius: 0.5rem; border: 2px solid #111827; background: #fff; color: #111827; font-weight: 700; cursor: pointer; }
.enc-confirm-ok { min-height: 44px; padding: 0 1.1rem; border-radius: 0.5rem; border: 2px solid #7F1D1D; background: #7F1D1D; color: #fff; font-weight: 700; cursor: pointer; }
.enc-confirm-ok:disabled, .enc-confirm-cancel:disabled { opacity: 0.55; cursor: not-allowed; }
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
