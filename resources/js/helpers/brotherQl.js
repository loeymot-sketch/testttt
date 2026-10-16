/**
 * brotherQl.js — [ETIQUETTES 2026-10-16] Protocole « raster » des imprimantes d'étiquettes Brother
 * QL‑800 / QL‑810W / QL‑820NWB, en JavaScript pur (aucune dépendance, aucun accès au navigateur).
 *
 * SOURCE DE VÉRITÉ : « Software Developer's Manual — Raster Command Reference, QL‑800/810W/820NWB,
 * version 1.01 » (download.brother.com/welcome/docp100278/cv_ql800_eng_raster_101.pdf). Chaque octet
 * ci‑dessous renvoie à une section de ce manuel. Rien n'est écrit « de mémoire » : le manuel a été
 * extrait en texte et relu (cf. plans/PLAN_LABELS-BROTHER-QL820NWB_2026-10-16.md).
 *
 * Ce module ne parle PAS à l'imprimante : il fabrique les octets d'un travail d'impression et lit
 * ceux que l'imprimante répond. Le transport (Web Serial / Bluetooth) vit dans labelPrinter.js, ce
 * qui permet de tester ici, sans matériel, que les octets sont ceux que le manuel prescrit.
 *
 * VOCABULAIRE
 *   · « repère de lecture » : l'image telle qu'un humain lit l'étiquette (x vers la droite, y vers le bas).
 *   · « repère tête » : l'image telle que la tête d'impression la consomme — largeur = travers du
 *     ruban (broches), hauteur = sens d'avance du papier.
 *   Pour les rouleaux dont l'étiquette est plus longue que large (29×90, 38×90) on lit l'étiquette
 *   « couchée » et on la tourne de 90° (convention de brother_ql) pour obtenir le repère tête.
 */

/** 90 octets = 720 broches par ligne raster, quelle que soit la largeur du ruban (manuel §2.3.5). */
export const RASTER_BYTES = 90;
export const HEAD_PINS = RASTER_BYTES * 8;
/** Manuel §3 « Invalidate » : un envoi de 400 octets nuls réinitialise l'état de réception. */
export const INVALIDATE_BYTES = 400;
/** Marge d'avance (avant + après) en broches pour le ruban continu : minimum 3 mm = 35 points (§2.3.3). */
export const CONTINUOUS_MARGIN_DOTS = 35;
/** Longueur minimale / maximale d'un ruban continu, en points à 300 dpi (§2.3.4). */
export const CONTINUOUS_MIN_DOTS = 150;
export const CONTINUOUS_MAX_DOTS = 11811;

/**
 * Rouleaux gérés. Les nombres de broches viennent du tableau « Continuous length tape / Die‑cut labels »
 * du manuel §2.3.5 ; `lengthDots` est la « Print area length » du tableau §2.3.2 (b).
 *
 *   leftPins / printPins / rightPins : à gauche / zone imprimable / à droite dans le repère de lecture,
 *   la somme valant toujours 720.
 *   readRotate : l'étiquette se lit couchée → rotation 90° pour passer au repère tête.
 */
export const MEDIA = Object.freeze({
    c62: Object.freeze({
        id: 'c62', ref: 'DK-22205', label: '62 mm continu', type: 'continuous', widthMm: 62, lengthMm: 0,
        leftPins: 12, printPins: 696, rightPins: 12, lengthDots: 0, readRotate: false,
    }),
    d62x29: Object.freeze({
        id: 'd62x29', ref: 'DK-11209', label: '62 × 29 mm', type: 'diecut', widthMm: 62, lengthMm: 29,
        leftPins: 12, printPins: 696, rightPins: 12, lengthDots: 271, readRotate: false,
    }),
    d62x100: Object.freeze({
        id: 'd62x100', ref: 'DK-11202', label: '62 × 100 mm', type: 'diecut', widthMm: 62, lengthMm: 100,
        leftPins: 12, printPins: 696, rightPins: 12, lengthDots: 1109, readRotate: false,
    }),
    d29x90: Object.freeze({
        id: 'd29x90', ref: 'DK-11201', label: '29 × 90 mm', type: 'diecut', widthMm: 29, lengthMm: 90,
        leftPins: 408, printPins: 306, rightPins: 6, lengthDots: 991, readRotate: true,
    }),
    d38x90: Object.freeze({
        id: 'd38x90', ref: 'DK-11208', label: '38 × 90 mm', type: 'diecut', widthMm: 38, lengthMm: 90,
        leftPins: 295, printPins: 413, rightPins: 12, lengthDots: 991, readRotate: true,
    }),
});

