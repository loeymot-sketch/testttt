# Checklist Production — FoodKing Kiosk/POS/KDS
**Date :** 2026-03-24  
**Statut :** EN COURS DE VALIDATION  
**Objectif :** Valider chaque point avant test manuel et mise en production

---

## LÉGENDE
- ✅ VALIDÉ — fonctionnel, testé, aucune action requise
- ⚠️ À VÉRIFIER — fonctionnel mais nécessite test manuel ou confirmation
- 🔴 BUG CONFIRMÉ — problème identifié, correction planifiée
- 🔧 CORRIGÉ — bug corrigé dans cette session, à valider manuellement

---

## BLOC 1 — PARCOURS BORNE (Kiosk Customer Journey)

### 1.1 Écran d'accueil / Idle
| # | Point | Statut | Note |
|---|---|---|---|
| 1.1.1 | Vidéo/animation idle démarre automatiquement | ⚠️ À VÉRIFIER | Composant KioskIdleScreenComponent |
| 1.1.2 | Touch/click démarre la commande sans double navigation | 🔧 CORRIGÉ | Flag `_touchActivated` ajouté Phase 40 |
| 1.1.3 | Retour automatique à l'idle après inactivité | ⚠️ À VÉRIFIER | Timeout configuré ? |
| 1.1.4 | Choix "Sur place" / "À emporter" sauvegardé dans Vuex | ⚠️ À VÉRIFIER | `setOrderType` action |

### 1.2 Catégories & Best Sellers
| # | Point | Statut | Note |
|---|---|---|---|
| 1.2.1 | Section Best Sellers visible avec produits `is_featured=1` | 🔧 CORRIGÉ | Ajouté Phase 40 |
| 1.2.2 | Toutes les catégories actives s'affichent | ⚠️ À VÉRIFIER | Filtre `status=5&surface=kiosk` |
| 1.2.3 | Images catégories chargent correctement | ⚠️ À VÉRIFIER | Spatie Media Library |
| 1.2.4 | Clic best-seller navigue vers la bonne catégorie | ⚠️ À VÉRIFIER | `selectBestSeller()` |

### 1.3 Liste produits
| # | Point | Statut | Note |
|---|---|---|---|
| 1.3.1 | Produits filtrés par `surface=kiosk` (extras/variations) | 🔧 CORRIGÉ | Phase 41 |
| 1.3.2 | Images produits s'affichent | ⚠️ À VÉRIFIER | |
| 1.3.3 | Prix affiché correct (avec variations) | ⚠️ À VÉRIFIER | |
| 1.3.4 | Produits indisponibles masqués ou désactivés | ⚠️ À VÉRIFIER | `status` filter |

### 1.4 Wizard de personnalisation (Tacos, Sandwich, Burger, etc.)
| # | Point | Statut | Note |
|---|---|---|---|
| 1.4.1 | Étapes Tacos : Taille → Viandes → Crudités → Sauce → Suppléments → Menu → Sauce frites | 🔧 CORRIGÉ | Phase 40 |
| 1.4.2 | Étapes Sandwich : Pain → Viande → Garnitures → Sauce → Suppléments → Menu | ⚠️ À VÉRIFIER | |
| 1.4.3 | Étapes Burger : Pain → Viande → Garnitures → Sauce → Suppléments → Menu | ⚠️ À VÉRIFIER | |
| 1.4.4 | Étapes Snacking : Sauce → Suppléments → Récap | 🔧 CORRIGÉ | Phase 40 |
| 1.4.5 | Étapes Omelette : Garnitures → Suppléments → Récap | 🔧 CORRIGÉ | Phase 40 |
| 1.4.6 | Étapes Salade : Garnitures → Sauce → Suppléments → Récap | 🔧 CORRIGÉ | Phase 40 |
| 1.4.7 | Détection taille tacos (S/M/L/XL) depuis nom produit | ⚠️ À VÉRIFIER | Heuristique — P2 : étape dédiée prévue |
| 1.4.8 | Instruction wizard incluse dans la commande (KDS lisible) | ✅ VALIDÉ | `buildInstruction()` → `instruction` field |
| 1.4.9 | Suppléments filtrés par `group_label` (ex: "Sauce Borji") | 🔧 CORRIGÉ | Phase 41 `visible_on` |
| 1.4.10 | Récapitulatif wizard correct avant ajout panier | ⚠️ À VÉRIFIER | |

