import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-02 2026-08-28] Aucune clé AFFICHÉE ne doit manquer au français.
 *
 * Le Studio catalogue appelait `$t("error.something_wrong")` sur ses quatre
 * chemins d'échec — coupure réseau, 500, worker arrêté. La clé existait en
 * anglais et pas en français ; le repli (`i18n.js:124`, `['fr','en']`) allait
 * donc chercher l'anglais. Au moment précis où le commerçant a besoin de
 * comprendre ce qui vient d'échouer, le produit lui répondait « Something Wrong. »
 *
 * LE PÉRIMÈTRE EST LE SUJET DE CE BANC. Il serait facile — et faux — d'exiger que
 * `fr.json` contienne toutes les clés de `en.json` : il en manque 171, et elles
 * sont TOUTES orphelines (modules livreurs, licence, application client : masqués
 * en V1, jamais rendus). Un banc qui les compterait produirait 171 échecs pour un
 * seul défaut réel, serait mis en liste d'exceptions dans la semaine, et ne
 * garderait plus rien.
 *
 * On ne mord donc que sur l'intersection : **absente du français ET citée par un
 * écran**. Mesuré au moment de l'écriture : 172 absentes, 1 citée. Après
 * correctif : 0.
 *
 * Une note de `i18n.js` datée du 2026-07-16 affirmait « 0 clé manquante n'est
 * utilisée — vérifié ». C'était vrai ce jour-là ; une clé s'est glissée depuis,
 * sans que rien ne le signale. C'est précisément ce qu'un banc fait mieux qu'une
 * note.
 */
describe('ONB-02 · aucune clé affichée ne manque au français', () => {
    const racine = process.cwd();

    const charger = (langue) =>
        JSON.parse(
            fs.readFileSync(path.join(racine, `resources/js/languages/${langue}.json`), 'utf8'),
        );

    /** Toutes les clés `bloc.cle` de premier niveau d'un dictionnaire. */
    const aplatir = (dictionnaire) => {
        const sortie = new Set();

        for (const [bloc, contenu] of Object.entries(dictionnaire)) {
            if (contenu && typeof contenu === 'object' && !Array.isArray(contenu)) {
                for (const cle of Object.keys(contenu)) {
                    sortie.add(`${bloc}.${cle}`);
                }
            }
        }

        return sortie;
    };

    /** Parcourt tout le code front et relève les clés réellement citées. */
    const clesCitees = () => {
        const citees = new Set();

        const motifs = [
            /\$t\(\s*['"]([a-zA-Z0-9_]+\.[a-zA-Z0-9_]+)['"]/g,
            /[^$]\bt\(\s*['"]([a-zA-Z0-9_]+\.[a-zA-Z0-9_]+)['"]/g,
            /i18n\.global\.t\(\s*['"]([a-zA-Z0-9_]+\.[a-zA-Z0-9_]+)['"]/g,
        ];

        const parcourir = (dossier) => {
            for (const entree of fs.readdirSync(dossier, { withFileTypes: true })) {
                const complet = path.join(dossier, entree.name);

                if (entree.isDirectory()) {
                    if (entree.name !== 'languages') {
                        parcourir(complet);
                    }
                    continue;
                }

                if (!/\.(vue|js)$/.test(entree.name)) {
                    continue;
                }

                const source = fs.readFileSync(complet, 'utf8');

                for (const motif of motifs) {
                    motif.lastIndex = 0;
                    let m;
                    while ((m = motif.exec(source)) !== null) {
                        citees.add(m[1]);
                    }
                }
            }
        };

        parcourir(path.join(racine, 'resources/js'));

        return citees;
    };

    it("l'extraction mord — sinon ce banc serait vert en ne mesurant rien", () => {
        // Sans ce contrôle, une expression régulière cassée rendrait 0 clé citée,
        // l'intersection serait vide, et le banc serait vert en ne gardant rien.
        const citees = clesCitees();

        expect(
            citees.size,
            "Aucune clé de traduction trouvée dans resources/js : l'extraction est "
            + 'cassée et ce banc ne mesure plus rien.',
        ).toBeGreaterThan(500);

        // Une clé dont on sait qu'elle est citée, comme témoin.
        expect(citees.has('error.something_wrong')).toBe(true);
    });

    it("aucune clé citée par un écran ne manque au français", () => {
        const fr = aplatir(charger('fr'));
        const en = aplatir(charger('en'));
        const citees = clesCitees();

        // On ne juge que les clés qui EXISTENT quelque part : une clé citée et
        // absente des deux langues est un autre défaut (clé brute à l'écran),
        // couvert ailleurs, et le dire ici mélangerait deux mesures.
        const coupables = [...citees].filter((cle) => en.has(cle) && !fr.has(cle)).sort();

        expect(
            coupables,
            "Ces clés sont affichées par un écran et absentes de fr.json : le repli\n"
            + "va chercher l'anglais, et le commerçant lit de l'anglais dans un produit\n"
            + "français. Ajoutez-les à resources/js/languages/fr.json.\n"
            + coupables.join('\n'),
        ).toEqual([]);
    });

    it('les clés orphelines ne sont PAS comptées comme un défaut', () => {
        // Contrôle de périmètre : il en reste beaucoup, et c'est voulu. Si ce
        // nombre tombait à zéro, ce serait que quelqu'un a traduit 171 clés de
        // modules masqués — inutile, mais surtout le signe que le banc ci-dessus
        // a été élargi au mauvais périmètre.
        const fr = aplatir(charger('fr'));
        const en = aplatir(charger('en'));
        const citees = clesCitees();

        const orphelines = [...en].filter((cle) => !fr.has(cle) && !citees.has(cle));

        expect(
            orphelines.length,
            "Ce banc ne demande PAS de traduire les clés que personne n'affiche.",
        ).toBeGreaterThan(0);
    });
});
