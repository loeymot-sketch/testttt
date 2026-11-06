# VAGUE C — COMMANDES / DÉTAIL / ENCAISSEMENT / HISTORIQUE · phase CAPTURE (round 1)

- Surfaces : `/admin/pos-orders`, `/admin/pos-orders/show/:id`, `/admin/encaissement`, `/admin/historique`
- Spec : `tests/e2e/audit-supervisor-waveC.spec.js` — **1 passed (40,9 s)**, 9 quartets + 3 gros plans
- Artefacts : `tests/e2e/__screenshots__/test-e2e-waveC/`
  (`.png` + `.dom.html` + `.console.json` + `.network.json` par état, plus `_facts.json` = mesures brutes)
- Données : semées par `php artisan tinker`, préfixe **exclusif** `AUDC-` sur `order_serial_no`,
  **nettoyées** en fin de course — vérifié en base après coup :
  `orders AUDC-% = 0`, `order_items rattachés = 0`, `soft-deleted = 0`.
- **Aucun code de production touché.** Phase capture uniquement.

## Garde d'environnement (obligatoire, et elle a servi)

La spec refuse de capturer si le HTML rendu contient `Warning: require`, `Fatal error`,
`Failed to open stream` ou `Uncaught Error` (`assertAppNotBroken()`, appelée après **chaque**
`goto`). Elle existe parce qu'au démarrage de cette vague le `vendor/` du worktree était amputé :
`POST /api/auth/login` renvoyait **HTTP 200** dont le corps n'était qu'un avertissement PHP
(`vendor/composer/../thecodingmachine/safe/lib/special_cases.php` introuvable). Une sonde naïve
sur le code HTTP concluait « serveur en ligne » — un piège à faux défaut produit.