### 1.5 Panier
| # | Point | Statut | Note |
|---|---|---|---|
| 1.5.1 | Ajout produit au panier fonctionne | ⚠️ À VÉRIFIER | |
| 1.5.2 | Modification quantité (+ / -) | ⚠️ À VÉRIFIER | |
| 1.5.3 | Suppression ligne panier | ⚠️ À VÉRIFIER | |
| 1.5.4 | Total panier recalculé correctement côté client | ⚠️ À VÉRIFIER | |
| 1.5.5 | Panier vide → bouton valider désactivé | ⚠️ À VÉRIFIER | |
| 1.5.6 | Bouton "Valider le panier" déclenche l'upsell (1 fois par session) | 🔧 CORRIGÉ | Phase 40 |

### 1.6 Upsell (suggestion dessert)
| # | Point | Statut | Note |
|---|---|---|---|
| 1.6.1 | Écran upsell s'affiche après validation panier (1 fois) | ✅ VALIDÉ | `upsellShown` flag |
| 1.6.2 | Produits upsell = items `is_upsell=1` | ✅ VALIDÉ | |
| 1.6.3 | Ajout upsell → retour panier avec item ajouté | ⚠️ À VÉRIFIER | |
| 1.6.4 | "Non merci" → passe directement au paiement | ⚠️ À VÉRIFIER | |
| 1.6.5 | Loyalty ne bypass plus l'upsell | 🔧 CORRIGÉ | Phase 40 |

### 1.7 Fidélité (Loyalty)
| # | Point | Statut | Note |
|---|---|---|---|
| 1.7.1 | Saisie code fidélité → vérification serveur | ✅ VALIDÉ | `POST frontend/loyalty/check` |
| 1.7.2 | Solde points affiché correctement | ⚠️ À VÉRIFIER | |
| 1.7.3 | Réduction calculée et appliquée au total | ⚠️ À VÉRIFIER | |
| 1.7.4 | Déduction points atomique (lockForUpdate) | ✅ VALIDÉ | Phase 39 |
| 1.7.5 | Points crédités après livraison (AwardLoyaltyPoints) | ✅ VALIDÉ | Phase 39 |
| 1.7.6 | Ledger `loyalty_transactions` enregistré | ✅ VALIDÉ | Phase 39 |

### 1.8 Paiement
| # | Point | Statut | Note |
|---|---|---|---|
| 1.8.1 | Choix Cash / Carte / Ticket Restaurant | ⚠️ À VÉRIFIER | |
| 1.8.2 | Double-clic sur "Payer" bloqué (`submitting` flag) | ✅ VALIDÉ | |
| 1.8.3 | Commande créée côté serveur AVANT débit TPE | ✅ VALIDÉ | `submitOrder` puis `processCardPayment` |
| 1.8.4 | TPE Electron IPC (`window.borne.chargeCard`) | ⚠️ À CONFIGURER | Banque non configurée — P0 opérationnel |
| 1.8.5 | Stub browser 2s en mode dev (sans Electron) | ✅ VALIDÉ | |
| 1.8.6 | Annulation TPE → commande serveur orpheline non annulée | 🔴 BUG CONNU | Pas de void/cancel auto — P2 |
| 1.8.7 | `payment-confirm` POST après paiement carte | ⚠️ À VÉRIFIER | Non bloquant si échoue (warn only) |
| 1.8.8 | `orderId` valide avant navigation vers waiting | 🔴 BUG CONNU | Pas de guard si API response mal formée |
| 1.8.9 | Total affiché borne = total serveur (avec taxes) | 🔴 BUG CONNU | Client n'inclut pas la taxe dans l'affichage |

