import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

describe("catalog studio routing", () => {
    const root = resolve(__dirname, "..", "..");
    const itemRoutesPath = resolve(root, "resources/js/router/modules/itemRoutes.js");
    const backendMenuPath = resolve(root, "resources/js/components/layouts/backend/BackendMenuComponent.vue");
    const studioPath = resolve(root, "resources/js/components/admin/items/CatalogStudioComponent.vue");

    it("registers the admin.items.studio route", () => {
        const source = readFileSync(itemRoutesPath, "utf8");
        expect(source).toContain("CatalogStudioComponent");
        expect(source).toContain("path: 'studio'");
        expect(source).toContain("name: 'admin.items.studio'");
    });

    it("points catalog menu virtual child to studio", () => {
        const source = readFileSync(backendMenuPath, "utf8");
        expect(source).toContain("url: 'items/studio'");
    });

    it("exposes core unified controls in studio page", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("data-testid=\"catalog-studio-page\"");
        expect(source).toContain("data-testid=\"catalog-studio-products-grid\"");
        expect(source).toContain("admin.items.composer");
    });

    it("[P0] sends required `order` field on product create", () => {
        // ItemRequest.php declares `order` as required|numeric. Without it the backend
        // returns HTTP 422 systematically. This sentinel locks the fix in place.
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("nextItemOrder()");
        expect(source).toContain("fd.append(\"order\", String(this.nextItemOrder))");
    });

    it("[P0] resets vuex temp state before quick create", () => {
        // The store's save() dispatches PUT when temp.isEditing is true. After any prior
        // edit on another screen the temp state may still be dirty, which would silently
        // overwrite a different record. We must dispatch reset() before save().
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("dispatch(\"itemCategory/reset\")");
        expect(source).toContain("dispatch(\"item/reset\")");
    });

    it("[P0] does not falsely block creation when no tax is configured", () => {
        // tax_id is nullable per ItemRequest.php. The previous version aborted creation
        // when no tax existed, which is wrong.
        const source = readFileSync(studioPath, "utf8");
        expect(source).not.toMatch(/Aucune taxe disponible/);
        expect(source).not.toMatch(/if\s*\(\s*!\s*this\.defaultTaxId\s*\)\s*\{[^}]*alertService\.error/);
    });

    it("[P0] surfaces backend validation errors to the operator", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("err?.response?.data?.errors");
        expect(source).toContain("err?.response?.data?.message");
    });

    it("[P1] inline edit + delete actions exist for categories and products", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("destroyCategory(category)");
        expect(source).toContain("destroyItem(item)");
        expect(source).toContain("editCategory(category)");
        expect(source).toContain("appService.destroyConfirmation()");
    });

    it("[P1] action buttons are gated by permissionChecker", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("permissionChecker(\"items_create\")");
        expect(source).toContain("permissionChecker(\"items_edit\")");
        expect(source).toContain("permissionChecker(\"items_delete\")");
        expect(source).toContain("v-if=\"canCreateItem\"");
        expect(source).toContain("v-if=\"canDeleteItem\"");
    });

    it("[P1] every quick-action button exposes a stable data-testid", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("data-testid=\"catalog-studio-add-category\"");
        expect(source).toContain("data-testid=\"catalog-studio-add-product\"");
        expect(source).toMatch(/catalog-studio-product-delete-/);
        expect(source).toMatch(/catalog-studio-category-delete-/);
    });

    it("[P1] opens product composer in an embedded drawer", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("openComposerDrawer(item)");
        expect(source).toContain("data-testid=\"catalog-studio-composer-overlay\"");
        expect(source).toContain("data-testid=\"catalog-studio-composer-frame\"");
        expect(source).toContain("catalog-studio-composer-open-full");
    });

    it("[P1] exposes inline stock controls on each product card", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("AvailabilityToggleComponent");
        expect(source).toContain("data-testid=\"catalog-studio-stock-inline\"");
        expect(source).toContain("$t(\"studio.stock_parallel_title\")");
    });

    it("[P2] quick create supports optional image upload", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).toContain("catalog-studio-product-image-upload");
        expect(source).toContain("changeQuickProductImage");
        expect(source).toContain("fd.append(\"image\", this.productQuickForm.image)");
    });

    it("[P1] all visible strings go through $t() (no hardcoded French/English)", () => {
        const source = readFileSync(studioPath, "utf8");
        expect(source).not.toMatch(/Catalogue centralis/i);
        expect(source).not.toMatch(/Toutes les categories/i);
        expect(source).not.toMatch(/Selectionnez une categorie/i);
        expect(source).not.toMatch(/Stock \/ disponibilite/i);
        expect(source).not.toMatch(/Parametres categories avances/i);
        expect(source).not.toMatch(/Ajout rapide produit/);
        expect(source).toContain("$t(\"studio.eyebrow\")");
        expect(source).toContain("$t(\"studio.title\")");
        expect(source).toContain("$t(\"studio.all_categories\")");
        expect(source).toContain("$t(\"studio.stock_link\")");
        expect(source).toContain("$t(\"studio.advanced_settings\")");
    });

    it("[P1] studio i18n keys are present in all 5 languages", () => {
        const langs = ["fr", "en", "de", "bn", "ar"];
        const expectedKeys = [
            "'eyebrow' =>",
            "'title' =>",
            "'subtitle' =>",
            "'all_categories' =>",
            "'products_count' =>",
            "'advanced_settings' =>",
            "'stock_link' =>",
            "'stock_parallel_title' =>",
            "'stock_parallel_hint' =>",
            "'daily_quota_hint' =>",
            "'quick_create_product' =>",
            "'select_category_first' =>",
            "'composer_drawer_eyebrow' =>",
            "'composer_drawer_title' =>",
            "'open_full_page' =>",
        ];
        for (const lang of langs) {
            const file = readFileSync(`${process.cwd()}/lang/${lang}/all.php`, "utf8");
            expect(file).toContain("'studio' => [");
            for (const key of expectedKeys) {
                expect(file, `lang/${lang}/all.php missing studio.${key}`).toContain(key);
            }
        }
    });
});
