/*
 * FoodKing — open-order forensics dry run
 * READ ONLY: this file intentionally contains SELECT statements only.
 * Review the result set and the 2026-08-11 audit before any repair cycle.
 */

SELECT
    NOW() AS observed_at,
    branch_id,
    COUNT(*) AS total_open,
    SUM(status IN (1, 4, 7)) AS health_open,
    SUM(status = 8) AS prepared,
    SUM(payment_status = 5) AS paid,
    SUM(payment_status IN (10, 15)) AS unpaid_or_counter,
    SUM(fiscal_sequence_no IS NOT NULL) AS fiscalized,
    SUM(DATE(COALESCE(business_date, created_at)) = CURDATE()) AS today,
    SUM(scheduled_at > NOW()) AS future
FROM orders
WHERE deleted_at IS NULL
  AND status IN (1, 4, 7, 8)
GROUP BY branch_id
ORDER BY total_open DESC;

SELECT
    status,
    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS lt_15m,
    SUM(created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS m15_to_24h,
    SUM(created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS d1_to_7,
    SUM(created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS d8_to_30,
    SUM(created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS over_30d,
    COUNT(*) AS total
FROM orders
WHERE deleted_at IS NULL
  AND status IN (1, 4, 7, 8)
GROUP BY status
ORDER BY status;

/* Exact dry-run mirror of the six-hour web/delivery janitor lane. */
SELECT
    id,
    branch_id,
    status,
    payment_status,
    source_surface,
    order_type,
    payment_method,
    created_at,
    order_datetime,
    scheduled_at,
    fiscal_sequence_no
FROM orders
WHERE deleted_at IS NULL
  AND fiscal_sequence_no IS NULL
  AND status IN (1, 4, 7)
  AND payment_status IN (10, 15)
  AND source_surface IN ('web', 'delivery')
  AND (
      order_datetime < DATE_SUB(NOW(), INTERVAL 360 MINUTE)
      OR (
          order_datetime IS NULL
          AND created_at < DATE_SUB(NOW(), INTERVAL 360 MINUTE)
      )
  )
ORDER BY id;

/* Non-fiscal open exceptions not covered by the web/delivery lane. */
SELECT
    id,
    branch_id,
    status,
    payment_status,
    source_surface,
    source,
    order_type,
    payment_method,
    pos_payment_method,
    created_at,
    order_datetime,
    scheduled_at,
    fiscal_sequence_no
FROM orders
WHERE deleted_at IS NULL
  AND fiscal_sequence_no IS NULL
  AND status IN (1, 4, 7, 8)
  AND payment_status IN (10, 15)
  AND NOT (
      status IN (1, 4, 7)
      AND COALESCE(source_surface, '') IN ('web', 'delivery')
      AND (
          order_datetime < DATE_SUB(NOW(), INTERVAL 360 MINUTE)
          OR (
              order_datetime IS NULL
              AND created_at < DATE_SUB(NOW(), INTERVAL 360 MINUTE)
          )
      )
  )
ORDER BY created_at, id;

/* Paid/fiscalized non-terminal debt: reporting only, never a purge set. */
SELECT
    branch_id,
    source_surface,
    status,
    COUNT(*) AS rows_count,
    SUM(payment_status = 5) AS paid,
    SUM(fiscal_sequence_no IS NOT NULL) AS fiscalized,
    MIN(created_at) AS oldest,
    MAX(created_at) AS newest
FROM orders
WHERE deleted_at IS NULL
  AND status IN (1, 4, 7, 8)
  AND (payment_status = 5 OR fiscal_sequence_no IS NOT NULL)
GROUP BY branch_id, source_surface, status
ORDER BY rows_count DESC, branch_id, source_surface, status;
