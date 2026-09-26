import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { mount, flushPromises } from '@vue/test-utils';
import SystemHealthComponent from '../../resources/js/components/admin/observability/SystemHealthComponent.vue';

vi.mock('axios', () => ({
    default: { get: vi.fn(), put: vi.fn() },
}));

/**
 * [GOAL G3 2026-09-03 · T3.4 · défaut V-15]
 *
 * Le bloc « Interrupteurs » annonçait : « Consigne dans le journal serveur, pas le
 * journal fiscal NF525. »
 *
 * C'était faux dans les deux sens. La bascule n'écrit pas dans « le journal serveur » —
 * un fichier texte rotaté, tronquable, purgeable par la personne même qui a basculé :
 * elle écrit dans `audit_logs`, signé en chaîne HMAC, append-only, dont la suppression
 * est refusée par un déclencheur SQL. Annoncer une trace plus faible qu'elle ne l'est,
 * c'est en faire douter le jour où on en a besoin — et c'est ce jour-là qu'elle sert.
 *
 * Mais il ne faut pas non plus sur-vendre : une bascule d'interrupteur n'est pas une
 * écriture fiscale au sens du ticket Z. L'écran doit dire exactement ce qui est écrit,
 * et où.
 *
 * Ce banc verrouille les deux bords : ni « journal serveur », ni « journal fiscal ».
 */
function etatSante() {
    return {
        verdict: 'ok',
        alertes: [],
        controles: { db: 'ok', redis: 'ok', websocket: 'ok', fiscal_chain: 'ok', queue_pending: 0 },
        mesure_le: new Date().toISOString(),
        mesure_age_min: 1,
        mesure_horodatage_invalide: false,
        mesure_attendu_max_min: 30,
        sauvegarde: {
            dernier_fichier: 'daily-2026-09-03.sql.gz',
            age_heures: 3,
            attendu_max_h: 26,
            fraiche: true,
            restauration: {
                status: 'green',
                verified_at: new Date().toISOString(),
                age_hours: 3,
                file: 'daily-2026-09-03.sql.gz',
                sha256: 'a'.repeat(64),
                duration_s: 12.5,
                reasons: [],
                max_age_hours: 26,
            },
        },
        planificateur: { dernier_battement_min: 1, attendu_max_min: 10 },
    };
}

async function monter() {
    vi.mocked(axios.get).mockImplementation((url) => {
        if (String(url).includes('system-health')) {
            return Promise.resolve({ data: etatSante() });
        }
        return Promise.resolve({ data: { data: [] } });
    });
    const wrapper = mount(SystemHealthComponent);
    await flushPromises();
    return wrapper;
}

/** Le texte du bloc Interrupteurs, apostrophes normalisées pour ne pas tester la typographie. */
function texteInterrupteurs(wrapper) {
    return wrapper
        .get('[data-testid="system-interrupteurs"]')
        .text()
        .replace(/[’‘]/g, "'")
        .replace(/\s+/g, ' ');
}

describe("Cockpit — le bloc Interrupteurs nomme le journal où la bascule est réellement écrite", () => {
    beforeEach(() => {
        vi.mocked(axios.get).mockReset();
        vi.mocked(axios.put).mockReset();
    });

    it('nomme le journal d\'audit et la table audit_logs', async () => {
        const texte = texteInterrupteurs(await monter());

        expect(texte).toMatch(/journal d'audit/i);
        expect(texte).toContain('audit_logs');
    });

    it('ne sous-vend plus la trace en la disant « journal serveur »', async () => {
        const texte = texteInterrupteurs(await monter());

        expect(texte).not.toMatch(/journal serveur/i);
        expect(texte).not.toMatch(/journal applicatif/i);
    });

    it('ne sur-vend pas : la mention NF525 reste une NÉGATION, jamais une revendication', async () => {
        const texte = texteInterrupteurs(await monter());

        // La seule mention acceptable de NF525 ici est celle qui dit que ça n'en est pas.
        expect(texte).toMatch(/n'est pas une écriture fiscale NF525/i);
        // Et jamais la revendication inverse.
        expect(texte).not.toMatch(/journal fiscal/i);
    });

    it("dit ce qui rend la trace opposable — chaînée et non modifiable", async () => {
        const texte = texteInterrupteurs(await monter());

        expect(texte).toMatch(/signé en chaîne/i);
        expect(texte).toMatch(/non modifiable/i);
    });
});
