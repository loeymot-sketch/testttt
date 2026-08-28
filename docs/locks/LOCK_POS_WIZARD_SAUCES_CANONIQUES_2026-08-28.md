# LOCK — Wizard caisse : carte de sauces canonique, tuiles colorées, horaire, duplication

**ID :** `LOCK_POS_WIZARD_SAUCES_CANONIQUES_2026-08-28.md`
**Date :** 2026-08-28
**Statut :** **SIGNÉ — contreseing propriétaire obtenu le 2026-08-28 (§10)**
**Portée :** chirurgicale (3 fichiers gelés, 217 lignes ajoutées / 50 retirées)

## Fichiers gelés touchés (CLAUDE.md §7)

| Fichier | SHA-256 avant | SHA-256 après | Diff |
|---|---|---|---|
| `public/js/pos-wizard.js` | `24eaac96230b4f37fa26a24a2a71e2a05d6d81c9f7679a128f3b5835464560d3` | `2a5b27aa36eb049a8f56a558ff16f34370446ee109cb1257b8cfe764e6b34a04` | +127 / −26 |
| `public/css/pos-wizard.css` | `857e00680ea813b5a902dbf8365f77eb34400a179a66e8d0ecd6905527f98626` | `66195e477069a9142457a8c58dc7a8b6a8e8bfed896dce61d605253096814942` | +84 / −23 |
| `resources/views/admin-pos-v4.blade.php` | `47535c19c7c06ed2a863f03d3bee8335280838740fe30eb51e8044809d48c956` | `625e3222c8c810541e9e21d2b59f6dbed2f8a9e248692a68c079e357d212b9cc` | +6 / −1 |

**Baseline à mettre à jour dans LE MÊME COMMIT que le correctif :**
`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`.

## 1. Pourquoi l'override est nécessaire

Demande explicite du propriétaire (`/goal` du 2026-08-28) portant **nommément sur
le wizard de la caisse et sa page de personnalisation** : sauces manquantes,
ordre différent d'un produit à l'autre, tuiles « mal faites » à agrandir et à
colorer, horaire programmé à saisir plus vite, et duplication d'une ligne du
panier.

Le wizard caisse est gelé parce que son **design** est validé par le
propriétaire. Ici c'est le propriétaire lui-même qui demande à le changer : le
gel protège contre une dérive non voulue, pas contre une décision du
propriétaire.

**Une alternative non gelée a été cherchée et n'existe pas.** Le rendu des
tuiles sauce, la lecture de la liste de sauces et l'injection de configuration
vivent dans ces trois fichiers exactement. Tout ce qui pouvait sortir de la zone
gelée en est sorti — et c'est l'essentiel du travail :

- `config/pos_sauces.php` (nouveau) — liste, ordre, couleurs, alias ;
- `app/Support/Menu/SauceCatalog.php` (nouveau) — tri canonique ;
- `app/Console/Commands/SyncSauceCatalogCommand.php` (nouveau) — réparation données ;
- `app/Http/Resources/ItemResource.php` + `NormalItemResource.php` — tri appliqué ;
- `resources/js/components/admin/pos/PosComponent.vue` — horaire + duplication ;
- `resources/css/pos-v5.css` — bouton Dupliquer.

## 2. Le changement, fichier par fichier

### `public/js/pos-wizard.js` (+127 / −26)

1. **`SAUCE_STYLES` + `sauceStyleFor()` + `shadeHex()`** — lecture du catalogue
   servi par le serveur. **Aucune couleur n'est écrite en dur** : elles viennent
   toutes de `config/pos_sauces.php`. Repli neutre lisible si l'injection échoue.
2. **`renderSauceTile()`** — remplace la puce de 12 px par une tuile colorée.
   **Contrat de classes conservé à l'identique** (`.sauce-chip`, `data-type`,
   `data-id`, `.chip-free`, `.chip-paid`) : la mise à jour d'état et la
   délégation de clic s'y accrochent déjà, changer le markup sans ça aurait cassé
   la sélection **sans erreur visible**.
