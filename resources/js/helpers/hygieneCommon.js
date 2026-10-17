// [CAYENNE HYGIÈNE 2026-10-17] Outils communs des écrans hygiène : références d'envoi, messages d'erreur du serveur, heures,
// compression de photo (le téléphone envoie 4 Mo ; le serveur en garde 200 Ko) et affichage d'une pièce jointe protégée.
import axios from "axios";

/** Référence d'envoi : rejouer la même requête (coupure réseau) ne crée jamais un doublon côté serveur. */
export function newClientRef(prefix = "h") {
    return `${prefix}${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/** Premier message d'erreur lisible d'une réponse du serveur (champ invalide d'abord, puis message général). */
export function apiMessage(e, fallback) {
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
    const { data } = await axios.post("admin/hygiene/attachments", form, { headers: { "Content-Type": "multipart/form-data" } });
    return data.data;
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
