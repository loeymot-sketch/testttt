# VAGUE D — ARGENT ET CANAUX ANNEXES · rapport de CAPTURE (round 1)

**Date** : 2026-08-25 · **Phase** : capture seule, **aucun correctif appliqué**
**Spec** : `tests/e2e/audit-supervisor-waveD.spec.js` (vert, 49,4 s, 1 worker)
**Artefacts** : `tests/e2e/__screenshots__/test-e2e-waveD/` — 9 états × 5 fichiers
(`.png`, `.dom.html`, `.console.json`, `.network.json`, **+ `.money.json`** propre à cette vague)
**Base** : `foodking_e2e` · **Serveur** : `http://127.0.0.1:8000` (déjà en ligne, non relancé)
**Compte** : `admin@lecayenne.fr` (rôle `Admin`, `branch_id = 1`)

## Garde d'environnement

Le spec vérifie, **après chaque `goto` et avant chaque capture**, que le HTML servi ne
contient ni `Warning: require`, ni `Fatal error`, ni `Failed to open stream`, ni `Uncaught Error`,
et qu'il fait au moins 5 000 octets (`assertEnvironmentHealthy`, spec lignes 48-64). Un HTTP 200
qui n'affiche qu'un avertissement PHP fait **échouer** le spec au lieu d'être capturé.

Les captures d'un premier passage (04:00, pendant la panne `vendor/`) ont été **intégralement
supprimées** ; tout ce qui suit provient du passage post-réparation (04:07-04:08), garde active,
9 états sur 9 franchis.

## Aucune mutation

`git status` : seul le spec est nouveau. Comptes en base **identiques avant/après**
(`cash_drawer_sessions` 59, `promo_flyers` 213, `uber_ticket_captures` 29). Aucun préfixe `AUDD-`
semé — la vague n'a créé aucune donnée, donc rien à nettoyer. Les `network.json` ne contiennent
**aucune requête mutante** (POST/PUT/PATCH/DELETE) : la vague est strictement en lecture.

---

# 1. FORMATS MONÉTAIRES OBSERVÉS

**Verdict : 38 chaînes euro distinctes relevées, 0 violation.**

Le collecteur `collectMoney()` parcourt tous les nœuds de texte visibles et enregistre le
**point de code exact** du caractère qui précède `€` — seul moyen de distinguer une espace
ordinaire (U+0020, fautive) d'une insécable (U+00A0, correcte), invisibles à l'œil.

| Chaîne exacte (séparateurs explicités) | Séparateur avant € | Où |
|---|---|---|
| `15,60[NBSP]€` | **U+00A0** | `cash-overview-summary`, `cash-overview-source-borne` |
| `247,70[NBSP]€` | **U+00A0** | `cash-overview-summary` (grand total, filtre 23→24/08) |
| `0,00[NBSP]€` | **U+00A0** | `cash-overview-source-caisse/-livreur`, cartes flyer |
| `13,10[NBSP]€` / `2,50[NBSP]€` | **U+00A0** | lignes `cash-overview-row-<id>` |
| `17[SP]·[SP]222,70[NBSP]€` | **U+00A0** | `cash-overview-mode-card` |
| `10[SP]·[SP]25,00[NBSP]€` | **U+00A0** | `cash-overview-mode-cash` |
| `50,00[NBSP]€` / `8,50[NBSP]€` / `58,50[NBSP]€` | **U+00A0** | bandeau `cash-overview-reconciliation-*` |
| `600,00[NBSP]€` `883,80[NBSP]€` `147,30[NBSP]€` `47,30[NBSP]€` | **U+00A0** | rapport caisses, samedi 15 août |
| `1[NNBSP]400,00[NBSP]€` · `1[NNBSP]000,00[NBSP]€` | **U+00A0** | rapport caisses, vendredi 14 août |
| `-40,00[NBSP]€` · `-135,90[NBSP]€` | **U+00A0** | colonne « Écart » (déficit) |
| `789,26[NBSP]€` `389,46[NBSP]€` `109,40[NBSP]€` `59,40[NBSP]€` `0,50[NBSP]€` `2,00[NBSP]€` | **U+00A0** | rapport caisses, juin |
| `dont[SP]offert[SP]:[SP]0,00[NBSP]€` | **U+00A0** | carte « Chiffre ramene », ticket promo |

