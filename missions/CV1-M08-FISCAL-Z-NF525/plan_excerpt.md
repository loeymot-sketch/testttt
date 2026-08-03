# PLAN EXCERPT — CV1-M08-FISCAL-Z-NF525

Gate fiscal decision: Option B — POS finalize. Gate schema: Option A.

Implement Z aggregation/refund/void/HMAC with kiosk fiscal route delegated to POS finalization. Do not introduce kiosk direct Z sealing.
The M08 allowlist includes `tests/Feature/Sentinels/FiscalZBranchExactnessSentinelTest.php` because the mission's mandatory tests require it to turn green and the fixture must use fiscalized rows (`fiscal_sequence_no`) under the NF525 Z policy.
No KDS or frontend pricing scope is authorized in this mission.
