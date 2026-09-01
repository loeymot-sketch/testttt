# Revue UI — Dashboard et contrôle

Référentiel frais : https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md, rechargé le 2026-08-29. Format `fichier:ligne`, constats uniquement.

## `resources/js/components/admin/dashboard/DashboardComponent.vue`

`resources/js/components/admin/dashboard/DashboardComponent.vue:133` - permission des accès rapides fail-open quand la liste ou l'entrée manque ; masquer jusqu'à hydratation explicite.

`resources/js/components/admin/dashboard/DashboardComponent.vue:263` - erreur EOD via `window.alert`; rendre une erreur inline focusable avec `aria-live` et prochaine action.

## `resources/js/components/admin/dashboard/OverviewComponent.vue`

`resources/js/components/admin/dashboard/OverviewComponent.vue:9` - icône décorative sans `aria-hidden="true"`.

`resources/js/components/admin/dashboard/OverviewComponent.vue:20` - icône décorative sans `aria-hidden="true"`.

`resources/js/components/admin/dashboard/OverviewComponent.vue:31` - icône décorative sans `aria-hidden="true"`.

`resources/js/components/admin/dashboard/OverviewComponent.vue:70` - trois requêtes concurrentes partagent un booléen loader ; la première terminée masque les deux autres.

`resources/js/components/admin/dashboard/OverviewComponent.vue:75` - erreur réseau avalée ; KPI vide sans message, retry ni état live.

## `resources/js/components/admin/dashboard/FeaturedItemsComponent.vue`

`resources/js/components/admin/dashboard/FeaturedItemsComponent.vue:11` - objet utilisé comme clé Vue ; utiliser un identifiant stable.

`resources/js/components/admin/dashboard/FeaturedItemsComponent.vue:12` - `alt="product"` non descriptif ; dimensions explicites et `loading="lazy"` absents.

`resources/js/components/admin/dashboard/FeaturedItemsComponent.vue:44` - erreur avalée ; aucun état erreur/retry.

## `resources/js/components/admin/dashboard/MostPopularItemsComponent.vue`

`resources/js/components/admin/dashboard/MostPopularItemsComponent.vue:11` - objet utilisé comme clé Vue ; utiliser un identifiant stable.

`resources/js/components/admin/dashboard/MostPopularItemsComponent.vue:12` - `alt="product"` non descriptif ; dimensions explicites et `loading="lazy"` absents.

`resources/js/components/admin/dashboard/MostPopularItemsComponent.vue:50` - erreur avalée ; aucun état erreur/retry.

## `resources/js/components/admin/dashboard/TopCustomersComponent.vue`

`resources/js/components/admin/dashboard/TopCustomersComponent.vue:11` - objet utilisé comme clé Vue ; utiliser un identifiant stable.

`resources/js/components/admin/dashboard/TopCustomersComponent.vue:12` - `alt="avatar"` non descriptif ; dimensions intrinsèques explicites et `loading="lazy"` absents.

`resources/js/components/admin/dashboard/TopCustomersComponent.vue:49` - erreur avalée ; aucun état erreur/retry.

## `resources/js/components/admin/dashboard/OrderSummaryComponent.vue`

`resources/js/components/admin/dashboard/OrderSummaryComponent.vue:15` - icône calendrier décorative sans `aria-hidden="true"`.

## `resources/js/components/admin/dashboard/SalesSummaryComponent.vue`

`resources/js/components/admin/dashboard/SalesSummaryComponent.vue:16` - icône calendrier décorative sans `aria-hidden="true"`.

`resources/js/components/admin/dashboard/SalesSummaryComponent.vue:23` - icône décorative sans `aria-hidden="true"`.

`resources/js/components/admin/dashboard/SalesSummaryComponent.vue:30` - icône décorative sans `aria-hidden="true"`.

## `resources/js/components/admin/dashboard/AuditTrailComponent.vue`

`resources/js/components/admin/dashboard/AuditTrailComponent.vue:62` - polling sans état d'erreur, retry ou annonce `aria-live`; un rejet laisse le tableau figé sans explication.

## `resources/js/components/admin/dashboard/ChannelStatsComponent.vue`

`resources/js/components/admin/dashboard/ChannelStatsComponent.vue:7` - index de boucle utilisé comme clé ; utiliser l'identité du canal.

## `resources/js/components/admin/dashboard/LastZReportWidget.vue`

`resources/js/components/admin/dashboard/LastZReportWidget.vue:58` - date formatée avec locale implicite ; utiliser `Intl.DateTimeFormat` avec la locale active.

`resources/js/components/admin/dashboard/LastZReportWidget.vue:79` - permission du fetch fail-open quand permissions absentes/incomplètes.

## `resources/js/components/admin/observability/SystemHealthComponent.vue`

`resources/js/components/admin/observability/SystemHealthComponent.vue:86` - copie affirme une restauration à 5 h alors que l'API ne fournit aucun résultat de restauration.

`resources/js/components/admin/observability/SystemHealthComponent.vue:123` - bascule opérationnelle à effet immédiat, sans confirmation ni undo.

`resources/js/components/admin/observability/SystemHealthComponent.vue:140` - erreur asynchrone sans `role="alert"`/`aria-live`.

`resources/js/components/admin/observability/SystemHealthComponent.vue:167` - locale `fr-FR` codée en dur ; utiliser la locale i18n active via `Intl.DateTimeFormat`.

`resources/js/components/admin/observability/SystemHealthComponent.vue:225` - échec du chargement des interrupteurs transformé silencieusement en liste vide ; afficher erreur et retry.

## Points conformes notables

`resources/js/components/admin/dashboard/SlaAlertsComponent.vue:18` - bouton iconique correctement nommé, focus visible, état disabled et cible 44 px.

`resources/js/components/admin/dashboard/SlaAlertsComponent.vue:119` - résumé asynchrone annoncé avec `aria-live="polite"`.

`resources/js/components/admin/dashboard/SlaAlertsComponent.vue:309` - animations désactivées sous `prefers-reduced-motion`.

`resources/js/components/admin/dashboard/StockLowAlertsWidget.vue:25` - distingue explicitement panne de récupération et véritable état vide.
