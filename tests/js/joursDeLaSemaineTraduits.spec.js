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

        /**
         * [ONB-01 2026-08-28 · ASSERTION RESSERRÉE] `toContain('dayEnum.key')` était
         * trop lâche : elle passait aussi sur `{{ dayEnum.key }}` tout court, qui
         * afficherait « day_monday » en toutes lettres au commerçant. Le banc aurait
         * donc été vert sur un écran affichant la clé brute — soit un défaut PIRE que
         * l'anglais qu'il devait corriger.
         *
         * On exige maintenant la forme exacte : la clé passée à `$t`.
         * Trouvé par un agent adverse lancé sur mon propre travail.
         */
        expect(
            source,
            "la clé doit passer par \$t() — affichée telle quelle, elle donnerait "
            + "« day_monday » à l'écran",
        ).toMatch(/\$t\(\s*["']label\.["']\s*\+\s*dayEnum\.key/);

        expect(
            /\{\{\s*dayEnum\.key\s*\}\}/.test(source),
            "l'écran afficherait la clé de traduction brute (« day_monday »)",
        ).toBe(false);

        expect(
            source.includes('{{ dayEnum.name }}'),
            "l'écran est repassé sur le nom anglais brut",
        ).toBe(false);
    });

    /**
     * [ONB-01 2026-08-28] Vérification de l'API, pas de ma mémoire de l'API.
     *
     * L'écran appelle `$t("label." + key, dayEnum.name)`. En vue-i18n **v8**, un
     * second argument de type chaîne désignait la LOCALE — l'appel aurait cherché une
     * langue nommée « Monday », échoué, et serait retombé sur l'anglais : le défaut
     * d'origine intact sous un correctif d'apparence. En **v9**, la même position est
     * le message par défaut.
     *
     * Le projet est en 9.14.5, donc la forme est bonne — mais c'est exactement le
     * genre de fait qu'on croit savoir. On l'exerce.
     */
    it('le second argument de $t est bien un repli, pas une locale', async () => {
        const { createI18n } = await import('vue-i18n');

        const i18n = createI18n({
            legacy: false,
            locale: 'fr',
            fallbackLocale: 'en',
            messages: {
                fr: { label: { day_monday: 'Lundi' } },
                en: { label: { day_monday: 'Monday' } },
            },
        });

        const t = i18n.global.t;

        expect(
            t('label.' + 'day_monday', 'Monday'),
            'une clé présente en français doit rendre le français, pas le repli',
        ).toBe('Lundi');

        // ⚠️ Ce cas doit porter sur une clé absente des DEUX langues. Une clé présente
        // en anglais serait rendue par la `fallbackLocale`, pas par le second argument :
        // le test passerait sans rien prouver. C'est le piège dans lequel la première
        // version de ce banc était tombée — la trace `[intlify] Fall back to translate`
        // l'a dit tout haut.
        expect(
            t('label.' + 'day_inexistant', 'Repli attendu'),
            "second argument traité comme une LOCALE : ce serait la v8, et l'appel "
            + "`$t('label.' + key, dayEnum.name)` de l'écran chercherait une langue "
            + 'nommée « Monday » au lieu de rendre un repli',
        ).toBe('Repli attendu');
    });
});
