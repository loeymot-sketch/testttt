# FICHES DE RENVOI — ce qui a été trouvé hors de la voie où on l'a trouvé

> Règle du protocole §5 : on ne corrige jamais un fichier qui appartient à une autre voie.
> On émet une fiche, datée, avec la preuve, et le propriétaire corrige chez lui.
> Chaque fiche ci-dessous a été **vérifiée dans le code** avant d'être écrite.

---

## F-01 → **ONB-10** (équipement) · le téléphone du Cayenne est en dur dans le ticket

**Trouvé en** ONB-01 (identité de l'établissement).
**Fichier possédé par ONB-10** : `config/printing.php`.

```php
// config/printing.php:109
'phone' => env('RECEIPT_PHONE', '03 65 67 82 91'),
```

Le commentaire du fichier précise lui-même que cette valeur part **à la caisse (`OrderReceiptEscPosRenderer`) ET à la borne** (`master.blade` → `window.foodkingConfig.borneTicket.phone` → `bridge.js`).

**Conséquence pour un nouveau commerçant** : si sa filiale n'a pas de téléphone renseigné, son ticket affiche le numéro du Cayenne d'origine. Il donne le numéro d'un autre restaurant à ses clients.

**Ce qu'il faut** : le repli doit venir de la filiale (`branch->phone`), pas d'une constante. Si aucune source n'existe, ne rien imprimer plutôt qu'un faux numéro.

---

## F-02 → **ONB-05** (réglages) · la page des taxes est inatteignable au clic

**Trouvé en** ONB-02 (catalogue).
**Fichier possédé par ONB-05** : `resources/js/config/v1-hidden-modules.js`.

```js
// resources/js/config/v1-hidden-modules.js:39
'settings.tax',
```

**Conséquence** : un commerçant doit régler ses taux de TVA — c'est une obligation, pas un confort — et la page n'apparaît pas dans le menu. Elle n'est atteignable qu'en devinant l'URL `/admin/settings/taxes/list`.

**Aggravant mesuré** : sur 170 articles en base, 77 sont à 0 % de TVA. La page qui permettrait de corriger ça est celle qu'on a cachée.

**Ce qu'il faut** : `settings.tax` sort de la liste des modules cachés. C'est une décision qui relève du gate G-CACHE (arbitrage page par page des 22 entrées).

---

## F-03 → **ONB-10** (équipement) · `App\Enums\KdsStation` reste à créer

**Trouvé en** ONB-02. **Collision déclarée au protocole §5** : l'énumération est revendiquée par ONB-02 et ONB-10 ; ONB-10 la crée, ONB-02 s'y branche.

La colonne `items.kds_station` est un ENUM MySQL strict à quatre valeurs (`bar`, `cuisine_chaude`, `cuisine_froide`, `none`) — migration `add_kds_station_to_items`. La règle de validation acceptait n'importe quelle chaîne de 32 caractères ; elle est désormais alignée **en écrivant les quatre valeurs en clair** dans `ItemRequest`, pour ne pas créer l'énumération à la place d'ONB-10.

**Ce qu'il faut** : quand ONB-10 crée `App\Enums\KdsStation`, remplacer la liste littérale de `ItemRequest` par la constante. Une seule source, trois consommateurs.

---

## F-04 → **ONB-02** (catalogue), vague suivante · l'import Excel ne dit jamais ce qui a échoué

**Trouvé en** ONB-02 W1, non corrigé faute de temps dans la vague.

```php
// app/Http/Controllers/Admin/ItemController.php — import()
Excel::import(new ItemImport($request->file('file')), $request->file('file'));
return response('', 202);
```

Un `202` avec un **corps vide**. Si trois lignes sur cinquante échouent, le commerçant lit « accepté » et n'apprendra jamais lesquelles ni pourquoi.

**Ce qu'il faut** : un rapport d'import — nombre de lignes lues, créées, ignorées, et la liste des refus avec le motif. Et le gabarit fourni (`itemImportSample.xlsx`) est en anglais avec des catégories qui n'existent pas.

---

## F-05 → **ONB-06** (rôles) · un test de seeder de permissions échoue déjà

**Trouvé en** ONB-02 en lançant la suite Branch, **sans lien avec les modifications d'ONB-02**.

`tests/Feature/Seeders/RolePermissionSeederTest::test_branch_manager_receives_expected_permissions`
→ `SQLSTATE[23000]: UNIQUE constraint failed: permissions.name, permissions.guard_name`

Le seeder de permissions n'est pas idempotent : rejoué sur une base qui en contient déjà, il tente de réinsérer. Le test échoue **aussi en isolement**, donc ce n'est pas un problème d'ordre d'exécution.

**Ce qu'il faut** : `updateOrCreate` (ou `firstOrCreate`) sur `(name, guard_name)` plutôt qu'un `insert` en masse. C'est la voie d'ONB-06.

---

## F-06 → **ONB-12** (publication vierge) · la pollution de test est soudée à la chaîne fiscale

**Trouvé en** ONB-01, mesuré en base.

| Résidu | Ce à quoi il est lié |
|---|---|
| 5 filiales fictives (« Collier and Sons Branch »…) | **5 commandes, 11 `audit_logs`, 6 `z_reports`** |
| 26 taxes `AUDIT-*` | 52 articles |
| 58 articles de test | 42 lignes de commande |

`audit_logs` et `z_reports` sont append-only avec un trigger qui interdit le DELETE. **Ces déchets ne peuvent plus être retirés de `foodking_e2e`.**

**Conséquence pour ONB-12** : la « publication vierge » ne pourra JAMAIS se prouver sur cette base. La base dédiée n'est pas une commodité, c'est le seul instrument possible. Et l'interdit « aucune commande créée » du protocole cesse d'être une précaution : c'est un fait démontré.

**Visible à l'écran** : `E2E_PLAYWRIGHT_STUDIO_ITEM` à 1,00 € apparaît dans le catalogue entre « Sandwich Classique » et « Perrier 33cl ».

---

## F-07 → **propriétaire** · le LOCK de la borne n'est pas contresigné

**Trouvé en** inspectant l'arbre principal.

Parmi les 235 fichiers stagés de l'arbre principal, `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (**zone gelée §7**) porte 53 lignes de modification. Elles référencent `LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP_2026-08-25`, et le document existe dans `docs/locks/`. Mais :

> **Contresignature propriétaire :** ☐ *(à cocher par le propriétaire — je ne signe pas à sa place)*

Le hook `pre-commit` du dépôt bloque le commit — **correctement**. Le contournement `--no-verify` est interdit par CLAUDE.md §3quater sans accord explicite.

**Ce qu'il faut** : soit la case est cochée, soit ce fichier sort du commit. Décision propriétaire, personne d'autre.

---

## F-08 → **ONB-13** (sécurité) · une garde d'upload appliquée à un chemin, oubliée sur l'autre

**Trouvé en** ONB-04, en cartographiant les deux motifs Vision existants.

Deux chemins d'upload de photo, la même brique, deux traitements :

```php
// app/Http/Controllers/Admin/PurchasingScanController.php:61  — facture
'photo' => ['required', 'file', 'max:12288', new \App\Rules\NoDangerousFileExtension()],

// app/Http/Controllers/Admin/UberPhotoCaptureController.php   — ticket Uber
'photos.*' => ['required', 'file', 'max:'.$maxKb],     // ← la règle manque
```

Le commentaire du fichier « propre » (`:54-56`) dit que `NoDangerousFileExtension` a été ajoutée par un correctif de sécurité visant les extensions `.pht`. Elle a été posée à un endroit et **pas à l'autre**.

**Gravité réelle, mesurée et non enflée** : les fichiers Uber sont stockés sur le disque `local` (`$file->store('uber-tickets', 'local')`, `:89`), **hors de `public/`**. Ils ne sont donc pas atteignables en HTTP : ce n'est **pas** une exécution de code à distance. C'est une **défense en profondeur manquante** — la garde existe, elle protège un chemin, elle a été oubliée sur le second.

**Ce qu'il faut** : appliquer la même règle aux deux. Le coût est d'une ligne ; l'écart, lui, est le genre qui devient une faille le jour où quelqu'un change le disque de stockage.

**À ne pas copier** : ONB-04 va créer un troisième chemin d'upload (la photo de la carte). Qu'il reprenne le motif de `PurchasingScanController`, jamais celui d'Uber.

---

## F-09 → **ONB-04** (à traiter dans sa propre vague) · aucun compteur de dépense IA

Grep exhaustif : **aucun compteur, aucun plafond, aucune trace** de ce qui est dépensé en appels Vision. Un commerçant qui photographie sa carte de 200 produits ne saura jamais ce que ça lui a coûté, et rien ne l'empêche de relancer cent fois.

**Ce qu'il faut** avant tout appel réel (gate G-IA) : un compteur par établissement, un plafond configurable, et le refus au-delà — pas une facture surprise.
