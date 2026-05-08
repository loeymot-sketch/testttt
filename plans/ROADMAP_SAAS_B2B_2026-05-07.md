# Roadmap SaaS B2B FoodKing — Phases 0 → 6 sur 24 mois
**Date :** 2026-05-07
**Auteur :** Claude orchestrateur
**Public :** Owner, équipe technique, futur board / investisseurs
**Companion :** [`AUDIT_STRATEGIC_VISION_2026-05-07.md`](plans/AUDIT_STRATEGIC_VISION_2026-05-07.md), [`COMPETITOR_GAP_ANALYSIS_2026-05-07.md`](plans/COMPETITOR_GAP_ANALYSIS_2026-05-07.md)

---

## 0. Logique de phasage

### 0.1 Trois objectifs en compétition

| Objectif | Échéance | Contrainte |
|---|---|---|
| **A. Déployer dans le fast-food owner** | Court (M1-M2) | Doit être stable, conforme NF525, opérationnel |
| **B. Vendre en SaaS B2B premiers pilotes** | Moyen (M5-M9) | Demande multi-tenant + onboarding self-service |
| **C. Scaler en SaaS B2B mass-market** | Long (M12-M24) | Demande feature parity marché QSR + différenciation |

### 0.2 Principe de phasage

> **Stabilize first, then layer**.

Une fonctionnalité ne s'ajoute jamais sur une fondation instable. Le multi-tenant ne se construit pas avant que le mono-tenant soit propre. Le mobile customer n'arrive pas avant que la surface API soit complète et l'auth-customer mature.

### 0.3 Parallélisation autorisée

Chaque phase a un **agent owner** et des **dependencies**. Tant que les dependencies sont satisfaites et que les zones de code ne se chevauchent pas, plusieurs phases peuvent avancer en parallèle (avec resources humaines / sub-agents Claude suffisants).

---

## 1. PHASE 0 — STABILIZE (M1 à M2)

### 1.1 Objectif

Préparer le produit pour le **déploiement fast-food owner**. Zéro feature nouvelle. Audit clos. Doc à jour. Monitoring en place.

### 1.2 Sub-tasks

| # | Item | Effort | Owner | Dépend de |
|---|---|---|---|---|
| 0.1 | F-001..F-014 audit tactique (cf. `HANDOFF_TO_EXECUTOR_2026-05-07.md`) | 14 j | Exécuteur Opus | — |
| 0.2 | F-015 production blocker queue config | 1 j | Exécuteur | — |
| 0.3 | Doc REALTIME_SETUP.md corrigée (sync est PAS suffisant) | 0.5 j | Exécuteur | F-015 |
| 0.4 | Health check `HealthController::ready` étendu (queue worker actif) | 1 j | Exécuteur | F-015 |
| 0.5 | Monitoring outbox stale detection (alerte si dispatched_at NULL > 30s) | 1 j | Exécuteur | 0.4 |
| 0.6 | Tests E2E Playwright golden path POS + kiosk + KDS sync | 3 j | QA agent | 0.1-0.5 |
| 0.7 | Deploy script + rollback procedure documentés | 2 j | DevOps | 0.5 |
| 0.8 | Backup automatique DB + retention 30j | 1 j | DevOps | 0.7 |
| 0.9 | Hardware partnerships initiated (Ingenico, Verifone, Epson, Star) | parallel ongoing | Owner | — |

### 1.3 Critères de sortie Phase 0

- [ ] Tous les F-001..F-015 verts.
- [ ] `docs/REALTIME_SETUP.md` corrigée + reviewed.
- [ ] Health check OK + monitoring actif.
- [ ] Suite Playwright e2e verte.
- [ ] Backup automatique tourné 1 nuit OK.
- [ ] Owner peut déployer dans son fast-food avec confiance.

### 1.4 Total Phase 0 : ~25 jours-agent (1.5 dev fulltime sur 4 semaines, ou 1 dev + 1 Claude exécuteur en parallèle).

---

## 2. PHASE 1 — SAAS FOUNDATION (M3 à M5)

### 2.1 Objectif

Refactoriser la base pour devenir un vrai produit SaaS multi-tenant. **Aucune feature client visible** ne change. La phase est invisible pour les utilisateurs finaux.

### 2.2 Décision technique préalable (gate owner)

| Option | Effort | Risque | Recommandé ? |
|---|---|---|---|
| Single DB + tenant_id | 15-20 j | Élevé (data leak inter-tenant si scope bug) | ❌ |
| Tenant-per-Schema MySQL (1 schéma par tenant, code unique) | 25-35 j | Moyen | ✅ Recommandé |
| Tenant-per-DB (1 DB physique par tenant) | 30-48 j | Faible (isolation max) | ✅ Si capital dispo |

