# C3 — « Les suppléments ne s'affichent pas sur le ticket » (2026-09-05)

Lecture seule. Aucun fichier produit modifié. Base de production interrogée en `SELECT`
uniquement. Rendu des tickets rejoué sur la production via `php artisan tinker` (instanciation
de service + appel de méthode de rendu : aucune écriture). Scripts supprimés en fin de mission.

**Verdict : NON REPRODUIT pour la formulation générale.** Les suppléments payants sont bien
imprimés, sur le ticket cuisine ET sur le ticket client. Un défaut réel et reproduit existe
toutefois, plus étroit : **la 2ᵉ sauce payée disparaît du ticket cuisine et est remplacée par un
jeton parasite** (souvent `90`), à cause d'une phrase de la caisse mal découpée.

---

## Avertissement d'instrument (à lire avant les chiffres)

Ma première passe automatique a annoncé **71 absences sur 145 suppléments payants**. C'était
**faux** : elle cherchait un motif UTF‑8 dans des octets **CP858**
(`OrderReceiptEscPosRenderer.php:280` et `:514` → `EscPosCommandBuilder::encodeForPrinter`).
`Œuf`, `Maïs`, `Légumes sautés` ne pouvaient donc jamais être trouvés. Après re‑décodage
`CP858→UTF‑8`, ils sont tous présents sur le papier. Les seules « absences » qui subsistent
concernent `Sauce supplémentaire` (§ Cause). Aucun chiffre de la première passe n'est repris ici.

---

## § Ticket cuisine

Construit par `app/Services/Hardware/OrderReceiptEscPosRenderer.php:284` `renderKitchenTicket()`,
qui délègue le détail à `app/Services/Hardware/KitchenTicketSymbolicFormatter.php`.

- Les suppléments payants sont rendus par `KitchenTicketSymbolicFormatter.php:309`
  `supplementLines()`, appelée en `OrderReceiptEscPosRenderer.php:431` (ligne formule) et
  `:455` (ligne produit), puis imprimées en gras avec une étoile (`:479-487`).
- **Conditions de non‑impression — il n'y en a que deux, toutes deux volontaires :**
  1. `:355` — un extra **gratuit** dont le nom est une crudité (`Salade/Tomate/Oignon`) est
     replié dans le slot crudités de la ligne 1 (`:268-276`), donc affiché comme lettre `S T O`.
     Le test est `cruditeSymbol($name) !== '' && isFreeExtra($e)` (`:179`, prix ≤ 0) : une
     crudité **payante** (`Maïs`, `Olives`, `Poivrons cuits` à 0,90 €) reste bien une ligne `*`.
  2. `:357-370` — un extra générique `Sauce supplémentaire` est masqué à hauteur d'un
     « budget » de sauces déjà expliquées ailleurs (`:336`). Tout ce qui dépasse reste imprimé.
- **Réponse à la question décisive : un élément SANS symbole connu est imprimé quand même.**
  `supplementLines()` n'utilise aucune table de symboles ; elle imprime `+ <nom>` tel quel.
  Un extra sans aucun champ de nom n'est pas sauté non plus : `:349-351` le remplace par
  `Supplément` (`EXTRA_SANS_NOM`).
- **Une omission silencieuse existe malgré tout, mais sur l'autre canal** : dans `mainLine()`,
  la chaîne `:241/243/245/247/252` traite les groupes `viande|meat`, `sauce`,
  `pain|galette|support|bread`, `taille|size|portion`, puis une viande reconnue —
  **et n'a pas de branche `else`**. Une ligne de composition d'un autre groupe est perdue sans
  trace. En production ce trou **ne mord pas aujourd'hui** : `JSON_TABLE` sur les instantanés
  depuis le 2026‑08‑20 ne rend que `Sauce (1ère Gratuite)` (379), `Viande 1` (366), `Sauce 1`
  (255), `Type de Pain` (237), `Pain` (151), `Viande 2` (137), `Sauce bol` (15), `Viande 3` (9) —
  tous couverts. C'est une dette, pas la cause.

## § Ticket client

