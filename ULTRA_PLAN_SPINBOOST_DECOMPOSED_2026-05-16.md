# ULTRA-PLAN DÉCOMPOSÉ — SpinBoost (post-pivot, solo + IA)

> **Hypothèse** : Option A retenue (pivot conformité — découplage récompense ↔ avis Google).
> **Founder** : solo + Claude Code + sub-agents.
> **Cible** : MVP 5 restos pilotes en **6-8 semaines réelles**.
> **Coût visé** : ~10-12 k€ cash + 6 mois temps.
> **Source critique** : ce plan REMPLACE `ULTRA_PLAN_DEV_SPINBOOST.md` §14 (sprints 1-6).
> **Gates GO/NO-GO** : à la fin de chaque sprint, décision explicite continuer/pivoter/kill.

---

## 0. Pré-requis avant Sprint 0 (J-7 à J-1)

**Décision founder à acter avant de coder une ligne** :

- [ ] **Choix Option A confirmé** (cf. `ULTRA_REVIEW_SPINBOOST_2026-05-16.md` §5) — pivot conformité acté
- [ ] **Nom marque validé** (SpinBoost est-il déposable ? INPI search + domaines `.fr/.com/.io`)
- [ ] **Statut juridique tranché** : SAS solo (Stripe-friendly, ~500€ greffe) vs EURL vs micro-entreprise (plafond 77 700€ → bloquant à 50 restos × 49€ × 12 = 29 400€ OK 1ère année puis migration forcée)
- [ ] **5 restos warm-leads identifiés** pour pilotes — sans eux, le sprint 6 (pilotes) n'existe pas. Si tu n'as pas ces 5 noms maintenant, le plan business saute → reviens à Option C.
- [ ] **Budget cash dispo** : minimum 12 k€ pour 3-4 mois sans revenu (dev + infra + juridique + pentest + vie)

---

## 1. Sprint 0 — Fondations légales + setup (semaine 1, 5 j)

**Objectif** : pas de code. Désamorcer les risques non-techniques avant de coder.

### Jour 1-2 — Juridique
- [ ] RDV avocat (1h, ~300€) pour valider le pivot conformité + identifier les docs obligatoires
- [ ] Commander : règlement de jeu, JCA (Joint Controller Agreement RGPD art. 26) type, CGV B2B, CGU joueur, politique confidentialité, mentions légales — ~1 500€ chez Legalstart + review avocat 1h
- [ ] Démarrer AIPD (DPIA art. 35) — template CNIL, à finaliser en sprint 5

### Jour 3 — Comptes & domaines
- [ ] Créer SAS (greffe en ligne) — délai 5-10 j calendaires en parallèle
- [ ] Acheter domaines `.fr` + `.com` + `.io` (~150€/2 ans)
- [ ] Créer comptes : Vercel, Supabase, Stripe (mode test), Resend, Sentry, Axiom, GitHub org, Cloudflare (Turnstile)

