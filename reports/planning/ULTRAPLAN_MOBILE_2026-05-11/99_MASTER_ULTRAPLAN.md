# 99 — MASTER ULTRAPLAN — Mobile Le Cayenne complete-to-launch

> `/ultraplan` cycle 2026-05-11 · 5 sub-agents YC GStack en parallèle · HEAD `ebb712dd8`
> Branche : `feature/mobile-app-le-cayenne-2026-05-10`

---

## TL;DR — état réel post-Phase 6.A

L'app mobile est **visuellement prête** (Phase 6.A real-assets DONE, AD-N4 fermé) mais **fonctionnellement incomplète** par rapport au kiosque, et **non backed** (V0 standalone mock).

5 agents ont audité 5 dimensions en parallèle :

| Dimension | Findings | P0 | P1 | P2/P3 |
|-----------|----------|----|----|-------|
| **01 — Parcours par catégorie vs kiosk** | 33 + 17 cross-cat | 8 | 15 | 27 |
| **02 — Data drift (mobile vs SSOT + DB)** | 20 | 8 | 7 | 5 |
| **03 — UI parity (allergens / search / instructions / promo / etc.)** | 27 | 4 | 10 | 13 |
| **04 — Couverture E2E (101 states / 60 items)** | ~20% effective | — | — | +134 states needed |
| **05 — Phase 6.B → Launch roadmap** | 5 phases + B-prereq | — | — | 6-8 weeks |
| **TOTAL UNIQUE** (after dedupe) | **~75 findings** | **~15-18** | **~25** | **~35** |

---

## 🔥 Top P0 consolidés (à fermer AVANT toute Phase 6.B)

Cross-agent findings classés par criticité (déduplication appliquée) :

| # | Finding | Source | Sévérité | Fix scope |
|---|---------|--------|----------|-----------|
| **P0-1** | **STEP.PAIN manquant** sur Sandwich Classique items 207/208 (kiosk obligatoire) — kiosk a un step "Type de Pain" (Pain / Galette) que mobile a fusionné en 2 items distincts au lieu d'1 item avec choix | Agent 1 + Agent 2 cross-confirmed | P0 | Refactor data + add ScreenStepPain |
| **P0-2** | **STEP.TAILLE manquant** pour tacos sans `viandes` pre-encodé — kiosk a un step de taille (M/L/XL/XXL) où le user choisit, mobile a 4 items distincts | Agent 1 | P0 | Refactor data + add ScreenStepTaille |
| **P0-3** | **Cat 312 (Salades) et 313 (Wings) ont `has_menu=TRUE` en DB** mais mobile hardcode `false` → casse les offres formule sur 8 items | Agent 2 (DB live) | P0 | 2-line patch in menu.js |
| **P0-4** | **`snacking` template step ordering bug** : mobile fait `sauce → menu → supplements` (`steps.jsx:93-98`), kiosk fait `sauce → menu → frites_style → supplements` (`KW.vue:598-608`) | Agent 1 | P0 | Add frites_style step in snacking template |
| **P0-5** | **`assiette` + `omelette` templates over-engineered** dans mobile — ajoutent crudités+suppléments que kiosk V3.8 ROUND-5 a retiré | Agent 1 | P0 | Remove erroneous steps |
| **P0-6** | **Allergens display ABSENTE partout** (list / item detail / wizard) — kiosk a `KsAllergenBadge` partout — **EU FIC 1169/2011 = obligation légale de disclosure** | Agent 3 | P0 | Add AllergenBadge component everywhere |
| **P0-7** | **Bouton "+" list bypasse wizard** (`screens-main.jsx:212`) — items requirant viandes/sauces atterrissent au panier avec composition vide | Agent 3 | P0 | Quick-add disabled for items with viandes>0 OR has_sauce, opens wizard instead |
| **P0-8** | **Special-instructions textarea absente** sur wizard recap — kiosk a champ `selections.instruction` 190-char (`KW.vue:151-161`) | Agent 3 | P0 | Add textarea in ScreenStepRecap |
| **P0-9** | **Promo code input absent** sur cart — kiosk a `kiosk-cart-promo` flow (`KioskCartComponent.vue:265-284`) | Agent 3 | P0 | Add promo input + API call (V0 = mock) |
| **P0-10** | **DB items 402/403 frites_style extras** — mobile a flag mais render incomplet (15 items affectés cat 310-313) | Agent 2 | P0 | Wire frites_style to omelette/snacking templates |
| **P0-11** | **Le Suprême `viandes=0`** mobile, mais DB attaches viandes → wizard ne demande pas la viande | Agent 2 (standing audit 2026-05-10) | P0 | Set viandes:1 ou aligner avec DB |
| **P0-12** | **Tacos slug mismatch** (`tacos-m` mobile vs `tacos-m-1-viande` DB) — bloque Phase 6.B menu fetch | Agent 2 (standing) | P0 | Slug alignment script |
| **P0-13** | **B-01..B-08 backend backlog** (loyalty regulatory NF525) — bloque Phase 6.D | Agent 5 + loyalty audit 2026-05-10 | P0 | 4-5d agent backend sprint |
| **P0-14** | **Cat IDs fake 1..13 vs DB 306..318** — bloque API wireup | Agent 2 (standing) | P0 | Phase 6.B refactor |
| **P0-15** | **Allergens fabriqués sur 63 items** mobile (default `['gluten','lactose']` hardcodé sans data réelle) — risque sanitaire | Agent 2 (standing) | P0 | Backend allergens table population |

