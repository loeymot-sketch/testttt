// @vitest-environment node
/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0 « hors ligne »] Un relevé saisi sans réseau n'est jamais perdu, ni jeté en silence.
 */
import { describe, it, expect, vi } from 'vitest';
import { createHygieneQueue, isRetriable, refusalReason, MAX_AGE_MS, QUEUE_KEY } from '../../resources/js/helpers/hygieneOfflineQueue.js';

const mem = () => { const m = {}; return { getItem: (k) => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = v; }, m }; };
const err = (status, data) => { const e = new Error(String(status)); if (status) e.response = { status, data }; return e; };
const entry = (n = 1) => ({ url: 'admin/hygiene/temperature/readings', body: { readings: [{ equipment_id: n, value: 3, measured_at: '2026-10-20T10:12:00+02:00', client_ref: `r${n}` }] }, label: `${n} relevé` });

describe('quelles erreurs se rejouent', () => {
    it('réseau absent, 5xx et jeton expiré se rejouent ; un refus définitif non', () => {
        [undefined, 0, 500, 502, 503, 401, 403, 408, 419, 425, 429].forEach((s) => expect(isRetriable(err(s)), String(s)).toBe(true));
        [400, 404, 409, 422].forEach((s) => expect(isRetriable(err(s)), String(s)).toBe(false));
        expect(isRetriable(new Error('Network Error'))).toBe(true);
        expect(isRetriable(null)).toBe(true);
    });
    it('la raison du refus est celle du serveur, sinon un texte sûr', () => {
        expect(refusalReason(err(422, { message: 'x', errors: { 'readings.0.value': ['« Frigo 1 » : 99 °C n’est pas plausible.'] } }))).toContain('Frigo 1');
        expect(refusalReason(err(409, { message: 'Déjà utilisée' }))).toBe('Déjà utilisée');
        expect(refusalReason(err(422, {}))).toBe('Le serveur a refusé ces relevés.');
    });
});

describe('file hors ligne des relevés', () => {
    it('garde une écriture, survit à un « rechargement » (nouvelle instance sur le même stockage)', () => {
        const storage = mem();
        const q1 = createHygieneQueue({ post: vi.fn(), storage });
        const e = q1.enqueue(entry());
        expect(e).toMatchObject({ state: 'pending', tries: 1 });
        const q2 = createHygieneQueue({ post: vi.fn(), storage });
        expect(q2.pending()).toHaveLength(1);
        expect(q2.pending()[0].body.readings[0].measured_at).toBe('2026-10-20T10:12:00+02:00'); // l'heure de la mesure est gardée
    });

    it('stockage plein ou interdit : enqueue renvoie null (rien n’est faussement « gardé »)', () => {
        const q = createHygieneQueue({ post: vi.fn(), storage: { getItem: () => null, setItem: () => { throw new Error('quota'); } } });
        expect(q.enqueue(entry())).toBeNull();
        expect(createHygieneQueue({ post: vi.fn(), storage: null }).enqueue(entry())).toBeNull();
    });

    it('rejoue dans l’ordre et vide la file quand tout passe', async () => {
        const storage = mem();
        const post = vi.fn(async () => ({}));
        const q = createHygieneQueue({ post, storage });
        q.enqueue(entry(1)); q.enqueue(entry(2));
        const r = await q.flush();
        expect(r).toEqual({ sent: 2, pending: 0, rejected: 0 });
        expect(post.mock.calls.map((c) => c[1].readings[0].equipment_id)).toEqual([1, 2]);
        expect(q.items()).toHaveLength(0);
    });

    it('le réseau absent garde tout et n’insiste pas sur les suivantes', async () => {
        const post = vi.fn(async () => { throw err(undefined); });
        const q = createHygieneQueue({ post, storage: mem() });
        q.enqueue(entry(1)); q.enqueue(entry(2));
        const r = await q.flush();
        expect(r).toEqual({ sent: 0, pending: 2, rejected: 0 });
        expect(post).toHaveBeenCalledTimes(1);
        expect(q.pending()[0].tries).toBe(2);
    });

    it('un refus définitif n’est PAS jeté : l’entrée passe « refusée » avec sa raison, et les suivantes partent', async () => {
        const post = vi.fn(async (url, body) => {
            if (body.readings[0].equipment_id === 1) throw err(422, { errors: { 'readings.0.value': ['99 °C n’est pas plausible.'] } });
            return {};
        });
        const q = createHygieneQueue({ post, storage: mem() });
        q.enqueue(entry(1)); q.enqueue(entry(2));
        const r = await q.flush();
        expect(r).toEqual({ sent: 1, pending: 0, rejected: 1 });
        expect(q.rejected()[0].reason).toContain('n’est pas plausible');
        await q.flush(); // un refus n'est jamais rejoué
        expect(post).toHaveBeenCalledTimes(2);
        q.dismiss(q.rejected()[0].qid);
        expect(q.items()).toHaveLength(0);
    });

    it('plus de 72 h hors ligne : refusé sans appel au serveur', async () => {
        let t = 1_700_000_000_000;
        const post = vi.fn(async () => ({}));
        const q = createHygieneQueue({ post, storage: mem(), now: () => t });
        q.enqueue(entry());
        t += MAX_AGE_MS + 1;
        const r = await q.flush();
        expect(r).toEqual({ sent: 0, pending: 0, rejected: 1 });
        expect(post).not.toHaveBeenCalled();
        expect(q.rejected()[0].reason).toContain('72 h');
        // juste dans la limite : part
        const q2 = createHygieneQueue({ post, storage: mem(), now: () => t });
        q2.enqueue(entry()); t += MAX_AGE_MS - 1;
        expect((await q2.flush()).sent).toBe(1);
    });

    it('une entrée ajoutée PENDANT l’envoi n’est pas écrasée', async () => {
        const storage = mem();
        let q;
        const post = vi.fn(async () => { q.enqueue(entry(9)); return {}; }); // une saisie arrive pendant le rejeu
        q = createHygieneQueue({ post, storage });
        q.enqueue(entry(1));
        const r = await q.flush();
        expect(r.sent).toBe(1);
        expect(q.pending().map((i) => i.body.readings[0].equipment_id)).toEqual([9]);
    });

    it('deux rejeux simultanés n’envoient qu’une fois', async () => {
        const post = vi.fn(async () => ({}));
        const q = createHygieneQueue({ post, storage: mem() });
        q.enqueue(entry());
        await Promise.all([q.flush(), q.flush()]);
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('un stockage corrompu ne casse rien', async () => {
        const storage = mem();
        storage.setItem(QUEUE_KEY, '{pas du json');
        const q = createHygieneQueue({ post: vi.fn(), storage });
        expect(q.items()).toEqual([]);
        expect((await q.flush()).sent).toBe(0);
        storage.setItem(QUEUE_KEY, JSON.stringify([{ nonsense: true }, null, 5]));
        expect(q.items()).toEqual([]);
    });
});
