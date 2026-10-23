import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// [2026-10-23 · décision propriétaire] L'écran livreur montre la durée RÉELLE de la tournée ALLER-RETOUR.
// La page est un Blade avec son JS en ligne : on en extrait les deux fonctions et on les exécute telles quelles.
const SRC = readFileSync(resolve(__dirname, '../../resources/views/livreur.blade.php'), 'utf8');

function extraire(nom) {
    const debut = SRC.indexOf('function ' + nom);
    expect(debut, 'la fonction ' + nom + ' doit exister dans livreur.blade.php').toBeGreaterThan(-1);
    let i = SRC.indexOf('{', debut), niveau = 0;
    for (; i < SRC.length; i++) {
        if (SRC[i] === '{') niveau++;
        if (SRC[i] === '}' && --niveau === 0) break;
    }
    return SRC.slice(debut, i + 1);
}

const { dureeLabel, tourHtml } = new Function(
    extraire('hm') + extraire('dureeLabel') + extraire('tourHtml') + '; return { dureeLabel, tourHtml };'
)();

describe('écran livreur — tournée aller-retour', () => {
    it('formate les durées', () => {
        expect(dureeLabel(47)).toBe('47 min');
        expect(dureeLabel(65)).toBe('1 h 05');
        expect(dureeLabel(120)).toBe('2 h 00');
    });

    it('affiche durée, heure de retour, source et part du retour', () => {
        const h = tourHtml({ tour_minutes: 65, return_minutes: 15, back_at: '2026-10-22 00:35:00', source: 'road' });
        expect(h).toContain('data-testid="livreur-tour"');
        expect(h).toContain('aller-retour');
        expect(h).toContain('1 h 05');
        expect(h).toContain('vers 00:35');
        expect(h).toContain('Itinéraire routier réel');
        expect(h).toContain('15 min de retour');
    });

    it('une estimation se dit estimation ; sans durée, rien', () => {
        expect(tourHtml({ tour_minutes: 32, return_minutes: 13, back_at: '2026-10-23 01:02:00', source: 'estimate' })).toContain('Estimation (itinéraire indisponible)');
        expect(tourHtml(null)).toBe('');
    });

    it('la tournée est bien posée entre l\'en-tête et le bouton « Je pars »', () => {
        expect(SRC).toContain('return head + tourHtml(w.tour) + go +');
    });
});
