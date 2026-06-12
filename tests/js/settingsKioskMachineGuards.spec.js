import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

/**
 * [W-REM T-R2.3 Q-6 2026-06-12] Settings — gardes + libellés FR restants.
 *
 * Findings D-B4 (micro-audit loyalty-validation 2026-06-12) :
 *   - F4 : Bornes > Modifier → TypeError console + UTILISATEUR « -- » quand
 *     la machine référence un user ABSENT de la liste (SimpleUserService
 *     filtre whereHas roles — artefacts E2E sans rôle). Le vue-select doit
 *     recevoir une option fallback pour le user_id courant, et les fetchs
 *     de listes doivent surfacer un toast en cas d'échec (pas de .catch
 *     silencieux → modal vide inexpliqué).
 *   - F1 : fr.json label.ios_app_link = "Ios Application Lien" (franglish
 *     visible sur Settings > Site).
 */

const REPO_ROOT = path.resolve(__dirname, '../..');
const CREATE_SRC = fs.readFileSync(
    path.join(
        REPO_ROOT,
        'resources/js/components/admin/settings/KioskMachine/KioskMachineCreateComponent.vue'
    ),
    'utf-8'
);
const fr = JSON.parse(
    fs.readFileSync(path.join(REPO_ROOT, 'resources/js/languages/fr.json'), 'utf-8')
);

describe('kiosk machine edit modal user fallback (Q-6/F4)', () => {
    it('vue-select consumes a guarded userOptions computed (not the raw store list)', () => {
        expect(CREATE_SRC).toMatch(/:options="userOptions"/);
        expect(CREATE_SRC).toMatch(/userOptions\s*:\s*function/);
        // Fallback entry must be injected when form.user_id is missing from the list.
        expect(CREATE_SRC).toContain('hors liste');
    });

    it('list fetches surface a FR toast on failure (no silent catch-less dispatch)', () => {
        const mountedStart = CREATE_SRC.indexOf('mounted()');
        const mountedBody = CREATE_SRC.slice(mountedStart, mountedStart + 1500);
        const catches = mountedBody.match(/\.catch\(/g) ?? [];
        expect(catches.length).toBeGreaterThanOrEqual(2);
        expect(mountedBody).toContain('alertService.error');
    });
});

describe('settings FR labels (Q-6/F1)', () => {
    it('label.ios_app_link is clean French', () => {
        expect(fr.label.ios_app_link).toBe('Lien application iOS');
    });

    it('label.android_app_link stays clean French (regression hold)', () => {
        expect(fr.label.android_app_link).toBe('Lien application Android');
    });
});
