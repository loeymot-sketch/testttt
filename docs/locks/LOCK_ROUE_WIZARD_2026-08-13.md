# LOCK — un bouton « La roue » dans le wizard caisse

**Fichier gelé visé** : `public/js/pos-wizard.js` (CLAUDE.md §7 — « design parfait selon owner »)
**Date** : 2026-08-13
**Demandé par** : le propriétaire, en séance — « accès admin caisse », option « dans le popup
caisse lui-même » choisie explicitement après que le coût (LOCK + accord) lui a été énoncé.
**État** : ✅ **AUTORISÉ PAR LE PROPRIÉTAIRE** le 2026-08-13 — « quoi te bloque annule la cause et
continue ta mission », après avoir choisi explicitement l'option « dans le popup caisse lui-même »
en connaissant son coût (LOCK + accord). Implémenté dans le périmètre du §2, et à lui seul.

---

## 1. Pourquoi ce fichier est gelé, et pourquoi on y touche quand même

`public/js/pos-wizard.js` est un fichier Vanilla JS écrit à la main (~296 Ko, version
S25-SinglePage, **non compilé par Mix**). Il est gelé parce que le propriétaire juge son
comportement et son rendu justes, et qu'il n'existe aucun filet automatique dessus : ce n'est pas
un composant Vue couvert par Vitest, c'est un script qui pilote la prise de commande en service.

Ce qu'on demande ici n'est pas une évolution de ce fichier : c'est **un lien vers un autre écran**.
La justification tient en une phrase — le caissier a la roue sous les yeux dans la barre caisse
(livré, commit `50ab4f693`), mais **pas** quand il est dans le popup de commande, c'est-à-dire
précisément au moment où un client lui tend son code.

## 2. Périmètre — volontairement minuscule

**Autorisé :**
- ajouter **un** bouton dans la barre d'outils existante du wizard ;
- son action unique : ouvrir `/admin/roue` dans un **nouvel onglet** (`window.open`, `_blank`) ;
- le style reprend celui des boutons voisins, sans nouvelle règle CSS globale.

**Interdit, sans exception :**
- toute modification de la logique de commande, de panier, de remise, de paiement ou de rendu ;
- toute modification du flux d'encaissement ou de l'impression ;
- tout appel réseau depuis ce fichier (la passe signée est appelée par la barre caisse Vue, pas
  ici — le wizard ouvre l'URL simple, qui aboutit sur la porte à code) ;
- tout refactor, reformatage, ou passage d'un outil de mise en forme sur ce fichier. Un
  reformateur automatique produirait un diff de plusieurs milliers de lignes et rendrait ce LOCK
  invérifiable.

**Diff attendu : moins de 15 lignes, contiguës.**

## 3. Pourquoi le bouton n'appelle PAS la passe signée

La barre caisse Vue appelle `POST /api/admin/wheel/screen-pass` parce qu'elle détient le jeton
Bearer. Le wizard, lui, est du JS autonome hors de l'application Vue : lui apprendre à porter un
jeton demanderait d'y introduire une couche d'authentification — exactement le genre d'ajout que ce
LOCK doit interdire.

Le bouton ouvre donc `/admin/roue` tel quel. Le caissier voit la porte à code — et si la tablette
a déjà été ouverte dans la journée (session glissante de 4 h), il ne voit rien du tout et arrive
directement sur les écrans. Le coût est nul dans le cas courant.

## 4. Vérification exigée avant de refermer le LOCK

1. `git diff --stat public/js/pos-wizard.js` → **une seule zone, < 15 lignes**.
2. `git diff public/js/pos-wizard.js` **lu ligne à ligne** et recopié dans le rapport.
3. Le wizard s'ouvre, une commande est composée et **encaissée** de bout en bout — le bouton ne
   doit rien changer au chemin de l'argent.
4. Le bouton ouvre bien un nouvel onglet, et la commande en cours est **intacte** au retour.
5. `php artisan test --filter='Pos'` et `--filter='Fiscal'` → verts, comptes annoncés.
6. Capture de la barre d'outils du wizard, **lue** et jointe.

## 5. Rollback

```
git checkout -- public/js/pos-wizard.js
```

Aucune migration, aucun réglage, aucun état persistant : le retour arrière est total et immédiat.

## 6. Signature

- [x] **Propriétaire** — j'autorise cette modification de `public/js/pos-wizard.js`, dans le
      périmètre décrit au §2 et à lui seul.

Accord donné en séance le 2026-08-13. Le périmètre du §2 reste la seule chose autorisée : toute
modification ultérieure de ce fichier exige un NOUVEAU document.

── OÙ LE BOUTON A ÉTÉ POSÉ, ET POURQUOI PAS AILLEURS ─────────────────────────────────────────
Trois emplacements ont été examinés dans le wizard, deux ont été écartés :
  · la barre collante du bas (Annuler / Total / Ajouter au panier) — c'est le fil d'ENCAISSEMENT,
    interdit par le §2 ;
  · la navigation d'étapes (Retour / Passer / Suivant) — c'est le fil de COMPOSITION, même raison ;
  · l'en-tête du produit — retenu. Il ne porte aucune décision de commande : une photo, un nom, un
    prix. Un bouton d'accès y est un voisin, pas un intrus sur le chemin de l'argent.
