# Ultra-loop CONVERGENCE — bloc gérance/DB/historique/intersections/UI — 2026-07-07

Boucle audit→fix→re-audit→visuel→heal→confirm, agents adversaires parallèles, verify-before-trust.
Base 24e8a09c3 → HEAD (voir git). Branche pos/category-first-caisse-2026-06-23. NON poussé (gate owner).

## Verdict : SCELLÉ — 5 rounds, P0/P1/P2/P3 = 0

| Round | Findings | Action | Gate |
|---|---|---|---|
| R1 audit (12 agents + visuel) | 45 → 8 réels | 8 fixes (P1 PDF ventes, NF525 trigger-check, coupon, CRUD, KDS labels) | Vitest 2286/0 PHPUnit 3206/0 |
| R2 vérif + sweep + visuel | 8/8 tiennent + 4 nouveaux | 2 heals (3e PDF, garde 500→422, KDS token) | 2289/0 3209/0 |
| R3 convergence | 0 P0/P1 | CONVERGED | idem |
| R4 polish | 2 P3 | 2 fixes (exports entités, labels reçu partagés) | 2298/0 3213/0 |
| R5 sceau | 1 P3 (jumeau reçu client) | fix NEW-1 | idem |

## Vrais bugs sortis (l'audit INTERNE calibré V1 trouve, là où 2 audits externes = 85-90% faux)
- 3 rapports PDF (ventes/articles/online-order) sous-déclaraient le CA ~99,99% (6,70€/38 522€ ; 58€/745 633€) — fixés + garde anti-OOM.
- NF525 : triggers d'immutabilité ABSENTS de la base (provisioning dump) — code correct mais silencieux → commande `fiscal:verify-immutability-triggers` (à lancer prod).
- KDS sync 401 sur poste mono-machine : token kiosk périmé pris au lieu de staff — vrai bug, pas artefact env.
- Coupon hard-delete orphelinant l'historique → soft-delete. Prix variations/extras non validés. Outbox dead-letter immortel + faux 503. 11 exports entités + 9 vues reçu tronqués/slugs bruts.

## Attestation
- Chaînes HMAC audit_logs (4917) + z_reports intègres, 0 gap/orphelin/fork. order_status_transitions append-only.
- Intersections refund×Z / cancel×stock / split×fiscal : déjà scellées (LOCK netting), byte-identiques, confirmées.
- Gates finaux : Vitest 2298/0, PHPUnit 3213/0, frozen 0 hors LOCK owner8, CHAIN OK ×4.

## Décisions OWNER ouvertes (P3 fiscal, non bloquantes)
- Livraison à 0% TVA dans le Z-report (32 cmd) — politique fiscale à trancher.
- C33 trou fenêtre morte entre 2 Z — LOCK DRAFT, Appliquer/Différer.
