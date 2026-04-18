# SYNC PROTOCOL — Kiosk Phase 9 (Track A) ↔ POS Phase POS (Track B)

**Version.** 2026-04-18
**But.** Permettre à deux conversations Cursor indépendantes de travailler en parallèle sans conflit git, sans décision contradictoire, sans régression croisée.

---

## 1. Principe fondateur

**Deux tracks, deux branches git, synchronisation hebdomadaire sur `main`.**

- Track A (Kiosk) : branche `feat/kiosk-phase-9` et sous-branches `feat/kiosk-phase-9-X`.
- Track B (POS) : branche `feat/pos-phase-9` et sous-branches `feat/pos-phase-9-X`.
- `main` reste intouché jusqu'aux merges validés humainement.

Les deux tracks rebase régulièrement depuis `main` **après** chaque merge validé du track adverse.

## 2. Zones de code exclusives (propriété claire)

### Track A (Kiosk) seul propriétaire

- `resources/js/components/frontend/kiosk/**`
- `resources/js/store/modules/kioskMenu.js`, `kioskCart.js`, `kioskSettings.js`
- `resources/js/composables/useKioskSpeech.js`, autres composables kiosk
- `resources/js/services/kioskHardware.js`, `kioskAnalytics.js`, `kioskOfflineQueue.js`
- `resources/js/services/kioskPricing.js`
- `resources/css/kiosk/**`
- `app/Http/Controllers/Frontend/**` (sauf ceux partagés explicitement)
- `app/Services/FrontendOrderService.php`
- Migrations marquées `kiosk_*` ou touchant exclusivement flows kiosk
- Tests kiosk (`tests/Feature/Kiosk/*`, `tests/js/kiosk/*`, `tests/e2e/kiosk-*`)

### Track B (POS) seul propriétaire

- `resources/js/components/admin/pos/**`
- `resources/js/store/modules/pos*.js`
- `app/Http/Controllers/Admin/Pos/**`, `OrderController` côté admin
- `resources/js/components/admin/kds/**` (KDS admin côté POS)
- `resources/js/components/admin/oss/**` (OSS)
- Migrations `pos_*`, fin de journée, tiroir, audit logs
- Tests POS (`tests/Feature/Pos/*`, `tests/Feature/Order/Admin*`, Playwright pos-*)

### Zone partagée (verrou mutuel strict)

Ces fichiers sont touchés par les deux tracks. **Aucun track ne modifie un fichier partagé sans poser un verrou préalable** (voir §4).

- `app/Services/OrderService.php`
- `app/Services/PricingService.php`
- `app/Services/ItemService.php`, `ItemCategoryService.php`, `ItemAttributeService.php`
- `app/Services/AllergenService.php` (à créer en P9.2)
- `app/Http/Resources/NormalItemResource.php`, `ItemResource.php`, `OrderResource.php`
- `app/Http/Requests/ItemRequest.php`, `ItemCategoryRequest.php`
- `app/Events/*`, `app/Listeners/*`
- `app/Domain/Order/OrderStateMachine.php`
- `app/Http/EventContract.php`
- `app/Models/Order.php`, `OrderItem.php`, `Item.php`, `ItemCategory.php`
- `routes/channels.php`, `routes/api.php`
- Migrations qui touchent des tables partagées (`orders`, `order_items`, `items`, `item_categories`, `allergens`, `item_branch_availability`)
- `docs/ORDER_FLOW.md`, `docs/ARCHITECTURE.md`, `docs/BUSINESS_RULES.md`, `docs/AUTHZ_MATRIX.md`
- `AGENTS.md`, `CLAUDE.md`

## 3. Ordonnancement imposé

