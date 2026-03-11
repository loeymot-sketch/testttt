# Review Reports

This directory contains Claude's review reports after Kimi implementations.

## Structure

- `latest.md` - Always the most recent review (entry point for agents)
- `review-001.md`, `review-002.md`, etc. - Numbered historical reviews

## Review Flow

1. Kimi implements following Claude's plan
2. Kimi executes tests (if "Kimi-test" specified)
3. Kimi writes execution summary
4. **Claude reviews implementation and writes review report here**
5. Review includes verdict: APPROVED / NEEDS_FIX / NEEDS_ANTIGRAVITY
6. Human validates based on review

## Verdict Types

- **APPROVED**: Implementation correct, ready for human validation
- **NEEDS_FIX**: Issues found, Kimi should fix
- **NEEDS_ANTIGRAVITY**: Critical validation needed, Anti-Gravity should test

## AI Navigation

Agents must read `latest.md` (not numbered files) to get the current review state.
