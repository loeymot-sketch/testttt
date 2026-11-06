# G5 — Chaque écran du dashboard a un banc, ou n'existe plus

Défauts couverts : **V-14** (P2) · **N-02** (trouvé en propre) · **V-16** (P3)
Dépendances : aucune. Contient la seule porte propriétaire du lot backend.

---

## Le défaut, dit simplement

Huit composants du dashboard n'ont aucun banc direct. Mais la vérification a trouvé pire que
l'absence de test : **deux d'entre eux ne sont montés nulle part**.

`CustomerStatsComponent.vue` et `TopCustomersComponent.vue` n'apparaissent dans aucun autre
fichier de `resources/js` que le leur — pas d'import, pas d'enregistrement, pas de balise. Ce ne
sont pas des composants non testés : ce sont des composants que **personne n'ouvre**, alors que
leurs routes API (`total-customers`, `customer-states`, `top-customers`) existent, sont gardées,
et continuent d'être maintenues.

Écrire des tests pour du code mort serait le pire des deux mondes : du vert sur du néant.

## Ancres vérifiées (2026-09-03)

| Composant | Monté par `DashboardComponent.vue` | Tests directs |
|---|---|---|
| `ChannelStatsComponent.vue` | oui | **0** |
| `FeaturedItemsComponent.vue` | oui | **0** |
| `MostPopularItemsComponent.vue` | oui | **0** |
| `OrderStatisticsComponent.vue` | oui | **0** |
| `RealtimeReportComponent.vue` | oui | **0** |
| `SalesSummaryComponent.vue` | oui | **0** |
| `CustomerStatsComponent.vue` | **NON — code mort** | 0 |
| `TopCustomersComponent.vue` | **NON — code mort** | 0 |

Point de contexte utile : contrairement à ce qu'affirmait l'audit externe, **les 16 routes
dashboard ont toutes au moins une référence HTTP en test**. Les quatre qu'il déclarait manquantes
sont couvertes par URL concaténée dans `tests/Feature/Dashboard/DashboardRoutesAuthzMatrixTest.php:27-33`.
Le trou n'est pas côté routes, il est côté composants.

## Décision requise — PORTE PROPRIÉTAIRE P1

`CustomerStatsComponent` et `TopCustomersComponent` : trois issues, une seule à retenir.

- **(a) Remonter** — les afficher dans `DashboardComponent.vue`. À choisir si le propriétaire
  veut ces deux vues (segments clients, meilleurs clients) sur le tableau de bord.
- **(b) Déplacer** — les rattacher à une autre page (rapports clients).
- **(c) Supprimer** — retirer les composants **et** évaluer le sort des routes API associées.

**Ne rien décider n'est pas neutre** : ces routes restent gardées, testées et maintenues pour
un écran que personne ne voit.
Tant que la porte est ouverte, T5.1 s'exécute sur les **six** composants vivants seulement.

## Tâches

- **T5.1 — Couvrir les six composants vivants.**
  Un banc par composant, dans `tests/js/dashboard/` :
  `channelStatsComponent.spec.js` · `featuredItemsComponent.spec.js` ·
  `mostPopularItemsComponent.spec.js` · `orderStatisticsComponent.spec.js` ·
  `realtimeReportComponent.spec.js` · `salesSummaryComponent.spec.js` (tous **À CRÉER**).
  Chacun exerce : succès · **liste vide** · **403** · **500** · **timeout** · nettoyage du
  minuteur au démontage.
  Règle : l'état d'erreur doit être **discernable** de l'état vide. Aujourd'hui, plusieurs de ces
  composants font converger les deux vers « — » ou « Aucune donnée » : un échec réseau y est
  indiscernable d'une journée sans vente. C'est le vrai défaut, pas l'absence de test.

- **T5.2 — Contraste et accessibilité, mesurés.**
  Le correctif de contraste du Ticket Moyen (`RealtimeReportComponent.vue:14-16`, `text-white`)
  n'a jamais eu de mesure navigateur. Ajouter au banc `realtimeReportComponent.spec.js` une
  assertion sur les classes, **et** une mesure de contraste réelle dans la campagne visuelle.
  Ajouter `aria-hidden="true"` aux icônes décoratives recensées, des clés stables (pas `index`,
  pas l'objet lui-même), et `role="progressbar"` + `aria-valuenow/min/max` sur les barres de
  `ChannelStatsComponent`.

- **T5.3 — V-16, nommer les bords du contrat de dates.**
  `first_date=0&last_date=0` tombe dans le défaut à cause d'`empty()` ; `diffInDays() > 366`
  accepte 367 jours inclusifs. Aucune conséquence produit démontrée — c'est de la précision de
  contrat, pas un défaut utilisateur.
  Banc : ajouter à `tests/Feature/Dashboard/DashboardDateContractMatrixTest.php` les cas
  limite−1 / limite / limite+1 **en nommant la métrique** (jours inclusifs, pas `diffInDays`).

- **T5.4 — Exécuter la décision de la porte P1.**
  Selon (a), (b) ou (c). Si (c) : supprimer les composants, puis décider séparément du sort des
  routes — une route sans consommateur n'est pas forcément à supprimer (API publique, usage
  externe) ; le trancher explicitement, pas par omission.

## Acceptation

- Six bancs de composants VERTS, chacun couvrant succès/vide/403/500/timeout/nettoyage.
- Dans chacun : un cas prouvant que **l'erreur ne se déguise pas en état vide**.
- `tests/Feature/Dashboard/DashboardDateContractMatrixTest.php` — VERT, bords nommés.
- Non-régression VERTE : `tests/Feature/Dashboard/DashboardRoutesAuthzMatrixTest.php` ·
  `PopularItemsFailClosedTest.php` · `tests/js/dashboardDateEnvoyeeEnJourCivil.spec.js`.
- Porte P1 tranchée et consignée dans ce fichier, section Décision.

## Surface visuelle

`http://127.0.0.1:8766/admin/dashboard` — compte Admin, aux trois formats 390×844, 768×1024,
1366×768. Captures lues et analysées : aucun libellé brut, contraste du Ticket Moyen mesuré,
états vides distincts des états d'erreur.

## Condition de sortie

Deux rondes identiques, six bancs verts, porte P1 tranchée. Un composant mort qui reste mort est
acceptable **s'il est documenté comme tel** ; un composant mort silencieux ne l'est pas.
