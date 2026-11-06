import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * LES ENTRÉES DU MENU « BONJOUR … » DOIVENT MENER QUELQUE PART DEPUIS LA CAISSE.
 *
 * Défaut trouvé à la ronde 3 de l'audit superviseur (2026-08-25), en poursuivant 20 erreurs
 * de console sur `/admin/pos-v4` — toutes un `resolve()` de routeur en échec, sans message.
 *
 * La coquille de caisse monte `DefaultComponent`, donc la navbar d'admin, dont le menu
 * utilisateur résout trois routes de profil. Les trois étaient mortes, par DEUX mécanismes
 * différents — et c'est la seconde moitié qui est instructive :
 *
 *   1. « Appareils connectés » n'était pas enregistrée → `resolve()` levait, lien sans
 *      adresse. Bruyant : 20 erreurs de console. C'est ce qui a mis sur la piste.
 *
 *   2. « Modifier le profil » et « Changer le mot de passe » étaient enregistrées, mais en
 *      REDIRECTION VERS LA CAISSE. Le nom se résolvait, donc aucune erreur, donc AUCUNE
 *      trace : le caissier cliquait et revenait exactement d'où il venait. Un lien qui avale
 *      la demande en silence est pire qu'un lien cassé — rien n'indique qu'il ne marche pas.
 *
 * Ces tests gardent les deux : le nom doit exister, ET ne pas boucler sur la caisse.
 */

const POS_APP = path.resolve(__dirname, '../../resources/js/pos-app.js');

/** Le menu utilisateur de la navbar d'admin résout exactement ces trois noms. */
const ENTREES_DU_MENU = [
    ['admin.profile.editProfile', 'Modifier le profil'],
    ['admin.profile.changePassword', 'Changer le mot de passe'],
    ['admin.profile.devices', 'Appareils connectés'],
];

const source = () => fs.readFileSync(POS_APP, 'utf8');

/**
 * Le bloc de définition d'une route, borné par APPARIEMENT D'ACCOLADES.
 *
 * Un premier jet cherchait la prochaine occurrence de « \n        }, » : sur une route
 * écrite en UNE SEULE LIGNE — précisément la forme qu'avait la redirection fautive — ce
 * repère se trouvait bien plus bas, dans la route SUIVANTE. Le bloc débordait donc sur son
 * voisin, et le test « passe la main » y trouvait le `window.location.assign` du voisin :
 * il passait au vert sur une route qui ne l'avait pas. Vérifié par mutation.
 */
function blocDeLaRoute(src, nom) {
    const i = src.indexOf(`name: '${nom}'`);
    if (i === -1) return null;
    const debut = src.lastIndexOf('{', i);

    let profondeur = 0;
    for (let k = debut; k < src.length; k += 1) {
        if (src[k] === '{') profondeur += 1;
        else if (src[k] === '}') {
            profondeur -= 1;
            if (profondeur === 0) return src.slice(debut, k + 1);
        }
    }
    return null;
}

describe('menu profil depuis la caisse', () => {
    it.each(ENTREES_DU_MENU)('« %s » (%s) est enregistrée dans le routeur de la caisse', (nom, libelle) => {
        expect(
            source().includes(`name: '${nom}'`),
            `RÉGRESSION : « ${libelle} » n'est pas enregistrée dans pos-app.js. La navbar la `
            + 'résout quand même : `resolve()` lève, le lien ne porte aucune adresse, et le '
            + 'menu du poste affiche une entrée qui ne mène nulle part.'
        ).toBe(true);
    });

    it.each(ENTREES_DU_MENU)('« %s » (%s) ne renvoie PAS le caissier sur la caisse', (nom, libelle) => {
        const bloc = blocDeLaRoute(source(), nom);
        expect(bloc, `route « ${nom} » introuvable`).not.toBeNull();

        expect(
            /redirect\s*:/.test(bloc),
            `RÉGRESSION : « ${libelle} » est une REDIRECTION. Le nom se résout — donc aucune `
            + 'erreur, donc aucune trace dans les journaux — et le clic ramène le caissier '
            + 'exactement d\'où il vient. C\'est le défaut le plus difficile à voir : un lien '
            + 'qui avale la demande en silence.'
        ).toBe(false);
    });

    it.each(ENTREES_DU_MENU)('« %s » (%s) passe la main à l\'application d\'admin', (nom, libelle) => {
        const bloc = blocDeLaRoute(source(), nom);
        expect(bloc, `route « ${nom} » introuvable`).not.toBeNull();

        expect(
            /window\.location\.assign\(to\.fullPath\)/.test(bloc),
            `« ${libelle} » doit relayer vers l'application d'admin par une navigation de page `
            + 'entière : cet écran vit dans le bundle complet, pas dans le lot allégé de la '
            + 'caisse. C\'est le patron déjà utilisé par le suivi et l\'écran client.'
        ).toBe(true);
    });

    it('les trois entrées visent bien les adresses réelles des pages de profil', () => {
        const src = source();
        [
            ['admin.profile.editProfile', '/admin/profile/edit-profile'],
            ['admin.profile.changePassword', '/admin/profile/change-password'],
            ['admin.profile.devices', '/admin/profile/devices'],
        ].forEach(([nom, chemin]) => {
            const bloc = blocDeLaRoute(src, nom);
            expect(bloc, `route « ${nom} » introuvable`).not.toBeNull();
            expect(
                bloc.includes(`path: '${chemin}'`),
                `« ${nom} » doit pointer sur « ${chemin} » — une adresse fausse relaierait vers `
                + 'une page 404, ce qui ne vaut pas mieux qu\'un lien mort.'
            ).toBe(true);
        });
    });
});
