# LOCK — deux sauces offertes sur les sandwichs, crudités payantes réellement servies, viande en plus juste à la modification

**Fichiers gelés visés** (CLAUDE.md §7) :
- `public/js/pos-wizard.js` — assistant de la caisse ;
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` — assistant de la borne (quantité de sauces envoyée).

**Date** : 2026-11-11
**Gate** : demande du propriétaire du 2026-11-11 (« à corriger ici, c'est-à-dire sur la caisse, sur le site web aussi », puis
« à toi de continuer à faire le nécessaire … finir le tout »). Décision consignée par la session Codex dans
`docs/gates/GATE_CATALOG_POS_KDS_20261111.md` (option 1 approuvée) sur le worktree `wt-app-hybride`.
**État** : ✅ autorisé par le propriétaire
**Hors périmètre** : `PricingService` (inchangé : il facture les extras reçus, prix de la base), `OrderStateMachine`,
la chaîne NF525, `PaymentComponent.vue`, `pos-wizard.css`, `admin-pos-v4.blade.php`.

## 1. Les demandes et les défauts

1. **Deux sauces offertes sur un sandwich** (« la première sauce et la deuxième sauce pour le sandwich offertes »).
   Aujourd'hui la 1ʳᵉ seule est offerte partout ; chaque sauce en plus part en N × l'extra « Sauce supplémentaire » @0,50.
   Mettre ce prix à 0 € en base est impossible : en formule, les sauces des FRITES passent par ce même extra du
   sandwich (elles deviendraient gratuites) ; la caisse affiche une constante 0,50 (écran ≠ facturé) ; le site enverrait
   un total attendu faux (422). Les frites gardent 1 incluse puis payante.
2. **Crudités fantômes sur la caisse** (« plein de crudités déjà sélectionnées, alors que jamais elles sont imprimées »).
   Depuis a5720abe9 le bloc Crudités prend Maïs / Olives / Poivrons cuits (0,90 €, groupe `crudite`) et les affiche
   « ✓ », mais la sérialisation, la ligne de consigne et le défaut au clic suivent encore l'ancienne règle
   (`convert_price === 0`) : jamais envoyées, jamais imprimées. Elles apparaissent aussi en double dans Suppléments.
3. **Viande en plus** (« lors du paiement ça affiche un autre prix … l'écran de cuisine n'affiche pas viande
   supplémentaire ») — chemin Modifier / Dupliquer d'une ligne : l'extra générique est rechargé coché à l'ancienne
   quantité et rangé en supplément ; la quantité n'est jamais renvoyée et la consigne perd « Viandes en plus ».
   Correctif dans `ItemComponent.vue` (non gelé) ; le wizard gelé n'est touché que si c'est indispensable.

## 2. Le changement

- Serveur (non gelé) : `App\Support\Menu\IncludedSauceCount` + `config/pos_sauces.php` `included`
  (`Sandwichs` → 2, défaut 1), publié en `sauce_included` par `ItemResource` (caisse), `NormalItemResource`
  (site, application) et `KioskMenuService` (borne). Aucun prix n'est écrit dans ce nombre.
- `pos-wizard.js` : `sauceIncludedCount()` lit `sauce_included` ; total, libellés et quantité envoyée =
  `max(0, N − incluses)` pour les sauces du produit ; les sauces frites d'une formule restent `M − 1`.
  Crudités : une seule règle `isCruditeExtra` (groupe d'abord), crudité payante = au choix (non cochée par défaut),
  prix affiché, comptée au total, envoyée et imprimée quand elle est cochée ; jamais dans Suppléments.
- `KioskWizardComponent.vue` : `extraSauceN = max(0, N − incluses)` ; frites inchangées.
- Site / application (dépôt séparé) : même règle, même source (`sauce_included`).

Chaque ligne touchée porte le marqueur `[OWNER 2026-11-11 LOCK_POS_KIOSK_SAUCES_CRUDITES_2026-11-11]`.

## 3. Risque assumé (à connaître)

Le serveur ne contrôle pas « 2 sauces offertes au plus » : la 2ᵉ sauce d'un sandwich n'existe que dans la consigne
(`Sauces en plus : …`), comme les sauces supplémentaires aujourd'hui. Le contrôler côté serveur exigerait de passer
l'attribut 5 (partagé avec tacos, burgers, frites) à `max_select = 2`, ce qui ouvrirait la 2ᵉ sauce gratuite partout.
Le montant facturé reste toujours celui que le serveur calcule à partir des extras reçus.

## 4. Preuves exigées

- Vitest : sandwich 2 sauces → 0 extra et 0 € ajouté ; 3 sauces → 1 extra +0,50 ; tacos 2 sauces → +0,50 ;
  formule sandwich + 2 sauces frites → +0,50 ; crudité payante non cochée par défaut, cochée → envoyée et comptée ;
  aucune crudité dans Suppléments ; modification d'une ligne avec viande en plus → quantité renvoyée.
- PHPUnit : `IncludedSauceCount` + publication `sauce_included` sur les trois surfaces ; tickets cuisine
  (`MultiSauceTicketNamesTest`, `KitchenTicketSaucesPlacementTest`) verts.
- `FrozenZoneSha256BaselineSentinelTest` : baseline mise à jour dans le MÊME commit que le correctif.
- `php artisan fiscal:verify-chain --all` → CHAIN OK.
- Capture navigateur de la caisse (Cayenne : crudités payantes non cochées ; 2 sauces sans +0,50).

## 5. Retour arrière

`git revert` du commit de correctif (la baseline revient avec lui). La règle peut aussi être neutralisée sans code :
`config/pos_sauces.php` `included.by_category` vidé → 1 sauce offerte partout, comme avant.
