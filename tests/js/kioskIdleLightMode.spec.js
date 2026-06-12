/**
 * [dispute-r1 ADV-F-P1-3 2026-06-12] — idle borne à dominante SOMBRE vs mandat
 * owner « kiosk light mode 100% » (DESIGN_SYSTEM_POLICY_2026-06-10.md §1 +
 * CLAUDE.md §3bis).
 * -----------------------------------------------------------------------------
 * Round-1 adversarial (F-design-vision, F-01-borne-idle.png) : fond brun/noir
 * dominant (.kiosk-idle-fallback linear-gradient #1A1410→#0E0A07) + ellipse
 * floue centrale (scrim A-001) + micro-texte crème — le PREMIER écran client
 * contredisait littéralement la règle écrite.
 *
 * Invariants verrouillés (variante SANS vidéo = défaut V1 Le Cayenne) :
 *  1. Fallback = gradient CLAIR (blanc → pêche, accents brand doux) — plus
 *     aucun stop sombre #1A1410/#0E0A07 dans la règle par défaut.
 *  2. Overlay sombre et scrim ::before gatés .kiosk-idle--has-video UNIQUEMENT.
 *  3. Texte par défaut = encre sombre ; le crème #FFF5E8 ne survit que sous
 *     .kiosk-idle--has-video (lisibilité sur vidéo).
 *  4. Le root binde la classe kiosk-idle--has-video sur videoSrc.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const src = readFileSync(
    resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue'),
    'utf-8',
);

// Extrait le corps d'une règle CSS (premier bloc qui suit le sélecteur exact).
function ruleBody(selector) {
    const idx = src.indexOf(selector + ' {');
    expect(idx, `sélecteur introuvable: ${selector}`).toBeGreaterThan(-1);
    const start = src.indexOf('{', idx);
    const end = src.indexOf('}', start);
    return src.slice(start + 1, end);
}

describe('[ADV-F-P1-3] idle light-mode par défaut', () => {
    it('le root binde kiosk-idle--has-video sur videoSrc', () => {
        expect(src).toMatch(/'kiosk-idle--has-video':\s*!!videoSrc/);
    });

    it('fallback sans vidéo = gradient clair (plus de brun/noir)', () => {
        const body = ruleBody('.kiosk-idle-fallback');
        expect(body).not.toMatch(/#1A1410/i);
        expect(body).not.toMatch(/#0E0A07/i);
        expect(body).toMatch(/#FFFFFF/i);
    });

    it('overlay sombre gaté has-video uniquement (défaut = none)', () => {
        const base = ruleBody('.kiosk-idle-overlay');
        expect(base).toMatch(/background:\s*none/);
        const video = ruleBody('.kiosk-idle--has-video .kiosk-idle-overlay');
        expect(video).toMatch(/rgba\(26,\s*20,\s*16/);
    });

    it('scrim ::before (ellipse sombre A-001) gaté has-video uniquement', () => {
        expect(src).toMatch(/\.kiosk-idle--has-video \.kiosk-idle-content::before/);
        // Aucune règle ::before non-gatée ne doit poser le scrim sombre.
        expect(src).not.toMatch(/^\.kiosk-idle-content::before/m);
    });

    it('texte par défaut = encre sombre ; crème réservé à la variante vidéo', () => {
        expect(ruleBody('.kiosk-idle--bold')).toMatch(/var\(--kiosk-text,\s*#1A1410\)/);
        expect(ruleBody('.kiosk-idle-title')).not.toMatch(/#FFF5E8/);
        expect(ruleBody('.kiosk-idle-brand')).not.toMatch(/#FFF5E8/);
        expect(ruleBody('.kiosk-idle--has-video .kiosk-idle-title')).toMatch(/#FFF5E8/);
        expect(ruleBody('.kiosk-idle--has-video .kiosk-idle-brand')).toMatch(/#FFF5E8/);
    });
});
