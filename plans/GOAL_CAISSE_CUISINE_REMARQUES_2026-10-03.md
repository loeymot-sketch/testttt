# GOAL — Caisse, cuisine, encaissement : les remarques du propriétaire non encore faites (2026-10-03)

> Mandat (propriétaire, 2026-10-03) : « j'ai fait beaucoup de remarques […] la caisse, les personnalisation,
> la facturation, l'affichage, l'annulation des commandes annuler par téléphone ainsi que l'écran de cuisine
> d'agrandir les suppléments ainsi que mettre un dièse si il y a un produit avec des supplément […] détermine si
> modifier pas encore […] écris-moi le goal bien déterminé et lance-le et puis test, il tourne en boucle.
> Vérifie que tout est bien intégré. »

## §0 — Préambule

- **Base** : branche `qa/corrige-rapports-2026-09-28`, HEAD `b0144f788`. Ce HEAD intègre le lot caisse/cuisine du
  02/10 (`78037783c`, fusion `8ce5797d3`). PHPUnit `tests/Feature/Pos` passe à 436/436, I18n à 8/8, Vitest
  complet à 4723/4723 (579 fichiers), et la chaîne fiscale est OK sur 7 branches.
- **Inventaire** : 79 remarques recensées dans `reports/caisse-remarques-2026-10-03/REMARQUES_PROPRIETAIRE.md`.
  Un statut a été établi pour chacune, en lisant le code au `fichier:ligne`, dans `STATUT_A_caisse_autres.md`,
  `STATUT_B_personnalisation.md` et `STATUT_C_paiement_tickets_kds.md`, sur le même dossier.
- **Arbre de travail** : `testttt/.claude/worktrees/qa-corrige-2026-09-28`. Les commits se font par chemins
  explicites ; jamais de `git add .`.
- **Limites de cette session**, constatées le 2026-10-03 : Chromium ne démarre pas (`chromium.launch()` échoue)
  et ssh et GitHub sont inaccessibles. Les serveurs :8766 et :8000 répondent. Conséquences :
  - tout ce qui se prouve par code et par test se fait **maintenant** ;
  - la preuve visuelle (CLAUDE.md §6) et le déploiement attendent le redémarrage de Claude Code depuis le
    Terminal (porte G3).
- **Pipeline par tâche** : test rouge → correctif → test vert → relecture du diff → suite ciblée. Une tâche n'est
  pas « faite » sans le test cité dans son critère.
- **Collègue** : la session `testttt-a8` (auteur du lot du 02/10) n'a rien en cours et ne touche plus ces
  fichiers (message du 2026-10-03).

### Règles dures (CLAUDE.md §7, §8, §3quater)

- **Zones gelées intouchées** : `PaymentComponent.vue`, `PosV5TrancheRow.vue`, `pos-wizard.js` et `.css`,
  `admin-pos-v4.blade.php`, Kiosk Wizard/App/Upsell, `PricingService`, `OrderStateMachine`, `BranchScope`,
  `IdempotencyKeyMiddleware`, `Services/Fiscal/*`. Une remarque qui exige ces fichiers va en porte G1.
- **NF525** :
  - on ne supprime jamais une commande, une ligne d'audit ou un Z ;
  - on ne touche jamais une commande qui porte un numéro fiscal ;
  - l'annulation reste une transition tracée.
- **Prix** : seul le serveur calcule. Le client envoie `item_id`, la quantité et les options, rien d'autre.

## §1 — Bilan des 79 remarques (au HEAD `8ce5797d3`)

| Section | FAIT | PARTIEL | NON FAIT | NON VÉRIFIABLE |
|---|---|---|---|---|
| 1 Caisse, 7 Afficheur, 8 Autres (24) | 13 | 6 | 2 | 3 |
| 2 Personnalisation (24) | 8 | 5 | 2 | 9 |
| 3 Encaissement, 4 Tickets, 5 Annulation, 6 KDS (31) | 20 | 7 | 2 | 2 |
| **Total (79)** | **41** | **18** | **6** | **14** |

