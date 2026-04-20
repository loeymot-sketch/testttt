---
id: POST_HOC_LOCK_P1_STOCK_SYNC_2026-04-20
scope: P1 stock / disponibilité (sync checkout & prune panier kiosk)
date: 2026-04-20
statut: CLOSED post-hoc
sha: b76506ae94a2b898ab094ca42780b0a67eb52dcb
---

# POST-HOC LOCK — P1 stock / availability sync

## Référence commit

| Champ | Valeur |
| --- | --- |
| **SHA** | `b76506ae9` (`b76506ae94a2b898ab094ca42780b0a67eb52dcb`) |
| **Sujet** | `feat(P1): garde checkout rupture branche + prune panier kiosk` |
| **Date auteur** | 2026-04-19 |

## Plan référent

- `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md` (ajouté dans ce commit)

## Périmètre — fichiers touchés (`git show --stat b76506ae9`)

| Fichier | Rôle |
| --- | --- |
| `app/Services/FrontendOrderService.php` | Garde / chemin legacy si SSOT désactivé (per commit message) |
| `app/Services/Menu/AvailabilityService.php` | `assertItemsOrderableForBranch` (lock optionnel selon message commit) |
| `app/Services/OrderService.php` | Alignement legacy avec P1 (per commit message) |
| `app/Services/Pricing/PricingService.php` | **Cœur SSOT** — appel disponibilité avant pricing (`calculateOrder`) |
| `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md` | Documentation handoff |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | Kiosk — wiring événement |
| `resources/js/store/modules/kioskCart.js` | Prune lignes panier après `ItemAvailabilityChanged` |
| `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php` | Couverture feature |

## Justification post-hoc

Commit **déjà mergé sur `main`** avant détection de l’absence de LOCK_A/B dans `tasks/phase9-sync/` ; ce document **régularise la gouvernance Phase 9.5** (cf. audit T19) sans réécrire l’historique Git.

## Rapports liés

- [REPORT_TASK19 — Locks P9 + Frozen Zones](../reports/audit-orchestration/REPORT_TASK19_LOCKS_FROZEN_ZONES_2026-04-20.md)
- [REPORT_TASK20 — Gate prod final](../reports/audit-orchestration/REPORT_TASK20_GATE_PROD_FINAL_2026-04-20.md)

## Risque SSOT (PricingService)

Édition du **cœur** `app/Services/Pricing/PricingService.php` **sans gate LOCK explicite** au moment du merge : tout changement ici peut impacter le calcul serveur unique des paniers / commandes. Un **audit diff manuel** reste **obligatoire** pour toute revue de conformité SSOT.

## Action requise — synthèse `git show b76506ae9 -- app/Services/Pricing/PricingService.php`

Lecture du diff ciblé (read-only) :

1. **`__construct`**
   - Ajout d’une dépendance optionnelle `?AvailabilityService $availabilityService = null`.
   - Résolution à l’exécution via `$this->availabilityService ?? app(AvailabilityService::class)`.
   - **Signatures** : constructeur élargi ; comportement par défaut inchangé pour les appels existants si le conteneur résout `AvailabilityService`.

2. **`calculateOrder(PricingRequest $req, CouponService $couponService): PricingResult`**
   - Après normalisation de `$requestItems` et construction de `$requestedItemIds`, si `branchId > 0` et liste d’IDs non vide :
     - appel `assertItemsOrderableForBranch($req->branchId, $requestedItemIds, $req->orderId > 0)` ;
     - le booléen final encode : prévisualisation (`orderId === 0`) = lecture seule ; commande réelle (`orderId > 0`) = lock sous transaction (commentaire dans le diff).
   - **Aucune modification** des boucles de prix, taxes, remises ou agrégation des montants dans ce diff : uniquement une **barrière d’ordre** avant le `Item::query()->whereIn(...)`.
   - Changements cosmétiques : `use App\Services\Menu\AvailabilityService;`, espacement `! is_array`, `! $dbItem`, etc.

3. **Invariants potentiellement affectés**
   - **Ordre des opérations** : échec plus tôt si article non commandable pour la branche → peut exposer de nouvelles erreurs 422 / exceptions métier **avant** chargement DB des items (comportement voulu pour la garde stock).
   - **Concurrence** : chemin « commande réelle » suppose que `assertItemsOrderableForBranch(..., lock: true)` est cohérent avec les transactions appelantes (à valider avec `AvailabilityService`).

**Alarme calcul TVA / total / discount** : le diff **ne modifie pas** la logique de calcul TVA, totaux ou remises. **Aucun marquage `REQUIRES_HUMAN_REVIEW`** au sens « changement de formule monétaire » ; la **revue humaine reste recommandée** pour la gouvernance SSOT et le comportement du lock disponibilité.
