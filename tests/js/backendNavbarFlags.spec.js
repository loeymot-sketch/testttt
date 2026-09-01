import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

describe("backend navbar language flags", () => {
    const src = readFileSync(
        resolve(__dirname, "..", "..", "resources/js/components/layouts/backend/BackendNavbarComponent.vue"),
        "utf8",
    );

    it("serves public language PNGs instead of broken /storage/1/english.png", () => {
        expect(src).toContain("languageFlagSrc");
        expect(src).toContain("/images/language/");
        expect(src).toContain("english.png");
        expect(src).not.toContain(":src=\"language.image\"");
    });
});
