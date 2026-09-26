# Triage — Rapport d'audit externe "Codex" (24/09/2026)

Source : `/Users/1millnonstop/Documents/Codex/2026-09-20/x20-teste-moi/plans/RAPPORT_DEV_CAISSE_2026-09-24.md` (1619 lignes).
Couverture de lecture : **100% du rapport source lu (lignes 1-1619)**. Table structurée P0-01→P1-76 complète aux lignes 1-122, puis ~50 sections "Retest live" narratives lignes 124-1619 qui approfondissent/prouvent les mêmes items. La deuxième moitié (lignes 1040-1619 : TPE/readiness, fidélité, identité entreprise, stock, messagerie/RGPD, KDS/écran client, wizards produit par produit, sessions de caisse, borne) ne contenait qu'UN défaut racine réellement nouveau (A20) — tout le reste reconfirme ou approfondit A1-A19/C/D déjà identifiés.

**Total items numérotés** : 76 (P0-01 à P0-19 + P1-01 à P1-76, numérotation non strictement séquentielle — des P0 apparaissent aussi après P1-36).
**Défauts racine après regroupement** : 20 (section A ci-dessous — A1 à A20).
**Nécessitant une décision propriétaire (pas un bug)** : 8 (section C).
**Contradictions internes au rapport lui-même** : 3 (section D).

---

## A. Défauts racine regroupés

### A1. Désynchronisation multi-surface (POS / Tracker / KDS / Dashboard / Écran client / Historique / Transactions ne concordent jamais)
**Le plus gros cluster, ~40% des items.** Refs : P0-02, P0-03, P0-04, P0-06, P0-07, P0-17, P1-04, P1-25, P1-28, P1-31, P1-33, P1-38, P1-58 + sections "faux vert de l'observabilité", "divergence KDS/écran client", "configuration temps réel réellement déployée".
**Root cause CONCRÈTE trouvée dans le rapport** (ligne ~712) : console navigateur admin logge `[WS] MIX_PUSHER_APP_KEY not set in .env — WebSocket disabled, polling-only mode.` → explique le bandeau KDS "Mode secours actif — SYNC · LOCAL" et la plupart des divergences de compteurs.
**Fichiers à vérifier** : `.env` prod (clé `PUSHER_APP_KEY`/`MIX_PUSHER_APP_KEY`), `config/broadcasting.php:48`, `app/Http/Controllers/Admin/Observability/` (healthcheck qui dit "Tout va bien" sans tester le chemin réel), `app/Http/Controllers/Admin/PosSystemHealthController.php`.
**⚠️ Vérifier d'abord si c'est déjà réglé** : cette session a confirmé plus tôt (ultra-audit test-e2e) que le KDS reçoit bien les commandes borne en local dev — mais n'a pas vérifié la clé Pusher en PRODUCTION. Root cause probable = juste une variable d'env manquante en prod, pas un bug de code.