**Note** : P0-11, P0-12, P0-14, P0-15 étaient déjà flagés à l'audit 2026-05-10 mais sont restés ouverts car non-bloquants V0 mock. Avec Phase 6.B (wireup réel), ils deviennent bloquants.

---

## 🛠️ P1 consolidés (~25 findings)

### Parcours / Wizard (Agent 1)
- Le Cayenne (1 viande mobile vs 1 viande + crème fraîche cuisson spec kiosk) — viande customization manquante
- Salades wizard scope D1 owner-gate non finalisé (Agent 1 propose réouverture)
- Wings BBQ/Nashville rejected mais Wings 12 distincts kiosk → mobile a fusionné
- Boissons cat 12 — pas d'option taille (33cl vs 50cl) sur certaines
- Menus enfants taille adapt-frites manquante

### Data drift (Agent 2)
- Sandwich Froid (item 205) viandes:0 mais DB attribute thon → wizard ne demande pas
- Panini variations (5 ingredients possibles) → mobile hardcode 1 choix, DB attache attribut multi-select
- Suppléments mobile a 7 items distinct, DB attache 7 extras per item (drift count)
- Prix `salade-verte` 2€ mobile vs DB 2.50€

### UI parity (Agent 3)
- Search bar non fonctionnelle (UI présente mais aucune logique)
- Sort options (prix asc/desc, popularité) absent
- Filter chips sticky scroll missing → autoscroll non-actif
- Favorites heart icon présent mais non fonctionnel
- Empty state "Pour accompagner ?" cross-sell vide
- Recently viewed absent
- Calories / nutritional info absent (kiosk affiche selon settings)
- Prep time (`item.time`) calculé mais pas affiché item page
- Modify item from cart absent (re-edit selections)
- Notes / instructions per cart line absent

### E2E coverage (Agent 4)
- Cat 13 Suppléments (0/8 items testés)
- Cat 12 Boissons (1/8 testés)
- Tacos viande counts {1,2,3} non vérifiés (seul XXL testé)
- Sans-Sauce exclusivity non testé
- Cart same-composition merge non testé
- Cheddar+Oignons +1,50€ path non testé
- Validation gates : 0 negative-path tests
- A11y automation : 0 axe-core runs

### Roadmap (Agent 5)
- iOS PWA push limitations pré-16.4 → polling 30s fallback
- Apple Pay domain verification 24h lead time
- Refund NF525 implications (frozen-zone touch potentiel)

---

## 🔄 Roadmap consolidée — 6 semaines minimum à 8 semaines réaliste

### Pré-requis URGENT — Cluster fix P0 mobile (avant Phase 6.B)
**Effort : 2-3 jours**
- Fix P0-1 à P0-12 + P0-15 par patches scope-minimal sur `mobile/data/menu.js` + `mobile/screens-item-steps.jsx`
- Add `AllergenBadge` component (P0-6) + wire on menu cards + wizard recap + item detail
- Add `ScreenStepPain` + `ScreenStepTaille` + `ScreenStepInstruction` (P0-1/2/8)
- Add cart promo input UI (P0-9, V0 mock seulement)
- Fix quick-add bypass (P0-7)
- Re-capture 4 waves Playwright → vérifier convergence

### Phase 6.B — Backend Foundation (2 sem)
- Sanctum `mobile:order` ability
- `/menu/customer` endpoint
- Phone OTP signup/verify (Twilio)
- Mobile API client `mobile/api/api.js`
- Branch context resolution
- Mock screens → API calls

