# CONTRAT TICKET — Client détaillé / Cuisine symbolique (borne + caisse)

> 2026-06-29. **À LIRE avant toute modification touchant l'impression d'un ticket ou
> l'écran cuisine (KDS).** Ce contrat décrit ce que CHAQUE ticket doit afficher. Il
> est verrouillé par des tests (§5) — si tu modifies le format, fais-le des DEUX côtés
> (PHP + JS) et garde les tests verts.

---

## 1. La règle (non négociable)

Toute la prise de commande (chaque étape choisie) doit ressortir, mais SOUS DEUX FORMES :

| Destinataire | Format | Contenu |
|---|---|---|
| **CLIENT** (ticket remis au client) | **DÉTAILLÉ, en toutes lettres** | Nom du produit + **toutes** les étapes : viandes, sauce(s), crudités choisies, pain/galette, suppléments (avec prix), formule + boisson. Lisible par un humain. |
| **CUISINE** (ticket cuisine + écran KDS) | **SYMBOLIQUE, 3 lignes** | Symboles courts pour le cuisinier (voir §3). Même info, condensée. |

Les deux formats lisent **la même source de vérité** : `composition_snapshot` de l'`OrderItem`
(scellé à la création, NF525). On ne réinvente jamais la compo ailleurs.

⛔ Un ticket client qui n'affiche PAS une étape choisie = bug. Un ticket cuisine qui
n'est PAS au format symbolique 3 lignes = bug. Les deux doivent rester **imprimables**.

---

## 2. Les 5 surfaces et leurs fichiers (où c'est codé)

| Surface | Format | Fichier source (NON-frozen) |
|---|---|---|
| **Caisse — ticket CLIENT** | détaillé | `app/Services/Hardware/OrderReceiptEscPosRenderer.php` → `renderClientTicket()` |
| **Caisse — ticket CUISINE** | symbolique 3 lignes | même fichier → `renderKitchenTicket()` → délègue à `KitchenTicketSymbolicFormatter` |
| **Borne — ticket CLIENT** | détaillé | `resources/js/helpers/kioskPrinter.js` → `kioskItemCompositionText()` → `buildBridgePayload()` |
| **Borne — ticket CUISINE** | symbolique 3 lignes | listener `app/Listeners/PrintKioskKitchenTicketOnOrderCreated.php` → `renderKitchenTicket()` (même renderer que la caisse) |
| **Écran KDS** | symbolique 3 lignes | `resources/js/helpers/kdsSymbolic.js` (jumeau JS du formatter PHP) |

➡️ Le format **cuisine** (ticket imprimé) et l'**écran KDS** partagent la même logique,
implémentée DEUX FOIS : `KitchenTicketSymbolicFormatter.php` (impression) et
`kdsSymbolic.js` (écran). **Ils DOIVENT rester identiques** — verrouillé par le sentinel §5.

---

## 3. Format CUISINE symbolique — les 3 lignes

Par produit, dans l'ordre :

```
<qté> x  L1 : SUPPORT | PRODUIT | TAILLE | VIANDES | CRUDITÉS | SAUCES
         L2 : + Supplément payant      (une ligne par supplément, crudités gratuites exclues)
         L3 : MENU  ou  F              (formule : MENU complet, F = frites seules)
```

Exemple réel (Méga galette, 2 viandes, STO, Samouraï, + Cheddar, menu complet) :
```
1 x G | MÉGA | Cordon Tender | STO | SAM
  + Cheddar
  MENU
```

- **Support** : `G` pour tacos/galette (défaut `G` si non précisé), sinon le pain.
- **Crudités** : repliées en une suite ordonnée **`STO`** = Salade, Tomate, Oignon (ordre canonique `S,T,O`). Une crudité non choisie disparaît (`SO` = salade+oignon, sans tomate).
- **Sauces** : symboles courts (voir tables). Sauce supplémentaire = symbole en plus.
- Les **crudités gratuites** (Salade/Tomate/Oignon prix 0) se replient en L1, elles ne
  réapparaissent PAS en L2. Un supplément payant nommé comme une crudité (« Oignons frits » 0,90€) RESTE en L2.