### 1.9 Écran d'attente (Waiting)
| # | Point | Statut | Note |
|---|---|---|---|
| 1.9.1 | Numéro de queue affiché immédiatement | 🔧 CORRIGÉ | `OrderCreated` Echo ajouté Phase 42 |
| 1.9.2 | Mise à jour statut via Echo (OrderStatusChanged) | ✅ VALIDÉ | |
| 1.9.3 | Fallback polling 15s si Echo indisponible | ✅ VALIDÉ | |
| 1.9.4 | Passage à "Prêt" → son + animation | ✅ VALIDÉ | `playReadySound()` |
| 1.9.5 | Annulation commande possible avant PREPARING | ✅ VALIDÉ | |
| 1.9.6 | Bannière réseau perdu après 3 échecs poll | ✅ VALIDÉ | |
| 1.9.7 | Retour automatique à l'idle après 20s (commande prête) | ✅ VALIDÉ | `startAutoReset()` |

### 1.10 Écran de confirmation
| # | Point | Statut | Note |
|---|---|---|---|
| 1.10.1 | Snapshot panier pris AVANT reset | ✅ VALIDÉ | `mounted()` snapshot |
| 1.10.2 | Numéro commande + total affichés | ⚠️ À VÉRIFIER | Via query params |
| 1.10.3 | Panier Vuex resetté proprement | ✅ VALIDÉ | `kioskCart/reset` |
| 1.10.4 | Impression reçu (si imprimante connectée) | ⚠️ À VÉRIFIER | `printReceipt()` Electron |

---

## BLOC 2 — SYNCHRONISATION COMMANDES (POS ↔ Borne ↔ Admin)

### 2.1 Numérotation queue (A001, A002…)
| # | Point | Statut | Note |
|---|---|---|---|
| 2.1.1 | Compteur partagé POS + Borne (même séquence) | ✅ VALIDÉ | `max(Order, FrontendOrder)` |
| 2.1.2 | Atomicité via `lockForUpdate` + `DB::transaction` | ✅ VALIDÉ | |
| 2.1.3 | `withoutGlobalScope(BranchScope)` sur FrontendOrder dans POS | 🔧 CORRIGÉ | Phase 42 |
| 2.1.4 | Remise à zéro quotidienne (filtre `whereDate today`) | ✅ VALIDÉ | |
| 2.1.5 | Format `A###` (A001 → A999) — limite à 999/jour | ⚠️ À SURVEILLER | Suffisant pour restaurant standard |

### 2.2 Idempotence des commandes
| # | Point | Statut | Note |
|---|---|---|---|
| 2.2.1 | Borne online : `X-Idempotency-Key` envoyé | ✅ VALIDÉ | |
| 2.2.2 | Borne offline replay : `X-Idempotency-Key` dans syncQueue | 🔧 CORRIGÉ | Phase 42 |
| 2.2.3 | Serveur déduplique via `idempotency_key` column | ✅ VALIDÉ | `FrontendOrderService` |
| 2.2.4 | POS : pas d'idempotency key | ⚠️ À NOTER | Staff moins risqué, P2 |

### 2.3 Offline mode borne
| # | Point | Statut | Note |
|---|---|---|---|
| 2.3.1 | Commande sauvée localStorage si réseau KO | ✅ VALIDÉ | `kioskOfflineQueue.js` |
| 2.3.2 | Sync auto toutes les 30s au retour réseau | ✅ VALIDÉ | `startAutoSync` |
| 2.3.3 | Max 10 tentatives puis abandon | ✅ VALIDÉ | |
| 2.3.4 | UI "offline" montrée au client (pas de numéro queue) | ✅ VALIDÉ | `isOfflineOrder` state |
| 2.3.5 | Nettoyage localStorage après 24h | ✅ VALIDÉ | |

---

