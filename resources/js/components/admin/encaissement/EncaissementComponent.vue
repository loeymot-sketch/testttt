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
                    <!-- [GOAL REMARQUES 2026-10-03 · R-059] « ça va dans commande rater ça reste 24 heures ». -->
                    <button
                        type="button" role="tab" class="enc-scope-tab"
                        :class="{ 'is-active': scope === 'missed' }"
                        :aria-selected="scope === 'missed' ? 'true' : 'false'"
                        data-testid="enc-scope-missed"
                        @click="setScope('missed')"
                    >{{ $t('label.enc_scope_missed') }}</button>
                    <button
                        v-if="scope === 'previous' && orders.length > 0"
                        type="button"
                        class="enc-purge-all-btn"
                        data-testid="enc-purge-all"
                        @click="askPurge(null)"
                    >{{ $t('label.enc_purge_all', { n: orders.length }) }}</button>
                    <!-- [GOAL REMARQUES 2026-10-03 · R-060] « Dans l'attente je veux tout supprimer » : les
                         commandes TÉLÉPHONE du jour, d'un geste confirmé. Jamais la borne ni le site. -->
                    <button
                        v-if="scope === 'today' && phoneOrders.length > 0"
                        type="button"
                        class="enc-purge-all-btn"
                        data-testid="enc-purge-phone"
                        @click="askPurgePhoneToday"
                    >{{ $t('label.enc_purge_phone', { n: phoneOrders.length }) }}</button>
                </div>
                <p v-if="scope === 'previous'" class="enc-scope-hint" data-testid="enc-previous-hint">{{ $t('label.enc_previous_hint') }}</p>
                <p v-if="scope === 'missed'" class="enc-scope-hint" data-testid="enc-missed-hint">{{ $t('label.enc_missed_hint') }}</p>

                <div class="enc-body">
                    <!-- [GOAL REMARQUES 2026-10-03 · R-059] Commandes ratées : LECTURE SEULE, aucun bouton. -->
                    <div v-if="scope === 'missed'" class="enc-missed" data-testid="enc-missed-list">
                        <div v-if="ratees.length === 0" class="enc-empty" data-testid="enc-missed-empty">
                            <p class="enc-empty-title">{{ $t('label.enc_missed_empty') }}</p>
                        </div>
                        <div v-for="r in ratees" :key="r.id" class="enc-missed-row" :data-testid="`enc-missed-${r.id}`">
                            <div class="enc-missed-head">
                                <span class="enc-missed-num">N° {{ r.numero }}</span>
                                <span v-if="r.client" class="enc-missed-client">{{ r.client }}</span>
                                <span v-if="r.telephone" class="enc-missed-phone">{{ r.telephone }}</span>
                                <span class="enc-missed-time">{{ $t('label.enc_missed_cancelled_at', { time: heureCourte(r.annulee_a) }) }}</span>
                                <span class="enc-missed-total">{{ formatPrice(r.total) }}</span>
                            </div>
                            <ul class="enc-missed-items">
                                <li v-for="(p, i) in r.produits" :key="i">{{ p }}</li>
                            </ul>
                        </div>
                    </div>
                    <!-- [T-4.1 FAUX-VIDE 2026-08-15] Un fetch en échec avec orders=[] affichait le
                         MÊME ✅ vert que "0 commande à encaisser" réel — le caissier ne pouvait pas
                         distinguer une file réellement vide d'une file INVISIBLE par panne réseau.
                         Un poll silencieux qui échoue alors qu'une liste réelle est déjà affichée ne
                         doit PAS l'effacer (orders.length > 0 garde la priorité sur l'erreur). -->
                    <div v-else-if="fetchError && orders.length === 0" class="enc-empty enc-error" data-test="enc-fetch-error">
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
                            <!--
                              [CAISSE 2026-09-29 · demande propriétaire] « Je veux pas cliquer sur
                              chacune et mettre le justificatif pour pouvoir annuler : directement X
                              et ça s'annule. »

                              Le backend l'acceptait DÉJÀ : POST counter-collect/{id}/cancel valide
                              `reason` en `nullable`. Ce qui manquait n'était pas la permission, c'était
                              le bouton — cet écran n'en avait aucun, et la seule autre interface câblée
                              sur cet endpoint (le panneau borne-espèces de la caisse) ré-imposait un
                              motif de 3 caractères CÔTÉ CLIENT, alors que le serveur ne le demande pas.

                              Confirmation en DEUX TEMPS : pas de modale, rien à taper, mais un doigt
                              qui ripe sur la carte d'un client en train d'arriver ne lui annule pas sa
                              commande. Le second clic doit tomber dans les 4 s.

                              [HEAL visuel 2026-09-29] Première version : croix en `position:absolute`
                              au coin de la carte. Capture relue — elle CHEVAUCHAIT le badge de date
                              (« 25/0… » tronqué) et, une fois armée, recouvrait le numéro de commande
                              d'un bloc rouge. Le caissier perdait de vue l'identifiant au moment
                              précis où il confirme une annulation. La croix est donc maintenant un
                              élément NORMAL de la ligne d'en-tête (qui est déjà en flex), et l'état
                              armé s'affiche sur une bande dédiée sous l'en-tête : plus aucun
                              recouvrement possible, quelle que soit la largeur du libellé.
                            -->
                            <div class="enc-ticket-top">
                                <span class="enc-origin-badge" :class="originBadge(order).cls">
                                    {{ originBadge(order).label }}
                                </span>
                                <span class="enc-queue" v-if="order.queue_number">N°{{ order.queue_number }}</span>
                                <!--
                                  [QA 2026-09-28 · P0-18] Le numéro court est un compteur
                                  QUOTIDIEN par branche, et cette file n'a volontairement PAS
                                  de filtre de journée (une commande non encaissée doit rester
                                  visible). Deux commandes de jours différents peuvent donc
                                  porter le MÊME N° ici — le rapport l'observe en vrai
                                  (A0041 = 22/09 à 10,80 € ET 24/09 à 11,50 €). Sans date, le
                                  caissier encaisse potentiellement la mauvaise commande.
                                  Vide pour une commande du jour : aucun bruit visuel.
                                -->
                                <span
                                    v-if="queueDateBadge(order)"
                                    class="enc-queue-date-badge"
                                    :data-testid="`enc-queue-date-${order.id}`"
                                >{{ queueDateBadge(order) }}</span>
                                <!-- [E2E stores · B2-R2-11 · 2026-10-02] Commande PROGRAMMÉE : l'heure de
                                     retrait choisie par le client (même règle que la fiche, serveur). -->
                                <span
                                    v-if="order.scheduled_at && order.delivery_time"
                                    class="enc-queue-date-badge"
                                    :data-testid="`enc-scheduled-${order.id}`"
                                >⏰ {{ order.delivery_time }}</span>
                                <button
                                    class="enc-cancel-x"
                                    :class="{ 'enc-cancel-x--armed': pendingCancelId === order.id }"
                                    :disabled="cancellingId === order.id"
                                    title="Annuler cette commande (client non venu)"
                                    :aria-label="`Annuler la commande ${order.order_serial_no || order.id} — client non venu`"
                                    :data-testid="`enc-cancel-${order.id}`"
                                    @click.prevent="cancelOrder(order)"
                                >
                                    <span v-if="cancellingId === order.id">…</span>
                                    <span v-else aria-hidden="true">✕</span>
                                </button>
                            </div>
                            <!-- Bande de confirmation : n'existe QUE sur la carte armée. -->
                            <div
                                v-if="pendingCancelId === order.id"
                                class="enc-cancel-confirm"
                                :data-testid="`enc-cancel-confirm-${order.id}`"
                                role="alert"
                            >
                                <span class="enc-cancel-confirm-txt">Annuler cette commande ?</span>
                                <button
                                    class="enc-cancel-yes"
                                    :data-testid="`enc-cancel-yes-${order.id}`"
                                    @click.prevent="cancelOrder(order)"
                                >Oui, client non venu</button>
                                <button
                                    class="enc-cancel-no"
                                    :data-testid="`enc-cancel-no-${order.id}`"
                                    @click.prevent="abortCancel"
                                >Non</button>
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
// [QA 2026-09-28 P0-18] Règle partagée avec PosComponent — une seule définition.
import { queueNumberDateBadge } from "../../../helpers/queueNumberDateBadge";
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
            ratees: [], // [R-059] commandes téléphone annulées < 24 h (lecture seule)
            purgeTarget: null, // { order: Order|null } — null order = toutes les anciennes
            purgeReason: '',
            purging: false,
            encaisseOrder: null,
            pollTimer: null,
            enums: { orderTypeEnum },
            // [CAISSE 2026-09-29] Annulation directe (croix). (Le nettoyage des jours passés vit dans
            // l'onglet « Jours précédents » depuis le 02/10 — un seul chemin, R-061.)
            pendingCancelId: null,   // commande dont la croix est ARMÉE (2e clic attendu)
            pendingCancelTimer: null,
            cancellingId: null,      // requête en vol, pour ne pas double-annuler
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
        // [CAISSE 2026-09-29] Compte à blanc : le bouton de nettoyage ne s'affiche
        // que s'il a réellement quelque chose à faire.
    },
    beforeUnmount() {
        if (this.pendingCancelTimer) {
            clearTimeout(this.pendingCancelTimer);
            this.pendingCancelTimer = null;
        }
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        this.unsubscribeEcho();
    },
    computed: {
        // [GOAL REMARQUES 2026-10-03 · R-060] Commandes TÉLÉPHONE de la file du jour (seules concernées
        // par « Supprimer les commandes téléphone »).
        phoneOrders() {
            return this.orders.filter((o) => String(o.source_surface || '').toLowerCase() === 'phone');
        },
        purgeSummary() {
            const t = this.purgeTarget;
            if (!t) return '';
            if (t.phoneToday) {
                const totalTel = this.phoneOrders.reduce((sum, o) => sum + (parseFloat(this.orderAmount(o)) || 0), 0);
                return this.$t('label.enc_purge_summary_phone', { n: this.phoneOrders.length, amount: this.formatPrice(totalTel) });
            }
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
        /**
         * [CAISSE 2026-09-29] La croix : annule UNE commande, sans motif à saisir.
         *
         * Premier clic = arme le bouton (« Confirmer ? ») pendant 4 s ; second clic =
         * annule. Pas de `window.confirm` : une modale native bloque la page entière,
         * et le propriétaire veut justement aller vite.
         *
         * Le motif envoyé est FIXE. Le serveur l'accepte en `nullable`, mais laisser
         * une trace vide dans la chaîne d'audit serait un cadeau empoisonné à qui
         * relira l'historique dans six mois : « Client non venu » dit ce qui s'est
         * réellement passé, sans rien demander au caissier.
         */
        /** Désarme la croix : « Non » sur la bande de confirmation. */
        abortCancel() {
            if (this.pendingCancelTimer) clearTimeout(this.pendingCancelTimer);
            this.pendingCancelTimer = null;
            this.pendingCancelId = null;
        },
        cancelOrder(order) {
            if (!order || this.cancellingId === order.id) return;

            if (this.pendingCancelId !== order.id) {
                this.pendingCancelId = order.id;
                if (this.pendingCancelTimer) clearTimeout(this.pendingCancelTimer);
                this.pendingCancelTimer = setTimeout(() => { this.pendingCancelId = null; }, 4000);
                return;
            }

            if (this.pendingCancelTimer) clearTimeout(this.pendingCancelTimer);
            this.pendingCancelId = null;
            this.cancellingId = order.id;

            // [HEAL 2026-09-29] `counter-collect/*/cancel` figure dans
            // `config('idempotency.required_routes')` : SANS l'en-tête, le middleware
            // répond 422 « Header X-Idempotency-Key requis » et la croix ne marche
            // pas du tout. Défaut trouvé par la suite complète AVANT tout déploiement,
            // pas par l'écran — le contrôle navigateur n'avait fait qu'ARMER la croix,
            // jamais confirmer, donc il ne l'aurait pas vu.
            // Clé STABLE par commande : un second clic ou un rejeu réseau rejoue la
            // réponse mise en cache au lieu d'annuler une deuxième fois.
            axios.post(`admin/pos/counter-collect/${order.id}/cancel`, {
                reason: 'Client non venu',
            }, {
                headers: { 'X-Idempotency-Key': `enc-cancel-${order.id}` },
            }).then(() => {
                // Retrait immédiat de la carte : le caissier doit VOIR la file
                // raccourcir. Le fetch qui suit fait foi.
                this.orders = this.orders.filter((o) => o.id !== order.id);
            }).catch(() => {
                // Un échec ne doit jamais se déguiser en succès : on laisse la carte
                // en place et on relit la file, qui dira la vérité.
                this.fetchError = true;
            }).finally(() => {
                this.cancellingId = null;
                this.fetchPending(true);
            });
        },
        // [GOAL REMARQUES 2026-10-03 · R-059] Commandes ratées (téléphone annulées < 24 h), lecture seule.
        fetchRatees(silent = false) {
            if (!silent) this.loading.isActive = true;
            return axios.get('admin/pos/counter-collect/missed').then((res) => {
                this.ratees = res.data?.data || [];
                this.fetchError = false;
                this.loading.isActive = false;
            }).catch(() => {
                this.fetchError = true;
                this.loading.isActive = false;
            });
        },
        heureCourte(iso) {
            try {
                return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            } catch (_) { return ''; }
        },
        fetchPending(silent = false) {
            // Sur l'onglet « Ratées », le rafraîchissement (polling, temps réel) relit CETTE liste — il ne
            // doit jamais la remplacer par la file en attente.
            if (this.scope === 'missed') return this.fetchRatees(silent);
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
            if (scope !== 'today' && scope !== 'previous' && scope !== 'missed') return;
            this.scope = scope;
            this.orders = [];
            return this.fetchPending();
        },
        // [GOAL #3 2026-10-02] Demande de purge : `order` = une commande, null = toutes les anciennes.
        askPurge(order) {
            this.purgeReason = this.$t('label.enc_purge_reason_default');
            this.purgeTarget = { order: order || null };
        },
        // [GOAL REMARQUES 2026-10-03 · R-060] Toutes les commandes téléphone du jour : même fenêtre de
        // confirmation, motif pré-rempli « Client non venu » (rien à taper).
        askPurgePhoneToday() {
            this.purgeReason = this.$t('label.enc_purge_reason_phone_default');
            this.purgeTarget = { order: null, phoneToday: true };
        },
        cancelPurge() {
            if (this.purging) return;
            this.purgeTarget = null;
        },
        async confirmPurge() {
            if (!this.purgeTarget || this.purging || this.purgeReason.trim().length < 3) return;
            const one = this.purgeTarget.order;
            const phoneToday = !!this.purgeTarget.phoneToday;
            const body = phoneToday
                ? { confirm: true, reason: this.purgeReason.trim() }
                : {
                    confirm: true,
                    reason: this.purgeReason.trim(),
                    ...(one ? { ids: [one.id] } : { all: true }),
                };
            const minute = Math.floor(Date.now() / 60000);
            this.purging = true;
            try {
                const res = phoneToday
                    ? await axios.post('admin/pos/counter-collect/purge-phone-today', body, {
                        headers: { 'X-Idempotency-Key': `pos-purge-phone-today-${minute}` },
                    })
                    : await axios.post('admin/pos/counter-collect/purge-previous', body, {
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
        /**
         * [QA 2026-09-28 · P0-18 / triage A2] Lève l'ambiguïté du numéro court.
         * Délègue au helper PARTAGÉ avec PosComponent.shortcutDateBadge : une
         * seule définition de la règle, pas une troisième copie à la main.
         */
        queueDateBadge(order) {
            return queueNumberDateBadge(order);
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
            // [E2E stores · B2-R2-07 · 2026-10-01] PosCounterCollectModal affiche DÉJÀ son toast
            // (avec le numéro) sur chaque chemin de succès : celui-ci doublait, au numéro vide.
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
/* [GOAL REMARQUES 2026-10-03 · R-059] Commandes ratées : liste sobre, en lecture seule. */
.enc-missed { display: flex; flex-direction: column; gap: 0.6rem; }
.enc-missed-row { border: 1px solid #E5E7EB; border-inline-start: 4px solid #9CA3AF; border-radius: 0.5rem; padding: 0.6rem 0.9rem; background: #FFFFFF; }
.enc-missed-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.4rem 0.9rem; color: #111827; }
.enc-missed-num { font-weight: 800; }
.enc-missed-client, .enc-missed-phone { font-weight: 600; }
.enc-missed-time { color: #374151; font-size: 0.9rem; }
.enc-missed-total { margin-inline-start: auto; font-weight: 700; font-variant-numeric: tabular-nums; }
.enc-missed-items { margin: 0.35rem 0 0; padding-inline-start: 1.1rem; list-style: disc; color: #1F2937; font-size: 0.92rem; }
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
/* [CAISSE 2026-09-29] Croix d'annulation directe.
   Élément NORMAL de la ligne d'en-tête (jamais en position:absolute — la première
   version recouvrait le badge de date et le numéro de commande, constaté en
   capture). Discrète au repos : elle ne doit pas concurrencer « Encaisser », qui
   reste l'action normale. Largeur FIXE, donc aucun débordement possible. */
.enc-cancel-x {
    flex: 0 0 auto;
    margin-left: auto;
    width: 1.75rem;
    height: 1.75rem;
    padding: 0;
    border: 1px solid var(--pos-v5-border);
    border-radius: 999px;
    background: #fff;
    color: #9a9a9a;
    font-size: 0.8rem;
    line-height: 1;
    cursor: pointer;
    transition: background .12s ease, color .12s ease, border-color .12s ease;
}
.enc-cancel-x:hover { color: #c0392b; border-color: #c0392b; background: #fdf2f1; }
.enc-cancel-x--armed { background: #c0392b; border-color: #c0392b; color: #fff; }
.enc-cancel-x:disabled { opacity: .55; cursor: default; }
/* Bande de confirmation : sous l'en-tête, pleine largeur de la carte. Elle pousse
   le contenu vers le bas au lieu de le masquer. */
.enc-cancel-confirm {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.4rem;
    padding: 0.4rem 0.5rem;
    border: 1px solid #c0392b;
    border-radius: var(--pos-v5-radius-md);
    background: #fdf2f1;
}
.enc-cancel-confirm-txt { font-size: 0.8rem; font-weight: 600; color: #8e2a1f; }
.enc-cancel-yes,
.enc-cancel-no {
    border: none;
    border-radius: var(--pos-v5-radius-md);
    padding: 0.25rem 0.6rem;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
}
.enc-cancel-yes { background: #c0392b; color: #fff; }
.enc-cancel-no { background: #fff; color: #6b6b6b; border: 1px solid var(--pos-v5-border); }
/* Bouton de nettoyage des journées passées : neutre tant qu'il n'a pas compté,
   rouge une fois qu'il annonce un nombre — le second clic est destructif. */
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
/* [QA 2026-09-28 P0-18] Badge de date accolé au numéro court : discret mais
   lisible avec le client en face. Rendu uniquement hors du jour courant. */
.enc-queue-date-badge {
    margin-left: 0.35rem;
    padding: 0.05rem 0.3rem;
    border: 1px solid #fed7aa;
    border-radius: 0.25rem;
    background: #fff7ed;
    color: #9a3412;
    font-size: 0.7rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
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