Toutes les captures ci-dessous sont **postérieures** à la réparation. Preuves : DOM de 491 320 à
577 117 octets par état, aucun marqueur PHP. La garde est appelée à 10 endroits du spec ; **9 se sont réellement exécutées** (la 10ᵉ appartient à l'état 6, non atteint), et aucune n'a déclenché.

---

## VÉRIFICATION CIBLÉE DEMANDÉE — les suppléments de formule (état 3)

**Oui, ils apparaissent, sous le libellé français « Suppléments ».**

Texte **exact** rendu (relevé sur le DOM capturé, `03-detail-commande-composee.dom.html`) :

```
Suppléments: Frites, Coca-Cola 33cl
```

HTML exact :

```html
<h3 class="capitalize text-xs w-fit whitespace-nowrap">Suppléments:</h3>
<p class="text-xs" data-testid="pos-order-show-addons">
  <span>Frites<!--v-if--><span>,&nbsp;</span></span><span>Coca-Cola 33cl<!--v-if--><!--v-if--></span>
</p>
```

- `data-testid="pos-order-show-addons"` : **présent, visible**, 1 occurrence.
- Libellé `<h3>` exact : `Suppléments:` — c'est `label.addons` de `resources/js/languages/fr.json:462`
  (`"addons": "Suppléments"`), pas un libellé brut, pas un `Label.X`.
- Le bloc de composition complet rendu sur la fiche :
  `Sauce: Algerienne` / `Extras: Cheddar ×2` / `Suppléments: Frites, Coca-Cola 33cl` / `Instruction: Sans oignons`.
- Gros plan : `03-detail-composition-zoom.png`.

**Réserve d'honnêteté sur cette vérification** : la fiche NOMME désormais les suppléments mais ne
dit **pas ce qu'ils coûtent**. Le ticket client, lui, imprime le montant
(`ReceiptComponent.vue:162-170` : `+ Frites` … `+1,20 €`, et même `Extras: 2× Cheddar (+1,00 €)`).
Les deux surfaces partagent le normaliseur (`normalizeReceiptAddons`, `posReceiptBuilder.js:244`)
et donc les mêmes NOMS, mais pas les mêmes CHIFFRES : `line_total` est calculé, transporté… et
ignoré à l'affichage sur la fiche. Le motif inscrit dans le code de cette vague est « un client
demandant *pourquoi 3 € de plus ?* recevait une fiche muette » ; la fiche n'est plus muette sur le
**quoi**, elle l'est encore sur le **combien**. C'est un constat, pas une correction — je n'ai rien
touché.

---

## Les états, un par un

| # | Fichier | Ce que j'attendais | Ce que j'ai réellement vu |
|---|---------|--------------------|---------------------------|
| 1 | `01-liste-pos-orders` | la liste caisse au chargement, sans état vide | Titre « Commandes Caisse », 10 lignes, colonnes `N° COMMANDE · N° FILE · TYPE DE COMMANDE · CLIENT · MONTANT · DATE · STATUT · ACTION`. Mes 3 lignes `AUDC-` en tête. Montants FR corrects (`14,60 €`). **Défaut visible : les pastilles de la colonne STATUT sont coupées à droite** (« En préparati… ») et la colonne ACTION sort du cadre à 1280 px — il faut défiler horizontalement pour l'atteindre. |
| 2 | `02-liste-pos-orders-filtre-statut` | la même liste avec un filtre de statut appliqué | Tiroir « Filtrer » ouvert (`N° COMMANDE · STATUT · CLIENT · DATE`), statut = **Livré**, clic sur « Rechercher ». Résultat : 10 lignes, **10/10 au statut « Livré »** — le filtre fait ce qu'il annonce. `AUDC-ENC` (Acceptée) a bien disparu, `AUDC-NU` et `AUDC-RICHE` (Livré) sont restées. |
| 3 | `03-detail-commande-composee` (+ `03-detail-composition-zoom`) | variations **et** extras **et** suppléments de formule | Les 4 lignes de composition présentes et nommées (détail ci-dessus). **Mais deux libellés de l'en-tête sont rendus avec une valeur VIDE** : `Type de paiement:` et `Type de commande:` — voir « Défauts » ci-dessous. |
| 4 | `04-detail-commande-sans-composition` (+ zoom) | aucune ligne « Extras: » ni « Instruction: » orpheline | **Propre.** Bloc rendu en entier : `Détails Commande / 1 / Menu (Frites + Boisson) / 8,90 €`. Mesuré : `Extras:` absent, `Instruction:` absent, `Suppléments:` absent, `0` `<ul>` de composition, `0` `data-testid="pos-order-show-addons"`. Les gardes de contenu tiennent. |
| 5 | `05-encaissement-file-non-vide` (+ `05b-…`, + `05-encaissement-ticket-audc-zoom`) | la file avec au moins une commande en attente | 3 tickets, pastille compteur « 3 » cohérente avec les 3 cartes. Badges d'origine corrects (`Caisse` ×2, `Borne` ×1 = ma ligne semée). Mon ticket : `Borne · N°C98A2 · Admin Le Cayenne · 1× Menu (Frites + Boisson) · 14,60 € · [Encaisser]`. Ni l'état vide ni l'état d'erreur ne s'affichent (`enc-empty-real` = 0, `enc-fetch-error` = 0). |
| 6 | `06-encaissement-vide` | l'état vide de la file | **ÉTAT NON ATTEINT — et je ne l'ai pas simulé.** Détail et chiffre exact ci-dessous. |
| 7 | `07-historique-chargement` | l'historique unifié au chargement | Titre « Historique », 4 chips (`Aujourd'hui · Hier · Annulé · Remboursé`), 10 lignes, en-têtes `N° COMMANDE · ORIGINE · N° FILE · CLIENT · MONTANT · PAIEMENT · N° FISCAL · DATE · STATUT · ACTION`. Origines correctement discriminées (`Borne` pour `AUDC-ENC`, `Caisse` pour `AUDC-NU`/`AUDC-RICHE`), paiement `À encaisser` / `Payé`, N° fiscal `—` quand il n'y en a pas. **Défaut majeur : la colonne ACTION se superpose à la colonne DATE** — voir « Défauts ». |
| 8 | `08-historique-remboursements` | une ligne de remboursement / une commande scellée dans un Z | Chip « Remboursé » actif → 10 lignes, **10/10** en paiement `Remboursé` et **10/10 porteuses d'un N° fiscal** (`2754, 2752, 2750, 2748, 2746, 2743, 2741, 2740, 2739, 2738`) : ce sont bien des commandes scellées. Exemple : `2508266720 · Borne · N°A0035 · 13,10 € · Remboursé · 2754 · Annulée`. À noter : **0 tag `↩ #parent`** dans cette vue — le chip filtre sur `payment_status`, il ne fait pas remonter les miroirs de contrepassation. D'où l'état 8 bis. |
| 8 bis | `08bis-historique-contrepassation` | la contrepassation NF525 telle qu'elle se voit | Recherche par n° sur une commande miroir **réelle** (aucune ligne `AUDC-` n'a de parent). Ligne rendue : `RTN-310526971 · Borne · — · stress-kiosk-1 · **-12,00 €** · Remboursé **↩ #971** · **2238** · Retournée`. Montant négatif, lien vers la commande mère et numéro fiscal propre du miroir : la contre-écriture est lisible telle quelle. |

