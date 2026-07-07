# GOAL — V1 LOCAL Le Cayenne : CONVERGENCE GO-LIVE PROUVÉE
Date 2026-07-07 · Branche `pos/category-first-caisse-2026-06-23` · Base `24e8a09c3` · HEAD `1e884c63d` (26 commits non poussés)
Mission slug `convergence-golive` · Rapports `reports/test-e2e/convergence-golive/`

## §0 Préambule
- **§0.1 Working tree** : tout le code produit de session est COMMITÉ (26 commits). Bruit non-committé = suppressions d'artefacts pré-existants (playwright traces, .env.bak, bundles) → NE PAS committer. Chaque nouveau commit = fichiers explicites (jamais `git add .`, secret-guard actif).
- **§0.2 Pipeline par tâche** : `ultra-audit-profond` (5 spécialistes → TDD → RED → visual). Convergence finale : `test-e2e` adversarial.
- **§0.3 Convergence** : P0+P1=0 sur 2 cycles consécutifs à findings IDENTIQUES, frozen diff 0 hors LOCK, CHAIN OK ×4, Vitest+PHPUnit verts, captures analysées + bytes décodés + DB.
- **§0.4 Verify-before-report** : chaque finding = file:line + repro (grep/DB/bytes/capture). Le contexte « acquis » (owner-8 converged, 2 audits triagés) NE se ré-audite PAS ; on RÉ-ATTESTE l'ensemble consolidé.
- **§0.5 Backup filet** : `backup/pre-convergence-golive-2026-07-07` + `backup/pre-owner8-2026-07-06` existants.

