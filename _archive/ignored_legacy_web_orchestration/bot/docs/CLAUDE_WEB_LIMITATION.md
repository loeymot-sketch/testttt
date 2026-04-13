# Claude web — platform limitation (not a bot bug)

Claude.ai may show a **human verification** or anti-automation interstitial (for example copy like “Nous vérifions que vous êtes humain…” / “Verifying you are human…”). That screen is enforced by **Anthropic / the browser stack**, not by FoodKing bot code.

## What the bot does

- **`browser-open-target --target claude`** can still be useful to open the configured URL in the isolated Playwright profile (for a **human** to log in or inspect the tab).
- **`browser-run-step`** on `claude_project_browser` **detects** those pages and returns a deterministic status: **`human_verification_blocked`**. It does **not** pretend success, does **not** write inbox JSON, and does **not** retry in a loop.
- When the cycle FSM is **`waiting_claude`**, the runner also transitions the cycle to **`manual_gate`** with a clear `manual_gate_reason`, so the bridge and operator flow stay coherent.

## Supported production mode

1. Bot prepares **`bot/outbox/claude/`** handoffs and supervisor drop zones as today.
2. **Human** pastes the handoff into Claude in a normal browser session (or completes verification there).
3. **Human** saves Claude’s JSON output into the correct **`bot/inbox/claude_plan/`** or **`bot/inbox/claude_review/`** path for the active cycle.
4. **`run-supervisor-once`** (or your scripted loop after manual Claude) continues automation for Cursor, validation, and reports.

**Cursor** browser automation is unchanged by this policy; only Claude **web** automation is treated as best-effort and bounded by the platform.

## References

- Operator smoke steps: `bot/docs/CLAUDE_BROWSER_SMOKE_CHECKLIST.md`
- Bridge recovery catalog: `bot/browser_bridge/failure_modes.md`
