# CRITIC NUIT — Wave A — Durabilité & long-terme (read-only)

HEAD `cfc23966a` · DB `foodking_e2e` LIVE · 2026-07-03 · read-only, 0 write projet.

## Verdict coverage : DEEP
Vérifié file:line sur les 6 cibles : scheduler/DST, fiscal config/rollover/exhaustion,
index DB hot-path, domain_events/retention, sanctum prune, refund gate.
Plusieurs cibles annoncées « IMPROVABLE » se sont **réfutées** à la lecture (voir §Réfutés).

## Réfutés ce passage (NE PAS re-signaler)
- **Exhaustion séquence fiscale** : `fiscal_sequence_no` = `unsignedBigInteger`
  (migration `2026_04_22_000001:28`), max ~9.2e18. À 30k/an → jamais atteint. Non-issue.
- **Sanctum prune / croissance `personal_access_tokens`** : DÉJÀ HEALED —
  `sanctum:prune-expired --hours=24` scheduled `Kernel.php:211` (le commentaire :201
  « NEVER scheduled » est historique, la lane existe en dessous).
- **DST scheduler double-run/skip** : les lanes NF525 portent `->timezone('Europe/Paris')`
  (`Kernel.php:213/240/403/448`). Aucune lane dans la fenêtre 02:00-03:00 (spring-skip /
  fall-repeat) : backup 03:00, purge 03:15, prune 04:00/04:15, cleanup 04:10, verify 05:00/06:05.
  Cron timezone-aware Laravel → risque marginal, pas P.
- **Index audit_logs** : `(branch_id,created_at)` + `(resource,resource_id)` + unique-chain
  présents (`2026_04_22_000002:54-55`). Hot-path OK.

## Attaques durabilité RESTANTES (au-delà des 10 confirmées)

### A1 — RACINE des deux scans : `orders` sans index status/payment_status
`create_orders_table` (`2022_11_17_110810:36-37`) déclare `payment_status` et `status` en
`tinyInteger` **sans aucun index**. C'est la cause commune du full-scan `/counter-collect/pending`
(P2 confirmé) ET du scan KDS `historyToday()` (P3 confirmé). Une **seule migration additive
réversible** (composite `(payment_status,status)` + `(status,updated_at)`) soigne les deux.
Aucun fichier frozen. **ROI le plus haut de tout le lot.**

### A2 — Intersection perte-de-données : outbox retry 24h × alarme perma-morte
`outbox:retry-failed --since=24h` (`Kernel.php:64`) ne rattrape QUE les échecs < 24h.
Couplé à l'unique alarme de panne-synchro `MonitorOutboxStaleness` en **échec permanent**
(P2 confirmé #10) : une panne worker > 24h **orpheline des événements pour toujours,
silencieusement** — pas de fenêtre de retry + pas d'alarme fonctionnelle. Compound durable.

### A3 — Trou complétude archive NF525 6 ans (pas de catch-up)
`archived_at` jamais écrit (P3 confirmé #2) + lane `storage:cleanup`/archive sans
rattrapage de run manqué. Attaque : serveur down pendant la fenêtre 04:10 UN jour →
cette journée n'est jamais archivée ET jamais re-tentée → brèche complétude 6 ans
inobservable. Repro : arrêt planifié couvrant la lane.

### A4 — `verifyChain --all` non borné à l'échelle pluriannuelle
Le tail borné (500 lignes, `config/fiscal.php:audit_chain_tail_window`) protège `open()`,
mais `fiscal:verify-chain --all` walk table entière. audit_logs 6 ans no-purge (correct
légalement) → la vérif complète dégrade linéairement. Heal : curseur batché.

### A5 — `OUT_FOR_DELIVERY` cul-de-sac (P3 confirmé #8) → touche frozen
`OrderStateMachine.php:74` : `OUT_FOR_DELIVERY → DELIVERED` uniquement. Une livraison
échouée/refusée n'a AUCUN terminal honnête, même Admin. **OrderStateMachine = frozen §7** →
heal = LOCK-gate owner, PAS auto-heal.

## Heals prioritaires (safe, non-frozen, haut ROI)
1. **[A1] Migration additive index `orders`** — `(payment_status,status)` + `(status,updated_at)`.
   Réversible, 0 frozen, soigne 2 findings confirmés. À faire EN PREMIER.
2. **DashboardService::salesSummary** — remplacer la boucle 1 SUM/jour (365 round-trips) par
   un seul `GROUP BY business_date`. Non-frozen, safe.
3. **[A2] MonitorOutboxStaleness** — corriger la cause du FAILURE permanent pour rendre
   l'unique alarme sync de nouveau signifiante ; élargir/compléter la fenêtre retry 24h
   par un sweep terminal-failure alertant. Non-frozen.
4. **Brute-force OTP** (P2 confirmé) — lock par-identité + consommer le code sur échec
   (throttle par-IP seul aujourd'hui). Non-frozen.
5. **[A3] Observabilité archive** — écrire `archived_at` + commande verify-complétude +
   catch-up run manqué. Commandes non-frozen.

## À ESCALADER (owner gate, PAS auto-heal)
- change-payment-status → REFUNDED : le Sealed-Z guard (`OrderService.php:2379`) ne couvre
  que le post-Z ; le **void pré-Z d'une commande différée** contourne le gate pos-refund
  (P2 confirmé, revenue-leak). Sémantique NF525 → gate humaine.
- OUT_FOR_DELIVERY terminal (A5, frozen §7).
- Double cash-out counter-entry cross-Z (P3 confirmé #9).
- Clôture périodique/annuelle + Grand Total perpétuel manquants (P2 confirmé #1) — décision archi.
