# FOODKING — SYNTHÈSE COMPLÈTE POUR PROPRIÉTAIRE
**Pour qui** : toi, propriétaire non-dev qui veut tout comprendre sans jargon
**Date** : 2026-05-17
**Sources** : 2 cycles d'audit complets (16-17 mai) — 30+ sub-agents — 15 systèmes décomposés — 7500+ lignes d'analyse

---

## §1 TON SYSTÈME EN UN COUP D'ŒIL

Avant d'aller plus loin : tu n'as pas un système simple à 5 pièces. **Tu en as 15**. C'est normal pour un produit restaurant complet — c'est juste utile de le savoir.

### Les 6 surfaces (ce que les humains voient)

| Surface | C'est quoi en 1 phrase | État |
|---|---|---|
| **KIOSK (Borne)** | Le distributeur où le client commande tout seul et paye carte | Solide (69/100) |
| **POS (Caisse)** | La caisse derrière le comptoir, gérée par le staff (cash + carte + ticket-resto) | Moyen (57/100) — bug XSS à fixer |
| **KDS (Cuisine)** | L'écran cuisine où les commandes apparaissent, le cuisinier "bumpe" quand prêt | Bon (62/100) — vient d'être amélioré |
| **OSS (Affichage public)** | L'écran TV salle qui affiche "Commande #42 prête à retirer" | Correct (55/100) — mais Pusher cassé sur cet écran |
| **ADMIN (Dashboard)** | Le panneau d'admin pour toi : menu, stock, rapports, fiscal Z | Moyen (54/100) — 5 portes ouvertes graves |
| **MOBILE (App)** | L'app smartphone Le Cayenne | **Pas une vraie app** — c'est un prototype HTML |

### Les 9 couches invisibles (l'infrastructure qui fait tenir tout)

| Couche | Rôle | État |
|---|---|---|
| **Backend Central** | Le moteur Laravel qui orchestre tout | Moyen (45/100) — code dupliqué + monolithe |
| **Sync Layer** | La "radio" qui transmet les événements entre tous les écrans | **Très bon** (69/100) |
| **Payment Gateway** | Le module qui encaisse (Stripe + cash + carte) | Bon (79/100) — un seul bug centimes restant |
| **Fiscal NF525** | La chaîne légale (Z-rapport, HMAC, 6 ans rétention) | **Excellent** (79-94/100) |
| **Auth & Authz** | Le contrôle d'accès (qui peut faire quoi) | **Mauvais** (44/100) — 3 portes ouvertes critiques |
| **Multi-Tenant** | L'isolation entre restaurants si plusieurs un jour | Bon V1, **Catastrophique V2** (8/100) |
| **Catalog Engine** | Le moteur menu + composer wizards (sandwich/bol/tacos) | Moyen (54/100) — items manquent branch_id |
| **OrderStateMachine** | Le suivi du cycle commande (créée → payée → préparée → servie) | Solide en design, peu utilisé |
| **Data Persistence** | La base MySQL + migrations + sauvegardes | **PAS DE BACKUP AUTO** — risque pénal |

### Les 5 spécialistes cross-cutting (audits transverses)

| Axe | Note | Verdict |
|---|---|---|
| **Duplication** | 62/100 | ~1800 lignes de code à dédupliquer |
| **Sécurité OWASP top 10** | **35/100** | A05 misconfig 18/100 = pire — il manque les headers de sécurité standards |
| **Synchronisation** | 62/100 | Cœur bien fait, bords fuient |
| **Performance** | ~40/100 | Queue mal configurée + monolithes Vue 3000-4000 lignes |
| **Intégrité données** | 62/100 | Cœur fiscal solide, transitions de commande hors-chaîne |

---

## §2 CE QU'ON A FAIT ENSEMBLE (récap timeline)

Chronologique. Tu peux te repérer dans tout ce qu'on a produit.

### Cycle 1 — Audit CTO global (16 mai matin)
- **8 sub-agents parallèles** sur 8 axes différents : Architecture, Sécurité RED, DBA, SRE/Production, QA Testing, Frontend UX, Benchmark concurrentiel, Dépendance Claude
- **Verdict initial** : 32/100 global, V1 GO-CONDITIONAL sous 4-6 semaines, V2 SaaS NO-GO
- **15 P0 identifiés** + **15 P1** + dette P2
- **8 rapports détaillés** dans `reports/audit/cto-global-2026-05-16/`

