# Orchestration V1 — Vision, plan, gates & spécification du rapport de clôture

**Date** : 2026-04-17  
**Rôle** : document **SSOT** pour piloter les cycles jusqu’à une V1 « validée » ; définit **quand** produire le **rapport complet final** et **ce qu’il doit contenir**.  
**Documents déjà produits** : `AUDIT_MASSIF_FR_2026-04-16.md`, `AUDIT_ALIMENTATIONS_COGNITIVES_V1_GLOBAL_2026-04-17.md`.

---

## 1. Vision (non négociable)

| Pilier | Définition opérationnelle |
|--------|---------------------------|
| **Imbattable (V1)** | Meilleur **rapport complexité / valeur** sur fast-food FR : wizard métier, rupture branch-scoped, idempotency, SSOT prix, temps réel maîtrisé — **sans** promettre l’équivalent Toast/Square sur finance/BI. |
| **Robuste** | Même vérité MySQL partout ; transitions d’état **une seule** machine ; events **après commit** ; file + WS avec dégradation explicite. |
| **Prudent** | Zones gelées (pricing) = **gate humaine** + tests de parité ; pas d’élargissement hors index V1 (2FA, RGPD avancé, etc.). |
| **Validé** | Critères §8.4 audit massif + matrice tests ci-dessous + **rapport de clôture** (Annexe A) **une fois** les cases cochées. |

---

## 2. Orchestration — cycles de travail

Conformité **`AGENTS.md`** + **`.cursor/commands/run-cycle.md`** :

```
PLAN (artefact plans/PLAN_<TASK_ID>_<date>.md)
  → GATE si zone sensible (pricing, schéma channels, prod)
EXECUTE (délégation implémenteur selon routing)
VALIDATE (PHPUnit / Vitest / Playwright selon TEST_STRATEGY du plan)
AUDIT (relecture risques + écart vs invariants)
CLOSE (+ mise à jour matrice tâches + lien vers rapport d’exécution)
```

**Règle d’intelligence** : une PR / un cycle = **un objectif mesurable** (endpoint, event, spec) — pas de mélange « refacto + feature + doc » sauf plan explicite.

---

## 3. Plan par vagues (ordre strict)

| Vague | Tâches (réf. audit massif §6) | But | Prudence |
|-------|-------------------------------|-----|----------|
| **V1** | SYNC_BACKBONE → OUTBOX → EVENT_CONTRACT | Fondations temps réel & contrat | Ne pas skipper tests outbox |
| **V2** | STATUS_MACHINE ∥ puis PRICING_SSOT (**gate**) puis MENU_86 | Intégrité commande + menu/rupture partout | **Gate** pricing ; MENU_86 après contrat d’events stable |
| **V3** | SEC_XSS, SEC_CORS_RATELIMIT | Surface d’attaque | Whitelist CORS documentée |
| **V4** | DATA_SOFTDELETE, OBS_HEALTH_CORR | Données & exploitable | branch_scope + correlation_id jobs |
| **V5** | TEST_PW_5FLOWS, TEST_PRICING_STATE | CI fiable | 10 runs Playwright consécutifs avant « done » |

**Référence détaillée** : matrice §6 + §8 `AUDIT_MASSIF_FR_2026-04-16.md`.  
**Référence flux** : `AUDIT_ALIMENTATIONS_COGNITIVES_V1_GLOBAL_2026-04-17.md`.

---

## 4. Barre « tout est bon » (checklist avant rapport final)

Cocher **toutes** les lignes ci-dessous (équivalent synthétique §8.4 audit massif) :

### 4.1 Fonctionnel

- [ ] POS cash + POS carte + Kiosk + KDS + OSS : parcours complets sans erreur bloquante.
- [ ] Rupture / dispo : changement admin → **< 2 s** perçu borne + **POS abonné** (pas seulement kiosk).
- [ ] Kiosk : boot → idle (vidéo) → commande **sans saisie mot de passe client** ; machine `.env` alignée DB.

### 4.2 Technique

- [ ] 0 calcul prix hors `PricingService` (grep CI ou équivalent documenté).
- [ ] 0 transition `OrderStatus` hors `OrderStateMachine` (grep CI).
- [ ] Outbox / events : scénarios critiques documentés + tests verts.
- [ ] `ItemAvailabilityChanged` + menu projection : chemins testés (Feature + où pertinent E2E).

### 4.3 Sécurité & ops

- [ ] 0 `v-html` non conforme à la politique projet.
- [ ] CORS non `*` en prod ; rate limits sur routes mutables sensibles.
- [ ] `/api/health/*` + logs JSON + `correlation_id` sur chemins critiques.

