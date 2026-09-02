# Supervision chef de projet — 2026-09-02

Branche `pos/category-first-caisse-2026-06-23` · HEAD `4383e95a0`.

## 0. Fait qui conditionne tout le reste

**L'arbre est partagé.** Une seconde session a travaillé dans ce dépôt pendant la mienne :
commits `8fc877521` et `179e27d99`, plus `fr.json`, `BackendMenuComponent.vue`,
`ProductComposerEditorComponent.vue` édités à 14:56, et deux fichiers non suivis créés à
02:43 / 02:52 (`tests/Playwright/dashboard-catalogue-captures-2026-09-02.spec.js`,
`plans/GOAL_DASHBOARD_PILOTABLE_2026-09-02.md`).

Conséquence : **aucun verdict de suite rendu sur cet arbre n'est stable.** Je n'ai touché
à rien de leur travail.

## 1. Ce que j'ai corrigé, avec la preuve

| Commit | Défaut | Preuve |
|---|---|---|
| `ef0e41d01` | Deux sentinelles qui ne mordaient plus | Défaut réintroduit → rouge ; retiré → vert |
| `de63f72f9` | `MenuProjectionService` servait 3 rayons de campagne | Test rouge avant / vert après, base isolée |
| `5f3b6a147` | `PosCategoryController` en servait encore un au caissier | Endpoint HTTP rouge/vert **+ caisse servie re-mesurée au navigateur** |
| `4383e95a0` | Journal `§2` périmé | — |

Détail du plus instructif : `Zone5PricingSsotConvergenceSentinelTest` portait
`preg_match('#^\s*[*/#]#', …)` — le `#` non échappé coupe le motif. Cette ligne ne
s'exécutait **que s'il y avait un candidat** : sentinelle verte tant qu'il n'y avait rien à
vérifier, et en erreur dès qu'il y avait quelque chose. Réparée, elle a immédiatement
dénoncé une affectation de `composition_snapshot` dans `KitchenBundledAddonCollapser`.
Vérifié non persistant (clone, aucun site de sauvegarde, unique consommateur = rendu
ticket) et prouvé par `CollapserNePersistePasLInstantaneTest`, dont j'ai vérifié la
morsure en sabotant la ligne 182. Exemption posée **à cliquet**, pas en silence.

## 2. Ce que je retire

J'ai annoncé « la catégorie Burgers entière est cassée : pas obligatoire `viande` à zéro
choix, l'aperçu est un mensonge commerçant ». **C'est faux.**

Je mesurais `ComposerProfileProjection` avec le profil de CATÉGORIE. La caisse reçoit un
profil **par produit** via `ItemResource` : Chicken Burger = profil 66 v4, viande 11 choix,
sauce 14 ; Suprême = profil 57 v4, viande 11, viande_2 10, sauce 14.

Mesuré au navigateur sur la caisse servie : le wizard affiche les viandes (Poulet mariné,
Viande Hachée, Cordon Bleu), et l'ajout au panier n'est refusé que sur
« 🥤 Choisissez votre boisson » et « ⚠️ Sélectionnez au moins une sauce » — comportement
correct. Témoin : Coca sort en `is-unavailable` avec `aria-label="Indisponible : …"`.

Reste vrai comme **fait de données** : les burgers n'ont aucune variation sur l'attribut
« Viande 1 », et le profil de catégorie P37 exige ce pas. Ça ne gêne pas la vente ; ça
concerne la gestion (cf. §4).

## 3. Bloqueur de déploiement — ouvert

À HEAD, `KioskWizardComponent.vue` vaut `f445b1a8…` ; la baseline commitée annonce
`fcbe3755…`. **14 fichiers gelés sur 15 sont sains.**

Cause : le commit `6a2264085` a régénéré la baseline **depuis l'arbre de travail**,
bénissant un correctif jamais commité — il dort dans l'index sous
`LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP_2026-08-25` (23 tests Vitest verts, émetteur et
récepteur tous deux présents, fonctionnalité complète).

La sentinelle lit l'arbre de travail : **verte ici, rouge sur un clone neuf ou en CI.**
Le lot est prêt et borné ; sa signature relève du propriétaire (§7).

## 4. Signalé, non corrigé (hors de ma voie)

- **Le Dashboard peut mentir sur les wizards de catégorie** — l'écran affiche « Publié »
  pour un profil de catégorie, mais les surfaces lisent les profils par produit. Chantier
  déjà ouvert par l'autre session (leur `GOAL_DASHBOARD_PILOTABLE`, F1).
- **Libellés sans accents** (« Apercu live », « Rafraichi apres modification ») —
  l'autre session édite déjà `fr.json`.
- **Sélecteur client pollué** — dizaines de « Guest User », « HIJACKED ByOrderToken »,
  « AdvTest » visibles par le commerçant.
- **En-tête caisse tronqué** (« Co…rapide » chevauche « À encaisser ») et **debugbar
  affichée**.
- **`APP_DEBUG=true"`** dans `.env` — guillemet parasite. Sans effet en local ; les
  garde-fous §8 sont fail-closed. Mais `config/idempotency.php` lit l'env brut là où ses
  voisins passent par `filter_var` : incohérence à surveiller.

## 5. Bancs

- PHPUnit Grok : **83/83, 263 assertions** — chiffre de Grok reproduit.
- Sentinelles : **369/369** après `ef0e41d01`. Avant : 1 erreur + 2 échecs, invisibles au
  filtre de Grok.
- `tests/Feature/Menu` : **166/166**.
- NF525 `fiscal:verify-chain --all` : **CHAIN OK**, 6 branches.
- Vitest complet **sous Node 22** : **3857 verts, 6 échecs**, tous tracés au travail en vol
  de l'autre session (`fr.json` non recompilé → 2 sentinelles de fraîcheur de bundle ;
  nouveau `ComposerPageLibraryModal` → 3 specs ; 1 instrument de capture non suivi).
- ⚠ Le `node` du PATH est en **v18** : toute spec qui charge `playwright.config.js`
  échoue alors pour une raison étrangère au produit.

## 6. Verdict

- **`block`** sur le déploiement — baseline gelée incohérente à HEAD, et arbre partagé en
  cours d'édition.
- **`continue`** sur le travail lui-même.
- Rien n'est poussé.
