import fs from 'fs';
import path from 'path';
import { createRequire } from 'module';
import { describe, expect, it } from 'vitest';

const require = createRequire(import.meta.url);
const { isDedicatedE2EWriteScope } = require('../e2e/helpers/kiosk-order.js');

const helperPath = 'tests/e2e/helpers/kiosk-order.js';
const mutatingHarnesses = [
    helperPath,
    'tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js',
    'tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js',
];
const callers = [
    'tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-D.spec.js',
    'tests/e2e/rush-sync-flow.spec.js',
    'tests/e2e/menu-v2-kiosk-final.spec.js',
    'tests/e2e/test-e2e-goal-4chantiers-wave-D.spec.js',
    'tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-F.spec.js',
    'tests/e2e/zone6-sync-resilience.spec.js',
    'tests/e2e/goal-pageby-borne-2026-05-18.spec.js',
    'tests/e2e/zone3-kiosk-to-kds.spec.js',
    'tests/e2e/wave-p-kiosk-2026-05-20.spec.js',
    'tests/e2e/test-e2e-abuse-P-idempotency.spec.js',
    'tests/e2e/wave-p-cross-system-2026-05-20.spec.js',
];

/**
 * [REPLAN_8 2026-08-24] Extraction par APPARIEMENT D'ACCOLADES, plus par index approximatif.
 *
 * L'ancienne découpe allait de `function X` jusqu'à la mention suivante d'un autre nom : la
 * tranche de `getKioskApiToken` avalait `resolveBranchId` ET tout le JSDoc de `placeKioskOrder`.
 * Conséquence : `toContain('assertCurrentE2EWriteScope()')` restait vert si la garde était
 * déplacée dans une AUTRE fonction. Un test qui ne peut pas échouer pour la bonne raison ne
 * prouve rien — c'est précisément ce que ce cycle traque.
 */
function functionBody(source, functionName) {
    const start = source.indexOf(`function ${functionName}`);
    expect(start, `${functionName} doit exister`).toBeGreaterThanOrEqual(0);
    const open = source.indexOf('{', start);
    expect(open, `corps de ${functionName} introuvable`).toBeGreaterThan(start);
    let depth = 0;
    for (let i = open; i < source.length; i += 1) {
        if (source[i] === '{') depth += 1;
        else if (source[i] === '}') {
            depth -= 1;
            if (depth === 0) return source.slice(start, i + 1);
        }
    }
    throw new Error(`accolade fermante de ${functionName} introuvable`);
}

/**
 * Position de la garde RELATIVE à la première mutation. Une garde présente mais placée après la
 * création d'un jeton, d'une commande ou d'une transition ne garde rien.
 */
function gardeAvantPremiereMutation(corps, nomsGarde, motifsMutation) {
    const posGarde = nomsGarde
        .map((n) => corps.indexOf(n))
        .filter((i) => i >= 0)
        .sort((a, b) => a - b)[0];
    const posMutation = motifsMutation
        .map((m) => corps.search(m))
        .filter((i) => i >= 0)
        .sort((a, b) => a - b)[0];
    return { posGarde, posMutation };
}

