# Audit UI — Dashboard et cockpit de contrôle

Date : 2026-09-02

## Actualisation finale — 2026-09-03

Le lot du 2 septembre améliore nettement l'honnêteté UI : outbox périmée explicitement, erreurs et actions annoncées, purge confirmée, dates françaises, audit NF525 présenté comme préfixe et non comme validation cryptographique. Les tests ciblés sont verts et le parcours Chromium dashboard/cockpit passe.

Les défauts UI/UX P1 encore observables sont néanmoins précis :

- les états `inFlight`, `staleClaimed` et `terminalFailures` sont stockés dans `OutboxOverviewComponent` mais jamais affichés ;
- la relance lit `retried` alors que le serveur renvoie `requeued`, donc son bilan quantifié disparaît ;
- la confirmation de purge annonce le nombre d'événements terminaux mais supprime des entrées `failed_jobs`, ensemble différent ;
- le cockpit dit que les interrupteurs ne vont pas dans le journal NF525 alors qu'ils écrivent désormais dans `audit_logs` ;
- le tiroir caisse affirme couvrir le service, mais n'affiche qu'une page de 100 et peut masquer les plus anciennes commandes ;
- huit composants statistiques restent sans test de composant direct ;
- le Playwright courant collecte des refus WebSocket/connexion sans assertion, et quatre sentinelles prouvent que les bundles distribués sont plus vieux que leurs sources.
- le parcours caisse sur le bundle servi échoue cinq fois de suite : badges, cartes et panneaux du nouveau tiroir ne sont pas dans le DOM. Le défaut de fraîcheur est donc visible par l'utilisateur, pas seulement théorique.

**UI_VERDICT: NEEDS_FIX** — l'interface ciblée est lisible et testée, mais elle ne peut pas encore servir de preuve opérationnelle complète ni être certifiée sur les sources courantes.

