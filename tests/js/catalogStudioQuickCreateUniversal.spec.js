import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

describe("CatalogStudio universal quick product create", () => {
    const sfcPath = resolve(process.cwd(), "resources/js/components/admin/items/CatalogStudioComponent.vue");
    const sfc = readFileSync(sfcPath, "utf8");

    it("keeps the add product button enabled on all categories view", () => {
        const buttonMatch = sfc.match(/<button[\s\S]*data-testid="catalog-studio-add-product"[\s\S]*?<\/button>/);
        expect(buttonMatch?.[0]).toBeDefined();
        expect(buttonMatch[0]).not.toContain(':disabled="!selectedCategoryId"');
        expect(buttonMatch[0]).toContain('@click="onAddProductClick"');
    });

    it("shows a required category dropdown when no category is selected", () => {
        expect(sfc).toContain('v-if="!selectedCategoryId"');
        expect(sfc).toContain('data-testid="catalog-studio-quick-product-category"');
        expect(sfc).toContain('v-model.number="productQuickForm.categoryId"');
        expect(sfc).toMatch(/<option v-for="cat in categories"[\s\S]*:value="cat\.id"/);
    });

    it("stores a dropdown category id in the product quick form state", () => {
        expect(sfc).toMatch(/productQuickForm:\s*\{[\s\S]*categoryId:\s*null/);
        expect(sfc).toMatch(/onAddProductClick\(\)[\s\S]*productQuickForm\.categoryId = this\.selectedCategoryId \?\? null/);
    });

    it("uses the dropdown category before falling back to selected category", () => {
        expect(sfc).toContain("const categoryId = this.productQuickForm.categoryId || this.selectedCategoryId");
        expect(sfc).toContain('fd.append("item_category_id", String(categoryId))');
    });

    it("rejects product creation when no category exists at all", () => {
        expect(sfc).toMatch(/createProduct\(\)\s*\{[\s\S]*const categoryId = this\.productQuickForm\.categoryId \|\| this\.selectedCategoryId/);
        expect(sfc).toMatch(/if \(!categoryId\) \{[\s\S]*alertService\.error\(this\.\$t\("studio\.select_category_first"\)\)/);
    });

    it("points the stock dashboard link to the stock rupture route", () => {
        expect(sfc).toContain('data-testid="catalog-studio-stock-link"');
        expect(sfc).toContain(':to="{ name: \'admin.stock.rupture\' }"');
    });
});
