import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { mount, flushPromises } from '@vue/test-utils';
import SystemHealthComponent from '../../resources/js/components/admin/observability/SystemHealthComponent.vue';

vi.mock('axios', () => ({
    default: { get: vi.fn(), put: vi.fn() },
}));

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-A — moitié écran]
 *
 * La carte « Dernière sauvegarde » ne regardait QUE la date du fichier `.sql.gz`.
 * Une sauvegarde de 2 h totalement corrompue s'affichait donc en vert : le seul
 * signal qui prouve qu'une sauvegarde vaut quelque chose — la restauration de
 * vérification de 5 h — n'était pas lu par l'écran.
 *
 * Ce banc vérifie le COMPORTEMENT rendu (couleur + texte), pas la présence d'une
 * chaîne dans le source : un banc qui grep le fichier resterait vert si la
 * couleur retombait au vert par une autre voie.
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

function drill(status, extra = {}) {
    return {
        status,
        verified_at: new Date().toISOString(),
        age_hours: 3,
        file: 'daily-2026-09-02.sql.gz',
        sha256: 'a'.repeat(64),
        duration_s: 42.5,
        reasons: [],
        max_age_hours: 48,
        ...extra,
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

describe('Cockpit — la carte sauvegarde lit la restauration de vérification', () => {
    beforeEach(() => {
        vi.mocked(axios.get).mockReset();
        vi.mocked(axios.put).mockReset();
    });

    it('fichier frais ET restauration verte → carte verte', async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 2,
            attendu_max_h: 26,
            restauration: drill('green'),
        });
        expect(carte(w).html()).toContain('text-emerald-700');
        expect(carte(w).html()).not.toContain('text-red-700');
    });

    it('fichier frais MAIS restauration échouée → carte rouge et raison affichée', async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 2,
            attendu_max_h: 26,
            restauration: drill('failed', { reasons: ['audit_logs chain broken at seq 41'] }),
        });
        const html = carte(w).html();
        expect(html).toContain('text-red-700');
        expect(html).toContain('audit_logs chain broken at seq 41');
    });

    it('restauration jamais mesurée → carte non verte, et elle le DIT', async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 2,
            attendu_max_h: 26,
            restauration: {
                status: 'unknown',
                verified_at: null,
                age_hours: null,
                file: null,
                sha256: null,
                duration_s: null,
                reasons: [],
                max_age_hours: 48,
            },
        });
        const html = carte(w).html();
        expect(html).toContain('text-red-700');
        expect(html.toLowerCase()).toMatch(/jamais/);
    });

    it('restauration verte mais périmée → carte non verte', async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 2,
            attendu_max_h: 26,
            restauration: drill('stale', { age_hours: 121 }),
        });
        expect(carte(w).html()).toContain('text-red-700');
    });

    it('fichier périmé → carte rouge même si la restauration était verte', async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-08-28.sql.gz',
            age_heures: 110,
            attendu_max_h: 26,
            restauration: drill('green'),
        });
        expect(carte(w).html()).toContain('text-red-700');
    });

    it("la carte n'annonce plus que la restauration n'est pas lue", async () => {
        const w = await monter({
            dernier_fichier: 'daily-2026-09-02.sql.gz',
            age_heures: 2,
            attendu_max_h: 26,
            restauration: drill('green'),
        });
        expect(carte(w).text()).not.toMatch(/n'est pas encore lue/);
    });
});