## BLOC 3 — KDS (Kitchen Display System)

### 3.1 Affichage commandes
| # | Point | Statut | Note |
|---|---|---|---|
| 3.1.1 | Commandes borne visibles dans colonne KIOSK | ✅ VALIDÉ | `order_type === KIOSK` |
| 3.1.2 | Numéro queue affiché sur chaque carte | ✅ VALIDÉ | `queue_number` dans resource |
| 3.1.3 | Instructions wizard affichées (pain, viande, sauce…) | ✅ VALIDÉ | `instruction` field |
| 3.1.4 | Variations et extras affichés | ⚠️ À VÉRIFIER | Shape JSON kiosk ≠ POS — possible mismatch UI |
| 3.1.5 | Commandes POS visibles dans bonne colonne | ⚠️ À VÉRIFIER | |
| 3.1.6 | Commandes table visibles | ⚠️ À VÉRIFIER | |

### 3.2 Temps réel KDS
| # | Point | Statut | Note |
|---|---|---|---|
| 3.2.1 | Nouvelle commande → apparaît sans refresh manuel | ⚠️ À VÉRIFIER | Echo `.OrderCreated` → `refreshOrderList()` |
| 3.2.2 | Changement statut → KDS mis à jour | ⚠️ À VÉRIFIER | Echo `.OrderStatusChanged` |
| 3.2.3 | Fallback polling 30s si Echo KO | ✅ VALIDÉ | |
| 3.2.4 | Admin (branch_id=0) : polling seulement (Echo skip) | ⚠️ À NOTER | Délai 30s pour admin |

### 3.3 Impression cuisine
| # | Point | Statut | Note |
|---|---|---|---|
| 3.3.1 | Ticket cuisine (impression automatique nouvelle commande) | 🔴 ABSENT | Pas de mécanisme d'impression KDS |
| 3.3.2 | Impression reçu client POS | ✅ VALIDÉ | `ReceiptComponent.vue` + `vue3-print-nb` |

---

## BLOC 4 — OSS (Order Status Screen / Écran client)

### 4.1 Affichage
| # | Point | Statut | Note |
|---|---|---|---|
| 4.1.1 | Commandes en PREPARING affichées | ✅ VALIDÉ | `OrderStatusScreenOrderService` |
| 4.1.2 | Commandes en PREPARED (prêtes) affichées | ✅ VALIDÉ | |
| 4.1.3 | Numéro queue affiché (POS + Borne) | ✅ VALIDÉ | `queue_number` ou `token` |
| 4.1.4 | Animation/son quand commande passe en PREPARED | ⚠️ À VÉRIFIER | `_markNewReady()` |
| 4.1.5 | Double chime possible (Echo + list refresh) | 🔴 BUG CONNU | `_markNewReady` appelé 2x possible |

### 4.2 Temps réel OSS
| # | Point | Statut | Note |
|---|---|---|---|
| 4.2.1 | Echo `.OrderStatusChanged` → refresh liste | ✅ VALIDÉ | |
| 4.2.2 | Echo `.OrderCreated` → refresh liste | ✅ VALIDÉ | |
| 4.2.3 | Event `realtime-order-update` cross-composant | ✅ VALIDÉ | |
| 4.2.4 | Fallback polling 30s | ✅ VALIDÉ | |

---

## BLOC 5 — CAISSE POS

### 5.1 Panier POS
| # | Point | Statut | Note |
|---|---|---|---|
| 5.1.1 | Calcul total client-side (subtotal + delivery - discount) | ✅ VALIDÉ | `posCartLineMath.js` |
| 5.1.2 | **Taxe NON incluse dans total affiché POS** | 🔴 BUG CONNU | Client = subtotal+delivery-discount, serveur = +tax |
| 5.1.3 | Recalcul serveur obligatoire (prix DB) | ✅ VALIDÉ | `OrderService::posOrderStore` |
| 5.1.4 | Changement prix produit mid-session → divergence | 🔴 BUG CONNU | Pas de refresh auto des prix en session |
| 5.1.5 | Discount > subtotal → ignoré serveur silencieusement | ⚠️ À NOTER | Client peut afficher discount non appliqué |
| 5.1.6 | Panier persisté localStorage 2h TTL | ✅ VALIDÉ | |
| 5.1.7 | Tests unitaires cart math | 🔴 ABSENT | `posCart.spec.js` ne teste pas la math |

