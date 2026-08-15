import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createBarcodeDetector } from '../../resources/js/helpers/posBarcode';

/**
 * [T-4.4 SCANNER-CAPTE-CHAMPS 2026-08-15 · GOAL_CONFORT_MAX] `createBarcodeDetector`
 * écoutait `keydown` en phase CAPTURE sur `window` SANS jamais regarder `event.target`
 * — contrairement à `createFKeyShortcuts` (même fichier) qui ignore déjà correctement
 * INPUT/TEXTAREA/contentEditable. Une saisie manuelle rapide (motif remise, nom
 * client…) ≥6 caractères + Entrée déclenchait une fausse détection de scan :
 * `preventDefault()` avalait l'Entrée du champ ET lançait une recherche produit sur
 * du texte tapé.
 */
function fireKeydown(target, key) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    Object.defineProperty(event, 'target', { value: target, configurable: true });
    window.dispatchEvent(event);
    return event;
}

describe('createBarcodeDetector — ignore les frappes dans un champ texte', () => {
    let onBarcode;
    let stop;

    beforeEach(() => {
        onBarcode = vi.fn();
        stop = createBarcodeDetector(onBarcode);
    });

    afterEach(() => {
        stop();
    });

    it('un INPUT focusé : la saisie rapide + Entrée n\'est PAS interprétée comme un scan', () => {
        const input = document.createElement('input');
        document.body.appendChild(input);
        try {
            for (const ch of 'ABC123') fireKeydown(input, ch);
            const enterEvent = fireKeydown(input, 'Enter');
            expect(onBarcode).not.toHaveBeenCalled();
            expect(enterEvent.defaultPrevented, 'l\'Entrée du champ doit rester utilisable par le champ lui-même').toBe(false);
        } finally {
            input.remove();
        }
    });

    it('un TEXTAREA focusé : même garde', () => {
        const textarea = document.createElement('textarea');
        document.body.appendChild(textarea);
        try {
            for (const ch of 'REMISE') fireKeydown(textarea, ch);
            fireKeydown(textarea, 'Enter');
            expect(onBarcode).not.toHaveBeenCalled();
        } finally {
            textarea.remove();
        }
    });

    it('un élément contentEditable focusé : même garde', () => {
        const div = document.createElement('div');
        div.contentEditable = 'true';
        document.body.appendChild(div);
        try {
            for (const ch of 'ABC123') fireKeydown(div, ch);
            fireKeydown(div, 'Enter');
            expect(onBarcode).not.toHaveBeenCalled();
        } finally {
            div.remove();
        }
    });

    it('hors champ texte (body) : un VRAI scan (frappe rapide ≥6 car. + Entrée) déclenche toujours onBarcode', () => {
        for (const ch of 'ABC123') fireKeydown(document.body, ch);
        fireKeydown(document.body, 'Enter');
        expect(onBarcode).toHaveBeenCalledWith('ABC123');
    });

    it('hors champ texte : moins de 6 caractères → pas de scan (comportement inchangé)', () => {
        for (const ch of 'AB') fireKeydown(document.body, ch);
        fireKeydown(document.body, 'Enter');
        expect(onBarcode).not.toHaveBeenCalled();
    });
});
