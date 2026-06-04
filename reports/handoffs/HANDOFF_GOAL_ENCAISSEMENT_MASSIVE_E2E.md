<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- ║  HANDOFF — LANCER DANS UNE NOUVELLE SESSION CLAUDE                  ║ -->
<!-- ║  GOAL: Encaissement massif (espèces/carte/TR × caisse/borne) →     ║ -->
<!-- ║        valider → auditer → re-tester → confirmation PRÉ-CLOUD       ║ -->
<!-- ║  Receiver: nouvelle session Claude Code · Lane: CAISSE             ║ -->
<!-- ║  Auteur: session 2026-06-03 · HEAD base 6da66e8a0                  ║ -->
<!-- ════════════════════════════════════════════════════════════════════ -->

# 0. COMMENT UTILISER CE FICHIER
1. Ouvre une **nouvelle session Claude Code** dans `…/web/testttt`.
2. **Copie-colle le PROMPT DÉTERMINÉ du §6** (bloc unique) comme premier message.
3. Le reste de ce fichier = le contexte que cette nouvelle session lira (le prompt l'y renvoie).
   Ne rien exécuter depuis CE fichier ; il est la **carte de mission**, pas un script.

---

# 1. PROBLÉMATIQUE (l'état réel, vérifié sur le code — 12 agents + vérif orchestrateur)
Le système n'est **PAS encore « sans faute »** pour l'encaissement complet. Détail prouvé `file:line`
dans `reports/test-e2e/sync-borne-caisse-kds-2026-06-03/cloud-readiness/ULTRAPLAN_ENCAISSEMENT_PRE_CLOUD.md` :

- ✅ **Caisse ↔ Borne (liaison/sync) : MARCHE.** Même chemin scellé (`PENDING_COUNTER`+`COUNTER_DEFERRED`
  → `PaymentService::confirmCounterPayment` → séquence fiscale → `PAID`), race-protégé (409), idempotent.
  Prouvé live le 2026-06-03 (A0014 → Payé + séq. 1998 + ticket + broadcast `OrderPaidAtCounter`).
- 🔴 **Carte = STUB simulation** (`PosCounterCollectModal.vue:146-153`) — 1 tap → « TPE validé (simulation) »,
  **aucune saisie réf TPE / montant tapé, aucun `OrderPayment` créé, pas de CashMovement.**
  → l'**alternative manuelle SumUp** que l'owner veut **N'EXISTE PAS encore** (≈80 LOC à coder).
- 🔴 **Ticket Restaurant = STUB** — 1 tap → PAID, **aucune saisie « combien de tickets » / valeur / split**,
  0 conformité FR (plafond 25€/j, dénominations, CONECS).
- ⚠️ **Espèces = PARTIEL** — rendu de monnaie **affiché mais NON persisté** ; **session caisse non-bloquante**
  (commande Payée sans `CashMovement` si aucune caisse ouverte = **trou cash-trail NF525**) ;
  précision `pos_received_amount(19,6)` vs EUR(10,2).
- ⚠️ **Z-report / DB** — `ZReportService.php:666` stocke des **clés numériques** `'1'/'2'/'5'` (au lieu de
  labels lisibles) dans le `total_by_method` **signé/immuable** (totaux corrects + chaîne valide ⇒ **P1
  lisibilité audit, PAS corruption**) ; `CashMovement` **sans colonne `payment_method`** ; carte/TR
  **n'écrivent aucun CashMovement** ⇒ réconciliation par méthode impossible.
- ⚠️ **Cloud-readiness** — 14 boot-guards en place ; cutover = checklist `.env`/config (§3.E ci-dessous),
  peu de code.

**Conséquence** : la « confirmation sans faute avant cloud » est **NO-GO en l'état**. Il faut d'abord
**coder carte+TR fonctionnels + combler les trous espèces/Z**, **puis** tester massivement, **puis** confirmer.

---

# 2. MA DEMANDE (owner — verbatim décodé, FR)
> Test E2E **massif** du **système complet** d'encaissement, en plusieurs étapes. Confirmer la **liaison +
> synchro caisse ↔ borne** pour l'**encaissement des paiements** des commandes **caisse ET borne**.
> À l'encaissement : choisir **Carte / Espèces / Ticket Restaurant**. **~20 commandes par cas** pour confirmer.
> **MCP Playwright** + **captures d'écran à CHAQUE étape** (prise commande borne → caisse → encaissement →
> rendu monnaie si espèces / « je tape sur mon Terminal » si carte (l'alternative SumUp) / « combien de
> tickets restaurant » si TR). Mettre une **logique fonctionnelle** pour l'alternative carte le temps que le
> terminal officiel marche. **Agir comme superviseur professionnel**, tout décomposer, **test massif**.
> Puis **confirmation DB + gestion + cloud** car on va passer au **Cloud** (nom de domaine, etc.) — mais
> **seulement** quand le système tourne **parfaitement, sans faute, en LOCAL**.
> Discipline : **sub-agents au maximum en parallèle, intelligents, disciplinés.** Une fois validé →
> **audit** → **re-test**. Si quelque chose « clignote » (signal faible / pas net / raisonnement douteux) → le traiter.

---

# 3. LE PLAN (décomposé, discipliné, gates explicites)
**Lane = CAISSE** (SYSTEM_MAP §2). Touche des **ZONES PARTAGÉES** (NF525, PaymentService, sync-bus) ⇒
ces écritures sont **SÉRIALISÉES + gated** (PARALLEL_PROTOCOL §2). Le BUILD se fait en voie unique ; seuls
l'**AUDIT** et le **TEST fan-out** se parallélisent (lectures disjointes).

### 3.A — BUILD (voie unique, séquentiel, chaque item = LOOP §5 CLAUDE.md + tests + visual + NF525 chain)
Ordre conseillé (du plus sûr au plus sensible) :
1. **P-CONFIG** (0 LOC code) — poser `POS_WALKIN_ROUTE_TO_COUNTER=true` dans `.env` (sinon caisse walk-in
   paie inline, pas en différé comptoir). Vérifier `config/pos.php:209`.
2. **P-CARD** (≈80 LOC, **non-frozen** modal + **PaymentService = zone partagée → gate**) — alternative TPE
   manuelle : sous-section CARTE = champ **réf TPE** (last-4 / ID terminal) + **montant tapé** (numpad) +
   bouton « ✓ J'ai tapé sur le TPE » ; `confirmCounterPayment` mode=CARD → **créer `OrderPayment`**
   (reference, tendered) + valider `montant ≥ total` + **écrire `CashMovement`** + retirer « (simulation) »
   du toast. **Pas de migration** (colonnes `OrderPayment` existent). Fichiers : `PosCounterCollectModal.vue`,
   `app/Services/PaymentService.php:376-387`, i18n `tpe_validated_simulation`.
3. **P-TR** (modal + PaymentService) — Ticket Restaurant : **nb tickets × valeur/ticket** (5/8/10€) → montant
   TR ; si **montant TR < total → split complément ESPÈCES** (TR ne rend pas la monnaie) ; enregistrer la
   ventilation + `OrderPayment` + `CashMovement`. **CONECS/DGFIP = note cloud** (exemption/bridge plus tard).
4. **P-CASH** (modal + PaymentService) — **persister le rendu** (`pos_change_amount(10,2)` ou CashMovement OUT)
   + **session caisse bloquante** ou badge « caisse ouverte/fermée » (`GET /admin/pos/cash-session/status`)
   + précision `pos_received_amount→(10,2)`.
5. **P-DB** — `CashMovement` + **colonne `payment_method`** ; carte/TR/cash écrivent un CashMovement par méthode.
6. **P-ZREPORT** (🔒 **FROZEN §7 `ZReportService.php` → LOCK doc owner-gate + triple-vert OBLIGATOIRE**) —
   stocker clés lisibles (`counter_cash`/`counter_card`/`counter_ticket_restaurant`) au lieu de `'1'/'2'/'5'`.
   Forward-only (anciens Z immuables inchangés). **NE PAS toucher sans LOCK validé owner.**

> ⚠️ **Skill `/lock-plan`** pour P-ZREPORT (+ tout fichier §7). PaymentService n'est pas dans la liste §7
> stricte mais est **fiscal-adjacent** → traiter en zone partagée sérialisée + tests NF525 après chaque edit.

### 3.B — TEST MASSIF (après build, **MCP Playwright**, capture à chaque étape)
Matrice : `{Espèces, Carte, Ticket Resto} × {Commande Caisse, Commande Borne} × N≈20`.
Par commande : prise (capture) → board « À encaisser » (capture) → modal méthode (capture : rendu monnaie /
réf TPE+montant / nb tickets+split) → confirm → PAID (capture) → ticket (capture) → **vérif DB+Z**.
Edge à inclure : split TR+espèces, carte sous-montant (422), double-tap (409 idempotence), caisse fermée,
rendu monnaie (reçu>total).

### 3.C — AUTO-VALIDATION → AUDIT → RE-TEST (la boucle exigée)
1. **Self-validate** : PHPUnit filter + Vitest filter + Playwright affected + **NF525 `fiscal:verify-chain --all`** + frozen-zone diff (0).
2. **AUDIT adversarial** (max parallèle, lectures disjointes) : rejouer chaque méthode/surface, chercher ce qui
   « clignote » (raisonnement douteux, P0/P1 caché, sémantique fausse même si test vert). **verify-before-report**.
3. **RE-TEST** : si l'audit lève quoi que ce soit → heal (max 3 cycles) → re-run. **Converger 2 rounds verts
   consécutifs set-equality** par méthode×source. Sinon escaler à l'owner avec analyse de cause.

### 3.D — CONFIRMATION DB + GESTION
Après N×3 méthodes : `orders.pos_payment_method` correct · `OrderPayment` créé (carte/TR aussi) ·
`CashMovement` par méthode · **Z `total_by_method`** ventile cash/carte/TR avec **clés lisibles** + sommes
exactes · `cash-overview` réconcilie · chaîne fiscale OK · `fiscal:verify-z-membership`.

### 3.E — CHECKLIST CUTOVER CLOUD (la porte go-prod ; surtout `.env`)
🔴 secrets fiscaux 32+ aléatoires · redis cache partagé (multi-pod lock) · `POS_SIMULATION_HARDWARE=false`
+ bypass off · 🟠 `APP_URL=https://<domaine>` (CORS) · `BROADCAST_DRIVER=pusher` · `SESSION_DRIVER` redis/sticky ·
`QUEUE_CONNECTION=redis`+worker · triggers MySQL BEFORE DELETE préservés (`mysqldump --triggers`).
Les 14 boot-guards (`AppServiceProvider:158-453`) attrapent les misconfigs fail-fast. → produire le doc final.

### 3.F — ⚠️ PRÉCONDITION D'EXÉCUTION (sinon résultats NON fiables)
**Environnement exclusif requis** pour un test « 20×/cas sans faute » :
- Le batch `abuse-e2e-2026-06-01` a tourné toute la session précédente → **sessions POS qui tombent**
  (compte `pos@lecayenne.fr` contendu, tokens révoqués), **DB revertée**, **1254 commandes soak** polluantes
  qui masquent le rendu encaissement (cap 200 FIFO). **Confirmer que le batch est ARRÊTÉ** + **purger les
  commandes soak** (test fixtures, pas réelles) AVANT tout test live. Sinon → **read-only / static** seulement.
- Pré-flight env (cf. `test-e2e` skill PRE_FLIGHT) : serve:8000 + soketi:6001 + redis + queue:work `--queue=high`
  UP ; bundles rebuilds après chaque edit JS (`npm run dev`) — **cache navigateur bust** (les chunks lazy
  `admin-shell.js` se cachent ; CDP `Network.setCacheDisabled` éprouvé) ; login SPA **par router.push (sans reload)**
  pour survivre à la volatilité (la commit `authLogin` non-namespaced + `getters.authToken`).

---

# 4. DISCIPLINE MULTI-AGENTS (max parallèle, intelligent, sans collision)
Suivre **PARALLEL_PROTOCOL.md** à la lettre :
- **Déclarer la voie = CAISSE** au démarrage. Lecture cold-start §0 AVANT tout (CONSTITUTION → BRAIN §2 →
  SYSTEM_MAP §2 → SYNC_CONTRACT → PARALLEL_PROTOCOL).
- **Parallèle = lectures/audits/tests disjoints** (1 agent par méthode, par surface, par facette). **Jamais**
  2 agents qui écrivent le même fichier. Le BUILD shared-zone (PaymentService, ZReportService) = **voie unique sérialisée**.
- **Workflow tool** pour les fan-out (audit adversarial, test par méthode×source, vérification DB/Z) : agents
  `Explore` read-only + schéma + brevity cap ; **verify stage adversarial** par finding (file:line confirmé).
- **Reporter les fichiers touchés** en fin de mission (vérif zéro cross-lane).

---

# 5. ANTI-DRIFT — RÈGLES DURES (ne JAMAIS sortir de ça)
1. **Scope = encaissement CAISSE (cash/carte/TR) × {caisse, borne} UNIQUEMENT.** Ne pas dériver vers BORNE
   wizard / KDS / WEB / CENTRAL sauf lecture nécessaire à la synchro.
2. **Frozen §7 = LOCK + gate owner** (ZReportService, PaymentComponent, pos-wizard.js, FiscalSequenceService,
   BranchScope, IdempotencyKeyMiddleware, PricingService, OrderStateMachine, AuditLogService, kiosk wizard ×3).
3. **NF525** : 100% pricing backend ; `composition_snapshot` figé ; `fiscal:verify-chain --all` **après chaque**
   edit fiscal/payment ; jamais supprimer une commande avec `fiscal_sequence_no` (entrée chaîne immuable).
4. **No-cloud sans ordre explicite** : produire la *checklist* cloud (§3.E) mais **ne coder AUCUN changement
   cloud/multi-tenant** ; ne pas remonter le cloud comme blocker V1.
5. **Locale FR** user-facing. **SSOT menu = DB items** (45 items, jamais inventer de produit).
6. **Git** : `git add <fichiers explicites>` (jamais `.`/`-A`) ; jamais `--no-verify` ; **jamais push/force**
   sans owner ; checkpoint-commit par phase.
7. **Evidence** : capture Playwright **lue+analysée** (pas juste prise) ; jamais « vert » sur un `--filter`
   étroit (suite complète) ; **verify-before-report** (file:line + repro, sinon REJECT).
8. **TPE simulé = choix assumé** (CONSTITUTION §2), PAS un bug. L'« alternative carte » = flux **manuel
   fonctionnel** (caissier tape sur le TPE physique + confirme dans l'app), pas un no-op.
9. **Healing** : max 3 cycles sur le même problème → escaler owner avec analyse de cause (pas « y'a une erreur »).

---

# 6. ⬇️ PROMPT DÉTERMINÉ À COLLER DANS LA NOUVELLE SESSION ⬇️
```
/goal SUPERVISEUR — Encaissement massif caisse↔borne (espèces/carte/TR) → valider → auditer → re-tester → confirmation PRÉ-CLOUD. Lane = CAISSE.

ÉTAPE 0 (obligatoire, dans l'ordre, AVANT toute action) : lis CONSTITUTION.md → PROJECT_BRAIN.md §2 → SYSTEM_MAP.md §2 (CAISSE) → SYNC_CONTRACT.md → PARALLEL_PROTOCOL.md, PUIS le dossier de mission reports/handoffs/HANDOFF_GOAL_ENCAISSEMENT_MASSIVE_E2E.md (carte complète) + reports/test-e2e/sync-borne-caisse-kds-2026-06-03/cloud-readiness/ULTRAPLAN_ENCAISSEMENT_PRE_CLOUD.md (le quoi/où exact, file:line). Déclare ta voie = CAISSE.

PROBLÈME : carte + ticket-restaurant à l'encaissement sont des STUBS simulation (aucune saisie réf TPE / montant tapé / nb tickets / split), espèces ne persiste pas le rendu + session non-bloquante (trou NF525), Z-report stocke des clés numériques '1'/'2'/'5'. La liaison caisse↔borne, elle, MARCHE (prouvé). But owner : que TOUT marche « sans faute en LOCAL » AVANT de passer au cloud.

PRÉCONDITION : vérifie via /workflows + STATE.md que le batch abuse-e2e est ARRÊTÉ ; si actif → read-only/static seulement. Si arrêté → purge les ~1254 commandes soak (PENDING_COUNTER, fixtures non réelles) pour un test propre, confirme serve:8000 + soketi:6001 + redis + queue:work --queue=high UP. Demande à l'owner d'arrêter le batch si besoin (c'est le SEUL blocage légitime).

EXÉCUTE (LOOP CLAUDE.md §5, voie unique pour le BUILD shared-zone, parallèle MAX pour AUDIT/TEST) :
1) BUILD séquentiel + tests + visual + NF525 chain après chaque : P-CONFIG (POS_WALKIN_ROUTE_TO_COUNTER=true) → P-CARD (alternative TPE manuelle : réf+montant tapé+OrderPayment+CashMovement, retire « simulation ») → P-TR (nb tickets×valeur + split espèces + OrderPayment+CashMovement) → P-CASH (persister rendu + session bloquante/badge) → P-DB (CashMovement.payment_method) → P-ZREPORT (clés lisibles — ⛔ FROZEN : /lock-plan + gate owner AVANT, sinon STOP). PaymentService = fiscal-adjacent → sérialise + teste NF525.
2) TEST MASSIF MCP Playwright, CAPTURE à CHAQUE étape : {Espèces,Carte,TR}×{Caisse,Borne}×~20 ; prise→board→modal(rendu/réf TPE/nb tickets)→confirm→PAID→ticket→vérif DB+Z. Edges : split TR+espèces, carte sous-montant 422, double-tap 409, caisse fermée, rendu monnaie.
3) AUTO-VALIDATION → AUDIT adversarial parallèle (verify-before-report, file:line) → RE-TEST. Converge 2 rounds verts set-equality par méthode×source. Si quelque chose « clignote » (sémantique fausse même test vert, raisonnement douteux, P0/P1 caché) → heal (max 3) ou escalade.
4) CONFIRMATION DB+gestion (Z total_by_method ventilé lisible + CashMovement par méthode + cash-overview + chaîne fiscale) + CHECKLIST cutover cloud (secrets/redis/APP_URL/triggers — checklist SEULEMENT, 0 code cloud).

RÈGLES DURES : scope = encaissement CAISSE cash/carte/TR ×{caisse,borne} UNIQUEMENT (pas de drift). Frozen §7 = LOCK+gate. NF525 fiscal:verify-chain après chaque edit ; jamais supprimer commande avec fiscal_sequence_no. Locale FR. SSOT menu = DB. git add explicite (jamais -A), jamais --no-verify, jamais push. Evidence : captures lues+analysées, suite complète (pas --filter étroit), verify-before-report. TPE simulé = choix assumé (pas un bug) ; l'alternative carte = flux manuel fonctionnel. Checkpoint-commit par phase.

LIVRABLES : reports/test-e2e/encaissement-massive-<date>/ → CONVERGENCE_FINAL.md (matrice méthode×source verte + captures analysées + latences) + DB_GESTION_CONFIRM.md + CLOUD_CUTOVER_CHECKLIST.md + verdict GO/NO-GO ferme. PROJECT_BRAIN.md §2 mis à jour. No push.
```
⬆️ FIN DU PROMPT ⬆️

---

# 7. FICHIERS PRÊTS (index de mission — tous vérifiés présents 2026-06-03)
**Gouvernance (cold-start)** : `CONSTITUTION.md` · `SYSTEM_MAP.md` · `SYNC_CONTRACT.md` · `PARALLEL_PROTOCOL.md`
**Plan/état encaissement** :
- `…/cloud-readiness/ULTRAPLAN_ENCAISSEMENT_PRE_CLOUD.md` ⭐ le quoi/où (file:line, gaps, gates)
- `…/cloud-readiness/decomposition-raw.json` (12-agent brut, traçabilité)
- `…/ENCAISSEMENT_E2E.md` (chronologie encaissement prouvée live + findings P2/P3)
- `…/CONVERGENCE_FINAL.md` (sync borne↔caisse↔KDS, heals F-W5-01/F-LAT-01 validés)
- `…/AUDIT.md` (architecture sync, canaux, outbox, frozen/NF525 observe-only)
- `…/captures/encaissement/enc-01…08*.png` (board, modal cash, order-detail Payé/Espèces)
**Règlement** : `CLAUDE.md` (LOOP §5, frozen §7, NF525 §8) · skills `/lock-plan` `/test-e2e` `/verify-before-report` `/checkpoint-commit` `/ultra-audit-profond`.

# 8. CRITÈRES D'ACCEPTATION (la nouvelle session est « finie » quand)
- Carte = flux manuel TPE fonctionnel (réf+montant+OrderPayment+CashMovement) ; TR = nb tickets+valeur+split fonctionnel ; espèces persiste rendu + session gérée — **tous testés massivement caisse+borne, 2 rounds verts**.
- DB/Z ventilent les 3 méthodes (clés lisibles, sommes exactes) ; chaîne NF525 OK ; cash-overview réconcilie.
- Checklist cutover cloud produite (0 code cloud). Verdict **GO/NO-GO ferme** documenté.
- **0 frozen-zone violation** (diff=0 hors LOCK validé) ; no push.

# 9. ROLLBACK
Chaque phase = checkpoint-commit ⇒ `git revert <sha>` par item. P-ZREPORT sous LOCK = forward-only (anciens
Z immuables, pas de rollback de données). En cas de doute fiscal → **STOP + escalade owner** (jamais « heal en douce »).

_Handoff écrit 2026-06-03. Receiver = nouvelle session Claude (lane CAISSE). 0 frozen touché ici (doc). No push._
