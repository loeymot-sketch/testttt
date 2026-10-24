# Rapport de clôture — Mission de convergence du 2026-09-03

Superviseur : Claude, session unique.
HEAD d'ouverture : `28cd79d5a` · HEAD de clôture : voir §6.
Origine : contre-audit Codex (dashboard/contrôle, 2026-09-02) + audit Grok (vitrine, T01–T58, 2026-08-28).

---

## §1 — Le résultat en une page

| Mesure | À l'ouverture (selon les audits) | À la clôture (mesuré) |
|---|---|---|
| PHPUnit complet | 5 542 tests, **10 échecs** | **5 991 verts, 0 échec** (6 incomplets, 36 sautés) |
| Vitest complet | 460 fichiers, **4 rouges** | **531 fichiers, 4 375 verts, 0 rouge** (3 sautés) |
| Playwright caisse | **5 échecs consécutifs** | **11/11**, puis campagne de clôture **4/4** |
| Sentinelles de fraîcheur des lots | **4 rouges** | **5 vertes** |
| Sentinelles PHP dites critiques | « 3 rouges » | **9 verts** — elles n'ont jamais été rouges sur ce HEAD |
| `safety-check.sh` | « BLOCKED » | **PASS** — il ne l'a jamais été sur ce HEAD |
| Diff zone gelée | « 53 insertions en attente » | **0 ligne** — il n'y en a jamais eu sur ce HEAD |
| Chaîne NF525 | non relevée | **CHAIN OK** sur les 6 branches, avant et après |
| P1 ouverts | 10 annoncés | **0** sur le périmètre traité |

Journaux bruts : `phpunit-complet.txt`, `vitest-complet.txt`, `CONTROLES-CLOTURE.txt`.

---

## §2 — Ce que la re-vérification a coûté aux audits d'origine

C'est la partie qui sert le plus à la prochaine session.

**Sept affirmations de Codex sont fausses sur le HEAD courant** : zone gelée prétendue modifiée
(diff vide), `safety-check` prétendu bloquant (PASS), trois sentinelles PHP prétendues rouges
(vertes 9/9), campagne caisse prétendue en échec (11/11), contrôles `pos-control-*` prétendus
absents (présents — dans le morceau différé `pos-shell`, pas dans `pos-app`), zéro route
dashboard prétendue non testée (0 réellement), et message d'exception prétendu renvoyé brut
(traduit depuis un correctif antérieur).

**Cause commune** : un rapport écrit sur un instantané mouvant, avec des `grep` littéraux
aveugles à la concaténation d'URL et au découpage en morceaux différés de Webpack.

**Côté vitrine, 19 des 41 tickets vérifiables étaient déjà corrigés** entre le 28 août et le
2 septembre, 1 était réfutable par la mesure, 1 non reproductible. Il ne restait pas de quoi
remplir les quatre GOALs prévus : il restait **quatre défauts**.

Exécuter ces deux listes telles quelles aurait consommé plusieurs journées à réparer ce qui
l'était déjà — et laissé passer trois défauts qu'aucun des deux n'avait vus.

---

## §3 — Le défaut le plus coûteux, et il tenait en une commande

Quatre sentinelles Vitest rouges, cinq échecs Playwright, et l'affirmation « le tiroir existe
dans le source mais n'est pas livré » avaient **une seule et même cause** : les sources du
2 septembre 23h24 n'étaient pas compilées. Les lots servis dataient de 18h12 et du 1er septembre.

`npm run production` a tout fermé.

La leçon vaut d'être gardée : **on mesure le fichier réellement servi, pas celui dont on suppose
le nom.** Un audit qui grep `public/js/pos-app.js` sans savoir que Webpack a mis le composant
dans `pos-shell.<hash>.js` conclut à l'absence d'une chose présente.

---

## §4 — Les neuf P1 réels, et ce qu'ils faisaient

Chacun est fermé par un banc **prouvé rouge sans son correctif** ; les sorties rouges sont
conservées dans les fichiers `G*-bancs-mordent.txt`.

