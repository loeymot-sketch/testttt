<template>
    <section class="voice-assistant" aria-labelledby="voice-assistant-title" data-testid="voice-order-assistant">
        <header class="voice-assistant__header">
            <div>
                <p class="voice-assistant__eyebrow">COPILOTE TÉLÉPHONE</p>
                <h2 id="voice-assistant-title">L’appel reste humain. La prise de notes devient instantanée.</h2>
            </div>
            <div class="voice-assistant__health" :class="healthTone">
                <span aria-hidden="true"></span>{{ healthLabel }}
            </div>
        </header>

        <div v-if="!enabled" class="voice-assistant__disabled" role="status">
            <strong>Assistant désactivé en sécurité.</strong>
            <span>{{ disabledMessage }}</span>
            <small>La commande téléphone manuelle de la caisse reste entièrement utilisable.</small>
        </div>

        <template v-else>
            <div v-if="linkState && linkState.pending" class="voice-assistant__link-warning" role="alert">
                <div>
                    <strong>Commande créée — transcription encore à relier</strong>
                    <span>La commande n’est pas recréée. Seul le lien est retenté.</span>
                </div>
                <button type="button" @click="$emit('retry-link')">Relier maintenant</button>
            </div>

            <div class="voice-assistant__grid">
                <aside class="voice-assistant__calls" aria-label="Appels téléphoniques">
                    <div class="voice-assistant__section-title">
                        <span>Appels</span><b>{{ activeCalls.length }}</b>
                    </div>
                    <button
                        v-for="call in calls"
                        :key="call.call_id"
                        type="button"
                        class="voice-call"
                        :class="{ 'voice-call--active': selectedId === call.call_id }"
                        @click="selectCall(call)"
                    >
                        <span class="voice-call__status" :class="`voice-call__status--${call.status}`"></span>
                        <span class="voice-call__identity">
                            <strong>{{ call.caller_name || call.caller_number || 'Numéro masqué' }}</strong>
                            <small>{{ call.caller_name && call.caller_number ? call.caller_number : statusLabel(call) }}</small>
                        </span>
                        <time>{{ shortTime(call.started_at) }}</time>
                    </button>
                    <div v-if="calls.length === 0" class="voice-assistant__empty">
                        <span aria-hidden="true">☎</span>
                        <strong>En attente d’un appel</strong>
                        <small>Le téléphone de l’employé continue de sonner normalement.</small>
                    </div>
                </aside>

                <main class="voice-assistant__conversation">
                    <template v-if="selectedCall">
                        <div class="voice-assistant__caller">
                            <div>
                                <span>APPEL EN COURS</span>
                                <strong>{{ selectedCall.caller_name || 'Client téléphone' }}</strong>
                                <small>{{ selectedCall.caller_number || 'Numéro non transmis' }}</small>
                            </div>
                            <button type="button" class="voice-assistant__ghost" @click="applyCaller">
                                Reporter nom & numéro
                            </button>
                        </div>

                        <div v-if="selectedCall.status !== 'ended' && !selectedCall.consented_at" class="voice-assistant__consent">
                            <div class="voice-assistant__consent-copy">
                                <strong>1. Informez le client à voix haute</strong>
                                <p>« Pour bien noter votre commande, notre outil transcrit cet appel. Aucun audio n’est enregistré. »</p>
                            </div>
                            <button type="button" :disabled="busy" @click="confirmConsent">
                                Client informé — démarrer
                            </button>
                        </div>

                        <div v-else class="voice-assistant__transcript" aria-live="polite">
                            <div class="voice-assistant__section-title">
                                <span>Transcription en direct</span>
                                <small>Texte d’aide, à vérifier avec le client</small>
                            </div>
                            <ol>
                                <li v-for="turn in selectedCall.turns || []" :key="turn.turn_id">
                                    <span>{{ speakerLabel(turn.speaker) }}</span>
                                    <p>{{ turn.text }}</p>
                                    <em v-if="turn.confidence !== null && turn.confidence !== undefined">
                                        {{ Math.round(turn.confidence * 100) }} %
                                    </em>
                                </li>
                                <li v-if="selectedCall.live_turn" class="voice-assistant__live-turn">
                                    <span>{{ speakerLabel(selectedCall.live_turn.speaker) }}</span>
                                    <p>{{ selectedCall.live_turn.text }}</p>
                                    <em>écoute…</em>
                                </li>
                            </ol>
                            <div v-if="!(selectedCall.turns || []).length && !selectedCall.live_turn" class="voice-assistant__empty voice-assistant__empty--small">
                                La transcription démarre dès que le client parle.
                            </div>
                        </div>
                    </template>
                    <div v-else class="voice-assistant__empty voice-assistant__empty--large">
                        <span aria-hidden="true">◌</span>
                        <strong>Sélectionnez un appel</strong>
                        <small>La transcription et la proposition de commande apparaîtront ici.</small>
                    </div>
                </main>

                <aside class="voice-assistant__draft">
                    <div class="voice-assistant__section-title">
                        <span>Commande proposée</span>
                        <button v-if="selectedCall && selectedCall.status !== 'ended' && selectedCall.consented_at" type="button" :disabled="busy" @click="extractDraft">
                            {{ busy ? 'Analyse…' : 'Actualiser' }}
                        </button>
                    </div>

                    <template v-if="draftLines.length">
                        <article v-for="line in draftLines" :key="`${line.item_id}-${line.quantity}`" class="voice-draft-line">
                            <div class="voice-draft-line__qty">{{ line.quantity }}×</div>
                            <div>
                                <strong>{{ line.name }}</strong>
                                <p v-if="line.notes">{{ line.notes }}</p>
                                <ul v-if="line.missing_slots && line.missing_slots.length">
                                    <li v-for="slot in line.missing_slots.slice(0, 3)" :key="slot">{{ slot }} à confirmer</li>
                                </ul>
                            </div>
                            <button type="button" @click="$emit('review-item', line)">Ouvrir le wizard</button>
                        </article>
                    </template>
                    <div v-else class="voice-assistant__empty voice-assistant__empty--draft">
                        <span aria-hidden="true">✎</span>
                        <strong>Aucun produit proposé</strong>
                        <small>Parlez normalement, puis cliquez sur « Actualiser ».</small>
                    </div>

                    <div v-if="ambiguities.length" class="voice-assistant__ambiguities" role="alert">
                        <strong>À clarifier</strong>
                        <p v-for="ambiguity in ambiguities" :key="ambiguity">{{ ambiguity }}</p>
                    </div>

                    <div v-if="selectedCall" class="voice-assistant__reply">
                        <span>RÉPONSE CONSEILLÉE</span>
                        <p>“{{ selectedCall.recommended_reply || 'Je vous écoute ; je vérifie chaque élément avec vous.' }}”</p>
                    </div>

                    <p class="voice-assistant__safety">Le copilot ne valide jamais la commande et n’annonce jamais de prix. Utilisez le wizard, puis le bouton « Commande téléphone ».</p>
                </aside>
            </div>
        </template>
    </section>