* `[NBSP]` = U+00A0 (insécable) · `[NNBSP]` = U+202F (insécable étroite, séparateur de milliers) · `[SP]` = U+0020

**Aucune occurrence** de `19.40€`, `19.4`, `0.00`, `€19.40`, ni de `EUR` littéral, sur aucune des
5 surfaces. Les trois renderers en jeu convergent :

* `formatPrice()` — `resources/js/helpers/formatPrice.js` (Intl `fr-FR`/EUR, **pinné**), utilisé par le rapport caisses ;
* `CashOverviewComponent.formatMoney()` — `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:563` (Intl sur `$i18n.locale`, verrouillée à `fr` sur `/admin/*` par `isAdminPath()` dans `resources/js/i18n.js:54`) ;
* `PromoFlyerComponent.money()` — `resources/js/components/admin/promo/PromoFlyerComponent.vue:329` (`toLocaleString("fr-FR")`, hardcodé).

**Réserve honnête** : `CashOverviewComponent.formatMoney` suit `$i18n.locale`, pas une locale
figée. Le FR n'est garanti que par le verrou `isAdminPath()`. Si ce verrou saute un jour, ce
sont ces écrans-là qui basculeraient en `€247.70`. Le rapport caisses et le ticket promo, eux,
sont immunisés (`fr-FR` en dur).

**Dates/heures** : aucun ISO brut à l'écran. `14/08/2026 13:54` (flyer), `samedi 15 août 2026`
(rapport caisses), `06:25` (heures). Voir toutefois D-2, D-5 et D-7 : plusieurs heures sont
affichées **sans leur date**, ce qui est le vrai défaut de cette vague.

---

# 2. ÉTAT PAR ÉTAT

## État 1 — `/admin/cash-overview` au chargement
**Fichier** : `01-cash-overview-load.png` (+ quartet + `.money.json`)
**Attendu** : période du jour hydratée, cartes de synthèse cohérentes, formats FR.
**Observé** : `GET /api/admin/cash-overview?from=2026-08-25&to=2026-08-25` → **200**.
Grand total **15,60 €** / 2 tx = Borne 15,60 € / 2 tx ; lignes 13,10 € + 2,50 € = **15,60 €** ✔.
Répartition par mode `Carte 1 · 13,10 €` + `Espèce 1 · 2,50 €` = **15,60 €** ✔.
**Défauts** : D-1 (dates US), D-2 (bandeau réconciliation périmé et faussement daté), D-9 (404 `english.png`).

## État 2 — `/admin/cash-overview`, filtre de période appliqué
**Fichier** : `02-cash-overview-filtre-periode.png`
**Attendu** : la page entière suit le filtre ; Σ lignes = total affiché.
**Observé** : filtre `2026-08-23 → 2026-08-24`, 27 lignes, **200**.

| Contrôle | Affiché | Calculé depuis le DOM | Δ |
|---|---|---|---|
| Grand total vs Σ des 27 lignes | `247,70 €` | `247,70 €` | **0,00** ✔ |
| Grand total vs Σ des 3 cartes source | `247,70 €` | `247,70 €` (0 + 247,70 + 0) | **0,00** ✔ |
| Grand total vs Σ répartition par mode | `247,70 €` | `222,70 + 25,00 = 247,70 €` | **0,00** ✔ |
| Nombre de tx vs somme des compteurs mode | 27 tx | 17 + 10 = 27 | **0** ✔ |

**L'arithmétique de cet écran est juste.** Les défauts sont ailleurs : D-2, D-3, D-1.

