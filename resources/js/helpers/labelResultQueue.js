/**
 * labelResultQueue.js — [ETIQUETTES 2026-10-16] Le verdict d'une impression (« imprimé », « échec »…) ne doit
 * jamais se perdre.
 *
 * Pourquoi : si la tablette imprime puis perd le réseau avant d'avoir signalé le verdict, le serveur croit la
 * réclamation abandonnée et, 120 s plus tard, la rend à la file : l'impression automatique recommencerait —
 * un DOUBLON. Le verdict est donc mis en file d'attente locale (localStorage) et rejoué dès que le réseau revient,
 * y compris après un rechargement de la page.
 *
 * Règle : un refus DÉFINITIF du serveur (400, 404, 409, 422…) sort de la file ; tout le reste est rejoué — absence de
 * réponse, 5xx, mais aussi 401/419 (jeton expiré pendant une longue coupure : rejoué après reconnexion), 403, 408, 425 et
 * 429 (limite de débit atteinte en vidant un gros arriéré). Une étiquette imprimée hors ligne ne doit jamais perdre sa
 * ligne de journal en silence. Aucune fonction ne lève d'exception.
 */
export const QUEUE_KEY = 'label_result_queue';
const MAX_AGE_MS = 24 * 60 * 60 * 1000;
const MAX_TRIES = 30;
const MAX_ITEMS = 500; // une longue coupure réseau peut accumuler des centaines d’étiquettes hors ligne

function load(storage) {
    try {
        const raw = storage && storage.getItem(QUEUE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
        return [];
    }
}

function save(storage, items) {
    try { storage && storage.setItem(QUEUE_KEY, JSON.stringify(items.slice(-MAX_ITEMS))); } catch (_) { /* mémoire seule */ }
}

/** Statuts qui ne disent rien de la demande elle-même : on la garde et on la rejoue plus tard. */
const RETRY_STATUSES = new Set([401, 403, 408, 419, 425, 429]);

/** Rejouable ? — tout sauf un refus définitif de la demande (400, 404, 409, 422…). */
function retriable(err) {
    const status = err && err.response && err.response.status;
    return !status || status >= 500 || RETRY_STATUSES.has(status);
}

let seq = 0;
function newId() {
    seq += 1;
    return `${Date.now().toString(36)}-${seq}-${Math.random().toString(36).slice(2, 8)}`;
}
/** Identité d'une entrée (les anciennes n'avaient pas d'identifiant). */
function keyOf(i) {
    return i.qid || JSON.stringify([i.url, i.body, i.at]);
}

/**
 * @param {{post:(url:string, body:object)=>Promise<any>, storage?:Storage, now?:()=>number}} deps
 */
export function createResultQueue({ post, storage, now = Date.now }) {
    let flushing = null;

    async function flush() {
        if (flushing) return flushing;
        flushing = (async () => {
            // chaque entrée peut porter sa propre durée de garde (une étiquette imprimée hors ligne : 7 jours)
            const snapshot = load(storage);
            const seen = new Set(snapshot.map(keyOf));
            // chaque entrée peut porter sa propre durée de garde (une étiquette imprimée hors ligne : 7 jours)
            const items = snapshot.filter((i) => now() - i.at < (i.maxAge || MAX_AGE_MS) && i.tries < (i.maxAge ? 1e9 : MAX_TRIES));
            const remaining = [];
            let offline = false;
            for (const item of items) {
                if (offline) { remaining.push(item); continue; }
                try {
                    await post(item.url, item.body);
                } catch (err) {
                    if (retriable(err)) {
                        remaining.push({ ...item, tries: item.tries + 1 });
                        offline = true;
                    }
                    // refus définitif (400, 404, 409, 422…) : on abandonne l'entrée
                }
            }
            // Ce qui a été mis en file PENDANT l'envoi (une autre impression hors ligne) est gardé : on n'écrase jamais
            // la file avec la photo prise au début.
            const added = load(storage).filter((i) => !seen.has(keyOf(i)));
            save(storage, [...remaining, ...added]);
            return remaining.length + added.length;
        })().finally(() => { flushing = null; });
        return flushing;
    }

    return {
        /**
         * Envoie ; en cas d'absence de réponse, le garde pour plus tard. `opts.maxAgeMs` : durée de garde propre à cette
         * entrée (au lieu de 24 h), rejouée sans limite de tentatives pendant ce temps.
         * @returns {Promise<boolean>} envoyé ?
         */
        async send(url, body, opts = {}) {
            try {
                await post(url, body);
                return true;
            } catch (err) {
                if (retriable(err)) {
                    const item = { qid: newId(), url, body, at: now(), tries: 1 };
                    if (opts.maxAgeMs) item.maxAge = opts.maxAgeMs;
                    save(storage, [...load(storage), item]);
                }
                return false;
            }
        },
        flush,
        pending: () => load(storage).length,
        /** Les entrées en attente (copie), pour les montrer — ex. les étiquettes imprimées hors ligne pas encore journalisées. */
        items: () => load(storage).map((i) => ({ ...i })),
    };
}

let singleton = null;

/** File unique de la tablette, branchée sur axios et localStorage. */
export function labelResultQueue(axios) {
    if (!singleton) {
        let storage;
        try { storage = window.localStorage; } catch (_) { storage = undefined; }
        singleton = createResultQueue({ post: (url, body) => axios.post(url, body), storage });
    }
    return singleton;
}

// ── Impressions « en vol » ────────────────────────────────────────────────────
export const INFLIGHT_KEY = 'label_inflight';
const SESSION = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

/**
 * Journal local des impressions DÉMARRÉES et pas encore conclues. Si la page est rechargée (ou la tablette redémarre)
 * en pleine impression, le serveur ne recevrait jamais de verdict et rendrait la réclamation à la file 120 s plus tard :
 * l'impression automatique recommencerait, alors que les étiquettes sont peut‑être sorties — un DOUBLON.
 * Au rechargement, chaque impression laissée par une AUTRE session de page est déclarée « non confirmée » (compte comme
 * faite : on ne réimprime jamais seul ; un humain décide).
 */
export function createInflight({ storage, queue, now = Date.now, session = SESSION }) {
    const read = () => {
        try {
            const parsed = JSON.parse((storage && storage.getItem(INFLIGHT_KEY)) || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    };
    const write = (items) => { try { storage && storage.setItem(INFLIGHT_KEY, JSON.stringify(items.slice(-50))); } catch (_) { /* rien */ } };
    return {
        begin(id, url, attempt) { write([...read().filter((i) => i.id !== id), { id, url, attempt: attempt || null, session, at: now() }]); },
        end(id) { write(read().filter((i) => i.id !== id)); },
        /** À appeler au montage d'un écran : conclut les impressions d'une session précédente. */
        async recover() {
            const mine = read().filter((i) => i.session === session);
            const orphans = read().filter((i) => i.session !== session);
            write(mine);
            for (const o of orphans) {
                await queue.send(o.url, {
                    status: 'unconfirmed',
                    message: 'Page rechargée pendant l’impression : vérifiez que les étiquettes sont sorties.',
                    attempt: o.attempt,
                });
            }
            return orphans.length;
        },
    };
}

let inflightSingleton = null;

export function labelInflight(axios) {
    if (!inflightSingleton) {
        let storage;
        try { storage = window.localStorage; } catch (_) { storage = undefined; }
        inflightSingleton = createInflight({ storage, queue: labelResultQueue(axios) });
    }
    return inflightSingleton;
}
