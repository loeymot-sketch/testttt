# PROMPT — Audit consolidé W0+ POS v4 — Claude terminal — 2026-04-26

## Mission
Auditer la session de remédiation W0+ POS v4 exécutée par cursor-claude (orchestrator session). Tu es Claude terminal, second avis indépendant. Tu dois challenger, pas approuver par défaut.

## Contexte cycle
- TASK_ID : `POS_V4_W0_REMEDIATION` (continuation du cycle `POS_V4_IMPL_EXEC_FINAL_2026-04-26`)
- PRIMARY_MODEL : claude-terminal (planner + auditor) ; cursor-claude (executor session — l'exécution sub-agent codex-terminal a été DÉCIDÉE non utilisée car les refactors étaient surgicaux et codex API était instable lors des audits W0 — voir output_codex.json POS_V4_FINAL_AUDIT_W0_001)
- PHASE : EXECUTE (W0+ remediation) → en attente de ton VERDICT pour ouvrir W1

## Livrables à auditer (tous fraîchement écrits)

### 1. Refactors code appliqués
- `resources/js/components/admin/pos/PosComponent.vue` :
  - L719 : `import orderStatusEnum from "../../../enums/modules/orderStatusEnum";` (nouveau)
  - L1391-1399 : `[4, 7, 8]` magic ints → `[orderStatusEnum.ACCEPT, orderStatusEnum.PREPARING, orderStatusEnum.PREPARED]`
  - L1418 : `status: 13` → `status: orderStatusEnum.DELIVERED`
  - L1779-1788 : ligne pricing `total = subtotal + delivery_charge - discount` wrappée `// @pricing-allowed-block start ... end` avec PENDING sign-off référence
  - L1834-1842 : guard `branch_id != null` avant génération `idempotency_key` (anciennement fallback `|| 0` collisogène cross-branche)

### 2. Lint guards créés
- `tools/lint/pos_pricing_guard.mjs` (107 lignes, scan POS+KDS+Kiosk)
- `tools/lint/pos_orderstatus_guard.mjs` (96 lignes, scan POS+KDS — kiosk reporté car violations existantes)
- `package.json` scripts ajoutés : `pos:lint:pricing`, `pos:lint:status`
- Résultat des 2 lints : OK CLEAN sur le périmètre scopé

### 3. Documentation
- `reports/audit/BACKLOG_POS_V4_W0PLUS_DISCOVERIES_2026-04-26.md` :
  - §1 PRICING découverte PosComponent:1779 (analogue ItemComponent → décision D1, sign-off TL+BE pending)
  - §2 KIOSK découverte 7 violations magic ints (cycle dédié W1-KIOSK proposé)
  - §3 PaymentComponent prop mutation DÉFÉRÉ (gate QA + impact 3 sites — KioskPayment, PosComponent, PaymentComponent self)
  - §4 branch_id ParkedOrders:72 RÉSOLU — c'était un commentaire de doc, PAS un filtre commenté (cross-check faux positif)
- `docs/design/ADR_POS_V4_COULEUR.md` (DRAFT, recommandation Option C — variable CSS `--fk-pos-primary` défaut `#0084FF`)
- `reports/baseline/POS_V4_PERF_BASELINE_W0.md` mis à jour §4 :
  - Mesures bundle réelles : `app.js` 965 KB gzip (CONTIENT POS), `pos-wizard.js` 49 KB gzip
  - DÉCOUVERTE CRITIQUE : POS first-paint masqué dans app.js → seuil 220 KB INATTEIGNABLE sans code splitting
  - Action W1 bloquante définie : créer entrypoint `pos-shell.js` + dynamic import des SFC POS lourds

## Décisions orchestrales prises (à challenger)

### D1 — PaymentComponent mute props : DÉFÉRÉ avec gate brief proposé
Justification cursor-claude : refactor en `$emit('payment-reset')` impacte 3 sites incluant **kiosk payment** (chemin critique fiscal NF525) → déclenche HARD GATE QA sign-off (project-invariants.mdc).
Question pour toi : est-ce que tu valides ce différé, ou penses-tu qu'on doit absolument refactorer maintenant avant W1 ?

### D2 — Codex non utilisé pour les refactors W0+
Justification : refactors surgicaux (4 lignes magic ints, 1 wrap pricing, 1 guard branch_id, 2 scripts lint) — coût LLM disproportionné, et codex 504×2 historiques.
Question : aurais-tu préféré déléguer au moins 1 mission codex pour valider que la chain fonctionne, ou ce choix d'efficience est-il justifié ?

### D3 — branch_id ParkedOrders:72 statut faux positif cross-check
Le commentaire L72 `rappel ne traverse pas branch_id (API 404)` est de la DOC, pas un filtre commenté. Cross-check audit (`AUDIT_FINAL_W0_CROSSCHECK_2026-04-26.md` blind spot #4) s'est trompé.
Question : confirmes-tu cette interprétation après lecture des lignes 60-150 de ParkedOrdersComponent.vue ?

### D4 — Découverte bundle 965 KB
La mesure révèle que sans code splitting POS, le seuil 220 KB est inatteignable. Action W1 bloquante définie.
Question : ce code splitting doit-il être W0+ (avant ouverture W1) ou peut-il être le premier livrable W1-A ?

## Sections attendues dans ton rapport `AUDIT_FINAL_W0PLUS_CLAUDE_2026-04-26.md`

1. **Verdict consolidé** : APPROVED | PASS-WITH-FIX | NEEDS_FIX | BLOCK
2. **Validation des 4 refactors code** (1 par 1) : conformité invariants, qualité diff, risques résiduels
3. **Validation des 2 lint guards** : false positives risk, scope adéquat, intégration CI manquante (workflow YAML?)
4. **Validation des 4 décisions orchestrales D1-D4** : confirmer ou contester avec argumentation
5. **4 P0-CC du cross-check** : statut actuel (mesure bundle ✅, ADR draft ✅, pricing sign-off ⏳ humain, branch_id ✅ résolu)
6. **Lacunes que cursor-claude pourrait avoir manquées** (sois antagoniste)
7. **STOP triggers résiduels** avant ouverture W1
8. **Décision finale** : prêt pour W1 ? Si oui, quel est le premier livrable ? Si non, quoi corriger en priorité ?

## Contraintes
- Lis les fichiers livrés (tu as `--add-dir` sur la racine repo).
- Respecte token discipline : ne dump pas le code, cite par `file:line`.
- Réponds en français.
- Prends 5-10 minutes pour réfléchir avant de répondre.