La suite de ce document ne traite que les 24 remarques **PARTIEL** ou **NON FAIT**, plus les risques latents
repérés au passage. Les **NON VÉRIFIABLE** relèvent de la production ou du matériel : voir les portes G5 et G6.

## §2 — Ce qui entre dans ce GOAL, et ce qui n'y entre pas

| Classe | Remarques | Traitement |
|---|---|---|
| **Code hors zone gelée** | R-009, R-012, R-015, R-016, R-017, R-038, R-041, R-048, R-052, R-053, R-054, R-059, R-060, R-065, R-069, R-070, R-071, R-072 (vérif), R-075, R-078 (code) | **Vagues 1 à 3, maintenant** |
| **Mise en page à mesurer** | R-005 (bande blanche), R-006 (barre du haut, décision G1-layout) | Vague 4, avec navigateur (G3) |
| **Zone gelée `pos-wizard.js` et `.css`** | R-020 (tuiles suppléments et frites 10-11 px), R-023 (« Sans sauce » non exclusif, facturé +0,50 €), R-025 (faux choix pain sur produit sans attribut pain), R-033 (message « viande supplémentaire »), R-035 (étape « extra sauce fromagère ») | Porte **G1** : LOCK contresigné par le propriétaire |
| **Décision du propriétaire** | R-034 (Américaine), R-036 (portion 1,90 €, « plus tard »), R-028 (cornichon, D1), R-006 (agencement) | Porte **G2** |
| **Données de production** | R-024 à R-032, R-040 (migration), R-042 (extras nommés), R-078 et R-079 (état prod), R-068 | Porte **G6** |
| **Matériel et ressenti** | R-047 (tiroir), R-074 (afficheur SAGA), R-007 (vitesse) | Porte **G5** |

## §3 — Vague 1 : Cuisine (écran KDS et ticket cuisine)

C'est la demande explicite du 03/10 : suppléments plus grands, « # », annulation.

**Ancres vérifiées** (`wc -l`, 2026-10-03) :

| Fichier | Lignes |
|---|---|
| `resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue` | 373 |
| `KdsOrderCard.vue` | 1125 |
| `KdsV2Grid.vue` | 896 |
| `KitchenDisplaySystemComponent.vue` | 3624 |
| `resources/js/helpers/kdsSymbolic.js` | 1107 |
| `resources/js/helpers/kdsCustomization.js` | 523 |
| `app/Services/Hardware/KitchenTicketSymbolicFormatter.php` | 1175 |
| `OrderReceiptEscPosRenderer.php` | 929 |
| `EscPosCommandBuilder.php` | 549 |
| `app/Services/Uber/UberOrderMapper.php` et `UberPhotoOrderMapper.php` | — |

**Bancs existants à ne pas casser** :
- `tests/js/kdsLisibiliteCuisine.spec.js`
- `tests/js/kdsNomsDesSaucesToutesCategories.spec.js`
- `tests/Feature/Hardware/KitchenTicketLisibiliteInverseeTest.php`
- `tests/Unit/Hardware/KitchenTicketNomsDesSaucesToutesCategoriesTest.php`
- `OrderReceiptEscPosRendererTest`
- `TicketWidthSafeTest`

### T-1.1 — R-071 : agrandir les suppléments sur l'écran de cuisine

- **Constat** : `.kds-line__supplement` est en 15 px (`KdsOrderLine.vue:281`), plus petit que la ligne produit.
- **But** : un supplément s'écrit **au moins aussi grand que le nom du produit**, en gras (800 ou plus), blanc
  sur cadre noir, avec une marge intérieure lisible. Garde-fou : aucun débordement horizontal, et retour à la
  ligne par mots, jamais à l'intérieur d'un mot (cf. régression C4-001).
- **Critère** : `tests/js/kdsSupplementsAgrandis.spec.js` (à créer). Il compare la règle CSS de
  `.kds-line__supplement` à celle du nom produit et vérifie gras, fond noir et texte blanc. Les bancs existants
  restent verts.
- **Écran** : `http://127.0.0.1:8766/kds`, en vague 4.

