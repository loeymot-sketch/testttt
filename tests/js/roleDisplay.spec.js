/**
 * [Wave 5 — i18n/labels] Unit test for the roleDisplay helper.
 *
 * Verifies the EN Spatie role name → fr.json label.* key mapping and the
 * raw-name / empty fallbacks. Uses an identity translator `t = (k) => k`
 * so the assertions check the i18n KEY without needing a vue-i18n instance.
 *
 * Run:
 *   npx vitest run tests/js/roleDisplay.spec.js
 *
 * [Supervisor heal 2026-06-08] Moved from tests/Unit/RoleDisplayHelperTest.js
 * (orphan: matched neither Vitest tests/js/**\/*.spec.js nor PHPUnit *Test.php)
 * into the Vitest-collected path so it actually runs. Logic unchanged.
 */
import { describe, it, expect } from "vitest";
import { roleDisplay, ROLE_LABEL_KEYS } from "../../resources/js/helpers/roleDisplay";

const t = (key) => key; // identity translator → returns the i18n key

describe("roleDisplay", () => {
    it("maps the four canonical staff roles to their FR label keys", () => {
        expect(roleDisplay("Admin", t)).toBe("label.admin");
        expect(roleDisplay("Branch Manager", t)).toBe("label.branch_manager");
        expect(roleDisplay("Chef", t)).toBe("label.chef");
        expect(roleDisplay("POS Operator", t)).toBe("label.pos_operator");
    });

    it("maps the additional known roles", () => {
        expect(roleDisplay("Customer", t)).toBe("label.customer");
        expect(roleDisplay("Delivery Boy", t)).toBe("label.delivery_boy");
        expect(roleDisplay("Waiter", t)).toBe("label.waiter");
    });

    it("falls back to the raw name for unknown roles", () => {
        expect(roleDisplay("Stuff", t)).toBe("Stuff");
        expect(roleDisplay("Some Future Role", t)).toBe("Some Future Role");
    });

    it("returns empty string for null / undefined / empty input", () => {
        expect(roleDisplay(null, t)).toBe("");
        expect(roleDisplay(undefined, t)).toBe("");
        expect(roleDisplay("", t)).toBe("");
    });

    it("falls back to the raw name when no translator is provided", () => {
        expect(roleDisplay("Admin")).toBe("Admin");
    });

    it("keys in ROLE_LABEL_KEYS are all under the label.* namespace", () => {
        Object.values(ROLE_LABEL_KEYS).forEach((key) => {
            expect(key.startsWith("label.")).toBe(true);
        });
    });
});
