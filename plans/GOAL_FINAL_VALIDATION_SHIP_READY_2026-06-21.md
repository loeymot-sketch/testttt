# GOAL — FINAL VALIDATION → SHIP-READY (Le Cayenne V1, supervisor roadmap)

> Owner 2026-06-21 : « supervisor audit structure + notre position + ce qui reste + ultra-planifie tout
> jusqu'à la dernière étape testé+validé, test-e2e en boucle ». Ce GOAL est le **chemin final** de
> « convergé-mais-non-committé » → « testé + validé + prêt-à-brancher ». Pipeline/tâche = `ultra-audit-profond`.

## §0 — SUPERVISOR VERDICT (position, datée 2026-06-21)
**Verdict : V1-CRITIQUE VALIDÉ AU NIVEAU CODE ✅ — mais PAS ENCORE SHIPPABLE (gates owner + cérémonie commit).**
- HEAD `b40390a31` (inchangé), branche `goal/wizard-wysiwyg-builder-2026-06-14`.
- **⚠️ TOUT EST NON-COMMITTÉ** : 35 fichiers modifiés + 141 untracked ; ~24 source + 11 tests neufs CE cycle, + heals de sessions antérieures (XSS frozen, livreur, deliv-twin, kiosk-twin…). **Risque #1** : un `git` malheureux perd ~50 heals. = la 1ʳᵉ étape de « validé ».

## §1 — STRUCTURE & POSITION (les 5 systèmes + transverses)
| Système | Maturité | Preuve |
|---|---|---|
| POS/Caisse | ✅ VALIDÉ | 384 invariants + live encaissement/fiscal 2505 + refund-bypass healed |
| Borne/Kiosk | ✅ VALIDÉ | pricing SSOT + delivery_charge healed (3 surfaces) + paiement live |
| KDS | ✅ VALIDÉ | release-filter 4 chemins convergé + allergènes + sync |
| OSS | ✅ VALIDÉ | mur public/authed, verified-clean (pas de leak) |
| Livreur | ✅ VALIDÉ | live IDOR 403/COD-fiscal/idempotence + cash-session |
| Sync temps-réel | ✅ VALIDÉ | outbox→soketi→Echo + **dégradation prouvée** (soketi-down→REST, 0 perte) |
| Sécu/Auth | ✅ VALIDÉ | 2 sentinels systémiques (phantom-gate + phantom-route) + RBAC |
| Fiscal/NF525 | ✅ VALIDÉ | chain OK, gap-free, 100% backend ; Z-window 2min connu/déféré |
| Gestion/CMS | ✅ VALIDÉ | 36 surfaces, revenue-leak/user-enum/secret-index healed |
- **Convergence** : boucle broad 7 rounds → **2 dry consécutifs (R6+R7)** ; new-lens (concurrency/sync/fiscal/frontend) → reste = UX-tail. **Gates** : PHPUnit 3058/0, Vitest 2007/0, NF525 chain OK, frozen §7 diff = 0. **~50 heals** campagne, lentille jumeau-systémique.

## §2 — CE QUI RESTE (verdict honnête, 3 catégories)
### (A) AUTONOME — je peux finir sans owner
- **A1** Frontend a11y/i18n tail : ~394 composants ; classe icon-only-no-label a une longue traîne (rendements décroissants) → **sentinel systémique a11y** pour la fermer d'un coup plutôt qu'instance-par-instance.
- **A2** `SYNC_CONTRACT.md` périmé (6KB, HEAD `d6487f716`, 4 events) → refondre avec la cartographie 16-events (deep sync GOAL).
- **A3** Cross-surface e2e LIVE en boucle (borne→KDS→OSS→encaissement→Z ; livreur→cash ; online→refund→loyalty) jusqu'à 2 rounds secs (LE « test-e2e en boucle » demandé).
- **A4** Cérémonie commit (organiser les ~50 heals en commits cohérents, chemins explicites, secret-scan) — **commit LOCAL only**, push = gate.

