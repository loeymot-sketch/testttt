# FoodKing Version A — Central Data & Sync Runbook — 2026-04-30

## Verdict de perimetre

`SOFTWARE_SYNC_SCOPE: LOCAL_PASS_IN_PROGRESS_TO_FINAL_VALIDATION`

`HARDWARE_SCOPE: DEFERRED_TO_INDUSTRIAL_UAT`

Ce runbook documente le fonctionnement logiciel centralise entre Dashboard, POS, Kiosk, KDS, OSS, stock, catalogue, photos, composer wizard et outbox. Les composants physiques (TPE reel, imprimante fiscale, OS kiosk lockdown industriel, provider cloud live, Google Maps live) restent volontairement hors perimetre jusqu'a l'UAT materiel.

## Surfaces connectees

| Surface | Role | Source de donnees | Sync entrant | Sync sortant |
| --- | --- | --- | --- | --- |
| Dashboard admin | Gestion produits, categories, photos, composer, stock | API admin Laravel | Etat DB + projections | Mutations catalogue, composer, availability, photo |
| POS / caisse | Prise commande staff, encaissement, counter collect | API admin/POS Laravel | Catalogue, stock, orders, payment events | Orders, payments, status changes, table changes |
| Kiosk / borne | Prise commande client | API kiosk + token machine | Menu projection branch-scoped, stock, composer | Orders kiosk, cash-at-counter intent |
| KDS | Cuisine | API KDS sync + realtime branch channel | OrderCreated, OrderStatusChanged, ItemAvailabilityChanged, CatalogChanged | Status transitions cuisine |
| OSS | Order status screen client | API OSS + realtime branch channel | Status changes et preparing/ready | Aucun flux metier critique |
| Backend Laravel | Source de verite | MySQL/DB, cache, queue, outbox | Toutes mutations | Domain events + API responses |

## Sources de verite

| Domaine | SSOT | Regle |
| --- | --- | --- |
| Prix | Backend `PricingService` | Frontend affiche/preview, backend recalcule et rejette les payloads forges |
| Catalogue produit/categorie/photo | DB via controllers admin autorises | Dashboard ecrit; POS/Kiosk/KDS consomment des projections |
| Composer wizard | `composer_profiles` publies + steps/options | Produit simple possible sans wizard; produit compose utilise profil publie |
| Stock produit | `stock_levels` + `StockService` | Produit/menu/boisson/dessert/supplement peut etre en rupture complete |
| Stock choix wizard | choice stockable resolu par branche | Ingredient/sauce/crudite/supplement/boisson/dessert peut etre indisponible dans le wizard |
| Commande | `orders`, `order_items`, snapshots | POS et Kiosk ecrivent via services differencies mais invariants symetriques |
| Realtime | `domain_events` outbox | Event persiste apres commit, broadcast idempotent, fallback sync possible |
| Fiscal | Services fiscaux NF525 | Sequence allouee au paiement reel, jamais a la creation kiosk cash deferred |

## Flux principaux

### 1. Modification produit/categorie/photo depuis Dashboard

1. User admin appelle API admin avec permission et scope branche/catalogue.
2. Controller valide permission et branch visibility.
3. Mutation DB du produit, categorie, photo ou availability.
4. Event catalogue ou availability declenche invalidation cache/projection.
5. Outbox stocke un event branch-scoped.
6. POS/Kiosk/KDS/OSS refreshent via realtime ou fallback API.

Points verifies:

- photo produit reservee aux roles globaux en V1;
- branch users ne peuvent pas muter ou lire une branche etrangere;
- availability null fanout est scope: branch user -> sa branche, admin -> toutes branches;
- catalogue change et stock change produisent des refreshs coherents.

### 2. Publication composer wizard

1. Dashboard cree ou met a jour un profil composer pour un produit.
2. Les steps/options definissent min/max/repeat, required, et types de choix.
3. Publication rend le profil actif pour les projections.
4. Kiosk et POS consomment la projection publiee.
5. Backend pricing revalide que les choices soumis existent toujours dans le profil courant.

Regles de conception:

- produit pret/simple: pas de wizard obligatoire;
- produit avec taille/options simples: wizard court possible;
- tacos/menu/personnalisation: wizard multi-step publie;
- choix devenu retire ou indisponible: frontend nettoie, backend rejette si forge ou stale.

### 3. Prise de commande Kiosk

1. Kiosk charge menu branch-scoped depuis machine token.
2. Client compose produit si profil wizard existe.
3. Frontend desactive les produits/choix indisponibles.
4. Submit commande: backend ignore prix client et recalcule.
5. Order cree en DB, stock decremente selon snapshot.
6. Outbox publie `OrderCreated`.
7. KDS/POS/OSS recoivent la commande sans reload manuel dans C3 local.
8. Cash-at-counter reste fiscalement non encaisse jusqu'a confirmation caisse.

### 4. Prise de commande POS

1. POS charge catalogue et availability pour la branche staff.
2. Caissier ajoute produit simple ou compose.
3. Frontend bloque les choix indisponibles et nettoie les lignes restaurees.
4. Backend pricing recalcule et valide profil/stock.
5. Order cree, stock decremente, queue number alloue.
6. KDS/OSS/POS live board recoivent via event/fallback.

### 5. Rupture stock

Rupture produit:

- item complet indisponible pour POS/Kiosk;
- le produit peut rester visible avec badge selon UX, mais non commandable;
- backend rejette toute soumission forgee.

Rupture choix wizard:

- ingredient/sauce/crudite/supplement/boisson/dessert stockable peut etre indisponible;
- le wizard affiche l'option comme indisponible;
- le choix ne compte pas pour avancer une etape requise;
- les anciennes selections restaurees sont supprimees avant submit;
- backend rejette stale choice absent du profil publie ou indisponible branche.

## Outbox et realtime

Pipeline:

1. Mutation DB dans transaction.
2. Event declenche apres commit.
3. Listener persiste `domain_events`.
4. Job `DispatchDomainEventsJob` claim puis broadcast.
5. En cas d'echec provider, `dispatched_at` reste null et `last_error` est trace.
6. `foodking:outbox:rescue` requeue les pending stale retriables.
7. `foodking:outbox:retry-failed` reset/requeue les failed recents.
8. Frontend dedupe par `correlation_id`.

Commandes utiles:

```bash
php artisan foodking:outbox:rescue
php artisan foodking:outbox:retry-failed --since=1h
php artisan test tests/Feature/Outbox
npx vitest run tests/js/eventContractDedupe.spec.js tests/js/realtimeBroadcastFallback.spec.js
```

## Surveillance avant go-live

| Point | Seuil local | Action si fail |
| --- | --- | --- |
| Events pending stale | 0 ou faible | lancer rescue, verifier queue worker |
| Events failed recents | 0 | retry-failed, verifier provider |
| KDS reception C3 | visible sans reload | verifier channel auth, fallback sync, throttle |
| Rupture choix | option disabled + backend reject | verifier projection branch_id et PricingService |
| Queue number | unique branche/jour | relancer prod-like concurrency |
| Stock | jamais negatif | relancer stock stress MySQL/Redis |

## Limites gardees pour UAT materiel

- TPE physique et refus/timeouts reels.
- Imprimante fiscale/reprint papier.
- OS kiosk lockdown industriel.
- Provider realtime cloud avec latence reseau reelle.
- Google Maps live et quotas.
- Coupure reseau physique longue duree.

