import { describe, it, expect } from 'vitest';
import {
    CAISSE_ZOOM,
    resolveCaisseZoom,
    applyCaisseZoom,
    clearCaisseZoom,
} from '../../resources/js/helpers/caisseZoom';

// Fake DOM document — le helper prend `doc` en paramètre pour rester testable
// sans jsdom (réplique l'API body.style.zoom + setAttribute/removeAttribute).
function fakeDoc() {
    const attrs = {};
    return {
        body: {
            style: {},
            setAttribute: (k, v) => { attrs[k] = String(v); },
            removeAttribute: (k) => { delete attrs[k]; },
            _attrs: attrs,
        },
    };
}
// Fake localStorage
function fakeStorage(map = {}) {
    return { getItem: (k) => (k in map ? map[k] : null) };
}

describe('caisseZoom', () => {
    it('CAISSE_ZOOM par défaut = 0.67 (valeur testée par l\'owner = Chrome 67%)', () => {
        expect(CAISSE_ZOOM).toBe(0.67);
    });

    it('applyCaisseZoom écrit body.style.zoom = "0.67" + l\'attribut data', () => {
        const d = fakeDoc();
        applyCaisseZoom(d);
        expect(d.body.style.zoom).toBe('0.67');
        expect(d.body._attrs['data-caisse-zoom']).toBe('0.67');
    });

    it('applyCaisseZoom accepte une valeur explicite', () => {
        const d = fakeDoc();
        applyCaisseZoom(d, 0.8);
        expect(d.body.style.zoom).toBe('0.8');
    });

    it('clearCaisseZoom remet à zéro (sortie de la caisse)', () => {
        const d = fakeDoc();
        applyCaisseZoom(d);
        clearCaisseZoom(d);
        expect(d.body.style.zoom).toBe('');
        expect(d.body._attrs['data-caisse-zoom']).toBeUndefined();
    });

    it('resolveCaisseZoom lit localStorage.caisse_zoom si valide (live-tuning sans redéploiement)', () => {
        expect(resolveCaisseZoom(fakeStorage({ caisse_zoom: '0.7' }))).toBe(0.7);
        expect(resolveCaisseZoom(fakeStorage({ caisse_zoom: '0.55' }))).toBe(0.55);
    });

    it('resolveCaisseZoom retombe sur le défaut si absent / invalide / hors borne', () => {
        expect(resolveCaisseZoom(fakeStorage())).toBe(CAISSE_ZOOM);
        expect(resolveCaisseZoom(fakeStorage({ caisse_zoom: 'abc' }))).toBe(CAISSE_ZOOM);
        expect(resolveCaisseZoom(fakeStorage({ caisse_zoom: '2' }))).toBe(CAISSE_ZOOM);   // >1 rejeté
        expect(resolveCaisseZoom(fakeStorage({ caisse_zoom: '0.1' }))).toBe(CAISSE_ZOOM); // <0.3 rejeté
        expect(resolveCaisseZoom(undefined)).toBe(CAISSE_ZOOM);
    });

    it('défensif : ne crash pas si doc/body absent', () => {
        expect(() => applyCaisseZoom(null)).not.toThrow();
        expect(() => applyCaisseZoom({})).not.toThrow();
        expect(() => clearCaisseZoom(undefined)).not.toThrow();
    });
});
