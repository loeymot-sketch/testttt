# Ultra-loop Round 1 — FIXES livrés (2026-07-07)
| # | Sév | Fix | Commit |
|---|---|---|---|
| 1 | P1 | PDF ventes total tronqué → merge paginate=0 (14/14 lignes) | 4a2d76dfd |
| 2 | P2 | PDF articles total tronqué → merge paginate=0 | 4a2d76dfd |
| 3 | P2 | NF525 trigger-check `fiscal:verify-immutability-triggers` (attrape 8/8 manquants) | c8deb655e |
| 4 | P3 | Validation prix variations/extras (numeric/min:0) | 969532d33 |
| 5 | P3 | Coupon soft-delete (fini orphelins order_coupons) | 969532d33 |
| 6 | P3 | Outbox dead-letter purge + faux 503 /health/ready | c8deb655e |
| 7 | P2 | KDS sync baseURL (401 split-host, no-op prod) | 8a376fafd |
| 8 | P3 | Labels transactions €/FR + badge « À encaisser » + nom livreur + « Acceptée » | 8a376fafd |
Réfuté by-design : dashboard moyenne (payées vs placées, documenté).
Gates : Vitest 2286/0, PHPUnit 3206/0, frozen 0 hors LOCK, CHAIN OK ×4.
Note prod : lancer `fiscal:verify-immutability-triggers` sur la box (triggers absents en dev).
