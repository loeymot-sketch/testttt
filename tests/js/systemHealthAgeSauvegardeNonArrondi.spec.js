import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { mount, flushPromises } from '@vue/test-utils';
import SystemHealthComponent from '../../resources/js/components/admin/observability/SystemHealthComponent.vue';

vi.mock('axios', () => ({
    default: { get: vi.fn(), put: vi.fn() },
}));

/**
 * [GOAL G4 2026-09-03 · T4.4 · défaut N-01 — moitié écran]
 *
 * Le serveur comparait l'âge de la sauvegarde en décimal (`> 26`) pour sa bande
 * d'alertes, mais PUBLIAIT la valeur arrondie à l'heure. L'écran, lui, refaisait le
 * calcul sur la valeur publiée : `26 <= 26` est vrai, donc carte VERTE. Résultat, entre
 * 26 h 01 et 26 h 29 — 29 minutes par jour — la carte « Dernière sauvegarde » affichait
 * un vert que la bande d'alertes du MÊME écran contredisait.
 *
 * Un écran qui se contredit cesse d'être consulté ; c'est ainsi qu'on perd une
 * sauvegarde sans que personne ne l'ait vu venir.
 *
 * La règle posée ici : le serveur décide, l'écran affiche. La carte ne conclut plus au
 * vert par un calcul à elle ; elle lit le verdict `sauvegarde.fraiche`. Un verdict absent
 * n'est pas un verdict favorable — la carte reste rouge.
 */
function etatSante(sauvegarde) {
    return {
        verdict: 'ok',
        alertes: [],
        controles: { db: 'ok', redis: 'ok', websocket: 'ok', fiscal_chain: 'ok', queue_pending: 3 },
        mesure_le: new Date().toISOString(),
        mesure_age_min: 1,
        mesure_horodatage_invalide: false,
        mesure_attendu_max_min: 30,
        sauvegarde,
        planificateur: { dernier_battement_min: 1, attendu_max_min: 10 },
    };
}

function drillVert() {
    return {
        status: 'green',
        verified_at: new Date().toISOString(),
        age_hours: 3,
        file: 'daily-2026-09-02.sql.gz',
        sha256: 'a'.repeat(64),
        duration_s: 42.5,
        reasons: [],
        max_age_hours: 26,
    };
}

async function monter(sauvegarde) {
    vi.mocked(axios.get).mockImplementation((url) => {
        if (String(url).includes('system-health')) {
            return Promise.resolve({ data: etatSante(sauvegarde) });
        }
        return Promise.resolve({ data: { data: [] } });
    });
    const wrapper = mount(SystemHealthComponent);
    await flushPromises();
    return wrapper;
}

function carte(wrapper) {
    return wrapper.get('[data-testid="system-health-sauvegarde"]');
}

/**
 * La carte porte DEUX lignes colorées : l'âge du fichier et le verdict de restauration.
 * Viser la carte entière ferait passer un banc sur la mauvaise ligne — c'est la ligne
 * d'âge qui porte le défaut N-01. Cette classe n'appartient qu'à elle.
 */
function ligneAge(wrapper) {
    return carte(wrapper).html().match(/text-lg font-semibold (text-\w+-700)/)[1];
}

describe("Cockpit — l'âge de sauvegarde n'est plus arrondi en faveur du vert", () => {
    beforeEach(() => {
        vi.mocked(axios.get).mockReset();
        vi.mocked(axios.put).mockReset();
    });

    it('26 h 20 publié comme « 26 » (ancienne forme, arrondie) ne doit pas faire une carte verte', async () => {
        // Exactement ce que le serveur envoyait : (int) round(26.33) === 26.
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 26,
            attendu_max_h: 26,
            restauration: drillVert(),
        });

        expect(ligneAge(w)).toBe('text-red-700');
    });

    it('26 h 20 publié en décimal avec le verdict serveur → carte rouge', async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 26.33,
            attendu_max_h: 26,
            fraiche: false,
            restauration: drillVert(),
        });

        expect(ligneAge(w)).toBe('text-red-700');
    });

    it("l'âge décimal reste lisible : la carte affiche « il y a 26 h », pas « il y a 26.33 h »", async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 26.33,
            attendu_max_h: 26,
            fraiche: false,
            restauration: drillVert(),
        });

        const texte = carte(w).text();
        expect(texte).toContain('il y a 26 h');
        expect(texte).not.toContain('26.33');
    });

    it('sauvegarde fraîche attestée par le serveur → carte verte (garde-fou anti-sur-correction)', async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 2.4,
            attendu_max_h: 26,
            fraiche: true,
            restauration: drillVert(),
        });

        expect(ligneAge(w)).toBe('text-emerald-700');
    });

    it("un verdict de fraîcheur absent n'est pas un verdict favorable", async () => {
        // Vieille réponse en cache, déploiement partiel, mise en cache d'un ancien
        // bundle : si la clé manque, la carte doit rester rouge, jamais verte par défaut.
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 2,
            attendu_max_h: 26,
            restauration: drillVert(),
        });

        expect(ligneAge(w)).toBe('text-red-700');
    });
});