### T-1.2 — R-072 : « # » sur TOUT produit qui porte un supplément

- **Constat** : marquage présent (`kdsSymbolic.js:803`, `:812-816` ; `OrderReceiptEscPosRenderer.php:490-493`).
  Le propriétaire le redemande le 03/10. Il faut donc le prouver pour **chaque type** de supplément :
  - extra payant ;
  - sauce supplémentaire repliée sur la ligne ;
  - viande supplémentaire ;
  - options de formule frites (Grande Portion, Cheddar Fondu) ;
  - extra offert (0 €) ;
  - commande à plusieurs lignes.
- **But** : tout trou trouvé est corrigé sur l'écran de cuisine **et** sur le ticket.
- **Critère** :
  - `tests/js/kdsDieseTousSupplements.spec.js` (à créer), une matrice des types sur `kdsSymbolic` ;
  - `tests/Unit/Hardware/KitchenTicketDieseTousSupplementsTest.php` (à créer), la même matrice sur le ticket.

### T-1.3 — R-070 : une sauce vendue seule doit garder son nom en cuisine

- **Constat** : un article de la catégorie « Sauces supplémentaires » (« Sauce Ketchup ») sort « SAU » via
  `produitCode` (`KitchenTicketSymbolicFormatter.php:1119+`, et son jumeau `kdsSymbolic.js:379-415`). Contrôle
  tinker : `mainLine('Sauce Ketchup')` donne `SAU`.
- **But** : le nom de la sauce est affiché (« SAUCE KETCHUP », ou son abréviation métier si elle existe dans
  `config/pos_sauces.php`), à l'identique sur l'écran et sur le ticket.
- **Critère** : cas ajoutés dans `tests/Unit/Hardware/KitchenTicketNomsDesSaucesToutesCategoriesTest.php` et
  `tests/js/kdsNomsDesSaucesToutesCategories.spec.js`, sur les 13 sauces de la catégorie.

### T-1.4 — R-052 : numéro de commande géant sur le ticket cuisine

- **Constat** : double taille seulement (`OrderReceiptEscPosRenderer.php:339-349`, `GS ! 0x11`).
- **But** : passer à la taille ESC/POS maximale (`textSize` ×8 en hauteur). La largeur est choisie pour que le
  numéro tienne sur une ligne de `receipt.width_chars`.
- **Limite matérielle** : ×8 avec la police A fait environ 2,4 cm. Atteindre 3 à 4 cm demande une image
  tramée : c'est la porte **G4**.
- **Critère** : `tests/Feature/Hardware/KitchenTicketNumeroGeantTest.php` (à créer) vérifie les octets de taille
  avant le numéro, le retour à la taille normale après, et la largeur respectée.

### T-1.5 — R-053 : lignes MENU et FRITES encadrées en noir sur le ticket cuisine

- **Constat** : ces lignes sont en gras et en double hauteur, sans inversion (`OrderReceiptEscPosRenderer.php:499-505`).
- **But** : vidéo inverse `GS B 1` … `GS B 0` sur ces lignes, sur le même modèle que les suppléments (`:515`).
- **Critère** : cas ajoutés dans `tests/Feature/Hardware/KitchenTicketLisibiliteInverseeTest.php`.

### T-1.6 — R-054 : quantité « 2 x » sur fond noir

- **Constat** : la quantité fait partie de la ligne produit, sans distinction (`OrderReceiptEscPosRenderer.php:412`, `:489-496`).
- **But** : le préfixe de quantité (> 1) est en vidéo inverse sur le ticket cuisine. Sur l'écran de cuisine, la
  quantité reste en 26 px gras ; on y ajoute un fond noir si elle dépasse 1.
- **Critère** : cas dans `KitchenTicketLisibiliteInverseeTest.php` et dans `tests/js/kdsSupplementsAgrandis.spec.js`.

### T-1.7 — R-075 : ligne Uber non reconnue, afficher son titre complet

