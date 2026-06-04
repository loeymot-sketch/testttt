# FOODKING — AUDIT CTO GLOBAL — VERDICT FINAL
**Date** : 2026-05-16
**Méthode** : 8 sub-agents parallèles G-Stack + Superpowers + RED-team adversarial
**Périmètre** : POS / Kiosk / KDS / OSS / Admin / Mobile / Backend / DB / Infra / Tests / SaaS / Claude-dependency
**Sources** : 8 rapports détaillés sous `reports/audit/cto-global-2026-05-16/agent-{1..8}-*.md`

---

## §0 VERDICT EXÉCUTIF

> **Note globale : 32/100**
> - **V1 — ouverture Le Cayenne (1 restaurant)** : **NO-GO en l'état**. GO-CONDITIONAL sous 4-6 semaines de hardening discipliné (sécurité, backups, runbooks, alerting, P0 architecture).
> - **V2 — commercialisation SaaS multi-restaurants** : **NO-GO ferme**. 6-12 mois de travail structurel minimum.

**Le système n'est pas cassé. Il donne l'impression de fonctionner pour de bonnes raisons** : la moitié moderne (OrderStateMachine, Outbox triad, PricingService DI, HMAC fiscal chain, sync Pusher+polling, listener ordering) est réellement bien conçue. **Mais cette moitié moderne n'est pas la moitié load-bearing**. Le système qui tourne en production est encore l'ancienne base CRUD : controllers fat avec `DB::transaction` inline, OrderService 2432 LOC, deux modèles Eloquent sur la même table `orders`, frontend de 380 composants Vue Options API sans couche API client, gates frozen-zones théâtraux laissant accumuler +5000 lignes de diff sur du code soi-disant gelé.

**Ce qui te brûlerait dès demain** (P0 immédiat, par sévérité décroissante) :

1. **Clés AWS vivantes leakées dans le commit `a4a88df06`** (`AKIAYJOT77SIZHDXNYOZ`) — détectées il y a 3 jours par toi-même via BRAIN.md, **non rotées**, historique git permanent. À cloner-grepper en 30 secondes. → **Rotation AWS console TODAY**, non négociable.
2. **Primitive RCE pré-auth-light** via `LanguageService::edit` (`app/Services/LanguageService.php:198-220` + route `routes/api.php:486`) sous `auth:sanctum` seulement (pas `permission:settings`), combinée à des tokens Sanctum émis avec abilities wildcard `['*']` (`LoginController.php:87-91`, `GuestSignupController.php:140`). Un client OTP kiosk peut écrire un fichier PHP arbitraire.
3. **Aucun backup automatisé, aucun restore testé**. `storage/backups/` ne contient que des snapshots manuels nommés à la main avant chaque cycle. Disk fail = perte des 6 ans de chaîne fiscale NF525 = exposition pénale.
4. **Aucune alerting layer câblée** : `MonitorOutboxStaleness` n'écrit que dans `Log::error`, pas de Slack webhook, pas de Sentry, pas de UptimeRobot. Un outage 22h samedi soir sera détecté par un client le dimanche matin.

**Ce que tu ne peux PAS vendre demain comme SaaS multi-restaurants** :

- Les items catalogues (`items`, `item_categories`, `taxes`, `coupons`, `item_attributes`, `item_variations`, etc.) **n'ont pas de `branch_id`**. Deux restaurants ne peuvent pas avoir deux menus différents dans le schéma actuel. Aucun `BranchScope` sur `Item.php`.
- **Aucune table de billing / subscription / plan / tenant**. Aucun command `Onboard*`. `MultiTenantModelTrait` est un stub no-op (lignes 14-18).
- **Aucun site marketing**, aucun signup flow, aucun onboarding wizard.
- **Aucune intégration UberEats/Deliveroo/JustEat** — bloque ~60% du TAM fast-food français.
- **Aucun driver TPE natif** (le `payment_terminals` ne stocke que des métadonnées, pas de pilote).

---

## §1 DIAGNOSTIC GLOBAL — par dimension

### Architecture (48/100)
Deux architectures coexistent dans le même repo : une moderne (OrderStateMachine, PricingService DI, Outbox triad — tous propres, testables, documentés) et une legacy (controllers gros, OrderService god-service 2432 LOC, dual Order/FrontendOrder sur même table, frontend Options API monolithique). Aucun glide-path de migration. Chaque feature Claude peut atterrir des deux côtés. La sync cross-surface est le meilleur layer du repo (Pusher + polling fallback, eventContract.js centralisé, listener ordering documenté).

### Sécurité (28/100 — CRITIQUE)
Le cœur fiscal est solide (HMAC chain, idempotency middleware, webhook sig). Tout autour est 2 ans en arrière. Tokens Sanctum wildcard `['*']`, IDOR via `withoutGlobalScope(BranchScope::class)` dans `PosOrderController`, primitive RCE LanguageService, Stripe charge `(int) $total * 100` qui tronque les centimes (perte fiscale NF525), Laravel 9.52 EOL, PHPSpreadsheet 1.30.0 CVE-2024-45048 reachable via admin Excel import. Clés AWS leakées **non rotées**.

### DB single-tenant (72/100) + SaaS multi-tenant (8/100 — STRUCTUREL)
La DB single-tenant est production-grade : NF525 invariants, HMAC chains, triggers d'immutabilité, FK discipline, idempotency uniqueness. **Multi-tenant n'existe pas** : items n'ont pas branch_id, aucune billing, aucun onboarding, bug `branch.status=1 vs 5` documenté et non corrigé, aucun super_admin/chain_owner role, aucun backup DB planifié.

### Production-readiness (38/100) + Ops simplicity (22/100 — CRITIQUE)
Primitives bien pensées : `/health/ready` outbox-staleness probe, `PreflightProductionCommand` 15-point gate, fiscal log channel 400j, scheduler `onOneServer()`. **Mais** : 10 runbooks tous tagués `DRAFT_SKELETON_NOT_SIGNED`, aucun backup auto, aucun alerting câblé, aucun script de deploy/rollback dans `bin/`, kiosk pos-wizard.js sans mode offline. Pour un owner non-dev-senior, la résolution d'incident "kiosk 500 vendredi 19h30" échoue dès l'étape "détection".

