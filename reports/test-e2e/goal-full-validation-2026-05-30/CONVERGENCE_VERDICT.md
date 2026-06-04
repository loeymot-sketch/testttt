# Full-System Validation — Convergence Verdict (V1 LOCAL Le Cayenne)
**Date** 2026-05-30 · HEAD `4c6b886be` · branche `heal/cms-pr1-quickwins-2026-05-18`

Campagne superviseur : audit adversarial par système (5 systèmes, 7 agents read-only +
confirm), calibré V1 LOCAL (single-box, single-branch — pas V2 SaaS), anti-hallucination
(file:line + preuve DB/curl), discipline CLAUDE.md (agents auditent ; superviseur décide +
patche scope-minimal + respecte frozen/§12 anti-drift).

## Verdict par système

| Système | Verdict V1 LOCAL | Preuve |
|---|---|---|
| **POS / caisse** | ✅ GO | fiscal_seq **gap-free + monotone live-prouvé** (43 orders, 0 dup, 0 gap, triple-défense `FiscalSequenceService` frozen) ; 2 drawers open = **by-design per-cashier** (unique partiel `(branch,user) WHERE open`), 0 impact Z ; tile-click e2e = **spec périmée** (dialog dismissable) |
| **KDS** | ✅ GREEN | chef branch>0 s'abonne `private-branch.N` (4 events) ; admin poll ; recall/bump/86 idempotents + grace 60s + lockForUpdate TOCTOU ; 0 crash/raw-label |
| **OSS** | ✅ GO | live-sync OK (WS staff / poll 2s admin) ; colonnes ne peuvent pas dupliquer un ordre ; fix queue_number vérifié |
| **SYNC** | ✅ GREEN | outbox claim double-broadcast-proof, crash-orphan auto-recover/paged, dedupe WS+poll non-double-apply ; **0 P0/P1, 0 fix requis** |
| **MANAGEMENT** | ✅ GO | dashboard/historique/reports/settings render 200 + data FR réelle post-login ; fenêtre Z correcte (frozen) ; F7 truncation 500-row fixée |

## Anti-drift — le « heal-now » refusé (catch superviseur)
**POS-Q3 (P2)** — l'audit propose d'allouer `fiscal_sequence_no` dans `changePaymentStatus`
(UNPAID→PAID). **REFUSÉ.** C'est exactement le commit `1808f9494`, **reverté `3a4744e63`**
car il créait l'orphelin cross-Z-window, puis remplacé par la décision owner **detect-only**
`fiscal:verify-z-membership` (`b6a1cf81a`, cron'd `Kernel.php:91`). Le vérificateur adversarial
l'a confirmé depuis le code seul, aveugle à l'historique du DECISIONS LOG. Le réappliquer =
ré-introduire un bug reverté + override une décision owner-gated (CLAUDE.md §12). **Mitigation
déjà en place (detect-only) ; aucun changement.** L'edge n'est PAS sur le chemin opérateur
quotidien (POS direct + counter-collect allouent le seq atomiquement).

## Backlog V1.0.X (P2/P3 — calibrés, non-bloquants)
- **OSS-2 (P2 dormant)** : collision d'affichage queue_number cross-business-date (branche
  advance-order + sim NULL business_date) — aucun flux V1 actif ne le déclenche.
- **POS dual-open drawers (P3)** : commande optionnelle `cash:reap-stale-sessions` ou badge UI
  « drawer ouvert d'hier ». NE PAS resserrer le guard en per-branch (casserait la relève multi-cashier).
- **Management (P3)** : endpoint X-report mort (422 admin), pick reconciliation dual-open, 0% VAT dormant.

## Honnêteté outillage (tier evidence)
- **Tier 3 (stress harness)** : `foodking:e2e:stress --orders=100 --type=pos` a **401 sur 100/100**
  (auth du harness mal alignée avec le dev server — **bug d'OUTIL de test, pas produit** ; ses
  « 0 duplicates » sont donc vides de sens). À corriger pour que l'abuse Tier-3 fonctionne vraiment.
- **Tier 1 (MCP interactif)** : déconnecté cette session → capture-par-commande interactive indisponible.
- **Validation réelle reposant sur** : l'audit code-level par système + preuves DB live (fiscal
  gap-free, chain OK) + l'E2E live antérieur (Kiosk→KDS push **6 ms**, cascade order-status **512 ms**,
  dégradation WS→poll **5 s**, OSS JSON réel). Vitest 1881/0 · PHP 2716/0 · NF525 CHAIN OK.

## Convergence
5/5 systèmes GO/GREEN. **0 code change warranté** (le seul « heal-now » est un faux-positif
anti-drift déjà mitigé). P2/P3 → backlog calibré. Aucune sur-correction, aucune complexité
hors-vision V1.

## GO / NO-GO
**GO pour V1 LOCAL Le Cayenne** sur le périmètre audité (caisse + KDS + OSS + sync + management),
sous réserve des actions owner on-site déjà documentées (`migrate:fresh --seed`, supervisor
worker, cron `schedule:run`, UptimeRobot). Restent : (a) aligner l'auth du stress harness pour
un vrai abuse Tier-3 100+ commandes, (b) reconnecter le MCP pour la capture interactive
par-commande si désirée. Aucun de ces deux n'est un bloqueur produit.
