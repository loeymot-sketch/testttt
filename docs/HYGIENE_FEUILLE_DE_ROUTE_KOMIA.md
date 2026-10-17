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
Slices 0 à 5 livrées et testées (PHPUnit `tests/Feature/Hygiene`, Vitest `tests/js/hygiene*.spec.js`), vérifiées visuellement (harnais isolé), **non encore déployées** hors températures + DLC.

## Reste à faire (non démarré)
- Rappels et alertes de retard (push / SMS) : nécessite un canal de notification et un planificateur — à cadrer.
- Capteurs / sondes connectés (relevé automatique) : la colonne `source` des relevés est prête (`manual` aujourd'hui).
- Alerte « produit non tracé depuis x jours » et alerte de stock bas par produit (idées Komia) : demandent une colonne par produit.
- Import des produits DLC par fichier ; boutique de rouleaux.
- Planning et pointage : module RH distinct, hors hygiène.
- Application « Cayenne Hygiène » autonome : extraction de `config/hygiene.php`, `hygiene_*`, `/admin/hygiene/*`, `components/admin/hygiene/`.
