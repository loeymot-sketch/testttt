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
    it('CAISSE_ZOOM par défaut = 0.9 (16" lisible — Chrome 67% était trop petit/étroit)', () => {
        expect(CAISSE_ZOOM).toBe(0.9);
    });

    it('applyCaisseZoom écrit body.style.zoom = "0.9" + l\'attribut data', () => {
        const d = fakeDoc();
        applyCaisseZoom(d);
        expect(d.body.style.zoom).toBe('0.9');
        expect(d.body._attrs['data-caisse-zoom']).toBe('0.9');
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

    // [GOAL REMARQUES 2026-10-03 · R-005] « 2 cm de blanc en bas de la caisse ». Mesuré dans le navigateur :
    // 72 px à 720, 77 px à 768, 108 px à 1080, soit 10 % de la hauteur. Cause : `zoom: 0.9` sur le body +
    // des hauteurs en unités d'écran (`h-screen`, `100dvh`) qui ne suivent PAS le zoom. Le helper publie donc
    // le facteur dans `--caisse-zoom`, que le CSS divise pour redonner à ces boîtes toute la fenêtre.
    it('applyCaisseZoom publie le facteur dans --caisse-zoom, clearCaisseZoom le retire', () => {
        const props = {};
        const d = fakeDoc();
        d.body.style.setProperty = (k, v) => { props[k] = String(v); };
        d.body.style.removeProperty = (k) => { delete props[k]; };

        applyCaisseZoom(d, 0.9);
        expect(props['--caisse-zoom'], 'le CSS divise 100vh par ce facteur').toBe('0.9');

        applyCaisseZoom(d, 0.8);
        expect(props['--caisse-zoom'], 'le facteur suit un réglage localStorage').toBe('0.8');

        clearCaisseZoom(d);
        expect(props['--caisse-zoom'], 'sortie de la caisse : plus de compensation sur les autres pages').toBeUndefined();
    });

    it('défensif : un body sans style.setProperty ne fait pas planter le zoom', () => {
        const d = fakeDoc(); // style = {} : pas de setProperty
        expect(() => applyCaisseZoom(d, 0.9)).not.toThrow();
        expect(d.body.style.zoom).toBe('0.9');
        expect(() => clearCaisseZoom(d)).not.toThrow();
    });

    it('défensif : ne crash pas si doc/body absent', () => {
        expect(() => applyCaisseZoom(null)).not.toThrow();
        expect(() => applyCaisseZoom({})).not.toThrow();
        expect(() => clearCaisseZoom(undefined)).not.toThrow();
    });
});
