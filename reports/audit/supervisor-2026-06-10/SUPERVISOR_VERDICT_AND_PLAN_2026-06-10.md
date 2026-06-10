# VERDICT SUPERVISEUR + PLAN — FoodKing / Le Cayenne (2026-06-10)

> Audit superviseur 7-lanes × 3-stages (DONE grounded → bad-supervisor LACKING → anti-hallucination verify), 21 agents, run `wf_8c14fd8f-9aa`, V1-LOCAL fenced, read-only. Tous les findings ci-dessous sont grounded file:line/git (1 seul rejeté à la vérif). Frozen baseline = 0 ligne touchée (vérifié).

## VERDICT GLOBAL : 🔴 NO-GO « fini » — état CONVERGÉ-MAIS-NON-DÉPLOYÉ & FRAGMENTÉ

Le travail des 3 derniers GOAL (ultra-audit, validation profonde, clients) est réel et majoritairement prouvé **sur disque** — mais **rien n'est en ligne et rien n'est unifié**. Le rôle superviseur tranche : la priorité absolue n'est plus l'audit ni les features, c'est **matérialiser un arbre shippable unique, le build/test réellement, nettoyer la DB fiscale, et décider le push→production (gate owner)**.

### Le finding qui recadre tout — INT-DEPLOY-GAP-01 (P1)
La box OVH déployée exécute `origin/production` = **`8579f7eae`**, soit **204 commits DERRIÈRE la spine**. `deploy.sh:108` fait `git reset --hard origin/${LECAYENNE_BRANCH}` (branche `production`). Donc W-A..W-E + validation profonde + clients + CMS + tous les heals = **JAMAIS DÉPLOYÉS**. Le seul vecteur de prod = push→branche `production` (gate PUSH-1, owner). « Intégrer les lignes » ne livre **rien** sans ce push.

## CLUSTER P1 #1 — FRAGMENTATION STRUCTURELLE (5 findings convergents)
`INT-NO-SUPERSET-BUILT-03 · CMS-INT-01 · CMS-FRAG-01 · CLI-03 · EVID-05`
- **Aucune branche superset** printer+cms+mobile+spine n'existe ; aucune branche shippable unique.
- Code prod vivant sur ≥4 lignes divergentes jamais mergées : spine `003ca736b` · `heal/mobile-update-2026-06-10` · `feat/pos-printer-saga-autoprint` · `goal/cms-gestion-2026-06-10-spine` (+ `heal/dashboard-redeep` STALE = à NE PAS intégrer, régresserait FR-locale, INT-DROPPED-LINE-04 P2).
- L'arbre intégré n'a **jamais été build (mix) ni test-run** réellement (`merge-tree` résout l'arbre, n'invoque ni composer/npm/phpunit/vitest). « Buildable & shippable » = **non prouvé**.
- Collisions réelles à résoudre au merge : `goal/cms-gestion` touche **i18n ×5 (fr/en/ar/de/bn.json) + routes/api.php + bundles public/js + app.css** — les mêmes fichiers que mes heals clients/i18n → conflits json+bundles attendus. `printer-saga` = **0 conflit** (merge propre, vérifié). `mobile-update ∩ cms` = docs seulement.

## CLUSTER P1 #2 — CHAÎNE D'IMPRESSION ABSENTE DU SHIPPABLE
`NF525-PRINT-01 · CAISSE-PRINT-01`
La spine déployable **ne peut imprimer AUCUN ticket** (ni auto ni manuel) — tout le pipeline ESC/POS SAGA vit sur `feat/pos-printer-saga-autoprint`, jamais mergée. Donc « il ne reste qu'à brancher l'imprimante » est **FAUX** tant que ce merge n'est pas fait. La branche porte AUSSI un fix NF525 (netting TVA par taux sur ticket remisé) absent de la spine.

## CLUSTER P1 #3 — FEATURES « LIVRÉES » MAIS INERTES EN PROD (gates déguisés)
`CAISSE-01-INERT · CMS-GATE-01 · CMS-GATE-02 · EVID-01`
- **CAISSE-01** (frites Grande/Cheddar) : ticket NF525 #2155 affiche +2€ mais **facture 0€** ; le « fix » est gracieux mais **inerte** sans seed ItemExtra (donnée owner). Reporting l'a parfois habillé en « triple-vert CLOSED » → c'est un **vrai under-bill en prod** déguisé en gate.
- **CMS** : suppression wizard entier (T-W5b) et builder presets+prix **côté ITEM** = gated demo-OFF → inertes en prod ; seule la variante CATÉGORIE marche.

## CLUSTER P1 #4 — NF525 NON PROUVABLE LOCALEMENT
`NF525-DB-01 · NF525-TRIG-01`
La box locale `foodking` = install ÉTRANGER re-seedé (63 items non-Le-Cayenne), schéma **pré-fiscal** : `migrate:status` = audit_logs/z_reports/fiscal_sequence_no/triggers TOUS *Pending* ; `fiscal:verify-chain --all` **CRASH** (`Unknown column branches.deleted_at`). Toute la « verdeur » fiscale repose sur **SQLite :memory: (logique seule)**. Les triggers d'immutabilité MySQL (SIGNAL 45000) ne tournent jamais en CI. → Aucune preuve NF525 reproductible sur le poste de validation (≠ prod OVH, d'où P1 pas P0) + drift catalogue (GATE-DATA-1).