- **Constat** : la ligne non reconnue est rattachée à l'article technique « Article Uber (non mappé) », qui sort
  « ART » (`UberOrderMapper.php:196-213`, `KitchenTicketSymbolicFormatter.php:1119-1150`). Le vrai titre n'est
  que dans la note « [UBER NON MAPPÉ: …] » (`UberPhotoOrderMapper.php:229-231`).
- **But** : le titre Uber complet devient la ligne produit, sur le ticket cuisine et sur l'écran de cuisine. Les
  options restent en symboles caisse (pas « sauce », « crudité », « boisson » en toutes lettres).
- **Critère** :
  - `tests/Unit/Hardware/KitchenTicketUberNonMappeTitreTest.php` (à créer) ;
  - `tests/js/kdsUberNonMappe.spec.js` (à créer).

### T-1.8 — R-069 : tiroir Historique du KDS, rattacher la sauce à son produit

- **Constat** : le plateau principal est correct, mais l'Historique garde une ligne par extra (`kdsCustomization.js:447-456`).
- **But** : même regroupement que sur le plateau (sous le produit, ou sur le badge MENU ou FRITES).
- **Critère** : `tests/js/kdsHistoriqueSupplementsRattaches.spec.js` (à créer).

### T-1.9 — R-065 : barre du KDS sur une seule ligne, à côté du logo

- **Constat** : la rangée de boutons est distincte de la barre du logo, et le sélecteur de colonnes a sa propre
  ligne (`KitchenDisplaySystemComponent.vue:22-99`, `KdsV2Grid.vue:91`, `:277-279`).
- **But** : logo, boutons et sélecteur de colonnes tiennent sur une seule ligne. Garde-fou du Teleport : ne pas
  réintroduire le plantage R-063.
- **Critère** : `tests/js/kdsBarreUneLigne.spec.js` (à créer) vérifie la structure : un seul conteneur de barre,
  le sélecteur dedans, et aucun Teleport conditionnel. La capture se fait en vague 4.

## §4 — Vague 2 : annulation, commandes téléphone, impression après encaissement

**Ancres** :

| Fichier | Lignes |
|---|---|
| `resources/js/components/admin/encaissement/EncaissementComponent.vue` | 865 |
| `resources/js/components/admin/pos/PosCounterCollectModal.vue` | 1183 |
| `PosOrdersTrackerComponent.vue` | 4273 |
| `PosComponent.vue` | 8569 |
| `app/Services/Pos/CounterCollectQueue.php` | 131 |

Les routes `counter-collect/*` sont dans `routes/api.php`, vers les lignes 1078 à 1416.

**Bancs existants** :
- `tests/Feature/Pos/FileEncaissementVidageGroupeTest.php`
- `tests/js/encaissementUnSeulVidage.spec.js`
- `tests/js/posCounterCollectPrintDecision.spec.js`

### T-2.1 — R-060 : la croix du panneau « à encaisser » de la caisse annule sans motif

- **Constat** : `/admin/encaissement` annule en deux clics, sans motif (`EncaissementComponent.vue:163-194`,
  `:413-452`). Le panneau de la caisse exige encore un motif tapé (`PosComponent.vue:2223-2230`, `:2290-2301`,
  `:5671-5678`).
- **But** : même geste que sur `/admin/encaissement` (✕ armé puis confirmé), même route et même motif
  automatique.
- **Critère** : `tests/js/posPanneauAnnulationSansMotif.spec.js` (à créer). La route serveur garde son test
  existant, ou en reçoit un nouveau.

### T-2.2 — R-060 : « Tout supprimer » pour les commandes téléphone du jour encore en attente

- **Constat** : il n'existe de purge en bloc que pour les jours précédents (R-061).
- **But** : une action « Supprimer toutes les commandes téléphone en attente ». Elle est bornée par :
  - `source_surface = phone`, `PENDING_COUNTER`, jamais encaissées, sans numéro fiscal ;
  - **jamais** les commandes de la borne ni du site (ces clients sont, ou peuvent être, présents) ;
  - une confirmation qui affiche le nombre de commandes ; aucun motif à taper.

  L'annulation passe par la même transition tracée que la croix.
