# CONVERGENCE — GOAL ULTRA E2E TOUS SYSTÈMES (2026-07-10)

## VERDICT : CONVERGÉ — P0=0 · P1=0 · P2=0 · 0 frozen · NF525 intact

### Boucle plan→test→corrige→vérifie→re-test (2 cycles adversaires)
| Cycle | Trouvé | Healé | Résultat |
|---|---|---|---|
| **Baseline** | — | — | **640 tests PASS, 0 fail** (tous systèmes) |
| **C1** adversaire (9 agents) | POS-1 (P1), OSS-01 (P2), OSS-02 (P3) réels | 3 healés+testés | — |
| **C2** adversaire (6 agents) | **P0+P1=0** ; heals C1 confirmés GONE ; 1 nouveau P2 (zombie backend) | zombie healé+vérifié | **P0+P1+P2 = 0** |

### Défauts réels corrigés + verrouillés (ce GOAL)
| # | Sév | Défaut | Fix (non-frozen) | Preuve |
|---|---|---|---|---|
| POS-1 | P1 | Commande POS DELIVERY ≥30€ bloquée 409 (règle livraison-offerte absente du quote) | `OrderQuoteService::calculatePricing` (miroir OrderService:860) | test `PosFreeDeliveryQuoteSealTest` |
| OSS-01 | P2 | Mur client peignait `<li>` vides (queue+token null) | filtre `PreparingAndReadyComponent._hydrateFromRows` | Vitest OSS+KDS 3/3 |
| ZOMBIE | P2 | Backend leakait la cmd null-identifiant (5399 CARDTEST 8j) sur mur+KDS | garde identifiant dans `OrderStatusScreenOrderService::list()`+`listForBranch()` | 5399 exclu, OSS 13 + sister 4 + midnight 4 |
| OSS-02 | P3 | Écran public → toutes branches si contexte absent | garde branchId≤0 → mur vide (`OrderStatusScreenController`) | OSS suite verte |

*(+ ce jour, hors ce GOAL mais même working-tree : borne 429 `kiosk-quote`, caisse cancel `actorIsKioskMachine` — testés 7/7.)*

### Parcours réel PROUVÉ (live, cross-surface)
Commande borne réelle **5633** (queue A0034, 7,90€, Tacos L composé) → **CAISSE à-encaisser ✓ +
KDS board ✓ + OSS mur (absent à ACCEPT, présent après bump chef) ✓**, `composition_snapshot` figé.
Sync borne→caisse→KDS→OSS cohérente (id/prix/queue/compo identiques).

### Reste divulgué (P3, non bloquant — ne manifeste pas à l'échelle V1 / by-design / data-test)
- KDS-01/02/03 : grouping raw / recall-window updated_at / sort>50 (branche1 = 24 <50).
- POS-2 : recall parked garde ligne item soft-deleted (UX, rejeté 422 au resubmit).
- SYNC-ACCEPT-GATE : OSS exclut ACCEPT (by-design).
- Data : ordres test stales 5399/4829 (CARDTEST) — purge `foodking:cleanup-web-test-orders` (owner).

### Gates
- **0 fichier frozen touché** · **NF525** audit_logs=4938 hash=ffe782b9 z=25 inchangé.
- Suites vertes : POS 125, Kiosk 34(+13), KDS 47, Order 50, OSS 1+13, Fiscal 246, Branch 14, Security 106.
- **Rien poussé** — backend testttt à déployer VPS (11 fichiers non-frozen : voir git status). Gate owner §10.