Même fichier, `renderClientTicket()` (`:46`), données par `lines()` (`:532`) et rendu par
`renderClientItem()` (`:598`).

- Extras lus en `:549-559` ; un extra **payant** devient une ligne `+ <nom> ..... 0,90 €`
  (`:612-624`), un extra **gratuit** rejoint la ligne de composition compacte. Aucun n'est perdu.
- Divergence à noter avec la cuisine : `:551-552` **saute** un extra dont le nom est vide, là où
  la cuisine imprime `Supplément`. Aucune donnée de production n'est dans ce cas
  (`JSON_SEARCH(... '$[*].extra_name') IS NULL` → 0 ligne), donc latent, non constaté.
- Troisième constructeur, pour mémoire : le ticket **borne** est bâti côté navigateur par
  `resources/js/helpers/kioskPrinter.js:418-425`, qui liste tous les extras par nom, payants
  comme gratuits.

## § Le groupe compte‑t‑il ?

**Réfuté.** `grep -rn "group_label"` sur `app/Services/Hardware/`, `app/Services/Receipt/`,
`app/Support/Order/CompositionCompactor.php`, `resources/js/helpers/posReceiptBuilder.js` et
`resources/js/helpers/kdsSymbolic.js` → **aucune occurrence**. Aucun constructeur de ticket ne
connaît le champ. `CompositionSnapshotBuilder.php:83-115` ne le recopie même pas dans
l'instantané : un extra scellé porte `extra_id / extra_name / quantity / unit_price / line_total`.

Les 52 extras `group_label IS NULL` sont d'anciennes lignes de graine
(`created_at` 2026‑05‑28 et 2026‑06‑12, articles 1, 2, 9, 10, 11, toutes à 1,00 €) — pas les
suppléments que le propriétaire ajoute aujourd'hui. Le `group_label` ne joue qu'en **amont**, pour
décider si un extra est *proposé* dans une page du composeur
(`app/Services/Composer/ComposerProfileProjection.php:250-270` : un extra sans étiquette n'est
atteignable que par une page `sourceRef === 'default'`). Un extra jamais proposé ne peut pas être
ajouté ; ce n'est pas le symptôme décrit.

## § Preuve sur commandes réelles

Rendu rejoué sur la production, sortie relue en UTF‑8.

| Commande | Extras payants scellés | Ticket cuisine | Ticket client |
|---|---|---|---|
| 955 (04/09 20:57) | Sauce supplémentaire 0,50 · **Boursin 0,90** | `S \| CAY \| P \| FRO MAY` + `* Boursin` | `+ Sauce supplémentaire : Mayonnaise 0,50 €` · `+ Boursin 0,90 €` |
| 949 (04/09 19:59) | **Olives 0,90** | `S \| CAY \| P \| SO \| AND` + `* Olives` | `+ Olives 0,90 €` |
| 934 (04/09 18:13) | Sauce supplémentaire 0,50 · **Jambon 0,90** | `G \| TAC \| P Mex Cordon \| AME AND` + `* Jambon` | (non rejoué) |
| 921 (03/09 20:08) | Raclette · Œuf · Légumes sautés · Viande suppl. 2,50 · Sauce suppl. 0,50 | `* Raclette` · `* Oeuf` · `* Légumes sautés` · `* Viande supplémentaire : Cordon Bleu` | — |
| 668 | **Maïs 0,90 · Olives 0,90** | `* Maïs` · `* Olives` | — |
| 885 | Légumes sautés · **Option Gratiné 2,00** | `* Légumes sautés` · `* Option Gratiné` | — |

Tous les suppléments **nommés** sortent sur le papier. Le signalement, pris au pied de la lettre,
est **NON REPRODUIT**.

**Le contre‑exemple, lui, est reproduit — commande 929, ligne 2184.**
Instruction caisse : `DOUBLE CHEESE / Viandes : Viande Hachée - Sans crudités Sauce : Mayonnaise
Supplément : Œuf (+0,90 €)`. Extras scellés : `Sauce supplémentaire 0,50 €` + `Œuf 0,90 €`.
Ticket cuisine réellement rendu :

```
DOUBLE CHEESE | K | MAY 90
    * Oeuf
```