### Phase 6.C — Payment + Fiscal (2 sem, parallèle avec B-prereq backend)
- Order POST avec X-Idempotency-Key
- Composition snapshot server-side via PricingService SSOT
- Stripe Payment Element (card + Apple Pay + Google Pay)
- Fiscal sequence allocation channel-agnostic
- Webhook hardening

### Backend B-01..B-08 sprint (parallèle 4-5 jours)
- Loyalty regulatory NF525 (5 P0 + 3 P1)

### Phase 6.D — Loyalty wireup (~2.5 sem, post B-prereq)
- Création model LoyaltyReward + table
- `/loyalty/rewards` + `/loyalty/qr/sign` endpoints
- Mobile API client wireup (6 méthodes)
- Earn ratio config dynamic

### Phase 6.E — PWA shell (~1.5 sem, parallèle 6.D)
- Manifest + Service Worker
- Icons + splash from `cayenne-hero.png`
- Offline cache + stale banner
- RGPD/Privacy/ToS pages

### Phase 6.F — Pre-launch ops (~2 sem)
- KDS notification path validation
- Staff training package + onsite 2h
- Refund + cancel flow
- Customer support flow
- Observability PostHog + Sentry
- Pilot user selection (5 staff → 5 friends → public)

### Phase 6.G — Soft launch (~1 sem)
- Instagram + in-store QR codes
- Daily monitoring
- Feedback collection

**Total réaliste : 6-8 semaines calendar (28 agent-days + ~2 semaines dogfood)**

---

## 📋 Décisions owner à prendre AVANT exécution

| ID | Question | Recommandation par défaut |
|----|----------|---------------------------|
| **D1** | Salades wizard scope — simplifié (sauce + suppléments, owner-gate 2026-05-10) OU complet (kiosk = 5 steps) ? | Aligner sur kiosk = 5 steps (cohérence cross-surface) |
| **D2** | OTP provider — Twilio (€0.05/SMS) OU Vonage (€0.07/SMS) ? | Twilio (volume + français) |
| **D3** | Platform shell — PWA seul (1 sem) OU Capacitor native (3 sem + App Store) ? | PWA pour pilot, Capacitor pour V1.1 |
| **D4** | Refund policy — fiscal counter-entry obligatoire ou cancel before paid only ? | Cancel before paid only V1, refund V1.1 |
| **D5** | NF525 channel — mobile = channel distinct `'mobile_app'` ou agnostic `'order'` ? | Distinct (audit trail + Z-report breakdown) |
| **D6** | Allergens P0-6 — bundler data locale OU bloquer 6.B sur backend allergens table ? | Bundler localement V0, backend en parallèle 6.B |
| **D7** | Souvenir loyalty welcome bonus — V1 ou V1.1 ? | V1.1 (déjà fonctionnel mock) |
| **D8** | Item Le Suprême viandes (P0-11) — accepter mobile (0) ou aligner DB (1) ? | Lire DB d'abord, décider après |

---

## 🎯 Recommandation immédiate

**Avant de toucher Phase 6.B**, fermer le **Cluster P0 mobile** (15 findings ci-dessus, ~2-3 jours agent) :

1. **Sprint Cluster P0 mobile** : data + wizard steps + allergens + quick-add bypass + cart promo
2. **Re-run 4 waves Playwright** → convergence sur AD-N5+ findings
3. **Commit cluster-7** + update BRAIN §3
4. **THEN** : démarrer Phase 6.B avec une base mobile saine vs kiosk

L'alternative (skipper cluster P0 mobile → direct Phase 6.B) bloque le wireup parce que les slugs/cat_ids/has_X flags ne matcheront pas le backend → erreurs 422/409 partout au premier API call.

---

## 📂 Liens vers les 5 rapports sub-agents

- [01_category_parcours.md](01_category_parcours.md) — Agent 1 : 33 findings + 17 cross-cat
- [02_data_drift.md](02_data_drift.md) — Agent 2 : 20 drifts mobile vs DB
- [03_ui_parity.md](03_ui_parity.md) — Agent 3 : 27 missing UI features
- [04_e2e_coverage.md](04_e2e_coverage.md) — Agent 4 : couverture ~20% → +134 states
- [05_phase6_roadmap.md](05_phase6_roadmap.md) — Agent 5 : 5 phases + B-prereq, 6-8 sem

---

## 🚦 Prochaine action (après owner gate sur D1-D8)

**Option A** : Cluster P0 mobile fix (2-3j) → puis Phase 6.B Backend Foundation
**Option B** : Direct Phase 6.B (skip cluster) → risque blocage cascade
**Option C** : Owner gate D1-D8 d'abord, puis décision A/B
