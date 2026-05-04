import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

describe("CatalogStudio add category UX", () => {
    const sfcPath = resolve(process.cwd(), "resources/js/components/admin/items/CatalogStudioComponent.vue");
    const sfc = readFileSync(sfcPath, "utf8");

    it("routes the add category button through the UX handler", () => {
        expect(sfc).toMatch(/data-testid="catalog-studio-add-category"[\s\S]*@click="onAddCategoryClick"/);
        expect(sfc).not.toMatch(/@click="showCategoryQuickForm = !showCategoryQuickForm"/);
    });

    it("scrolls the category quick form into view after opening", () => {
        expect(sfc).toMatch(/onAddCategoryClick\(\)[\s\S]*showCategoryQuickForm[\s\S]*\$nextTick/);
        expect(sfc).toMatch(/onAddCategoryClick\(\)[\s\S]*categoryQuickForm[\s\S]*scrollIntoView/);
    });

    it("focuses the category name input after opening", () => {
        expect(sfc).toContain('ref="categoryQuickForm"');
        expect(sfc).toContain('ref="categoryQuickFormNameInput"');
        expect(sfc).toMatch(/onAddCategoryClick\(\)[\s\S]*categoryQuickFormNameInput[\s\S]*focus\(\)/);
    });
});
