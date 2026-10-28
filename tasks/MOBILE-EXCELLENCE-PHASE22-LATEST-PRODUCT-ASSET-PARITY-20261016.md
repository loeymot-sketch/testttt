# MOBILE-EXCELLENCE-PHASE22-LATEST-PRODUCT-ASSET-PARITY-20261016

## Mandat

Aligner l'application mobile sur les dernières images produits actuellement
résolues par le site et la borne Le Cayenne. Le périmètre porte uniquement sur
les sandwichs, les galettes et les tacos, y compris les cartes de catégorie et
le choix de pain galette.

## Preuve de source

Le site et la borne utilisent les noms PNG du pack `public/images/menu/` via
`config/menu_images.php`. L'application mobile utilise encore les vignettes
WebP historiques de `public/images/menu/thumbs/` pour ces références.

## Scope

- `mobile/data/menu.js`
- `mobile/assets/menu/cat-sandwich-cayenne.png`
- `mobile/assets/menu/cat-galette.png`
- `mobile/assets/menu/cat-tacos.png`
- `mobile/assets/menu/sandwich-cayenne.png`
- `mobile/assets/menu/sandwich-supreme.png`
- `mobile/assets/menu/sandwich-mega.png`
- `mobile/assets/menu/sandwich-terminator.png`
- `mobile/assets/menu/galette.png`
- `mobile/assets/menu/tacos-cayenne.png`
- `mobile/tests/mobile-catalog-visual.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `tasks/MOBILE-EXCELLENCE-PHASE22-LATEST-PRODUCT-ASSET-PARITY-20261016.md`
- `plans/PLAN_MOBILE-EXCELLENCE-PHASE22-LATEST-PRODUCT-ASSET-PARITY-20261016_2026-10-16.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE22-LATEST-PRODUCT-ASSET-PARITY-20261016/report.md`
- `missions/MOBILE-EXCELLENCE-PHASE22-LATEST-PRODUCT-ASSET-PARITY-20261016/input.json`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Sources de vérification en lecture seule

- `public/images/menu/`
- `config/menu_images.php`

Ces sources ne sont pas modifiées par ce lot.

## Allowlist de mappings canoniques

| Références produit | Fichier PNG mobile/source |
|---|---|
| Cayenne | `sandwich-cayenne.png` |
| Suprême | `sandwich-supreme.png` |
| Méga | `sandwich-mega.png` |
| Terminator | `sandwich-terminator.png` |
| Galette Normale, Galette Cayenne, choix Pain/Galette | `galette.png` |
| Tacos M, Tacos L, Tacos XL | `tacos-cayenne.png` |

Les neuf références produit correspondent à six fichiers uniques. Les cartes
de catégories utilisent respectivement `cat-sandwich-cayenne.png`,
`cat-galette.png` et `cat-tacos.png`. Une source absente, un mapping différent
ou une copie SHA divergente déclenche une ESCALATION ; aucune substitution ne
doit être inventée.

## Hors périmètre / frozen

Backend PHP/config, prix, migrations, commandes, `OrderService`,
`FrontendOrderService`, paiement/Mollie, SMS, fidélité, branch isolation,
dispatch, POS/caisse/KDS, borne runtime et packaging iOS.

## Critères d'acceptation

1. Les neuf images produit sandwich/galette/tacos et les trois cartes de
   catégorie utilisent les PNG du pack `public/images/menu/` et leurs copies
   mobiles sont byte-identiques.
2. Les héros produits et le choix `pain-galette` n'utilisent plus les WebP
   historiques pour ces références.
3. Les tests statiques et E2E vérifient les chemins PNG, la parité SHA-256, la
   navigation catalogue et l'absence de requêtes d'écriture.
4. Aucun nom, prix, flag, payload de commande ou invariant métier ne change.

## Stratégie de test

`playwright-critical-flow` avec validation statique catalogue, test E2E ciblé
du parcours accueil → menu → catégorie → produit, `git diff --check`, safety
check et post-execute guard.

## Invariants

- Prix backend SSOT ; aucune logique de prix frontend.
- `OrderStatus`, `branch_id`, dispatch et services de commande inchangés.
- Aucun fichier gelé ou migration modifié.
- Les assets sont synchronisés depuis les fichiers existants du site/borne ;
  aucune image générée ou substituée.