### (B) OWNER-GATE — décision/countersign requis (je prépare tout, prêt)
- **G-PUSH** : pousser la branche (tout est LOCAL).
- **G-REFUND-GATE** : consolider le garde refund dans `OrderStateMachine` (FROZEN §7) — actuellement healé en couche controller (sûr), consolidation = LOCK owner.
- **G-LICENSE-KEY** : découpler `license_key` de `MIX_API_KEY` (une édition licence rote la clé API globale).
- **G-SETTINGS-READ** : 8 endpoints settings non-secrets lisibles par staff non-settings (gate vs documenter).
- **push-V2-branch / kiosk-loyalty-TTC** : V2-prep (mono-branche V1 non-atteignable) — différer V1.0.2 ou heal maintenant.

### (C) PHYSICAL-OWNER — hors-code
- **G-DEPLOY** : `.env` prod + triggers MySQL anti-DELETE (z_reports/audit_logs) + OVH.
- **G-HARDWARE** : vrai TPE caisse (SumUp/terminal) — V1 simulé assumé jusque-là.

## §3 — VAGUES FINALES (séquentiel, checkpoint+commit)
- **WF1 — A11y/i18n tail → dry** : sentinel a11y systémique + heal résidus + rebuild + visuel. Checkpoint : sentinel vert, 0 icon-only-no-label.
- **WF2 — SYNC_CONTRACT.md refondu** (16 events + dedup + recovery + degradation). Checkpoint : doc à jour, HEAD courant.
- **WF3 — Cross-surface e2e LIVE en boucle** (`test-e2e` skill) : 3 parcours money bout-en-bout × plusieurs runs jusqu'à 2 rounds secs P0+P1=0. Checkpoint : fiscal gap-free + chain OK + réconciliation panier==Z.
- **WF4 — Convergence finale** : full PHPUnit + Vitest + E2E + frozen diff 0 + NF525 attestation (`count, last_hash`). Checkpoint : tout vert.
- **WF5 — Cérémonie commit** (LOCAL, chemins explicites, secret-scan, messages structurés). Checkpoint : working tree propre, log lisible. **Push = G-PUSH.**
- **WF6 — Owner-gate pack** : LOCK docs + fixes prêts pour G-REFUND-GATE/G-LICENSE-KEY/G-SETTINGS-READ + décisions V2-prep. Présenter au owner. **STOP = décision owner.**
- **WF7 — Deploy-prep + GO/NO-GO** : checklist `.env` prod + boot-guards + trigger-MySQL sentinel + tag `v1.0.X-ship-ready`. **STOP = G-DEPLOY/G-HARDWARE owner.**

## §G — Owner gates (WHO/WHAT/WHERE)
| Gate | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|
| G-PUSH | owner | autorisation push | branche LOCALE | PENDING |
| G-REFUND-GATE | owner | LOCK countersign (frozen OrderStateMachine) | plans/LOCK_* | PENDING |
| G-LICENSE-KEY / G-SETTINGS-READ / push-V2 / kiosk-loyalty | owner | décision heal-now vs V1.0.2 | BRAIN | PENDING |
| G-DEPLOY | owner | .env prod + triggers MySQL + OVH | reports/deploy | PENDING |
| G-HARDWARE | owner | vrai TPE | BRAIN §2 | PENDING |

## §F — Règle finale (« testé + validé » = quoi)
DONE = WF1-WF5 verts (a11y dry, sync doc, **cross-surface e2e 2 rounds secs**, full-suite + NF525 + frozen 0, **tout committé** LOCAL) → projet **TESTÉ + VALIDÉ au niveau code**. **SHIPPÉ** = + WF6 (owner-gates résolus) + WF7 (G-PUSH + G-DEPLOY + G-HARDWARE owner). Je mène WF1-WF5 + prépare WF6/WF7 ; les actions physiques/push/deploy/frozen-consolidation restent owner (§10 human-gate). Production-perfect, jamais « presque ».
