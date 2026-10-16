/**
 * [ETIQUETTES 2026-10-16] Un verdict d'impression perdu = un doublon d'étiquettes 120 s plus tard (le serveur
 * rend la réclamation à la file). La file locale rejoue le verdict jusqu'à ce que le serveur l'accepte.
 */
import { describe, it, expect } from 'vitest';
import { createResultQueue, createInflight, QUEUE_KEY } from '../../resources/js/helpers/labelResultQueue.js';

const mem = () => {
    const m = new Map();
    return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)) };
};
const netErr = () => Object.assign(new Error('Network Error'), { response: undefined });
const httpErr = (status) => Object.assign(new Error('http'), { response: { status } });

describe('labelResultQueue', () => {
    it('envoi réussi : rien n’est mis en file', async () => {
        const sent = [];
        const q = createResultQueue({ post: async (u, b) => { sent.push([u, b]); }, storage: mem() });
        expect(await q.send('u', { a: 1 })).toBe(true);
        expect(sent).toEqual([['u', { a: 1 }]]);
        expect(q.pending()).toBe(0);
    });

    it('réseau coupé : le verdict est gardé, puis rejoué dès que le réseau revient', async () => {
        let online = false;
        const sent = [];
        const storage = mem();
        const q = createResultQueue({ post: async (u, b) => { if (!online) throw netErr(); sent.push([u, b]); }, storage });
        expect(await q.send('log/1/result', { status: 'printed', attempt: 2 })).toBe(false);
        expect(q.pending()).toBe(1);
        expect(await q.flush()).toBe(1); // toujours hors ligne
        online = true;
        expect(await q.flush()).toBe(0);
        expect(sent).toEqual([['log/1/result', { status: 'printed', attempt: 2 }]]);
        expect(q.pending()).toBe(0);
    });

    it('survit à un rechargement de page (même stockage, nouvelle instance)', async () => {
        const storage = mem();
        const down = createResultQueue({ post: async () => { throw netErr(); }, storage });
        await down.send('x', { s: 1 });
        const sent = [];
        const reloaded = createResultQueue({ post: async (u, b) => { sent.push(u); }, storage });
        expect(reloaded.pending()).toBe(1);
        await reloaded.flush();
        expect(sent).toEqual(['x']);
    });

    it('5xx rejoué ; 4xx est un refus définitif qui sort de la file', async () => {
        const storage = mem();
        const q500 = createResultQueue({ post: async () => { throw httpErr(503); }, storage });
        await q500.send('a', {});
        expect(q500.pending()).toBe(1);
        const q404 = createResultQueue({ post: async () => { throw httpErr(404); }, storage });
        await q404.flush();
        expect(q404.pending()).toBe(0);
        const direct = createResultQueue({ post: async () => { throw httpErr(422); }, storage: mem() });
        await direct.send('b', {});
        expect(direct.pending()).toBe(0);
    });

    it('l’ordre est conservé et une panne réseau stoppe le rejeu sans rien perdre', async () => {
        const storage = mem();
        let calls = 0;
        const q = createResultQueue({
            post: async () => { calls += 1; if (calls >= 2) throw netErr(); },
            storage,
        });
        storage.setItem(QUEUE_KEY, JSON.stringify([
            { url: '1', body: {}, at: Date.now(), tries: 1 },
            { url: '2', body: {}, at: Date.now(), tries: 1 },
            { url: '3', body: {}, at: Date.now(), tries: 1 },
        ]));
        expect(await q.flush()).toBe(2);
        expect(JSON.parse(storage.getItem(QUEUE_KEY)).map((i) => i.url)).toEqual(['2', '3']);
    });

    it('abandonne les verdicts trop vieux (24 h) ou trop souvent rejoués', async () => {
        const storage = mem();
        const t = 10 * 24 * 3600 * 1000;
        storage.setItem(QUEUE_KEY, JSON.stringify([
            { url: 'vieux', body: {}, at: 0, tries: 1 },
            { url: 'usé', body: {}, at: t, tries: 30 },
            { url: 'bon', body: {}, at: t, tries: 1 },
        ]));
        const sent = [];
        const q = createResultQueue({ post: async (u) => { sent.push(u); }, storage, now: () => t });
        await q.flush();
        expect(sent).toEqual(['bon']);
    });

    it('un stockage indisponible ou corrompu ne lève jamais', async () => {
        const broken = { getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); } };
        const q = createResultQueue({ post: async () => { throw netErr(); }, storage: broken });
        expect(await q.send('a', {})).toBe(false);
        expect(await q.flush()).toBe(0);
        const corrupt = mem();
        corrupt.setItem(QUEUE_KEY, '{pas du json');
        expect(createResultQueue({ post: async () => {}, storage: corrupt }).pending()).toBe(0);
    });

    it('deux flush simultanés ne rejouent pas deux fois', async () => {
        const storage = mem();
        storage.setItem(QUEUE_KEY, JSON.stringify([{ url: 'z', body: {}, at: Date.now(), tries: 1 }]));
        let n = 0;
        const q = createResultQueue({ post: async () => { n += 1; await new Promise((r) => setTimeout(r, 10)); }, storage });
        await Promise.all([q.flush(), q.flush()]);
        expect(n).toBe(1);
    });
});

describe('impressions en vol (rechargement de page pendant l’impression)', () => {
    it('une impression laissée par une AUTRE session est déclarée « non confirmée » — jamais réimprimée seule', async () => {
        const storage = mem();
        const sent = [];
        const queue = createResultQueue({ post: async (u, b) => { sent.push([u, b]); }, storage: mem() });
        const before = createInflight({ storage, queue, session: 'ancienne-page' });
        before.begin(42, 'admin/labels/prints/42/result', 3); // la page est rechargée ici : jamais de end()

        const after = createInflight({ storage, queue, session: 'nouvelle-page' });
        expect(await after.recover()).toBe(1);
        expect(sent).toHaveLength(1);
        expect(sent[0][0]).toBe('admin/labels/prints/42/result');
        expect(sent[0][1]).toMatchObject({ status: 'unconfirmed', attempt: 3 });
        expect(await after.recover()).toBe(0); // une seule fois
    });

    it('une impression de la session COURANTE n’est jamais « récupérée » (elle va conclure toute seule)', async () => {
        const storage = mem();
        const sent = [];
        const queue = createResultQueue({ post: async (u) => { sent.push(u); }, storage: mem() });
        const inflight = createInflight({ storage, queue, session: 's1' });
        inflight.begin(1, 'u1', 1);
        expect(await inflight.recover()).toBe(0);
        expect(sent).toEqual([]);
        inflight.end(1);
        expect(JSON.parse(storage.getItem('label_inflight'))).toEqual([]);
    });
});