### Jour 4 — Architecture & schéma
- [ ] Mono-app Next.js 15 décidé (kill Hono, kill Turborepo, kill 3 apps séparées — cf. P0-5)
- [ ] Trancher Auth.js v5 SEUL (kill ambiguïté Supabase Auth — cf. P2-4)
- [ ] Trancher Node runtime SEUL (kill l'argument edge + Prisma Accelerate non-budgété — cf. P0-4)
- [ ] Schéma Prisma corrigé écrit (avec les 10 fixes + 5 tables manquantes — voir annexe data model agent) → commit dans repo

### Jour 5 — Design brief envoyé + projection landing
- [ ] Envoyer `DESIGN_BRIEF_CLAUDE_DESIGN_2026-05-16.md` (livrable séparé) → Claude Design en parallèle
- [ ] Setup repo + CI minimal (lint, typecheck, vitest) sur GitHub Actions
- [ ] `.env.example` documenté avec TOUS les secrets (avec génération `openssl rand` documentée pour `ENCRYPTION_KEY`)

**Gate Sprint 0 → Sprint 1** :
- ✅ Docs juridiques en cours de rédaction (pas finalisés mais commandés)
- ✅ Schéma Prisma fixé écrit (peut être généré, pas migré encore)
- ✅ Brief design envoyé
- ✅ 5 warm-leads pilotes confirmés (sinon STOP)

---

## 2. Sprint 1 — Foundation App (semaine 2, 5-6 j)

**Objectif** : application Next.js déployée en staging, un user peut se logger et créer son resto.

### Tâches
- [ ] `pnpm create next-app@latest spinboost --typescript --tailwind --app`
- [ ] Setup Prisma + migrations + seed minimal
- [ ] Auth.js v5 magic link (Resend) — tables `User`, `Session`, `Account`, `VerificationToken` standard
- [ ] Layout shell dashboard (sidebar, header, dark mode)
- [ ] CRUD `Organization` + `Venue` (formulaire onboarding step 1)
- [ ] **OpenTelemetry SDK installé dès maintenant** (`@vercel/otel` + exporter OTLP → Axiom) — cf. §3 truc caché review
- [ ] Pino + ECS format figé en `lib/logger.ts`
- [ ] Sentry SDK init front + back avec traceparent propagation
- [ ] Déployer Vercel staging, DB Supabase staging

### Vérifications fin Sprint
- [ ] `pnpm test` vert (unit basiques sur valid Zod schemas)
- [ ] `pnpm e2e` 1 test happy path : signup → magic link → onboarding step 1
- [ ] Sentry capture une fake error et tu vois le `trace_id` dans Axiom logs
- [ ] CI verte sur main

**Gate Sprint 1 → Sprint 2** :
- ✅ Tu peux te connecter en staging et créer 1 venue
- ✅ Trace distribuée fonctionne (Sentry → Axiom corrélé)

---

## 3. Sprint 2 — Wheel + Play core (semaine 3-4, 8-10 j)

**Objectif** : un joueur peut scanner, jouer, gagner, recevoir son voucher par email.

### Tâches
- [ ] CRUD `Campaign` + `CampaignSlot` (table normalisée, pas Json — cf. P0-8) avec UI `<WheelEditor>` drag-and-drop
- [ ] CRUD `Prize` avec compteur atomique `UPDATE ... RETURNING *` (cf. P0-8)
- [ ] Validation Zod : sum(probabilityBp) = 10000 côté app + check SQL côté DB
- [ ] Page joueur `/r/[slug]` — design from Claude Design intégré
- [ ] Form email opt-in (case marketing **séparée** du voucher — cf. P1-3) + Turnstile
- [ ] NPS 4 emojis (privé, **pas conditionnel** au spin — pivot conformité)
- [ ] Endpoint `POST /api/v1/public/plays/spin` :
  - Crée `Play`
  - Tirage authoritative serveur (crypto.randomInt sur slots cumulés)
  - **Signe `Play.drawProof = HMAC-SHA256(prev_play_hash \|\| campaign_slots_snapshot \|\| server_seed \|\| ts)`** avec clé KMS-managed (cf. P0-2)
  - Atomic stock decrement Prize
  - Génère `voucherCode` unique (cuid2 court)
  - Retourne `prizeIndex` pour animation
- [ ] Animation roue (SVG + CSS keyframes ou @react-spring/web — pas Framer Motion 30KB — cf. P2-6)
- [ ] Page result `/r/[slug]/result/[playId]` avec voucher + CTA Google review **inconditionnel non-récompensé** (pivot)
- [ ] Resend email confirmation gain (template React Email)
- [ ] Rate limiting Upstash Redis : 10 req/min/IP sur `/spin`
- [ ] Cooldown 30j par device fingerprint (FingerprintJS Pro free tier OU lib OSS) + IP + emailHash (cf. P1-1)
- [ ] Détection disposable emails (lib `disposable-email-domains` npm) — bloquer ou exiger phone

### Vérifications fin Sprint
- [ ] `pnpm e2e` : scan QR → form → spin → gain → email reçu (Resend test mode capture)
- [ ] Lighthouse mobile `/r/[slug]` ≥ 90 perf, CLS < 0.1
- [ ] Test anti-fraude : même device + email rejoue → blocage avec message clair
- [ ] Test concurrent stock : 2 plays simultanés sur stock=1 → 1 WIN 1 CONSOLATION (jamais 2 WIN)
- [ ] `Play.drawProof` vérifiable : reconstruction côté serveur match

**Gate Sprint 2 → Sprint 3** :
- ✅ Tu peux jouer toi-même sur staging, ton voucher arrive par mail
- ✅ Audit tirage : pour 1000 spins simulés, distribution = configuration ±2%
- ✅ Test fraude : 10minutemail bloqué

---

## 4. Sprint 3 — Onboarding + Flyer (semaine 5, 5-6 j)

**Objectif** : un resto s'inscrit seul en < 10 min et télécharge son flyer.

### Tâches
- [ ] Wizard onboarding 4 étapes (kill l'étape Google OAuth — cf. kill list) :
  1. Resto (nom, adresse, type, lien Google Maps writereview collé manuellement)
  2. Branding (logo upload Supabase Storage, couleur primaire — kill secondary)
  3. Roue (presets 3 templates : "Boisson", "Dessert", "Mix")
  4. Flyer (preview + download PDF)
- [ ] Logo upload : SSRF protection (validate content-type, reject SVG, max 2MB, scan magic bytes)
- [ ] React-PDF flyer : 1 seul template paramétrable (cf. kill list)
- [ ] QR code généré server-side (`qrcode` npm) pointant `/r/[slug]`
- [ ] Page publique venue avec branding live preview

### Vérifications fin Sprint
- [ ] Tu fais un onboarding chronométré toi-même < 10 min (mesure)
- [ ] PDF généré ouvre sur Mac + iPhone Acrobat sans bug
- [ ] QR scanné par 3 téléphones différents (Android low-end, iPhone, OnePlus mid) ouvre la bonne page

**Gate Sprint 3 → Sprint 4** :
- ✅ 1 ami (non-tech) finit l'onboarding sans aide
- ✅ Flyer imprimé en A6 lisible et QR scannable

---

## 5. Sprint 4 — Billing Stripe (semaine 6, 5-7 j)

**Objectif** : conversion trial → payant automatisée. **Piège classique** : prévoir buffer 2j.

### Tâches
- [ ] Produits Stripe : Starter 39€/mois (recalibré vs 29€ irréaliste — cf. P1-7) + Pro 49€/mois (incl. priority support) — pas d'Enterprise V1
- [ ] Stripe Checkout (session) + Customer Portal
- [ ] Webhook handler `/api/v1/webhooks/stripe` :
  - Vérification signature avec SDK officiel (jamais HMAC custom — cf. P0-3)
  - Vérif timestamp tolérance 5 min
  - **Table `WebhookEvent UNIQUE(provider, eventId)` pour idempotency** (cf. P0-3)
  - Events à gérer : `customer.subscription.created/updated/deleted`, `invoice.payment_failed/succeeded`
- [ ] States `Organization.status` synchronisés : TRIAL → ACTIVE → PAST_DUE → CANCELED → SUSPENDED
- [ ] Email Resend `payment_failed` + grace period 7j avant suspension
- [ ] Page `/billing` dashboard avec next invoice + cancel button (-> Stripe portal)
- [ ] Test E2E avec Stripe CLI : `stripe trigger invoice.payment_failed` → état DB conforme

### Vérifications fin Sprint
- [ ] Tu fais 1 cycle trial → CB ajoutée → payé → annulé en mode test Stripe
- [ ] 2 webhooks identiques arrivent → 1 seul process (idempotency)
- [ ] Replay attaque (renvoi event ID déjà vu) → rejeté

**Gate Sprint 4 → Sprint 5** :
- ✅ Cycle paiement complet testé bout-en-bout

---

## 6. Sprint 5 — Polish + Compliance + Pilotes (semaine 7-8, 8-10 j)

**Objectif** : prêt pour 5 restos pilotes en condition réelle.

### Tâches
- [ ] CRM page : liste participants + export CSV (basique, pas de filtres avancés)
- [ ] Dashboard KPIs : scans, plays, conversion (Recharts) — 4 cards, pas plus
- [ ] AIPD finalisée + JCA finalisé + règlement de jeu finalisé (avocat)
- [ ] Banner cookies CNIL-conforme (lib `vanilla-cookieconsent` ou équivalent)
- [ ] Pages `/cgv` `/cgu` `/confidentialite` `/mentions-legales` `/regulement-jeu`
- [ ] **Pentest externe** (budget réaliste 5-7j ~6-9k€ — voir P2-7 ; ou pentest interne avec tools OSS : `nuclei`, `zap-baseline`, `semgrep` + 1j freelance review = ~1500€ minimal)
- [ ] Crisp ou Plain chat intégré (gratuit) + FAQ Notion publique
- [ ] **MFA TOTP obligatoire pour OWNER dès onboarding** (cf. P0-7)
- [ ] Migration prod : Supabase prod, Vercel prod, Stripe live (KYC validé avant)
- [ ] Onboarding manuel 5 pilotes (call 30min chacun + Loom de support)

### Vérifications fin Sprint
- [ ] Lighthouse `/r/[slug]` ≥ 95
- [ ] Backup DB testé : restore réel sur staging fonctionne
- [ ] Sentry alertes Slack configurées
- [ ] Audit headers : CSP strict, X-Frame-Options, Strict-Transport-Security
- [ ] Test de charge k6 : 100 req/s sur `/spin` tient (Vercel + Upstash rate limit)

**Gate Sprint 5 → LIVE** :
- ✅ 5 pilotes signés (gratuits 2 mois)
- ✅ Docs légaux signés
- ✅ Pentest passé sans CVSS > 7
- ✅ Tu as débuggé 1 problème en moins de 30 min via trace distribuée (validation OTel)

---

## 7. Kill list assumée (déjà appliquée dans ce plan)

| Killed | Raison | Décalé à |
|---|---|---|
| Google MyBusiness OAuth + sync + IA reply | -2 sem délai validation Google API, kill 1 risque conformité de plus | V1.1 |
| Mistral AI assistant rédaction avis | Pas le différenciateur, kill 1 sprint, kill `AiCall` table | V1.2 |
| Multi-membres `OrganizationMember` + invitations | 1 user = 1 org en V1, tue 30% RLS | V1.1 |
| Marque blanche / multi-tenant slug | Espace de nom global suffit | V2 |
| Admin SpinBoost UI complète | SQL console + Stripe dashboard suffisent toi seul | V1.2 |
| PWA `next-pwa` | Personne installe pour scan QR one-shot | jamais |
| 3 apps Vercel + Turborepo + Hono | Overkill solo (cf. P0-5) | jamais (1 app suffit) |
| Inngest | Vercel Cron + CRON_SECRET suffit | jamais |
| FlyerDesigner 3 templates | 1 template paramétrable | V1.1 |
| PostHog + Better Stack + Vercel Analytics + Axiom | Sentry + Axiom seuls (corrélés via OTel) | V1.1 si signal le justifie |
| SMS Brevo | Email-only V1 | V2 |
| Intégration POS Tiller/Lightspeed/Square | Pivot conformité retire l'urgence, V1.1+ | V1.1+ |

---

## 8. Gates GO/NO-GO globaux (decision points)

| Gate | Quand | Critère KILL | Critère PIVOT | Critère CONTINUE |
|---|---|---|---|---|
| **Sprint 0 → 1** | Fin sem 1 | Pas de warm-leads | Pas de pivot validé | Docs légaux en cours + 5 leads |
| **Sprint 2 → 3** | Fin sem 4 | Bug tirage non résolvable | Animation roue rejetée user-test | E2E + audit tirage OK |
| **Sprint 3 → 4** | Fin sem 5 | Onboarding > 15 min même chronométré | UX confuse | < 10 min onboarding |
| **Sprint 4 → 5** | Fin sem 6 | Stripe webhook impossible à stabiliser | Tarification refusée par 3+ leads | Cycle paiement vert |
| **Sprint 5 → LIVE** | Fin sem 8 | Pentest CVSS > 7 non fixable | < 3 pilotes prêts | 5 pilotes signés + docs OK |
| **+30j post-LIVE** | Fin sem 12 | Suspension Google d'un pilote | Conversion trial < 10% | Conversion > 20% + NPS > 50 |
| **+90j post-LIVE** | Fin sem 21 | MRR < 500€ | Churn > 15%/mois | Path vers 50 restos en 6 mois clair |

---

## 9. Risques top-3 solo founder (mitigations)

### Risque 1 — Support client à 20-50 restos quand tu codes seul
**Probabilité** : certain à scale  
**Impact** : tu codes 0 h/jour parce que tu débogues du Stripe pour un resto qui n'arrive pas à login.  
**Mitigation** :
- Crisp + FAQ Notion publique dès J1
- Sentry Replay (10$/mois) pour debug à distance sans appel
- Onboarding ultra-guidé Loom in-app
- Tarif Pro 49€ inclut « 1h setup » → filtre les flemmards
- Refuser pilotes complexes (multi-établissement, custom)

### Risque 2 — Google suspend un pilote
**Probabilité** : faible si pivot conformité, élevée sans pivot  
**Impact** : perte client + bad PR + churn cascade  
**Mitigation** :
- Pivot conformité acté (Option A)
- Monitoring placé : `accounts.locations.list` API check hebdo de suspension status (V1.1)
- Plan de communication pré-écrit : email aux pilotes + post LinkedIn transparent
- Bouclier : règlement de jeu déposé + analyse juridique mensuelle (vendable comme USP Pro)

### Risque 3 — Stripe webhook désync billing
**Probabilité** : moyen sans idempotency  
**Impact** : pilote suspendu à tort = churn immédiat  
**Mitigation** :
- Table `WebhookEvent UNIQUE(provider, eventId)` (P0-3)
- Source de vérité = Stripe API au moment du check, DB = cache uniquement
- Grace period 7j avant suspension
- Alerte Sentry tag billing si écart Stripe ↔ DB détecté
- Test stripe CLI en CI sur chaque PR

---

## 10. Vraie estimation finale

| Sprint | Durée calendaire | Jours-humain effectifs | Coût € |
|---|---|---|---|
| 0 Légal+setup | 5 j | 4 j | 2 000 € (juridique + comptes) |
| 1 Foundation | 5-6 j | 5 j | 50 € (infra) |
| 2 Wheel+Play | 8-10 j | 9 j | 100 € (infra + Claude API) |
| 3 Onboarding+Flyer | 5-6 j | 5 j | 50 € |
| 4 Billing | 5-7 j | 6 j | 100 € |
| 5 Polish+Pilotes | 8-10 j | 9 j | 2 500 € (pentest minimal + finalisation juridique) |
| **TOTAL** | **~6-8 sem** | **~38 j** | **~5 000 € cash dev** |

+ ~5 000 € (SAS + URSSAF + Claude Max + comptable 6 mois)
= **~10 000 € cash total pour MVP livré**

+ 24 000 € opportunity cost (60j × 400€/j si tu valorises ton temps)
= **~34 000 € coût réel projet**

vs 79 000 € plan original = **gain 57%**.

---

## 11. Ce qui n'est PAS dans ce plan (mais qui arrive ensuite)

- **Commercial** : trouver 50 restos. Probablement 30-50% de ton temps post-launch. Plan séparé à écrire avant Sprint 5.
- **V1.1** : Google MyBusiness OAuth + IA reply + multi-user
- **V1.2** : Admin UI complète + intégration POS (Tiller/Lightspeed)
- **V2** : SMS marketing, autres jeux (grattage, slot machine), marque blanche

---

**Fin du plan décomposé. Démarrage Sprint 0 conditionné aux 5 cases du §0.**