## État 3 — `/admin/cash-overview` en état VIDE
**Fichier** : `03-cash-overview-vide.png`
**Attendu** : illustration + texte ≥ 20 car. + action primaire.
**Observé** : filtre `2026-01-01 → 2026-01-02`, **200**, 0 ligne.
Bloc `cash-overview-empty` présent, avec illustration SVG (`cash-overview-empty-illustration`),
copie « Aucune transaction trouvée pour les filtres actuels. Modifie la période ou réinitialise
les filtres. » (98 car.) et CTA primaire « Réinitialiser Les Filtres ».
**→ L'état vide de cet écran est le meilleur de la vague : il coche les trois critères.**
Défauts résiduels : D-2 (le bandeau réconciliation affiche toujours 8,50 € sur une période
sans un centime — c'est ici que la preuve est la plus nette), D-8 (tutoiement + « Les » capitalisé).

## État 4 — `/admin/cash-sessions-report` au chargement
**Fichier** : `04-cash-sessions-report-load.png`
**Attendu** : totaux d'en-tête = somme des lignes du jour.
**Observé** : **200**, 11 groupes-jours, 50 sessions (page 1/2).
Pour chacun des 11 jours, **Σ des cellules « Fond de caisse » = « Total ouverture » de l'en-tête**
et **Σ des cellules « Fonds final » = « Total clôture »** (vérifié par parsing du DOM, script
`/tmp/waveD_parse_sessions.py`). L'addition est exacte. Ce qu'elle additionne ne l'est pas : D-4.
Défauts : D-1, D-4, D-6, D-10.

## État 5 — `/admin/uber-photo` au chargement (tablette 1024×1366)
**Fichier** : `05-uber-photo-load.png`
**Attendu** : écran de capture prêt, cibles tactiles larges.
**Observé** : `GET /api/admin/uber/photo/recent?limit=20` → **200**. Trois boutons ~56 px
(« 📷 Photographier le ticket », « Lire le ticket » désactivé tant qu'aucune photo, « Recommencer »),
consigne « Ticket trop long ? … » présente. Aucun montant à l'écran. Défauts : D-5, D-7, D-8.

## État 6 — `/admin/uber-photo`, liste des captures récentes
**Fichier** : `06-uber-photo-captures-recentes.png`
**Attendu** : historique lisible, détail dépliable.
**Observé** : bloc `uber-photo-history` présent, **20 lignes**, dépliage de la 1re ligne OK →
« Aucune ligne lisible sur cette capture. » + « Jamais envoyée en cuisine — rien à réimprimer. »
(détail vide honnête, il dit pourquoi il n'y a pas de bouton). Défauts : D-5, D-7, D-11.

## État 7 — `/admin/promo-flyer` au chargement
**Fichiers** : `07-promo-flyer-load.png`, `07b-promo-flyer-historique.png`
**Attendu** : formulaire d'émission + statistiques + historique.
**Observé** : **200**. **Le formulaire d'émission est absent** et remplacé par « Votre compte n'a
pas encore accès à l'impression du ticket promo. » — voir **D-12**. Bandeau ambre honnête sur
`POS_COUPON_CODES_ENABLED=false`. Cartes : `Tickets imprimes 0` / `Codes utilises 0` /
`Taux de retour —` / `Chiffre ramene 0,00 € · dont offert : 0,00 €`.
Table : **100 lignes**, toutes `Échec / Code annule avant impression / annule`, dates `14/08/2026 13:54`.
Défauts : D-12, D-13, D-14, D-15, D-8.

> **Correction d'une mesure intermédiaire** : mon premier relevé annonçait « 111 lignes ».
> C'était **faux** — `page.locator('table tbody tr')` comptait aussi les 5 tables de la
> Laravel Debugbar présente en pied de page. Le comptage exact, par parsing de la seule
> `.table-responsive > table`, donne **100 lignes de données + 1 ligne d'en-tête**.

## État 8 — `/admin/promo-flyer/settings`
**Fichier** : `08-promo-flyer-settings.png`
**Attendu** : réglages éditables + aperçu 48 colonnes.
**Observé** : **200**. Titre `LE CAYENNE`, remise `10`, validité `30`, site `www.lecayenne.fr`,
QR `https://www.lecayenne.fr/`. Aperçu `<pre>` rendu à 48 colonnes, cohérent avec les champs.
**Aucun montant sur cet écran** (la remise est un pourcentage). Défauts : D-16, D-8, D-12 (corollaire).

---

# 3. DÉFAUTS

## D-2 — P0 · Le bandeau « Réconciliation caisse » affiche un tiroir vieux de 50 jours, étiqueté « aujourd'hui »
**Surface** : `/admin/cash-overview` · **Preuve** : états 1, 2 **et 3** (identique dans les trois).

Le bandeau affiche invariablement :
`Caisse ouverte à: 20:56` · `Fond de caisse: 50,00 €` · `Espèces encaissées aujourd'hui: 8,50 €` · `Espèces attendues au tiroir: 58,50 €`

En base (`cash_drawer_sessions`), la session retenue est la **#38, ouverte le 2026-07-06 à 20:56:58**,
et son unique mouvement de caisse est de **8,50 € daté du 2026-07-11 14:29:24**
(`cash_movements` id 418). La page, elle, est datée du **2026-08-25**.

Trois mensonges superposés :
1. **« aujourd'hui »** — l'argent a été encaissé il y a **45 jours**. Libellé `label.cash_collected_today`.
2. **« 20:56 » sans date** — `formatTime()` ne rend que `HH:mm`
   (`CashOverviewComponent.vue:580-588`), donc un tiroir ouvert il y a 50 jours se lit comme
   ouvert ce soir.
3. **Insensible au filtre** — état 3, période `01/01/2026 → 02/01/2026`, **zéro transaction**,
   grand total `0,00 €` : le bandeau affiche toujours `8,50 €` encaissés et `58,50 €` attendus
   au tiroir. Une période sans un centime affiche 8,50 € d'espèces.

Et sur le même écran, en état 2 (23→24 août), le bandeau annonce `8,50 €` d'espèces pendant que
la répartition par mode de la **même page** annonce `Espèce 10 · 25,00 €`. Deux chiffres
contradictoires côte à côte, sur la surface dont le mandat est précisément « détecter les écarts ».

**Cause, lue dans le code** : `CashOverviewController::resolveOpenCashSession()` (lignes 397-422)
se termine par `orderByDesc('opened_at')->first()` — **sans aucune borne de date**. Il existe
**11 sessions `open` simultanées** en base (la plus ancienne du 2026-06-12). Le contrôleur en
choisit une, la plus récemment ouverte, quelle que soit son ancienneté.

Aggravant : la transaction espèce **de ce jour** (2,50 €, 2026-08-25 03:55:09) a été rattachée à
la session **#36**, pas à la #38 affichée. Le tiroir montré n'est pas celui qui encaisse.

> Nuance à ne pas perdre : l'invariance au filtre est **délibérée** et documentée
> (commentaire « Fix C-014 », lignes 218-226) — filtrer `source=borne` ne doit pas amputer le
> tiroir physique. Le défaut n'est donc **pas** cette invariance : c'est que le tiroir choisi
> n'est borné par aucune date et que le libellé dit « aujourd'hui ».

## D-4 — P1 · « Total clôture » compte une caisse encore ouverte comme 0,00 €
**Surface** : `/admin/cash-sessions-report` · **Preuve** : `04-cash-sessions-report-load.png` + DOM.

* `lundi 6 juillet 2026 — Sessions: 3 · Total ouverture: 200,00 € · **Total clôture: 0,00 €**`
  → les 3 sessions sont `Ouverte`, leurs cellules « Fonds final » affichent honnêtement `—`.
* `samedi 13 juin 2026 — Sessions: 4 · Total ouverture: 200,00 € · **Total clôture: 0,00 €**` (4 × `Ouverte`).
* `dimanche 14 juin 2026 — Total ouverture: 120,00 € · Total clôture: 10,00 €` → 1 session
  réconciliée à 10,00 € + 1 session ouverte comptée 0.

`Total clôture: 0,00 €` est indiscernable de « le tiroir a fermé vide ». Les cellules disent `—`,
l'agrégat convertit silencieusement ce `—` en zéro.

**Cause** : `CashSessionReportListComponent.vue`, `groupedByDay()` :
`bucket.totalClosing += Number(s.closing_amount || 0);` — `null || 0` → `0`.

## D-3 — P1 · Aucune date dans la table sur une période multi-jours
**Surface** : `/admin/cash-overview` · **Preuve** : état 2, colonne « Heure ».

Filtre `2026-08-23 → 2026-08-24`, 27 lignes, colonne « Heure » = `06:25, 06:24, …, 23:37, 23:32, …`.
Aucune colonne date, aucun séparateur de jour. Impossible de savoir si `23:37` est le 23 ou le 24 août.
Sur un écran de rapprochement d'espèces, rattacher une ligne au mauvais jour, c'est rater l'écart.
Le composant n'a que `formatTime()` (`CashOverviewComponent.vue:580`), aucun `formatDate`.

## D-5 — P1 · « Commandes du service » liste des tickets vieux de 13 jours, sans date
**Surface** : `/admin/uber-photo` · **Preuve** : états 5 et 6.

L'en-tête dit « Commandes du service **20** » ; les 20 lignes portent `22:37`, `22:36`, …, `19:28`.
En base, **les 20 captures sont datées du 2026-08-12** (`uber_ticket_captures`, ids 10→29) ;
la page est du **2026-08-25**. L'opérateur lit 13 jours d'archives comme le service en cours.

**Cause** : `UberPhotoCaptureController::recent()` (lignes 279-288) = `orderByDesc('id')->limit(20)`,
**aucun filtre de date** ; côté vue, `heure(iso)` (`UberPhotoCaptureComponent.vue:543-548`) ne
formate que `HH:mm`. Même famille que D-2 : une heure nue présentée comme « maintenant ».

## D-12 — P1 · L'admin peut ouvrir `/admin/promo-flyer` mais pas imprimer
**Surface** : `/admin/promo-flyer` · **Preuve** : `07-promo-flyer-load.png`.

À la place du formulaire : « Votre compte n'a pas encore accès à l'impression du ticket promo.
Demandez à un responsable de vous accorder cet accès. »

**Je n'ai pas contourné.** Vérification en base :

```
permissions : pos-flyer-print  id=88 guard=sanctum · id=89 guard=web   (nom DUPLIQUÉ sur 2 guards)
role_has_permissions : Branch Manager + POS Operator (guard sanctum), Branch Manager (guard web)
users : admin@lecayenne.fr → rôle « Admin », branch_id=1
```

Le rôle **`Admin` ne porte pas `pos-flyer-print`**. La route SPA, elle, est gardée par
`permissionUrl: "pos-orders"` (`resources/js/router/modules/promoFlyerRoutes.js:19`) — l'admin
atteint donc une page qu'il ne peut pas utiliser. Le commentaire du contrôleur affirme
« `pos-flyer-print` est **maintenant accordé** au rôle Caissier/Branch Manager » : c'est vrai pour
ces deux rôles, et faux pour l'Admin, qui n'y figure pas.
Corollaire : `/admin/promo-flyer/settings` est en revanche **pleinement éditable** par ce même
compte — il peut régler un ticket qu'il ne peut pas émettre.
À arbitrer par le propriétaire : gap de seeder, ou choix assumé ?

## D-14 — P1 · L'historique des tickets promo est tronqué à 100 sans le dire
**Surface** : `/admin/promo-flyer` · **Preuve** : `07b-promo-flyer-historique.png` + base.

La table affiche **100 lignes**. `promo_flyers` en contient **213**. `PromoFlyerController::index()`
(ligne 208) : `->limit(100)`. **Aucune pagination, aucun avis de troncature.** 113 tickets sont
invisibles sans le moindre signal.

Comparaison interne accablante : `/admin/cash-overview`, elle, **a** un avis de plafond
(`data-testid="cash-overview-cap-notice"`, `label.cash_overview_capped_notice`) et
`/admin/cash-sessions-report` **a** une pagination (« 1 / 2 » visible en pied de table).
Le ticket promo n'a ni l'un ni l'autre.

## D-13 — P2 · Quatre libellés de statistiques en français sans accents
**Surface** : `/admin/promo-flyer` · **Preuve** : `07-promo-flyer-load.png`, cartes en capitales.

`resources/js/languages/fr.json` :

```
1538: "flyer_stat_printed": "Tickets imprimes"    → imprimés
1539: "flyer_stat_used":    "Codes utilises"      → utilisés
1541: "flyer_stat_revenue": "Chiffre ramene"      → ramené
1544: "flyer_revoked":      "annule"              → annulé
```

Plus, côté serveur, `app/Services/Promo/PromoFlyerService.php:494` :
`'last_error' => 'Code annule avant impression'` — affiché tel quel, 100 fois, dans la colonne Statut.

Ce sont des libellés d'**écran**, pas de ticket thermique : rien n'impose d'y retirer les accents.

## D-16 — P2 · Les textes par défaut du ticket promo sont sans accents, dans un champ éditable
**Surface** : `/admin/promo-flyer/settings` · **Preuve** : `08-promo-flyer-settings.png`.

Champs « Message principal » et « Nos points forts » :
« c'est le **meme** restaurant » · « **Meme** cuisine, **meme** equipe » ·
« Des points **fidelite a** chaque commande » · « Paiement en ligne **securise** ».

Source : `app/Services/Promo/PromoFlyerService.php:47` et `:76-78` (constantes par défaut).

**Nuance importante, à confirmer par le propriétaire plutôt qu'à corriger** : la piste
« contrainte imprimante » ne tient pas telle quelle — la couche ESC/POS translittère déjà
(`app/Services/Hardware/EscPosCommandBuilder.php:329`,
`iconv('UTF-8', $encoding.'//TRANSLIT//IGNORE', $text)`). L'absence d'accents dans la **valeur
stockée** n'est donc pas une nécessité technique du chemin d'impression. Si c'est un choix
délibéré, le formulaire devrait le dire ; en l'état l'exploitant lit du français fautif et,
s'il le « corrige », personne ne l'avertit de ce qui sortira du papier.

## D-1 — P2 · Champs date au format américain sur un admin verrouillé FR
**Surfaces** : `/admin/cash-overview`, `/admin/cash-sessions-report`
**Preuve** : état 1 → `08/25/2026` ; état 4 → placeholder `mm/dd/yyyy` ; libellés « DU » / « AU » en français.

`<input type="date">` suit la locale du **navigateur**, pas celle de la page (`<html lang="fr">`).
Le défaut est donc **conditionné à l'environnement** : sur un poste Chrome configuré en FR, ces
champs afficheraient `25/08/2026`. Je le signale malgré tout parce que tout le reste de l'admin
est verrouillé FR **en dur** (`isAdminPath()` dans `i18n.js`) et que ces deux champs sont les
seuls à retomber sur le navigateur — sur le poste de la caisse, personne ne garantit sa locale.

## D-15 — P2 · Colonnes « Code » et « Statut » qui se touchent
**Surface** : `/admin/promo-flyer` · **Preuve** : `07b-promo-flyer-historique.png`, très lisible.

`CAPE2E381786-68AH` bute directement contre `Échec` : zéro gouttière entre les deux colonnes sur
les 100 lignes. En dessous, `Code annule avant impression` déborde sous la colonne « Commande ».
Le code promo est ce que le caissier dicte au client au téléphone quand le ticket ne sort pas —
c'est la chaîne qu'il ne faut surtout pas rendre ambiguë.

## D-6 — P2 · Écart absent sur une caisse fermée mais non réconciliée
**Surface** : `/admin/cash-sessions-report` · **Preuve** : état 4, samedi 15 août.

Six sessions identiques (100,00 € → 147,30 €). Les trois `Réconciliée` affichent `Écart 47,30 €` ;
les trois `Fermée` affichent `—`. Vérifié en base : `variance` est `NULL` pour les trois
sessions `closed` et `47.30` pour les trois `reconciled` — **l'UI rend fidèlement ce qui est
stocké**, ce n'est pas un bug d'affichage. C'est un manque de domaine : l'écart n'est matérialisé
qu'à la réconciliation, or c'est exactement le chiffre que le mandat propriétaire réclame.
Le bouton « Réconcilier » sur ces lignes offre le chemin de reprise (correctif du 2026-08-15) ;
tant qu'il n'est pas cliqué, la journée n'a pas d'écart.

## D-10 — P2 (latent, **non observé**) · Groupement par jour calculé sur la page courante
**Surface** : `/admin/cash-sessions-report`.

`groupedByDay()` regroupe `this.sessions`, c'est-à-dire **la page courante** (50 sur 59, pagination
« 1 / 2 » affichée). Un jour à cheval sur la frontière afficherait un « Total ouverture / clôture /
Transactions » **partiel** présenté comme le total du jour.
**Honnêteté** : ce cas ne s'est **pas** produit ici — la coupure tombe entre le 8 juin (1 seule
session, complète) et le 7 juin. Risque structurel identifié par lecture de code, non prouvé par
la capture. À ne pas compter comme défaut observé.

## D-11 — P2 (latent, **non observé**) · Aucun état vide sur l'historique Uber
`UberPhotoCaptureComponent.vue:186` : `v-if="recentes.length"`. Sans capture, le bloc entier
disparaît — ni texte, ni icône, ni action. Non observable ici (29 captures en base).

## D-7 — P2 · Le montant du ticket Uber est capturé et jamais montré
**Surface** : `/admin/uber-photo`.

`UberPhotoCaptureController::present()` renvoie `'total' => $capture->total` ; en base, 8 des
20 captures récentes portent `total = 27.40`. Aucune vue ne l'affiche : `grep` du composant ne
trouve `total` que dans le texte d'alerte « aucun montant total n'a été lu »
(`UberPhotoCaptureComponent.vue:124`). Le montant sert donc uniquement à détecter le ticket coupé.
Sur la surface « argent et canaux annexes », le seul montant qui transite par cet écran est
invisible — l'opérateur ne peut pas recouper la photo avec ce que la plateforme a facturé.
(Réserve : la vocation de l'écran est l'envoi en cuisine, pas l'encaissement. À arbitrer.)

## D-8 — P3 · Coquille de casse et de ton (shell partagé)
La classe `capitalize` du gabarit admin met une majuscule à **chaque mot** français :
« Commande Uber — Photo **Du** Ticket », « Réglages **Du** Ticket Promo », « Réinitialiser **Les**
Filtres », « Tableau **De** Bord », « Attribut **D'articles** ». Typographie anglaise appliquée au
français. Cross-surface, hors périmètre exclusif de la vague D.
L'état vide de `/admin/cash-overview` tutoie (« Modifie la période ») alors que le reste de
l'admin vouvoie ou reste neutre.

## D-9 — P3 · 404 `english.png` sur les 5 surfaces
`GET /storage/1/english.png` → **404**, 1 à 2 fois par page, sur les 5 surfaces (drapeau du
sélecteur de langue du bandeau). Seuls 4xx/5xx du run. Aucun appel API en erreur : les 5
endpoints métier répondent **200**. Cross-surface, shell partagé.

---

# 4. CE QUI EST SAIN

* **Format monétaire : 38 chaînes, 0 violation** — virgule décimale, U+00A0 avant `€`, U+202F comme séparateur de milliers. Le critère principal de la vague est tenu.
* **Arithmétique de `/admin/cash-overview` : exacte** — total = Σ lignes = Σ cartes source = Σ répartition mode, à 0,00 € près, sur 27 lignes.
* **Arithmétique de `/admin/cash-sessions-report` : exacte** — sur les 11 groupes-jours, l'en-tête égale la somme des cellules affichées (le défaut D-4 porte sur *ce qui* est sommé, pas sur *comment*).
* **État vide de `/admin/cash-overview`** : illustration + copie + action primaire, les trois critères cochés.
* **Détail vide de l'historique Uber** : dit pourquoi il n'y a rien et pourquoi il n'y a pas de bouton.
* **Bandeau `POS_COUPON_CODES_ENABLED=false`** : avertissement franc, il nomme la variable et la conséquence pour le client.
* **Zéro erreur console applicative** — les seules entrées `error` sont les 404 `english.png` et le `ERR_CONNECTION_REFUSED` du websocket Pusher (allowlisté).
* **Zéro 4xx/5xx d'API**, zéro requête > 2 000 ms, zéro `pageerror`.

---

# 5. RÉCAPITULATIF

| # | Sévérité | Surface | Défaut |
|---|---|---|---|
| D-2 | **P0** | cash-overview | Tiroir vieux de 50 jours affiché comme « aujourd'hui », insensible au filtre |
| D-3 | **P1** | cash-overview | Aucune date en table sur période multi-jours |
| D-4 | **P1** | cash-sessions-report | « Total clôture » compte une caisse ouverte comme 0,00 € |
| D-5 | **P1** | uber-photo | « Commandes du service » = archives de 13 jours, sans date |
| D-12 | **P1** | promo-flyer | L'admin ouvre l'écran mais ne peut pas imprimer (`pos-flyer-print` absent du rôle Admin) |
| D-14 | **P1** | promo-flyer | Historique tronqué à 100/213 sans pagination ni avis |
| D-1 | P2 | cash-overview, cash-sessions-report | Champs date en `mm/dd/yyyy` (dépend du navigateur) |
| D-6 | P2 | cash-sessions-report | Pas d'écart sur caisse fermée non réconciliée (fidèle à la base) |
| D-7 | P2 | uber-photo | Montant du ticket capturé mais jamais affiché |
| D-13 | P2 | promo-flyer | 4 libellés FR sans accents (+ 1 message serveur) |
| D-15 | P2 | promo-flyer | Colonnes Code/Statut collées |
| D-16 | P2 | promo-flyer/settings | Textes par défaut sans accents dans un champ éditable |
| D-10 | P2 *latent* | cash-sessions-report | Groupement par jour calculé sur la page courante — **non observé** |
| D-11 | P2 *latent* | uber-photo | Aucun état vide sur l'historique — **non observé** |
| D-8 | P3 | shell | `capitalize` anglophone sur le français ; tutoiement isolé |
| D-9 | P3 | shell | 404 `/storage/1/english.png` |

**6 défauts bloquants (1 P0 + 5 P1).** Aucun n'est un défaut de **format** monétaire : tous
portent sur **ce que le chiffre prétend représenter** — sa date, son périmètre, sa complétude.

## Réserves de méthode

* **Environnement** : les captures du premier passage (pendant la panne `vendor/`) ont été détruites ; toutes celles versées ici sont post-réparation, garde active.
* **Correction assumée** : « 111 lignes » annoncé en cours d'analyse était faux (Laravel Debugbar comptée) — le chiffre exact est 100.
* **Laravel Debugbar est visible** en pied de chaque capture (`APP_DEBUG=true` en dev). Elle occupe ~30 px et n'a masqué aucun élément analysé.
* **Deux défauts sont marqués *latents*** (D-10, D-11) : identifiés par lecture de code, **non reproduits** par la capture. Ils ne doivent pas être comptés dans le score de la vague.
* **Données concurrentes** : deux transactions du 2026-08-25 (13,10 € + 2,50 €, lignes `cash-overview-row-1149/1150`) sont apparues entre mon relevé de base initial et la capture — vraisemblablement une autre vague opérant sur la même base. Elles n'invalident aucun contrôle (les sommes ont été recalculées depuis le DOM capturé, pas depuis la base).
