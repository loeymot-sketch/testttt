# Competitor Gap Analysis — FoodKing vs FR/EU Restaurant SaaS Market
**Date :** 2026-05-07
**Auteur :** Claude orchestrateur (audit stratégique)
**Disclaimer :** ⚠️ Cette analyse est basée sur ma connaissance générale du marché restaurant SaaS FR au moment de mon training. **Pricing exact, intégrations récentes, partenariats actuels doivent être validés** par un benchmark commercial à jour avant tout positionnement marketing public ou pitch sales.

---

## 1. Méthodologie

Plutôt que d'inventer des stats compétitives fragiles (prix, listes intégrations exactes), je décompose le marché par **catégorie de fonctionnalité standard** que les restaurants attendent d'une solution SaaS moderne en 2026, et je situe **FoodKing** dans chacune.

Cible : segment **fast-food / QSR France**, restaurants comptoir-service, multi-établissement.

---

## 2. Acteurs FR/EU pertinents (référencés sans assertion de stats)

Au moment de l'écriture, le segment FR QSR voit régulièrement opérer :

- **Lightspeed Restaurant** — héritage iKentoo, présence FR forte, ecosystem large
- **Tiller** — POS iPad-first racheté par SumUp, populaire FR
- **Cashpad** — POS iPad pure SaaS, NF525 native FR
- **Innovorder** — spécialiste kiosk + QSR, leader kiosk FR
- **L'Addition** — POS established 30k+ restaurants, accounting integration deep
- **Zelty** — focus QSR, livraison, multi-établissement
- **Square Restaurants** — international présent FR, écosystème paiement Square
- **Toast** — leader US qui étend en EU progressivement
- **Pi Electronique / Restoflash / Sequoia** — niches FR

Niche QSR fast-food spécifique : Innovorder + Zelty + Lightspeed L-Series ressortent.

---

## 3. Catégorisation des fonctionnalités du marché QSR moderne

> Légende : 🟢 Mature standard / 🟡 Premium ou émergent / 🔴 Différenciation

### Cluster A — Encaissement core (must-have)

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| A1 | POS comptoir rapide | 🟢 Universel | 🟢 | Sous condition F-006 idempotency POS |
| A2 | Multi-paiement (Cash / Card / Mobile banking) | 🟢 Universel | 🟢 | Cash + Card + Mobile + Other |
| A3 | Apple Pay / Google Pay natif | 🟢 Standard 2024+ | 🔴 | Manquant — requiert Stripe Payment Intents server-side |
| A4 | Ticket Restaurant (TR) agréé | 🟢 Pré-requis FR | 🟡 | `PaymentGateway::TICKET_RESTAURANT` enum présent — agrément CRT à valider |
| A5 | Split bill / partage addition | 🟢 Universel | 🟡 | `SplitPaymentEndToEndTest` existe — UI à valider |
| A6 | Tip management | 🟢 Standard | 🔴 | Absent |
| A7 | Gift cards | 🟢 Premium | 🔴 | Absent |
| A8 | Cash reconciliation (open/close session) | 🟢 Standard | 🔴 → 🟡 (post F-003) | Décision F-003 = Option A va combler |
| A9 | NF525 fiscal compliance FR | 🟢 Pré-requis FR | 🟢 (post F-001) | Audit chain HMAC + Z report — différenciateur si bien fait |
| A10 | Refund flow conforme NF525 (counter-entry) | 🟢 Pré-requis FR | 🟢 | `RefundWithCounterEntryService` confirmé |

### Cluster B — Self-service & Multi-canaux client

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| B1 | Kiosk self-order multi-langue | 🟢 Universel QSR | 🟢 | V1.x prod-ready — différenciation possible si UX premium |
| B2 | Web ordering channel white-label | 🟢 Universel | 🔴 | Backend API ready (27 controllers), frontend manquant |
| B3 | App mobile customer | 🟢 Standard premium | 🔴 | Critique pour QSR jeune |
| B4 | Click & Collect dédié (workflow + slots) | 🟢 Universel | 🟡 | Takeaway générique — pas C&C spécifique |
| B5 | Drive-thru workflow | 🟡 QSR spécifique | 🔴 | Manquant |
| B6 | Reservations / table booking | 🟢 Standard tablerie | 🔴 | V1 dine-in désactivé |
| B7 | Push notifications client (FCM) | 🟢 Standard | 🟡 | Doc présente, deploy non vérifié, endpoint register manquant |