| # | Ce que l'utilisateur subissait | Fermé par |
|---|---|---|
| V-01 | Au-delà de 100 commandes, le tiroir de caisse **jetait les plus anciennes** — celles qui traînent — sans rien signaler. Quatre files, deux badges et le rang cuisine devenaient faux en silence. | endpoint borné au jour de service, deux familles, un plafond chacune, et une troncature qui s'annonce |
| V-02 | La relance outbox ne disait jamais combien d'événements avaient été remis en file. | contrat API/écran réaligné sur `requeued` |
| V-03 | **2 150 claims orphelins** étaient chargés dans l'écran puis jetés sans être affichés. | trois blocs rendus, avec annonce accessible |
| V-04 | La confirmation de purge comptait une table, l'action en supprimait une autre. | compteur dédié `purgeable_failed_jobs` |
| V-05 | L'audit de purge écrivait le nombre supprimé **avant** le `DELETE` : une suppression ratée laissait une preuve immuable qui mentait. | suppression puis audit du nombre réel, même transaction |
| V-06 | Un worker de notifications vivant affichait la file outbox « up » alors qu'elle était **morte**. | sonde bornée à la file portée par le job |
| V-07 | L'audit d'une bascule était écrit **avant** la mutation : une mutation ratée laissait un journal indélébile affirmant le contraire. | mutation et audit dans une même transaction |
| V-08 | Un dump corrompu arrivé après un essai réussi sur un autre fichier était présenté comme « réellement remonté ». | rapprochement nom **et** SHA-256 |
| V-09 | Six des neuf sorties d'échec du drill ne persistaient aucun verdict : readiness affichait le succès de la veille pendant 48 h. | enregistreur unique de verdict, toutes sorties |

---

## §5 — Ce que la mission a trouvé en propre

Aucun des deux audits n'avait vu ces cinq points.

1. **La carte sauvegarde restait verte 29 minutes par jour** en contredisant la bande d'alertes
   du même écran. Le serveur comparait bien en décimal, mais publiait l'arrondi et l'écran
   recalculait son vert dessus. Codex plaçait ce défaut côté serveur, où il était déjà corrigé.
2. **Trois surfaces lisaient le même dossier de sauvegardes avec deux motifs différents.** Le
   rapprochement fichier/preuve devenait donc impossible à satisfaire dès qu'un dump manuel
   était plus récent qu'une quotidienne. Pas un faux vert : un **rouge permanent**, ce qui est
   pire, parce qu'on cesse de le regarder.
3. **Le sondage périodique effaçait le message d'échec d'une bascule.** L'exploitant voyait
   l'erreur une seconde, puis plus rien — bouton inchangé, aucune explication : il concluait
   que son clic n'avait pas été pris et recommençait.
4. **Un bouton promettait une clôture fiscale qu'il ne fait pas.** « PDF Clôture du jour » sur
   une surface NF525, alors que `eodSynthesis()` est une lecture pure. Trouvé en **lisant la
   capture**, pas le code.
5. **Deux composants du tableau de bord ne sont montés nulle part** — dont un gardé par trois
   tests verts. Du code mort tenu par une sentinelle vivante, avec ses routes API maintenues.

Et sur le tableau de bord, le vrai défaut n'était pas l'absence de tests : sur **cinq
composants sur six**, un échec réseau était indiscernable d'une journée creuse. `OrderStatistics`
faisait pire — une journée à zéro affiche « 0 », l'échec affichait du **blanc**, que l'œil lit
comme un zéro. Et `RealtimeReport` levait correctement son drapeau d'échec alors que les deux
branches rendaient la même chose : un correctif qui avait l'air appliqué et n'avait aucun effet.

---

## §6 — Ce qui reste ouvert, et pourquoi

### Portes propriétaire (aucune ne bloque le code déjà livré)

| Porte | Question | Recommandation |
|---|---|---|
| **P1** | `CustomerStatsComponent` et `TopCustomersComponent` : remonter, déplacer ou supprimer ? | Trancher explicitement. Leurs routes API restent gardées et testées pour un écran que personne n'ouvre. |
| **P2** | Déploiement backend : la production tourne en **PHP 8.1**, `server-setup.sh` installe **8.4**. | Rendre la version paramétrable et **faire refuser le pré-vol** si l'hôte ne correspond pas, puis figer sur 8.1. Quelques lignes suppriment la classe entière de l'accident. |
| **P3** | Déploiement vitrine — un `push` sur `main` déclenche Vercel. Le commit est posé **en local**, non poussé. | Autorisation par lot. |
| **P3-a** | Politique d'upsell après « Sans formule ». Mesure faite : ce ne sont pas trois murs mais **deux**, le troisième étant du code mort. | Un écran groupé n'économise qu'**un** clic sur six. À arbitrer commercialement, pas techniquement. |
| **P3-b** | Les CGV disent « sur place » et « au comptoir », l'accueil dit « tout se prend à emporter ». | Deux vérités contradictoires, dont une contractuelle. Une seule doit rester. |

### Constats portés, non corrigés en douce

- **Cinq violations du garde de prix**, toutes **antérieures** à cette mission — vérifiées
  présentes au HEAD d'ouverture. L'une attend une signature humaine depuis le 10 mai ; trois
  sont en zone gelée et demandent un LOCK ; la dernière demande de déporter un calcul au serveur.
- **Une sauvegarde de sécurité vide** (0 octet) prise avant une synchronisation le 30 août en
  production. Ce n'est pas un défaut de code : c'est une commande manuelle dont personne n'a lu
  la sortie.
