/**
 * [ETIQUETTES 2026-10-19 · owner] Dictée vocale : l'enregistrement du navigateur (webm/opus, mp4…) devient un WAV
 * 16 kHz mono — le seul format que TOUS les fournisseurs de modèle acceptent en entrée audio. Les fonctions de
 * calcul sont pures (testables sans micro) ; seules `recordVoice` et `blobToWav` touchent au navigateur.
 */

export const VOICE_RATE = 16000;
/** En dessous, l'enregistrement est du silence (micro coupé, mauvais périphérique) : inutile de l'envoyer. */
export const SILENCE_PEAK = 0.02;

/** Moyenne des canaux → un seul. */
export function toMono(channels) {
    if (!channels || !channels.length) return new Float32Array(0);
    if (channels.length === 1) return channels[0];
    const out = new Float32Array(channels[0].length);
    for (let c = 0; c < channels.length; c += 1) {
        const ch = channels[c];
        for (let i = 0; i < out.length; i += 1) out[i] += ch[i] / channels.length;
    }
    return out;
}

/** Rééchantillonnage linéaire (suffisant pour la parole à 16 kHz). */
export function resampleLinear(data, from, to) {
    if (!data.length || from === to) return data;
    const length = Math.max(1, Math.round((data.length * to) / from));
    const out = new Float32Array(length);
    const ratio = (data.length - 1) / Math.max(1, length - 1);
    for (let i = 0; i < length; i += 1) {
        const pos = i * ratio;
        const lo = Math.floor(pos);
        const hi = Math.min(data.length - 1, lo + 1);
        out[i] = data[lo] + (data[hi] - data[lo]) * (pos - lo);
    }
    return out;
}

/** Plus grande amplitude absolue (0..1). */
export function peakOf(data) {
    let peak = 0;
    for (let i = 0; i < data.length; i += 1) {
        const v = Math.abs(data[i]);
        if (v > peak) peak = v;
    }
    return peak;
}

/** WAV PCM 16 bits mono. */
export function encodeWav(samples, rate = VOICE_RATE) {
    const buffer = new ArrayBuffer(44 + samples.length * 2);
    const view = new DataView(buffer);
    const text = (offset, s) => { for (let i = 0; i < s.length; i += 1) view.setUint8(offset + i, s.charCodeAt(i)); };
    text(0, "RIFF");
    view.setUint32(4, 36 + samples.length * 2, true);
    text(8, "WAVE");
    text(12, "fmt ");
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true); // PCM
    view.setUint16(22, 1, true); // mono
    view.setUint32(24, rate, true);
    view.setUint32(28, rate * 2, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    text(36, "data");
    view.setUint32(40, samples.length * 2, true);
    for (let i = 0; i < samples.length; i += 1) {
        const s = Math.max(-1, Math.min(1, samples[i]));
        view.setInt16(44 + i * 2, s < 0 ? s * 0x8000 : s * 0x7fff, true);
    }

    return buffer;
}

export function voiceSupported() {
    return typeof navigator !== "undefined" && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) && typeof MediaRecorder !== "undefined";
}

/** Message lisible pour une erreur du micro. */
export function micErrorMessage(e) {
    const name = e && e.name;
    if (name === "NotAllowedError" || name === "SecurityError") return "Le micro est bloqué : autorisez-le dans le navigateur (cadenas près de l’adresse), puis réessayez.";
    if (name === "NotFoundError" || name === "OverconstrainedError") return "Aucun micro détecté sur cet appareil.";
    if (name === "NotReadableError") return "Le micro est utilisé par une autre application.";
    return "Le micro n’a pas démarré. Réessayez.";
}

/** Décode un enregistrement du navigateur et le convertit en WAV. @returns {Promise<{wav: Blob, peak: number, seconds: number}>} */
export async function blobToWav(blob) {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) throw new Error("Audio non pris en charge.");
    const ctx = new Ctx();
    try {
        const decoded = await ctx.decodeAudioData(await blob.arrayBuffer());
        const channels = [];
        for (let c = 0; c < decoded.numberOfChannels; c += 1) channels.push(decoded.getChannelData(c));
        const samples = resampleLinear(toMono(channels), decoded.sampleRate, VOICE_RATE);

        return { wav: new Blob([encodeWav(samples)], { type: "audio/wav" }), peak: peakOf(samples), seconds: samples.length / VOICE_RATE };
    } finally {
        if (ctx.close) ctx.close().catch(() => {});
    }
}

/**
 * Démarre l'enregistrement. `stop()` rend l'enregistrement ; `cancel()` l'abandonne. Le micro est TOUJOURS relâché.
 * @returns {Promise<{stop: () => Promise<Blob>, cancel: () => void}>}
 */
export async function recordVoice() {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
    const release = () => stream.getTracks().forEach((t) => t.stop());
    let recorder;
    try {
        recorder = new MediaRecorder(stream);
    } catch (e) {
        release();
        throw e;
    }
    const chunks = [];
    recorder.ondataavailable = (ev) => { if (ev.data && ev.data.size) chunks.push(ev.data); };
    recorder.start();

    return {
        stop: () => new Promise((resolve) => {
            recorder.onstop = () => { release(); resolve(new Blob(chunks, { type: recorder.mimeType || "audio/webm" })); };
            if (recorder.state === "inactive") { release(); resolve(new Blob(chunks)); } else recorder.stop();
        }),
        cancel: () => {
            recorder.onstop = null;
            try { if (recorder.state !== "inactive") recorder.stop(); } catch (e) { /* déjà arrêté */ }
            release();
        },
    };
}