export const DEFAULT_MEDIA_ID = 'c62';

export function mediaList() {
    return Object.values(MEDIA);
}

export function getMedia(id) {
    return MEDIA[id] || MEDIA[DEFAULT_MEDIA_ID];
}

/**
 * Dimensions du repère de LECTURE d'une étiquette. `height` vaut null pour un ruban continu
 * (la hauteur dépend du contenu).
 */
export function readFrame(media) {
    if (media.type === 'continuous') return { width: media.printPins, height: null };
    return media.readRotate
        ? { width: media.lengthDots, height: media.printPins }
        : { width: media.printPins, height: media.lengthDots };
}

// ───────────────────────────── Bitmap ─────────────────────────────

/**
 * Bitmap 1 bit : un octet par pixel (1 = point noir), ligne par ligne, en haut à gauche.
 * @typedef {{width:number, height:number, pixels:Uint8Array}} Bitmap
 */
export function createBitmap(width, height) {
    return { width, height, pixels: new Uint8Array(width * height) };
}

/** Rotation de 90° ANTI‑horaire (équivalent de PIL `rotate(90, expand=True)`). */
export function rotateCcw(bitmap) {
    const { width: w, height: h, pixels } = bitmap;
    const out = createBitmap(h, w);
    for (let yp = 0; yp < w; yp++) {
        for (let xp = 0; xp < h; xp++) {
            // new(x', y') = old(w-1-y', x')
            out.pixels[yp * h + xp] = pixels[xp * w + (w - 1 - yp)];
        }
    }
    return out;
}

export function rotate180(bitmap) {
    const { width: w, height: h, pixels } = bitmap;
    const out = createBitmap(w, h);
    for (let i = 0; i < pixels.length; i++) out.pixels[i] = pixels[pixels.length - 1 - i];
    return out;
}

/**
 * Convertit un bitmap de lecture en lignes du repère tête (une `Uint8Array(90)` par ligne).
 *
 * Disposition d'une ligne (convention de brother_ql, validée sur matériel réel) : on compose la ligne
 * de 720 points [gauche vide][contenu][droite vide] dans le repère de lecture, puis on l'INVERSE
 * (miroir) avant l'empaquetage MSB‑first. Pour les rouleaux 62 mm les marges sont symétriques
 * (12/696/12) : seul le sens du contenu compte, et c'est ce miroir qui rend le texte lisible.
 *
 * @param {Bitmap} bitmap  image de lecture
 * @param {object} media   entrée de MEDIA
 * @param {{mirror?:boolean, turn180?:boolean}} [opts]
 * @returns {Uint8Array[]}
 */
export function bitmapToRasterRows(bitmap, media, opts = {}) {
    const mirror = opts.mirror !== false;
    let bm = bitmap;
    if (opts.turn180) bm = rotate180(bm);
    if (media.readRotate) bm = rotateCcw(bm);

    if (bm.width !== media.printPins) {
        throw new Error(`Largeur de l'image (${bm.width}) ≠ zone imprimable du rouleau (${media.printPins}).`);
    }
    if (media.type === 'diecut' && bm.height !== media.lengthDots) {
        throw new Error(`Hauteur de l'image (${bm.height}) ≠ longueur imprimable du rouleau (${media.lengthDots}).`);
    }
    if (media.type === 'continuous' && (bm.height < CONTINUOUS_MIN_DOTS || bm.height > CONTINUOUS_MAX_DOTS)) {
        throw new Error(`Longueur d'étiquette hors limites (${bm.height} points ; ${CONTINUOUS_MIN_DOTS}–${CONTINUOUS_MAX_DOTS}).`);
    }

    const rows = [];
    const line = new Uint8Array(HEAD_PINS);
    for (let y = 0; y < bm.height; y++) {
        line.fill(0);
        line.set(bm.pixels.subarray(y * bm.width, (y + 1) * bm.width), media.leftPins);
        if (mirror) line.reverse();
        const packed = new Uint8Array(RASTER_BYTES);
        for (let x = 0; x < HEAD_PINS; x++) {
            if (line[x]) packed[x >> 3] |= 0x80 >> (x & 7);
        }
        rows.push(packed);
    }
    return rows;
}

