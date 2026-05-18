# W2 Consolidated Findings — Ultra-Frontends 2026-05-18

## Real P0 (must heal)
- **P0 Web color contrast** : `--orange #FF5A1F` on white/cream = 3.11:1 / 2.91:1 FAIL WCAG SC 1.4.3 (23 nodes — eyebrows, hero stats, CTAs). Need `--orange-text` applied or palette fix.

## Real P1 (must heal)
- **P1 Web cascade_frites_sauce** : `getActiveSteps` (wizard-v2.jsx:144-173) omits cascade_frites_sauce step. Mobile FORCES it (computeActiveSteps line 144). Users on web order frites style but skip sauce.
- **P1 Web icon button aria-label** : 2 buttons missing accessible names (components.jsx back + burger).
- **P1 Web focus-visible removed** : styles.css:820, 877 outline removed.
- **P1 Web CTA white-on-orange** : 3.11:1 FAIL — adjust button color or orange shade.
- **P1 Mobile emoji field naming** : mobile uses `kiosk_emoji`, web uses `emoji`. Inconsistent shape.

## P2 (deferred backlog)
- Web form labels missing, web live regions absent, web nav touch targets <44px, mobile badge field absent
- Security Phase 6 TODOs: backend price validation, promo server-side, dev-helpers gate, CSP

## All P0 security PASS (XSS / RGPD / SRI ✓)
## Data parity 100% (cf. standalone-parity report — minor cosmetic divergences only)
