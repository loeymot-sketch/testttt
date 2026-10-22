<template>
    <div class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4" role="dialog" aria-modal="true" aria-label="Tournées de livraison" data-testid="pos-delivery-board" @click.self="$emit('close')">
        <div class="w-full sm:max-w-2xl max-h-[90vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl bg-white p-4 shadow-xl">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h2 class="text-lg font-bold text-heading">🛵 Tournées de livraison</h2>
                <button type="button" class="h-9 px-3 rounded-lg border border-[#D9DBE9] text-sm font-semibold" @click="$emit('close')">Fermer</button>
            </div>

            <p v-if="error" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700" role="alert">{{ error }}</p>
            <p v-if="loading && !waves.length" class="py-8 text-center text-sm text-gray-500">Chargement…</p>
            <p v-else-if="!waves.length" class="py-8 text-center text-sm text-gray-500" data-testid="pos-delivery-board-empty">
                Aucune livraison prévue. Les commandes de livraison sont regroupées en départs toutes les 30 minutes.
            </p>

            <section v-for="w in waves" :key="w.wave_at" class="mb-4 rounded-xl border border-[#E4E4E7] p-3" :data-testid="'pos-delivery-wave-' + w.time">
                <header class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <div class="text-base font-extrabold text-heading">
                        Départ {{ w.time }}
                        <span class="ml-1 text-xs font-semibold" :class="w.left ? 'text-green-700' : 'text-[#9A3412]'">{{ w.left ? 'parti' : 'à venir' }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <span v-if="w.courier" class="font-semibold">Livreur : {{ w.courier }}</span>
                        <span v-else class="font-semibold text-red-700">Aucun livreur</span>
                        <select
                            v-if="!w.left && couriers.length > 1"
                            class="h-9 rounded-lg border border-[#D9DBE9] px-2 text-sm"
                            :aria-label="'Choisir le livreur du départ ' + w.time"
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
import { fetchBoard, assignCourier } from '../../../helpers/posLivraison';

export default {
    name: 'PosDeliveryBoard',
    emits: ['close'],
    data() {
        return { waves: [], couriers: [], loading: false, error: '' };
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
                this.error = '';
            } catch (e) {
                this.error = e?.response?.data?.message || 'Impossible de charger les tournées.';
            } finally {
                this.loading = false;
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