- **Critère** : `tests/Feature/Pos/PurgeCommandesTelephoneDuJourTest.php` (à créer) couvre :
  - le périmètre exact (téléphone seulement, borne et web intacts) ;
  - l'isolation par branche ;
  - le refus d'une commande payée ou portant un numéro fiscal ;
  - une trace d'audit par commande.

### T-2.3 — R-059 : « Commandes ratées », récupérables pendant 24 h

- **Constat** : la croix annule définitivement (CANCELED + REFUNDED, `PaymentService.php:946-949`). Rendre la
  commande annulée réversible demanderait de toucher la machine à états, qui est gelée.
- **Conception retenue** (sans zone gelée) :
  - un onglet ou une liste « Commandes ratées » montre les commandes téléphone annulées par la croix ou par
    T-2.2 au cours des **24 dernières heures** ;
  - un bouton « Reprendre » recharge leurs lignes dans le panier de la caisse ; on passe ensuite une **nouvelle**
    commande par le circuit normal, avec prix serveur et nouveau numéro ;
  - après 24 h, la commande sort de la liste. Elle n'est pas effacée : NF525 interdit la suppression.
- **« Sans trace fiscale »** : c'est déjà vrai, puisqu'il n'y a ni numéro fiscal ni Z. La ligne d'audit chaînée
  de l'annulation reste, car elle est obligatoire ; c'est expliqué au propriétaire.
- **Critère** :
  - `tests/Feature/Pos/CommandesRateesVingtQuatreHeuresTest.php` (à créer) : fenêtre de 24 h, téléphone
    seulement, branche, lecture seule ;
  - `tests/js/encaissementCommandesRatees.spec.js` (à créer) : liste, bouton « Reprendre », panier rechargé.

### T-2.4 — R-048 : demander « imprimer ou non » après chaque encaissement

- **Constat** : la question existe après une vente directe et après un encaissement depuis l'écran caisse. Elle
  manque sur `/admin/encaissement` (`EncaissementComponent.vue:710-731`) et dans le suivi
  (`PosOrdersTrackerComponent.vue:2314-2321`). Le bouton y affiche « Confirmer & Imprimer » sans rien imprimer
  (`PosCounterCollectModal.vue:254`).
- **But** :
  - après un encaissement réussi, sur toutes les surfaces, la même question est posée, avec le même helper que
    la caisse ;
  - le libellé du bouton dit la vérité ;
  - aucune impression automatique.
- **Critère** :
  - `tests/js/posCounterCollectPrintDecision.spec.js`, étendu ;
  - `tests/js/encaissementQuestionImpression.spec.js` (à créer).

## §5 — Vague 3 : caisse

**Ancres** :
- `PosComponent.vue` (8569 lignes) ;
- `PosControlDrawer.vue` (1092 lignes) ;
- `resources/js/helpers/posCartCompactDisplay.js` (230 lignes) ;
- `app/Services/Pos/OfferedExtras.php` (139 lignes) ;
- `app/Console/Commands/MenuResetLeCayenneCommand.php` (1250 lignes) ;
- `config/menu_images.php`.

### T-3.1 — R-012 : commandes web vraiment rouges

- **Constat** : un liseré de 4 px seulement ; les boutons « Accepter » et « Détails » restent bleus
  (`PosComponent.vue:7847-7865`).
- **But** : lignes et boutons des panneaux web en rouge, avec un contraste AA vérifié.
- **Critère** : `tests/js/posCommandesWebRouge.spec.js` (à créer).

### T-3.2 — R-015 : ✕ « Retirer le client » sur le badge fidélité

- **Constat** : `PosComponent.vue:1166-1186` n'offre aucun moyen de détacher le client.
- **But** : un ✕ détache le client sans vider le panier.
- **Critère** : `tests/js/posRetirerClientFidelite.spec.js` (à créer).

### T-3.3 — R-017 : temps de préparation au bouton « Accepter » de l'écran principal

- **Constat** : le suivi envoie `preparation_time`, l'écran principal non (`PosComponent.vue:5213-5223`).
- **But** : le même choix (raccourcis plus saisie libre en minutes) au moment d'accepter une commande web
  depuis l'écran principal.