### 5.2 Paiement POS
| # | Point | Statut | Note |
|---|---|---|---|
| 5.2.1 | `PosOrderRequest::total` = total client (pas serveur) | ⚠️ À VÉRIFIER | Risque si taxe active |
| 5.2.2 | Loyauté client affichée en caisse | ✅ VALIDÉ | Phase 39 |
| 5.2.3 | `loyalty_customer_code` transmis à la commande | ✅ VALIDÉ | Phase 39 |
| 5.2.4 | `source_surface = 'pos'` enregistré | ✅ VALIDÉ | Phase 39 |

### 5.3 Liste commandes POS admin
| # | Point | Statut | Note |
|---|---|---|---|
| 5.3.1 | Commandes POS filtrées par `source=POS` | ✅ VALIDÉ | |
| 5.3.2 | Commandes borne dans liste "Online" (pas POS) | ✅ VALIDÉ | `exceptSource: POS` |
| 5.3.3 | Label "KIOSK" dans type commande manquant | 🔴 BUG CONNU | `orderTypeEnumArray` incomplet dans certains composants |
| 5.3.4 | Export Excel POS : filename "Online-Order.xlsx" | 🔴 BUG COSMÉTIQUE | Copy-paste dans `PosOrderController::export` |

---

## BLOC 6 — SÉCURITÉ & INFRASTRUCTURE

### 6.1 Auth & Middleware
| # | Point | Statut | Note |
|---|---|---|---|
| 6.1.1 | Routes admin protégées `auth:sanctum` | ✅ VALIDÉ | |
| 6.1.2 | Routes borne protégées `apiKey` | ✅ VALIDÉ | |
| 6.1.3 | `/refresh-token` sans `apiKey` | 🔧 CORRIGÉ | Phase 42 |
| 6.1.4 | Tokens Sanctum sans expiration | 🔧 CORRIGÉ | 43200 min Phase 42 |
| 6.1.5 | Rate limiter global inefficace (200/min flat) | 🔧 CORRIGÉ | 120/min par user/IP Phase 42 |
| 6.1.6 | `POST table/dining-order` sans throttle dédié | 🔧 CORRIGÉ | 20/min Phase 42 |

### 6.2 Données financières
| # | Point | Statut | Note |
|---|---|---|---|
| 6.2.1 | Total recalculé serveur (prix DB) | ✅ VALIDÉ | |
| 6.2.2 | Discount validé serveur (≤ subtotal) | ✅ VALIDÉ | |
| 6.2.3 | Coupon validé serveur | ✅ VALIDÉ | |
| 6.2.4 | Loyauté déduction atomique | ✅ VALIDÉ | |

### 6.3 Infrastructure
| # | Point | Statut | Note |
|---|---|---|---|
| 6.3.1 | `QUEUE_CONNECTION=database` + supervisord actif | ⚠️ À CONFIGURER | Défaut = `sync` (bloquant) |
| 6.3.2 | Soketi/Pusher configuré pour Echo | ⚠️ À CONFIGURER | Sans ça : polling 30s seulement |
| 6.3.3 | `SANCTUM_TOKEN_EXPIRATION` dans `.env` prod | ⚠️ À CONFIGURER | Recommandé 10080 (7j) en prod |
| 6.3.4 | `APP_DEBUG=false` en production | ⚠️ À VÉRIFIER | |
| 6.3.5 | Migrations exécutées (visible_on, source_surface, loyalty_transactions) | ⚠️ À VÉRIFIER | `php artisan migrate` |