### Cluster C — Cuisine & Opérations

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| C1 | KDS (Kitchen Display) | 🟢 Universel | 🟢 | Basique solide |
| C2 | KDS priorisation AI / temps cuisson dynamique | 🟡 Émergent premium | 🔴 | Différenciation possible |
| C3 | OSS / Order Status Screen | 🟢 Universel | 🟢 | OK |
| C4 | Recipe + cost management (food cost) | 🟢 Premium | 🔴 | Manquant |
| C5 | Inventory + stock tracking | 🟢 Universel | 🔴 | "v2" dans `BUSINESS_RULES.md` |
| C6 | Allergens labels (INCO compliance FR) | 🟢 Pré-requis FR | 🟢 | `OrderItemAllergenSnapshot` fiscal-frozen ✓ |
| C7 | Menu engineering (heatmap performance plats) | 🟡 Premium | 🟡 | Reports basiques, pas heatmap |

### Cluster D — Delivery & Marketplace

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| D1 | Uber Eats integration native | 🟢 Quasi-universel QSR | 🔴 | "Frozen V2" |
| D2 | Deliveroo integration native | 🟢 Idem | 🔴 | Frozen |
| D3 | Just Eat integration native | 🟢 Idem | 🔴 | Frozen |
| D4 | Stuart / livreurs internes | 🟡 Premium | 🔴 | Frozen |
| D5 | Aggregator middleware (Deliverect, Otter) | 🟡 Premium | 🔴 | Manquant |
| D6 | GPS livreur tracking | 🟡 Premium | 🔴 | Frozen |

### Cluster E — Multi-établissement & SaaS readiness

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| E1 | Multi-branche dashboard unifié | 🟢 Universel | 🟢 | Multi-branche OK |
| E2 | Cross-store transfer (stock, items, comparaisons) | 🟢 Standard | 🟡 | Multi-branche OK, transfer absent |
| E3 | Multi-tenant true SaaS (1 plateforme, N restos) | 🟢 Pré-requis SaaS | 🔴 | Mono-tenant actuel, V2 vision |
| E4 | Onboarding self-service (signup → setup → live <30 min) | 🟢 Pré-requis SaaS | 🔴 | Inexistant |
| E5 | Stripe SaaS billing par tenant | 🟢 Pré-requis SaaS | 🔴 | Inexistant |
| E6 | Subdomain routing | 🟢 Pré-requis SaaS | 🔴 | Inexistant |
| E7 | Tenant-isolation tests automatisés | 🟢 Critique SaaS | 🟡 | BranchIsolationTest existe — à étendre tenant level |

### Cluster F — Staff & RH

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| F1 | Time clock (pointage) | 🟢 Standard | 🔴 | Manquant |
| F2 | Scheduling (planning) | 🟢 Standard | 🔴 | Manquant |
| F3 | Payroll prep export | 🟡 Premium | 🔴 | Manquant |
| F4 | Permissions fines staff | 🟢 Standard | 🟢 | Spatie permissions complets |
| F5 | Multi-poste session (caissier change de POS) | 🟡 Standard | 🟡 | Sanctum tokens device-bound — à confirmer multi-poste |

### Cluster G — Marketing & Customer Success

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| G1 | Loyalty programme basique (points, codes) | 🟢 Universel | 🟢 | `loyalty_points`, `loyalty_code`, redeem flow |
| G2 | Loyalty tier-based (Bronze/Silver/Gold) | 🟡 Premium | 🔴 | Manquant |
| G3 | Referral programme | 🟡 Premium | 🔴 | Manquant |
| G4 | Marketing automation campagnes (email, push) | 🟡 Premium | 🔴 | Manquant |
| G5 | Customer segments + targeting | 🟡 Premium | 🔴 | Manquant |
| G6 | Reviews / feedback post-commande | 🟢 Standard | 🔴 | Manquant — endpoint POST `/feedback` recommandé |
| G7 | Promos / coupons mécaniques | 🟢 Universel | 🟢 | `Coupon` + `KioskPromo` priorité |

### Cluster H — Reporting & Business Intelligence

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| H1 | Reports sales / items basiques | 🟢 Universel | 🟢 | OK |
| H2 | Dashboard temps réel (CA jour, commandes en cours) | 🟢 Standard | 🟡 | Endpoint `realtime-report` existe partiellement |
| H3 | Heatmap heure × produit | 🟡 Premium | 🔴 | Manquant |
| H4 | Marges réelles par plat (couplé food cost) | 🟡 Premium | 🔴 | Couplé C4 manquant |
| H5 | Customer retention analytics | 🟡 Premium | 🔴 | Manquant |
| H6 | Export comptable (Pennylane, Sage, Cegid) | 🟢 Pré-requis FR | 🔴 | Excel export uniquement |