- **Critère** : `tests/js/posAccepterTempsPreparation.spec.js` (à créer).

### T-3.4 — R-009 : composition en mots techniques dans le suivi, le tiroir et la file « À encaisser »

- **Constat** : la composition s'affiche en toutes lettres (`support/compositionCommande.js:65-86`), et la file
  ne montre que le numéro et le prix (`PosComponent.vue:633-665`).
- **But** : réutiliser les abréviations du panier (`posCartCompactDisplay.js`, par exemple « STO ») pour le
  suivi et le tiroir, et ajouter un aperçu court des produits dans la file.
- **Critère** : `tests/js/posCompositionTechniqueSuivi.spec.js` (à créer).

### T-3.5 — R-016 : « Valider le retrait » sur les commandes payées du site

- **Constat** : le panneau « Web payées » est en lecture seule (`PosComponent.vue:799-806`, `:836-842`). Les
  points sont crédités au passage en livrée (`AwardLoyaltyPointsOnDelivery.php:43-45`).
- **But** : un bouton « Valider le retrait » par commande, qui fait passer la commande en livrée par la route
  existante et déclenche ainsi les points.
- **Critère** :
  - `tests/js/posWebPayeesValiderRetrait.spec.js` (à créer) ;
  - pour les points, le test existant `AwardLoyaltyPointsOnDelivery`, ou un test à créer à côté.

### T-3.6 — R-038 : supplément libre interdit sur un panier vide

- **Constat** : `PosComponent.vue:6327-6357` ne vérifie rien (Codex P1-19).
- **But** : bouton désactivé avec un message tant que le panier ne contient aucun produit.
- **Critère** : `tests/js/posSupplementLibrePanierVide.spec.js` (à créer).

### T-3.7 — R-041 : « Offert » sur une sauce vendue seule et sur une option de formule

- **Constat** : le serveur exige un extra du produit de la ligne (`OfferedExtras.php:85-93`), et le panier ne
  lit que `item_extras` (`PosComponent.vue:6501-6506`).
- **Étape 1 (exploration)** : comment l'option de formule et la ligne « sauce vendue seule » sont-elles
  facturées ? Peut-on les offrir sans toucher `PricingService` ?
  - si oui : implémenter, avec le serveur qui valide et trace en audit chaîné ;
  - si non : porte G1 bis (LOCK `PricingService`).
- **Critère** : `tests/Feature/Pos/OffertSauceSeuleEtOptionFormuleTest.php` (à créer).

### T-3.8 — R-078 : « Galette Normale » ne doit plus jamais revenir

- **Constat** : `MenuResetLeCayenneCommand.php:97`, `:651` recrée encore « Galette Normale », et
  `config/menu_images.php:85-92` pointe les galettes vers `galette.png`.
- **But** : la réinitialisation ne crée que « Galette Cayenne » et « Galette Classique », avec les images
  validées. La garde de dérive `fbe045524` doit être lue avant d'y toucher.
- **Critère** : `tests/Feature/Menu/ResetNeRecreePasGaletteNormaleTest.php` (à créer).

## §6 — Vague 4 : preuve visuelle et boucle test-e2e (après la porte G3)

- **Surfaces** : `/kds`, `/admin/pos`, `/admin/encaissement`, `/admin/pos/orders` (le suivi), et le ticket
  cuisine en aperçu.
- **Mesures** : R-005 (bande blanche) et R-006 (barre du haut, si la décision G2 est prise) se mesurent et se
  corrigent ici.
- **Boucle** (skill `test-e2e`) :
  - captures avec leur quatuor (PNG, DOM, console, réseau) ;
  - relecture adverse : priorité au visuel, puis au technique ;
  - correction, puis nouvelle passe ;
  - **convergence** : deux passes consécutives avec P0+P1 = 0 et des constats identiques.

## §7 — Vague 5 : déploiement et contrôle en production (après G3 et G6)

1. Backend d'abord, migrations comprises (dont `2026_10_02_090000` « Sauces supplémentaires »). Comparer le
   HEAD du VPS avant et après.
