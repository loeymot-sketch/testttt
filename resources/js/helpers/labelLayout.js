/**
 * labelLayout.js — [ETIQUETTES 2026-10-16] Mise en page PURE d'une étiquette : une liste de blocs
 * (bandeau, titre, lignes…) devient une liste de commandes de dessin positionnées. Aucune dépendance
 * au navigateur : la mesure du texte est injectée, ce qui permet de tester ici le retour à la ligne
 * et le dépassement sans canvas (le dessin réel vit dans labelRender.js).
 *
 * RÈGLE DE SÛRETÉ ALIMENTAIRE : on ne TRONQUE jamais un texte en silence. Si le contenu ne tient pas
 * dans une étiquette de hauteur fixe, même après réduction des polices jusqu'au minimum, le résultat
 * porte `overflow: true` et l'appelant refuse d'imprimer (un allergène coupé est pire que pas d'étiquette).
 */

export const FONT_FAMILY = '"Roboto", "Helvetica Neue", Arial, "Liberation Sans", sans-serif';

/** Tailles à l'échelle 1 (points d'impression à 300 dpi : 12 px ≈ 1 mm). */
export const SIZES = Object.freeze({ band: 64, title: 50, lg: 44, md: 34, sm: 26 });

const PAD_X = 14;
const PAD_Y = 10;
const GAP = 8;
const LINE_HEIGHT = 1.16;
/** Echelles essayées, dans l'ordre, pour faire tenir un contenu dans une hauteur fixe. */
const SCALE_STEPS = [1, 0.92, 0.84, 0.76, 0.68, 0.6];
export const MIN_LABEL_DOTS = 160;

function fontOf(size, bold) {
    return `${bold ? '700' : '400'} ${Math.round(size)}px ${FONT_FAMILY}`;
}

/** Retour à la ligne par mots ; un mot plus large que la ligne est coupé au caractère. */
export function wrapText(text, maxWidth, font, measure) {
    const out = [];
    const paragraphs = String(text ?? '').split('\n');
    paragraphs.forEach((para) => {
        const words = para.split(/\s+/).filter(Boolean);
        if (words.length === 0) { out.push(''); return; }
        let line = '';
        const pushWord = (word) => {
            if (measure(word, font) <= maxWidth) { line = word; return; }
            // mot trop large : coupe au caractère
            let chunk = '';
            Array.from(word).forEach((ch) => {
                if (measure(chunk + ch, font) > maxWidth && chunk) {
                    out.push(chunk);
                    chunk = ch;
                } else {
                    chunk += ch;
                }
            });
            line = chunk;
        };
        words.forEach((word) => {
            if (!line) { pushWord(word); return; }
            const candidate = `${line} ${word}`;
            if (measure(candidate, font) <= maxWidth) {
                line = candidate;
            } else {
                out.push(line);
                line = '';
                pushWord(word);
            }
        });
        if (line) out.push(line);
    });
    return out;
}

