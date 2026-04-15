# Task – SYNC_WIZARD_DEEP_001

## Description
Audit profond et corrections sur deux axes interdépendants :

**Axe 1 — Synchronisation** : commandes simultanées, multi-bornes, temps réel KDS/OSS,
cohérence calculs (arrondi, taxes, remises), stock (gap documenté), stale price mid-session.

**Axe 2 — Wizard deep** : tous les templates (8), toutes les étapes, tous les cas limites,
navigation arrière, état, edge cases (0 suppléments, pas de variantes, sauce vide, liste vide).

Niveau : **audit massif UI/UX/data/logique** avec corrections ciblées uniquement.
Aucun ajout de feature hors gap critique. Aucune refonte visuelle.

---

## Contexte technique (résultat de l'exploration pré-task)

### Synchronisation — état actuel
| Système | État | Détail |
|---|---|---|
| Idempotency (kiosk) | ✅ solide | Cache lock + DB unique + QueryException 23000 catch |
| Idempotency (POS) | ✅ solide | Même pattern |
| Queue number allocation | ✅ atomique | `Cache::lock('queue_lock_{branch}_{date}', 10)` |
| Loyalty deduction | ✅ atomique | `lockForUpdate()` sur User + ledger `loyalty_transactions` |
| Real-time broadcast | ✅ dual-layer | Echo WebSocket (ShouldBroadcastNow) + polling 30s fallback |
| KDS debounce | ✅ optimisé | 300ms debounce, cleanup on unmount |
| OSS de-duplication | ✅ présent | `_echoMarkedReady` set pour éviter double chime/flash |
| **Stock management** | ❌ absent | Aucune colonne stock/is_available, aucune validation |
| **Prix stale mid-session** | ⚠️ partiel | IndexedDB 24h — affichage kiosk peut être périmé |
| **Arrondi calculs** | ⚠️ absent | Pas de `round(x, 2)` avant sauvegarde en DB |
| **Sauce step vide** | ⚠️ UX | Étape sauce toujours affichée même si 0 options de sauce |
| **Taille heuristic** | ⚠️ fragile | Parsing nom item pour détecter "xl", "2 viande", "l " |
| **Kiosk timeout** | ⚠️ drift | Code = 60s, spec docs = 3 min |

### Wizard — 8 templates avec séquences d'étapes
| Template | Étapes |
|---|---|
| tacos | [taille?] → viande → sauce → garnitures → supplements → menu → recap |
| sandwich | pain → [viande?] → sauce → garnitures → supplements → menu → recap |
| burger | viande → sauce → garnitures → supplements → menu → recap |
| assiette | viande → sauce → garnitures → supplements → recap (pas de menu) |
| snacking | sauce → supplements → recap |
| omelette | garnitures → supplements → recap |
| salade | garnitures → sauce → supplements → recap |
| simple | supplements → recap (ou juste recap) |

### Fichiers clés identifiés
- `app/Services/FrontendOrderService.php` — L.113-128 (idempotency), L.194-455 (calcul complet)
- `app/Services/OrderService.php` — L.521-861 (POS même logique)
- `app/Services/KitchenDisplaySystemOrderService.php` — L.42-97 (sort, advance)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` — L.226-297 (templates), L.380-463 (heuristics)
- `resources/js/components/frontend/kiosk/steps/*.vue` — 8 step components
- `resources/js/helpers/kioskPricing.js` — L.1-17 (config), L.60-103 (calcul running total)
- `resources/js/helpers/kioskMenuCache.js` — L.26-90 (IndexedDB 24h snapshot)
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` — L.510-557 (Echo)
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` — L.68-114 (Echo)

---

## Périmètre d'audit et corrections

### PHASE A — Corrections synchronisation (8 items)

#### A1 — Arrondi calcul serveur (RISK: données en DB)
**Fichier** : `app/Services/FrontendOrderService.php` + `app/Services/OrderService.php`
**Problème** : `(price * rate) / 100` en PHP float — pas de `round(x, 2)` avant sauvegarde
**Correction** : ajouter `round($taxPrice, 2)`, `round($verifiedTotalPrice, 2)`, `round($total, 2)` aux points de sauvegarde
**Invariant** : Backend pricing SSOT — correction côté backend uniquement

#### A2 — Arrondi calcul client kioskPricing.js (RISK: affichage)
**Fichier** : `resources/js/helpers/kioskPricing.js`
**Problème** : ratio 0.6 * price peut donner €2.3999999 au lieu de €2.40
**Correction** : `Math.round(result * 100) / 100` dans `getKioskMenuAddonPrice()` et `calculateKioskRunningTotal()`

#### A3 — Sauce step affichée même si vide
**Fichier** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Problème** : `shouldShowStep('sauce')` retourne toujours true
**Correction** : vérifier si `item.variations` contient au moins une sauce (group_label==='sauce') avant d'afficher l'étape

#### A4 — Stale price warning (notification kiosk)
**Fichier** : `resources/js/helpers/kioskMenuCache.js` + `KioskWizardComponent.vue`
**Problème** : IndexedDB cache 24h — le prix affiché peut différer du prix facturé
**Correction** : si `savedAt` > 4h ET prix récapitulatif diffère du prix serveur à la soumission → toast "Prix mis à jour"
**Limite** : ne pas modifier FrontendOrderService (frozen)

#### A5 — Kiosk timeout : 60s → 3 min (spec drift)
**Fichier** : composant kiosk idleness (à identifier par GPT-5.4)
**Problème** : code fait reset à 60s, docs disent 3 min
**Décision** : corriger le code à 180 000ms (3 min = comportement attendu restaurant)
**ET** : mettre à jour `docs/BUSINESS_RULES.md` pour documenter la valeur canonique

#### A6 — Commandes simultanées : test de stress PHPUnit
**Fichier** : `tests/Feature/` → créer `ConcurrentOrderTest.php`
**Contenu** :
- Test A : deux requêtes avec le même idempotency_key → une seule commande créée
- Test B : deux requêtes simultanées sans idempotency_key → deux commandes distinctes avec queue_numbers différents
- Test C : loyalty_points = 100, deux requêtes simultanées → points déduits une seule fois

#### A7 — Stock management : documentation du gap
**Fichier** : `docs/BUSINESS_RULES.md`
**Action** : documenter que le stock n'est pas géré en v1 — ajouter section "Stock (non implémenté)" avec le schema prévu
**Pas de code** : la mise en œuvre stock est un cycle futur (hors scope ici)

#### A8 — KDS sort par heure : vérification + tri visuel
**Fichier** : `app/Services/KitchenDisplaySystemOrderService.php`
**Audit** : vérifier que `created_at` DESC est cohérent avec l'affichage KDS (plus ancienne = priorité)
**Correction si besoin** : inverser en ASC (plus vieille commande en haut = priorité chef)

---

### PHASE B — Corrections wizard deep (10 items)

#### B1 — Template 'simple' : étape recap seule si 0 suppléments
**Problème** : wizard template 'simple' avec item sans extras → step supplements affichée vide
**Correction** : si `supplementList.length === 0` ET template 'simple' → skip supplements, aller direct à recap

#### B2 — Sandwich : étape viande conditionnelle
**Problème** : `shouldShowStep('viande')` pour sandwich vérifie `hasViandeVariations()` mais heuristic peut retourner false sur des items qui ont bien des variantes de viande
**Correction** : renforcer `hasViandeVariations()` — vérifier aussi `item.extras` (certains sandwichs ont la viande en extra, pas en variation)

#### B3 — Taille heuristic " l " (faux positif)
**Problème** : `name.includes(' l ')` peut matcher "Formule l'été" ou "Le classique"
**Correction** : regex `\b[lL]\b` (word boundary) OU vérification insensible à la casse + trim

#### B4 — Sauce vide : message explicite
**Problème** : si sauce step s'affiche (après A3) mais avec 0 options → écran blanc
**Correction** : message "Aucune sauce disponible pour ce produit" + bouton "Continuer"

#### B5 — Back navigation : étapes conditionnelles
**Problème** : si user clique "retour" depuis viande → pain → mais pain step était skippée (pas de variations) → index négatif possible
**Correction** : `prevStep()` doit utiliser `visibleSteps` filtré, pas l'index brut

#### B6 — Garnitures init race condition (P2 corrigé — vérification en prod)
**Audit** : vérifier que `userInteracted` flag + `mounted()` adoption fonctionne sur tous les templates
**Test** : ouvrir wizard, aller direct à garnitures sans toucher → vérifier pré-sélections correctes

#### B7 — Menu boisson : sous-sélection obligatoire non forcée
**Problème** : si user choisit "formule complète" ou "boisson seule" mais ne choisit pas de boisson → peut valider recap sans boisson sélectionnée
**Fichier** : `KioskStepMenuComponent.vue`
**Correction** : bloquer "Suivant" si `menuChoice` ∈ ['full', 'boisson'] ET `boissonChoice === null`

#### B8 — Recap : prix fractionnaire non arrondi (lié à A2)
**Problème** : récap peut afficher "€2.3999999" au lieu de "€2.40"
**Correction** : `toFixed(2)` sur tous les prix dans `KioskOrderSummaryComponent.vue`

#### B9 — Multi-produits : test wizard enchaîné
**Scénario** : commande 3 produits différents de templates différents (tacos + sandwich + simple)
**Audit** : vérifier que les selections du produit 1 ne contaminent pas le wizard du produit 2
**Correction si besoin** : reset complet de `selections` à chaque `openWizard()`

#### B10 — Wizard sans catégorie (item.category null)
**Problème** : `item.category?.wizard_template` (P1 corrigé) — mais `item.category` peut être null si chargement API partiel
**Correction** : vérifier que le fallback 'simple' s'applique proprement ET que l'item s'affiche quand même

---

### PHASE C — Tests massifs (Playwright + PHPUnit)

#### Tests PHPUnit à créer
- `ConcurrentOrderTest.php` (A6) — idempotency + queue + loyalty concurrence
- Étendre `AntiGravityFinalTest.php` — vérifier total arrondi (A1)

#### Tests Playwright à créer / étendre (`tests/e2e/`)
- `05-kiosk-wizard-templates.spec.js` — wizard tacos XL, sandwich, simple : flow complet
- `06-kiosk-multiproduct.spec.js` — 3 produits de templates différents sans contamination
- `07-kiosk-menu-boisson.spec.js` — validation bouton bloqué si boisson non choisie (B7)
- `08-kds-simultaneous.spec.js` — KDS reçoit ordre et affiche sans crash

#### Stratégie test déclarée
`playwright-critical-flow` + `local-validation` (PHPUnit)
Flows Playwright obligatoires après corrections :
1. Kiosk tacos XL : taille → 2 viandes → sauce → garnitures → supplément → formule frites → boisson obligatoire → recap → prix arrondi
2. Kiosk sandwich : pain → sauce vide gérée → supplements → menu → recap
3. Kiosk 3 produits : wizard tacos + wizard simple → 2 lignes distinctes panier
4. POS : login → surface sans crash JS (déjà couvert)
5. KDS : login chef → surface sans crash (déjà couvert)

---

## Critères d'acceptation

- [ ] A1 : `round(x, 2)` sur total, tax, subtotal avant sauvegarde (FrontendOrderService + OrderService)
- [ ] A2 : prix arrondi dans kioskPricing.js — aucun affichage €2.3999
- [ ] A3 : sauce step masquée si 0 options de sauce pour l'item
- [ ] A4 : toast "Prix mis à jour" si stale cache > 4h et différence de prix détectée
- [ ] A5 : idle timeout kiosk = 180 000ms (3 min) + BUSINESS_RULES.md mis à jour
- [ ] A6 : ConcurrentOrderTest.php — 3 tests passent (idempotency, queue, loyalty)
- [ ] A7 : docs/BUSINESS_RULES.md — section stock documentée
- [ ] A8 : KDS sort confirmé ou corrigé (plus ancienne commande en priorité)
- [ ] B1 : wizard simple sans suppléments → skip step, recap directement
- [ ] B2 : sandwich viande — vérification extras incluse
- [ ] B3 : taille heuristic " l " — regex word boundary
- [ ] B4 : sauce step vide → message explicite + bouton continuer
- [ ] B5 : back navigation sur étapes conditionnelles — pas d'index négatif
- [ ] B7 : boisson obligatoire si formule boisson ou complète choisie
- [ ] B8 : recap prix → `toFixed(2)` partout
- [ ] B9 : wizard reset entre produits — pas de contamination selections
- [ ] B10 : item.category null → fallback 'simple' propre
- [ ] Tests : 191 PHPUnit + nouveaux ConcurrentOrderTest passent
- [ ] Playwright : flows 1-3 ci-dessus passent

---

## Périmètre

**In scope :**
- `app/Services/FrontendOrderService.php` — arrondi uniquement (A1) — lecture uniquement sinon
- `app/Services/OrderService.php` — arrondi uniquement (A1) — lecture uniquement sinon
- `app/Services/KitchenDisplaySystemOrderService.php` — sort KDS (A8)
- `resources/js/helpers/kioskPricing.js` — arrondi (A2)
- `resources/js/helpers/kioskMenuCache.js` — stale price check (A4)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` — A3, B1-B3, B5, B9, B10
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue` — B7
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue` — B4
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` — B8
- `resources/js/components/frontend/kiosk/[idle-component]` — A5 (timeout)
- `tests/Feature/ConcurrentOrderTest.php` — création (A6)
- `tests/e2e/05-*.spec.js` à `08-*.spec.js` — création (Phase C)
- `docs/BUSINESS_RULES.md` — A5 + A7

**Explicitly out of scope :**
- `app/Services/OrderService.php` — frozen zone (sauf A1 arrondi)
- `app/Services/FrontendOrderService.php` — frozen zone (sauf A1 arrondi)
- Implémentation complète du stock (cycle futur)
- Intégration Pusher/Soketi (configuration serveur)
- Refonte visuelle
- Migrations DB (hors arrondi si nécessaire)

**Gate obligatoire avant A1 :**
Tout changement dans FrontendOrderService ou OrderService (même arrondi) nécessite
une gate humaine avant exécution — ces services sont frozen et toute modification,
même mineure, peut affecter les totaux des commandes en production.

---

## branch_id Impact
[x] branch_id scoping affecté — KDS, OSS, kiosk chargent tous par branch_id
    Aucune correction ne doit affaiblir l'isolation

## Invariants at Risk
[x] Backend pricing SSOT — A1 modifie les services de calcul → gate obligatoire
[x] OrderStatus enum — A8 touche l'ordre d'affichage KDS — vérifier enum cohérent
[x] branch_id data isolation — ne pas casser
[x] Dispatch after DB commit — A1 ne doit pas déplacer le dispatch avant commit
[x] Frozen zone — FrontendOrderService + OrderService : gate avant toute modification

## Anticipated Gate Conditions
[x] GATE OBLIGATOIRE avant A1 — modification des services frozen (arrondi)
    Gate brief : décrire exactement les lignes modifiées, impact sur totaux existants, rollback
[x] Gate si B9 révèle contamination des selections entre produits → pourrait toucher kioskCart
[ ] Gate si A8 inverse le sort KDS → impact opérationnel réel (les chefs voient différemment)

## Test Strategy
`playwright-critical-flow` + `local-validation`

## PRIMARY_MODEL
[x] GPT-5.4 — complex implementation
    (multi-fichiers, logique concurrence, wizard edge cases, tests PHPUnit nouveaux)
    Planning complet + gate arbitrage + audit final : Claude Opus 4.6

## Status
[x] Pending plan
[ ] Plan approved
[ ] In execution
[ ] Validation
[ ] Audit
[ ] Gate open
[ ] Closed
