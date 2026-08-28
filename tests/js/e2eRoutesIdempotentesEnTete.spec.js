import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — découverte du 2026-08-25, vague W7]
 *
 * CE QUI A ÉTÉ TROUVÉ, EN REJOUANT LA VAGUE D
 * --------------------------------------------
 * La vague D échouait sur deux états consécutifs avec une seule et même erreur backend :
 *
 *     state07: KDS→PREPARING  → 422 « Header X-Idempotency-Key requis pour cette opération. »
 *     state09: KDS→PREPARED   → 422 « Header X-Idempotency-Key requis pour cette opération. »
 *
 * La cause n'est pas un défaut produit : `config/idempotency.php` liste
 * `api/admin/kds-order/change-status/*` dans `required_routes`, **délibérément** — un double bump
 * enverrait deux notifications client. C'est la SPEC qui n'avait jamais été mise à jour après
 * l'ajout de la route à cette liste.
 *
 * L'AMPLEUR — ce n'était pas un cas isolé
 * ---------------------------------------
 * Relevé le 2026-08-25 : **26 specs** appellent `kds-order/change-status`, dont **12 sans en-tête d'idempotence** —
 * toutes corrigées le 2026-08-25. Toutes prennent un 422 sur cette transition, et tout ce qui en dépend
 * en aval échoue « pour une mauvaise raison ».
 *
 * C'est un mode de panne particulièrement coûteux : l'échec ressemble à un défaut de synchro
 * cuisine, et envoie chercher dans le KDS un problème qui n'y est pas.
 *
 * CE QUE CETTE SENTINELLE FAIT
 * -----------------------------
 * Elle ne répare pas les 16 specs — chacune a une forme d'appel différente et demande une
 * vérification propre. Elle empêche la dette de **croître**, et surtout elle rend la classe de
 * défaut VISIBLE : la prochaine route ajoutée à `required_routes` sans mise à jour des specs
 * fera monter ce compteur au lieu de produire des rouges inexplicables des mois plus tard.
 */

const racineE2E = path.resolve(process.cwd(), 'tests/e2e');

/** Routes exigeant l'en-tête, extraites de la config PHP (source unique). */
function routesExigeantIdempotence() {
    const source = fs.readFileSync(path.resolve(process.cwd(), 'config/idempotency.php'), 'utf8');
    const bloc = source.slice(source.indexOf("'required_routes'"), source.lastIndexOf(']'));
    return [...bloc.matchAll(/'(api\/[^']+)'/g)].map((m) => m[1]);
}

function listerSpecs(repertoire) {
    const sortie = [];
    for (const entree of fs.readdirSync(repertoire, { withFileTypes: true })) {
        if (entree.name === '__screenshots__' || entree.name === 'node_modules') continue;
        const complet = path.join(repertoire, entree.name);
        if (entree.isDirectory()) sortie.push(...listerSpecs(complet));
        else if (entree.name.endsWith('.spec.js')) sortie.push(complet);
    }
    return sortie;
}

/**
 * Une spec est « fautive » si elle appelle une route exigeant l'en-tête sans qu'un
 * `X-Idempotency-Key` apparaisse dans les 25 lignes qui suivent l'appel.
 *
 * ⚠️ Heuristique de proximité, et je le dis : elle regarde 25 lignes après l'appel. Elle accepte
 * l'en-tête posé directement OU un `idemKey:` transmis à un assistant qui le pose. Elle ne suit
 * pas une indirection plus profonde — un assistant appelant un assistant lui échapperait.
 */
/**
 * Retire commentaires de ligne et de bloc.
 *
 * ⚠️ Corrigé le 2026-08-25 : la première version comptait 18 fichiers, dont plusieurs pour de
 * simples COMMENTAIRES, noms de test ou chaînes d'assertion mentionnant la route. Un cliquet qui
 * gonfle son chiffre dilue la confiance qu'on lui accorde — et le premier réflexe devant un
 * compteur qu'on ne croit plus est de le désactiver.
 */
