# DIAG — Multi-sauces / multi-choix : ticket + KDS ne gardent que la 1ère (prix historiquement faux)

> Investigation READ-ONLY (systematic-debugging). Aucun fichier applicatif modifié.
> Serveur :8000, DB `foodking_e2e`. Repro reconstituée en tinker (rendu ESC/POS réel).
> Date : 2026-07-18.

## TL;DR — verdict

**UN seul bug racine (modèle de données), pas 2-3 bugs de renderers indépendants.**

Le 2ᵉ+ choix d'un multi-select « même type » (2ᵉ sauce, et par extension toute « X
supplémentaire ») est persisté comme un **`item_extra` GÉNÉRIQUE** (« Sauce supplémentaire »
@0,50) qui ne porte **qu'un prix, aucune identité** du choix. Le `composition_snapshot`
(SSOT NF525) ne contient donc **jamais le nom de la 2ᵉ sauce**. Toutes les surfaces
imprimées / cuisine lisent ce snapshot (correctement) → elles ne peuvent afficher que la
1ère sauce (la variation nommée) + une ligne générique « + Sauce supplémentaire ».

Le nom réel des sauces en plus survit UNIQUEMENT dans le **texte libre `instruction`**
(caisse : `Sauce : X, Y` ; borne : `Sauces en plus : X`) — ce qui explique pourquoi le
**panier / écran de paiement** (recap wizard client-side + instruction brute) les montrent.
Mais **tous les rendus ticket + KDS strippent volontairement la ligne compo de
l'instruction** (regex `cleanInstruction` / `sanitizeKdsInstruction`) → le nom meurt là.

Le **« prix faux »** est un problème DISTINCT et **déjà corrigé il y a 2-3 jours** (surcoût
sauce jadis *display-only*, jamais envoyé au backend → commande scellée au prix de base).
Corrigé borne 2026-07-15 + caisse 2026-07-16. **Risque résiduel** : produits SANS extra
« Sauce supplémentaire » (Frites Seules/Petite/Grande) → sauce en plus gratuite alors que
l'écran peut afficher +0,50.

---

## Repro (vérité terrain)

Commande **#5727 / OrderItem #5484** — Tacos M, 2 sauces **« Algérienne, Andalouse »**,
total 7,40 € (base 6,90 + 0,50).

`composition_snapshot` réel (DB) :
```json
"lines":  [ {"attribute_name":"Sauce (1ère Gratuite)","variation_name":"Algérienne", ...},
            {"attribute_name":"Viande 1","variation_name":"Poulet mariné", ...} ],
"extras": [ {"extra_name":"Salade",...0}, {"extra_name":"Tomate",...0}, {"extra_name":"Oignon",...0},
            {"extra_id":431,"extra_name":"Sauce supplémentaire","quantity":1,"line_total":0.5} ]
```
→ « Andalouse » (2ᵉ sauce) **absente du snapshot**. Elle n'existe que dans :
`instruction = "TACOS M\nViandes : Poulet mariné - Salade, Tomate, Oignon Sauce : Algérienne, Andalouse"`.

**Ticket CLIENT ESC/POS rendu** (`OrderReceiptEscPosRenderer::renderClientTicket`) :
```
1  Tacos M                              7,40 EUR
   Algérienne, Poulet mariné, Salade, Tomate, Oignon
+ Sauce supplémentaire                  0,50 EUR      ← générique, "Andalouse" absente
```
**Ticket CUISINE rendu** (`renderKitchenTicket`) :
```
  G | TAC | M | P | STO
  | ALG                                                ← seule la 1ère sauce (symbole)
    * Sauce supplémentaire                             ← générique, "AND" (Andalouse) absente
```

---

## Chaîne causale (file:line)

### 1. Création — le nom de la 2ᵉ sauce n'entre jamais dans le snapshot

