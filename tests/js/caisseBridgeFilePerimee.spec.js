import { describe, it, expect } from 'vitest';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

/**
 * [FILE-PERIMEE 2026-10-20 · owner « il imprime tous les anciens tickets »] Le pont de la caisse
 * ne doit plus laisser le spouleur Windows empiler des tickets quand l'imprimante est hors ligne,
 * ni les rejouer tous à son retour. Le contrat (porte 503, purge des jobs périmés, fail-open) est
 * vérifié par tools/caisse-bridge/caisse-bridge.test.js (faux worker PowerShell, aucun matériel) ;
 * ce spec l'exécute dans la suite pour qu'une régression du pont casse le build.
 *
 * NON couvert ici (à vérifier sur le vrai PC caisse) : la requête CIM / Get-PrintJob de Windows.
 */
describe('pont caisse — file d\'impression périmée', () => {
    it('contrat du pont : porte hors ligne, purge des tickets périmés, porte ouverte au doute', () => {
        const script = path.resolve(__dirname, '../../tools/caisse-bridge/caisse-bridge.test.js');
        const out = execFileSync(process.execPath, [script], { encoding: 'utf8', timeout: 30000 });
        expect(out).toMatch(/10\/10 assertions/);
    });
});
