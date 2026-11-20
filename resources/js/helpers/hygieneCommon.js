// [CAYENNE HYGIÈNE 2026-10-17] Outils communs des écrans hygiène : références d'envoi, messages d'erreur du serveur, heures,
// compression de photo (le téléphone envoie 4 Mo ; le serveur en garde 200 Ko) et affichage d'une pièce jointe protégée.
import axios from "axios";

/** Référence d'envoi : rejouer la même requête (coupure réseau) ne crée jamais un doublon côté serveur. */
export function newClientRef(prefix = "h") {
    return `${prefix}${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

// ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// [VG-2026-10-21 M8-101 M8-103] « C'est bien le serveur qui a répondu » : UNE définition pour tout le module. Une réponse 200 qui n'a pas
// la forme attendue (portail captif, proxy, page HTML, corps vide) n'est NI « enregistré » (un relevé perdu avec un « merci ! »), NI « rien
// à faire » (« Aucun équipement », « Aucune fiche prévue » sur AUCUNE donnée). Le tour 1 n'avait gardé que l'accueil.
// ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Ce qu'on dit quand un envoi a reçu une réponse qui ne prouve pas l'enregistrement : la saisie n'est pas perdue. */
export const UNCONFIRMED_SAVE = "Envoi non confirmé — votre saisie est gardée : revalidez dès que la connexion est rétablie (rien ne sera doublé).";

const isObj = (x) => Boolean(x) && typeof x === "object" && !Array.isArray(x);
const hasId = (x) => isObj(x) && Number.isInteger(Number(x.id)) && Number(x.id) > 0;

/**
 * Erreur « réponse inattendue » : sans `response`, elle est traitée comme une absence de réseau (rejouable, jamais « réussi »).
 * `message` : ce que l'écran dit (un envoi non confirmé) ; absent pour un chargement, l'écran dit alors son propre « indisponible ».
 */
export function unexpectedResponse(message = "") {
    const e = new Error(message || "Réponse inattendue du réseau");
    e.hyUnexpected = true;
    e.hyMessage = message;
    return e;
}
export function isUnexpectedResponse(e) { return Boolean(e && e.hyUnexpected); }

/**
 * Chargement : `{ data: [...] | {...}, meta: { server_now } }` — un vrai serveur du module envoie toujours son horloge. Sinon : erreur.
 * Usage : `const data = loadedBody(await axios.get(url))`.
 */
export function loadedBody(response) {
    const data = response && response.data;
    if (!isObj(data) || !(Array.isArray(data.data) || isObj(data.data)) || !isObj(data.meta) || !data.meta.server_now) throw unexpectedResponse();
    return data;
}

/**
 * Écriture : réussie SEULEMENT si le corps prouve l'enregistrement. Sinon : erreur (la saisie reste, rien n'est dit « enregistré »).
 *  - lot de relevés (`readings`) : une ligne par relevé envoyé, chacune avec son identifiant, et chaque référence d'envoi rendue ;
 *  - une fiche : `{ data: { id } }` ; une suppression : `{ status: "ok" }` ; une création groupée : `{ data: { added } }`.
 * Usage : `const data = savedBody(await axios.post(url, body))` — ou `savedBody(res, body.readings)` pour un lot.
 */
export function savedBody(response, readings) {
    const data = response && response.data;
    const fail = () => unexpectedResponse(UNCONFIRMED_SAVE);
    if (!isObj(data)) throw fail();
    if (Array.isArray(readings)) {
        const rows = Array.isArray(data.data) ? data.data : null;
        if (!rows || rows.length !== readings.length || !rows.every(hasId)) throw fail();
        const back = new Set(rows.map((r) => r.client_ref));
        if (readings.some((r) => r && r.client_ref && !back.has(r.client_ref))) throw fail();
        return data;
    }
    if (data.status === "ok" || hasId(data.data) || (isObj(data.data) && Number.isInteger(data.data.added))) return data;
    throw fail();
}

/**
 * Message d'un chargement raté : au premier chargement, l'écran n'a rien (« indisponible ») ; ensuite les données affichées sont GARDÉES et
 * dites périmées, avec leur heure — jamais remplacées par une liste vide inventée.
 */
export function loadFailedText(firstText, lastOkAt, tz = "Europe/Paris") {
    if (!lastOkAt) return firstText;
    const at = formatHm(new Date(lastOkAt).toISOString(), tz) || "tout à l'heure";
    return `Réseau indisponible : ces chiffres datent de ${at}. Ils ne sont peut-être plus à jour.`;
}

/** Premier message d'erreur lisible d'une réponse du serveur (champ invalide d'abord, puis message général). */
export function apiMessage(e, fallback) {
    if (isUnexpectedResponse(e) && e.hyMessage) return e.hyMessage; // [VG-2026-10-21 M8-101] un envoi non confirmé le dit, la saisie est gardée
    const data = e && e.response && e.response.data;
    if (data && data.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) return first[0];
    }
    return (data && data.message) || fallback;
}

export function formatHm(iso, tz = "Europe/Paris") {
    try {
        return new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
    } catch (_) { return ""; }
}

export function formatDayHm(iso, tz = "Europe/Paris") {
    try {
        return new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
    } catch (_) { return ""; }
}

export function formatDay(iso, tz = "Europe/Paris") {
    try {
        return new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", timeZone: tz }).format(new Date(iso));
    } catch (_) { return ""; }
}

/** Réduit une photo avant l'envoi (côté long ≤ 1600 px, JPEG 82 %). Sans canvas ou si le décodage échoue : le fichier est envoyé tel quel. */
export async function compressImage(file, maxSide = 1600, quality = 0.82) {
    try {
        if (!file || !/^image\//.test(file.type || "") || typeof document === "undefined") return file;
        const url = URL.createObjectURL(file);
        try {
            const img = await new Promise((resolve, reject) => {
                const i = new Image();
                i.onload = () => resolve(i);
                i.onerror = reject;
                i.src = url;
            });
            const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
            const canvas = document.createElement("canvas");
            canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
            const ctx = canvas.getContext("2d");
            if (!ctx) return file;
            ctx.fillStyle = "#fff";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", quality));
            return blob || file;
        } finally {
            URL.revokeObjectURL(url);
        }
    } catch (_) {
        return file;
    }
}

/** Envoie une photo et renvoie la pièce jointe créée { id, url, … }. */
export async function uploadPhoto(file, kind = "other") {
    const body = await compressImage(file);
    const form = new FormData();
    form.append("file", body, "photo.jpg");
    form.append("kind", kind);
    // [VG-2026-10-21 M8-101] une photo « envoyée » sans identifiant rendu n'est pas jointe : erreur, pas une pièce jointe vide
    return savedBody(await axios.post("admin/hygiene/attachments", form, { headers: { "Content-Type": "multipart/form-data" } })).data;
}

/** Les pièces jointes sont derrière authentification : un <img src> ne porte pas le jeton, on charge donc l'image puis on l'expose en blob. */
export async function loadPhotoUrl(id) {
    const { data } = await axios.get(`admin/hygiene/attachments/${id}`, { responseType: "blob" });
    return URL.createObjectURL(data);
}

/** Date du jour AAAA-MM-JJ dans le fuseau du restaurant (jamais celui de la tablette : une tablette mal réglée ne décale pas une période). */
export function todayIso(tz = "Europe/Paris", now = new Date()) {
    try {
        return new Intl.DateTimeFormat("en-CA", { year: "numeric", month: "2-digit", day: "2-digit", timeZone: tz }).format(now);
    } catch (_) {
        return now.toISOString().slice(0, 10);
    }
}

/** AAAA-MM-JJ + n jours (calcul en UTC pur : aucun décalage d'heure d'été). */
export function addDaysIso(iso, n) {
    const d = new Date(`${iso}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
}