```
          ┌─────────────────────────────────────────────────────┐
          │ Track A (Kiosk)        │ Track B (POS)              │
          ├────────────────────────┼────────────────────────────┤
Day 1     │ P9.1 stop-the-bleed    │ POS-A audit lecture seule  │ PARALLÈLE OK
Day 2     │ (merge P9.1 → main)    │ (rapport POS livré)        │ Merge P9.1 d'abord
Day 3     │ P9.2 catalog backend   │ POS-B plan d'exécution     │ PARALLÈLE OK
Day 4     │ P9.3 wizard robustness │ POS-B validé humain        │ P9.3 touche peu de shared
Day 5     │ P9.4 UX hors-wizard    │ POS-9.1 stop-the-bleed     │ PARALLÈLE (POS-9.1 évite backend si possible)
Day 6     │ (merge P9.4)           │ POS-9.1 verrouille backend │ Synchro obligatoire
Day 7     │ P9.5 order pipeline    │ STOP (attendre P9.5 merge) │ SÉQUENTIEL
Day 8     │ (merge P9.5)           │ POS-9.2 reprise            │ 
...
```

**Règle d'or.** P9.5 (order pipeline backend) et POS-9.2+ (backend POS) sont **séquentiels**. L'un des deux doit passer avant l'autre. Par défaut : kiosk P9.5 d'abord car plus avancé.

## 4. Verrou sur zone partagée

Quand un track doit modifier un fichier partagé :

1. Créer une issue dans `tasks/phase9-sync/LOCK_<track>_<file>_<date>.md` avec : fichier, lignes prévues, raison, ETA libération.
2. Vérifier qu'aucun autre `LOCK_` du track adverse n'existe pour ce fichier.
3. Si conflit → poser la question à l'humain, ne pas forcer.
4. À la fin du commit qui touche le shared, supprimer le lock (ou le marquer `RELEASED`).
5. Annoncer dans `tasks/phase9-sync/BROADCAST_<date>.md` ce qui a été changé et pourquoi (synchro asynchrone entre tracks).

## 5. Registre consolidé

Un fichier unique agrège l'état des deux tracks :

`tasks/phase9-sync/CROSS_TRACK_STATUS.md`

Colonnes : `track | phase | wave | item | branch | pr_url | status | depends_on | blocks | reviewer_agent_sha`

Mis à jour par **chaque commit** de chaque track. Consulté par l'humain avant chaque décision de merge.

## 6. Double-check indépendant systématique

Chaque vague (P9.X et POS-9.X) se termine par :

1. Lancement d'un sous-agent verifier (Task tool, subagent_type general-purpose) qui ne connaît pas l'implémentation.
2. Prompt standardisé : "Vérifier que les findings X, Y, Z sont RESOLVED / PARTIAL / STILL_BROKEN en lisant le code HEAD courant. Rapport dans `reports/review/VERIFY_<track>_<wave>_<date>.md`."
3. Pas de merge sans 100 % RESOLVED.

## 7. Escalade humaine obligatoire si

- Un finding apparaît des deux côtés avec résolution divergente proposée.
- Une migration shared table (orders, items, etc.) est nécessaire en même temps dans les 2 tracks.
- Un invariant (SSOT pricing, branch_id isolation, state machine, event contract V1) est remis en question.
- Une décision produit est ambiguë (ex : qui possède l'écran "Gestion commande kiosk en caisse" ? POS drawer ou interface dédiée ?).
- Un test shared (ex : `FrontendSurfaceFilteringTest`) casse à cause de changements cumulés.

## 8. Règles de merge vers main

- Merge par fast-forward ou squash, jamais de merge commit avec historique divergent.
- Aucun merge d'un track sans :
  - CI verte intégrale.
  - Verifier indépendant 100 % RESOLVED.
  - Registre `CROSS_TRACK_STATUS.md` mis à jour.
  - Rebase récent depuis main (< 24 h).
  - Handoff note écrite pour le track adverse si zone partagée touchée.

## 9. En cas de conflit git non-trivial

- Les deux tracks stop.
- L'humain arbitre (ping.) fichier par fichier.
- Résolution consignée dans `tasks/phase9-sync/CONFLICT_RESOLUTION_<date>.md` pour traçabilité.

## 10. Objectif final

Les deux tracks convergent vers un **merge consolidé main** à la fin de :
- Kiosk P9.10 (build final kiosk).
- POS-9.10 (build final POS).

Puis lancement de **Track C — E2E Global Sync** qui teste les 2 tracks merged ensemble sur scénarios multi-surfaces.
