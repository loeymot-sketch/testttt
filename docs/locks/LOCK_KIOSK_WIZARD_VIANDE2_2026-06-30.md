# LOCK — KioskWizardComponent.vue : 2ᵉ viande perdue à l'ajout panier (plainte owner)

**Date** : 2026-06-30
**Fichier frozen §7** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Gate** : owner (plainte explicite : « les sandwichs avec viande, je choisis la viande, ça fait une erreur » + « bugs à l'ajout au panier après le wizard »).

## Bug

Sur un produit multi-viandes (Tacos L/XL/XXL, Méga, Terminator, Suprême — le cœur du
menu), le client sélectionne 2 viandes, le wizard affiche **2/2 ✓** et la composition
montre les 2 viandes, MAIS l'article ajouté au panier ne contient que la **1ère** viande
(Viande 1). Conséquence : au paiement, le backend rejette en **422 « Sélectionnez au moins
1 Viande 2 (actuel : 0) »** → la commande échoue. C'est le bug n°1 rapporté par l'owner.

## Root cause

`buildCartItem()` distribue les viandes sur les attributs « Viande N » en cherchant, pour
chaque viande, la variation de CE nom **sous CET attribut** dans `item.variations` :

```js
const allVars = Array.isArray(item.variations) ? item.variations : [];   // ← BUG
```

En production, `item.variations` (issu de la projection menu kiosk) est un **OBJET groupé
par attribut**, pas un tableau → `Array.isArray` = false → `allVars = []` → le `match`
échoue toujours → seule la 1ère viande survit (fallback `idx===0 ? v.id`), la 2ᵉ est
droppée. Le fix P0 précédent (`cfcd27d53`) avait un test qui **mockait un tableau**, donc
il passait au vert sans jamais exercer la forme objet réelle.

## Fix (display/logique d'assemblage, périmètre minimal)

Aplatir `item.variations` quand c'est un objet :

```js
const allVars = Array.isArray(item.variations)
  ? item.variations
  : (item.variations && typeof item.variations === 'object'
    ? Object.values(item.variations).flat()
    : []);
```

`<template>` et `<style>` **intouchés**. Aucune autre logique modifiée.

## Preuve

- Repro headless 1080×1920 AVANT : article panier Tacos L = `[Viande 1: Mexicanos, Sauce]`
  (Viande 2 absente) ; `submitOrder` → 422 « Sélectionnez au moins 1 Viande 2 ».
- APRÈS : article panier = `[Viande 1: Mexicanos (361), Viande 2: Cordon Bleu (369),
  Sauce: Mayonnaise (375)]` ; plus de rejet viande (seuls quote_token/signature manquent =
  flux quote normal).
- Test régression `tests/js/kioskWizardMultiViande.spec.js` : **5/5** dont un nouveau cas
  avec `item.variations` en **forme OBJET** (la vraie forme prod) qui aurait attrapé le bug.

## Baseline SHA

`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` mis à jour :
`2f827c0fe27b…` → `8ef016fbe2bc7e411f1461c87508547cda1aac3c0b24dce51676b759e4714be6`.