**Recommandation Claude orchestrateur : Tenant-per-Schema MySQL**. Compromis optimal.

### 2.3 Sub-tasks (sous hypothèse Tenant-per-Schema)

| # | Item | Effort | Dépend de |
|---|---|---|---|
| 1.1 | Schema `saas_root.tenants` + Tenant model | 2 j | Phase 0 close |
| 1.2 | Tenant resolver middleware (subdomain → tenant_id → DB connection switching) | 3 j | 1.1 |
| 1.3 | Refactor `BranchScope` → `TenantBranchScope` | 5 j | 1.2 |
| 1.4 | Sanctum auth tenant-aware (token scoped tenant) | 3 j | 1.2 |
| 1.5 | Subdomain routing setup (DNS wildcard + Nginx config) | 1 j | 1.2 |
| 1.6 | Onboarding flow self-service | 7 j | 1.1-1.5 |
| 1.7 | Stripe SaaS billing intégration | 7 j | 1.6 |
| 1.8 | Tests tenant isolation exhaustifs | 5 j | 1.3 |
| 1.9 | Migration data existante (1 tenant initial owner) | 2 j | 1.3-1.5 |
| 1.10 | RBAC tenant-aware (super-admin SaaS plateforme + admin tenant + staff) | 3 j | 1.4 |
| 1.11 | Doc `MULTI_TENANT_OPERATIONS.md` | 1 j | 1.7 |

### 2.4 Total Phase 1 : ~39 jours-agent (~2 mois avec 1 dev fulltime)

### 2.5 Critères de sortie Phase 1

- [ ] Tenant 1 (owner) tourne sur la nouvelle architecture.
- [ ] Tenant 2 (test) créé en self-service via UI signup.
- [ ] Tests tenant-isolation exhaustifs verts.
- [ ] Stripe SaaS billing facture le tenant test.
- [ ] Subdomain `tenantX.foodking.fr` route correctement.
- [ ] Aucun data leak inter-tenant détectable.

---

## 3. PHASE 2 — CUSTOMER REACH (M4 à M7, parallèle Phase 1)

### 3.1 Objectif

Étendre le produit aux canaux **client final** (mobile + web).

### 3.2 Sub-tasks

| # | Item | Effort | Dépend de |
|---|---|---|---|
| 2.1 | Endpoints API extension (FCM register, payment intent, feedback, order timeline) | 3 j | — |
| 2.2 | Stripe Payment Intents server-side (Apple Pay / Google Pay backbone) | 5 j | 2.1 |
| 2.3 | Mobile customer app — choice tech : Flutter vs React Native vs PWA Capacitor | 0.5 j décision | 2.1 |
| 2.4 | Mobile customer app — implémentation MVP (auth + browse menu + order + tracking + loyalty) | 35-45 j | 2.3 |
| 2.5 | Web ordering channel white-label (Vue 3 ou Next.js) | 25-30 j | 2.1 |
| 2.6 | Multi-langue runtime UI (FR/EN) | 5 j | — |
| 2.7 | Tests E2E mobile (Detox / Appium ou équivalent) | 10 j | 2.4 |

### 3.3 Total Phase 2 : ~85-100 jours-agent — typiquement 1 dev frontend mobile + 1 dev frontend web 3-4 mois en parallèle

### 3.4 Critères de sortie Phase 2

- [ ] App mobile customer publiée sur stores (Apple App Store, Google Play).
- [ ] Web ordering accessible URL `tenantX.foodking.fr/order`.
- [ ] Apple Pay + Google Pay fonctionnels (mobile + web).
- [ ] Tracking commande temps réel (push FCM ou polling fallback).

---

## 4. PHASE 3 — DELIVERY + INVENTORY (M6 à M9, parallèle Phases 1-2)

### 4.1 Objectif

Combler 2 gaps marché-standard critiques pour QSR : delivery + stock.

### 4.2 Sub-tasks

| # | Item | Effort | Dépend de |
|---|---|---|---|
| 3.1 | Delivery integration Uber Eats native (Eats API) | 10-15 j | Phase 1 close (multi-tenant onboarding) |
| 3.2 | Delivery integration Deliveroo (en option, après Uber Eats validé) | 7-10 j | 3.1 |
| 3.3 | Aggregator middleware (Deliverect ou Otter) en alternative | 5-7 j | — |
| 3.4 | Inventory + stock V1 (track item.stock_quantity, low alerts) | 15-20 j | Phase 0 close |
| 3.5 | Click & Collect dédié avec slots horaires | 5-7 j | — |
| 3.6 | Drive-thru workflow (UI + KDS prio drive vs comptoir) | 10-15 j | — |

