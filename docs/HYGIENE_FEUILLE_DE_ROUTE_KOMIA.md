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
