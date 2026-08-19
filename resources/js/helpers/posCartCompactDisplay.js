/**
 * [T-PANIER-COMPACT 2026-08-19 · GOAL owner] Rendu compact de la composition
 * d'une ligne du panier CAISSE.
 *
 * POURQUOI
 * --------
 * `cart_display` est produit par `buildCartDisplay()` dans
 * `public/js/pos-wizard.js` (FROZEN §7) sous la forme « Groupe: valeurs », une
 * paire par ligne, rendue avec `white-space: pre-line` — donc une ligne d'écran
 * chacune. Mesuré en direct : un sandwich menu occupait 196 px dans un corps de
 * panier haut de 40 px. Le propriétaire lit son panier au travers d'un hublot.
 *
 * Ce module ne touche PAS au wizard gelé : il re-formate la chaîne déjà produite,
 * côté Vue, au moment du rendu.
 *
 * RÈGLE DE SÛRETÉ
 * ---------------
 * Compacter ne doit jamais faire disparaître une information qui change le plat :
 *   - un RETRAIT (« Sans oignon ») est conservé et mis en capitales ;
 *   - un groupe inconnu conserve sa valeur intégrale plutôt que d'être ignoré ;
 *   - seule la tautologie stricte (« Pain: Pain ») est supprimée.
 * Les symboles de crudités réutilisent le moteur du KDS (`kdsSymbolic.js`), déjà
 * en production côté cuisine, plutôt qu'une seconde table qui pourrait diverger.
 */

import { cruditeSymbol } from './kdsSymbolic.js';

/** Ordre d'impression canonique des crudités — jumeau de kdsSymbolic.CRUDITE_ORDER. */
const CRUDITE_ORDER = ['S', 'T', 'O', 'O̲'];

/** Accepte un tableau, un objet indexé, ou rien. */
function toArray(value) {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object') return Object.values(value);
    return [];
}

function normalizeGroup(label) {
    return String(label || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

/**
 * Replie une liste de crudités en symboles ordonnés : « Salade, Tomate, Oignon » → « STO ».
 * Les refus (« Sans oignons ») sont écartés par la garde de négation de `cruditeSymbol`.
 */
function foldCrudites(rawValue) {
    const symbols = String(rawValue || '')
        .split(',')
        .map((part) => cruditeSymbol(part.trim()))
        .filter(Boolean);

    if (symbols.length === 0) return '';

    const unique = [];
    CRUDITE_ORDER.forEach((sym) => {
        if (symbols.includes(sym)) unique.push(sym);
    });
    // Symbole hors table canonique : conservé en fin, jamais perdu.
    symbols.forEach((sym) => {
        if (!unique.includes(sym)) unique.push(sym);
    });

    return unique.join('');
}

/**
 * Transforme le `cart_display` verbeux en segments courts prêts à être joints.
 *
 * @param {string|null|undefined} cartDisplay
 * @returns {string[]}
 */
export function compactCompositionSegments(cartDisplay) {
    const text = typeof cartDisplay === 'string' ? cartDisplay : '';
    const segments = [];

    text.split('\n').forEach((rawLine) => {
        const line = rawLine.trim();
        if (!line) return;

        const separator = line.indexOf(':');
        if (separator === -1) {
            // Ligne sans groupe : information brute, conservée telle quelle.
            segments.push(line);
            return;
        }

        const group = normalizeGroup(line.slice(0, separator));
        const value = line.slice(separator + 1).trim();
        if (!value) return;

        // RETRAIT — toujours visible, en capitales : c'est ce qui change le plat.
        if (/^sans\b/.test(group)) {
            segments.push(`SANS ${value.toUpperCase()}`);
            return;
        }

        if (/crudite/.test(group)) {
            const folded = foldCrudites(value);
            if (folded) segments.push(folded);
            return;
        }

        if (/supplement|extra/.test(group)) {
            segments.push(`+${value}`);
            return;
        }

        // Tautologie stricte (« Pain: Pain ») : le groupe n'apporte rien, la valeur
        // non plus. Un « Pain: Galette » reste évidemment affiché.
        if (normalizeGroup(value) === group) return;

        segments.push(value);
    });

    return segments;
}

/**
 * Allège le nom d'une formule : « Menu (Frites + Boisson) » → « Menu ».
 *
 * Le propriétaire : « puis au menu, puis la sauce pour les frites » — le contenu
 * de la formule est déjà détaillé juste en dessous (« Frites : Mayonnaise ·
 * Coca-Cola 33cl »), la parenthèse ne fait que le répéter et provoque un retour
 * à la ligne dans une colonne étroite.
 * Seule une parenthèse FINALE est retirée : « Menu enfant » reste intact.
 *
 * @param {string|null|undefined} name
 * @returns {string}
 */
export function compactBundledName(name) {
    const text = String(name || '').trim();
    const stripped = text.replace(/\s*\([^()]*\)\s*$/, '').trim();
    // Si la parenthèse portait TOUT le nom, on garde l'original plutôt que rien.
    return stripped || text;
}

/**
 * Extrait le nom de la boisson depuis l'`instruction` de la ligne parente.
 *
 * Le wizard écrit « BOISSON: <nom> » (pos-wizard.js, ~l.3975) mais ne l'ajoute PAS
 * à `menu_extras` — la boisson était donc invisible au panier. Vérifié en direct
 * le 2026-08-19 : Coca-Cola 33cl choisi, absent de l'affichage caisse.
 *
 * @param {string|null|undefined} instruction
 * @returns {string}
 */
export function drinkFromInstruction(instruction) {
    const text = typeof instruction === 'string' ? instruction : '';
    const match = text.match(/^\s*BOISSON\s*:\s*(.+?)\s*$/mi);
    return match ? match[1].trim() : '';
}

/**
 * Rend la liste compacte des extras d'une formule, boisson comprise.
 *
 * « Sauce frites: Mayonnaise » → « Frites : Mayonnaise », puis la boisson.
 *
 * @param {object|null|undefined} bundled            Entrée de `pos_line_addons`.
 * @param {string|null|undefined} parentInstruction  `instruction` de la ligne parente.
 * @returns {string[]}
 */
export function compactBundledExtras(bundled, parentInstruction) {
    const extras = toArray(bundled && bundled.menu_extras)
        .map((entry) => String(entry || '').trim())
        .filter(Boolean)
        .map((entry) => entry.replace(/^sauce\s+frites\s*:\s*/i, 'Frites : '));

    const drink = drinkFromInstruction(parentInstruction);
    if (drink) {
        const alreadyListed = extras.some(
            (entry) => normalizeGroup(entry) === normalizeGroup(drink)
        );
        if (!alreadyListed) extras.push(drink);
    }

    return extras;
}

export default {
    compactCompositionSegments,
    compactBundledExtras,
    compactBundledName,
    drinkFromInstruction,
};