### Tests & Gates (46/100 test + 27/100 gate)
Volume impressionnant (443 PHPUnit + 220 Vitest + 127 Playwright + 47 sentinels + 4575 captures), pièces d'architecture réelle (HMAC attacker tests, real-Pusher outbox harness). **Mais** : E2E n'est PAS bloquant par défaut (`continue-on-error: true` + label `e2e-required` opt-in), stress suite théâtrale (sqlite-memory s'auto-documente comme `lockForUpdate no-op`), frozen-zones hook (`safety-check.sh:9-12`) liste 2 fichiers vs CLAUDE.md §7 qui en liste 13+, **23 `assertTrue(true)` sur des paths fiscal/payment/state-machine**, sentinels sont du regex source-string et non du behavioural.

### Frontend UX + A11y + i18n (62 + 48 + 66 /100)
Kiosk Vue est le meilleur (DS mature, A11y composable, RTL hooks). POS Vanilla JS frozen est le pire (31/100) : 0 ARIA, 34 click handlers sur `<div>`, touch targets 32px (WCAG 2.5.5 fail), 100% FR hardcodé, palette rouge legacy. **Mobile fabricated allergens** (`mobile/data/menu.js:274` défault `['gluten','lactose']` sur 60/60 items incl. eau minérale) = exposition légale EU FIC 1169/2011. **Mobile promo code applique pas le discount** (`mobile/screens-main.jsx:595`) → UX trompeuse.

### Commercial readiness (18/100) + Différenciation (52/100)
Produit existe, packaging zéro. Pas de site marketing, pas de signup, pas de pricing tier, pas de support tier, pas de SLA, pas de DPA GDPR. Différenciation réelle vs Innovorder/Tiller : NF525 natif (moat légal FR), kiosk+KDS+loyalty intégrés (€100-160/mois ailleurs), composer wizard (Toast n'a pas l'équivalent pour kebab/tacos). Bloqueurs ventes : aggregateurs livraison (60% du TAM), self-service onboarding, driver TPE natif.

### Claude-dependency RISK (72/100 HIGH) + Process maturity (41/100)
**Contradiction doctrinale au cœur du stack** : `CLAUDE.md:10-13, 70-72` mandate Claude comme executor. `AGENTS.md:113-120, 153` interdit les edits product Claude. Les deux se chargent à chaque session. La réalité observée (753 commits, ~44 auto-rollups "up", aucun trail `EXECUTE_DELEGATION:`) suit CLAUDE.md et ignore AGENTS.md. Frozen-zones théâtrales : +6782 lignes de diff sur fichiers gelés incl. ZReportService +714, AuditLogService +312, PricingService +740. **Une bonne nouvelle** : aucune dépendance runtime à Claude (zéro `anthropic`/`openai` SDK dans `app/`, `config/`, `composer.json`) — le POS déployé survit si Anthropic disparaît.

---

## §2 TABLEAU COMPARATIF — FoodKing actuel vs Architecture idéale

| Domaine | Idéal | FoodKing actuel | Verdict |
|---|---|---|---|
| **Frontend POS** | Composition API + Pinia + API client layer | PosComponent.vue 3769 LOC Options API + axios direct + 113 Vuex modules | ❌ legacy |
| **Frontend Kiosk** | Vue 3 Composition + state isolé | KioskWizardComponent.vue 3094 LOC Options API mais bien structuré, frozen | ⚠️ acceptable mais figé |
| **Écran KDS** | Vue 3 + sync temps réel + bump system | KitchenDisplaySystemComponent.vue 2545 LOC + Echo + polling fallback + V2 flip récent | ⚠️ fonctionnel, raw labels |
| **Backend central** | Laravel + services thin + domain layer | Laravel 9.52 (EOL) + OrderService 2432 LOC + 14 controllers `DB` direct | ❌ controllers fat |
| **Base de données** | 1 model = 1 table + FK + indexes + scopes consistents | dual Order/FrontendOrder sur `orders`, BranchScope 13 modèles, 39 `withoutGlobalScope` overrides | ⚠️ correct hors duality |
| **API** | RESTful + versioning + OpenAPI schema | `routes/api.php` 1314 lignes flat, no versioning, no OpenAPI | ❌ |
| **Authentification** | Sanctum + abilities scopées + TTL court | Sanctum + abilities wildcard `['*']` + TTL 480min | ❌ |
| **Gestion rôles** | Spatie + FormRequest authz cohérent | Spatie présent + 88 endpoints sans FormRequest authz (scheduled V1.0.1) | ❌ |
| **Logs** | Structured + per-channel + searchable + PII-safe | `logging.php` configuré + canal `fiscal` 400j + zéro central log aggregator | ⚠️ |
| **Monitoring** | SLI/SLO + métrics + alerting + dashboards | `MonitorOutboxStaleness` cmd existe + Log::error only + aucun Slack/Sentry/UptimeRobot | ❌ |
| **Sauvegardes** | Auto daily + off-host + GPG + restore drill | Manuel par cycle + même host + non chiffré + jamais testé | ❌ critique |
| **Mode offline** | POS write-ahead log + sync à reconnect | pos-wizard.js zéro offline, kiosk zéro offline | ❌ |
| **Tickets cuisine** | KDS + bump + delay tracking + allergens | KDS V2 + delivery enrichment Sprint 2A+3C + allergen pill | ✅ |
| **Paiements** | Stripe + webhook idempotency + TPE driver | Stripe + idempotency middleware + SenangPay webhook + payment_terminals table (no driver) | ⚠️ TPE manquant |
| **Multi-restaurant** | branch_id sur 100% modèles + super_admin séparé | branch_id sur 13/N modèles, items.branch_id manquant, branch_id=0 = super | ❌ structurel |
| **Multi-tenant** | tenants table + plans + billing + provisioning | aucun | ❌ |
| **Sécurité** | OWASP top 10 covered + secret scanning + CVE auto | Secrets leakés, CVE phpspreadsheet RCE, Laravel EOL, RCE primitive | ❌ critique |
| **CI/CD** | Lint + tests + security + deploy + rollback | 5 workflows GH Actions + E2E opt-in + zéro security scan + zéro deploy script | ❌ |
| **Tests automatisés** | Coverage 70%+ + bloquant CI + E2E + visual | Volume haut + E2E non-bloquant + 23 `assertTrue(true)` + stress théâtral | ⚠️ |
| **Documentation technique** | Architecture, ADRs, runbooks, onboarding | docs/ existe + 10 runbooks DRAFT non signés + AGENTS.md/CLAUDE.md contradictoires | ⚠️ |

---

## §3 NOTATION /100 — 11 AXES (exigeance maximale, expliqué)

| # | Axe | Note | Raison |
|---|---|---|---|
| 1 | **Architecture générale** | **48** | Bonnes pièces modernes (OrderStateMachine, PricingService, Outbox) mais OrderService 2432 LOC, dual Order/FrontendOrder, frontend Options API 380 composants sans couche API. Deux architectures coexistent sans glide-path. |
| 2 | **Robustesse** | **40** | Outbox + sync fallback solides, fiscal HMAC bétonné. Mais 0 backup auto, 0 alerting, runbooks DRAFT, 14 controllers avec `DB::transaction` inline. Survit en happy-path, casse au premier incident réel. |
| 3 | **Sécurité** | **28** | RCE primitive + tokens wildcard + IDOR cross-branch + clés AWS leakées **non rotées** + Laravel EOL + CVE PHPSpreadsheet RCE. Seul le fiscal HMAC sauve la note de 15. |
| 4 | **Maintenabilité** | **45** | Domaine moderne lisible, MAIS PosComponent.vue 3769 LOC, KioskWizardComponent.vue 3094 LOC, OrderService.php 2432 LOC, doublons FrontendOrder/Order, directories `Order/` + `Orders/`, 34 configs dont versionnés `caisse_v1_rollout.php`. |
| 5 | **Scalabilité multi-restaurant** | **8** | Items.branch_id absent → impossible 2 menus différents. Aucune billing/plan/onboarding. Bug branch.status=1 vs 5 connu non corrigé. Single-tenant déguisé en multi-tenant. |
| 6 | **Préparation production** | **38** | Primitives présentes (preflight, health, fiscal channel) mais 0 backup auto, 0 alerting, runbooks non signés, 0 script deploy/rollback, kiosk zéro offline, secrets en clair en .env. |
| 7 | **Qualité produit (UX/A11y/i18n)** | **52** | Kiosk Vue mature (74), KDS correct (58), Admin sample (55), Mobile cluster-7 P0 ouverts (52), POS Vanilla a11y catastrophique (31). Allergènes fabriqués sur 60/60 items mobile = exposition légale. |
| 8 | **Capacité vente autres restos** | **22** | 8/100 multi-tenant + 18/100 commercial readiness + 52/100 différenciation. Aucun site marketing, aucun onboarding, aucune intégration UberEats/Deliveroo (60% TAM FR fast-food). |
| 9 | **Niveau d'automatisation** | **27** | CI existe mais E2E opt-in `continue-on-error: true`, frozen-zones gate théâtral (2 fichiers vs 13 doctrine), 0 secret scanning, 0 commit hygiene gate, 0 deploy auto. |
| 10 | **Dépendance Claude (favorability)** | **28** | RISK élevé (72/100): contradiction CLAUDE.md vs AGENTS.md, +6782 lignes frozen-zone drift, auto-commits "up" qui détruisent le trail, .env leak un cycle Claude récent. Seule bonne nouvelle: **0 runtime Claude** — si Anthropic disparaît, la prod tourne. |
| 11 | **Simplicité exploitation restaurateur** | **22** | Walkthrough "kiosk 500 vendredi 19h30" échoue dès la détection. Runbooks `DRAFT_SKELETON_NOT_SIGNED`. Fiscal-sequence break runbook explicitement refuse la recovery et exige L4 NF525 contact que tu n'as pas. |

**Global pondéré (V1)** : critique × 40% (sécu 28, prod 38, ops 22, multi-tenant 8) + qualité × 30% (arch 48, tests 46, UX 52) + risk × 30% (Claude 28, automation 27, commercial 18) = **32/100**.

**Global V1 single-restaurant only** : **45/100** sous condition rotation secrets + backup + runbooks signés + 4 P0 architecture résolus.

---

## §4 CE QUI EST DÉJÀ AVANCÉ — Forces réelles

**Tu as construit certaines choses que peu de SaaS restaurant français ont** :

1. **NF525 natif HMAC chain** (`app/Services/Fiscal/*` + migrations 2026_05_10) — **moat légal**. Toast, Square, Lightspeed ne peuvent pas se déployer en France <6 mois sans cette conformité. Tiller/Innovorder l'ont mais c'est leur core asset. Tu l'as construit toi-même → différenciation défendable.

2. **POS + Kiosk + KDS + OSS + Admin + Mobile intégrés** sous une seule plateforme. Toast vend KDS €40-60/mois en plus du POS, Square vend Kiosk €60-80/mois, Loyverse fait payer le KDS. Tu bundles tout = avantage prix structurel.

3. **Outbox pattern + Pusher + polling fallback** (`PersistOrderCreatedToOutbox.php`, `OssSyncService.js`, `eventContract.js`) — cross-surface sync graceful-degradation réelle. C'est la pièce la mieux écrite du repo.

4. **OrderStateMachine** (`app/Domain/Order/OrderStateMachine.php:179-254`) — `DB::transaction` + `lockForUpdate` + idempotent early-return + reason-required terminal. Si tu l'imposes comme seul writer, tu as l'invariant.

5. **Composer wizard pour items complexes** (sandwich/taco/bol/frites — multi-step variation+sauce+supplément+drink). Toast et Square ne supportent pas ce pattern hors override custom payant. Pour le marché kebab/tacos français c'est un avantage produit réel.

6. **Loyalty natif avec NFC + QR scan** (LoyaltyController 730 LOC — code à refactorer mais feature complète) — Toast vend ça €30-50/mois en add-on.

7. **Vélocité de développement** orchestrée Claude — 263h en 49 sessions, 89 commits, audits multi-agents convergence GREEN. Aucune startup early-stage ne peut matcher cette vélocité sans Claude.

8. **Vision produit complète** documentée (CLAUDE.md, PROJECT_BRAIN.md, BUSINESS_RULES.md, docs/AUTHZ_MATRIX.md, plans/MASTER_*). Beaucoup d'écrit mais le north star est clair.

---

## §5 RISQUES PRINCIPAUX — P0/P1/P2 consolidés

### 🔴 P0 — Bloque la mise en production (à résoudre AVANT ouverture Le Cayenne)

| # | Risque | Source | Action |
|---|---|---|---|
| 1 | Clés AWS `AKIAYJOT77SIZHDXNYOZ` leakées commit `a4a88df06` non rotées | Agent 2, Agent 8, BRAIN.md:53 | Rotation AWS console TODAY + gitleaks pre-commit |
| 2 | RCE primitive via LanguageService + tokens Sanctum wildcard `['*']` | Agent 2 | Patch immédiat: `permission:settings` sur route, abilities role-scoped, force re-login |
| 3 | IDOR cross-branch dans PosOrderController via `withoutGlobalScope` | Agent 2 | Revue 39 occurrences `withoutGlobalScope(BranchScope::class)` + ajout assertion `auth()->user()->branch_id === $order->branch_id` |
| 4 | Aucun backup automatisé, aucun restore testé | Agent 4 | spatie/laravel-backup daily + off-host GPG + S3 object-lock + DR drill restore |
| 5 | Aucun alerting câblé (outage détecté par client) | Agent 4 | Slack webhook + Sentry + BetterUptime ping `/health/live` |
| 6 | Stripe charge `(int) $total * 100` tronque centimes (NF525 mismatch) | Agent 2 | Cast bcmath round-half-up, regression test sur €X.99 |
| 7 | Dual Order / FrontendOrder sur même table `orders` | Agent 1 | Collapse vers Order unique, fillable consolidé, observer single attach |
| 8 | Allergènes fabriqués mobile (60/60 items default `['gluten','lactose']`) | Agent 6 | Fix 1 ligne `mobile/data/menu.js:274` + curation recettes |
| 9 | Mobile promo code affiche "✓ Code appliqué" sans discount | Agent 6 | Stub désactivé ou implémentation backend |
| 10 | 10 runbooks tous `DRAFT_SKELETON_NOT_SIGNED` | Agent 4 | Signer 4 critiques (FISCAL_SEQUENCE_BREAK, KIOSK_NETWORK_LOSS, OUTBOX_BLOCKED, ROLLBACK_CANARY) avec commandes copy-paste |
| 11 | POS direct-cash → CashMovement wiring untested | Agent 5 + Wave Z 2026-05-09 | Feature test cash trail bout-en-bout |
| 12 | OrderService.php 2432 LOC + 5 status mutations bypassent OrderStateMachine | Agent 1 | Forcer `apply()` seul writer + split en QueryService/CommandService |
| 13 | PHPSpreadsheet 1.30.0 CVE-2024-45048 reachable via admin Excel import | Agent 2 | Upgrade composer ≥ 2.0.0 + revue surface attaque admin |
| 14 | Laravel 9.52 EOL (sécurité plus patchée) | Agent 2 | Migration L10 → L11 (track séparé V1.x) |
| 15 | E2E non-bloquant CI (`continue-on-error: true` + label opt-in) | Agent 5 | Rendre E2E requis, smoke pack 5 specs minimum bloquant |

### 🟠 P1 — Doit être corrigé avant V1.x (4-12 semaines)

| # | Risque | Source |
|---|---|---|
| 16 | 14 controllers importent `DB` facade direct, logique métier inline | Agent 1 |
| 17 | OrderStateMachine::apply() utilisé seulement 2× (pattern half-adopted) | Agent 1 |
| 18 | Frontend zéro architectural layering (0 API client, 113 Vuex flat, 10 Composition vs 308 Options) | Agent 1 |
| 19 | 39 occurrences `withoutGlobalScope(BranchScope::class)` — chacune une fuite multi-tenant potentielle | Agent 1, Agent 3 |
| 20 | KDS UX 3.2/10 (audit 2026-05-11 — 8 P0 cross-validated) — raw labels FR, contrast 3.2:1, bump 32px | Agent 6 + memory project_kds_audit_2026-05-11 |
| 21 | POS Vanilla wizard 0 ARIA, 32px touch targets, 100% FR hardcoded — frozen zone | Agent 6 |
| 22 | Bug `branch.status=1 vs 5` documenté non corrigé (`PersistCatalogChangedToOutbox.php:38-41`) | Agent 3 + BRAIN.md |
| 23 | 23 `assertTrue(true)` dans tests fiscal/payment/state-machine | Agent 5 |
| 24 | Frozen-zones hook (`safety-check.sh:9-12`) liste 2 fichiers vs 13+ doctrine | Agent 5, Agent 8 |
| 25 | Aucun script deploy/rollback (`bin/` vide pour ops) | Agent 4 |
| 26 | Contradiction CLAUDE.md vs AGENTS.md (load tous deux à chaque session) | Agent 8 |
| 27 | Aucun driver TPE natif (payment_terminals table seule, BypassMode actif) | Agent 7 |
| 28 | 88 endpoints sans FormRequest authz (scheduled V1.0.1) | BRAIN.md |
| 29 | Stress test théâtral (sqlite-memory `lockForUpdate no-op`) | Agent 5 |
| 30 | Vuex modules 113 flat — pas de migration Pinia entamée | Agent 1 |

### 🟡 P2 — Dette à éponger (V1.x → V2)

- 380 composants Options API à migrer Composition API
- Directories `app/Services/Order/` + `Orders/` (singular vs plural)
- 34 config files (incl. versionnés `caisse_v1_rollout.php`, `catalog_v15.php`) à consolider en feature flag registry
- ~44 auto-commits "up" qui obscurcissent l'historique
- 17 advisories security composer triage backlog (incl. PHPSpreadsheet CRITICAL)
- AR i18n -43 keys gap
- `tests/sentinels/` à transformer du regex en behavioural test
- `.claude/settings.local.json` 159 entrées permissions à élaguer
- 4575 captures e2e accumulées dans `tests/e2e/__screenshots__/` à purger

---

## §6 PRIORITÉS ABSOLUES — Top 10 actions sous 30 jours

**Si tu ne dois faire que 10 choses, fais celles-ci dans cet ordre** :

1. **🔥 ROTATE AWS keys + APP_KEY + FISCAL secrets** (commit `a4a88df06`) — AWS console + `php artisan key:generate` + new HMAC seeds. **TODAY**. 2h.

2. **🔥 Patch RCE LanguageService + tokens wildcard** — route sous `permission:settings` + abilities role-scoped (`['pos:order','admin:catalog',...]`) + force re-login + CI lint `createToken(..., ['*'], ...)`. 1 jour.

3. **🔥 Installer gitleaks + commitlint + composer audit en CI** — bloquer .env leaks, commits "up", CVEs. 30 minutes config + 2h pour fix les hits actuels.

4. **💾 spatie/laravel-backup quotidien off-host GPG S3 object-lock** + DR drill restore + bin/restore.sh documenté. 12h.

5. **📊 Alerting câblé** : Slack webhook + Sentry + BetterUptime `/health/live`. 2h.

6. **📋 Signer 4 runbooks critiques** (FISCAL_SEQUENCE_BREAK, KIOSK_NETWORK_LOSS, OUTBOX_BLOCKED, ROLLBACK_CANARY) avec commandes `php artisan` copy-paste. Imprimer cheatsheet plastifiée pour le proprio Le Cayenne. 8h.

7. **🚢 Écrire `bin/deploy.sh` + `bin/rollback.sh`** atomic symlink flip + `composer install --no-dev` + `npm run prod` + `php artisan migrate --force optimize` + rollback testé. supervisor/systemd pour `queue:work` et `schedule:run`. 6h.

8. **🔒 Collapse `Order` ↔ `FrontendOrder`** vers `Order` unique + fillable consolidé + observer single attach. 4 semaines mais MANDATORY avant fiscal go-live. Lock plan obligatoire (frozen-zone NF525).

9. **🚨 Fix mobile cluster-7 P0** (allergènes fabriqués + promo code stub). 1 jour + 1-2j curation allergènes recette-validée.

10. **✅ Rendre E2E bloquant CI** (drop `e2e-required` label + `continue-on-error`) + porter `php artisan foodking:e2e:stress` en CI matrix MySQL. 1 jour.

**Budget total estimé** : ~80h focused work + ~3-5 jours décalés (curation recettes + DR drill). Réalisable en 4 semaines si owner consacre 60% du temps.

---

## §7 PLAN D'AMÉLIORATION EN 5 PHASES

### PHASE 1 — Stabiliser pour 1 restaurant (Le Cayenne) — **4-6 semaines**

**Objectif** : passer de "NO-GO" à "GO-CONDITIONAL Le Cayenne uniquement".

- Semaine 1 : Priorités 1-7 ci-dessus (sécurité + ops). Critère sortie : pas un seul P0 sécu/ops ouvert.
- Semaine 2-3 : Priorités 8-10 (Order collapse + mobile P0 + E2E bloquant). Critère sortie : OrderStateMachine.apply() = seul writer.
- Semaine 4 : LOCK plan POS wizard surgical patch (ARIA + 44px + var(--pos-v5-brand-red) + i18n keys `pos.wizard.*`). 200 lignes diff owner-gate.
- Semaine 5-6 : Hardening rounds — fix les P1 sécurité (Stripe cast, PHPSpreadsheet upgrade, IDOR scopes), FormRequest authz pour les 20 endpoints les plus exposés.
- **Gate sortie phase 1** : ouverture Le Cayenne possible avec retainer dev-senior 90j en backup.

### PHASE 2 — Sécuriser + monitorer + production-grade (3-4 semaines)

**Objectif** : passer de "GO-CONDITIONAL avec retainer" à "GO sans retainer pour 1 restaurant".

- Migration Laravel 9 → 10 → 11 (sécurité patches + Sanctum 4.x).
- Upgrade Spatie permissions 5 → 6.
- FormRequest authz refactor 88 endpoints (V1.0.1 scheduled).
- Sentry + Slack alerting → PagerDuty / Pagerly avec on-call rotation (toi → toi + 1 freelance backup).
- Frozen-zones gate réel : `scripts/check-frozen-zones.sh` en CI + LOCK doc requis pour toucher.
- Test maturity : E2E bloquant + sentinels behavioral + stress test MySQL CI.
- KDS UX heal (8 P0 audit 2026-05-11).
- POS Composition API + Pinia migration POS-V5 (commencer petit).

### PHASE 3 — Préparer multi-restaurant (8-12 semaines)

**Objectif** : pouvoir ouvrir un 2ème restaurant test sans rewrite massif.

- Migration `items` + `item_categories` + `taxes` + `coupons` + `item_attributes` + `item_variations` + `item_extras` avec `branch_id` nullable (héritage chain), backfill, `BranchScope` ajouté.
- Onboarding command : `php artisan foodking:onboard --restaurant="X" --plan=starter --siret=...` qui crée Branch + User owner + seed menu vide + génère DB backup baseline.
- Super-admin separation Spatie : `super_admin` (cross-tenant, 2FA mandatory) vs `chain_owner` (multi-branch chaîne) vs `branch_manager` (1 branch).
- Per-tenant feature flags : `App\Services\FeatureFlag` au lieu de 34 configs.
- Per-tenant branding/locale/currency.
- Bug `branch.status=1 vs 5` fixé proprement.
- 2nd restaurant pilote en interne (ami restaurateur).

### PHASE 4 — Transformer en SaaS commercialisable (8-12 semaines)

**Objectif** : pouvoir vendre à customer #2 externe.

- Site marketing 4 pages (landing + features + pricing + signup).
- Signup self-service + Stripe Billing intégration (subscription + plan tier + trial).
- Onboarding wizard (signup → menu import wizard → kiosk pairing → KDS setup → première commande de test).
- DPA GDPR + Privacy + DPIA template.
- Documentation client (Notion ou GitBook public) : guide POS, guide Kiosk, guide KDS, guide Admin, FAQ, vidéos.
- Pricing tiers : Starter €39 / Pro €69 / Multi €129+ (cf. Agent 7 benchmark).
- Support tier : Intercom + helpdesk + SLA contractuel.
- Audit fiscal NF525 tiers (€3-5k stamp) + page compliance.

### PHASE 5 — Industrialiser (continu, 12+ mois)

**Objectif** : scale opéré.

- Intégration UberEats (premier — 60% TAM FR fast-food).
- Intégration Deliveroo + JustEat.
- Driver TPE natif (Ingenico Tetra premier — marché FR dominant).
- Inventory forecasting + supplier management.
- Employee scheduling + payroll integration.
- Marketing analytics + CRM customer (loyalty déjà là, manque cohorts).
- Multi-currency (international expansion path).
- Per-tenant data residency (FR mandatory pour fiscal, autres pays selon).
- Compliance multi-pays (passer NF525 FR à equivalent ES/IT/DE etc.).

**Budget total Phase 1→5** : ~12-18 mois full-time si owner + 1 senior backend + 1 senior frontend + 1 product designer + 1 ops/SRE. Réaliste pour bootstrap solo : 24-36 mois en parallèle de l'exploitation Le Cayenne.

---

## §8 CHECKLIST PRODUCTION — Avant ouverture Le Cayenne

**40 items concrets à cocher**. Aucune ouverture si <90% verts.

### Commandes (10)
- [ ] Création commande POS ↔ création commande Kiosk produisent même structure DB
- [ ] OrderStateMachine.apply() est le SEUL writer de `orders.status` (audit grep)
- [ ] Transitions interdites (PAID→PENDING, CANCELLED→PAID) testées + bloquées
- [ ] Commande mid-flight survit reboot serveur (test reboot pendant rush simulé)
- [ ] Commandes parked POS reprise après crash navigateur testée
- [ ] Sync commande POS → KDS < 2s p95 mesurée
- [ ] Sync commande Kiosk → KDS < 2s p95 mesurée
- [ ] Polling fallback 5s actif si Pusher DOWN testé manuellement (déconnexion réseau)
- [ ] Idempotency middleware: même `X-Idempotency-Key` ne crée pas doublons (test E2E)
- [ ] composition_snapshot frozen à création, jamais overwritten (test régression)

### Cuisine (5)
- [ ] KDS lisible à 3m de distance (test physique en salle)
- [ ] Bump time + allergènes visibles sur tous tickets
- [ ] Sync KDS bump → OSS état client < 1s
- [ ] Reprint receipt fonctionne pour items archivés (composition_snapshot.name backfilled)
- [ ] Mode dégradé KDS hors-ligne : commande imprimée papier en backup

### Paiements (6)
- [ ] Stripe charge: `bcmath round-half-up`, pas de troncation centimes (test €9.99)
- [ ] Webhook Stripe idempotent (replay test)
- [ ] Webhook SenangPay idempotent (replay test)
- [ ] CASH session ouvert → ferméZ-rapport produit
- [ ] Variance CASH detect (Sprint 1B) bloque close session si écart > seuil
- [ ] TPE BypassMode désactivé OU driver natif intégré (DECISION owner)

### Imprimantes + Reçus (4)
- [ ] Imprimante reçu testée physiquement (papier déroulé + coupe + branding)
- [ ] Print queue retry si imprimante offline 30s
- [ ] Reçu inclut fiscal_sequence_no + branch SIRET + montants TVA (NF525 mandatory)
- [ ] Z-report quotidien imprimé + signé HMAC + archivé 6 ans

### Droits utilisateurs (4)
- [ ] Cashier ne peut PAS supprimer commande (test E2E)
- [ ] Cashier ne peut PAS lire commandes autre branch (IDOR test)
- [ ] Admin doit s'authentifier 2FA pour actions sensibles (Z-report, refund, archive)
- [ ] Token Sanctum kiosk ne peut PAS appeler /admin/* (ability check)

### Sauvegardes (4)
- [ ] Backup DB quotidien automatique 03:00 (cron testé)
- [ ] Backup off-host (S3 ou Wasabi) avec GPG encryption
- [ ] Restore testé en staging (drop orders → restore → continuer)
- [ ] Retention 6 ans NF525 + object-lock (immutability)

### Logs + Monitoring (4)
- [ ] Sentry Frontend + Backend wired, alertes configurées
- [ ] Slack webhook reçoit `Log::error` (test trigger manuel)
- [ ] BetterUptime ping `/health/live` + alerte propriétaire si fail 2 min
- [ ] PII scrubbed dans logs (email, phone, card last4 OK, full no)

### Tests + Sécurité (3)
- [ ] AWS keys rotées + gitleaks pre-commit + composer audit en CI
- [ ] PHPSpreadsheet ≥ 2.0.0 (CVE patched)
- [ ] E2E smoke pack bloquant en CI (5 specs minimum: kiosk happy / POS cash / KDS bump / OSS update / fiscal Z-close)

---

## §9 ARCHITECTURE CIBLE

```
┌────────────────────────────────────────────────────────────────┐
│  EDGE                                                          │
│  ├── Cloudflare (CDN + WAF + DDoS + bot protection)            │
│  └── BetterUptime (status page public + ping /health/live)     │
├────────────────────────────────────────────────────────────────┤
│  FRONTEND SURFACES (Vue 3 Composition API + Pinia)             │
│  ├── POS V5 (composition, < 500 LOC/composant, API client)     │
│  ├── Kiosk Wizard V2 (composition, addon flow, RTL ready)      │
│  ├── KDS V2 (live + polling fallback, allergen pill, bump)     │
│  ├── OSS (Order Status Screen — public lobby)                  │
│  ├── Admin (catalog, stock, orders, reports, fiscal Z)         │
│  └── Mobile App (Expo React Native — séparé)                   │
├────────────────────────────────────────────────────────────────┤
│  API CLIENT LAYER (resources/js/api/*)                         │
│  └── 1 fichier par controller backend, ZERO axios direct       │
├────────────────────────────────────────────────────────────────┤
│  BACKEND (Laravel 11 — migration depuis 9.52)                  │
│  ├── HTTP Controllers (thin — validate → dispatch → resource)  │
│  ├── FormRequest (authz cohérent sur 100% endpoints)           │
│  ├── Application Services (< 600 LOC chacun)                   │
│  │   ├── OrderCommandService (writes)                          │
│  │   ├── OrderQueryService (reads, paginated)                  │
│  │   ├── PricingService (SSOT, DI'd collaborators)             │
│  │   ├── LoyaltyService (extrait de LoyaltyController)         │
│  │   ├── PaymentFinalizerService (extrait du paymentConfirm)   │
│  │   └── FiscalService (HMAC chain, Z-report, audit log)       │
│  ├── Domain Layer (app/Domain/)                                │
│  │   ├── Order/OrderStateMachine (SEUL writer status)          │
│  │   ├── Order/OrderQuote                                      │
│  │   ├── Fiscal/AuditLogChain                                  │
│  │   └── Catalog/* (CompositionSnapshot, Wizard, etc.)         │
│  └── Outbox + Event Bus                                        │
│      ├── DomainEvent (persistent)                              │
│      ├── DispatchDomainEventsJob                               │
│      └── Listeners (Persist*ToOutbox FIRST, then side-effects) │
├────────────────────────────────────────────────────────────────┤
│  REALTIME                                                      │
│  ├── Pusher Channels (live)                                    │
│  └── Polling fallback 5s (déjà en place)                       │
├────────────────────────────────────────────────────────────────┤
│  PERSISTENCE                                                   │
│  ├── MySQL 8 (primary)                                         │
│  │   ├── 1 model = 1 table (collapse Order/FrontendOrder)      │
│  │   ├── BranchScope sur 100% models tenant-aware              │
│  │   ├── Audit triggers (NF525 immutability)                   │
│  │   └── Idempotency UNIQUE (provider, webhook_id)             │
│  ├── Redis (Cache + Sessions + Queue + Pusher presence)        │
│  └── S3 (backups GPG-encrypted + object-lock 6 ans)            │
├────────────────────────────────────────────────────────────────┤
│  QUEUE + JOBS                                                  │
│  ├── Horizon (UI monitoring + auto-scaling workers)            │
│  ├── schedule:run via supervisor                               │
│  └── queue:work via supervisor (failover restart)              │
├────────────────────────────────────────────────────────────────┤
│  OBSERVABILITY                                                 │
│  ├── Sentry (errors backend + frontend)                        │
│  ├── Slack webhook (Log::error → channel #foodking-prod)       │
│  ├── BetterUptime (sondes externes)                            │
│  └── Per-tenant SLI dashboard (uptime / order success rate)    │
├────────────────────────────────────────────────────────────────┤
│  CI/CD                                                         │
│  ├── GH Actions: lint + PHPUnit + Vitest + Playwright (block.) │
│  ├── gitleaks + composer audit + npm audit                     │
│  ├── frozen-zones gate (CI fail si touch sans LOCK_*.md commit)│
│  ├── deploy.sh atomic symlink + rollback.sh tested             │
│  └── Canary deploy (1 branch test → 100%)                      │
├────────────────────────────────────────────────────────────────┤
│  MULTI-TENANT (Phase 3+)                                       │
│  ├── tenants table (id, siret, plan_id, status, gdpr_data)     │
│  ├── plans table (id, name, price_eur, features_json)          │
│  ├── subscriptions (tenant_id, stripe_sub_id, status, dates)   │
│  ├── invoices (Stripe Billing intégration)                     │
│  ├── super_admin role Spatie (2FA mandatory, cross-tenant)     │
│  └── per-tenant feature flag registry (App\Services\Features)  │
└────────────────────────────────────────────────────────────────┘
```

**Migration glide-path** : ne pas tout réécrire. Stop the bleed d'abord (interdire nouveau code dans patterns legacy), puis migrer surface par surface (POS d'abord car déjà branche V5 amorcée).

---

## §10 COMMENT UTILISER CLAUDE CORRECTEMENT — Guide concret

### Ce que Claude PEUT orchestrer en autonomie (faible risque)

- Audits read-only (comme celui-ci) avec sub-agents parallèles + RED-team adversarial
- Refactor scope-minimal sur fichiers NON frozen-zone (≤ 30 LOC, ≤ 3 fichiers, tests immédiats)
- Génération de tests de régression sur paths fiscal/payment/auth
- Rédaction de runbooks à partir de l'existant
- Mise à jour de documentation
- Recherche de patterns dans la codebase
- Génération de migrations DB simples (ajout colonne, index)
- Génération de seeds de test
- Synthèse de plans d'implémentation détaillés pour passage à Codex/Cursor

### Ce que Claude ne DOIT PAS contrôler seul (gate humaine obligatoire)

| Action | Pourquoi gate humaine |
|---|---|
| Push vers `main` ou branche release protégée | Owner gate explicite |
| Modification fichier frozen-zone | LOCK doc requis + diff review owner |
| Migration DB destructive (DROP COLUMN, RENAME) | Backup + DR drill requis |
| Modification chaîne NF525 (FiscalSequenceService, ZReportService, AuditLogService) | Audit fiscal tiers requis |
| Modification PricingService (SSOT prices) | Owner gate + test régression triple-vert |
| Modification BranchScope (multi-tenant isolation) | Owner gate + audit cross-tenant test |
| Création PR publique GitHub | Owner gate |
| Rotation secrets / clés API | Owner action (Claude ne touche pas .env) |
| Decision architecturale majeure (changer de queue, ajouter service externe, migration framework) | Owner gate après plan |
| Suppression données production | Owner gate après backup verify |

### Documenter les décisions (anti-drift)

- **À chaque cycle significatif** : update `PROJECT_BRAIN.md` §2 §3 §4 §7 (état + last + next + verification). Auto-managé par CLAUDE.md §5 LOOP étape 8.
- **À chaque décision architecturale** : ADR (Architecture Decision Record) sous `docs/adr/NNNN-titre.md` — context, decision, consequences, alternatives considered. (`docs/adr/` existe déjà — utilise-le.)
- **À chaque audit** : rapport daté sous `reports/audit/<topic>-<YYYY-MM-DD>/` (comme cet audit). Conserver 6 mois minimum.
- **À chaque sprint** : push Graphiti episode `foodking` group avec résumé + decisions + verifications.

### Faire relire le code

- **Toujours** dispatcher RED-team sub-agent adversarial APRÈS l'implémenteur, AVANT le ship.
- **Toujours** demander Architect + Security + DBA en parallèle pour reviews >100 LOC ou touchant frozen-zone.
- Utiliser `/ultrareview` (slash command natif Claude Code) pour audit profond d'une PR.
- **Hostile framing obligatoire** pour RED : "Tu es payé pour trouver les P0 que les autres ont manqués. Ta réputation en dépend."

### Éviter qu'une modification casse tout

1. **Tests d'abord** : invoquer `superpowers:test-driven-development` skill avant tout code feature/bugfix.
2. **Sentinels** : tester un invariant load-bearing (NF525 chain, BranchScope, OrderStateMachine apply()) AVANT de toucher.
3. **Frozen-zones gate** : `scripts/check-frozen-zones.sh` en CI (à créer — actuellement théâtral). LOCK doc obligatoire.
4. **Visual gate** : Playwright capture + Read screenshot + analyse à chaque change frontend (CLAUDE.md §6 — déjà doctrine).
5. **3-loop limit** : si fix échoue 3× → ESCALATE owner avec root-cause analysis. Pas de loop 4 silencieuse.

### Organiser les prompts (anti-hallucination)

- **Toujours référencer file:line** dans les prompts. "Modifie `OrderService.php:1530` pour utiliser `OrderStateMachine::apply()`" pas "fixe le order service".
- **Toujours fournir SSOT** quand le sujet est business : "Le menu Le Cayenne est dans `config/menu.php` et `mobile/data/menu.js`. N'invente AUCUN item." Apprendre de l'incident "Box Familiale fictifs".
- **Mode opératoire explicite** : "autonomously" / "carte-blanche" = pas de questions, exécute. "audit only" = pas de code, plan + diagnostic.
- **Budget de tokens** : pour les missions longues, dire à Claude "écris le deliverable directement avec Write tool, dans chat réponds < 400 mots". Évite le output-token-limit cap.
- **Convertir les dates relatives** en absolues : "Jeudi" → "2026-03-05" pour que les mémoires restent valides.

### Demander des audits réguliers

- **Cadence recommandée** :
  - Audit sécurité : 1x/mois (gitleaks + composer audit + npm audit + grep secrets + revue `withoutGlobalScope`)
  - Audit fiscal NF525 : 1x/trimestre (HMAC chain integrity test + 6y retention verify + Z-report sample)
  - Audit production-readiness : avant chaque go-live nouveau restaurant
  - Audit performance : avant chaque pic de saison (rentrée, fêtes)
  - Audit dépendances : 1x/semaine (CVE feed)
- **Pattern** : `/ultrareview` pour PR critique, `superpower-gstack` pour cycle complet, `Agent` parallel pour audit read-only ciblé.

### Créer des tests AVANT modifications (TDD)

- Invoquer `superpowers:test-driven-development` skill avant feature/bugfix.
- Pattern : RED (test fail) → GREEN (implementation) → REFACTOR (clean).
- Test doit échouer pour la raison **attendue** avant l'implémentation (pas pour syntaxe ou setup).
- Sentinels load-bearing à écrire en behavioural (drop trigger, tamper data, assert detection) — pas en regex source-string. Voir contre-exemple `tests/Feature/AuditLogHashChainTest.php` (bien fait).

### Fichier d'architecture de référence

Tu en as déjà 3 qui coexistent et se contredisent partiellement :
- `CLAUDE.md` (load-bearing operating memory pour Claude — récent, à jour)
- `AGENTS.md` (legacy, contradictoire avec CLAUDE.md sur le rôle de Claude — **archive-le ou rewrite**)
- `docs/ARCHITECTURE.md` + `docs/ARCHITECTURE_TECHNIQUE.md` (2 fichiers — consolider)

**Action immédiate** : décider qui est SSOT. Recommandation Agent 8 : garder CLAUDE.md, archiver AGENTS.md (rename → `AGENTS_LEGACY_2026-03.md` + entrée dans changelog), réécrire `docs/ARCHITECTURE.md` comme cible (vs `ARCHITECTURE_TECHNIQUE.md` qui décrit l'existant).

---

## §11 RECOMMANDATIONS PRÊTES À DONNER À CLAUDE/GStack/Superpowers

**10 prompts copy-paste pour les prochains cycles**. Chacun est self-contained, scope-minimal, avec gates explicites.

### Prompt #1 — Rotation secrets + gitleaks (priorité 1)

```
Mission: rotation secrets fuites + installation gitleaks pre-commit.

Context: AWS keys AKIAYJOT77SIZHDXNYOZ + APP_KEY + FISCAL_*_SECRET ont été commitées dans `a4a88df06` (.env.backup-pre-round2). L'historique git est permanent. Detection BRAIN.md:53 il y a 3 jours, NON rotées encore.

Étapes:
1. Lister les secrets exposés via `git log -p -- .env*` et `git show a4a88df06`.
2. Écrire CHECKLIST.md des secrets à roter (AWS console, Stripe dashboard, Pusher dashboard, etc.) — owner fait les rotations.
3. Installer pre-commit hook gitleaks (`gitleaks-action` dans `.github/workflows/`).
4. Ajouter `composer audit` + `npm audit` en CI.
5. Test: créer un commit avec un AWS key fake → vérifier que CI fail.

Constraint: owner gate avant rotation effective. Tu ne touches PAS aux secrets toi-même.

Output: PR draft avec hook installation + CHECKLIST.md des rotations à faire par owner. Pas de commit auto "up".
```

### Prompt #2 — Patch RCE LanguageService + abilities scopées

```
Mission: fermer le primitive RCE LanguageService + détonner les tokens Sanctum wildcard.

Context (Agent 2 audit 2026-05-16):
- `app/Services/LanguageService.php:198-220` écrit fichier avec path user-supplied
- Route `routes/api.php:486` sous `auth:sanctum` seulement (pas `permission:settings`)
- `LoginController.php:87-91` + `GuestSignupController.php:140` émettent tokens avec abilities `['*']`
- `tokenCan('kiosk:order')` retourne `true` pour tous tokens wildcard

Étapes:
1. Ajouter `middleware('permission:settings')` sur la route admin/language/*.
2. Whitelist `realpath()` à `lang_path()` dans le service.
3. Rejeter `<?` dans values.
4. Remplacer abilities wildcard par role-scoped: cashier=['pos:order'], kiosk=['kiosk:order'], admin=['admin:catalog','admin:report','admin:fiscal'].
5. Force re-login (revoke all tokens existants).
6. Tests régression: kiosk token ne peut PAS appeler /admin/*; admin token peut.
7. CI lint qui fail sur `createToken(..., ['*'], ...)`.

Constraint: tests d'abord (TDD). Pas de skip frozen-zone (Sanctum config n'est pas frozen).

Output: 1 PR avec patch + tests + CI lint rule.
```

### Prompt #3 — Backup + restore + DR drill

```
Mission: installer spatie/laravel-backup + DR drill restoré.

Context: storage/backups/ ne contient que des snapshots manuels. NF525 6-year retention legal exposure. Disk fail = perte totale.

Étapes:
1. composer require spatie/laravel-backup.
2. Configurer backup quotidien 03:00 schedule.
3. Destination: S3 ou Wasabi avec GPG encryption + object-lock 6 ans.
4. Écrire `bin/restore.sh` pour restore depuis backup.
5. DR drill: en staging, drop orders table → restore → vérifier outbox replay → close Z report. Documenter timing.
6. Runbook `docs/runbooks/RUNBOOK_DR_RESTORE.md` signé (pas DRAFT).
7. Test régression: cron quotidien tire backup → S3 → vérifier le fichier existe + checksum.

Output: PR + runbook + DR drill timing report.
```

### Prompt #4 — Collapse Order/FrontendOrder (frozen-zone, LOCK doc requis)

```
Mission: collapse dual model Order/FrontendOrder vers Order unique.

Context (Agent 1 audit): app/Models/Order.php:19 et app/Models/FrontendOrder.php:19 ciblent tous deux table 'orders' avec fillable divergent (parent_order_id+fiscal_alloc_error_at vs transaction_id+card_type+source_surface), observer attaché à FrontendOrder seulement (AppServiceProvider.php:68), OrderService.php:1102 crée FrontendOrder.

Frozen-zone car NF525 sensible. LOCK doc requis (`/lock-plan` skill).

Étapes:
1. Invoquer skill `/lock-plan` pour générer LOCK_ORDER_COLLAPSE.md.
2. Plan détaillé file:line: fillable consolidé, observer single attach, view scopes pour cas read-only, migration data si différence.
3. RED-team review du plan AVANT exécution.
4. Implémenter en feature branch dédiée.
5. Tests régression: Order création POS vs Kiosk produisent même row structure; fiscal sequence allocation OK; audit log écrit; idempotency intact.
6. Owner gate avant merge.

Constraint: 4 semaines de travail estimé. NE PAS commencer sans LOCK doc owner-signed.

Output: LOCK doc + plan + branch + PR avec tests + owner-gate sign-off.
```

### Prompt #5 — OrderStateMachine = seul writer status

```
Mission: forcer OrderStateMachine::apply() comme seul writer de orders.status.

Context (Agent 1): OrderStateMachine::apply utilisé seulement 2× (Jobs/CleanupStalePendingKioskOrders.php:60 + comment OrderService.php:1511). 5 mutations directes dans OrderService.php aux lignes 1530, 1609, 1714, 1820, 1907.

Étapes:
1. Tests RED: pour chacune des 5 mutations, écrire un test qui assert que l'invariant state-machine est respecté (transitions interdites bloquées, audit row écrit, idempotency).
2. Remplacer chaque mutation directe par OrderStateMachine::apply($order, $next, $actor, $reason).
3. Tests GREEN.
4. Grep final pour `->status =` dans `app/Services/` → doit retourner 0 hits hors OrderStateMachine.php lui-même.
5. CI lint qui fail sur `->status = ` dans `app/Services/` et `app/Http/Controllers/`.

Output: PR avec 5 sites refactorés + tests + CI lint rule.
```

### Prompt #6 — Frozen-zones gate réel

```
Mission: rendre le frozen-zones gate réel (vs théâtral actuel).

Context (Agent 5 + Agent 8): `.cursor/hooks/safety-check.sh:9-12` liste 2 fichiers vs CLAUDE.md §7 qui liste 13+. Hook self-documents "Run manually before every execution phase. Not auto-invoked." Frozen-zones ont accumulé +6782 lignes diff non-LOCKées (incl. ZReportService +714, AuditLogService +312, PricingService +740).

Étapes:
1. Synchroniser la liste frozen-zone entre CLAUDE.md §7, memory/reference_frozen_zones.md, et le script.
2. Réécrire `scripts/check-frozen-zones.sh` avec array complet (13 fichiers).
3. Ajouter GitHub Action `.github/workflows/frozen-zones.yml` qui fail le CI si un fichier frozen est modifié SANS un LOCK_*.md co-committé dans la même PR.
4. Cumulative-diff ratchet: stocker baseline diff par fichier, fail CI si nouvelle PR augmente le diff.
5. Documentation `docs/FROZEN_ZONES.md` expliquant le process LOCK.

Output: PR + Action + doc.
```

### Prompt #7 — Mobile P0 fix (cluster-7 ouvert)

```
Mission: fermer mobile cluster-7 P0 (allergènes fabriqués + promo code stub).

Context (Agent 6): mobile/data/menu.js:274 default ['gluten','lactose'] sur 60/60 items incl. eau minérale = EU FIC 1169/2011 legal exposure. mobile/screens-main.jsx:595 affiche "✓ Code appliqué" sans appliquer discount.

Étapes:
1. Fix allergènes: changer default `[]` ligne 274, puis curation manuelle item-by-item avec recette validée par owner Le Cayenne.
2. Fix promo: soit implémenter backend call + applique discount, soit retirer entièrement le bouton si fonctionnalité pas prête (recommandé V1).
3. Tests E2E mobile: order eau minérale → vérifier 0 allergène affiché; appliquer fake promo → vérifier soit erreur claire soit pas de banner trompeur.
4. Visual gate Read screenshots.

Output: 2 PRs (allergènes + promo) avec tests + screenshots.
```

### Prompt #8 — Runbooks signés + alerting

```
Mission: signer 4 runbooks critiques + câbler alerting Slack/Sentry/BetterUptime.

Context (Agent 4): 10 runbooks tous `DRAFT_SKELETON_NOT_SIGNED`. Diagnostic steps disent "Observation incident; aucune commande dédiée". `MonitorOutboxStaleness` n'écrit que dans Log::error, aucun Slack webhook configuré.

Étapes:
1. Pour chaque runbook critique (FISCAL_SEQUENCE_BREAK, KIOSK_NETWORK_LOSS, OUTBOX_BLOCKED, ROLLBACK_CANARY): remplacer "Observation; aucune commande" par commandes `php artisan` copy-paste exécutables.
2. Owner test: faire jouer le runbook par owner Le Cayenne en staging → mesurer temps + identifier blocages.
3. Itérer.
4. Tag header `DRAFT_SKELETON_NOT_SIGNED` → `SIGNED_BY_OWNER_2026-XX-XX`.
5. Imprimer cheatsheet plastifiée pour Le Cayenne (1 page recto-verso).
6. Câbler Slack webhook: ajouter LOG_SLACK_WEBHOOK_URL à .env, channel #foodking-prod-alerts.
7. Installer Sentry (laravel SDK + Vue SDK).
8. Setup BetterUptime ping /health/live toutes les 60s, alerte si fail 2 cycles.

Output: 4 runbooks signed + Slack/Sentry/BetterUptime live + screenshot dashboards.
```

### Prompt #9 — E2E bloquant + stress en CI

```
Mission: rendre E2E bloquant CI + porter stress test en CI matrix MySQL.

Context (Agent 5): `.github/workflows/playwright.yml:36-41` opt-in par label `e2e-required` + `continue-on-error: true`. PRs ship green sans Playwright. tests/load/RushMidiSimulationTest.php:48-58 self-documents "sqlite-memory pas vrai concurrent, lockForUpdate no-op", 10 orders only, S7.2+S7.3 markTestIncomplete (CI ne fail pas).

Étapes:
1. Modifier playwright.yml: drop label opt-in, drop continue-on-error.
2. Définir smoke pack 5 specs minimum bloquant (kiosk happy / POS cash / KDS bump / OSS update / fiscal Z-close).
3. Reste des E2E (full pack) reste opt-in (timeout cost), bloquant uniquement sur PR mergeant vers `main`.
4. Stress test MySQL matrix: porter RushMidiSimulationTest en MySQL CI step (déjà harness existant ci-sync-rupture-harness.yml).
5. POS direct-cash → CashMovement Feature test (Wave Z gap).

Output: workflows mis à jour + tests stress en CI + smoke pack documenté.
```

### Prompt #10 — Phase 3 multi-tenant catalog migration (planning seulement)

```
Mission: ULTRAPLAN multi-tenant catalog migration (PLAN ONLY, pas d'execution).

Context (Agent 3): items + item_categories + taxes + coupons + item_attributes + item_variations + item_extras tous SANS branch_id. Impossible 2 menus différents en l'état. MultiTenantModelTrait stub no-op.

Étapes (plan):
1. Pour chacune des 7+ tables catalogues: schéma migration nullable `branch_id` + backfill `branch_id=1` (Le Cayenne) + BranchScope ajouté au model.
2. Inheritance chain: si `branch_id IS NULL` = item global (chain default), si `branch_id=X` = item override branch X. Service `CatalogResolverService::resolve($branchId)` qui merge.
3. Onboarding command `php artisan foodking:onboard --restaurant="X" --plan=starter --siret=...` qui crée Branch + User owner + seed menu vide.
4. Super-admin Spatie separation: super_admin (2FA mandatory, cross-tenant) vs chain_owner (multi-branch) vs branch_manager (1 branch).
5. Test plan: 2 restaurants pilote, menus différents, isolation verifiée, super-admin accès cross-tenant.
6. Budget estimé + risks + rollback plan.

Constraint: PLAN ONLY. Pas de code. Owner gate avant exécution.

Output: `plans/MASTER_MULTI_TENANT_CATALOG_PHASE3.md` détaillé avec file:line citations + budget + risks.
```

---

## §12 ANNEXE — Indexation des rapports détaillés

| Agent | Rôle | Fichier | Score domaine | Findings P0 |
|---|---|---|---|---|
| 1 | Architect | `agent-1-architect.md` | 48/100 | dual Order/FrontendOrder, controllers fat, OrderService 2432 LOC |
| 2 | Security RED-team | `agent-2-security-red.md` | 28/100 | RCE LanguageService, tokens wildcard, AWS keys leaked, IDOR PosOrderController |
| 3 | DBA + SaaS multi-tenant | `agent-3-dba-saas.md` | 72 / 8 | items sans branch_id, no billing infra, branch.status drift, no backups |
| 4 | SRE + Production | `agent-4-sre-production.md` | 38 / 22 | runbooks DRAFT, no backups, no alerting, AWS keys, no deploy script |
| 5 | QA + Testing | `agent-5-qa-testing.md` | 46 / 27 | E2E non-bloquant, stress théâtral, 23 assertTrue(true), frozen-zones gate cassé |
| 6 | Frontend UX A11y i18n | `agent-6-frontend-ux.md` | 62 / 48 / 66 | mobile allergens fabriqués, mobile promo stub, POS wizard 0 ARIA |
| 7 | Competitive benchmark | `agent-7-competitive-benchmark.md` | 18 / 52 | no marketing/signup, no UberEats, no TPE driver |
| 8 | Claude-dependency | `agent-8-claude-dependency.md` | RISK 72 | CLAUDE.md vs AGENTS.md contradiction, frozen-zones théâtraux, auto-commits "up" |

---

## §13 CONCLUSION

**Tu n'as pas construit du vent.** Tu as construit la moitié d'un bon produit SaaS restaurant — avec des pièces que peu de tes concurrents français peuvent matcher (NF525 natif, kiosk+KDS intégrés, composer wizard, vélocité Claude). Et tu as construit la moitié d'un château de cartes — où la sécurité, l'ops, le multi-tenant et la commercialisation n'existent essentiellement pas.

**Le verdict honnête** :
- Pour ouvrir Le Cayenne dans 4-6 semaines en sortant des P0 immédiats — **faisable**, sous condition.
- Pour vendre à un 2ème restaurant payant dans 6 mois — **non, sauf en mode services-pro à 1 customer fait main**.
- Pour devenir un produit SaaS scalable à 50+ restaurants en 18 mois — **possible, mais demande une décision de fond cette année** : seul + Claude OR seed + équipe senior.

**Le risque vrai** n'est pas la qualité de Claude. C'est la **vitesse à laquelle tu accumules de la dette sans gates réels**. Le frozen-zones drift +6782 lignes, le AGENTS.md vs CLAUDE.md contradictoire, les 44 auto-commits "up", les runbooks DRAFT, le E2E non-bloquant — tous indiquent que **le process est plus déclaré qu'enforcé**. Bolt les gates au CI (gitleaks + frozen-zones + commitlint + E2E bloquant + composer audit) cette semaine et tu transformes Claude d'un risque en un multiplicateur réel.

**Le moat existe** (NF525 + intégration + composer + Claude-velocity). Il vaut la peine d'être défendu. Mais pas en l'état.

— Fin verdict CTO global 2026-05-16.
