# FoodKing Version A — Product, Catalog, Stock & Composer Sync Spec — 2026-04-30

## Objectif

Decrire comment FoodKing doit gerer un produit centralise de bout en bout: creation, categorie, photo, stock, rupture, composer wizard, projection POS/Kiosk/KDS, commande, decrement, release, historique et synchronisation.

## Types de produits supportes

| Type | Wizard requis | Stock possible | Exemple |
| --- | --- | --- | --- |
| Produit pret | Non | Oui | boisson, dessert, gateau, frite |
| Produit simple avec options | Optionnel court | Oui produit + choix | taille, cuisson, sauce simple |
| Produit compose | Oui | Oui produit + choix | tacos, sandwich configurable, menu |
| Supplement / addon target | Selon usage | Oui | fromage, viande supplementaire, boisson addon |
| Ingredient / crudite / sauce | Choix wizard | Oui si stockable | tomate, mais, sauce algerienne |

Decision utilisateur integree:

- Tout ce qui est produit vendable peut etre mis en rupture complete.
- Tout choix de composition stockable peut etre mis en rupture dans le wizard.
- Le systeme doit pouvoir afficher un choix indisponible au bon endroit, sans permettre la commande.

## Cycle de vie dashboard

### Creation produit

1. Creer categorie si necessaire.
2. Creer item avec statut/visibilite.
3. Uploader photo si role global autorise.
4. Declarer stock produit si suivi stock.
5. Declarer variations/extras/addons ou composer profile selon complexite.
6. Publier le composer profile si produit compose.
7. Verifier projection Kiosk/POS.

### Modification

Modification possible:

- nom, categorie, image, statut, disponibilite;
- stock produit;
- options composer;
- steps wizard;
- visibilite surface.

Effet attendu:

- caches/projections invalides;
- event outbox catalogue/availability;
- POS/Kiosk ne doivent pas garder une option supprimee comme commandable;
- backend pricing doit rejeter tout payload stale.

### Suppression / desactivation

La suppression dure doit rester rare. Comportement recommande Version A:

- preferer status hidden/inactive pour conserver historique commande;
- si un produit est reference par commandes historiques, ne pas casser les snapshots;
- KDS/POS historique lit snapshots de commande, pas le catalogue live pour recalculer le passe.

## Composer wizard

Un produit peut avoir:

- aucun profil composer publie: ajout direct au panier;
- profil composer simple: une ou deux etapes;
- profil composer complet: multi-step avec min/max/repeat.

Regles:

- Le profil publie est prioritaire sur les anciennes heuristiques.
- Les steps doivent etre branch-scoped selon le profil.
- Chaque choix soumis doit exister dans le profil publie courant.
- Les contraintes min/max/repeat sont validees backend.
- Une option indisponible ne peut pas satisfaire `required`.
- Un choix retire du profil entre ouverture panier et submit est rejete backend.

## Projection par surface

| Donnee | Dashboard | POS | Kiosk | KDS | OSS |
| --- | --- | --- | --- | --- | --- |
| Produit | CRUD | lit + commande | lit + commande | snapshot commande | non critique |
| Categorie | CRUD | lit | lit | non critique | non critique |
| Photo | upload global | affichage | affichage | optionnel | non critique |
| Stock produit | edit/toggle | badge + block | badge + block | badge/event | non critique |
| Stock choix | edit via stock/composer | disabled + block | disabled + block | snapshot commande | non critique |
| Composer profile | edit/publish | wizard compact | wizard client | snapshot seulement | non critique |
| Order status | dashboard/order | update/read | waiting/confirmation | update/read | read |

## Stock et snapshots

Au moment de la commande:

1. Backend construit le snapshot de composition.
2. Backend scelle le prix depuis DB/profil courant.
3. `StockService` decremente les produits et addon targets stockables.
4. Les choices stockables indisponibles sont rejetes avant decrement.
5. En cancel/refund, release idempotent restaure selon snapshot et ledger.

Ce qui ne doit jamais arriver:

- stock negatif;
- frontend accepte un choix indisponible par appel direct;
- backend accepte un prix client;
- ancienne option supprimee reste commandable;
- branch A voit stock/commande/profil branch B.

## API vs MCP

Decision Version A:

- Les surfaces runtime communiquent par API Laravel + outbox/realtime.
- MCP n'est pas le bus runtime client. Il peut servir aux agents/devtools, pas a la caisse/borne/KDS.
- Les contrats stables sont routes API, events outbox, schemas de payload, et projections backend.

Raison:

- les appareils POS/Kiosk/KDS doivent fonctionner sans agent MCP local;
- auth, branch isolation, logs, rate limiting et fiscalite restent dans Laravel;
- outbox permet replay/retry/audit, ce qu'un MCP direct ne garantit pas pour le runtime client.

## Tests de reference

| Domaine | Suites |
| --- | --- |
| Composer constraints | `tests/Feature/Services/Pricing/ComposerStepConstraintTest.php` |
| Menu projection | `tests/Feature/Services/Menu` |
| Stock produit/choix | `tests/Feature/Stock` |
| POS/Kiosk rupture UX | `tests/js/posRuptureUx.spec.js`, `tests/js/kioskRuptureUx.spec.js` |
| Kiosk generic wizard | `tests/js/kioskWizardGenericComposer.spec.js` |
| Catalog event/photo | `tests/Feature/Catalog/*`, `tests/Feature/Menu/*` |
| Outbox/realtime | `tests/Feature/Outbox`, `tests/js/eventContractDedupe.spec.js` |
| Runtime multi-surface | `tests/e2e/c3-runtime-multi-surface.spec.js` |

## Definition of done produit centralise

Un produit est considere valide Version A quand:

1. Il est cree/modifie dans Dashboard avec permissions correctes.
2. Sa categorie/photo/statut sont visibles sur POS et Kiosk.
3. Son composer wizard est publie ou absent volontairement.
4. Ses choices stockables montrent la rupture au bon endroit.
5. POS et Kiosk ne peuvent pas submit un choix indisponible.
6. Backend pricing rejette prix forge, stale choice et inactive choice.
7. La commande arrive KDS/OSS/POS sans reload manuel.
8. Le stock decremente puis release sur cancel/refund.
9. L'historique commande reste lisible via snapshots.
10. Le flux est couvert par test automatisable ou note UAT hardware.

