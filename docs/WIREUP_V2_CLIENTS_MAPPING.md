# WIREUP V2 — Mapping mécanique clients (miroirs → API backend) — DOC ONLY, AUCUN câblage V1

> Mandat V1 intact : apps standalone, localStorage, zéro appel API. Ce doc rend le câblage V2 MÉCANIQUE le jour du GO owner. Pattern accepté : `composer_profile` hardcodé miroir (cf. mémoire hinge 2026-06-07).

## 1. Catalogue
| Client (miroir) | Backend (SSOT) | Notes |
|---|---|---|
| `mobile/data/menu.js` + `/Downloads/web/data/menu.js` (41 items + 4 constructs, identiques) | `MenuProjectionService::forChannel('kiosk'|'web', branchId)` → `categories[].items[]` (id, name, price, thumb, variations, extras, addons, composer_profile, availability) | clé de jointure = **name** tant que D9 non tranché ; après D9 → id stables |
| images locales `assets/` | `item.thumb/cover` (URL absolues) | précache SW à adapter (runtime cache même-origine → origin backend) |
| disponibilité (absente côté clients V1) | `is_available` + `unavailable_reason` par item/variation/extra | nouveau : griser/retirer au render |

## 2. Divergences à trancher AVANT câblage (gate D9)
| Miroir clients (owner-locked) | DB backend | Action selon D9 |
|---|---|---|
| Tacos M 6,90 € / Tacos L 8,90 € | Tacos 8,50 € / Big Tacos 11,50 € | A : renommer/ajuster DB au cutover · B : maj miroirs |
| 41 items + 4 constructs (Menu +3,00, Frites Seules, Boisson Seule, Boule gratinée) | 45-63 selon DB (incident DB locale étrangère — D2) | réconciliation post-D2 sur la DB propre |

## 3. Commandes
| Client | Backend | Notes |
|---|---|---|
| panier localStorage (`orders.js` mobile / `js/cart` web) | `POST /api/frontend/order` (FrontendOrderController) — payload item_id, quantity, option_ids UNIQUEMENT (NF525 : prix recalculé backend par PricingService) | la structure « instruction » clients → champ note ; les choix wizard → option_ids réels (jointure par name→id) |
| suivi commande simulé | `GET /api/frontend/order/{id}` + Echo `branch.{id}` (OrderStatusChanged) ou polling | tracker temps réel dispo |
| historique local | `GET /api/frontend/order` (auth Sanctum customer) | nécessite auth réelle |

## 4. Auth & fidélité
| Client | Backend | Notes |
|---|---|---|
| login/OTP simulés | Sanctum customer tokens (`/api/auth/...`) | OTP réel = config SMS à décider |
| points localStorage, earn 1pt/€, redeem linéaire 100pts=1€ (PARITÉ 6/6 prouvée 2026-06-10) | backend loyalty (accrual caisse phone-keyed déjà livré `d2b244df5`) | le barème backend devra adopter le linéaire A ; redeem côté caisse = modal LOCKée existante |

## 5. Étapes mécaniques du jour J (V2)
1. D2 (DB propre) + D9 (nommage) tranchés → table de correspondance name→item_id générée par script (à écrire : compare miroir vs `/api/frontend/items`).
2. Couche `apiClient.js` par produit (fetch + clé API frontend) derrière un flag `WIREUP_ENABLED` default false — l'app reste 100% fonctionnelle offline si OFF (le SW couvre déjà l'app-shell).
3. Remplacer source de vérité écran par écran (menu → commande → auth → loyalty), un commit par écran, e2e + adversaire à chaque étape (triade habituelle).
4. CORS backend : autoriser le domaine D8 ; throttle frontend existant suffit.
