# EXECUTE BRIEF — CV1-M03-GATES-DRAFT (M-03)

## INVIOLABLE

1. **Lectures obligatoires (ordre strict, lecture *complète* avant toute écriture)** :
   - `AGENTS.md` § *Parcours obligatoire* + § *Authoritative multi-agent bounded cycle* — rôle `codex-extension`, format `output_codex.json`, sortie JSON unique.
   - `missions/CV1-M03-GATES-DRAFT/input.json` — `allowlist` (9 paths : 8 briefs + `GATE_LOG.md`), `off_limits`, `self_audit_checklist`, `objective`, `mandatory_tests`. **Tu ne dépasses JAMAIS l'allowlist.**
   - `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § 1 *État des gates* (les 7+1 entrées `TO_DRAFT` à transformer en briefs signables) + § 2 *Cartographie code réelle* (file:line ancrages — utilisés tels quels, **pas re-grep, pas de drift**) + mission **M-03** (§ 4) — c'est ton scope.
   - `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 *Required Human Gates* (table autoritaire — recommandation par défaut **par gate**) + § 0 `HUMAN_GATES_FIRST_WITH_PARALLEL_NO_CODE_WORK` (pourquoi Caisse V1 est bloqué jusqu'à signature humaine).
   - `plans/masterplay/MASTERPLAY_DISCIPLINE.md` § 3.4 *Pas de gate auto-approuvée* + § 3.6 *Diff minimal* + § 10 *Anti-patterns interdits*.
   - `.cursor/rules/human-gates.mdc` — **format gate brief obligatoire** (Trigger / Affected Subsystems / Invariants at Risk / Decision Required / Options 1‑3 / Approval VIDE / Date) + § *Hard Gates* + § *Absolute Prohibitions* (lignes 79‑86 — auto-approval interdit).
   - `.cursor/rules/project-invariants.mdc` — 6 invariants FoodKing (référence canonique, à citer textuellement quand un gate les engage).
   - `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` — **modèle de référence dense** (Trigger multi-règles, Subsystems file:line, Invariants à risque, Plan minimal, Justification, Rollback, Tests critiques, Options A/B/C, Approval **non rempli**). Ne pas réécrire ; t'en inspirer pour la **densité**.
   - `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md` — modèle alternatif (4 options A/B/C/D, escalation clause, owners co‑signataires).
   - `docs/gates/GATE_LOG.md` — format de **trail** (table `| Date | Gate ID | Brief file | Frozen files touched | Decision | Approver | Commit SHA / Cycle |`) + § *Process futur*.

2. **Allowlist STRICTE** — tu ne touches QUE ces 9 chemins, rien d'autre :
   - `docs/gates/GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25.md` (NEW)
   - `docs/gates/GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25.md` (NEW)
   - `docs/gates/GATE_PAYMENT_LEDGER_V1_2026-04-25.md` (NEW)
   - `docs/gates/GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25.md` (NEW)
   - `docs/gates/GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25.md` (NEW)
   - `docs/gates/GATE_OFFLINE_SCOPE_V1_2026-04-25.md` (NEW)
   - `docs/gates/GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25.md` (NEW)
   - `docs/gates/GATE_STRIPE_CENTS_ACTIVE_2026-04-25.md` (NEW)
   - `docs/gates/GATE_LOG.md` (modify — ajout 8 lignes en § *Trail courant*, ne **pas** réécrire le rétroactif)

3. **Off-limits absolus** — toute écriture hors allowlist déclenche `SCOPE_PRESSURE` + STOP :
   - `app/**`, `resources/**`, `routes/**`, `database/**`, `tests/**`, `scripts/**`, `config/**`, `.cursor/**`, `AGENTS.md`.
   - **Gates existants à ne JAMAIS modifier** (déjà signés ou en attente humaine, hors scope mission) : `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`, `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`, `docs/gates/GATE_W2_KPI_REVISION_2026-04-26.md`, `docs/gates/GATE_W2_CUTOVER_2026-04-26.md`. Tu peux les **citer** comme références ; jamais les éditer, jamais re-rédiger leurs options.
   - Tout autre fichier `docs/gates/GATE_*.md` rétroactif (cf. `GATE_LOG.md` § *Trail rétroactif* du 2026-04-14/15/20) — lecture autorisée, écriture interdite.

4. **Aucune signature, aucune approbation cochée** :
   - Champ `Approval` de chaque brief → blocs vides à remplir UNIQUEMENT par humain : `[ ] Approved — option selected: ___`, `[ ] Cancelled`, `Approved by: ___`, `Date: ___`.
   - Décision dans `GATE_LOG.md` → colonne `Decision` = `PENDING_HUMAN_GATE` pour chaque nouvelle ligne ; colonne `Approver` = `(en attente — <profils>)` ; colonne `Commit SHA / Cycle` = `CV1-M03-GATES-DRAFT`.
   - Aucune ligne `[x] Approved`, aucune mention `Approved by: Claude/Codex`, aucune date d'approbation auto-remplie. Violation = `REWORK` immédiat (cf. `MASTERPLAY_DISCIPLINE.md` § 3.4).

5. **Aucun code produit** : tu ne touches **aucun** fichier hors `docs/gates/`. Pas de migration, pas de refactor, pas d'ajout de test. Si tu as besoin de "vérifier" un file:line cité dans un gate, **lis-le** (read-only) — n'écris pas.

## OBJECTIF EXACT

Produire **8 gate briefs** au format `human-gates.mdc` (chacun avec Trigger précis, Affected Subsystems file:line, Invariants at Risk, Decision Required formulée comme une question fermée, **2 à 3 options** chiffrées avec impact (story-points / semaines / complexité), recommandation technique **non-décisive**, Evidence requise pour signature, Rollback prévu, bloc Approval **vide**), couvrant les 7 entrées `TO_DRAFT` listées en `PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § 1 + `GATE_STRIPE_CENTS_ACTIVE` (8e brief). Mettre à jour `docs/gates/GATE_LOG.md` § *Trail courant* avec **8 nouvelles lignes** statut `PENDING_HUMAN_GATE` (commit cycle `CV1-M03-GATES-DRAFT`). Briefs **prêts à signer** par TL + BE + QA NF525 + UX + Product + DBA selon les profils impactés. Aucun gate auto-approuvé.

## CARTOGRAPHIE PRÉ-ANALYSÉE — Sources d'évidence par gate

> **Règle d'or** : chaque brief de gate cite ses sources **par chemin exact** (audits ou plans) sans re-paraphraser. Les ancrages file:line viennent de `PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § 2 (déjà vérifiés par sous-agent `explore`). Tu **ne re-grepes pas** : tu cites.

### Gate 1 — `GATE_FROZEN_ZONES_CAISSE_V1`
- **Plan source** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 ligne `GATE_FROZEN_ZONES_CAISSE_V1` (Options A open all scoped / B refuse / C partial allowlist by method/surface — recommandation default : **C**).
- **Bloque** : M-06 (POS guards), M-09 (branch isolation), M-10 (OS/FOS symmetry) — cf. masterplay § 1.
- **Audits référents** : `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` (frozen zones identifiés), `reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md` (revue Claude § frozen zones), `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md` (POS surface).
- **Invariants concernés** : #5 OS/FOS Symmetry, #6 Frozen Zones, #4 Dispatch after commit (`OrderService::changeStatus` L1489‑1540, dispatch L1523+).
- **Précédent gate similaire** : `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` (8 cycles P0 frozen — modèle structurel).

### Gate 2 — `GATE_FISCAL_KIOSK_SCOPE_V1`
- **Plan source** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 ligne `GATE_FISCAL_KIOSK_V1` (Options A kiosk Z direct / B POS finalizes / C no paid kiosk V1 — recommandation : **C** si pas d'auditable Z, **B** si POS finalization prête).
- **Bloque** : M-08 (fiscal Z NF525), M-11 (kiosk runtime).
- **Audits référents** : `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md` (kiosk fiscal flow), `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md` (chaîne fiscale), `reports/audit/CLAUDE_TERMINAL_CODEX_401_SANITIZE_2026-04-25.md` (kiosk auth).
- **Invariants concernés** : #4 Dispatch after commit (Z scellé), invariant fiscal NF525 (CLAUDE.md § 8 escalade humaine obligatoire).
- **Anchor code** : `app/Services/FrontendOrderService.php:791` (`finalizePaidKioskOrder`), `app/Http/Controllers/Frontend/OrderController.php:101-118` (TPE confirm).

### Gate 3 — `GATE_PAYMENT_LEDGER_V1`
- **Plan source** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 ligne `GATE_PAYMENT_LEDGER_V1` (Options A ledger full / B restricted pilot — recommandation : **B** pour pilote, **A** seulement si paiements larges obligatoires).
- **Bloque** : choix entre M-04A (`CAISSE_V1_PAYMENT_LEDGER_FULL`) et M-04B (`CAISSE_V1_PAYMENT_PILOT_RESTRICT`) — exclusion mutuelle, **un seul** des deux exécute.
- **Audits référents** : `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` (concept Codex `PaymentProof`), `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md` § paiement.
- **Invariants concernés** : #1 Pricing SSOT (paiement = source vérité financière), #4 Dispatch after commit (events `PaymentLedgerEntryRecorded`).
- **Anchor code** : `app/Services/PaymentService.php` (frozen sous LOCK B 9.2/9.3 — partial release), tables `payment_proofs` / `payment_ledger` (à créer).

### Gate 4 — `GATE_KDS_BUMP_AUTHORITY_V1`
- **Plan source** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 ligne `GATE_KDS_BUMP_V1` (Options A local / B server `expected_status` — recommandation : **B** avec feature flag).
- **Bloque** : M-07 (KDS release transitions).
- **Audits référents** : `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`, `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` § KDS.
- **Invariants concernés** : #2 OrderStatus enum authoritative, #3 branch_id isolation (KDS multi-écran), #5 OS/FOS symétrie.
- **Anchor code** : `app/Http/Requests/OrderStatusRequest.php:45-47` (manque `expected_status` body), `app/Services/KitchenDisplaySystemOrderService.php:117-168` (`changeStatus` + lock + transition), `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:130, 786-793` (Swiper + cap 50).

### Gate 5 — `GATE_SCHEMA_MIGRATIONS_CAISSE_V1`
- **Plan source** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 ligne `GATE_SCHEMA_MIGRATIONS_V1` (Options A all / B subset / C none — recommandation : **A** avec rehearsal + backup).
- **Bloque** : M-04 (paiement, ledger ou pilot), M-05 (`order_quotes`), M-08 (fiscal `z_reports.status CLOSING`), M-13 (migration safety).
- **Audits référents** : `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md` § migrations, `tasks/phase9-sync/LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md` (précédent migration scopée).
- **Invariants concernés** : #6 Frozen zones (toute migration = hard gate par défaut, cf. `human-gates.mdc:19`), #3 branch_id isolation (clés composites `(branch_id, *)`).
- **Liste prévisionnelle V1** : `payment_proofs` (M-04A), `payment_ledger` (M-04A), `kitchen_releases` (M-07), `order_quotes` (M-05), `idempotency_keys` extension (M-04A/M-05), `z_reports` ajout `STATUS_CLOSING` (M-08).

### Gate 6 — `GATE_OFFLINE_SCOPE_V1`
- **Plan source** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 ligne `GATE_OFFLINE_SCOPE_V1` (Options A cash-only / B card with ledger queue / C no offline — recommandation : **A** cash-only, backend refuse CB/TR).
- **Bloque** : M-11 (kiosk runtime).
- **Audits référents** : `reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md` § offline, `reports/audit/SIMULATION_PARCOURS_PROD_CHECKPOINTS_2026-04-25.md` (parcours kiosk déconnecté).
- **Invariants concernés** : #1 Pricing SSOT (offline = pas de quote signé serveur), #4 Dispatch after commit (queue offline → reconcile post-reconnect).
- **Anchor code** : `resources/js/helpers/kioskOfflineQueue.js:135, 330` (prefix `offline_`), `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292, 297-305` (détection + fallback total), `resources/js/store/modules/kioskCart.js:483-486` (réponse synthétique).

### Gate 7 — `GATE_WEB_PAYMENT_SCOPE_V1`
- **Plan source** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 ligne `GATE_WEB_PAYMENT_SCOPE_V1` (Options A active / B off V1 — recommandation : **B** sauf si obligatoire).
- **Bloque** : M-17 (web Stripe scope).
- **Audits référents** : `reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md` § web, `reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md` § paiement web.
- **Invariants concernés** : #1 Pricing SSOT (web /payment/{order}/pay raw id à protéger), #6 Frozen zones (routes paiement publiques).
- **Anchor code** : routes `/payment/{order}/pay` (cf. masterplay § M-17 — chemins publics raw id à désactiver ou sécuriser via `PaymentIntent` signé).

### Gate 8 — `GATE_STRIPE_CENTS_ACTIVE`
- **Plan source** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` § 3 ligne `GATE_STRIPE_CENTS_ACTIVE` (Options A Stripe active => P0 / B off V1 — dépend du gate web-payment).
- **Bloque** : M-17 (Stripe cents fix).
- **Audits référents** : `reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md` § Stripe, `reports/audit/MEGA_RAPPORT_FINAL_DISPUTE_CAISSE_POS_KIOSK_KDS_2026-04-25.md` § paiement card.
- **Invariants concernés** : #1 Pricing SSOT (un bug cents/euros = perte 100x).
- **Anchor évidence** : feature flag Stripe à confirmer **active sur prod** (sinon le bug cents devient un issue P2 dormant ; si actif, devient P0). Flag à identifier humain (config env / `config/payment.php`).

## SPÉCIFICATION DÉTAILLÉE — STRUCTURE DE CHAQUE BRIEF

Chaque fichier `docs/gates/GATE_<NAME>_2026-04-25.md` contient **exactement** ces sections, dans cet ordre, balisage strict (cohérent avec `human-gates.mdc` § Gate Brief Format + densité de `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`) :

1. **Titre H1** : `# Gate Brief — <NOM HUMAIN> — 2026-04-25` (un seul H1 par fichier).
2. **Bandeau métadonnées** (liste à puces) :
   - `Gate ID: GATE_<NAME>_2026-04-25`
   - `Statut: PENDING_HUMAN_GATE`
   - `Auteur du brief: Claude (orchestrateur Caisse V1) — délégation rédaction options à codex-extension cycle CV1-M03-GATES-DRAFT`
   - `Date d'émission: 2026-04-25`
   - `Plan parent: plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md § 3 (gate row) + plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md § 1`
   - `Bloque: <missions M-XX listées>`
   - `Recommandation par défaut (super master): <option lettre + résumé en 1 ligne>`
3. **`## Trigger`** — condition exacte qui ouvre ce gate. Cite **règle source** (`.cursor/rules/human-gates.mdc:<line>` Hard Gate concerné, p.ex. `:19` schema migration, `:20` auth logic, `:23` frozen zone, `:24` invariant violation, `:26` branch_id isolation). 5‑12 lignes.
4. **`## Affected Subsystems`** — table Markdown `| Path | Lignes | Rôle |` ou liste à puces avec `file:line`. Tous les ancrages viennent de la cartographie ci-dessus (PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md § 2). 5‑15 entrées selon la portée.
5. **`## Invariants at Risk`** — liste numérotée des invariants `.cursor/rules/project-invariants.mdc` engagés (citer "Invariant #N <Nom>" + 1 ligne pourquoi le gate les touche). Si aucun engagé, écrire explicitement `None engagés directement — gate de scope/produit, pas d'invariant`.
6. **`## Decision Required`** — UNE question fermée, 1‑3 lignes, qui isole exactement l'arbitrage humain. Exemple template : `Le tenant FoodKing autorise-t-il <X> en V1, et si oui sous quelle option <A|B|C> ?`.
7. **`## Options`** — sous-sections `### Option A — <titre>`, `### Option B — <titre>`, optionnellement `### Option C — <titre>` + obligatoirement `### Option D — Cancel cycle / Différer V1.1` (sauf si Cancel n'a pas de sens — alors le justifier). Pour CHAQUE option :
   - **Action** (en 2‑5 lignes — quoi faire concrètement, fichiers/migrations/services/services touchés au niveau **catégorie**, pas pseudo-code).
   - **Conséquence** (impact chiffré : story-points 1‑8, semaines `~Xs`, complexité `low|medium|high`, surfaces touchées). Si tu ne peux honnêtement pas chiffrer, écris `TBD: humain à chiffrer en revue` — **pas d'invention**.
   - **Risques résiduels** (1‑3 puces).
8. **`## Recommandation technique (non-décisive)`** — 4‑8 lignes : rappelle l'option par défaut du super master, justifie techniquement (sans préjuger du business), liste les conditions sous lesquelles une autre option deviendrait préférable. Termine par : `Décision finale = humain. Cette section n'est pas une approbation.`
9. **`## Evidence requise pour signature`** — checklist `[ ]` (jamais `[x]`) : artéfacts qu'un humain doit avoir vu **avant** de cocher Approval (ex: `[ ] Lecture de l'option choisie`, `[ ] Confirmation TL + BE + QA NF525 si fiscal`, `[ ] Lecture du runbook rollback`, `[ ] Validation owner DBA si migration`, `[ ] Lecture de la mission M-XX bloquée`).
10. **`## Rollback prévu (si option A/B exécutée puis rejetée)`** — 3‑6 lignes : feature flag prévu (`<flag_name>`), fenêtre max (jours), runbook référent (`docs/runbooks/<X>.md` *à créer en M-13/M-20* — citer comme **planifié**, pas comme existant si ce n'est pas vrai).
11. **`## Approval`** — STRICTEMENT ce bloc, lignes vides à remplir par humain :
   ```
   - [ ] Approved — option selected: ___
   - [ ] Cancelled
   
   Approved by: ___________________ (rôle)
   Co-signed by: ___________________ (rôle)  ← ajouter co-signataires selon profils impactés
   Date: ___________
   ```
12. **`## Resumption Protocol`** — 4 puces standard : (1) Approval section ci-dessus complétée par humain ; (2) Décision recordée dans `docs/gates/GATE_LOG.md` § Trail courant ; (3) Mission(s) bloquée(s) `M-XX` débloquée(s) dans `plans/masterplay/MASTERPLAY_QUEUE.md` (passage `BLOCKED → PENDING`) ; (4) Plan parent (super master + masterplay) cité comme à jour pour le run suivant.
13. **`## Annexes & références`** — liste à puces : audits, plans, gates précédents similaires, ancrages clés. **Pas de duplication** du contenu — uniquement chemins/sections.

## SPÉCIFICATION GATE PAR GATE — Options à rédiger (recommandations non-décisives)

> Pour chaque gate ci-dessous, tu rédiges un brief complet selon la structure §SPÉCIFICATION DÉTAILLÉE. Les options listées ici sont **des squelettes obligatoires** : tu peux les enrichir mais pas les remplacer ni en supprimer (sauf justification explicite dans `risks` du JSON sortie). Chiffrages = repères ; si l'audit source ne permet pas un chiffrage honnête, écris `TBD: humain à chiffrer en revue`.

### Brief 1 — `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25.md`

**Decision Required** : Quels fichiers frozen ouvre-t-on, par quelle granularité (fichier entier vs méthode/surface), pour permettre l'exécution de la séquence Caisse V1 (M-06, M-09, M-10) ?

- **Option A — Open all scoped (frozen entier des fichiers listés en LOCK)** : ouverture pleine de `OrderService.php`, `FrontendOrderService.php`, `PaymentService.php`, `KitchenDisplaySystemOrderService.php`, `routes/api.php`, `OrderController.php` (frontend) jusqu'à fin Caisse V1. **Conséquence** : déblocage maximal, ~2 semaines de cycles parallèles GPT, **risque de régression cross-méthode** élevé (chaque commit GPT touche potentiellement des méthodes hors scope mission). **Risque résiduel** : drift de scope, dette d'audit.
- **Option B — Refuse (maintenir frozen)** : aucune ouverture ; M-06/M-09/M-10 différés post-V1. **Conséquence** : V1 ne peut pas livrer les revenue guards POS ni la branch isolation P0 ; sentinels M-02 #7‑#11 et #1‑#6 restent rouges. **Risque résiduel** : V1 ne livre pas les fixes P0 — décision business de différer le go-live.
- **Option C — Partial allowlist by method/surface (recommandé super master)** : ouverture **par méthode** dans chaque fichier frozen, listée explicitement (`OrderService::changeStatus`, `OrderService::changePaymentStatus`, `FrontendOrderService::finalizePaidKioskOrder`, `PaymentService::cashBack`, etc. — cf. masterplay § 2.2). **Conséquence** : ~`8sp` de coordination en plus pour cataloguer les méthodes ouvertes mais pas de drift cross-méthode ; chaque mission M-XX référence sa sous-allowlist méthode. **Risque résiduel** : surcoût de gouvernance, ralentissement de ~3-5 jours sur la séquence.
- **Option D — Cancel cycle / Différer Caisse V1** : abandon V1 actuel, replan V1.1.

**Recommandation technique** : **C** (cohérent avec super master § 3 default `partial allowlist by method/surface`). Critère pour basculer en A : si TL + BE acceptent un audit Claude renforcé après chaque mission. Critère pour basculer en B : si la dette d'audit excède les ressources humaines disponibles.

**Co-signataires Approval** : TL + BE + QA NF525 (si méthode `OrderService::changePaymentStatus` ouverte).

### Brief 2 — `GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25.md`

**Decision Required** : Le kiosk émet-il un ticket fiscal NF525 immédiatement après TPE OK, ou bascule-t-il systématiquement vers le POS pour finalisation, ou refuse-t-on tout paiement kiosk en V1 ?

- **Option A — Kiosk Z direct (kiosk émet ticket NF525 immédiat)** : `FrontendOrderService::finalizePaidKioskOrder` (`app/Services/FrontendOrderService.php:791`) déclenche aussi sceau fiscal HMAC + insertion ligne Z. **Conséquence** : UX kiosk fluide (ticket auto), `~5sp` (fiscal sealing service + tests NF525) + `~3sp` (audit chain). **Risque résiduel** : si le kiosk perd la connexion entre TPE OK et seal, la chaîne HMAC peut casser → escalade NF525.
- **Option B — POS finalize (kiosk émet l'intent, POS finalise fiscalement)** : kiosk paie, mais le POS ouvert récupère la commande dans une file "à finaliser" et un caissier signe la fiscalisation manuellement. **Conséquence** : `~3sp` côté POS (UI file + bouton), latence pour le client final. **Risque résiduel** : opérationnel, dépend de la présence d'un POS actif sur la branche.
- **Option C — No paid kiosk V1 (recommandé super master si pas de Z auditable)** : kiosk **lecture-seule** ou commande différée payée au comptoir POS. Le bouton "Payer" est masqué. **Conséquence** : `~2sp` (UI désactivation + tests), pas de risque fiscal. **Risque résiduel** : régression UX kiosk (pas de paiement self-service).
- **Option D — Cancel / Différer kiosk V1.1**.

**Recommandation technique** : **C** par défaut si aucun mécanisme Z auditable n'est déjà en place ; **B** si M-08 a livré un mécanisme POS-finalize prêt ; **A** uniquement si HMAC chain + audit log NF525 sont validés par QA NF525 + tests `FiscalSealingHmacTest` verts.

**Co-signataires Approval** : TL + QA NF525 + UX (option C impacte UX kiosk).

### Brief 3 — `GATE_PAYMENT_LEDGER_V1_2026-04-25.md`

**Decision Required** : Caisse V1 implémente-t-elle un ledger de paiement complet (option A) ou un pilote restreint avec garde serveur (option B) ? Choix exclusif — un seul de M-04A / M-04B exécute.

- **Option A — Ledger full (`payment_ledger` + `payment_transactions` + state machine `pending|authorized|captured|refunded|voided|failed` + idempotency par callback)** : implémente M-04A (cf. masterplay § M-04A). **Conséquence** : `~13sp` (~2 semaines), 2 migrations frozen, refactor `PaymentService`, refactor `paymentConfirm` controller, 5 tests Feature obligatoires, audit immuable. **Risque résiduel** : périmètre large = risque de régression fiscal (nécessite M-08 en parallèle).
- **Option B — Restricted pilot (recommandé super master)** : implémente M-04B (cf. masterplay § M-04B). Refus serveur explicite hors pilote, UI désactivée hors pilote, audit attempts. Aucun branchement silencieux par `.env`. **Conséquence** : `~5sp` (~1 semaine), pas de migration, mineur sur `PaymentService`. **Risque résiduel** : dette technique reportée — V1.1 devra livrer ledger complet.
- **Option C — Cancel / Différer paiement V1.1**.

**Recommandation technique** : **B** par défaut (super master). Critère pour A : si le board exige paiements larges (Stripe, multi-card) en V1 et accepte le coût de 2 semaines. Critère pour C : si V1.1 est imminent (< 30 jours) ou si fiscal NF525 force un report global.

**Co-signataires Approval** : TL + BE owner + QA NF525.

### Brief 4 — `GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25.md`

**Decision Required** : Qui peut bumper une commande KDS d'un statut à l'autre (cuisine seule, manager + cuisine, ou cashier + manager + cuisine), et l'autorité est-elle calculée local (front decide) ou serveur (`expected_status` body required) ?

- **Option A — Local authority (front decide)** : `KitchenDisplaySystemComponent.vue` envoie le nouveau statut, le serveur n'exige pas `expected_status`. **Conséquence** : `~1sp` (statu quo), zéro changement back. **Risque résiduel** : 2 chefs qui bumpent simultanément depuis 2 écrans → race condition silencieuse, sentinel `KdsExpectedStatusConflictSentinelTest` reste rouge.
- **Option B — Server authority avec `expected_status` body required (recommandé super master, avec feature flag)** : `KdsOrderStatusRequest` (NEW) exige `expected_status` ; `KitchenDisplaySystemOrderService::changeStatus` compare `body.expected_status` vs `locked->status` → 409 si divergent. **Conséquence** : `~5sp` (request + service modify L117-168 + JS store passer le champ + 4 tests Feature/Vitest/Playwright). **Risque résiduel** : régression UX si le front oublie d'envoyer `expected_status` (mitigé par contrat tests).
- **Option C — Restrict bump authority par rôle (cuisine seule)** : seuls les rôles `kitchen_*` peuvent bumper ; cashier/manager bloqué. **Conséquence** : `~3sp` (middleware role check + tests), réduction du périmètre fonctionnel KDS. **Risque résiduel** : cas de cashier qui doit débloquer en l'absence d'un cuisinier (escalade manuelle).
- **Option D — Cancel / Différer durcissement KDS V1.1**.

**Recommandation technique** : **B** avec feature flag `kds_strict_release` (cf. masterplay § M-15), enable progressif 1 branche pilote → 10% → 100%. Critère pour A : si le board accepte le risque race en V1. Critère pour C : si exigence métier d'isolation rôles.

**Co-signataires Approval** : TL + Backend owner + Ops (rollout flag).

### Brief 5 — `GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25.md`

**Decision Required** : Quelles migrations Caisse V1 sont autorisées en V1, dans quel ordre, et avec quelle stratégie (rehearsal staging, backup, plan rollback) ? Cf. règle Hard Gate `human-gates.mdc:19`.

- **Option A — All migrations autorisées avec rehearsal + backup (recommandé super master)** : liste prévisionnelle `payment_proofs` (M-04A), `payment_ledger` (M-04A), `kitchen_releases` (M-07), `order_quotes` (M-05), `idempotency_keys` extension (M-04A/M-05), `z_reports.status CLOSING` (M-08). Ordre dépendant : C5 (idempotency) → C6 (coupons branch_id, déjà signé) → puis order_quotes / payment_ledger / kitchen_releases / fiscal en parallèle après gates respectifs. **Conséquence** : `~8sp` migration safety (M-13 dry-run + rehearsal full-volume + Up/Down testés + runbooks). **Risque résiduel** : downtime backup nécessaire si volume > X Go (chiffrer humain).
- **Option B — Subset (uniquement migrations critiques V1)** : exclure `kitchen_releases` (M-07 reporté V1.1), exclure `z_reports CLOSING` (M-08 reporté). **Conséquence** : V1 ne livre pas KDS strict release ni fiscal hardening. **Risque résiduel** : dette schema.
- **Option C — None (aucune migration V1, tout en code applicatif)** : forcer M-04B (pilot restrict, sans `payment_ledger`), bloquer M-05 (quote en signature applicative seule, sans table), bloquer M-07 et M-08. **Conséquence** : V1 minimale, pas de quote signé persisté. **Risque résiduel** : régressions silencieuses, no SSOT pour quote/release.
- **Option D — Cancel / Différer V1**.

**Recommandation technique** : **A** avec exigence M-13 (dry-run + rehearsal full-volume) et autorisation explicite humaine par migration au moment de l'exécution (cf. `human-gates.mdc:19` — chaque migration = écriture humaine séparée dans GATE_LOG). Critère pour B : si la fenêtre downtime n'est pas négociable. Critère pour C : si DBA refuse toute migration en V1.

**Co-signataires Approval** : TL + BE owner + DBA (obligatoire) + Ops (fenêtre downtime).

### Brief 6 — `GATE_OFFLINE_SCOPE_V1_2026-04-25.md`

**Decision Required** : En V1, le kiosk déconnecté du réseau est-il (A) **read-only** menu sans paiement, (B) **commande différée + reconcile** à reconnexion, ou (C) **hard-disable** (kiosk éteint) ?

- **Option A — Read-only menu, paiement désactivé (recommandé super master cash-only avec backend refus CB/TR)** : le kiosk affiche le menu cached (`store/modules/kioskMenu.js:276`), bouton Payer désactivé, message "mode hors-ligne". CB/TR refusés serveur (cf. masterplay § M-11 Option A — backend refuse 422 en cas de soumission offline). **Conséquence** : `~4sp` (UI désactivation, message, refus serveur, sentinel #18). **Risque résiduel** : perte de revenue pendant la coupure (acceptable si rare).
- **Option B — Commande différée + reconcile** : le kiosk accepte la commande, génère ID `offline_<ts>_<uuid>` (cf. `helpers/kioskOfflineQueue.js:135, 330`), met en queue locale, reconcile à reconnexion (POST replay). CB/TR autorisés mais avec ledger queue signé. **Conséquence** : `~13sp` (queue signée + reconcile + idempotency + tests Vitest + Playwright + risque NF525 sur fiscal différé). **Risque résiduel** : double-charge si reconcile foire, dette NF525.
- **Option C — Hard-disable kiosk offline (écran "service indisponible")** : le kiosk détecte la perte réseau et affiche un écran d'indisponibilité totale. **Conséquence** : `~2sp` (UI), perte UX maximale. **Risque résiduel** : perception client négative, mais zéro risque transactionnel.
- **Option D — Cancel / Différer offline V1.1**.

**Recommandation technique** : **A** (cash-only, read-only menu, backend refus serveur CB/TR). Critère pour B : si la branche pilote a un historique de coupures fréquentes ET le board accepte le coût. Critère pour C : si la branche pilote n'a pas de risque de coupure (réseau redondant).

**Co-signataires Approval** : TL + UX (impact perception) + Ops.

### Brief 7 — `GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25.md`

**Decision Required** : Le paiement web (URL publique `/payment/{order}/pay` raw id) est-il inclus dans Caisse V1 ou différé en V1.1 ?

- **Option A — Web payment actif en V1** : sécuriser via `PaymentIntent` signé (HMAC + TTL court + branch_id check), Stripe activé (cf. gate 8). Refactor route `/payment/{order}/pay` pour exiger un token signé au lieu d'un raw id. **Conséquence** : `~8sp` (refactor route, signature service, tests Feature, Stripe wiring). **Risque résiduel** : surface d'attaque web, exige audit security.
- **Option B — Web payment off V1 (recommandé super master)** : la route `/payment/{order}/pay` répond 404 ou 503 V1, fonctionnalité différée V1.1. **Conséquence** : `~1sp` (désactivation route + message + test 404). **Risque résiduel** : si des clients utilisent déjà cette URL en prod (à confirmer humain via analytics) → régression UX.
- **Option C — Cancel / Décision V1.x ultérieure**.

**Recommandation technique** : **B** sauf si analytics prod montrent un usage non négligeable de `/payment/{order}/pay`. Critère pour A : exigence business explicite + ressources `~8sp` disponibles + pré-requis gate 8 résolu.

**Co-signataires Approval** : TL + Product (priorité business) + BE owner.

### Brief 8 — `GATE_STRIPE_CENTS_ACTIVE_2026-04-25.md`

**Decision Required** : Stripe est-il (ou sera-t-il) **actif sur prod** pendant Caisse V1 ? Si oui, le bug cents/euros doit être corrigé en P0 (incohérence de représentation monétaire = perte 100x). Si non, le fix devient un issue dormant V1.1.

- **Option A — Stripe actif prod V1, fix cents P0 obligatoire** : audit du code Stripe (montants envoyés en cents `total * 100`, vs réception webhook), tests `StripeCentsConversionTest` (M-04A test obligatoire), validation manuelle 1 transaction réelle test mode. **Conséquence** : `~5sp` (audit + fix + tests + validation manuelle), incluant 1 transaction réelle € 1.00 → vérifier `amount_received` sur dashboard Stripe. **Risque résiduel** : si fix incomplet, perte 100x sur transactions réelles.
- **Option B — Stripe inactif prod V1, fix cents reporté V1.1** : confirmer feature flag Stripe `disabled` sur prod, ajouter sentinel CI `StripeFeatureFlagDisabledOnProdTest` qui empêche d'activer Stripe sans signer ce gate à nouveau. **Conséquence** : `~1sp` (sentinel CI). **Risque résiduel** : zéro tant que flag off.
- **Option C — Cancel / Décision V1.x ultérieure (uniquement si gate web-payment = B et aucune autre intégration Stripe)**.

**Recommandation technique** : **B** par défaut si gate web-payment (gate 7) = B. **A** si gate 7 = A ou si Stripe est déjà actif sur une branche (à confirmer humain — preuve dashboard). Le statut "actif sur prod" est un fait à confirmer **humain**, pas par GPT.

**Evidence requise spécifique gate 8** : capture d'écran ou export config Stripe dashboard montrant statut actif/inactif sur les branches de production ; **codex ne peut pas le confirmer**.

**Co-signataires Approval** : TL + BE owner + Ops (config flag prod).

## RÈGLES DE QUALITÉ

1. **Format `human-gates.mdc` strict** : ordre de sections respecté (Trigger → Affected Subsystems → Invariants at Risk → Decision Required → Options → Approval). Tu peux ajouter des sections complémentaires (Recommandation, Evidence, Rollback, Resumption, Annexes) **après** Approval, mais Approval reste **vide** et apparaît **avant** Annexes pour signature lisible.
2. **Aucune approbation cochée**. Lignes Approval = `[ ]` exclusivement. Aucun `[x]`. Aucune date pré-remplie.
3. **Aucune invention** : ancrages file:line viennent **strictement** de la cartographie `PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § 2 (déjà vérifiés). Si tu cites un autre fichier, tu **dois** l'avoir lu (Read) ; sinon omets l'ancrage.
4. **Aucun chiffre inventé** : story-points et semaines sont des repères. Si tu ne peux pas estimer honnêtement (manque d'info source) → `TBD: humain à chiffrer en revue`. Mieux vaut un TBD qu'un chiffre faux.
5. **Citation des invariants** : nomme l'invariant par son numéro et son titre canonique (`Invariant #1 Backend Pricing SSOT`, `Invariant #2 OrderStatus Enum`, etc., cf. `.cursor/rules/project-invariants.mdc`). Pas de paraphrase floue.
6. **Pas de duplication** : ne réécris pas les ancrages déjà dans masterplay § 2 — cite la section avec le numéro de ligne. Ne dupliques pas les Options du super master § 3 — élargis-les. Ne réécris pas `human-gates.mdc` — cite-le.
7. **Diff minimal sur `GATE_LOG.md`** : 8 lignes ajoutées en § *Trail courant* uniquement. Ne **pas** modifier le § *Trail rétroactif* (rétroactif 2026-04-14/15/20). Ne pas modifier le format header. Ne pas réordonner les lignes existantes.
8. **Cohérence vocab** : `OrderStatus enum`, `branch_id` strict, `dispatch after commit`, `frozen zones` — usage conforme aux invariants même en prose.
9. **Densité** : chaque brief 200‑400 lignes Markdown. Pas de blabla, pas d'introductions narratives, pas de "Ce brief décrit…".
10. **Date figée** : `2026-04-25` partout. Pas de date relative ("today", "demain"). Pas d'heure.
11. **UTF-8 sans BOM, fin de fichier `\n`**, tables Markdown valides, listes `-` (pas `*`).
12. **Pas de gate signé en cascade** : un gate ne peut pas "approuver" un autre gate. Chaque gate a son propre Approval indépendant.

## LIVRABLES dans `output_codex.json`

Sortie JSON UNIQUE (racine objet), conforme `agents/codex.prompt.txt`. Aucun texte hors JSON. Aucun double JSON.

```json
{
  "task_id": "CV1-M03-GATES-DRAFT",
  "mission_id": "M-03",
  "files_to_modify": [
    "docs/gates/GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25.md",
    "docs/gates/GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25.md",
    "docs/gates/GATE_PAYMENT_LEDGER_V1_2026-04-25.md",
    "docs/gates/GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25.md",
    "docs/gates/GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25.md",
    "docs/gates/GATE_OFFLINE_SCOPE_V1_2026-04-25.md",
    "docs/gates/GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25.md",
    "docs/gates/GATE_STRIPE_CENTS_ACTIVE_2026-04-25.md",
    "docs/gates/GATE_LOG.md"
  ],
  "code_blocks": [
    { "path": "docs/gates/GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25.md", "op": "create", "excerpt": "<markdown complet >= 200 lignes>" },
    { "path": "docs/gates/GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "docs/gates/GATE_PAYMENT_LEDGER_V1_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "docs/gates/GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "docs/gates/GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "docs/gates/GATE_OFFLINE_SCOPE_V1_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "docs/gates/GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "docs/gates/GATE_STRIPE_CENTS_ACTIVE_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "docs/gates/GATE_LOG.md", "op": "edit", "anchors": ["§ Trail courant : append 8 lines"], "excerpt": "<patch unifié ou bloc des 8 nouvelles lignes>" }
  ],
  "risks": [],
  "notes": "8 briefs créés statut PENDING_HUMAN_GATE ; 0 approval cochée ; GATE_LOG.md mis à jour § Trail courant uniquement (rétroactif intact) ; aucun fichier produit modifié ; ancrages file:line conformes masterplay § 2 ; chiffrages = repères TBD si manque d'info source. Total caractères briefs: <X> ; lignes par brief min/max/moy: <m>/<M>/<a>.",
  "execution_trace": {
    "delegation": "codex-extension",
    "model": "gpt-5.5-pro",
    "reasoning_effort": "xhigh",
    "invariants_considered": [
      "pricing-ssot",
      "order-status-enum",
      "branch-id-isolation",
      "dispatch-after-commit",
      "os-fos-symmetry",
      "frozen-zones"
    ]
  },
  "self_audit": {
    "briefs_created_count": 8,
    "all_briefs_match_human_gates_format": true,
    "approval_block_empty_for_each": true,
    "no_x_approved_anywhere": true,
    "gate_log_updated_trail_courant_only": true,
    "gate_log_retroactive_intact": true,
    "two_or_more_options_per_brief": true,
    "non_decisive_recommendation_present": true,
    "no_product_file_touched": true,
    "no_existing_gate_file_modified": true,
    "files_outside_allowlist_modified": false
  }
}
```

`GPT_SELF_AUDIT_CV1-M03-GATES-DRAFT.md` doit cocher chaque item de `self_audit_checklist` (`input.json`) avec evidence (chemin du brief + numéro de ligne où la preuve apparaît). Item `mandatory_tests` : exécuter `test -f docs/gates/GATE_LOG.md && grep -c 'GATE_' docs/gates/GATE_LOG.md` (output `>= ancien_count + 8`).

## INTERDITS

- Cocher `[x] Approved` n'importe où, dans n'importe quel brief, sous quelque forme que ce soit (`[x]`, `✓`, `✅`, `Approved by: <nom>`, date pré-remplie). Violation = `REWORK` immédiat (cf. `MASTERPLAY_DISCIPLINE.md` § 3.4 + `human-gates.mdc:79-86` *Absolute Prohibitions*).
- Modifier un gate existant : `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`, `GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`, `GATE_W2_KPI_REVISION_2026-04-26.md`, `GATE_W2_CUTOVER_2026-04-26.md`, ou tout autre fichier `docs/gates/GATE_*.md` non listé dans l'allowlist. **Lecture autorisée pour citer**, écriture interdite.
- Modifier `GATE_LOG.md` ailleurs qu'en § *Trail courant*. Ne **pas** réécrire/réordonner le § *Trail rétroactif*. Ne pas modifier le header, le format d'entrée obligatoire, le § *Process futur*, le § *Self-approval interdite*.
- Modifier tout fichier produit (`app/**`, `resources/**`, `routes/**`, `database/**`, `tests/**`, `scripts/**`, `config/**`, `.cursor/**`, `AGENTS.md`). Toute écriture hors allowlist = `SCOPE_PRESSURE` + STOP.
- Inventer un ancrage file:line non présent dans `PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` § 2 (sauf si tu as **lu** le fichier et cites la ligne réelle). Inventer un nom de service, une commande artisan, une route, un test.
- Inventer un chiffrage (story-points, semaines) sans source. Préférer `TBD: humain à chiffrer en revue`.
- Pré-remplir un `Approver`, `Co-signed by`, ou `Date` avec un nom propre, une date, ou une initiale.
- Faire un `git add` ou commit (la mission ne le demande pas).
- Approuver un gate par cascade (un gate qui "valide" un autre gate). Chaque gate a sa propre signature humaine indépendante.
- Inclure du code exécutable dans les briefs (PHP, JS, SQL DML). Snippets shell autorisés uniquement s'ils citent une commande existante (`php artisan ...`, `bash scripts/...`).
- Dupliquer le contenu d'`input.json`, du super master plan, ou de `human-gates.mdc` dans les briefs. Cite les chemins/sections.
- Produire de la prose hors `output_codex.json`. Produire plusieurs JSON.
- Ajouter une signature `Co-Authored-By` ou un changelog dans les briefs.

## SI BLOCAGE

- **Format gate ambigu sur un point précis** (p.ex. doit-on inclure une section "Escalation Clause" comme dans `GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`) → **inclure** la section si elle apporte de la valeur (deadline, fallback automatique au statut `OVERDUE`), sinon l'omettre. Documenter le choix dans `notes` du JSON.
- **Une option semble auto-évidente** ("Cancel cycle" trivialement à éviter) → conserver l'option D (Cancel/Différer) pour respecter le format `human-gates.mdc § Options 3` (option 3 = Cancel cycle), mais la libeller `Cancel V1 / Différer V1.1` avec `Conséquence: V1 ne livre pas <X>, replan complet`. Ne JAMAIS supprimer l'option Cancel — c'est une protection humaine.
- **Dépendance avec gate existant** (p.ex. `GATE_FROZEN_ZONES_CAISSE_V1` chevauche `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20`) → cite le précédent en § Annexes ("Précédent gate similaire — modèle structurel"), mais ne réécris pas son scope. Ton brief couvre **les nouvelles méthodes/fichiers** non couverts par le précédent. Si recouvrement total → `risks: ["AMBIGUITY: GATE_FROZEN_ZONES_CAISSE_V1 recouvre largement GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 — humain doit décider de fusionner ou maintenir séparé"]`.
- **Audit source cité (ex: `MEGA_RAPPORT_FINAL_DISPUTE_*`) introuvable** → ne pas inventer le contenu. Citer le chemin tel quel + ajouter `risks: ["EVIDENCE_MISSING: <path> attendu mais non vérifié"]`. Le brief peut quand même être écrit en s'appuyant sur la cartographie masterplay § 2.
- **Numéros de ligne décalés** (le repo a évolué entre l'écriture de masterplay § 2 et l'exécution) → **ne pas re-grep** : conserve les ancrages tels que cités dans masterplay § 2 (autorité), et ajoute `risks: ["CARTO_DRIFT_RISK: ancrages de masterplay § 2 datés 2026-04-25 — humain à valider lors de l'exécution M-04..M-11"]`.
- **Recommandation par défaut super master ambiguë** (ex: gate `GATE_STRIPE_CENTS_ACTIVE` recommandation `Depends on web-payment gate`) → écris la recommandation conditionnelle telle quelle (`Si gate 7 = A → recommandation A ; si gate 7 = B → recommandation B`). Ne tranche pas à la place de l'humain.
- **Gate mission spécifique a déjà été partiellement signé ailleurs** → ne re-rédige pas, écris dans le brief `Statut: PARTIAL_PRECEDENT — voir docs/gates/GATE_<X>.md` + `risks: ["PRECEDENT_CONFLICT: <gate>"]` et stoppe ce brief (les autres briefs continuent).
- **Toute ambiguïté bloquante non résolvable** (ex: si tu ne sais pas quel profil doit co-signer un gate) → ne pas inventer, écris `Co-signataires Approval: ___ (TL obligatoire ; autres co-signataires à déterminer humain)` et ajoute `risks: ["AMBIGUITY: cosigners undetermined for GATE_<X>"]`.
- **`GATE_LOG.md` doit recevoir 8 lignes mais format "Approver" ambigu pour PENDING** → utilise `(en attente — TL + <profils>)` cohérent avec le précédent `GATE_PAYMENT_PROP_MUTATION_2026-04-26` ligne 39 (`(en attente — TL + Backend + QA NF525 + UX)`).
- **Self-audit checklist `input.json.self_audit_checklist` non satisfait à la fin** → ne **pas** retourner le JSON ; refais le brief concerné. En particulier `Aucune ligne [x] Approved cochée` est un check mécanique : si tu en trouves une, supprime-la avant retour.
