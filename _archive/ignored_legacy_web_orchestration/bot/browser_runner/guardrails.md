# Browser runner — guardrails

## What the runner is allowed to do

- Launch **one** Chromium session per CLI invocation using a **persistent** `user_data_dir` (cookies survive between runs).
- Navigate to URLs declared in `browser_profiles.json` (no hardcoded production secrets).
- Read `bot/state/browser_bridge_next_action.json` and follow `action_kind` for that single step.
- For **Claude** (`claude_project_browser`): paste handoff text from the absolute path in `instructions.paste_source_file`, send, capture visible assistant output to `bot/state/browser_runner_last_capture.txt`.
- Run `json_extractor` on capture; if structurally valid for `expected_response_kind`, optionally write inbox JSON via `browser-write-inbox` or the combined `browser-run-step` path.
- For **Cursor** (`cursor_agent`): in `clipboard` mode, copy handoff text to the OS clipboard (Windows) and exit with a structured **human-required** status — **no** fabricated `cursor_done.json`.
- For **Cursor** (`playwright` mode + non-empty `start_url`): same pattern as Claude when you configure a reachable UI (experimental).

## What still requires human intervention

- First-time **Claude / Cursor login** inside the persistent profile (open-target, sign in manually once).
- **Google Chrome + `user_data_dir_absolute`**: set `claude.use_google_chrome: true` and point `user_data_dir_absolute` at `…\\Google\\Chrome\\User Data`. **Quit every Chrome window** before `browser-run-step` (Playwright needs an exclusive lock on that profile). Optional `chrome_profile_directory` (e.g. `Profile 2`) if you use multiple Chrome profiles.
- **Choosing the correct** orchestrator thread if the sidebar is ambiguous: the runner first checks that **`page.url`** matches **`claude.expected_chat_url`** (if set) or **`claude.start_url`** (same `/chat/<uuid>` identity). If that passes, the conversation label in HTML is **not** required. If URL does not match, it falls back to **`conversation_label`** substring in `page.content()`; if both fail → stop.
- **Cursor Agent** completion when mode is `clipboard`: human runs Cursor, then either pastes output back for `browser-parse-last` or creates `cursor_done.json` manually per supervisor docs.
- **Playwright E2E** for FoodKing app (`playwright_pending`) — not implemented in this runner.
- **Local validation** (`local_validation`) — runner only reports pending; human/CI runs `php artisan test` etc.

## When the runner must stop instead of guessing

| Condition | Behavior |
|-----------|----------|
| `handoff_must_exist` is false in next-action | Stop; do not navigate. |
| URL not same chat as `expected_chat_url` / `start_url` **and** page does not contain `conversation_label` | Stop with `wrong_target_conversation`. |
| Composer/send not found within `composer_wait` | Stop with `missing_dom_target` and `dom_diagnostics` (selectors tried, `page_url`, per-selector counts: `total`, `visible_count`, `attached_hidden`). |
| Capture empty or unchanged after send | Stop with `missing_assistant_response`. |
| `json_extractor` fails or shape invalid | **Do not** write inbox JSON; stop with parse error code. |
| Quota / rate-limit heuristics match | Stop with `quota_or_limit_ui`. |
| Browser profile missing or placeholder `start_url` | Stop with `profile_not_configured`. |
| Playwright Python package or browsers not installed | Stop with `import_error_playwright`. |

## Quota interruption surfacing

- Runner scans `page.content()` (lower-cased) for substrings from `claude.quota_snippets` in profile JSON.
- On match: exit code non-zero, stdout JSON includes `"status": "quota_or_limit_ui"` and a short `matched_hint` (which substring class), **no** inbox write.

## Recovering from malformed Claude output

1. Inspect `bot/state/browser_runner_last_capture.txt`.
2. Fix assistant output offline or re-prompt Claude in the browser.
3. Optionally run `browser-parse-last` after replacing capture file manually.
4. When valid, `browser-write-inbox --file fixed.json` (still checks `response_kind` vs next-action).

## Recovering from wrong target conversation

1. Prefer **direct chat URL** in `claude.start_url` (or set `claude.expected_chat_url` to that chat URL). Successful URL match is recorded as `"target_verification": "url_match"` in JSON.
2. If you rely on label fallback only, ensure `browser_bridge_session.json` `claude_conversation_label` is a substring that appears in the page HTML (`"target_verification": "label_match"`).
3. Re-run `browser-open-target --target claude`, then `browser-run-step`.

## Invariants

- **One CLI invocation = one automation step** (no internal loops beyond bounded waits for DOM).
- **Never** claim success without a captured assistant body for Claude steps.
- **Never** synthesize `cursor_done.json` from assumptions — only from observed automation when a future Cursor web flow is configured and validated.
