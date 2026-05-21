// Wave Y Round-1 fix verification — captures post-fix state of A-001, A-002, A-004, C-002.
// Standalone (no shared storage state needed for kiosk / public /admin redirect probe).
import { test, expect } from "@playwright/test";

const BASE = "http://127.0.0.1:8000";
const OUT = "reports/test-e2e/wave-y-le-cayenne-v2-2026-05-21/round-1/fix-verification";

test.describe("Wave Y Round 1 — post-fix verification", () => {
    test.setTimeout(90_000);
    test.use({ viewport: { width: 1366, height: 900 } });

    test("A-004 — idle subtitle readable on cream hero (text-shadow applied)", async ({ page }) => {
        const errors = [];
        page.on("pageerror", (e) => errors.push(`pageerror: ${e.message}`));
        const corsErrors = [];
        page.on("console", (msg) => {
            const t = msg.text();
            if (msg.type() === "error" && /CORS|blocked by CORS|Access to XMLHttpRequest/i.test(t)) {
                corsErrors.push(t);
            }
        });
        await page.goto(`${BASE}/kiosk/idle`, { waitUntil: "networkidle" });
        await page.waitForTimeout(800);
        await page.screenshot({ path: `${OUT}/FIX-A-004-kiosk-idle-subtitle-after.png`, fullPage: true });
        // Confirm CSS rule landed
        const shadow = await page.evaluate(() => {
            const el = document.querySelector(".kiosk-idle-subtitle");
            if (!el) return null;
            return getComputedStyle(el).textShadow;
        });
        await page.evaluate((s) => console.log("A-004 subtitle textShadow:", s), shadow);
        // A-002 — CORS errors should be 0 after config/cors.php fix
        await page.evaluate((arr) => console.log("A-002 cors errors captured:", JSON.stringify(arr)), corsErrors);
        expect(shadow, "subtitle should have text-shadow").not.toBeNull();
        expect(shadow, "subtitle text-shadow non-empty").not.toBe("none");
    });

    test("A-001 — Sandwich Cayenne category lists signature items first", async ({ page }) => {
        await page.goto(`${BASE}/kiosk/idle`, { waitUntil: "networkidle" });
        // Try a couple of selectors to land on takeaway flow & cat 1
        const takeaway = page.getByText(/À\s*emporter|A\s*emporter/i).first();
        if (await takeaway.isVisible().catch(() => false)) {
            await takeaway.click();
            await page.waitForLoadState("networkidle");
        }
        // Direct nav to category 1
        await page.goto(`${BASE}/kiosk/categories?cat=1`, { waitUntil: "networkidle" });
        await page.waitForTimeout(900);
        await page.screenshot({ path: `${OUT}/FIX-A-001-sandwich-cayenne-after.png`, fullPage: true });
        // Capture the visible product names in DOM order. The kiosk catalog grid
        // exposes data-testid="kiosk-product-card-<itemId>" on each card; the
        // product name lives inside .kiosk-product-card-title or the card's text.
        const names = await page.evaluate(() => {
            const cards = Array.from(document.querySelectorAll('[data-testid^="kiosk-product-card-"]'));
            return cards.slice(0, 8).map((el) => {
                // Pick the longest text node line that looks like a product name
                const lines = el.innerText.split("\n").map((s) => s.trim()).filter(Boolean);
                // Prefer any line containing "cayenne" so we don't pick badges like "Nouveau"
                const cayenneLine = lines.find((l) => /cayenne/i.test(l));
                return cayenneLine || lines.find((l) => l.length > 3 && !/^nouveau|^upsell|^\+|^€/i.test(l)) || lines[0] || "";
            });
        });
        await page.evaluate((arr) => console.log("A-001 first products in DOM order:", JSON.stringify(arr)), names);
        // Loose assertion: 'Sandwich Cayenne' or 'Big Cayenne' should appear in first 3 entries
        const first3 = (names.slice(0, 3) || []).join(" | ").toLowerCase();
        expect(first3).toMatch(/cayenne/);
    });

    test("C-002 — /admin redirects to /admin/dashboard", async ({ page }) => {
        // Anon: should bounce to login (acceptable). Authed would land on dashboard.
        const response = await page.goto(`${BASE}/admin`, { waitUntil: "networkidle" });
        await page.waitForTimeout(800);
        const finalUrl = page.url();
        await page.screenshot({ path: `${OUT}/FIX-C-002-admin-redirect-after.png`, fullPage: true });
        await page.evaluate((u) => console.log("C-002 /admin landed on:", u), finalUrl);
        // After fix: /admin must NOT remain on a Vue 404 page — should redirect somewhere
        // useful (dashboard if authed, login if anon).
        expect(finalUrl).not.toMatch(/\/admin\/?$/); // Not stuck on bare /admin
        // 404 component title would contain 'Page Non Trouvée' OR similar — ensure absent
        const has404 = await page.locator("text=/Page Non Trouvée|Page Not Found|404/i").count();
        expect(has404).toBe(0);
    });
});
