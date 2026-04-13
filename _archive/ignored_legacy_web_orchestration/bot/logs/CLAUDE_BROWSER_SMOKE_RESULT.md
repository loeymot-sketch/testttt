# Claude browser smoke — result log (template)

Duplicate this block after each smoke attempt; keep newest block at the top.

---

## Run metadata

- **Date (local):** YYYY-MM-DD HH:MM
- **Machine:** (hostname / role)
- **Git revision (optional):** `git rev-parse --short HEAD`

## Commands executed

```text
(paste the exact command lines)
```

## Outputs

### browser-bridge-next-action (summary)

- **cycle_id:**
- **task_id:**
- **action_kind:**
- **handoff_must_exist:** true / false

### browser-run-step

- **ok:**
- **status:**
- **message:** (first line or full)

### browser-parse-last

- **ok:**
- **status / parse error:**
- **extracted kind:** plan | review | …

## Verdict

- **Smoke:** PASS / FAIL
- **Notes:**

---
