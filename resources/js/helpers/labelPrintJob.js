/**
 * labelPrintJob.js — [ETIQUETTES 2026-10-16] Colle entre les blocs de mise en page, le rendu canvas,
 * l'encodeur Brother et le gestionnaire Bluetooth : « voici N étiquettes, imprime‑les ».
 *
 * Rien ici ne touche le DOM autrement que par le canvas de labelRender.js ; la fonction `print` renvoie
 * toujours un résultat (jamais d'exception) que l'écran traduit en message.
 */
import { buildPrintJob, getMedia, appendCutLine } from './brotherQl.js';
import { renderLabel, bitmapToDataUrl } from './labelRender.js';

/**
 * Rend toutes les étiquettes. `overflow` = au moins une ne tient pas dans un rouleau de hauteur fixe :
 * on REFUSE d'imprimer (un allergène ou une sauce tronqués sont pires qu'une étiquette absente).
 */
export function renderAll(blocksList, settings, env = {}) {
    const media = getMedia(settings.mediaId);
    const rendered = blocksList.map((blocks) => renderLabel(blocks, media, env));
    return {
        media,
        rendered,
        overflow: rendered.some((r) => r.overflow),
        overflowIndexes: rendered.map((r, i) => (r.overflow ? i : -1)).filter((i) => i >= 0),
    };
}

/**
 * « Une coupe par commande » : les tickets d'UNE commande restent attachés (une seule coupe, après le dernier) et dans l'ordre.
 * Actif seulement pour un groupe (`group`), avec la coupe automatique, et au moins deux tickets.
 */
export function chainPlan(count, settings, group) {
    const chained = Boolean(group) && settings.autoCut !== false && settings.cutMode === 'order' && count > 1;
    return { chained, cutEvery: chained ? Math.min(255, count) : 1 };
}

/**
 * Bitmaps à imprimer. En ruban continu, chaque ticket sauf le dernier reçoit sa ligne pointillée (rien ne le sépare du suivant
 * que la lame ne coupe pas) ; sur étiquettes prédécoupées la séparation existe déjà sur le support, rien n'est ajouté.
 */
export function bitmapsFor(rendered, media, plan) {
    return rendered.map((r, i) => (plan.chained && media.type === 'continuous' && i < rendered.length - 1 ? appendCutLine(r.bitmap) : r.bitmap));
}

export const LAST_JOB_KEY = 'label_last_job';

/** Garde, sur CETTE tablette, ce qu'elle vient d'envoyer à l'imprimante : l'écran « Imprimante » le relit pour dire la vérité. */
export function rememberJob(job, storage = (typeof localStorage !== 'undefined' ? localStorage : null)) {
    try { if (storage) storage.setItem(LAST_JOB_KEY, JSON.stringify(job)); } catch (_) { /* stockage indisponible : rien à garder */ }
}

export function readLastJob(storage = (typeof localStorage !== 'undefined' ? localStorage : null)) {
    try {
        const j = storage ? JSON.parse(storage.getItem(LAST_JOB_KEY) || 'null') : null;
        return j && typeof j === 'object' && Number.isFinite(j.at) ? j : null;
    } catch (_) { return null; }
}

/** Phrase lisible : « 14:32 — 4 étiquettes, UNE coupe à la fin (tickets attachés) — envoyé ». */
export function describeLastJob(j) {
    if (!j) return '';
    const hm = new Date(j.at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    const n = `${j.pages} étiquette${j.pages > 1 ? 's' : ''}`;
    const cut = j.pages < 2 ? 'une seule étiquette'
        : j.cutEvery >= j.pages ? 'UNE coupe à la fin (tickets attachés)'
            : j.cutEvery === 1 ? 'une coupe après chaque étiquette'
                : `une coupe toutes les ${j.cutEvery} étiquettes`;
    return `${hm} — ${n}, ${cut} — ${j.ok ? 'envoyé' : 'échec'}`;
}

/** URLs d'aperçu (PNG noir et blanc, point pour point ce qui sera imprimé). */
export function previewUrls(blocksList, settings, env = {}, opts = {}) {
    const { media, rendered, overflow } = renderAll(blocksList, settings, env);
    const bitmaps = bitmapsFor(rendered, media, chainPlan(rendered.length, settings, opts.group));
    return { urls: bitmaps.map((b) => bitmapToDataUrl(b, env)), overflow };
}

/**
 * @param {object} printer   instance de labelPrinter()
 * @param {Array<Array<object>>} blocksList  une liste de blocs par étiquette
 * @param {object} settings  réglages de la tablette (mediaId, autoCut, cutMode, mirror, turn180)
 * @param {object} env
 * @param {{group?:boolean}} [opts]  group : ces étiquettes sont les tickets d'UNE commande (voir chainPlan)
 * @returns {Promise<{ok:boolean, unconfirmed?:boolean, message?:string, pages?:number}>}
 */
export async function printBlocks(printer, blocksList, settings, env = {}, opts = {}) {
    try {
        if (!blocksList.length) return { ok: false, message: 'Aucune étiquette à imprimer.' };
        const { media, rendered, overflow } = renderAll(blocksList, settings, env);
        if (overflow) {
            return {
                ok: false,
                message: `Étiquette trop longue pour le rouleau « ${media.label} ». Choisissez le rouleau 62 mm continu dans « Imprimante ».`,
            };
        }
        const plan = chainPlan(rendered.length, settings, opts.group);
        const job = buildPrintJob(bitmapsFor(rendered, media, plan), media, {
            autoCut: settings.autoCut,
            cutEvery: plan.cutEvery,
            mirror: settings.mirror,
            turn180: settings.turn180,
        });
        const result = await printer.print(job, media);
        rememberJob({ at: Date.now(), pages: job.pages, cutEvery: plan.cutEvery, chained: plan.chained, ok: Boolean(result && result.ok) });
        return result;
    } catch (err) {
        return { ok: false, message: 'Préparation de l’étiquette impossible : ' + ((err && err.message) || err) };
    }
}
