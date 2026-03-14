# AUDIT PRISE DE COMMANDE — NOUS vs EUX
## FoodKing vs McDonald's / KFC / GUR KEBAB

**Date :** 12 Mars 2026  
**Focus :** Parcours prise de commande uniquement  
**Validation :** Tout sauf « Envoi KDS avant paiement » (rejeté)

---

## RÉSUMÉ (RZ)

### Validations confirmées

| Correction | Statut |
|------------|--------|
| Boutons +/- 44×44px | ✅ Validé |
| Total sticky panier | ✅ Validé |
| Bouton Ajouter 44px | ✅ Validé |
| text-xs panier (au lieu de 10px) | ✅ Validé |
| 1 tap ajout direct items simples | ✅ Validé |
| Indicateur scroll catégories | ✅ Validé |
| Rupture stock visible | ✅ Validé |
| Animation feedback ajout panier | ✅ Validé |
| Combo 1 tap | ✅ Validé |
| Idempotency paiement | ✅ Validé |
| Allergènes | ✅ Validé |
| Audit WCAG | ✅ Validé |

### ❌ Non validé

| Correction | Raison |
|------------|--------|
| **Envoi KDS avant paiement** | Rejeté — conserver envoi après paiement |

---

## AUDIT PRISE DE COMMANDE — ÉTAPE PAR ÉTAPE

### Scénario : « Un Tacos M 1 viande, Poulet, Sauce Algérienne, Complet, à emporter »

---

### ÉTAPE 1 : Type de commande

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Type en premier (Drive-thru / Comptoir / Sur place). Toujours visible en haut. | **Nous :** Emporter / Livraison dans le panier latéral (droite). Dine-In masqué. |
| 1 tap = type choisi | 1 tap = type choisi |
| **Position :** En haut, immédiat | **Position :** Panier droit, scroll possible |

**Écart :** Le type est dans le panier. Si le caissier ne regarde pas à droite, il peut oublier. McDo : type visible dès le début.

---

### ÉTAPE 2 : Navigation catégorie

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Boutons gauche ou haut. « Tacos » ou « Burgers » en 1 tap. | **Nous :** Swiper horizontal. Catégories w-28. « Nos Tacos » en 1 tap. |
| Toujours visibles | Scroll horizontal si > 1 écran |
| Icônes + texte | Icônes h-7 + texte |

**Écart :** Si scroll, pas d’indicateur. GUR : barre progression « Étape 1/7 ». Nous : pas d’indicateur scroll catégories.

---

### ÉTAPE 3 : Sélection produit

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Item simple (ex. Frites) = 1 tap direct au panier. Item custom = 1 tap → modal. | **Nous :** Tous les items = 1 tap « Ajouter » → ouverture modal. |
| Combo = 1 tap (burger + frites + boisson) | Addons = étapes séparées dans wizard |
| **Clics :** 1 (item simple) ou 2 (combo) | **Clics :** 1 (ouvrir) + N (wizard) |

**Écart :** Pour « Tacos M 1 viande », nous obligeons toujours le modal. Eux : item avec custom = modal, item simple = direct.

---

### ÉTAPE 4 : Customisation (modal)

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Modificateurs visibles d’un coup ou en 2–3 étapes. Boutons 44–64px. | **Nous :** Wizard : Viande → Sauce → Garniture → Suppléments → Addons. |
| **Ordre :** Souvent logique (base → garniture → sauce) | **Ordre :** Viandes → Sauce → Garnitures → Suppléments → Menu → Sauce frites → Récap |
| **Prix :** Mis à jour en temps réel | **Prix :** totalPriceSetup() à chaque changement |
| **Total visible :** Oui dans le modal | **Total visible :** Oui dans le modal (footer) |

**Écart :** Nous : plus d’étapes, mais plus de contrôle. Eux : plus rapide, moins de clics. Nos boutons +/- 18px (sous WCAG).

---

### ÉTAPE 5 : Viande (Tacos)

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Liste ou grille. 1 tap = choix. | **Nous :** Swiper variations. Radio + image 40×40. 1 tap = choix. |
| **Visuel :** Image + nom + prix | **Visuel :** Image + nom + prix (+X€) |

**Aligné :** ✅ Images récentes. Sélection claire.

---

### ÉTAPE 6 : Sauce

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Liste ou grille. 1ère gratuite souvent. | **Nous :** Swiper. Radio. « Sauce (1ère Gratuite) ». 1 tap = choix. |
| **Visuel :** Icône sauce | **Visuel :** Image + nom |

