import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * LE MUR DE STATUT NE DOIT PORTER AUCUN HABILLAGE D'ADMINISTRATION.
 *
 * Défaut mesuré le 2026-08-25 (superviseur adverse, constat E-005, ouvert 2 rondes) :
 * `/admin/order-status-screen` est une TÉLÉ TOURNÉE VERS LA SALLE — le client y lit son
 * numéro. La route ne portait aucun drapeau de thème, tombait donc dans le `else` de
 * `DefaultComponent` (theme « backend ») et montait la navbar + le menu d'admin.
 *
 * Relevé dans le DOM réellement capturé du mur :
 *   « Déconnexion » ×1, « admin@lecayenne.fr » ×1, plus le menu de profil
 *   (« Modifier Le Profil », « Changer Le Mot De Passe », « Appareils Connectés »).
 *
 * L'adresse du compte d'administration et la sortie de session, au-dessus de la tête des
 * clients, à un clic. Ces tests lisent les SOURCES : ils tiennent quel que soit le harnais
 * de rendu, et rougissent si quelqu'un retire le drapeau ou la branche.
 */

const RACINE = path.resolve(__dirname, '../..');
const ROUTE = path.join(RACINE, 'resources/js/router/modules/orderStatusScreenRoutes.js');
const LAYOUT = path.join(RACINE, 'resources/js/components/DefaultComponent.vue');

const lire = (f) => fs.readFileSync(f, 'utf8');

/**
 * Lire les sources est utile — mais une ligne COMMENTÉE reste du texte. Un premier jet de
 * ces tests cherchait `isWall: true` par expression régulière : la mutation qui commentait
 * la ligne (`// isWall: true`) a SURVÉCU, parce que le motif y était toujours. On dépouille
 * donc les commentaires avant d'interroger le code. Sans ça, ces tests prouvent la présence
 * d'une chaîne de caractères, pas celle d'un comportement.
 */
const codeSeul = (src) => src
    .replace(/\/\*[\s\S]*?\*\//g, '')       // blocs /* ... */
    .replace(/\{\{--[\s\S]*?--\}\}/g, '')   // commentaires Blade
    .replace(/<!--[\s\S]*?-->/g, '')        // commentaires HTML
    .split('\n')
    .map((l) => l.replace(/(^|[^:])\/\/.*$/, '$1'))
    .join('\n');

describe('mur de statut client — aucun habillage d\'administration', () => {
    it('LA ROUTE porte le drapeau de mur', () => {
        const src = codeSeul(lire(ROUTE));

        expect(
            /isWall\s*:\s*true/.test(src),
            'RÉGRESSION : la route du mur ne porte plus `isWall: true`. Sans ce drapeau elle '
            + 'retombe dans le `else` de DefaultComponent → theme « backend », qui monte la '
            + 'navbar et le menu d\'admin sur un écran tourné vers la salle.'
        ).toBe(true);
    });

    it('LE LAYOUT connaît un thème de mur, rendu SANS habillage', () => {
        const src = codeSeul(lire(LAYOUT));

        expect(
            /isWall\s*===\s*true/.test(src) || /meta\?\.isWall/.test(src),
            'DefaultComponent ne lit plus le drapeau `isWall`'
        ).toBe(true);

        // La branche de rendu du mur : un router-view et rien d'autre.
        const bloc = src.match(
            /<div v-if="isWallRoute \|\| theme === 'wall'">([\s\S]*?)<\/div>/
        );
        expect(bloc, 'la branche de rendu du mur a disparu du gabarit').not.toBeNull();

        const dedans = bloc[1];
        expect(dedans).toMatch(/<router-view><\/router-view>/);

        // Aucun composant d'habillage ne doit être monté dans cette branche.
        ['BackendNavbarComponent', 'BackendMenuComponent', 'FrontendNavbarComponent',
            'FrontendFooterComponent', 'FrontendCartComponent'].forEach((c) => {
            expect(
                dedans.includes(c),
                `« ${c} » est monté dans la branche du mur : c'est précisément l'habillage `
                + 'qu\'on retire.'
            ).toBe(false);
        });
    });

    it('LA BRANCHE ADMIN est explicitement exclue sur le mur', () => {
        const src = lire(LAYOUT);

        const backend = src.match(/<div v-if="theme === 'backend'([^"]*)">/);
        expect(backend, 'branche backend introuvable').not.toBeNull();

        expect(
            backend[1].includes('!isWallRoute'),
            'RÉGRESSION : la branche « backend » ne s\'exclut plus sur le mur. C\'est ELLE qui '
            + 'monte BackendNavbarComponent et BackendMenuComponent — donc « Déconnexion » et '
            + 'l\'adresse du compte d\'administration, face aux clients.'
        ).toBe(true);
    });

    it('LA BRANCHE VITRINE est exclue aussi — sinon le mur clignote au chargement à froid', () => {
        const src = lire(LAYOUT);

        const frontend = src.match(/<div v-if="theme === 'frontend'([^"]*)">/);
        expect(frontend, 'branche frontend introuvable').not.toBeNull();

        expect(
            frontend[1].includes('!isWallRoute'),
            'Le thème par défaut est « frontend » tant que le router n\'a pas résolu la route. '
            + 'Sans cette exclusion, une télé de salle allumée sur une adresse directe montre '
            + 'brièvement la navbar de la vitrine avant de se corriger.'
        ).toBe(true);
    });

    it('LE CALCUL est SYNCHRONE : il lit window.location, pas $route', () => {
        const src = lire(LAYOUT);

        const calcul = src.match(/isWallRoute:\s*function\s*\(\)\s*\{([\s\S]*?)\n    \},/);
        expect(calcul, 'le calcul isWallRoute a disparu').not.toBeNull();

        expect(
            /window\.location/.test(calcul[1]),
            'RÉGRESSION : `isWallRoute` ne lit plus `window.location`. Au chargement à froid — '
            + 'le SEUL chemin réel pour une télé de salle — `$route` n\'est pas encore résolu, '
            + 'donc un calcul basé uniquement dessus rend faux au premier rendu et laisse '
            + 'passer l\'habillage.'
        ).toBe(true);

        // Les deux chemins de la route doivent être couverts (le principal et son alias).
        expect(calcul[1]).toContain('/admin/order-status-screen');
        expect(calcul[1]).toContain('/order-status-screen');
    });

    it('LE MUR NE REBONDIT PAS vers /login : « wall » n\'est ni « frontend » ni « backend »', () => {
        const src = lire(LAYOUT);

        // La redirection de session expirée ne vise que ces deux thèmes. Le thème du mur
        // doit rester en dehors, sinon un formulaire de connexion s'affiche en salle.
        const redirection = src.match(
            /this\.theme == "frontend" \|\| this\.theme == "backend"/
        );
        expect(
            redirection,
            'la condition de redirection a changé de forme — vérifier à la main que le thème '
            + '« wall » en reste exclu, sinon une session expirée affiche /login face aux clients.'
        ).not.toBeNull();

        expect(
            /this\.theme == "wall"/.test(src),
            'le thème « wall » ne doit PAS figurer dans la condition de redirection vers /login'
        ).toBe(false);
    });
});
