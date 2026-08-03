# FOODKING — OWNER GATES REGISTRY + ACCEPTANCE CRITERIA + ROLLBACK PLANS
**Date** : 2026-05-16
**Source** : `reports/audit/cto-global-2026-05-16/00_FINAL_CTO_VERDICT.md` (15 P0 + 15 P1)
**Audience** : Owner non-senior-dev — décide quoi Claude peut auto-exécuter vs ce qui nécessite signature humaine
**Doctrine** : CLAUDE.md §7 (frozen zones), §8 (NF525), §9 (multi-tenant), §10 (decision framework)

---

## §1 — Système de classification des gates (5 types)

| Gate | Nom | Signification | Action owner |
|------|-----|---------------|--------------|
| **OG-AUTO** | Auto-exécution Claude | Scope-minimal hors frozen-zone, low-risk, read-only audits, tests générés, runbooks. Claude exécute, teste, livre. Owner relit la PR. | Lire la PR + merger |
| **OG-RED-TEAM-FIRST** | RED-team adversarial obligatoire | Claude peut exécuter MAIS doit dispatcher RED-team sub-agent adversarial AVANT le ship. Tests régression triple-vert + visual gate si frontend. | Exiger le rapport RED-team avant merger |
| **OG-LOCK-DOC** | LOCK doc owner-signed requis | Touche frozen-zone (NF525, BranchScope, Pricing, PaymentService, OrderService, FrontendOrderService, KioskWizard, pos-wizard.js). Claude génère un LOCK_*.md via skill `/lock-plan`, owner signe avant exécution. | Signer LOCK_*.md → autoriser exécution |
| **OG-OWNER-DECIDE** | Décision architecturale/business owner | Claude propose 2-3 options. Owner choisit avant que Claude code. Concerne scope, pricing, TPE driver, archive AGENTS.md, etc. | Choisir 1 option + valider plan |
| **OG-OWNER-EXECUTE** | Action que Claude ne peut PAS faire | Rotation clés AWS console, signature contrats, AWS console, signing legal, certification fiscal tiers, deploy en prod first time. Claude rédige instructions, owner exécute. | Exécuter dans la console externe |

### Règle d'escalade (CLAUDE.md §10)
Si Claude rencontre l'un de ces signaux pendant l'exécution → **STOP + escalate à owner** :
- Critical risk découvert pendant l'exécution
- Stable rule contradicted par les findings
- Architecture direction uncertain
- Evidence too weak
- Business-critical correctness unclear
- Frozen-zone touch needed (non prévu au départ)
- Push to protected release branch
- Public PR creation
- Production data deletion

Max 3 loops de self-correct sur même problème — au-delà : escalate.

---

## §2 — Distribution des gates (30 items P0 + P1)

| Gate | Count P0 | Count P1 | Total | % |
|------|----------|----------|-------|---|
| **OG-AUTO** | 1 | 6 | 7 | 23% |
| **OG-RED-TEAM-FIRST** | 4 | 4 | 8 | 27% |
| **OG-LOCK-DOC** | 4 | 2 | 6 | 20% |
| **OG-OWNER-DECIDE** | 2 | 3 | 5 | 17% |
| **OG-OWNER-EXECUTE** | 4 | 0 | 4 | 13% |
| **TOTAL** | **15** | **15** | **30** | **100%** |

**Top-line** : 4 P0 nécessitent une action propre de l'owner (clés AWS, sign-off backup S3, signature legal, sign runbooks). 4 P0 nécessitent LOCK doc (Order collapse, OrderService refactor, Stripe cast frozen-zone, dual model). 4 P0 sont éligibles RED-team auto. Le reste est OG-OWNER-DECIDE (TPE, frozen-zones gate cible) ou OG-AUTO (E2E unblocking).

---

## §3 — Per-item Owner-Gate Registry

### 3.1 — P0 (15 items, bloque ouverture Le Cayenne)

