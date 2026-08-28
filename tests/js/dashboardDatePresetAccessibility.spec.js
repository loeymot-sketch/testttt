import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';
import { parse } from '@vue/compiler-sfc';

/**
 * [REPLAN_8 2026-08-24] Découverte AUTOMATIQUE, plus de liste en dur.
 *
 * La version précédente énumérait exactement les fichiers déjà corrigés : six autres composants
 * admin portaient le même défaut et lui étaient structurellement invisibles, tout comme le
 * serait n'importe quel composant ajouté demain. Une sentinelle qui ne regarde que ce qu'on a
 * déjà réparé ne garde rien. On balaie donc tout `resources/js` et on retient tout composant qui
 * déclare `preset-ranges`.
 */
function listerComposantsAvecPreregles(racine = path.resolve('resources/js')) {
    const trouves = [];
    for (const entree of fs.readdirSync(racine, { withFileTypes: true })) {
        const chemin = path.join(racine, entree.name);
        if (entree.isDirectory()) {
            trouves.push(...listerComposantsAvecPreregles(chemin));
        } else if (entree.name.endsWith('.vue')
            && fs.readFileSync(chemin, 'utf8').includes('preset-ranges')) {
            trouves.push(path.relative(process.cwd(), chemin));
        }
    }
    return trouves.sort();
}

const files = listerComposantsAvecPreregles();

/**
 * Extrait le tableau `presetRanges` et le découpe en objets de premier niveau.
 *
 * [REPLAN_7 2026-08-24] Pourquoi ce parseur plutôt qu'un simple grep : la
 * version précédente de cette sentinelle se contentait de vérifier que le
 * FICHIER contenait un `<button class="dashboard-date-preset">`. Elle était
 * verte alors que 4 préréglages sur 5 rendaient encore une `<div>` muette,
 * parce que vue-datepicker n'emprunte le slot personnalisé que pour les
 * entrées qui déclarent `slot`. Un test qui ne peut pas échouer pour la bonne
 * raison ne prouve rien : on compte donc les entrées une par une.
 */