## P1 isolés
- **INT-UNCOMMITTED-COLLIDE-02** : fix source non-commité sur la spine (`ProductComposerEditorComponent.vue` guard `source_ref` + spec, 28 lignes) → **perdu au prochain `git reset --hard`** (ce que fait deploy.sh) ou balayé par un `git add -A` parallèle. **Sauvegardé en patch** : `reports/audit/supervisor-2026-06-10/AT-RISK-uncommitted-composer-source_ref-guard.patch`. À committer sur branche AVANT toute intégration.
- **KDS-MULTIPOSTE-LOGIN** : le test « 2 postes » prouve le fan-out WS d'UN token cloné, pas 2 logins réels sur 2 écrans physiques.

## P2/P3 (dette, divulgués — non bloquants)
INT-2-STALE + EVID-02(rejeté) : GATE-INT-2/BRAIN périmés (2 P0 KDS dits absents = en fait FIXÉS sur la spine) · KDS-SOKETI-LIVENESS (/health/ready ne sonde pas soketi vivant) · CLI-05 (apps client = React DEV-build + Babel-in-browser via CDN unpkg, pas de build prod) · CLI-01 (loyalty redeem divergent mobile↔web) · CLI-02 (29 LCEN web + 0 page légale mobile) · EVID-03 (headline « 0 P0/P1 » contredit le détail CAISSE-01) · EVID-04 (compteurs tests sans transcript committé).

---

# PLAN SUPERVISEUR — chemin critique vers une V1 réellement en ligne

> Principe : **un seul arbre, build+test réel, DB fiscale propre, puis push**. Tout le reste est secondaire. Chaque étape = gate owner explicite quand elle touche prod/données.

## P-0 (immédiat, sûr) — Préserver l'existant à risque
1. Committer le fix `source_ref` (patch sauvegardé) sur une branche dédiée (chemins explicites, jamais `-A`). ✅ patch déjà backupé.
2. Geler l'état : tagger spine `003ca736b`, mobile-update, printer-saga, cms-gestion-spine (refs de survie avant toute manip).

## P-1 — Matérialiser LA branche release unique (`release/v1-2026-06-10`)
Ordre de merge prouvé sûr :
1. base = spine `003ca736b` (porte ultra-audit + validation profonde + clients-docs).
2. + `feat/pos-printer-saga-autoprint` → **0 conflit** (vérifié) — débloque l'impression.
3. + `goal/cms-gestion-2026-06-10-spine` → résoudre conflits **i18n ×5 + routes/api.php + bundles** (union i18n, routes additives, **rebuild bundles après**, pas merge des bundles).
4. + fix `source_ref` (P-0.1).
5. Décision owner : `heal/mobile-update-2026-06-10` (apps client standalone) — merger dans la release OU garder branche produit séparée (elles ne servent pas depuis le backend). **NE PAS** merger `heal/dashboard-redeep` (stale, régression).

## P-2 — Build + test RÉELS sur l'arbre intégré (la preuve qui manque)
`npm ci && npx mix --production` + `php artisan test` + `npx vitest run` **sur la release-branch** (pas sur les branches isolées). Sentinelles (BranchScope, FormRequestAuthz, frozen-SHA, KdsTodayWindowTz) vertes. Frozen diff = 0. **Committer les transcripts** (EVID-04).

## P-3 — DB fiscale propre (GATE-DATA-1, owner)
Sur un clone : restaurer le schéma fiscal complet (`migrate`) + le **vrai catalogue 45 items Le Cayenne** (purger les 63 items étrangers) + identité E.DELICE SAS (siret/vat/deleted_at). Puis `fiscal:verify-chain --all` = CHAIN OK **réel** (pas SQLite). C'est ce qui transforme la conformité NF525 de « logique » en « prouvée ». Sur la prod OVH : valider que SON schéma est complet (distinct du dev cassé).

## P-4 — Activer les features inertes (données owner)
- Seed ItemExtra Grande/Cheddar + étapes wizard → **active CAISSE-01** (sinon under-bill persiste).
- Décider flag demo des deliverables CMS item-level (wizard-delete, presets+prix item) : ON en prod ou rester catégorie-only.

## P-5 — PUSH → PRODUCTION (gate PUSH-1, owner — le seul vecteur de mise en ligne)
Push `release/v1-2026-06-10` → branche `production` → CI `deploy-production.yml` → `deploy.sh` (reset hard + mix prod + migrate). **Préalable** : `deploy.sh:99` warn sur hot-patches locaux OVH → vérifier qu'aucun correctif opérateur live ne sera écrasé. C'est l'étape qui met TOUT le travail validé en ligne d'un coup.

## P-6 — Reste hors chemin critique (post-go-live)
Impression physique (brancher SAGA + `pos:configure-receipt-printer <IP>` + ticket réel) · LCEN 29 placeholders (PUBLISH-1) · loyalty redeem cross-produit · /health/ready soketi-liveness · apps client build prod (sortir du CDN unpkg/Babel-in-browser) · MADV-2 reconciliation Phase-6.

## Gates owner à trancher (ordre)
1. **PUSH-1** (le débloqueur ultime — sans lui, 0 livraison).
2. **DATA-1** (DB fiscale propre + vrai catalogue 45).
3. Merge mobile-update dans release ou branche séparée.
4. Seed CAISSE-01 + flags demo CMS.
5. W6 parité wizard caisse (frozen, différable V1.0.X).

---
*Verdict : le projet est à ~1 chaîne d'intégration + 1 push d'être réellement livré ; il n'est PAS « fini » tant que la box OVH tourne 204 commits en arrière. La discipline anti-drift a tenu (frozen 0 ligne, NF525 non muté), mais la fragmentation + le gap de déploiement sont le vrai risque produit. Aucun P0 : aucun défaut ne corrompt la chaîne fiscale en l'état ; les P1 sont des manques d'intégration/déploiement/activation, pas des corruptions.*
