<template>
    <div class="cb-shell">
        <!-- ÉCRAN PIN -->
        <div v-if="!unlocked" class="cb-pin-screen">
            <h1 class="cb-brand">📒 Carnet</h1>
            <p class="cb-pin-hint">Entrez le code d'accès</p>
            <div class="cb-pin-dots" role="status" :aria-label="'Code : ' + pin.length + ' chiffres'">
                <span v-for="i in 6" :key="i" class="cb-dot" :class="{ 'cb-dot--on': pin.length >= i }"></span>
            </div>
            <p v-if="pinError" class="cb-pin-error" role="alert">{{ pinError }}</p>
            <div class="cb-pad">
                <button v-for="d in [1,2,3,4,5,6,7,8,9]" :key="d" type="button" class="cb-pad-key" @click="tapDigit(String(d))">{{ d }}</button>
                <button type="button" class="cb-pad-key cb-pad-key--muted" @click="pin = ''">C</button>
                <button type="button" class="cb-pad-key" @click="tapDigit('0')">0</button>
                <button type="button" class="cb-pad-key cb-pad-key--muted" @click="pin = pin.slice(0, -1)">⌫</button>
            </div>
            <button type="button" class="cb-pin-submit" :disabled="pin.length < 4 || pinBusy" @click="unlock">
                {{ pinBusy ? '…' : 'Déverrouiller' }}
            </button>
        </div>

        <!-- APP -->
        <div v-else class="cb-app">
            <header class="cb-head">
                <h1 class="cb-brand cb-brand--small">📒 Carnet</h1>
                <button type="button" class="cb-lock" @click="lock">🔒 Verrouiller</button>
            </header>

            <nav class="cb-tabs">
                <button type="button" class="cb-tab" :class="{ 'cb-tab--on': tab === 'day' }" @click="tab = 'day'">Aujourd'hui</button>
                <button type="button" class="cb-tab" :class="{ 'cb-tab--on': tab === 'month' }" @click="switchToMonth">Résumé du mois</button>
            </nav>

            <!-- ONGLET JOUR -->
            <section v-if="tab === 'day'" class="cb-day">
                <div class="cb-type-row">
                    <button type="button" class="cb-type" :class="{ 'cb-type--on': form.type === 'expense' }" @click="form.type = 'expense'">💶 Dépense</button>
                    <button type="button" class="cb-type" :class="{ 'cb-type--on': form.type === 'advance' }" @click="form.type = 'advance'">🤝 Acompte</button>
                    <button type="button" class="cb-type" :class="{ 'cb-type--on': form.type === 'note' }" @click="form.type = 'note'">📝 Note</button>
                </div>

                <form class="cb-form" @submit.prevent="submitEntry">
                    <input v-model.trim="form.label" type="text" class="cb-input" required maxlength="190"
                        :placeholder="form.type === 'note' ? 'Note (ex : commander des serviettes)' : 'Libellé (ex : facture légumes)'" />
                    <input v-if="form.type === 'advance'" v-model.trim="form.worker_name" type="text" class="cb-input" required maxlength="120"
                        placeholder="Travailleur (ex : Karim)" />
                    <input v-if="form.type !== 'note'" v-model="form.amount" type="number" step="0.01" min="0" max="99999.99" class="cb-input" required
                        placeholder="Montant (€)" inputmode="decimal" />
                    <input v-model="form.entry_date" type="date" class="cb-input" required />
                    <label v-if="form.type === 'expense'" class="cb-photo-label">
                        📷 {{ photoName || 'Photo de la facture (optionnel)' }}
                        <input type="file" accept="image/*" capture="environment" class="cb-photo-input" @change="onPhoto" />
                    </label>
                    <p v-if="formError" class="cb-form-error" role="alert">{{ formError }}</p>
                    <button type="submit" class="cb-submit" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Ajouter' }}</button>
                </form>

                <h2 class="cb-section">Entrées du {{ frDate(dayFilter) }}</h2>
                <input v-model="dayFilter" type="date" class="cb-input cb-input--filter" @change="loadDay" />
                <p v-if="entries.length === 0" class="cb-empty">Aucune entrée ce jour.</p>
                <ul class="cb-list">
                    <li v-for="e in entries" :key="e.id" class="cb-row">
                        <span class="cb-row-ico">{{ e.type === 'expense' ? '💶' : (e.type === 'advance' ? '🤝' : '📝') }}</span>
                        <span class="cb-row-main">
                            <span class="cb-row-label">{{ e.label }}<template v-if="e.worker_name"> — {{ e.worker_name }}</template></span>
                            <span v-if="e.note" class="cb-row-note">{{ e.note }}</span>
                        </span>
                        <a v-if="e.photo_thumb_url" :href="e.photo_url" target="_blank" rel="noopener" class="cb-row-photo">
                            <img :src="e.photo_thumb_url" alt="Facture" />
                        </a>
                        <span v-if="e.amount !== null" class="cb-row-amount">{{ money(e.amount) }}</span>
                        <button type="button" class="cb-row-del" aria-label="Supprimer" @click="removeEntry(e)">✕</button>
                    </li>
                </ul>
                <p v-if="dayTotal > 0" class="cb-day-total">Total sorti ce jour : <strong>{{ money(dayTotal) }}</strong></p>
            </section>

            <!-- ONGLET MOIS -->
            <section v-else class="cb-month">
                <input v-model="monthFilter" type="month" class="cb-input cb-input--filter" @change="loadMonth" />
                <div v-if="summary" class="cb-cards">
                    <div class="cb-card"><span class="cb-card-k">Dépenses</span><span class="cb-card-v">{{ money(summary.total_expenses) }}</span></div>
                    <div class="cb-card"><span class="cb-card-k">Acomptes</span><span class="cb-card-v">{{ money(summary.total_advances) }}</span></div>
                    <div class="cb-card cb-card--total"><span class="cb-card-k">Total sorti</span><span class="cb-card-v">{{ money(summary.total_out) }}</span></div>
                </div>
                <template v-if="summary && summary.by_worker.length > 0">
                    <h2 class="cb-section">Acomptes par travailleur</h2>
                    <ul class="cb-list">
                        <li v-for="w in summary.by_worker" :key="w.worker_name" class="cb-row">
                            <span class="cb-row-ico">🤝</span>
                            <span class="cb-row-main"><span class="cb-row-label">{{ w.worker_name }}</span>
                                <span class="cb-row-note">{{ w.count }} acompte(s)</span></span>
                            <span class="cb-row-amount">{{ money(w.total) }}</span>
                        </li>
                    </ul>
                </template>
                <template v-if="summary && summary.by_day.length > 0">
                    <h2 class="cb-section">Sorties par jour</h2>
                    <ul class="cb-list">
                        <li v-for="d in summary.by_day" :key="d.date" class="cb-row">
                            <span class="cb-row-ico">📅</span>
                            <span class="cb-row-main"><span class="cb-row-label">{{ frDate(d.date) }}</span></span>
                            <span class="cb-row-amount">{{ money(d.total) }}</span>
                        </li>
                    </ul>
                </template>
                <p v-if="summary && summary.notes_count > 0" class="cb-empty">📝 {{ summary.notes_count }} note(s) ce mois.</p>
            </section>
        </div>
    </div>