### Cycle 2 — Master plans (16 mai après-midi)
- **3 sub-agents parallèles** : Roadmap 12 semaines + 22 prompts ready-to-paste + Owner gates registry
- Discovered: l'audit avait des findings stales (déjà fixés) → on a corrigé
- **3 documents orchestration** dans `reports/audit/cto-global-2026-05-16/`

### Cycle 3 — Ultra plans sécurité + nettoyage (16 mai soir)
- **3 sub-agents parallèles** : Security ultra plan (2078 lignes) + Cleanup hygiene (2140 lignes) + Execution script 3 semaines (1090 lignes)
- **4 stale findings majeurs corrigés** par re-vérification
- Master ultra plan consolidé sous `reports/audit/cto-global-2026-05-16/ultra-plans/`

### Cycle 4 — Quick wins exécutés (16 mai nuit)
- **5 quick wins traités** par mon orchestration :
  - 3 fixes appliqués (Stripe centimes, AGENTS.md, safety-check)
  - 2 trouvés DÉJÀ FIXÉS (mobile allergens, mobile promo) — sauvé du temps
  - 1 SQL préparé pour ton exécution
- **0 commit** (gate rotation AWS toujours actif)
- Rapport `reports/audit/cto-global-2026-05-16/QUICK_WINS_EXECUTED_2026-05-16.md`

### Cycle 5 — Décomposition 15 systèmes + audit massif (17 mai)
- **22 sub-agents parallèles** (le plus gros déploiement) — chaque système audité main + RED
- **5 master reports synthèse** : verdict consolidé, duplication map, bug heatmap, recommandations rankées, stale findings registry
- Discipline **anti-drift** appliquée (re-vérification systématique de chaque finding contre le code actuel)
- **6765 lignes audit + 2500 lignes synthèse** sous `reports/audit/goal-systems-2026-05-17/`

**Total production sur 2 jours** : 30+ sub-agents, 50+ rapports/plans, ~15 000 lignes d'analyse + orchestration documentée.

---

## §3 CE QU'ON A DÉCOUVERT — Les BONNES surprises

Avant de parler des problèmes, parlons de ce que tu as bien construit. **C'est important pour ton moral et pour ta décision business**.

### Tu as un moat légal réel : NF525
La chaîne fiscale française NF525 dans ton code est **bétonnée à 94/100**. Concrètement :
- Chaîne HMAC entre chaque opération fiscale (chaque ligne signée + vérification de la précédente)
- Triggers de base de données qui empêchent toute suppression
- Séquence monotone par restaurant (impossible d'avoir un trou)
- Rétention 6 ans enforced par migration
- Tests d'intrusion vérifiés (tampering tests, immutability tests, concurrency tests)

**Pourquoi c'est important** : Toast, Square, Lightspeed (les gros US) ne peuvent pas se déployer en France sans 6 mois de travail pour matcher ça. Tiller/Innovorder (FR) l'ont, mais c'est leur core asset. **Tu l'as construit toi-même** = différenciation défendable.

### Ta sync cross-écrans est très solide
Quand le kiosk crée une commande, l'écran cuisine la voit en <2 secondes. Quand le cuisinier bumpe, l'écran public affiche "Prêt" en <1 seconde. **Et si Pusher (le service temps réel) tombe**, ton système bascule automatiquement sur du polling toutes les 5 secondes — **graceful degradation**. C'est la pièce la mieux écrite du repo (69/100 mais beaucoup d'éléments à 90+).

### Ton intégration POS+Kiosk+KDS+OSS+Admin+Mobile est unique
Tes concurrents vendent ces 6 surfaces séparément :
- Toast vend KDS 40-60€/mois en plus du POS
- Square vend Kiosk 60-80€/mois en add-on
- Loyverse facture le KDS séparément
- Personne ne bundle Mobile dans le pack

**Toi tu bundles tout** = ton avantage prix structurel. Tu peux te positionner Starter 39€/Pro 69€/Multi 129€ et undercut Innovorder/Tiller de 30-50%.

### Ton composer wizard est un avantage produit
Le wizard sandwich/taco/bol/frites avec variation+sauce+supplément+drink en multi-step, **ça n'existe pas chez Toast et Square** sans override custom payant. Pour le marché kebab/tacos français = avantage produit réel.