// ───────────────────────────── Commandes ─────────────────────────────

const ESC = 0x1b;

/** `ESC i S` — demande de statut (§3) : l'imprimante répond 32 octets. */
export const STATUS_REQUEST = Uint8Array.of(ESC, 0x69, 0x53);

/**
 * `ESC i z` — informations d'impression (§4). Octets n1…n10.
 *   n1 : 0x02 type de rouleau valide · 0x04 largeur valide · 0x08 longueur valide · 0x40 priorité à la
 *        qualité · 0x80 reprise sur erreur. Si le rouleau chargé ne correspond pas à type/largeur/longueur
 *        annoncés, l'imprimante répond « Replace media » : c'est notre garde contre un mauvais rouleau.
 *   n2 : 0x0A ruban continu · 0x0B étiquettes prédécoupées
 *   n3 : largeur mm · n4 : longueur mm (0 en continu) · n5‑n8 : nombre de lignes (petit‑boutiste)
 *   n9 : 0 pour la première page, 1 ensuite · n10 : 0
 */
export function printInformation(media, rasterCount, pageIndex) {
    let flags = 0x80 | 0x40 | 0x02 | 0x04;
    if (media.lengthMm > 0) flags |= 0x08;
    return Uint8Array.of(
        ESC, 0x69, 0x7a,
        flags,
        media.type === 'continuous' ? 0x0a : 0x0b,
        media.widthMm,
        media.lengthMm,
        rasterCount & 0xff, (rasterCount >> 8) & 0xff, (rasterCount >> 16) & 0xff, (rasterCount >> 24) & 0xff,
        pageIndex === 0 ? 0 : 1,
        0x00,
    );
}

/** Codes de commande d'une page (tout ce qui précède les lignes raster), manuel §2.1 (2). */
function controlCodes(media, rasterCount, pageIndex, { autoCut, cutAtEnd }) {
    const parts = [
        Uint8Array.of(ESC, 0x69, 0x61, 0x01),                       // ESC i a 1 : mode raster
        Uint8Array.of(ESC, 0x69, 0x21, 0x00),                       // ESC i ! 0 : notifications d'état activées
        printInformation(media, rasterCount, pageIndex),
        Uint8Array.of(ESC, 0x69, 0x4d, autoCut ? 0x40 : 0x00),      // ESC i M : bit 6 = coupe automatique
        Uint8Array.of(ESC, 0x69, 0x41, 0x01),                       // ESC i A : coupe à chaque étiquette
        Uint8Array.of(ESC, 0x69, 0x4b, cutAtEnd ? 0x08 : 0x00),     // ESC i K : bit 3 = couper en fin de travail
        // ESC i d : marge d'avance. Interdite (0) sur étiquettes prédécoupées (§2.3.3).
        Uint8Array.of(ESC, 0x69, 0x64,
            media.type === 'continuous' ? CONTINUOUS_MARGIN_DOTS & 0xff : 0x00,
            media.type === 'continuous' ? (CONTINUOUS_MARGIN_DOTS >> 8) & 0xff : 0x00),
        Uint8Array.of(0x4d, 0x00),                                  // M 0 : SANS compression (le QL‑800 n'en gère pas)
    ];
    return concat(parts);
}

function concat(chunks) {
    let n = 0;
    chunks.forEach((c) => { n += c.length; });
    const out = new Uint8Array(n);
    let o = 0;
    chunks.forEach((c) => { out.set(c, o); o += c.length; });
    return out;
}

