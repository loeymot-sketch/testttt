import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-07 2026-08-28] Le filtre « Rôle » du registre des avoirs ne filtrait rien.
 *
 * `CreditBalanceReportController::customerScoped()` écrase `role_id` par le rôle
 * Client, SANS CONDITION, sur les deux voies — écran et export. Le cantonnement est
 * justifié et documenté depuis le 2026-06-01 : un membre du personnel ne détient pas
 * d'avoir client, et les lister gonfle le registre pour rien.
 *
 * C'est donc le contrôle à l'écran qui mentait. Le commerçant choisissait « Chef »,
 * cliquait Rechercher, et obtenait exactement la même liste — sans un mot.
 *
 * Un contrôle inopérant est pire qu'un contrôle absent : il fait douter le commerçant
 * de sa propre lecture. On retire le contrôle, on garde la règle, et on écrit à
 * l'écran POURQUOI la liste est ce qu'elle est.
 */
describe('ONB-07 · registre des avoirs', () => {
    const composant = path.join(
        process.cwd(),
        'resources/js/components/admin/creditBalanceReport/CreditBalanceReportComponent.vue',
    );

    it('le sélecteur de rôle a disparu de l\'écran', () => {
        const source = fs.readFileSync(composant, 'utf8');

        expect(
            source.includes('v-model="props.search.role_id"'),
            'le sélecteur de rôle est revenu : il ne filtre pourtant rien',
        ).toBe(false);
    });

    it("l'écran explique pourquoi la liste ne contient que des clients", () => {
        const source = fs.readFileSync(composant, 'utf8');

        expect(source).toContain('message.credit_balance_customers_only');
    });

    it('la clé existe en français ET en anglais', () => {
        for (const langue of ['fr', 'en']) {
            const json = JSON.parse(
                fs.readFileSync(
                    path.join(process.cwd(), `resources/js/languages/${langue}.json`),
                    'utf8',
                ),
            );

            expect(
                json.message?.credit_balance_customers_only,
                `${langue}.json : la clé manque — l'écran afficherait la clé brute`,
            ).toBeTruthy();
        }
    });

    it('le cantonnement serveur reste en place', () => {
        // Contrôle négatif : on a retiré le CONTRÔLE, pas la RÈGLE. Si quelqu'un
        // retirait aussi le cantonnement, le registre listerait à nouveau le
        // personnel — et ce banc doit le dire.
        const controleur = fs.readFileSync(
            path.join(
                process.cwd(),
                'app/Http/Controllers/Admin/CreditBalanceReportController.php',
            ),
            'utf8',
        );

        expect(controleur).toContain('customerScoped');
        expect(controleur).toContain("'role_id' => $customerRoleId");
    });
});