### Cluster I — Plateforme & Intégrations

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| I1 | Open API publique avec API keys | 🟡 Premium SaaS | 🟡 | API existe, pas exposée publique avec gestion clés |
| I2 | Webhooks customer (events out) | 🟡 Standard SaaS | 🔴 | Manquant — outbox interne mais pas exposé |
| I3 | Marketplace d'intégrations | 🟡 Premium SaaS | 🔴 | Manquant |
| I4 | Stack ouverte (Zapier, Make.com bridges) | 🟡 Standard | 🔴 | Manquant |
| I5 | Multi-currency runtime | 🟡 Pertinent EU | 🟡 | Configurable, pas runtime switching |
| I6 | Multi-langue UI complète | 🟢 Pré-requis EU | 🟡 | i18n présent, couverture à valider |

### Cluster J — Hardware & Edge

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| J1 | TPE EMV-compliant intégration | 🟢 Pré-requis | 🟡 | Bridge `kioskHardware.tpeCharge` agnostique — drivers à plugger |
| J2 | Imprimante ESC/POS | 🟢 Pré-requis | 🟢 | `kioskPrinter.js` + bridge |
| J3 | Tiroir caisse | 🟢 Pré-requis | 🟢 | `openDrawer()` |
| J4 | Lecteur code-barre / QR | 🟡 Standard | 🟡 | `scanQR()`, `readNFC()` doc — non vérifié actif |
| J5 | Offline mode | 🟡 QSR spécifique | 🟡 | Doc kiosk offline mode mentionné — pas vérifié |
| J6 | Drive-thru hardware (écran outdoor, intercom) | 🟡 QSR drive | 🔴 | Manquant |

### Cluster K — IA & Innovation 2025+

| # | Catégorie | Niveau marché | FoodKing | Notes |
|---|---|---|---|---|
| K1 | AI upsell intelligent (kiosk recommandation) | 🟡 Émergent | 🔴 | `KioskUpsellComponent` existe — règle-based, pas AI |
| K2 | Voice ordering (drive-thru, kiosk) | 🟡 Émergent | 🔴 | Différenciation possible |
| K3 | Pricing dynamique (happy hour auto) | 🟡 Premium | 🔴 | Manquant |
| K4 | Chatbot commande (Messenger, WhatsApp) | 🟡 Émergent | 🔴 | Différenciation possible |
| K5 | Computer vision (queue length, customer flow) | 🔴 Bleeding edge | 🔴 | Niche |

---

## 4. Synthèse — Score global FoodKing par cluster

| Cluster | Note FoodKing | Distance vs marché QSR mature |
|---|---|---|
| A — Encaissement core | 7/10 | -2 (manque Apple Pay, Tip, Gift cards, audit cash session post F-003) |
| B — Self-service & Multi-canaux | 4/10 | -4 (kiosk OK, web/mobile/C&C absents) |
| C — Cuisine & Opérations | 5/10 | -3 (KDS OK, mais inventory/recipe absents) |
| D — Delivery & Marketplace | 1/10 | -8 (gros trou, frozen V2) |
| E — Multi-établissement & SaaS | 5/10 | -3 (multi-branche OK, multi-tenant absent) |
| F — Staff & RH | 3/10 | -5 (permissions OK mais pointage absent) |
| G — Marketing & Customer Success | 4/10 | -4 (loyalty basique, reviews/automation absents) |
| H — Reporting & BI | 5/10 | -3 (basic OK, premium absent) |
| I — Plateforme & Intégrations | 3/10 | -5 (API existe, pas ouverte) |
| J — Hardware & Edge | 7/10 | -1 (bridge agnostique solide) |
| K — IA & Innovation | 1/10 | -3 (rien encore) |

**Score global : ~45 / 110 (≈ 41%)**.

→ **Lecture honnête** : FoodKing a un noyau (POS + kiosk + KDS + NF525) **solide à très solide** sur la qualité technique. Mais l'**étendue fonctionnelle** est en-dessous d'un standard QSR moderne complet. Le produit est un **MVP mature techniquement** mais **pas encore feature-complete** pour rivaliser frontalement sur le segment.

---

## 5. Stratégie de différenciation recommandée

