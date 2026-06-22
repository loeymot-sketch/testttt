# Proposition de cleanup dashboard V1 — FoodKing — 2026-05-02

> **Ce document est une proposition pour validation user.** Aucune suppression sans confirmation explicite. Trois niveaux : **GARDE V1** / **DIFFÉRER V2** / **SUPPRIMER MAINTENANT**.

**Contexte user :** « Je vois beaucoup de complexité et beaucoup de choses inutiles dans cette version actuelle. […] supprimer toute chose qui ne va pas donner de valeur dans notre version actuelle. » (2026-05-02)

**Hypothèse FoodKing :** fast-food avec POS + Kiosk + KDS + commande sur place / à emporter. Pas (ou peu) de service à table. Pas (ou peu) de livraison interne. Pas de site marketing prioritaire pour V1.

> Si une de ces hypothèses est fausse (ex : tu fais aussi de la livraison ou du service à table), dis-le, j'ajuste le tableau.

---

## Tableau de classification (28 modules admin + sous-réglages)

### Légende
- 🟢 **GARDE V1** — fonctionnalité indispensable au métier fast-food.
- 🟡 **DIFFÉRER V2** — fonctionnalité utile mais pas V1-bloquante. Désactiver visuellement (cacher du menu admin), garder le code.
- 🔴 **SUPPRIMER MAINTENANT** — pas de valeur V1, alourdit l'UI/UX/codebase. À retirer (routes + composants + controllers Vue/Laravel + tests + i18n).
- ⚠️ **GATE HUMAIN si DROP TABLE** — ne JAMAIS supprimer de table DB sans approbation explicite (préserve historique + NF525).

### Modules principaux (`resources/js/components/admin/`)

| # | Module | Recommandation | Raison | Risque suppression | Effort |
|---|---|---|---|---|---|
| 1 | `pos/` | 🟢 GARDE V1 | Cœur caisse | — | — |
| 2 | `posOrders/` | 🟢 GARDE V1 | Suivi commandes en cours / archive | — | — |
| 3 | `kitchenDisplaySystem/` | 🟢 GARDE V1 | KDS cuisine | — | — |
| 4 | `orderStatusScreen/` | 🟢 GARDE V1 | OSS écran client | — | — |
| 5 | `items/` | 🟢 GARDE V1 | Gestion produits | — | — |
| 6 | `stock/` | 🟢 GARDE V1 | Gestion stock | — | — |
| 7 | `dashboard/` | 🟢 GARDE V1 | Page d'accueil admin | (à nettoyer voir §4 ci-dessous) | — |
| 8 | `transactions/` | 🟢 GARDE V1 | Trace fiscale + comptable | — | — |
| 9 | `salesReport/` | 🟢 GARDE V1 | Rapport ventes (Z) | — | — |
| 10 | `itemsReport/` | 🟢 GARDE V1 | Rapport produits | — | — |
| 11 | `settings/` | 🟢 GARDE V1 (avec ménage interne, voir §3) | Branches, taxes, etc. | — | — |
| 12 | `profile/` | 🟢 GARDE V1 | Profil utilisateur connecté | — | — |
| 13 | `administrators/` | 🟢 GARDE V1 | Gestion comptes admins | — | — |
| 14 | `employees/` | 🟢 GARDE V1 | Gestion comptes caissiers | — | — |
| 15 | `customers/` | 🟡 DIFFÉRER V2 | DB client utile mais pas V1-bloquant si pas de fidélité active | Conserve le module, cache du menu | S |
| 16 | `coupons/` | 🟡 DIFFÉRER V2 | Promos non implémentées au POS V1 (pas de bouton « appliquer code promo » testé) | Cache du menu admin | S |
| 17 | `offers/` | 🟡 DIFFÉRER V2 | Offres marketing | Cache | S |
| 18 | `messages/` | 🔴 SUPPRIMER | Module messagerie interne — pas V1 | Routes + composants + controller | M |
| 19 | `subscribers/` | 🔴 SUPPRIMER | Newsletter site web — hors scope fast-food | Routes + composants + controller (⚠️ DROP TABLE = gate) | M |
| 20 | `pushNotification/` | 🔴 SUPPRIMER | Notifs mobile customer app — pas de customer app V1 | Routes + composants + controller | M |
| 21 | `creditBalanceReport/` | 🟡 DIFFÉRER V2 | Solde crédit client (lié `customers`) — utile si fidélité | Cache du menu | S |
| 22 | `deliveryBoys/` | ⚙️ DÉPEND | Si tu ne fais PAS de livraison V1 → 🔴 SUPPRIMER. Sinon 🟢 GARDE. | Routes + composants + controller (⚠️ DROP TABLE = gate) | M |
| 23 | `waiters/` | ⚙️ DÉPEND | Si tu ne fais PAS de service à table V1 → 🔴 SUPPRIMER. Sinon 🟢 GARDE. | (⚠️ DROP TABLE = gate) | M |
| 24 | `chefs/` | ⚙️ DÉPEND | Si tu ne sépares pas chefs vs employés → 🔴 SUPPRIMER. | (⚠️ DROP TABLE = gate) | M |
| 25 | `tableOrders/` | ⚙️ DÉPEND | Service à table → 🔴 si fast-food pur. | (⚠️ DROP TABLE = gate) | M |
| 26 | `diningTable/` | ⚙️ DÉPEND | Service à table → 🔴 si fast-food pur. | (⚠️ DROP TABLE = gate) | M |
| 27 | `onlineOrders/` | ⚙️ DÉPEND | Vente en ligne (site web) → 🔴 si pas de site web V1. | M |
| 28 | `components/` | 🟢 GARDE V1 | Composants partagés (BackendNavbar, etc.) | — | — |

