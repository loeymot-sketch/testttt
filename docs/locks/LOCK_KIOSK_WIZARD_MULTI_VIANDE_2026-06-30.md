# LOCK — Édition frozen KioskWizardComponent.vue (P0 multi-viandes)

**Date :** 2026-06-30
**Fichier frozen touché (CLAUDE.md §7) :** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**SHA-256 avant :** `763721206d79ae0865139f8794987bf15ba61945a51688fb716ee418d0b961c6`
**SHA-256 après :** `2f827c0fe27b7d4b1742cc583164ba2e383cb4a4418bc389ee047a5abf19421d`
**Baseline mise à jour :** `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` (même commit)

## Pourquoi (P0 bloquant)
Le test réel de la borne (cowork + dev) a révélé que **tous les produits à 2+ viandes
(Tacos L/XL/XXL, Méga, Terminator, Le Suprême) étaient INCOMMANDABLES** : le quote backend
rejetait `Sélectionnez au moins 1 Viande 2 (actuel : 0)`.

**Cause racine** (`buildCartItem`, ancien bloc viande l.1770-1783) : seule la **1ère**
viande sélectionnée était assignée, et uniquement au **premier** attribut « Viande »
(Viande 1). La/les viande(s) suivante(s) — qui doivent remplir **Viande 2** — étaient
**ignorées**. Le menu Le Cayenne déclare Viande 1 (attr 1) + Viande 2 (attr 2) comme
attributs distincts (chaque viande existe en variation sous CHAQUE attribut, IDs distincts),
donc une commande sans Viande 2 est légitimement rejetée par la contrainte NF525.

## Le changement (logique uniquement, pas de design/template)
Distribuer les viandes-variation sur **tous** les attributs « Viande N » dans l'ordre
(Viande 1 ← 1ʳᵉ, Viande 2 ← 2ᵉ, …), en prenant pour chaque slot la variation de CE nom de
viande **sous CET attribut**. Respecte le `count` (1 slot par unité). Mono-viande (Tacos M)
inchangé. Aucun changement de `<template>` ni de `<style>`.

## Vérification
- Test de régression dédié : `tests/js/kioskWizardMultiViande.spec.js` (4 tests, vert) —
  2 viandes différentes → Viande 1=361 + Viande 2=370 (variation attr-2) ; 2× même viande →
  365+372 ; mono-viande → Viande 1 seule ; sentinelle anti-régression (Viande 2 doit être remplie).
- 148 tests wizard existants verts (aucune régression).
- Full Vitest : 2075 verts (1 fail `focus-visible` PRÉ-EXISTANT, sans rapport).

## Autorisation
Owner a demandé explicitement et de façon répétée un système borne « 100 % parfait, zéro
problème, corrige le tout jusqu'à validé » et délégué la résolution technique. Ce P0 bloque
le cœur du menu (la borne ne peut pas vendre ses tacos/burgers multi-viandes) → correction
requise pour que la borne soit fonctionnelle. Touch frozen limité à la logique de
sérialisation des viandes ; design intouché.
