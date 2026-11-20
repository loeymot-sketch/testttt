# Gate Brief — WIZARD DE CATÉGORIE JAMAIS LU — 2026-08-28

> **⚠️ EN ATTENTE DE SIGNATURE DU PROPRIÉTAIRE.** Aucun code n'a été modifié pour ce
> défaut. Ce document existe pour qu'il ne soit pas oublié, pas pour l'autoriser.

## Le défaut, en une phrase

Le wizard de catégorie — **le seul qu'un commerçant neuf puisse ouvrir** — est écrit en
base, lié depuis `item_categories.wizard_profile_id`, et **relu par personne**. Ni la
borne, ni la caisse, ni le calcul de prix.

## Ce que vit le commerçant

Studio Catalogue → sélectionner une catégorie → « Wizard de la catégorie ». Il compose
ses pages Pain / Viande / Sauce, règle ses minimums et maximums, choisit ce qui est
offert et ce qui est payant. L'écran lui affirme, mot pour mot :

> « Ce wizard s'applique à TOUS les produits de cette catégorie. »
> — `resources/js/components/admin/items/CatalogStudioComponent.vue:43` et `:185`

Il clique **Publier**. Le bandeau confirme. Il ouvre la borne : l'ancien parcours
heuristique. Il ouvre la caisse : idem. Il passe une commande : aucune de ses règles
n'est appliquée, aucun de ses suppléments n'est facturé selon sa configuration.

Il a passé une heure à configurer une fonctionnalité **entièrement inerte**, et rien à
l'écran ne le lui dit.

## La chaîne, vérifiée ligne par ligne

1. **Un profil de catégorie a `item_id = NULL`, par construction.**
   `app/Services/Composer/ComposerProfileService.php:86` écrit `'item_id' => null`.
   Une contrainte SQL l'impose :
   `database/migrations/2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner.php:112-115`
   → `CHECK ((item_id IS NOT NULL) <> (item_category_id IS NOT NULL))`.

2. **Tous les lecteurs de production cherchent par `item_id`.**
   - `app/Services/Pricing/PricingService.php:566` → `whereIn('item_id', $itemIds->all())`
   - `app/Services/Kiosk/KioskMenuService.php:477` → `whereIn('item_id', $itemIds)`
   - `app/Services/Menu/MenuProjectionService.php:300` → `whereIn('item_id', $itemIds)`
   - `app/Http/Resources/NormalItemResource.php:184`, `app/Http/Resources/ItemResource.php:151`

   Un `whereIn('item_id', …)` ne peut pas atteindre une ligne dont `item_id` est NULL.

3. **Les deux méthodes qui SAURAIENT résoudre la catégorie n'ont aucun appelant.**
   Vérifié par `grep -rn` sur `app/`, `resources/`, `routes/` — seules leurs propres
   définitions apparaissent :
   - `app/Services/Composer/ComposerProfileService.php:104` — `resolveForItem()`,
     dont le commentaire annonce « la catégorie gagne »
   - `app/Models/ItemCategory.php:162` — `getEffectiveWizardProfile()`

4. **Et le wizard PAR PRODUIT est éteint.**
   `.env.example:103` → `FEATURE_WIZARD_PER_ITEM_DEMO=false`. Sa route porte
   `beforeEnter: requireWizardPerItemDemo` (`itemRoutes.js:152`) ; la route de catégorie
   n'a **aucune garde** (`:154-168`). Sur une installation par défaut, le wizard de
   catégorie est donc le seul accessible — et c'est celui qui n'est pas lu.

## Pourquoi ce n'est PAS une architecture à décider

**Elle l'a déjà été, et signée.** `docs/gates/GATE_CV1-V1-PIVOT-WIZARD-CATEGORY-OWNER_2026-05-04.md` :

- **Option 1 « Voie A »** — owner polymorphique + **« résolution fallback catégorie →
  item legacy »** dans `ComposerProfileService` et `ComposerProfileProjection` :
  **APPROUVÉE** (`[x] Approved — option selected: 1 (Voie A)`).
- **Option 2 « Voie B »** — *copy on attach*, duplication des données :
  **« NON recommandé »**, non retenue.

La moitié SCHÉMA de la voie A a été livrée : migration, contrainte XOR, `resolveForItem()`
écrite. **La moitié RÉSOLUTION n'a jamais été câblée.** Le gate a été approuvé puis
construit à moitié.

## La contradiction qui exige votre arbitrage

Le gate de mai déclare, dans ses invariants :

> « I1 pricing SSOT : non touché. » — « I6 frozen zones : non touché. »

Or câbler la résolution approuvée **oblige** à modifier `app/Services/Pricing/PricingService.php:566`,
qui est en **ZONE GELÉE (CLAUDE.md §7)**. L'option approuvée exige donc une modification
que le document qui l'approuve exclut explicitement.

C'est cette contradiction que je ne peux pas trancher seul (CLAUDE.md §12), et c'est
pourquoi **je n'ai touché à rien**.

## Décision demandée

Autoriser — ou refuser — le câblage de la résolution catégorie dans les cinq lecteurs,
**y compris `PricingService` (zone gelée §7)**.

### Options

1. **Voie A, terminée** (cohérente avec la décision de mai) : brancher `resolveForItem()`
   dans les cinq lecteurs. Un seul endroit par lecteur ; aucune donnée dupliquée ;
   `ItemWizardStepVersion` continue de figer l'instantané à la publication.
   **Exige de lever la zone gelée sur `PricingService` pour cette ligne.**

2. **Voie B, déploiement à la publication** : publier un profil de catégorie crée un
   profil par produit de la catégorie. Aucun fichier gelé touché — mais duplication des
   données, réconciliation à chaque republication, et c'est l'option que vous aviez
   écartée en mai.

3. **Ne rien faire, et le DIRE au commerçant** : masquer ou désactiver le bouton
   « Wizard de la catégorie », ou y afficher un avertissement explicite. Coût nul,
   honnêteté restaurée, fonctionnalité toujours absente.

### Ce que je recommande

**Option 1.** C'est votre décision de mai, elle est cohérente avec le mandat du
programme (« ~10 catégories de personnalisation par produit »), et elle ne duplique
aucune donnée. La zone gelée protège `PricingService` d'un changement de logique de
PRIX ; ici la modification porte sur la RÉSOLUTION DU PROFIL, pas sur le calcul.

Si vous ne voulez pas ouvrir la zone gelée aujourd'hui, **l'option 3 est préférable à
l'inaction** : un bouton qui ment coûte plus cher qu'un bouton absent.

## Approbation

[ ] **Option 1** — câbler la résolution, zone gelée `PricingService` levée pour cette ligne
[ ] **Option 2** — déploiement à la publication
[ ] **Option 3** — désactiver ou avertir, en attendant
[ ] **Refusé / différé** — motif : ______________________

Signature propriétaire : ______________________  Date : ____________