3. **`aria-pressed` synchronisé** dans `updateSinglePageState` — sans cette
   ligne, un lecteur d'écran annonce toutes les sauces comme non sélectionnées.
   *(Défaut introduit puis corrigé pendant l'implémentation, constaté en navigateur.)*
4. **`ALL_SAUCES` et `SAUCE_EMOJIS` alignés** sur les sauces réellement servies.
   Ce repli listait Cocktail, Burger, Biggy, Poivre, BBQ, Américaine — **six
   sauces inexistantes en base**. Dormant tant que la base répond, mais une panne
   de données aurait affiché en caisse des sauces que la cuisine ne sert pas
   (CLAUDE.md §3bis : ne jamais inventer de produit).

### `public/css/pos-wizard.css` (+84 / −23)

`.sauce-chips-grid` passe de `flex-wrap` à une grille ; `.sauce-chip` passe de
puce à tuile pleine largeur pilotée par variables inline
(`--sauce-bg/-fg/-border`). **Aucune hauteur maximale ni scroll interne** :
masquer une partie des sauces est précisément le défaut signalé. La sélection
garde la couleur de la sauce (anneau sombre + coche) au lieu de repeindre la
tuile en rouge — sinon toutes les sauces choisies deviennent identiques et la
couleur ne sert plus à rien.

### `resources/views/admin-pos-v4.blade.php` (+6 / −1)

Une seule addition : `sauceStyles: @json(SauceCatalog::frontPayload())` dans
`POS_WIZARD_CONFIG`, à l'identique de `master.blade.php` (le fichier porte déjà
la consigne « keep injection identical »). **Aucun autre changement.**

## 3. Ce qui n'est PAS touché

Aucun changement du calcul de prix, de la sérialisation des variations, du pont
wizard→Vue (`data-wizard-cart-display`, sync des radios par id), du chemin
d'ajout au panier, ni des étapes du wizard. `LOCK_POS_WIZARD_VIANDE_SUPPL_CHARGE_2026-07-01`
et `LOCK_POSWIZARD_KIOSKWIZARD_OWNER8` restent intacts. **Zones gelées hors
wizard : zéro ligne** (kiosk, PaymentComponent, PosV5TrancheRow, Fiscal,
BranchScope, Idempotency, PricingService, OrderStateMachine).

## 4. Critères d'acceptation (vérifiables, pas « ça a l'air bien »)

| Critère | Commande | Résultat obtenu |
|---|---|---|
| Sentinelle sauces | `./vendor/bin/phpunit --filter SauceCatalogCanonicalOrderSentinelTest` | **5/5 verts** |
| Suite ciblée | `./vendor/bin/phpunit --filter 'SauceCatalog…\|ItemResourceAllergens…\|ItemAttributeComposer…\|PosReceiptFiscal…\|OrderItemComposition…'` | **30/30, 183 assertions** |
| Front | `npx vitest run tests/js/posWizard*.spec.js tests/js/encaissementComposition.spec.js tests/js/itemListWizardButton.spec.js` | **13/13** |
| NF525 | `php artisan fiscal:verify-chain --all` | **CHAIN OK, 6 branches** |
| Dérive données | `php artisan foodking:sauces:sync --dry-run` | **0 · 0 · 0** |
| Convergence | 27 listes de sauces vendables | **0 divergente** |
| Sentinelle gelée | `./vendor/bin/phpunit --filter FrozenZone` | **ROUGE tant que la baseline n'est pas mise à jour (attendu)** |

**Morsure de la sentinelle vérifiée** : défaut réintroduit volontairement dans
`SauceCatalog::sortVariations` → 2 tests échouent ; défaut retiré → 5 verts. Un
banc vert au mauvais périmètre serait pire que pas de banc.

**Vérification en navigateur réel** (`http://127.0.0.1:8766`, arbre principal) :
14 tuiles colorées dans l'ordre canonique, « Sans sauce » en dernier ; cadre
Sauce et cadre Crudités mesurés à **468 px chacun (écart 0)** ; duplication
produisant deux lignes distinctes (Ketchup / Samouraï) à partir d'une seule saisie.

## 5. Rollback

**Le correctif** — `git revert <sha>` restaure les trois fichiers ET la baseline
SHA-256 dans le même mouvement (ils sont dans le même commit, par construction).
Aucun état applicatif à défaire : ces trois fichiers ne portent ni schéma ni
donnée.

