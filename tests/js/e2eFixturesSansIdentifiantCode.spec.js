import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-4.1.2] Cliquet anti-dérive de fixtures E2E.
 *
 * POURQUOI CETTE SENTINELLE EXISTE
 * --------------------------------
 * Le 2026-08-25, un relevé sur la base réelle a montré que **27 des 36 identifiants d'articles
 * codés en dur dans `tests/e2e/` ne correspondent à AUCUN article existant**. Les specs qui les
 * portent ne peuvent pas passer : elles commandent des produits qui n'existent plus.
 *
 * La cause racine est connue et documentée (`reports/audit/E2E_DERIVE_FIXTURES_2026-08-25.md`) :
 * des articles ont été supprimés en douceur (`deleted_at`), donc invisibles via Eloquent mais
 * encore atteignables via `DB::table()` — et le menu a été refondu. Les specs, elles, ont gardé
 * leurs numéros de 2026-05.
 *
 * CE QUE CETTE SENTINELLE FAIT — ET NE FAIT PAS
 * ----------------------------------------------
 * Elle NE prétend PAS que les identifiants restants sont valides : elle ne parle pas à la base.
 * Elle empêche seulement la dette de **croître**. Le remède est le résolveur partagé
 * `resolveSimpleOrderableItem()` de `tests/e2e/helpers/kiosk-order.js`, qui choisit à l'exécution
 * un article réellement vendable (non supprimé, `status=5`, sans variation, sans étape d'assistant
 * obligatoire, disponible en succursale, et doté d'une vraie station cuisine).
 *
 * COMMENT FAIRE BAISSER LE CLIQUET
 * --------------------------------
 * Remplacer les littéraux d'une spec par un appel au résolveur, puis ABAISSER `PLAFOND_FICHIERS`
 * d'autant. Le compteur ne doit jamais remonter.
 */

const racineE2E = path.resolve(process.cwd(), 'tests/e2e');

/**
 * Identifiants volontairement inexistants : ils servent à prouver les CHEMINS D'ERREUR
 * (produit retiré du menu, article inconnu). Les coder en dur est ici le comportement correct —
 * un résolveur dynamique renverrait un article valide et détruirait le cas de test.
 */
const FAUX_INTENTIONNELS = new Set([999, 9001, 9002]);

/**
 * Deux plafonds, relevés le 2026-08-25. ILS NE DOIVENT QUE DESCENDRE.
 *
 * `PLAFOND_PAIRES` est le vrai cliquet : il compte les couples (fichier, identifiant) distincts.
 * Un plafond par FICHIER seul ne mordait pas — ajouter un identifiant figé de plus à un fichier
 * déjà fautif ne changeait pas le compte. Défaut constaté par test négatif le 2026-08-25 sur
 * cette sentinelle elle-même, puis corrigé ici.
 *
 * Répartition au relevé : 24 fichiers / 56 couples — 11 fichiers portent des identifiants MORTS
 * (aucun article correspondant en base), 13 des identifiants encore valides mais figés.
 */
const PLAFOND_FICHIERS = 24;
const PLAFOND_PAIRES = 56;

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

/** Extrait les identifiants d'articles écrits en dur dans une source de spec. */
function identifiantsCodesEnDur(source) {
    const trouves = [];
    const motifs = [
        /\bitem_id['"]?\s*[:=]\s*(\d+)/g,
        /\bitemId\s*[:=]\s*(\d+)/g,
    ];
    for (const motif of motifs) {
        let m;
        while ((m = motif.exec(source)) !== null) trouves.push(Number(m[1]));
    }
    return trouves;
}

const specs = listerSpecs(racineE2E);

const fichiersFautifs = specs
    .map((chemin) => {
        const ids = identifiantsCodesEnDur(fs.readFileSync(chemin, 'utf8'))
            .filter((id) => !FAUX_INTENTIONNELS.has(id));
        return { chemin: path.relative(process.cwd(), chemin), ids: [...new Set(ids)].sort((a, b) => a - b) };
    })
    .filter((f) => f.ids.length > 0);

describe('Dérive de fixtures E2E — cliquet sur les identifiants codés en dur', () => {
    it('trouve des specs à analyser (le balayage lui-même doit être vivant)', () => {
        expect(specs.length).toBeGreaterThan(100);
    });

    it('ne laisse pas la dette croître — cliquet sur les couples (fichier, identifiant)', () => {
        const detail = fichiersFautifs
            .map((f) => `  ${f.chemin} → ${f.ids.join(', ')}`)
            .join('\n');
        const couples = fichiersFautifs.reduce((n, f) => n + f.ids.length, 0);
        expect(
            couples,
            `Des specs codent en dur des identifiants d'articles.\n${detail}\n\n` +
                `Couples (fichier, identifiant) : ${couples} — plafond ${PLAFOND_PAIRES}.\n` +
                `S'il MONTE, un identifiant figé vient d'être introduit : remplacez-le par ` +
                `resolveSimpleOrderableItem(). S'il DESCEND, abaissez PLAFOND_PAIRES d'autant.`,
        ).toBeLessThanOrEqual(PLAFOND_PAIRES);
    });

    it('ne laisse pas de NOUVEAU fichier entrer dans la dette', () => {
        expect(
            fichiersFautifs.length,
            `Fichiers portant un identifiant figé : ${fichiersFautifs.length} — plafond ${PLAFOND_FICHIERS}. ` +
                `Une spec neuve ne doit jamais naître avec un identifiant en dur.`,
        ).toBeLessThanOrEqual(PLAFOND_FICHIERS);
    });

    it('expose le résolveur partagé, qui est le remède', () => {
        const helper = fs.readFileSync(
            path.resolve(process.cwd(), 'tests/e2e/helpers/kiosk-order.js'),
            'utf8',
        );
        expect(helper).toContain('resolveSimpleOrderableItem');
        expect(helper).toMatch(/module\.exports\s*=|exports\.resolveSimpleOrderableItem/);
    });

    /**
     * [T-4.1.3] Les CINQ exclusions du résolveur, chacune née d'un échec réel.
     *
     * ⚠️ Ces assertions sont TEXTUELLES, et je le dis plutôt que de le masquer : le résolveur
     * construit une requête exécutée par artisan/DB, qu'un test Vitest ne peut pas dérouler.
     * Vérifier la présence des clauses porteuses est le contrôle honnête disponible ici — il
     * attrape la suppression accidentelle d'un filtre, pas une erreur de logique SQL.
     */
    describe('exclusions du résolveur partagé', () => {
        const helper = fs.readFileSync(
            path.resolve(process.cwd(), 'tests/e2e/helpers/kiosk-order.js'),
            'utf8',
        );

        it('exclut les articles supprimés en douceur', () => {
            // Constaté le 2026-08-24 : le résolveur rendait des articles avec `deleted_at` au
            // 2026-05-28 mais `status = 5` — invisibles via Eloquent, atteignables via DB::table().
            expect(helper).toMatch(/whereNull\(\s*['"]items\.deleted_at['"]\s*\)/);
        });

        it('exclut les articles non publiés', () => {
            expect(helper).toMatch(/where\(\s*['"]items\.status['"]\s*,\s*5\s*\)/);
        });

        it('exclut les articles à variations (la commande exigerait un choix)', () => {
            expect(helper).toContain('item_variations');
            expect(helper).toMatch(/whereColumn\(\s*['"]item_variations\.item_id['"]/);
        });

        it('exclut les articles dont une étape d’assistant est obligatoire', () => {
            // `min_select > 0` = le client DOIT choisir : une commande automatique échouerait.
            expect(helper).toMatch(/min_select['"]\s*,\s*['"]>['"]\s*,\s*0/);
        });

        it('exige une VRAIE station cuisine, sinon le KDS ne montre rien', () => {
            // Piège coûteux : mon premier résolveur choisissait des articles `kds_station = none`
            // (Menu, Frites Seules, Boisson Seule). La commande partait, le KDS ne l'affichait
            // jamais, et l'échec ressemblait à un défaut de synchro.
            expect(helper).toMatch(/whereNotNull\(\s*['"]items\.kds_station['"]\s*\)/);
            expect(helper).toMatch(/['"]items\.kds_station['"]\s*,\s*['"]!=['"]\s*,\s*['"]none['"]/);
        });

        it('accepte un nom préféré et une liste d’exclusion, pour que les specs restent distinctes', () => {
            expect(helper).toContain('preferName');
            expect(helper).toContain('excludeIds');
        });
    });

    it('documente les faux identifiants intentionnels plutôt que de les subir', () => {
        // Si un de ces identifiants devient un vrai article, le cas d'erreur qu'il teste
        // cesse silencieusement de tester quoi que ce soit.
        expect(FAUX_INTENTIONNELS.size).toBeGreaterThan(0);
        for (const id of FAUX_INTENTIONNELS) expect(id).toBeGreaterThan(900);
    });
});
