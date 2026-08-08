import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [P0 ARGENT 2026-08-08] La caisse détournait la commande vers une file HORS-LIGNE dont le rejeu
 * était structurellement impossible — elle prenait donc l'argent pour une commande qui n'existerait
 * jamais.
 *
 * Le contenu mis en file était `{...checkoutProps.form}`, donc avec ses valeurs par défaut :
 * `pos_received_amount` à null et AUCUN `quote_token`/`quote_signature`. Or ces deux jetons sont
 * forgés par le SERVEUR (`PaymentComponent.refreshQuote`), qui n'était jamais atteint puisque la
 * modale de paiement ne s'ouvrait pas. Au retour du réseau, le serveur refusait donc toujours :
 *   · `app/Http/Requests/PosOrderRequest.php` exige `pos_received_amount` en espèces → 422 ;
 *   · `app/Services/Order/OrderQuoteService.php` exige le couple jeton/signature sur la surface
 *     `pos` → 401.
 * L'entrée n'étant jamais purgée, le bandeau annonçait « retentée plus tard » pour quelque chose
 * qui ne réussirait jamais. Bilan d'un service hors-ligne : 0 commande, 0 numéro fiscal, 0 mouvement
 * de tiroir, 0 ticket cuisine — et l'argent dans le tiroir.
 *
 * Second motif, indépendant et suffisant : V1 est MONO-POSTE LOCAL. `navigator.onLine === false`
 * signale la perte du réseau EXTERNE, alors que le serveur tourne sur la même machine et reste
 * joignable en local — la dérivation pouvait donc se déclencher alors que la commande aurait
 * parfaitement abouti.
 *
 * Cette sentinelle est STATIQUE (elle lit le source) parce que c'est exactement ce qu'il faut
 * verrouiller : l'ABSENCE d'un appel. Monter le composant complet n'apporterait rien ici et
 * masquerait la propriété derrière un harnais fragile.
 */
const SOURCE = path.resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue');

describe('caisse — aucune fausse promesse hors-ligne', () => {
    const code = fs.readFileSync(SOURCE, 'utf8');

    it('ne met JAMAIS une commande en file d\'attente hors-ligne', () => {
        // On cherche un APPEL (`enqueueOrder(`), pas une simple mention : le nom subsiste
        // légitimement dans le câblage du composable et dans les commentaires d'explication.
        const appels = code.match(/enqueueOrder\s*\(/g) || [];

        expect(appels.length, 'un appel à enqueueOrder est revenu dans la caisse : le rejeu est '
            + 'impossible sans devis signé par le serveur, donc la commande serait encaissée puis '
            + 'perdue. Rebrancher la file exige d\'abord de faire signer le devis hors-ligne.')
            .toBe(0);
    });

    it('n\'annonce plus une synchronisation automatique qui n\'aura pas lieu', () => {
        expect(code).not.toContain('Synchronisation auto au retour réseau');
        expect(code).not.toMatch(/mise en file d'attente/);
    });

    it('avertit tout de même le caissier quand le réseau externe est absent', () => {
        // Garde anti-suppression-muette : retirer la dérivation sans rien dire laisserait le
        // caissier servir à l'aveugle. L'avertissement doit exister ET nommer le bon geste.
        expect(code).toMatch(/Réseau externe indisponible/);
        expect(code, 'l\'avertissement doit dire de vérifier AVANT de servir — c\'est tout son objet')
            .toMatch(/AVANT de servir/);
    });

    it('garde le déclencheur réseau non bloquant (avertir, jamais détourner)', () => {
        // La branche `navigator.onLine === false` doit subsister, mais ne plus contenir de `return`
        // qui court-circuiterait l'ouverture de la modale de paiement.
        const i = code.indexOf("navigator.onLine === false");
        expect(i, 'le signal réseau a disparu : le caissier ne serait plus averti du tout')
            .toBeGreaterThan(0);

        const bloc = code.slice(i, i + 400);
        expect(bloc, 'un `return` dans cette branche re-détournerait le paiement')
            .not.toMatch(/\breturn\b/);
    });
});