2. Contrôles en lecture seule en production, pour les données R-024 à R-032, R-040, R-042, R-078 et R-079.
3. Rapport au propriétaire, remarque par remarque.

## §8 — Boucle et contrôles de fin de vague

À la fin de **chaque** vague, dans cet ordre :

1. `npm run production`, sans erreur.
2. PHPUnit complet et Vitest complet. Tout échec est rejoué seul avant d'être imputé.
3. Zones gelées : `git diff --stat b0144f788..HEAD -- <fichiers §7>` = **0 ligne**, et la sentinelle SHA-256
   reste verte.
4. Chaîne fiscale : `php artisan fiscal:verify-chain --all` OK.
5. Relecture adverse : **deux agents en lecture seule, en parallèle** :
   - un relecteur de code, qui cherche défauts, régressions et cas oubliés ;
   - un relecteur « œil du propriétaire », qui confronte chaque remarque à ses mots exacts.

   Un constat P0 ou P1 rouvre la vague (correction, puis retour à l'étape 1). Au-delà de 3 cycles sur le même
   défaut, on remonte au propriétaire.
6. Commit par chemins explicites. Mise à jour de `PROJECT_BRAIN.md` §2 et §3, et des statuts dans
   `reports/caisse-remarques-2026-10-03/`.

**Interruption** (limite d'usage, coupure) : commit `wip(vague-N): …`, puis manifeste
`reports/caisse-remarques-2026-10-03/INTERRUPT_<vague>.md` contenant le dernier SHA vert, la tâche en cours et
la suivante. Mise à jour du BRAIN §2.

## §G — Portes du propriétaire

| Porte | Objet | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| G1 | R-020, R-023, R-025, R-033, R-035 dans `pos-wizard.js` et `.css` (zone gelée) | Propriétaire | Contreseing d'un LOCK | `plans/LOCK_POS_WIZARD_REMARQUES_2026-10-03.md` (brouillon préparé par Claude) | EN ATTENTE |
| G2 | Américaine (R-034), portion 1,90 € (R-036), cornichon (R-028, D1), agencement de la barre (R-006) | Propriétaire | Décision écrite | Réponse en session, puis `PROJECT_BRAIN.md` §6 | EN ATTENTE |
| G3 | Navigateur, ssh et GitHub indisponibles dans cette session | Propriétaire | Relancer Claude Code depuis le Terminal | Vagues 4 et 5 débloquées | EN ATTENTE |
| G4 | R-052 : 3 à 4 cm demandent une image tramée (ESC/POS plafonne à ×8, environ 2,4 cm) | Propriétaire | « ×8 suffit » ou « image tramée » | Réponse en session | EN ATTENTE |
| G5 | Tiroir (R-047), afficheur SAGA (R-074), ressenti de vitesse (R-007) | Propriétaire, au comptoir | Essai sur le matériel réel | Retour en session | EN ATTENTE |
| G6 | Données de production (R-024 à R-032, R-079, R-068) | Claude (lecture ssh) après G3 | Requêtes en lecture seule | `reports/caisse-remarques-2026-10-03/PROD_CHECK.md` | EN ATTENTE |

Les vagues 1 à 3 ne dépendent d'**aucune** porte. Elles démarrent immédiatement.

## §F — Définition de « fini »

1. Chaque tâche T-1.x à T-3.x a son test cité, vert, et ce test **mord** : en restaurant l'ancien comportement,
   il échoue.
2. Les suites complètes sont vertes, avec 0 ligne de diff sur les zones gelées et la chaîne fiscale OK.
3. Deux relectures adverses consécutives ne trouvent aucun P0 ni P1.
4. La preuve visuelle (vague 4) est faite, ou explicitement portée par G3 dans le rapport final.
5. Le statut des 79 remarques est réécrit, chacune FAIT, EN PORTE (avec son numéro) ou NON VÉRIFIABLE avec sa
   raison.
6. `PROJECT_BRAIN.md` est à jour, et le propriétaire reçoit un rapport court : ce qui a changé, ce qui attend sa
   main.
