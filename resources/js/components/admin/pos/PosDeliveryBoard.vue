<template>
    <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4" role="dialog" aria-modal="true" aria-label="Tournées de livraison" data-testid="pos-delivery-board" @click.self="$emit('close')">
        <div class="w-full sm:max-w-2xl max-h-[90vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl bg-white p-4 shadow-xl" style="scroll-padding-top: 7rem">
            <!-- [E2E 2026-10-22 B-009/R2-01/R2-02] En-tête ET bandeau d'erreur restent collés en haut quand la modale défile : un refus d'Uber
                 n'est jamais caché hors de vue. `top:-1rem` = collé au bord réel (le conteneur a 1 rem de marge), sans bande visible au-dessus. -->
            <div class="sticky z-10 -mx-4 -mt-4 mb-3 bg-white px-4 pt-3 pb-2 border-b border-[#F0F0F2]" style="top: -1rem" data-testid="pos-delivery-board-head">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-bold text-heading">🛵 Tournées de livraison</h2>
                    <button type="button" class="h-9 px-3 rounded-lg border border-[#D9DBE9] text-sm font-semibold" @click="$emit('close')">Fermer</button>
                </div>
                <p v-if="error" class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700" role="alert">{{ error }}</p>
            </div>
            <p v-if="loading && !waves.length" class="py-8 text-center text-sm text-gray-500">Chargement…</p>
            <p v-else-if="!waves.length" class="py-8 text-center text-sm text-gray-500" data-testid="pos-delivery-board-empty">
                Aucune livraison prévue. Les commandes de livraison sont regroupées en départs toutes les 30 minutes.
            </p>

            <!-- [VG-2026-10-21 M4-005] Le data-testid porte la date : deux « 23:30 » (hier, ce soir) ne se confondent plus. -->
            <section v-for="w in waves" :key="w.wave_at" class="mb-4 rounded-xl border border-[#E4E4E7] p-3" :data-testid="'pos-delivery-wave-' + waveDate(w) + '-' + w.time">
                <header class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <div class="text-base font-extrabold text-heading">
                        <!-- [VG-2026-10-21 M4-005] Le jour dès que ce n'est pas aujourd'hui (« Départ demain 00:30 »). -->
                        Départ {{ waveWhen(w) }}
                        <span class="ml-1 text-xs font-semibold" :class="w.left ? 'text-green-700' : 'text-[#9A3412]'">{{ w.left ? 'parti' : 'à venir' }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <span v-if="w.courier" class="font-semibold">Livreur : {{ w.courier }}</span>
                        <span v-else class="font-semibold text-red-700">Aucun livreur</span>
                        <select
                            v-if="!w.left && couriers.length > 1"
                            class="h-9 rounded-lg border border-[#D9DBE9] px-2 text-sm"
                            :aria-label="'Choisir le livreur du départ ' + waveWhen(w)"
                            :value="w.courier_id || ''"
                            @change="onAssign(w, $event.target.value)"
                        >
                            <option value="" disabled>Choisir…</option>
                            <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                </header>
                <ul class="divide-y divide-[#F0F0F2]">
                    <li v-for="s in w.stops" :key="s.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <div class="min-w-0">
                            <div class="font-bold">#{{ s.order_number || s.order_id }} · {{ s.customer || 'Client' }}</div>
                            <div class="truncate text-gray-600">{{ s.address }}</div>
                        </div>
                        <p v-if="s.uber_attention" class="basis-full rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700" role="alert" data-testid="pos-delivery-uber-attention">⚠ {{ s.uber_attention }}</p>
                        <!-- [UBER-DIRECT 2026-10-22] Qui livre : nos livreurs ou un coursier Uber. Deux boutons, un seul actif à la fois. -->
                        <div v-if="!s.delivered_at" class="basis-full flex flex-wrap items-center gap-2" :data-testid="'pos-delivery-dispatch-' + s.id">
                            <template v-if="s.dispatch === 'uber'">
                                <span class="rounded-full bg-black px-2 py-1 text-xs font-bold text-white" data-testid="pos-delivery-uber-badge">🚗 Uber · {{ uberLabel(s.uber && s.uber.status) }}</span>
                                <span v-if="s.uber && s.uber.courier_name" class="text-xs text-gray-600">{{ s.uber.courier_name }}</span>
                                <a v-if="s.uber && s.uber.tracking_url" :href="s.uber.tracking_url" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold underline">Suivi</a>
                                <button type="button" class="h-8 px-3 rounded-lg border border-[#D9DBE9] text-xs font-semibold disabled:opacity-40" :disabled="busy === s.id || (s.uber && s.uber.bag_taken)" :title="s.uber && s.uber.bag_taken ? 'Le coursier Uber a déjà la commande' : ''" data-testid="pos-delivery-use-own" @click="onRelease(s)">🛵 Nos livreurs</button>
                            </template>
                            <template v-else-if="proposal && proposal.stopId === s.id">
                                <span class="text-xs font-bold" data-testid="pos-delivery-uber-price">Uber : {{ format(proposal.fee) }}<span v-if="proposal.eta"> · ~{{ proposal.eta }} min</span></span>
                                <button type="button" class="h-8 px-3 rounded-lg bg-black text-xs font-bold text-white disabled:opacity-40" :disabled="busy === s.id" data-testid="pos-delivery-uber-confirm" @click="onConfirm(s)">Confirmer Uber</button>
                                <button type="button" class="h-8 px-3 rounded-lg border border-[#D9DBE9] text-xs font-semibold" data-testid="pos-delivery-uber-cancel" @click="proposal = null">Annuler</button>
                            </template>
                            <template v-else>
                                <span class="rounded-full bg-[#FFF3E0] px-2 py-1 text-xs font-bold text-[#9A3412]">🛵 Nos livreurs</span>
                                <button type="button" class="h-8 px-3 rounded-lg bg-black text-xs font-bold text-white disabled:opacity-40" :disabled="busy === s.id || !!s.left_at || !!s.uber_blocked || !uberEnabled" :title="uberReason(s)" data-testid="pos-delivery-use-uber" @click="onQuote(s)">🚗 Confier à Uber</button>
                                <span v-if="uberReason(s)" class="text-xs text-gray-500" data-testid="pos-delivery-uber-reason">{{ uberReason(s) }}</span>
                            </template>
                        </div>
                        <div class="text-right">
                            <!-- [VG-2026-10-21 R2-cuisine-livraison-003] Le vrai mode : un titre-restaurant n'est plus annoncé « Espèces ». -->
                            <div class="font-bold" :class="s.amount_due > 0 ? 'text-[#9A3412]' : 'text-green-700'">
                                {{ s.amount_due > 0 ? (collectLabel(s.collect_method) + ' ' + format(s.amount_due)) : 'Payé' }}
                            </div>
                            <div class="text-xs text-gray-500">{{ stateOf(s) }}</div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import { fetchBoard, assignCourier, uberQuote, uberDispatch, uberRelease, uberStatusLabel } from '../../../helpers/posLivraison';

export default {
    name: 'PosDeliveryBoard',
    emits: ['close'],
    data() {
        return { waves: [], couriers: [], loading: false, error: '', uberEnabled: false, busy: null, proposal: null };
    },
    mounted() {
        this.load();
        this._timer = setInterval(() => { if (!document.hidden) this.load(); }, 20000);
    },
    beforeUnmount() {
        clearInterval(this._timer);
    },
    methods: {
        async load() {
            this.loading = true;
            try {
                const b = await fetchBoard(axios);
                this.waves = b.data || [];
                this.couriers = b.couriers || [];
                this.uberEnabled = Boolean(b.uber_enabled);
                this.error = '';
            } catch (e) {
                this.error = e?.response?.data?.message || 'Impossible de charger les tournées.';
            } finally {
                this.loading = false;
            }
        },
        uberLabel(status) {
            return uberStatusLabel(status);
        },
        // Pourquoi le bouton Uber est grisé : la vraie raison renvoyée par le serveur, jamais un bouton muet.
        uberReason(s) {
            if (!this.uberEnabled) return 'Uber Direct n\'est pas activé.';
            if (s.left_at) return 'Le livreur est déjà parti.';
            return s.uber_blocked || '';
        },
        // 1) prix d'Uber (gratuit, sans engagement) ; 2) le caissier confirme ; 3) la course est demandée.
        async onQuote(s) {
            this.busy = s.id;
            try {
                const q = await uberQuote(axios, s.id);
                this.proposal = { stopId: s.id, quoteId: q.quote_id, fee: q.fee, eta: q.eta_minutes };
                this.error = '';
            } catch (e) {
                this.error = e.message;
            } finally {
                this.busy = null;
            }
        },
        async onConfirm(s) {
            const p = this.proposal;
            this.busy = s.id;
            try {
                const r = await uberDispatch(axios, s.id, p.quoteId);
                if (r.changed) {
                    // Le prix a changé entre-temps : on ne dépêche jamais un montant que le caissier n'a pas vu.
                    this.proposal = { stopId: s.id, quoteId: r.quote.quote_id, fee: r.quote.fee, eta: r.quote.eta_minutes };
                    this.error = 'Le prix d\'Uber a changé : vérifiez et confirmez à nouveau.';
                } else {
                    this.proposal = null;
                    this.error = '';
                    await this.load();
                }
            } catch (e) {
                this.error = e.message;
                this.proposal = null;
            } finally {
                this.busy = null;
            }
        },
        async onRelease(s) {
            this.busy = s.id;
            try {
                await uberRelease(axios, s.id);
                this.error = '';
                await this.load();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.busy = null;
            }
        },
        async onAssign(wave, courierId) {
            try {
                await assignCourier(axios, wave.wave_at, Number(courierId));
                await this.load();
            } catch (e) {
                this.error = e?.response?.data?.message || 'Impossible de désigner ce livreur.';
            }
        },
        // [VG-2026-10-21 R2-cuisine-livraison-003] Ce que le livreur rapporte, selon le mode renvoyé par le serveur
        // (DeliveryStop::COLLECT_* + DeliveryWaveService::COLLECT_MEAL_VOUCHER). Mode inconnu : jamais « Espèces » inventé.
        collectLabel(method) {
            return { cash: 'Espèces', card: 'Carte', meal_voucher: 'Titre-restaurant' }[method] || 'À encaisser';
        },
        // [VG-2026-10-21 M4-005] Jour de la tournée renvoyé par le serveur (DeliveryWaveService::waveDay) ;
        // repli sur la date de wave_at (« Y-m-d H:i:s ») si le champ manque.
        waveDate(w) {
            return w.date || String(w.wave_at || '').slice(0, 10);
        },
        waveWhen(w) {
            return w.is_today === false && w.day_label ? w.day_label + ' ' + w.time : w.time;
        },
        format(n) {
            return Number(n).toFixed(2).replace('.', ',') + ' €';
        },
        stateOf(s) {
            if (s.delivered_at) return 'Remise ' + s.delivered_at.slice(11, 16) + (s.has_photo ? ' · 📷' : '');
            if (s.arrived_at) return 'Arrivé ' + s.arrived_at.slice(11, 16) + (s.has_photo ? ' · 📷' : '');
            if (s.left_at) return 'En route';
            return 'À partir';
        },
    },
};
</script>
