# Migration Runbook Template

Copy this file for each production migration or release batch. Fill all fields
before requesting production execution.

## Identification

- Migration file:
- Release batch:
- Owner:
- Gate reference:
- Expected deployment window:

## Branch Isolation Impact

- Tables touched:
- Existing `branch_id` columns:
- New `branch_id` columns:
- Exact branch verification query:

Use equality checks only. Prefix or `LIKE` matching is not acceptable branch
isolation evidence.

## Backup

- Script command:
- Backup manifest path:
- Backup artifact path:
- SHA-256:
- Restore command:

## Dry-Run

- Script command:
- Transcript path:
- Reviewer:
- Result:

## Rehearsal

- Script command:
- Staging dataset source:
- Rollback step count:
- Up result:
- Down result:
- Final Up result:

## Rollback

- Rollback trigger:
- Maximum rollback window:
- Command:
- Data verification after rollback:

## Sign-Off

- Engineering:
- Operations:
- Human gate owner:
