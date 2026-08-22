# Vague 1 — Lignes de base (GOAL CAISSE PARFAITE)

> Prises le 2026-08-22, branche `goal/caisse-parfaite-2026-08-22` depuis `fbe13a48a`.
> Navigateur réel (Chromium/Playwright), `/admin/pos`, compte `pos@lecayenne.fr`, `zoom: 0.9`.

## Porte de vague 1 : les mesures publiées se reproduisent-elles ? → **OUI, à l'identique**

| Gabarit | `main` visible | à défiler | en-tête caché | corps panier | pied bas | grille y | bloc suivi |
|---|---|---|---|---|---|---|---|
| 1366×768 | **53 %** (768/1455) | 687 | **214** (152/366) | 372 | 690 ✅ | **792** | 432 |
| 1280×800 | 55 % (800/1455) | 655 | 207 (159/366) | 395 | 719 ✅ | 792 | 432 |
| 1024×768 | **48 %** (768/1587) | 819 | 214 (152/366) | 372 | 690 ✅ | **910** | 507 |
| 1920×1080 | 97 % (1080/1116) | 36 | 43 (323/366) | 499 | 971 ✅ | 573 | 273 |

« pied bas » = bord inférieur de `.pos-v5-cart__foot` ; il doit toujours rester sous la hauteur
de fenêtre — c'est le bouton d'encaissement, il ne doit JAMAIS sortir de l'écran. ✅ aux 4.

## État des critères de convergence AVANT tout correctif

| Critère | Aujourd'hui | Cible |
|---|---|---|
| **C1** grille atteignable sans défiler | **FAUX** — y=792 sur un écran de 768 | VRAI |
| **C2** contrôles de saisie coupés | **214 px cachés** (≥ 4 contrôles) | 0 |
| **C3** corps du panier ≥ 20 vh, pied intact | **VRAI** (372 px ≥ 154, pied à 690 < 768) | reste VRAI |
| **C4** nom client atteint la cuisine | non prouvé par un test | VERT |
| **C5** suites | 4840 / 36 / 8 (2026-08-22) | 8 échecs, pas 9 |
| **C6** zones gelées | 0 ligne | 0 ligne |

⚠️ **C3 est DÉJÀ VRAI. C'est le gain du 2026-08-19 (le panier n'avait que 40 px avant).**
Toute tâche de ce GOAL qui ferait retomber `bodyH` sous 20 vh est un retour en arrière déguisé.

## Autres lignes de base

- Instantané SQL pris avant toute écriture : `pre-goal-caisse-20260822-1810.sql.gz` (4,1 Mo)
- NF525 : `audit_logs` = **7281** lignes, dernier hash `ffe782b9f42f`
- Catalogue local = catalogue production : **57 articles actifs, 9 catégories** (vérifié sur le VPS)
