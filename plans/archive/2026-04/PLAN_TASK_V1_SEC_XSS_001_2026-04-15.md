# Plan – TASK_V1_SEC_XSS_001 – 2026-04-15

## TASK_ID
TASK_V1_SEC_XSS_001

## PRIMARY_MODEL
Composer (routine cleanup — frontend-only, no backend, no frozen zones)

## TEST_STRATEGY
`static-inspection` — grep verification, ESLint rule, visual diff.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `resources/js/components/table/page/PageComponent.vue` | v-html → sanitized | Write | No | No |
| `resources/js/components/admin/settings/Page/PageShowComponent.vue` | v-html → sanitized | Write | No | No |
| `resources/js/components/frontend/page/PageComponent.vue` | v-html → sanitized | Write | No | No |
| `resources/js/components/frontend/account/chat/ChatComponent.vue` | innerHTML → textContent | Write | No | No |
| `resources/js/components/admin/messages/MessageListComponent.vue` | innerHTML → textContent | Write | No | No |
| `resources/js/utils/safeHtml.js` | New utility — DOMPurify wrapper | Write | No | No |
| `package.json` | Add dompurify dependency | Write | No | No |
| `docs/SECURITY_NOTES.md` | XSS section update | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- Backend (PHP) — no changes
- Migrations — none
- OrderService / FrontendOrderService / frozen zones
- Blade templates (`{!! !!}` analytics in master.blade.php — admin-controlled, separate concern, documented only)

## INVARIANTS_AT_RISK
- None

## GATE_CONDITIONS
- None anticipated

## XSS INVENTORY (actual findings)

| # | File | Line | Pattern | Source | Decision |
|---|---|---|---|---|---|
| 1 | `resources/js/components/table/page/PageComponent.vue` | 11 | `v-html="page.description"` | Quill editor HTML | **Sanitize** via `safeHtml()` |
| 2 | `resources/js/components/admin/settings/Page/PageShowComponent.vue` | 20 | `v-html="page.description"` | Quill editor HTML | **Sanitize** via `safeHtml()` |
| 3 | `resources/js/components/frontend/page/PageComponent.vue` | 11 | `v-html="page.description"` | Quill editor HTML | **Sanitize** via `safeHtml()` |
| 4 | `resources/js/components/frontend/account/chat/ChatComponent.vue` | 226 | `fileItem.innerHTML = ...${fileName}...` | File input name | **Replace** with `textContent` |
| 5 | `resources/js/components/admin/messages/MessageListComponent.vue` | 209 | `fileItem.innerHTML = ...${fileName}...` | File input name | **Replace** with `textContent` |
| N | `resources/views/master.blade.php` | 35,49,65 | `{!! $section->data !!}` | Admin analytics config | **Document only** — not modified (admin-controlled, needed for script injection) |

## Execution Steps

### E1 — Install DOMPurify
`npm install dompurify`

### E2 — Create `resources/js/utils/safeHtml.js`
DOMPurify wrapper with restricted allowlist: `b, i, em, strong, br, p, ul, ol, li, a, h1-h6, blockquote, pre, code, span`. No attributes except `href` on `a` (with protocol check).

### E3 — Fix 3 v-html usages (Quill pages)
Replace `v-html="page.description"` with `v-html="safeHtml(page.description)"` and import the utility. Add `/* eslint-disable-next-line vue/no-v-html -- sanitized via DOMPurify */` above each.

### E4 — Fix 2 innerHTML usages (chat file display)
Replace `fileItem.innerHTML = ...` with safe DOM construction:
- Create child elements via `document.createElement`
- Use `textContent` for the filename
- Append children to `fileItem`

### E5 — Update docs/SECURITY_NOTES.md
Add XSS section with inventory, rationale, and exception process.

### E6 — Compile frontend
`npm run prod` to verify no build errors.

## SYMMETRY_NOTE
N/A

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened
