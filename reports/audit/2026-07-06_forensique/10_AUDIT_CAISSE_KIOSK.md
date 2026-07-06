# FoodKing — Audit dédié : Caisse (POS) & Borne libre-service

> Complément à l'audit forensique du 2026-07-06.
> **Méthode** : 11 dimensions fonctionnelles+techniques auditées en parallèle sur le vrai code (flux reconstitués étape par étape), **65 findings**, vérification adversariale (2 réfutateurs/finding) → **32 confirmés** (12 critiques, 20 élevés), 1 rejeté.

## 0. Verdict : fondations crédibles, exploitation non sûre

| Sous-système | Verdict | En un mot |
|---|---|---|
| **Caisse (POS)** | 🟠 **HEAL sévère → BLOCK sur l'axe fiscal** | Encaisse bien, mais **ne clôture pas de façon défendable** (NF525 cassé) + plafond de remise contournable |
| **Borne libre-service** | 🔴 **BLOCK** | Failles exploitables **sans compétence** : token=super-admin, paiement déclaré par le client, vol de points |
| **Transverse (états/temps réel)** | 🟠 **HEAL** | Deux chemins de transition divergents, temps réel fragile (oversell) |

> Le cœur transactionnel est de **qualité production** (pricing SSOT backend, idempotence multi-couche, audit HMAC immuable, séquence fiscale gap-free, isolation de branche). Les trous sont **concentrés aux frontières** : paiement, fiscal, disponibilité, auth borne. **Les scores élevés reflètent les fondations, pas l'état livrable.**

## 1. Scorecard des 11 dimensions

| Dimension | Sous-système | Score |
|---|---|:---:|
| Borne — authentification machine & provisioning | kiosk | **3.0** |
| Borne — paiement libre-service | kiosk | **3.5** |
| Borne — menu, disponibilité (86) & cache | kiosk | **3.5** |
| POS — flux de commande & panier | caisse | **4.5** |
| POS — remises, pricing & autorisation | caisse | **4.5** |
| POS — fiscal, session & clôture | caisse | **4.5** |
| Borne — cycle de vie commande & abandon | kiosk | **4.5** |
| Borne — fidélité, upsell, accessibilité & UX | kiosk | **4.5** |
| Machine d'états & cohérence caisse↔borne↔KDS↔OSS | transverse | **4.5** |
| POS — paiements (espèces/carte/ticket/crédit) | caisse | **5.0** |
| Temps réel, reconnexion & résilience | transverse | **5.5** |

## 2. Synthèse exécutive

**Ce qui tient.** Pricing réellement *source de vérité backend* : les champs financiers du client (total/subtotal/discount) sont **effacés avant persistance** puis recalculés en DB, avec rejet des items/variations/extras inconnus et garde anti-injection cross-item. Idempotence multi-couche (`X-Idempotency-Key` + `Cache::lock` + `UNIQUE` DB + rattrapage de course 23000), y compris au rejeu offline. Audit HMAC chaîné et immuable (triggers SQL), séquence fiscale gap-free. Isolation de branche des commandes borne dérivée de la `KioskMachine`. Caisse et borne partagent `OrderStateMachine` et la table `orders` : **pas de drift**, visibilité KDS/OSS native.

**Ce qui casse.** La caisse encaisse bien mais son **intégrité NF525 est compromise** : annulés/remboursés comptés PAID dans le Z (CA/TVA surdéclarés, écart de tiroir), fenêtre morte hors Z signé, immutabilité partielle post-clôture, totaux non réconciliables (TTC≠HT+TVA), **aucun shift ni rapprochement d'espèces**. Plafond de remise contournable via le `subtotal` client (90 % sans validation manager).

**La borne est le maillon faible.** Token machine = super-admin (`kiosk:order` enforcé nulle part, car lié à l'admin id=1), aggravé par identifiants en clair et défaut `kiosk123` : **accès physique = back-office complet**. Paiement libre-service **entièrement déclaré par le client**, sans PSP ni contrôle de montant : fraude directe. Jobs de nettoyage rejetant les commandes **PAID** sans rembourser ni restaurer la fidélité. Disponibilité (86) **non chargée au rendu** : rupture commandable. Fidélité et promo **affichées mais jamais appliquées**.

## 3. Cartographie des flux

**CAISSE** : Panier → `PosOrderRequest` *(gate remise contournable via subtotal)* → `OrderService` *(unset financier, recalcul PricingService SSOT)* → `PaymentComponent` *(total affiché HT, monnaie faussée)* → passerelles *(espèces double-contrôle OK ; Credit sans verrou → solde négatif)* → `Order PAID` → audit HMAC + séquence gap-free → `ZReportService` *(annulés restent PAID, fenêtre morte, TTC≠HT+TVA)*. Idempotence régénérée à la ré-ouverture du modal → **double encaissement**.

**BORNE** : `/api/auth/kiosk-login` *(défaut kiosk123, identifiants en clair)* → **token super-admin** *(kiosk:order non enforcé, révoque les autres bornes)*. Menu : **86 non chargé**, patch temps réel sans état indisponible. Panier : fidélité+promo affichées non appliquées ; 5xx → file offline → **fausse confirmation**. Paiement : `OrderController` confirme **sur déclaration client** (pas de PSP) → auto-ACCEPT. Nettoyage : rejette les PAID sans rembourser. Fidélité : `/register`+`redeem` publics (PII, vol de points).

**TRANSVERSE** : `orders` partagée, `OrderStateMachine` commune, mais **deux chemins de transition divergents**. KDS annule des commandes payées sans remboursement ni NF525. `Echo.leave` partagé coupe les abonnés, pas de resync → **oversell**.

---

## 4. CAISSE (POS) — findings confirmés

**✅ Forces réelles** : pricing SSOT (champs client `unset` avant `Order::create`, recalcul DB, garde cross-item) · idempotence serveur robuste (pré-check + capture UNIQUE 23000) · double validation du cash reçu contre le total **réel** · rendu de monnaie du ticket dérivé du total serveur autoritatif · traçabilité NF525 (HMAC) sur remise/cash_back/annulation · panier localStorage **scopé** par branch_id+user_id (TTL 2h, anti-fuite inter-caissier) · timeout réseau 30 s avec message anti-relance.

### 🔴 Critiques
| Finding | Emplacement | Impact |
|---|---|---|
| **Plafond de remise contournable via `subtotal` client** | `app/Http/Requests/PosOrderRequest.php:143` | Caissier `pos-discount-up-to-10` : subtotal réel 100 €, discount 90 €, `subtotal` payload 10000 € → pct 0,9 % → passe le gate → **90 % de remise sans validation manager** |
| **Annulés/remboursés restent PAID et comptés dans le Z** | `app/Services/Fiscal/ZReportService.php:217` | CA et cash surévalués, **écart de caisse systématique**, TVA surdéclarée (NF525) |
| **Fenêtre morte entre Z (borne basse = `opened_at`)** | `app/Services/Fiscal/ZReportService.php:129` | Recettes numérotées fiscalement mais dans **aucun Z signé** → sous-déclaration silencieuse |

### 🟠 Élevés
| Finding | Emplacement |
|---|---|
| Total & « monnaie à rendre » affichés **excluent la TVA** → sur-rendu au tiroir | `resources/js/components/admin/pos/PaymentComponent.vue:142` |
| Aucun contrôle serveur de disponibilité : article désactivé/86 ou panier restauré (2 h) reste commandable | `app/Services/Pricing/PricingService.php:35` |
| Double encaissement : clé d'idempotence **régénérée** à chaque ouverture du modal paiement | `resources/js/components/admin/pos/PosComponent.vue:1496` |
| Credit : `success()` sans re-vérif ni verrou → **solde négatif / double dépense** | `app/Http/PaymentGateways/Gateways/Credit.php:67` |
| Immutabilité NF525 partielle : ordre scellé annulable via `updateStatus`/`changePaymentStatus` | `app/Services/OrderService.php:1594` |
| Motif de remise validé mais **jamais persisté** (trou d'audit) | `app/Services/OrderService.php:927` |
| Suppléments wizard (sauce/viande/frites) tarifés **uniquement frontend** | `resources/views/master.blade.php:122` |
| **Aucune session/shift ni rapprochement d'espèces** (écart de caisse indétectable) | `app/Models/ZReport.php:22` |
| Totaux Z non réconciliables : `total_ht` dérivé de `subtotal`, TTC ≠ HT+TVA | `app/Services/Fiscal/ZReportService.php:229` |

> ⚠️ **Fait structurel caisse** : les commandes POS sont créées `PAID` **inconditionnellement** (aucune preuve d'encaissement carte) et **aucune `Transaction` n'est écrite** → le remboursement (`cashBack`, conditionné à `$order->transaction`) est **mort** pour toute vente POS. Pas de paiement fractionné/split.

---

## 5. BORNE libre-service — findings confirmés

**✅ Forces réelles** : isolation de branche dérivée de la `KioskMachine` · idempotence au rejeu offline · partage de `OrderStateMachine`/`orders` (visibilité KDS native) · cycle pending→payé structuré.

### 🔴 Critiques
| Finding | Emplacement | Impact |
|---|---|---|
| **Token borne = super-admin** (`kiosk:order` enforcé sur aucune route, lié à admin id=1) | `app/Http/Controllers/Auth/KioskMachineLoginController.php:83` | Accès physique borne → **back-office complet** (pricing, fiscal, users, toutes branches) |
| **Identifiants machine en clair dans la page** + défaut `kiosk123` documenté | `config/kiosk.php:68` | view-source/DevTools révèle le mot de passe ; borne non re-paramétrée attaquable à distance |
| **Paiement 100 % déclaré par le client**, sans PSP ni contrôle de montant | `app/Http/Controllers/Frontend/OrderController.php:111` | Commandes marquées payées **sans paiement réel = fraude directe** |
| Nettoyage auto-rejette les commandes **PAYÉES** (aucun garde `payment_status`) | `app/Jobs/CleanupStalePendingKioskOrders.php:19` | Client débité au TPE, jamais transmis en cuisine, **aucun remboursement** |
| Points fidélité **débités mais jamais remboursés** à l'auto-rejet | `app/Jobs/CleanupStalePendingKioskOrders.php:29` | Fuite de valeur fidélité systématique et silencieuse |
| Remboursement carte/TR **impossible** : aucune `Transaction`, `cashBack` crédite le compte machine | `app/Services/FrontendOrderService.php:660` | Argent conservé indûment, litiges/chargebacks non traçables |
| **Article 86/rupture reste commandable** : le menu borne ne charge jamais la disponibilité | `resources/js/store/modules/kioskMenu.js:253` | Commande d'un produit non servable → annulation cuisine, remboursement, friction |
| **Fidélité ET promo affichées mais jamais appliquées** au paiement | `resources/js/store/modules/kioskCart.js:21` | Client voit −X €, débité du **plein tarif** : divergence prix affiché/facturé |

### 🟠 Élevés
| Finding | Emplacement |
|---|---|
| Multi-bornes cassé : un login **révoque les tokens de toutes les autres bornes** (même cross-branche) | `.../KioskMachineLoginController.php:81` |
| File offline : **abandon silencieux** après 10 échecs alors que le client a une fausse confirmation | `resources/js/helpers/kioskOfflineQueue.js:115` |
| Débité au TPE mais **commande perdue** : échec de confirmation sans réconciliation | `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:551` |
| Le job de nettoyage rejette les commandes **PAID** restées PENDING | `app/Jobs/CleanupStalePendingKioskOrders.php:20` |
| La grille produits n'a **aucun état « indisponible »** → le patch 86 temps réel est sans effet | `resources/js/components/frontend/kiosk/KioskProductListComponent.vue:129` |
| **Vol/destruction de points** : `/loyalty/redeem` accepte le token kiosk pour n'importe quel code | `app/Http/Controllers/Frontend/LoyaltyController.php:255` |
| `/loyalty/register` (public) **divulgue code fidélité + solde + nom** par téléphone | `app/Http/Controllers/Frontend/LoyaltyController.php:167` |

---

## 6. TRANSVERSE — findings confirmés

### 🔴 Critique
| Finding | Emplacement | Impact |
|---|---|---|
| **Annulation KDS d'une commande payée** : aucun remboursement ni trace NF525 | `app/Services/KitchenDisplaySystemOrderService.php:115` | Un chef POST `status=16` annule une vente payée sans rembourser, sans audit fiscal |

### 🟠 Élevés
| Finding | Emplacement |
|---|---|
| **Deux chemins de transition divergents** sur la même table `orders` | `.../KitchenDisplaySystemOrderService.php:133` |
| `Echo.leave` partagé : le démontage d'un composant **coupe le temps réel des autres abonnés** → oversell | `resources/js/services/eventContract.js:111` |
| **Aucune resync de disponibilité à la reconnexion** : `ItemAvailabilityChanged` manqué = état figé | `resources/js/components/frontend/kiosk/KioskAppComponent.vue:262` |
| Mode dégradé : tout **5xx traité comme « hors ligne »**, succès affiché, commande abandonnée en silence | `resources/js/store/modules/kioskCart.js:366` |

---

## 7. Top risques prioritaires (exploitation réelle)

1. 🔴 **Token borne = super-admin** → accès physique = back-office complet, toutes branches.
2. 🔴 **Paiement borne déclaré par le client** sans PSP → fraude directe.
3. 🔴 **NF525 cassé** : annulés/remboursés comptés PAID, fenêtre morte hors Z, TTC≠HT+TVA.
4. 🔴 **Nettoyage rejette des PAID** sans rembourser ni restaurer la fidélité → client débité, jamais servi.
5. 🔴 **Plafond de remise contournable** via `subtotal` client → 90 % sans validation.
6. 🔴 **86 non chargé au rendu borne** → article épuisé commandable.
7. 🔴 **Fidélité/promo affichées jamais appliquées** → prix affiché ≠ facturé.
8. 🔴 **Annulation KDS d'une commande payée** sans remboursement ni NF525.
9. 🟠 **Aucun shift caissier ni rapprochement d'espèces** → écart de caisse indétectable.
10. 🟠 **Temps réel fragile** : `Echo.leave` partagé, pas de resync dispo à la reconnexion.

---

## 8. Où corriger

Plusieurs de ces findings recoupent le **paquet P0 prêt à appliquer** (`11_PAQUET_P0_CORRECTIFS.md`) : token borne dédié + enforcement `kiosk:order`, vérification PSP avant PAID, isolation de branche. Les correctifs **spécifiques caisse/borne** à ajouter au backlog :
- **Fiscal (P0)** : fenêtre Z sur `closed_at` du Z précédent · exclure CANCELED/RETURNED des totaux (ou forcer REFUNDED) · immutabilité sur `updateStatus`/`changePaymentStatus` · `total_ht` net cohérent (une seule source de TVA) · **entité session/shift + rapprochement d'espèces**.
- **Remise (P0)** : déplacer le gate d'autorisation **après** recalcul de `$realSubtotal` (ne jamais autoriser sur une valeur client) · persister `discount_reason`.
- **Borne (P0)** : charger `is_available` (scoped branche) dans le payload liste + griser les 86 · appliquer réellement fidélité/promo serveur (SSOT) · exclure les PAID du nettoyage + rembourser/restaurer à l'auto-rejet · créer une vraie `Transaction` + refund PSP réel.
- **Loyalty (P0)** : `/loyalty/register` ne renvoie rien de sensible sur un compte existant · `/loyalty/redeem` hors token kiosk (déduction liée à un `order_id`).
- **Transverse (P1)** : converger vers **un seul** `OrderTransitionService` (garde+motif+remboursement+audit+broadcast) · ref-count sur `Echo.leave` · `fetchMenu({force:true})` sur `connected` · distinguer erreur réseau vs 5xx applicatif.

---

*11 dimensions auditées, 32 findings confirmés par vérification adversariale. Chaque finding est ancré `fichier:ligne`. Les fondations transactionnelles sont saines ; la remédiation porte sur les frontières (paiement, fiscal, disponibilité, auth borne).*