describe('Helper E2E borne — cleanup fiscalement sûr', () => {
    it('utilise la transition métier, refuse les bases non dédiées et ne supprime aucune preuve', () => {
        const source = fs.readFileSync(path.resolve(helperPath), 'utf8');
        const cleanupBody = source.slice(
            source.indexOf('function cleanupKioskAuditOrders'),
            source.indexOf('\nmodule.exports ='),
        );

        expect(cleanupBody).toContain('assertDedicatedE2EWriteScope(scope.database)');
        expect(cleanupBody).toContain('OrderService::class)->changeStatus');
        expect(cleanupBody).toContain('ValidStatusTransition');
        expect(cleanupBody).toContain("->where('branch_id', $branchId)");
        expect(cleanupBody).toContain("'branch_id' => $branchId");
        expect(cleanupBody).toContain('Canonical kiosk cleanup failed');
        expect(cleanupBody).toContain('remaining_active_order_ids');
        expect(cleanupBody).not.toContain('->delete(');
        expect(cleanupBody).not.toContain('Cache::flush');
        expect(cleanupBody).not.toMatch(/fiscal_sequence_no[^;\n]*null/i);
        expect(cleanupBody).not.toMatch(/DB::table\(['"]orders['"]\)[^;]*->update/i);
    });

    it('place la garde AVANT la première mutation, pas seulement quelque part dans le fichier', () => {
        const source = fs.readFileSync(path.resolve(helperPath), 'utf8');
        const cas = [
            {
                fonction: 'getKioskApiToken',
                mutations: [/axios\.post\(/, /http\.request\(/, /auth\/kiosk-login/],
            },
            {
                fonction: 'placeKioskOrder',
                mutations: [/window\.axios\.post\(/, /frontend\/order/],
            },
            {
                fonction: 'cleanupKioskAuditOrders',
                mutations: [/changeStatus/, /ValidStatusTransition/],
            },
        ];

        for (const { fonction, mutations } of cas) {
            const corps = functionBody(source, fonction);
            const { posGarde, posMutation } = gardeAvantPremiereMutation(
                corps,
                ['assertCurrentE2EWriteScope(', 'assertDedicatedE2EWriteScope('],
                mutations,
            );
            expect(posGarde, `aucune garde de base dans ${fonction}`).toBeGreaterThanOrEqual(0);
            expect(posMutation, `aucune mutation reconnue dans ${fonction}`).toBeGreaterThanOrEqual(0);
            expect(
                posGarde,
                `dans ${fonction}, la garde de base dédiée apparaît APRÈS la première mutation `
                + `(garde à l'offset ${posGarde}, mutation à ${posMutation}) : elle ne garde rien.`,
            ).toBeLessThan(posMutation);
        }
    });

    it('le corps extrait est bien borné à la fonction visée', () => {
        // Garde-fou du parseur lui-même : si l'appariement d'accolades dérive, les tests d'ordre
        // ci-dessus redeviendraient des tests de présence.
        const source = fs.readFileSync(path.resolve(helperPath), 'utf8');
        const corps = functionBody(source, 'getKioskApiToken');
        expect(corps).toContain('kiosk-login');
        expect(corps, 'la tranche déborde sur placeKioskOrder').not.toContain('function placeKioskOrder');
        expect(corps, 'la tranche déborde sur le module.exports').not.toContain('module.exports');
    });

    it('exige simultanément opt-in explicite et identité de base dédiée', () => {
        expect(isDedicatedE2EWriteScope('foodking_e2e', '1')).toBe(true);
        expect(isDedicatedE2EWriteScope('foodking_test', '1')).toBe(true);
        expect(isDedicatedE2EWriteScope('foodking_playwright', '1')).toBe(true);
        expect(isDedicatedE2EWriteScope('foodking_e2e', '0')).toBe(false);
        expect(isDedicatedE2EWriteScope('foodking_e2e', undefined)).toBe(false);
        expect(isDedicatedE2EWriteScope('foodking', '1')).toBe(false);
    });

    it('branche les trois harnesses mutateurs sur la garde double et ignore APP_ENV', () => {
        for (const harness of mutatingHarnesses) {
            const source = fs.readFileSync(path.resolve(harness), 'utf8');
            expect(source, harness).toContain('assertDedicatedE2EWriteScope');
            expect(source, harness).not.toMatch(/environment\s*===\s*['"]testing['"]/);
        }
        const helperSource = fs.readFileSync(path.resolve(helperPath), 'utf8');
        expect(helperSource).toContain("process.env.FOODKING_E2E_DEDICATED_DB");
        expect(helperSource).toContain("explicitOptIn === '1'");
        expect(helperSource).toContain("/(test|e2e|playwright)/i");
        expect(functionBody(helperSource, 'getKioskApiToken', 'placeKioskOrder')).toContain('assertCurrentE2EWriteScope()');
        expect(functionBody(helperSource, 'placeKioskOrder', 'placeKioskOrderTwice')).toContain('assertCurrentE2EWriteScope()');
        expect(functionBody(helperSource, 'cleanupKioskAuditOrders')).toContain('assertDedicatedE2EWriteScope(scope.database)');
    });

    it('garde le parcours multi-produits branché sur les APIs canoniques sans muter utilisateur ou machine', () => {
        const source = fs.readFileSync(
            path.resolve('tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js'),
            'utf8',
        );
        const identityBody = functionBody(
            source,
            'resolveExistingKioskIdentity',
            'collectCounterOrderThroughPosApi',
        );
        expect(source).toContain('resolveExistingKioskIdentity');
        expect(source).toContain('cancelActiveSyntheticOrders');
        expect(source).toContain('ACTIVE_SYNTHETIC_ORDER_STATUSES');
        expect(source).toContain('orderStatusEnum.OUT_FOR_DELIVERY');
        expect(source).toContain('deactivateKioskFixtures(branchId, fixture)');
        expect(source.match(/Cache::forget\(\$cacheKey\)/g)).toHaveLength(2);
        expect(source).toContain("$cacheKey = 'kiosk.menu.branch.' . $branchId");
        expect(source).toContain('cache_present_after_invalidation');
        expect(source).toContain('other_branch_cache');
        expect(identityBody).toContain('Illuminate\\\\Support\\\\Facades\\\\Cache::get');
        expect(identityBody).toContain('Illuminate\\\\Support\\\\Facades\\\\Cache::has');
        expect(identityBody).toContain('$model->getAttributes()');
        expect(identityBody).toContain('->only($fields)');
        expect(identityBody).toContain("'id', 'user_id', 'branch_id', 'username', 'machine_id', 'password', 'status'");
        expect(identityBody).toContain("'id', 'branch_id', 'username', 'email', 'password', 'status', 'is_guest'");
        expect(identityBody).not.toContain("'is_login'");
        expect(identityBody).not.toContain("'created_at'");
        expect(identityBody).not.toContain("'updated_at'");
        expect(identityBody).toContain("'password_fingerprint'");
        expect(identityBody).toContain("hash('sha256', (string) $snapshot['password'])");
        expect(identityBody).not.toMatch(/['"]password['"]\s*=>/);
        expect(identityBody).not.toMatch(/\(string\)\s*\$(?:machine|user)->password/);
        expect(source).toContain('.toEqual(kioskIdentityBefore.machine)');
        expect(source).toContain('.toEqual(kioskIdentityBefore.user)');
        expect(source).toContain('.toEqual(kioskIdentityBefore.other_branch_cache)');
        expect(source).not.toContain('Cache::flush()');
        expect(source).not.toContain('forceFill(');
        expect(source).not.toMatch(/KioskMachine::query\(\)->updateOrCreate/);
        expect(source).not.toMatch(/DB::table\(['"]orders['"]\)[^;]*->update/i);
        expect(source).not.toMatch(/update\s*\(\s*\[[^\]]*fiscal_sequence_no/i);
    });

    for (const caller of callers) {
        it(path.basename(caller) + ' ne dépend pas des anciens compteurs de suppression', () => {
            const source = fs.readFileSync(path.resolve(caller), 'utf8');
            expect(source).toContain('cleanupKioskAuditOrders');
            expect(source).not.toMatch(
                /cleanupKioskAuditOrders\([^)]*\)\s*\.\s*(orders|order_items|domain_events|audit_logs|transactions)/,
            );
            expect(source).not.toMatch(
                /\{[^}]*\b(orders|order_items|domain_events|audit_logs|transactions)\b[^}]*\}\s*=\s*cleanupKioskAuditOrders/,
            );
        });
    }
});
