import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-CV1-KIOSK-EXTRAS-OOS-MARKER-001
 * @source HEAL-A 2026-05-08 (suite RED-R3 + EDGE-CASES audit P1)
 *
 * Sentinel garantit que les 4 étapes wizard kiosk qui présentent des extras
 * (Sauce, Viande, Garnitures, Supplements) :
 *   - lisent le snapshot `is_available` exposé par le backend
 *   - rendent un badge "Épuisé" (i18n key `pos.item_86_d`) sur les rows OOS
 *   - exposent un data-testid="kiosk-extra-oos-badge" pour ancrer e2e + tests
 *   - marquent la row `aria-disabled="true"` quand OOS (a11y)
 *   - ajoutent une classe `is-out-of-stock` (CSS hook)
 *
 * Le helper `partitionKioskExtras` doit propager `is_available` et
 * `unavailable_reason` au row, sinon les step components récupèrent un row
 * dépouillé de la donnée et ne peuvent rien afficher.
 *
 * Le helper `kioskViandeCatalogForItem` doit également exposer `is_available`
 * sur les rows (variation + extra) pour que KioskStepViande affiche le badge
 * sur les viandes payantes en rupture.
 *
 * Anti-régression : si l'un de ces invariants disparaît, un client kiosk
 * pourrait ajouter un extra/sauce/viande en rupture sans aucun signal visuel
 * et la commande serait rejetée 422 au submit côté backend (UX dégradée que
 * cette feature vise précisément à éviter — exigence user 2026-05-07).
 */
