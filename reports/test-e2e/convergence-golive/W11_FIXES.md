# W11 — fixes audit profond (deep audit interne) — 2026-07-07
| Finding | Sévérité | Fix | Commit |
|---|---|---|---|
| label.note raw label ticket cuisine borne FR | P2 | clé i18n FR ajoutée (0-frozen) | d4a5877d0 |
| DB KDS board full-scan (ORDER BY id+LIMIT trap) | P2 | FORCE INDEX conditionnel, 3128→534 rows | ca5629658 |
| Mobile mock incohérences (prix/loyalty/reçu/bandeau/CONNECTION_PLAN fantômes) | P3×5 | alignés canon, 0 fantôme | 89cf0ec8e |
| Web standalone promo fantôme + adresse + P3 | P2×2+P3 | promo backend-only, adresse unifiée 62210 | 08c4a30 (repo web) |
Non fixés (backlog documenté) : db-schema append-only triggers (P3, défense en profondeur), kiosk-offline abandoned order (P3), Cache::lock idempotence (FAUX, déjà release:698).
Gates : Vitest 2276/0, PHPUnit 3185/0, frozen 0 hors LOCK, CHAIN OK ×4.
