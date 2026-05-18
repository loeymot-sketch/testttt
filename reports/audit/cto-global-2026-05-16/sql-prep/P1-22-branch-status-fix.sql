-- ============================================================================
-- P1-22 — Branch.status legacy=1 → Status::ACTIVE=5 migration
-- CTO audit 2026-05-16 — OG-OWNER-EXECUTE (Claude cannot run on prod DB)
-- ============================================================================
--
-- CONTEXT
--   Status::ACTIVE enum value = 5 (see app/Enums/Status.php:7)
--   Legacy production DB seeded before enum migration uses status=1 for active.
--   Bug surfaces in app/Listeners/PersistCatalogChangedToOutbox.php:39
--   which has a workaround `whereIn('status', [Status::ACTIVE, 1])` since
--   ultra-goal A3 heal (2026-05-13). The workaround is bandage; the real fix
--   is data migration to use the enum value, then remove the workaround.
--
-- RISK : Very Low.
--   Only affects 1 column on `branches` table.
--   No FK cascade. No event triggered. No row deleted.
--   Reversible: see rollback section below.
--
-- TIME TO ROLLBACK : <30 seconds (manual UPDATE with id list).
-- DATA LOSS RISK  : No.
--
-- ============================================================================

-- STEP 1 — INSPECT current state (run this FIRST, save output)
-- ----------------------------------------------------------------------------
SELECT id, name, status, created_at, updated_at
FROM branches
ORDER BY status, id;

-- Group counts to confirm scope:
SELECT status, COUNT(*) AS cnt
FROM branches
GROUP BY status
ORDER BY status;
-- Expected before fix : some rows with status=1 (legacy active)
-- Expected after  fix : all rows status IN (5, 10) — ACTIVE or INACTIVE
-- (Status enum has only ACTIVE=5 + INACTIVE=10)


-- STEP 2 — GENERATE rollback SQL (save the OUTPUT of this query!)
-- ----------------------------------------------------------------------------
SELECT CONCAT(
  'UPDATE branches SET status=1 WHERE id IN (',
  GROUP_CONCAT(id ORDER BY id),
  ');'
) AS rollback_sql
FROM branches
WHERE status = 1;
-- Copy the output of this query into a safe file — it's your rollback if needed.


-- STEP 3 — DRY RUN preview (no mutation, just count what would change)
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS rows_to_update FROM branches WHERE status = 1;


-- STEP 4 — APPLY fix (mutation — only after Steps 1-3 verified)
-- ----------------------------------------------------------------------------
START TRANSACTION;

UPDATE branches SET status = 5 WHERE status = 1;

-- Inspect inside transaction before commit:
SELECT id, name, status FROM branches WHERE status = 5 ORDER BY id;

-- If looks correct:
COMMIT;
-- Otherwise:
-- ROLLBACK;


-- STEP 5 — VERIFY post-fix
-- ----------------------------------------------------------------------------
SELECT status, COUNT(*) AS cnt
FROM branches
GROUP BY status
ORDER BY status;
-- Expected : only status IN (5, 10), zero rows with status=1.

-- Smoke test : fire a CatalogChanged event (e.g. trivial item update via admin)
-- and observe `domain_events` table for the new row with branch_id populated.
-- This confirms the listener fan-out path no longer needs the legacy=1
-- workaround.


-- STEP 6 — REMOVE the listener workaround (follow-up commit by Claude)
-- ----------------------------------------------------------------------------
-- Once Step 5 verified, ask Claude to:
--   Edit app/Listeners/PersistCatalogChangedToOutbox.php line 39
--   Change: ->whereIn('status', [Status::ACTIVE, 1])
--   To:     ->where('status', Status::ACTIVE)
--   And remove the comment block at lines 33-38 (no longer relevant).
--   This is a separate ≤30 LOC scope-minimal commit.


-- ============================================================================
-- ROLLBACK (only if Step 5 reveals broken state)
-- ============================================================================
-- Paste the SQL captured in Step 2 here, e.g.:
--   UPDATE branches SET status=1 WHERE id IN (1, 2, 3);
-- Then re-run Step 5 to verify rollback succeeded.