/**
 * Fabrique les octets d'un travail d'impression : une ou plusieurs étiquettes (pages).
 *
 * Structure (manuel §2.1) :
 *   initialisation (une fois) : 400 × 0x00, ESC @
 *   par page : codes de commande, lignes raster `g 00 5A <90 octets>`, puis 0x0C (page suivante) ou
 *              0x1A (dernière page : impression avec avance).
 *
 * @param {Bitmap[]} bitmaps  une image de lecture par étiquette
 * @param {object}   media
 * @param {{autoCut?:boolean, mirror?:boolean, turn180?:boolean}} [opts]
 * @returns {{bytes:Uint8Array, pages:number, rows:number}}
 */
export function buildPrintJob(bitmaps, media, opts = {}) {
    if (!Array.isArray(bitmaps) || bitmaps.length === 0) throw new Error('Aucune étiquette à imprimer.');
    const autoCut = opts.autoCut !== false;
    const chunks = [new Uint8Array(INVALIDATE_BYTES), Uint8Array.of(ESC, 0x40)];
    let totalRows = 0;

    bitmaps.forEach((bitmap, index) => {
        const rows = bitmapToRasterRows(bitmap, media, opts);
        totalRows += rows.length;
        chunks.push(controlCodes(media, rows.length, index, { autoCut, cutAtEnd: autoCut }));
        rows.forEach((row) => {
            chunks.push(Uint8Array.of(0x67, 0x00, RASTER_BYTES), row);
        });
        chunks.push(Uint8Array.of(index === bitmaps.length - 1 ? 0x1a : 0x0c));
    });

    return { bytes: concat(chunks), pages: bitmaps.length, rows: totalRows };
}

// ───────────────────────────── Statut (32 octets) ─────────────────────────────

export const STATUS_LENGTH = 32;

/** Types d'état (§4 « Status type »). */
export const STATUS_TYPE = Object.freeze({
    0x00: 'reply', 0x01: 'completed', 0x02: 'error', 0x04: 'off', 0x05: 'notification', 0x06: 'phase',
});

const MODEL_CODES = Object.freeze({ 0x38: 'QL-800', 0x39: 'QL-810W', 0x41: 'QL-820NWB' });

/** Une trame d'état commence par 80 20 42 34 (« tête », taille, « B », « 4 »). */
export function looksLikeStatus(bytes, offset = 0) {
    return bytes[offset] === 0x80 && bytes[offset + 1] === 0x20
        && bytes[offset + 2] === 0x42 && bytes[offset + 3] === 0x34;
}

/**
 * Extrait les trames d'état complètes d'un tampon, en se resynchronisant sur l'en‑tête.
 * @returns {{frames:Uint8Array[], rest:Uint8Array}} `rest` = octets non consommés (trame partielle)
 */
export function extractStatusFrames(buffer) {
    const frames = [];
    let i = 0;
    while (i + STATUS_LENGTH <= buffer.length) {
        if (looksLikeStatus(buffer, i)) {
            frames.push(buffer.slice(i, i + STATUS_LENGTH));
            i += STATUS_LENGTH;
        } else {
            i += 1; // octet parasite : on cherche l'en‑tête suivant
        }
    }
    // Garde la fin du tampon si elle peut être le début d'une trame.
    let keepFrom = buffer.length;
    for (let j = Math.max(i, buffer.length - (STATUS_LENGTH - 1)); j < buffer.length; j++) {
        if (buffer[j] === 0x80) { keepFrom = j; break; }
    }
    return { frames, rest: buffer.slice(keepFrom) };
}

