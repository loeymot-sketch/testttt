/**
 * POS barcode (HID keyboard wedge) + F-key helpers.
 *
 * Heuristic: scanners typically emit keydown events faster than human typing
 * (gap ≤ BARCODE_THRESHOLD_MS between keys). Very slow scanners may not be
 * detected — future: optional UI threshold config.
 */

const BARCODE_THRESHOLD_MS = 50;
const BARCODE_MIN_LENGTH = 6;
const ENTER_KEY = 'Enter';

export function createBarcodeDetector(onBarcode) {
    let buffer = '';
    let lastKeyAt = 0;

    function handler(event) {
        // [T-4.4 SCANNER-CAPTE-CHAMPS 2026-08-15 · GOAL_CONFORT_MAX] Enregistré en
        // phase CAPTURE (3e arg `true`) sur `window`, ce handler voyait CHAQUE frappe
        // AVANT tout champ texte — y compris pendant une saisie manuelle normale (motif
        // remise, nom client, recherche…). Une saisie rapide ≥6 caractères suivie
        // d'Entrée était interprétée comme un scan : `event.preventDefault()` avalait
        // l'Entrée du champ ET déclenchait une recherche produit sur du texte tapé, pas
        // un vrai code-barres. Même garde que `createFKeyShortcuts` juste en dessous
        // (déjà correcte pour les raccourcis F-key dans ce même fichier).
        const target = event.target;
        if (
            target
            && (target.tagName === 'INPUT'
                || target.tagName === 'TEXTAREA'
                || target.isContentEditable)
        ) {
            return;
        }

        const now = performance.now();
        const delta = now - lastKeyAt;
        lastKeyAt = now;

        if (event.key === ENTER_KEY) {
            if (buffer.length >= BARCODE_MIN_LENGTH) {
                const code = buffer;
                buffer = '';
                onBarcode(code);
                event.preventDefault();
            } else {
                buffer = '';
            }
            return;
        }

        if (event.key.length === 1 && /[\w-]/.test(event.key)) {
            if (delta > BARCODE_THRESHOLD_MS && buffer.length > 0) {
                buffer = '';
            }
            buffer += event.key;
        }
    }

    window.addEventListener('keydown', handler, true);
    return () => window.removeEventListener('keydown', handler, true);
}

/**
 * @param {(idx:number)=>void} onShortcut
 * @param {{ shouldIntercept?: () => boolean }} [options]
 *   - shouldIntercept: predicate returning false to disable F-keys (e.g. a
 *     drawer/modal is open). Default: always intercept (legacy behavior).
 *     [V14 C-α / FINDING C-5 P2] Allows the caller to neutralize F-keys when
 *     a drawer (parked orders, etc.) is open without unmounting the listener.
 */
export function createFKeyShortcuts(onShortcut, options = {}) {
    const shouldIntercept = typeof options.shouldIntercept === 'function'
        ? options.shouldIntercept
        : () => true;

    function handler(event) {
        if (shouldIntercept() === false) {
            return;
        }
        const target = event.target;
        if (
            target
            && (target.tagName === 'INPUT'
                || target.tagName === 'TEXTAREA'
                || target.isContentEditable)
        ) {
            return;
        }
        const m = /^F(\d{1,2})$/.exec(event.key);
        if (m) {
            const idx = parseInt(m[1], 10);
            if (idx >= 1 && idx <= 12) {
                event.preventDefault();
                onShortcut(idx);
            }
        }
    }

    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
}
