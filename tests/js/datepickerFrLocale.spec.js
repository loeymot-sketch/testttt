import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

/**
 * [W-REM T-R2.2 2026-06-12] Sentinelle datepickers FR — admin.
 *
 * Dette §0.1 GOAL PRODUCTION TOTALE : 17/21 fichiers admin avec
 * @vuepic/vue-datepicker SANS locale FR → calendrier EN (mois/jours),
 * affichage date US, et time-pickers 12h AM/PM sur UI FR (finding DB5-02 :
 * "02 : 52 AM" prouvé live sur /admin/coupons).
 *
 * Heal inline (pattern déjà établi + CI-locked par
 * cashFiltersFrDatepicker.spec.js sur cash*) :
 *   - chaque tag <Datepicker …> admin porte locale="fr"
 *   - date-only  → format="dd/MM/yyyy"
 *   - datetime   → :is24="true" + format="dd/MM/yyyy HH:mm"
 *   - time-only  → :is24="true" + format="HH:mm"
 * Display-only : AUCUN model-type ajouté (les v-model Date/range et les
 * handlers @update:modelValue restent intacts).
 *
 * Ratchet : 0 datepicker admin sans locale="fr", 0 :is24="false" résiduel.
 */

const REPO_ROOT = path.resolve(__dirname, '../..');
const ADMIN_DIR = path.join(REPO_ROOT, 'resources/js/components/admin');

function walkVueFiles(dir) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const filePath = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            out.push(...walkVueFiles(filePath));
        } else if (filePath.endsWith('.vue')) {
            out.push(filePath);
        }
    }
    return out;
}

// Every <Datepicker …> opening tag, multi-line, up to its closing ">".
const TAG_RE = /<Datepicker\b[^>]*>/gs;

const files = walkVueFiles(ADMIN_DIR);

describe('admin datepickers FR locale sentinel (T-R2.2)', () => {
    it('every <Datepicker> tag in resources/js/components/admin carries locale="fr"', () => {
        const offenders = [];
        for (const file of files) {
            const src = fs.readFileSync(file, 'utf-8');
            for (const tag of src.match(TAG_RE) ?? []) {
                if (!/\blocale="fr"/.test(tag)) {
                    offenders.push(path.relative(REPO_ROOT, file));
                }
            }
        }
        expect(
            offenders,
            `Datepicker(s) without locale="fr" in: ${[...new Set(offenders)].join(', ')}`
        ).toEqual([]);
    });

    it('no admin datepicker forces 12h AM/PM (:is24="false") on the FR UI (DB5-02)', () => {
        const offenders = [];
        for (const file of files) {
            const src = fs.readFileSync(file, 'utf-8');
            if (/:is24="false"/.test(src)) {
                offenders.push(path.relative(REPO_ROOT, file));
            }
        }
        expect(offenders).toEqual([]);
    });

    it('every time-enabled admin datepicker renders 24h (:is24="true")', () => {
        const offenders = [];
        for (const file of files) {
            const src = fs.readFileSync(file, 'utf-8');
            for (const tag of src.match(TAG_RE) ?? []) {
                const timeEnabled = /:enableTimePicker="true"/.test(tag) || /\btime-picker\b/.test(tag);
                if (timeEnabled && !/:is24="true"/.test(tag)) {
                    offenders.push(path.relative(REPO_ROOT, file));
                }
            }
        }
        expect(offenders).toEqual([]);
    });

    it('every admin datepicker declares an explicit FR display format', () => {
        const offenders = [];
        for (const file of files) {
            const src = fs.readFileSync(file, 'utf-8');
            for (const tag of src.match(TAG_RE) ?? []) {
                if (!/\bformat="(dd\/MM\/yyyy( HH:mm)?|HH:mm)"/.test(tag)) {
                    offenders.push(path.relative(REPO_ROOT, file));
                }
            }
        }
        expect(
            offenders,
            `Datepicker(s) without FR format in: ${[...new Set(offenders)].join(', ')}`
        ).toEqual([]);
    });
});