### 4.3 Total Phase 3 : ~52-74 jours-agent

### 4.4 Critères de sortie Phase 3

- [ ] Une commande Uber Eats arrive dans le KDS comme une commande POS native.
- [ ] Un item à stock 0 est rejeté à la commande (kiosk + POS + web).
- [ ] Click & Collect avec créneau horaire fonctionnel UI + backend.
- [ ] Drive-thru workflow opérationnel pour 1 fast-food pilote.

---

## 5. PHASE 4 — OPS PREMIUM (M9 à M12)

### 5.1 Objectif

Ajouter les fonctionnalités opérationnelles attendues par les restaurants moyens-grands.

### 5.2 Sub-tasks

| # | Item | Effort | Dépend de |
|---|---|---|---|
| 4.1 | Mobile admin app (consultation + actions critiques) | 25-35 j | Phase 2 close |
| 4.2 | Staff management : time clock + scheduling | 20-25 j | — |
| 4.3 | Recipe + cost management | 15-20 j | Phase 3 (inventory) close |
| 4.4 | Accounting integration Pennylane (1 connector FR) | 7-10 j | — |
| 4.5 | Accounting integration Cegid ou Sage (2e connector FR) | 7-10 j | 4.4 |
| 4.6 | Reports avancés : heatmap heure × produit, marges réelles | 10-12 j | 4.3 |

### 5.3 Total Phase 4 : ~84-112 jours-agent

### 5.4 Critères de sortie Phase 4

- [ ] App mobile admin publiée stores.
- [ ] Time clock + planning utilisable par 1 manager.
- [ ] Recipe cost calculé sur 80% du menu.
- [ ] Pennylane export fonctionnel mensuel.
- [ ] Reports heatmap accessibles dashboard.

---

## 6. PHASE 5 — MARKETING + DIFFÉRENCIATION (M12 à M18)

### 6.1 Objectif

Différenciation marché par les fonctionnalités premium attendues sur SaaS QSR moderne.

### 6.2 Sub-tasks

| # | Item | Effort | Dépend de |
|---|---|---|---|
| 5.1 | Marketing automation (campagnes email/push, segments) | 20-25 j | Phase 2 (mobile customer) |
| 5.2 | Loyalty avancée tier-based + referral programme | 10-15 j | Phase 4 close |
| 5.3 | Gift cards | 5-7 j | — |
| 5.4 | Tip management UI POS + kiosk | 3-5 j | — |
| 5.5 | Reviews / feedback post-commande + page publique resto | 5-7 j | — |
| 5.6 | Open API public + webhooks customer + API keys management | 15-20 j | Phase 1 close |
| 5.7 | Marketplace d'intégrations (Zapier, Make.com) | 10 j | 5.6 |

### 6.3 Total Phase 5 : ~68-89 jours-agent

### 6.4 Critères de sortie Phase 5

- [ ] Première campagne marketing automation envoyée (push + email).
- [ ] Tier loyalty actif.
- [ ] API publique documentée + 1 intégration partenaire validée.

---

## 7. PHASE 6 — IA + INNOVATION (M18 à M24)

### 7.1 Objectif

Différenciation premium par l'IA et innovations émergentes 2025+.

### 7.2 Sub-tasks

| # | Item | Effort | Dépend de |
|---|---|---|---|
| 6.1 | AI upsell intelligent kiosk (recommandation contextualisée) | 15-25 j | Phase 5 (data marketing) close |
| 6.2 | Voice ordering kiosk + drive-thru | 25-35 j | Hardware drive |
| 6.3 | Pricing dynamique (happy hour auto + règles) | 10 j | — |
| 6.4 | Chatbot commande (Messenger, WhatsApp) | 15-20 j | — |
| 6.5 | KDS priorisation AI (temps cuisson dynamique) | 15-20 j | — |
| 6.6 | Computer vision queue length (optionnel R&D) | R&D | — |

### 7.3 Total Phase 6 : ~80-110 jours-agent

---

## 8. RÉCAP TOTAL EFFORT