---

## Défauts observés (constats, aucune correction appliquée)

### D-C1 — `/admin/historique` : la colonne ACTION collée à droite est TRANSPARENTE une ligne sur deux

`resources/js/components/admin/orderHistory/HistoriqueListComponent.vue:675-679`

```css
.hist-action-col { position: sticky; right: 0; z-index: 2; background: inherit; }
```

`background: inherit` sur un `<td>` recopie le fond du `<tr>`. Or la zébrure ne peint **que les
rangs impairs** : `resources/css/app.css:464` → `.db-table.stripe .db-table-body-tr { odd:!bg-[#f9fafb] }`.
Les rangs **pairs** n'ont donc aucun fond, la cellule collante devient transparente, et la colonne
DATE défile **sous** les boutons d'action.

Visible tel quel sur `08-historique-remboursements.png` : lignes 1, 3, 5 (impaires) propres ;
lignes 2, 4, 6 (paires) avec « …24-08-20… » qui traverse les icônes. Même chose sur
`07-historique-chargement.png`, où **l'en-tête** cumule les deux mots superposés : on lit
`DACTIEON` au lieu de `DATE` + `ACTION` (le `<tr>` du `<thead>` n'a pas non plus de fond propre).
Le commentaire du code annonce « sticky la garde toujours atteignable, **sans masquer de donnée** » :
c'est précisément ce qui échoue.

### D-C2 — fiche commande : « Type de commande » vide sur TOUTES les commandes caisse et borne

`resources/js/components/admin/posOrders/PosOrderShowComponent.vue:740-746`

```js
orderTypeEnumArray: function () {
    return {
        [orderTypeEnum.DELIVERY]: this.$t("label.delivery"),
        [orderTypeEnum.TAKEAWAY]: this.$t("label.takeaway"),
        [orderTypeEnum.DINING_TABLE]: this.$t("label.dining_table")
    }
},
```

Il manque **`POS` (15)** et **`KIOSK` (25)**. La ligne 43-46 rend alors
`Type de commande: <span class="text-heading"></span>` — un libellé suivi de rien. Constaté dans
le DOM capturé (`03-detail-commande-composee.dom.html`) sur une commande `order_type = POS`.

Ce n'est pas un artefact de semis : POS et KIOSK sont les deux types dominants du V1 Le Cayenne.
Et la liste, elle, sait les nommer — `PosOrderListComponent.vue:254-259` inclut bien
`[orderTypeEnum.KIOSK]` et `[orderTypeEnum.POS]`. La liste affiche « POS » / « Borne » (visible sur
`01-liste-pos-orders.png`), la fiche ouverte depuis cette même liste n'affiche rien.

C'est exactement la famille de défaut que cette mission a déjà corrigée deux fois sur cette page
(« Extras: » orphelin, « Instruction: » orpheline) : un libellé qui survit à l'absence de sa valeur.

### D-C3 — fiche commande : « Type de paiement » sans garde de contenu

Même bloc, lignes 29-41 : `posPaymentMethodEnumArray[order.pos_payment_method]` est rendu sans
`v-if`. Sur ma commande semée `pos_payment_method` était `NULL` et la fiche a rendu
`Type de paiement: <span class="text-heading"> <!--v-if--></span>`.

**Réserve** : mon semis a mis ce champ à `NULL`, donc je ne peux pas affirmer à quelle fréquence
cela arrive en production. Le constat solide est le constat **structurel** : contrairement aux
lignes Extras/Suppléments/Instruction voisines, cette ligne n'a **aucune garde**, donc toute
commande sans `pos_payment_method` affichera un libellé creux.

### D-C4 — `/admin/pos-orders` : colonne STATUT tronquée, colonne ACTION hors cadre à 1280 px

`01-liste-pos-orders.png` : les pastilles « En préparation » sont coupées (« En préparati… ») et
l'en-tête ACTION est déjà hors du cadre visible. Le tableau est bien dans un
`.db-table-responsive` (donc défilable), mais l'action principale de la ligne n'est pas visible
sans geste supplémentaire sur la largeur d'écran de test.

### D-C5 — 404 permanent sur le drapeau de langue (toutes surfaces)

`GET /storage/1/english.png` → **404**, 4 fois sur `01`, 2 fois sur `03`, `04`, `05`, `07`.
Le sélecteur de langue de l'en-tête est concerné, donc toutes les surfaces admin. Sans effet
fonctionnel, mais c'est la seule erreur console récurrente de la vague — elle noie le signal.

---

## État 6 — pourquoi il n'est PAS capturé

La file d'encaissement n'était **pas vide**, indépendamment de ma vague.

Chiffre mesuré avec le **miroir exact** du prédicat de l'endpoint (`routes/api.php:973-1043`, clauses
d'origine borne / caisse-différé / téléphone / web / filet anti-NULL comprises) :

| mesure | avant semis | après semis |
|---|---|---|
| commandes réellement servies par `admin/pos/counter-collect/pending` | **2** | **3** |
| dont préfixe `AUDC-` (à moi) | 0 | 1 |
| lignes `payment_status = PENDING_COUNTER` en base (comptage NAÏF) | 75 | 76 |

Vider la file aurait exigé de faire disparaître **2 commandes qui ne m'appartiennent pas**
(données préexistantes / autres vagues) : destruction de données partagées, refusée.
Je n'ai posé **aucun stub réseau** sur `counter-collect/pending` : l'état n'est pas simulé, il est
manquant.

**Note de méthode, à retenir pour les autres vagues** : mon premier comptage disait « 178 commandes
en attente ». Il était **FAUX**. `payment_status = PENDING_COUNTER` ne suffit pas — l'endpoint exige
en plus une origine reconnue. L'écran, lui, en affichait 3. J'ai corrigé le comptage avant de
publier ; le chiffre juste est **3** (dont 1 à moi), pas 178.

Ce qui aurait été rendu, pour mémoire et sans le faire passer pour une capture :
`resources/js/components/admin/encaissement/EncaissementComponent.vue:40-43` →
`data-test="enc-empty-real"`, icône `✅`, texte `label.encaisser_queue_empty` =
**« Aucune commande à encaisser »** (`fr.json`). Le composant distingue par ailleurs correctement
cet état vide RÉEL de l'état « la file est invisible parce que le fetch a échoué »
(`data-test="enc-fetch-error"`), et j'ai vérifié que ni l'un ni l'autre n'était affiché à tort
pendant l'état 5.

---

## Console / réseau (quartets)

- **0 `pageerror`** sur les 9 états.
- Seules erreurs console récurrentes : les 404 `/storage/1/english.png` (D-C5).
- `08bis` : 3 × `net::ERR_CONNECTION_REFUSED` — pont d'impression / diffusion temps réel absents en
  dev, attendu dans cet environnement.
- États `02`, `05b`, `08` : **console vide, réseau vide**.
- Aucune requête ≥ 400 hors les 404 de drapeau ; aucune requête > 2 000 ms.

## Réserves d'honnêteté

- Le client de mes 3 commandes est « Admin Le Cayenne » (`user_id = 1`) : artefact de semis.
- `AUDC-RICHE` / `AUDC-NU` / `AUDC-ENC` portent un `order_serial_no` plus long qu'un vrai numéro :
  artefact de semis, sans conséquence de mise en page observée sur ces 4 surfaces.
- La barre Debugbar occupe ~40 px en bas de chaque capture — outil de dev, absent en production.
- L'état 8 bis s'appuie sur une commande miroir **réelle** (`RTN-310526971`, mère `#971`) : je ne
  fabrique pas de contrepassation, et je n'ai touché à aucune ligne fiscale.
- `03-detail-composition-zoom.png`, `04-detail-sans-composition-zoom.png` et
  `05-encaissement-ticket-audc-zoom.png` sont des découpes d'élément (gros plans) : elles n'ont pas
  de quartet propre, la preuve complète est dans l'état plein écran correspondant.
- Une remarque de cohérence, trop faible pour être un défaut : la fiche écrit `Cheddar ×2` là où le
  ticket écrit `2× Cheddar`. Deux notations pour la même donnée, sur deux papiers que le caissier
  compare.