- **Caisse** (frozen `public/js/pos-wizard.js`) :
  - `4012-4023` : `sauceSupplQty = sauceOrder.length - 1` ; recherche l'ItemExtra « Sauce
    supplémentaire » (par nom) → `sauceSupplExtraId`. **Seule la quantité** est transmise,
    jamais le nom des sauces choisies.
  - `4141-4145` `[LOCK_CAISSE_SAUCE_SEAL 2026-07-16]` : pose `data-wizard-qty = N` sur la
    checkbox de l'extra générique → snapshot final : `extra_name:"Sauce supplémentaire",
    quantity:N`. **Aucune identité.**
  - `3805` (`buildTicketInstruction`) : écrit `Sauce : <noms>` dans `instruction` (seul
    endroit où « Andalouse » est persistée).
- **Borne** (frozen `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`) :
  - `1849-1850` : `sauceOrder[0]` → variation gratuite (1ère sauce nommée).
  - `1906-1928` `[COMPOSITION-SAUCE BORNE 2026-07-15]` : `for (i<extraSauceN)
    normalizedExtras.push({id: ssExtra.id, name:"Sauce supplémentaire"})` → N extras
    génériques, **sans nom**.
  - `2142-2148` (`buildInstruction`) : écrit `Sauces en plus : <noms>` dans `instruction`.
- **Web** : `app/Services/FrontendOrderService.php:455` construit le snapshot via le MÊME
  `CompositionSnapshotBuilder` ; le composer web est un miroir du borne → même extra
  générique.
- **Builder SSOT** `app/Services/Pricing/CompositionSnapshotBuilder.php:83-103` : recopie
  fidèlement `extra_id / extra_name / quantity / prix`. Il **ne peut pas inventer** le nom :
  la donnée n'est pas dans le payload. → Bug racine EN AMONT du builder, dans le payload wizard.

### 2. Rendu — les surfaces SSOT ne lisent que la 1ère sauce, et strippent l'instruction

- **Ticket client** `app/Services/Hardware/OrderReceiptEscPosRenderer.php` :
  - `436-442` : itère `snap['extras']` → « Sauce supplémentaire » rendu générique (`505`).
  - `518` : `cleanInstruction(instruction, name)` → supprime la ligne compo (voir 2bis).
- **Ticket cuisine** idem fichier `302-346` : la ligne symbolique vient de
  `KitchenTicketSymbolicFormatter::mainLine` → sauces lues **uniquement** depuis
  `snap['lines']` (variations) `KitchenTicketSymbolicFormatter.php:164` = 1ère sauce.
  Les extras passent par `supplementLines` `:209-225` → « + Sauce supplémentaire » générique.
- **KDS écran** `resources/js/helpers/kdsSymbolic.js:229-230` : `case 'sauce': sauces.push(...)`
  lit **seulement** les variations (1ère sauce). `readExtras` `:135-138` → générique.
- **Aperçu ticket à l'écran** `resources/js/components/admin/pos/ReceiptComponent.vue:564` :
  utilise aussi `sanitizeKdsInstruction` → même strip.

### 2bis. Le strip qui tue le nom (le point commun)

Regex identique PHP + JS :
- PHP `KitchenTicketSymbolicFormatter.php:412` — `$compoRe = '/(^|\s)(Viandes?|Sauce|Suppl[ée]ment|Pain|Galette)\s*:/iu'` ; drop ligne `:459`.
- JS `resources/js/helpers/kdsCustomization.js:221` — `KDS_COMPO_LINE_RE = /(^|\s)(Viandes?|Sauce|Suppl[ée]ment|Pain|Galette)\s*:/i`.

**Test empirique `cleanInstruction`** (tinker) :
```
CAISSE "…Viandes : … Sauce : Algérienne, Andalouse"      => []   (ligne dropée entière)
BORNE  "Pain : Pain. Viandes : …. Sauces en plus : Andalouse. Menu : …" => []   (mono-ligne
        jointe par ". " → dès qu'un marqueur "Pain :/Viandes :" matche, TOUTE la ligne saute)
BORNE  "Sauces en plus : Andalouse" (isolée hypothétique)  => "Sauces en plus : Andalouse"
```
→ En pratique **caisse ET borne perdent le nom** : la caisse met tout le compo sur une
ligne « Viandes : … Sauce : … » (match direct) ; le borne joint tout par « . » en une
seule ligne qui matche sur « Pain :/Viandes : ». Le nom de la sauce en plus n'apparaît
sur aucun ticket ni KDS. **C'est un seul et même bug sur les 3 systèmes.**

### 3. Pourquoi le paiement/panier MARCHE (asymétrie)

Le recap wizard est 100 % client-side : `pos-wizard.js:2205-2211` pousse chaque sauce
`nom + (gratuit)` / `nom + (+0,50)` ; le panier POS affiche aussi l'`instruction` brute
(`PosComponent.vue:1490-1492`). Ces surfaces **ne lisent pas le snapshot** et **ne
strippent pas** l'instruction → tous les noms + prix visibles. D'où « panier ✓, paiement ✓,
ticket/KDS ✗ ».

---

## Le « prix faux » — déjà corrigé (distinct du nom)

Origine documentée dans le code borne `KioskWizardComponent.vue:1910-1913` :
> « AVANT : surcoût display-only (sauceVariationSurcharge) jamais envoyé → écran 7,40 €
> mais order scellé 6,90 € = fuite revenu + composition_snapshot sous-facturé (risque NF525). »

→ C'est exactement le « PRIX imprimé faux (celui de la 1ère seulement) » de l'owner.
**Corrigé** : borne 2026-07-15 (`1906-1928`), caisse 2026-07-16 (`4141-4145`
`LOCK_CAISSE_SAUCE_SEAL`). Vérifié : #5727 (créée 2026-07-16) → ticket 7,40 € avec +0,50 OK.

**Risque prix RÉSIDUEL** — produits sans ItemExtra « Sauce supplémentaire » :
`Frites Seules #2`, `Petite Frites #33`, `Grande Frites #34` = **NONE** (sauce en plus
gratuite, aucun mécanisme backend). Les `Bol Frites #41`, `Bowl Frites Poulet #42/43/44`,
`Bol Riz #45` **ont** l'extra → facturés correctement. Si le wizard affiche +0,50
(constante plate `SAUCE_EXTRA_PRICE`) sur un produit sans extra → écran > scellé.
À vérifier au fix : ces produits exposent-ils une étape multi-sauces ?