### 4.4 Qualification continue

- [ ] PHPUnit : suites ciblées V1 vertes en CI.
- [ ] Playwright : **5 flows** + **10 exécutions consécutives** sans flake.
- [ ] Couverture minimale : `PricingService` / `OrderStateMachine` selon objectifs audit (100% / 95% branches où applicable).

### 4.5 Documentation livrée

- [ ] `EVENT_CONTRACT.md`, `MENU_AVAILABILITY.md`, `RATE_LIMITS_MATRIX.md`, `SECURITY_NOTES.md`, `KIOSK_DEPLOYMENT.md` à jour pour l’état **réel** post-V1.

---

## 5. Audits « massifs » à maintenir (pas un seul doc figé)

| Audit | Rôle | Quand mettre à jour |
|-------|------|---------------------|
| `AUDIT_MASSIF_FR_2026-04-16.md` | Référence produit / concurrence / 12 tâches | Après chaque vague majeure : §6 état |
| `AUDIT_ALIMENTATIONS_COGNITIVES_V1_GLOBAL_2026-04-17.md` | Carte flux & UX | Après MENU_86 / sync |
| `reports/execution/*` par TASK_ID | Preuve d’exécution | À chaque CLOSE |
| `reports/review/*` | Verdict | Après AUDIT |

---

## 6. Implémentations prioritaires (intelligence = ordre + tests)

Ordre **recommandé** pour maximiser la robustesse sans rework :

1. **EVENT_CONTRACT** + nettoyage enum / payloads (évite bugs subtils multi-surfaces).
2. **OUTBOX** (scénario G, observer, idempotency job).
3. **SYNC_BACKBONE** (reconnexion, bannières, doc prod).
4. **STATUS_MACHINE** (grep + `apply()` partout où il reste du legacy).
5. **PRICING_SSOT** (**gate** + parité POS/Kiosk tests).
6. **MENU_86** (API `item_branch_availability` + `channels` + UI rupture + abonnements POS).
7. **SEC_*** puis **OBS_*** puis **TEST_***.

Chaque item : **plan** avec fichiers touchés + **3 tests minimum** (happy, edge, régression) sauf justification explicite dans le plan.

---

## Annexe A — Spécification du **rapport complet de clôture V1**

**Condition de déclenchement** : checklist §4 entièrement cochée + **tag `v1.0.0`** approuvé (ou équivalent release interne).

**Emplacement proposé** : `reports/execution/RAPPORT_CLOTURE_V1_COMPLET_<YYYY-MM-DD>.md`

**Structure obligatoire** (couvre « tout » sans doublon inutile) :

1. **Résumé exécutif** (10–15 lignes) : pour qui (ops, dev, investisseur technique), verdict GO/NO-GO production pilote.
2. **Périmètre V1** : in / out explicite (rappel index V1).
3. **Architecture finale** : diagramme ou tableau POS / Kiosk / KDS / OSS / Admin / API / Queue / WS / DB.
4. **Alimentations validées** : renvoi synthétique à `AUDIT_ALIMENTATIONS…` + **delta** (ce qui a changé depuis).
5. **Matrice 12 tâches** : état final DONE/PARTIAL + lien vers derniers `PLAN_*` / `RUN_*`.
6. **Sécurité & conformité** : CORS, XSS, rate limit, auth borne, branches — références `SECURITY_NOTES`, `RATE_LIMITS_MATRIX`.
7. **Performances & fiabilité** : p95 events, queue, WS ; chaos tests effectués (résumé).
8. **Qualité logicielle** : couverture ciblée, liste specs Playwright, résultat 10× runs, dette résiduelle P1/P2.
9. **Exploitation** : healthchecks, logs, correlation_id, runbooks (incident TPE, Pusher down, tiroir).
10. **Annexes** : liens vers tous les rapports d’exécution, audits, gates signés, migration DB notable.

**Règle** : ce rapport est **rédigé une fois** ; les cycles intermédiaires produisent seulement des **rapports d’exécution partiels** (`reports/execution/RUN_*` ou équivalent).

---

## Annexe B — Rappel prudence (anti-dérive)

- Ne pas fusionner **V1.5** (vue commandes unifiée, Ctrl+K, widgets dashboard avancés) dans la même livraison que la **fermeture** checklist §4.
- Toute modification **pricing** ou **schéma DB menu** : **mini-audit** dans le même cycle que le code.
- Si Graphiti / MCP / embeddings : **hors périmètre caisse V1** sauf besoin explicite documenté.

---

*Ce document remplace les répétitions orales « orchestrer / planifier » : il est la référence pour savoir **quoi faire**, **dans quel ordre**, et **quel livrable final** produire.*