### Sous-modules `settings/`

| # | Sous-module | Recommandation | Raison | Effort |
|---|---|---|---|---|
| 1 | `company` | 🟢 GARDE V1 | Identité entreprise (NF525) | — |
| 2 | `branches` | 🟢 GARDE V1 | Multi-restaurant | — |
| 3 | `mail` | 🟡 DIFFÉRER V2 | Envoi mails (reset password seulement V1) | S |
| 4 | `order-setup` | 🟢 GARDE V1 | Param commandes | — |
| 5 | `kiosk-setup` | 🟢 GARDE V1 | Param kiosk | — |
| 6 | `loyalty-setup` | 🟡 DIFFÉRER V2 | Lié coupons / customers | S |
| 7 | `otp` | 🟢 GARDE V1 | Auth OTP | — |
| 8 | `notification` | 🟡 DIFFÉRER V2 | Préférences notifs | S |
| 9 | `social-media` | 🔴 SUPPRIMER | Liens marketing site web | S |
| 10 | `cookies` | 🔴 SUPPRIMER | RGPD site web (pas dans backoffice caisse) | S |
| 11 | `analytics` | 🔴 SUPPRIMER | Google Analytics, etc. — site web | S |
| 12 | `theme` | 🟡 DIFFÉRER V2 | Custom theme — V1 = thème par défaut | S |
| 13 | `time-slots` | 🟢 GARDE V1 | Créneaux horaires | — |
| 14 | `sliders` | 🔴 SUPPRIMER | Carrousel site web frontend | S |
| 15 | `currencies` | 🟢 GARDE V1 | Devise | — |
| 16 | `item-categories` | 🟢 GARDE V1 | Catégories produits | — |
| 17 | `item-attributes` | 🟢 GARDE V1 | Attributs (viandes, sauces) | — |
| 18 | `site` | 🔴 SUPPRIMER | Configuration site web (titre, description) | S |

---

## §3 — Réglages internes à nettoyer dans `settings/` (sans supprimer)

Même dans les sous-modules gardés, certains champs sont parasitaires V1. À cacher dans le formulaire (pas supprimer la colonne DB) :

- `settings/company` : champs Facebook URL, Twitter URL, Instagram URL, etc. → cacher si on supprime `social-media`.
- `settings/site` : tout → supprimer la page.

