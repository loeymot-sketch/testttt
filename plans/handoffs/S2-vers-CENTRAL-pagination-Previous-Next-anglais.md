# Handoff S2 → CENTRAL / zone partagée §6 — pagination « Previous / Next » anglaise (2026-07-29)

## Le fait (PROUVÉ à l'écran, pas déduit)
Sur `/admin/historique` (et **les ~50 écrans admin qui utilisent `PaginationBox`**), les boutons
de navigation affichent **« Previous » / « Next »** en anglais. Mandat FR (ADR-007) violé.

Preuve : sonde DOM réelle sur `http://127.0.0.1:8010/admin/historique`, bundle à jour →
`{"hits":["Previous","Previous","Next","Next","Previous","Previous","Next","Next"]}`.

## ⚠️ Correction d'une erreur d'analyse de S2 — à lire avant de refaire le fix
Un premier diagnostic (RED cycle 1) attribuait ces libellés au paginator Laravel, donc à
`lang/fr/pagination.php` (qui n'était effectivement **jamais traduit** : `'« Previous'` /
`'Next »'`). S2 a corrigé ce fichier → « Précédent » / « Suivant », **et a eu tort de déclarer
l'écran corrigé sur la seule foi de `trans('pagination.previous')` en console**. La sonde DOM
ci-dessus, faite après rebuild, montre que **rien n'a changé à l'écran**.

Racine réelle : `laravel-vue-pagination` **n'utilise pas** `link.label` pour les flèches — le
composant `TailwindPagination` porte ses **propres libellés codés en dur** dans son dist
(`aria-label: "Previous"` + contenu par défaut du slot, `dist/laravel-vue-pagination.es.js:192-205`).
Seuls les numéros de page viennent des données.

Le correctif de `lang/fr/pagination.php` reste **juste et conservé** (il sert toute pagination
rendue côté serveur/Blade), mais il ne traite PAS les écrans admin Vue.

## Diff proposé (la lib expose les slots)
`resources/js/components/admin/components/pagination/PaginationBox.vue` (et le miroir
`PaginationSMBox.vue`) — remplir les slots `prev-nav` / `next-nav`, déjà prévus par la lib
(`_(e.$slots, "prev-nav", ...)`, même chose pour `next-nav`) :

```vue
<TailwindPagination :data="pagination" @pagination-change-page="page" :active-classes="activeClass" :limit="1">
    <template #prev-nav><span>&laquo; {{ $t('button.previous') }}</span></template>
    <template #next-nav><span>{{ $t('button.next') }} &raquo;</span></template>
</TailwindPagination>
```
(vérifier/ajouter les clés `button.previous` / `button.next` dans les 5 catalogues i18n).

## Pourquoi S2 ne l'a pas appliqué
`resources/js/components/admin/components/**` est une **zone partagée §6** (SYSTEM_MAP :
« shared UI widgets → coordinate, NOT a free lane edit ») : ces 2 composants sont importés
par ~50 écrans de toutes les voies. Un correctif d'une session parallèle sur ce fichier
entre en collision directe avec les autres leads. À faire par le lead CENTRAL, ou en
sérialisé hors vague parallèle.

## Leçon (vaut plus que le bug)
Un `trans()` vert en console **ne prouve pas** ce que l'utilisateur voit. La seule preuve
recevable pour un libellé est la lecture du DOM/capture **après rebuild** — c'est
exactement la règle « evidence avant affirmation » de la CONSTITUTION §6, et S2 s'est fait
prendre dessus une fois dans cette session.
