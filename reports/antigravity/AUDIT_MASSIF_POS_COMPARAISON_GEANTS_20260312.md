# AUDIT MASSIF — POS CAISSE : LOGIQUE & VISUEL
## Comparaison McDonald's, KFC, GUR KEBAB vs FoodKing

**Date :** 12 Mars 2026  
**Type :** Audit documentaire — Aucune modification de code  
**Objectif :** Audit logique et visuelle étape par étape, comparaison avec les géants, identification des corrections possibles

---

## TABLE DES MATIÈRES

1. [Résumé exécutif](#1-résumé-exécutif)
2. [Benchmark — McDonald's POS](#2-benchmark--mcdonalds-pos)
3. [Benchmark — KFC, Starbucks, Subway](#3-benchmark--kfc-starbucks-subway)
4. [Comparaison étape par étape : McDo vs FoodKing](#4-comparaison-étape-par-étape--mcdo-vs-foodking)
5. [Audit logique FoodKing POS](#5-audit-logique-foodking-pos)
6. [Audit visuel FoodKing POS](#6-audit-visuel-foodking-pos)
7. [Matrice des corrections possibles](#7-matrice-des-corrections-possibles)
8. [Plan d'action priorisé](#8-plan-daction-priorisé)

---

## 1. RÉSUMÉ EXÉCUTIF

| Élément | McDo / KFC | FoodKing | Écart |
|---------|------------|----------|-------|
| **Vitesse** | 1 tap = combo complet | 1 tap = item, puis modal customisation | ⚠️ Plus de clics |
| **Boutons** | 44–64px (touch target) | ~18–24px (boutons +/-) | ❌ Sous WCAG |
| **Total visible** | Toujours en bas | Partiel (panier latéral) | ⚠️ |
| **Combo** | Bouton unique "Meal" | Addons séparés | ⚠️ |
| **Rupture stock** | Non (McDo) | Non documenté | ❌ |
| **KDS temps réel** | Envoi avant paiement | Après paiement | ⚠️ |
| **Upsell** | Contextuel intégré | Addons dans wizard | ✅ |

**Verdict :** FoodKing a une base solide (wizard, images, panier) mais des écarts significatifs sur la vitesse, l'accessibilité tactile et la visibilité du total.

---

## 2. BENCHMARK — McDonald's POS

### 2.1 Architecture McDo (sources : ConnectPOS, McDonald's POS Training)

| Composant | Description McDo |
|-----------|------------------|
| **Menu Category** | Boutons gauche/haut — Burgers, Chicken, Sides, Drinks |
| **Item Grid** | Grille d'items par catégorie |
| **Meal vs À la carte** | 1 tap = combo complet (burger + frites + boisson) |
| **Customization** | Panel modificateurs (sauce extra, sans oignon) |
| **Order Summary** | Toujours visible — total, taxes, customisations |
| **Payment** | Cash, card, mobile, exact change |

### 2.2 Principes de vitesse McDo

1. **Speed-first** : Minimisation de l'input humain
2. **Boutons prédéfinis** : Combos, tailles par défaut
3. **POS → KDS temps réel** : Envoi avant paiement (préparation dès le 1er tap)
4. **Queue management** : Drive-thru prioritaire, "parking" pour grosses commandes
5. **Standardisation** : Même layout dans tous les restaurants
6. **A/B testing** : Données comportementales pour optimiser UI

### 2.3 Ce que McDo apprend

- **1 tap = combo** : pas de sélection item par item pour un menu
- **Boutons larges** : réduction des erreurs en rush
- **Total toujours visible** : confirmation client
- **KDS avant paiement** : gain de temps cuisson

---

## 3. BENCHMARK — KFC, Starbucks, Subway

### 3.1 KFC

| Élément | KFC |
|---------|-----|
| Multi-écran | Comptoir fixe, handheld, kiosk, mobile |
| Navigation | Catégories + images produits |
| Customisation | Taille, accompagnements, sauces |
| Paiement | CB, Apple Pay, Google Pay, cash |

### 3.2 Subway (référence UX)

- **Simplicité** : Interface si intuitive que formation annulée
- **Interaction client** : Plus de temps pour parler au client
- **KISS** : Pas de cluttered screens, pas de pop-ups excessifs

### 3.3 Principes UI/UX POS (BPA, Agentestudio)

| Principe | Description |
|----------|-------------|
| **Simplicité** | KISS — éviter complexité cognitive |
| **Navigation** | Catégories logiques, hiérarchie visuelle |
| **Cohérence** | Même placement des contrôles partout |
| **Touch target** | 44–48px minimum (WCAG 24px, pratique 44–64px) |
| **Feedback** | Retour immédiat sur chaque action |
| **Contraste** | Texte lisible, contrastes suffisants |

---

## 4. COMPARAISON ÉTAPE PAR ÉTAPE : McDo vs FoodKing

### Étape 1 : Choix type de commande

| McDo | FoodKing |
|------|----------|
| Dine-In / Takeaway / Drive-thru en premier | ✅ Emporter / Livraison (Dine-In masqué) |
| Visible immédiatement | Dans le panier latéral (droite) |

**Verdict :** ✅ FoodKing aligné. Mais le type est dans le panier, pas en haut — le caissier doit scroller ou voir le panier.

---

### Étape 2 : Navigation catégories

| McDo | FoodKing |
|------|----------|
| Boutons larges, gauche ou haut | Swiper horizontal, catégories w-28 |
| Icône + texte | Icône h-7 + texte text-xs |
| Toujours visibles | Scroll horizontal si > 1 écran |

**Verdict :** ⚠️ FoodKing OK mais icônes petites (h-7 ≈ 28px). Zone tactile w-28 ≈ 112px — correct.

---

### Étape 3 : Sélection produit

| McDo | FoodKing |
|------|----------|
| 1 tap = ajout direct (si pas de custom) | 1 tap = ouverture modal |
| Combo = 1 tap | Addons = étapes séparées dans wizard |
| Boutons produits ~120×120px | Cartes minmax(140px, 185px) |

**Verdict :** ⚠️ FoodKing exige toujours le modal (même pour items simples). McDo : item simple = 1 tap direct.

---

### Étape 4 : Customisation (modal)

| McDo | FoodKing |
|------|----------|
| Panel latéral ou overlay | Modal max-w-[647px] |
| Modificateurs visibles d'un coup | Wizard multi-étapes (Viande → Sauce → Garniture → Suppléments → Addons) |
| Boutons 44–64px | Boutons +/- 18×18px, variations 60px min-height |

**Verdict :** ❌ Boutons +/- 18×18px sous WCAG (24px min). Variations/extras 60px OK. Images 40×40px récentes ✅.

---

### Étape 5 : Révision commande (panier)

| McDo | FoodKing |
|------|----------|
| Order summary toujours visible | Panier latéral fixe (322–360px) |
| Total visible | Subtotal, discount, total en bas du panier |
| Modifications rapides | Table avec qty, delete, edit |

**Verdict :** ✅ Panier structuré. ⚠️ total peut être scrollé hors vue si panier long.

---

### Étape 6 : Réduction / Coupon

| McDo | FoodKing |
|------|----------|
| Intégré au flow | Dropdown % / fixe + input + Apply |
| 1 tap pour appliquer | 2 actions (champ + bouton Apply) |

**Verdict :** ⚠️ FoodKing plus verbeux. McDo : champ coupon + auto-apply.

---

### Étape 7 : Paiement

| McDo | FoodKing |
|------|----------|
| Cash, card, mobile, exact change | Cash, Card (PaymentComponent) |
| Envoi KDS avant paiement | Envoi après paiement |

**Verdict :** ⚠️ FoodKing envoie après paiement → délai cuisson vs McDo.

---

### Étape 8 : Client / Token

| McDo | FoodKing |
|------|----------|
| Optionnel (drive-thru) | Client obligatoire (vue-select) |
| Token / numéro de commande | Token input manuel |

**Verdict :** ✅ FoodKing gère client + token. McDo : numéro voiture ou ticket.

---

## 5. AUDIT LOGIQUE FoodKing POS

### 5.1 Flux de commande

| Élément | État | Remarque |
|---------|------|----------|
| Type commande en premier | ✅ | Emporter / Livraison |
| Catégories → Items | ✅ | Filtrage correct |
| Modal wizard | ✅ | Viandes, sauces, garnitures, suppléments, addons |
| Panier | ✅ | Add, delete, qty |
| Discount | ✅ | % ou fixe |
| Paiement | ✅ | Cash / Card |
| Envoi KDS | ⚠️ | Après paiement (pas avant) |

### 5.2 Problèmes logiques identifiés

| ID | Problème | Sévérité |
|----|----------|----------|
| L1 | Envoi KDS après paiement | Moyenne — perte temps cuisson |
| L2 | Pas de "1 tap = combo" pour items simples | Moyenne |
| L3 | Client obligatoire — peut être vide ? | À vérifier |
| L4 | Token vide — comportement | Documenté |
| L5 | Idempotency double paiement | Non documenté |
| L6 | Rupture stock — items/extras non filtrés visuellement | Moyenne |

### 5.3 Points forts logiques

- Wizard multi-étapes cohérent
- Recalcul prix serveur (SSOT)
- Validation item_id, quantity
- Gestion variations, extras, addons
- Discount % ou fixe

---

## 6. AUDIT VISUEL FoodKing POS

### 6.1 Tailles des éléments (mesures code)

| Élément | Taille | WCAG / Pratique | Verdict |
|---------|--------|-----------------|---------|
| Boutons +/- | 18×18px | 24px min, 44px recommandé | ❌ |
| Bouton "Ajouter" | py-1 px-2 (≈24×32px) | 44px | ❌ |
| Cartes produits | 140–185px | OK | ✅ |
| Images produits | pos-image-height | OK | ✅ |
| Variations / Extras | min-h-[60px], img 40×40 | OK | ✅ |
| Catégories | w-28, h-7 img | Zone tactile OK | ⚠️ |
| Input quantité | w-7 (28px) | Petit | ⚠️ |

### 6.2 Hiérarchie visuelle

| Élément | État |
|---------|------|
| Titres | text-sm, font-medium |
| Prix | text-sm, font-rubik |
| Labels | text-xs |
| Détails panier | text-[10px] — très petit |

**Problème :** text-[10px] pour variations/extras dans le panier — difficile à lire en rush.

### 6.3 Couleurs et contrastes

| Élément | Couleur | Remarque |
|---------|---------|----------|
| Primary | #008BBA, primary | Cohérent |
| Bordure | #EFF0F6, #D9DBE9 | OK |
| Fond panier | #FFEDF4 (header) | OK |
| Texte | text-heading, #2E2F38 | À vérifier contraste WCAG |

### 6.4 Espacement et densité

| Zone | État |
|------|------|
| Grille produits | gap-3, gap-[18px] |
| Modal | max-w-[647px], padding |
| Panier | max-h-[calc(100dvh-600px)] scroll |

**Problème :** Panier scrollable — total peut sortir de la vue.

### 6.5 Feedback utilisateur

| Action | Feedback |
|--------|----------|
| Clic sur item | Ouverture modal |
| Sélection variation | Classe "active" |
| Ajout panier | Panier mis à jour |
| Erreur | alertService |

**Manquant :** Pas de feedback visuel type "shake" ou animation sur ajout réussie.

### 6.6 Corrections visuelles possibles

| ID | Correction | Impact |
|----|------------|--------|
| V1 | Boutons +/- 44×44px | Accessibilité, rush |
| V2 | Bouton "Ajouter" min 44px height | Touch target |
| V3 | Total toujours visible (sticky footer panier) | Confirmation |
| V4 | text-[10px] → text-xs au minimum | Lisibilité |
| V5 | Indicateur scroll catégories | Découvrabilité |
| V6 | Badge "EN RUPTURE" sur items | Conformité |
| V7 | Animation feedback sur ajout panier | UX |
| V8 | Zone tactile addons 70px → 80px | Touch |

---

## 7. MATRICE DES CORRECTIONS POSSIBLES

### 7.1 Priorité P0 (critique)

| ID | Correction | Fichier | Effort |
|----|------------|---------|--------|
| P0-1 | Boutons +/- 44×44px | ItemComponent, PosComponent | 1h |
| P0-2 | Total sticky en bas du panier | PosComponent | 2h |

### 7.2 Priorité P1 (haute)

| ID | Correction | Fichier | Effort |
|----|------------|---------|--------|
| P1-1 | Bouton "Ajouter" min 44px | ItemComponent | 1h |
| P1-2 | text-[10px] → text-xs panier | PosComponent | 1h |
| P1-3 | Rupture stock visible | ItemComponent, API | 4h |
| P1-4 | 1 tap = ajout direct (items sans custom) | ItemComponent, pos-wizard | 4h |

### 7.3 Priorité P2 (moyenne)

| ID | Correction | Fichier | Effort |
|----|------------|---------|--------|
| ~~P2-1~~ | ~~Envoi KDS avant paiement~~ | ❌ NON VALIDÉ | — |
| P2-2 | Indicateur scroll catégories | PosComponent | 1h |
| P2-3 | Animation feedback ajout panier | ItemComponent | 2h |
| P2-4 | Combo 1 tap (menu frites boisson) | Config, wizard | 6h |

### 7.4 Priorité P3 (basse)

| ID | Correction | Fichier | Effort |
|----|------------|---------|--------|
| P3-1 | Idempotency key paiement | PaymentComponent, API | 3h |
| P3-2 | Allergènes sur items | Item model, modal | 4h |
| P3-3 | Accessibilité WCAG audit complet | Global | 8h |

---

## 8. PLAN D'ACTION PRIORISÉ

### Phase 1 — Quick wins (4h)

1. **P0-1** : Boutons +/- 44×44px
2. **P0-2** : Total sticky panier
3. **P1-1** : Bouton Ajouter 44px
4. **P1-2** : text-xs panier

### Phase 2 — UX vitesse (6h)

1. **P1-4** : 1 tap ajout direct items simples
2. ~~**P2-1** : Envoi KDS avant paiement~~ ❌ **NON VALIDÉ** — conserver envoi après paiement
3. **P2-2** : Indicateur scroll catégories

### Phase 3 — Fonctionnel (12h)

1. **P1-3** : Rupture stock
2. **P2-4** : Combo 1 tap
3. **P2-3** : Animation feedback

### Phase 4 — Conformité (15h)

1. **P3-1** : Idempotency
2. **P3-2** : Allergènes
3. **P3-3** : Audit WCAG

---

## ANNEXE A — Références

- ConnectPOS : McDonald's POS System
- WCAG 2.5.8 : Target size 24×24px minimum
- BPA POS : User Interface Design
- RAPPORT_AUDIT_FINAL_CONSOLIDE_20260310.md
- docs/ORDER_FLOW.md, BUSINESS_RULES.md

---

## ANNEXE B — Checklist validation manuelle

| # | Vérification | Comment |
|---|--------------|---------|
| C1 | Boutons +/- 44px | Mesurer après implémentation |
| C2 | Total visible sans scroll | Panier avec 10 items |
| C3 | 1 tap item simple | Item sans variations (ex. Frites) |
| C4 | KDS avant paiement | Vérifier temps écran cuisine |
| C5 | Rupture stock | Items status=0 grisés |
| C6 | Contraste WCAG | Outil axe DevTools |

---

**FIN AUDIT MASSIF — 12 Mars 2026**
