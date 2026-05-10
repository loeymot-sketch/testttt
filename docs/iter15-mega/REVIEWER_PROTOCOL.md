# iter15 Mega-Audit — Reviewer Protocol

> Each Wave E reviewer agent reads this file before scoring artifacts.
> Findings emitted to `reports/iter15-mega/run-N/wave-X-findings.json`.

## Artifact quartet (per visual state)

For every screenshot capture, the spec emits 4 sibling files:

```
__screenshots__/iter15-mega-X/<state>.png         ← visual
__screenshots__/iter15-mega-X/<state>.dom.html    ← await page.content()
__screenshots__/iter15-mega-X/<state>.console.json ← page.on('console') + pageerror sink
__screenshots__/iter15-mega-X/<state>.network.json ← responses status>=400 OR duration>2000ms
```

The reviewer must inspect **all four** before declaring a state clean.

## 12 defect categories

| # | Category | Detection rule | Severity |
|---|---|---|---|
| 1 | i18n leak | DOM contains visible text matching regex `^[a-z]+(\.[a-z_]+){1,4}$` (e.g. `label.add_to_cart`, `kiosk.confirmation.title`, `button.split_equally`). False positives: code blocks, monospace fonts on diagnostic pages. | **P1** |
| 2 | Text truncation without tooltip | element with `text-overflow: ellipsis` AND `scrollWidth > clientWidth + 2` AND no `title` attr / sibling tooltip | **P2** |
| 3 | Element overlap (clickable) | bounding-box intersection of two `role=button` / `<a>` / `[onclick]` elements where the smaller is fully contained or >50% overlapped | **P1** |
| 4 | Color contrast WCAG AA | axe-core `color-contrast` violation; foreground/background ratio < 4.5:1 (3:1 for ≥18px bold) | **P2** |
| 5 | Empty-state quality | empty list/grid contains zero of: illustration `<img>`, copy ≥20 chars, primary CTA `<button>` / `<a>` | **P2** |
| 6 | Silent error | `network.json` contains 4xx/5xx response AND DOM has no `[role=alert]` / `.toast` / `.alert-*` visible | **P0** |
| 7 | Loading state missing | `network.json` request duration > 2000ms AND no `.spinner` / `.skeleton` / `[aria-busy=true]` rendered during that window | **P2** |
| 8 | Aria/keyboard | live region missing on KDS status containers (`role=status` or `aria-live`); missing `:focus-visible` on buttons; non-button click handlers | **P2** |
| 9 | Console error | `console.json` contains entry with `level=error` whose stack does not include `vendor.js` / Laravel websockets noise | **P1** |
| 10 | Unexpected 4xx/5xx | `network.json` status ≥ 400 not in expected-allowlist (auth-redirect 401 on logout, 422 on validation form-state, 304 noop) | **P0** |
| 11 | Numeric integrity | observable mismatch: cart total ≠ Σ(line × qty); receipt total ≠ payment-modal total; tax line ≠ `PRICING_TAX_INCLUSIVE=true` expectation | **P0** |
| 12 | Visual hash drift | unexpected pixel diff vs. last-known-good baseline; flag only > 5% pixel delta on a non-animated region | **P3** |

## Severity meaning

| Sev | Meaning | Loop blocking? |
|-----|---------|----------------|
| P0 | Production-breaking (data wrong, silent failure, fiscal risk) | YES — must be 0 to declare green |
| P1 | User-visible defect (i18n leak, console error, button overlap) | YES — must be 0 to declare green |
| P2 | UX-quality issue (truncation, contrast, empty-state weak) | NO — list in report, do not loop |
| P3 | Cosmetic (pixel drift, minor antialias) | NO — info only |

## Output schema (strict)

```json
{
  "wave": "A",
  "run_n": 1,
  "findings": [
    {
      "id": "A-001",
      "state_artifact": "iter15-mega-admin/admin-dashboard-default.png",
      "category": "i18n_leak",
      "severity": "P1",
      "evidence": "DOM contains text node 'menu.observability' inside <li class=\"sidebar-item\">",
      "fix_hint": "Add 'menu.observability' to lang/fr.json + lang/en.json"
    }
  ],
  "summary": { "P0": 0, "P1": 0, "P2": 3, "P3": 1 },
  "states_reviewed": 12
}
```

## Reviewer mindset

You are an **adversarial QA**. Owner asked us to find:
- Hidden problems (silent failures, indirect bugs)
- Visual problems (cut text, overlapping buttons, misaligned elements)
- Data sync issues (KDS not updating, stock cascade missed)
- Anything that would embarrass the product in front of a paying restaurant owner

Be skeptical. A spec passing means the happy path renders — that's the floor, not the ceiling. Hunt for what the test author missed.