La 2ᵉ sauce payée 0,50 € n'a **ni ligne `*`, ni nom** : elle est devenue le jeton `90`.
Même mécanisme sur la commande 668 : `Olives` s'imprime **deux fois**, en fausse sauce `OLI`
dans la ligne 1 (`SAN OLI`) et en `* Olives`.

## § Cause

`KitchenTicketSymbolicFormatter::extraSauceNames()` (`:400-421`) récupère le nom des sauces en
plus dans le texte libre. Faute de ligne dédiée, la caisse écrit **tout sur une seule ligne** :
`Sauce : <sauce> Supplément : <nom> (+0,90 €)`. Le motif `:414`
`/(?<![\p{L}])sauces?\s*:\s*([^\n]+)/iu` capture donc **aussi la clause `Supplément`**, puis
`splitSauceList()` (`:483-486`) découpe **sur la virgule** — et `0,90` en contient une.

Conséquences en chaîne :
1. les jetons parasites (`90`, `OLI`, `OEU`) sont imprimés comme sauces en ligne 1 (`:259-265`) ;
2. ils gonflent `$budgetSaucesExpliquees` (`:336`), qui **masque** la ligne
   `+ Sauce supplémentaire` en `:365-367`. Le supplément payé disparaît vraiment.

Portée mesurée : **61 lignes de commande depuis le 2026‑08‑01** portent
`Sauce : … Supplément : …` sur une même ligne (`instruction REGEXP "Sauce[[:space:]]*:[^\n]*Suppl"`).

**Pas de régression des 3 derniers jours.** `git log` sur les fichiers de ticket :
`OrderReceiptEscPosRenderer.php` et `KitchenTicketSymbolicFormatter.php` inchangés depuis
`d57fc8f08` (2026‑08‑25) ; `KitchenBundledAddonCollapser.php` touché le 2026‑09‑02 par
`1ea8529aa` (+6/−1, sentinelle NF525) et par la fusion `f0da0bc82`. `md5sum` confirme que la
production sert **exactement** les fichiers de la branche. Le défaut est donc **ancien**, pas né
d'hier — ce qui a pu changer, c'est la fréquence des commandes « sauce + supplément ».

## § Correctif scope‑minimal

Aucune zone gelée touchée. `public/js/pos-wizard.js` (émetteur du texte) reste en lecture seule ;
tout se corrige côté lecture.

1. **Borner la capture** dans `extraSauceNames()` (`:414`) : arrêter le groupe avant une nouvelle
   clause, p. ex. `([^\n]+?)(?=\s+(?:Suppl[ée]ment|Viandes?|Formule|Sauce\s+frites)\s*:|$)`.
   Un test de non‑régression avec les deux instructions réelles ci‑dessus (2184, 1487).
2. **Ne pas découper un prix** dans `splitSauceList()` (`:483`) : retirer d'abord les montants
   parenthésés (`/\s*\(\s*\+?[^)]*\d[.,]\d{1,2}[^)]*\)/u`, motif déjà présent en `:918`) avant
   l'`explode(',', …)`, et rejeter tout jeton purement numérique.
3. **Ceinture** : dans `supplementLines()` (`:336`), ne compter dans le budget que des noms
   reconnus par `knownSauceSymbol()` (`:136`) — une sauce inconnue ne doit jamais avoir le droit
   de masquer une ligne payée. C'est le garde‑fou qui empêche ce défaut de revenir par une autre
   formulation.
4. **Dette séparée, hors incident** : ajouter une branche `else` à `mainLine()` (`:252`) pour
   qu'un groupe de composition inconnu soit imprimé en clair plutôt que perdu ; et aligner
   `OrderReceiptEscPosRenderer.php:551` sur `EXTRA_SANS_NOM` pour que le ticket client n'escamote
   pas un extra sans nom.

Toute modification de `KitchenTicketSymbolicFormatter` impose la mise à jour du **jumeau strict**
`resources/js/helpers/kdsSymbolic.js:199-223` (`extraSauceNames`/`splitSauceList`), sans quoi
l'écran KDS et le papier divergeront.