| # | Item | Gate | Rationale |
|---|------|------|-----------|
| **P0-1** | AWS keys leaked `AKIAYJOT77SIZHDXNYOZ` (commit `a4a88df06`) non rotées | **OG-OWNER-EXECUTE** | Claude ne peut PAS toucher .env ni console AWS (CLAUDE.md §10). Rotation nécessite login AWS IAM + révocation old keys + propagation new keys. Owner-only. |
| **P0-2** | RCE primitive LanguageService + tokens Sanctum wildcard `['*']` | **OG-RED-TEAM-FIRST** | Hors frozen-zone (LanguageService.php n'est pas dans la liste §7). Patch route middleware + abilities role-scoped. RED-team obligatoire car sécurité critique + risque drift sur tokens existants. |
| **P0-3** | IDOR cross-branch via 39 occurrences `withoutGlobalScope(BranchScope::class)` | **OG-LOCK-DOC** | BranchScope.php est frozen (CLAUDE.md §7 backend multi-tenant). Toute revue des 39 sites + modification de scope appliquée nécessite LOCK doc. Risque cross-tenant data leak. |
| **P0-4** | Aucun backup automatisé, aucun restore testé | **OG-OWNER-EXECUTE** | Setup S3 bucket + GPG keys + object-lock = action AWS console + génération clés GPG owner-only. Claude écrit le code spatie/laravel-backup mais ne peut pas créer le bucket S3 ni la clé GPG owner. |
| **P0-5** | Aucun alerting câblé (Slack/Sentry/BetterUptime) | **OG-OWNER-EXECUTE** | Création comptes Sentry/BetterUptime + récupération DSN/webhook URL + ajout au `.env` prod = action owner externe. Claude peut câbler le code mais pas les comptes. |
| **P0-6** | Stripe charge `(int) $total * 100` tronque centimes (NF525 mismatch) | **OG-LOCK-DOC** | `app/Services/Payments/PaymentService.php` est en frozen-zone (memory/reference_frozen_zones.md ligne 14). LOCK doc surgical patch obligatoire. NF525 fiscal-impact = audit tiers possible. |
| **P0-7** | Dual `Order` / `FrontendOrder` sur même table `orders` | **OG-LOCK-DOC** | Touche `app/Services/Orders/OrderService.php` (frozen NF525) + observers + models. Effort 4 semaines selon audit. LOCK doc + RED-team plan AVANT toute ligne code. |
| **P0-8** | Mobile allergens fabriqués (60/60 items default `['gluten','lactose']`) | **OG-OWNER-DECIDE** | Décision business : owner doit valider les vraies recettes/allergènes item-par-item. Claude peut fixer le default `[]` (1 ligne) mais la curation recette est owner. Risque légal FIC 1169/2011. |
| **P0-9** | Mobile promo code affiche "✓ Code appliqué" sans appliquer discount | **OG-OWNER-DECIDE** | Décision scope : (a) implémenter backend complet (~2-3j) (b) retirer le bouton V1 (~30min). Owner choisit. |
| **P0-10** | 10 runbooks tous `DRAFT_SKELETON_NOT_SIGNED` | **OG-AUTO + sign-off owner** | Claude peut écrire les commandes copy-paste (OG-AUTO). MAIS sign-off (`SIGNED_BY_OWNER_2026-XX-XX`) nécessite owner test en staging et certification. Split workflow. |
| **P0-11** | POS direct-cash → CashMovement wiring untested | **OG-RED-TEAM-FIRST** | Feature test bout-en-bout hors frozen-zone (test code seulement). RED-team pour s'assurer que les invariants CashDrawerService sont testés (P0-09 historique Wave Z 2026-05-09). |
| **P0-12** | OrderService.php 2432 LOC + 5 mutations status bypassent OrderStateMachine | **OG-LOCK-DOC** | `app/Services/Orders/OrderService.php` est frozen-zone (memory/reference_frozen_zones.md ligne 13). 5 sites refactor (lignes 1530, 1609, 1714, 1820, 1907) = surgical mais frozen. LOCK doc obligatoire. |
| **P0-13** | PHPSpreadsheet 1.30.0 CVE-2024-45048 RCE via admin Excel import | **OG-RED-TEAM-FIRST** | Upgrade composer + revue surface attaque admin = hors frozen-zone. RED-team pour vérifier que l'upgrade ne casse pas les imports existants + test régression. |
| **P0-14** | Laravel 9.52 EOL (sécurité plus patchée) | **OG-OWNER-DECIDE** | Owner doit choisir track : (a) migration L10 puis L11 séquentielle (b) skip L10 → L11 direct (c) reporter Phase 2. Décision impact 2-4 semaines effort. APRÈS décision, exécution = OG-RED-TEAM-FIRST. |
| **P0-15** | E2E non-bloquant CI (`continue-on-error: true` + label opt-in) | **OG-AUTO** | Modification `.github/workflows/playwright.yml` + smoke pack 5 specs = scope-minimal hors frozen-zone. Auto-exécutable, owner relit la PR. |

### 3.2 — P1 (15 items, 4-12 semaines)

| # | Item | Gate | Rationale |
|---|------|------|-----------|
| **P1-16** | 14 controllers importent `DB` facade direct, logique métier inline | **OG-RED-TEAM-FIRST** | Refactor controllers → services thin. Hors frozen-zone (controllers non listés §7). RED-team pour cohérence patterns. |
| **P1-17** | OrderStateMachine::apply() utilisé seulement 2× (pattern half-adopted) | **OG-LOCK-DOC** | Couplé P0-12. Touche OrderService frozen. LOCK doc partagé. |
| **P1-18** | Frontend zéro architectural layering (0 API client, 113 Vuex flat) | **OG-OWNER-DECIDE** | Décision : (a) commencer migration POS-V5 Composition+Pinia (b) wrap Vuex stores en API client layer seul (c) reporter Phase 2. Owner décide effort scope. |
| **P1-19** | 39 `withoutGlobalScope(BranchScope::class)` — multi-tenant leak potentiel | **OG-LOCK-DOC** | Couplé P0-3. Même LOCK doc, ou LOCK doc séparé scope-élargi. |
| **P1-20** | KDS UX 3.2/10 (8 P0 audit 2026-05-11 — raw labels, contrast 3.2:1) | **OG-RED-TEAM-FIRST** | Hors frozen-zone (KitchenDisplaySystemComponent.vue n'est pas listé §7). Visual gate obligatoire. RED-team pour valider que les fixes ne cassent pas le V2 flip récent. |
| **P1-21** | POS Vanilla wizard 0 ARIA, 32px touch, 100% FR hardcoded | **OG-LOCK-DOC** | `public/js/pos-wizard.js` est frozen-zone explicite (CLAUDE.md §7). LOCK doc + surgical patch ≤200 lignes diff (Phase 1 semaine 4 du verdict CTO). |
| **P1-22** | Bug `branch.status=1 vs 5` documenté non corrigé | **OG-AUTO** | Fix 1-2 lignes dans `PersistCatalogChangedToOutbox.php:38-41`. Scope-minimal. Tests régression. |
| **P1-23** | 23 `assertTrue(true)` dans tests fiscal/payment/state-machine | **OG-AUTO** | Réécriture tests fakes en tests réels = scope-minimal test code seulement. Hors frozen-zone (tests/ pas listé). |
| **P1-24** | Frozen-zones hook safety-check.sh liste 2 fichiers vs 13+ doctrine | **OG-OWNER-DECIDE** | Owner doit valider la liste cible (13 fichiers de CLAUDE.md §7 ? + ajouts depuis audit ?). APRÈS décision, exécution OG-AUTO. |
| **P1-25** | Aucun script deploy/rollback (`bin/` vide pour ops) | **OG-OWNER-EXECUTE-PARTIAL** | Claude peut écrire `bin/deploy.sh` + `bin/rollback.sh`. MAIS test réel deploy = action owner sur serveur prod (SSH + supervisord + nginx config). |
| **P1-26** | Contradiction CLAUDE.md vs AGENTS.md (les deux loadés à chaque session) | **OG-OWNER-DECIDE** | Owner doit décider SSOT : (a) archiver AGENTS.md → `AGENTS_LEGACY_2026-05.md` (b) merger contenu utile dans CLAUDE.md (c) split par rôle clair. |
| **P1-27** | Aucun driver TPE natif (payment_terminals table seule, BypassMode actif) | **OG-OWNER-DECIDE** | Décision business + technique : choix TPE marque (Ingenico Tetra recommandé Agent 7), budget intégration, scope V1 vs V2. Owner-only. |
| **P1-28** | 88 endpoints sans FormRequest authz (scheduled V1.0.1) | **OG-RED-TEAM-FIRST** | Refactor sécurité scope-large. Hors frozen-zone. RED-team obligatoire authz coverage matrix. |
| **P1-29** | Stress test théâtral (sqlite-memory `lockForUpdate no-op`) | **OG-AUTO** | Migration test harness sqlite → MySQL CI matrix. Scope-minimal test infra. |
| **P1-30** | Vuex modules 113 flat — pas de migration Pinia entamée | **OG-OWNER-DECIDE** | Couplé P1-18. Décision stratégique frontend. |

---

## §4 — Acceptance Criteria Templates (15 P0)

> **Convention** : Owner coche chaque case AVANT merger. Sign-off date obligatoire en bas.
> Toute commande est copy-paste depuis le repo racine `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`.

### ITEM: P0-1 — Rotation clés AWS leakées
```
GATE: OG-OWNER-EXECUTE
ACCEPTANCE CRITERIA (all must be checked before merge):
- [ ] Login AWS console IAM → user contenant key AKIAYJOT77SIZHDXNYOZ
- [ ] Créer NEW access key pour le même user
- [ ] Ajouter NEW keys dans .env prod (AWS_ACCESS_KEY_ID + AWS_SECRET_ACCESS_KEY)
- [ ] Redéployer le code prod avec le .env mis à jour
- [ ] Tester upload S3 (commande: `php artisan tinker` → `Storage::disk('s3')->put('test-rotation.txt', 'ok');`)
- [ ] Si test OK → révoquer OLD key dans AWS console IAM
- [ ] Vérifier `aws iam list-access-keys --user-name <user>` n'affiche plus l'old key
- [ ] Installer gitleaks pre-commit hook (`brew install gitleaks` + `.gitleaks.toml`)
- [ ] Test gitleaks: créer fichier test avec fake AWS key → vérifier hook bloque
- [ ] CI workflow `.github/workflows/security.yml` avec gitleaks-action ajouté
- [ ] PR draft avec hook + CHECKLIST.md des autres secrets (Stripe, Pusher, APP_KEY, FISCAL_*_SECRET)
- [ ] Visual gate: N/A (backend only)
- [ ] RED-team review: N/A (action owner)
- [ ] Rollback verified: si new key cassé → restaurer ancien .env (gardé en backup 24h), revert deploy
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-2 — Patch RCE LanguageService + tokens Sanctum scopés
```
GATE: OG-RED-TEAM-FIRST
ACCEPTANCE CRITERIA:
- [ ] Route `admin/language/*` (`routes/api.php:486`) wrappée dans `middleware('permission:settings')`
- [ ] `LanguageService::edit` whitelist `realpath()` ⊂ `lang_path()` ajoutée
- [ ] Rejet pattern `<?` dans les values langues
- [ ] LoginController.php:87-91 + GuestSignupController.php:140: abilities role-scoped (cashier=['pos:order'], kiosk=['kiosk:order'], admin=['admin:catalog','admin:report','admin:fiscal'])
- [ ] Tokens existants révoqués: `DB::table('personal_access_tokens')->delete();` (force re-login)
- [ ] PHPUnit test: `php artisan test --filter=LanguageServiceSecurityTest` passe (exit 0)
- [ ] PHPUnit test: `php artisan test --filter=SanctumAbilitiesTest` passe (exit 0)
- [ ] Test régression: kiosk token ne peut PAS appeler /admin/* (assertion 403)
- [ ] CI lint regex: `grep -rn "createToken.*\['\*'\]" app/` retourne 0 hits
- [ ] Visual gate: capture login screen + admin language UI après patch
- [ ] RED-team review attached: 1 rapport sub-agent Security dans `reports/audit/P0-2-red-team/`
- [ ] Rollback verified: revert PR + re-emit wildcard tokens via factory si rollback nécessaire
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-3 — IDOR cross-branch via withoutGlobalScope
```
GATE: OG-LOCK-DOC
ACCEPTANCE CRITERIA:
- [ ] LOCK doc `LOCK_BRANCHSCOPE_AUDIT_2026-05-XX.md` créé et signé owner
- [ ] Liste des 39 occurrences `withoutGlobalScope(BranchScope::class)` cataloguée par fichier
- [ ] Chaque occurrence classée: (a) légitime admin cross-branch (b) légitime pre-auth (c) potentielle IDOR
- [ ] Pour chaque (c): assertion `auth()->user()->branch_id === $resource->branch_id` ajoutée OU endpoint déplacé sous middleware admin
- [ ] PHPUnit test cross-tenant: `php artisan test --filter=BranchIsolationIdorTest` passe (exit 0)
- [ ] PHPUnit test: cashier branch=2 ne peut PAS lire order branch=3 (assertion 403/404)
- [ ] PHPUnit test: admin branch_id=0 peut lire toutes branches (admin bypass)
- [ ] Sentinel `tests/sentinels/branch_isolation.test.php` étend les invariants
- [ ] Visual gate: capture PosOrderController routes en staging avec compte cashier multi-branch
- [ ] RED-team review attached: sub-agent Security relit chaque site IDOR
- [ ] Rollback verified: si fix casse admin cross-branch → revert PR + investigation
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-4 — Backup automatisé + DR drill
```
GATE: OG-OWNER-EXECUTE
ACCEPTANCE CRITERIA:
- [ ] Owner: créer bucket S3 `foodking-backups-prod` avec object-lock 6 ans enabled
- [ ] Owner: générer clé GPG `gpg --gen-key` + exporter pubkey
- [ ] Owner: ajouter AWS S3 credentials dédiés backup (IAM user `foodking-backup-writer`, write-only policy)
- [ ] `composer require spatie/laravel-backup` exécuté
- [ ] `config/backup.php` configuré: schedule daily 03:00, destination S3, GPG encryption
- [ ] Cron schedule ajouté à `app/Console/Kernel.php`: `$schedule->command('backup:run')->dailyAt('03:00')`
- [ ] Test manuel: `php artisan backup:run` réussit + fichier visible dans S3
- [ ] Restore drill staging: drop table orders → `mysql -u root foodking_staging < backup.sql` → vérifier 1 order existing
- [ ] Drill timing documenté: < 30 min restore + outbox replay
- [ ] Runbook `docs/runbooks/RUNBOOK_DR_RESTORE.md` signé (pas DRAFT)
- [ ] Visual gate: capture S3 console montrant le bucket + object-lock + 1 backup file
- [ ] RED-team review: N/A (owner action + drill verified)
- [ ] Rollback verified: drill réussi = preuve que restore fonctionne
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-5 — Alerting câblé (Slack + Sentry + BetterUptime)
```
GATE: OG-OWNER-EXECUTE
ACCEPTANCE CRITERIA:
- [ ] Owner: créer compte Sentry → projet FoodKing-prod → récupérer DSN
- [ ] Owner: créer Slack workspace `#foodking-prod-alerts` + incoming webhook URL
- [ ] Owner: créer compte BetterUptime → monitor `/health/live` toutes 60s
- [ ] `.env` prod ajout: `SENTRY_LARAVEL_DSN=...` + `LOG_SLACK_WEBHOOK_URL=...`
- [ ] `composer require sentry/sentry-laravel` exécuté
- [ ] Sentry frontend SDK: `npm install @sentry/vue` + init dans `resources/js/app.js`
- [ ] Test trigger Sentry: `Log::error('test-sentry')` → vérifier event visible Sentry UI
- [ ] Test trigger Slack: `Log::channel('slack')->error('test-slack')` → vérifier message Slack
- [ ] Test BetterUptime: stopper le serveur 3 min → vérifier alerte SMS/email reçue
- [ ] Visual gate: screenshot Sentry dashboard + Slack channel + BetterUptime monitor
- [ ] RED-team review: N/A (live monitoring vérification = drill)
- [ ] Rollback verified: si alerting flood → désactiver canal Slack (env var `LOG_SLACK_WEBHOOK_URL=`), redéployer
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-6 — Stripe charge bcmath round-half-up (frozen-zone)
```
GATE: OG-LOCK-DOC
ACCEPTANCE CRITERIA:
- [ ] LOCK doc `LOCK_STRIPE_CENTS_CAST_2026-05-XX.md` créé et signé owner
- [ ] `app/Services/Payments/PaymentService.php` (frozen) modifié: remplacer `(int) $total * 100` par `intval(bcmul((string) $total, '100', 0))` avec rounding half-up
- [ ] PHPUnit test régression: `php artisan test --filter=StripeChargeCentsTest` passe (exit 0)
- [ ] Test edge cases couverts: €9.99, €0.01, €10.005, €9.999, €0.00
- [ ] Test NF525 fiscal sequence: charge €X.99 → fiscal entry montre montant exact (pas tronqué)
- [ ] Audit log: vérifier `audit_logs` table reflète le montant exact post-fix
- [ ] Test régression: HMAC chain intacte (pas de break post-patch)
- [ ] Visual gate: capture admin payment detail screen montrant montant exact post-fix
- [ ] RED-team review attached: sub-agent Security + DBA relisent diff
- [ ] Rollback verified: revert LOCK doc + revert patch (test PHPUnit régression doit alors fail = preuve patch nécessaire)
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-7 — Collapse Order / FrontendOrder
```
GATE: OG-LOCK-DOC
ACCEPTANCE CRITERIA:
- [ ] LOCK doc `LOCK_ORDER_COLLAPSE_2026-05-XX.md` créé et signé owner
- [ ] Plan détaillé file:line dans le LOCK doc (fillable consolidé, observer single attach, view scopes)
- [ ] Branch feature dédiée: `feature/order-collapse-2026-05-XX`
- [ ] PHPUnit test: `php artisan test --filter=OrderModelUnifiedTest` passe (exit 0)
- [ ] Test régression POS: création order POS produit row structure identique à kiosk
- [ ] Test régression Kiosk: création order Kiosk produit row structure identique à POS
- [ ] Test fiscal: allocation `fiscal_sequence_no` OK pour les 2 surfaces
- [ ] Test audit_log: 1 row écrit par order, peu importe surface
- [ ] Test idempotency: `X-Idempotency-Key` intact post-collapse
- [ ] Migration data si différence: `php artisan migrate` succeed + rollback testé
- [ ] Sentinel: `tests/sentinels/order_unified_model.test.php` ajouté
- [ ] Visual gate: capture admin order detail POS + Kiosk montrant rendering identique
- [ ] RED-team review attached: Architect + DBA + Tester sub-agents
- [ ] Rollback verified: branch revert + restore observer FrontendOrder + restore separate model (preserved en backup)
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-8 — Mobile allergens fabriqués (60/60 items)
```
GATE: OG-OWNER-DECIDE
ACCEPTANCE CRITERIA:
- [ ] Owner DECIDE: option (a) curation manuelle complète item-par-item AVANT release, OU (b) default `[]` puis curation progressive avec banner "allergènes en cours de validation"
- [ ] Fix code: `mobile/data/menu.js:274` default `[]` au lieu de `['gluten','lactose']`
- [ ] Curation owner: fournir CSV `mobile/data/allergens-validated.csv` avec colonnes (item_id, allergens, validated_by_owner_date)
- [ ] Script `mobile/scripts/apply-allergens.js` injecte la curation dans menu.js
- [ ] Test E2E mobile: order eau minérale → vérifier 0 allergène affiché
- [ ] Test E2E mobile: order item curated → vérifier allergènes corrects affichés
- [ ] Visual gate: screenshot mobile item detail (eau minérale + sandwich) en RTL et LTR
- [ ] Vérification légale: confirmer compliance EU FIC 1169/2011 (texte légal affiché)
- [ ] RED-team review attached: Frontend + Tester sub-agents
- [ ] Rollback verified: revert PR + restaurer le default `['gluten','lactose']` legacy (NON recommandé - exposition légale)
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-9 — Mobile promo code stub
```
GATE: OG-OWNER-DECIDE
ACCEPTANCE CRITERIA:
- [ ] Owner DECIDE: option (a) implémenter backend complet (~2-3j) OU (b) retirer bouton V1 (~30min, recommandé)
- [ ] Si (a): endpoint POST /api/coupons/validate → applique discount au panier mobile
- [ ] Si (b): commenter/supprimer code `mobile/screens-main.jsx:595` et bouton trigger
- [ ] Test E2E mobile: appliquer fake promo "XYZ" → soit erreur claire "Code invalide" (a) soit bouton absent (b)
- [ ] Test E2E mobile: aucun banner trompeur "✓ Code appliqué" SANS discount visible
- [ ] Visual gate: screenshot mobile cart avant/après promo (option choisie)
- [ ] RED-team review attached: Frontend sub-agent confirme UX cohérent
- [ ] Rollback verified: si (a) cassé → switch to (b) via feature flag `mobile.promo_enabled=false`
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-10 — Sign 4 runbooks critiques
```
GATE: OG-AUTO + OWNER SIGN-OFF
ACCEPTANCE CRITERIA:
- [ ] Claude AUTO: rewrite `RUNBOOK_FISCAL_SEQUENCE_BREAK.md` avec commandes `php artisan` copy-paste
- [ ] Claude AUTO: rewrite `RUNBOOK_KIOSK_NETWORK_LOSS.md` avec commandes copy-paste + fallback cashier procedure
- [ ] Claude AUTO: rewrite `RUNBOOK_OUTBOX_BLOCKED.md` avec commandes (queue:work + outbox replay)
- [ ] Claude AUTO: rewrite `RUNBOOK_ROLLBACK_CANARY.md` avec commandes deploy/rollback
- [ ] Owner: tester chaque runbook en staging (timer chrono pour mesurer temps)
- [ ] Owner: itération avec Claude si blocage
- [ ] Owner: tag header `DRAFT_SKELETON_NOT_SIGNED` → `SIGNED_BY_OWNER_2026-XX-XX`
- [ ] Cheatsheet plastifiée 1 page imprimée pour Le Cayenne
- [ ] Test e2e drill: owner joue scénario "fiscal sequence break vendredi 19h" → < 10 min recovery
- [ ] Visual gate: photo de la cheatsheet plastifiée + screenshot des 4 runbooks SIGNED
- [ ] RED-team review attached: SRE sub-agent valide commandes copy-paste
- [ ] Rollback verified: garder ancien runbook DRAFT_SKELETON en `archive/` pour référence
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-11 — POS direct-cash → CashMovement wiring untested
```
GATE: OG-RED-TEAM-FIRST
ACCEPTANCE CRITERIA:
- [ ] Feature test créé: `tests/Feature/Pos/DirectCashMovementTrailTest.php`
- [ ] Test couvre: POS direct-cash → CashMovement row inséré → CashDrawerSession affectée
- [ ] Test idempotency: même order → 1 seul CashMovement (pas duplicate)
- [ ] Test concurrent: 2 cashiers simultanés → 0 lock contention error
- [ ] Test Z-report: cash trail apparaît dans Z-report avec montant exact
- [ ] PHPUnit: `php artisan test --filter=DirectCashMovementTrailTest` passe (exit 0)
- [ ] Test régression Wave Z 2026-05-09: P0-09 CashDrawerService no lock = couvert par UNIQUE constraint check
- [ ] Visual gate: screenshot Z-report avec ligne cash visible
- [ ] RED-team review attached: Tester sub-agent + Cash domain expert
- [ ] Rollback verified: tests added en `tests/Feature/` = revert simple via git
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-12 — OrderService refactor + OrderStateMachine seul writer (frozen)
```
GATE: OG-LOCK-DOC
ACCEPTANCE CRITERIA:
- [ ] LOCK doc `LOCK_ORDERSERVICE_STATEMACHINE_2026-05-XX.md` créé et signé owner
- [ ] Tests RED écrits AVANT modification: pour chaque mutation status (lignes 1530, 1609, 1714, 1820, 1907), test assertant l'invariant state-machine échoue actuellement
- [ ] Chaque mutation directe `$order->status =` remplacée par `OrderStateMachine::apply($order, $next, $actor, $reason)`
- [ ] Tests GREEN: `php artisan test --filter=OrderStateMachineOnlyWriterTest` passe (exit 0)
- [ ] Grep final: `grep -rn '->status\s*=' app/Services/Orders/ app/Http/Controllers/` retourne 0 hits (hors OrderStateMachine.php lui-même)
- [ ] CI lint rule: `.github/workflows/state-machine-guard.yml` qui fail le CI sur ces patterns
- [ ] PHPUnit régression: `php artisan test` complet passe (pas seulement filter)
- [ ] Sentinel: `tests/sentinels/order_state_machine_writer_invariant.test.php`
- [ ] Visual gate: screenshot admin order detail avec transitions normales + transitions interdites bloquées
- [ ] RED-team review attached: Architect + Security + Tester sub-agents (3 reports)
- [ ] Rollback verified: revert LOCK doc + revert 5 sites = OrderService.php restauré (gardé en branch backup)
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-13 — PHPSpreadsheet 1.30.0 CVE upgrade
```
GATE: OG-RED-TEAM-FIRST
ACCEPTANCE CRITERIA:
- [ ] `composer require phpoffice/phpspreadsheet:^2.0` exécuté
- [ ] `composer audit` retourne 0 CVE pour phpspreadsheet
- [ ] Test régression admin Excel imports: tester chaque endpoint `/admin/*/import` avec fichier .xlsx valide
- [ ] Test régression admin Excel exports: vérifier exports `/admin/*/export` produisent fichiers valides
- [ ] PHPUnit: `php artisan test --filter=AdminExcelImportTest` passe (exit 0)
- [ ] PHPUnit: `php artisan test --filter=AdminExcelExportTest` passe (exit 0)
- [ ] Test sécurité: fichier .xlsx malveillant (XXE payload) → rejected, pas de RCE
- [ ] Sentinel sécurité: `tests/sentinels/phpspreadsheet_cve_2024_45048.test.php`
- [ ] Visual gate: capture admin import/export UI montrant succès post-upgrade
- [ ] RED-team review attached: Security sub-agent valide surface attaque
- [ ] Rollback verified: `composer require phpoffice/phpspreadsheet:1.30.0` revert + lock CVE en backlog
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-14 — Laravel 9.52 EOL → upgrade
```
GATE: OG-OWNER-DECIDE (puis OG-RED-TEAM-FIRST exécution)
ACCEPTANCE CRITERIA:
- [ ] Owner DECIDE: track (a) L9 → L10 → L11 séquentiel (~3-4 semaines, low-risk) OU (b) L9 → L11 direct (~2 semaines, breaking changes plus larges) OU (c) reporter Phase 2 si V1 ouvre dans <6 semaines
- [ ] APRÈS décision: plan détaillé `plans/MASTER_LARAVEL_UPGRADE_2026-05.md` rédigé par Claude
- [ ] Branch dédiée `feature/laravel-upgrade-2026-05`
- [ ] `composer update laravel/framework` selon track choisi
- [ ] PHPUnit complet: `php artisan test` passe (exit 0) — 443 tests verts
- [ ] Vitest: `npm run test` passe (exit 0)
- [ ] Sanctum upgrade: tokens existants compatibles
- [ ] Spatie permissions upgrade: roles compatibles
- [ ] Test régression NF525: fiscal sequence + HMAC chain intacts
- [ ] Test régression idempotency: middleware compatible L10/L11
- [ ] Visual gate: smoke E2E sur kiosk + POS + KDS + admin post-upgrade
- [ ] RED-team review attached: Architect + Security + Tester (3 reports)
- [ ] Rollback verified: branch revert + `composer require laravel/framework:9.52` restauration
OWNER SIGN-OFF: __________ DATE: ____
```

### ITEM: P0-15 — E2E bloquant CI
```
GATE: OG-AUTO
ACCEPTANCE CRITERIA:
- [ ] `.github/workflows/playwright.yml`: ligne 37-40 retiré opt-in label `e2e-required`
- [ ] `.github/workflows/playwright.yml`: ligne 36 `continue-on-error: true` retiré
- [ ] Smoke pack 5 specs documenté dans `tests/e2e/smoke/`: kiosk_happy.spec.js, pos_cash.spec.js, kds_bump.spec.js, oss_update.spec.js, fiscal_z_close.spec.js
- [ ] Smoke pack bloquant : tag `@smoke` ajouté aux 5 specs + workflow filter `--grep @smoke`
- [ ] Full pack reste opt-in via label `e2e-full-required`
- [ ] CI test: PR test avec smoke fail → CI fail (vérifié manuellement)
- [ ] Visual gate: capture GitHub Actions workflow runs montrant smoke verts
- [ ] RED-team review: N/A (auto-exécutable scope-minimal)
- [ ] Rollback verified: revert workflow files via git
OWNER SIGN-OFF: __________ DATE: ____
```

---

## §5 — Rollback Plan per P0 (15 plans)

> **Tous les chemins sont absolus relatifs au repo root** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
> **Pré-requis pour TOUS les rollbacks** : avoir un git commit hash `pre-fix` noté avant l'application du fix.

### P0-1 — AWS keys rotation
```
ROLLBACK:
1. Si le redéploiement avec NEW keys casse prod → re-ajouter OLD AWS keys au .env prod
   (Owner garde un backup `.env.pre-rotation-2026-05-XX` 24h max)
2. Redéployer avec OLD keys: `ssh prod 'cd /var/www/foodking && cp /tmp/.env.pre-rotation .env && systemctl restart php-fpm'`
3. Verify: `curl https://foodking.fr/health/ready | jq .checks.s3` retourne `ok`
TIME TO ROLLBACK: 5 minutes
DATA LOSS RISK: No — uploads S3 récents post-rotation pourraient être inaccessibles avec OLD keys SI bucket policy changé. Backup mental S3 avant rotation.
NOTE: Action irréversible une fois OLD key révoquée. Garder OLD key active 24h en parallèle, révoquer après confirmation NEW key OK.
```

### P0-2 — RCE LanguageService patch + tokens scopés
```
ROLLBACK:
1. `git revert <commit-hash-fix>` créer revert commit
2. `git push origin <branch>` puis merger revert PR
3. Re-émettre tokens wildcard si flow cassé: `DB::table('personal_access_tokens')->whereNull('abilities')->update(['abilities' => json_encode(['*'])]);` (DERNIER RECOURS)
TIME TO ROLLBACK: 10 minutes (revert + redeploy)
DATA LOSS RISK: No — tokens existants ont été révoqués lors du fix. Users devront re-login. Pas de perte data.
```

### P0-3 — IDOR cross-branch (LOCK doc requis)
```
ROLLBACK:
1. `git revert <commit-hash-fix>` créer revert commit pour assertions ajoutées
2. Re-déployer: assertions branch_id retirées des 39 sites
3. Verify: `php artisan test --filter=BranchIsolationIdorTest` doit alors fail (preuve assertions retirées)
TIME TO ROLLBACK: 15 minutes
DATA LOSS RISK: No — uniquement code logic, pas de data migration. MAIS: rollback expose à nouveau à IDOR cross-branch → NE PAS rollback sans plan B.
```

### P0-4 — Backup automatisé + DR drill
```
ROLLBACK:
1. Si backup quotidien sature disque/budget S3 → désactiver schedule:
   `php artisan schedule:work --no-interaction` puis commenter ligne `backup:run` dans `app/Console/Kernel.php`
2. `git revert` config/backup.php + Kernel.php changes
3. Verify: `php artisan schedule:list` ne montre plus `backup:run`
TIME TO ROLLBACK: 5 minutes
DATA LOSS RISK: No (backups passés conservés en S3 6 ans via object-lock). MAIS: rollback = revenir à 0 backup → NE PAS rollback sans plan alternatif.
```

### P0-5 — Alerting (Slack + Sentry + BetterUptime)
```
ROLLBACK:
1. Si alerting flood/false-positive → vider env var:
   `ssh prod 'sed -i "s/LOG_SLACK_WEBHOOK_URL=.*/LOG_SLACK_WEBHOOK_URL=/" .env && systemctl restart php-fpm'`
2. Désactiver Sentry: `SENTRY_LARAVEL_DSN=` (vide) puis redeploy
3. Désactiver BetterUptime monitor depuis dashboard external
TIME TO ROLLBACK: 5 minutes
DATA LOSS RISK: No — alerts historiques conservés dans Sentry/Slack history.
```

### P0-6 — Stripe bcmath round-half-up (frozen-zone)
```
ROLLBACK:
1. `git revert <commit-hash-fix>` PaymentService.php
2. Re-déployer: PaymentService.php `(int) $total * 100` restauré
3. Verify: `php artisan test --filter=StripeChargeCentsTest` doit fail (preuve patch retiré)
TIME TO ROLLBACK: 10 minutes (revert + redeploy)
DATA LOSS RISK: Yes — paiements futurs reviennent à la troncation centimes. NF525 mismatch. Audit logs fiscal seraient incohérents avec montants réels Stripe. NE PAS rollback sans escalation NF525.
```

### P0-7 — Collapse Order/FrontendOrder (frozen-zone, LOCK)
```
ROLLBACK:
1. `git checkout backup/order-collapse-pre-2026-05-XX` (branch backup créée avant collapse)
2. Restore FrontendOrder model + observer attachment dans AppServiceProvider.php
3. `php artisan migrate:rollback --step=N` si migration data effectuée
4. Verify: `php artisan test --filter=OrderModelUnifiedTest` doit fail; `php artisan test --filter=FrontendOrderObserverTest` doit passer
TIME TO ROLLBACK: 30-60 minutes (branch restore + migration rollback + smoke E2E)
DATA LOSS RISK: Yes — orders créés POST-collapse pourraient avoir structure non-rétrocompatible avec FrontendOrder. Backup DB obligatoire AVANT collapse pour pouvoir restore.
```

### P0-8 — Mobile allergens fabriqués
```
ROLLBACK:
1. `git revert <commit-hash-fix>` sur mobile/data/menu.js
2. Re-build mobile bundle: `cd mobile && npx expo build`
3. Re-deploy via Expo OTA ou store update
TIME TO ROLLBACK: 30 minutes (build + Expo OTA push) OU 1-7 jours si store review required
DATA LOSS RISK: Yes — exposition légale FIC 1169/2011 si default `['gluten','lactose']` réintroduit sur eau minérale. NE PAS rollback sans escalation legal/compliance.
NOTE: mobile bundle ship via Expo store = rollback non-instantané. Privilégier hotfix forward (curation correcte) plutôt que rollback.
```

### P0-9 — Mobile promo code stub
```
ROLLBACK:
1. `git revert <commit-hash-fix>` mobile/screens-main.jsx
2. Re-build + redeploy mobile bundle (Expo OTA)
3. Verify: bouton "Appliquer code promo" réapparaît
TIME TO ROLLBACK: 30 minutes (build + Expo OTA)
DATA LOSS RISK: No — purement UX. MAIS: rollback réintroduit le banner trompeur "✓ Code appliqué" sans discount → UX trompeuse pour client.
```

### P0-10 — Runbooks signés
```
ROLLBACK:
1. Restaurer DRAFT_SKELETON depuis `docs/runbooks/archive/`:
   `cp docs/runbooks/archive/RUNBOOK_*_DRAFT_2026-04-XX.md docs/runbooks/`
2. Tag header re-modifier en `DRAFT_SKELETON_NOT_SIGNED` via Edit
3. Verify: `grep -l 'SIGNED_BY_OWNER' docs/runbooks/` retourne 0 fichiers
TIME TO ROLLBACK: 2 minutes
DATA LOSS RISK: No — runbooks SIGNED conservés en archive. Rollback = perte de la qualité documentation mais zéro impact code.
```

### P0-11 — POS direct-cash → CashMovement test
```
ROLLBACK:
1. `git revert <commit-hash-fix>` tests/Feature/Pos/DirectCashMovementTrailTest.php (suppression test)
2. Verify: `php artisan test --filter=DirectCashMovementTrailTest` retourne "no tests"
TIME TO ROLLBACK: 1 minute
DATA LOSS RISK: No — test code seulement, pas de modification logic.
NOTE: Rollback = revenir à l'état Wave Z 2026-05-09 où cash trail untested. Réintroduit le risque P0 sans le confirmer.
```

### P0-12 — OrderService refactor (frozen-zone)
```
ROLLBACK:
1. `git checkout backup/orderservice-pre-statemachine-2026-05-XX` (branch backup)
2. Restore OrderService.php avec 5 mutations directes status
3. Disable CI lint rule: `git revert <state-machine-guard.yml commit>`
4. Verify: `grep -rn '->status\s*=' app/Services/Orders/` retourne 5+ hits
TIME TO ROLLBACK: 20 minutes
DATA LOSS RISK: No — code logic seulement, orders existing conservés. MAIS: rollback réintroduit risque transitions illegales (PAID→PENDING, CANCELLED→PAID).
```

### P0-13 — PHPSpreadsheet upgrade
```
ROLLBACK:
1. `composer require phpoffice/phpspreadsheet:1.30.0` retour version vulnérable
2. `composer install --no-dev` redeploy
3. Verify: `composer show phpoffice/phpspreadsheet | grep versions` montre 1.30.0
TIME TO ROLLBACK: 10 minutes
DATA LOSS RISK: No — code logic seulement, fichiers Excel importés conservés. MAIS: rollback réintroduit CVE-2024-45048 RCE exposé via admin import.
```

### P0-14 — Laravel upgrade
```
ROLLBACK:
1. `git checkout backup/laravel-9.52-pre-upgrade-2026-05-XX` (branch backup)
2. `composer install --no-dev` restaure dependencies L9.52
3. `php artisan migrate:rollback --step=N` si migrations L10/L11 appliquées (à inventorier dans LOCK doc)
4. Verify: `php artisan --version` retourne `Laravel Framework 9.52.x`
TIME TO ROLLBACK: 60-120 minutes (rollback migrations + smoke E2E full)
DATA LOSS RISK: Yes — migrations Laravel framework peuvent toucher schema sessions, jobs, cache. Backup DB obligatoire AVANT upgrade.
```

### P0-15 — E2E bloquant CI
```
ROLLBACK:
1. `git revert <commit-hash-fix>` `.github/workflows/playwright.yml`
2. Re-ajouter `continue-on-error: true` + `if: contains(github.event.pull_request.labels.*.name, 'e2e-required')`
3. Verify: ouvrir PR test sans label → CI passe sans E2E
TIME TO ROLLBACK: 5 minutes
DATA LOSS RISK: No — workflow CI seulement. MAIS: rollback réintroduit le risque PRs mergent sans E2E (état pré-fix).
```

---

## §6 — Quick-decision flowchart (text)

> Owner ou Claude utilise ce flowchart pour classifier rapidement une NEW action en cours de cycle.

```
START — Une action est proposée par Claude ou par owner
│
├─► [Q1] Est-ce une action AWS console / Sentry signup / Stripe dashboard / legal contract / signature certificat ?
│   └─► OUI → OG-OWNER-EXECUTE (Claude rédige instructions, owner clique)
│
├─► [Q2] Touche un fichier listé dans CLAUDE.md §7 frozen zones ?
│   Liste: KioskWizardComponent.vue, KioskAppComponent.vue, KioskUpsellComponent.vue,
│   public/js/pos-wizard.js, public/css/pos-wizard.css, admin-pos-v4.blade.php,
│   FiscalSequenceService.php, ZReportService.php, AuditLogService.php,
│   audit_logs/z_reports migrations, BranchScope.php, IdempotencyKeyMiddleware.php,
│   PricingService.php, OrderStateMachine.php,
│   + memory list: OrderService.php, PaymentService.php, FrontendOrderService.php,
│   PaymentComponent.vue, ItemComponent.vue
│   └─► OUI → OG-LOCK-DOC (skill `/lock-plan` génère LOCK_*.md, owner signe)
│
├─► [Q3] Modifie chaîne NF525 (HMAC, fiscal sequence, audit log, Z-report) ?
│   └─► OUI → OG-LOCK-DOC + audit fiscal tiers possible (selon §10 CLAUDE.md)
│
├─► [Q4] Décision architecturale / business / scope / pricing / fournisseur ?
│   Examples: choix TPE driver, archiver AGENTS.md, migration Pinia scope,
│   curation allergènes recette, choix Laravel track upgrade
│   └─► OUI → OG-OWNER-DECIDE (Claude propose 2-3 options + plan, owner choisit)
│
├─► [Q5] Modifie sécurité (auth, RCE, IDOR, secrets) ?
│   └─► OUI → OG-RED-TEAM-FIRST minimum (sub-agent Security adversarial avant ship)
│
├─► [Q6] Effort > 100 LOC OU > 3 fichiers OU multi-surface (POS+Kiosk+KDS) ?
│   └─► OUI → OG-RED-TEAM-FIRST (sub-agents Architect+Security+DBA+Tester parallèles)
│
├─► [Q7] Frontend touch (Vue, blade, mobile, KDS, kiosk, POS) ?
│   └─► OUI → ajouter Visual Gate obligatoire (CLAUDE.md §6) + capture Playwright + Read screenshot
│
├─► [Q8] Production push / release branch / public PR ?
│   └─► OUI → OG-OWNER-EXECUTE (owner gate explicit avant push)
│
└─► AUCUN OUI → OG-AUTO (Claude exécute, teste, livre PR)
    └─► Toujours: PHPUnit filter + frozen-zones diff + screenshots si UI + BRAIN.md update
```

---

## §7 — Checklist owner sign-off avant ouverture Le Cayenne

> Ouvrir Le Cayenne nécessite **15/15 P0 verts** + **80%+ P1 verts** = condition stricte.
> Si moins, ouverture en mode "GO-CONDITIONAL avec retainer dev-senior 90j" (§7 verdict CTO).

- [ ] P0-1 AWS keys rotées (OWNER-EXECUTE)
- [ ] P0-2 RCE patch + tokens scopés (RED-TEAM)
- [ ] P0-3 IDOR audit + assertions (LOCK)
- [ ] P0-4 Backup S3 + DR drill (OWNER-EXECUTE)
- [ ] P0-5 Alerting câblé (OWNER-EXECUTE)
- [ ] P0-6 Stripe bcmath cast (LOCK)
- [ ] P0-7 Order collapse (LOCK)
- [ ] P0-8 Mobile allergens curation (OWNER-DECIDE)
- [ ] P0-9 Mobile promo (OWNER-DECIDE)
- [ ] P0-10 Runbooks signés (AUTO + sign-off)
- [ ] P0-11 Cash trail test (RED-TEAM)
- [ ] P0-12 OrderStateMachine seul writer (LOCK)
- [ ] P0-13 PHPSpreadsheet upgrade (RED-TEAM)
- [ ] P0-14 Laravel upgrade (OWNER-DECIDE)
- [ ] P0-15 E2E bloquant (AUTO)

**OWNER GO/NO-GO Le Cayenne** : __________ DATE: ____

---

— Fin OWNER_GATES_REGISTRY 2026-05-16
