# W5 Gestion (36 surfaces) — 6 confirmed → 5 HEALED + 1 deferred, 0 refuted — 2026-06-20

Workflow w9k7zzvj4 (13 agents, 5 sub-wave lanes). 6 confirmed, 0 refuted — richest sweep.

## HEALED (TDD, 0 frozen)
- **[P1] REP-AUTHZ-OVERVIEW-01** — /sales-report/overview leaked branch revenue to ANY staff: the gate
  `->only(...'overview')` named a NON-EXISTENT method (Laravel binds by handler name = salesReportOverview),
  so it never attached — AND the sentinel was false-green (string-matched the literal). HEAL: real method name
  in SalesReportController:40 + sentinel:35, and assertMethodGated now asserts the method exists (get_class_methods).
- **[P1] RBAC-USERS-INDEX-01** — GET /admin/users index ungated + no role filter → any staff enumerated all
  emails incl. Admins (?role_id=1; documented 2026-05-17, never fixed). HEAL: gate index on permission:pos +
  FORCE CUSTOMER role in SimpleUserService::list (defense-in-depth). Sentinel: Chef→403, operator can't see admins.
- **[P2] W5-SET-NOTIF-INDEX-01** — /setting/notification index ungated → FCM push creds readable by non-settings
  staff (missed Mail-SET-02 sibling). HEAL: ->only('index','update').
- **[P2] CAT-MOD-PRICE-CAP-01** — bulk item nested-modifier price guard rejected negatives but NOT magnitude →
  13-digit price overflowed order_quotes.subtotal (SQLSTATE 22003 + raw-SQL leak) at quote. HEAL: IniAmount(true)
  cap in ItemRequest::validateNestedModifierPrices (mirrors the dedicated /variation,/extra endpoints).
- **[P3] W5-SET-LICENSE-INDEX-02** — /setting/license index echoes license_key == MIX_API_KEY == global x-api-key.
  HEAL: ->only('index','update'). Owner note G-LICENSE-KEY: license edit rotates the global API key (decouple?).

## DEFERRED (owner decision — anti-drift)
- **[P3] W5-SET-CONFIG-READ-EXPOSURE-03** — 8 settings index endpoints (site/company/otp/currency/tax/page/slider/
  language) readable by non-settings staff. NON-secret display/operational config (no creds); likely legit
  non-settings consumers (currency/language/theme widgets). Blanket-gating 8 endpoints risks breaking admin
  widgets for a P3 consistency issue → owner decision G-SETTINGS-READ (gate vs document-as-intentional per endpoint).

## Gates: 5 RED→GREEN/sentinels, W5 regression 607 passed, frozen §7 diff = 0, scope = 6 non-frozen source + 3 tests.
