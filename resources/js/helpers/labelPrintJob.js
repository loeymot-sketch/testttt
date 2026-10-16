/**
 * labelPrintJob.js — [ETIQUETTES 2026-10-16] Colle entre les blocs de mise en page, le rendu canvas,
 * l'encodeur Brother et le gestionnaire Bluetooth : « voici N étiquettes, imprime‑les ».
 *
 * Rien ici ne touche le DOM autrement que par le canvas de labelRender.js ; la fonction `print` renvoie
 * toujours un résultat (jamais d'exception) que l'écran traduit en message.
 */
import { buildPrintJob, getMedia } from './brotherQl.js';
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

/** URLs d'aperçu (PNG noir et blanc, point pour point ce qui sera imprimé). */
export function previewUrls(blocksList, settings, env = {}) {
    const { rendered, overflow } = renderAll(blocksList, settings, env);
    return { urls: rendered.map((r) => bitmapToDataUrl(r.bitmap, env)), overflow };
}

/**
 * @param {object} printer   instance de labelPrinter()
 * @param {Array<Array<object>>} blocksList  une liste de blocs par étiquette
 * @param {object} settings  réglages de la tablette (mediaId, autoCut, mirror, turn180)
 * @returns {Promise<{ok:boolean, unconfirmed?:boolean, message?:string, pages?:number}>}
 */
export async function printBlocks(printer, blocksList, settings, env = {}) {
    try {
        if (!blocksList.length) return { ok: false, message: 'Aucune étiquette à imprimer.' };
        const { media, rendered, overflow } = renderAll(blocksList, settings, env);
        if (overflow) {
            return {
                ok: false,
                message: `Étiquette trop longue pour le rouleau « ${media.label} ». Choisissez le rouleau 62 mm continu dans « Imprimante ».`,
            };
        }
        const job = buildPrintJob(rendered.map((r) => r.bitmap), media, {
            autoCut: settings.autoCut,
            mirror: settings.mirror,
            turn180: settings.turn180,
        });
        return await printer.print(job, media);
    } catch (err) {
        return { ok: false, message: 'Préparation de l’étiquette impossible : ' + ((err && err.message) || err) };
    }
}