| Phase | Période | Effort total |
|---|---|---|
| 0 — Stabilize | M1-M2 | ~25 j |
| 1 — SaaS Foundation | M3-M5 | ~39 j |
| 2 — Customer Reach | M4-M7 (parallèle) | ~85-100 j |
| 3 — Delivery + Inventory | M6-M9 (parallèle) | ~52-74 j |
| 4 — Ops Premium | M9-M12 | ~84-112 j |
| 5 — Marketing + Différenciation | M12-M18 | ~68-89 j |
| 6 — IA + Innovation | M18-M24 | ~80-110 j |
| **TOTAL 24 mois** | — | **~433-549 jours-agent** |

→ Avec **3 développeurs full-time + 1 Claude orchestrateur + 1 Claude exécuteur Opus**, faisable en 18-24 mois sur le rythme normal.

→ Avec **2 développeurs + Claude duo**, plus proche de 24-30 mois.

→ Avec **1 développeur + Claude duo (modèle solo founder)**, 30-36 mois — possible si focus exécution rigoureux.

---

## 9. DÉPENDANCES CRITIQUES (graph résumé)

```
Phase 0 ────► Phase 1 ────► Phase 3 ────► Phase 4 ────► Phase 5 ────► Phase 6
                │                │             │             │
                └────► Phase 2 ──┘             │             │
                          │                    │             │
                          └────────────────────┴─────────────┘
                                  
Phase 0 = blocker absolu (toutes les phases dépendent)
Phase 1 = blocker SaaS (Phase 3, 4, 5, 6 dépendent)
Phase 2 = parallèle Phase 1, débloque mobile-related (Phase 5 marketing)
Phase 3, 4, 5, 6 = peuvent partiellement chevaucher avec planning soigneux
```

---

## 10. JALON FINANCIER & GO-TO-MARKET

| Phase | Risque cash | Revenue potentiel |
|---|---|---|
| 0 | Investissement pur | 0 (deploy own resto = save fees concurrents) |
| 1 | Investissement pur | 0 (refactor invisible) |
| 2 | Investissement | Possible Web sale own resto |
| 3 | Investissement + 1ers pilotes SaaS payants (5 restos cible @ ~150€/mois = 9k€/an) | ~10k€ ARR fin Phase 3 |
| 4 | Investissement + scaling pilote (15-20 restos @ 200€/mois = ~40k€ ARR) | ~40k€ ARR fin Phase 4 |
| 5 | Pricing tier Premium activé | ~80-150k€ ARR fin Phase 5 |
| 6 | Différenciation premium | ~200-400k€ ARR cible 24 mois |

> ⚠️ **Caveat** : ces nombres sont indicatifs basés sur hypothèses standards SaaS B2B FR niche QSR (50-200€/mois/resto, churn 10-20%/an, CAC 600-1500€). Pas une projection garantie. À valider avec un BP commercial.

---

## 11. POINTS DE GATES DÉCISIONNELS

| Gate | Période | Décision owner |
|---|---|---|
| G0 | Fin Phase 0 | Go/No-Go déploiement fast-food owner |
| G1 | Fin Phase 1 | Tenant-per-Schema validé, lancer pilote SaaS ? |
| G2 | Fin Phase 2 | Mobile customer publié, lancer marketing acquisition ? |
| G3 | Fin Phase 3 | Delivery integrations OK, scaler à 20 pilotes ? |
| G4 | Fin Phase 4 | Ops premium en place, ouvrir aux chaînes 5-15 restos ? |
| G5 | Fin Phase 5 | API publique active, partenariats marketplace ouvrir ? |
| G6 | Fin Phase 6 | IA différenciation, push pricing tier Enterprise ? |

À chaque gate : retour utilisateurs, métriques retention, NPS, CAC/LTV. Décision pivot ou continue.

---

## 12. CONCLUSION

FoodKing a une **fondation technique solide** mais a besoin de **24 mois disciplinés** pour devenir un SaaS B2B QSR FR compétitif. La trajectoire :

- **M1-M2** : stabiliser pour deploy own resto.
- **M3-M9** : foundation SaaS + customer reach + delivery (premières recettes).
- **M9-M18** : combler gap fonctionnel marché.
- **M18-M24** : différenciation IA + innovation.

Avec **rigueur d'exécution** (GSTACK pipeline, audit chain HMAC, tests systématiques) et **patience stratégique** (pas de raccourcis multi-tenant), le produit peut atteindre 200-400k€ ARR en 24 mois et viser une série A ou exit stratégique vers M30-M36.

**La discipline prime la vélocité. Les fondations priment les features. La compliance prime le marketing.**

— *Roadmap honnête. Validate market assumptions before raising. Validate technical estimates before commiting.*