**Aligné :** ✅

---

### ÉTAPE 7 : Garnitures

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Complet / Sans oignon / Sans tomate etc. | **Nous :** Complet, Sans Oignon, Sans Tomate, Sans Salade, Aucune. |
| **Visuel :** Image par option | **Visuel :** Image + nom |

**Aligné :** ✅ Granularité similaire à GUR.

---

### ÉTAPE 8 : Suppléments

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Checkbox. +prix affiché. | **Nous :** Checkbox. Image 40×40. +prix. |
| **Visuel :** Liste | **Visuel :** Swiper, images |

**Aligné :** ✅ Nous avons les images.

---

### ÉTAPE 9 : Addons (Menu, Frites, Boisson)

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Combo = 1 tap. Ou choix séparé. | **Nous :** Cartes addons. Image 68×70. Qty +/-. |
| **Visuel :** Bouton « En menu » | **Visuel :** Carte avec image, nom, prix |

**Écart :** Eux : 1 tap combo. Nous : 1 tap par addon + qty. Plus flexible mais plus de clics.

---

### ÉTAPE 10 : Récap + Ajouter au panier

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Total visible. Bouton « Ajouter » ou « Valider ». | **Nous :** Total visible. Bouton « Ajouter ». alertService.success. |
| **Feedback :** Item ajouté au panier | **Feedback :** Toast « add_to_cart ». Modal se ferme. |

**Écart :** Pas d’animation visuelle sur le panier. Eux : parfois item qui « vole » vers le panier.

---

### ÉTAPE 11 : Panier (révision)

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Order summary toujours visible. Total en bas. | **Nous :** Panier latéral. Table items. Total en bas. |
| **Modification :** Tap sur item = edit ou delete | **Modification :** Delete icône. Qty +/-. |
| **Scroll :** Total peut rester visible | **Scroll :** Total peut sortir de vue si panier long |

**Écart :** Total sticky recommandé.

---

### ÉTAPE 12 : Client / Token

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Optionnel. Numéro voiture ou ticket. | **Nous :** Client (vue-select). Token (input). |
| **Position :** En haut du panier | **Position :** En haut du panier |

**Aligné :** ✅ Gestion client + token plus complète que McDo.

---

### ÉTAPE 13 : Discount

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Champ coupon ou % rapide. 1–2 taps. | **Nous :** Dropdown %/fixe + input + bouton Apply. |
| **Feedback :** Réduction appliquée | **Feedback :** Total mis à jour |

**Écart :** Nous : 3 actions (dropdown, input, apply). Eux : souvent 1–2.

---

### ÉTAPE 14 : Paiement

| McDo / KFC | FoodKing |
|------------|----------|
| **Eux :** Cash / Card / Mobile. KDS avant paiement (préparation dès commande). | **Nous :** Cash / Card. **KDS après paiement** (validé). |
| **Envoi cuisine :** Avant | **Envoi cuisine :** Après |

**Décision :** Conserver « KDS après paiement ». Pas de modification.

---

## SYNTHÈSE COMPARATIVE

| Phase | McDo / KFC | FoodKing | Écart |
|-------|------------|----------|-------|
| Type commande | 1 tap, visible | 1 tap, dans panier | ⚠️ Position |
| Catégories | 1 tap | 1 tap | ✅ |
| Item simple | 1 tap direct | 1 tap = modal | ❌ |
| Item custom | 1 tap = modal | 1 tap = modal | ✅ |
| Wizard | 2–3 étapes | 5–7 étapes | ⚠️ Plus détaillé |
| Panier | Total visible | Total scrollable | ❌ |
| Paiement | Multi-options | Cash/Card | ✅ |
| KDS | Avant paiement | Après paiement | ⏸️ Non modifié |

---

## PLAN VALIDÉ (SANS KDS AVANT PAIEMENT)

| Phase | Priorité | Corrections |
|-------|----------|-------------|
| **1** | P0 | Boutons +/- 44px, Total sticky |
| **2** | P1 | Bouton Ajouter 44px, text-xs panier |

| Phase | Priorité | Corrections |
|-------|----------|-------------|
| **3** | P1 | 1 tap ajout direct, Rupture stock |
| **4** | P2 | Indicateur scroll, Animation feedback, Combo 1 tap |

| Phase | Priorité | Corrections |
|-------|----------|-------------|
| **5** | P3 | Idempotency, Allergènes, WCAG |

**Exclu :** Envoi KDS avant paiement.

---

**FIN AUDIT PRISE DE COMMANDE — 12 Mars 2026**
