import fs from 'fs';
import path from 'path';
import { createRequire } from 'module';
import { describe, expect, it } from 'vitest';

const require = createRequire(import.meta.url);
const {
    KIOSK_AUDIT_PREFIX,
    prefixeAuditPourSpec,
    assertPrefixeAuditValide,
} = require('../e2e/helpers/kiosk-order.js');

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-4.2.1] Isolation des préfixes d'audit E2E.
 *
 * LE DÉFAUT QUE CE TEST EMPÊCHE DE REVENIR
 * -----------------------------------------
 * Relevé le 2026-08-25 : **huit specs écrivaient leurs commandes sous le même préfixe**
 * `AUDIT-KIOSK-WAVE-E`. Chacune nettoyait ensuite par `LIKE 'AUDIT-KIOSK-WAVE-E%'`, et emportait
 * donc les commandes VIVANTES des sept autres.
 *
 * En exécution séquentielle, personne ne le voit. En parallèle, une spec voit ses lignes
 * disparaître sous elle en plein test — et l'échec ressemble à un défaut produit alors que c'est
 * une collision de harnais. C'est le pire type de faux négatif : il envoie corriger du code sain.
 *
 * LE PIÈGE SUBTIL — « distincts » NE SUFFIT PAS
 * ----------------------------------------------
 * Le nettoyage se fait par `LIKE 'préfixe%'`. Si `AUDIT-RUSH` et `AUDIT-RUSH-SYNC` coexistent,
 * ils sont bien *distincts*, mais nettoyer le premier emporte le second. La vraie propriété
 * exigée est : **aucun préfixe n'est le début d'un autre.**
 */

/**
 * AUTO-DÉCOUVERTE plutôt que liste figée.
 *
 * Une liste écrite à la main pourrit : une spec neuve qui écrit des commandes n'y serait pas, et
 * le test resterait vert en ne prouvant rien pour elle. On balaie donc le répertoire et on retient
 * toute spec qui déclare un préfixe d'audit propre.
 */
const racineE2E = path.resolve(process.cwd(), 'tests/e2e');

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

const toutesLesSpecs = listerSpecs(racineE2E);

/** Specs qui déclarent leur propre préfixe d'audit. */
const SPECS_ECRIVAINES = toutesLesSpecs
    .filter((c) => fs.readFileSync(c, 'utf8').includes('prefixeAuditPourSpec(__filename)'))
    .map((c) => path.relative(process.cwd(), c));

/** Specs qui écrivent des commandes SANS déclarer de préfixe propre — la dette restante. */
const SPECS_SANS_PREFIXE_PROPRE = toutesLesSpecs
    .filter((c) => {
        const s = fs.readFileSync(c, 'utf8');
        const ecrit = /await placeKioskOrder\(|= placeKioskOrder\(|placeKioskOrderTwice\(/.test(s);
        return ecrit && !s.includes('prefixeAuditPourSpec(__filename)');
    })
    .map((c) => path.relative(process.cwd(), c));

describe('Préfixes d’audit E2E — isolation entre specs', () => {
    it('découvre effectivement des specs à vérifier', () => {
        // Si ce compte tombe à zéro, c'est que la découverte est cassée — pas que le problème
        // est résolu. Un test qui ne trouve rien ne prouve rien.
        expect(SPECS_ECRIVAINES.length).toBeGreaterThanOrEqual(16);
    });

    it('aucune spec n’écrit plus de commandes sous le préfixe partagé', () => {
        expect(
            SPECS_SANS_PREFIXE_PROPRE,
            `Ces specs écrivent des commandes sans préfixe propre — elles retombent sur le défaut ` +
                `partagé '${KIOSK_AUDIT_PREFIX}' et se nettoieront mutuellement dès qu'on parallélisera :\n  ` +
                SPECS_SANS_PREFIXE_PROPRE.join('\n  '),
        ).toEqual([]);
    });

    it('dérive un préfixe distinct pour chaque spec', () => {
        const prefixes = SPECS_ECRIVAINES.map(prefixeAuditPourSpec);
        expect(new Set(prefixes).size).toBe(SPECS_ECRIVAINES.length);
    });

    it('aucun préfixe n’est le début d’un autre (sinon LIKE emporte le voisin)', () => {
        const prefixes = SPECS_ECRIVAINES.map(prefixeAuditPourSpec);
        const collisions = [];
        for (const a of prefixes) {
            for (const b of prefixes) {
                if (a !== b && b.startsWith(a)) collisions.push(`${a}  emporterait  ${b}`);
            }
        }
        expect(
            collisions,
            `Un nettoyage LIKE '<préfixe>%' emporterait les lignes d'une autre spec :\n  ${collisions.join('\n  ')}`,
        ).toEqual([]);
    });

    it('chaque préfixe dérivé satisfait les règles de nettoyage', () => {
        for (const s of SPECS_ECRIVAINES) {
            const p = prefixeAuditPourSpec(s);
            expect(p.length).toBeGreaterThanOrEqual(8);
            expect(/[%_\\]/.test(p)).toBe(false);
            expect(() => assertPrefixeAuditValide(p, 'test')).not.toThrow();
        }
    });

    it('la dérivation est stable : même fichier, même préfixe', () => {
        for (const s of SPECS_ECRIVAINES) {
            expect(prefixeAuditPourSpec(s)).toBe(prefixeAuditPourSpec(s));
        }
        // Le chemin ne doit pas changer le résultat — seul le nom de fichier compte.
        expect(prefixeAuditPourSpec('rush-sync-flow.spec.js'))
            .toBe(prefixeAuditPourSpec('tests/e2e/rush-sync-flow.spec.js'));
    });

    it('refuse tout préfixe dangereux pour un LIKE', () => {
        for (const mauvais of ['AUDIT%', 'AUDIT_X', 'AUDIT\\Y', 'court', '', null, undefined]) {
            expect(() => assertPrefixeAuditValide(mauvais, 'test')).toThrow();
        }
    });

    it('conserve le défaut historique pour ne casser aucun appelant existant', () => {
        // La migration des specs est incrémentale : le défaut doit rester celui d'avant.
        expect(KIOSK_AUDIT_PREFIX).toBe('AUDIT-KIOSK-WAVE-E');
    });

    it('le défaut partagé reste refusable comme préfixe propre à une spec', () => {
        // Tant qu'une spec n'a pas migré, elle écrit sous le défaut partagé : c'est connu,
        // c'est la dette. Ce test documente que le défaut N'EST PAS un préfixe isolé.
        const derives = SPECS_ECRIVAINES.map(prefixeAuditPourSpec);
        expect(derives).not.toContain(KIOSK_AUDIT_PREFIX);
    });
});
