# PROD-LIVE-VALIDATION-D0-PREFLIGHT

Mission: documentation-only pre-flight for D1-D13 production-live validation.

Scope:
- Inventory the real kiosk, POS, KDS, OSS, dashboard/catalog-management surfaces.
- Inventory critical API route groups, events/listeners/outbox, tables/migrations, Echo channels, enums.
- Build a coverage matrix from existing Playwright, Vitest, PHPUnit Feature, PHPUnit Unit tests.
- Build a bug/gap backlog that routes the next massive missions.

Constraints:
- No product code edits.
- No OrderService or FrontendOrderService edits.
- No migrations.
- No gates self-approved.
- Mention route-list failure if it blocks automated route inventory.

Validation:
- File existence for the three D0 reports and self-audit.
- Sondage against code references:
  - routes/api.php route groups.
  - EventServiceProvider listener map.
  - routes/channels.php Echo auth.
  - app/Enums values.
  - tests/e2e, tests/js, tests/Feature, tests/Unit coverage.
