/**
 * [ETIQUETTES 2026-10-16] Le bandeau d'état de l'imprimante dit la VÉRITÉ : prête seulement quand elle l'est,
 * sinon pourquoi (non connectée, capot, mauvais rouleau) — et jamais « prête » devant une panne.
 */
import { describe, it, expect } from 'vitest';
import { describePrinter, chromeMajor } from '../../resources/js/helpers/labelPrinterMixin.js';
import { parseStatus, STATUS_LENGTH } from '../../resources/js/helpers/brotherQl.js';
import { DEFAULT_SETTINGS } from '../../resources/js/helpers/labelPrinter.js';

function status({ err1 = 0, err2 = 0, width = 62, type = 0x4a, length = 0 } = {}) {
    const f = new Uint8Array(STATUS_LENGTH);
    f.set([0x80, 0x20, 0x42, 0x34, 0x41, 0x30, 0x30, 0x00, err1, err2, width, type, 0, 0, 0x3f, 0, 0, length, 0, 0]);
    return parseStatus(f);
}
const st = (over) => ({ status: 'connected', error: null, printer: null, ...over });

describe('describePrinter', () => {
    it('navigateur non compatible : erreur, pas prête, avec la marche à suivre', () => {
        const d = describePrinter(st({ status: 'unsupported' }), DEFAULT_SETTINGS, false);
        expect(d.level).toBe('error');
        expect(d.ready).toBe(false);
        expect(d.detail).toMatch(/Chrome.*138/);
    });

    it('non connectée : avertissement, pas prête ; l’erreur du port est reprise telle quelle', () => {
        const d = describePrinter(st({ status: 'disconnected', error: 'Imprimante injoignable : …' }), DEFAULT_SETTINGS, true);
        expect(d.level).toBe('warn');
        expect(d.ready).toBe(false);
        expect(d.detail).toBe('Imprimante injoignable : …');
        expect(describePrinter(st({ status: 'disconnected' }), DEFAULT_SETTINGS, true).detail).toMatch(/Connecter/);
    });

    it('connexion et impression en cours : information, pas prête', () => {
        expect(describePrinter(st({ status: 'connecting' }), DEFAULT_SETTINGS, true).ready).toBe(false);
        const busy = describePrinter(st({ status: 'busy' }), DEFAULT_SETTINGS, true);
        expect(busy.level).toBe('info');
        expect(busy.ready).toBe(false);
    });

    it('connectée sans état lu : utilisable (l’impression refait son propre contrôle)', () => {
        const d = describePrinter(st({}), DEFAULT_SETTINGS, true);
        expect(d.ready).toBe(true);
        expect(d.level).toBe('info');
    });

    it('prête : modèle et rouleau affichés', () => {
        const d = describePrinter(st({ printer: status() }), DEFAULT_SETTINGS, true);
        expect(d).toMatchObject({ level: 'ok', ready: true, title: 'Imprimante prête', detail: 'QL-820NWB · 62 mm continu' });
    });

    it('capot ouvert : en défaut, jamais « prête »', () => {
        const d = describePrinter(st({ printer: status({ err2: 0x10 }) }), DEFAULT_SETTINGS, true);
        expect(d.level).toBe('error');
        expect(d.ready).toBe(false);
        expect(d.detail).toMatch(/Capot ouvert/);
    });

    it('« imprimante occupée » seule n’est pas une panne', () => {
        expect(describePrinter(st({ printer: status({ err1: 0x10 }) }), DEFAULT_SETTINGS, true).ready).toBe(true);
    });

    it('mauvais rouleau : le message dit le rouleau chargé et le format réglé', () => {
        const d = describePrinter(st({ printer: status({ width: 29, type: 0x4b, length: 90 }) }), DEFAULT_SETTINGS, true);
        expect(d.level).toBe('error');
        expect(d.title).toBe('Mauvais rouleau');
        expect(d.detail).toMatch(/29 × 90 mm.*62 mm continu/);
        // réglé sur le bon rouleau : prête
        const ok = describePrinter(st({ printer: status({ width: 29, type: 0x4b, length: 90 }) }), { ...DEFAULT_SETTINGS, mediaId: 'd29x90' }, true);
        expect(ok.ready).toBe(true);
    });
});

describe('chromeMajor', () => {
    it('lit la version de Chrome, ignore le reste', () => {
        expect(chromeMajor('Mozilla/5.0 (Linux; Android 14; SM-X810) AppleWebKit/537.36 Chrome/142.0.0.0 Safari/537.36')).toBe(142);
        expect(chromeMajor('Mozilla/5.0 (iPhone) CriOS/130.0.1 Mobile')).toBe(130);
        expect(chromeMajor('Mozilla/5.0 Firefox/130.0')).toBeNull();
        expect(chromeMajor(undefined)).toBeNull();
    });
});