- Le « dump de 20 octets » signalé par l'audit **n'existe pas en production** : c'est un fichier
  local d'un octet, et le banc qui le rejette existe désormais.

### Périmètre non couvert, dit franchement

- Les **états dégradés** du cockpit (worker mort, purge en échec, drill rouge) sont tenus par
  les bancs de composant et les bancs serveur, qui savent les fabriquer — **pas** par la
  campagne navigateur, qui vérifie l'écran réel sur le lot réel.
- La **parité chiffrée du PDF de synthèse avec le Z fiscal** sur remboursements et paiements
  multiples n'est toujours pas éprouvée. Le libellé ne ment plus ; les chiffres ne sont pas
  vérifiés pour autant.
- La transaction audit/mutation **n'a pas été éprouvée sous concurrence réelle** (deux
  administrateurs basculant simultanément). Sa sûreté repose sur l'index
  `audit_logs_branch_prev_unique`, dont l'existence a été vérifiée en base plutôt que supposée.

---

## §7 — Une erreur de ma part, consignée

Le commit `39fffecad` (caisse) a emporté six clés `fr.json` appartenant au chantier tableau de
bord : j'ai commité un fichier transversal pendant qu'un autre agent y écrivait. Les clés sont
justes et uniques, rien n'est cassé — mais le commit qui les porte n'est pas le bon.

Règle à retenir : **un fichier de traduction appartient à tous les chantiers ; il se commite en
dernier, jamais avec un lot.**

---

## §8 — Méthode, pour la prochaine session

Trois règles ont produit tout ce qui précède, et une quatrième a failli coûter cher.

1. **Vérifier avant de planifier.** La moitié du travail annoncé n'avait pas lieu d'être.
2. **Un banc doit mordre.** Chaque correctif est précédé d'une sortie rouge conservée. Deux
   bancs ont dû être réécrits parce qu'ils rougissaient pour la mauvaise raison — un tiroir
   monté par `Teleport` que `find()` ne trouvait pas, et un `ZReport` lu dans un **commentaire**.
3. **Mesurer le fichier servi**, pas celui dont on suppose le nom.
4. **Lire les captures.** Le défaut du bouton « Clôture » n'était visible nulle part ailleurs.

---

## §9 — Vitrine : les dix-sept jamais mesurés (ajouté après clôture backend)

Onze étaient encore vrais. **Cinq corrigés** (T17, T24, T26, T35, T36), **trois réfutés par la
mesure** (T25, T31, T57), **trois relèvent d'un choix propriétaire** (T19, T21, T30).

**T24 était bien plus grave que son propre ticket.** Pas 26 fiches mais 24, et **35 constats sur
14 d'entre elles**. Le rang de prix sortait de la position dans une liste triée : entre deux
produits au même prix il était **tiré du hasard**, et la phrase se contredisait dans sa propre
ligne — « C'est le plus cher des 3 desserts […] au même prix que Glace et Tarte Daim ». Corrigé
dans le générateur, 24 fiches régénérées, banc à 108 constats verts.

**T17 : le diagnostic du ticket était inversé.** Le cartouche n'était pas figé, il était **en
avance** — texte instantané, photo en 900 ms. 25 relevés sur 335 nommaient une photo visible à
2 %. Un premier réglage à 450 ms est resté rouge ; le banc a imposé **265 ms**, le croisement
réel d'une courbe `ease` étant à 29,3 % de sa durée et non à la moitié.

**Trois prémisses d'audit fausses à la mesure** : les emojis ne sont pas « le seul endroit du
site » (l'accueil en porte 15) · « Pepper Club » est nommé ailleurs que dans les CGV · les sept
scripts « sans `defer` » sont en fin de `<body>` et ne bloquent rien — 1,3 Mo sur disque font
**411 Ko réellement transférés**.

**Non appliqué volontairement** : l'en-tête `Access-Control-Allow-Origin: *` est réel et mesuré
en production, mais rien ne permet de vérifier sans déploiement que le correctif écrase bien
l'en-tête ajouté par la plateforme. Le correctif est écrit dans le rapport, pas dans le dépôt.

**Reste hors code** : T09 et T20 demandent un fichier image. Et une correction d'énoncé — le
logo dit « SANDWICHS », français correct ; le seul mot anglais est **BOWLS**, quand le site dit
« Bols » partout.

**T15 mesuré, rien refondu** : trois en-têtes, 9 · 10 · 4 liens, deux fonds, écrits en trois
langages sur 43 pages. C'est **une journée pleine**, pas une nuit — et le dire vaut mieux que
le commencer.

Détail : `reports/audit/VERIF_VITRINE_LOT2_2026-09-03.md`.
Dépôt vitrine : **2 commits en local, non poussés** (porte propriétaire P3).
