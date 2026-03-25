# Rapport d'Exécution — Round 9 (401 interceptor, cart edit, POS kiosk cash)
**Date :** 2026-03-25 03:20
**Agent :** Claude (Architect & Builder — Round 9)
**Cycle :** Normal Cycle – Kimi-test

---

## Résumé

Round 9 corrige 3 problèmes identifiés à l'audit :
- 1 CRITIQUE (token expiré = échecs API silencieux)
- 1 HIGH UX (articles du panier non éditables)
- 1 HIGH OPS (caissiers aveugles sur les commandes kiosk cash)

Build webpack ✅ | Syntax PHP N/A (fichiers JS uniquement)

---

## Corrections appliquées

### R36 — CRITIQUE : Intercepteur 401 global
**Fichier :** `resources/js/app.js`

Ajout d'un `axios.interceptors.response` après l'intercepteur de requête existant :
- Capture **tous les 401** retournés par l'API
- Si le chemin URL contient `/kiosk` :
  - `store.commit('kioskCart/CLEAR_KIOSK_TOKEN')` — efface le token machine
  - `router.push({ name: 'kiosk.login' })` — renvoie à la page de login borne
- Sinon (interface admin/utilisateur) :
  - `store.dispatch('auth/logout')` — efface la session
  - `router.push({ name: 'auth.login' })` — renvoie au login normal
- **Flag `_401Handling`** (3s cooldown) pour éviter les redirections en boucle si plusieurs requêtes simultanées reçoivent un 401

---

### R37 — HIGH UX : Édition d'un article dans le panier
**Fichiers :**
- `resources/js/store/modules/kioskCart.js`
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue`

**Store** : nouvelle action `popItem(index)` — retire l'article du panier et le retourne (pour pré-remplissage futur).

**KioskCartComponent** :
- Icône crayon ✏️ ajoutée à droite du nom de chaque article (si `item.item_id` présent)
- Méthode `editItem(index)` :
  - Appelle `popItem(index)` → retire l'article du panier
  - Navigue vers `kiosk.wizard/:itemId` → le wizard s'ouvre normalement
  - Le client re-personnalise et ré-ajoute au panier
- CSS dédié `.kiosk-cart-edit-btn` (gris, rouge au hover, 28×28px)
- `.kiosk-cart-item-name-row` (flex avec gap entre nom et bouton edit)

**Comportement intentionnel :** retirer + rouvrir (vs restauration complexe des sélections du wizard) — simple, robuste, identique au comportement Splash.

---

### R38 — HIGH OPS : Badge POS pour commandes borne cash
**Fichier :** `resources/js/components/admin/pos/PosComponent.vue`

Ajouté dans le POS (admin) :

**FAB pulsant (badge flottant)** :
- Apparaît quand des commandes kiosk cash sont en attente (status 4 ou 7)
- Icône 🖥️ + badge rouge avec le nombre de commandes
- Animation pulse rouge

**Panel latéral (drawer)** :
- S'ouvre au clic sur le FAB
- Liste les commandes kiosk avec paiement CASH en attente
- Chaque carte affiche : N° commande, montant total, articles (max 3 + "X autres"), heure, statut "💵 Espèces à encaisser"
- Bouton "Actualiser"

**Polling automatique toutes les 30s** dans `mounted()` + `clearInterval` dans `beforeUnmount`.

**Endpoint** : `GET admin/kds-order?order_type=25&payment_method=1` — filtre côté client par status [4, 7].

---

## Tests

| Type | Résultat |
|------|----------|
| `npm run dev` | ✅ Compiled Successfully in 5442ms |

---

## Fichiers modifiés

| Fichier | Changement |
|---------|-----------|
| `resources/js/app.js` | Ajout intercepteur response 401 global |
| `resources/js/store/modules/kioskCart.js` | Nouvelle action `popItem` |
| `resources/js/components/frontend/kiosk/KioskCartComponent.vue` | Bouton Edit + méthode editItem |
| `resources/js/components/admin/pos/PosComponent.vue` | FAB + panel kiosk cash + polling |

---

## État du système après Round 9

| Module | État |
|--------|------|
| Auth machine kiosk | ✅ Login + token persisté + 401 interceptor |
| Flow commande borne | ✅ idle → catégories → wizard → panier → upsell → paiement → attente → confirmation |
| Fidélité | ✅ Check code OU téléphone, inscription, attribution PREPARED |
| Paiement | ✅ Cash direct, Carte/TR avec écran TPE 5s |
| KDS | ✅ Colonne borne avec N° de queue |
| OSS | ✅ N° queue kiosk affiché |
| POS | ✅ Badge + panel commandes borne cash |
| Sécurité | ✅ CLEAR_TOKEN sur 401, re-login machine |
| Robustesse réseau | ✅ Retry menu/produits, banner connexion perdue, branch error overlay |

---

## Prochaines étapes recommandées

1. **Anti-Gravity E2E** : flow complet navigateur (login borne → commande → attente → confirmation)
2. **TPE webhook** : configurer `POST frontend/order/{id}/payment-confirm` depuis le terminal physique
3. **Ticket d'impression ESC/POS** : connecter le print à une vraie imprimante thermique (port série)
4. **Tests unitaires Jest** : couvrir `kioskCart` mutations/actions (submitOrder, popItem, kioskLogin)
5. **Admin borne** : permettre à un admin de terminer manuellement une commande kiosk cash depuis le panel

---

**Verdict :** APPROVED — Round 9 livré ✅
