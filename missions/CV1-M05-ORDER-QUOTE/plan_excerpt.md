# PLAN EXCERPT — CV1-M05-ORDER-QUOTE

Create authoritative `OrderQuoteService`: HMAC-SHA256 canonical intent, TTL 60s, idempotent consume, tamper rejection. POS/kiosk must pay backend `quote.total_ttc`, never client form total.

Gate schema Option A is approved.