</template>

<script>
import axios from 'axios';

export default {
    name: 'VoiceOrderAssistantPanel',
    props: {
        branchId: { type: [Number, String], default: null },
        userId: { type: [Number, String], default: null },
        linkState: { type: Object, default: () => ({ pending: false }) },
    },
    emits: ['select-call', 'apply-caller', 'review-item', 'retry-link'],
    data() {
        return {
            enabled: true,
            disabledMessage: '',
            activeCalls: [],
            recentCalls: [],
            selectedId: null,
            selectedCall: null,
            loadedPersistedId: null,
            busy: false,
            online: true,
            pollTimer: null,
            pollDelay: 750,
            destroyed: false,
        };
    },
    computed: {
        calls() {
            const map = new Map();
            [...this.activeCalls, ...this.recentCalls].forEach((call) => {
                if (call && call.call_id && !map.has(call.call_id)) map.set(call.call_id, call);
            });
            return Array.from(map.values()).slice(0, 30);
        },
        draftLines() {
            return Array.isArray(this.selectedCall?.draft?.lines) ? this.selectedCall.draft.lines : [];
        },
        ambiguities() {
            return Array.isArray(this.selectedCall?.draft?.ambiguities) ? this.selectedCall.draft.ambiguities : [];
        },
        healthTone() {
            if (!this.enabled) return 'voice-assistant__health--off';
            return this.online ? 'voice-assistant__health--ok' : 'voice-assistant__health--warn';
        },
        healthLabel() {
            if (!this.enabled) return 'Désactivé';
            return this.online ? 'Synchronisé' : 'Reconnexion…';
        },
    },
    mounted() {
        this.poll();
    },
    beforeUnmount() {
        this.destroyed = true;
        if (this.pollTimer) clearTimeout(this.pollTimer);
    },
    methods: {
        async poll() {
            if (this.destroyed || !Number(this.branchId)) {
                this.schedulePoll(1200);
                return;
            }
            try {
                const response = await axios.get('/admin/voice-order/snapshot');
                const data = response?.data?.data || {};
                this.enabled = data.enabled !== false;
                this.disabledMessage = data.message || '';
                this.activeCalls = Array.isArray(data.active_calls) ? data.active_calls : [];
                this.recentCalls = Array.isArray(data.recent_calls) ? data.recent_calls : [];
                this.online = true;
                this.pollDelay = 750;

                const preferred = this.selectedId || this.activeCalls[0]?.call_id || this.recentCalls[0]?.call_id;
                if (preferred) {
                    const updated = [...this.activeCalls, ...this.recentCalls].find((call) => call.call_id === preferred);
                    if (updated) {
                        this.selectedId = preferred;
                        const keepLoaded = this.loadedPersistedId === preferred
                            && Array.isArray(this.selectedCall?.turns)
                            && this.selectedCall.turns.length > 0
                            && (!Array.isArray(updated.turns) || updated.turns.length === 0);
                        if (!keepLoaded) this.selectedCall = updated;
                        this.$emit('select-call', this.selectedCall);
                        if (updated.persisted_at && !keepLoaded && (!updated.turns || updated.turns.length === 0)) {
                            this.loadCallDetails(preferred);
                        }
                    }
                }
            } catch (error) {
                this.online = false;
                this.pollDelay = error?.response?.status === 429 ? 5000 : Math.min(10000, this.pollDelay * 2);
            } finally {
                this.schedulePoll(this.pollDelay);
            }
        },
        schedulePoll(delay) {
            if (this.destroyed) return;
            if (this.pollTimer) clearTimeout(this.pollTimer);
            this.pollTimer = setTimeout(() => this.poll(), delay);
        },
        selectCall(call) {
            this.selectedId = call.call_id;
            this.selectedCall = call;
            this.$emit('select-call', call);
            if (call.persisted_at && (!call.turns || call.turns.length === 0)) {
                this.loadCallDetails(call.call_id);
            }
        },
        async loadCallDetails(callId) {
            if (!callId || this.loadedPersistedId === callId) return;
            this.loadedPersistedId = callId;
            try {
                const response = await axios.get(`/admin/voice-order/calls/${encodeURIComponent(callId)}`);
                if (this.selectedId === callId && response?.data?.data) {
                    this.selectedCall = response.data.data;
                    this.$emit('select-call', this.selectedCall);
                }
            } catch (_) {
                this.loadedPersistedId = null;
            }
        },
        async confirmConsent() {
            if (!this.selectedId || this.busy) return;
            this.busy = true;
            try {
                const response = await axios.post(`/admin/voice-order/calls/${encodeURIComponent(this.selectedId)}/consent`, { caller_informed: true });
                this.selectedCall = response?.data?.data || this.selectedCall;
            } finally {
                this.busy = false;
            }
        },
        async extractDraft() {
            if (!this.selectedId || this.busy) return;
            this.busy = true;
            try {
                const response = await axios.post(`/admin/voice-order/calls/${encodeURIComponent(this.selectedId)}/extract`);
                this.selectedCall = response?.data?.data || this.selectedCall;
            } finally {
                this.busy = false;
            }
        },
        applyCaller() {
            if (!this.selectedCall) return;
            this.$emit('apply-caller', {
                call_id: this.selectedCall.call_id,
                caller_name: this.selectedCall.caller_name || '',
                caller_number: this.selectedCall.caller_number || '',
                order_id: this.selectedCall.order_id || null,
            });
        },
        speakerLabel(speaker) {
            if (speaker === 'caller') return 'CLIENT';
            if (speaker === 'employee') return 'ÉQUIPIER';
            return 'APPEL';
        },
        statusLabel(call) {
            if (call.status === 'ended') return 'Terminé';
            if (call.consented_at) return 'Transcription active';
            return 'Information client requise';
        },
        shortTime(value) {
            if (!value) return '';
            try { return new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(value)); }
            catch (_) { return ''; }
        },
    },
};
</script>

