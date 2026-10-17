/**
 * hygieneOfflineQueue.js — [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0 « hors ligne »] Un relevé saisi quand le réseau tombe (chambre
 * froide, wifi capricieux) ne doit JAMAIS être perdu ni faussé.
 *
 * Règles :
 *   · l'envoi qui échoue faute de réseau (aucune réponse, 5xx, jeton expiré 401/419, 403, 408, 425, 429) est gardé dans la tablette
 *     (localStorage, survit au rechargement) avec l'heure de la mesure, et rejoué dès que le réseau revient ;
 *   · un REFUS définitif du serveur (422 : valeur absurde, équipement supprimé, heure fausse…) n'est PAS jeté en silence : l'entrée passe
 *     « refusée » avec la raison et reste affichée jusqu'à ce que la personne l'ait lue (`dismiss`) — une mesure sanitaire ne disparaît
 *     jamais sans que quelqu'un le sache ;
 *   · au-delà de 72 h hors ligne, l'entrée est refusée sans appel au serveur (il la refuserait de toute façon) ;
 *   · le rejeu est idempotent côté serveur (`client_ref`) : jamais de doublon, même si le premier envoi avait en fait abouti.
 * Aucune fonction ne lève d'exception.
 */
export const QUEUE_KEY = 'hygiene_offline_queue';
export const MAX_AGE_MS = 72 * 60 * 60 * 1000;
const MAX_ITEMS = 200;
const RETRY_STATUSES = new Set([401, 403, 408, 419, 425, 429]);

/** Faute de réseau ou serveur indisponible : la demande n'a rien dit, on la rejoue plus tard. */
export function isRetriable(err) {
    const status = err && err.response && err.response.status;
    return !status || status >= 500 || RETRY_STATUSES.has(status);
}

/** La raison d'un refus, telle que le serveur l'a écrite (première erreur de champ, sinon le message). */
export function refusalReason(err) {
    const d = err && err.response && err.response.data;
    if (d && d.errors && typeof d.errors === 'object') {
        const first = Object.values(d.errors)[0];
        if (Array.isArray(first) && first[0]) return String(first[0]);
    }
    return (d && d.message) ? String(d.message) : 'Le serveur a refusé ces relevés.';
}

function load(storage) {
    try {
        const raw = storage && storage.getItem(QUEUE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed.filter((i) => i && typeof i === 'object' && i.qid && i.url) : [];
    } catch (_) {
        return [];
    }
}

/** @returns {boolean} écrit pour de bon ? (stockage plein ou interdit : faux, l'appelant garde la saisie à l'écran) */
function save(storage, items) {
    try {
        if (!storage) return false;
        storage.setItem(QUEUE_KEY, JSON.stringify(items.slice(-MAX_ITEMS)));
        return true;
    } catch (_) {
        return false;
    }
}

let seq = 0;
const newId = (now) => { seq += 1; return `${now().toString(36)}-${seq}-${Math.random().toString(36).slice(2, 8)}`; };

/**
 * @param {{post:(url:string, body:object)=>Promise<any>, storage?:Storage, now?:()=>number}} deps
 */
export function createHygieneQueue({ post, storage, now = Date.now }) {
    let flushing = null;

    return {
        /** Garde une écriture à rejouer. @returns {object|null} l'entrée, ou null si le stockage a refusé (rien n'est alors gardé). */
        enqueue({ url, body, label = '', kind = 'temperature' }) {
            const item = { qid: newId(now), url, body, label, kind, at: now(), tries: 1, state: 'pending', reason: '' };
            return save(storage, [...load(storage), item]) ? item : null;
        },

        async flush() {
            if (flushing) return flushing;
            flushing = (async () => {
                const snapshot = load(storage);
                const seen = new Set(snapshot.map((i) => i.qid));
                const out = [];
                let sent = 0;
                let offline = false;
                for (const item of snapshot) {
                    if (item.state !== 'pending' || offline) { out.push(item); continue; }
                    if (now() - item.at > MAX_AGE_MS) {
                        out.push({ ...item, state: 'rejected', reason: 'Gardé plus de 72 h hors ligne : ressaisissez ces relevés avec une mesure actuelle.' });
                        continue;
                    }
                    try {
                        await post(item.url, item.body);
                        sent += 1; // envoyé : l'entrée sort de la file
                    } catch (err) {
                        if (isRetriable(err)) {
                            out.push({ ...item, tries: item.tries + 1 });
                            offline = true; // le réseau est encore absent : inutile d'insister sur les suivantes
                        } else {
                            out.push({ ...item, state: 'rejected', reason: refusalReason(err) });
                        }
                    }
                }
                // ce qui a été mis en file PENDANT l'envoi est gardé : on n'écrase jamais la file avec la photo du début
                const added = load(storage).filter((i) => !seen.has(i.qid));
                save(storage, [...out, ...added]);
                const all = [...out, ...added];
                return { sent, pending: all.filter((i) => i.state === 'pending').length, rejected: all.filter((i) => i.state === 'rejected').length };
            })().finally(() => { flushing = null; });
            return flushing;
        },

        /** La personne a lu le refus : l'entrée peut sortir. */
        dismiss(qid) {
            return save(storage, load(storage).filter((i) => i.qid !== qid));
        },

        items: () => load(storage).map((i) => ({ ...i })),
        pending: () => load(storage).filter((i) => i.state === 'pending').map((i) => ({ ...i })),
        rejected: () => load(storage).filter((i) => i.state === 'rejected').map((i) => ({ ...i })),
    };
}

let singleton = null;

/** File unique de la tablette, branchée sur axios et localStorage. */
export function hygieneQueue(axios) {
    if (!singleton) {
        let storage = null;
        try { storage = typeof localStorage !== 'undefined' ? localStorage : null; } catch (_) { storage = null; }
        singleton = createHygieneQueue({ post: (url, body) => axios.post(url, body), storage });
    }
    return singleton;
}