---

## Distinction bug commun vs bugs parallèles

| Symptôme | Nature | Statut |
|---|---|---|
| 2ᵉ+ sauce absente ticket client | racine snapshot + strip instruction | **OUVERT** |
| 2ᵉ+ sauce absente ticket cuisine | même racine (lit snapshot) | **OUVERT** |
| 2ᵉ+ sauce absente KDS écran | même racine (jumeau JS) | **OUVERT** |
| 2ᵉ+ sauce absente aperçu reçu écran | même racine (`sanitizeKdsInstruction`) | **OUVERT** |
| Prix scellé = base seule | surcoût display-only | **CORRIGÉ** 07-15/16 |
| Frites plates : sauce+ gratuite | pas d'ItemExtra sur le produit | à confirmer |

→ **1 bug racine de données** (identité du choix non persistée dans le SSOT), commun aux
3 systèmes, décliné en 4 surfaces d'affichage. Les renderers ne sont PAS fautifs : ils
lisent correctement un SSOT incomplet.

---

## Approche de fix proposée (NON implémentée — gate owner requis : wizard frozen + snapshot NF525)

**Option A — recommandée (data-model, NF525-grade).** Faire porter l'IDENTITÉ du choix
jusqu'au snapshot :
1. Wizard (caisse `pos-wizard.js`, borne `KioskWizardComponent.vue`, miroir web) : quand on
   émet l'extra « Sauce supplémentaire », transmettre AUSSI le(s) nom(s) de sauce choisi(s)
   (p.ex. champ `choice_name`/`label` par occurrence, ou une occurrence d'extra par sauce
   nommée au lieu de `quantity:N`).
2. `CompositionSnapshotBuilder::build()` (`:83-103`) : persister ce label dans chaque entrée
   `extras[]` (nouveau champ, rétro-compatible).
3. Renderers : `OrderReceiptEscPosRenderer` (client + `supplementLines`), `kdsSymbolic.js`,
   `KitchenTicketSymbolicFormatter` → afficher « + Sauce supplémentaire : Andalouse » (ou
   mapper le nom vers le symbole sauce `AND` dans la ligne cuisine).
   *Avantage* : le SSOT décrit enfin ce qui a été vendu (correct NF525) ; parité totale
   panier↔ticket↔KDS. *Coût* : touche zones frozen (wizard) + snapshot → **LOCK + gate owner**.

**Option B — palliatif display (léger, partiel, fragile).** Ne plus stripper la portion
sauce de l'instruction. Problème : le borne concatène tout en UNE ligne → un un-strip naïf
réinjecte tout le blob compo (double rendu). Nécessiterait de restructurer l'instruction en
lignes séparées. Ne corrige pas le SSOT (reste incomplet pour un audit fiscal).

**Option C — modéliser en variations répétées** (attribut sauce max>1, 2ᵉ+ variation
facturée). Plus lourd, touche la SSOT prix fiscale. Non recommandé en V1.

→ **Recommandation : Option A**, sous LOCK frozen-zone + gate owner (pos-wizard.js +
KioskWizardComponent.vue frozen §7 ; composition_snapshot = SSOT NF525 §8).

---

## Fichiers clés

- `public/js/pos-wizard.js` (frozen) : `4012-4023`, `4141-4145`, `3805` (émission caisse + instruction)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (frozen) : `1849-1850`, `1906-1928`, `2142-2148`
- `app/Services/FrontendOrderService.php:455` (snapshot borne/web)
- `app/Services/Pricing/CompositionSnapshotBuilder.php:83-103` (persistance extras)
- `app/Services/Hardware/OrderReceiptEscPosRenderer.php:436-442,505,518` (ticket client) + `302-346` (cuisine)
- `app/Services/Hardware/KitchenTicketSymbolicFormatter.php:164,209-225,406-462` (symbolique + strip)
- `resources/js/helpers/kdsSymbolic.js:229-230`, `resources/js/helpers/kdsCustomization.js:221` (KDS écran + strip)
- `resources/js/components/admin/pos/ReceiptComponent.vue:564` (aperçu reçu écran)