---

## §4 — Dashboard d'accueil (`dashboard/`) — refonte légère V1

Le dashboard actuel (à confirmer) montre probablement KPI marketing + beaucoup de widgets. **Proposition V1** :

Garder seulement 4 widgets utiles fast-food :
1. **Ventes du jour** (CA, nb tickets) avec filtre branche.
2. **Top 5 produits du jour** (volume).
3. **Stock low alerts** (lien vers `StockRuptureDashboard` M2 2.1).
4. **Z-report dernier** (lien vers fiscal archive).

Retirer (à confirmer si présents) :
- Newsletter subscribers count.
- Site web visitors / page views.
- Push notifications sent.
- Coupons used (si DIFFÉRÉ).
- Messages unread (si SUPPRIMÉ).

---

## §5 — Plan d'exécution proposé (2 lots + gates)

### Lot A — Cleanup pur frontend (zéro gate, zéro DROP TABLE) — **routine, 1 cycle**

Supprimer **uniquement les routes + composants Vue + entrées menu admin** pour :
- `messages/`, `pushNotification/`, `subscribers/` (frontend uniquement — backend reste, table DB reste)
- `settings/social-media`, `settings/cookies`, `settings/analytics`, `settings/sliders`, `settings/site`
- Cacher du menu : `customers`, `coupons`, `offers`, `creditBalanceReport`, `settings/mail`, `settings/loyalty-setup`, `settings/notification`, `settings/theme`

**Effet :** dashboard admin visuellement épuré ; aucun risque DB ; les controllers Laravel restent en place mais ne sont plus accessibles via UI.

**Effort :** 1 cycle routine (Composer), ~2h. Sentinel : tests existants ne doivent pas casser ; quelques tests UI à mettre à jour.

### Lot B — Décision métier (gates à clarifier avec toi avant exécution)

| Question | Si OUI | Si NON |
|---|---|---|
| FoodKing fait-il de la livraison interne en V1 ? | Garde `deliveryBoys/` | 🔴 Supprime (gate DROP TABLE) |
| FoodKing fait-il du service à table en V1 ? | Garde `waiters`, `chefs`, `tableOrders`, `diningTable` | 🔴 Supprime les 4 (gate DROP TABLE × 4) |
| FoodKing a-t-il un site web de commande en ligne en V1 ? | Garde `onlineOrders/` | 🔴 Supprime (gate DROP TABLE) |

**Effet :** suppression réelle (frontend + backend + tests + données historiques). DROP TABLE requiert gate humain. Effort par module : M.

### Lot C — Refonte dashboard d'accueil (§4)

**Effort :** 1 cycle routine (Composer), ~3h.

---

## §6 — Estimation totale

| Lot | Type | Cycles | Coût Codex | Coût Composer | Gate humain |
|---|---|---|---|---|---|
| A | cleanup frontend pur | 1 | 0 | ~2h | non |
| B (par décision OUI/NON) | DROP TABLE + cleanup full | 1–4 | 0 (Composer suffit) | ~M par module | OUI (1 par module avec DROP) |
| C | refonte dashboard | 1 | 0 | ~3h | non |

**Si tu réponds aux 3 questions du Lot B**, je peux générer les gate briefs + lancer Lot A + Lot C immédiatement. Lot B sera lancé dès gate signé.

---

## §7 — Ce dont j'ai besoin de toi (3 questions courtes)

1. **FoodKing fait-il de la livraison en V1 ?** OUI / NON
2. **FoodKing fait-il du service à table en V1 ?** OUI / NON
3. **FoodKing a-t-il un site web de commande en ligne en V1 ?** OUI / NON

(+) **Confirmes-tu Lot A** (cleanup frontend pur, sans risque) ? **OUI / NON**
(+) **Confirmes-tu Lot C** (refonte dashboard accueil) ? **OUI / NON**

Une fois les réponses, je lance.

---

**Auteur :** Claude in-session
**Date :** 2026-05-02
**Branche :** `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