**Les données**, en revanche, ne sont PAS annulées par un revert de code. La
commande `foodking:sauces:sync` a créé des lignes dans `item_variations` et
renommé des libellés alias. Pour revenir en arrière :

```sql
-- Lignes créées le 2026-08-28 par foodking:sauces:sync
SELECT id, item_id, item_attribute_id, name FROM item_variations
 WHERE item_attribute_id IN (5,8) AND DATE(created_at) = '2026-08-28';
```

⚠️ **Un rollback des données n'est PAS souhaitable** : il rétablirait les cinq
profils divergents, dont les 13 articles sans « Sans sauce » et les deux bols à
deux sauces. La commande est **additive et idempotente** — elle ne supprime ni ne
désactive jamais une ligne. Le risque d'un revert de code sans revert de données
est nul : la base contient simplement la carte complète, et l'ancien code
l'affiche dans son ordre d'insertion.

**Sauvegarde** : les listes d'avant sont reconstituables depuis le §1 du rapport
`reports/goal-wizard-caisse-2026-08-28/RAPPORT.md`, qui les consigne toutes les cinq.

## 6. Risque résiduel

- Le repli `ALL_SAUCES` et `config/pos_sauces.php` peuvent diverger si l'on
  n'édite que l'un des deux. Le repli porte un avertissement explicite en
  commentaire ; il n'est atteint que si la base ne renvoie AUCUNE sauce.
- Les couleurs sont un choix esthétique. Elles sont **toutes** dans un seul
  fichier de config : les changer ne demande plus de toucher la zone gelée.

## 7. Sous-agent

Aucun. Correctif appliqué directement par la session principale, à la demande du
propriétaire (mandat d'exécution autonome, CLAUDE.md §3quater).

## 8. Séquence de commit

1. **Ce LOCK**, seul (`docs(lock): …`).
2. Contreseing propriétaire en §10.
3. Le correctif + la baseline SHA-256 mise à jour, **dans un seul commit**, dont
   le message cite `LOCK_POS_WIZARD_SAUCES_CANONIQUES_2026-08-28.md` (c'est ce
   que le hook `pre-commit` cherche).

⛔ `--no-verify` interdit (CLAUDE.md §3quater). Le hook doit passer par la
citation du LOCK, pas par un contournement.

## 9. Traçabilité

- Rapport complet : `reports/goal-wizard-caisse-2026-08-28/RAPPORT.md`
- Entrée BRAIN §2 : ajoutée côté HEAD (⚠️ `PROJECT_BRAIN.md` est en conflit de
  fusion non résolu, hérité d'une session antérieure — non arbitré ici)

## 10. Human gate — contreseing propriétaire

> Le gel de `pos-wizard.js` / `.css` / `admin-pos-v4.blade.php` protège un design
> que **vous** avez validé. Le présent LOCK demande à le modifier — sur votre
> propre demande du 2026-08-28, mais la règle exige que vous le confirmiez une
> fois le changement écrit et mesuré, pas seulement demandé.

- [x] **Propriétaire — lu et approuvé.** Date : **2026-08-28**
      (contreseing donné en session, après présentation des SHA-256, du diff
      fichier par fichier et des critères d'acceptation mesurés)
- [x] Baseline SHA-256 mise à jour dans le commit du correctif
      (`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` — sentinelle
      `FrozenZoneSha256BaselineSentinelTest` repassée VERTE)
- [x] LOCK passé à **CLÔTURÉ** après livraison

**Statut actuel : CLÔTURÉ — correctif commité en local.**

⚠️ **NON POUSSÉ, sur décision du propriétaire.** La branche locale est 38 commits
derrière `origin/pos/category-first-caisse-2026-06-23` à cause d'une fusion
laissée inachevée par une session antérieure (marqueurs de conflit stagés dans
`PROJECT_BRAIN.md`, SHA `6aad72ca` = tête distante). Décision du 2026-08-28 :
**ne pas y toucher**. Le push, la fusion vers `main` et le déploiement serveur
(`scripts/deploy/deploy.sh`, en sudo sur le Hetzner) restent à faire.
