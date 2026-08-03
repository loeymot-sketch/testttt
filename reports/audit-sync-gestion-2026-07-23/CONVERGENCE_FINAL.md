# Convergence — Audit adversarial TOUS systèmes synchro + gestion (2026-07-23/24)

**Mandat owner** : « audit pour TOUS les systèmes de synchronisation et gestion + toutes les
fonctionnalités + ajouts adversaires (disputes) + assurer que tout est au top, bien installé,
synchronisé, sans faute, max intelligence. »

## VERDICT : ✅ CONVERGÉ — P0 = 0 · P1 = 0 (1 trouvé, corrigé) · 4 P2 guéris · reste = prod-hardening/owner-data

5 auditeurs adversaires en parallèle, chacun **reproduit sur données/env RÉELS → dispute chaque
finding (verify-before-report) → ne retient que le prouvé**. 2 candidats P2 réfutés par la repro.

## Résultats par dimension
| Dim | Système | P0 | P1 | P2 | Verdict |
|---|---|:-:|:-:|:-:|---|
| **A** | Synchro temps-réel (borne/caisse/KDS/OSS/web/m, Echo/outbox/worker) | 0 | 1→**0** | 0 | transport vivant, anti-doublage prouvé ; **A-P1 = drift schéma LOCAL** (prod déjà OK) → `migrate` |
| **B** | Stock + BOM matières | 0 | 0 | 2→**0** | décision-A/food-cost/idempotence RÉFUTÉS=sains ; B-1+B-2 guéris |
| **C** | Money-path + NF525 | 0 | 0 | 1 | **VERT au centime** ; refunds attaqués=tenus ; chaîne OK ×4 ; C-01 pré-existant |
| **D** | Caisse + KDS lifecycle | 0 | 0 | 2→**0** | idempotence/transitions solides ; D-1+D-2 guéris |
| **E** | Web + borne + ticket cuisine | 0 | 0 | 0 | **parité PHP↔JS airtight** (600 cmd, 0 divergence) ; money-path scellé prouvé |

## Guérisons (RED→GREEN, frozen 0, indépendamment vérifiées)
- **A-P1** (toggle 86 → 500 en LOCAL) : migration `manual_unavailable_since` appliquée. Toggle OK (OFF→manual_since→restore). Prod (VPS) l'avait déjà. `commit env-only`.
- **B-1** (conso non annulée) : listener `ReverseRawMaterialsOnOrderCanceled` (OrderCanceled → CANCELED/REJECTED/RETURNED) rend le stock, idempotent. Preuve : 5→5 rendus→0. `e097ca1df`.
- **B-2** (extras non décomptés) : recettes extras (Sauce supp 25g, Cheddar/Œuf 1pc, Viande supp 75g) via subject_type='extra_group'. Œuf ajouté (13 matières). Reste owner-data honnête. `e097ca1df`.
- **D-1** (Admin ressuscite terminal) : garde NON-frozen OrderService (OrderStateMachine intact), terminal→actif refusé pour tous. 8/8. `c1dbcb53c`.
- **D-2** (bucket fantôme PENDING) : todayCount exclut les PENDING non-web (paniers abandonnés). 24/24. `c1dbcb53c`.

## Documenté / différé (non-bloquant, pas des bugs code introduits)
- **C-01 (P2, PRÉ-EXISTANT)** : `verify-chain` HMAC n'attrape pas les trous de `fiscal_sequence_no` (dépend du trigger + REVOKE TRUNCATE). **Action go-live** : confirmer trigger+REVOKE en prod + câbler le cron `verify-sequence-continuity` en ALARME (pas juste log). Pollution dev pré-trigger observée.
- **D-1 côté frozen** : la permissivité vient de `OrderStateMachine::allows()` (frozen) ; garde posée en amont non-frozen (defense-in-depth). Un durcissement dans le frozen lui-même = LOCK owner futur.
- **P3** : aria-label boutons tracker · nettoyage 16 commandes hors-enum (seed) · migration prix couplée au nom (fragile au reseed) · MonitorOutboxStaleness log-only (pas d'alarme) · reason fantôme item 1 · i18n AR « صلصات إضافية » (V2, V1 FR-locked ADR-007) · lignes fiche périmées (Suprême Poulet×0).

## Attestation
Tous les systèmes de synchro (commande + disponibilité, borne↔caisse↔KDS↔OSS↔web↔/m) et de
gestion (stock, matières/BOM, food-cost, caisse, money-path, NF525) sont **audités
adversariallement, disputés, et sains** : 0 P0, 0 P1 résiduel, cluster P2 guéri, cœur money-path/
NF525 vert au centime, anti-doublage et idempotence prouvés. Preuves : ~2500 vitest + ~950 PHPUnit
ciblés verts, chaîne NF525 OK ×4, frozen 0. Reste = 1 durcissement prod (C-01) + P3 cosmétiques +
données owner (grammages/prix). **« Au top, bien installé, synchronisé » — attesté sur preuves.**

---
## CYCLE 2 (convergence) — attaque des heals + non-régression
- **R2-B non-régression** : ✅ 0 régression. Money-path 447 verts, Order|Sync|Availability|RawMaterial 1184, NF525 OK ×4, frozen 0, vitest 2525. Garde D-1 laisse passer refund DELIVERED→RETURNED ; nouveau listener annulation cohabite (ledgers disjoints).
- **R2-A attaque hostile** : les 4 heals TIENNENT. **0 P0/P1/P2.** 3 P3 :
  - **R2-1 (nouveau, B-2)** : « Œuf » double-décompté (subject_group utf8mb4_unicode_ci ligature-insensible œ≡oe → 2 lignes) → **GUÉRI** (dédoublonnage + garde collisions).
  - **R2-2 (résiduel, B-1)** : reverse async pourrait courir avant consume sous ≥2 workers → **GUÉRI** (consume skip si order déjà terminal, belt-and-suspenders). Mono-worker était déjà sûr.
  - **R2-3 (résiduel, D-2)** : PENDING non-web pos/livraison (8) restent invisibles (pré-existant : jamais de carte ; mon fix a juste stoppé l'inflation du compteur). = **DÉCISION PRODUIT owner** : faut-il une carte/voie pour les PENDING pos+livraison ? (kiosk = paniers abandonnés, à laisser). Non guéri (attente owner).

## VERDICT CONVERGENCE : ✅ ATTEINT
2 cycles consécutifs **P0+P1 = 0**. Cycle 2 n'a révélé que des P3 (2 guéris, 1 = décision produit). Cœur (money-path/NF525/sync/caisse) stable et prouvé sur les deux passes.
