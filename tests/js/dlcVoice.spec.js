/**
 * [ETIQUETTES 2026-10-19 · owner « une salade iceberg, tomates, oignons, poivrons coupés → les quatre DLC »]
 * La dictée vocale : la voix REMPLIT la sélection, rien ne s'imprime sans l'appui sur « Imprimer la sélection », et ce que le
 * serveur a compris (ou pas) est montré avant. Écran monté pour de vrai ; micro et serveur simulés.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const h = vi.hoisted(() => ({
    axios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
    printBlocks: vi.fn(),
    printer: null,
    alert: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
    rec: { stop: vi.fn(), cancel: vi.fn() },
    wav: { peak: 0.5, seconds: 3 },
    recordVoice: vi.fn(),
}));
vi.mock('axios', () => ({ default: h.axios }));
vi.mock('../../resources/js/services/alertService', () => ({ default: h.alert }));
vi.mock('../../resources/js/helpers/labelPrintJob', () => ({
    printBlocks: (...a) => h.printBlocks(...a),
    previewUrls: (list) => ({ urls: list.map(() => 'data:image/png;base64,AA=='), overflow: false }),
}));
vi.mock('../../resources/js/helpers/labelPrinter.js', async (orig) => ({ ...(await orig()), labelPrinter: () => h.printer }));
vi.mock('../../resources/js/helpers/dlcVoice', async (orig) => ({
    ...(await orig()),
    voiceSupported: () => true,
    recordVoice: (...a) => h.recordVoice(...a),
    blobToWav: async () => ({ ...h.wav, wav: new window.Blob(['RIFFxxxx'], { type: 'audio/wav' }) }),
}));

import LabelDlcComponent from '../../resources/js/components/admin/labels/LabelDlcComponent.vue';
import DlcVoiceButton from '../../resources/js/components/admin/labels/DlcVoiceButton.vue';
import { encodeWav, micErrorMessage, peakOf, resampleLinear, toMono, VOICE_RATE } from '../../resources/js/helpers/dlcVoice';

const printer = { isSupported: () => true, getState: () => ({ status: 'connected', printer: null }), onChange: () => () => {}, startKeepAlive() {}, stopKeepAlive() {}, isConnected: () => true };
const P = (over) => ({
    id: 1, name: 'Oignons ciselés', category: 'Légumes & crudités', shelf_life_value: 24, shelf_life_unit: 'hours',
    actions: [{ key: 'prepared', value: 24, unit: 'hours' }], allergens: [], storage: '0 à +4 °C', note: null,
    ask_original_dlc: false, print_operator: true, default_copies: 1, is_active: true, uses_30d: 0, ...over,
});
const CATALOGUE = () => [
    P({ id: 1, name: 'Salade iceberg coupée' }), P({ id: 2, name: 'Tomates tranchées' }), P({ id: 3, name: 'Oignons ciselés', default_copies: 2 }),
    P({ id: 4, name: 'Poivrons coupés' }), P({ id: 5, name: 'Fiche sans conservation', storage: '' }),
];
let prints;

function setup(list = CATALOGUE()) {
    prints = [];
    h.printer = printer;
    h.printBlocks.mockReset();
    h.printBlocks.mockResolvedValue({ ok: true, pages: 1 });
    h.alert.error.mockReset();
    for (const f of [h.axios.get, h.axios.post, h.axios.put]) f.mockReset();
    h.axios.get.mockImplementation(async (url) => {
        if (url === 'admin/labels/products') return { data: { data: list, meta: { server_now: new Date().toISOString(), timezone: 'Europe/Paris' } } };
        if (url === 'admin/labels/watch') return { data: { data: [], meta: { server_now: new Date().toISOString(), expired_hidden: 0 } } };
        return { data: { data: [] } };
    });
    h.axios.post.mockImplementation(async (url, body) => {
        if (url === 'admin/labels/dlc/print') {
            prints.push(body);
            const p = list.find((x) => x.id === body.product_id);
            return { data: {
                log_id: 100 + prints.length, attempt: 1, name: p.name, category: p.category, action: p.actions[0].key,
                opened_at: '2026-10-16T08:00:00Z', expires_at: '2026-10-17T08:00:00Z', copies: body.copies, lot: 'L-1',
                allergens: [], storage: p.storage, by: 'Karim', capped: false,
            } };
        }
        return { data: { status: 'ok' } };
    });
    window.localStorage.clear();
}

async function mountScreen() {
    const w = mount(LabelDlcComponent, { global: { mocks: { $t: (k) => k }, stubs: { 'router-link': { template: '<a><slot /></a>' } } } });
    await flushPromises();
    return w;
}
const say = async (w, data) => { w.findComponent(DlcVoiceButton).vm.$emit('result', data); await flushPromises(); };
const item = (product_id, name, copies = 1) => ({ product_id, name, copies, heard: name.toLowerCase() });

beforeEach(() => { vi.useRealTimers(); });

describe('dictée — fonctions pures', () => {
    it('toMono moyenne les canaux, resampleLinear garde la durée, peakOf mesure', () => {
        expect(Array.from(toMono([new Float32Array([1, 0]), new Float32Array([0, 1])]))).toEqual([0.5, 0.5]);
        const out = resampleLinear(new Float32Array(48000).fill(0.25), 48000, VOICE_RATE);
        expect(out.length).toBe(16000);
        expect(out[0]).toBeCloseTo(0.25);
        expect(peakOf(new Float32Array([0.1, -0.7, 0.3]))).toBeCloseTo(0.7);
    });

    it('encodeWav écrit un en-tête PCM 16 bits mono 16 kHz valide', () => {
        const wav = new DataView(encodeWav(new Float32Array([0, 1, -1, 2]), 16000));
        const tag = (o) => String.fromCharCode(wav.getUint8(o), wav.getUint8(o + 1), wav.getUint8(o + 2), wav.getUint8(o + 3));
        expect([tag(0), tag(8), tag(12), tag(36)]).toEqual(['RIFF', 'WAVE', 'fmt ', 'data']);
        expect(wav.byteLength).toBe(44 + 8);
        expect(wav.getUint16(22, true)).toBe(1);
        expect(wav.getUint32(24, true)).toBe(16000);
        expect(wav.getUint16(34, true)).toBe(16);
        expect(wav.getInt16(46, true)).toBe(0x7fff);
        expect(wav.getInt16(48, true)).toBe(-0x8000);
        expect(wav.getInt16(50, true)).toBe(0x7fff); // 2 écrêté, jamais débordé
    });

    it('micErrorMessage dit quoi faire', () => {
        expect(micErrorMessage({ name: 'NotAllowedError' })).toMatch(/autorisez/);
        expect(micErrorMessage({ name: 'NotFoundError' })).toMatch(/Aucun micro/);
        expect(micErrorMessage(new Error('x'))).toMatch(/Réessayez/);
    });
});

describe('dictée — écran', () => {
    it('quatre produits dits = quatre produits sélectionnés, RIEN d\'imprimé, et ce qui a été entendu est montré', async () => {
        setup();
        const w = await mountScreen();
        await say(w, { transcript: 'salade iceberg tomates oignons poivrons coupés', items: [item(1, 'Salade iceberg coupée'), item(2, 'Tomates tranchées'), item(3, 'Oignons ciselés'), item(4, 'Poivrons coupés')], unmatched: [] });

        expect(w.get('[data-testid="dlc-voice-heard"]').text()).toContain('salade iceberg tomates oignons poivrons coupés');
        expect(w.get('[data-testid="dlc-voice-added"]').text()).toMatch(/4 produits sélectionnés/);
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('5 étiquettes'); // oignons : 2 par défaut
        expect(prints).toHaveLength(0);
        expect(h.printBlocks).not.toHaveBeenCalled();

        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        expect(prints.map((p) => [p.product_id, p.copies]).sort()).toEqual([[1, 1], [2, 1], [3, 2], [4, 1]]);
    });

    it('un nombre dit est respecté, plafonné à 20 ; redire la phrase ne double pas ; « aussi » ajoute', async () => {
        setup();
        const w = await mountScreen();
        await say(w, { transcript: 'trois tomates', items: [item(2, 'Tomates tranchées', 3)], unmatched: [] });
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('3 étiquettes');

        await say(w, { transcript: 'trois tomates', items: [item(2, 'Tomates tranchées', 3)], unmatched: [] });
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('3 étiquettes');

        await say(w, { transcript: 'aussi poivrons', items: [item(4, 'Poivrons coupés')], unmatched: [] });
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('4 étiquettes');

        await say(w, { transcript: 'cent tomates', items: [item(2, 'Tomates tranchées', 99)], unmatched: [] });
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('21 étiquettes'); // 20 + 1
    });

    it('une fiche qu\'on ne peut pas imprimer en lot va « à part » ; un mot inconnu ou une fiche disparue est « pas compris »', async () => {
        setup();
        const w = await mountScreen();
        await say(w, { transcript: 'x', items: [item(5, 'Fiche sans conservation'), item(999, 'Fiche fantôme'), item(2, 'Tomates tranchées')], unmatched: ['licornes'] });

        expect(w.get('[data-testid="dlc-voice-apart"]').text()).toMatch(/Fiche sans conservation.*fiche à compléter/);
        expect(w.get('[data-testid="dlc-voice-unmatched"]').text()).toMatch(/licornes.*fiche fantôme/i);
        expect(w.get('[data-testid="dlc-basket-total"]').text()).toBe('1 étiquette');
    });

    it('rien entendu : une alerte, pas de panneau, pas de sélection', async () => {
        setup();
        const w = await mountScreen();
        await say(w, { transcript: '', items: [], unmatched: [], message: 'Je n’ai rien entendu' });

        expect(h.alert.error).toHaveBeenCalledWith('Je n’ai rien entendu');
        expect(w.find('[data-testid="dlc-voice-note"]').exists()).toBe(false);
        expect(w.find('[data-testid="dlc-basket"]').exists()).toBe(false);
    });

    it('après l\'impression, la note ne garde que ce qui reste À FAIRE', async () => {
        setup();
        const w = await mountScreen();
        await say(w, { transcript: 'tomates licornes', items: [item(2, 'Tomates tranchées')], unmatched: ['licornes'] });
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="dlc-voice-added"]').exists()).toBe(false);
        expect(w.get('[data-testid="dlc-voice-unmatched"]').text()).toMatch(/licornes/);

        await say(w, { transcript: 'tomates', items: [item(2, 'Tomates tranchées')], unmatched: [] });
        await w.get('[data-testid="dlc-basket-print"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="dlc-voice-note"]').exists()).toBe(false);
    });

    it('changer de mode efface la note ; « Fermer » aussi', async () => {
        setup();
        const w = await mountScreen();
        await say(w, { transcript: 'tomates', items: [item(2, 'Tomates tranchées')], unmatched: [] });
        await w.get('[data-testid="dlc-voice-close"]').trigger('click');
        expect(w.find('[data-testid="dlc-voice-note"]').exists()).toBe(false);

        await say(w, { transcript: 'tomates', items: [item(2, 'Tomates tranchées')], unmatched: [] });
        await w.get('[data-testid="dlc-mode-single"]').trigger('click');
        expect(w.find('[data-testid="dlc-voice-note"]').exists()).toBe(false);
        expect(w.find('[data-testid="dlc-basket"]').exists()).toBe(false);
    });

    it('dictée non activée sur ce restaurant : le bouton disparaît', async () => {
        setup();
        const w = await mountScreen();
        w.findComponent(DlcVoiceButton).vm.$emit('unavailable', 'La dictée vocale n’est pas activée sur ce restaurant.');
        await flushPromises();
        expect(w.find('[data-testid="dlc-voice"]').exists()).toBe(false);
        expect(h.alert.error).toHaveBeenCalledWith('La dictée vocale n’est pas activée sur ce restaurant.');
    });
});

describe('dictée — bouton', () => {
    beforeEach(() => {
        h.rec.stop.mockReset(); h.rec.cancel.mockReset(); h.recordVoice.mockReset();
        h.rec.stop.mockResolvedValue(new Blob(['x']));
        h.recordVoice.mockResolvedValue(h.rec);
        h.axios.post.mockReset();
        h.wav.peak = 0.5;
    });

    it('un appui enregistre, le second envoie un WAV au serveur et rend le résultat', async () => {
        h.axios.post.mockResolvedValue({ data: { data: { transcript: 'oignons', items: [], unmatched: [] }, message: null } });
        const w = mount(DlcVoiceButton);
        await w.get('[data-testid="dlc-voice"]').trigger('click');
        await flushPromises();
        expect(w.get('[data-testid="dlc-voice"]').attributes('aria-pressed')).toBe('true');

        await w.get('[data-testid="dlc-voice"]').trigger('click');
        await flushPromises();

        const [url, form] = h.axios.post.mock.calls[0];
        expect(url).toBe('admin/labels/dlc/voice');
        expect(form.get('audio').name).toBe('voix.wav');
        expect(w.emitted('result')[0][0].transcript).toBe('oignons');
        expect(w.get('[data-testid="dlc-voice"]').attributes('aria-pressed')).toBe('false');
    });

    it('micro muet : rien n\'est envoyé, la personne est prévenue', async () => {
        h.wav.peak = 0.001;
        const w = mount(DlcVoiceButton);
        await w.get('[data-testid="dlc-voice"]').trigger('click');
        await flushPromises();
        await w.get('[data-testid="dlc-voice"]').trigger('click');
        await flushPromises();

        expect(h.axios.post).not.toHaveBeenCalled();
        expect(w.emitted('error')[0][0]).toMatch(/micro semble muet/);
    });

    it('micro refusé : message clair, pas d\'état bloqué', async () => {
        h.recordVoice.mockRejectedValue({ name: 'NotAllowedError' });
        const w = mount(DlcVoiceButton);
        await w.get('[data-testid="dlc-voice"]').trigger('click');
        await flushPromises();

        expect(w.emitted('error')[0][0]).toMatch(/autorisez/);
        expect(w.get('[data-testid="dlc-voice"]').attributes('aria-pressed')).toBe('false');
    });

    it('503 = non activée ; autre erreur = message du serveur ; le bouton redevient disponible', async () => {
        const w = mount(DlcVoiceButton);
        h.axios.post.mockRejectedValueOnce({ response: { status: 503, data: { message: 'non activée' } } });
        await w.get('[data-testid="dlc-voice"]').trigger('click'); await flushPromises();
        await w.get('[data-testid="dlc-voice"]').trigger('click'); await flushPromises();
        expect(w.emitted('unavailable')[0][0]).toBe('non activée');

        h.axios.post.mockRejectedValueOnce({ response: { status: 502, data: { message: 'réessayez' } } });
        await w.get('[data-testid="dlc-voice"]').trigger('click'); await flushPromises();
        await w.get('[data-testid="dlc-voice"]').trigger('click'); await flushPromises();
        expect(w.emitted('error')[0][0]).toBe('réessayez');
        expect(w.get('[data-testid="dlc-voice"]').attributes('disabled')).toBeUndefined();
    });

    it('s\'arrête seul au bout de la durée maximale, et le micro est relâché si l\'écran se ferme', async () => {
        vi.useFakeTimers();
        h.axios.post.mockResolvedValue({ data: { data: { transcript: 'x', items: [], unmatched: [] } } });
        const w = mount(DlcVoiceButton, { props: { maxSeconds: 3 } });
        await w.get('[data-testid="dlc-voice"]').trigger('click');
        await vi.advanceTimersByTimeAsync(3100);
        await flushPromises();
        expect(h.rec.stop).toHaveBeenCalledTimes(1);
        expect(h.axios.post).toHaveBeenCalledTimes(1);

        h.recordVoice.mockResolvedValue(h.rec);
        const w2 = mount(DlcVoiceButton);
        await w2.get('[data-testid="dlc-voice"]').trigger('click');
        await flushPromises();
        w2.unmount();
        expect(h.rec.cancel).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
    });
});
