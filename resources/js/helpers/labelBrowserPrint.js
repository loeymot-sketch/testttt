/**
 * labelBrowserPrint.js — [ETIQUETTES 2026-10-16] Impression de SECOURS par la boîte d'impression d'Android
 * (plugin « Brother Print Service » ou impression système), quand Web Serial n'est pas disponible
 * (navigateur autre que Chrome, Chrome < 138, Bluetooth non appairé…).
 *
 * Moins confortable (une boîte de dialogue à chaque fois, pas de lecture d'état) mais elle ne dépend de rien :
 * l'étiquette rendue est la même, à la taille exacte du rouleau.
 */
import { getMedia } from './brotherQl.js';

/** Construit la page à imprimer : une image par étiquette, à la taille réelle du ruban, sans marge. */
export function buildPrintDocument(dataUrls, media) {
    const widthMm = media.widthMm;
    const pages = dataUrls
        .map((u) => `<div class="p"><img src="${u}" alt=""></div>`)
        .join('');
    const size = media.type === 'continuous' ? `${widthMm}mm auto` : `${media.widthMm}mm ${media.lengthMm}mm`;
    return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Étiquettes</title><style>
@page { size: ${size}; margin: 0; }
html, body { margin: 0; padding: 0; background: #fff; }
.p { width: ${widthMm}mm; page-break-after: always; break-after: page; }
.p:last-child { page-break-after: auto; break-after: auto; }
img { display: block; width: ${widthMm}mm; height: auto; image-rendering: pixelated; }
</style></head><body>${pages}</body></html>`;
}

/** Ouvre la boîte d'impression du système. Renvoie false si le navigateur l'empêche. */
export function printViaBrowser(dataUrls, mediaId, doc = typeof document !== 'undefined' ? document : null) {
    if (!doc || !dataUrls.length) return false;
    const media = getMedia(mediaId);
    try {
        const frame = doc.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
        doc.body.appendChild(frame);
        const w = frame.contentWindow;
        w.document.open();
        w.document.write(buildPrintDocument(dataUrls, media));
        w.document.close();
        const run = () => {
            try { w.focus(); w.print(); } catch (_) { /* annulé */ }
            setTimeout(() => { try { doc.body.removeChild(frame); } catch (_) { /* déjà retiré */ } }, 60000);
        };
        // laisse aux images le temps de se décoder avant d'ouvrir la boîte d'impression
        setTimeout(run, 300);
        return true;
    } catch (_) {
        return false;
    }
}