### A2. Numéro de commande court (A00xx) réutilisé entre commandes différentes
Sous-cas de A1 mais assez grave financièrement (risque d'encaisser/remettre la MAUVAISE commande) pour être séparé. Preuve concrète répétée : `A0035` = 39,60€ (Double Cheese+Boisson+2 Tacos M) ET 30,20€ (2 Cayenne+2 menus) simultanément ; `A0043` = commande livrée-non-payée du 22/09 ET commande active du 24/09. Refs : P0-18 + 2 sections "collision de numéro court" dédiées.
**Fichiers à localiser** : composant qui affiche `queue_number`/`order.number` sans `order_id`/référence longue — `PosComponent.vue` (cartes "à encaisser"), `PosOrdersTrackerComponent.vue`, KDS Vue component (nom exact non confirmé faute de budget grep).

### A3. Healthcheck "faux vert" (Tout va bien / TEMPS RÉEL en service alors que KDS dégradé, stock en rupture, TPE simulé, staging)
Refs : P0-03, P0-04, P0-07, P0-08 + section "faux vert de l'observabilité". Le contrôle vérifie la disponibilité du service, pas le chemin bout-en-bout consommé par chaque écran.
**Fichiers** : `app/Http/Controllers/Admin/Observability/` (à lister précisément), `app/Http/Controllers/Admin/PosSystemHealthController.php`.

### A4. Wizard/composition : source de vérité ≠ publication ≠ POS/borne/web
Le bug original P0-01 (Tacos XL 3 viandes → réouverture recharge 1 seule) + preuves étendues : compositeur de catégorie Tacos affiche "4 produit(s) sur 4 n'ont pas encore ce wizard en caisse" malgré catégorie publiée ; plafonds différents entre page source (`1 à 1`), preview POS/borne (`1 à 4`) et web ; coexistence ancien format (`ARTICLE (ANCIEN FORMAT)`) et nouveau format wizard-catégorie sur les mêmes produits. Refs : P0-01, P1-10, P1-11, P1-65, P1-66 + sections "compositeur de catégorie Tacos", "plafonds source des suppléments", "référentiel ingrédients et doublons".
**⚠️ POSSIBLEMENT PARTIELLEMENT ADRESSÉ cette session** : le bug de duplication panier (viande fantôme après dupliquer+modifier, `ItemComponent.vue::getAttributeConfig`/`initializeDefaultSelections`) a été corrigé. MAIS le cas P0-01 du rapport est une **RÉOUVERTURE/ÉDITION** d'une ligne déjà en panier (pas une duplication) — vérifier si c'est le même point de code ou un chemin différent avant de le classer résolu.
**Fichiers** : `app/Http/Controllers/Admin/ItemAttributeController.php`, `resources/js/components/admin/catalog/*Composer*.vue` (nom exact à confirmer), `app/Services/Pricing/PricingService.php` (frozen — assertComposerStepConstraints).

### A5. Disponibilité catalogue non propagée aux composants de formule
`Fanta Citron 33cl` marqué épuisé au direct mais reste sélectionnable dans un menu, traverse tout le checkout jusqu'au bouton `Confirmer ma commande`. Refs : P0-12, P0-16 + sections "Tacos XL, maxima et boisson indisponible", "passage complet jusqu'au paiement sans soumission".
**Fichiers** : service de disponibilité/capacité catalogue (chercher `ItemAvailabilityChanged`, déjà vu cette session dans `ItemService::changeImage`), logique de checkout web (dépôt séparé `Site-lecayenne`, pas ce dépôt).

### A6. Impression automatique du ticket client non conforme au flag désactivé
Refs : P1-02, P1-03, P1-27, P1-35, P1-68.
**✅ DÉJÀ CORRIGÉ cette session** pour le chemin `onCounterCollectConfirmed` (commit de la session : plus d'impression auto sur encaissement téléphone/web/borne différé, prompt "Imprimer ou non ?"). Le rapport Codex est daté du 24/09, **avant** ce correctif (fait le 25/09) — cohérent, pas une régression. Vérifier qu'aucun AUTRE point d'impression auto ne subsiste (P1-35 parle d'une "matrice serveur source × transition × ticket_type" globale — le correctif de cette session ne couvre qu'un seul point d'entrée).

### A7. Précision monétaire — flottants au lieu de centimes entiers
**Probablement CE que vise "/goal corrige max precision".** Trouvaille ligne ~379 : un supplément à 0,90€ affiché comme `0.8999999761581421` dans un contrôle admin. Le rapport recommande explicitement : "les prix et totaux doivent rester en centimes entiers, sans flottants, et être comparés centime par centime."
**Fichiers à localiser** : chercher où ce prix est stocké/affiché — probablement `ItemAttribute.price`/`ItemVariation.price` (colonnes `decimal`) transformées en float côté JS avant affichage admin (`resources/js/components/admin/catalog/` ou `ItemAttributeController`). **PRIORITÉ HAUTE, non investiguée en détail par manque de budget — à creuser en premier vu la demande explicite du propriétaire.**

### A8. Suppression physique au lieu d'annulation métier auditée
`Supprimer` actif sur commandes `En préparation`/`Préparée`/payées ; `Rembourser` sans confirmation récapitulative visible. Refs : P0-09, P1-13, P1-57.
**Fichier confirmé** : `app/Http/Controllers/Admin/PosOrderController.php:571` (`function destroy`) — à lire pour voir si c'est un vrai `DELETE` SQL ou déjà un soft-cancel (le nom de méthode seul ne le prouve pas).

### A9. Rattachement fiscal cassé — Rapport X refuse "compte non rattaché à un établissement"
**BLOQUANT FISCAL DIRECT.** Refs : P0-10 + section "rapports Z, séquence et rattachement opérateur". Le bouton `Rapport X` est visible et cliquable pour un compte qui reçoit ensuite un refus — modèle d'autorisation `user/operator → establishment → branch → cash_register` cassé ou mal affiché.
**Fichiers** : chercher le contrôleur Z-report (`grep -rn "aucun établissement" app/`, non trouvé par mes greps limités — à refaire plus largement, ex. `app/Services/Fiscal/ZReportService.php` frozen, ou un `EstablishmentController`).

### A10. Permissions non vérifiées côté serveur (UI ≠ API)
Ex. caissier a la permission "Imprimer ticket promo" cochée en admin mais reçoit un refus à l'usage ; caissier peut basculer rupture/dispo produit sans motif/audit. Refs : P1-41, P1-42, P1-71 + section "comptes, rôles et séparation des responsabilités".
**Fichiers** : matrice de permissions Spatie (`FormRequestAuthzDriftSentinelTest` existe déjà côté tests selon CLAUDE.md §9 — vérifier s'il couvre CE cas précis).

### A11. Stock théorique très négatif, aucune décrémentation ne bloque la vente, coûts/seuils absents
20 matières en rupture (`Poulet mariné -70100g`, `Cheddar -1482`...), stock revendable boissons à 0 malgré ventes réelles de boissons. Refs : P0-14, P0-15, P1-29 + 2 sections stock dédiées.
**Fichiers** : `app/Services/Stock/` (nom exact non confirmé), module "Conso & Stock" admin.

### A12. Identité entreprise incohérente (Paris/75000 en admin vs Hénin-Beaumont réel)
Refs : P1-45, P1-51 + section dédiée. **Mixte : bug technique (pas de source unique) + décision propriétaire (quelles sont les VRAIES coordonnées légales).**
**Fichier confirmé** : `app/Http/Controllers/Admin/CompanyController.php`.

### A13. Paiement en ligne affiché/checkout fonctionnel alors que passerelle désactivée en réglages
Client peut arriver jusqu'à `Confirmer ma commande 100,00€` avec Mollie/3-D Secure affichés alors que `Passerelle de paiement en ligne : Désactiver`. Refs : P1-46 + sections "contrat public vs capacités admin", "checkout public et estimation temps réel". **Risque de commande créée avec une promesse non tenable.**
**Fichier** : dépôt séparé `Site-lecayenne` (checkout), + réglage source `app/Http/Controllers/Admin/*Settings*` pour la capacité serveur.

### A14. Configuration borne : PIN admin par défaut 1234, borne rattachée à un compte humain Admin
Refs : P1-39 + section "rattachement de la borne et moindre privilège".
**Fichier** : `app/Http/Controllers/Admin/KioskMachineController.php` (nom à confirmer), middleware déjà positif noté dans le rapport : `BlockKioskTokenFromAdminRoutes` (existe et testé vert).

### A15. Uber Eats : cartes peu détaillées côté UI (abréviations, pas de compteur boisson visible, doublons potentiels non signalés à l'écran)
Refs : P1-06, P1-07, P1-26, P1-32, P1-60.
**⚠️ À RÉCONCILIER avec un résultat DÉJÀ obtenu cette session** : un audit E2E parallèle plus tôt aujourd'hui a fait tourner 124 tests réels (webhook, capture ticket, dédup, tarif) — TOUS VERTS, zéro défaut. **Pas contradictoire en soi** : la LOGIQUE serveur (dédup, idempotence, tarif) est prouvée saine ; le rapport Codex critique la PRÉSENTATION UI (cartes en abréviations, pas de compteur boisson visible à l'écran) — un problème de couche différente, réel mais moins grave que "l'intégration Uber est cassée".

### A16. Fidélité — seuil incohérent 50/100/1000 entre admin et pages publiques
Refs : P1-50, P1-52. **✅ DÉJÀ CORRIGÉ cette session** (LoyaltyRules::floorSetting relevé à 1000 partout : backend prod+local, site web, mobile). Le rapport est daté du 24/09, avant ce correctif du 25/09 — cohérent.

### A17. Google Maps zone de livraison non chargée (dépendance externe clé API/facturation)
Refs : section dédiée. Mineur tant que livraison reste désactivée (A18 ci-dessous).

### A18. Canal Livraison désactivé en réglages mais sélectionnable dans le panier POS et présent dans l'historique/commandes payées
Refs : P1-08, P1-36 + sections "canaux de commande", "historique, canaux désactivés et déduplication externe". Le réglage global ne couvre pas les imports externes (livraisons `Payé` malgré désactivation).

### A19. Menu enfant "Sans sauce" combinable avec des sauces payantes (option censée être exclusive)
Refs : P1-11, P1-65, P1-76 (message d'erreur résiduel qui fuite d'une étape à l'autre du wizard). Défaut logique de configuration, pas de sync.
**Addendum lecture 1040-1619** : reconfirmé sur Galette Normale ("Sauce pour les frites" : Mayonnaise + Sans sauce simultanés, +0,50€) et sur Tacos XL (panier persistant, 4 sauces + Sans sauce). Même défaut racine, aucune nouvelle cause.
**Note annexe (pas un nouveau défaut racine)** : fiche catalogue (Cayenne, Suprême) annonce une composition signature fixe alors que le tunnel impose un choix Pain/Galette non mentionné dans le descriptif court — décision de contenu marketing vs contrat produit, à trancher par le propriétaire, distinct du bug technique A4/A19.

### A20. Sessions de caisse concurrentes jamais clôturées (78+ jours, fonds non rattachés)
**NOUVEAU, non couvert par A1-A19.** Refs (déjà dans la table originale mais non regroupés) : P1-61, P1-67 + sections "Rapport quotidien des sessions de caisse", "rapprochement caisse et session non clôturée". Deux sessions de caisse (branche 1) restent simultanément au statut `Ouverte` : session #1 ouverte le 25/06/2026 (53 transactions, fond initial 110€), session #2 ouverte le 08/07/2026 (242 transactions, fond initial 50€, 78+ jours). Aucune règle n'empêche l'ouverture d'une nouvelle session tant que la précédente n'est pas comptée/clôturée ; `4 360,90€` d'espèces attendues s'accumulent depuis l'ouverture sans qu'aucun X/Z ne les rattache. Un "Aucun écart" affiché sur le périmètre du jour peut donner une fausse impression de régularité alors que la session sous-jacente n'est jamais clôturée.
**Fichiers à investiguer** : module `CashDrawerSession` (déjà dans la liste BranchScope §9 CLAUDE.md), écran "Rapport Caisses Quotidien" / "Vue Caisse Unifiée" admin, service d'ouverture de session caisse (vérifier s'il y a une contrainte "une seule session active par branche/poste" au niveau applicatif ou juste UI).
**Probable bug technique corrigeable** (pas seulement une décision) : si le code permet réellement d'ouvrir une 2e session sans avoir clôturé la 1ère, c'est un vrai trou de contrôle métier — à vérifier avant de le classer "décision propriétaire uniquement".

---

## B. Table complète des items (résumé une ligne, sous-système)

*(Regroupée par sous-système pour éviter les 76 lignes redondantes — voir §A pour le détail par défaut racine. Sous-systèmes : sync multi-surface [A1-A3, A15 partiel], wizard/composition [A4, A7, A19], catalogue/disponibilité [A5, A11], impression [A6], permissions/rôles [A10, A14], fiscal/Z [A9], suppression/audit [A8], identité/paiement [A12, A13], marketing/RGPD [voir C], Uber [A15], stock [A11], livraison [A18].)*

Réfs individuelles non dupliquées ici — chaque P0-xx/P1-xx du rapport source est déjà rattaché à un défaut racine A1-A19 ci-dessus. Consulter le rapport source pour le libellé exact d'un réf donné.

---

## C. Nécessite une décision propriétaire (PAS une correction technique)

1. **APP_ENV=staging en "production"** (P0-05) — décision déjà connue de cette session (mémoire : "La prod tourne en staging" — tous les gardes de boot NF525 sont inertes). Bascule à traiter avec précaution (terminaux pas câblés).
2. **Mention "100% HALAL" sur la borne** (P0-13) vs page publique qui ne revendique aucune certification — nécessite une preuve documentaire du propriétaire avant de trancher.
3. **Paiement en plusieurs fois** — activé ou non (voir contradiction D1 ci-dessous).
4. **Connexion invité + vérification téléphone désactivée** (P1-44) — politique anti-abus à définir.
5. **Messagerie marketing (SMS/email) sans consentement/périmètre visible** (P1-48, P1-53, P1-62, P1-63, P1-64) — RGPD, décision de gouvernance.
6. **Identité légale entreprise** (Paris vs Hénin-Beaumont, P1-45/P1-51) — quelles sont les VRAIES coordonnées à utiliser sur tickets/factures.
7. **Paiement en ligne** — l'activer réellement (câblage Mollie complet) ou retirer la promesse partout (site, ticket promo, FAQ) (P1-46).
8. **Livraison** — l'activer réellement ou la retirer partout, y compris les imports externes déjà `Payé` (P1-08, P1-36, A18).

---

## D. Contradictions internes au rapport (probablement pas des bugs)

1. **P1-59** : le rapport observe "Paiement en plusieurs fois : Activé" à une lecture, et mentionne qu'"une lecture précédente du même audit indiquait le paiement en plusieurs fois activé" — texte ambigu, semble décrire un changement en cours PENDANT l'audit (quelqu'un a modifié le réglage entre deux relectures), pas une incohérence système.
2. **Séquence Z-18→Z-19 sans Z du 03/09** — le rapport dit lui-même explicitement "peut être légitime (journée sans activité)". Pas un bug confirmé, juste une absence de traçabilité de la CAUSE (le système ne dit pas pourquoi).
3. **Uber Eats "cassé" (Codex) vs "124 tests verts" (cette session)** — voir A15 : pas contradictoire, deux couches différentes (logique serveur saine, présentation UI à améliorer).

---

## E. Top 15 par risque réel (fiscal/paiement/perte de données d'abord)

1. **A9 — Rattachement fiscal X/Z cassé** (bloque un rapport fiscal légalement obligatoire) — investiguer le contrôleur Z-report / modèle d'autorisation establishment→branch→cash_register.
2. **A2 — Collision numéro court A00xx** (risque d'encaisser la mauvaise commande, argent réel) — PosComponent.vue + Tracker + KDS, remplacer l'affichage par order_id/référence longue.
3. **A7 — Précision monétaire (flottants)** — objet explicite du /goal, à localiser précisément dans le pipeline prix ItemAttribute/ItemVariation → affichage admin.
4. **A8 — Suppression physique vs annulation auditée** — `PosOrderController.php:571 destroy()`, vérifier si c'est un vrai DELETE.
5. **A13 — Paiement en ligne affiché alors que désactivé** — checkout peut créer une attente client non honorable.
6. **A5 — Disponibilité catalogue non propagée aux formules** — commande peut contenir un composant réellement épuisé jusqu'au paiement.
7. **A1 — Désync multi-surface / clé Pusher manquante** — root cause simple (env var) à vérifier en prod avant tout autre correctif de sync.
8. **A3 — Healthcheck faux vert** — masque activement tous les points 1, 2, 6, 7 aux yeux de l'exploitant.
9. **A4 — Wizard/composition source ≠ publication ≠ canaux** — cause probable de plusieurs erreurs de panier/paiement rapportées.
10. **A11 — Stock négatif sans blocage de vente** — vente possible de produits sans matière réelle.
11. **A10 — Permissions non vérifiées côté serveur** — un rôle limité peut potentiellement agir au-delà de son périmètre si l'API n'est pas testée.
12. **A6 — Impression auto résiduelle hors du point déjà corrigé** — vérifier qu'aucun autre chemin d'impression auto ne subsiste.
13. **A18 — Canal Livraison désactivé mais actif dans les faits** — commandes livraison payées malgré désactivation globale.
14. **A12 — Identité entreprise incohérente** — impact légal sur tickets/factures.
15. **A14 — PIN borne par défaut 1234** — sécurité, faible probabilité d'exploitation mais triviale à corriger.
