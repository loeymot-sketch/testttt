import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * CV1-WIZARD-COMPOSABLE-001 — T-WC-LIST-01 + T-WC-CREATE-URL-01 (combo)
 *
 * Source-level sentinel for two admin UX gaps surfaced by audit A.3:
 *   #4 — no per-row "Configure wizard" entry point in the items list
 *        (was: only view / edit / duplicate / delete in the actions cell).
 *   #3 — no dedicated /admin/items/create deep-link (only the list ''
 *        and show/:id); creating an item required clicking a button on
 *        the list, which is not bookmarkable / share-able.
 *
 * Approach: static source-code contract (matches productComposerSummary.spec.js
 * precedent). Mounting ItemListComponent would drag in 6+ Vuex modules
 * (item, itemCategory, tax, frontendLanguage, ...), axios, AvailabilityToggle,
 * SmIcon* wrappers, ItemCreateComponent, and the LoadingComponent stack. The
 * mission spec explicitly tolerates "assertions allégées" when full mount is
 * too heavy, and a source assertion is the right fit for a pure UX-affordance
 * + route sentinel.
 *
 * i18n: label.configure_wizard and label.wizard_configured are present in
 * resources/js/languages/{fr,en,ar,de,bn}.json (committed in
 * 83efc3940 by T-WC-I18N-JSON-FOLLOWUP). The button title resolves natively;
 * the wizard_configured key is parked for a future cycle that adds the badge
 * once SimpleItemResource exposes composer_profile.
 */

const itemListPath = resolve(
    process.cwd(),
    'resources/js/components/admin/items/ItemListComponent.vue',
);
const itemRoutesPath = resolve(
    process.cwd(),
    'resources/js/router/modules/itemRoutes.js',
);

const readSource = (path) => readFileSync(path, 'utf8');

