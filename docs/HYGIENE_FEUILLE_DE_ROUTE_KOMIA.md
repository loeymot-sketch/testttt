# Cayenne Hygiène — feuille de route issue de l'extraction Komia (2026-10-17)

Source : `~/Downloads/komia-extraction/` (compte de démonstration Komia **presque vide** : pas de catalogue, pas de seuils,
pas de parcours après « Suivant »). On retient donc de Komia sa **structure** et ses **manques** ; les durées, seuils et
règles viennent du GBPH / de la réglementation et restent **modifiables par le restaurant** (son PMS fait foi).

## Principes communs (tous les modules)
1. **Journal append-only** : une ligne n'est jamais modifiée ni effacée ; une erreur s'**annule** (`voided_*`, motif ≥ 3 caractères).
2. **Contexte copié** à chaque ligne (nom, seuils, opérateur, fuseau) : renommer ou changer un seuil ne réécrit pas l'histoire.
3. **Verdict calculé par le serveur** (une règle, une horloge) ; l'écran n'affiche qu'un aperçu.
4. **Écart ⇒ action corrective obligatoire** (liste fermée + commentaire si « autre »).
5. **Rejouable** : `client_ref` unique par branche (coupure réseau ≠ doublon). Lots atomiques.
6. **Qui ?** Une tablette partagée : `operator_user_id` (liste du personnel) sinon l'utilisateur connecté.
7. **Preuve photo** optionnelle (BL, étiquette, bandelette) : image ré-encodée côté serveur (EXIF/GPS retirés), servie derrière authentification.
8. **Séparable** : `config/hygiene.php`, tables `hygiene_*`, API `/admin/hygiene/*`, composants `components/admin/hygiene/`.
   Aucune dépendance vers la caisse, la compta ou les commandes (hors l'étiquette DLC qui vit dans `labels`).
9. **Un seul référentiel d'équipements** (frigo, congélateur… et friteuse), comme Komia, mais chaque écran ne montre que les siens.

## Komia → Cayenne
| Komia (vu) | Manque constaté | Cayenne |
|---|---|---|
| DLC : 11 « types de préparation », jours seulement | pas d'heures, pas de catalogue | **FAIT** — heures/jours, catalogue ~80 produits, lots à surveiller (jeté/utilisé) |
| Température : froid/chaud, ±0,5, valeur préremplie | pas de seuil, pas d'action corrective | **FAIT** — seuils par équipement, verdict serveur, action corrective, journal |
| Tâches : 5 fiches à interrupteurs, « 0/5 » rouge | pas d'horaire, pas de preuve, pas de « tout cocher » | **FAIT** (slice 1) — `/admin/hygiene/tasks` |
| Réception : 3 températures + BL + photo | pas de seuil, pas de conforme/non conforme | **FAIT** (slice 2) — `/admin/hygiene/reception` |
| Traçabilité : photo + lot + DLC + BL | pas de recherche de lot, pas de rappel | **FAIT** (slice 3) — `/admin/hygiene/trace`, fiche de rappel de lot |
| Huile : « changement » seulement | pas de test polaire, pas de seuil | **FAIT** (slice 4) — `/admin/hygiene/oil` |
| Historique : 6 listes | pas de rapport DDPP, pas de tableau de bord, pas d'export | **FAIT** (slice 5) — accueil `/admin/hygiene/home`, dossier `/admin/hygiene/report` (PDF + CSV) |
| Stock / Pertes / Production | — | existent déjà dans Le Cayenne (stock, repas/pertes) → liens depuis l'accueil, pas de doublon |
| Planning / Pointage | — | hors périmètre hygiène (module RH) — à traiter à part |

## Tranches
- **Slice 0 — socle** : opérateur, pièces jointes, helpers communs, styles `hy-*`.
- **Slice 1 — Tâches de nettoyage** : modèles (fréquence quotidienne/hebdo/mensuelle, horaire, produit & méthode), fiches à cocher,
  « tout cocher », point non fait ⇒ motif, compteur du jour, catalogue de démarrage burger.
- **Slice 2 — Réception** : fournisseurs (table `suppliers` existante), 3 familles de température avec seuils, contrôles visuels,
  décision (accepté / avec réserve / refusé), photo du BL.
- **Slice 3 — Traçabilité** : lot + DLC fournisseur + photo d'étiquette, rattachement à la réception, **recherche par lot** et
  **fiche de rappel** (réception → étiquettes de préparation issues du lot).
- **Slice 4 — Huile** : friteuses (équipement), test polaire (limite légale 25 %, décret 2008-1463), température du bain, filtrage, vidange.
- **Slice 5 — Accueil + rapports** : tuiles à compteurs d'alerte, historique unifié, **dossier de contrôle sanitaire** PDF + CSV par période,
  taux de conformité.

## À valider par le restaurant (non inventé)
Durées DLC, fréquences de nettoyage, seuils de réception : valeurs de départ prudentes (GBPH restauration, arrêté du 21/12/2009),
toutes éditables. Références réglementaires à sourcer par le restaurant : règlement (CE) 852/2004, arrêté du 21/12/2009, décret 2008-1463.

## État au 2026-10-17
Slices 0 à 5 livrées et testées (PHPUnit `tests/Feature/Hygiene`, Vitest `tests/js/hygiene*.spec.js`), vérifiées visuellement (harnais isolé), revue adverse corrigée. **Déployées en production le 2026-10-17 (HEAD `cfb659a3b`)** : 4 migrations `hygiene_*` passées, chaîne NF525 intacte, healthz vert. Non éprouvé : tablette réelle, imprimante Brother, rendu PDF réel.

## Lot « méga-fichier » (2026-10-17, local — non déployé)
Exécution de `~/Downloads/mega-fichier/MEGA_FICHIER_AMELIORATION_CAYENNE_HYGIENE.md` (§8, P0 puis P1). Le fichier a été établi par une autre session
sur une prod encore vide : plusieurs « manques » y étaient en réalité des modules construits mais non configurés ; chaque ligne a été recoupée avec le code.

| Méga-fichier | Réalité vérifiée | Résultat |
|---|---|---|
| P0.1 heure et DLC imprimées (3 horaires) | tests à 03 h 55 / 14 h 22 / 23 h 50 + passage à l'heure d'hiver | **défaut réel trouvé** : `addHours` (Carbon 2) = heures murales → « 24 h » durait 25 h la nuit du recul d'horloge ; corrigé (`addRealHours`) |
| P0.2 statut d'impression réel | déjà : `printed` seulement sur trame d'achèvement de l'imprimante, sinon `unconfirmed` / `failed` (labelPrinter.js) | rien à changer ; tablette + Brother réelles restent à éprouver |
| P0.3 fiches sans famille / règles divergentes | pas d'outil | **fait** : « Contrôle du catalogue » (famille probable du catalogue, fiches voisines : doublon / conservation différente) |
| P0.4 allergènes à renseigner | badge par fiche seulement | **fait** : liste dédiée dans le contrôle ; jamais deviné |
| P0.5 « Rien tracé » en vert | — | fait avant (tuiles `idle`) |
| P0.6 source de chaque durée + validation | seulement « durées vérifiées » pour les durées inhabituelles | **fait** : `duration_source`, validation par le responsable (qui, quand), « valeur de départ » = À VALIDER, changer un chiffre fait tomber la validation |
| P1.1 températures | équipements, seuils, rouge hors seuil, action corrective, historique, export : existaient | **ajouté** : équipements chauds (bain-marie ≥ 63 °C), courbe 14 jours par équipement |
| P1.2 huile | existait ; | « changement à prévoir » via les rappels |
| P1.3 alertes et rappels | absent | **fait à l'écran** (`Reminders`, accueil « À traiter maintenant », tuile en alerte). **Pas de notification poussée / SMS** : aucun canal configuré (FCM en échec en production) |
| P1.4 import CSV | absent | **fait** : modèle, aperçu sans écriture, jamais d'écrasement |
| P1.5 états de DLC | 5 sur 8 | **fait** : + Congélation (≤ −18 °C), Repas témoin (0 à +3 °C), Réception ; jamais de recongélation d'un décongelé non cuit |
| P1.6 lecture du BL par photo | photo seulement | **non fait** : exige une reconnaissance de texte (hors périmètre « 0 cloud ») ; « Tout cocher » et compteurs sur tuiles existaient |
| P1.7 premier salarié / liste vide muette | — | **fait** : « moi » + « Ajouter un employé » |
| Komia §3 | duplication en un clic ; saisie de durée | **fait** : « Dupliquer » (copie à valider), durée sélectionnée au toucher ; bouton d'action sur chaque tuile |

Hors de ce lot (P2/P3 du méga-fichier) : planning/pointage/congés, stock théorique / inventaire / production / commandes reliés au POS, recettes et coût matière,
PWA hors-ligne, scan code-barres, produit vendable seul. Non éprouvé : tablette réelle, imprimante Brother, notification poussée.

## Lot « rapport Komia phase 3 » (2026-10-18)
Source : `~/Downloads/komia-phase3/RAPPORT_KOMIA_PHASE3.md` + `TABLEAU_PARITE.md` (analyse de Komia par Claude dans Chrome, sans salarié : aucun flux d'écriture observé).
Chaque écart du rapport a été recoupé avec le code et la production AVANT d'être codé.

| Écart du rapport | Réalité vérifiée | Résultat |
|---|---|---|
| P0 « imprimée » alors que l'imprimante est « non connectée » (C08) | `printed` n'est écrit que sur confirmation de page par l'imprimante (`labelPrinter.js`), sinon `unconfirmed`/`failed` ; un historique garde les impressions passées. Prod : 32 `printed`, 2 `failed`, 0 incohérence | **non confirmé** (lecture d'un historique pris pour un état courant) |
| P1 « à jeter aujourd'hui / demain » absent | la liste « À surveiller » (lots expirés ou proches) avec « Jeté ✓ » / « Utilisé » existe sur l'écran DLC | **déjà fait** |
| P1 recherche par lot / rappel | `GET /hygiene/trace/recall` existait | **déjà fait** |
| P2 récurrence mensuelle | `frequency` daily/weekly/monthly existait ; annuelle absente | partiel, non repris |
| P0 alertes hors écran | aucun rappel ne sortait de l'écran ; FCM sans jeton (0 en prod), SMTP Brevo configuré en prod | **fait** : e-mail aux responsables (`hygiene:notify` /5 min, désactivé par défaut, plage d'envoi, relance espacée et plafonnée, journal en ajout seul, essai à la demande, réglage réservé à `settings`) |
| P0 hors ligne | relevés perdus si le réseau tombe | **fait** pour les températures : file locale, heure de la MESURE gardée (bornée, ≤ 72 h), dit « saisi hors ligne » au journal, rejeu idempotent, refus jamais jeté en silence. **Reste** : huile, réception, traçabilité, nettoyage (photos à téléverser d'abord) |
| P1 « Qui ? » sans code | n'importe qui signait pour n'importe qui | **fait** : code personnel facultatif par employé (haché), jeton signé de 10 min (le code n'est jamais gardé, y compris hors ligne), 5 faux codes = blocage 10 min, écran « Codes de l'équipe » |
| P1 relevé de suivi après écart | « Nouvelle mesure après quelques minutes » n'était qu'un libellé | **fait** : nouvelle mesure annoncée, attendue 30 min après, rappelée (écran + e-mail) tant qu'elle manque |
| Demande n°1 du propriétaire : favoris | absents (la sélection multiple + panier existait) | **fait** : étoile, onglet « Favoris », « Préparer toute ma liste » |
| Demande du propriétaire : tickets d'une commande attachés (Brother) | le manuel Brother n'a **aucune demi-coupe** ; seulement « couper toutes les n étiquettes » | **fait en option** (une coupe par commande + pointillé entre tickets) ; « dernier travail envoyé » et « test de coupe » pour départager tablette et machine |

**Relecture adverse du lot (2026-10-18)** — deux relecteurs indépendants (backend, interface) : ~45 constats, chacun revérifié dans le code puis corrigé avec test + mutation. Les plus graves :
la fenêtre du jeton « Qui ? » n'était jugée que sur la mesure la plus ancienne du lot ; la période d'un relevé venait d'un champ envoyé par la tablette (un relevé « du soir » posté à 10 h taisait le rappel du soir) ;
les annulations ignoraient le code personnel ; l'envoi hors ligne relisait la saisie APRÈS l'attente (relevé perdu en silence ou doublé) ; « moi » n'était pas figé dans la file (un autre compte qui rejouait signait à sa place) ;
un lot refusé en bloc perdait aussi ses relevés valides ; la file ne se vidait que sur l'écran Températures ; l'expiration du code ramenait en silence sur le compte partagé ; une panne de messagerie de 15 minutes abandonnait l'alerte
pour la journée ; un second écart le même jour n'était jamais rappelé après le plafond d'envois ; le dossier de contrôle ne distinguait pas un relevé saisi hors ligne. **Résiduel assumé** : annuler sans désigner personne reste signé du compte
connecté (pas de code imposé à tous) ; le jeton de signature (10 min) reste dans la file hors ligne (le code, jamais) ; la liste « Qui ? » n'a pas de navigation clavier ; le brouillon n'est écrit dans la file qu'après l'échec de l'envoi (≤ 30 s) ;
V1 mono-branche (planificateur et alertes sur `hygiene.branch_id`) ; un ticket de plus d'1 m de papier avec pointillé pourrait dépasser la limite du rouleau continu.

**Non fait** (décisions ou matériel) : WhatsApp (aucune preuve que Komia le fasse ; aucun fournisseur choisi, paquet de décision du 2026-08-12 : d'abord PWA/push) ; sonde Bluetooth (le rapport lui-même contredit Bluetooth / Dragino + passerelle 4G : modèle exact à fixer) ; rappels par équipement ou par service ; assignation de tâches ; bibliothèque d'équipements typés ; PWA ; hors ligne des autres écrans ; états « mariné » et « étiquette vierge ».
**Non éprouvé** : tablette et Brother réelles ; envoi SMTP réel (le code journalise l'échec et le dit ; la messagerie locale pointe sur un hôte factice).

## Reste à faire (non démarré)
- Notification poussée / SMS / WhatsApp des rappels : l'e-mail est fait (2026-10-18) ; les autres canaux se branchent dans `AlertNotifier::deliver` sans toucher au calcul des rappels — fournisseur à choisir.
- Capteurs / sondes connectés (relevé automatique) : la colonne `source` des relevés est prête (`manual` aujourd'hui).
- Alerte « produit non tracé depuis x jours » PAR PRODUIT et alerte de stock bas par produit (idées Komia) : demandent une colonne par produit (aujourd'hui : « rien tracé depuis N jours » global).
- Lecture automatique du bon de livraison par photo (OCR) ; boutique de rouleaux .
- Planning et pointage : module RH distinct, hors hygiène.
- Application « Cayenne Hygiène » autonome : extraction de `config/hygiene.php`, `hygiene_*`, `/admin/hygiene/*`, `components/admin/hygiene/`.

## Lot « rapport ultra-deep » + psychologie (2026-10-18)

**Livré** (commits `61fddb18b`, `ff6617fe5`, `cb4d090ba`) : « Qui ? » signé sur les étiquettes DLC ; engagement obligatoire (interface) sur la validation d'une fiche de nettoyage (`attested_at`, colonne CSV + PDF) ; guide de mise en place déduit des journaux (`SetupProgress`, 5 étapes obligatoires + 3 facultatives, une seule « prochaine étape », félicitation, masquage mémorisé) ; bannière « Journée en règle » chiffrée, jamais à côté d'un rappel ; messages de validation honnêtes (« plus que N », « complet, merci ») ; repas témoin avec service Matin/Midi/Soir obligatoire (jamais deviné, imprimé, journalisé, au CSV et au PDF).

**Migrations non déployées** : `2026_10_18_140000_add_attested_at_to_hygiene_task_completions`, `2026_10_18_150000_add_meal_slot_to_label_print_logs`. Le code tolère la fenêtre entre le reset et `migrate` (colonnes écrites seulement si renseignées).

**Non fait / assumé** : « mariné » et étiquette vierge avec « Qui ? » (pas de besoin exprimé) ; engagement non exigé par l'API (nullable : l'absence se lit « non » au dossier) ; la tuile DLC passe « à compléter » dès qu'une étiquette expire sous 24 h, ce qui retient souvent « Journée en règle » (décision produit à prendre, pas un défaut de ce lot) ; `LABELS_BRANCH_ID` et `HYGIENE_BRANCH_ID` sont deux variables distinctes (sans effet en V1 mono-branche).

## Inventaire Komia par l'extension Chrome (2026-10-18) — recoupé avec Cayenne

Source : rapport de l'extension (appli, aide, site, stores). **À re-vérifier avant d'agir** : seul ce qui est marqué « vu » a été observé dans l'appli ; « aide » et « site » sont des déclarations de Komia, et le rapport relève lui-même 9 contradictions entre ses sources. La session Administrateur (configuration, objectifs HACCP, étiquettes, employés, paie) n'a **pas** été vue.

**Décision d'interface (propriétaire, 2026-10-18)** : une seule entrée dans la barre latérale ouvrant un hub de tuiles. Komia fait pareil sur mobile (tuiles avec coche verte ou « 0/5 » rouge) et propose 12 interrupteurs « modules activables » : le hub doit pouvoir masquer un module.

**Écarts réels, par ordre de valeur**
1. Relevés d'un autre type : température à cœur (cuisson), refroidissement rapide (cellule), livraison, buffet, pH du riz. Le refroidissement rapide est une exigence courante du GBPH : seuils à faire valider par le restaurant. Cayenne n'a que froid, chaud, suite d'écart.
2. Sonde connectée : l'aide de Komia parle de capteurs + passerelle 4G, mesure toutes les 10 min, alerte e-mail et téléphone (le site dit « Bluetooth » : contradiction non tranchée). Cayenne a la colonne `source`, pas de point d'entrée machine.
3. Tâches : Komia annonce ponctuelle, hebdo, mensuelle, annuelle, personnalisée, équipe ou employé, photo de preuve. Cayenne : quotidienne, hebdo, mensuelle, sans assignation.
4. Étiquettes : « sans date » (congelé), étiquette vierge, types de DLC activables, étiquettes de vente (prix, code-barres). Cayenne n'a ni l'un ni l'autre.
5. Documents : drive documentaire (PMS, procédures) et formations d'hygiène du personnel. Cayenne a des pièces jointes, pas de rangement par document ni d'échéance de formation.
6. Objectifs HACCP quotidiens ou hebdomadaires réglables, avec rappels répétés. Cayenne a des heures de rappel dans `config/hygiene.php`, pas réglables à l'écran.
7. « Employé requis » partout. Cayenne signe du compte connecté par défaut ; un mode tablette pourrait l'imposer.
8. Réception « simplifiée » (moins d'étapes) ; scan du BL en bêta (OCR : hors périmètre « 0 cloud »).

**Où Cayenne est déjà devant** : seuils et verdict côté serveur, action corrective avec preuve par nouvelle mesure, code personnel, hors ligne des températures, dossier PDF avec engagement, alertes e-mail, guide de mise en place, imprimante pilotée depuis le web (Komia : iOS/Android seulement), pas de gamification faussée.

**Hors hygiène** : planning, pointage, congés, paie, multi-sites, caisse connectée, commandes fournisseurs. Module RH et stock à décider à part.

## Hub unique « Hygiène & étiquettes » + plan « parité Komia » recoupé avec le code (2026-10-18)

**Livré** (commit « un seul bouton… ») : la barre latérale passe de 12 lignes (groupes Étiquettes + Hygiène) à UNE, placée sous POS et allumée sur tous les écrans du module ; l'accueil Hygiène est le hub (tuiles du jour + « Autres outils » : étiquettes de commande, imprimante, alertes, codes d'équipe) ; chaque écran du module pose une barre « ← Hygiène & étiquettes » et remet `.db-main` en haut (le routeur ne scrolle que la fenêtre : une page ouverte depuis le bas du hub s'ouvrait défilée de 658 px, bouton de retour hors de vue). Les routes sont inchangées. Preuves : Vitest 5399, mutations rouges puis restaurées, e2e réel `hygiene-hub-2026-10-18`.

**Le plan « Méga plan Cayenne Hygiène — parité fonctionnelle Komia » (document partagé) a été écrit SANS accès au code** (il le dit : « pile de Cayenne non vue »). Recoupé ligne à ligne :

| Le plan dit | Le code dit |
|---|---|
| P0 : afficher le vrai statut d'impression | déjà : `printed` seulement sur trame d'achèvement, sinon `unconfirmed` / `failed` |
| DLC « à compléter : repas témoin avec moment » | fait le 2026-10-18 (service Matin/Midi/Soir obligatoire, jamais deviné) |
| allergènes « à ajouter sur l'étiquette » | déjà : les 14 allergènes, ou « sans allergène », imprimés |
| « Rien tracé » affiché en vert | la tuile est neutre (`idle`), jamais verte |
| seuils, courbe 14 j, action corrective, polaires de l'huile | faits |
| import CSV produits, « Tout cocher », compteurs | faits |
| alertes e-mail | faites ; push / SMS / WhatsApp non |
| Planning, pointage, congés, paie | **absents de Cayenne** (aucune route, aucune table) : module RH distinct, décision propriétaire |
| Stock, pertes, production | existent hors du module (stock, repas/pertes) : liens, pas de doublon |

**Reste réel du plan** (déjà listé plus haut, rien de nouveau) : relevés « refroidissement rapide » et « température à cœur », étiquettes « sans date » et vierge, tâches ponctuelles/annuelles/attribuées, documents et formations, sondes connectées, PWA/hors ligne des autres écrans. Les seuils de refroidissement sont une valeur réglementaire à faire valider par le responsable avant affichage « conforme ».

## Cycle test-e2e du hub — 3 relecteurs adverses indépendants (2026-10-18)

Trois relecteurs (visuel/UX, accessibilité/droits/traduction/navigation, robustesse selon les états de données) ont attaqué le code SERVI, sans rien corriger. 27 constats ; chacun recoupé dans le code avant d'agir.

**Corrigés (avec test qui échoue sans le correctif)** :
- P0 : une réponse 200 vide ou HTML (portail captif, proxy) affichait « Tout est en ordre ✓ » sur AUCUNE donnée → réponse sans la forme attendue = échec (erreur au premier chargement ; tuiles gardées + « chiffres périmés » ensuite).
- P0 : l'écran Nettoyage comptait une fiche validée INCOMPLÈTE « faite » et disait « Bravo » pendant que le hub disait « 0/1 » → même définition partout.
- P1 : la barre « retour au hub » redevenait un lien nu de 24 px en chargement direct (F5, favori) sur les écrans d'étiquettes (hygiene.css non importé) ; les e2e partaient du hub, ce qui masquait le défaut → la barre porte sa feuille ; e2e en chargement direct.
- P1 : une étiquette DLC qui expire dans la journée (20 produits du catalogue durent 24 h) retenait « Journée en règle » avec un bouton « Imprimer » absurde → seule une étiquette DÉPASSÉE appelle une action. (Décision produit jusque-là reportée : tranchée par les preuves.)
- P1 : le guide de mise en place passait avant « À traiter maintenant » → l'urgent d'abord.
- P2 : tuile DLC verte « Aucune alerte » au premier jour → neutre ; « chiffres périmés » dès le premier échec (et non le deuxième) ; la ligne active est exposée (`aria-current`) ; cartes Alertes/Codes d'équipe masquées sans le droit « settings » (impasse 403 pour 59 caissiers + 22 responsables) ; tiroir mobile qui restait ouvert devant le hub ; rappels de nettoyage regroupés au-delà de 3 ; URL en majuscules ; nom du module cohérent ; appel du dossier de contrôle ; hauteurs des cartes d'outils.
- Optimisation : plus d'appel au serveur écran masqué, rafraîchissement au réveil / retour du réseau si les chiffres ont plus de 15 s, jamais deux appels en même temps.

**Constatés, NON corrigés (préexistants au hub, hors de sa portée)** : double allumage de la barre après retour navigateur (classe `active` posée au clic, partout) ; focus clavier laissé sur `body` et titre d'onglet fixe « Le Cayenne » après navigation (toute l'admin) ; contraste de la ligne active 3,27:1 et bouton du tiroir « Fermer » de 36 px / mal nommé (coquille partagée) ; Chef / Waiter (droit `kitchen-display-system` seul) acceptés par l'API mais sans entrée dans l'interface ; « Codes de l'équipe » : 62 lignes sans recherche, plafond silencieux à 200 ; une alerte levée par « produits jetés » reste jusqu'à une nouvelle mesure (par conception) ; bain-marie non relevé = tuile « à faire » toute la journée alors que le rappel du matin l'en dispense ; texte de la barre de retour en français en dur (en anglais seul le menu change). Non vérifié : lecteur d'écran réel, tablette physique, Safari/Firefox, mode sombre, passage de minuit.

**Tour 2 (2 relecteurs : navigateur, code et tests) : 0 P0, 0 P1 ; les 10 correctifs du tour 1 tiennent.** Régressions de mon propre commit trouvées et corrigées : (1) `:aria-current` passé au router-link ÉCRASAIT celui du routeur sur toutes les autres lignes de la barre (le test avec RouterLink simulé cachait le défaut → test avec le VRAI routeur) ; (2) la note « fiche incomplète à reprendre » s'affichait en vert, faute de style `.hy-note.is-todo` ; (3) « 24 h » se coupait en laissant « h » seul. Préexistants alignés sur la même définition que les rappels : une fiche créée après son heure limite n'est plus « en retard » sur la tuile Nettoyage ; « Rien à faire » ne dit plus « Tout est fait » ; l'ordre des fiches nommées dans le rappel groupé suit l'écran. 6 mutants qui survivaient ont des tests (statut et type d'étiquette comptés « en cours », pluriel, garde écran masqué au réveil, titre de section qui fermait le tiroir, ORDER BY du rappel).

**Choix assumé** : une étiquette DLC « non confirmée » ou « à vérifier » est DITE sur la tuile (« dont 1 non confirmée »), mais ne retient pas « Journée en règle » : aucune action de l'écran ne la lève (on ne peut que la jeter ou la réimprimer), donc la compter « à compléter » ferait attendre l'expiration de l'étiquette — le piège que la relecture du tour 1 a fait corriger.
**Non corrigé** : un seul accroc réseau remplace la bannière « 1 domaine en alerte » par « Réseau indisponible » pendant 60 s (la tuile et « À traiter maintenant » restent) ; redimensionnement 390→1280 px avec le tiroir ouvert ; titres des écrans d'étiquettes en « Capitalize ».

**Tour 3 : 1 P1 (régression de ma correction du tour 2) — corrigé à la racine.** Le « en retard » d'une fiche de nettoyage vivait en TROIS exemplaires (écran du jour, tuile de l'accueil, rappels) plus l'indicateur « après l'heure » d'une validation ; en alignant deux, j'avais déplacé la contradiction au lieu de la supprimer (tuile « à faire », écran « en retard »). Désormais `TaskSchedule::isLate()` est l'UNIQUE définition (heure limite passée ET fiche existant déjà à cette heure ; ligne ancienne sans date de création = attendue, ce qui évite aussi un 500 de l'accueil). Au passage : tuile « Rien à faire » neutre et non verte ; message de l'écran Nettoyage vrai quand un plan existe ; « à échéance » / « à vérifier » insécables.

**Tour 4 (relecteur navigateur : ~175 états aléatoires croisés ; relecteur code : « un fait, deux définitions ») : 0 P0 strict, 5 P1 préexistants — tous corrigés à la racine, un test par règle.**
1. **Étiquette réservée sans verdict depuis plus de 10 min** : l'écran DLC la surveillait (« EXPIRÉE »), l'accueil disait « Journée en règle » et le dossier « 0 échue non sortie » → `LabelPrintLog::scopeWatchable()` est l'UNIQUE définition (écran, tuile, rappels, dossier).
2. **Écart de température traité mais nouvelle mesure pas encore due** : l'écran disait « nouvelle mesure à faire avant 19:27 », l'accueil « Tout est relevé » → la tuile Températures lit la même source (`TemperatureFollowUp`) et reste « à faire ».
3. **Huile : l'action « Huile changée » ne soldait rien** (rappel « à changer » et e-mails de relance continuaient ; tuile rouge « 1 écart » après une vidange saisie ; tuile verte pour des polaires « à surveiller ») → `OilRules::isOpen()` est l'UNIQUE règle (tuile, rappel, écran).
4. **Dossier de contrôle, « Aujourd'hui » à 100 % vert (« 1 sur 1 ») quand l'accueil disait 1/3** → la journée en cours n'entre plus dans le taux ; elle est dite à part, avec le même chiffre que l'accueil, à l'écran et dans le PDF ; aucun jour écoulé = « — ».
5. **Dossier : un frigo ou une fiche créé puis supprimé le même jour comptait « manqué »**, définitivement, dans un document d'inspection → les jours de création et de retrait (heure inconnue) ne comptent que s'ils sont couverts, jamais « manqués ».
Aussi : une ligne sans date de création ne fait plus tomber l'accueil (équipements) ; commentaire faux de `TaskSchedule` corrigé.
**Constatés, non corrigés (P2)** : une étiquette « à vérifier » (hors ligne) laisse la tuile verte (aucune action de l'écran ne la lève vraiment) ; « N étiquettes » compte des lignes à l'accueil et des lots à l'écran ; le dossier compte les échues sans borne de 30 jours alors que l'accueil et l'écran s'arrêtent à 30 ; étape du guide « plan de nettoyage » = fiches actives alors que la tuile compte aussi les inactives ; écart traité sans suivi (produits jetés) ou réception refusée = tuile « à traiter » toute la journée sans rappel ; 72 h dupliquées en JS ; « heure de masquage » du dossier approchée par `updated_at`.

**Tour 5 (2 relecteurs) : 1 P0 + 2 P1, TOUS des régressions de mes propres correctifs du tour 4 — corrigés, un test par défaut.**
1. La phrase « Aucun jour écoulé dans la période » (écran ET PDF d'inspection) se déduisait de `expected === 0` : fausse pour un frigo créé hier, une fiche seulement hebdomadaire ou rien de configuré → le SERVEUR fournit `period.past_days` ; sinon « Aucun jour attendu dans la période ».
2. « Huile changée » déclarée sur un test : l'écran disait « Conforme » ET « Aucune vidange enregistrée », le dossier comptait 0 vidange → `HygieneOilControl::countsAsChange()` est l'UNIQUE définition (écran, tuile, rappel, dossier).
3. Dossier DLC : « échues non sorties » dépassait le total d'étiquettes et le geste « Jeté » sur une réservée n'apparaissait ni dans le PDF ni dans le CSV → la base entière du bloc passe par `scopeWatchable`.
Aussi : carte Huile du dossier avec sa ligne « aujourd'hui » ; les 5 mutants que le relecteur avait prouvés survivants (retrait seul à l'accueil, jour de retrait d'une fiche, `>=` des vidanges simultanées, échéance la plus proche, grâce de 10 min) ont maintenant des tests (dont un qui ne mordait pas : équipement retiré la VEILLE n'atteignait jamais le bloc « aujourd'hui »).
**Leçon** : chaque correctif de « un fait, deux définitions » doit chercher les AUTRES endroits qui disaient la même chose (ici : la déduction côté écran et le PDF), sinon il déplace l'incohérence.
**Non corrigé (P2/P3)** : la tuile DLC compte des « non confirmées » à plus de 72 h que l'écran « À surveiller » ne liste pas ; « Journée en règle » reste à côté d'une étiquette « non confirmée » (choix assumé) ; `changedSince` de l'huile est calculé trois fois (fenêtres du jour / 60 j / 400 j, équivalentes aujourd'hui) ; période entièrement future : « — » sans explication.

**Tour 6 (2 relecteurs, > 330 états aléatoires dont un oracle indépendant sur 4 périodes) : 0 écart de comptage ; 1 P1 + 2 P2 de ma propre correction du tour 5 — corrigés.** Une seule huile changée comptait DEUX vidanges dans le PDF d'inspection quand l'opérateur déclarait « Huile changée » sur le test puis saisissait aussi la vidange (litres, marque) : déclaration et ligne du même jour sur la même friteuse = un seul changement. Carte Huile du dossier : plus de « Aujourd'hui : 0 sur 0 » pour un restaurant sans friteuse (domaine facultatif). Borne haute des « échues non sorties » désormais testée (une étiquette qui expire dans l'heure n'est pas échue).
**Constaté, non corrigé (P3)** : « Conforme » à côté de « Dernier test : 28 % » sur la carte Huile (voulu : écart soldé par le changement, journal conservé) ; message « éléments créés récemment ou rien de configuré » imprécis pour une fiche hebdomadaire non due ; une étiquette réservée devenue « surveillée » figure au journal DLC du dossier comme « Préparation » sans mention d'incertitude (comme déjà une « non confirmée ») ; `last_change` de l'API peut être une ligne de type test ; clause redondante `corrective_action !== 'oil_changed'` dans `OilRules::isOpen`.

**Tour 7 : navigateur ET code à 0 P0 / 0 P1 sur `4d89eb88d`** (12 cas de vidange identiques dans le JSON, le PDF et le CSV ; 125 états × 4 périodes contre un oracle PHP indépendant : 0 écart). Limites assumées (P3) : une déclaration à 23:50 et la vidange saisie à 00:10 comptent deux changements ; deux changements distincts le même jour avec une déclaration au milieu en comptent un.
**Tour 8 (relecteur navigateur NEUF, jamais vu le projet) : 2 P1 que les tours précédents n'avaient pas vus — corrigés.**
1. **Le nombre d'étiquettes DLC dépassées avait TROIS unités** : l'accueil et le rappel comptaient des lignes de journal (« 3 »), l'écran DLC des lots (« 2 lots »), le PDF « 3 » avec dessous « 5 étiquette(s) » pour les mêmes 5 étiquettes physiques → l'unité est l'ÉTIQUETTE PHYSIQUE (copies) partout (tuile, rappel, dossier : étiquettes, échues, jetées, utilisées) ; le nombre d'impressions est dit à côté.
2. **Taux arrondi à « 100 % » vert avec une fiche non faite** (2189 sur 2190, soit le dossier annuel d'un inspecteur, avec un bandeau rouge juste dessous) → `rate()` ne dit 100 que si rien ne manque (99,9 sinon).
Aussi : un suivi d'écart HÉRITÉ de la veille (aucun relevé aujourd'hui) est maintenant annoncé sur la carte de l'écran Températures avec « Mesure impossible » (avant : le rappel de l'accueil le demandait, l'écran se taisait) ; la tuile DLC n'annonce plus des « non confirmées » à plus de 72 h que l'écran « À surveiller » ne liste pas (une anomalie reste signalée tout de suite).
**Non corrigé, à ARBITRER par le propriétaire** : (a) « Journée en règle ✓ » reste affichée à côté d'une étiquette « non confirmée » (aucune action de l'écran ne la lève : la compter bloquerait la journée jusqu'à l'expiration) ; (b) une étiquette échue depuis plus de 30 jours et jamais « jetée » reste en rouge dans le dossier sans qu'aucun écran n'offre de la jeter (au-delà de 30 jours elle « ne vit plus qu'au journal »).

**Tour 9 (2e relecteur NEUF, 115 appels d'outils, calcul indépendant du dossier sur 9 périodes : exact) : 4 P1 réels — corrigés, un test chacun.**
1. **Un « − » tapé au clavier était jeté EN SILENCE** : « -2 » dans un frigo (0 à +4 °C) s'enregistrait « +2,0 °C — Conforme » alors que −2 °C est « trop froid » (le commentaire du code le disait : « un moins en tête est toléré »). Un moins tapé bascule désormais le bouton ± sur « − » et sort de la saisie (relevés de température, réception, huile ; un champ non signé comme les litres n'est pas réécrit).
2. **Renommer un équipement ou une fiche DÉJÀ masqué inventait des jours « non faits » dans le dossier d'inspection** (fiche à 100 % → 65 %, dix dates fantômes), car le dossier date le masquage par `updated_at` → `KeepsHideMoment` : une écriture sur un élément qui reste masqué ne touche plus `updated_at` (celle qui le masque ou le réactive, si).
3. **La surveillance DLC coupait les lots urgents** : plafond unique de 300 lignes trié par échéance croissante ; avec un arriéré d'échus (restaurant qui n'utilise pas « Jeté »), l'accueil disait « 5 à échéance sous 24 h » et le tableau de bord « Sous 72 h : 0 » → deux plafonds séparés (200 échus les plus récents + tous les à-venir) et `meta.expired_hidden` DIT ce qui n'est pas affiché (écran DLC et tableau de bord).
4. **Le tableau de bord disait « 2 » quand l'accueil disait « 11 »** (lots vs étiquettes, sans unité) → le widget compte en étiquettes (copies) avec sa légende ; la pastille de l'écran DLC dit « 2 lots expirés (11 étiquettes) ».
**Constaté, non corrigé (P2/P3)** : titres de sections du PDF comptant les lignes non annulées alors que le tableau liste aussi les annulées ; suivi d'écart dont la mesure de contrôle est prise AVANT la fenêtre minimale de 10 min (la carte dit « ✓ conforme » et « mesure en retard » sans expliquer) ; saisies non validées perdues sans confirmation en quittant l'écran ; le dossier ne nomme pas le relevé de température manquant (seules les fiches ont `missed_list`) ; une impression en échec avec des pages déjà sorties n'est comptée nulle part ; course de dates dans le sélecteur du dossier ; formats « 6,0 » / « 6 », « −12 » / « -12 » ; nom de 80 caractères sans espace qui déborde ; un jour de fermeture hebdomadaire n'existe pas (impossible d'atteindre 100 %).

**Tour 10 (3e relecteur NEUF, 134 appels d'outils, saisies comparées à l'écran ET à la base) : 0 P0, 1 P1 — corrigé.** Un « − » tapé dans les champs SANS signe possible de l'huile (polaires %, bain, litres) était jeté en silence : « -8 » s'enregistrait « 8 % », « -12,5 » litres « 12,5 » (même classe que le P1 du tour 9, que le correctif n'avait pas couverte sur les champs non signés) → ces champs lisent désormais un moins tapé comme « Valeur illisible » et bloquent l'enregistrement. Le reste des saisies (températures aux bornes, réception, traçabilité, huile aux bornes, nettoyage, étiquettes, code personnel verrouillé après 5 échecs, jeton de 10 min, double clic, hors ligne, réponse perdue) est identique entre ce qui est tapé, l'écran et la base.
**À décider par le propriétaire (P2)** : (a) un caissier (POS Operator) peut modifier les SEUILS légaux d'un équipement (`PUT …/temperature/equipment/{id}` → 200) : le commentaire de la route dit « le personnel qui tient la tablette », donc peut-être voulu ; non vérifié : un journal d'audit trace-t-il le changement ; (b) la validation d'adresse e-mail diverge entre l'écran et le serveur (le serveur refuse sans dire laquelle) ; (c) un « jour du mois » vide pour une fiche mensuelle s'enregistre « le 1 » sans le dire.
