/**
 * [W5-PERF §5.2 2026-07-06] Contrat source + comportement : l'ajout panier POS
 * ne doit PLUS déclencher un refetch complet `/api/admin/item`.
 *
 * Mesuré AVANT (verdicts.md §5.2 + protocole w5) : `onProductAddedReturnToCategories`
 * → `allCategory()` → `itemList()` = 1 GET /api/admin/item COMPLET + re-render
 * grille PAR ARTICLE ajouté, alors que le hub post-ajout n'affiche que les tuiles
 * catégories (posCategory/lists) et que chaque drill-in de catégorie refetch déjà.
 *
 * Contrat verrouillé ici :
 *   1. le retour au hub reste (reset name + item_category_id) — owner mandate
 *      POS-CATEGORY-FIRST intact ;
 *   2. AUCUN appel `allCategory()` (refetch inconditionnel) dans le handler ;
 *   3. refetch de resync AU PLUS 1/60 s (throttle _lastPostAddCatalogRefetchAt) ;
 *   4. les événements stock temps-réel refetchent toujours (CatalogChanged /
 *      ItemAvailabilityChanged type=full inchangés) — la dispo affichée ne
 *      régresse pas.
 */
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const posComponentPath = resolve(
    process.cwd(),
    'resources/js/components/admin/pos/PosComponent.vue',
);
const source = readFileSync(posComponentPath, 'utf8');

/** Extrait le corps de onProductAddedReturnToCategories (jusqu'à la méthode suivante). */
function extractHandler() {
    const match = source.match(
        /onProductAddedReturnToCategories:\s*function \(\) \{([\s\S]*?)\n {8}\},/,
    );
    expect(match, 'onProductAddedReturnToCategories introuvable dans PosComponent.vue').toBeTruthy();
    return match[1];
}

describe('POS — pas de refetch /api/admin/item à chaque ajout panier (contrat source)', () => {
    it('le handler post-ajout ne rappelle plus allCategory() (refetch inconditionnel)', () => {
        const body = extractHandler();
        expect(body).not.toMatch(/this\.allCategory\(\)/);
    });

    it('le retour au hub catégories est préservé (reset name + item_category_id)', () => {
        const body = extractHandler();
        expect(body).toMatch(/props\.search\.name\s*=\s*""/);
        expect(body).toMatch(/props\.search\.item_category_id\s*=\s*""/);
    });

    it('un refetch de resync reste possible mais throttlé à 1/60 s max', () => {
        const body = extractHandler();
        expect(body).toMatch(/_lastPostAddCatalogRefetchAt/);
        expect(body).toMatch(/60000/);
        expect(body).toMatch(/this\.itemList\(1,\s*\{\s*overlay:\s*false\s*\}\)/);
    });

    it('les refetchs poussés par le stock réel restent câblés (CatalogChanged + availability full)', () => {
        // _onCatalogChanged → itemList (push temps réel catalogue)
        expect(source).toMatch(/_onCatalogChanged\(event\)\s*\{[\s\S]*?this\.itemList\(1,\s*\{\s*overlay:\s*false\s*\}\)/);
        // les handlers Echo ItemAvailabilityChanged / CatalogChanged sont toujours abonnés
        expect(source).toMatch(/broadcastAs:\s*'ItemAvailabilityChanged'/);
        expect(source).toMatch(/broadcastAs:\s*'CatalogChanged'/);
    });
});

describe('POS — comportement du throttle post-ajout (exécution réelle du handler extrait)', () => {
    function buildContext() {
        const calls = [];
        return {
            props: { search: { name: 'x', item_category_id: '7' } },
            itemList(page, opts) { calls.push({ page, opts }); },
            calls,
        };
    }

    function runHandler(ctx) {
        const body = extractHandler();
        // eslint-disable-next-line no-new-func
        const fn = new Function(body);
        fn.call(ctx);
    }

    it('1er ajout : retour hub + un refetch de resync', () => {
        const ctx = buildContext();
        runHandler(ctx);
        expect(ctx.props.search.item_category_id).toBe('');
        expect(ctx.props.search.name).toBe('');
        expect(ctx.calls.length).toBe(1);
    });

    it('ajouts suivants dans la minute : retour hub SANS aucun refetch', () => {
        const ctx = buildContext();
        runHandler(ctx); // amorce le timestamp
        for (let i = 0; i < 9; i++) {
            ctx.props.search.item_category_id = '7';
            runHandler(ctx);
        }
        expect(ctx.calls.length).toBe(1); // 10 ajouts → 1 seul GET
        expect(ctx.props.search.item_category_id).toBe('');
    });

    it('après 60 s, le refetch de resync repart (données jamais > 60 s de retard)', () => {
        const ctx = buildContext();
        runHandler(ctx);
        ctx._lastPostAddCatalogRefetchAt = Date.now() - 60001;
        runHandler(ctx);
        expect(ctx.calls.length).toBe(2);
    });
});

describe('POS — bonus W5 : GET /admin/pos/walk-in-customer dédupliqué au mount (single-flight)', () => {
    it('ensureWalkInCustomer partage la promesse en vol (_ensureWalkInInflight)', () => {
        const match = source.match(/async ensureWalkInCustomer\(\) \{([\s\S]*?)\n {8}\},/);
        expect(match, 'ensureWalkInCustomer introuvable').toBeTruthy();
        const body = match[1];
        expect(body).toMatch(/if \(this\._ensureWalkInInflight\) return this\._ensureWalkInInflight;/);
        expect(body).toMatch(/_ensureWalkInCustomerInner\(\)/);
        expect(body).toMatch(/\.finally\(/); // la promesse partagée se libère toujours
    });

    it("la logique d'origine vit dans _ensureWalkInCustomerInner (GET puis POST fallback)", () => {
        const match = source.match(/async _ensureWalkInCustomerInner\(\) \{([\s\S]*?)\n {8}\},/);
        expect(match, '_ensureWalkInCustomerInner introuvable').toBeTruthy();
        expect(match[1]).toMatch(/\/admin\/pos\/walk-in-customer/);
    });
});