function sansCommentaires(source) {
    // ⚠️ ORDRE IMPORTANT — commentaires de LIGNE d'abord, blocs ensuite.
    //
    // L'ordre inverse a réellement cassé cette sentinelle le 2026-08-25 : un commentaire de ligne
    // mentionnait un motif de route se terminant par `/` puis `*`. Ce `/`+`*` ouvrait un
    // commentaire de bloc aux yeux du dépouilleur, qui avalait tout jusqu'au `*` `/` suivant —
    // dont la ligne d'en-tête qu'on cherchait justement à détecter. Résultat : 8 faux positifs,
    // sur des fichiers déjà corrigés.
    //
    // En retirant les commentaires de ligne EN PREMIER, un tel motif disparaît avant de pouvoir
    // ouvrir quoi que ce soit.
    const sansLignes = source
        .split('\n')
        .map((ligne) => ligne.replace(/(^|[^:'"`\\])\/\/.*$/, '$1'))
        .join('\n');

    return sansLignes.replace(/\/\*[\s\S]*?\*\//g, ' ');
}

/** Un vrai site d'appel : axios.post / fetch / nodeRequest, pas une mention. */
const MOTIFS_APPEL = /(axios\.(post|put|patch)|fetch\s*\(|nodeRequest\s*\()/;

/**
 * Le fragment est-il à l'intérieur d'une chaîne simple/double ?
 *
 * ⚠️ Ajouté après un faux positif réel : `wave-final-S3-kds.spec.js:449` porte
 *     fix_hint: 'Check … must be axios.post(`admin/kds-order/change-status/${id}`).'
 * — une chaîne de CONSEIL, pas un appel. Cette spec pilote l'interface et observe le réseau ;
 * elle n'appelle jamais l'API directement. Le motif d'appel matchait pourtant, parce que le texte
 * du conseil contient littéralement « axios.post( ».
 *
 * Les vraies URL d'appel sont écrites en gabarit (accent grave) ; les mentions en prose le sont
 * en apostrophes ou guillemets. On s'appuie sur cette distinction, qui tient dans tout le dépôt.
 */
function dansUneChaineDeProse(ligne, index) {
    let apostrophe = false;
    let guillemet = false;
    for (let i = 0; i < index; i += 1) {
        const c = ligne[i];
        if (c === '\\') { i += 1; continue; }
        if (c === "'" && !guillemet) apostrophe = !apostrophe;
        else if (c === '"' && !apostrophe) guillemet = !guillemet;
    }
    return apostrophe || guillemet;
}

function specsSansEnTete(fragmentRoute) {
    const fautives = [];
    for (const chemin of listerSpecs(racineE2E)) {
        const lignes = sansCommentaires(fs.readFileSync(chemin, 'utf8')).split('\n');
        for (let i = 0; i < lignes.length; i += 1) {
            if (!lignes[i].includes(fragmentRoute)) continue;

            // Le fragment doit apparaître dans un CONTEXTE D'APPEL — la ligne elle-même ou les
            // trois précédentes (formes multi-lignes `axios.post(\n  'route',`).
            if (dansUneChaineDeProse(lignes[i], lignes[i].indexOf(fragmentRoute))) continue;

            const contexte = lignes.slice(Math.max(0, i - 3), i + 1).join('\n');
            if (!MOTIFS_APPEL.test(contexte)) continue;

            const fenetre = lignes.slice(i, i + 25).join('\n');

            // Deux preuves acceptées, parce que le dépôt emploie deux façons légitimes de poser
            // l'en-tête :
            //   - directement : `headers: { 'X-Idempotency-Key': … }` ;
            //   - indirectement : `idemKey: …` passé à un assistant qui pose l'en-tête lui-même
            //     (voir `nodeRequest()` dans test-e2e-supervisor-wave-c-z4-latency, ligne 85).
            //
            // ⚠️ Ce second cas a d'abord été signalé à tort comme fautif. Une sentinelle qui
            // accuse du code correct est aussi nuisible qu'une qui laisse passer du code cassé :
            // dans les deux cas on cesse de la croire.
            if (!/X-Idempotency-Key|idemKey\s*:/i.test(fenetre)) {
                fautives.push(path.relative(process.cwd(), chemin));
                break;
            }
        }
    }
    return fautives;
}

/**
 * Plafond : **0**. La dette est soldée le 2026-08-25 — plus aucune spec n'appelle
 * `kds-order/change-status` sans `X-Idempotency-Key`. Elle ne doit JAMAIS remonter.
 *
 * ⚠️ Le chiffre a été corrigé TROIS FOIS avant d'être juste, et l'historique vaut d'être gardé :
 *   - **16** — comptage manuel : il s'arrêtait au premier site PORTANT l'en-tête et déclarait le
 *     fichier conforme. Or un fichier à deux appels dont un seul est correct reste cassé.
 *   - **18** — première sentinelle : elle comptait aussi les commentaires, noms de test et
 *     chaînes d'assertion mentionnant la route.
 *   - **13** — commentaires dépouillés + contexte d'appel exigé. Restait un faux positif :
 *     `wave-final-S3-kds.spec.js:449` porte la route dans une chaîne de CONSEIL (`fix_hint`),
 *     pas dans un appel — cette spec pilote l'interface et observe le réseau.
 *   - **12 corrigées, 0 restante** — après exclusion de ce qui se trouve dans une chaîne de prose.
 *
 * Un cliquet qui gonfle son chiffre dilue la confiance qu'on lui accorde ; le premier réflexe
 * devant un compteur qu'on ne croit plus est de le désactiver.
 */
const PLAFOND_SANS_ENTETE = 0;

describe('Routes idempotentes — les specs E2E envoient-elles l’en-tête ?', () => {
    it('lit bien la liste des routes exigeant l’en-tête', () => {
        const routes = routesExigeantIdempotence();
        expect(routes.length).toBeGreaterThan(5);
        expect(routes).toContain('api/admin/kds-order/change-status/*');
    });

    it('ne laisse pas grandir le nombre de specs sans en-tête sur change-status', () => {
        const fautives = specsSansEnTete('kds-order/change-status');
        expect(
            fautives.length,
            `${fautives.length} specs appellent kds-order/change-status SANS X-Idempotency-Key ` +
                `(plafond ${PLAFOND_SANS_ENTETE}). Chacune prendra un 422, et l'échec ressemblera ` +
                `à un défaut de synchro cuisine :\n  ${fautives.join('\n  ')}`,
        ).toBeLessThanOrEqual(PLAFOND_SANS_ENTETE);
    });

    it('la vague D, elle, a été corrigée', () => {
        // Régression nommée : c'est par elle que la classe de défaut a été découverte.
        const source = fs.readFileSync(
            path.resolve(process.cwd(), 'tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-D.spec.js'),
            'utf8',
        );
        expect(source).toContain('X-Idempotency-Key');
        expect(source).toMatch(/kdsAdvanceStatus[\s\S]{0,2000}X-Idempotency-Key/);
    });

    it('la config reste la source unique — aucune route écrite en dur ici', () => {
        // Si cette sentinelle recopiait la liste, elle mentirait dès la prochaine route ajoutée.
        const moi = fs.readFileSync(
            path.resolve(process.cwd(), 'tests/js/e2eRoutesIdempotentesEnTete.spec.js'),
            'utf8',
        );
        expect(moi).toContain("readFileSync(path.resolve(process.cwd(), 'config/idempotency.php')");
    });
});
