# Review Reports

This directory contains Claude's review reports after executor (Kimi / Cursor) implementations.

## Structure

- `latest.md` - Always the most recent review (entry point for agents)
- `review-001.md`, `review-002.md`, etc. - Numbered historical reviews

## Review Flow

1. Executor implements following Claude's plan
2. Executor runs tests if **`local-validation`** (or other strategy) is specified
3. Executor writes execution summary
4. **Claude reviews implementation and writes review report here**
5. Review includes verdict: **APPROVED** / **NEEDS_FIX** / **NEEDS_PLAYWRIGHT**
6. Human validates based on review

## Verdict Types

- **APPROVED**: Implementation correct, ready for human validation
- **NEEDS_FIX**: Issues found; executor should apply minimal correction plan
- **NEEDS_PLAYWRIGHT**: Critical **Playwright / E2E verification** needed before approval; evidence expected under `reports/antigravity/latest.md` (legacy path)

## AI Navigation

Agents must read `latest.md` (not numbered files) to get the current review state.
