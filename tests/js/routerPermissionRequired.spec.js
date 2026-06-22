import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// [CV1-WIZARD-COMPOSABLE-001 T-WC-PERM-01]
// Sentinelle source-level : vérifie que le guard global router délègue
// désormais à `handlePermissionDenied` (toast clair + redirect contextuel)
// au lieu de pousser un redirect aveugle vers `route.exception`, et que la
// clé i18n `message.permission_required` existe pour les 5 locales du dépôt.
describe('router permission_required guard (T-WC-PERM-01)', () => {
    const routerSource = readFileSync(
        resolve(process.cwd(), 'resources/js/router/index.js'),
        'utf8',
    );

    it('imports alertService and the i18n instance for the toast pipeline', () => {
        expect(routerSource).toContain('import alertService from "../services/alertService"');
        expect(routerSource).toContain("import i18n, { ensureKioskLocale } from '../i18n'");
    });

    it('declares handlePermissionDenied with the toast + dashboard fallback', () => {
        expect(routerSource).toContain('const handlePermissionDenied = (to, next)');
        expect(routerSource).toContain("i18n.global.t('message.permission_required'");
        expect(routerSource).toContain('alertService.error(message)');
        expect(routerSource).toContain("return next({ name: 'admin.dashboard' })");
    });

    it('routes meta.access === false through handlePermissionDenied (no blind route.exception)', () => {
        expect(routerSource).toContain('return handlePermissionDenied(to, next);');
        // Le legacy redirect aveugle vers la page 403 doit avoir disparu du
        // bloc `if (to.meta.access === false)`.
        expect(routerSource).not.toMatch(
            /if \(to\.meta\.access === false\) \{\s*\n\s*next\(\{\s*\n\s*name: "route\.exception"/,
        );
    });

    it('redirects back to admin.item.show when an item id is in scope', () => {
        expect(routerSource).toContain(
            "return next({ name: 'admin.item.show', params: { id: to.params.id } });",
        );
    });

    it.each([
        ['fr', 'Permission requise pour accéder à cette page : {permission}'],
        ['en', 'Permission required to access this page: {permission}'],
        ['ar', 'الإذن المطلوب للوصول إلى هذه الصفحة: {permission}'],
        ['de', 'Erforderliche Berechtigung für den Zugriff auf diese Seite: {permission}'],
        ['bn', 'এই পৃষ্ঠায় প্রবেশ করতে অনুমতি প্রয়োজন: {permission}'],
    ])('exposes message.permission_required for locale %s', (locale, expected) => {
        const messages = JSON.parse(
            readFileSync(
                resolve(process.cwd(), `resources/js/languages/${locale}.json`),
                'utf8',
            ),
        );
        expect(messages.message.permission_required).toBe(expected);
    });
});