## §1 Map principal (ancres vérifiées 2026-07-07 via ls/find)
| # | Système | Maturité | Ancre vérifiée | Tests existants |
|---|---|---|---|---|
| S1 | POS caisse | CONVERGÉ, à ré-attester | pos-wizard.js (FROZEN), PosComponent.vue | tests/Feature/Pos/* (CashDrawer, CounterCollect, Destroy…) |
| S2 | Borne kiosk | CONVERGÉ, à ré-attester | Kiosk{Wizard(FROZEN),App(FROZEN),CashInstruction,Waiting}Component.vue | tests/js/kiosk*, tests/Feature/Frontend/KioskEscpos* |
| S3 | KDS/cuisine | CONVERGÉ, à ré-attester | kdsSymbolic.js, KitchenTicketSymbolicFormatter.php | tests/js/kdsSymbolic*, tests/Feature/Hardware/KitchenTicket*, kitchenParityRealData.spec.js |
| S4 | Fiscal NF525 | 1 décision ouverte (C33) | ZReportService.php (FROZEN), VerifyZMembershipCommand.php | tests/Feature/Fiscal/* (à créer ZReportContinuity) |
| S5 | Synchro temps-réel | 2 fixes livrés, à ré-attester | eventContract.js, OrderDetailsComponent.vue | tests/js/eventContractChannelRefcount.spec.js, orderDetailsClientPolling.spec.js |
| S6 | Déploiement+machines | owner-physique | tools/deploy-owner8.sh | n/a (gate physique) |

## §2 Map séparée (STANDALONE — HORS scope V1, mandat §3bis)
- Mobile RN (`mobile/`) — non câblé backend. Web (`/Downloads/web/`) — non câblé. Ne PAS toucher.

## §3 — S1 POS caisse
### Contract
Caisse staff : commande wizard (boissons/oignon cuit/images viandes) + paiement + tiroir + Z NF525 + impression instantanée.
### Frozen zones : `public/js/pos-wizard.js`, `PaymentComponent.vue`, `admin-pos-v4.blade.php` (CLAUDE.md §7 ; cf. `memory/reference_frozen_zones.md`).
### Sous-systèmes
- **Sub 1.1 — Wizard boissons+oignon cuit+images** (LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 CLOSED). Tasks : T-3.1.1 ré-attester 15 boissons « Incluse » prix figé ; T-3.1.2 exclusivité oignon cru↔cuit + O̲ ; T-3.1.3 images viandes 7/7. Acceptance : `tests/js/posWizardDrinkFallback.spec.js` + `posWizardOnionCuit.spec.js` + `posWizardMeatImages.spec.js` PASS + E2E caisse GREEN (capture + DB total inchangé).
- **Sub 1.2 — Perf** (W5). Tasks : T-3.2.1 vignettes webp ; T-3.2.2 0 refetch/ajout ; T-3.2.3 pos_cart_v3 scope. Acceptance : `tests/js/posDrinksCatalogPersistence.spec.js` + mesure transfert < 1 Mo au clic catégorie (Playwright network).
- **Sub 1.3 — Impression** (W4). Tasks : T-3.3.1 fire-and-forget toast < 1s ; T-3.3.2 window.print jamais auto. Acceptance : `tests/js/pos/receiptNonBlockingPrint.spec.js` PASS + E2E encaissement 0 dialog gris.
- **Sub 1.4 — P3 idempotence** (différé). Task T-3.4.1 documenter clé idempotence régénérée (PosComponent.vue:1496). Acceptance : décision owner (fix ou différé documenté BRAIN).

## §4 — S2 Borne kiosk
### Contract : commande borne wizard → panier → upsell → paiement Plan B → ticket serveur ; offline queue ; temps-réel dispo.
### Frozen zones : `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`.
### Sous-systèmes
- **Sub 2.1 — Commande+oignon cuit borne**. Tasks : T-4.1.1 flux complet 201 ; T-4.1.2 exclusivité cru↔cuit snapshot 0€. Acceptance : `tests/js/kioskWizardOnionCuit.spec.js` PASS + E2E borne A00xx créée (capture + composition_snapshot DB).
- **Sub 2.2 — Ticket serveur unifié**. Tasks : T-4.2.1 orderId passé cash-instruction ; T-4.2.2 bytes = design caisse « A REGLER EN CAISSE ». Acceptance : `tests/Feature/Frontend/KioskEscposCounterDeferredTest.php` PASS + bytes décodés 32 col.
- **Sub 2.3 — Synchro dispo (Echo refcount)**. Task T-4.3.1 canal branch.1 survit au démontage écran attente. Acceptance : `tests/js/eventContractChannelRefcount.spec.js` PASS.

## §5 — S3 KDS/cuisine
### Contract : écran /kds 3 cartes + ticket cuisine symbolique ; notes client ; boissons ; O̲.
### Frozen zones : aucune (kdsSymbolic.js, formatter, renderer NON frozen).
### Sous-systèmes
- **Sub 3.1 — Notes+boissons cuisine**. Tasks : T-5.1.1 note sur carte KDS ; T-5.1.2 boissons nom complet 3 chemins + extraction formule borne. Acceptance : `tests/js/kdsSymbolicInstruction.spec.js` + `kdsSymbolicDrinks.spec.js` + `tests/Feature/Hardware/KitchenTicketDrinkVisibilityTest.php` PASS.
- **Sub 3.2 — Symbole O̲ + parité**. Tasks : T-5.2.1 O souligné écran+ticket (ESC/POS `1B 2D 01 4F 1B 2D 00`) ; T-5.2.2 parité PHP↔JS 391 rows. Acceptance : `tests/Feature/Hardware/KitchenTicketOnionCuitSymbolTest.php` + `kitchenParityRealData.spec.js` PASS.
- **Sub 3.3 — Layout 3-cartes**. Task T-5.3.1 cartes plein écran hauteur + pastille +N, nouvelles lignes ne cassent rien. Acceptance : capture /kds paysage+portrait analysée, mesure DOM.

## §6 — S4 Fiscal NF525 (DÉCISION C33 sous gate)
### Contract : chaîne Z signée HMAC, allocation fiscal_sequence_no, partition sans trou.
### Frozen zones : `ZReportService.php`, `FiscalSequenceService.php`, `AuditLogService.php` (§7 + §8).
### Sous-systèmes
- **Sub 4.1 — Décision C33** (OWNER GATE G1). Task T-6.1.1 : SI owner approuve → appliquer LOCK_ZREPORT_C33_DEAD_WINDOW (borne basse = closed_at Z précédent). Acceptance : `tests/Feature/Fiscal/ZReportContinuityTest.php` (test TO BE CREATED) PASS + partition sans trou + refund-mirror préservé + `fiscal:verify-chain --all` CHAIN OK ×4 + `fiscal:verify-z-membership` 0 TROU. SINON → différé documenté (verify-z-membership détectif suffit V1).
- **Sub 4.2 — Attestation chaîne**. Task T-6.2.1 : chain hash inchangé/append-only sur toute la plage GOAL. Acceptance : `fiscal:verify-chain --all` CHAIN OK ×4 + audit_logs count ≥ baseline.

## §7 — S5 Synchro temps-réel
### Contract : outbox afterCommit, Echo branch.{id}, polling fallback, suivi client.
### Frozen zones : aucune (eventContract.js, OrderDetailsComponent.vue NON frozen).
### Sous-systèmes
- **Sub 5.1 — Echo refcount**. Task T-7.1.1 leave seulement à refcount 0. Acceptance : `tests/js/eventContractChannelRefcount.spec.js` (8) PASS + non-régression staff.
- **Sub 5.2 — Polling suivi web**. Task T-7.2.1 15s + arrêt terminal + beforeUnmount. Acceptance : `tests/js/orderDetailsClientPolling.spec.js` (5) PASS.
- **Sub 5.3 — Non-régression staff**. Task T-7.3.1 POS↔KDS↔OSS temps-réel intact (attesté GREEN). Acceptance : e2e cross-surface commande→KDS visible < 5s.

## §8 — S6 Déploiement + machines (OWNER-PHYSIQUE)
### Contract : push → VPS deploy → machines refresh → preuve.
### Sous-systèmes
- **Sub 6.1 — Push+deploy** (GATE G5). Task T-8.1.1 : owner `git push` + `tools/deploy-owner8.sh` (rebuild+vignettes+2 ponts+POS_PRINT_SILENT_ONLY+seeders+chain). Acceptance : log deploy HEAD == origin + hashes bundles bustés + CHAIN OK VPS.
- **Sub 6.2 — Machines** (GATE G3). Task T-8.2.1 : hard-reload écran cuisine + pont borne caché VBS. Acceptance : photos preuve cowork (COWORK_VERIF_BORNE_KDS).

## §A Armée d'agents (fan-out matrice §Axis-4)
Frontend visual : Architect+Security+UX+Implementer+RED+QA/RED Visual. Backend/fiscal : +DBA. Sync : +SRE. Implémenteurs JAMAIS parallèles sur mêmes fichiers. Rapports disque `reports/test-e2e/convergence-golive/<wave>-<role>.json`.

## §X Vagues (checkpoint 6-points + interrupt-resume chacune)
| Wave | Scope | Parallélisme | Checkpoint | Gate |
|---|---|---|---|---|
| W1 | Pre-flight : backup branch, baselines (Vitest/PHPUnit counts, audit_logs hash), confirmer gates | séquentiel | baselines commitées | — |
| W2 | RÉ-ATTESTATION e2e S1+S2+S3+S5 au HEAD consolidé (test-e2e 4 surfaces + adversaires) | fan-out ∥ audit | P0+P1=0 cycle 1 | — |
| W3 | Décision C33 (S4) — si G1 owner OK : impl LOCK + fiscal e2e ; sinon différé | séquentiel (fiscal) | CHAIN OK + z-membership 0 trou | **G1** |
| W4 | P3 polish (label oignon cuit sous LOCK si owner OK, idempotence POS décision) | séquentiel | frozen diff = LOCK only | G-polish |
| W5 | CONVERGENCE finale : cycle 2 e2e identique → P0+P1=0 ×2, tag | fan-out ∥ | 2 cycles identiques | — |
| W6 | Déploiement (owner) + preuve machines | owner-physique | log deploy + photos | **G3,G5** |
Interrupt-resume : commit WIP `wip(golive-wN)` + `reports/test-e2e/convergence-golive/INTERRUPT_<wave>.md` + BRAIN §2.

## §G Owner gates (WHO/WHAT/WHERE)
| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | Décision C33 : appliquer trou fiscal sous LOCK ou différer | Owner physique | choix APPLIQUER/DIFFÉRER | LOCK §10 sign-off | PENDING |
| G2 | Vraie photo Cordon Bleu (watermark PNGTREE) | Owner physique | fichier image | public/images/ | PENDING |
| G3 | Pont borne caché VBS (flash terminal) + hard-reload machines | Owner/cowork | photos preuve | reports/handoff/ | PENDING |
| G4 | Purge .env.bak* + rotation clés AWS | Owner physique | rotation receipt | BRAIN §2 | PENDING |
| G5 | git push 26 commits + deploy VPS | Owner (script fourni) | log deploy | scratchpad/deploy-owner8.sh | PENDING |
| G-polish | Label « ✕ Sans Oignons cuits »→« ＋ » (frozen) | Owner sign-off LOCK | décision | LOCK owner8 §10 | PENDING |

**Gate-waiting** : W1/W2/W5 (ré-attestation locale) NE dépendent d'AUCUN gate → exécutables immédiatement. W3 dépend G1. W4 dépend G-polish. W6 dépend G3+G5. Owner traite G1-G5 pendant que W1/W2/W5 tournent.

## §R Références
`ultra-audit-profond`, `test-e2e`, `lock-plan` skills ; CLAUDE.md §§4-13 ; PROJECT_BRAIN.md §2 ; reports/test-e2e/owner-8-problemes/CONVERGENCE_FINAL.md + FINAL_E2E_ATTESTATION.md ; reports/audit-externe-triage-2026-07-06/VERDICT.md ; reports/audit-synchro-triage-2026-07-07/VERDICT.md ; LOCK_ZREPORT_C33 + LOCK_POSWIZARD_KIOSKWIZARD_OWNER8.

## §F Règle finale
DONE = les 4 surfaces ré-attestées P0+P1=0 sur 2 cycles identiques + preuve (captures+bytes+DB+CHAIN) ; C33 tranché (appliqué-prouvé OU différé-documenté) ; frozen diff 0 hors LOCK ; Vitest+PHPUnit verts ; CHAIN OK ×4 ; puis gate owner déploiement. **Production-perfect prouvé, pas « presque ». Aucun retour tant que tout n'est pas vert-prouvé.**