<style scoped>
.voice-assistant { --ink:#211b16; --muted:#746a61; --paper:#fffdf8; --line:#e8ded2; --gold:#c88b2d; --green:#167b55; margin:12px; border:1px solid var(--line); border-radius:18px; background:var(--paper); box-shadow:0 18px 55px rgba(61,43,25,.12); color:var(--ink); overflow:hidden; }
.voice-assistant__header { display:flex; align-items:center; justify-content:space-between; gap:18px; padding:18px 22px; border-bottom:1px solid var(--line); background:linear-gradient(110deg,#fffaf0,#fff 62%); }
.voice-assistant__eyebrow,.voice-assistant__reply span { margin:0 0 4px; color:#9b641d; font-size:10px; font-weight:900; letter-spacing:.16em; }
.voice-assistant h2 { margin:0; font-family:Georgia,serif; font-size:22px; line-height:1.15; }
.voice-assistant__health { display:flex; align-items:center; gap:7px; white-space:nowrap; font-size:12px; font-weight:800; }
.voice-assistant__health span { width:9px; height:9px; border-radius:50%; background:currentColor; box-shadow:0 0 0 4px color-mix(in srgb,currentColor 12%,transparent); }
.voice-assistant__health--ok { color:var(--green); }.voice-assistant__health--warn { color:#b55d19; }.voice-assistant__health--off { color:#777; }
.voice-assistant__grid { display:grid; grid-template-columns:220px minmax(360px,1fr) minmax(300px,390px); min-height:470px; }
.voice-assistant__calls,.voice-assistant__conversation,.voice-assistant__draft { min-width:0; padding:16px; }
.voice-assistant__calls { border-right:1px solid var(--line); background:#f8f3eb; }.voice-assistant__draft { border-left:1px solid var(--line); background:#fbfaf7; }
.voice-assistant__section-title { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:12px; font-size:11px; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
.voice-assistant__section-title b { display:grid; place-items:center; min-width:22px; height:22px; border-radius:99px; background:var(--ink); color:#fff; }.voice-assistant__section-title small { color:var(--muted); font-size:10px; letter-spacing:0; text-transform:none; }
.voice-call { width:100%; display:flex; align-items:center; gap:9px; margin-bottom:7px; padding:10px; border:1px solid transparent; border-radius:11px; background:transparent; color:inherit; text-align:left; cursor:pointer; }
.voice-call:hover,.voice-call--active { border-color:#dfc29b; background:#fff; box-shadow:0 6px 20px rgba(76,48,19,.08); }.voice-call__status { width:8px; height:8px; flex:none; border-radius:50%; background:#c36b27; }.voice-call__status--transcribing { background:#20a36a; box-shadow:0 0 0 4px #d9f3e7; }.voice-call__status--ended { background:#aaa; }
.voice-call__identity { min-width:0; flex:1; }.voice-call__identity strong,.voice-call__identity small { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }.voice-call__identity strong { font-size:12px; }.voice-call__identity small,.voice-call time { color:var(--muted); font-size:10px; }
.voice-assistant__caller { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid var(--line); }.voice-assistant__caller span,.voice-assistant__caller small { display:block; color:var(--muted); font-size:10px; }.voice-assistant__caller strong { display:block; margin:2px 0; font-size:18px; }
.voice-assistant button { border:0; border-radius:9px; padding:9px 11px; background:var(--ink); color:#fff; font-size:11px; font-weight:800; cursor:pointer; }.voice-assistant button:disabled { opacity:.45; cursor:wait; }.voice-assistant__ghost { border:1px solid var(--line)!important; background:#fff!important; color:var(--ink)!important; }
.voice-assistant__consent { display:grid; grid-template-columns:1fr auto; align-items:center; gap:16px; padding:18px; border:1px solid #f0ca92; border-radius:14px; background:#fff5df; }.voice-assistant__consent-copy p { margin:6px 0 0; color:#765421; font-size:13px; line-height:1.45; }
.voice-assistant__transcript ol { max-height:360px; margin:0; padding:0; overflow:auto; list-style:none; }.voice-assistant__transcript li { display:grid; grid-template-columns:62px 1fr auto; gap:10px; align-items:start; padding:10px 0; border-bottom:1px solid #f0ebe5; }.voice-assistant__transcript li span { padding-top:2px; color:#9b641d; font-size:9px; font-weight:900; letter-spacing:.08em; }.voice-assistant__transcript li p { margin:0; font-size:13px; line-height:1.45; }.voice-assistant__transcript li em { color:var(--muted); font-size:9px; font-style:normal; }.voice-assistant__live-turn { opacity:.68; }
.voice-draft-line { display:grid; grid-template-columns:32px 1fr auto; gap:9px; margin-bottom:9px; padding:11px; border:1px solid var(--line); border-radius:12px; background:#fff; }.voice-draft-line__qty { display:grid; place-items:center; width:30px; height:30px; border-radius:8px; background:#f1e6d6; color:#744817; font-weight:900; }.voice-draft-line strong { font-size:13px; }.voice-draft-line p { margin:4px 0; color:var(--muted); font-size:11px; }.voice-draft-line ul { margin:5px 0 0; padding-left:14px; color:#a34d20; font-size:10px; }.voice-draft-line button { align-self:start; padding:7px 8px; background:#eee5da; color:var(--ink); }
.voice-assistant__reply { margin-top:14px; padding:13px; border-left:3px solid var(--gold); border-radius:0 10px 10px 0; background:#fff6e8; }.voice-assistant__reply p { margin:5px 0 0; font-family:Georgia,serif; font-size:14px; line-height:1.45; }.voice-assistant__ambiguities { margin-top:10px; padding:10px; border-radius:10px; background:#fff0ec; color:#923a27; }.voice-assistant__ambiguities p { margin:4px 0; font-size:11px; }
.voice-assistant__safety { margin:13px 0 0; color:var(--muted); font-size:10px; line-height:1.45; }.voice-assistant__empty { display:grid; justify-items:center; gap:5px; padding:28px 10px; color:var(--muted); text-align:center; }.voice-assistant__empty span { font-size:28px; color:#c6aa84; }.voice-assistant__empty strong { color:var(--ink); font-size:12px; }.voice-assistant__empty small { max-width:230px; font-size:10px; line-height:1.4; }.voice-assistant__empty--large { min-height:350px; align-content:center; }.voice-assistant__empty--small { min-height:auto; }.voice-assistant__empty--draft { padding:40px 10px; }
.voice-assistant__disabled,.voice-assistant__link-warning { display:flex; align-items:center; justify-content:space-between; gap:18px; padding:16px 22px; }.voice-assistant__disabled { flex-direction:column; align-items:flex-start; background:#f4f1ec; }.voice-assistant__disabled span,.voice-assistant__disabled small,.voice-assistant__link-warning span { display:block; color:var(--muted); font-size:11px; }.voice-assistant__link-warning { border-bottom:1px solid #efc6a8; background:#fff1e5; color:#803c18; }
@media (max-width:1100px) { .voice-assistant__grid { grid-template-columns:180px 1fr; }.voice-assistant__draft { grid-column:1/-1; border-top:1px solid var(--line); border-left:0; }.voice-assistant__draft .voice-draft-line { grid-template-columns:32px 1fr auto; } }
@media (max-width:760px) { .voice-assistant { margin:8px; }.voice-assistant__header { align-items:flex-start; }.voice-assistant h2 { font-size:17px; }.voice-assistant__grid { display:block; }.voice-assistant__calls { border-right:0; border-bottom:1px solid var(--line); }.voice-assistant__calls .voice-call { display:inline-flex; width:calc(50% - 4px); }.voice-assistant__consent { grid-template-columns:1fr; }.voice-assistant__link-warning { align-items:flex-start; flex-direction:column; } }
</style>