Plutôt que tenter de combler les 65% de gap fonctionnel face à un Lightspeed/Innovorder en 12 mois (irréaliste), **différencier sur ce qui est déjà solide** :

### 5.1 Avantages compétitifs uniques de FoodKing

1. **NF525 audit chain HMAC immutable** — peu de concurrents communiquent sur ce niveau de fiabilité fiscale.
2. **Architecture event-driven outbox** — résilience supérieure aux solutions iPad-only.
3. **Stack ouverte Laravel + Vue** — extensible, pas de lock-in propriétaire.
4. **Bridge hardware agnostique** (`kioskHardware`) — peut adopter n'importe quel TPE/imprimante future.
5. **Kiosk wizard validé visuellement et UX-validated par owner** — différenciation UX bien rôdée.

### 5.2 Positionnement marché suggéré

> **"FoodKing — la caisse française fast-food / kiosk avec audit chain bancaire-grade et architecture ouverte"**

Cible primaire : **fast-food indépendants 1-5 établissements** qui veulent :
- Conformité NF525 sans friction
- Kiosk + POS + KDS intégrés (pas iPad addon disjoint)
- Architecture extensible (pas de lock-in)
- Pricing accessible vs Lightspeed/Innovorder premium

Pas la cible : chaînes >50 restos (Toast/Lightspeed Enterprise gagnent), restaurants gastronomiques (Cashpad/L'Addition gagnent).

### 5.3 Quick wins différenciation (Phase 1-2)

1. **Apple Pay / Google Pay native** — combler A3, alignement standard 2024+.
2. **App mobile customer minimum viable** — combler B3, ouvre marché QSR jeune.
3. **1 delivery integration native** (Uber Eats prioritaire FR QSR) — combler D1.
4. **Multi-tenant true SaaS** — combler E3-E7, déverouille business B2B.
5. **Stripe SaaS billing onboarding self-service** — combler E4-E5, accélère go-to-market.

---

## 6. Risques compétitifs spécifiques

| Risque | Mitigation |
|---|---|
| Innovorder réplique le pattern audit chain HMAC | Communiquer fort + breveter approche si possible |
| Toast EU lance offre fast-food agressive | Positionner sur compliance FR profonde + cost optimisé EU |
| Tiller/SumUp pousse les kiosks à prix cassé | Différencier par customisation kiosk + bridge hardware ouvert |
| Lightspeed acquiert un acteur FR QSR | Vendre vite sur le segment indépendant 1-5 restos avant fenêtre |
| Cashpad ajoute un kiosk natif | Garder leadership UX kiosk + multi-paiement français |

---

## 7. Feature absent rapide à fermer (low effort, high market signal)

| # | Feature | Effort | Impact marché |
|---|---|---|---|
| 1 | Tip management UI POS | 3-5 j | High visibilité service-resto |
| 2 | Apple Pay / Google Pay (Stripe Payment Intents) | 5-7 j | Standard 2024+ |
| 3 | Customer feedback endpoint + notification post-commande | 3-5 j | Boucle CRM |
| 4 | Reviews public restaurant page | 5-7 j | Signal social |
| 5 | Click & Collect dédié avec slots horaires | 5-7 j | Signal QSR moderne |
| 6 | Pennylane export (1 connector FR) | 5-7 j | Compliance FR |
| 7 | Multi-langue runtime UI POS (FR/EN/ES) | 7-10 j | Marché EU |
| 8 | Webhooks customer (events out) | 5-7 j | API maturity signal |

**Total : 38-55 jours-agent — rapproche significativement du standard sans gros refactor.**

---

## 8. Conclusion

FoodKing est **bien positionné techniquement** mais **incomplet fonctionnellement** pour le marché QSR FR mature. La différenciation ne peut **pas** se faire sur "tout faire mieux que Lightspeed" — irréaliste. Elle se fait sur :

1. **Profondeur compliance FR** (NF525, allergens, fiscaux) → cible restaurateurs FR sensibles à l'audit.
2. **Architecture ouverte** → cible restaurateurs voulant éviter le lock-in.
3. **Cost-effective full-stack** (POS+kiosk+KDS+OSS intégrés) → cible 1-5 restos qui ne veulent pas mixer 4 fournisseurs.

Le travail prioritaire est **fermeture sélective des gaps marché-standard** (clusters A, B, E) **avant** de viser des features de différenciation premium (clusters K).

— *Honest gap analysis. Validate competitor specifics before sales pitch.*