### Tables de symboles (SSOT — dupliquées PHP + JS, à garder identiques)
- **Viandes** : `hach|steak|bœuf`→`K`, `poulet`→`P`, `tender`→`Tender`, `nugget`→`Nug`, `mexic`→`Mex`, `fricadelle`→`Frec`, `cordon`→`Cordon`.
- **Sauces** : `mayo`→`MAY`, `samou`→`SAM`, `hannibal`→`HAN`, `curry`→`CURY`, `andalouse`→`AND`, `blanche`→`BL`, `ketchup`→`KTP`, `burger`→`Burg`, `algerien`→`ALG`, `barbecue|bbq`→`BBQ`, `harissa`→`HAR`, `fromage`→`FRO`, `spicy`→`SPI`.
- **Crudités** : `salade`→`S`, `tomate`→`T`, `oignon`→`O` ; ordre d'impression `S,T,O`.

> Pour AJOUTER un symbole (nouvelle viande/sauce) : l'ajouter dans **les deux** fichiers
> (`KitchenTicketSymbolicFormatter.php` ET `kdsSymbolic.js`) au même endroit, puis lancer
> le sentinel de parité (§5). Sinon l'écran et le papier divergent.

---

## 4. Format CLIENT détaillé

Par produit : `<qté>  <Nom> .......... <prix>` puis, en dessous, en toutes lettres :
- la compo (viandes, sauce, crudités, pain) séparée par virgules ;
- chaque **supplément payant** sur sa ligne avec le prix (`+ Cheddar  0,90 EUR`) ;
- la **formule** + la **boisson** (`+ Menu (Frites + Boisson)`).
Puis les totaux, la TVA, le moyen de paiement, et les mentions fiscales (caisse) /
« À régler en caisse » (borne Plan B).

Exemple (caisse) :
```
1  Méga                                14,80 EUR
   Galette, Cordon Bleu, Tenders, Samouraï, Salade, Tomate, Oignon
   + Cheddar                            0,90 EUR
   + Menu (Frites + Boisson)            2,50 EUR
```

Côté borne, `kioskItemCompositionText()` reconstruit cette compo détaillée depuis les
champs structurés du panier (`item_variations` / `item_extras` / `item_addons`).

---

## 5. Tests qui VERROUILLENT le contrat (à garder verts)

| Test | Ce qu'il protège |
|---|---|
| `tests/Unit/Hardware/KitchenTicketSymbolicFormatterTest.php` | Format symbolique PHP (L1/L2/L3, ordre STO, 2 viandes, crudité-vs-supplément) |
| `tests/js/kdsSymbolic.spec.js` + `kdsSymbolicRender.spec.js` | Format symbolique JS (écran KDS) |
| `tests/Unit/Hardware/KitchenSymbolPhpJsParityTest.php` | **PARITÉ PHP↔JS** des tables de symboles (écran == papier) |
| `tests/js/kioskTicketComposition.spec.js` | Ticket CLIENT borne = compo complète (sauce + crudités + viandes + suppléments) |
| `tests/Feature/Hardware/OrderReceiptEscPosRendererTest.php` | Ticket CLIENT caisse = détail + fiscal |
| `tests/Feature/Hardware/KioskKitchenTicketTest.php` | Le ticket cuisine borne sort bien au bon format |

**Avant de livrer une modif d'impression** : `vendor/bin/phpunit --filter 'Symbolic|Receipt|KioskKitchen'`
+ `npx vitest run tests/js/kdsSymbolic*.spec.js tests/js/kioskTicketComposition.spec.js`.

---

## 6. Checklist « je modifie un truc un jour »

- [ ] La compo client reste **complète** (toutes les étapes en toutes lettres) ? → test §5.
- [ ] La cuisine reste **symbolique 3 lignes** (L1/L2/L3) ? → test §5.
- [ ] Si j'ai touché un symbole : modifié dans **PHP + JS** ? → sentinel parité §5 vert.
- [ ] Les 5 surfaces (§2) lisent toujours `composition_snapshot` (jamais une compo réinventée) ?
- [ ] Borne ET caisse, le ticket cuisine reste **imprimable** (renderer branché) ?