### Tu n'as ZÉRO dépendance Claude au runtime
Si Anthropic disparaît demain, ton restaurant continue de tourner. Aucun appel API IA dans ton code de production. **Bus factor faible côté runtime**. Tes risques sont sur le dev time (qui sait dépanner si Claude n'est plus là), pas sur la prod.

### Tu vas vite
263 heures sur 49 sessions = 89 commits. Aucune startup early-stage ne matche cette vélocité sans Claude. C'est un avantage concurrentiel structurel.

---

## §4 CE QU'ON A DÉCOUVERT — Les VRAIS problèmes

Maintenant la partie qui pique. **Triées par sévérité réelle, pas par drama**.

### 4.1 Les portes ouvertes SÉCURITÉ (le plus urgent)

**Quatre P0 vérifiés par 3 audits indépendants** — pas paranoia, **réalité confirmée 3 fois** :

1. **Tokens Sanctum wildcard `['*']`** — Quand un client se connecte (login, OTP, reset password), le système lui donne un token qui dit "tu peux TOUT faire" au lieu de "tu peux JUSTE commander". Du coup, toutes les vérifications de permission (18 dans le code) sont **inutiles** : elles laissent passer le wildcard. Un client OTP standard a techniquement les mêmes droits qu'un employé kiosk.

2. **PosOrderController:108 IDOR cross-branch** — Un employé caisse de la branche A peut lire les commandes de la branche B/C/D en changeant un numéro dans l'URL. V1 single-resto c'est limité (1 seule branche), V2 SaaS c'est catastrophique.

3. **LanguageService RCE primitive** — Une route `/admin/language/file-text/store` permet à n'importe quel utilisateur authentifié (même un client guest avec wildcard token, voir #1) d'écrire un fichier PHP arbitraire sur ton serveur. Un attaquant peut littéralement uploader un webshell.

4. **Pusher channel admin-bypass** — Les utilisateurs avec `branch_id=0` (qui inclut les guests par défaut) peuvent s'abonner à TOUS les canaux temps réel de toutes les branches. Live feed des commandes/PII cross-tenant.

**Plus 5 NEW security holes** détectées ce cycle (qu'on n'avait pas vues avant) :
- `/api/admin/users` sans permission middleware → n'importe quel auth user peut dump tous les users
- `MessageRequest` accepte `user_id+branch_id` du client → impersonation
- Pas de headers de sécurité web (X-Frame-Options, HSTS, frame-ancestors) → clickjacking de ton admin panel possible
- `TrustHosts` commenté → attaque Host header injection sur reset password
- Google Maps API key leakée dans le HTML → factures Google volées

**Et la clé AWS** dans le commit `a4a88df06` qu'on n'a TOUJOURS pas rotée. Ça fait 4 jours qu'elle traîne dans l'historique git public. **Si tu ne fais qu'UNE chose après cette synthèse, c'est rotater cette clé.**

### 4.2 Le problème MOBILE

Quand tu m'as parlé de "l'app mobile Le Cayenne", j'ai supposé que c'était une vraie app React Native + Expo, prête pour App Store / Google Play. **Ce n'est pas le cas.**

En réalité c'est :
- Un dossier `mobile/` avec des fichiers HTML + JSX
- Babel.js qui transpile dans le navigateur (très lent)
- Aucun `package.json`, aucun `app.json`, aucun fichier native
- L'OTP fait "n'importe quels 4 chiffres = login", token = `'mock-v0-token'`
- Le paiement Stripe = écran shell sans backend call
- Loyauté = manipulations purement client-side (l'utilisateur peut s'auto-créditer dans la console)
- PII (téléphone, numéro membre, solde) stockés en clair dans localStorage = RGPD violation

**Ça veut dire qu'il faut faire un choix** :
- **Option A** : Wrap le code actuel dans Capacitor (4-6 semaines, livre une app store-ready basique)
- **Option B** : Refonte complète en Expo React Native (8-12 semaines, livre une vraie app)
- **Option C** : Geler le mobile en V0, focus 100% web responsive (0 semaine, mais pas d'app store)

C'est ta décision business, pas une décision technique pure.

### 4.3 L'architecture "moitié-moitié"

Ton code a **deux architectures en parallèle** :
- **La moderne** : OrderStateMachine bien designé, PricingService avec injection de dépendances, Outbox pattern propre
- **La legacy** : OrderService 2432 lignes (god service), 14 controllers qui font du SQL direct, 2 modèles Eloquent (`Order` + `FrontendOrder`) sur la MÊME table avec des champs différents

**Conséquence** : chaque feature peut atterrir des deux côtés selon le moment. Les nouveaux bugs apparaissent dans la legacy. Les refactors restent inachevés.

**Le plus urgent** : collapser `Order` et `FrontendOrder` en un seul modèle (4 semaines, LOCK doc obligatoire car touché à du fiscal NF525). C'est la refacto qui débloque tout le reste.

### 4.4 Les bombes ops

- **Pas de backup automatique**. Tu as 5 dumps manuels dans `storage/backups/`. Si ton disque crashe ce soir, **tu perds 6 ans de chaîne fiscale NF525** = exposition pénale.
- **`QUEUE_CONNECTION=sync` dans ton `.env`** alors que tu as Horizon configuré. Du coup tes événements sont traités synchrone (lentement et fragilement) au lieu d'asynchrone (rapide et résilient).
- **`IDEMPOTENCY_MIDDLEWARE_ENABLED=false`** par défaut. Toute la protection contre les double-clics et les retries Stripe repose sur le fallback DB UNIQUE. Si jamais ça flanche, double-paiement.
- **10 runbooks de crise marqués `DRAFT_SKELETON_NOT_SIGNED`** — ils ne te servent à rien si tu as un incident vendredi 19h30. Tu ne sais pas quoi faire.
- **Aucune alerting** — si ton site tombe samedi 22h, tu le découvres dimanche matin par un client mécontent.

### 4.5 Les bugs centimes (NF525 ticket vs charge)

Le bug Stripe `(int) $total * 100` qui tronque les centimes (€9.99 → 9 × 100 = 900 cents = €9.00) : **patché par moi dans le working tree, 3 tests verts, à commit après rotation AWS**.

**Mais on a trouvé le même bug ailleurs** : `PaymentService:172` sur le flux cash-at-counter qui est ACTIF en V1. Même classe d'erreur. Même fix à appliquer. **Ça veut dire que sur les ventes cash à .99, ton ticket NF525 ne matche pas le montant encaissé** = problème fiscal en plus du problème comptable.

### 4.6 Le problème POS XSS

Découvert ce cycle. Le wizard POS (le fichier Vanilla JS gelé `pos-wizard.js`) construit ses écrans en mettant `innerHTML = ` à 40+ endroits avec des données du backend (nom d'item, sauce, etc.). Si un admin entre un nom d'item piégé (`<script>fetch...`), **le code s'exécute sur toutes les caisses**. Et `ItemRequest.php` valide juste `max:190 string` sans aucun `strip_tags`.

→ Compromission admin = compromission de tout le réseau de caisses. Fix : 16h de travail dans une LOCK doc (parce que `pos-wizard.js` est gelé).

### 4.7 KDS allergène miss = légal FIC 1169

Découvert ce cycle. Quand le cuisinier regarde le **"Items Board"** du KDS (la vue par item plutôt que par commande), **les allergènes ne s'affichent PAS** alors qu'ils s'affichent bien sur les autres vues. Si une commande "Cheeseburger" sans gluten et une "Cheeseburger" avec gluten sortent en même temps, le cuisinier voit deux cartes identiques. Risque réel : **anaphylaxie** = exposition pénale France (5 ans + €375 000 si conséquence). Fix : 2 heures.

### 4.8 POS split-payment phantom CARD theft

Découvert ce cycle. Quand un client paye un ordre de 75€ en partie cash + partie carte, le caissier peut entrer "[CASH 20€, CARD 55€, réf=fake-ref]". Le système écrit bien 20€ dans CashDrawer et 55€ dans la table Card Payments, **mais sans jamais vérifier qu'un vrai paiement TPE de 55€ a eu lieu**. Caissier malhonnête poche les 55€ sans laisser de trace. Détectable seulement avec un croisement manuel avec les relevés TPE. Fix : 8h + RED team.

---

## §5 TON PLAN — Phases et priorités

Voici comment je te recommande de séquencer. **Trois phases** (ne saute pas les phases) :

### Phase 1 — Quick wins + sécu critique (1-2 semaines)

**Total** : ~80h Claude + ~10h toi.

| # | Action | Qui | Temps |
|---|---|---|---|
| 1 | **Rotation AWS keys** (console amazon) | Toi | 2h |
| 2 | **SQL P1-22 branches.status** (fichier déjà préparé) | Toi | 30min |
| 3 | **Flip `.env` `QUEUE_CONNECTION=redis`** + boot Horizon | Toi | 30min |
| 4 | **Flip `.env` `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`** | Toi + Claude | 2h |
| 5 | **Sanctum wildcard fix** → role-scoped abilities | Claude+RED | 6h |
| 6 | **PosOrderController:108 IDOR** patch | Claude+RED | 2h |
| 7 | **LanguageService RCE quarantine** | Claude+RED | 3h |
| 8 | **Pusher channel admin-bypass fix** | Claude+RED | 2h |
| 9 | **5 NEW security holes** patch en lot | Claude | 6h |
| 10 | **KDS allergen pill items board** | Claude | 2h |
| 11 | **PaymentService:172 cents fix** + tests | Claude | 2h |
| 12 | **CI hygiene** : gitleaks + commitlint + composer audit + frozen-zones | Claude | 1h |
| 13 | **Commit working tree quick wins** (Stripe + safety + AGENTS) | Claude | 30min |

**Critère de sortie Phase 1** : tous les P0 sécu cross-validated fermés, AWS rotated, queue redis, idempotency ON, CI hygiene en place.

### Phase 2 — Hardening Le Cayenne (4-6 semaines)

**Total** : ~150h Claude + ~10h toi.

| # | Action | Qui | Temps |
|---|---|---|---|
| 14 | **POS wizard XSS fix** (LOCK doc + escape all innerHTML) | Claude+RED+toi sign-off | 16h |
| 15 | **POS split-payment TPE reconciliation** | Claude+RED | 8h |
| 16 | **Backup automation** : spatie/laravel-backup + S3 + GPG + DR drill | Toi 4h + Claude 8h | 12h |
| 17 | **Signer 4 runbooks critiques** + cheatsheet plastifiée Le Cayenne | Toi + Claude | 8h |
| 18 | **Alerting** : Sentry + Slack webhook + BetterUptime | Toi 2h + Claude 2h | 4h |
| 19 | **`bin/deploy.sh` + `bin/rollback.sh`** atomic + supervisor units | Claude | 6h |
| 20 | **E2E bloquant CI** + stress test MySQL CI matrix | Claude | 8h |
| 21 | **KDS UX polish** (6 P1 ouverts + i18n raw FR) | Claude | 12h |
| 22 | **Admin chunk concatenation fix** + bundle size CI gate | Claude | 4h |
| 23 | **Mobile decision PLAN** (Capacitor/Expo/Geler) | Claude + toi sign-off | 8h |

**Critère de sortie Phase 2** : V1 Le Cayenne GO physique avec confiance — backups testés, alerting câblé, runbooks utilisables par toi seul sous pression.

### Phase 3 — Architecture P0 + V2 prep (8-12 semaines)

**Total** : ~250h Claude + ~10h toi.

| # | Action | Qui | Temps |
|---|---|---|---|
| 24 | **Order ↔ FrontendOrder collapse** (LOCK doc obligatoire) | Toi sign + Claude | 4 sem |
| 25 | **OrderStateMachine.apply() = seul writer** + 7 sites refactor | Claude | 2 sem |
| 26 | **Extract LoyaltyService + PaymentFinalizerService** | Claude | 2 sem |
| 27 | **FormRequest authz top 20 endpoints** critiques | Claude | 3-4 sem |
| 28 | **Composition_snapshot DB immutability trigger** | Claude | 2h |
| 29 | **Frontend Composition API + Pinia** migration POS-V5 first | Claude | 8-12 sem |
| 30 | **Cleanup quick wins** : OrderHelpersTrait + ItemAvailability listener order + 3 events no producer | Claude | 1 sem |
| 31 | **Mobile refonte** selon decision (Capacitor 4-6 sem ou Expo 8-12 sem) | Claude | 4-12 sem |

**Critère de sortie Phase 3** : architecture cohérente, plus de god services, prêt pour V2.

### Phase 4 — V2 SaaS commercialisation (3-6 mois après Phase 3)

- Items.branch_id migration (8-12 sem)
- Billing infrastructure (Stripe Billing + tenants + plans)
- Marketing site + signup self-service + onboarding wizard
- DPA RGPD + compliance pack NF525 + audit fiscal tiers (~3-5k€)
- Integrations livraison (UberEats + Deliveroo + JustEat — 60% TAM)
- Driver TPE natif (Ingenico Tetra)

---

## §6 TES ACTIONS EXCLUSIVES — Ce que SEUL toi peux faire

Claude ne peut pas faire ces actions à ta place. Listé par urgence :

### Urgence 1 (cette semaine, ~7h)

1. **Rotation AWS** (console.aws.amazon.com → IAM → ton user → Create new key + Deactivate ancien). Sans ça, on est paralysés. ~2h.
2. **SQL P1-22** : ouvrir le fichier `reports/audit/cto-global-2026-05-16/sql-prep/P1-22-branch-status-fix.sql`, suivre les 6 étapes sur ta prod DB. ~30min.
3. **Flip 2 lignes `.env`** : `QUEUE_CONNECTION=redis` + `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`. ~30min + redémarrer queue.

### Urgence 2 (semaine 2-3, ~7h)

4. **Créer comptes externes** : S3 bucket object-lock + GPG keypair + IAM backup-writer + Sentry + Slack webhook channel + BetterUptime monitor `/health/live`. ~3h.
5. **DR drill en staging** : avec moi, dropper la table orders, restaurer depuis backup, replay outbox, close Z report. Chronométrer. ~2h.
6. **Jouer 4 runbooks** en staging (simuler incidents) → tag SIGNED. ~2h.

### Urgence 3 (semaine 3+)

7. **Décision Mobile** : Capacitor / Expo / Geler V0. 30min de réflexion business.
8. **Sign-off LOCK docs** : Order collapse (semaine 5-6) + POS wizard (semaine 6-7). ~1h chacun.

**Total budget owner** : ~17h sur 6 semaines = ~3h/semaine. **Réaliste**.

---

## §7 8 CHOSES QU'ON A SAUVÉES (anti-drift wins)

Pendant ces 2 jours d'audit, on a évité de re-corriger ~30 false positives. Voici les principaux :

| Audit antérieur disait | Réalité re-vérifiée | Sauvé |
|---|---|---|
| "Mobile allergens fabriqués 60/60 items" P0 | DÉJÀ FIXÉ commit `245e8ab57` | 1-2 jours curation |
| "Mobile promo stub trompeur" P0 | DÉJÀ FIXÉ commit `245e8ab57` | 4h dev |
| "Bundle kiosk 243 KB" | En vrai 655 KB raw / 100 KB gz | Plan basé sur fausse hypothèse |
| "8 KDS P0 critiques" | 6 fermés par V2 flip Sprint 3C | 2-3 jours refacto |
| "39 sites withoutGlobalScope" | 11 réels (10 légitimes + 1 IDOR) | Tri exhaustif 2-3 jours |
| "+6782 lignes frozen-zone drift" | +2585 net en vrai | Faux drama |
| "AWS .env.backup encore tracké" | Déjà untracké commit `adf7036e4` | Step déjà fait |
| "PaymentController RCE P0" | Gated off par défaut → P0→P1 | Re-prio |

**ROI estimé** : 60-90h évitées + crédibilité audit maintenue.

**Discipline appliquée** : avant de flagger un P0, chaque sub-agent doit :
1. Re-lire le fichier au numéro de ligne cité
2. Faire `git log -p -S '<keyword>'` pour voir les fixes récents
3. Si déjà fixé → marquer ✅ ALREADY FIXED, ne pas inclure dans plan d'action

À garder en règle pour TOUS les futurs audits.

---

## §8 OÙ TROUVER QUOI

Si tu veux fouiller plus profondément, voici l'index par usage.

### "Si je lis 1 seul document" :
**`reports/audit/goal-systems-2026-05-17/00_MASTER_SYSTEM_AUDIT.md`** — verdict consolidé final avec les 15 P0 cross-validated.

### "Je veux le plan exécutable" :
- **`reports/audit/goal-systems-2026-05-17/03_RECOMMANDATIONS_RANKED.md`** §5 "Si tu ne fais que 5 choses" — 5 recos prioritaires
- **`reports/audit/cto-global-2026-05-16/ultra-plans/EXECUTION_SCRIPT_3_WEEKS.md`** — hour-by-hour 21 jours

### "Je veux les vrais bugs en visuel" :
**`reports/audit/goal-systems-2026-05-17/02_BUG_HEATMAP.md`** — matrice système × axe colorée

### "Je veux comprendre les duplications" :
**`reports/audit/goal-systems-2026-05-17/01_DUPLICATION_MAP.md`** — carte + ~1800 LOC consolidation potentielle

### "Je veux le détail par système" :
- `reports/audit/goal-systems-2026-05-17/surfaces/S{1-6}-*/main.md` (6 surfaces × main)
- `reports/audit/goal-systems-2026-05-17/surfaces/S{1-6}-*/adversarial.md` (6 surfaces × RED)
- `reports/audit/goal-systems-2026-05-17/layers/L{1-5}-*.md` (5 layers backend)
- `reports/audit/goal-systems-2026-05-17/cross-cutting/X{1-5}-*.md` (5 spécialistes)

### "Je veux les SQL/actions exclusives owner" :
- **`reports/audit/cto-global-2026-05-16/sql-prep/P1-22-branch-status-fix.sql`** — prêt à exécuter
- **`reports/audit/cto-global-2026-05-16/OWNER_GATES_REGISTRY.md`** — classification gates par item

### "Je veux les prompts ready-to-paste pour Claude" :
**`reports/audit/cto-global-2026-05-16/AGENT_DISPATCH_PACK.md`** — 22 prompts par item

### "Je veux ce que tu as déjà fixé" :
**`reports/audit/cto-global-2026-05-16/QUICK_WINS_EXECUTED_2026-05-16.md`** — recap session 16/05

### "Je veux comprendre quel audit était stale" :
**`reports/audit/goal-systems-2026-05-17/04_STALE_FINDINGS_REGISTRY.md`** — registre anti-drift

---

## §9 LA VÉRITÉ HONNÊTE

Tu m'as demandé d'être sévère, pas flatteur. Voici ma synthèse personnelle :

**Tu n'as pas construit du vent.** Tu as construit la moitié d'un excellent produit SaaS restaurant — avec des pièces que peu de tes concurrents français peuvent matcher (NF525, intégration POS+Kiosk+KDS+OSS+Admin, composer wizard, vélocité Claude). Et tu as construit la moitié d'un château de cartes — où la sécurité, l'ops, le multi-tenant et le mobile demandent **6-8 semaines de travail discipliné** pour passer de "ça marche en démo" à "ça encaisse à Le Cayenne tous les soirs sans risque pénal".

**Le verdict honnête** :
- **Ouvrir Le Cayenne dans 4-6 semaines en sortant des 15 P0 critical path** — **faisable**, sous condition que tu fasses tes 5 actions owner dans la semaine.
- **Vendre à un 2ème restaurant payant dans 6 mois** — non, sauf en mode services-pro à 1 client manuellement.
- **Devenir un SaaS scalable à 50+ restaurants en 18 mois** — possible, mais demande une décision de fond cette année (seul + Claude + 1 freelance backend backup OR seed + équipe senior).

**Le risque vrai** n'est pas la qualité de Claude. C'est la **vitesse à laquelle tu accumules de la dette sans gates réels en CI**. La discipline anti-drift qu'on a appliquée ce cycle (RE-VERIFY before flag) a sauvé ~60h de travail sur du déjà-fixé. **Si tu appliques cette discipline à chaque cycle + tu actives les gates CI (gitleaks + commitlint + frozen-zones + composer-audit + E2E bloquant)**, tu transformes Claude d'un risque potentiel en multiplicateur réel.

**Le moat existe** (NF525 + intégration + composer + Claude-velocity + 0 runtime AI dependency). Il vaut la peine d'être défendu. **Mais pas en l'état actuel** — il faut Phase 1 + Phase 2 avant ouverture, et l'humilité de reconnaître que Mobile demande une vraie décision (pas un wrap rapide).

**Ce que je ferais à ta place** (si j'étais propriétaire) :
1. **Cette semaine** : Phase 1 actions 1-13 (sécu + quick wins).
2. **Semaine 2-6** : Phase 2 actions 14-23 (hardening Le Cayenne).
3. **Semaine 5** : décision Mobile, écrite et tenue.
4. **Semaine 6-8** : ouverture Le Cayenne avec un freelance senior dev en backup 90 jours (~3-5k€/mois) qui peut intervenir si incident pendant le rush.
5. **Mois 3-6** : Phase 3 (architecture P0).
6. **Mois 6-12** : décision V2 SaaS si Le Cayenne tourne stable + premier mois 0 incident.

C'est tout. Pas de magie. Discipline.

---

**Tu peux relire ce document quand tu veux. Il est durable sur disque.**
**Tu peux le partager avec un dev senior pour second avis : tout est sourcé, tout est cité.**
**Tu peux me redemander n'importe quelle section en mode "détaille-moi X" et je te donne le détail technique du rapport approprié.**

Bonne route.