describe('Kiosk extras OOS marker — structure (CV1-KIOSK-EXTRAS-OOS-MARKER-001)', () => {
    const repoRoot = process.cwd();

    const partitionPath = resolve(repoRoot, 'resources/js/helpers/kioskExtrasPartition.js');
    const viandeCatalogPath = resolve(repoRoot, 'resources/js/helpers/kioskViandeCatalog.js');
    const supplementsPath = resolve(
        repoRoot,
        'resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue',
    );
    const saucePath = resolve(
        repoRoot,
        'resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue',
    );
    const garnituresPath = resolve(
        repoRoot,
        'resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue',
    );
    const viandePath = resolve(
        repoRoot,
        'resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue',
    );

    const partitionSrc = readFileSync(partitionPath, 'utf8');
    const viandeCatalogSrc = readFileSync(viandeCatalogPath, 'utf8');
    const supplementsSrc = readFileSync(supplementsPath, 'utf8');
    const sauceSrc = readFileSync(saucePath, 'utf8');
    const garnituresSrc = readFileSync(garnituresPath, 'utf8');
    const viandeSrc = readFileSync(viandePath, 'utf8');

    it('partitionKioskExtras propagates is_available + unavailable_reason on each row', () => {
        // The row factory must surface those two fields for downstream step
        // components — without them, supplements / garnitures rows lose the
        // backend OOS snapshot.
        expect(partitionSrc).toMatch(/is_available\s*:\s*e\?\.is_available\s*!==\s*false/);
        expect(partitionSrc).toMatch(/unavailable_reason\s*:\s*e\?\.unavailable_reason\s*\|\|\s*null/);
    });

    it('kioskViandeCatalogForItem propagates is_available + unavailable_reason on viande rows', () => {
        // Both 'variation' and 'extra' source rows must expose availability
        // so KioskStepViande can render the OOS badge on paid viandes (extras)
        // and stay defensive on free viandes (variations).
        const occurrences = (viandeCatalogSrc.match(/is_available\s*:\s*[ev]\?\.is_available\s*!==\s*false/g) || []);
        expect(occurrences.length).toBeGreaterThanOrEqual(2);
        expect(viandeCatalogSrc).toMatch(/unavailable_reason/);
    });

    it('OOS badge data-testid="kiosk-extra-oos-badge" is rendered in all 4 step components', () => {
        // One badge per step → 4 occurrences total across the 4 files.
        const allSrc = supplementsSrc + sauceSrc + garnituresSrc + viandeSrc;
        const occurrences = allSrc.match(/data-testid="kiosk-extra-oos-badge"/g) || [];
        expect(occurrences.length).toBeGreaterThanOrEqual(4);
        // Each step ships at least one badge
        expect(supplementsSrc).toMatch(/data-testid="kiosk-extra-oos-badge"/);
        expect(sauceSrc).toMatch(/data-testid="kiosk-extra-oos-badge"/);
        expect(garnituresSrc).toMatch(/data-testid="kiosk-extra-oos-badge"/);
        expect(viandeSrc).toMatch(/data-testid="kiosk-extra-oos-badge"/);
    });

    it('OOS badge uses the existing i18n key pos.item_86_d (no new key)', () => {
        // Reuse the existing FR/EN/AR key — anti new-key drift.
        expect(supplementsSrc).toMatch(/\$t\(\s*['"]pos\.item_86_d['"]\s*\)/);
        expect(sauceSrc).toMatch(/\$t\(\s*['"]pos\.item_86_d['"]\s*\)/);
        expect(garnituresSrc).toMatch(/\$t\(\s*['"]pos\.item_86_d['"]\s*\)/);
        expect(viandeSrc).toMatch(/\$t\(\s*['"]pos\.item_86_d['"]\s*\)/);
    });

    it('Each step exposes an isXxxOos helper (read snapshot, not raw probing)', () => {
        expect(supplementsSrc).toMatch(/isSupplementOos\s*\(/);
        expect(sauceSrc).toMatch(/isSauceOos\s*\(/);
        expect(garnituresSrc).toMatch(/isGarnitureOos\s*\(/);
        expect(viandeSrc).toMatch(/isViandeOos\s*\(/);
    });

    it('Each step row applies class "is-out-of-stock" + aria-disabled when OOS', () => {
        // CSS class hook for visual treatment + ARIA for assistive tech.
        expect(supplementsSrc).toMatch(/['"]is-out-of-stock['"]\s*:\s*isSupplementOos\(/);
        expect(sauceSrc).toMatch(/['"]is-out-of-stock['"]\s*:\s*isSauceOos\(/);
        expect(garnituresSrc).toMatch(/['"]is-out-of-stock['"]\s*:\s*isGarnitureOos\(/);
        expect(viandeSrc).toMatch(/['"]is-out-of-stock['"]\s*:\s*isViandeOos\(/);

        // aria-disabled inverts on OOS — the row is non-interactive
        expect(supplementsSrc).toMatch(/aria-disabled[^>]*isSupplementOos/);
        expect(sauceSrc).toMatch(/aria-disabled[^>]*isSauceOos/);
        expect(garnituresSrc).toMatch(/aria-disabled[^>]*isGarnitureOos/);
        expect(viandeSrc).toMatch(/aria-disabled[^>]*isViandeOos/);
    });

    it('Supplements + button is disabled when supplement is OOS', () => {
        // Adding an OOS supplement must be physically blocked (not just visual)
        expect(supplementsSrc).toMatch(/:disabled="[^"]*isSupplementOos\(supplement\)[^"]*"/);
    });

    it('Viande canIncrement guard rejects OOS viandes', () => {
        // Increment guard prevents the user from clicking + on a OOS viande
        expect(viandeSrc).toMatch(/if\s*\(\s*this\.isViandeOos\(viande\)\s*\)\s*return\s+false/);
    });

    it('Order is NOT hard-blocked at submit (OOS marker is UX-only)', () => {
        // Anti-regression: no submit-blocking added by mistake. The marker only
        // locks the per-row + button. The wizard "ajouter au panier" must not
        // gate on isXxxOos. We assert no submit/emit guard references the OOS
        // helpers in the wizard parent (KioskWizardComponent).
        const wizardPath = resolve(
            repoRoot,
            'resources/js/components/frontend/kiosk/KioskWizardComponent.vue',
        );
        const wizardSrc = readFileSync(wizardPath, 'utf8');
        // No "isSupplementOos"/"isSauceOos"/etc. used as a submit gate in wizard
        expect(wizardSrc).not.toMatch(/isSupplementOos|isSauceOos|isGarnitureOos|isViandeOos/);
    });
});