/** Télécharge un fichier protégé : chargé avec le jeton (blob), puis proposé au navigateur sous `filename`. */
export async function downloadFile(url, params, filename) {
    const { data } = await axios.get(url, { params, responseType: "blob" });
    const href = URL.createObjectURL(data);
    const a = document.createElement("a");
    a.href = href;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
}

/**
 * Référence d'envoi dérivée du CONTENU : rejouer la même requête (coupure réseau) garde la même référence — le serveur ne double rien ;
 * corriger la saisie après un refus change la référence — le serveur n'ignore pas la correction en rendant l'ancienne ligne.
 */
export function refFor(base, payload) {
    const s = JSON.stringify(payload);
    let h = 5381;
    for (let i = 0; i < s.length; i += 1) h = ((h * 33) ^ s.charCodeAt(i)) >>> 0;
    return `${base}-${h.toString(36)}`;
}

/** Compteur de requêtes : seule la DERNIÈRE réponse a le droit d'écrire à l'écran (une réponse lente d'un ancien filtre ne l'écrase pas). */
export function latest() {
    let n = 0;
    return { next: () => { n += 1; return n; }, isCurrent: (id) => id === n };
}


/**
 * [CAYENNE HYGIÈNE 2026-10-18] Le conteneur qui défile dans l'admin est `.db-main` (h-screen, overflow-auto), PAS la fenêtre : le
 * `scrollBehavior` du routeur ne le remet donc jamais en haut. Une page ouverte depuis le bas du hub (« Autres outils ») s'ouvrait défilée,
 * titre et bouton « retour au hub » hors de vue. Chaque écran du module remet le conteneur en haut en s'ouvrant.
 */
export function scrollShellToTop() {
    try {
        const main = typeof document !== "undefined" ? document.querySelector(".db-main") : null;
        if (main && main.scrollTop) main.scrollTop = 0;
    } catch (_) { /* sans effet : au pire l'écran s'ouvre là où il était */ }
}