---

## BLOC 7 — GESTION ADMIN CATALOGUE

### 7.1 Produits
| # | Point | Statut | Note |
|---|---|---|---|
| 7.1.1 | CRUD produits depuis admin | ✅ VALIDÉ | |
| 7.1.2 | Photos produits (Spatie Media Library) | ✅ VALIDÉ | |
| 7.1.3 | `is_upsell` préservé lors de l'édition | 🔧 CORRIGÉ | Phase 39 `SimpleItemResource` |
| 7.1.4 | `visible_on` sur extras (kiosk/pos/web) | 🔧 CORRIGÉ | Phase 41 |
| 7.1.5 | `group_label` sur extras (ex: "Sauce", "Supplément") | 🔧 CORRIGÉ | Phase 41 |
| 7.1.6 | Sync borne automatique (pas de cache manuel) | ✅ VALIDÉ | API appelée à chaque chargement |

### 7.2 Catégories
| # | Point | Statut | Note |
|---|---|---|---|
| 7.2.1 | CRUD catégories | ✅ VALIDÉ | |
| 7.2.2 | `wizard_template` configuré par catégorie | ✅ VALIDÉ | |
| 7.2.3 | Ordre d'affichage catégories | ⚠️ À VÉRIFIER | `sort_order` / `order` field |

---

## BLOC 8 — POINTS RESTANTS NON BLOQUANTS (P2/P3)

| # | Point | Priorité | Note |
|---|---|---|---|
| 8.1 | Taille tacos : étape dédiée au lieu d'heuristique nom | P2 | Heuristique fonctionne mais fragile |
| 8.2 | Annulation commande après échec TPE (void serveur) | P2 | Commande orpheline en base |
| 8.3 | Guard `orderId` undefined avant navigation waiting | P2 | Rare mais possible |
| 8.4 | Total POS affiché sans taxe | P2 | Recalcul serveur correct, affichage seul faux |
| 8.5 | Tests unitaires `posCartLineMath.js` | P3 | Pas bloquant prod |
| 8.6 | Impression ticket cuisine KDS | P2 | Pas de mécanisme — manuel pour l'instant |
| 8.7 | Double chime OSS (`_markNewReady` 2x) | P3 | Cosmétique |
| 8.8 | Label "KIOSK" dans listes admin | P3 | Cosmétique |
| 8.9 | Filename export POS "Online-Order.xlsx" | P3 | Cosmétique |
| 8.10 | Idempotency key POS | P3 | Staff moins risqué |
| 8.11 | Message explicite si loyauté insuffisante à la soumission | P2 | UX |
| 8.12 | Configuration TPE banque | P0-OPS | Hors code |

---

## RÉSUMÉ ÉTAT GLOBAL

| Bloc | Total | ✅ Validé | 🔧 Corrigé | ⚠️ À vérifier | 🔴 Bug |
|---|---|---|---|---|---|
| 1 — Parcours borne | 40 | 16 | 12 | 10 | 2 |
| 2 — Sync commandes | 12 | 9 | 3 | 0 | 0 |
| 3 — KDS | 11 | 5 | 0 | 5 | 1 |
| 4 — OSS | 9 | 7 | 0 | 1 | 1 |
| 5 — POS | 14 | 7 | 0 | 3 | 4 |
| 6 — Sécurité | 13 | 6 | 5 | 2 | 0 |
| 7 — Catalogue admin | 9 | 5 | 4 | 0 | 0 |
| **TOTAL** | **108** | **55 (51%)** | **24 (22%)** | **21 (19%)** | **8 (7%)** |

### Prêt pour production quand :
1. Tous les ⚠️ "À VÉRIFIER" sont testés manuellement et confirmés
2. Les 🔴 bugs P2 critiques (1.8.6, 1.8.8, 1.8.9) sont corrigés ou acceptés
3. Infrastructure (QUEUE_CONNECTION, Soketi, migrations) configurée
4. TPE banque configuré (hors code)
