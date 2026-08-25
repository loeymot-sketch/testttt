# REPRISE — audit superviseur caisse, interrompu au round 3

**Interruption** : limite de session atteinte pendant la 2ᵉ vague de correction.
Les 5 implémenteurs ont été coupés **avant d'écrire la moindre ligne**.
**Arbre propre**, aucun travail partiel. Dernier commit : `c9980913d`.

---

## Où on en est

| Round | Verdict | P0 | P1 |
|---|---|---|---|
| 1 | ROUGE | 4 | 15 |
| 2 | **ROUGE** | **2** | **14** |

Par vague au round 2 : **A GREEN** · B AMBER · C AMBER · **D RED (2 P0)** · E AMBER.

Convergence exigée : deux rounds consécutifs à P0+P1 = 0 avec jeux de constats
identiques. **Non atteinte.** Ne pas déclarer vert.

---

## Ce qui BLOQUE encore (à reprendre en priorité)

### P0-1 · Le grand total de l'écran d'argent perd des lignes
`app/Http/Controllers/Admin/CashOverviewController.php:135-144` — `whereHas('order', …)`
est une jointure **INTERNE** sur un modèle à **suppression douce**.
Mesuré sur 23–24/08 : **27 lignes / 247,70 € → 17 lignes / 222,70 €**. Les 10 lignes
perdues sont exactement les 25,00 € d'espèces que le bandeau affichait.
Base entière : **22 paiements orphelins = 97,40 €**, **40 mouvements = 177,00 €**.
**Aggravant** : `summarizeUnrecordedCash()` porte la même jointure — le détecteur
d'écart est aveugle aux lignes qu'il contrôle.
⚠️ Le superviseur n'accuse PAS l'app de supprimer ces commandes. Le travail est que
la comptabilité cesse de perdre des lignes **silencieusement**.

### P0-2 · Le bandeau de réconciliation contredit encore sa propre page
Résiduel du P0 du round 1. « aujourd'hui » a disparu, la période vide fait taire le
bandeau (vérifié : section **absente**, pas mise à zéro). Mais :
état 02 → bandeau « 25,00 € sur la période » au-dessus de « Carte 17 · 222,70 € », zéro espèce.
état 01 → **5,00 €** contre « Espèce 1 · 2,50 € ».
**Même racine que P0-1** : corriger la jointure devrait le fermer. À revérifier après.

### Les 14 P1 ouverts, groupés
1. **B-014** — l'en-tête du panier cache **54 % de lui-même** sans indice de défilement.
   Disparaît notamment « Annuler la dernière ligne », qui n'existe dans le DOM QUE
   quand le panier a des lignes → caché exactement quand il sert.
2. **C-002** — colonnes **DATE et STATUT à zéro pixel** sur un état ; date amputée en
   plein glyphe sur un autre (8 caractères sur 17). Pire que ce qui avait été admis.
3. **C-017** — la **facture imprimée du CLIENT** porte « Type de commande: » vide sur
   100 % du parc V1. **4ᵉ copie** du même tableau d'énumération ; le conseil d'extraire
   le facteur commun n'a pas été suivi.
4. **E-009** — le repli des formules en cuisine **annule le correctif du round 2** :
   `collapseBundledAddonItems()` abandonne la ligne repliée avec ses extras. Même trou
   dans le jumeau PHP. Vivant sur **21 commandes réelles** (aucune ne portant d'extra
   aujourd'hui, d'où P1).
5. **D-003** — « Total clôture » compte une caisse **encore ouverte** comme 0,00 €.
   5 journées, 11 sessions. Un jour se lit « 150,00 € entrés, 0,00 € sortis ».
6. **E-004 / E-005** — 2 images de lot en **404 face client** (fichiers absents du
   disque : `frites.png`, `coca.png`) ; back-office à un clic sur le mur client.
7. + les autres consignés dans `round-2/wave-*-findings.json`.

### P2 notables (non bloquants mais instructifs)
- **A-015** — deux couleurs de canal à **1,02:1** l'une de l'autre ; et la couleur de
  texte annoncée « AA vérifié » **n'est peinte nulle part** (le seul enfant visible
  est un emoji, qui ignore `color`).
- **A-016** — le marqueur « +N », pièce maîtresse d'un correctif du round 2,
  **n'est rendu sur aucun des 10 états** (budget 58 caractères, chaîne semée 54).
  Chemin de code neuf, jamais photographié.
- **E-007** — contraste 2,74:1 sur la ligne du supplément **payé**, jaune sur jaune,
  lue à distance. **Notre correctif en multiplie les occurrences** : il aggrave.
- **C-020** — le verrou de test posé sur la colonne épinglée ne compare les couleurs
  que sur les **rangs impairs**. Les deux états non testés sont exactement les deux
  états faux.

---

## Deux défauts du BANC, pas du produit — à corriger avant de citer ces états

- **A-017** — les commandes sont semées en `now('UTC')` alors que l'app est en
  `Europe/Paris`. Conséquence : **4 des 5 commandes semées, affichées « à l'instant »,
  remplissent les 4 premiers rangs du panneau « en souffrance »**. L'état 09 ne peut
  PAS être cité comme preuve que le panneau classe correctement.
- **B — 4ᵉ combinaison manquante** : `1024×600 à panier plein` n'existe dans aucune
  des 13 captures. Le « 0 recouvrement sur 4 combinaisons » n'est vérifiable que sur 3.

---

## Comment reprendre

```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/goal-caisse-vision-2026-08-24
php artisan serve --host=127.0.0.1 --port=8000 &     # puis VÉRIFIER LE CORPS, pas le code HTTP
curl -s http://127.0.0.1:8000/admin/pos-orders-tracker | head -c 200
```

⚠️ **Piège d'environnement déjà rencontré** : un `vendor/` amputé rend **HTTP 200**
avec un simple avertissement PHP en guise de page. Toujours lire le CORPS.
Les 5 specs de capture portent désormais une garde qui refuse de photographier une
application cassée.

Rejouer les captures :
```bash
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 PLAYWRIGHT_WEB_SERVER_CMD="" \
  npx playwright test tests/e2e/audit-supervisor-wave{A,B,C,D,E}.spec.js --workers=1
```
Puis relancer 5 superviseurs adverses sur `round-3/`, agréger :
```bash
bash reports/test-e2e/supervisor-caisse-2026-08-24/aggregate_findings.sh \
  supervisor-caisse-2026-08-24 3
```

---

## Règles qui ont fait la valeur de cet audit — à ne pas relâcher

1. **Un code 200 ne prouve pas qu'une page s'affiche.** Lire le corps.
2. **Ne jamais chercher l'absence d'un symptôme.** Exiger une présence. Le correctif
   cuisine du matin est passé au travers parce que le test cherchait `Extras: , , ,`
   sur un gabarit qui n'émet aucun libellé « Extras: ».
3. **Un test vert ne prouve pas un écran juste.** Un implémenteur a eu son unitaire
   vert avec l'écran toujours tronqué (`white-space` est hérité).
4. **Mesurer sur le rendu, pas sur la déclaration.** Deux couleurs déclarées
   distinctes peuvent être indiscernables ; une couleur de texte déclarée peut
   n'être peinte nulle part.
5. **Ne jamais réutiliser un chiffre qu'on n'a pas mesuré soi-même.** Deux
   superviseurs se sont corrigés eux-mêmes sur ce principe ; un troisième a refusé
   un chiffre faute de preuve dans les artefacts — et a trouvé pire en remesurant.
