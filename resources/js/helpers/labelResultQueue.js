/**
 * labelResultQueue.js — [ETIQUETTES 2026-10-16] Le verdict d'une impression (« imprimé », « échec »…) ne doit
 * jamais se perdre.
 *
 * Pourquoi : si la tablette imprime puis perd le réseau avant d'avoir signalé le verdict, le serveur croit la
 * réclamation abandonnée et, 120 s plus tard, la rend à la file : l'impression automatique recommencerait —
 * un DOUBLON. Le verdict est donc mis en file d'attente locale (localStorage) et rejoué dès que le réseau revient,
 * y compris après un rechargement de la page.
 *
 * Règle : un refus du serveur (4xx) est définitif et sort de la file ; seule une absence de réponse (réseau, 5xx)
 * est rejouée. Aucune fonction ne lève d'exception.
 */
export const QUEUE_KEY = 'label_result_queue';
const MAX_AGE_MS = 24 * 60 * 60 * 1000;
const MAX_TRIES = 30;
const MAX_ITEMS = 100;

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

/** Erreur « pas de réponse du serveur » (rejouable) — par opposition à un refus explicite 4xx. */
function retriable(err) {
    const status = err && err.response && err.response.status;
    return !status || status >= 500;
}

/**
 * @param {{post:(url:string, body:object)=>Promise<any>, storage?:Storage, now?:()=>number}} deps
 */
export function createResultQueue({ post, storage, now = Date.now }) {
    let flushing = null;

    async function flush() {
        if (flushing) return flushing;
        flushing = (async () => {
            let items = load(storage).filter((i) => now() - i.at < MAX_AGE_MS && i.tries < MAX_TRIES);
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
                    // 4xx : refus définitif, on abandonne l'entrée
                }
            }
            save(storage, remaining);
            return remaining.length;
        })().finally(() => { flushing = null; });
        return flushing;
    }

    return {
        /** Envoie le verdict ; en cas d'absence de réponse, le garde pour plus tard. @returns {Promise<boolean>} envoyé ? */
        async send(url, body) {
            try {
                await post(url, body);
                return true;
            } catch (err) {
                if (retriable(err)) {
                    save(storage, [...load(storage), { url, body, at: now(), tries: 1 }]);
                }
                return false;
            }
        },
        flush,
        pending: () => load(storage).length,
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