function layoutOnce(blocks, width, measure, scale) {
    const cmds = [];
    let clipped = false;
    const inner = width - PAD_X * 2;
    // Un bandeau en première position part du bord haut de l'étiquette (pas de liseré blanc au‑dessus).
    let y = blocks[0] && blocks[0].t === 'band' ? 0 : PAD_Y;
    const sz = (key) => SIZES[key] * scale;

    blocks.forEach((b, index) => {
        const first = index === 0;
        const gap = first ? 0 : GAP * scale;

        if (b.t === 'gap') { y += (b.h || 10) * scale; return; }

        if (b.t === 'rule') {
            y += gap;
            cmds.push({ op: 'rect', x: PAD_X, y, w: inner, h: Math.max(2, 3 * scale), fill: '#000' });
            y += Math.max(2, 3 * scale);
            return;
        }

        if (b.t === 'band') {
            // Bandeau noir plein largeur, texte blanc : le numéro de commande se lit de loin.
            let size = sz('band');
            const bandPad = 8 * scale;
            let left = String(b.left ?? '');
            let right = String(b.right ?? '');
            // réduit la police jusqu'à ce que les deux textes tiennent côte à côte
            for (let i = 0; i < 12; i++) {
                const wl = measure(left, fontOf(size, true));
                const wr = right ? measure(right, fontOf(size, true)) : 0;
                if (wl + wr + 28 <= inner - bandPad) break;
                size *= 0.92;
            }
            // Réduction épuisée et les deux textes se chevauchent encore : on le DIT, jamais de recouvrement silencieux.
            if (measure(left, fontOf(size, true)) + (right ? measure(right, fontOf(size, true)) : 0) + 28 > inner - bandPad) clipped = true;
            const h = size * LINE_HEIGHT + bandPad * 2;
            const top = y + (first ? 0 : gap);
            cmds.push({ op: 'rect', x: 0, y: top, w: width, h, fill: '#000' });
            cmds.push({ op: 'text', x: PAD_X + bandPad, y: top + bandPad, text: left, font: fontOf(size, true), color: '#fff', size });
            if (right) {
                const wr = measure(right, fontOf(size, true));
                cmds.push({ op: 'text', x: width - PAD_X - bandPad - wr, y: top + bandPad, text: right, font: fontOf(size, true), color: '#fff', size });
            }
            y = top + h;
            return;
        }

        if (b.t === 'dayline') {
            // [ÉTIQUETTE DLC] Pastille du JOUR de la DLC (« DIM ») en blanc sur noir, à gauche ; à droite la légende et la
            // date/heure. C'est l'équivalent, en impression noir et blanc, des pastilles de couleur par jour des cuisines :
            // on repère d'un coup d'œil, dans la chambre froide, ce qui doit partir aujourd'hui.
            y += gap;
            const capSize = sz('sm');
            const dateSize = sz('lg');
            const capFont = fontOf(capSize, true);
            const dateFont = fontOf(dateSize, true);
            const textH = capSize * LINE_HEIGHT + dateSize * LINE_HEIGHT;
            const pad = 10 * scale;
            const dayFontSize = Math.min(textH * 0.62, sz('band') * 1.05);
            const dayFont = fontOf(dayFontSize, true);
            const dayText = String(b.day || '');
            const badgeW = Math.max(textH, measure(dayText, dayFont) + pad * 2);
            const h = textH + pad * 2;
            cmds.push({ op: 'rect', x: PAD_X, y, w: badgeW, h, fill: '#000' });
            const dw = measure(dayText, dayFont);
            cmds.push({ op: 'text', x: PAD_X + (badgeW - dw) / 2, y: y + (h - dayFontSize * LINE_HEIGHT) / 2, text: dayText, font: dayFont, color: '#fff', size: dayFontSize });
            const tx = PAD_X + badgeW + 14 * scale;
            const avail = width - PAD_X - tx;
            const cap = String(b.caption || '');
            const date = String(b.text || '');
            if (measure(cap, capFont) > avail - 10 * scale || measure(date, dateFont) > avail - 10 * scale) clipped = true;
            cmds.push({ op: 'frame', x: tx - 6 * scale, y, w: width - PAD_X - tx + 6 * scale, h, line: Math.max(3, 4 * scale) });
            cmds.push({ op: 'text', x: tx + 4 * scale, y: y + pad, text: cap, font: capFont, color: '#000', size: capSize });
            cmds.push({ op: 'text', x: tx + 4 * scale, y: y + pad + capSize * LINE_HEIGHT, text: date, font: dateFont, color: '#000', size: dateSize });
            y += h;
            return;
        }

        if (b.t === 'pair') {
            y += gap;
            const size = sz(b.size || 'md');
            const font = fontOf(size, b.bold !== false);
            const wr = measure(String(b.right ?? ''), font);
            if (measure(String(b.left ?? ''), fontOf(size, false)) + wr + 16 > inner) clipped = true;
            cmds.push({ op: 'text', x: PAD_X, y, text: String(b.left ?? ''), font: fontOf(size, false), color: '#000', size });
            cmds.push({ op: 'text', x: width - PAD_X - wr, y, text: String(b.right ?? ''), font, color: '#000', size });
            y += size * LINE_HEIGHT;
            return;
        }

        // title | line | sub : texte à retour à la ligne
        const sizeKey = b.t === 'title' ? 'title' : (b.size || (b.t === 'sub' ? 'sm' : 'md'));
        const size = sz(sizeKey);
        const bold = b.t === 'title' ? true : b.bold !== false && b.t !== 'sub';
        const font = fontOf(size, bold);
        const boxed = Boolean(b.box);
        const boxPad = boxed ? 10 * scale : 0;
        const maxW = inner - boxPad * 2 - (b.indent ? b.indent * scale : 0);
        const prefix = b.prefix ? String(b.prefix) : '';
        const lines = wrapText(prefix + String(b.text ?? ''), maxW, font, measure);
        const lh = size * LINE_HEIGHT;
        const textH = lines.length * lh;
        const top = y + gap;

        if (boxed) {
            cmds.push({ op: 'frame', x: PAD_X, y: top, w: inner, h: textH + boxPad * 2, line: Math.max(3, 4 * scale) });
        }
        lines.forEach((ln, i) => {
            const w = measure(ln, font);
            const x = b.align === 'center'
                ? (width - w) / 2
                : PAD_X + boxPad + (b.indent ? b.indent * scale : 0);
            cmds.push({ op: 'text', x, y: top + boxPad + i * lh, text: ln, font, color: '#000', size });
        });
        y = top + textH + boxPad * 2;
    });

    return { cmds, height: y + PAD_Y, clipped };
}

/**
 * @param {Array<object>} blocks
 * @param {{width:number, height?:number|null, measure:(text:string,font:string)=>number, minHeight?:number}} opts
 *        height null/absent = ruban continu (hauteur selon le contenu) ; sinon hauteur fixe.
 * @returns {{commands:Array<object>, width:number, height:number, overflow:boolean, scale:number}}
 */
export function layoutLabel(blocks, opts) {
    const { width, measure } = opts;
    const fixed = Number.isFinite(opts.height) && opts.height > 0 ? opts.height : null;

    if (!fixed) {
        const r = layoutOnce(blocks, width, measure, 1);
        const height = Math.max(opts.minHeight || MIN_LABEL_DOTS, Math.ceil(r.height));
        return { commands: r.cmds, width, height, overflow: r.clipped, scale: 1 };
    }

    for (const scale of SCALE_STEPS) {
        const r = layoutOnce(blocks, width, measure, scale);
        if (r.height <= fixed && !r.clipped) {
            return { commands: r.cmds, width, height: fixed, overflow: false, scale };
        }
    }
    const last = layoutOnce(blocks, width, measure, SCALE_STEPS[SCALE_STEPS.length - 1]);
    return { commands: last.cmds, width, height: fixed, overflow: true, scale: SCALE_STEPS[SCALE_STEPS.length - 1] };
}
