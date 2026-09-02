import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AuditTrailComponent from '../../resources/js/components/admin/dashboard/AuditTrailComponent.vue';

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.4 · Codex P1-I]
 *
 * Le widget affirmait : « Le préfixe de hash atteste l'intégrité de la chaîne ».
 * C'est FAUX, et c'est la pire sorte de faux : il rassure exactement là où il ne faut pas.
 *
 * Les huit premiers caractères d'une empreinte ne prouvent RIEN sur la chaîne. Ils ne
 * disent pas que la ligne précédente est intacte, ni qu'aucune ligne n'a été retirée, ni
 * que la signature se reproduit avec le secret de la branche. La seule chose qui atteste
 * l'intégrité, c'est de reparcourir la chaîne — `fiscal:verify-chain` /
 * `AuditLogService::verifyChain()`. Un contrôleur NF525 qui lirait cette phrase, puis
 * découvrirait qu'aucune vérification n'a lieu, aurait raison de tout remettre en cause.
 *
 * Et « il y a 3 heures » ne se recoupe avec rien : sans date exacte, impossible de
 * rapprocher une ligne d'audit d'un ticket ou d'un Z.
 */
const LIGNES = [
    {
        id: 41,
        user_name: 'Caissière Dupont',
        branch_id: 1,
        action: 'cash.movement.recorded',
        resource: 'cash_drawer#7',
        hash_prefix: 'a1b2c3d4',
        payload_keys: ['amount'],
        time: 'il y a 3 heures',
        created_at: '2026-09-02T14:05:11+02:00',
    },
];

function monter() {
    const store = { dispatch: vi.fn().mockResolvedValue({ data: { data: LIGNES } }) };

    return mount(AuditTrailComponent, {
        global: {
            mocks: { $store: store, $t: (k) => k },
        },
    });
}

describe('Widget audit NF525 — ce qu’il affirme doit être vrai', () => {
    it("n'affirme plus que le préfixe de hash atteste l'intégrité", async () => {
        const w = monter();
        await flushPromises();

        const texte = w.text();
        expect(texte).not.toMatch(/atteste l'intégrité/i);
        expect(texte).not.toMatch(/atteste l’intégrité/i);
    });

    it("dit où l'intégrité est réellement vérifiée", async () => {
        const w = monter();
        await flushPromises();

        expect(w.text()).toMatch(/verify-chain/i);
    });

    it('affiche la date exacte à côté de la formulation relative', async () => {
        const w = monter();
        await flushPromises();

        const html = w.html();
        expect(html).toContain('2026-09-02');
        expect(w.text()).toContain('il y a 3 heures');
    });

    it('montre la branche de chaque ligne', async () => {
        const w = monter();
        await flushPromises();

        expect(w.find('[data-testid="audit-trail-branche-41"]').exists()).toBe(true);
    });
});