describe('ItemListComponent — wizard button (T-WC-LIST-01)', () => {
    it('exposes a configure-wizard button per item row pointing to admin.items.composer', () => {
        const source = readSource(itemListPath);

        // Stable selector for E2E + sentinels (matches the duplicate / delete / view
        // pattern used elsewhere in the same actions cell).
        expect(source).toContain('data-testid="item-list-configure-wizard-button"');

        // The router-link `to` resolves the named route admin.items.composer with
        // the row item's id — same target as ProductComposerSummaryComponent.
        expect(source).toContain("name: 'admin.items.composer'");
        expect(source).toMatch(
            new RegExp("name:\\s*'admin\\.items\\.composer'\\s*,\\s*params:\\s*\\{\\s*id:\\s*item\\.id\\s*\\}"),
        );

        // [ONB 2026-08-28] Cette assertion se disait « visual sentinel » et epinglait
        // `lab-cog` — une classe que la fonte d'icones NE DEFINIT PAS. Le bouton se
        // rendait donc VIDE, sans pictogramme, et ce banc garantissait que ca continue.
        // Verifie a l'ecran : c'est une capture qui l'a montre, pas un test.
        //
        // On garde l'intention — un bouton de configuration porte une icone — mais on
        // exige desormais une icone qui EXISTE. Sinon la sentinelle atteste la presence
        // d'une classe, pas celle d'un pictogramme.
        const fonte = readSource(
            resolve(process.cwd(), 'public/themes/default/fonts/lab/lab.css'),
        );

        const iconeDuBouton = 'lab-settings';

        expect(source).toContain(iconeDuBouton);
        expect(
            fonte,
            `${iconeDuBouton} doit etre definie dans la fonte, sinon le bouton se rend vide`,
        ).toContain(`.${iconeDuBouton}:before`);

        expect(
            source.includes('lab-cog'),
            'lab-cog est revenue : elle n\'existe pas dans la fonte et le bouton '
            + 'redeviendrait vide.',
        ).toBe(false);

        // Anchor the button to the actions block: the per-row wrapper span
        // immediately precedes the data-testid for the new button. The proximity
        // window covers the router-link opening tag (v-if + :to + class + :title
        // + :aria-label + data-testid) which is ~600 chars wide once formatted.
        const wrapperIdx = source.indexOf('admin-item-configure-wizard-');
        const buttonIdx = source.indexOf('item-list-configure-wizard-button');
        expect(wrapperIdx).toBeGreaterThan(-1);
        expect(buttonIdx).toBeGreaterThan(wrapperIdx);
        expect(buttonIdx - wrapperIdx).toBeLessThan(800);
    });

    it('uses i18n key label.configure_wizard for the button title (badge deferred — composer_profile not in SimpleItemResource payload)', () => {
        const source = readSource(itemListPath);

        // Tooltip + aria use the same i18n key. label.configure_wizard is
        // already present in resources/js/languages/{fr,en,ar,de,bn}.json
        // (commit 83efc3940 by T-WC-I18N-JSON-FOLLOWUP) so the UI resolves
        // natively on first render.
        expect(source).toContain(":title=\"$t('label.configure_wizard')\"");
        expect(source).toContain(":aria-label=\"$t('label.configure_wizard')\"");

        // No wizard-configured badge is shipped this cycle: SimpleItemResource
        // (app/Http/Resources/SimpleItemResource.php — backing GET /api/admin/item)
        // does NOT expose composer_profile. Adding the badge against an absent
        // payload field would render dead UI on every row. Sentinel guards this
        // intentional deferral so a future cycle is not surprised.
        expect(source).not.toContain('item-list-wizard-badge');
    });

    it('opens the create drawer in mounted() when $route.query.create === "1" (T-WC-CREATE-URL-01)', () => {
        const source = readSource(itemListPath);

        // Deep-link from /admin/items/create (which beforeEnter-redirects here
        // with ?create=1) is honoured by the mounted hook.
        expect(source).toMatch(
            new RegExp("\\$route\\?\\.query\\?\\.create\\s*===\\s*'1'"),
        );

        // Drawer activation goes through the existing appService helper used by
        // SmSidebarModalCreateComponent (data-drawer="#sidebar") and by edit().
        expect(source).toContain('appService.sideDrawerShow()');

        // Permission gating prevents a no-op flicker for users without
        // items_create — the ItemCreateComponent (which renders the drawer DOM)
        // is itself gated by v-if="permissionChecker('items_create')". We anchor
        // the search at the $route.query.create check so we verify the gate IN
        // mounted(), not the unrelated v-if usage on ItemCreateComponent at the
        // top of the template.
        const queryIdx = source.indexOf("$route?.query?.create");
        const permFromQuery = source.indexOf("permissionChecker('items_create')", queryIdx);
        const drawerFromPerm = source.indexOf('appService.sideDrawerShow()', permFromQuery);
        expect(queryIdx).toBeGreaterThan(-1);
        expect(permFromQuery).toBeGreaterThan(queryIdx);
        expect(drawerFromPerm).toBeGreaterThan(permFromQuery);
        expect(drawerFromPerm - queryIdx).toBeLessThan(300);
    });
});

describe('itemRoutes — admin.items.create (T-WC-CREATE-URL-01)', () => {
    it('declares admin.items.create with create path, items_create permission, and beforeEnter redirect to ?create=1', () => {
        const source = readSource(itemRoutesPath);

        expect(source).toContain("path: 'create'");
        expect(source).toContain("name: 'admin.items.create'");

        // Permission key matches the items_create middleware gate on the
        // backend ItemController::store / duplicate / import.
        expect(source).toContain("permissionUrl: 'items_create'");

        // beforeEnter redirects to the list with create=1 so the drawer opens
        // via the ItemListComponent.mounted() hook — keeps the URL bookmarkable
        // without forcing a separate page component.
        const beforeIdx = source.indexOf('beforeEnter');
        const listNameIdx = source.indexOf("name: 'admin.items.list'", beforeIdx);
        const createQueryIdx = source.indexOf("create: '1'", beforeIdx);
        expect(beforeIdx).toBeGreaterThan(-1);
        expect(listNameIdx).toBeGreaterThan(beforeIdx);
        expect(createQueryIdx).toBeGreaterThan(beforeIdx);
        expect(createQueryIdx - beforeIdx).toBeLessThan(300);
    });
});
