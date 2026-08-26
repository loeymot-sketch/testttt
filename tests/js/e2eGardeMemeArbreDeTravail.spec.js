import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — suite du P0 du 2026-08-25]
 *
 * CE QUI A ÉTÉ CONSTATÉ
 * ---------------------
 * `playwright.config.js:12` vise `http://localhost:8000` par défaut. Sur cette machine, ce port
 * servait `.claude/worktrees/goal-caisse-vision-2026-08-24/public` — un AUTRE worktree, à
 * **89 fichiers et 15 356 lignes d'écart** de l'arbre principal.
 *
 * Preuve différentielle : `/api/healthz` renvoyait `queue_pending = 0` sur `:8000` et
 * `1490` sur `:8766`. Même URL, deux codes.
 *
 * POURQUOI C'EST LE PIRE ÉTAT D'UN HARNAIS
 * -----------------------------------------
 * Ce n'est pas un échec isolé, c'est une **confiance mal placée** :
 *   - un correctif appliqué ici paraît « ne pas marcher » (le test interroge du code qui ne l'a pas reçu) ;
 *   - un défaut corrigé ailleurs paraît « déjà résolu » (le test passe sur du code qu'on ne livrera pas).
 *
 * LA GARDE
 * --------
 * `tests/Playwright/global-setup.js` dépose un marqueur unique dans le `public/` de CET arbre,
 * demande au serveur ciblé de le rendre, puis le retire. Si le serveur est ailleurs, il ne peut
 * pas connaître le jeton — y compris quand un catch-all SPA répond 200 avec du HTML.
 *
 * Vérifié le 2026-08-25 : `:8000` REJETÉ, `:8766` ACCEPTÉ, port mort → erreur réseau explicite,
 * et zéro résidu dans `public/`.
 */

const cheminSetup = path.resolve(process.cwd(), 'tests/Playwright/global-setup.js');
const source = fs.readFileSync(cheminSetup, 'utf8');

describe('Garde E2E — le serveur ciblé sert-il bien cet arbre de travail ?', () => {
    it('la garde existe dans le global-setup', () => {
        expect(source).toContain('verifierMemeArbreDeTravail');
        expect(source).toContain('.e2e-worktree-marker');
    });

    it('elle échoue AVANT que le moindre seeder n’écrive', () => {
        // Une garde placée après les écritures ne garde rien : les seeders auraient déjà
        // réécrit le mot de passe admin et la machine borne sur la mauvaise base.
        const positionGarde = source.indexOf('verifierMemeArbreDeTravail');
        const positionSeeders = source.indexOf("run([\n        'foodking:ensure-admin'");
        const positionRun = source.indexOf('const run = (args) =>');

        expect(positionGarde).toBeGreaterThan(-1);
        expect(positionRun).toBeGreaterThan(-1);
        expect(
            positionGarde,
            'La garde d\'arbre de travail doit précéder la définition de run() et tout appel artisan.',
        ).toBeLessThan(positionRun);

        if (positionSeeders > -1) {
            expect(positionGarde).toBeLessThan(positionSeeders);
        }
    });

    it('elle reste posée APRÈS la garde de base de données', () => {
        // L'ordre importe : on refuse d'abord d'écrire sur la mauvaise BASE, ensuite on vérifie
        // le bon CODE. Inverser exposerait la base au cas où la vérification HTTP traîne.
        const positionBase = source.indexOf('FOODKING_E2E_DEDICATED_DB');
        const positionGarde = source.indexOf('verifierMemeArbreDeTravail');

        expect(positionBase).toBeGreaterThan(-1);
        expect(positionGarde).toBeGreaterThan(positionBase);
    });

    it('elle retire toujours le marqueur, même en cas d’échec', () => {
        // Sans `finally`, un serveur injoignable laisserait un fichier dans public/ à chaque essai.
        const bloc = source.slice(
            source.indexOf('verifierMemeArbreDeTravail'),
            source.indexOf('const run = (args) =>'),
        );
        expect(bloc).toContain('finally');
        expect(bloc).toMatch(/unlinkSync/);
    });

    it('elle distingue « serveur absent » de « mauvais serveur »', () => {
        const bloc = source.slice(
            source.indexOf('verifierMemeArbreDeTravail'),
            source.indexOf('const run = (args) =>'),
        );
        // Deux messages distincts : un opérateur doit savoir s'il doit démarrer un serveur
        // ou en changer. Un message unique le ferait chercher au mauvais endroit.
        expect(bloc).toContain('impossible de joindre le serveur ciblé');
        expect(bloc).toContain('NE SERT PAS cet arbre de travail');
    });

    it('elle nomme la correction concrète, pas seulement le problème', () => {
        const bloc = source.slice(
            source.indexOf('verifierMemeArbreDeTravail'),
            source.indexOf('const run = (args) =>'),
        );
        expect(bloc).toContain('PLAYWRIGHT_BASE_URL');
        expect(bloc).toContain('P0_E2E_VISE_UN_AUTRE_WORKTREE_2026-08-25.md');
    });

    it('le marqueur n’est pas versionné par accident', () => {
        expect(
            fs.existsSync(path.resolve(process.cwd(), 'public/.e2e-worktree-marker')),
            'Un marqueur résiduel traîne dans public/ : la garde ne nettoie pas.',
        ).toBe(false);
    });

    it('playwright.config.js expose bien la variable que la garde recommande', () => {
        const config = fs.readFileSync(path.resolve(process.cwd(), 'playwright.config.js'), 'utf8');
        expect(config).toContain('PLAYWRIGHT_BASE_URL');
    });
});
