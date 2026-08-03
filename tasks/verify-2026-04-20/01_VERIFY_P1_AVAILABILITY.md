# VERIFY-01 — P1 Stock / disponibilité branche (POS ↔ Kiosk ↔ KDS)

**Date :** 2026-04-20  **Origine :** P1 (commit `b76506ae9`) + finding `F-SYNC-001` (audit 110)
**Priorité :** P0  **Mode :** AUDIT-ONLY (lecture, pas d'édition code)

## 1. Contexte
P1 a introduit `AvailabilityService::assertItemsOrderableForBranch()`, intégré aux chemins commande, ainsi qu'un `pruneUnavailableLines` côté kiosk. Doit être prouvé que **TOUS** les chemins de création / modification d'order (POS, Kiosk, Table QR, FrontendOrderService legacy, table dining, online) appellent le garde, sinon une rupture branche peut encore générer une commande.

## 2. Sources OBLIGATOIRES
- `app/Services/AvailabilityService.php`
- `app/Services/PricingService.php`
- `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`
- Controllers : `app/Http/Controllers/Admin/PosOrderController.php`, `app/Http/Controllers/Frontend/OrderController.php`, `TableOrderController.php`, kiosk controllers
- Front : `resources/js/components/frontend/kiosk/**`, `pruneUnavailableLines` (rechercher), Pusher listener
- Tests : `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php` + tout test mentionnant `assertItemsOrderableForBranch`
- Plan : `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md`
- Doc : `docs/BUSINESS_RULES.md` (section stock — possiblement obsolète)
- Audit : `reports/review/AUDIT_POS_110_SYNC_CROSS_SURFACE_2026-04-19.md`, `..._DATA_*`

## 3. Hypothèses à challenger
- H1 : un chemin legacy (admin order, order edit, reorder, kiosk-event) **n'appelle pas** le garde.
- H2 : la dispo est seulement en mémoire / cache (pas d'invariant DB) → race possible.
- H3 : la prune kiosk se déclenche bien sur **tous** les events (Pusher + polling fallback).
- H4 : `BUSINESS_RULES.md` documente une règle stock incompatible avec le nouveau garde.

## 4. Plan multi-agent
1. **Explore A** (`subagent_type=explore`, thoroughness=very thorough) : énumère **TOUS** les call sites qui créent/mutent `Order` ou `OrderItem` et dit lesquels passent par `AvailabilityService`.
2. **Explore B** (parallèle) : audite côté front (kiosk + POS) toutes les sources d'ajout panier et la propagation de l'event de rupture.
3. **GeneralPurpose** : synthèse contradictoire des deux explores, produit le rapport et la matrice "chemin × garde".
4. Si désaccord entre A et B sur un chemin → relancer un explore ciblé sur ce chemin.

## 5. Vérifications obligatoires (preuve `fichier:ligne` requise)
- [ ] V1 : `assertItemsOrderableForBranch` est appelé dans **chaque** chemin de création (POS store, Frontend store, Kiosk store, Table QR store, reorder).
- [ ] V2 : Le garde lève **422** (ou code documenté) avec payload structuré (item_id, branch_id, raison).
- [ ] V3 : Le garde est appelé **avant** `PricingService::calculateOrder()` et **avant** la persistance.
- [ ] V4 : Aucune transaction DB n'est ouverte avant la vérif (sinon rollback systématique).
- [ ] V5 : Côté Vuex/Pinia kiosk, l'event de rupture purge bien le panier ET désactive l'item dans le menu visible.
- [ ] V6 : Il existe un test E2E (Playwright) ou Feature qui démontre **rupture pendant ajout** → impossible de checkout.
- [ ] V7 : `BUSINESS_RULES.md` mentionne explicitement la règle dispo branche post-P1, sinon noter en FAIL doc.
- [ ] V8 : Les données DB (`item_branches.is_available`, `stock_quantity` si présent) ont les contraintes attendues.
- [ ] V9 : Le throttle / cache de `MenuController::kiosk` (route ajoutée récemment) ne masque pas une indisponibilité fraîche.

## 6. Critères d'acceptation
- **ALL_GREEN** : V1–V9 passent avec preuve.
- **WARN** : 1 chemin legacy non couvert mais documenté + ticket P créé.
- **FAIL** : un chemin de production crée une commande sans garde, ou doc divergente non corrigée.

## 7. Livrables
- `reports/review/VERIFY_01_P1_AVAILABILITY_2026-04-20.md` contenant :
  - matrice chemin × garde
  - liste preuves V1–V9
  - liste findings `F-VERIFY-01-*` avec sévérité
  - cycles P proposés (`P11_*`, `P12_*` …)

## 8. Suite
Reporter les FAIL dans `reports/review/VERIFY_TRACKER_2026-04-20.md` et alimenter `plans/PLAN_POST_VERIFY_2026-04-20.md`.

---

### PROMPT À COLLER
```
Tu es l'orchestrateur d'un cycle d'AUDIT (pas d'édition code).
Lis intégralement: tasks/verify-2026-04-20/01_VERIFY_P1_AVAILABILITY.md
puis applique la procédure §4-§7 sur le repo courant.

CONTRAINTES STRICTES:
- Pas de modification de code applicatif.
- Tu DOIS lancer en parallèle 2 subagents `explore` (thoroughness "very thorough"):
  Subagent A: énumérer tous les call sites Order/OrderItem (PHP) et dire lesquels appellent AvailabilityService::assertItemsOrderableForBranch — réponse en tableau (chemin, fichier, ligne, garde présent oui/non, preuve).
  Subagent B: auditer côté front (kiosk + POS) tous les sites d'ajout panier + propagation events ItemAvailabilityChanged / pruneUnavailableLines.
- Ensuite tu lances 1 subagent `generalPurpose` qui prend les deux rapports et produit la SYNTHESE CONTRADICTOIRE + matrice + findings.
- Si A et B divergent sur un chemin, relance un explore ciblé.

LIVRABLE FINAL: reports/review/VERIFY_01_P1_AVAILABILITY_2026-04-20.md
au format §7. Termine par un encadré "GLOBAL: ALL_GREEN | WARN | FAIL" et la liste des cycles P à créer.

Ne commence pas l'exécution sans m'avoir résumé en 5 lignes ton plan d'attaque (subagents prévus + ordre).
```
