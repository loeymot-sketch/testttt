# Gate de parité B3 — mirrors standalone vs fixture canonique borne

> [GOAL-SYNC 2026-07-08] Gate rerunnable qui compare les data mirrors `menu.js`
> (web standalone + mobile standalone) à la fixture canonique extraite de la borne.
> **Il encode l'ÉTAT CIBLE du contrat (`reports/goal-web-app-sync/CONTRACTS.md §5`)** :
> tant que les mirrors ne sont pas convergés, il est ROUGE — c'est voulu.
> La fixture fait loi : aucun produit hors fixture n'est toléré.

## Fichiers

| Fichier | Rôle |
| --- | --- |
| `check-parity.mjs` | Le gate. Node ≥ 18, **zéro dépendance npm**, lecture seule sur les mirrors. |
| `extract-canonical.sh` | Ré-extraction de la fixture depuis la borne (token kiosk éphémère + curl). |

## Usage

```bash
# Depuis la racine du backend testttt :
node tools/parity/check-parity.mjs --surface=all       # web + mobile
node tools/parity/check-parity.mjs --surface=web
node tools/parity/check-parity.mjs --surface=mobile

# Options :
#   --fixture=<json>      fixture alternative (défaut : reports/goal-web-app-sync/catalog-canonical.json)
#   --mirror=<menu.js>    mirror alternatif — surface unique uniquement (auto-test sur copie mutée)
#   --report-dir=<dir>    dossier des rapports (défaut : reports/goal-web-app-sync/)
```

- **Sortie** : `reports/goal-web-app-sync/parity-report-<surface>.json` + résumé console.
- **Exit code** : `0` seulement si **0 divergence** sur toutes les surfaces demandées ;
  `1` si divergences ; `2` si erreur d'usage / fichier introuvable.

## Surfaces comparées

- **web** : `/Users/1millnonstop/Downloads/web/data/menu.js` (IIFE → `window.LC.menu` + `window.W_ITEMS`)
- **mobile** : `<backend>/mobile/data/menu.js` (IIFE → `window.LC.menu` + `window.ITEMS`)

Chaque mirror est évalué dans un `vm` Node avec stub `window={}` / `document.querySelector→null`
(aucun navigateur requis). La comparaison se fait **PAR NOM normalisé** — `norm()` est la copie
exacte de `/Users/1millnonstop/Downloads/web/api.js:158` (lowercase, sans accents, strip
préfixe « sauce », espaces normalisés) car la résolution API en production se fait par nom.

## Vérifications encodées (état cible CONTRACTS.md §5)

1. **Items (42) par catégorie** : présence par nom normalisé, prix flat exact (au centime),
   `is_available`, pas de produit inventé, pas de mauvaise catégorie, `is_spicy` fidèle au canon,
   jamais `is_halal:true` si le canon dit `false` (allégation réglementaire).
2. **Frites (EXCEPTION structurelle acceptée)** : pas d'égalité SKU — vérification de
   l'**atteignabilité** des 6 prix canoniques (2,50 / 4,00 / 3,50 / 4,50 / 5,00 / 6,00 €)
   via base × `FRITES_STYLES`, et aucune combinaison hors canon.
3. **Pools** : 7 viandes (noms exacts), 12 sauces, 4 crudités (dont « Oignons cuits ») —
   égalité stricte des ensembles.
4. **SUPPLEMENTS** : 9 × 0,90 € + « Boule gratinée » 1,00 € `galette_only:true`
   + Boursin `galette_excluded:true`, rien hors canon.
5. **EXTRA_MEAT_PRICE** = 2,50 € + `has_extra_meat:true` sur les 16 items
   (4 sandwichs, 2 galettes, 6 burgers, 2 tacos, 2 bols).
6. **SUPPLEMENTS_BOLS** : 9 × 0,90 € + « Option Gratiné » 2,00 € `riz_only:true`.
7. **BOL_SAUCES** : 2 noms EXACTS (`Sauce fromagère maison`, `Sauce spicy`) — attribut backend « Sauce bol ».
8. **PAINS** (Pain / Galette) + `has_pain_choice:true` sur les 4 sandwichs.
9. **Tacos M/L** : `has_crudites:true` (revert backend `05e5cacd0`).
10. **Cayenne** : `has_sauce:true`, `sauce_default:'Sauce fromagère maison'`, plus de `sauce_locked`.
11. **Formule** : `f-menu` à 2,50 €.
12. **FORMULE_DRINKS** : couverture des 15 saveurs canoniques + `priceForDrinkAddon`
    aligné aux prix catalogue (dont `d-capri` → 1,50 €), aucune saveur inconnue du canon.
13. **« Menu Enfant Chicken Burger »** : nom canonique EXACT (résolution API par nom).
14. Exports legacy cohérents (`window.W_ITEMS` / `window.ITEMS` = même cardinalité que `LC.menu.items`).

Non couvert (comportemental, hors data) : la suppression de la tarification multi-sauces
+0,50 € dans les wizards (canonique min1/max1) — vérifiée par les e2e des wizards, pas par ce gate.

## Auto-test du gate (ne JAMAIS muter les vrais mirrors)

```bash
SCRATCH=$(mktemp -d)
sed -e "s/'Cayenne', 7.40/'Cayenne', 7.90/" \
    -e "/c-oignons-cuits/d" \
    /Users/1millnonstop/Downloads/web/data/menu.js > "$SCRATCH/menu-mute.js"
node tools/parity/check-parity.mjs --surface=web --mirror="$SCRATCH/menu-mute.js" --report-dir="$SCRATCH"
# Attendu : divergences [item_prix Cayenne 7,90€] + [pool_crudites oignons cuits] → exit 1
```

Validé le 2026-07-08 : mutations prix / renommage produit / crudité supprimée /
`d-capri` 1,90 / style frites 1,20 → toutes détectées (types `item_prix`, `item_manquant`,
`item_invente`, `pool_crudites`, `price_drink_addon`, `frites_atteignabilite`,
`frites_prix_hors_canon`).

## Ré-extraction de la fixture

```bash
# Serveur borne UP sur :8766 requis. Écrase la fixture par défaut — à ne relancer
# que si le catalogue borne a VRAIMENT changé (la fixture fait loi pour le gate).
tools/parity/extract-canonical.sh                       # → reports/goal-web-app-sync/catalog-canonical.json
tools/parity/extract-canonical.sh /tmp/fixture-test.json  # test sans écraser
```

Méthode : `tinker` crée un token Sanctum `parity-extract` (ability `kiosk:order`) sur le user
de la 1ère `KioskMachine`, `curl GET /api/frontend/menu` avec `Authorization: Bearer` +
`X-API-Key` (= `MIX_API_KEY` du `.env`, jamais committée), validation JSON (9 catégories /
42 items), écriture atomique, puis **révocation du token** (trap EXIT).
