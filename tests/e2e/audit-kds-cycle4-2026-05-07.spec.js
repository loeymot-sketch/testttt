// FoodKing E2E — AUDIT KDS CYCLE D4 (2026-05-07)
// Allergen split G-5 + composition_snapshot immutability.
// Vérifie côté source :
//   1. KitchenDisplaySystemOrderService::orderItems() agrège par allergens_hash.
//   2. KDSOrderItemsResource résout les addons via composition_snapshot (immutable, pas via relation live).
//   3. Tests existants couvrant l'allergen split sont au catalogue (run via phpunit en D5) :
//        - tests/Feature/Orders/KDSAllergenVisibilityTest.php
//        - tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php
//        - tests/js/kdsAllergens.spec.js
//   Note honnête : il n'existe PAS de fichier "KdsAllergenAggregationSplitSentinelTest.php"
//   dédié dans tests/Feature/Sentinels/ ; le brief amalgamait la couverture indirecte.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-kds-2026-05-07/cycle4');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function record(step, slug, state, sev, note, screenshot) {
  findings.push({ step, slug, state, severity: sev, note, screenshot, ts: new Date().toISOString() });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

const KDS_SVC_PATH      = path.join(process.cwd(), 'app/Services/KitchenDisplaySystemOrderService.php');
const KDS_RESOURCE_PATH = path.join(process.cwd(), 'app/Http/Resources/KDSOrderItemsResource.php');

test.describe('AUDIT KDS CYCLE D4 — Allergen split G-5 + composition_snapshot immutability', () => {
  test.setTimeout(60_000);

  test('D4-01 — KitchenDisplaySystemOrderService agrège par allergens_hash', async () => {
    expect(fs.existsSync(KDS_SVC_PATH)).toBe(true);
    const src = fs.readFileSync(KDS_SVC_PATH, 'utf8');

    const hasOrderItemsMethod = /public\s+function\s+orderItems\s*\(/.test(src);
    const usesAllergensHash = /allergens_hash/.test(src);
    const exposesAllergensHashKey = /['"]allergens_hash['"]\s*=>/.test(src);

    record('D4-01', 'allergen-aggregation-split', 'source-check',
      (hasOrderItemsMethod && usesAllergensHash && exposesAllergensHashKey) ? 'OK' : 'P1',
      `orderItems()=${hasOrderItemsMethod}, allergens_hash-used=${usesAllergensHash}, exposed-as-key=${exposesAllergensHashKey}`, null);

    expect(hasOrderItemsMethod).toBe(true);
    expect(usesAllergensHash).toBe(true);
    expect(exposesAllergensHashKey).toBe(true);
  });

  test('D4-02 — KDSOrderItemsResource résout addons via composition_snapshot (immutable)', async () => {
    expect(fs.existsSync(KDS_RESOURCE_PATH)).toBe(true);
    const src = fs.readFileSync(KDS_RESOURCE_PATH, 'utf8');

    const hasResolveAddons = /resolveAddonsForKds\s*\(/.test(src);
    const usesCompositionSnapshot = /composition_snapshot/.test(src);
    const safeJsonDecode = /safeJsonDecodeArray\s*\(/.test(src);
    const addonsKey = /\['?addons'?\]/.test(src);

    record('D4-02', 'composition-snapshot-immutable', 'source-check',
      (hasResolveAddons && usesCompositionSnapshot && safeJsonDecode && addonsKey) ? 'OK' : 'P1',
      `resolveAddonsForKds=${hasResolveAddons}, composition_snapshot=${usesCompositionSnapshot}, safe-json=${safeJsonDecode}, addons-key=${addonsKey}`, null);

    expect(hasResolveAddons).toBe(true);
    expect(usesCompositionSnapshot).toBe(true);
  });

  test('D4-03 — Tests allergen existants au catalogue (run via phpunit D5)', async () => {
    const targets = [
      'tests/Feature/Orders/KDSAllergenVisibilityTest.php',
      'tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php',
      'tests/Feature/Orders/OrderAllergenSnapshotTest.php',
      'tests/js/kdsAllergens.spec.js',
    ];

    const inventory = targets.map((t) => ({ file: t, exists: fs.existsSync(path.join(process.cwd(), t)) }));
    const allExist = inventory.every((i) => i.exists);

    record('D4-03', 'allergen-tests-inventory', 'source-check',
      allExist ? 'OK' : 'P2',
      `Inventaire tests allergens: ${JSON.stringify(inventory)}`, null);

    expect(inventory.find((i) => i.file.includes('KDSAllergenVisibilityTest')).exists).toBe(true);
    expect(inventory.find((i) => i.file.includes('OrderAllergenSnapshotComposedTest')).exists).toBe(true);
  });

  test('D4-04 — Pas de mutation runtime d\'allergens dans le composant KDS (immutable côté affichage)', async () => {
    const vue = path.join(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');
    expect(fs.existsSync(vue)).toBe(true);
    const src = fs.readFileSync(vue, 'utf8');

    // Pas d'attribution destructive à allergens depuis le composant (lecture seule).
    // Heuristique : on tolère "allergens" en lecture/badge/modal mais pas "allergens =" (assignment).
    const dangerousAssign = /\.allergens\s*=\s*[^=]/g;
    const matches = (src.match(dangerousAssign) || []).filter((m) => !m.includes('==')); // exclure '==' faux positifs

    record('D4-04', 'allergens-immutable-frontend', 'source-check',
      matches.length === 0 ? 'OK' : 'P2',
      `Mutations détectées: ${matches.length}. Sample: ${matches.slice(0, 3).join(' | ')}`, null);

    expect(matches.length).toBe(0);
  });
});
