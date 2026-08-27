import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import dayEnum from '../../resources/js/enums/modules/dayEnum';

/**
 * [ONB-01 2026-08-28] Les jours de la semaine s'affichaient en anglais.
 *
 * Vu à l'écran Paramètres › Créneaux Horaires : sous un titre français, la colonne
 * des jours affichait « Monday, Tuesday, Wednesday… ». C'est la page où un
 * restaurateur déclare ses heures d'ouverture — le premier réglage d'exploitation
 * qu'il touche après son identité.
 *
 * Les noms étaient des chaînes anglaises codées en dur dans `dayEnum` et rendues
 * telles quelles, sans passer par l'i18n. Aucun test ne pouvait le voir : le fichier
 * de langue était irréprochable, le mot anglais vivait ailleurs.
 *
 * ⚠️ Les IDENTIFIANTS ne doivent jamais bouger : ils sont le contrat de donnée avec
 * la colonne `time_slots.day`, et suivent la convention de `date('w')` où
 * **dimanche = 0**, pas 7. Les renuméroter décalerait les horaires déjà enregistrés
 * d'un jour — c'est ce que ce test protège en premier.
 */
describe('ONB-01 · jours de la semaine', () => {
    const attendus = [
        { id: 1, key: 'day_monday' },
        { id: 2, key: 'day_tuesday' },
        { id: 3, key: 'day_wednesday' },
        { id: 4, key: 'day_thursday' },
        { id: 5, key: 'day_friday' },
        { id: 6, key: 'day_saturday' },
        { id: 0, key: 'day_sunday' },
    ];

    it("les identifiants sont intacts, dimanche vaut 0", () => {
        expect(dayEnum.map((j) => j.id)).toEqual(attendus.map((j) => j.id));

        const dimanche = dayEnum.find((j) => j.key === 'day_sunday');
        expect(
            dimanche?.id,
            "dimanche doit rester 0 (convention date('w')) — le renuméroter décalerait "
            + "d'un jour tous les créneaux déjà enregistrés",
        ).toBe(0);
    });

    it('chaque jour porte une clé de traduction', () => {
        expect(dayEnum.map((j) => j.key)).toEqual(attendus.map((j) => j.key));
    });

    it('les sept clés existent en français ET en anglais', () => {
        for (const langue of ['fr', 'en']) {
            const json = JSON.parse(
                fs.readFileSync(
                    path.join(process.cwd(), `resources/js/languages/${langue}.json`),
                    'utf8',
                ),
            );

            for (const { key } of attendus) {
                expect(
                    json.label?.[key],
                    `${langue}.json : la clé label.${key} manque`,
                ).toBeTruthy();
            }
        }
    });

    it('le français dit bien « Lundi », pas « Monday »', () => {
        const fr = JSON.parse(
            fs.readFileSync(path.join(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'),
        );

        expect(fr.label.day_monday).toBe('Lundi');
        expect(fr.label.day_sunday).toBe('Dimanche');

        // Contrôle négatif : aucune des sept traductions françaises ne doit être
        // restée en anglais. Sans cette assertion, copier les valeurs anglaises
        // dans fr.json ferait passer le test ci-dessus.
        const anglais = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        for (const { key } of attendus) {
            expect(
                anglais.includes(fr.label[key]),
                `fr.json : label.${key} vaut « ${fr.label[key]} », qui est le mot anglais`,
            ).toBe(false);
        }
    });

    it("l'écran rend la traduction, plus le nom brut", () => {
        const source = fs.readFileSync(
            path.join(
                process.cwd(),
                'resources/js/components/admin/settings/TimeSlot/TimeSlotListComponent.vue',
            ),
            'utf8',
        );

        expect(source).toContain('dayEnum.key');
        expect(
            source.includes('{{ dayEnum.name }}'),
            "l'écran est repassé sur le nom anglais brut",
        ).toBe(false);
    });
});