/** Messages d'erreur en français (manuel §4 tableaux (1) et (2)). */
const ERROR_1 = [
    [0x01, 'no_media', 'Aucun rouleau d’étiquettes dans l’imprimante.', true, 'Plus de rouleau'],
    [0x02, 'end_of_media', 'Fin de rouleau : il n’y a plus d’étiquettes.', true, 'Fin de rouleau'],
    [0x04, 'cutter_jam', 'Bourrage du couteau : ouvrez le capot et dégagez le papier.', true, 'Couteau bloqué'],
    // « Printer in use » : information, pas une panne — elle ne bloque jamais une impression.
    [0x10, 'in_use', 'L’imprimante est occupée.', false, 'Imprimante occupée'],
    [0x20, 'turned_off', 'L’imprimante est en train de s’éteindre.', true, 'Imprimante qui s’éteint'],
];
const ERROR_2 = [
    [0x01, 'replace_media', 'Le rouleau chargé ne correspond pas au format réglé : choisissez le bon format dans les réglages ci-dessous, ou changez le rouleau.', true, 'Mauvais rouleau'],
    [0x02, 'buffer_full', 'Mémoire de l’imprimante pleine.', true, 'Mémoire pleine'],
    [0x04, 'communication', 'Erreur de communication avec l’imprimante.', true, 'Erreur de communication'],
    [0x08, 'comm_buffer_full', 'Tampon de communication plein.', true, 'Communication saturée'],
    [0x10, 'cover_open', 'Capot ouvert : fermez le capot de l’imprimante.', true, 'Capot ouvert'],
    [0x40, 'cannot_feed', 'Le papier ne peut pas avancer (fin de rouleau ou bourrage).', true, 'Papier bloqué'],
    [0x80, 'system_error', 'Erreur système de l’imprimante : éteignez‑la puis rallumez‑la.', true, 'Erreur système'],
];

/**
 * Décode une trame de 32 octets (§4). Ne jette jamais : une trame invalide renvoie `{valid:false}`.
 */
export function parseStatus(frame) {
    if (!frame || frame.length < STATUS_LENGTH || !looksLikeStatus(frame)) return { valid: false };
    const err1 = frame[8];
    const err2 = frame[9];
    const errors = [];
    ERROR_1.forEach(([mask, code, message, blocking, short]) => { if (err1 & mask) errors.push({ code, message, short, blocking: blocking !== false }); });
    ERROR_2.forEach(([mask, code, message, blocking, short]) => { if (err2 & mask) errors.push({ code, message, short, blocking: blocking !== false }); });

    const mediaTypeByte = frame[11];
    return {
        valid: true,
        model: MODEL_CODES[frame[4]] || 'inconnu',
        errors,
        /** Vrai s'il existe au moins une erreur BLOQUANTE (voir `blocking`). */
        hasError: errors.some((e) => e.blocking),
        media: {
            widthMm: frame[10],
            lengthMm: frame[17],
            type: mediaTypeByte === 0x4a ? 'continuous' : mediaTypeByte === 0x4b ? 'diecut' : 'none',
        },
        statusType: STATUS_TYPE[frame[18]] || 'unknown',
        statusTypeCode: frame[18],
        phase: frame[19] === 0x01 ? 'printing' : 'receiving',
        notification: frame[22],
    };
}

/**
 * Le rouleau CHARGÉ correspond‑il au format RÉGLÉ ? Renvoie un message en français, ou null si tout va bien.
 * Évite d'envoyer un travail qui finira en « Replace media » incompréhensible.
 */
export function mediaMismatchMessage(status, media) {
    if (!status || !status.valid) return null;
    const loaded = status.media;
    if (loaded.type === 'none' || loaded.widthMm === 0) return null; // « aucun rouleau » est déjà dans errors
    const wantType = media.type;
    const same = loaded.type === wantType && loaded.widthMm === media.widthMm
        && (wantType === 'continuous' || loaded.lengthMm === media.lengthMm);
    if (same) return null;
    const dim = (m) => (m.type === 'continuous' ? `${m.widthMm} mm continu` : `${m.widthMm} × ${m.lengthMm} mm`);
    return `Rouleau posé dans l’imprimante : ${dim(loaded)} — réglage actuel : ${dim(media)}. Choisissez le bon format dans les réglages, ou changez le rouleau.`;
}

/**
 * Le rouleau que l'imprimante dit avoir chargé, s'il fait partie des formats gérés (sinon null).
 * Sert à proposer « Utiliser le rouleau détecté » en un appui plutôt que de laisser l'équipe chercher le bon réglage.
 */
export function mediaFromStatus(status) {
    if (!status || !status.valid || !status.media || status.media.type === 'none') return null;
    const m = status.media;
    return mediaList().find((x) => x.type === m.type && x.widthMm === m.widthMm
        && (x.type === 'continuous' || x.lengthMm === m.lengthMm)) || null;
}
