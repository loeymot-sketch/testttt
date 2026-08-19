/**
 * [T-DOUBLON 2026-08-19 · GOAL owner] Extraction de la NOTE LIBRE du caissier
 * depuis l'instruction complète d'une ligne panier caisse.
 *
 * POURQUOI CE FICHIER EXISTE
 * --------------------------
 * `public/js/pos-wizard.js` (FROZEN, CLAUDE.md §7) compose l'instruction d'une
 * ligne ainsi (`buildTicketInstruction`, ~l.3984-3994) :
 *
 *     NOM DU PRODUIT EN MAJUSCULES
 *     Pain: … Viandes : … - Salade, Tomate Sauce : …
 *     + Menu (Frites + Boisson) (+2,50 €)
 *     ↳ Sauce frites: Mayonnaise
 *     BOISSON: Coca-Cola 33cl
 *     [note libre du caissier]          ← seule ligne non régénérable
 *
 * Tout sauf la dernière ligne est REGÉNÉRÉ par le wizard depuis ses `selections`.
 * Lui renvoyer l'instruction complète à l'édition le faisait donc ré-emballer
 * l'ensemble entre crochets (`pos-wizard.js:3981`), d'où le doublon imprimé sur
 * le ticket cuisine et affiché sur le KDS — les deux assainisseurs préservant
 * volontairement les blocs `[...]` verbatim (garantie FOOD-SAFETY allergènes).
 *
 * Ce module ne rend donc au wizard que ce qu'il ne sait PAS reconstruire.
 */

/** Nombre maximal de déballages — borne de sûreté contre les lignes corrompues. */
const MAX_UNWRAP_DEPTH = 12;

/**
 * Déballe le dernier bloc `[...]` de `text` en tenant compte de l'imbrication.
 * Retourne `null` s'il n'y a pas de bloc équilibré.
 *
 * @param {string} text
 * @returns {string|null}
 */
function unwrapLastBracketBlock(text) {
    const close = text.lastIndexOf(']');
    if (close === -1) {
        return null;
    }

    // Balayage arrière avec compteur de profondeur : on cherche le crochet
    // ouvrant qui APPARIE ce `]` final, pas simplement le plus proche — sinon
    // une ligne déjà corrompue (`[A [note]]`) rendrait « note] ».
    let depth = 0;
    for (let i = close; i >= 0; i -= 1) {
        const char = text[i];
        if (char === ']') {
            depth += 1;
        } else if (char === '[') {
            depth -= 1;
            if (depth === 0) {
                return text.slice(i + 1, close).trim();
            }
        }
    }

    return null;
}

/**
 * Rend la note libre saisie par le caissier, débarrassée de toute composition.
 *
 * Règle de discrimination : le wizard écrit TOUJOURS le nom du produit en
 * majuscules sur la première ligne. Une instruction qui ne commence pas par ce
 * nom n'a pas été produite par le wizard (produit sans wizard, note tapée
 * directement dans la modale) : elle est rendue intacte.
 *
 * @param {string|null|undefined} instruction Instruction complète de la ligne panier.
 * @param {string|null|undefined} productName Nom catalogue du produit de la ligne.
 * @returns {string} La note libre, ou '' s'il n'y en a pas.
 */
export function extractCashierNote(instruction, productName) {
    let text = typeof instruction === 'string' ? instruction.trim() : '';
    const expectedHeader = String(productName || '').trim().toUpperCase();

    if (!expectedHeader) {
        // Sans nom de produit on ne peut pas distinguer compo et note :
        // on préfère ne rien perdre (le doublon reste préférable à l'oubli
        // d'une consigne client, ex. allergie).
        return text;
    }

    for (let depth = 0; depth < MAX_UNWRAP_DEPTH; depth += 1) {
        if (!text) {
            return '';
        }

        const firstLine = text.split('\n', 1)[0].trim().toUpperCase();
        if (firstLine !== expectedHeader) {
            // Ce n'est pas (ou plus) un blob wizard → c'est la vraie note.
            return text;
        }

        // Blob wizard : la seule chose récupérable est le dernier bloc crocheté.
        const inner = unwrapLastBracketBlock(text);
        if (inner === null) {
            // Composition pure, sans note. Rien à restaurer.
            return '';
        }
        text = inner;
    }

    return '';
}

export default { extractCashierNote };
