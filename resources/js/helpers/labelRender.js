/**
 * labelRender.js — [ETIQUETTES 2026-10-16] Dessine une étiquette (liste de blocs) sur un canvas, puis la
 * convertit en bitmap 1 bit pour l'imprimante. L'aperçu affiché à l'écran est reconstruit À PARTIR de ce
 * même bitmap : ce que l'on voit est, point pour point, ce qui est envoyé.
 *
 * Le canvas est injectable (`env.createCanvas`) pour les tests ; par défaut on prend celui du navigateur.
 */
import { layoutLabel } from './labelLayout.js';
import { readFrame, createBitmap } from './brotherQl.js';

/** Luminance en dessous de laquelle un pixel devient un point noir (légèrement haute : traits plus nourris). */
export const BLACK_THRESHOLD = 170;

function defaultCreateCanvas(width, height) {
    if (typeof document !== 'undefined' && document.createElement) {
        const c = document.createElement('canvas');
        c.width = width;
        c.height = height;
        return c;
    }
    if (typeof OffscreenCanvas !== 'undefined') return new OffscreenCanvas(width, height);
    throw new Error('Canvas indisponible : impossible de dessiner l’étiquette.');
}

function paint(ctx, layout) {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, layout.width, layout.height);
    ctx.textBaseline = 'top';
    layout.commands.forEach((c) => {
        if (c.op === 'rect') {
            ctx.fillStyle = c.fill;
            ctx.fillRect(c.x, c.y, c.w, c.h);
        } else if (c.op === 'frame') {
            ctx.strokeStyle = '#000';
            ctx.lineWidth = c.line;
            ctx.strokeRect(c.x + c.line / 2, c.y + c.line / 2, c.w - c.line, c.h - c.line);
        } else if (c.op === 'text') {
            ctx.font = c.font;
            ctx.fillStyle = c.color;
            ctx.fillText(c.text, c.x, c.y);
        }
    });
}

/** Canvas → Bitmap 1 bit (1 = noir). */
export function canvasToBitmap(canvas, threshold = BLACK_THRESHOLD) {
    const ctx = canvas.getContext('2d');
    const { width, height } = canvas;
    const data = ctx.getImageData(0, 0, width, height).data;
    const bm = createBitmap(width, height);
    for (let i = 0, p = 0; p < bm.pixels.length; i += 4, p++) {
        const a = data[i + 3] / 255;
        // composite sur fond blanc
        const r = data[i] * a + 255 * (1 - a);
        const g = data[i + 1] * a + 255 * (1 - a);
        const b = data[i + 2] * a + 255 * (1 - a);
        bm.pixels[p] = 0.299 * r + 0.587 * g + 0.114 * b < threshold ? 1 : 0;
    }
    return bm;
}

/**
 * @param {Array<object>} blocks   blocs de mise en page (voir labelLayout.js)
 * @param {object} media           entrée de MEDIA
 * @param {{createCanvas?:Function}} [env]
 * @returns {{bitmap:object, width:number, height:number, overflow:boolean, scale:number}}
 */
export function renderLabel(blocks, media, env = {}) {
    const createCanvas = env.createCanvas || defaultCreateCanvas;
    const frame = readFrame(media);
    const probe = createCanvas(8, 8).getContext('2d');
    const measure = (text, font) => {
        probe.font = font;
        return probe.measureText(text).width;
    };
    const layout = layoutLabel(blocks, { width: frame.width, height: frame.height, measure });
    const canvas = createCanvas(layout.width, layout.height);
    paint(canvas.getContext('2d'), layout);
    return {
        bitmap: canvasToBitmap(canvas),
        width: layout.width,
        height: layout.height,
        overflow: layout.overflow,
        scale: layout.scale,
    };
}

/** Bitmap → URL d'image PNG (noir et blanc strict) pour l'aperçu à l'écran. */
export function bitmapToDataUrl(bitmap, env = {}) {
    const createCanvas = env.createCanvas || defaultCreateCanvas;
    const canvas = createCanvas(bitmap.width, bitmap.height);
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(bitmap.width, bitmap.height);
    for (let p = 0, i = 0; p < bitmap.pixels.length; p++, i += 4) {
        const v = bitmap.pixels[p] ? 0 : 255;
        img.data[i] = v;
        img.data[i + 1] = v;
        img.data[i + 2] = v;
        img.data[i + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
    return canvas.toDataURL ? canvas.toDataURL('image/png') : '';
}