function extrairePreréglages(source, fichier) {
    const debut = source.search(/presetRanges(?::|\s*=\s*ref\()\s*\[/);
    expect(debut, `presetRanges introuvable dans ${fichier}`).toBeGreaterThan(-1);
    const ouverture = source.indexOf('[', debut);
    let profondeur = 0;
    let fin = -1;
    for (let i = ouverture; i < source.length; i += 1) {
        if (source[i] === '[') profondeur += 1;
        else if (source[i] === ']') {
            profondeur -= 1;
            if (profondeur === 0) { fin = i; break; }
        }
    }
    expect(fin, `tableau presetRanges non refermé dans ${fichier}`).toBeGreaterThan(-1);

    const corps = source.slice(ouverture + 1, fin);
    const entrees = [];
    let niveau = 0;
    let depart = -1;
    for (let i = 0; i < corps.length; i += 1) {
        if (corps[i] === '{') {
            if (niveau === 0) depart = i;
            niveau += 1;
        } else if (corps[i] === '}') {
            niveau -= 1;
            if (niveau === 0) entrees.push(corps.slice(depart, i + 1));
        }
    }
    return entrees;
}

describe('Préréglages de dates — accessibilité clavier', () => {
    for (const file of files) {
        it(`${path.basename(file)} utilise une action native nommée`, () => {
            const source = fs.readFileSync(path.resolve(file), 'utf8');
            const parsed = parse(source, { filename: file });

            expect(parsed.errors).toEqual([]);
            expect(source).toMatch(/<button\s+type="button"[^>]*class="[^"]*dashboard-date-preset/);
            expect(source).toContain('@click="presetDateRange(range)"');
            expect(source).toContain('focus-visible:ring-2');
            expect(source).not.toMatch(/<span[^>]*@click="presetDateRange\(range\)"/);
        });

        it(`${path.basename(file)} route CHAQUE préréglage par le slot accessible`, () => {
            const source = fs.readFileSync(path.resolve(file), 'utf8');

            // Le nom du slot déclaré dans le template doit être celui que
            // portent les entrées : sinon le template accessible est mort.
            const nomSlot = source.match(/<template\s+#([A-Za-z0-9_-]+)="\{\s*label/);
            expect(nomSlot, `aucun <template #slot> de préréglage dans ${file}`).not.toBeNull();

            const entrees = extrairePreréglages(source, file);
            expect(entrees.length, `aucun préréglage listé dans ${file}`).toBeGreaterThan(0);

            const sansSlot = entrees.filter((e) => !new RegExp(`slot:\\s*['"]${nomSlot[1]}['"]`).test(e));
            // Une clé `slot` déclarée deux fois dans le même objet est un accident de patch :
            // JS garde la dernière en silence, mais c'est le signe d'une réécriture bâclée.
            const slotEnDouble = entrees.filter(
                (e) => (e.match(new RegExp(`slot:\\s*['"]${nomSlot[1]}['"]`, 'g')) || []).length > 1,
            );
            expect(slotEnDouble, `clé slot dupliquée dans ${path.basename(file)}`).toEqual([]);
            expect(
                sansSlot,
                `${sansSlot.length}/${entrees.length} préréglage(s) de ${path.basename(file)} `
                + `ne déclarent pas slot: '${nomSlot[1]}' → vue-datepicker rendra une <div> `
                + `non focalisable au lieu du <button> accessible. Entrées fautives : ${sansSlot.join(' | ')}`,
            ).toEqual([]);
        });

        it(`${path.basename(file)} n'expose aucun préréglage en double ni libellé de démo`, () => {
            const source = fs.readFileSync(path.resolve(file), 'utf8');
            const entrees = extrairePreréglages(source, file);
            const libelles = entrees
                // Les deux styles de guillemets coexistent dans le dépôt : une sentinelle qui
                // n'en lit qu'un déclare « libellés illisibles » sur du code parfaitement valide.
                .map((e) => (e.match(/label:\s*'([^']*)'/) || e.match(/label:\s*"([^"]*)"/) || [])[1])
                .filter(Boolean);

            expect(libelles.length, `libellés illisibles dans ${file}`).toBe(entrees.length);
            expect(
                libelles.filter((l) => /\(slot\)/i.test(l)),
                `libellé de démo du template vendeur encore visible dans ${path.basename(file)}`,
            ).toEqual([]);
            expect(
                new Set(libelles).size,
                `préréglages en double dans ${path.basename(file)} : ${libelles.join(', ')}`,
            ).toBe(libelles.length);

            // [REPLAN_8 2026-08-24] ADR-007 verrouille la locale FR. Cinq écrans admin affichaient
            // encore « Today / This month / Last month / This year », hérités du template vendeur.
            const ANGLAIS = /^(Today|This month|Last month|This year|Yesterday|Last year)$/i;
            expect(
                libelles.filter((l) => ANGLAIS.test(l)),
                `libellé de préréglage en anglais dans ${path.basename(file)} (ADR-007 : locale FR)`,
            ).toEqual([]);
        });
    }

    it('la découverte automatique couvre bien tout le dépôt', () => {
        // Un plancher, pas une liste : si la découverte tombe à zéro (chemin cassé, refactor de
        // dossier), la sentinelle deviendrait silencieusement vide et ne garderait plus rien.
        expect(files.length, 'aucun composant à préréglages découvert').toBeGreaterThanOrEqual(12);
        expect(files.every((f) => f.endsWith('.vue'))).toBe(true);
    });

    it('le bouton Effacer du filtre POS ne soumet pas le formulaire', () => {
        const source = fs.readFileSync(
            path.resolve('resources/js/components/admin/posOrders/PosOrderListComponent.vue'),
            'utf8',
        );
        expect(source).toMatch(/<button\s+type="button"[^>]*class="db-btn[^>]*@click="clear"/);
    });
});