</template>

<script>
// Mutable : session()->regenerate() au déverrouillage PIN invalide le token
// embarqué dans la page — l'unlock renvoie le nouveau (sinon 419 ensuite).
let CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function api(url, options = {}) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            'X-CSRF-TOKEN': CSRF,
            'Accept': 'application/json',
            ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            ...(options.headers || {}),
        },
        ...options,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const err = new Error(data.message || 'Erreur réseau');
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data;
}

function today() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

export default {
    name: 'DailyBookApp',
    data() {
        return {
            unlocked: false,
            pin: '',
            pinError: null,
            pinBusy: false,
            tab: 'day',
            form: { type: 'expense', label: '', worker_name: '', amount: '', entry_date: today(), note: '' },
            photoFile: null,
            photoName: '',
            formError: null,
            busy: false,
            entries: [],
            dayFilter: today(),
            monthFilter: today().slice(0, 7),
            summary: null,
        };
    },
    computed: {
        dayTotal() {
            return this.entries.reduce((sum, e) => sum + (e.amount || 0), 0);
        },
    },
    watch: {
        // [W6 heal P3] Une photo choisie en mode Dépense restait attachée
        // (invisible) après bascule Acompte/Note → facture collée à la mauvaise
        // entrée. Purge à chaque changement de type.
        'form.type'() {
            this.photoFile = null;
            this.photoName = '';
            this.formError = null;
        },
    },
    mounted() {
        api('/carnet/api/status').then((s) => {
            if (s.unlocked) {
                this.unlocked = true;
                this.loadDay();
            }
        }).catch(() => {});
    },
    methods: {
        money(v) {
            return Number(v || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        },
        frDate(iso) {
            if (!iso) return '';
            const [y, m, d] = iso.split('-');
            return d + '/' + m + '/' + y;
        },
        tapDigit(d) {
            if (this.pin.length < 6) this.pin += d;
        },
        unlock() {
            this.pinBusy = true;
            this.pinError = null;
            api('/carnet/api/pin', { method: 'POST', body: JSON.stringify({ pin: this.pin }) })
                .then((r) => {
                    if (r.csrf) CSRF = r.csrf;
                    this.unlocked = true;
                    this.pin = '';
                    this.loadDay();
                })
                .catch((e) => {
                    this.pinError = e.status === 429 ? 'Trop d\'essais — attendez une minute.' : 'Code PIN incorrect.';
                    this.pin = '';
                })
                .finally(() => { this.pinBusy = false; });
        },
        lock() {
            api('/carnet/api/lock', { method: 'POST' }).catch(() => {}).finally(() => {
                this.unlocked = false;
            });
        },
        onPhoto(ev) {
            const f = ev.target.files?.[0] || null;
            this.photoFile = f;
            this.photoName = f ? f.name : '';
        },
        submitEntry() {
            this.formError = null;
            this.busy = true;
            const fd = new FormData();
            fd.append('type', this.form.type);
            fd.append('label', this.form.label);
            fd.append('entry_date', this.form.entry_date);
            if (this.form.type === 'advance') fd.append('worker_name', this.form.worker_name);
            if (this.form.type !== 'note') fd.append('amount', this.form.amount);
            if (this.photoFile) fd.append('photo', this.photoFile);
            api('/carnet/api/entries', { method: 'POST', body: fd })
                .then(() => {
                    this.form.label = '';
                    this.form.amount = '';
                    this.form.worker_name = '';
                    this.photoFile = null;
                    this.photoName = '';
                    this.dayFilter = this.form.entry_date;
                    this.loadDay();
                })
                .catch((e) => {
                    if (e.status === 401) { this.unlocked = false; return; }
                    const first = e.data?.errors ? Object.values(e.data.errors)[0]?.[0] : null;
                    this.formError = first || e.message;
                })
                .finally(() => { this.busy = false; });
        },
        removeEntry(e) {
            if (!window.confirm('Supprimer « ' + e.label + ' » ?')) return;
            api('/carnet/api/entries/' + e.id, { method: 'DELETE' })
                .then(() => this.loadDay())
                .catch((err) => {
                    // [W6 heal P3] Échec avalé silencieusement : 401 = session PIN
                    // expirée → relock ; autre erreur → feedback visible.
                    if (err.status === 401) { this.unlocked = false; return; }
                    this.formError = 'Suppression impossible. Réessayez.';
                });
        },
        loadDay() {
            api('/carnet/api/entries?date=' + this.dayFilter)
                .then((r) => { this.entries = r.data; })
                .catch((e) => { if (e.status === 401) this.unlocked = false; });
        },
        switchToMonth() {
            this.tab = 'month';
            this.loadMonth();
        },
        loadMonth() {
            api('/carnet/api/summary/month?month=' + this.monthFilter)
                .then((r) => { this.summary = r.data; })
                .catch((e) => { if (e.status === 401) this.unlocked = false; });
        },
    },
};
</script>
