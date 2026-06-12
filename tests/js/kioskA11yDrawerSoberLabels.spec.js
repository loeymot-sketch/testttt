/**
 * [dispute-r1 D-009 2026-06-12] — drawer a11y : « (FR/EN) » sur une borne
 * FR-locked (ADR-007) + jargon normatif « (EAA 2025) » / « (WCAG 2.3.3) »
 * exposé au client (d3-02).
 * -----------------------------------------------------------------------------
 * Les hints du drawer sont des libellés CLIENT : ils décrivent l'effet de
 * l'option, pas la norme qui la justifie. Invariant : aucun jargon
 * EAA/WCAG/FR-EN dans les hints kiosk.a11y.* (FR + EN).
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const fr = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf-8'));
const en = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf-8'));

const HINT_KEYS = [
    'contrast_aa_hint',
    'contrast_aaa_hint',
    'pmr_hint',
    'audio_hint',
    'audio_description_hint',
    'reduced_motion_hint',
];

describe('[D-009] hints drawer a11y sobres (sans jargon normatif)', () => {
    it.each(HINT_KEYS)('FR kiosk.a11y.%s : pas de WCAG/EAA/(FR/EN)', (key) => {
        const value = fr.kiosk.a11y[key] || '';
        expect(value).not.toMatch(/WCAG/i);
        expect(value).not.toMatch(/EAA/);
        expect(value).not.toMatch(/FR\/EN|EN\/FR/);
        expect(value.length).toBeGreaterThan(3);
    });

    it.each(HINT_KEYS)('EN kiosk.a11y.%s : parité sobre', (key) => {
        const value = en.kiosk.a11y[key] || '';
        expect(value).not.toMatch(/WCAG/i);
        expect(value).not.toMatch(/EAA/);
        expect(value).not.toMatch(/FR\/EN|EN\/FR/);
    });

    it('la lecture vocale ne promet plus une langue EN sur borne FR-locked', () => {
        expect(fr.kiosk.a11y.audio_hint).toBe('Lecture vocale des étapes');
    });
});