Référentiel relu : [Web Interface Guidelines](https://raw.githubusercontent.com/vercel-labs/web-interface-guidelines/main/command.md)

Mode : inspection statique du HEAD `47c001fbb` ; aucun test de certification lancé par cet audit sous safety gate rouge. Une campagne externe caisse ciblée a passé 11 scénarios, sans cockpit Admin.

## Verdict

**UI_VERDICT: NEEDS_FIX**

**ACCESSIBILITY_ASSURANCE: INCOMPLETE**

**BROWSER_PROOF: STALE**

## Findings prioritaires

### P1 — Le nouveau contrat outbox backend n'atteint pas l'écran

- `SyncOverviewController.php:420` renvoie `delivered_24h`; `OutboxOverviewComponent.vue:365` lit seulement `data.dispatched_24h`. Après le commit `179e27d99`, la carte conserve donc ses zéros initiaux même lorsque l'API compte des livraisons.
- Le backend expose désormais `terminal_failures`, `in_flight` et `stale_claimed`, mais le composant ne les déclare ni ne les rend. Les 2 149 claims orphelins mentionnés dans le commit restent donc invisibles pour l'opérateur que l'écran devait alerter.
- `tests/js/observabilityOutboxRoute.spec.js` valide seulement cinq anciennes sections, les URLs, le polling et le montage. Il n'injecte pas le nouveau payload et ne peut pas détecter cette rupture.
- Le plan demandait une transition sûre : alias `dispatched_24h` conservé une release, puis migration du composant en W4. Ni l'alias ni la migration ne sont présents sur le HEAD.

### P1 — Les erreurs importantes restent silencieuses ou ambiguës

- `resources/js/components/admin/dashboard/OverviewComponent.vue:58,85-107` — `loadError` est positionné par trois requêtes mais jamais rendu ; l'utilisateur ne voit ni cause, ni prochaine action, ni bouton réessayer.
- `resources/js/components/admin/dashboard/RealtimeReportComponent.vue:35-49,59-69` — 403/500/timeout deviennent seulement `—`, sans texte d'erreur, retry ni région live ; un échec reste indiscernable d'une valeur absente.
- `resources/js/components/admin/dashboard/AuditTrailComponent.vue:68-71` — promesse sans `catch`; l'état vide affirme « Aucun événement » même si l'API n'a pas répondu.
- `resources/js/components/admin/dashboard/AuditTrailComponent.vue:8-10,31-35` — le texte présente un préfixe stocké de huit caractères comme attestation d'intégrité, sans résultat de vérification de chaîne. C'est une assurance visuelle non démontrée.
- `resources/js/components/admin/observability/SystemHealthComponent.vue:142,234-275` — une seule chaîne `erreur` est partagée entre deux requêtes concurrentes ; le refresh santé la remet à `null` et peut effacer une erreur interrupteur. L'erreur n'a ni `role="alert"` ni `aria-live`.
- `resources/js/components/admin/dashboard/DashboardComponent.vue:250-267` — l'échec du PDF utilise `window.alert` au lieu d'un message inline annoncé et récupérable.
- `47c001fbb` corrige le jour EOD : le client n'envoie plus son jour local par défaut, le serveur choisit aujourd'hui Paris et fournit le nom de fichier. La preuve reste ciblée et ne compare pas le contenu au Z fiscal.
- `resources/js/languages/fr.json:1494` — « PDF Clôture du jour » suggère une clôture alors que l'action ne fait qu'une synthèse read-only et ne clôture aucun Z fiscal.

### P1 — Le cockpit peut donner une assurance opérationnelle incomplète

- Le lot restore-drill committé dans `28c2771a8` lit maintenant le dernier verdict, mais `SystemHealthComponent.vue:226-230` rend la carte verte si le fichier courant est frais et si n'importe quel ancien drill récent est vert. Il ne compare pas `dernier_fichier` avec `restauration.file`/SHA, alors que le texte affirme que « cette sauvegarde » a réellement été remontée. C'est une assurance visuelle plus forte que la preuve disponible.
- `RestoreDrillResult::MAX_AGE_HOURS` vaut 48 alors que le plan impose 26 h : une restauration vieille de 27–48 h reste visuellement verte.
- `resources/js/components/admin/observability/SystemHealthComponent.vue:224-231` — seul system-health est repollé ; les interrupteurs restent figés après leur lecture initiale, même si un autre opérateur les modifie.
- `resources/js/components/admin/observability/SystemHealthComponent.vue:106-112` — la bascule est journalisée côté serveur, pas dans l'audit métier/fiscal ; le contrôle ne fournit pas acteur/avant/après/corrélation durable.
- `resources/js/components/admin/observability/SystemHealthComponent.vue:242-262` — `window.confirm` évite une activation accidentelle, mais il n'existe pas de preuve UI de succès, d'auteur, d'heure ni de restauration.
- `resources/js/router/modules/observabilityRoutes.js:26-44` — le garde SPA est `settings`, tandis que les API sont `Admin|Tenant Admin`; l'autorité d'affichage et l'autorité serveur ne sont pas identiques et la navigation Tenant Admin n'est pas verrouillée.
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue:358-371` — le polling n'a aucun `catch`; une panne réseau conserve silencieusement l'ancien état et son vert, sans bannière périmée, retry ni live region.
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue:60,373-380` — le bouton retry est activé par tout pending ou `failed_job`, alors que le backend ne rejoue que les événements pending avec `last_error`; `requeued=0` n'est jamais affiché.
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue:67-76,382-391` — « Drain failed » supprime des preuves sans confirmation, portée visible, résumé des lignes touchées, succès/erreur annoncé ni possibilité d'annulation.
- `resources/js/components/admin/observability/OutboxOverviewComponent.vue:87-135` — seulement `up/down`; aucune représentation `unknown/stale`, alors que la source est heuristique et peut devenir indisponible.

### P1 — Le correctif de contraste n'a pas de preuve navigateur fraîche

- `resources/js/components/admin/dashboard/RealtimeReportComponent.vue:14-16` — le source force maintenant `text-white` sur les titres, corrigeant le contraste mesuré à 1,088:1 dans l'ancienne capture.
- Le source est désormais committé dans `78a878e23` et un rebuild `public/js/app.js`/manifest daté de 02:04 est présent. Le reporter Playwright autoritaire reste toutefois antérieur au changement et aucun test direct du composant ne mesure le contraste ; le correctif ne peut pas être considéré comme validé en navigateur tant qu'une recapture du même snapshot ne l'a pas prouvé.

## Accessibilité et robustesse

- `resources/js/components/admin/dashboard/OverviewComponent.vue:9,20,31` — icônes décoratives sans `aria-hidden="true"`.
- `resources/js/components/admin/dashboard/AuditTrailComponent.vue:5` — icône décorative exposée à l'arbre accessible.
- `resources/js/components/admin/dashboard/AuditTrailComponent.vue:24-38` — le `branch_id` renvoyé n'est pas affiché et le temps n'est que relatif ; un Admin global ne peut pas attribuer précisément l'événement à une branche et un instant.
- `resources/js/components/admin/dashboard/SalesSummaryComponent.vue:16,23,30` — icônes décoratives sans `aria-hidden`.
- `resources/js/components/admin/dashboard/CustomerStatsComponent.vue:15` — icône décorative sans `aria-hidden`; composant non monté par le shell courant.
- `resources/js/components/admin/dashboard/OrderStatisticsComponent.vue:19-123` — dix icônes décoratives sans `aria-hidden`.
- `resources/js/components/admin/dashboard/OrderSummaryComponent.vue:15` — icône décorative sans `aria-hidden`.
- `resources/js/components/admin/dashboard/ChannelStatsComponent.vue:7` — `index` utilisé comme clé ; les barres visuelles n'exposent pas `role="progressbar"`, `aria-valuemin/max/now`.
- `resources/js/components/admin/dashboard/FeaturedItemsComponent.vue:11-12` — objet comme clé ; image avec alt générique `product`, sans dimensions explicites ni chargement différé.
- `resources/js/components/admin/dashboard/MostPopularItemsComponent.vue:11-12` — même défaut de clé/image.
- `resources/js/components/admin/dashboard/TopCustomersComponent.vue:11-12` — même défaut de clé ; alt générique `avatar`; composant non monté.
- `resources/js/components/admin/observability/SystemHealthComponent.vue:32-40,125-137` — boutons sans style `focus-visible` explicite dans le composant ; à valider au clavier contre les styles globaux.
- `resources/js/components/admin/observability/SystemHealthComponent.vue:167-170` — locale `fr-FR` codée en dur, au lieu de la locale applicative via `Intl.DateTimeFormat`.
- `resources/js/components/admin/dashboard/LastZReportWidget.vue:104` — échec et « aucune donnée » convergent vers le même état, sans action de récupération.

## Erreurs silencieuses supplémentaires

- `resources/js/components/admin/dashboard/FeaturedItemsComponent.vue:44`
- `resources/js/components/admin/dashboard/MostPopularItemsComponent.vue:50`
- `resources/js/components/admin/dashboard/TopCustomersComponent.vue:49`
- `resources/js/components/admin/dashboard/SalesSummaryComponent.vue:132`
- `resources/js/components/admin/dashboard/CustomerStatsComponent.vue:129`
- `resources/js/components/admin/dashboard/OrderStatisticsComponent.vue:207,231`
- `resources/js/components/admin/dashboard/OrderSummaryComponent.vue:156`

Dans ces chemins, le `catch` ne fournit pas un état visible avec prochaine action. Il faut distinguer succès vide, chargement, erreur, permission refusée et données périmées.

## Conditions de validation UI

1. Tests unitaires directs des composants avec succès, vide, 401/403, 422, 500, timeout, retry et destruction des timers.
2. Annonces `aria-live`/`role=alert` pour erreurs et mises à jour opérationnelles ; focus visible sur chaque action.
3. Navigation clavier complète, ordre de tabulation et confirmation destructive vérifiés.
4. Tests aux viewports 390×844, 768×1024 et 1366×768.
5. Contraste mesuré après rebuild, notamment Ticket Moyen et états amber/red/green.
6. Snapshot source = bundle = serveur = reporter Playwright.
7. Matrice UI/API Admin, Tenant Admin, détenteur `settings` non-admin et utilisateur sans `settings`.
8. Pour l'outbox : perte réseau après état vert, données stale visibles, retry `0/N`, double clic/concurrence, confirmation du drain et annonce accessible du résultat.
9. Monter le composant avec un payload contenant `delivered_24h`, `terminal_failures`, `in_flight` et `stale_claimed`, puis vérifier les nombres et états rendus. Les caches ciblés rouges relevés pendant l'audit doivent être remplacés par un run ciblé puis complet vert sur un snapshot figé.
10. Monter la carte backup avec `dernier_fichier=B` et un drill vert portant `file=A`/SHA différent : la carte doit être rouge ou inconnue et ne jamais affirmer que B a été restauré.
11. Faire échouer Playwright sur les erreurs console/runtime inattendues. La capture catalogue du 2 septembre passe malgré des refus WebSocket répétés; une preuve cockpit ne peut pas ignorer précisément le transport temps réel qu'elle prétend surveiller.
